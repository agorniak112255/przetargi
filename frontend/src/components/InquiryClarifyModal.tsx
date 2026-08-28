import { useEffect, useState } from 'react'

export type InquiryCardOption = {
  id: string
  label: string
}

export type InquiryCard = {
  id: string
  title: string
  prompt: string
  options: InquiryCardOption[]
  allow_custom?: boolean
}

export type InquiryAnswer = {
  option_id: string
  custom?: string | null
}

type Props = {
  open: boolean
  cards: InquiryCard[]
  initialAnswers?: Record<string, InquiryAnswer>
  initialNote?: string
  busy?: boolean
  error?: string
  onClose: () => void
  onSubmit: (answers: Record<string, InquiryAnswer>, extraNote: string) => void
}

export function InquiryClarifyModal({
  open,
  cards,
  initialAnswers = {},
  initialNote = '',
  busy = false,
  error = '',
  onClose,
  onSubmit,
}: Props) {
  const [answers, setAnswers] = useState<Record<string, InquiryAnswer>>(initialAnswers)
  const [note, setNote] = useState(initialNote)

  useEffect(() => {
    if (!open) return
    setAnswers(initialAnswers)
    setNote(initialNote)
    // tylko przy otwarciu — nie nadpisuj kliknięć przy re-renderze rodzica
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open])

  useEffect(() => {
    if (!open) return
    function onKey(e: KeyboardEvent) {
      if (e.key === 'Escape' && !busy) onClose()
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [open, busy, onClose])

  if (!open) return null

  const ready =
    cards.length === 0 ||
    cards.every((card) => {
      const picked = answers[card.id]?.option_id
      return Boolean(picked)
    })

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/55 p-4"
      role="dialog"
      aria-modal="true"
      onClick={() => {
        if (!busy) onClose()
      }}
    >
      <div
        className="flex max-h-[88vh] w-full max-w-md flex-col overflow-hidden rounded-xl bg-white"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="flex items-start justify-between gap-3 border-b border-slate-100 px-4 py-3">
          <div>
            <p className="text-sm font-semibold text-slate-900">Doprecyzowanie</p>
            <p className="text-xs text-slate-500">
              Kliknij opcje. Dopiero potem powstanie list do skopiowania.
            </p>
          </div>
          <button
            type="button"
            disabled={busy}
            onClick={onClose}
            className="rounded border border-slate-300 px-2 py-1 text-xs disabled:opacity-50"
          >
            Anuluj
          </button>
        </div>

        <div className="space-y-4 overflow-y-auto px-4 py-3">
          {cards.map((card) => (
            <div key={card.id}>
              <p className="text-xs font-semibold text-slate-800">{card.title}</p>
              <p className="mt-0.5 text-[11px] text-slate-500">{card.prompt}</p>
              <div className="mt-2 flex flex-wrap gap-1.5">
                {card.options.map((opt) => {
                  const active = answers[card.id]?.option_id === opt.id
                  return (
                    <button
                      key={opt.id}
                      type="button"
                      disabled={busy}
                      onClick={() =>
                        setAnswers((prev) => ({
                          ...prev,
                          [card.id]: { option_id: opt.id, custom: prev[card.id]?.custom ?? null },
                        }))
                      }
                      className={`rounded-full border px-2.5 py-1 text-[11px] ${
                        active
                          ? 'border-blue-600 bg-blue-600 text-white'
                          : 'border-slate-300 bg-white text-slate-700 hover:border-blue-300'
                      } disabled:opacity-50`}
                    >
                      {opt.label}
                    </button>
                  )
                })}
              </div>
              {card.allow_custom && (
                <input
                  className="mt-2 w-full rounded border border-slate-300 px-2 py-1 text-xs"
                  disabled={busy}
                  placeholder="Własna notatka do tej karty"
                  value={answers[card.id]?.custom ?? ''}
                  onChange={(e) =>
                    setAnswers((prev) => ({
                      ...prev,
                      [card.id]: {
                        option_id: prev[card.id]?.option_id ?? card.options[0]?.id ?? '',
                        custom: e.target.value,
                      },
                    }))
                  }
                />
              )}
            </div>
          ))}

          <label className="block">
            <span className="mb-1 block text-xs font-medium text-slate-600">Inny niuans</span>
            <textarea
              className="min-h-[56px] w-full rounded border border-slate-300 px-2 py-1.5 text-xs"
              disabled={busy}
              value={note}
              onChange={(e) => setNote(e.target.value)}
              placeholder="np. klient VIP, nie podawaj terminu, napisz po angielsku…"
            />
          </label>

          {error && <p className="text-xs text-red-600">{error}</p>}
        </div>

        <div className="border-t border-slate-100 px-4 py-3">
          <button
            type="button"
            disabled={busy || !ready}
            onClick={() => onSubmit(answers, note.trim())}
            className="w-full rounded bg-blue-600 px-3 py-2 text-xs font-medium text-white hover:bg-blue-700 disabled:opacity-50"
          >
            {busy ? 'Piszę odpowiedź…' : 'Napisz odpowiedź'}
          </button>
        </div>
      </div>
    </div>
  )
}
