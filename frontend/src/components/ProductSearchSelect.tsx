import { useEffect, useMemo, useRef, useState } from 'react'
import { api, type Product } from '../lib/api'
import { productDisplayName, productSelectLabel } from '../lib/productLabel'
import { ProductVerifyModal } from './ProductVerifyModal'

type MiniProduct = {
  id: number
  sku: string
  name: string
  manufacturer?: string | null
  description?: string | null
  purchase_price?: string | number | null
  purchase_price_pln?: number | null
  currency?: string | null
}

type Props = {
  products: Product[]
  value: string
  selectedProduct?: MiniProduct | null
  disabled?: boolean
  onChange: (productId: string, product?: MiniProduct | null) => void
  hint?: string
  applyMarginPercent?: number
  onApplyMargin?: () => void
  applyMarginDisabled?: boolean
  previewQuery?: string
}

function norm(s: string): string {
  return s
    .toLowerCase()
    .normalize('NFD')
    .replace(/\p{M}/gu, '')
}

function labelOf(p: MiniProduct): string {
  return productSelectLabel(p)
}

export function ProductSearchSelect({
  products,
  value,
  selectedProduct,
  disabled,
  onChange,
  hint,
  applyMarginPercent,
  onApplyMargin,
  applyMarginDisabled,
  previewQuery = '',
}: Props) {
  const [open, setOpen] = useState(false)
  const [q, setQ] = useState('')
  const [remote, setRemote] = useState<Product[]>([])
  const [searching, setSearching] = useState(false)
  const [previewId, setPreviewId] = useState<number | null>(null)
  const wrapRef = useRef<HTMLDivElement>(null)
  const searchTimer = useRef<ReturnType<typeof setTimeout> | null>(null)

  const selected: MiniProduct | undefined =
    products.find((p) => String(p.id) === value) ??
    remote.find((p) => String(p.id) === value) ??
    (selectedProduct && String(selectedProduct.id) === value ? selectedProduct : undefined)

  useEffect(() => {
    if (!open) {
      // zapisany produkt: zawsze pokaż etykietę (nie zostawiaj pustego „Szukaj…”)
      setQ(selected ? labelOf(selected) : value ? `ID ${value}` : '')
    }
  }, [selected, open, value])

  useEffect(() => {
    function onDoc(e: MouseEvent) {
      if (!wrapRef.current?.contains(e.target as Node)) setOpen(false)
    }
    document.addEventListener('mousedown', onDoc)
    return () => document.removeEventListener('mousedown', onDoc)
  }, [])

  useEffect(() => {
    if (!open) return
    const needle = q.trim()
    if (selected && needle === labelOf(selected)) {
      setRemote([])
      return
    }
    if (needle.length < 2) {
      setRemote([])
      return
    }
    if (searchTimer.current) clearTimeout(searchTimer.current)
    searchTimer.current = setTimeout(() => {
      setSearching(true)
      void api<{ data: Product[] }>(`/products?q=${encodeURIComponent(needle)}&per_page=50`)
        .then((res) => setRemote(res.data ?? []))
        .catch(() => setRemote([]))
        .finally(() => setSearching(false))
    }, 250)
    return () => {
      if (searchTimer.current) clearTimeout(searchTimer.current)
    }
  }, [q, open, selected])

  const filtered = useMemo(() => {
    const map = new Map<number, MiniProduct>()
    if (selected) map.set(selected.id, selected)
    for (const p of products) map.set(p.id, p)
    for (const p of remote) map.set(p.id, p)

    const all = [...map.values()]
    const needle = norm(q.trim())
    if (!needle || (selected && q === labelOf(selected))) {
      return all.slice(0, 80)
    }
    const parts = needle.split(/\s+/).filter(Boolean)
    return all
      .filter((p) => {
        const hay = norm(`${p.sku} ${p.name} ${p.manufacturer ?? ''}`)
        return parts.every((part) => hay.includes(part))
      })
      .slice(0, 80)
  }, [products, remote, q, selected])

  return (
    <div ref={wrapRef} className="relative min-w-[220px] max-w-[280px]">
      <input
        type="text"
        disabled={disabled}
        className="w-full rounded border border-slate-300 px-1.5 py-1 text-xs"
        placeholder="Szukaj SKU / nazwy…"
        value={q}
        onFocus={() => setOpen(true)}
        onChange={(e) => {
          setQ(e.target.value)
          setOpen(true)
        }}
      />
      {selected && !open && (
        <div className="mt-1 w-full max-w-full rounded-md border border-sky-200 bg-sky-50 px-2 py-1.5 shadow-sm">
          <button
            type="button"
            className="flex w-full items-start gap-1.5 text-left transition hover:opacity-90"
            title="Kliknij, aby zobaczyć szczegóły produktu"
            onClick={() => setPreviewId(selected.id)}
          >
            <span className="mt-0.5 inline-block h-1.5 w-1.5 shrink-0 rounded-full bg-sky-500" />
            <span className="min-w-0">
              <span className="block truncate text-[11px] font-medium text-sky-900">{selected.sku}</span>
              <span className="block truncate text-[10px] text-slate-600" title={selected.name}>
                {productDisplayName(selected)}
              </span>
            </span>
          </button>
          {onApplyMargin ? (
            <button
              type="button"
              disabled={applyMarginDisabled}
              className="mt-1 rounded bg-emerald-700 px-1.5 py-0.5 text-[9px] font-semibold text-white hover:bg-emerald-800 disabled:opacity-40"
              title={`Ustaw cenę oferty = zakup × (1 + ${applyMarginPercent ?? 18}% z przetargu)`}
              onClick={onApplyMargin}
            >
              Przelicz +{applyMarginPercent ?? 18}%
            </button>
          ) : null}
        </div>
      )}
      {hint && <p className="mt-0.5 text-[10px] text-violet-700">{hint}</p>}
      {open && !disabled && (
        <ul className="absolute z-30 mt-0.5 max-h-56 w-[min(360px,80vw)] overflow-y-auto rounded border border-slate-200 bg-white shadow-lg">
          <li>
            <button
              type="button"
              className="w-full px-2 py-1.5 text-left text-xs text-slate-500 hover:bg-slate-50"
              onClick={() => {
                onChange('')
                setQ('')
                setOpen(false)
              }}
            >
              — brak —
            </button>
          </li>
          {searching && (
            <li className="px-2 py-1 text-[10px] text-slate-400">Szukam w bazie…</li>
          )}
          {filtered.map((p) => (
            <li key={p.id}>
              <button
                type="button"
                className={`w-full px-2 py-1.5 text-left text-xs hover:bg-sky-50 ${
                  String(p.id) === value ? 'bg-sky-100' : ''
                }`}
                onClick={() => {
                  onChange(String(p.id), p)
                  setQ(labelOf(p))
                  setOpen(false)
                }}
              >
                <span className="font-mono text-[11px] text-slate-600">{p.sku}</span>
                <span className="text-slate-400"> · </span>
                {productDisplayName(p, 48)}
              </button>
            </li>
          ))}
          {filtered.length === 0 && !searching && (
            <li className="px-2 py-2 text-xs text-slate-400">Brak wyników dla „{q}”</li>
          )}
        </ul>
      )}
      <ProductVerifyModal
        productId={previewId}
        query={previewQuery}
        onClose={() => setPreviewId(null)}
      />
    </div>
  )
}
