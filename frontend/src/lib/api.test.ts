import { afterEach, describe, expect, it, vi } from 'vitest'
import { ApiError, addGame, getGames, removeGame, removeVote } from './api'

function stubFetch(response: Response | Error) {
  const fetchMock = vi.fn<typeof fetch>(async () => {
    if (response instanceof Error) throw response
    return response
  })
  vi.stubGlobal('fetch', fetchMock)
  return fetchMock
}

function headersOf(fetchMock: ReturnType<typeof stubFetch>) {
  const init = fetchMock.mock.calls[0]?.[1]
  return (init?.headers ?? {}) as Record<string, string>
}

afterEach(() => vi.unstubAllGlobals())

describe('request headers', () => {
  it('sends the time zone on reads but not the CSRF header', async () => {
    const fetchMock = stubFetch(
      new Response(JSON.stringify({ games: [] }), { status: 200 })
    )

    await getGames()

    const headers = headersOf(fetchMock)
    expect(headers['X-Timezone']).toBeTruthy()
    expect(headers['X-Requested-With']).toBeUndefined()
    expect(headers['Content-Type']).toBeUndefined()
  })

  it('sends the CSRF header and a JSON body on POST', async () => {
    const fetchMock = stubFetch(
      new Response(
        JSON.stringify({
          id: 1,
          title: 'Zelda',
          votes: 0,
          votedByMe: false,
          canRemove: true,
        }),
        { status: 201 }
      )
    )

    await addGame('Zelda')

    const headers = headersOf(fetchMock)
    const init = fetchMock.mock.calls[0]?.[1]
    expect(headers['X-Requested-With']).toBe('XMLHttpRequest')
    expect(headers['Content-Type']).toBe('application/json')
    expect(init?.body).toBe('{"title":"Zelda"}')
    expect(init?.credentials).toBe('same-origin')
  })

  it('sends the CSRF header on DELETE', async () => {
    const fetchMock = stubFetch(
      new Response(JSON.stringify({ success: true }), { status: 200 })
    )

    await removeGame(3)
    expect(headersOf(fetchMock)['X-Requested-With']).toBe('XMLHttpRequest')
    expect(fetchMock.mock.calls[0]?.[0]).toBe('/api/games/3')
  })
})

describe('errors', () => {
  it('turns the standard error shape into an ApiError', async () => {
    stubFetch(
      new Response(
        JSON.stringify({
          error: { code: 'DUPLICATE_TITLE', message: 'Taken.' },
        }),
        { status: 409 }
      )
    )

    await expect(addGame('Zelda')).rejects.toMatchObject({
      name: 'ApiError',
      code: 'DUPLICATE_TITLE',
      message: 'Taken.',
      status: 409,
    })
  })

  it('treats an HTML error page as an unexpected response', async () => {
    stubFetch(new Response('<html>Bad gateway</html>', { status: 502 }))

    await expect(getGames()).rejects.toMatchObject({
      code: 'UNEXPECTED_RESPONSE',
      status: 502,
    })
  })

  it('reports transport failures as NETWORK_ERROR', async () => {
    stubFetch(new TypeError('Failed to fetch'))

    await expect(removeVote(1)).rejects.toMatchObject({
      code: 'NETWORK_ERROR',
    })
  })

  it('rejects a successful response with the wrong shape', async () => {
    stubFetch(new Response(JSON.stringify({ games: [{ id: 'x' }] })))

    await expect(getGames()).rejects.toBeInstanceOf(ApiError)
  })

  it('rejects a successful response that is not JSON', async () => {
    stubFetch(new Response('ok', { status: 200 }))

    await expect(getGames()).rejects.toMatchObject({
      code: 'UNEXPECTED_RESPONSE',
    })
  })
})
