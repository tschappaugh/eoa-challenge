import { useNow } from '../hooks/useNow'
import { formatCountdown, formatResetTime } from '../lib/time'
import type { Me } from '../lib/types'

export const LIMIT_NOTE_ID = 'limit-note'

interface Props {
  me: Me
}

export function LimitBanner({ me }: Props) {
  const now = useNow()

  const used = me.action?.type === 'add' ? 'game suggestion' : 'vote'
  const resets = new Date(me.resetsAt).getTime()

  return (
    <section
      aria-labelledby="limit-heading"
      className={`rounded-sm border p-4 ${
        me.actionAvailable
          ? 'border-line bg-surface'
          : 'border-control bg-notice-soft'
      }`}
    >
      <h2 id="limit-heading" className="sr-only">
        Your daily action
      </h2>
      <p id={LIMIT_NOTE_ID} className="text-sm">
        {me.actionAvailable
          ? 'You can vote for one game or suggest one new game today.'
          : `You've used today's ${used}. Your next one unlocks at ${formatResetTime(
              me.resetsAt,
              me.timezone
            )} (in ${formatCountdown(resets, now)}).`}
      </p>
    </section>
  )
}
