interface Props {
  notice: { id: number; text: string } | null
}

/** Polite announcements for successful actions (voted, added, removed). */
export function StatusRegion({ notice }: Props) {
  return (
    <div role="status" className="sr-only">
      {notice && <p key={notice.id}>{notice.text}</p>}
    </div>
  )
}
