import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api, type Product } from '../lib/api'
import { productDisplayName } from '../lib/productLabel'

function descriptionProse(text: string | null | undefined): string {
  if (!text) return ''
  const cut = text.search(/\n\n(?:Specyfikacja|Cechy|Materiały|Normy|Certyfikaty|Zastosowanie)\s*:/)
  let body = cut >= 0 ? text.slice(0, cut).trim() : text.trim()
  body = body.replace(/([^\n])\s+(\d{1,2})\)\s+/g, '$1\n$2) ')
  return body
}

type Props = {
  productId: number | null
  onClose: () => void
}

export function ProductPreviewModal({ productId, onClose }: Props) {
  const [product, setProduct] = useState<Product | null>(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')

  useEffect(() => {
    if (productId == null) return
    setLoading(true)
    setError('')
    setProduct(null)
    void api<Product>(`/products/${productId}`)
      .then(setProduct)
      .catch((e) => setError(e instanceof Error ? e.message : 'Nie udało się pobrać produktu'))
      .finally(() => setLoading(false))
  }, [productId])

  useEffect(() => {
    if (productId == null) return
    function onKey(e: KeyboardEvent) {
      if (e.key === 'Escape') onClose()
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [productId, onClose])

  if (productId == null) return null

  const primaryImage = product?.images?.find((i) => i.is_primary) ?? product?.images?.[0]

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
      role="dialog"
      aria-modal="true"
      onClick={onClose}
    >
      <div
        className="max-h-[85vh] w-full max-w-2xl overflow-auto rounded-xl bg-white p-4 shadow-lg"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="mb-3 flex items-start justify-between gap-3">
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

        {product && (
          <>
            <div className="mb-3 flex flex-wrap gap-4 text-xs text-slate-700">
              <span>
                Katalog: <b>{product.catalog_price_net}</b> {product.currency ?? 'PLN'}
              </span>
              <span>
                Zakup: <b>{product.purchase_price}</b> {product.currency ?? 'PLN'}
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

            {product.description && (
              <p className="whitespace-pre-wrap text-sm text-slate-700">
                {descriptionProse(product.description)}
              </p>
            )}

            {(
              [
                ['Specyfikacja', product.enrichment_payload?.specs],
                ['Cechy', product.enrichment_payload?.features],
                ['Materiały', product.enrichment_payload?.materials],
                ['Normy', product.enrichment_payload?.norms],
                ['Certyfikaty', product.enrichment_payload?.certificates],
                ['Zastosowanie', product.enrichment_payload?.use_cases],
              ] as const
            ).map(([title, items]) =>
              Array.isArray(items) && items.length > 0 ? (
                <div key={title} className="mt-3">
                  <p className="mb-1 text-xs font-semibold text-slate-700">{title}</p>
                  <ul className="list-disc pl-5 text-xs text-slate-600">
                    {items.map((f) => (
                      <li key={f}>{f}</li>
                    ))}
                  </ul>
                </div>
              ) : null,
            )}

            {product.documents && product.documents.length > 0 && (
              <div className="mt-3">
                <p className="mb-1 text-xs font-semibold text-slate-700">Dokumenty</p>
                <ul className="space-y-1 text-xs">
                  {product.documents.map((d) => (
                    <li key={d.id}>
                      <a
                        href={d.url}
                        target="_blank"
                        rel="noreferrer"
                        className="text-blue-600 hover:underline"
                      >
                        {d.title || d.kind || 'PDF'}
                      </a>
                    </li>
                  ))}
                </ul>
              </div>
            )}

            <div className="mt-4 border-t border-slate-100 pt-3">
              <Link
                to={`/products/${product.id}`}
                className="text-xs text-blue-600 hover:underline"
                onClick={onClose}
              >
                Otwórz pełną kartę produktu →
              </Link>
            </div>
          </>
        )}
      </div>
    </div>
  )
}
