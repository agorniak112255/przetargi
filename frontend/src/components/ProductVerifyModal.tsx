import { useEffect, useMemo, useRef, useState } from 'react'
import { api, appHref, type Product } from '../lib/api'
import {
  descriptionProse,
  findAllOffsets,
  queryHighlightTokens,
} from '../lib/descriptionHighlight'
import { productDisplayName } from '../lib/productLabel'
import { HighlightedDescription } from './HighlightedDescription'

type Props = {
  productId: number | null
  query: string
  onClose: () => void
}

export function ProductVerifyModal({ productId, query, onClose }: Props) {
  const [product, setProduct] = useState<Product | null>(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const [find, setFind] = useState('')
  const [findIndex, setFindIndex] = useState(0)
  const [imageIndex, setImageIndex] = useState(0)
  const bodyRef = useRef<HTMLDivElement>(null)

  const tokens = useMemo(() => queryHighlightTokens(query), [query])

  useEffect(() => {
    if (productId == null) return
    setLoading(true)
    setError('')
    setProduct(null)
    setFind('')
    setFindIndex(0)
    setImageIndex(0)
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

  const bodyText = useMemo(() => {
    if (!product) return ''
    const extra = [
      product.norms ? `Normy: ${product.norms}` : '',
      ...(product.enrichment_payload?.specs ?? []).map((s) => `• ${s}`),
      ...(product.enrichment_payload?.features ?? []).map((s) => `• ${s}`),
      ...(product.enrichment_payload?.materials ?? []).map((s) => `• ${s}`),
      ...(product.enrichment_payload?.use_cases ?? []).map((s) => `• ${s}`),
    ].filter(Boolean)
    return [descriptionProse(product.description), extra.join('\n')].filter(Boolean).join('\n\n')
  }, [product])

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

  const images = product?.images ?? []
  const image = images[imageIndex] ?? images[0]
  const hitCount = findHits.length
  const safeIndex = hitCount === 0 ? 0 : ((findIndex % hitCount) + hitCount) % hitCount

  function jump(delta: number) {
    if (hitCount === 0) return
    setFindIndex((i) => ((i + delta) % hitCount + hitCount) % hitCount)
  }

  return (
    <div
      className="fixed inset-0 z-[70] flex items-center justify-center bg-slate-950/70 p-3 sm:p-6"
      role="dialog"
      aria-modal="true"
      aria-labelledby="verify-title"
      onClick={onClose}
    >
      <div
        className="flex max-h-[94vh] w-full max-w-6xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="flex items-start justify-between gap-3 border-b border-slate-100 bg-gradient-to-r from-violet-700 to-indigo-600 px-5 py-4 text-white">
          <div className="min-w-0">
            <p className="text-xs font-medium uppercase tracking-wide text-violet-200">Weryfikacja karty</p>
            {product && (
              <>
                <h2 id="verify-title" className="mt-0.5 truncate text-lg font-semibold">
                  {productDisplayName(product, 160)}
                </h2>
                <p className="truncate text-xs text-violet-100">
                  {product.sku} · {product.manufacturer}
                  {product.category ? ` · ${product.category}` : ''}
                </p>
              </>
            )}
            {loading && <p className="text-sm text-violet-100">Ładowanie karty…</p>}
            {error && <p className="text-sm text-red-100">{error}</p>}
          </div>
          <div className="flex shrink-0 items-center gap-2">
            <a
              href={appHref(`/products/${productId}`)}
              target="_blank"
              rel="noreferrer"
              className="rounded-md border border-white/30 px-2.5 py-1 text-xs font-medium hover:bg-white/10"
            >
              Otwórz w nowej karcie
            </a>
            <button
              type="button"
              onClick={onClose}
              className="rounded-md border border-white/30 px-2.5 py-1 text-xs font-medium hover:bg-white/10"
            >
              Zamknij
            </button>
          </div>
        </div>

        <div className="flex flex-wrap items-center gap-2 border-b border-slate-100 bg-slate-50 px-5 py-2.5">
          <label className="flex min-w-[16rem] flex-1 items-center gap-2">
            <span className="shrink-0 text-xs font-medium text-slate-600">Szukaj w opisie</span>
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
              placeholder="wpisz frazę — Enter następna, Shift+Enter poprzednia"
              className="w-full rounded-md border border-slate-300 bg-white px-2.5 py-1.5 text-sm focus:border-violet-400 focus:outline-none focus:ring-2 focus:ring-violet-200"
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
          <div className="flex flex-wrap gap-1.5 border-b border-slate-100 px-5 py-2">
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

        <div className="grid min-h-0 flex-1 grid-cols-1 overflow-hidden lg:grid-cols-[minmax(280px,38%)_1fr]">
          <div className="flex flex-col gap-3 border-b border-slate-100 bg-slate-50 p-4 lg:border-b-0 lg:border-r">
            {image ? (
              <img
                src={image.url}
                alt={product?.name ?? ''}
                className="max-h-[52vh] w-full rounded-lg border border-slate-200 bg-white object-contain"
              />
            ) : (
              <div className="flex h-48 items-center justify-center rounded-lg border border-dashed border-slate-300 text-sm text-slate-400">
                Brak zdjęcia
              </div>
            )}
            {images.length > 1 && (
              <div className="flex flex-wrap gap-1.5">
                {images.map((img, i) => (
                  <button
                    key={img.id}
                    type="button"
                    onClick={() => setImageIndex(i)}
                    className={`h-14 w-14 overflow-hidden rounded border ${
                      i === imageIndex ? 'border-violet-600 ring-2 ring-violet-300' : 'border-slate-200'
                    }`}
                  >
                    <img src={img.url} alt="" className="h-full w-full object-cover" />
                  </button>
                ))}
              </div>
            )}
            {product && (
              <div className="grid grid-cols-2 gap-2 text-xs text-slate-700">
                <span>
                  Zakup: <b>{product.purchase_price} {product.currency ?? 'PLN'}</b>
                  {(product.currency ?? 'PLN').toUpperCase() !== 'PLN' && product.purchase_price_pln != null ? (
                    <span className="block text-[10px] text-slate-500">≈ {product.purchase_price_pln} zł</span>
                  ) : null}
                </span>
                <span>
                  Katalog: <b>{product.catalog_price_net} {product.currency ?? 'PLN'}</b>
                  {(product.currency ?? 'PLN').toUpperCase() !== 'PLN' && product.price_pln != null ? (
                    <span className="block text-[10px] text-slate-500">≈ {product.price_pln} zł</span>
                  ) : null}
                </span>
              </div>
            )}
          </div>

          <div ref={bodyRef} className="min-h-0 overflow-y-auto px-5 py-4">
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
        </div>
      </div>
    </div>
  )
}
