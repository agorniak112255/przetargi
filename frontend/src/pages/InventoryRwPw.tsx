import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { useSearchParams } from 'react-router-dom'
import { InventoryTabs } from '../components/InventoryTabs'
import { ProductVerifyModal } from '../components/ProductVerifyModal'
import {
  api,
  type RwPwDoc,
  type RwPwItemRef,
  type RwPwItemRow,
  type RwPwOperatorRow,
  type RwPwPair,
  type RwPwResponse,
} from '../lib/api'
import { erpQty, erpUnitLabel } from '../lib/erpStock'
import { plural } from '../lib/plural'
import { formatDate, formatDateTime, formatPrice } from '../lib/priceChange'

/**
 * Zapasy — RW → PW: towar wydany dokumentem RW i zaraz przyjęty z powrotem PW w tej samej ilości.
 * Powstaje nowa partia z nową datą, choć towar nie rotował. Część par to uczciwe korekty (np. przecena),
 * więc lista jest „do wyjaśnienia”. Dane tylko z XL (GET /api/inventory/rw-pw); strona nic nie liczy poza
 * różnicą wartości w parze. Filtry, widok, sortowanie i strona w adresie — link odtwarza widok.
 */

type View = 'pairs' | 'items' | 'operators'
type PairSort = 'date' | 'value' | 'gap' | 'code'
type ItemSort = 'pairs' | 'value' | 'last_date' | 'code'
type SortKey = PairSort | ItemSort
type SortDir = 'asc' | 'desc'

const VIEWS: readonly View[] = ['pairs', 'items', 'operators']
const DEFAULT_VIEW: View = 'pairs'
const VIEW_OPTIONS: { value: View; label: string }[] = [
  { value: 'pairs', label: 'Pary' },
  { value: 'items', label: 'Po towarze' },
  { value: 'operators', label: 'Po osobie' },
]

const MONTHS = ['3', '6', '12'] as const
const DEFAULT_MONTHS = '12'
const GAPS = ['0', '3', '7', '30'] as const
const DEFAULT_GAP = '3'
const GAP_OPTIONS: { value: (typeof GAPS)[number]; label: string }[] = [
  { value: '0', label: 'ten sam dzień' },
  { value: '3', label: 'do 3 dni' },
  { value: '7', label: 'do 7 dni' },
  { value: '30', label: 'do 30 dni' },
]

const PAIR_SORTS: readonly PairSort[] = ['date', 'value', 'gap', 'code']
const ITEM_SORTS: readonly ItemSort[] = ['pairs', 'value', 'last_date', 'code']
/** Domyślne sortowanie widoku — takie samo jak domyślne w API (kierunek desc). */
const DEFAULT_SORT: Record<'pairs' | 'items', SortKey> = { pairs: 'date', items: 'value' }
/** Kierunek po kliknięciu nowej kolumny: daty najnowsze, kwoty i liczby największe, odstęp najkrótszy, kod alfabetycznie. */
const DEFAULT_DIR: Record<SortKey, SortDir> = {
  date: 'desc',
  value: 'desc',
  gap: 'asc',
  code: 'asc',
  pairs: 'desc',
  last_date: 'desc',
}

const PER_PAGE_OPTIONS = ['20', '50', '100', '200'] as const
const DEFAULT_PER_PAGE = '50'
const SEARCH_DEBOUNCE_MS = 300

function pick<T extends string>(value: string | null, allowed: readonly T[], fallback: T): T {
  return value !== null && (allowed as readonly string[]).includes(value) ? (value as T) : fallback
}

function fmtInt(n: number): string {
  return n.toLocaleString('pl-PL')
}

/** Kwota w zł, 2 miejsca — jak na stronie Zalegające. */
function fmtMoney(n: number): string {
  return `${formatPrice(n)} zł`
}

/** Różnica PW − RW ze znakiem: „+7 388,10 zł”, „−120,00 zł”. */
function fmtSignedMoney(n: number): string {
  const sign = n > 0 ? '+' : n < 0 ? '−' : '±'
  return `${sign}${formatPrice(Math.abs(n))} zł`
}

function gapLabel(days: number): string {
  return days === 0 ? 'ten sam dzień' : `${days} ${plural(days, 'dzień', 'dni', 'dni')}`
}

/** Numery stron z wielokropkiem — jak na liście Produkty i stronie Zalegające. */
function pageNumbers(current: number, last: number): Array<number | '…'> {
  if (last <= 7) return Array.from({ length: last }, (_, i) => i + 1)
  const pages = new Set<number>([1, last, current, current - 1, current + 1])
  if (current <= 3) [2, 3, 4].forEach((n) => pages.add(n))
  if (current >= last - 2) [last - 1, last - 2, last - 3].forEach((n) => pages.add(n))
  const sorted = [...pages].filter((n) => n >= 1 && n <= last).sort((a, b) => a - b)
  const out: Array<number | '…'> = []
  for (let i = 0; i < sorted.length; i++) {
    if (i > 0 && sorted[i] - sorted[i - 1] > 1) out.push('…')
    out.push(sorted[i])
  }
  return out
}

