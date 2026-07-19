import { useCallback, useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useAuth } from '../auth'
import {
  api,
  can,
  type EnrichmentBatch,
  type Product,
  type Substitute,
} from '../lib/api'

type Detail = Product & { substitutes: Substitute[] }

const STATUS_LABEL: Record<string, string> = {
  none: 'Brak danych',
  queued: 'W kolejce',
  running: 'Pobieranie…',
  done: 'Gotowe',
  failed: 'Błąd',
}

/** Opis tekstowy bez list — listy są osobno z enrichment_payload. */
function descriptionProse(text: string | null | undefined): string {
  if (!text) return ''
  const cut = text.search(/\n\n(?:Specyfikacja|Cechy|Materiały|Normy|Certyfikaty|Zastosowanie)\s*:/)
  return cut >= 0 ? text.slice(0, cut).trim() : text
}

export function ProductDetail() {
  const { id } = useParams()
  const { user } = useAuth()
  const canEnrich = can(user, 'price_lists.import')
  const [p, setP] = useState<Detail | null>(null)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const [batch, setBatch] = useState<EnrichmentBatch | null>(null)
  const [imageModalUrl, setImageModalUrl] = useState<string | null>(null)

  const load = useCallback(async () => {
    if (!id) return
    setP(await api<Detail>(`/products/${id}`))
  }, [id])

  useEffect(() => {
    void load()
  }, [load])

  useEffect(() => {
    if (!batch || batch.status === 'done' || batch.status === 'failed') return
    const t = window.setInterval(() => {
      void api<EnrichmentBatch>(`/product-enrichment-batches/${batch.id}`).then((b) => {
        setBatch(b)
        if (b.status === 'done' || b.status === 'failed') {
          void load()
        }
      })
    }, 2000)
    return () => window.clearInterval(t)
  }, [batch, load])

  useEffect(() => {
    if (!imageModalUrl) return
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setImageModalUrl(null)
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [imageModalUrl])

  async function enrich(force = false) {
    if (!id) return
    setBusy(true)
    setErr('')
    try {
      const res = await api<{ batch: EnrichmentBatch; product?: Detail; images_count?: number }>(
        `/products/${id}/enrich`,
        {
          method: 'POST',
          body: JSON.stringify({ force }),
        },
      )
      setBatch(res.batch)
      if (res.product) {
        setP(res.product)
      } else {
        await load()
      }
      if ((res.images_count ?? res.product?.images?.length ?? 0) === 0) {
        setErr('Opis pobrany, ale nie udało się zapisać zdjęcia. Spróbuj ponownie za chwilę.')
      }
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Błąd enrichmentu')
    } finally {
      setBusy(false)
    }
  }

  if (!p) return <p className="text-sm text-slate-500">Ładowanie…</p>

  const status = p.enrichment_status ?? 'none'
  const prose = descriptionProse(p.description)

  return (
    <div>
      <Link to="/products" className="text-xs text-blue-600 hover:underline">
        ← Produkty
      </Link>
      <div className="mt-2 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold">{p.name}</h1>
          <p className="mb-2 text-sm text-slate-500">
            {p.sku} · {p.manufacturer} · {p.norms ?? 'bez normy'}
          </p>
          <p className="text-xs text-slate-500">
            Opis/zdjęcia: <b>{STATUS_LABEL[status] ?? status}</b>
            {p.enriched_at ? ` · ${new Date(p.enriched_at).toLocaleString('pl-PL')}` : ''}
          </p>
        </div>
        {canEnrich && (
          <div className="flex flex-wrap gap-2">
            <button
              type="button"
              disabled={busy || status === 'queued' || status === 'running'}
              onClick={() => void enrich(status === 'done' || status === 'failed')}
              className="rounded bg-blue-600 px-3 py-2 text-xs text-white disabled:opacity-50"
            >
              {busy
                ? 'Startuję…'
                : status === 'done'
                  ? 'Pobierz ponownie'
                  : 'Pobierz opis i zdjęcia'}
            </button>
          </div>
        )}
      </div>

      {err && <p className="mt-2 rounded bg-red-50 px-3 py-2 text-xs text-red-700">{err}</p>}
      {p.enrichment_error && status === 'failed' && (
        <p className="mt-2 rounded bg-red-50 px-3 py-2 text-xs text-red-700">{p.enrichment_error}</p>
      )}
      {batch && (batch.status === 'queued' || batch.status === 'running') && (
        <p className="mt-2 text-xs text-slate-500">
          Postęp: {batch.done + batch.failed}/{batch.total} ({batch.progress_percent}%)
        </p>
      )}

      <div className="mb-4 mt-4 grid gap-3 sm:grid-cols-3">
        <div className="rounded-xl bg-white p-4 shadow-sm text-sm">
          Cena kat. netto: <b>{p.catalog_price_net} zł</b>
        </div>
        <div className="rounded-xl bg-white p-4 shadow-sm text-sm">
          Zakup: <b>{p.purchase_price} zł</b>
        </div>
        <div className="rounded-xl bg-white p-4 shadow-sm text-sm">
          Stan: <b>{p.stock}</b>
        </div>
      </div>

      {(p.description ||
        (p.images && p.images.length > 0) ||
        (p.documents && p.documents.length > 0) ||
        p.enrichment_payload) && (
        <div className="mb-4 rounded-xl bg-white p-4 shadow-sm">
          <h2 className="mb-2 text-sm font-semibold">Opis i zdjęcia</h2>
          {p.images && p.images.length > 0 ? (
            <div className="mb-3 flex flex-wrap gap-2">
              {p.images.map((img) => (
                <button
                  key={img.id}
                  type="button"
                  onClick={() => setImageModalUrl(img.url)}
                  className="rounded border border-slate-200 bg-slate-50 p-0 hover:border-blue-400"
                  title="Powiększ"
                >
                  <img
                    src={img.url}
                    alt={p.name}
                    className="h-32 w-32 object-contain"
                    onError={(e) => {
                      const el = e.currentTarget
                      el.style.display = 'none'
                      setErr(`Nie można wyświetlić zdjęcia (${img.url}). Sprawdź storage:link / Apache.`)
                    }}
                  />
                </button>
              ))}
            </div>
          ) : p.enrichment_status === 'done' ? (
            <p className="mb-3 text-xs text-amber-700">Brak zapisanego zdjęcia — użyj „Pobierz ponownie”.</p>
          ) : null}
          {prose && (
            <p className="mb-3 whitespace-pre-wrap text-sm text-slate-700">{prose}</p>
          )}
          {[
            ['Specyfikacja', p.enrichment_payload?.specs],
            ['Cechy', p.enrichment_payload?.features],
            ['Materiały', p.enrichment_payload?.materials],
            ['Normy', p.enrichment_payload?.norms],
            ['Certyfikaty', p.enrichment_payload?.certificates],
            ['Zastosowanie', p.enrichment_payload?.use_cases],
          ].map(([title, items]) =>
            Array.isArray(items) && items.length > 0 ? (
              <div key={String(title)} className="mb-3">
                <p className="mb-1 text-xs font-semibold text-slate-700">{title}</p>
                <ul className="list-disc pl-5 text-xs text-slate-600">
                  {items.map((f) => (
                    <li key={f}>{f}</li>
                  ))}
                </ul>
              </div>
            ) : null,
          )}
          {p.documents && p.documents.length > 0 && (
            <div className="mb-3">
              <p className="mb-1 text-xs font-semibold text-slate-700">Pliki PDF</p>
              <ul className="space-y-1 text-xs text-slate-600">
                {p.documents.map((doc) => (
                  <li key={doc.id}>
                    <a
                      href={doc.url}
                      target="_blank"
                      rel="noreferrer"
                      className="text-blue-600 hover:underline"
                    >
                      {doc.title || 'Dokument.pdf'}
                    </a>
                    <span className="ml-1 text-slate-400">
                      (
                      {doc.kind === 'certificate'
                        ? 'certyfikat'
                        : doc.kind === 'datasheet'
                          ? 'karta'
                          : 'PDF'}
                      {doc.size_bytes ? ` · ${Math.max(1, Math.round(doc.size_bytes / 1024))} KB` : ''}
                      )
                    </span>
                  </li>
                ))}
              </ul>
            </div>
          )}
          {p.enrichment_payload?.source_urls && p.enrichment_payload.source_urls.length > 0 && (
            <p className="text-[11px] text-slate-400">
              Źródła:{' '}
              {p.enrichment_payload.source_urls.slice(0, 3).map((u, i) => (
                <span key={u}>
                  {i > 0 ? ' · ' : ''}
                  <a className="text-blue-600 hover:underline" href={u} target="_blank" rel="noreferrer">
                    {u.replace(/^https?:\/\//, '').slice(0, 40)}
                  </a>
                </span>
              ))}
            </p>
          )}
        </div>
      )}

      {imageModalUrl && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4"
          role="dialog"
          aria-modal="true"
          onClick={() => setImageModalUrl(null)}
        >
          <div
            className="relative max-h-[90vh] max-w-[90vw]"
            onClick={(e) => e.stopPropagation()}
          >
            <button
              type="button"
              onClick={() => setImageModalUrl(null)}
              className="absolute -right-2 -top-2 rounded bg-white px-2 py-1 text-xs shadow"
            >
              Zamknij
            </button>
            <img
              src={imageModalUrl}
              alt={p.name}
              className="max-h-[85vh] max-w-[90vw] rounded bg-white object-contain"
            />
          </div>
        </div>
      )}

      <h2 className="mb-2 text-sm font-semibold">Zamienniki dla tego produktu głównego</h2>
      <div className="rounded-xl bg-white p-4 shadow-sm">
        <table className="w-full text-left text-xs">
          <thead>
            <tr className="border-b bg-slate-50">
              <th className="p-2">Kod</th>
              <th className="p-2">Nazwa</th>
              <th className="p-2">Typ</th>
              <th className="p-2">AI</th>
              <th className="p-2">Status</th>
            </tr>
          </thead>
          <tbody>
            {p.substitutes.map((s) => (
              <tr key={s.id} className="border-b">
                <td className="p-2">{s.substitute_product?.sku}</td>
                <td className="p-2">{s.substitute_product?.name}</td>
                <td className="p-2">{s.type}</td>
                <td className="p-2">{s.match_percent}%</td>
                <td className="p-2">{s.approval_status}</td>
              </tr>
            ))}
            {p.substitutes.length === 0 && (
              <tr>
                <td colSpan={5} className="p-3 text-slate-400">
                  Brak zamienników dla tego głównego.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  )
}
