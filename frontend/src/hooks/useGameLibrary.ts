import { useCallback, useEffect, useRef, useState } from 'react'
import * as api from '../lib/api'
import { ApiError } from '../lib/api'
import { friendlyMessage, shouldResync } from '../lib/messages'
import type { Game, Me } from '../lib/types'

type Status = 'loading' | 'ready' | 'error'

export type AddResult =
  { ok: true } | { ok: false; field: boolean; message: string }

interface Notice {
  id: number
  text: string
}

const MAX_TIMEOUT_MS = 2 ** 31 - 1

const LIMIT_MESSAGE =
  "You've already used today's vote or game suggestion. Come back after midnight."

// Codes the add form shows next to the field instead of in the page alert.
const FIELD_CODES = ['VALIDATION_FAILED', 'DUPLICATE_TITLE']

// Most votes first, then oldest (lowest id) first, matching the server.
function sortByVotes(games: Game[]): Game[] {
  return [...games].sort((a, b) => b.votes - a.votes || a.id - b.id)
}

// Puts one game back exactly as it was, leaving every other game alone.
function restoreGame(games: Game[], game: Game): Game[] {
  return sortByVotes([...games.filter((g) => g.id !== game.id), game])
}

interface OptimisticChange {
  games: (games: Game[]) => Game[]
  me?: (me: Me) => Me
}