export function InventoryRwPw() {
  const [params, setParams] = useSearchParams()

  const view = pick<View>(params.get('view'), VIEWS, DEFAULT_VIEW)
  const months = pick(params.get('months'), MONTHS, DEFAULT_MONTHS)
  const gap = pick(params.get('gap'), GAPS, DEFAULT_GAP)
  const sameValue = params.get('same_value') === '1'
  const operator = (params.get('operator') ?? '').trim()
  const search = params.get('search') ?? ''
  const sort: SortKey | null =
    view === 'pairs'
      ? pick<PairSort>(params.get('sort'), PAIR_SORTS, 'date')
      : view === 'items'
        ? pick<ItemSort>(params.get('sort'), ITEM_SORTS, 'value')
        : null
  const dir = sort ? pick<SortDir>(params.get('dir'), ['asc', 'desc'], DEFAULT_DIR[sort]) : 'desc'
  const page = Math.max(1, Math.floor(Number(params.get('page'))) || 1)
  const perPage = pick(params.get('per_page'), PER_PAGE_OPTIONS, DEFAULT_PER_PAGE)

  const apiQuery = useMemo(() => {
    const qs = new URLSearchParams()
    qs.set('view', view)
    qs.set('months', months)
    qs.set('gap', gap)
    qs.set('same_value', sameValue ? '1' : '0')
    if (operator) qs.set('operator', operator)
    if (search.trim()) qs.set('search', search.trim())
    // Widok „Po osobie” bez sortowania i stronicowania (zawsze od największej wartości).
    if (sort) {
      qs.set('sort', sort)
      qs.set('dir', dir)
      qs.set('page', String(page))
      qs.set('per_page', perPage)
    }
    return qs.toString()
  }, [view, months, gap, sameValue, operator, search, sort, dir, page, perPage])

  const [result, setResult] = useState<RwPwResponse | null>(null)
  const [loading, setLoading] = useState(false)
  const [err, setErr] = useState('')
  const [previewId, setPreviewId] = useState<number | null>(null)
  const seq = useRef(0)

  // Pole wyszukiwania: wpis od razu w polu, do adresu (i zapytania) po 300 ms bez pisania — jak Zalegające.
  const [searchInput, setSearchInput] = useState(search)
  const pushedSearch = useRef(search)

  /** Zmiana filtra = strona 1 (chyba że keepPage); pusta wartość albo domyślna usuwa parametr z adresu. */
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

  // Adres zmieniony z zewnątrz (wstecz, „Wyczyść filtry”, klik w liczbę par) — pole idzie za nim.
  useEffect(() => {
    if (search !== pushedSearch.current) {
      pushedSearch.current = search
      setSearchInput(search)
    }
  }, [search])

  useEffect(() => {
    if (searchInput === pushedSearch.current) return
    const t = window.setTimeout(() => {
      pushedSearch.current = searchInput
      setFilters({ search: searchInput }, { replace: true })
    }, SEARCH_DEBOUNCE_MS)
    return () => window.clearTimeout(t)
  }, [searchInput, setFilters])

  const load = useCallback(async () => {
    const my = ++seq.current
    setLoading(true)
    setErr('')
    try {
      const res = await api<RwPwResponse>(`/inventory/rw-pw?${apiQuery}`)
      // Szybkie klikanie filtrów — spóźniona odpowiedź nie nadpisuje nowszej.
      if (my !== seq.current) return
      setResult(res)
    } catch (ex) {
      if (my === seq.current) setErr(ex instanceof Error ? ex.message : 'Błąd wczytywania par RW → PW')
    } finally {
      if (my === seq.current) setLoading(false)
    }
  }, [apiQuery])

  useEffect(() => {
    void load()
  }, [load])

  // Strona za końcem listy (np. link sprzed nocnego odczytu z XL) — wróć na ostatnią istniejącą.
  useEffect(() => {
    const m = result?.meta
    if (result && result.data.length === 0 && m && m.current_page > 1 && m.current_page > m.last_page) {
      setFilters({ page: m.last_page > 1 ? String(m.last_page) : null }, { keepPage: true, replace: true })
    }
  }, [result, setFilters])

  function clickSort(key: SortKey) {
    if (!sort || view === 'operators') return
    const nextDir: SortDir = key === sort ? (dir === 'asc' ? 'desc' : 'asc') : DEFAULT_DIR[key]
    setFilters({
      sort: key === DEFAULT_SORT[view] ? null : key,
      dir: nextDir === DEFAULT_DIR[key] ? null : nextDir,
    })
  }

  /** Inny widok: sortowanie wraca do domyślnego danego widoku. */
  function switchView(next: View, patch: Record<string, string | null> = {}) {
    setFilters({ view: next === DEFAULT_VIEW ? null : next, sort: null, dir: null, ...patch })
  }

  function showOperatorPairs(acronym: string) {
    switchView('pairs', { operator: acronym })
  }

  function showItemPairs(code: string) {
    switchView('pairs', { search: code })
  }

  function goToPage(n: number) {
    setFilters({ page: n > 1 ? String(n) : null }, { keepPage: true })
  }

  /** Czyści filtry; widok i liczba wierszy na stronę zostają. */
  function clearFilters() {
    setParams((prev) => {
      const next = new URLSearchParams()
      for (const k of ['view', 'per_page']) {
        const v = prev.get(k)
        if (v) next.set(k, v)
      }
      return next
    })
  }

  const hasFilters = Boolean(months !== DEFAULT_MONTHS || gap !== DEFAULT_GAP || sameValue || operator || search)
  // Odpowiedź dla innego widoku (tuż po przełączeniu) nie trafia do tabeli — ma inny kształt wierszy.
  const current = result && result.view === view ? result : null
  const meta = current?.meta ?? null
  const summary = result?.summary
  const rowCount = current?.data.length ?? 0
  const from = meta && rowCount > 0 ? (meta.current_page - 1) * meta.per_page + 1 : null
  const to = from != null ? from + rowCount - 1 : null
  const pages = meta ? pageNumbers(meta.current_page, Math.max(1, meta.last_page)) : []
  // Akronim z adresu, którego nie ma w liście z odczytu (np. stary link) — i tak widoczny w wyborze.
  const operatorOptions = useMemo(() => {
    const list = result?.operators ?? []
    return operator && !list.includes(operator) ? [operator, ...list] : list
  }, [result?.operators, operator])

  const controls = meta && (
    <ListControls
      currentPage={meta.current_page}
      lastPage={Math.max(1, meta.last_page)}
      pages={pages}
      perPage={perPage}
      disabled={loading}
      onPage={goToPage}
      onPerPage={(v) => setFilters({ per_page: v === DEFAULT_PER_PAGE ? null : v })}
    />
  )

  const emptyText = loading || (!current && !err) ? 'Ładowanie…' : err && !current ? 'Nie udało się wczytać par RW → PW.' : null
  const emptyRow = (colSpan: number) =>
    rowCount === 0 ? (
      <tr>
        <td colSpan={colSpan} className="p-8 text-center text-slate-500">
          {emptyText ?? (
            <>
              Brak par RW → PW przy wybranych filtrach.
              {hasFilters && (
                <button type="button" className="ml-1 text-blue-600 hover:underline" onClick={clearFilters}>
                  Wyczyść filtry
                </button>
              )}
            </>
          )}
        </td>
      </tr>
    ) : null

  return (
    <div>
      <InventoryTabs />
      <div className="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold">Zapasy — RW → PW</h1>
          <p className="mt-1 text-xs text-slate-500">
            {meta ? (
              <>
                Łącznie <span className="font-medium text-slate-700">{fmtInt(meta.total)}</span>
                {from != null && to != null ? ` · wyświetlono ${from}–${to}` : ''}
                {` · ${perPage}/stronę`}
              </>
            ) : current && view === 'operators' ? (
              <>
                <span className="font-medium text-slate-700">{fmtInt(rowCount)}</span>{' '}
                {plural(rowCount, 'osoba', 'osoby', 'osób')}
              </>
            ) : loading ? (
              'Ładowanie…'
            ) : (
              ''
            )}
            {result?.from ? ` · od ${formatDate(result.from)}` : ''}
            {loading && (meta || current) ? ' · ładowanie…' : ''}
          </p>
          <p className="mt-0.5 max-w-3xl text-xs text-slate-600">
            Pary dokumentów XL: RW i PW tego samego towaru w tej samej ilości w krótkim odstępie — powstaje nowa partia
            z nową datą bez ruchu towaru. Część to uczciwe korekty (np. przecena), więc lista jest do wyjaśnienia.
          </p>
        </div>
        <p className="text-[11px] text-slate-500">
          {result
            ? result.synced_at
              ? `Odczyt z XL: ${formatDateTime(result.synced_at)} (raz na dobę w nocy)`
              : 'Brak odczytu z XL (odczyt raz na dobę w nocy)'
            : ''}
        </p>
      </div>

      <div className="mb-4 grid gap-2 sm:grid-cols-3 xl:grid-cols-5">
        <SummaryTile number={summary ? fmtInt(summary.pairs) : '…'} label="Pary RW → PW" hint="po wybranych filtrach" />
        <SummaryTile number={summary ? fmtInt(summary.items) : '…'} label="Towary" hint="różne towary XL w parach" />
        <SummaryTile
          number={summary ? fmtMoney(summary.value) : '…'}
          numberClass="text-amber-800"
          label="Wartość"
          hint="netto, wartość wydana dokumentami RW (wg XL)"
        />
        <SummaryTile
          number={summary ? fmtInt(summary.same_value) : '…'}
          numberClass="text-amber-800"
          label="Ta sama wartość"
          hint={sameValue ? 'filtr włączony — kliknij, żeby zdjąć' : 'RW i PW o identycznej wartości — najpierw do wyjaśnienia'}
          active={sameValue}
          onClick={() => setFilters({ same_value: sameValue ? null : '1' })}
        />
        <SummaryTile
          number={summary ? fmtInt(summary.operators) : '…'}
          label="Osoby"
          hint="operatorzy XL w parach"
        />
      </div>

      <div className="mb-4 flex flex-wrap items-end gap-x-3 gap-y-2 rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs shadow-sm">
        <Segments
          id="rwpw-months"
          label="Okres"
          options={MONTHS.map((m) => ({ value: m, label: m }))}
          value={months}
          numeric
          onChange={(m) => setFilters({ months: m === DEFAULT_MONTHS ? null : m })}
        />
        <span className="pb-1 text-xs text-slate-500">mies.</span>
        <Segments
          id="rwpw-gap"
          label="Odstęp RW → PW"
          options={GAP_OPTIONS}
          value={gap}
          onChange={(g) => setFilters({ gap: g === DEFAULT_GAP ? null : g })}
        />
        <label
          className="flex items-center gap-1.5 pb-1 text-xs text-slate-700"
          title="Tylko pary, w których RW i PW mają identyczną wartość — nowa partia bez zmiany ceny."
        >
          <input
            type="checkbox"
            checked={sameValue}
            onChange={(e) => setFilters({ same_value: e.target.checked ? '1' : null })}
          />
          Tylko ta sama wartość
        </label>
        <label
          className="flex flex-col gap-0.5 text-[11px] text-slate-500"
          title="Pary, w których ta osoba wystawiła albo zatwierdziła RW lub PW"
        >
          Osoba
          <select
            className="rounded border border-slate-300 bg-white px-1.5 py-1 font-mono text-xs text-slate-800"
            value={operator}
            onChange={(e) => setFilters({ operator: e.target.value })}
          >
            <option value="">wszystkie</option>
            {operatorOptions.map((o) => (
              <option key={o} value={o}>
                {o}
              </option>
            ))}
          </select>
        </label>
        <label className="flex min-w-[14rem] flex-1 flex-col gap-0.5 text-[11px] text-slate-500">
          Szukaj
          <input
            type="search"
            className="rounded border border-slate-300 bg-white px-1.5 py-1 text-xs text-slate-800"
            placeholder="kod XL, nazwa towaru, numer dokumentu"
            value={searchInput}
            onChange={(e) => setSearchInput(e.target.value)}
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

      {err && (
        <p className="mb-2 rounded bg-red-50 px-3 py-2 text-xs text-red-700">
          Nie udało się wczytać par RW → PW: {err}{' '}
          <button type="button" className="font-medium underline" onClick={() => void load()}>
            Spróbuj ponownie
          </button>
        </p>
      )}

      <div className="overflow-x-auto rounded-xl bg-white p-4 shadow-sm">
        <div className="flex flex-wrap items-end justify-between gap-3">
          <Segments id="rwpw-view" label="Widok" options={VIEW_OPTIONS} value={view} onChange={(v) => switchView(v)} />
          {operator && (
            <p className="pb-1 text-xs text-slate-600">
              Osoba: <span className="font-mono font-semibold text-slate-900">{operator}</span>
              <button
                type="button"
                className="ml-1.5 text-blue-600 hover:underline"
                onClick={() => setFilters({ operator: null })}
              >
                zdejmij
              </button>
            </p>
          )}
        </div>
        {controls}

        {view === 'pairs' && (
          <table className="w-full text-left text-xs">
            <thead>
              <tr className="border-b bg-slate-50">
                <th className="w-8 px-1 py-2 text-right font-normal text-slate-500">Lp.</th>
                <th className="whitespace-nowrap p-2 font-semibold text-slate-700">Karta</th>
                <SortTh label="Towar (kod XL)" k="code" sort={sort} dir={dir} onSort={clickSort} />
                <th className="whitespace-nowrap p-2" aria-sort={ariaSort(sort, dir, ['date', 'value'])}>
                  <span className="inline-flex items-center gap-2">
                    <span className="font-semibold text-slate-700">RW</span>
                    <SortButton label="data" k="date" sort={sort} dir={dir} onSort={clickSort} />
                    <span className="text-slate-300">/</span>
                    <SortButton label="wartość" k="value" sort={sort} dir={dir} onSort={clickSort} />
                  </span>
                </th>
                <th className="whitespace-nowrap p-2 font-semibold text-slate-700">PW</th>
                <SortTh label="Odstęp" k="gap" sort={sort} dir={dir} onSort={clickSort} />
                <th className="whitespace-nowrap p-2 font-semibold text-slate-700">Uwagi</th>
              </tr>
            </thead>
            <tbody>
              {current?.view === 'pairs' &&
                current.data.map((pair, i) => (
                  <PairRow
                    key={pair.id}
                    pair={pair}
                    index={(from ?? 1) + i}
                    striped={i % 2 === 1}
                    onOpenCard={setPreviewId}
                  />
                ))}
              {emptyRow(7)}
            </tbody>
          </table>
        )}

        {view === 'items' && (
          <table className="w-full text-left text-xs">
            <thead>
              <tr className="border-b bg-slate-50">
                <th className="w-8 px-1 py-2 text-right font-normal text-slate-500">Lp.</th>
                <th className="whitespace-nowrap p-2 font-semibold text-slate-700">Karta</th>
                <SortTh label="Towar (kod XL)" k="code" sort={sort} dir={dir} onSort={clickSort} />
                <SortTh label="Pary" k="pairs" sort={sort} dir={dir} onSort={clickSort} align="right" />
                <th className="whitespace-nowrap p-2 text-right font-semibold text-slate-700">Ilość łącznie</th>
                <SortTh label="Wartość" k="value" sort={sort} dir={dir} onSort={clickSort} align="right" />
                <th className="whitespace-nowrap p-2 text-right font-semibold text-slate-700">Ta sama wartość</th>
                <th className="whitespace-nowrap p-2 font-semibold text-slate-700">Osoby</th>
                <SortTh label="Pierwsza / ostatnia" k="last_date" sort={sort} dir={dir} onSort={clickSort} />
              </tr>
            </thead>
            <tbody>
              {current?.view === 'items' &&
                current.data.map((row, i) => (
                  <ItemRow
                    key={row.item.erp_item_id ?? row.item.code}
                    row={row}
                    index={(from ?? 1) + i}
                    striped={i % 2 === 1}
                    onOpenCard={setPreviewId}
                    onShowPairs={showItemPairs}
                    onOperator={showOperatorPairs}
                  />
                ))}
              {emptyRow(9)}
            </tbody>
          </table>
        )}

        {view === 'operators' && (
          <table className="w-full text-left text-xs">
            <thead>
              <tr className="border-b bg-slate-50">
                <th className="w-8 px-1 py-2 text-right font-normal text-slate-500">Lp.</th>
                <th className="whitespace-nowrap p-2 font-semibold text-slate-700" title="Kto wystawił RW">
                  Osoba (RW)
                </th>
                <th className="whitespace-nowrap p-2 text-right font-semibold text-slate-700">Pary</th>
                <th className="whitespace-nowrap p-2 text-right font-semibold text-slate-700">Towary</th>
                <th className="whitespace-nowrap p-2 text-right font-semibold text-slate-700">
                  Wartość <span className="text-[10px] font-normal text-slate-400">▼</span>
                </th>
                <th className="whitespace-nowrap p-2 text-right font-semibold text-slate-700">Ta sama wartość</th>
                <th
                  className="whitespace-nowrap p-2 text-right font-semibold text-slate-700"
                  title="Ile par, w których PW wystawił ktoś inny niż osoba, która wystawiła RW"
                >
                  PW wystawił ktoś inny
                </th>
                <th className="whitespace-nowrap p-2 font-semibold text-slate-700">Ostatnia para</th>
              </tr>
            </thead>
            <tbody>
              {current?.view === 'operators' &&
                current.data.map((row, i) => (
                  <OperatorRow
                    key={row.operator}
                    row={row}
                    index={i + 1}
                    striped={i % 2 === 1}
                    onOpen={showOperatorPairs}
                  />
                ))}
              {emptyRow(8)}
            </tbody>
          </table>
        )}

        {controls && rowCount > 0 && <div className="mt-2 border-t pt-1">{controls}</div>}

        <ProductVerifyModal productId={previewId} query={search.trim()} onClose={() => setPreviewId(null)} />
      </div>
    </div>
  )
}

