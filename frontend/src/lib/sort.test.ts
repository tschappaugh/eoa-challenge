import { describe, expect, it } from 'vitest'
import {
  DEFAULT_SORT,
  directionText,
  nextSort,
  sortAnnouncement,
  sortGames,
  sortHeading,
} from './sort'
import type { Game } from './types'

function game(id: number, title: string, votes = 0): Game {
  return { id, title, votes, votedByMe: false, canRemove: false }
}

const titles = (games: Game[]) => games.map((g) => g.title)

describe('sortGames by rank', () => {
  const games = [
    game(3, 'Tetris', 0),
    game(1, 'Zelda', 3),
    game(4, 'Mario', 1),
    game(2, 'Doom', 1),
  ]

  it('puts the most votes first, then the lowest id', () => {
    expect(titles(sortGames(games, DEFAULT_SORT))).toEqual([
      'Zelda',
      'Doom',
      'Mario',
      'Tetris',
    ])
  })

  it('puts the fewest votes first when reversed, ties still by id', () => {
    expect(titles(sortGames(games, { by: 'rank', reversed: true }))).toEqual([
      'Tetris',
      'Doom',
      'Mario',
      'Zelda',
    ])
  })

  it('does not change the array it is given', () => {
    const before = titles(games)
    sortGames(games, { by: 'name', reversed: false })
    expect(titles(games)).toEqual(before)
  })
})

describe('sortGames by name', () => {
  it('sorts A to Z and Z to A', () => {
    const games = [game(1, 'Mario'), game(2, 'Zelda'), game(3, 'Doom')]

    expect(titles(sortGames(games, { by: 'name', reversed: false }))).toEqual([
      'Doom',
      'Mario',
      'Zelda',
    ])
    expect(titles(sortGames(games, { by: 'name', reversed: true }))).toEqual([
      'Zelda',
      'Mario',
      'Doom',
    ])
  })

  it('ignores case and accents, and orders numbers as numbers', () => {
    const games = [
      game(1, 'zelda 10'),
      game(2, 'Zelda 2'),
      game(3, 'éclair'),
      game(4, 'Eagle'),
    ]

    expect(titles(sortGames(games, { by: 'name', reversed: false }))).toEqual([
      'Eagle',
      'éclair',
      'Zelda 2',
      'zelda 10',
    ])
  })

  it('breaks identical names by the lowest id, in both directions', () => {
    const games = [game(5, 'Tetris'), game(2, 'tetris')]

    expect(
      sortGames(games, { by: 'name', reversed: false }).map((g) => g.id)
    ).toEqual([2, 5])
    expect(
      sortGames(games, { by: 'name', reversed: true }).map((g) => g.id)
    ).toEqual([2, 5])
  })
})

describe('nextSort', () => {
  it('reverses the active sort', () => {
    expect(nextSort(DEFAULT_SORT, 'rank')).toEqual({
      by: 'rank',
      reversed: true,
    })
    expect(nextSort({ by: 'name', reversed: true }, 'name')).toEqual({
      by: 'name',
      reversed: false,
    })
  })

  it('switches to the default direction of the other sort', () => {
    expect(nextSort({ by: 'rank', reversed: true }, 'name')).toEqual({
      by: 'name',
      reversed: false,
    })
    expect(nextSort({ by: 'name', reversed: true }, 'rank')).toEqual({
      by: 'rank',
      reversed: false,
    })
  })
})

describe('wording', () => {
  it('describes each sort for headings and announcements', () => {
    expect(sortHeading(DEFAULT_SORT)).toBe('Games, most votes first')
    expect(directionText({ by: 'rank', reversed: true })).toBe(
      'fewest votes first'
    )
    expect(sortHeading({ by: 'name', reversed: true })).toBe('Games, Z to A')
    expect(sortAnnouncement({ by: 'name', reversed: false })).toBe(
      'Sorted alphabetically, A to Z.'
    )
    expect(sortAnnouncement(DEFAULT_SORT)).toBe(
      'Sorted by rank, most votes first.'
    )
  })
})
