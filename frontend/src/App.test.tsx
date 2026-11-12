import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { axe } from 'vitest-axe'
import App from './App'
import {
  apiError,
  installFakeServer,
  makeGame,
  makeMe,
} from './test/fakeServer'

afterEach(() => vi.unstubAllGlobals())

const zelda = makeGame({ id: 1, title: 'Zelda', votes: 3 })
const mario = makeGame({ id: 2, title: 'Mario', votes: 1, canRemove: true })
const tetris = makeGame({ id: 3, title: 'Tetris', votes: 0 })

async function renderApp(initial?: Parameters<typeof installFakeServer>[0]) {
  const server = installFakeServer(initial ?? { games: [zelda, mario, tetris] })
  const user = userEvent.setup()
  render(<App />)
  await waitFor(() =>
    expect(screen.queryByText('Loading games…')).not.toBeInTheDocument()
  )
  return { server, user }
}

/** Both the page alert and the load-error box use role="alert". */
async function expectAlert(text: string | RegExp) {
  await waitFor(() => {
    const combined = screen
      .getAllByRole('alert')
      .map((alert) => alert.textContent)
      .join(' ')
    expect(combined).toMatch(text)
  })
}

/** The live region is always mounted, so wait for its text to appear. */
async function expectStatus(text: string) {
  await waitFor(() =>
    expect(screen.getByRole('status')).toHaveTextContent(text)
  )
}

async function listItems() {
  return within(await screen.findByRole('list')).getAllByRole('listitem')
}