function SummaryTile({
  number,
  numberClass = 'text-slate-800',
  label,
  hint,
  active,
  onClick,
}: {
  number: string
  numberClass?: string
  label: string
  hint: string
  active?: boolean
  onClick?: () => void
}) {
  const body = (
    <>
      <div className={`text-xl font-semibold tabular-nums ${numberClass}`}>{number}</div>
      <div className="text-xs font-medium text-slate-800">{label}</div>
      <div className="text-[11px] text-slate-500">{hint}</div>
    </>
  )
  if (!onClick) {
    return <div className="rounded-xl border border-slate-200 bg-white px-3 py-2 shadow-sm">{body}</div>
  }
  return (
    <button
      type="button"
      onClick={onClick}
      aria-pressed={Boolean(active)}
      title={active ? 'Kliknij, żeby zdjąć ten filtr' : 'Pokaż tylko te pary'}
      className={`rounded-xl border px-3 py-2 text-left shadow-sm transition ${
        active
          ? 'border-sky-400 bg-sky-50 ring-1 ring-sky-300'
          : 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50'
      }`}
    >
      {body}
    </button>
  )
}

/** Segmenty wyboru jak „Bez sprzedaży od” na stronie Zalegające. */
function Segments<T extends string>({
  id,
  label,
  options,
  value,
  numeric,
  onChange,
}: {
  id: string
  label: string
  options: readonly { value: T; label: string }[]
  value: T
  numeric?: boolean
  onChange: (v: T) => void
}) {
  return (
    <div className="flex flex-col gap-0.5 text-[11px] text-slate-500">
      <span id={`${id}-label`}>{label}</span>
      <div className="inline-flex overflow-hidden rounded border border-slate-300" role="group" aria-labelledby={`${id}-label`}>
        {options.map((o, i) => {
          const active = o.value === value
          return (
            <button
              key={o.value}
              type="button"
              aria-pressed={active}
              onClick={() => onChange(o.value)}
              className={`whitespace-nowrap px-2 py-1 text-xs ${numeric ? 'tabular-nums' : ''} ${
                i > 0 ? 'border-l border-slate-300' : ''
              } ${active ? 'bg-blue-600 font-semibold text-white' : 'bg-white text-slate-700 hover:bg-slate-50'}`}
            >
              {o.label}
            </button>
          )
        })}
      </div>
    </div>
  )
}

