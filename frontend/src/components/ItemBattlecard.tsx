import { useEffect, useState } from 'react'
import { api } from '../lib/api'
import { productDisplayName, suggestedOfferPrice } from '../lib/productLabel'
import { ProductVerifyModal } from './ProductVerifyModal'

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
  source_currency?: string | null
  currency?: string | null
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

function cheaperSaveBadge(
  ours: BattlecardProduct | null,
  sub: BattlecardProduct | null,
): string | null {
  if (!ours || !sub) return null
  const ourP = ours.purchase_price ?? ours.catalog_price_net
  const subP = sub.purchase_price ?? sub.catalog_price_net
  if (ourP == null || subP == null || ourP <= 0 || subP <= 0) return null
  const save = ((ourP - subP) / ourP) * 100
  if (save < 3) return null
  return `−${Math.round(save)}%`
}

function Col({
  title,
  tone,
  p,
  clickable,
  selected,
  onSelect,
  onPreview,
  onApplyMargin,
  cheaperBadge,
  markupPercent = 18,
}: {
  title: string
  tone: 'main' | 'sub'
  p: BattlecardProduct | null
  clickable?: boolean
  selected?: boolean
  onSelect?: () => void
  onPreview?: () => void
  onApplyMargin?: () => void
  cheaperBadge?: string | null
  markupPercent?: number
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
    p.offer_price ??
    p.suggested_offer_price ??
    suggestedOfferPrice(p.purchase_price, 1 + markupPercent / 100)
  const selectedRing = selected ? 'ring-2 ring-violet-400 ring-offset-1' : ''

  return (
    <div className={`min-w-0 rounded border px-2.5 py-2 ${tones[tone]} ${selectedRing}`}>
      <div className="flex flex-wrap items-center justify-between gap-1">
        <span className="text-[10px] font-semibold uppercase tracking-wide text-slate-600">{title}</span>
        <span className="flex shrink-0 items-center gap-1">
          {tone === 'sub' && cheaperBadge ? (
            <span className="rounded bg-amber-500 px-1 py-0.5 text-[9px] font-bold text-white">
              {cheaperBadge}
            </span>
          ) : null}
          <span className="text-[10px] font-bold text-violet-700">{p.match_percent}%</span>
        </span>
      </div>
      <p className="mt-0.5 break-all text-[11px] font-medium text-slate-900" title={p.sku}>
        {p.sku}
      </p>
      <p className="line-clamp-2 text-[10px] text-slate-600" title={p.name}>
        {productDisplayName(p)}
      </p>
      <p className="mt-0.5 text-[10px] text-slate-500">{p.manufacturer || '—'}</p>
      <div className="mt-1 space-y-0.5">
        <p
          className="text-[11px] font-semibold text-slate-800"
          title={
            p.purchase_price != null
              ? `Cennik po upuście w zł${
                  p.source_currency && p.source_currency !== 'PLN'
                    ? ` (z ${p.source_currency}, kurs NBP)`
                    : ''
                }${p.catalog_price_net != null ? ` (kat. ${fmtPrice(p.catalog_price_net)} zł)` : ''}`
              : 'Cena katalogowa'
          }
        >
          Zakup: {fmtPrice(purchase)} zł
        </p>
        <p
          className="text-[10px] text-emerald-800"
          title={`Cena w ofercie (zapisana lub proponowana: zakup + ${markupPercent}%)`}
        >
          Oferta: {fmtPrice(offerHint)} zł
          {p.offer_price == null && offerHint != null ? (
            <span className="text-slate-500"> (prop. +{markupPercent}%)</span>
          ) : null}
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
      <div className="mt-1.5 flex flex-wrap items-center gap-1">
        <button
          type="button"
          onClick={onPreview}
          className="rounded border border-violet-400 bg-white px-1.5 py-0.5 text-[9px] font-medium text-violet-800 hover:bg-violet-100"
        >
          Opis
        </button>
        {selected ? (
          <span className="flex flex-wrap items-center gap-1">
            <span className="text-[9px] font-semibold text-violet-700">Wybrane w ofercie</span>
            {onApplyMargin ? (
              <button
                type="button"
                onClick={onApplyMargin}
                className="rounded bg-emerald-700 px-1.5 py-0.5 text-[9px] font-semibold text-white hover:bg-emerald-800"
                title={`Ustaw cenę oferty = zakup × (1 + ${markupPercent}% z przetargu)`}
              >
                Przelicz +{markupPercent}%
              </button>
            ) : null}
          </span>
        ) : clickable && onSelect ? (
          <button
            type="button"
            onClick={onSelect}
            className="rounded bg-violet-600 px-1.5 py-0.5 text-[9px] font-medium text-white hover:bg-violet-700"
          >
            Wybierz
          </button>
        ) : null}
      </div>
    </div>
  )
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
        className="w-full max-w-md overflow-hidden rounded-lg bg-white p-4 shadow-xl"
        onClick={(e) => e.stopPropagation()}
      >
        <p className="text-sm font-semibold text-slate-900">Zmienić produkt w ofercie?</p>
        <p className="mt-1 text-xs text-slate-600">
          Wybrany produkt zastąpi aktualną propozycję w tej pozycji.
        </p>
        <div className="mt-3 grid grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] items-stretch gap-2 text-[11px]">
          <div className="min-w-0 overflow-hidden rounded border border-slate-200 bg-slate-50 px-2 py-1.5">
            <p className="text-[9px] font-semibold uppercase text-slate-500">Teraz</p>
            <p className="truncate font-mono text-slate-800">{current?.sku ?? '—'}</p>
            <p className="mt-0.5 line-clamp-2 break-words text-slate-600">
              {current ? productDisplayName(current, 48) : 'brak'}
            </p>
          </div>
          <span className="self-center shrink-0 px-0.5 text-slate-400" aria-hidden>
            →
          </span>
          <div className="min-w-0 overflow-hidden rounded border border-sky-200 bg-sky-50 px-2 py-1.5">
            <p className="text-[9px] font-semibold uppercase text-sky-700">Nowy wybór</p>
            <p className="truncate font-mono text-slate-800">{next.sku}</p>
            <p className="mt-0.5 line-clamp-2 break-words text-slate-600">
              {productDisplayName(next, 48)}
            </p>
            <p className="mt-0.5 font-semibold text-slate-800">
              {fmtPrice(next.purchase_price ?? next.catalog_price_net)} zł
            </p>
          </div>
        </div>
        <div className="mt-4 flex justify-end gap-2">
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
  markupPercent = 18,
  enabled,
  canSelectSubstitute = false,
  selectedProductId = null,
  onSelectSubstitute,
  onApplySelectedOffer,
}: {
  tenderId: number
  itemId: number
  markupPercent?: number
  enabled: boolean
  canSelectSubstitute?: boolean
  selectedProductId?: number | null
  onSelectSubstitute?: (product: BattlecardProduct) => Promise<void> | void
  onApplySelectedOffer?: (product: BattlecardProduct) => void
}) {
  const [open, setOpen] = useState(true)
  const [loading, setLoading] = useState(false)
  const [err, setErr] = useState('')
  const [card, setCard] = useState<Battlecard | null>(null)
  const [pending, setPending] = useState<BattlecardProduct | null>(null)
  const [confirmBusy, setConfirmBusy] = useState(false)
  const [describeId, setDescribeId] = useState<number | null>(null)

  function canPick(product: BattlecardProduct | null | undefined): boolean {
    return (
      canSelectSubstitute &&
      Boolean(product) &&
      (selectedProductId == null || product!.product_id !== selectedProductId)
    )
  }

  function isSelected(product: BattlecardProduct | null | undefined): boolean {
    return Boolean(product && selectedProductId != null && product.product_id === selectedProductId)
  }

  const currentInOffer =
    card == null
      ? null
      : ([card.ours, ...card.substitutes].find(
          (product) => product != null && product.product_id === selectedProductId,
        ) ??
        card.ours)

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
      className="w-full rounded border border-slate-200 bg-white px-2 py-1 text-[10px] text-slate-800"
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
            <div className="grid grid-cols-2 gap-2 min-[900px]:grid-cols-5">
              <Col
                title="Propozycja"
                tone="main"
                markupPercent={markupPercent}
                p={card.ours}
                selected={isSelected(card.ours)}
                clickable={canPick(card.ours)}
                onApplyMargin={
                  isSelected(card.ours) && card.ours && onApplySelectedOffer
                    ? () => onApplySelectedOffer(card.ours!)
                    : undefined
                }
                onPreview={() => {
                  if (card.ours) setDescribeId(card.ours.product_id)
                }}
                onSelect={() => {
                  if (card.ours) setPending(card.ours)
                }}
              />
              {card.substitutes.map((sub, i) => (
                <Col
                  key={sub.product_id}
                  title={`Zamiennik ${i + 1}`}
                  tone="sub"
                  markupPercent={markupPercent}
                  p={sub}
                  cheaperBadge={cheaperSaveBadge(card.ours, sub)}
                  selected={isSelected(sub)}
                  clickable={canPick(sub)}
                  onApplyMargin={
                    isSelected(sub) && onApplySelectedOffer ? () => onApplySelectedOffer(sub) : undefined
                  }
                  onPreview={() => setDescribeId(sub.product_id)}
                  onSelect={() => setPending(sub)}
                />
              ))}
            </div>
            {!card.ours && (
              <p className="text-slate-400">Brak danych do porównania — najpierw dopasuj produkt.</p>
            )}
          </>
        )}
      </div>
      {pending && (
        <ConfirmSubstituteModal
          current={currentInOffer}
          next={pending}
          busy={confirmBusy}
          onCancel={() => {
            if (!confirmBusy) setPending(null)
          }}
          onConfirm={() => void confirmSubstitute()}
        />
      )}
      <ProductVerifyModal
        productId={describeId}
        query={card?.requirement.text ?? ''}
        onClose={() => setDescribeId(null)}
      />
    </details>
  )
}
