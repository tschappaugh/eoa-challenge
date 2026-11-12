const focusRing =
  'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ink'

const buttonBase = `inline-flex min-h-11 cursor-pointer items-center justify-center gap-2 rounded-sm px-4 text-xs font-semibold tracking-widest uppercase motion-safe:transition-colors aria-disabled:cursor-not-allowed ${focusRing}`

export const primaryButton = `${buttonBase} bg-ink text-white hover:bg-accent aria-disabled:bg-line aria-disabled:text-muted aria-disabled:hover:bg-line`

export const secondaryButton = `${buttonBase} border border-control bg-surface text-ink hover:bg-page aria-disabled:text-muted aria-disabled:hover:bg-surface`

export const toggleOnButton = `${buttonBase} border border-ink bg-ink text-white hover:bg-accent aria-disabled:opacity-70`

export const dangerButton = `${buttonBase} bg-danger text-white hover:bg-danger-hover`

export const textInput = `min-h-11 w-full rounded-sm border bg-surface px-3 text-base text-ink aria-invalid:border-danger aria-invalid:border-2 ${focusRing}`
