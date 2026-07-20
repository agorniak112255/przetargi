import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../auth'
import { ProductPreviewModal } from '../components/ProductPreviewModal'
import { api, can, type EnrichmentBatch, type Product } from '../lib/api'

type Page = {
  data: Product[]
  current_page: number
  last_page: number
  per_page: number
  total: number
  from: number | null
  to: number | null
}

const STATUS_LABEL: Record<string, string> = {
  none: 'Brak',
  queued: 'Kolejka',
  running: 'Pobieranie',
  done: 'OK',
  failed: 'Błąd',
}

function pageNumbers(current: number, last: number): Array<number | '…'> {
  if (last <= 7) {
    return Array.from({ length: last }, (_, i) => i + 1)
  }
  const pages = new Set<number>([1, last, current, current - 1, current + 1])
  if (current <= 3) {
    ;[2, 3, 4].forEach((n) => pages.add(n))
  }
  if (current >= last - 2) {
    ;[last - 1, last - 2, last - 3].forEach((n) => pages.add(n))
  }
  const sorted = [...pages].filter((n) => n >= 1 && n <= last).sort((a, b) => a - b)
  const out: Array<number | '…'> = []
  for (let i = 0; i < sorted.length; i++) {
    if (i > 0 && sorted[i] - sorted[i - 1] > 1) out.push('…')
    out.push(sorted[i])
  }
  return out
}

function hasDescription(p: Product): boolean {
  return Boolean(p.description && p.description.trim() !== '')
}

type SortKey =
  | 'sku'
  | 'name'
  | 'manufacturer'
  | 'catalog_price_net'
  | 'currency'
  | 'discount_percent'
  | 'description'
  | 'images_count'
  | 'enrichment_status'

function SortTh({
  label,
  col,
  sort,
  dir,
  onSort,
}: {
  label: string
  col: SortKey
  sort: SortKey
  dir: 'asc' | 'desc'
  onSort: (col: SortKey) => void
}) {
  const active = sort === col
  return (
    <th className="p-2">
      <button
        type="button"
        onClick={() => onSort(col)}
        className={`inline-flex items-center gap-1 font-semibold hover:text-blue-700 ${
          active ? 'text-blue-700' : 'text-slate-700'
        }`}
      >
        {label}
        <span className="text-[10px] text-slate-400">{active ? (dir === 'asc' ? '▲' : '▼') : '◇'}</span>
      </button>
    </th>
  )
}

