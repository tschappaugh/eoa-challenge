import type { Game } from './types'

export type SortKey = 'rank' | 'name'

export interface Sort {
  by: SortKey
  reversed: boolean
}

/** Most votes first: the order the server (and the hook) keep the list in. */
export const DEFAULT_SORT: Sort = { by: 'rank', reversed: false }

// Numeric-aware, so "Zelda 2" comes before "Zelda 10"; base sensitivity
// ignores case and accents. A fixed locale keeps the order predictable.
const collator = new Intl.Collator('en', { numeric: true, sensitivity: 'base' })

/** Returns a new array; ties always fall back to the lowest id first. */
export function sortGames(games: readonly Game[], sort: Sort): Game[] {
  const direction = sort.reversed ? -1 : 1

  return [...games].sort((a, b) => {
    const primary =
      sort.by === 'rank'
        ? direction * (b.votes - a.votes)
        : direction * collator.compare(a.title, b.title)

    return primary || a.id - b.id
  })
}

/** What clicking a button does: reverse the active sort, or switch to the default direction. */
export function nextSort(current: Sort, pressed: SortKey): Sort {
  return current.by === pressed
    ? { by: pressed, reversed: !current.reversed }
    : { by: pressed, reversed: false }
}

/** Direction in words, as read out by screen readers. */
export function directionText(sort: Sort): string {
  if (sort.by === 'rank') {
    return sort.reversed ? 'fewest votes first' : 'most votes first'
  }
  return sort.reversed ? 'Z to A' : 'A to Z'
}

export function sortHeading(sort: Sort): string {
  return `Games, ${directionText(sort)}`
}

export function sortAnnouncement(sort: Sort): string {
  return sort.by === 'rank'
    ? `Sorted by rank, ${directionText(sort)}.`
    : `Sorted alphabetically, ${directionText(sort)}.`
}
