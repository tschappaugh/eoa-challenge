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

// Most votes first, then lowest id first: the same order the server uses.
function sortByVotes(games: Game[]): Game[] {
  return [...games].sort((a, b) => b.votes - a.votes || a.id - b.id)
}

// Inserts or replaces one game, leaving every other game alone.
function upsertGame(games: Game[], game: Game): Game[] {
  return sortByVotes([...games.filter((g) => g.id !== game.id), game])
}

// Puts back the fields that `change` altered, keeping any others that moved
// on since (another action may have landed in the meantime).
function revertFields<T extends object>(current: T, before: T, after: T): T {
  const next = { ...current }
  for (const key of Object.keys(before) as (keyof T)[]) {
    if (!Object.is(before[key], after[key])) next[key] = before[key]
  }
  return next
}

interface OptimisticChange {
  games: (games: Game[]) => Game[]
  me?: (me: Me) => Me
  /** NOT_FOUND means the game is gone, so don't put it back (removal). */
  goneOnNotFound?: boolean
}

/**
 * applied: the result is on screen. superseded: a newer fetch will decide.
 * interrupted: a write started while this was in flight, so the result may
 * undo it; fetch again once writes settle.
 */
type FetchOutcome = 'applied' | 'superseded' | 'interrupted'

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
  const statusRef = useRef(status)
  const pendingRef = useRef(pendingIds)
  const fetchId = useRef(0)
  const writeEpoch = useRef(0)
  const writesInFlight = useRef(0)
  const refreshOwed = useRef(false)
  const noticeId = useRef(0)

  useEffect(() => {
    meRef.current = me
    statusRef.current = status
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

  const fetchAll = useCallback(async (): Promise<FetchOutcome> => {
    fetchId.current += 1
    const mine = fetchId.current
    const epoch = writeEpoch.current
    refreshOwed.current = false

    const outcome = (): FetchOutcome | null => {
      if (mine !== fetchId.current) return 'superseded'
      if (epoch !== writeEpoch.current || writesInFlight.current > 0) {
        refreshOwed.current = true
        return 'interrupted'
      }
      return null
    }

    let result: [Game[], Me]
    try {
      result = await Promise.all([api.getGames(), api.getMe()])
    } catch (error) {
      const dropped = outcome()
      if (dropped) return dropped
      throw error
    }
    const dropped = outcome()
    if (dropped) return dropped

    setGames(result[0])
    setMe(result[1])
    return 'applied'
  }, [])

  // Fetches until a result can be applied, or until someone else will.
  const settle = useCallback(async (): Promise<boolean> => {
    for (;;) {
      const outcome = await fetchAll()
      if (outcome === 'applied') return true
      // A newer fetch, or the last running write (see runOptimistic), takes
      // over. Otherwise the write already finished: fetch again.
      if (outcome === 'superseded' || writesInFlight.current > 0) return false
    }
  }, [fetchAll])

  /**
   * Loads or re-syncs with the server. While no list has loaded yet a
   * failure shows the load error (with Try again); after that it shows in
   * the page alert and keeps the current view. A success clears a load error,
   * e.g. when an add works once the server is back.
   */
  const refresh = useCallback(
    () =>
      settle().then(
        (applied) => {
          if (!applied) return
          setStatus('ready')
          setLoadError(null)
        },
        (error: unknown) => {
          if (statusRef.current === 'ready') {
            setActionError(friendlyMessage(error))
          } else {
            setLoadError(friendlyMessage(error))
            setStatus('error')
          }
        }
      ),
    [settle]
  )

  const load = useCallback(() => {
    setStatus('loading')
    setLoadError(null)
    void refresh()
  }, [refresh])

  useEffect(() => {
    void refresh()
  }, [refresh])

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
      // A fetch already in flight now can't apply its result (see fetchAll).
      writeEpoch.current += 1
      writesInFlight.current += 1
      setActionError(null)
      setPending(game.id, true)
      setGames(change.games)
      if (changeMe) setMe((current) => (current ? changeMe(current) : current))

      let resync = false
      try {
        await send()
        announce(success)
        resync = true
        return true
      } catch (error) {
        const gone =
          change.goneOnNotFound === true &&
          error instanceof ApiError &&
          error.code === 'NOT_FOUND'
        // Revert only what this action changed; other actions may have
        // landed since it started.
        if (!gone) setGames((current) => upsertGame(current, game))
        if (changeMe && meBefore) {
          const after = changeMe(meBefore)
          setMe((current) =>
            current ? revertFields(current, meBefore, after) : current
          )
        }
        setActionError(friendlyMessage(error))
        resync = shouldResync(error)
        return false
      } finally {
        writesInFlight.current -= 1
        setPending(game.id, false)
        // Also replays a refresh that was dropped while this write ran.
        if (resync || (refreshOwed.current && writesInFlight.current === 0)) {
          void refresh()
        }
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
        {
          games: (current) => current.filter((g) => g.id !== game.id),
          goneOnNotFound: true,
        },
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
        setGames((current) => upsertGame(current, created))
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
    announce,
    load,
    dismissError,
    vote,
    unvote,
    remove,
    add,
  }
}

export type GameLibrary = ReturnType<typeof useGameLibrary>