describe('game list', () => {
  it('ranks games by votes and shows counts', async () => {
    await renderApp()
    const items = await listItems()

    expect(items).toHaveLength(3)
    expect(items[0]).toHaveTextContent('Zelda')
    expect(items[0]).toHaveTextContent('3 votes')
    expect(items[1]).toHaveTextContent('Mario')
    expect(items[1]).toHaveTextContent('1 vote')
    expect(items[1]).not.toHaveTextContent('1 votes')
    expect(items[2]).toHaveTextContent('Tetris')
  })

  it('shows a loading state, then the list', async () => {
    const server = installFakeServer({ games: [zelda] })
    const release = server.hold('GET /games')
    render(<App />)

    expect(await screen.findByText('Loading games…')).toBeInTheDocument()
    release()
    expect(await screen.findByText('Zelda')).toBeInTheDocument()
    expect(screen.queryByText('Loading games…')).not.toBeInTheDocument()
  })

  it('shows an empty state', async () => {
    await renderApp({ games: [] })

    expect(screen.getByText(/no games yet/i)).toBeInTheDocument()
  })

  it('offers a retry when the first load fails', async () => {
    const server = installFakeServer({ games: [zelda] })
    server.failNext('GET /games', 'network')
    const user = userEvent.setup()
    render(<App />)

    await expectAlert(/can't reach the server/i)

    await user.click(screen.getByRole('button', { name: /try again/i }))

    expect(await screen.findByText('Zelda')).toBeInTheDocument()
  })
})

describe('voting', () => {
  it('updates immediately, then confirms with the server', async () => {
    const { server, user } = await renderApp()
    const release = server.hold('POST /games/3/vote')

    await user.click(screen.getByRole('button', { name: 'Vote for Tetris' }))

    const button = screen.getByRole('button', { name: 'Vote for Tetris' })
    expect(button).toHaveAttribute('aria-pressed', 'true')
    expect(button.closest('li')).toHaveTextContent('1 vote')
    expect(await screen.findByText(/used today's vote/i)).toBeInTheDocument()

    release()

    await expectStatus('Voted for Tetris.')
  })

  it('rolls back and explains when the vote fails', async () => {
    const { server, user } = await renderApp()
    server.failNext('POST /games/3/vote', 'network')

    await user.click(screen.getByRole('button', { name: 'Vote for Tetris' }))

    await expectAlert(/can't reach the server/i)
    expect(
      screen.getByRole('button', { name: 'Vote for Tetris' })
    ).toHaveAttribute('aria-pressed', 'false')
    expect(screen.getByText(/you can vote for one game/i)).toBeInTheDocument()
  })

  it('removes a vote without needing the daily action', async () => {
    const used = makeMe({
      actionAvailable: false,
      action: { type: 'vote', gameId: 1 },
    })
    const voted = makeGame({ id: 1, title: 'Zelda', votes: 3, votedByMe: true })
    const { user } = await renderApp({ games: [voted, mario], me: used })

    const button = screen.getByRole('button', { name: 'Vote for Zelda' })
    expect(button).not.toHaveAttribute('aria-disabled', 'true')
    await user.click(button)

    await expectStatus('Removed your vote for Zelda.')
    // The server handed today's action back.
    expect(await screen.findByText(/you can vote for one game/i)).toBeVisible()
  })

  it('blocks new votes once the daily action is used, and says why', async () => {
    const used = makeMe({
      actionAvailable: false,
      action: { type: 'add', gameId: 9 },
    })
    const { server, user } = await renderApp({
      games: [zelda, mario],
      me: used,
    })

    expect(screen.getByText(/used today's game suggestion/i)).toBeVisible()
    const button = screen.getByRole('button', { name: 'Vote for Zelda' })
    expect(button).toHaveAttribute('aria-disabled', 'true')
    expect(button).toHaveAccessibleDescription(/used today's game suggestion/i)

    await user.click(button)

    await expectAlert(/already used today's vote or game suggestion/i)
    expect(server.calls.filter((c) => c.startsWith('POST'))).toEqual([])
  })

  it('resyncs when the server says the daily limit was already reached', async () => {
    const { server, user } = await renderApp()
    server.failNext(
      'POST /games/3/vote',
      apiError(429, 'DAILY_LIMIT_REACHED', 'You already voted today.')
    )
    server.me = makeMe({
      actionAvailable: false,
      action: { type: 'vote', gameId: 1 },
    })

    await user.click(screen.getByRole('button', { name: 'Vote for Tetris' }))

    await expectAlert('You already voted today.')
    await waitFor(() =>
      expect(screen.getByText(/used today's vote/i)).toBeInTheDocument()
    )
    expect(
      screen.getByRole('button', { name: 'Vote for Tetris' })
    ).toHaveAttribute('aria-pressed', 'false')
  })

  it('shows the rate-limit message from the server', async () => {
    const { server, user } = await renderApp()
    server.failNext(
      'POST /games/3/vote',
      apiError(429, 'RATE_LIMITED', 'Too many requests. Please wait.')
    )

    await user.click(screen.getByRole('button', { name: 'Vote for Tetris' }))

    await expectAlert('Too many requests. Please wait.')
  })
})

describe('adding a game', () => {
  it('adds the game, clears the field, and updates the daily status', async () => {
    const { user } = await renderApp()

    await user.type(screen.getByLabelText(/suggest a game/i), '  Metroid ')
    await user.click(screen.getByRole('button', { name: 'Add game' }))

    expect(await screen.findByText('Metroid')).toBeInTheDocument()
    expect(screen.getByLabelText(/suggest a game/i)).toHaveValue('')
    await expectStatus('Metroid was added.')
    expect(screen.getByText(/used today's game suggestion/i)).toBeVisible()
  })

  it('validates before calling the server', async () => {
    const { server, user } = await renderApp()
    const input = screen.getByLabelText(/suggest a game/i)
    const add = screen.getByRole('button', { name: 'Add game' })

    await user.click(add)
    expect(input).toHaveAccessibleDescription(/enter a game title/i)
    expect(input).toBeInvalid()
    expect(input).toHaveFocus()

    await user.type(input, 'a'.repeat(101))
    await user.click(add)
    expect(input).toHaveAccessibleDescription(/at most 100/i)

    await user.clear(input)
    await user.type(input, '  zelda ')
    await user.click(add)
    expect(input).toHaveAccessibleDescription(/already on the list/i)

    expect(server.calls.filter((c) => c.startsWith('POST'))).toEqual([])
  })

  it('shows a server-side duplicate next to the field', async () => {
    const { server, user } = await renderApp()
    server.failNext(
      'POST /games',
      apiError(409, 'DUPLICATE_TITLE', 'That game is already on the list.')
    )

    await user.type(screen.getByLabelText(/suggest a game/i), 'Brand New')
    await user.click(screen.getByRole('button', { name: 'Add game' }))

    expect(
      screen.getByLabelText(/suggest a game/i)
    ).toHaveAccessibleDescription(/already on the list/i)
    await waitFor(() =>
      expect(screen.getByLabelText(/suggest a game/i)).toHaveFocus()
    )
    expect(screen.getByRole('alert')).toBeEmptyDOMElement()
  })

  it('shows network failures in the page alert and keeps the text', async () => {
    const { server, user } = await renderApp()
    server.failNext('POST /games', 'network')

    await user.type(screen.getByLabelText(/suggest a game/i), 'Metroid')
    await user.click(screen.getByRole('button', { name: 'Add game' }))

    await expectAlert(/can't reach the server/i)
    expect(screen.getByLabelText(/suggest a game/i)).toHaveValue('Metroid')
  })

  it('does not call the server when today is used up', async () => {
    const used = makeMe({
      actionAvailable: false,
      action: { type: 'vote', gameId: 1 },
    })
    const { server, user } = await renderApp({ games: [zelda], me: used })

    await user.type(screen.getByLabelText(/suggest a game/i), 'Metroid')
    await user.click(screen.getByRole('button', { name: 'Add game' }))

    await expectAlert(/already used/i)
    expect(server.calls.filter((c) => c.startsWith('POST'))).toEqual([])
  })
})

describe('removing a game', () => {
  it('only offers Remove on games the user added', async () => {
    await renderApp()

    expect(screen.getAllByRole('button', { name: /^Remove / })).toHaveLength(1)
    expect(
      screen.getByRole('button', { name: 'Remove Mario' })
    ).toBeInTheDocument()
  })

  it('asks first, and cancelling changes nothing', async () => {
    const { server, user } = await renderApp()

    await user.click(screen.getByRole('button', { name: 'Remove Mario' }))
    const dialog = await screen.findByRole('dialog', { name: /remove mario/i })
    await user.click(within(dialog).getByRole('button', { name: 'Cancel' }))

    expect(screen.getByText('Mario')).toBeInTheDocument()
    expect(server.calls.filter((c) => c.startsWith('DELETE'))).toEqual([])
  })

  it('removes the game after confirmation and moves focus to the list', async () => {
    const { server, user } = await renderApp()

    await user.click(screen.getByRole('button', { name: 'Remove Mario' }))
    const dialog = await screen.findByRole('dialog', { name: /remove mario/i })
    await user.click(
      within(dialog).getByRole('button', { name: 'Remove game' })
    )

    await waitFor(() =>
      expect(screen.queryByText('Mario')).not.toBeInTheDocument()
    )
    expect(server.calls).toContain('DELETE /games/2')
    await expectStatus('Mario was removed.')
    expect(
      screen.getByRole('heading', { name: /games, most votes first/i })
    ).toHaveFocus()
  })

  it('puts the game back and explains when removal fails', async () => {
    const { server, user } = await renderApp()
    server.failNext(
      'DELETE /games/2',
      apiError(
        403,
        'NOT_OWNER',
        'Only the person who added a game can remove it.'
      )
    )

    await user.click(screen.getByRole('button', { name: 'Remove Mario' }))
    const dialog = await screen.findByRole('dialog', { name: /remove mario/i })
    await user.click(
      within(dialog).getByRole('button', { name: 'Remove game' })
    )

    await expectAlert(/only the person who added/i)
    expect(await screen.findByText('Mario')).toBeInTheDocument()
  })
})

describe('accessibility', () => {
  it('has no detectable violations when the list is shown', async () => {
    await renderApp()

    expect((await axe(document.body)).violations).toEqual([])
  })

  it('has no detectable violations with errors and the limit shown', async () => {
    const used = makeMe({
      actionAvailable: false,
      action: { type: 'add', gameId: 9 },
    })
    const { user } = await renderApp({ games: [zelda, mario], me: used })
    await user.click(screen.getByRole('button', { name: 'Add game' }))
    await user.click(screen.getByRole('button', { name: 'Vote for Zelda' }))

    expect((await axe(document.body)).violations).toEqual([])
  })

  it('exposes landmarks, a skip link, and one h1', async () => {
    await renderApp()

    expect(screen.getByRole('banner')).toBeInTheDocument()
    expect(screen.getByRole('main')).toBeInTheDocument()
    expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1)
    expect(
      screen.getByRole('link', { name: /skip to main content/i })
    ).toHaveAttribute('href', '#main')
  })
})
