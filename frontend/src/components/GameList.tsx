import { useEffect, useRef, useState } from 'react'
import type { GameLibrary } from '../hooks/useGameLibrary'
import type { Game } from '../lib/types'
import { ConfirmDialog } from './ConfirmDialog'
import { GameItem } from './GameItem'
import { primaryButton } from './styles'

interface Props {
  library: GameLibrary
}

function LoadingList() {
  return (
    <div aria-busy="true">
      <p role="status" className="sr-only">
        Loading games…
      </p>
      <div aria-hidden="true" className="space-y-3">
        {[0, 1, 2].map((n) => (
          <div
            key={n}
            className="h-20 rounded-sm border border-line bg-surface motion-safe:animate-pulse"
          />
        ))}
      </div>
    </div>
  )
}

export function GameList({ library }: Props) {
  const [removing, setRemoving] = useState<Game | null>(null)
  const headingRef = useRef<HTMLHeadingElement>(null)
  const refocusHeading = useRef(false)

  const limited = library.me?.actionAvailable === false

  // The row (and the button that opened the dialog) disappears at once, so
  // focus goes to the list heading. A modal dialog keeps the rest of the page
  // inert until it closes, so wait for that: the dialog's own effect runs
  // before this one.
  useEffect(() => {
    if (removing === null && refocusHeading.current) {
      refocusHeading.current = false
      headingRef.current?.focus()
    }
  }, [removing])

  async function confirmRemove() {
    const game = removing
    if (!game) return

    refocusHeading.current = true
    setRemoving(null)
    await library.remove(game)
  }

  return (
    <section aria-labelledby="games-heading" className="space-y-4">
      <h2
        id="games-heading"
        ref={headingRef}
        tabIndex={-1}
        className="border-b border-line pb-2 text-xs font-bold tracking-widest text-accent uppercase focus:outline-none"
      >
        Games, most votes first
      </h2>

      {library.status === 'loading' && <LoadingList />}

      {library.status === 'error' && (
        <div
          role="alert"
          className="space-y-3 rounded-sm border border-danger bg-danger-soft p-4 text-danger"
        >
          <p className="text-sm font-medium">{library.loadError}</p>
          <button
            type="button"
            className={primaryButton}
            onClick={library.load}
          >
            Try again
          </button>
        </div>
      )}

      {library.status === 'ready' && library.games.length === 0 && (
        <p className="rounded-sm border border-line bg-surface p-4 text-sm text-muted">
          No games yet. Suggest the first one above.
        </p>
      )}

      {library.status === 'ready' && library.games.length > 0 && (
        <ol role="list" className="space-y-3">
          {library.games.map((game, index) => (
            <GameItem
              key={game.id}
              game={game}
              rank={index + 1}
              limited={limited}
              pending={library.pendingIds.has(game.id)}
              onVote={library.vote}
              onUnvote={library.unvote}
              onRemove={setRemoving}
            />
          ))}
        </ol>
      )}

      <ConfirmDialog
        open={removing !== null}
        title={removing ? `Remove ${removing.title}?` : 'Remove game?'}
        description="This removes the game and everyone's votes for it. If you added it today, your daily action is returned. This can't be undone."
        confirmLabel="Remove game"
        onConfirm={() => void confirmRemove()}
        onCancel={() => setRemoving(null)}
      />
    </section>
  )
}
