import { directionText } from '../lib/sort'
import type { Sort, SortKey } from '../lib/sort'
import { secondaryButton, toggleOnButton } from './styles'

interface Props {
  sort: Sort
  /** Names the group, so the two copies of the controls can be told apart. */
  label: string
  disabled: boolean
  describedBy?: string
  onSort: (key: SortKey) => void
}

function Arrow({ up }: { up: boolean }) {
  return (
    <svg
      aria-hidden="true"
      viewBox="0 0 16 16"
      className={`size-4 fill-none stroke-current stroke-2 ${up ? 'rotate-180' : ''}`}
    >
      <path d="M8 3v10M4 9l4 4 4-4" />
    </svg>
  )
}

export function SortControls({
  sort,
  label,
  disabled,
  describedBy,
  onSort,
}: Props) {
  const rankActive = !disabled && sort.by === 'rank'
  const nameActive = !disabled && sort.by === 'name'
  const nameText = nameActive && sort.reversed ? 'Z–A' : 'A–Z'

  return (
    <div
      role="group"
      aria-label={label}
      className="flex items-center justify-between gap-2"
    >
      <button
        type="button"
        aria-label={`Sort by rank, ${directionText({
          by: 'rank',
          reversed: rankActive && sort.reversed,
        })}`}
        aria-pressed={rankActive}
        aria-disabled={disabled}
        aria-describedby={disabled ? describedBy : undefined}
        onClick={() => {
          if (!disabled) onSort('rank')
        }}
        className={rankActive ? toggleOnButton : secondaryButton}
      >
        Rank
        {rankActive && <Arrow up={sort.reversed} />}
      </button>
      <button
        type="button"
        aria-label={`Sort ${nameText}`}
        aria-pressed={nameActive}
        aria-disabled={disabled}
        aria-describedby={disabled ? describedBy : undefined}
        onClick={() => {
          if (!disabled) onSort('name')
        }}
        className={nameActive ? toggleOnButton : secondaryButton}
      >
        {nameText}
        {nameActive && <Arrow up={sort.reversed} />}
      </button>
    </div>
  )
}
