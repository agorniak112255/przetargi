import { Fragment, useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { PriceListsTabs } from '../components/PriceListsTabs'
import { api } from '../lib/api'
import { plural } from '../lib/plural'
import { currencyLabel, formatDateTime, formatPrice } from '../lib/priceChange'

type ExclusionStatus = 'active' | 'restored' | 'all'
type KindFilter = '' | 'deleted' | 'detached'
type HitsFilter = '' | 'hit' | 'never'
type SortKey = 'deleted_at' | 'sku' | 'name' | 'manufacturer' | 'hits' | 'last_hit_at' | 'positions'
type SortDir = 'asc' | 'desc'

type ExclusionPosition = {
  id: number
  source_key: string
  /** Wartość filtra „Źródło”: „b2b:{konto}” albo „file:{producent}”. */
  scope_key: string
  /** np. „B2B P4S”, „Cennik z pliku CEDERROTH”. */
  source_label: string
  /** 'sku' = pozycja rozpoznawana po SKU karty (źródło bez własnego kodu pozycji). */
  match_kind: 'position' | 'sku'
  position_key: string
  remote_sku: string | null
  position_label: string | null
  hits: number
  last_hit_at: string | null
  restored_at: string | null
  restored_by: string | null
}

type ExclusionGroup = {
  deletion_id: string
  product: {
    id: number
    sku: string
    name: string
    manufacturer: string | null
    purchase_price: string | number | null
    catalog_price_net: string | number | null
    currency: string | null
  }
  deleted_at: string
  deleted_by: string | null
  /** Odpięto jedno źródło („b2b:{id}”) od karty, która została (usuwanie z listy kart konta dostawcy). */
  detached_source: string | null
  active_count: number
  /** Pozycje karty, których bieżący filtr (stan, źródło, pomijanie) nie pokazuje. */
  hidden_count: number
  positions: ExclusionPosition[]
}

type ExclusionFacets = {
  /** key = zakres blokady: „b2b:{konto}” albo „file:{producent}”. */
  sources: { key: string; label: string; active: number; total: number }[]
  manufacturers: { name: string; cards: number }[]
  users: { id: number; name: string }[]
}

type ExclusionPage = {
  data: ExclusionGroup[]
  meta: { current_page: number; last_page: number; total: number; per_page: number }
  facets: ExclusionFacets
}

const STATUS_OPTIONS: { value: ExclusionStatus; label: string }[] = [
  { value: 'active', label: 'Aktywne' },
  { value: 'restored', label: 'Przywrócone' },
  { value: 'all', label: 'Wszystkie' },
]

const SORT_OPTIONS: { value: SortKey; label: string }[] = [
  { value: 'deleted_at', label: 'data usunięcia' },
  { value: 'sku', label: 'SKU' },
  { value: 'name', label: 'nazwa' },
  { value: 'manufacturer', label: 'producent' },
  { value: 'hits', label: 'liczba pominięć' },
  { value: 'last_hit_at', label: 'ostatnie pominięcie' },
  { value: 'positions', label: 'liczba pozycji' },
]

const DEFAULT_DIR: Record<SortKey, SortDir> = {
  deleted_at: 'desc',
  sku: 'asc',
  name: 'asc',
  manufacturer: 'asc',
  hits: 'desc',
  last_hit_at: 'desc',
  positions: 'desc',
}

const PER_PAGE_OPTIONS = [20, 50, 100]

/** Klucze adresu będące filtrami (bez sortowania, strony i rozmiaru strony). */
const FILTER_KEYS = ['q', 'status', 'source', 'manufacturer', 'deleted_by', 'kind', 'hits', 'from', 'to'] as const

const SEARCH_DELAY_MS = 350

const RESTORE_NOTE =
  'Przy najbliższym imporcie cennika lub synchronizacji B2B wróci jako nowa karta (bez dawnych zdjęć i historii).'

const DETACHED_RESTORE_NOTE =
  'Przy najbliższej synchronizacji B2B pozycja wróci — na tę samą kartę (ten sam kod) albo jako nowa karta.'

const SELECT_CLASS = 'rounded border border-slate-300 bg-white px-1.5 py-1 text-xs text-slate-800'
const LABEL_CLASS = 'flex flex-col gap-0.5 text-[11px] text-slate-500'

function pick<T extends string>(value: string | null, allowed: readonly T[], fallback: T): T {
  return value !== null && (allowed as readonly string[]).includes(value) ? (value as T) : fallback
}

function isDate(value: string | null): value is string {
  return value !== null && /^\d{4}-\d{2}-\d{2}$/.test(value)
}

function priceLine(p: ExclusionGroup['product']): string | null {
  const cur = currencyLabel(p.currency)
  const parts: string[] = []
  if (p.purchase_price !== null && p.purchase_price !== '') parts.push(`zakup ${formatPrice(p.purchase_price)} ${cur}`)
  if (p.catalog_price_net !== null && p.catalog_price_net !== '') {
    parts.push(`katalogowa ${formatPrice(p.catalog_price_net)} ${cur}`)
  }
  return parts.length > 0 ? parts.join(' · ') : null
}

function hitsText(pos: ExclusionPosition): string {
  if (pos.hits <= 0) return 'jeszcze nie pominięto'
  const last = pos.last_hit_at ? ` (ostatnio ${formatDateTime(pos.last_hit_at)})` : ''
  return `pominięto ${pos.hits.toLocaleString('pl-PL')}×${last}`
}

function restoreNote(detached: boolean[]): string {
  if (detached.every(Boolean)) return DETACHED_RESTORE_NOTE
  if (!detached.some(Boolean)) return RESTORE_NOTE
  return `Usunięte karty: ${RESTORE_NOTE}\nOdpięte źródła: ${DETACHED_RESTORE_NOTE}`
}

/**
 * Cenniki → „Usunięte z pominięciem”: karty usunięte z opcją pomijania przy imporcie i ich pozycje ze źródeł.
 * Przywrócenie pozycji zdejmuje pominięcie — najbliższy import albo synchronizacja założy kartę od nowa.
 * Filtry, sortowanie i strona siedzą w adresie (odświeżenie i „wstecz” zachowują widok).
 */
export function PriceListsExcluded() {
  const [params, setParams] = useSearchParams()

  const q = params.get('q') ?? ''
  const status = pick<ExclusionStatus>(params.get('status'), ['active', 'restored', 'all'], 'active')
  const source = params.get('source') ?? ''
  const manufacturer = params.get('manufacturer') ?? ''
  const deletedBy = /^\d+$/.test(params.get('deleted_by') ?? '') ? (params.get('deleted_by') as string) : ''
  const kind = pick<KindFilter>(params.get('kind'), ['', 'deleted', 'detached'], '')
  const hits = pick<HitsFilter>(params.get('hits'), ['', 'hit', 'never'], '')
  const from = isDate(params.get('from')) ? (params.get('from') as string) : ''
  const to = isDate(params.get('to')) ? (params.get('to') as string) : ''
  const sort = pick<SortKey>(
    params.get('sort'),
    SORT_OPTIONS.map((o) => o.value),
    'deleted_at',
  )
  const dir = pick<SortDir>(params.get('dir'), ['asc', 'desc'], DEFAULT_DIR[sort])
  const page = Math.max(1, Math.floor(Number(params.get('page'))) || 1)
  const perPage = PER_PAGE_OPTIONS.includes(Number(params.get('per_page'))) ? Number(params.get('per_page')) : 20

  const apiQuery = useMemo(() => {
    const qs = new URLSearchParams()
    qs.set('status', status)
    if (q.trim()) qs.set('q', q.trim())
    if (source) qs.set('source', source)
    if (manufacturer) qs.set('manufacturer', manufacturer)
    if (deletedBy) qs.set('deleted_by', deletedBy)
    if (kind) qs.set('kind', kind)
    if (hits) qs.set('hits', hits)
    if (from) qs.set('from', from)
    if (to) qs.set('to', to)
    qs.set('sort', sort)
    qs.set('dir', dir)
    qs.set('page', String(page))
    qs.set('per_page', String(perPage))
    return qs.toString()
  }, [status, q, source, manufacturer, deletedBy, kind, hits, from, to, sort, dir, page, perPage])

  const [result, setResult] = useState<ExclusionPage | null>(null)
  const [loading, setLoading] = useState(false)
  const [err, setErr] = useState('')
  const [msg, setMsg] = useState('')
  /** Klucz przywracanej grupy, pozycji albo zaznaczenia ('g:<deletion_id>' / 'p:<id>' / 'sel'); null = nic w toku. */
  const [restoring, setRestoring] = useState<string | null>(null)
  /** Zaznaczone aktywne pozycje (id) — tylko z bieżącej strony. */
  const [selected, setSelected] = useState<Set<number>>(() => new Set())
  const requestSeq = useRef(0)

  // Pole wyszukiwania: wpis od razu w polu, do adresu (i zapytania) po chwili bez pisania.
  const [searchInput, setSearchInput] = useState(q)
  const pushedSearch = useRef(q)

  const setFilters = useCallback(
    (patch: Record<string, string | null>, opts: { keepPage?: boolean; replace?: boolean } = {}) => {
      setParams(
        (prev) => {
          const next = new URLSearchParams(prev)
          for (const [k, v] of Object.entries(patch)) {
            if (v === null || v === '') next.delete(k)
            else next.set(k, v)
          }
          if (!opts.keepPage) next.delete('page')
          return next
        },
        { replace: opts.replace },
      )
    },
    [setParams],
  )

  // Adres zmieniony z zewnątrz (wstecz w przeglądarce, „Wyczyść filtry”) — pole idzie za nim.
  useEffect(() => {
    if (q !== pushedSearch.current) {
      pushedSearch.current = q
      setSearchInput(q)
    }
  }, [q])

  useEffect(() => {
    if (searchInput === pushedSearch.current) return
    const timer = window.setTimeout(() => {
      pushedSearch.current = searchInput
      setFilters({ q: searchInput }, { replace: true })
    }, SEARCH_DELAY_MS)
    return () => window.clearTimeout(timer)
  }, [searchInput, setFilters])

  const load = useCallback(async () => {
    const seq = ++requestSeq.current
    setLoading(true)
    setErr('')
    try {
      const res = await api<ExclusionPage>(`/import-exclusions?${apiQuery}`)
      if (seq !== requestSeq.current) return
      // Po przywróceniu ostatniej pozycji strona mogła zniknąć — cofamy na ostatnią istniejącą.
      if (res.data.length === 0 && page > 1 && res.meta.last_page < page) {
        setFilters({ page: res.meta.last_page > 1 ? String(res.meta.last_page) : null }, { keepPage: true, replace: true })
        return
      }
      setResult(res)
      // zaznaczenie zostaje tylko dla aktywnych pozycji widocznych na nowej stronie
      const visible = new Set(
        res.data.flatMap((g) => g.positions.filter((p) => p.restored_at === null).map((p) => p.id)),
      )
      setSelected((prev) => {
        const next = new Set([...prev].filter((id) => visible.has(id)))
        return next.size === prev.size ? prev : next
      })
    } catch (ex) {
      if (seq !== requestSeq.current) return
      setErr(ex instanceof Error ? ex.message : 'Nie udało się wczytać listy')
    } finally {
      if (seq === requestSeq.current) setLoading(false)
    }
  }, [apiQuery, page, setFilters])

  useEffect(() => {
    void load()
  }, [load])

  async function restore(ids: number[], key: string, question: string, note: string) {
    if (ids.length === 0 || restoring !== null) return
    if (!window.confirm(`${question}\n\n${note}`)) return
    setRestoring(key)
    setErr('')
    setMsg('')
    try {
      const res = await api<{ message: string; restored: number }>('/import-exclusions/restore', {
        method: 'POST',
        body: JSON.stringify({ ids }),
      })
      setMsg(res.message)
      setSelected((prev) => {
        const next = new Set(prev)
        for (const id of ids) next.delete(id)
        return next
      })
      await load()
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Nie udało się przywrócić')
    } finally {
      setRestoring(null)
    }
  }

  function toggle(ids: number[], on: boolean) {
    setSelected((prev) => {
      const next = new Set(prev)
      for (const id of ids) {
        if (on) next.add(id)
        else next.delete(id)
      }
      return next
    })
  }

  const groups = useMemo(() => result?.data ?? [], [result])
  const meta = result?.meta ?? null
  const facets = result?.facets ?? null
  const hasFilters = FILTER_KEYS.some((k) => k !== 'status' && (params.get(k) ?? '') !== '') || status !== 'active'

  const pageActive = useMemo(
    () =>
      groups.flatMap((g) =>
        g.positions.filter((p) => p.restored_at === null).map((p) => ({ id: p.id, detached: Boolean(g.detached_source) })),
      ),
    [groups],
  )
  const selectedOnPage = pageActive.filter((p) => selected.has(p.id))

  function clearFilters() {
    setFilters(Object.fromEntries(FILTER_KEYS.map((k) => [k, null])))
    setSearchInput('')
    pushedSearch.current = ''
  }

  // wybrany filtr spoza listy (np. źródło bez pozycji po przywróceniu) — zostaje widoczny w polu wyboru
  const sourceMissing = source !== '' && facets !== null && !facets.sources.some((s) => s.key === source)
  const manufacturerMissing =
    manufacturer !== '' && facets !== null && !facets.manufacturers.some((m) => m.name === manufacturer)
  const userMissing = deletedBy !== '' && facets !== null && !facets.users.some((u) => String(u.id) === deletedBy)

  return (
    <div>
      <PriceListsTabs />
      <h1 className="text-xl font-semibold">Usunięte z pominięciem</h1>
      <p className="mb-3 max-w-4xl text-xs text-slate-500">
        Karty usunięte z opcją pomijania. Import cennika i synchronizacja B2B nie zakładają ich od nowa. Przywrócona
        pozycja wróci przy najbliższym imporcie lub synchronizacji jako nowa karta (bez dawnych zdjęć i historii).
      </p>

      <div className="mb-3 flex flex-wrap items-end gap-x-3 gap-y-2 rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs shadow-sm">
        <label className={`${LABEL_CLASS} min-w-[14rem] flex-1`}>
          Szukaj
          <input
            type="search"
            value={searchInput}
            onChange={(e) => setSearchInput(e.target.value)}
            placeholder="SKU, nazwa, producent, kod u dostawcy…"
            className={SELECT_CLASS}
          />
        </label>
        <div className={LABEL_CLASS}>
          Stan
          <div className="flex overflow-hidden rounded border border-slate-300" role="group" aria-label="Stan">
            {STATUS_OPTIONS.map((opt) => (
              <button
                key={opt.value}
                type="button"
                aria-pressed={status === opt.value}
                onClick={() => setFilters({ status: opt.value === 'active' ? null : opt.value })}
                className={`border-l border-slate-300 px-2.5 py-1 text-xs first:border-l-0 ${
                  status === opt.value ? 'bg-blue-600 text-white' : 'bg-white text-slate-700 hover:bg-slate-50'
                }`}
              >
                {opt.label}
              </button>
            ))}
          </div>
        </div>
        <label className={LABEL_CLASS}>
          Źródło
          <select className={`${SELECT_CLASS} max-w-[16rem]`} value={source} onChange={(e) => setFilters({ source: e.target.value })}>
            <option value="">wszystkie</option>
            {sourceMissing && <option value={source}>{source}</option>}
            {facets?.sources.map((s) => (
              <option key={s.key} value={s.key}>
                {s.label} ({s.active.toLocaleString('pl-PL')} akt. / {s.total.toLocaleString('pl-PL')})
              </option>
            ))}
          </select>
        </label>
        <label className={LABEL_CLASS}>
          Producent karty
          <select
            className={`${SELECT_CLASS} max-w-[14rem]`}
            value={manufacturer}
            onChange={(e) => setFilters({ manufacturer: e.target.value })}
          >
            <option value="">wszyscy</option>
            {manufacturerMissing && <option value={manufacturer}>{manufacturer}</option>}
            {facets?.manufacturers.map((m) => (
              <option key={m.name} value={m.name}>
                {m.name} ({m.cards.toLocaleString('pl-PL')})
              </option>
            ))}
          </select>
        </label>
        <label className={LABEL_CLASS}>
          Rodzaj
          <select className={SELECT_CLASS} value={kind} onChange={(e) => setFilters({ kind: e.target.value })}>
            <option value="">wszystkie</option>
            <option value="deleted">usunięte karty</option>
            <option value="detached">odpięte źródła (karta została)</option>
          </select>
        </label>
        <label className={LABEL_CLASS}>
          Pomijanie
          <select className={SELECT_CLASS} value={hits} onChange={(e) => setFilters({ hits: e.target.value })}>
            <option value="">dowolne</option>
            <option value="hit">import już pominął</option>
            <option value="never">jeszcze nie pominięto</option>
          </select>
        </label>
        <label className={LABEL_CLASS}>
          Usunął
          <select className={SELECT_CLASS} value={deletedBy} onChange={(e) => setFilters({ deleted_by: e.target.value })}>
            <option value="">każdy</option>
            {userMissing && <option value={deletedBy}>użytkownik #{deletedBy}</option>}
            {facets?.users.map((u) => (
              <option key={u.id} value={String(u.id)}>
                {u.name}
              </option>
            ))}
          </select>
        </label>
        <label className={LABEL_CLASS}>
          Usunięto od
          <input
            type="date"
            className={SELECT_CLASS}
            value={from}
            max={to || undefined}
            onChange={(e) => setFilters({ from: e.target.value })}
          />
        </label>
        <label className={LABEL_CLASS}>
          do
          <input
            type="date"
            className={SELECT_CLASS}
            value={to}
            min={from || undefined}
            onChange={(e) => setFilters({ to: e.target.value })}
          />
        </label>
        {hasFilters && (
          <button
            type="button"
            onClick={clearFilters}
            className="mb-0.5 rounded border border-slate-300 bg-white px-2.5 py-1 text-xs hover:bg-slate-50"
          >
            Wyczyść filtry
          </button>
        )}
      </div>

      <div className="mb-2 flex flex-wrap items-center gap-x-3 gap-y-2 text-xs">
        <label className="flex items-center gap-1 text-slate-500">
          Sortuj:
          <select
            className={SELECT_CLASS}
            value={sort}
            onChange={(e) => {
              const next = e.target.value as SortKey
              setFilters({ sort: next === 'deleted_at' ? null : next, dir: null })
            }}
          >
            {SORT_OPTIONS.map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </select>
        </label>
        <button
          type="button"
          onClick={() => setFilters({ dir: dir === DEFAULT_DIR[sort] ? (dir === 'asc' ? 'desc' : 'asc') : null })}
          className="rounded border border-slate-300 bg-white px-2 py-1 text-slate-700 hover:bg-slate-50"
          title="Zmień kierunek sortowania"
        >
          {dir === 'asc' ? '▲ rosnąco' : '▼ malejąco'}
        </button>
        <label className="flex items-center gap-1 text-slate-500">
          Na stronie:
          <select
            className={SELECT_CLASS}
            value={perPage}
            onChange={(e) => setFilters({ per_page: e.target.value === '20' ? null : e.target.value })}
          >
            {PER_PAGE_OPTIONS.map((n) => (
              <option key={n} value={n}>
                {n}
              </option>
            ))}
          </select>
        </label>
        {meta && (
          <span className="text-slate-500">
            {meta.total.toLocaleString('pl-PL')} {plural(meta.total, 'karta', 'karty', 'kart')}
          </span>
        )}
        {loading && <span className="text-slate-400">Ładowanie…</span>}

        {pageActive.length > 0 && (
          <div className="ml-auto flex flex-wrap items-center gap-2">
            <span className="text-slate-600">
              Zaznaczono {selectedOnPage.length} {plural(selectedOnPage.length, 'pozycję', 'pozycje', 'pozycji')}
            </span>
            <button
              type="button"
              onClick={() =>
                toggle(
                  pageActive.map((p) => p.id),
                  selectedOnPage.length < pageActive.length,
                )
              }
              className="rounded border border-slate-300 bg-white px-2 py-1 text-slate-700 hover:bg-slate-50"
            >
              {selectedOnPage.length < pageActive.length ? 'Zaznacz aktywne na stronie' : 'Odznacz wszystkie'}
            </button>
            <button
              type="button"
              disabled={selectedOnPage.length === 0 || restoring !== null}
              onClick={() =>
                void restore(
                  selectedOnPage.map((p) => p.id),
                  'sel',
                  `Przywrócić zaznaczone pozycje (${selectedOnPage.length})?`,
                  restoreNote(selectedOnPage.map((p) => p.detached)),
                )
              }
              className="rounded border border-blue-300 bg-white px-2 py-1 text-blue-700 hover:bg-blue-50 disabled:opacity-50"
            >
              {restoring === 'sel' ? 'Przywracam…' : 'Przywróć zaznaczone'}
            </button>
          </div>
        )}
      </div>

      {msg && <p className="mb-2 rounded bg-green-50 px-3 py-2 text-xs text-green-800">{msg}</p>}
      {err && <p className="mb-2 rounded bg-red-50 px-3 py-2 text-xs text-red-700">{err}</p>}

      <div className="overflow-x-auto rounded-xl bg-white shadow-sm">
        <table className={`min-w-full text-left text-xs ${loading && result ? 'opacity-60' : ''}`}>
          <thead className="border-b bg-slate-50 text-slate-500">
            <tr>
              <th className="w-8 px-3 py-2" />
              <th className="px-3 py-2">Źródło</th>
              <th className="px-3 py-2">Kod pozycji</th>
              <th className="px-3 py-2">U dostawcy</th>
              <th className="px-3 py-2">Pomijanie</th>
              <th className="px-3 py-2">Stan</th>
              <th className="px-3 py-2" />
            </tr>
          </thead>
          <tbody>
            {groups.length === 0 && (
              <tr>
                <td colSpan={7} className="px-3 py-6 text-center text-sm text-slate-500">
                  {loading ? (
                    'Ładowanie…'
                  ) : hasFilters ? (
                    <>
                      Nic nie pasuje do filtrów.{' '}
                      <button type="button" onClick={clearFilters} className="text-blue-700 underline">
                        Wyczyść filtry
                      </button>
                    </>
                  ) : (
                    'Brak kart pomijanych przy imporcie.'
                  )}
                </td>
              </tr>
            )}
            {groups.map((g) => {
              const activeIds = g.positions.filter((p) => p.restored_at === null).map((p) => p.id)
              const selectedInGroup = activeIds.filter((id) => selected.has(id)).length
              const groupKey = `g:${g.deletion_id}`
              const prices = priceLine(g.product)
              const detached = Boolean(g.detached_source)
              const note = detached ? DETACHED_RESTORE_NOTE : RESTORE_NOTE
              return (
                <Fragment key={g.deletion_id}>
                  <tr className="border-t-2 border-slate-200 bg-slate-50/70 align-top">
                    <td className="px-3 py-2">
                      {activeIds.length > 0 && (
                        <input
                          type="checkbox"
                          aria-label={`Zaznacz aktywne pozycje karty ${g.product.sku}`}
                          checked={selectedInGroup === activeIds.length}
                          ref={(el) => {
                            if (el) el.indeterminate = selectedInGroup > 0 && selectedInGroup < activeIds.length
                          }}
                          onChange={(e) => toggle(activeIds, e.target.checked)}
                        />
                      )}
                    </td>
                    <td colSpan={5} className="px-3 py-2">
                      <div className="flex flex-wrap items-baseline gap-x-2">
                        <span className="font-mono text-slate-700">{g.product.sku}</span>
                        <span className="text-sm font-medium text-slate-900">{g.product.name}</span>
                        {g.product.manufacturer && (
                          <button
                            type="button"
                            onClick={() => setFilters({ manufacturer: g.product.manufacturer })}
                            className="text-slate-500 hover:text-blue-700 hover:underline"
                            title="Pokaż tylko karty tego producenta"
                          >
                            {g.product.manufacturer}
                          </button>
                        )}
                      </div>
                      <div className="mt-0.5 text-slate-500">
                        {detached ? 'Odpięto od karty (karta zostaje)' : 'Usunięto'} {formatDateTime(g.deleted_at)}
                        {g.deleted_by ? ` przez ${g.deleted_by}` : ''}
                        {prices ? ` · ${prices}` : ''}
                        {' · '}
                        {g.active_count > 0
                          ? `pomijane: ${g.active_count} ${plural(g.active_count, 'pozycja', 'pozycje', 'pozycji')}`
                          : 'nic nie jest już pomijane'}
                        {g.hidden_count > 0 &&
                          ` · ${g.hidden_count} ${plural(g.hidden_count, 'pozycja ukryta', 'pozycje ukryte', 'pozycji ukrytych')} filtrem`}
                      </div>
                    </td>
                    <td className="px-3 py-2 text-right">
                      {activeIds.length > 0 && (
                        <button
                          type="button"
                          disabled={restoring !== null}
                          onClick={() =>
                            void restore(
                              activeIds,
                              groupKey,
                              `Przywrócić ${activeIds.length === 1 ? 'pozycję' : `wszystkie widoczne pozycje (${activeIds.length})`} karty ${g.product.sku}?`,
                              note,
                            )
                          }
                          className="whitespace-nowrap rounded border border-blue-300 px-2 py-1 text-[11px] text-blue-700 hover:bg-blue-50 disabled:opacity-50"
                          title={
                            g.hidden_count > 0
                              ? 'Zdejmuje pomijanie z aktywnych pozycji tej karty widocznych przy bieżącym filtrze'
                              : 'Zdejmuje pomijanie ze wszystkich aktywnych pozycji tej karty'
                          }
                        >
                          {restoring === groupKey
                            ? 'Przywracam…'
                            : g.hidden_count > 0
                              ? 'Przywróć widoczne'
                              : 'Przywróć wszystkie'}
                        </button>
                      )}
                    </td>
                  </tr>
                  {g.positions.length === 0 && (
                    <tr className="border-t border-slate-100">
                      <td colSpan={7} className="px-3 py-2 text-slate-400">
                        Brak pozycji w tym widoku.
                      </td>
                    </tr>
                  )}
                  {g.positions.map((pos) => {
                    const posKey = `p:${pos.id}`
                    const active = pos.restored_at === null
                    return (
                      <tr key={pos.id} className="border-t border-slate-100 align-top">
                        <td className="px-3 py-1.5">
                          {active && (
                            <input
                              type="checkbox"
                              aria-label={`Zaznacz pozycję ${pos.position_key}`}
                              checked={selected.has(pos.id)}
                              onChange={(e) => toggle([pos.id], e.target.checked)}
                            />
                          )}
                        </td>
                        <td className="px-3 py-1.5 text-slate-700">
                          <button
                            type="button"
                            onClick={() => setFilters({ source: pos.scope_key })}
                            className="text-left hover:text-blue-700 hover:underline"
                            title="Pokaż tylko to źródło"
                          >
                            {pos.source_label}
                          </button>
                        </td>
                        <td className="px-3 py-1.5">
                          <span className="font-mono text-slate-800">{pos.position_key}</span>
                          {pos.match_kind === 'sku' && <span className="ml-1 text-slate-500">po SKU karty</span>}
                        </td>
                        <td className="px-3 py-1.5 text-slate-700">
                          {pos.remote_sku && <span className="font-mono">{pos.remote_sku}</span>}
                          {pos.remote_sku && pos.position_label && ' · '}
                          {pos.position_label}
                          {!pos.remote_sku && !pos.position_label && <span className="text-slate-400">—</span>}
                        </td>
                        <td className="whitespace-nowrap px-3 py-1.5 text-slate-600">{hitsText(pos)}</td>
                        <td className="px-3 py-1.5">
                          {active ? (
                            <span className="rounded bg-amber-100 px-1.5 py-0.5 text-amber-900">aktywna</span>
                          ) : (
                            <span className="text-slate-600">
                              przywrócona {formatDateTime(pos.restored_at ?? '')}
                              {pos.restored_by ? ` przez ${pos.restored_by}` : ''}
                            </span>
                          )}
                        </td>
                        <td className="px-3 py-1.5 text-right">
                          {active && (
                            <button
                              type="button"
                              disabled={restoring !== null}
                              onClick={() =>
                                void restore(
                                  [pos.id],
                                  posKey,
                                  `Przywrócić pozycję ${pos.position_key} (${pos.source_label}) karty ${g.product.sku}?`,
                                  note,
                                )
                              }
                              className="whitespace-nowrap rounded border border-slate-300 px-2 py-1 text-[11px] text-slate-700 hover:bg-slate-50 disabled:opacity-50"
                              title="Zdejmuje pomijanie tej pozycji"
                            >
                              {restoring === posKey ? 'Przywracam…' : 'Przywróć'}
                            </button>
                          )}
                        </td>
                      </tr>
                    )
                  })}
                </Fragment>
              )
            })}
          </tbody>
        </table>
      </div>

      {meta && meta.last_page > 1 && (
        <div className="mt-3 flex items-center gap-3 text-sm">
          <button
            type="button"
            disabled={loading || page <= 1}
            className="rounded border px-2 py-1 disabled:opacity-40"
            onClick={() => setFilters({ page: page - 1 > 1 ? String(page - 1) : null }, { keepPage: true })}
          >
            ← Poprzednia
          </button>
          <span className="text-slate-600">
            Strona {meta.current_page} / {meta.last_page}
          </span>
          <button
            type="button"
            disabled={loading || page >= meta.last_page}
            className="rounded border px-2 py-1 disabled:opacity-40"
            onClick={() => setFilters({ page: String(page + 1) }, { keepPage: true })}
          >
            Następna →
          </button>
        </div>
      )}
    </div>
  )
}