/** Miniatura karty (okno weryfikacji), sam SKU gdy karta bez zdjęcia, „bez karty” dla towaru bez powiązania. */
function CardCell({ item, onOpenCard }: { item: RwPwItemRef; onOpenCard: (productId: number) => void }) {
  const card = item.card
  if (!card) {
    return (
      <span
        className="inline-block whitespace-nowrap rounded bg-slate-100 px-1.5 py-0.5 text-[11px] text-slate-500"
        title="Towar XL nie jest połączony z kartą w katalogu"
      >
        bez karty
      </span>
    )
  }
  const title = `${card.name}${card.manufacturer ? ` · ${card.manufacturer}` : ''} — kliknij, aby otworzyć okno weryfikacji karty`
  return card.thumb_url ? (
    <button
      type="button"
      onClick={() => onOpenCard(card.id)}
      className="block overflow-hidden rounded border border-slate-200 bg-slate-50"
      title={title}
    >
      <img src={card.thumb_url} alt="" className="h-12 w-12 bg-white object-contain" />
    </button>
  ) : (
    <button
      type="button"
      onClick={() => onOpenCard(card.id)}
      className="font-mono text-[11px] text-blue-600 hover:underline"
      title={title}
    >
      {card.sku}
    </button>
  )
}

function ItemCell({ item }: { item: RwPwItemRef }) {
  return (
    <td className="min-w-[12rem] max-w-[20rem] p-2">
      <span className="font-mono text-slate-900">{item.code}</span>
      <div className="line-clamp-2 break-words text-slate-800" title={item.name}>
        {item.name}
      </div>
      {item.stock_total != null && (
        <div className="text-[11px] tabular-nums text-slate-500" title="Stan obecny, wszystkie magazyny">
          stan: {erpQty(item.stock_total)}
          {erpUnitLabel(item.unit)}
        </div>
      )}
    </td>
  )
}

