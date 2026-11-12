import type { Game } from '../lib/types'
import { LIMIT_NOTE_ID } from './LimitBanner'
import { secondaryButton, toggleOnButton } from './styles'

interface Props {
  game: Game
  rank: number
  limited: boolean
  pending: boolean
  onVote: (game: Game) => void
  onUnvote: (game: Game) => void
  onRemove: (game: Game) => void
}

export function GameItem({
  game,
  rank,
  limited,
  pending,
  onVote,
  onUnvote,
  onRemove,
}: Props) {
  // Removing a vote never uses the daily action, so it is never blocked.
  const voteBlocked = pending || (limited && !game.votedByMe)

  return (
    <li
      aria-busy={pending}
      className="flex flex-wrap items-center gap-x-4 gap-y-3 rounded-sm border border-line bg-surface p-4"
    >
      <span
        aria-hidden="true"
        className="w-8 text-2xl font-extralight text-accent tabular-nums"
      >
        {rank}
      </span>
      <div className="min-w-0 flex-1">
        <h3 className="text-base font-semibold break-words">{game.title}</h3>
        <p className="text-sm text-muted">
          {game.votes} {game.votes === 1 ? 'vote' : 'votes'}
        </p>
      </div>
      <div className="flex gap-2">
        <button
          type="button"
          aria-label={`Vote for ${game.title}`}
          aria-pressed={game.votedByMe}
          aria-disabled={voteBlocked}
          aria-describedby={
            limited && !game.votedByMe ? LIMIT_NOTE_ID : undefined
          }
          onClick={() => {
            if (pending) return
            if (game.votedByMe) onUnvote(game)
            else onVote(game)
          }}
          className={game.votedByMe ? toggleOnButton : secondaryButton}
        >
          {game.votedByMe && (
            <svg
              aria-hidden="true"
              viewBox="0 0 16 16"
              className="size-4 fill-none stroke-current stroke-2"
            >
              <path d="M3 8.5l3.5 3.5L13 4.5" />
            </svg>
          )}
          Vote
        </button>
        {game.canRemove && (
          <button
            type="button"
            aria-label={`Remove ${game.title}`}
            aria-disabled={pending}
            onClick={() => {
              if (!pending) onRemove(game)
            }}
            className={secondaryButton}
          >
            Remove
          </button>
        )}
      </div>
    </li>
  )
}
