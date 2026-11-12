import { MAX_TITLE_LENGTH } from './types'

/** Mirrors the backend's cleaning so the client can give instant feedback. */
export function cleanTitle(raw: string): string {
  return raw
    .normalize('NFC')
    .replace(/\p{Cf}/gu, '')
    .replace(/\p{Cc}/gu, ' ')
    .replace(/\s+/gu, ' ')
    .trim()
}

export function normalizeTitle(raw: string): string {
  return cleanTitle(raw).normalize('NFKC').toLowerCase()
}

/** Returns an error message, or null when the title looks acceptable. */
export function validateTitle(
  raw: string,
  existingTitles: readonly string[]
): string | null {
  const title = cleanTitle(raw)

  if (title === '') return 'Enter a game title.'

  if (title.length > MAX_TITLE_LENGTH) {
    return `Titles can be at most ${MAX_TITLE_LENGTH} characters.`
  }

  const key = normalizeTitle(title)
  if (existingTitles.some((existing) => normalizeTitle(existing) === key)) {
    return 'That game is already on the list.'
  }

  return null
}