export function useGameLibrary() {
  const [status, setStatus] = useState<Status>('loading')
  const [games, setGames] = useState<Game[]>([])
  const [me, setMe] = useState<Me | null>(null)
  const [loadError, setLoadError] = useState<string | null>(null)
  const [actionError, setActionError] = useState<string | null>(null)
  const [notice, setNotice] = useState<Notice | null>(null)
  const [pendingIds, setPendingIds] = useState<ReadonlySet<number>>(new Set())
  const [adding, setAdding] = useState(false)

  const meRef = useRef(me)
  const pendingRef = useRef(pendingIds)
  const requestId = useRef(0)
  const writesInFlight = useRef(0)
  const noticeId = useRef(0)

  useEffect(() => {
    meRef.current = me
    pendingRef.current = pendingIds
  })

  const announce = useCallback((text: string) => {
    noticeId.current += 1
    setNotice({ id: noticeId.current, text })
  }, [])

  const setPending = useCallback((id: number, on: boolean) => {
    setPendingIds((current) => {
      const next = new Set(current)
      if (on) next.add(id)
      else next.delete(id)
      return next
    })
  }, [])

  /**
   * Resolves to true when the result was applied. A response (or failure) is
   * dropped when a newer fetch or an optimistic write started after it, or
   * while a write is still in flight: it would undo that write's optimistic
   * state. The write refreshes again when it finishes.
   */
  const fetchAll = useCallback(async (): Promise<boolean> => {
    requestId.current += 1
    const mine = requestId.current
    const stale = () => mine !== requestId.current || writesInFlight.current > 0

    let result: [Game[], Me]
    try {
      result = await Promise.all([api.getGames(), api.getMe()])
    } catch (error) {
      if (stale()) return false
      throw error
    }
    if (stale()) return false

    setGames(result[0])
    setMe(result[1])
    return true
  }, [])

  const fetchInitial = useCallback(() => {
    fetchAll().then(
      (applied) => {
        if (!applied) return
        setStatus('ready')
        setLoadError(null)
      },
      (error: unknown) => {
        setLoadError(friendlyMessage(error))
        setStatus('error')
      }
    )
  }, [fetchAll])

  const load = useCallback(() => {
    setStatus('loading')
    setLoadError(null)
    fetchInitial()
  }, [fetchInitial])

  // Quiet re-sync with the server after a change. A success also clears an
  // earlier load error, e.g. when an add works once the server is back.
  const refresh = useCallback(async () => {
    try {
      if (!(await fetchAll())) return
      setStatus('ready')
      setLoadError(null)
    } catch (error) {
      setActionError(friendlyMessage(error))
    }
  }, [fetchAll])

  useEffect(() => {
    fetchInitial()
  }, [fetchInitial])

  // The daily action returns at local midnight: re-check then.
  const resetsAt = me?.resetsAt
  useEffect(() => {
    if (!resetsAt) return
    const wait = new Date(resetsAt).getTime() - Date.now() + 1000
    // setTimeout silently fires at once if the delay exceeds 2^31 - 1 ms.
    const delay = Math.min(Math.max(1000, wait), MAX_TIMEOUT_MS)
    const timer = setTimeout(() => void refresh(), delay)
    return () => clearTimeout(timer)
  }, [resetsAt, refresh])

  const dismissError = useCallback(() => setActionError(null), [])

  const runOptimistic = useCallback(
    async (
      game: Game,
      change: OptimisticChange,
      send: () => Promise<void>,
      success: string
    ): Promise<boolean> => {
      if (pendingRef.current.has(game.id)) return false

      const meBefore = meRef.current
      const changeMe = change.me
      // Invalidates any fetch already in flight (see fetchAll).
      requestId.current += 1
      writesInFlight.current += 1
      setActionError(null)
      setPending(game.id, true)
      setGames(change.games)
      if (changeMe) setMe((current) => (current ? changeMe(current) : current))

      try {
        await send()
        announce(success)
        void refresh()
        return true
      } catch (error) {
        // Revert only what this action changed; other actions may have
        // landed since it started.
        setGames((current) => restoreGame(current, game))
        if (changeMe && meBefore) {
          setMe((current) =>
            current
              ? {
                  ...current,
                  actionAvailable: meBefore.actionAvailable,
                  action: meBefore.action,
                }
              : current
          )
        }
        setActionError(friendlyMessage(error))
        if (shouldResync(error)) void refresh()
        return false
      } finally {
        // Runs before the refresh above gets its response, so that refresh
        // is not dropped as stale (unless another write is still going).
        writesInFlight.current -= 1
        setPending(game.id, false)
      }
    },
    [announce, refresh, setPending]
  )

  const vote = useCallback(
    async (game: Game): Promise<void> => {
      if (meRef.current?.actionAvailable === false) {
        setActionError(LIMIT_MESSAGE)
        return
      }

      await runOptimistic(
        game,
        {
          games: (current) =>
            sortByVotes(
              current.map((g) =>
                g.id === game.id
                  ? { ...g, votes: g.votes + 1, votedByMe: true }
                  : g
              )
            ),
          me: (current) => ({
            ...current,
            actionAvailable: false,
            action: { type: 'vote', gameId: game.id },
          }),
        },
        () => api.vote(game.id),
        `Voted for ${game.title}.`
      )
    },
    [runOptimistic]
  )

  const unvote = useCallback(
    async (game: Game): Promise<void> => {
      // The refund (if any) is decided by the server, so `me` isn't guessed.
      await runOptimistic(
        game,
        {
          games: (current) =>
            sortByVotes(
              current.map((g) =>
                g.id === game.id
                  ? { ...g, votes: Math.max(0, g.votes - 1), votedByMe: false }
                  : g
              )
            ),
        },
        () => api.removeVote(game.id),
        `Removed your vote for ${game.title}.`
      )
    },
    [runOptimistic]
  )

  const remove = useCallback(
    (game: Game): Promise<boolean> =>
      runOptimistic(
        game,
        { games: (current) => current.filter((g) => g.id !== game.id) },
        () => api.removeGame(game.id),
        `${game.title} was removed.`
      ),
    [runOptimistic]
  )

  const add = useCallback(
    async (title: string): Promise<AddResult> => {
      if (meRef.current?.actionAvailable === false) {
        setActionError(LIMIT_MESSAGE)
        return { ok: false, field: false, message: LIMIT_MESSAGE }
      }

      setActionError(null)
      setAdding(true)

      try {
        const created = await api.addGame(title)
        // A refresh may already have brought the new game in.
        setGames((current) =>
          sortByVotes([...current.filter((g) => g.id !== created.id), created])
        )
        setMe((current) =>
          current
            ? {
                ...current,
                actionAvailable: false,
                action: { type: 'add', gameId: created.id },
              }
            : current
        )
        announce(`${created.title} was added.`)
        void refresh()

        return { ok: true }
      } catch (error) {
        const message = friendlyMessage(error)
        const field =
          error instanceof ApiError && FIELD_CODES.includes(error.code)

        if (!field) setActionError(message)
        if (shouldResync(error)) void refresh()

        return { ok: false, field, message }
      } finally {
        setAdding(false)
      }
    },
    [announce, refresh]
  )

  return {
    status,
    games,
    me,
    loadError,
    actionError,
    notice,
    pendingIds,
    adding,
    load,
    dismissError,
    vote,
    unvote,
    remove,
    add,
  }
}

export type GameLibrary = ReturnType<typeof useGameLibrary>