export function Products() {
  const { user } = useAuth()
  const canEnrich = can(user, 'price_lists.import')
  const [q, setQ] = useState('')
  const [debouncedQ, setDebouncedQ] = useState('')
  const [manufacturer, setManufacturer] = useState('')
  const [manufacturers, setManufacturers] = useState<string[]>([])
  const [aiQuery, setAiQuery] = useState('')
  const [aiMode, setAiMode] = useState(false)
  const [aiBusy, setAiBusy] = useState(false)
  const [page, setPage] = useState(1)
  const [sort, setSort] = useState<SortKey>('name')
  const [dir, setDir] = useState<'asc' | 'desc'>('asc')
  const [result, setResult] = useState<Page | null>(null)
  const [loading, setLoading] = useState(false)
  const [enrichBusy, setEnrichBusy] = useState(false)
  const [enrichRowId, setEnrichRowId] = useState<number | null>(null)
  const [batch, setBatch] = useState<EnrichmentBatch | null>(null)
  const [selected, setSelected] = useState<Record<number, boolean>>({})
  const [msg, setMsg] = useState('')
  const [err, setErr] = useState('')
  const [previewId, setPreviewId] = useState<number | null>(null)
  const [imageModal, setImageModal] = useState<{ name: string; url: string } | null>(null)

  useEffect(() => {
    const t = window.setTimeout(() => {
      setDebouncedQ(q.trim())
      setPage(1)
    }, 300)
    return () => window.clearTimeout(t)
  }, [q])

  useEffect(() => {
    void api<{ data: string[] }>('/products/manufacturers')
      .then((res) => setManufacturers(res.data ?? []))
      .catch(() => setManufacturers([]))
  }, [])

  useEffect(() => {
    if (!imageModal) return
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setImageModal(null)
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [imageModal])

  function buildParams(pageNum = page): URLSearchParams {
    const params = new URLSearchParams({
      page: String(pageNum),
      per_page: '100',
      sort,
      dir,
    })
    if (debouncedQ) params.set('q', debouncedQ)
    if (manufacturer) params.set('manufacturer', manufacturer)
    return params
  }

  function onSort(col: SortKey) {
    if (sort === col) {
      setDir((d) => (d === 'asc' ? 'desc' : 'asc'))
    } else {
      setSort(col)
      setDir('asc')
    }
    setPage(1)
  }

  useEffect(() => {
    if (aiMode) return
    setLoading(true)
    void api<Page>(`/products?${buildParams()}`)
      .then(setResult)
      .catch(() => {
        /* ignore */
      })
      .finally(() => setLoading(false))
    // eslint-disable-next-line react-hooks/exhaustive-deps -- buildParams uses current sort/dir/page/q/manufacturer
  }, [debouncedQ, manufacturer, page, sort, dir, aiMode])

  async function runAiSearch() {
    const query = aiQuery.trim()
    if (query.length < 3) {
      setErr('Podaj wymaganie (min. 3 znaki), np. rękawice do pracy z amoniakiem')
      return
    }
    setAiBusy(true)
    setErr('')
    setMsg('Szukam w katalogu przez AI…')
    const controller = new AbortController()
    const timer = window.setTimeout(() => controller.abort(), 75_000)
    try {
      const res = await api<{ query: string; total: number; products: Product[] }>(
        '/products/ai-search',
        {
          method: 'POST',
          body: JSON.stringify({ query, limit: 40 }),
          signal: controller.signal,
        },
      )
      setAiMode(true)
      setResult({
        data: res.products,
        current_page: 1,
        last_page: 1,
        per_page: res.products.length || 40,
        total: res.total,
        from: res.total > 0 ? 1 : null,
        to: res.total > 0 ? res.total : null,
      })
      setMsg(`AI znalazło ${res.total} produktów dla: „${res.query}”`)
    } catch (ex) {
      const aborted =
        (ex instanceof DOMException && ex.name === 'AbortError') ||
        (ex instanceof Error && /abort/i.test(ex.message))
      setErr(
        aborted
          ? 'Wyszukiwanie AI przekroczyło limit czasu (75 s). Sprawdź klucz/model w Ustawieniach AI i spróbuj krótszego wymagania.'
          : ex instanceof Error
            ? ex.message
            : 'Błąd wyszukiwania AI',
      )
      setMsg('')
    } finally {
      window.clearTimeout(timer)
      setAiBusy(false)
    }
  }

  function clearAiSearch() {
    setAiMode(false)
    setAiQuery('')
    setMsg('')
    setErr('')
  }

  useEffect(() => {
    if (!batch || batch.status === 'done' || batch.status === 'failed') return
    const t = window.setInterval(() => {
      void api<EnrichmentBatch>(`/product-enrichment-batches/${batch.id}`).then((b) => {
        setBatch(b)
        if (b.status === 'done' || b.status === 'failed') {
          void api<Page>(`/products?${buildParams()}`).then(setResult)
        }
      })
    }, 2000)
    return () => window.clearInterval(t)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [batch, page, debouncedQ, sort, dir])

  async function enrichIds(ids: number[], force = false) {
    if (ids.length === 0) return
    setEnrichBusy(true)
    setErr('')
    setMsg('')
    try {
      const res = await api<{ batch: EnrichmentBatch }>('/products/enrich', {
        method: 'POST',
        body: JSON.stringify({ product_ids: ids, force }),
      })
      setBatch(res.batch)
      setMsg(`W kolejce: ${res.batch.total} produktów (opisy i zdjęcia).`)
      setSelected({})
      setResult(await api<Page>(`/products?${buildParams()}`))
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Błąd wzbogacania')
    } finally {
      setEnrichBusy(false)
      setEnrichRowId(null)
    }
  }

  function toggleSelected(id: number) {
    setSelected((prev) => {
      const next = { ...prev }
      if (next[id]) delete next[id]
      else next[id] = true
      return next
    })
  }

  function toggleSelectAllVisible() {
    const ids = (result?.data ?? []).map((p) => p.id)
    if (ids.length === 0) return
    const allOn = ids.every((id) => selected[id])
    setSelected((prev) => {
      const next = { ...prev }
      if (allOn) {
        for (const id of ids) delete next[id]
      } else {
        for (const id of ids) next[id] = true
      }
      return next
    })
  }

  async function enrichOne(p: Product, force = false) {
    setEnrichRowId(p.id)
    setEnrichBusy(true)
    setErr('')
    try {
      const res = await api<{ batch: EnrichmentBatch }>(`/products/${p.id}/enrich`, {
        method: 'POST',
        body: JSON.stringify({ force }),
      })
      setBatch(res.batch)
      setMsg(`Pobieranie: ${p.sku}`)
      setResult(await api<Page>(`/products?${buildParams()}`))
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Błąd wzbogacania')
    } finally {
      setEnrichBusy(false)
      setEnrichRowId(null)
    }
  }

  const pages = result ? pageNumbers(result.current_page, result.last_page) : []
  const visibleIds = (result?.data ?? []).map((p) => p.id)
  const pendingVisible = (result?.data ?? []).filter(
    (p) => (p.enrichment_status ?? 'none') !== 'done',
  )
  const selectedIds = Object.keys(selected)
    .map(Number)
    .filter((id) => selected[id])
  const allVisibleSelected =
    visibleIds.length > 0 && visibleIds.every((id) => selected[id])
  const batchActive = batch?.status === 'queued' || batch?.status === 'running'
  const tableCols = 9 + (aiMode ? 1 : 0) + (canEnrich ? 2 : 0)

  return (
    <div>
      <div className="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold">Produkty</h1>
          {result && (
            <p className="mt-1 text-xs text-slate-500">
              Łącznie <span className="font-medium text-slate-700">{result.total}</span>
              {result.from != null && result.to != null
                ? ` · wyświetlono ${result.from}–${result.to}`
                : ''}
              {' · '}
              {result.per_page}/stronę
              {loading ? ' · ładowanie…' : ''}
            </p>
          )}
        </div>
        <div className="flex flex-wrap items-center gap-2">
          {canEnrich && (
            <>
              <button
                type="button"
                disabled={enrichBusy || batchActive || selectedIds.length === 0}
                onClick={() => void enrichIds(selectedIds)}
                className="rounded bg-blue-600 px-3 py-2 text-xs text-white disabled:opacity-50"
                title="Pobierz opisy i zdjęcia dla zaznaczonych produktów"
              >
                Pobierz zaznaczone{selectedIds.length > 0 ? ` (${selectedIds.length})` : ''}
              </button>
              <button
                type="button"
                disabled={enrichBusy || batchActive || selectedIds.length === 0}
                onClick={() => void enrichIds(selectedIds, true)}
                className="rounded border border-slate-300 px-3 py-2 text-xs disabled:opacity-50"
                title="Wymusza ponowne pobranie dla zaznaczonych"
              >
                Ponów zaznaczone
              </button>
              <button
                type="button"
                disabled={enrichBusy || batchActive || pendingVisible.length === 0}
                onClick={() => void enrichIds(pendingVisible.map((p) => p.id))}
                className="rounded border border-slate-300 px-3 py-2 text-xs disabled:opacity-50"
                title="Pobierz opisy dla widocznych bez danych"
              >
                Pobierz widoczne bez opisu
              </button>
            </>
          )}
          <select
            className="rounded border border-slate-300 px-3 py-2 text-sm"
            value={manufacturer}
            disabled={aiMode}
            onChange={(e) => {
              setManufacturer(e.target.value)
              setPage(1)
            }}
            title="Filtr producenta"
          >
            <option value="">Wszyscy producenci</option>
            {manufacturers.map((m) => (
              <option key={m} value={m}>
                {m}
              </option>
            ))}
          </select>
          <input
            className="w-full max-w-md rounded border border-slate-300 px-3 py-2 text-sm"
            placeholder="Szukaj kod, nazwa, producent…"
            value={q}
            disabled={aiMode}
            onChange={(e) => setQ(e.target.value)}
          />
        </div>
      </div>

      <div className="mb-4 rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
        <label className="mb-1 block text-xs font-medium text-slate-600">
          Wymaganie dla AI (np. rękawice do pracy z amoniakiem)
        </label>
        <div className="flex flex-wrap items-end gap-2">
          <textarea
            className="min-h-[2.5rem] w-full max-w-2xl flex-1 rounded border border-slate-300 px-3 py-2 text-sm"
            rows={2}
            placeholder="Opisz zastosowanie / substancję / normy — AI wyszuka w katalogu po opisach"
            value={aiQuery}
            onChange={(e) => setAiQuery(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault()
                void runAiSearch()
              }
            }}
          />
          <button
            type="button"
            disabled={aiBusy || aiQuery.trim().length < 3}
            onClick={() => void runAiSearch()}
            className="rounded bg-indigo-600 px-3 py-2 text-xs text-white disabled:opacity-50"
          >
            {aiBusy ? 'Szukam…' : 'Szukaj AI'}
          </button>
          {aiMode && (
            <button
              type="button"
              disabled={aiBusy}
              onClick={clearAiSearch}
              className="rounded border border-slate-300 px-3 py-2 text-xs disabled:opacity-50"
            >
              Wyczyść AI
            </button>
          )}
        </div>
      </div>

      {msg && <p className="mb-2 rounded bg-green-50 px-3 py-2 text-xs text-green-800">{msg}</p>}
      {err && <p className="mb-2 rounded bg-red-50 px-3 py-2 text-xs text-red-700">{err}</p>}

      {batchActive && batch && (
        <div className="mb-3 rounded-xl border border-blue-200 bg-blue-50 px-4 py-3 text-xs text-blue-900">
          <p className="font-semibold">
            Pobieranie opisów/zdjęć — {batch.done + batch.failed}/{batch.total} (
            {batch.progress_percent}%)
          </p>
          <div className="mt-1 h-2 overflow-hidden rounded bg-blue-100">
            <div
              className="h-full animate-pulse bg-blue-500 transition-all"
              style={{ width: `${Math.max(6, batch.progress_percent)}%` }}
            />
          </div>
          <p className="mt-1">
            {batch.current_sku
              ? `Teraz: ${batch.current_sku}${batch.current_name ? ` — ${batch.current_name}` : ''}`
              : 'W kolejce…'}
            {batch.message ? ` · ${batch.message}` : ''}
          </p>
        </div>
      )}

      <div className="rounded-xl bg-white p-4 shadow-sm overflow-x-auto">
        <table className="w-full text-left text-xs">
          <thead>
            <tr className="border-b bg-slate-50">
              {canEnrich && (
                <th className="p-2 w-8">
                  <input
                    type="checkbox"
                    checked={allVisibleSelected}
                    onChange={toggleSelectAllVisible}
                    title="Zaznacz / odznacz widoczne"
                    aria-label="Zaznacz wszystkie widoczne"
                  />
                </th>
              )}
              {aiMode && <th className="p-2">Dopasowanie</th>}
              <SortTh label="Kod" col="sku" sort={sort} dir={dir} onSort={onSort} />
              <SortTh label="Nazwa" col="name" sort={sort} dir={dir} onSort={onSort} />
              <SortTh label="Producent" col="manufacturer" sort={sort} dir={dir} onSort={onSort} />
              <SortTh label="Netto" col="catalog_price_net" sort={sort} dir={dir} onSort={onSort} />
              <SortTh label="Waluta" col="currency" sort={sort} dir={dir} onSort={onSort} />
              <SortTh label="Upust" col="discount_percent" sort={sort} dir={dir} onSort={onSort} />
              <SortTh label="Opis" col="description" sort={sort} dir={dir} onSort={onSort} />
              <SortTh label="Zdjęcia" col="images_count" sort={sort} dir={dir} onSort={onSort} />
              <SortTh label="Status AI" col="enrichment_status" sort={sort} dir={dir} onSort={onSort} />
              {canEnrich && <th className="p-2">Akcja</th>}
            </tr>
          </thead>
          <tbody>
            {(result?.data ?? []).map((p) => {
              const status = p.enrichment_status ?? 'none'
              return (
                <tr key={p.id} className={`border-b ${selected[p.id] ? 'bg-blue-50/40' : ''}`}>
                  {canEnrich && (
                    <td className="p-2">
                      <input
                        type="checkbox"
                        checked={Boolean(selected[p.id])}
                        onChange={() => toggleSelected(p.id)}
                        aria-label={`Zaznacz ${p.sku}`}
                      />
                    </td>
                  )}
                  {aiMode && (
                    <td className="p-2">
                      <span
                        className="font-semibold text-indigo-700"
                        title={p.ai_match_reason ?? undefined}
                      >
                        {p.ai_match_percent != null ? `${p.ai_match_percent}%` : '—'}
                      </span>
                      {p.ai_match_reason && (
                        <p className="mt-0.5 max-w-[10rem] truncate text-[10px] text-slate-500" title={p.ai_match_reason}>
                          {p.ai_match_reason}
                        </p>
                      )}
                    </td>
                  )}
                  <td className="p-2">
                    <Link className="text-blue-600 hover:underline" to={`/products/${p.id}`}>
                      {p.sku}
                    </Link>
                  </td>
                  <td className="p-2 max-w-[14rem] truncate" title={p.name}>
                    {p.name}
                  </td>
                  <td className="p-2">{p.manufacturer}</td>
                  <td className="p-2">{p.catalog_price_net}</td>
                  <td className="p-2">{p.currency ?? 'PLN'}</td>
                  <td className="p-2">
                    {p.discount_percent != null ? `${p.discount_percent}%` : '—'}
                  </td>
                  <td className="p-2">
                    {hasDescription(p) ? (
                      <button
                        type="button"
                        onClick={() => setPreviewId(p.id)}
                        className="rounded border border-green-300 bg-green-50 px-2 py-1 text-[11px] text-green-800 hover:bg-green-100"
                      >
                        Opis
                      </button>
                    ) : (
                      <span className="text-slate-400">—</span>
                    )}
                  </td>
                  <td className="p-2">
                    {(p.images_count ?? 0) > 0 && p.images?.[0]?.url ? (
                      <button
                        type="button"
                        onClick={() =>
                          setImageModal({ name: p.name, url: p.images![0].url })
                        }
                        className="rounded border border-blue-300 bg-blue-50 px-2 py-1 text-[11px] text-blue-800 hover:bg-blue-100"
                      >
                        Zdjęcie
                      </button>
                    ) : (
                      <span className="text-slate-400">—</span>
                    )}
                  </td>
                  <td className="p-2">
                    <span
                      className={
                        status === 'done'
                          ? 'text-green-700'
                          : status === 'failed'
                            ? 'text-red-600'
                            : status === 'running' || status === 'queued'
                              ? 'text-blue-700'
                              : 'text-slate-400'
                      }
                      title={p.enrichment_error ?? undefined}
                    >
                      {STATUS_LABEL[status] ?? status}
                    </span>
                  </td>
                  {canEnrich && (
                    <td className="p-2">
                      <button
                        type="button"
                        disabled={
                          enrichBusy ||
                          batchActive ||
                          status === 'queued' ||
                          status === 'running'
                        }
                        onClick={() => void enrichOne(p, status === 'done')}
                        className="rounded border border-slate-300 px-2 py-1 text-[11px] disabled:opacity-50"
                      >
                        {enrichRowId === p.id
                          ? '…'
                          : status === 'done'
                            ? 'Ponów'
                            : 'Pobierz'}
                      </button>
                    </td>
                  )}
                </tr>
              )
            })}
            {result && result.data.length === 0 && (
              <tr>
                <td colSpan={tableCols} className="p-4 text-slate-400">
                  {aiMode
                    ? 'AI nie znalazło pasujących produktów (wzbogać opisy lub doprecyzuj wymaganie).'
                    : 'Brak produktów dla tego wyszukiwania.'}
                </td>
              </tr>
            )}
          </tbody>
        </table>

        <ProductPreviewModal productId={previewId} onClose={() => setPreviewId(null)} />

        {imageModal && (
          <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4"
            role="dialog"
            aria-modal="true"
            onClick={() => setImageModal(null)}
          >
            <div
              className="relative max-h-[90vh] max-w-[90vw]"
              onClick={(e) => e.stopPropagation()}
            >
              <button
                type="button"
                onClick={() => setImageModal(null)}
                className="absolute -right-2 -top-2 rounded bg-white px-2 py-1 text-xs shadow"
              >
                Zamknij
              </button>
              <img
                src={imageModal.url}
                alt={imageModal.name}
                className="max-h-[85vh] max-w-[90vw] rounded bg-white object-contain"
              />
            </div>
          </div>
        )}

        {result && !aiMode && result.last_page > 1 && (
          <div className="mt-4 flex flex-wrap items-center justify-between gap-3 border-t pt-3">
            <p className="text-xs text-slate-500">
              Strona {result.current_page} z {result.last_page}
            </p>
            <nav className="flex flex-wrap items-center gap-1" aria-label="Paginacja">
              <button
                type="button"
                disabled={result.current_page <= 1}
                onClick={() => setPage((p) => Math.max(1, p - 1))}
                className="rounded border border-slate-300 px-2.5 py-1.5 text-xs disabled:opacity-40"
              >
                ← Poprzednia
              </button>
              {pages.map((n, i) =>
                n === '…' ? (
                  <span key={`e-${i}`} className="px-1 text-xs text-slate-400">
                    …
                  </span>
                ) : (
                  <button
                    key={n}
                    type="button"
                    onClick={() => setPage(n)}
                    className={`min-w-8 rounded px-2.5 py-1.5 text-xs ${
                      n === result.current_page
                        ? 'bg-blue-600 text-white'
                        : 'border border-slate-300 hover:bg-slate-50'
                    }`}
                  >
                    {n}
                  </button>
                ),
              )}
              <button
                type="button"
                disabled={result.current_page >= result.last_page}
                onClick={() => setPage((p) => Math.min(result.last_page, p + 1))}
                className="rounded border border-slate-300 px-2.5 py-1.5 text-xs disabled:opacity-40"
              >
                Następna →
              </button>
            </nav>
          </div>
        )}
      </div>
    </div>
  )
}
