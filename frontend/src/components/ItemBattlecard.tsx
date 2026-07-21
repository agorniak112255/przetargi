import { useEffect, useState } from 'react'
import { api } from '../lib/api'
import { productDisplayName, suggestedOfferPrice } from '../lib/productLabel'

export type BattlecardProduct = {
  role: string
  product_id: number
  sku: string
  name: string
  description?: string | null
  manufacturer: string
  category: string | null
  norms: string | null
  attributes?: {
    kategoria_bhp?: string | null
    material?: string | null
    klasa_ochrony?: string | null
    poziomy_en388?: string | null
    normy_en?: string[]
  } | null
  catalog_price_net: number | null
  offer_price: number | null
  purchase_price: number | null
  suggested_offer_price?: number | null
  stock: number
  match_percent: number
  match_source?: string | null
  substitute_type?: string
  approval_status?: string
  reason?: string | null
  source?: string
}

export type Battlecard = {
  requirement: { line_no: number; text: string }
  ours: BattlecardProduct | null
  substitutes: BattlecardProduct[]
  competitors?: BattlecardProduct[]
  highlights: string[]
}

function fmtPrice(v: number | null | undefined): string {
  if (v == null || Number.isNaN(Number(v))) return '—'
  return Number(v).toLocaleString('pl-PL', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function Col({
  title,
  tone,
  p,
  clickable,
  onClick,
}: {
  title: string
  tone: 'main' | 'sub'
  p: BattlecardProduct | null
  clickable?: boolean
  onClick?: () => void
}) {
  const tones = {
    main: 'border-emerald-200 bg-emerald-50/80',
    sub: 'border-sky-200 bg-sky-50/80',
  }
  if (!p) {
    return (
      <div className={`rounded border px-2 py-1.5 ${tones[tone]}`}>
        <div className="text-[10px] font-semibold uppercase tracking-wide text-slate-500">{title}</div>
        <p className="mt-1 text-[10px] text-slate-400">—</p>
      </div>
    )
  }
  const purchase = p.purchase_price ?? p.catalog_price_net
  const offerHint =
    p.offer_price ?? p.suggested_offer_price ?? suggestedOfferPrice(p.purchase_price)
  const body = (
    <>
      <div className="flex items-center justify-between gap-1">
        <span className="text-[10px] font-semibold uppercase tracking-wide text-slate-600">{title}</span>
        <span className="text-[10px] font-bold text-violet-700">{p.match_percent}%</span>
      </div>
      <p className="mt-0.5 truncate text-[11px] font-medium text-slate-900" title={p.sku}>
        {p.sku}
      </p>
      <p className="truncate text-[10px] text-slate-600" title={p.name}>
        {productDisplayName(p, 48)}
      </p>
      <p className="mt-0.5 text-[10px] text-slate-500">{p.manufacturer || '—'}</p>
      <div className="mt-1 space-y-0.5">
        <p
          className="text-[11px] font-semibold text-slate-800"
          title={
            p.purchase_price != null
              ? `Cennik po upuście${p.catalog_price_net != null ? ` (kat. ${fmtPrice(p.catalog_price_net)} zł)` : ''}`
              : 'Cena katalogowa'
          }
        >
          Zakup: {fmtPrice(purchase)} zł
        </p>
        <p className="text-[10px] text-emerald-800" title="Cena w ofercie (zapisana lub zakup × 1,18)">
          Oferta: {fmtPrice(offerHint)} zł
        </p>
      </div>
      {p.attributes?.material || p.attributes?.klasa_ochrony || p.attributes?.poziomy_en388 ? (
        <p className="mt-0.5 truncate text-[9px] text-slate-600">
          {[p.attributes.material, p.attributes.klasa_ochrony, p.attributes.poziomy_en388]
            .filter(Boolean)
            .join(' · ')}
        </p>
      ) : null}
      {p.norms ? (
        <p className="mt-0.5 truncate text-[9px] text-slate-500" title={p.norms}>
          {p.norms}
        </p>
      ) : null}
      {p.substitute_type ? (
        <p className="mt-0.5 text-[9px] text-sky-700">
          {p.substitute_type}
          {p.approval_status ? ` · ${p.approval_status}` : ''}
        </p>
      ) : null}
      {clickable ? (
        <p className="mt-1 text-[9px] font-medium text-sky-700">Kliknij, aby wybrać do oferty</p>
      ) : null}
    </>
  )

  if (clickable && onClick) {
    return (
      <button
        type="button"
        onClick={onClick}
        className={`w-full rounded border px-2 py-1.5 text-left transition hover:border-sky-400 hover:bg-sky-100 ${tones[tone]}`}
      >
        {body}
      </button>
    )
  }

  return <div className={`rounded border px-2 py-1.5 ${tones[tone]}`}>{body}</div>
}

function ConfirmSubstituteModal({
  current,
  next,
  busy,
  onCancel,
  onConfirm,
}: {
  current: BattlecardProduct | null
  next: BattlecardProduct
  busy: boolean
  onCancel: () => void
  onConfirm: () => void
}) {
  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
      role="dialog"
      aria-modal="true"
      onClick={onCancel}
    >
      <div
        className="w-full max-w-sm rounded-lg bg-white p-3 shadow-xl"
        onClick={(e) => e.stopPropagation()}
      >
        <p className="text-sm font-semibold text-slate-900">Zmienić produkt w ofercie?</p>
        <p className="mt-1 text-xs text-slate-600">
          Zamiennik zastąpi aktualną propozycję w tej pozycji.
        </p>
        <div className="mt-3 grid grid-cols-[1fr_auto_1fr] items-center gap-2 text-[11px]">
          <div className="rounded border border-slate-200 bg-slate-50 px-2 py-1.5">
            <p className="text-[9px] font-semibold uppercase text-slate-500">Teraz</p>
            <p className="font-mono text-slate-800">{current?.sku ?? '—'}</p>
            <p className="truncate text-slate-600">
              {current ? productDisplayName(current, 36) : 'brak'}
            </p>
          </div>
          <span className="text-slate-400">→</span>
          <div className="rounded border border-sky-200 bg-sky-50 px-2 py-1.5">
            <p className="text-[9px] font-semibold uppercase text-sky-700">Zamiennik</p>
            <p className="font-mono text-slate-800">{next.sku}</p>
            <p className="truncate text-slate-600">{productDisplayName(next, 36)}</p>
            <p className="mt-0.5 font-semibold text-slate-800">
              {fmtPrice(next.purchase_price ?? next.catalog_price_net)} zł
            </p>
          </div>
        </div>
        <div className="mt-3 flex justify-end gap-2">
          <button
            type="button"
            disabled={busy}
            onClick={onCancel}
            className="rounded border border-slate-300 px-3 py-1.5 text-xs text-slate-700 hover:bg-slate-50 disabled:opacity-50"
          >
            Anuluj
          </button>
          <button
            type="button"
            disabled={busy}
            onClick={onConfirm}
            className="rounded bg-sky-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-sky-700 disabled:opacity-50"
          >
            {busy ? 'Zapisuję…' : 'Tak, zmień'}
          </button>
        </div>
      </div>
    </div>
  )
}

export function ItemBattlecard({
  tenderId,
  itemId,
  enabled,
  canSelectSubstitute = false,
  onSelectSubstitute,
}: {
  tenderId: number
  itemId: number
  enabled: boolean
  canSelectSubstitute?: boolean
  onSelectSubstitute?: (product: BattlecardProduct) => Promise<void> | void
}) {
  const [open, setOpen] = useState(true)
  const [loading, setLoading] = useState(false)
  const [err, setErr] = useState('')
  const [card, setCard] = useState<Battlecard | null>(null)
  const [pending, setPending] = useState<BattlecardProduct | null>(null)
  const [confirmBusy, setConfirmBusy] = useState(false)

  useEffect(() => {
    if (!open || !enabled) return
    let cancelled = false
    setLoading(true)
    setErr('')
    void api<{ battlecard: Battlecard }>(`/tenders/${tenderId}/items/${itemId}/battlecard`)
      .then((res) => {
        if (!cancelled) setCard(res.battlecard)
      })
      .catch((e: unknown) => {
        if (!cancelled) setErr(e instanceof Error ? e.message : 'Błąd battlecard')
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })
    return () => {
      cancelled = true
    }
  }, [open, enabled, tenderId, itemId])

  useEffect(() => {
    if (pending == null) return
    function onKey(e: KeyboardEvent) {
      if (e.key === 'Escape' && !confirmBusy) setPending(null)
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [pending, confirmBusy])

  if (!enabled) return null

  async function confirmSubstitute() {
    if (!pending || !onSelectSubstitute) return
    setConfirmBusy(true)
    try {
      await onSelectSubstitute(pending)
      setPending(null)
    } finally {
      setConfirmBusy(false)
    }
  }

  return (
    <details
      className="max-w-[520px] rounded border border-slate-200 bg-white px-2 py-1 text-[10px] text-slate-800"
      open={open}
      onToggle={(e) => setOpen((e.target as HTMLDetailsElement).open)}
    >
      <summary className="cursor-pointer font-semibold text-slate-800">
        Battlecard
        {card?.substitutes?.length ? ` · zamienniki: ${card.substitutes.length}` : ''}
      </summary>
      <div className="mt-2 space-y-2">
        {loading && <p className="text-slate-400">Ładowanie…</p>}
        {err && <p className="text-rose-600">{err}</p>}
        {card && !loading && (
          <>
            {(card.highlights?.length ?? 0) > 0 && (
              <ul className="list-disc space-y-0.5 pl-4 text-[10px] text-slate-700">
                {card.highlights.map((h, i) => (
                  <li key={i}>{h}</li>
                ))}
              </ul>
            )}
            <div className="grid grid-cols-1 gap-1.5 sm:grid-cols-3">
              <Col title="Propozycja" tone="main" p={card.ours} />
              <Col
                title="Zamiennik 1"
                tone="sub"
                p={card.substitutes[0] ?? null}
                clickable={
                  canSelectSubstitute &&
                  Boolean(card.substitutes[0]) &&
                  card.substitutes[0].product_id !== card.ours?.product_id
                }
                onClick={() => {
                  const s = card.substitutes[0]
                  if (s) setPending(s)
                }}
              />
              <Col
                title="Zamiennik 2"
                tone="sub"
                p={card.substitutes[1] ?? null}
                clickable={
                  canSelectSubstitute &&
                  Boolean(card.substitutes[1]) &&
                  card.substitutes[1].product_id !== card.ours?.product_id
                }
                onClick={() => {
                  const s = card.substitutes[1]
                  if (s) setPending(s)
                }}
              />
            </div>
            {!card.ours && (
              <p className="text-slate-400">Brak danych do porównania — najpierw dopasuj produkt.</p>
            )}
          </>
        )}
      </div>
      {pending && (
        <ConfirmSubstituteModal
          current={card?.ours ?? null}
          next={pending}
          busy={confirmBusy}
          onCancel={() => {
            if (!confirmBusy) setPending(null)
          }}
          onConfirm={() => void confirmSubstitute()}
        />
      )}
    </details>
  )
}