/** „wyst. X / zatw. Y”; gdy ta sama osoba — jeden akronim. */
function DocPeople({ doc }: { doc: RwPwDoc }) {
  const { operator, approver } = doc
  if (!operator && !approver) return <span className="text-slate-400">osoba nieznana</span>
  if (operator && (!approver || approver === operator)) {
    return (
      <span className="font-mono text-slate-700" title={approver ? 'Wystawił i zatwierdził' : 'Wystawił'}>
        {operator}
      </span>
    )
  }
  return (
    <>
      {operator && (
        <>
          wyst. <span className="font-mono text-slate-700">{operator}</span>
        </>
      )}
      {operator && approver ? ' / ' : ''}
      {approver && (
        <>
          zatw. <span className="font-mono text-slate-700">{approver}</span>
        </>
      )}
    </>
  )
}

function DocCell({ doc, unit }: { doc: RwPwDoc; unit: string | null }) {
  return (
    <td className="whitespace-nowrap p-2">
      <div className="font-mono text-slate-900">{doc.number}</div>
      <div className="tabular-nums text-slate-600">
        {formatDate(doc.date)}
        {doc.warehouse && (
          <>
            {' · mag. '}
            <span className="font-mono">{doc.warehouse}</span>
          </>
        )}
      </div>
      <div className="tabular-nums text-slate-700">
        {erpQty(doc.quantity)}
        {erpUnitLabel(unit)} · <span className="font-semibold text-slate-900">{fmtMoney(doc.value)}</span>
      </div>
      <div className="text-[11px] text-slate-500">
        <DocPeople doc={doc} />
      </div>
    </td>
  )
}

