/**
 * Pole komentarza przetargu ze wzmiankami „@”: po wpisaniu „@” lista osób z dostępem do przetargu
 * (fetchMentionCandidates), wybrane osoby trafiają do mentionedIds.
 *
 * ZAŚLEPKA kroku 0 — zwykłe pole tekstowe; listę „@” dopisuje strumień C.
 */
export type MentionTextareaProps = {
  tenderId: number
  value: string
  onChange: (value: string) => void
  /** identyfikatory osób wybranych z listy „@” (wysyłane jako mentioned_user_ids) */
  mentionedIds: number[]
  onMentionedChange: (ids: number[]) => void
  disabled?: boolean
  placeholder?: string
  rows?: number
}

export function MentionTextarea({ value, onChange, disabled, placeholder, rows = 2 }: MentionTextareaProps) {
  return (
    <textarea
      className="mt-1 w-full rounded border border-slate-300 px-2 py-1"
      rows={rows}
      value={value}
      disabled={disabled}
      placeholder={placeholder}
      onChange={(e) => onChange(e.target.value)}
    />
  )
}
