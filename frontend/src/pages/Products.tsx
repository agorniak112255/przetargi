import { useEffect, useMemo, useRef, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useAuth } from '../auth'
import { CatalogHealthPanel } from '../components/CatalogHealthPanel'
import { EnrichmentProgressBanner } from '../components/EnrichmentProgressBanner'
import { EnrichmentQueuePanel } from '../components/EnrichmentQueuePanel'
import { PrestaSearchModal, type PrestaSearchResult } from '../components/PrestaSearchModal'
import { ProductAiSearchModal } from '../components/ProductAiSearchModal'
import { ProductPreviewModal } from '../components/ProductPreviewModal'
import { clampAiConcurrency, clampEnrichmentBatchLimit } from '../lib/aiConcurrency'
import { applyCheckboxRange } from '../lib/checkboxRange'
import { api, can, parseActiveEnrichment, type EnrichmentBatch, type PrestaExportBatch, type Product } from '../lib/api'

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
  manual: 'Ręcznie',
}

const STATUS_FILTERS = ['', 'none', 'queued', 'running', 'done', 'failed', 'manual'] as const

const PER_PAGE_CHOICES = ['100', '200', '500', '1000', 'all'] as const
type PerPageChoice = (typeof PER_PAGE_CHOICES)[number]

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

function ProductListControls({
  result,
  pages,
  canSelect,
  allVisibleSelected,
  visibleCount,
  selectedCount,
  perPage,
  perPageDisabled,
  onToggleSelectVisible,
  onPage,
  onPerPage,
}: {
  result: Page
  pages: Array<number | '…'>
  canSelect: boolean
  allVisibleSelected: boolean
  visibleCount: number
  selectedCount: number
  perPage: PerPageChoice
  perPageDisabled: boolean
  onToggleSelectVisible: () => void
  onPage: (page: number) => void
  onPerPage: (value: PerPageChoice) => void
}) {
  return (
    <div className="flex flex-wrap items-center justify-between gap-3 py-2">
      <div className="flex flex-wrap items-center gap-3">
        {canSelect && visibleCount > 0 && (
          <label className="flex items-center gap-2 text-xs text-slate-600">
            <input
              type="checkbox"
              checked={allVisibleSelected}
              onChange={onToggleSelectVisible}
              title="Zaznacz / odznacz widoczne"
            />
            Zaznacz widoczne ({visibleCount})
            {selectedCount > 0 ? ` · zaznaczono ${selectedCount}` : ''}
            <span className="text-slate-400"> · Shift+klik: zakres</span>
          </label>
        )}
        <p className="flex flex-wrap items-center gap-1.5 text-xs text-slate-500">
          <span>
            Strona {result.current_page} z {result.last_page}
          </span>
          <span>·</span>
          <label className="inline-flex items-center gap-1">
            <select
              className="rounded border border-slate-300 bg-white px-1.5 py-0.5 text-xs disabled:opacity-50"
              value={perPage}
              disabled={perPageDisabled}
              onChange={(e) => onPerPage(e.target.value as PerPageChoice)}
              title="Ile wierszy na stronie"
            >
              {PER_PAGE_CHOICES.map((n) => (
                <option key={n} value={n}>
                  {n === 'all' ? 'wszystkie' : n}
                </option>
              ))}
            </select>
            /stronę
          </label>
        </p>
      </div>
      {result.last_page > 1 && (
        <nav className="flex flex-wrap items-center gap-1" aria-label="Paginacja">
          <button
            type="button"
            disabled={result.current_page <= 1}
            onClick={() => onPage(Math.max(1, result.current_page - 1))}
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
                onClick={() => onPage(n)}
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
            onClick={() => onPage(Math.min(result.last_page, result.current_page + 1))}
            className="rounded border border-slate-300 px-2.5 py-1.5 text-xs disabled:opacity-40"
          >
            Następna →
          </button>
        </nav>
      )}
    </div>
  )
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
  | 'ai_match_percent'

function sortNum(value: unknown): number {
  const n = typeof value === 'number' ? value : Number(value)
  return Number.isFinite(n) ? n : 0
}

function sortProductRows(rows: Product[], sort: SortKey, dir: 'asc' | 'desc'): Product[] {
  const mul = dir === 'asc' ? 1 : -1
  return [...rows].sort((a, b) => {
    let cmp = 0
    if (sort === 'catalog_price_net') {
      cmp = sortNum(a.price_pln ?? a.catalog_price_net) - sortNum(b.price_pln ?? b.catalog_price_net)
    } else if (sort === 'ai_match_percent') {
      cmp = sortNum(a.ai_match_percent) - sortNum(b.ai_match_percent)
    } else if (sort === 'images_count') {
      cmp = sortNum(a.images_count) - sortNum(b.images_count)
    } else if (sort === 'discount_percent') {
      cmp = sortNum(a.discount_percent) - sortNum(b.discount_percent)
    } else if (sort === 'description') {
      cmp = Number(hasDescription(a)) - Number(hasDescription(b))
      if (cmp === 0) cmp = (a.description ?? '').localeCompare(b.description ?? '', 'pl')
    } else {
      cmp = String(a[sort] ?? '').localeCompare(String(b[sort] ?? ''), 'pl')
    }
    if (cmp === 0) cmp = (a.name ?? '').localeCompare(b.name ?? '', 'pl')
    return cmp * mul
  })
}

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
  const [searchParams] = useSearchParams()
  const canEnrich = can(user, 'price_lists.import')
  const canExportPresta = can(user, 'presta.export')
  const [q, setQ] = useState('')
  const [debouncedQ, setDebouncedQ] = useState('')
  const [manufacturer, setManufacturer] = useState(() => searchParams.get('manufacturer') ?? '')
  const [statusFilter, setStatusFilter] = useState('')
  const [manufacturers, setManufacturers] = useState<string[]>([])
  const [aiQuery, setAiQuery] = useState('')
  const [aiMode, setAiMode] = useState(false)
  const [aiBusy, setAiBusy] = useState<'catalog' | 'web' | false>(false)
  const [externalHints, setExternalHints] = useState<{ url: string; title: string }[]>([])
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState<PerPageChoice>('500')
  const [sort, setSort] = useState<SortKey>('name')
  const [dir, setDir] = useState<'asc' | 'desc'>('asc')
  const [result, setResult] = useState<Page | null>(null)
  const [loading, setLoading] = useState(false)
  const [enrichBusy, setEnrichBusy] = useState(false)
  const [enrichRowId, setEnrichRowId] = useState<number | null>(null)
  const [batch, setBatch] = useState<EnrichmentBatch | null>(null)
  const [selected, setSelected] = useState<Record<number, boolean>>({})
  const lastSelectIndex = useRef<number | null>(null)
  const [msg, setMsg] = useState('')
  const [err, setErr] = useState('')
  const [previewId, setPreviewId] = useState<number | null>(null)
  const [imageModal, setImageModal] = useState<{ name: string; url: string } | null>(null)
  const [aiModalOpen, setAiModalOpen] = useState(false)
  const [prestaOpen, setPrestaOpen] = useState(false)
  const [prestaBusy, setPrestaBusy] = useState(false)
  const [prestaErr, setPrestaErr] = useState('')
  const [prestaItems, setPrestaItems] = useState<PrestaSearchResult[]>([])
  const [exportBusy, setExportBusy] = useState(false)
  const [exportRowId, setExportRowId] = useState<number | null>(null)
  const [visibleEnrichOpen, setVisibleEnrichOpen] = useState(false)
  const [visibleEnrichAck, setVisibleEnrichAck] = useState(false)
  const [skipPrompt, setSkipPrompt] = useState<{
    force: boolean
    risky: Product[]
    rest: number[]
  } | null>(null)
  const [enrichBatchLimit, setEnrichBatchLimit] = useState(5)
  const [enrichConcurrency, setEnrichConcurrency] = useState(4)

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
    setManufacturer(searchParams.get('manufacturer') ?? '')
    setPage(1)
  }, [searchParams])

  useEffect(() => {
    lastSelectIndex.current = null
  }, [page, sort, dir, manufacturer, statusFilter, debouncedQ, aiMode])

  useEffect(() => {
    if (!canEnrich) return
    void api<unknown>('/product-enrichment-batches/active')
      .then((res) => {
        const list = parseActiveEnrichment(res).batches
        if (list.length > 0) {
          setBatch(list[0])
        }
      })
      .catch(() => {})
  }, [canEnrich])

  useEffect(() => {
    if (!canEnrich) return
    void api<{ enrichment_batch_limit: number; match_concurrency?: number }>('/product-enrichment/limits')
      .then((res) => {
        setEnrichBatchLimit(clampEnrichmentBatchLimit(res.enrichment_batch_limit))
        setEnrichConcurrency(clampAiConcurrency(res.match_concurrency))
      })
      .catch(() => {
        setEnrichBatchLimit(5)
        setEnrichConcurrency(4)
      })
  }, [canEnrich])

  useEffect(() => {
    if (!imageModal && !visibleEnrichOpen) return
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        setImageModal(null)
        setVisibleEnrichOpen(false)
        setVisibleEnrichAck(false)
      }
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [imageModal, visibleEnrichOpen])

  function buildParams(pageNum = page): URLSearchParams {
    const params = new URLSearchParams({
      page: String(pageNum),
      per_page: perPage,
      sort,
      dir,
    })
    if (debouncedQ) params.set('q', debouncedQ)
    if (manufacturer) params.set('manufacturer', manufacturer)
    if (statusFilter) params.set('enrichment_status', statusFilter)
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
    // eslint-disable-next-line react-hooks/exhaustive-deps -- buildParams uses current sort/dir/page/q/manufacturer/status
  }, [debouncedQ, manufacturer, statusFilter, page, perPage, sort, dir, aiMode])

  async function runAiSearch(web = false, raw = aiQuery) {
    const query = raw.trim()
    if (query.length < 3) {
      setErr('Podaj wymaganie (min. 3 znaki), np. rękawice do pracy z amoniakiem')
      return
    }
    setAiQuery(query)
    setAiBusy(web ? 'web' : 'catalog')
    setErr('')
    setMsg(web ? 'Szukam w internecie…' : 'Szukam w katalogu przez AI…')
    const controller = new AbortController()
    const timer = window.setTimeout(() => controller.abort(), 180_000)
    try {
      const res = await api<{
        query: string
        total: number
        products: Product[]
        ai_note?: string | null
        external_hint?: { url: string; title: string } | null
        external_hints?: { url: string; title: string }[]
      }>(
        '/products/ai-search',
        {
          method: 'POST',
          body: JSON.stringify({ query, limit: web ? 8 : 40, web }),
          signal: controller.signal,
        },
      )
      const hints =
        res.external_hints ?? (res.external_hint ? [res.external_hint] : [])
      setAiMode(true)
      setSort('ai_match_percent')
      setDir('desc')
      setExternalHints(hints)
      setResult({
        data: res.products,
        current_page: 1,
        last_page: 1,
        per_page: res.products.length || 40,
        total: res.total,
        from: res.total > 0 ? 1 : null,
        to: res.total > 0 ? res.total : null,
      })
      setMsg(
        res.total > 0
          ? `AI znalazło ${res.total} produktów dla: „${res.query}”`
          : hints.length > 0
            ? `Internet: ${hints.length} linków dla: „${res.query}”`
            : (res.ai_note ?? 'Model nie znalazł pasującego produktu w katalogu.'),
      )
      setAiModalOpen(false)
    } catch (ex) {
      const aborted =
        (ex instanceof DOMException && ex.name === 'AbortError') ||
        (ex instanceof Error && /abort/i.test(ex.message))
      setErr(
        aborted
          ? 'Wyszukiwanie AI przekroczyło limit czasu (180 s). Sprawdź klucz/model w Ustawieniach AI i spróbuj krótszego wymagania.'
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
    setExternalHints([])
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

  // „Ręcznie” i „Błąd” to pozycje, których kolejka normalnie nie rusza — przed
  // wymuszeniem pytamy, bo każda kosztuje wywołanie Tavily i modelu.
  function requestEnrich(ids: number[], force = false) {
    const byId = new Map((result?.data ?? []).map((p) => [p.id, p]))
    const risky: Product[] = []
    const rest: number[] = []
    for (const id of ids) {
      const status = byId.get(id)?.enrichment_status ?? 'none'
      if (status === 'manual' || status === 'failed') {
        const product = byId.get(id)
        if (product) risky.push(product)
      } else {
        rest.push(id)
      }
    }

    if (risky.length === 0) {
      void enrichIds(ids, force)

      return
    }

    setSkipPrompt({ force, risky, rest })
  }

  async function enrichIds(ids: number[], force = false) {
    if (ids.length === 0) return
    const capped = ids.slice(0, enrichBatchLimit)
    setEnrichBusy(true)
    setErr('')
    setMsg('')
    try {
      const res = await api<{ batch: EnrichmentBatch; product_ids?: number[] }>('/products/enrich', {
        method: 'POST',
        body: JSON.stringify({ product_ids: capped, force }),
      })
      setBatch(res.batch)
      const queuedIds = res.product_ids ?? capped
      setMsg(
        (ids.length > enrichBatchLimit
          ? `Zlecono ${queuedIds.length}/${ids.length} (limit ${enrichBatchLimit})`
          : `Zlecono ${queuedIds.length} produktów`) +
          `. Serwer liczy ${enrichConcurrency} naraz — możesz zamknąć stronę.`,
      )
      setSelected({})
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Błąd wzbogacania')
    } finally {
      setEnrichBusy(false)
      setEnrichRowId(null)
    }
  }

  function toggleSelected(id: number, shiftKey = false) {
    const ids = (result?.data ?? []).map((p) => p.id)
    const index = ids.indexOf(id)
    if (index < 0) return
    setSelected((prev) => {
      const applied = applyCheckboxRange(ids, prev, lastSelectIndex.current, index, shiftKey)
      lastSelectIndex.current = applied.anchorIndex
      return applied.selected
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
    lastSelectIndex.current = allOn ? null : ids.length - 1
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

  async function searchPrestaIds(ids: number[]) {
    if (ids.length === 0) return
    setPrestaBusy(true)
    setPrestaErr('')
    setPrestaOpen(true)
    try {
      if (ids.length === 1) {
        const res = await api<PrestaSearchResult>(`/products/${ids[0]}/presta-search`, {
          method: 'POST',
          body: '{}',
        })
        setPrestaItems([res])
        return
      }
      const res = await api<{ items: PrestaSearchResult[] }>('/products/presta-search', {
        method: 'POST',
        body: JSON.stringify({ product_ids: ids.slice(0, 80) }),
      })
      setPrestaItems(res.items ?? [])
    } catch (ex) {
      setPrestaItems([])
      setPrestaErr(ex instanceof Error ? ex.message : 'Błąd wyszukiwania w Preście')
    } finally {
      setPrestaBusy(false)
    }
  }

  async function exportPrestaIds(ids: number[], force = false) {
    if (ids.length === 0) return
    const ok = window.confirm(
      ids.length === 1
        ? force
          ? 'Zaktualizować ten produkt w Preście (opis, rozmiary, na zamówienie)?'
          : 'Wysłać ten produkt do Presty? Wejdą opis, rozmiary i termin „Na zamówienie”.'
        : `Wysłać ${ids.length} produktów do Presty? Wejdą opisy, rozmiary i termin „Na zamówienie”.`,
    )
    if (!ok) return
    setExportBusy(true)
    setErr('')
    setMsg('')
    try {
      if (ids.length === 1) {
        setExportRowId(ids[0])
        const res = await api<PrestaExportBatch & { action?: string; presta_id?: number; sizes?: string[] }>(
          `/products/${ids[0]}/presta-export`,
          { method: 'POST', body: JSON.stringify({ force }) },
        )
        setMsg(
          res.action === 'exists'
            ? `Już w Preście (#${res.presta_id}).`
            : `Wysłano do Presty (#${res.presta_id}${res.sizes?.length ? `, rozmiary ${res.sizes.join('/')}` : ''}).`,
        )
      } else {
        const res = await api<PrestaExportBatch>('/products/presta-export', {
          method: 'POST',
          body: JSON.stringify({ product_ids: ids, force }),
        })
        if ((res.queued ?? 0) > 0) {
          setMsg(`Zlecono ${res.queued} produktów do Presty — kolejka przetworzy je w tle.`)
        } else {
          const errs = (res.errors ?? []).length > 0 ? ` · błędy: ${res.errors?.slice(0, 3).join('; ')}` : ''
          setMsg(
            `Presta: wysłano ${res.exported ?? 0}, pominięto ${res.skipped ?? 0}, błędy ${res.failed ?? 0}${errs}`,
          )
        }
      }
      setResult(await api<Page>(`/products?${buildParams()}`))
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Błąd wysyłki do Presty')
    } finally {
      setExportBusy(false)
      setExportRowId(null)
    }
  }

  const pages = result ? pageNumbers(result.current_page, result.last_page) : []
  const displayRows = useMemo(() => {
    const data = result?.data ?? []
    if (!aiMode) return data
    return sortProductRows(data, sort, dir)
  }, [result, aiMode, sort, dir])
  const visibleIds = displayRows.map((p) => p.id)
  const pendingVisible = displayRows.filter(
    (p) => (p.enrichment_status ?? 'none') !== 'done',
  )
  const selectedIds = Object.keys(selected)
    .map(Number)
    .filter((id) => selected[id])
  const allVisibleSelected =
    visibleIds.length > 0 && visibleIds.every((id) => selected[id])
  const batchActive = batch?.status === 'queued' || batch?.status === 'running'
  const tableCols = 9 + (aiMode ? 1 : 0) + (canEnrich ? 1 : 0) + (canEnrich || canExportPresta ? 1 : 0)

  return (
    <div>
      {canEnrich && (
        <EnrichmentQueuePanel
          onChanged={() => {
            setBatch(null)
            void api<Page>(`/products?${buildParams()}`).then(setResult).catch(() => {})
          }}
        />
      )}
      <CatalogHealthPanel
        canQueue={canEnrich}
        manufacturerFilter={manufacturer}
        onQueued={(b) => {
          setBatch(b)
          setMsg(b.message || `W kolejce enrichment: ${b.total} produktów`)
        }}
        onChanged={() => {
          void api<Page>(`/products?${buildParams()}`).then(setResult).catch(() => {})
        }}
      />
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
              {perPage === 'all' ? 'wszystkie na stronie' : `${perPage}/stronę`}
              {loading ? ' · ładowanie…' : ''}
            </p>
          )}
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <button
            type="button"
            disabled={Boolean(aiBusy)}
            onClick={() => setAiModalOpen(true)}
            className={`max-w-xs rounded-lg border-2 border-dashed px-3 py-1.5 text-left ${
              aiMode
                ? 'border-indigo-400 bg-indigo-50'
                : 'border-indigo-300 bg-indigo-50/70 hover:border-indigo-400 hover:bg-indigo-50'
            }`}
            title="Szuka po opisie, nie po kodzie — otwiera okno wymagania"
          >
            <span className="block text-[10px] font-semibold uppercase tracking-wide text-indigo-600">
              Wymaganie AI
            </span>
            <span className={`block truncate text-xs ${aiMode ? 'font-medium text-indigo-950' : 'text-indigo-800'}`}>
              {aiBusy
                ? 'Szukam…'
                : aiMode && aiQuery
                  ? aiQuery
                  : 'Opisz zastosowanie, normę…'}
            </span>
          </button>
          {aiMode && (
            <button
              type="button"
              disabled={Boolean(aiBusy)}
              onClick={clearAiSearch}
              className="rounded border border-indigo-200 px-2 py-1.5 text-[11px] text-indigo-800 hover:bg-indigo-50 disabled:opacity-50"
            >
              Wyczyść
            </button>
          )}
          <select
            className="rounded border border-slate-300 px-3 py-2 text-sm"
            value={statusFilter}
            disabled={aiMode}
            onChange={(e) => {
              setStatusFilter(e.target.value)
              setPage(1)
            }}
            title="Filtr statusu opisów/zdjęć"
          >
            {STATUS_FILTERS.map((s) => (
              <option key={s || 'all'} value={s}>
                {s ? `Status AI: ${STATUS_LABEL[s]}` : 'Status AI: wszystkie'}
              </option>
            ))}
          </select>
          {canEnrich && (
            <>
              <button
                type="button"
                disabled={enrichBusy || selectedIds.length === 0}
                onClick={() => requestEnrich(selectedIds)}
                className="rounded bg-blue-600 px-3 py-2 text-xs text-white disabled:opacity-50"
                title="Pobierz opisy i zdjęcia dla zaznaczonych — działa też przy innej kolejce"
              >
                Pobierz zaznaczone{selectedIds.length > 0 ? ` (${selectedIds.length})` : ''}
              </button>
              <button
                type="button"
                disabled={prestaBusy || selectedIds.length === 0}
                onClick={() => void searchPrestaIds(selectedIds)}
                className="rounded bg-emerald-700 px-3 py-2 text-xs text-white disabled:opacity-50"
                title="Szuka zaznaczonych w sklepie Presta (SKU/EAN, potem nazwa)"
              >
                {prestaBusy
                  ? 'Szukam…'
                  : `Wyszukaj zaznaczone${selectedIds.length > 0 ? ` (${selectedIds.length})` : ''}`}
              </button>
              <button
                type="button"
                disabled={enrichBusy || batchActive || selectedIds.length === 0}
                onClick={() => requestEnrich(selectedIds, true)}
                className="rounded border border-slate-300 px-3 py-2 text-xs disabled:opacity-50"
                title="Wymusza ponowne pobranie dla zaznaczonych"
              >
                Ponów zaznaczone
              </button>
              <button
                type="button"
                disabled={enrichBusy || batchActive || pendingVisible.length === 0}
                onClick={() => {
                  setVisibleEnrichAck(false)
                  setVisibleEnrichOpen(true)
                }}
                className="rounded border border-slate-300 px-3 py-2 text-xs disabled:opacity-50"
                title={`Pobierz opisy dla widocznych bez danych — serwer liczy ${enrichConcurrency} naraz`}
              >
                Pobierz widoczne bez opisu
                {pendingVisible.length > 0 ? ` (${pendingVisible.length})` : ''}
              </button>
            </>
          )}
          {canExportPresta && (
            <button
              type="button"
              disabled={exportBusy || selectedIds.length === 0}
              onClick={() =>
                void exportPrestaIds(
                  selectedIds,
                  selectedIds.some((id) => displayRows.find((row) => row.id === id)?.presta_export?.presta_id),
                )
              }
              className="rounded bg-violet-700 px-3 py-2 text-xs text-white disabled:opacity-50"
              title="Wysyła zaznaczone do sklepu: opis, rozmiary, termin na zamówienie"
            >
              {exportBusy
                ? 'Wysyłam…'
                : `Wyślij do Presty${selectedIds.length > 0 ? ` (${selectedIds.length})` : ''}`}
            </button>
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

      {msg && <p className="mb-2 rounded bg-green-50 px-3 py-2 text-xs text-green-800">{msg}</p>}
      {err && <p className="mb-2 rounded bg-red-50 px-3 py-2 text-xs text-red-700">{err}</p>}
      {externalHints.length > 0 && (
        <div className="mb-2 space-y-1.5">
          {externalHints.map((hint) => (
            <a
              key={hint.url}
              href={hint.url}
              target="_blank"
              rel="noopener noreferrer"
              className="block rounded border border-orange-300 bg-orange-50 px-3 py-2"
            >
              <span className="rounded bg-orange-600 px-1 py-px text-[9px] font-bold uppercase tracking-wide text-white">
                Link zewnętrzny — nie z katalogu
              </span>
              <span className="mt-1 block text-xs font-medium text-orange-950 underline">{hint.title}</span>
            </a>
          ))}
        </div>
      )}

      {batchActive && batch && <EnrichmentProgressBanner batches={[batch]} />}

      <div className="rounded-xl bg-white p-4 shadow-sm overflow-x-auto">
        {result && (
          <ProductListControls
            result={result}
            pages={pages}
            canSelect={canEnrich}
            allVisibleSelected={allVisibleSelected}
            visibleCount={visibleIds.length}
            selectedCount={selectedIds.length}
            onToggleSelectVisible={toggleSelectAllVisible}
            onPage={setPage}
            perPage={perPage}
            perPageDisabled={aiMode}
            onPerPage={(value) => {
              setPerPage(value)
              setPage(1)
            }}
          />
        )}
        <table className="w-full text-left text-xs">
          <thead>
            <tr className="border-b bg-slate-50">
              {canEnrich && (
                <th className="p-2 w-8">
                  <input
                    type="checkbox"
                    checked={allVisibleSelected}
                    onChange={toggleSelectAllVisible}
                    title="Zaznacz / odznacz widoczne. Na wierszu: Shift+klik zaznacza zakres."
                    aria-label="Zaznacz wszystkie widoczne"
                  />
                </th>
              )}
              {aiMode && (
                <SortTh label="Dopasowanie" col="ai_match_percent" sort={sort} dir={dir} onSort={onSort} />
              )}
              <SortTh label="Status AI" col="enrichment_status" sort={sort} dir={dir} onSort={onSort} />
              <SortTh label="Kod" col="sku" sort={sort} dir={dir} onSort={onSort} />
              <SortTh label="Nazwa" col="name" sort={sort} dir={dir} onSort={onSort} />
              <SortTh label="Producent" col="manufacturer" sort={sort} dir={dir} onSort={onSort} />
              <SortTh label="Netto (PLN)" col="catalog_price_net" sort={sort} dir={dir} onSort={onSort} />
              <SortTh label="Waluta" col="currency" sort={sort} dir={dir} onSort={onSort} />
              <SortTh label="Upust" col="discount_percent" sort={sort} dir={dir} onSort={onSort} />
              <SortTh label="Opis" col="description" sort={sort} dir={dir} onSort={onSort} />
              <SortTh label="Zdjęcia" col="images_count" sort={sort} dir={dir} onSort={onSort} />
              {(canEnrich || canExportPresta) && <th className="p-2">Akcja</th>}
            </tr>
          </thead>
          <tbody>
            {displayRows.map((p) => {
              const status = p.enrichment_status ?? 'none'
              const thumb = p.images?.find((img) => img.is_primary) ?? p.images?.[0]
              return (
                <tr key={p.id} className={`border-b ${selected[p.id] ? 'bg-blue-50/40' : ''}`}>
                  {canEnrich && (
                    <td className="p-2 select-none">
                      <input
                        type="checkbox"
                        checked={Boolean(selected[p.id])}
                        title="Shift+klik zaznacza wszystkie od ostatnio klikniętej"
                        onMouseDown={(e) => {
                          if (!e.shiftKey) return
                          e.preventDefault()
                          toggleSelected(p.id, true)
                        }}
                        onChange={(e) => {
                          if ((e.nativeEvent as MouseEvent).shiftKey) return
                          toggleSelected(p.id, false)
                        }}
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
                    <span
                      className={
                        status === 'done'
                          ? 'text-green-700'
                          : status === 'failed'
                            ? 'text-red-600'
                            : status === 'manual'
                              ? 'text-amber-700'
                              : status === 'running' || status === 'queued'
                                ? 'text-blue-700'
                                : 'text-slate-400'
                      }
                      title={p.enrichment_error ?? undefined}
                    >
                      {STATUS_LABEL[status] ?? status}
                    </span>
                  </td>
                  <td className="p-2">
                    <Link className="text-blue-600 hover:underline" to={`/products/${p.id}`}>
                      {p.sku}
                    </Link>
                  </td>
                  <td className="p-2 max-w-[14rem] truncate" title={p.name}>
                    {p.name}
                  </td>
                  <td className="p-2">{p.manufacturer}</td>
                  <td className="p-2 whitespace-nowrap">
                    {p.catalog_price_net}
                    {(p.currency ?? 'PLN').toUpperCase() !== 'PLN' && p.price_pln != null && (
                      <span
                        className="mt-0.5 block text-[10px] text-slate-500"
                        title="Przeliczenie NBP tabela A do PLN"
                      >
                        ≈ {Number(p.price_pln).toFixed(2)} PLN
                      </span>
                    )}
                  </td>
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
                    {thumb?.url ? (
                      <button
                        type="button"
                        onClick={() => setImageModal({ name: p.name, url: thumb.url })}
                        className="block overflow-hidden rounded border border-slate-200 bg-slate-50"
                        title="Pokaż pełne zdjęcie"
                      >
                        <img src={thumb.url} alt="" className="h-10 w-10 object-cover" />
                      </button>
                    ) : (
                      <span className="text-slate-400">—</span>
                    )}
                  </td>
                  {(canEnrich || canExportPresta) && (
                    <td className="p-2">
                      <div className="flex flex-wrap gap-1">
                        {canEnrich && (
                          <button
                            type="button"
                            disabled={
                              enrichBusy ||
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
                        )}
                        {canExportPresta && (
                          <button
                            type="button"
                            disabled={exportBusy}
                            onClick={() => void exportPrestaIds([p.id], Boolean(p.presta_export?.presta_id))}
                            className="rounded bg-violet-700 px-2 py-1 text-[11px] text-white disabled:opacity-50"
                            title={p.presta_export?.url ? `W Preście #${p.presta_export.presta_id}` : 'Wyślij do sklepu'}
                          >
                            {exportRowId === p.id
                              ? '…'
                              : p.presta_export?.presta_id
                                ? 'Presta ✓'
                                : 'Do Presty'}
                          </button>
                        )}
                      </div>
                    </td>
                  )}
                </tr>
              )
            })}
            {result && result.data.length === 0 && (
              <tr>
                <td colSpan={tableCols} className="p-4 text-slate-400">
                  {aiMode
                    ? 'AI nie znalazło pasującego produktu w katalogu.'
                    : 'Brak produktów dla tego wyszukiwania.'}
                </td>
              </tr>
            )}
          </tbody>
          {canEnrich && visibleIds.length > 0 && (
            <tfoot>
              <tr className="border-t bg-slate-50">
                <td className="p-2 w-8">
                  <input
                    type="checkbox"
                    checked={allVisibleSelected}
                    onChange={toggleSelectAllVisible}
                    title="Zaznacz / odznacz widoczne"
                    aria-label="Zaznacz wszystkie widoczne"
                  />
                </td>
                <td colSpan={tableCols - 1} className="p-2 text-xs text-slate-600">
                  Zaznacz widoczne ({visibleIds.length})
                  {selectedIds.length > 0 ? ` · zaznaczono ${selectedIds.length}` : ''}
                  {' · Shift+klik: zakres'}
                </td>
              </tr>
            </tfoot>
          )}
        </table>

        <ProductPreviewModal
          productId={previewId}
          query={aiMode ? aiQuery : ''}
          onClose={() => setPreviewId(null)}
        />
        <ProductAiSearchModal
          open={aiModalOpen}
          busy={aiBusy}
          error={err}
          initialQuery={aiQuery}
          onClose={() => setAiModalOpen(false)}
          onSearch={(query, web) => void runAiSearch(web, query)}
        />
        <PrestaSearchModal
          open={prestaOpen}
          items={prestaItems}
          loading={prestaBusy}
          error={prestaErr}
          onClose={() => setPrestaOpen(false)}
          onApplied={() => {
            void api<Page>(`/products?${buildParams()}`).then(setResult).catch(() => {})
          }}
        />

        {visibleEnrichOpen && (
          <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
            role="dialog"
            aria-modal="true"
            onClick={() => {
              setVisibleEnrichOpen(false)
              setVisibleEnrichAck(false)
            }}
          >
            <div
              className="w-full max-w-md rounded-xl bg-white p-4 shadow-lg"
              onClick={(e) => e.stopPropagation()}
            >
              <p className="text-sm font-semibold text-slate-800">Pobierz widoczne bez opisu</p>
              <p className="mt-2 text-xs text-slate-600">
                Widocznych bez opisu: <b>{pendingVisible.length}</b>
                {pendingVisible.length > enrichBatchLimit
                  ? ` · do kolejki trafi max ${enrichBatchLimit} (limit z Ustawień AI)`
                  : ''}
                . Operacja używa Tavily i modelu AI — <b>generuje koszty</b>.
              </p>
              <label className="mt-3 flex items-start gap-2 text-xs text-slate-700">
                <input
                  type="checkbox"
                  className="mt-0.5"
                  checked={visibleEnrichAck}
                  onChange={(e) => setVisibleEnrichAck(e.target.checked)}
                />
                <span>
                  Rozumiem koszty. Uruchom dla{' '}
                  {Math.min(pendingVisible.length, enrichBatchLimit)}{' '}
                  {Math.min(pendingVisible.length, enrichBatchLimit) === 1
                    ? 'produktu'
                    : 'produktów'}
                  .
                </span>
              </label>
              <div className="mt-4 flex justify-end gap-2">
                <button
                  type="button"
                  className="rounded border border-slate-300 px-3 py-1.5 text-xs"
                  onClick={() => {
                    setVisibleEnrichOpen(false)
                    setVisibleEnrichAck(false)
                  }}
                >
                  Anuluj
                </button>
                <button
                  type="button"
                  disabled={
                    enrichBusy ||
                    batchActive ||
                    pendingVisible.length === 0 ||
                    !visibleEnrichAck
                  }
                  className="rounded bg-blue-600 px-3 py-1.5 text-xs text-white disabled:opacity-50"
                  onClick={() => {
                    const ids = pendingVisible
                      .slice(0, enrichBatchLimit)
                      .map((p) => p.id)
                    setVisibleEnrichOpen(false)
                    setVisibleEnrichAck(false)
                    void enrichIds(ids)
                  }}
                >
                  Pobierz ({Math.min(pendingVisible.length, enrichBatchLimit)})
                </button>
              </div>
            </div>
          </div>
        )}

        {skipPrompt && (
          <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
            role="dialog"
            aria-modal="true"
            onClick={() => setSkipPrompt(null)}
          >
            <div
              className="w-full max-w-lg rounded-xl bg-white p-4 shadow-lg"
              onClick={(e) => e.stopPropagation()}
            >
              <p className="text-sm font-semibold text-slate-800">
                Pozycje pomijane przez kolejkę ({skipPrompt.risky.length})
              </p>
              <p className="mt-2 text-xs text-slate-600">
                „Ręcznie” oznacza, że żadne źródło nie potwierdziło tego kodu — ponowienie
                zwykle da ten sam wynik. „Błąd” warto ponowić. Każda pozycja kosztuje
                zapytanie do Tavily i modelu.
              </p>
              <ul className="mt-3 max-h-56 divide-y overflow-auto rounded border border-slate-200 text-xs">
                {skipPrompt.risky.map((p) => (
                  <li key={p.id} className="flex items-center justify-between gap-3 p-2">
                    <span className="truncate">
                      <span className="font-medium text-slate-700">{p.sku}</span>
                      <span className="text-slate-500"> · {p.name}</span>
                    </span>
                    <span
                      className={
                        p.enrichment_status === 'failed'
                          ? 'shrink-0 text-red-600'
                          : 'shrink-0 text-amber-700'
                      }
                    >
                      {STATUS_LABEL[p.enrichment_status ?? 'none']}
                    </span>
                  </li>
                ))}
              </ul>
              <div className="mt-4 flex flex-wrap justify-end gap-2">
                <button
                  type="button"
                  className="rounded border border-slate-300 px-3 py-1.5 text-xs"
                  onClick={() => setSkipPrompt(null)}
                >
                  Anuluj
                </button>
                <button
                  type="button"
                  disabled={skipPrompt.rest.length === 0}
                  className="rounded border border-slate-300 px-3 py-1.5 text-xs disabled:opacity-50"
                  onClick={() => {
                    const { rest, force } = skipPrompt
                    setSkipPrompt(null)
                    void enrichIds(rest, force)
                  }}
                >
                  Pomiń je ({skipPrompt.rest.length})
                </button>
                <button
                  type="button"
                  className="rounded bg-blue-600 px-3 py-1.5 text-xs text-white"
                  onClick={() => {
                    const ids = [
                      ...skipPrompt.rest,
                      ...skipPrompt.risky.map((p) => p.id),
                    ]
                    setSkipPrompt(null)
                    void enrichIds(ids, true)
                  }}
                >
                  Pobierz mimo to ({skipPrompt.rest.length + skipPrompt.risky.length})
                </button>
              </div>
            </div>
          </div>
        )}

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

        {result && (
          <div className="mt-2 border-t pt-1">
            <ProductListControls
              result={result}
              pages={pages}
              canSelect={canEnrich}
              allVisibleSelected={allVisibleSelected}
              visibleCount={visibleIds.length}
              selectedCount={selectedIds.length}
              onToggleSelectVisible={toggleSelectAllVisible}
              onPage={setPage}
              perPage={perPage}
              perPageDisabled={aiMode}
              onPerPage={(value) => {
                setPerPage(value)
                setPage(1)
              }}
            />
          </div>
        )}
      </div>
    </div>
  )
}