function Tag({ tone, title, children }: { tone: 'amber' | 'neutral'; title: string; children: ReactNode }) {
  return (
    <span
      className={`inline-block whitespace-nowrap rounded px-1.5 py-0.5 text-[11px] ${
        tone === 'amber' ? 'bg-amber-100 font-medium text-amber-800' : 'bg-slate-100 text-slate-600'
      }`}
      title={title}
    >
      {children}
    </span>
  )
}

function PairRow({
  pair,
  index,
  striped,
  onOpenCard,
}: {
  pair: RwPwPair
  index: number
  striped: boolean
  onOpenCard: (productId: number) => void
}) {
  const diff = pair.pw.value - pair.rw.value
  return (
    <tr className={`border-b align-top hover:bg-sky-50 ${striped ? 'bg-slate-100/60' : ''}`}>
      <td className="w-8 px-1 py-2 text-right tabular-nums text-slate-400">{index}</td>
      <td className="p-2">
        <CardCell item={pair.item} onOpenCard={onOpenCard} />
      </td>
      <ItemCell item={pair.item} />
      <DocCell doc={pair.rw} unit={pair.item.unit} />
      <DocCell doc={pair.pw} unit={pair.item.unit} />
      <td className="whitespace-nowrap p-2 tabular-nums text-slate-700">{gapLabel(pair.gap_days)}</td>
      <td className="p-2">
        <div className="flex flex-col items-start gap-1">
          {pair.same_value ? (
            <Tag
              tone="amber"
              title="RW i PW mają identyczną wartość — nowa partia bez zmiany ceny. Najpierw do wyjaśnienia."
            >
              ta sama wartość
            </Tag>
          ) : (
            <Tag tone="neutral" title="Wartość PW − wartość RW; może to być np. przecena albo korekta ceny.">
              inna wartość: <span className="tabular-nums">{fmtSignedMoney(diff)}</span>
            </Tag>
          )}
          {!pair.same_warehouse && (
            <Tag
              tone="neutral"
              title={`RW z magazynu ${pair.rw.warehouse ?? '?'}, PW na magazyn ${pair.pw.warehouse ?? '?'}`}
            >
              inny magazyn
            </Tag>
          )}
        </div>
      </td>
    </tr>
  )
}

