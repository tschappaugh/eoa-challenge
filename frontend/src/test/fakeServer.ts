import { vi } from 'vitest'
import type { Game, Me } from '../lib/types'

type Reply = { status: number; body: unknown } | 'network'

export interface FakeServer {
  games: Game[]
  me: Me
  calls: string[]
  /** Next request matching "METHOD /path" gets this reply instead. */
  failNext: (route: string, reply: Reply) => void
  /** Hold matching requests until the returned function is called. */
  hold: (route: string) => () => void
}

export function makeMe(overrides: Partial<Me> = {}): Me {
  return {
    timezone: 'UTC',
    localDate: '2026-11-12',
    actionAvailable: true,
    action: null,
    resetsAt: new Date(Date.now() + 5 * 3_600_000).toISOString(),
    ...overrides,
  }
}

export function makeGame(overrides: Partial<Game> & { id: number }): Game {
  return {
    title: `Game ${overrides.id}`,
    votes: 0,
    votedByMe: false,
    canRemove: false,
    ...overrides,
  }
}

function json(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}

export function apiError(status: number, code: string, message: string) {
  return { status, body: { error: { code, message } } }
}

// Like the real server: most votes first, then oldest (lowest id) first.
function sorted(games: Game[]): Game[] {
  return [...games].sort((a, b) => b.votes - a.votes || a.id - b.id)
}

/** A tiny in-memory stand-in for the backend, behind a stubbed fetch. */
export function installFakeServer(
  initial: { games?: Game[]; me?: Me } = {}
): FakeServer {
  const server: FakeServer = {
    // Copies: the fake mutates its data, and fixtures are shared across tests.
    games: structuredClone(initial.games ?? []),
    me: structuredClone(initial.me ?? makeMe()),
    calls: [],
    failNext: (route, reply) => {
      failures.set(route, [...(failures.get(route) ?? []), reply])
    },
    hold: (route) => {
      let release = () => {}
      const gate = new Promise<void>((resolve) => {
        release = resolve
      })
      holds.set(route, gate)
      return release
    },
  }
  const failures = new Map<string, Reply[]>()
  const holds = new Map<string, Promise<void>>()
  let nextId = 100

  vi.stubGlobal(
    'fetch',
    vi.fn(async (input: string, init?: RequestInit): Promise<Response> => {
      const method = init?.method ?? 'GET'
      const path = input.replace(/^\/api/, '')
      const route = `${method} ${path}`
      server.calls.push(route)

      const gate = holds.get(route)
      if (gate) {
        holds.delete(route)
        await gate
      }

      const queued = failures.get(route)?.shift()
      if (queued === 'network') throw new TypeError('Failed to fetch')
      if (queued) return json(queued.status, queued.body)

      if (route === 'GET /games')
        return json(200, { games: sorted(server.games) })
      if (route === 'GET /me') return json(200, server.me)

      if (route === 'POST /games') {
        const title = String(JSON.parse(String(init?.body)).title).trim()
        const created = makeGame({ id: nextId++, title, canRemove: true })
        server.games.push(created)
        server.me = makeMe({
          actionAvailable: false,
          action: { type: 'add', gameId: created.id },
        })
        return json(201, created)
      }

      const match = /^\/games\/(\d+)(\/vote)?$/.exec(path)
      const game = match && server.games.find((g) => g.id === Number(match[1]))
      if (!match || !game)
        return json(404, {
          error: { code: 'NOT_FOUND', message: 'Not found.' },
        })

      if (method === 'POST' && match[2]) {
        game.votes += 1
        game.votedByMe = true
        server.me = makeMe({
          actionAvailable: false,
          action: { type: 'vote', gameId: game.id },
        })
      } else if (method === 'DELETE' && match[2]) {
        game.votes = Math.max(0, game.votes - 1)
        game.votedByMe = false
        server.me = makeMe() // refund, as for a vote cast today
      } else if (method === 'DELETE') {
        server.games = server.games.filter((g) => g.id !== game.id)
      }

      return json(200, { success: true })
    })
  )

  return server
}
