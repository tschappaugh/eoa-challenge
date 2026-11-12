import { ApiError } from './api'

/**
 * User-facing text for any failure. Server messages are already written for
 * people; only transport-level failures need wording of their own.
 */
export function friendlyMessage(error: unknown): string {
  if (error instanceof ApiError) {
    switch (error.code) {
      case 'NETWORK_ERROR':
        return "Can't reach the server. Check your connection and try again."
      case 'UNEXPECTED_RESPONSE':
        return 'Something went wrong. Please try again.'
      default:
        return error.message
    }
  }

  return 'Something went wrong. Please try again.'
}

/** Codes that mean the page's view of the world is stale. */
export function shouldResync(error: unknown): boolean {
  return (
    error instanceof ApiError &&
    ['DAILY_LIMIT_REACHED', 'ALREADY_VOTED', 'NOT_FOUND'].includes(error.code)
  )
}
