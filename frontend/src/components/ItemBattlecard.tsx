import { useEffect, useState } from 'react'
import { api } from '../lib/api'

export type BattlecardProduct = {
  role: string
  product_id: number
  sku: string
  name: string
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
}: {
  title: string
  tone: 'main' | 'sub'
  p: BattlecardProduct | null
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
  const price = p.offer_price ?? p.catalog_price_net
  return (
    <div className={`rounded border px-2 py-1.5 ${tones[tone]}`}>
      <div className="flex items-center justify-between gap-1">
        <span className="text-[10px] font-semibold uppercase tracking-wide text-slate-600">{title}</span>
        <span className="text-[10px] font-bold text-violet-700">{p.match_percent}%</span>
      </div>
      <p className="mt-0.5 truncate text-[11px] font-medium text-slate-900" title={p.sku}>
        {p.sku}
      </p>
      <p className="truncate text-[10px] text-slate-600" title={p.name}>
        {p.name}
      </p>
      <p className="mt-0.5 text-[10px] text-slate-500">{p.manufacturer || '—'}</p>
      <p className="mt-1 text-[11px] font-semibold text-slate-800">{fmtPrice(price)} zł</p>
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
    </div>
  )
}

export function ItemBattlecard({
  tenderId,
  itemId,
  enabled,
}: {
  tenderId: number
  itemId: number
  enabled: boolean
}) {
  const [open, setOpen] = useState(true)
  const [loading, setLoading] = useState(false)
  const [err, setErr] = useState('')
  const [card, setCard] = useState<Battlecard | null>(null)

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

  if (!enabled) return null

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
              <Col title="Zamiennik 1" tone="sub" p={card.substitutes[0] ?? null} />
              <Col title="Zamiennik 2" tone="sub" p={card.substitutes[1] ?? null} />
            </div>
            {!card.ours && (
              <p className="text-slate-400">Brak danych do porównania — najpierw dopasuj produkt.</p>
            )}
          </>
        )}
      </div>
    </details>
  )
}