function OperatorChip({ acronym, onClick }: { acronym: string; onClick: (acronym: string) => void }) {
  return (
    <button
      type="button"
      onClick={() => onClick(acronym)}
      className="rounded border border-slate-200 bg-slate-50 px-1.5 py-0.5 font-mono text-[11px] text-slate-700 hover:border-slate-300 hover:bg-slate-100"
      title={`Pokaż pary, w których ${acronym} wystawił albo zatwierdził RW lub PW`}
    >
      {acronym}
    </button>
  )
}

function ItemRow({
  row,
  index,
  striped,
  onOpenCard,
  onShowPairs,
  onOperator,
}: {
  row: RwPwItemRow
  index: number
  striped: boolean
  onOpenCard: (productId: number) => void
  onShowPairs: (code: string) => void
  onOperator: (acronym: string) => void
}) {
  return (
    <tr className={`border-b align-top hover:bg-sky-50 ${striped ? 'bg-slate-100/60' : ''}`}>
      <td className="w-8 px-1 py-2 text-right tabular-nums text-slate-400">{index}</td>
      <td className="p-2">
        <CardCell item={row.item} onOpenCard={onOpenCard} />
      </td>
      <ItemCell item={row.item} />
      <td className="whitespace-nowrap p-2 text-right">
        <button
          type="button"
          onClick={() => onShowPairs(row.item.code)}
          className="font-semibold tabular-nums text-blue-600 hover:underline"
          title="Pokaż pary tego towaru"
        >
          {fmtInt(row.pairs)}
        </button>
      </td>
      <td className="whitespace-nowrap p-2 text-right tabular-nums text-slate-700">
        {erpQty(row.quantity)}
        <span className="text-slate-500">{erpUnitLabel(row.item.unit)}</span>
      </td>
      <td className="whitespace-nowrap p-2 text-right font-semibold tabular-nums text-slate-900">
        {fmtMoney(row.value)}
      </td>
      <td className="whitespace-nowrap p-2 text-right tabular-nums">
        {row.same_value > 0 ? (
          <span className="font-semibold text-amber-800">{fmtInt(row.same_value)}</span>
        ) : (
          <span className="text-slate-400">0</span>
        )}
      </td>
      <td className="p-2">
        {row.operators.length > 0 ? (
          <div className="flex max-w-[12rem] flex-wrap gap-1">
            {row.operators.map((o) => (
              <OperatorChip key={o} acronym={o} onClick={onOperator} />
            ))}
          </div>
        ) : (
          <span className="text-slate-400">—</span>
        )}
      </td>
      <td className="whitespace-nowrap p-2 tabular-nums text-slate-700">
        {row.first_date === row.last_date ? (
          formatDate(row.last_date)
        ) : (
          <>
            <div className="text-[11px] text-slate-500">{formatDate(row.first_date)}</div>
            <div>{formatDate(row.last_date)}</div>
          </>
        )}
      </td>
    </tr>
  )
}

