import { secondaryButton } from './styles'

interface Props {
  message: string | null
  onDismiss: () => void
}

/** The container stays mounted so screen readers announce new messages. */
export function ErrorAlert({ message, onDismiss }: Props) {
  return (
    <div role="alert">
      {message && (
        <div className="flex flex-wrap items-center justify-between gap-3 rounded-sm border border-danger bg-danger-soft p-4 text-danger">
          <p className="min-w-0 flex-1 text-sm font-medium">{message}</p>
          <button type="button" className={secondaryButton} onClick={onDismiss}>
            Dismiss
          </button>
        </div>
      )}
    </div>
  )
}
