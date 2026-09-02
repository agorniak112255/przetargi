import { useCallback, useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useAuth } from '../auth'
import { CrossRefPanel } from '../components/CrossRefPanel'
import { PrestaSearchModal, type PrestaSearchResult } from '../components/PrestaSearchModal'
import {
  api,
  can,
  type EnrichmentBatch,
  type PrestaExportResult,
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
  manual: 'Ręcznie',
}

/** Opis tekstowy bez list — listy są osobno z enrichment_payload. */
function descriptionProse(text: string | null | undefined): string {
  if (!text) return ''
  const cut = text.search(/\n\n(?:Specyfikacja|Cechy|Materiały|Normy|Certyfikaty|Zastosowanie)\s*:/)
  let body = cut >= 0 ? text.slice(0, cut).trim() : text.trim()
  // LLM często zwraca „1) … 2) …” w jednym akapicie — każdy punkt w osobnej linii
  body = body.replace(/([^\n])\s+(\d{1,2})\)\s+/g, '$1\n$2) ')
  return body
}

export function ProductDetail() {
  const { id } = useParams()
  const { user } = useAuth()
  const canEnrich = can(user, 'price_lists.import')
  const canExportPresta = can(user, 'presta.export')
  const [p, setP] = useState<Detail | null>(null)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const [batch, setBatch] = useState<EnrichmentBatch | null>(null)
  const [imageModalUrl, setImageModalUrl] = useState<string | null>(null)
  const [prestaOpen, setPrestaOpen] = useState(false)
  const [prestaBusy, setPrestaBusy] = useState(false)
  const [prestaErr, setPrestaErr] = useState('')
  const [prestaItems, setPrestaItems] = useState<PrestaSearchResult[]>([])
  const [exportBusy, setExportBusy] = useState(false)
  const [exportMsg, setExportMsg] = useState('')
  const [priceHistory, setPriceHistory] = useState<
    {
      id: number
      catalog_price_net: string | null
      purchase_price: string | null
      source: string | null
      created_at: string
      price_list?: { manufacturer: string; version: string } | null
    }[]
  >([])
  const [categoryOptions, setCategoryOptions] = useState<{ value: string; label: string }[]>([])
  const [categoryBusy, setCategoryBusy] = useState(false)
  const [categoryMsg, setCategoryMsg] = useState('')

  const load = useCallback(async () => {
    if (!id) return
    setP(await api<Detail>(`/products/${id}`))
    const hist = await api<{ data: typeof priceHistory }>(`/products/${id}/price-history`)
    setPriceHistory(hist.data ?? [])
  }, [id])

  useEffect(() => {
    void load()
  }, [load])

  useEffect(() => {
    void api<{ data: { value: string; label: string }[] }>('/products/categories')
      .then((res) => setCategoryOptions(res.data ?? []))
      .catch(() => setCategoryOptions([]))
  }, [])

  async function saveCategory(next: string) {
    if (!id || !p) return
    const current = p.category ?? ''
    if (next === current) return
    setCategoryBusy(true)
    setCategoryMsg('')
    setErr('')
    try {
      const res = await api<{ category: string | null }>(`/products/${id}/category`, {
        method: 'PATCH',
        body: JSON.stringify({ category: next === '' ? null : next }),
      })
      setP({ ...p, category: res.category })
      setCategoryMsg('Zapisano')
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Nie udało się zapisać grupy')
    } finally {
      setCategoryBusy(false)
    }
  }

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

  async function searchPresta() {
    if (!id) return
    setPrestaBusy(true)
    setPrestaErr('')
    setPrestaOpen(true)
    try {
      const res = await api<PrestaSearchResult>(`/products/${id}/presta-search`, {
        method: 'POST',
        body: '{}',
      })
      setPrestaItems([res])
    } catch (ex) {
      setPrestaItems([])
      setPrestaErr(ex instanceof Error ? ex.message : 'Błąd wyszukiwania w Preście')
    } finally {
      setPrestaBusy(false)
    }
  }

  async function exportPresta() {
    if (!id) return
    const already = Boolean(p?.presta_export?.presta_id)
    const ok = window.confirm(
      already
        ? 'Produkt jest już w Preście. Zaktualizować opis, zdjęcia, rozmiary i termin „Na zamówienie”?'
        : 'Wysłać ten produkt do Presty? Wejdą opis, zdjęcia, rozmiary z opakowania i termin „Na zamówienie”.',
    )
    if (!ok) return
    setExportBusy(true)
    setErr('')
    setExportMsg('')
    try {
      const res = await api<PrestaExportResult>(`/products/${id}/presta-export`, {
        method: 'POST',
        body: JSON.stringify({ force: already }),
      })
      const missing =
        res.sizes_missing.length > 0 ? ` · brak atrybutów: ${res.sizes_missing.join(', ')}` : ''
      setExportMsg(
        res.action === 'exists'
          ? `Już w Preście (#${res.presta_id}).`
          : `Wysłano do Presty (#${res.presta_id}, ${res.action}` +
              (res.sizes.length > 0 ? `, rozmiary ${res.sizes.join('/')}` : '') +
              (res.images > 0 ? `, zdjęcia ${res.images}` : '') +
              `)${missing}`,
      )
      await load()
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Błąd wysyłki do Presty')
    } finally {
      setExportBusy(false)
    }
  }

  if (!p) return <p className="text-sm text-slate-500">Ładowanie…</p>

  const status = p.enrichment_status ?? 'none'
  const prose = descriptionProse(p.description)
  const currency = p.currency?.trim() || 'PLN'

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
          <div className="mb-2 flex max-w-xl flex-wrap items-center gap-2">
            <label htmlFor="product-group" className="shrink-0 text-xs font-semibold text-slate-600">
              Grupa
            </label>
            <select
              id="product-group"
              className="min-w-[16rem] flex-1 rounded border border-slate-300 bg-white px-2 py-1.5 text-xs"
              value={p.category ?? ''}
              disabled={categoryBusy}
              onChange={(e) => void saveCategory(e.target.value)}
            >
              <option value="">— brak —</option>
              {p.category
                && !categoryOptions.some((o) => o.value === p.category)
                && <option value={p.category}>{p.category}</option>}
              {categoryOptions.map((o) => (
                <option key={o.value} value={o.value}>
                  {o.label}
                </option>
              ))}
            </select>
            <span className="text-xs text-slate-500">
              {categoryBusy ? 'Zapisuję…' : categoryMsg}
            </span>
          </div>
          <p className="text-xs text-slate-500">
            Opis/zdjęcia: <b>{STATUS_LABEL[status] ?? status}</b>
            {p.enriched_at ? ` · ${new Date(p.enriched_at).toLocaleString('pl-PL')}` : ''}
          </p>
        </div>
        <div className="flex flex-wrap gap-2">
          <a
            href="#cross-ref"
            className="rounded border border-emerald-600 px-3 py-2 text-xs font-semibold text-emerald-800 hover:bg-emerald-50"
          >
            Cross-ref po SKU
          </a>
          {p.substitutes?.[0]?.substitute_product_id && (
            <Link
              to={`/products/compare?a=${p.id}&b=${p.substitutes[0].substitute_product_id}`}
              className="rounded border border-slate-300 px-3 py-2 text-xs hover:bg-slate-50"
            >
              Porównaj z zamiennikiem
            </Link>
          )}
          {canEnrich && (
            <button
              type="button"
              disabled={busy || status === 'queued'}
              onClick={() =>
                void enrich(
                  status === 'done'
                    || status === 'failed'
                    || status === 'running'
                    || status === 'manual',
                )
              }
              className="rounded bg-blue-600 px-3 py-2 text-xs text-white disabled:opacity-50"
            >
              {busy
                ? 'Startuję…'
                : status === 'running'
                  ? 'Odblokuj i pobierz ponownie'
                  : status === 'done'
                    ? 'Pobierz ponownie'
                    : 'Pobierz opis i zdjęcia'}
            </button>
          )}
          {canEnrich && (
            <button
              type="button"
              disabled={prestaBusy}
              onClick={() => void searchPresta()}
              className="rounded bg-emerald-700 px-3 py-2 text-xs text-white disabled:opacity-50"
            >
              {prestaBusy ? 'Szukam…' : 'Wyszukaj w Presta'}
            </button>
          )}
          {canExportPresta && (
            <button
              type="button"
              disabled={exportBusy}
              onClick={() => void exportPresta()}
              className="rounded bg-violet-700 px-3 py-2 text-xs text-white disabled:opacity-50"
              title="Wysyła kartę do sklepu: opis, rozmiary, termin na zamówienie"
            >
              {exportBusy
                ? 'Wysyłam…'
                : p.presta_export?.presta_id
                  ? 'Aktualizuj w Preście'
                  : 'Wyślij do Presty'}
            </button>
          )}
        </div>
      </div>

      <div id="cross-ref" className="mt-3">
        <CrossRefPanel initialCode={p.sku} />
      </div>

      {exportMsg && <p className="mt-2 rounded bg-green-50 px-3 py-2 text-xs text-green-800">{exportMsg}</p>}
      {err && <p className="mt-2 rounded bg-red-50 px-3 py-2 text-xs text-red-700">{err}</p>}
      {p.presta_export?.url && (
        <p className="mt-2 text-xs text-slate-500">
          W sklepie:{' '}
          <a href={p.presta_export.url} target="_blank" rel="noreferrer" className="text-violet-700 underline">
            karta Presta #{p.presta_export.presta_id}
          </a>
        </p>
      )}
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
          Cena kat. netto: <b>{p.catalog_price_net} {currency}</b>
          {p.price_change_percent != null && (
            <span
              className={`ml-2 text-xs ${
                p.price_change_percent > 0 ? 'text-red-600' : 'text-emerald-600'
              }`}
            >
              {p.price_change_percent > 0 ? '+' : ''}
              {p.price_change_percent}% vs poprzedni cennik
            </span>
          )}
        </div>
        <div className="rounded-xl bg-white p-4 shadow-sm text-sm">
          Zakup: <b>{p.purchase_price} {currency}</b>
        </div>
        <div className="rounded-xl bg-white p-4 shadow-sm text-sm">
          Upust:{' '}
          <b>{p.discount_percent != null && p.discount_percent !== '' ? `${p.discount_percent}%` : '—'}</b>
        </div>
      </div>

      {(p.special_prices?.length ?? 0) > 0 && (
        <div className="mb-4 rounded-xl bg-white p-4 shadow-sm">
          <h2 className="mb-2 text-sm font-semibold">Ceny specjalne (kontrakty klientów)</h2>
          <table className="w-full text-left text-xs">
            <thead>
              <tr className="border-b bg-slate-50">
                <th className="p-2">Klient</th>
                <th className="p-2">Cena</th>
                <th className="p-2">Od</th>
                <th className="p-2">Kontrakt</th>
              </tr>
            </thead>
            <tbody>
              {p.special_prices!.map((s) => (
                <tr key={s.id} className="border-b">
                  <td className="p-2">{s.client_name}</td>
                  <td className="p-2">
                    {s.price} {s.currency}
                  </td>
                  <td className="p-2">{s.valid_from ?? '—'}</td>
                  <td className="p-2">{s.contract_ref ?? '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {priceHistory.length > 0 && (
        <div className="mb-4 rounded-xl bg-white p-4 shadow-sm">
          <h2 className="mb-2 text-sm font-semibold">Historia cen</h2>
          <table className="w-full text-left text-xs">
            <thead>
              <tr className="border-b bg-slate-50">
                <th className="p-2">Data</th>
                <th className="p-2">Kat. netto</th>
                <th className="p-2">Zakup</th>
                <th className="p-2">Źródło</th>
              </tr>
            </thead>
            <tbody>
              {priceHistory.map((h) => (
                <tr key={h.id} className="border-b">
                  <td className="p-2">{new Date(h.created_at).toLocaleString('pl-PL')}</td>
                  <td className="p-2">
                    {h.catalog_price_net != null ? `${h.catalog_price_net} ${currency}` : '—'}
                  </td>
                  <td className="p-2">
                    {h.purchase_price != null ? `${h.purchase_price} ${currency}` : '—'}
                  </td>
                  <td className="p-2">
                    {h.price_list
                      ? `${h.price_list.manufacturer} ${h.price_list.version}`
                      : h.source ?? '—'}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

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
          {p.enrichment_payload?.attributes && (
            <div className="mb-3 rounded border border-slate-100 bg-slate-50 px-3 py-2">
              <p className="mb-1 text-xs font-semibold text-slate-700">Atrybuty BHP</p>
              <dl className="grid grid-cols-2 gap-x-3 gap-y-1 text-[11px] text-slate-600 sm:grid-cols-3">
                {(
                  [
                    ['Kategoria', p.enrichment_payload.attributes.kategoria_bhp],
                    ['Materiał', p.enrichment_payload.attributes.material],
                    ['Klasa', p.enrichment_payload.attributes.klasa_ochrony],
                    ['EN 388', p.enrichment_payload.attributes.poziomy_en388],
                    ['Rozmiar', p.enrichment_payload.attributes.rozmiar],
                    ['Kod', p.enrichment_payload.attributes.kod_producenta],
                  ] as const
                ).map(([label, val]) =>
                  val ? (
                    <div key={label}>
                      <dt className="text-slate-400">{label}</dt>
                      <dd className="font-medium text-slate-800">{val}</dd>
                    </div>
                  ) : null,
                )}
              </dl>
              {(p.enrichment_payload.attributes.normy_en?.length ?? 0) > 0 && (
                <p className="mt-1 text-[11px] text-slate-600">
                  Normy: {p.enrichment_payload.attributes.normy_en!.join(', ')}
                </p>
              )}
            </div>
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
            {(p.substitutes ?? []).map((s) => (
              <tr key={s.id} className="border-b">
                <td className="p-2">{s.substitute_product?.sku}</td>
                <td className="p-2">{s.substitute_product?.name}</td>
                <td className="p-2">{s.type}</td>
                <td className="p-2">{s.match_percent}%</td>
                <td className="p-2">
                  <span>{s.approval_status}</span>
                  {s.substitute_product_id && (
                    <Link
                      to={`/products/compare?a=${p.id}&b=${s.substitute_product_id}`}
                      className="ml-2 text-[10px] font-semibold text-emerald-800 hover:underline"
                    >
                      Porównaj
                    </Link>
                  )}
                </td>
              </tr>
            ))}
            {(p.substitutes ?? []).length === 0 && (
              <tr>
                <td colSpan={5} className="p-3 text-slate-400">
                  Brak zamienników dla tego głównego.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
      <PrestaSearchModal
        open={prestaOpen}
        items={prestaItems}
        loading={prestaBusy}
        error={prestaErr}
        onClose={() => setPrestaOpen(false)}
        onApplied={() => {
          void load()
        }}
      />
    </div>
  )
}
