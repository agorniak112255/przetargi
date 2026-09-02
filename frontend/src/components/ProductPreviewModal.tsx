import { useEffect, useMemo, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { api, type Product } from '../lib/api'
import {
  descriptionProse,
  findAllOffsets,
  queryHighlightTokens,
} from '../lib/descriptionHighlight'
import { productDisplayName } from '../lib/productLabel'
import { HighlightedDescription } from './HighlightedDescription'

type Props = {
  productId: number | null
  query?: string
  onClose: () => void
}

function previewBody(product: Product): string {
  const extra: string[] = []
  if (product.norms) extra.push(`Normy: ${product.norms}`)
  const payload = product.enrichment_payload
  for (const [title, items] of [
    ['Specyfikacja', payload?.specs],
    ['Cechy', payload?.features],
    ['Materiały', payload?.materials],
    ['Normy', payload?.norms],
    ['Certyfikaty', payload?.certificates],
    ['Zastosowanie', payload?.use_cases],
  ] as const) {
    if (Array.isArray(items) && items.length > 0) {
      extra.push(`${title}:\n${items.map((s) => `• ${s}`).join('\n')}`)
    }
  }
  if (product.documents && product.documents.length > 0) {
    extra.push(`Dokumenty:\n${product.documents.map((d) => `• ${d.title || d.kind || 'PDF'}`).join('\n')}`)
  }
  return [descriptionProse(product.description), extra.join('\n\n')].filter(Boolean).join('\n\n')
}

export function ProductPreviewModal({ productId, query = '', onClose }: Props) {
  const [product, setProduct] = useState<Product | null>(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const [find, setFind] = useState('')
  const [findIndex, setFindIndex] = useState(0)
  const bodyRef = useRef<HTMLDivElement>(null)

  const tokens = useMemo(() => queryHighlightTokens(query), [query])

  useEffect(() => {
    if (productId == null) return
    setLoading(true)
    setError('')
    setProduct(null)
    setFind('')
    setFindIndex(0)
    void api<Product>(`/products/${productId}`)
      .then(setProduct)
      .catch((e) => setError(e instanceof Error ? e.message : 'Nie udało się pobrać produktu'))
      .finally(() => setLoading(false))
  }, [productId])

  useEffect(() => {
    if (productId == null) return
    function onKey(e: KeyboardEvent) {
      if (e.key === 'Escape') {
        e.stopPropagation()
        onClose()
      }
    }
    document.addEventListener('keydown', onKey, true)
    return () => document.removeEventListener('keydown', onKey, true)
  }, [productId, onClose])

  const bodyText = useMemo(() => (product ? previewBody(product) : ''), [product])
  const findHits = useMemo(() => findAllOffsets(bodyText, find), [bodyText, find])

  useEffect(() => {
    setFindIndex(0)
  }, [find])

  useEffect(() => {
    if (findHits.length === 0) return
    const el = bodyRef.current?.querySelector(`[data-find-hit="${findIndex}"]`)
    el?.scrollIntoView({ block: 'center', behavior: 'smooth' })
  }, [findIndex, findHits.length, bodyText])

  if (productId == null) return null

  const primaryImage = product?.images?.find((i) => i.is_primary) ?? product?.images?.[0]
  const hitCount = findHits.length
  const safeIndex = hitCount === 0 ? 0 : ((findIndex % hitCount) + hitCount) % hitCount

  function jump(delta: number) {
    if (hitCount === 0) return
    setFindIndex((i) => ((i + delta) % hitCount + hitCount) % hitCount)
  }

  return (
    <div
      className="fixed inset-0 z-[80] flex items-center justify-center bg-black/60 p-4"
      role="dialog"
      aria-modal="true"
      onClick={onClose}
    >
      <div
        className="flex max-h-[85vh] w-full max-w-2xl flex-col overflow-hidden rounded-xl bg-white shadow-lg"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="flex items-start justify-between gap-3 border-b border-slate-100 px-4 py-3">
          <div>
            {loading && <p className="text-sm text-slate-500">Ładowanie…</p>}
            {error && <p className="text-sm text-red-600">{error}</p>}
            {product && (
              <>
                <p className="text-sm font-semibold">{productDisplayName(product, 120)}</p>
                <p className="text-xs text-slate-500">
                  {product.sku} · {product.manufacturer}
                  {product.category ? ` · ${product.category}` : ''}
                  {product.name && productDisplayName(product, 120) !== product.name
                    ? ` · cennik: ${product.name}`
                    : ''}
                </p>
              </>
            )}
          </div>
          <button
            type="button"
            onClick={onClose}
            className="rounded border border-slate-300 px-2 py-1 text-xs"
          >
            Zamknij
          </button>
        </div>

        <div className="flex flex-wrap items-center gap-2 border-b border-slate-100 bg-slate-50 px-4 py-2.5">
          <label className="flex min-w-[14rem] flex-1 items-center gap-2">
            <span className="shrink-0 text-xs font-medium text-slate-600">Szukaj w karcie</span>
            <input
              type="search"
              value={find}
              onChange={(e) => setFind(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === 'Enter') {
                  e.preventDefault()
                  jump(e.shiftKey ? -1 : 1)
                }
              }}
              placeholder="np. kwas siarkowy — Enter następna"
              className="w-full rounded-md border border-slate-300 bg-white px-2.5 py-1.5 text-sm focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-200"
            />
          </label>
          <span className="text-xs tabular-nums text-slate-500">
            {find.trim().length >= 2
              ? hitCount === 0
                ? '0 trafień'
                : `${safeIndex + 1} / ${hitCount}`
              : tokens.length > 0
                ? `${tokens.length} fraz z zapytania`
                : ''}
          </span>
          <button
            type="button"
            disabled={hitCount === 0}
            onClick={() => jump(-1)}
            className="rounded border border-slate-300 px-2 py-1 text-xs disabled:opacity-40"
          >
            Poprzednie
          </button>
          <button
            type="button"
            disabled={hitCount === 0}
            onClick={() => jump(1)}
            className="rounded border border-slate-300 px-2 py-1 text-xs disabled:opacity-40"
          >
            Następne
          </button>
        </div>

        {tokens.length > 0 && (
          <div className="flex flex-wrap gap-1.5 border-b border-slate-100 px-4 py-2">
            <span className="text-[11px] font-medium text-slate-500">Z zapytania:</span>
            {tokens.map((t) => (
              <button
                key={t}
                type="button"
                onClick={() => setFind(t)}
                className="rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-medium text-amber-950 hover:bg-amber-200"
              >
                {t}
              </button>
            ))}
          </div>
        )}

        <div className="min-h-0 flex-1 overflow-y-auto px-4 py-3">
          {product && (
            <>
              <div className="mb-3 flex flex-wrap gap-4 text-xs text-slate-700">
                <span>
                  Katalog: <b>{product.catalog_price_net}</b> {product.currency ?? 'PLN'}
                  {(product.currency ?? 'PLN').toUpperCase() !== 'PLN' && product.price_pln != null ? (
                    <span className="ml-1 text-slate-500">≈ {product.price_pln} zł</span>
                  ) : null}
                </span>
                <span>
                  Zakup: <b>{product.purchase_price}</b> {product.currency ?? 'PLN'}
                  {(product.currency ?? 'PLN').toUpperCase() !== 'PLN' && product.purchase_price_pln != null ? (
                    <span className="ml-1 text-slate-500">≈ {product.purchase_price_pln} zł</span>
                  ) : null}
                </span>
                {product.discount_percent != null && product.discount_percent !== '' && (
                  <span>
                    Rabat: <b>{product.discount_percent}%</b>
                  </span>
                )}
                {product.norms && (
                  <span>
                    Normy: <b>{product.norms}</b>
                  </span>
                )}
              </div>

              {primaryImage && (
                <img
                  src={primaryImage.url}
                  alt={product.name}
                  className="mb-3 max-h-48 rounded border border-slate-200 object-contain"
                />
              )}
            </>
          )}

          <div ref={bodyRef}>
            {bodyText ? (
              <HighlightedDescription
                text={bodyText}
                queryTokens={tokens}
                findPhrase={find}
                activeFindIndex={safeIndex}
              />
            ) : (
              !loading && <p className="text-sm text-slate-500">Brak opisu w karcie.</p>
            )}
          </div>

          {product && (
            <div className="mt-4 border-t border-slate-100 pt-3">
              <Link
                to={`/products/${product.id}`}
                className="text-xs text-blue-600 hover:underline"
                onClick={onClose}
              >
                Otwórz pełną kartę produktu →
              </Link>
            </div>
          )}
        </div>
      </div>
    </div>
  )
}
