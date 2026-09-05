import { useEffect, useState } from 'react'
import { ProductVerifyModal } from './ProductVerifyModal'

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
  kind?: 'item' | 'global'
  item_id?: string | null
  quote?: string | null
  qty?: string | null
  size?: string | null
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

function productIdFromOption(id: string): number | null {
  if (!id.startsWith('p:')) return null
  const n = Number(id.slice(2))
  return Number.isFinite(n) && n > 0 ? n : null
}

function isItemCard(card: InquiryCard): boolean {
  return card.kind === 'item' || Boolean(card.item_id) || Boolean(card.quote) || card.id.startsWith('product:')
}

function partitionCards(cards: InquiryCard[]): { groups: InquiryCard[][]; global: InquiryCard[] } {
  const groups: InquiryCard[][] = []
  const byItem = new Map<string, InquiryCard[]>()
  const global: InquiryCard[] = []
  const order: string[] = []

  for (const card of cards) {
    if (!isItemCard(card)) {
      global.push(card)
      continue
    }
    const key = card.item_id || card.id
    if (!byItem.has(key)) {
      byItem.set(key, [])
      order.push(key)
    }
    byItem.get(key)!.push(card)
  }
  for (const key of order) {
    const group = byItem.get(key)
    if (group) groups.push(group)
  }
  return { groups, global }
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
  const [previewId, setPreviewId] = useState<number | null>(null)
  const [previewQuery, setPreviewQuery] = useState('')
  const seconds = useBusySeconds(open && busy)

  useEffect(() => {
    if (!open) return
    setAnswers(initialAnswers)
    setNote(initialNote)
    setPreviewId(null)
    setPreviewQuery('')
    // tylko przy otwarciu — nie nadpisuj kliknięć przy re-renderze rodzica
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open])

  useEffect(() => {
    if (!open) return
    function onKey(e: KeyboardEvent) {
      if (e.key !== 'Escape' || busy) return
      if (previewId != null) {
        setPreviewId(null)
        setPreviewQuery('')
      }
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [open, busy, onClose, previewId])

  if (!open) return null

  const { groups, global } = partitionCards(cards)
  const missing = cards.filter((card) => !answers[card.id]?.option_id).map((c) => c.title)

  function setOption(card: InquiryCard, optionId: string) {
    setAnswers((prev) => {
      let custom = prev[card.id]?.custom ?? null
      if (card.id === 'price') {
        custom = optionId === 'catalog_margin' ? custom || '18' : null
      }
      return { ...prev, [card.id]: { option_id: optionId, custom } }
    })
  }

  function setCustom(card: InquiryCard, custom: string) {
    setAnswers((prev) => ({
      ...prev,
      [card.id]: {
        option_id: prev[card.id]?.option_id ?? card.options[0]?.id ?? '',
        custom,
      },
    }))
  }

  function renderFields(card: InquiryCard, quote?: string | null) {
    return (
      <div>
        <p className="text-sm font-semibold text-slate-800">{card.title}</p>
        <p className="mt-1 text-sm leading-snug text-slate-500">{card.prompt}</p>
        <div className="mt-3 flex flex-col gap-2">
          {card.options.map((opt) => {
            const active = answers[card.id]?.option_id === opt.id
            const productId = productIdFromOption(opt.id)
            return (
              <div key={opt.id} className="flex flex-wrap items-center gap-2">
                <button
                  type="button"
                  disabled={busy}
                  onClick={() => setOption(card, opt.id)}
                  className={`max-w-full rounded-full border px-3.5 py-1.5 text-left text-sm leading-snug ${
                    active
                      ? 'border-blue-600 bg-blue-600 text-white'
                      : 'border-slate-300 bg-white text-slate-700 hover:border-blue-300'
                  } disabled:opacity-50`}
                >
                  {opt.label}
                </button>
                {productId != null && (
                  <button
                    type="button"
                    disabled={busy}
                    onClick={() => {
                      setPreviewId(productId)
                      setPreviewQuery((quote ?? card.quote ?? '').trim())
                    }}
                    className="rounded-full border border-violet-300 bg-white px-2.5 py-1 text-xs font-medium text-violet-800 hover:bg-violet-50 disabled:opacity-50"
                  >
                    Opis
                  </button>
                )}
              </div>
            )
          })}
        </div>
        {card.id === 'price' && answers[card.id]?.option_id === 'catalog_margin' && (
          <label className="mt-3 flex flex-wrap items-center gap-2 text-sm text-slate-700">
            <span>Marża</span>
            <input
              type="number"
              min={0}
              max={99}
              step={0.5}
              disabled={busy}
              className="w-24 rounded border border-slate-300 px-2 py-1.5 text-sm"
              value={answers[card.id]?.custom ?? '18'}
              onChange={(e) => setCustom(card, e.target.value)}
            />
            <span>%</span>
            <span className="text-slate-500">domyślnie 18% od ceny katalogowej</span>
          </label>
        )}
        {card.allow_custom && (
          <input
            className="mt-3 w-full rounded border border-slate-300 px-3 py-2 text-sm"
            disabled={busy}
            placeholder="Własna notatka do tej karty"
            value={answers[card.id]?.custom ?? ''}
            onChange={(e) => setCustom(card, e.target.value)}
          />
        )}
      </div>
    )
  }

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/55 p-4"
      role="dialog"
      aria-modal="true"
      onClick={(e) => e.stopPropagation()}
    >
      <div
        className="relative flex max-h-[94vh] w-full max-w-[63rem] flex-col overflow-hidden rounded-2xl bg-white shadow-xl"
        onClick={(e) => e.stopPropagation()}
      >
        {busy && (
          <div className="absolute inset-0 z-10 flex flex-col items-center justify-center bg-white/85">
            <span className="inline-block h-10 w-10 animate-spin rounded-full border-4 border-violet-600 border-t-transparent" />
            <p className="mt-4 text-base font-semibold text-violet-900">Model pisze list…</p>
            <p className="mt-1 text-sm text-slate-500">{seconds}s — nie zamykaj okna</p>
          </div>
        )}
        <div className="flex items-start justify-between gap-4 border-b border-slate-100 px-6 py-5">
          <div>
            <p className="text-lg font-semibold text-slate-900">Doprecyzowanie</p>
            <p className="mt-1 text-sm text-slate-500">
              {groups.length > 1
                ? `${groups.length} pozycji — przy każdej wybierz towar i zamiennik, potem kolejna.`
                : 'Kliknij opcje. Dopiero potem powstanie list do skopiowania.'}
            </p>
          </div>
          <button
            type="button"
            disabled={busy}
            onClick={onClose}
            className="rounded-lg border border-slate-300 px-3 py-1.5 text-sm disabled:opacity-50"
          >
            Anuluj
          </button>
        </div>

        <div className="space-y-5 overflow-y-auto px-6 py-5">
          {groups.map((group, gi) => {
            const head = group[0]
            return (
              <section
                key={head.item_id || head.id}
                className="rounded-xl border border-slate-200 bg-slate-50/80 p-4"
              >
                <div className="mb-3 flex flex-wrap items-center gap-2">
                  <span className="inline-flex h-8 min-w-8 items-center justify-center rounded-lg bg-blue-600 px-2 text-sm font-semibold text-white">
                    {gi + 1}
                  </span>
                  {head.qty && (
                    <span className="rounded-full bg-white px-2.5 py-0.5 text-sm font-medium text-slate-800 ring-1 ring-slate-200">
                      {head.qty}
                    </span>
                  )}
                  {head.size && (
                    <span className="rounded-full bg-white px-2.5 py-0.5 text-sm text-slate-700 ring-1 ring-slate-200">
                      rozm. {head.size}
                    </span>
                  )}
                </div>
                {head.quote && (
                  <blockquote className="mb-4 border-l-4 border-amber-400 bg-amber-50 px-3 py-2.5 text-sm leading-relaxed text-slate-800">
                    <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-amber-800">
                      Klient napisał
                    </p>
                    {head.quote}
                  </blockquote>
                )}
                <div className="space-y-4">
                  {group.map((card) => (
                    <div
                      key={card.id}
                      className={
                        card.id.startsWith('substitutes')
                          ? 'border-t border-slate-200 pt-4'
                          : undefined
                      }
                    >
                      {renderFields(card, head.quote)}
                    </div>
                  ))}
                </div>
              </section>
            )
          })}

          {global.length > 0 && (
            <section className={groups.length > 0 ? 'border-t border-slate-200 pt-5' : undefined}>
              {groups.length > 0 && (
                <p className="mb-3 text-sm font-semibold text-slate-700">Dla całej oferty</p>
              )}
              <div className="space-y-4">{global.map((card) => renderFields(card))}</div>
            </section>
          )}

          <label className="block">
            <span className="mb-1.5 block text-sm font-medium text-slate-600">Inny niuans</span>
            <textarea
              className="min-h-[72px] w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
              disabled={busy}
              value={note}
              onChange={(e) => setNote(e.target.value)}
              placeholder="np. klient VIP, nie podawaj terminu, napisz po angielsku…"
            />
          </label>

          {error && <p className="text-sm text-red-600">{error}</p>}
        </div>

        <div className="border-t border-slate-100 px-6 py-5">
          {!busy && missing.length > 0 && (
            <p className="mb-3 text-center text-sm text-slate-500">
              Nie wybrane: {missing.join(', ')} — możesz i tak wysłać.
            </p>
          )}
          <button
            type="button"
            disabled={busy}
            onClick={() => {
              const next = { ...answers }
              if (next.price?.option_id === 'catalog_margin' && !next.price.custom?.trim()) {
                next.price = { ...next.price, custom: '18' }
              }
              onSubmit(next, note.trim())
            }}
            className={`w-full rounded-lg px-4 py-3 text-sm font-medium text-white ${
              busy ? 'cursor-wait bg-violet-600' : 'bg-blue-600 hover:bg-blue-700'
            }`}
          >
            {busy ? <BusyLabel label="Piszę odpowiedź" seconds={seconds} /> : 'Napisz odpowiedź'}
          </button>
        </div>
      </div>
      <ProductVerifyModal
        productId={previewId}
        query={previewQuery}
        onClose={() => {
          setPreviewId(null)
          setPreviewQuery('')
        }}
      />
    </div>
  )
}
