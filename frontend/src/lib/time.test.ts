import { describe, expect, it } from 'vitest'
import { formatCountdown, formatResetTime } from './time'

const NOW = Date.parse('2026-11-12T10:00:00Z')
const minutes = (n: number) => NOW + n * 60_000

describe('formatCountdown', () => {
  it.each([
    [minutes(0), 'less than a minute'],
    [minutes(-5), 'less than a minute'],
    [minutes(12), '12 min'],
    [minutes(60), '1 hr'],
    [minutes(312), '5 hr 12 min'],
  ])('formats %d', (until, expected) => {
    expect(formatCountdown(until, NOW)).toBe(expected)
  })

  it('rounds a partial minute up', () => {
    expect(formatCountdown(NOW + 61_000, NOW)).toBe('2 min')
  })
})

describe('formatResetTime', () => {
  it('shows the reset in the given time zone', () => {
    const text = formatResetTime('2026-11-13T00:00:00-06:00', 'America/Chicago')
    expect(text).toMatch(/12:00/)
  })

  it('falls back when the zone is invalid', () => {
    expect(formatResetTime('2026-11-13T00:00:00Z', 'Not/AZone')).toBe(
      'midnight'
    )
  })
})