function OperatorRow({
  row,
  index,
  striped,
  onOpen,
}: {
  row: RwPwOperatorRow
  index: number
  striped: boolean
  onOpen: (acronym: string) => void
}) {
  return (
    <tr
      className={`cursor-pointer border-b hover:bg-sky-50 ${striped ? 'bg-slate-100/60' : ''}`}
      onClick={() => onOpen(row.operator)}
    >
      <td className="w-8 px-1 py-2 text-right tabular-nums text-slate-400">{index}</td>
      <td className="p-2">
        <button
          type="button"
          onClick={(e) => {
            e.stopPropagation()
            onOpen(row.operator)
          }}
          className="font-mono text-sm font-semibold text-blue-700 hover:underline"
          title="Pokaż pary tej osoby"
        >
          {row.operator}
        </button>
      </td>
      <td className="whitespace-nowrap p-2 text-right tabular-nums text-slate-800">{fmtInt(row.pairs)}</td>
      <td className="whitespace-nowrap p-2 text-right tabular-nums text-slate-700">{fmtInt(row.items)}</td>
      <td className="whitespace-nowrap p-2 text-right font-semibold tabular-nums text-slate-900">
        {fmtMoney(row.value)}
      </td>
      <td className="whitespace-nowrap p-2 text-right tabular-nums">
        {row.same_value > 0 ? (
          <span className="font-semibold text-amber-800">{fmtInt(row.same_value)}</span>
        ) : (
          <span className="text-slate-400">0</span>
        )}
      </td>
      <td className="whitespace-nowrap p-2 text-right tabular-nums text-slate-700">{fmtInt(row.pw_by_other)}</td>
      <td className="whitespace-nowrap p-2 tabular-nums text-slate-700">{formatDate(row.last_date)}</td>
    </tr>
  )
}

function ariaSort(sort: SortKey | null, dir: SortDir, keys: SortKey[]): 'ascending' | 'descending' | 'none' {
  if (!sort || !keys.includes(sort)) return 'none'
  return dir === 'asc' ? 'ascending' : 'descending'
}

/** Przycisk sortowania w stylu listy Produkty (◇ = kolumna nieaktywna). */
function SortButton({
  label,
  k,
  sort,
  dir,
  onSort,
}: {
  label: string
  k: SortKey
  sort: SortKey | null
  dir: SortDir
  onSort: (k: SortKey) => void
}) {
  const active = sort === k
  return (
    <button
      type="button"
      onClick={() => onSort(k)}
      className={`inline-flex items-center gap-1 font-semibold hover:text-blue-700 ${
        active ? 'text-blue-700' : 'text-slate-700'
      }`}
      title="Sortuj"
    >
      {label}
      <span className="text-[10px] text-slate-400" aria-hidden>
        {active ? (dir === 'asc' ? '▲' : '▼') : '◇'}
      </span>
    </button>
  )
}

function SortTh(props: {
  label: string
  k: SortKey
  sort: SortKey | null
  dir: SortDir
  onSort: (k: SortKey) => void
  align?: 'right'
}) {
  const { align, ...button } = props
  return (
    <th
      className={`whitespace-nowrap p-2 ${align === 'right' ? 'text-right' : ''}`}
      aria-sort={ariaSort(props.sort, props.dir, [props.k])}
    >
      <SortButton {...button} />
    </th>
  )
}

/** Strona X z Y, wybór na stronę i numery stron — jak na stronie Zalegające. */
function ListControls({
  currentPage,
  lastPage,
  pages,
  perPage,
  disabled,
  onPage,
  onPerPage,
}: {
  currentPage: number
  lastPage: number
  pages: Array<number | '…'>
  perPage: string
  disabled: boolean
  onPage: (n: number) => void
  onPerPage: (v: string) => void
}) {
  return (
    <div className="flex flex-wrap items-center justify-between gap-3 py-2">
      <p className="flex flex-wrap items-center gap-1.5 text-xs text-slate-500">
        <span>
          Strona {currentPage} z {lastPage}
        </span>
        <span>·</span>
        <label className="inline-flex items-center gap-1">
          <select
            className="rounded border border-slate-300 bg-white px-1.5 py-0.5 text-xs"
            value={perPage}
            onChange={(e) => onPerPage(e.target.value)}
            title="Ile wierszy na stronie"
          >
            {PER_PAGE_OPTIONS.map((n) => (
              <option key={n} value={n}>
                {n}
              </option>
            ))}
          </select>
          /stronę
        </label>
      </p>
      {lastPage > 1 && (
        <nav className="flex flex-wrap items-center gap-1" aria-label="Paginacja">
          <button
            type="button"
            disabled={disabled || currentPage <= 1}
            onClick={() => onPage(Math.max(1, currentPage - 1))}
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
                disabled={disabled && n !== currentPage}
                onClick={() => onPage(n)}
                aria-current={n === currentPage ? 'page' : undefined}
                className={`min-w-8 rounded px-2.5 py-1.5 text-xs ${
                  n === currentPage ? 'bg-blue-600 text-white' : 'border border-slate-300 hover:bg-slate-50'
                }`}
              >
                {n}
              </button>
            ),
          )}
          <button
            type="button"
            disabled={disabled || currentPage >= lastPage}
            onClick={() => onPage(Math.min(lastPage, currentPage + 1))}
            className="rounded border border-slate-300 px-2.5 py-1.5 text-xs disabled:opacity-40"
          >
            Następna →
          </button>
        </nav>
      )}
    </div>
  )
}
