import { useEffect, useState } from 'react'

export function useBusySeconds(busy: boolean): number {
  const [sec, setSec] = useState(0)
  useEffect(() => {
    if (!busy) {
      setSec(0)
      return
    }
    const id = window.setInterval(() => setSec((s) => s + 1), 1000)
    return () => window.clearInterval(id)
  }, [busy])
  return sec
}

export function BusyLabel({ label, seconds }: { label: string; seconds: number }) {
  return (
    <span className="inline-flex items-center justify-center gap-2">
      <span
        className="inline-block h-3.5 w-3.5 animate-spin rounded-full border-2 border-current border-t-transparent"
        aria-hidden
      />
      {label} · {seconds}s
    </span>
  )
}

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
  const seconds = useBusySeconds(open && busy)

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

  const missing = cards.filter((card) => !answers[card.id]?.option_id).map((c) => c.title)

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
        className="relative flex max-h-[88vh] w-full max-w-md flex-col overflow-hidden rounded-xl bg-white"
        onClick={(e) => e.stopPropagation()}
      >
        {busy && (
          <div className="absolute inset-0 z-10 flex flex-col items-center justify-center bg-white/85">
            <span className="inline-block h-8 w-8 animate-spin rounded-full border-4 border-violet-600 border-t-transparent" />
            <p className="mt-3 text-sm font-semibold text-violet-900">Model pisze list…</p>
            <p className="mt-1 text-xs text-slate-500">{seconds}s — nie zamykaj okna</p>
          </div>
        )}
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
          {!busy && missing.length > 0 && (
            <p className="mb-2 text-center text-[11px] text-slate-500">
              Nie wybrane: {missing.join(', ')} — możesz i tak wysłać.
            </p>
          )}
          <button
            type="button"
            disabled={busy}
            onClick={() => onSubmit(answers, note.trim())}
            className={`w-full rounded px-3 py-2 text-xs font-medium text-white ${
              busy ? 'cursor-wait bg-violet-600' : 'bg-blue-600 hover:bg-blue-700'
            }`}
          >
            {busy ? <BusyLabel label="Piszę odpowiedź" seconds={seconds} /> : 'Napisz odpowiedź'}
          </button>
        </div>
      </div>
    </div>
  )
}
