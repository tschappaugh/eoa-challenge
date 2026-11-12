import { describe, expect, it } from 'vitest'
import { cleanTitle, normalizeTitle, validateTitle } from './title'

describe('validateTitle', () => {
  it('rejects empty and whitespace-only titles', () => {
    expect(validateTitle('', [])).toBe('Enter a game title.')
    expect(validateTitle('   ', [])).toBe('Enter a game title.')
  })

  it('rejects titles over 100 characters but allows exactly 100', () => {
    expect(validateTitle('a'.repeat(101), [])).toMatch(/at most 100/)
    expect(validateTitle('a'.repeat(100), [])).toBeNull()
  })

  it('counts characters like the server, so emoji count once', () => {
    expect(validateTitle('🎮'.repeat(100), [])).toBeNull()
    expect(validateTitle('🎮'.repeat(101), [])).toMatch(/at most 100/)
  })

  it('rejects duplicates regardless of case, spacing, and Unicode form', () => {
    expect(validateTitle('  zelda ', ['Zelda'])).toMatch(/already/)
    expect(validateTitle('Super   Mario', ['super mario'])).toMatch(/already/)
    expect(validateTitle('Pokémon', ['Pokémon'])).toMatch(/already/)
    expect(validateTitle('Zelda 2', ['Zelda'])).toBeNull()
  })
})

describe('cleanTitle', () => {
  it('collapses whitespace and drops invisible characters', () => {
    expect(cleanTitle('  Super \t Mario\n ')).toBe('Super Mario')
    expect(cleanTitle('Zel​da')).toBe('Zelda')
  })

  it('normalizes full-width letters when comparing', () => {
    expect(normalizeTitle('Ｚｅｌｄａ')).toBe('zelda')
  })
})
