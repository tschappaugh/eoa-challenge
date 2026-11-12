import type { DailyAction, Game, Me } from './types'

export class ApiError extends Error {
  readonly code: string
  readonly status: number

  constructor(code: string, message: string, status: number) {
    super(message)
    this.name = 'ApiError'
    this.code = code
    this.status = status
  }
}

const REQUEST_TIMEOUT_MS = 15000

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function parseJson(text: string): unknown {
  try {
    return JSON.parse(text)
  } catch {
    return undefined
  }
}

function toApiError(status: number, data: unknown): ApiError {
  const error = isRecord(data) ? data.error : undefined

  if (
    isRecord(error) &&
    typeof error.code === 'string' &&
    typeof error.message === 'string'
  ) {
    return new ApiError(error.code, error.message, status)
  }

  return new ApiError('UNEXPECTED_RESPONSE', 'Unexpected response.', status)
}

async function request(
  method: 'GET' | 'POST' | 'DELETE',
  path: string,
  body?: unknown
): Promise<unknown> {
  const headers: Record<string, string> = {
    Accept: 'application/json',
    // Saved on the user's first write so "per day" means their local day.
    'X-Timezone': Intl.DateTimeFormat().resolvedOptions().timeZone,
  }

  if (method !== 'GET') {
    // Required by the backend's CSRF guard on every write.
    headers['X-Requested-With'] = 'XMLHttpRequest'
  }

  if (body !== undefined) {
    headers['Content-Type'] = 'application/json'
  }

  let response: Response
  try {
    response = await fetch(`/api${path}`, {
      method,
      headers,
      body: body === undefined ? undefined : JSON.stringify(body),
      credentials: 'same-origin',
      signal: AbortSignal.timeout(REQUEST_TIMEOUT_MS),
    })
  } catch {
    throw new ApiError('NETWORK_ERROR', 'Network request failed.', 0)
  }

  const data = parseJson(await response.text())

  if (!response.ok) {
    throw toApiError(response.status, data)
  }

  if (data === undefined) {
    throw new ApiError('UNEXPECTED_RESPONSE', 'Unexpected response.', 0)
  }

  return data
}

function isGame(value: unknown): value is Game {
  return (
    isRecord(value) &&
    typeof value.id === 'number' &&
    typeof value.title === 'string' &&
    typeof value.votes === 'number' &&
    typeof value.votedByMe === 'boolean' &&
    typeof value.canRemove === 'boolean'
  )
}

function isDailyAction(value: unknown): value is DailyAction {
  return (
    isRecord(value) &&
    (value.type === 'vote' || value.type === 'add') &&
    (typeof value.gameId === 'number' || value.gameId === null)
  )
}

function isMe(value: unknown): value is Me {
  return (
    isRecord(value) &&
    typeof value.timezone === 'string' &&
    typeof value.localDate === 'string' &&
    typeof value.actionAvailable === 'boolean' &&
    (value.action === null || isDailyAction(value.action)) &&
    typeof value.resetsAt === 'string'
  )
}

function unexpected(): ApiError {
  return new ApiError('UNEXPECTED_RESPONSE', 'Unexpected response.', 0)
}

export async function getGames(): Promise<Game[]> {
  const data = await request('GET', '/games')

  if (!isRecord(data) || !Array.isArray(data.games)) throw unexpected()
  if (!data.games.every(isGame)) throw unexpected()

  return data.games
}

export async function getMe(): Promise<Me> {
  const data = await request('GET', '/me')

  if (!isMe(data)) throw unexpected()

  return data
}

export async function addGame(title: string): Promise<Game> {
  const data = await request('POST', '/games', { title })

  if (!isGame(data)) throw unexpected()

  return data
}

export async function removeGame(id: number): Promise<void> {
  await request('DELETE', `/games/${id}`)
}

export async function vote(id: number): Promise<void> {
  await request('POST', `/games/${id}/vote`)
}

export async function removeVote(id: number): Promise<void> {
  await request('DELETE', `/games/${id}/vote`)
}
