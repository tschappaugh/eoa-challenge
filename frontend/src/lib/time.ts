const MINUTE = 60_000

/** "5 hr 12 min", "12 min", or "less than a minute". */
export function formatCountdown(untilMs: number, nowMs: number): string {
  const minutes = Math.max(0, Math.ceil((untilMs - nowMs) / MINUTE))

  if (minutes < 1) return 'less than a minute'

  const hours = Math.floor(minutes / 60)
  const rest = minutes % 60

  if (hours === 0) return `${rest} min`
  if (rest === 0) return `${hours} hr`

  return `${hours} hr ${rest} min`
}

/** Wall-clock time of the reset in the user's saved time zone. */
export function formatResetTime(resetsAt: string, timezone: string): string {
  try {
    return new Intl.DateTimeFormat(undefined, {
      hour: 'numeric',
      minute: '2-digit',
      timeZone: timezone,
    }).format(new Date(resetsAt))
  } catch {
    return 'midnight'
  }
}
