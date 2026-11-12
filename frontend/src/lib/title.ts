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

/**
 * Look-alike quotes and dashes, folded to one ASCII form for duplicate
 * detection only. Kept in sync with Title::LOOKALIKES in the backend.
 */
const LOOKALIKES: readonly [RegExp, string][] = [
  [/[‘’‛ʼ′`´]/gu, "'"],
  [/[“”„‟″]/gu, '"'],
  [/[‐-―−]/gu, '-'],
]

/**
 * Mirrors the backend's duplicate key. Folding runs before NFKC, which
 * would otherwise rewrite some of these (a double prime becomes two primes).
 */
export function normalizeTitle(raw: string): string {
  const folded = LOOKALIKES.reduce(
    (text, [pattern, plain]) => text.replace(pattern, plain),
    cleanTitle(raw)
  )
  return folded.normalize('NFKC').toLowerCase()
}

/** Returns an error message, or null when the title looks acceptable. */
export function validateTitle(
  raw: string,
  existingTitles: readonly string[]
): string | null {
  const title = cleanTitle(raw)

  if (title === '') return 'Enter a game title.'

  // Count code points like the server's mb_strlen; .length counts UTF-16
  // units, so an emoji would count twice.
  if ([...title].length > MAX_TITLE_LENGTH) {
    return `Titles can be at most ${MAX_TITLE_LENGTH} characters.`
  }

  const key = normalizeTitle(title)
  if (existingTitles.some((existing) => normalizeTitle(existing) === key)) {
    return 'That game is already on the list.'
  }

  return null
}
