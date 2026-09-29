import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { ProductVerifyModal } from '../components/ProductVerifyModal'
import { api, type InventoryResponse, type InventoryRow, type InventoryWarehouse } from '../lib/api'
import { erpForeignPrice, erpQty, erpUnitLabel, erpUnitPrice, isTradeWarehouse } from '../lib/erpStock'
import { formatDate, formatDateTime, formatPrice } from '../lib/priceChange'

/**
 * Zapasy: towary z ERP XL, które mają stan (wszystkie magazyny) i nie sprzedały się od N miesięcy —
 * ile ich jest i ile pieniędzy w nich leży. Dane tylko z XL (GET /api/inventory); strona nic nie liczy
 * poza wiekiem dat („X mies. temu”). Stan filtrów, sortowania i strony w adresie — link odtwarza widok.
 */

type SortKey = 'value' | 'stock' | 'last_sale' | 'oldest_lot' | 'code' | 'name'
type SortDir = 'asc' | 'desc'
type CardFilter = '' | 'with' | 'without'

const MONTHS = ['1', '2', '3', '6', '9', '12', '18', '24'] as const
const DEFAULT_MONTHS = '6'
const SORT_KEYS: readonly SortKey[] = ['value', 'stock', 'last_sale', 'oldest_lot', 'code', 'name']
/** Kierunek po kliknięciu nowej kolumny: kwoty i stany od największych, daty od najstarszych, teksty alfabetycznie. */
const DEFAULT_DIR: Record<SortKey, SortDir> = {
  value: 'desc',
  stock: 'desc',
  last_sale: 'asc',
  oldest_lot: 'asc',
  code: 'asc',
  name: 'asc',
}
const PER_PAGE_OPTIONS = ['20', '50', '100', '200'] as const
const DEFAULT_PER_PAGE = '50'
const SEARCH_DEBOUNCE_MS = 300
/** Ile magazynów widać pod stanem; reszta jako „+n” (jak ErpStockInline). */
const INLINE_WAREHOUSES = 3

const CARD_OPTIONS: { value: CardFilter; label: string }[] = [
  { value: '', label: 'Wszystkie' },
  { value: 'with', label: 'Z kartą' },
  { value: 'without', label: 'Bez karty' },
]

/** Grupy asortymentu XL po pierwszej literze kodu — etykiety jak na ekranie Powiązania z ERP XL. */
const GROUP_LABEL: Record<string, string> = {
  A: 'odzież',
  B: 'obuwie',
  S: 'sprzęt ochronny',
  T: 'technika',
  H: 'higiena',
  other: 'inne',
}
const GROUPS = ['A', 'B', 'S', 'T', 'H', 'other'] as const

function groupLabel(g: string): string {
  return g === 'other' ? 'inne' : `${g} ${GROUP_LABEL[g] ?? ''}`.trim()
}

function pick<T extends string>(value: string | null, allowed: readonly T[], fallback: T): T {
  return value !== null && (allowed as readonly string[]).includes(value) ? (value as T) : fallback
}

/** Polska liczba mnoga: 1 → one, 2–4 (bez 12–14) → few, reszta → many. */
function plural(n: number, one: string, few: string, many: string): string {
  const mod10 = n % 10
  const mod100 = n % 100
  if (n === 1) return one
  return mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14) ? few : many
}

function fmtInt(n: number): string {
  return n.toLocaleString('pl-PL')
}

/** Kwota w zł, 2 miejsca (formatPrice: pl-PL, separator tysięcy od 5 cyfr — jak w całej aplikacji). */
function fmtMoney(n: number): string {
  return `${formatPrice(n)} zł`
}

/**
 * Wiek daty 'YYYY-MM-DD' względem dziś: pełne miesiące kalendarzowe („14 mies. temu”);
 * poniżej miesiąca dni („dzisiaj”, „wczoraj”, „12 dni temu”).
 */
function ageLabel(iso: string, today: Date = new Date()): string {
  const [y, m, d] = iso.slice(0, 10).split('-').map(Number)
  if (!y || !m || !d) return ''
  let months = (today.getFullYear() - y) * 12 + (today.getMonth() + 1 - m)
  if (today.getDate() < d) months -= 1
  if (months >= 1) return `${months} mies. temu`
  const days = Math.round(
    (Date.UTC(today.getFullYear(), today.getMonth(), today.getDate()) - Date.UTC(y, m - 1, d)) / 86_400_000,
  )
  if (days <= 0) return 'dzisiaj'
  if (days === 1) return 'wczoraj'
  return `${days} dni temu`
}

/** Numery stron z wielokropkiem — jak na liście Produkty. */
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

export function Inventory() {
  const [params, setParams] = useSearchParams()

  const months = pick(params.get('months'), MONTHS, DEFAULT_MONTHS)
  const neverSold = params.get('never_sold') !== '0'
  const card = pick<CardFilter>(params.get('card'), ['', 'with', 'without'], '')
  const group = pick<string>(params.get('group'), GROUPS, '')
  const supplier = params.get('supplier') ?? ''
  const search = params.get('search') ?? ''
  const sort = pick<SortKey>(params.get('sort'), SORT_KEYS, 'value')
  const dir = pick<SortDir>(params.get('dir'), ['asc', 'desc'], DEFAULT_DIR[sort])
  const page = Math.max(1, Math.floor(Number(params.get('page'))) || 1)
  const perPage = pick(params.get('per_page'), PER_PAGE_OPTIONS, DEFAULT_PER_PAGE)

  const apiQuery = useMemo(() => {
    const qs = new URLSearchParams()
    qs.set('months', months)
    qs.set('never_sold', neverSold ? '1' : '0')
    if (card) qs.set('card', card)
    if (group) qs.set('group', group)
    if (supplier.trim()) qs.set('supplier', supplier.trim())
    if (search.trim()) qs.set('search', search.trim())
    qs.set('sort', sort)
    qs.set('dir', dir)
    qs.set('page', String(page))
    qs.set('per_page', perPage)
    return qs.toString()
  }, [months, neverSold, card, group, supplier, search, sort, dir, page, perPage])

  const [result, setResult] = useState<InventoryResponse | null>(null)
  const [loading, setLoading] = useState(false)
  const [err, setErr] = useState('')
  const [previewId, setPreviewId] = useState<number | null>(null)
  const seq = useRef(0)

  // Pola tekstowe: wpis od razu w polu, do adresu (i zapytania) po 300 ms bez pisania — jak Powiązania z ERP XL.
  const [searchInput, setSearchInput] = useState(search)
  const [supplierInput, setSupplierInput] = useState(supplier)
  const pushedSearch = useRef(search)
  const pushedSupplier = useRef(supplier)

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

  // Adres zmieniony z zewnątrz (wstecz w przeglądarce, „Wyczyść filtry”) — pola idą za nim.
  useEffect(() => {
    if (search !== pushedSearch.current) {
      pushedSearch.current = search
      setSearchInput(search)
    }
  }, [search])
  useEffect(() => {
    if (supplier !== pushedSupplier.current) {
      pushedSupplier.current = supplier
      setSupplierInput(supplier)
    }
  }, [supplier])

  useEffect(() => {
    if (searchInput === pushedSearch.current && supplierInput === pushedSupplier.current) return
    const t = window.setTimeout(() => {
      pushedSearch.current = searchInput
      pushedSupplier.current = supplierInput
      setFilters({ search: searchInput, supplier: supplierInput }, { replace: true })
    }, SEARCH_DEBOUNCE_MS)
    return () => window.clearTimeout(t)
  }, [searchInput, supplierInput, setFilters])

  const load = useCallback(async () => {
    const my = ++seq.current
    setLoading(true)
    setErr('')
    try {
      const res = await api<InventoryResponse>(`/inventory?${apiQuery}`)
      // Szybkie klikanie filtrów — spóźniona odpowiedź nie nadpisuje nowszej.
      if (my !== seq.current) return
      setResult(res)
    } catch (ex) {
      if (my === seq.current) setErr(ex instanceof Error ? ex.message : 'Błąd wczytywania zapasów')
    } finally {
      if (my === seq.current) setLoading(false)
    }
  }, [apiQuery])

  useEffect(() => {
    void load()
  }, [load])

  // Strona za końcem listy (np. link sprzed odczytu z XL) — wróć na ostatnią istniejącą.
  useEffect(() => {
    const m = result?.meta
    if (result && result.data.length === 0 && m && m.current_page > 1 && m.current_page > m.last_page) {
      setFilters({ page: m.last_page > 1 ? String(m.last_page) : null }, { keepPage: true, replace: true })
    }
  }, [result, setFilters])

  function clickSort(key: SortKey) {
    const nextDir: SortDir = key === sort ? (dir === 'asc' ? 'desc' : 'asc') : DEFAULT_DIR[key]
    setFilters({ sort: key === 'value' ? null : key, dir: nextDir === DEFAULT_DIR[key] ? null : nextDir })
  }

  function goToPage(n: number) {
    setFilters({ page: n > 1 ? String(n) : null }, { keepPage: true })
  }

  function clearFilters() {
    setParams((prev) => {
      const next = new URLSearchParams()
      const pp = prev.get('per_page')
      if (pp) next.set('per_page', pp)
      return next
    })
  }

  const hasFilters = Boolean(
    months !== DEFAULT_MONTHS || !neverSold || card || group || supplier || search,
  )
  const rows = result?.data ?? []
  const meta = result?.meta
  const summary = result?.summary
  const from = meta && rows.length > 0 ? (meta.current_page - 1) * meta.per_page + 1 : null
  const to = from != null ? from + rows.length - 1 : null
  const pages = meta ? pageNumbers(meta.current_page, Math.max(1, meta.last_page)) : []
  const colCount = 9

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

  return (
    <div>
      <div className="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold">Zapasy</h1>
          <p className="mt-1 text-xs text-slate-500">
            {meta ? (
              <>
                Łącznie <span className="font-medium text-slate-700">{fmtInt(meta.total)}</span>
                {from != null && to != null ? ` · wyświetlono ${from}–${to}` : ''}
                {` · ${perPage}/stronę`}
                {result?.cutoff ? ` · bez sprzedaży od ${formatDate(result.cutoff)}` : ''}
                {loading ? ' · ładowanie…' : ''}
              </>
            ) : loading ? (
              'Ładowanie…'
            ) : (
              ''
            )}
          </p>
          <p className="mt-0.5 max-w-3xl text-xs text-slate-600">
            Towary z Comarch ERP XL, które mają stan (wszystkie magazyny) i nie sprzedały się od wybranej liczby
            miesięcy. Wartość = ilość × cena zakupu partii leżących na magazynie (z XL); dopóki XL nie poda partii —
            stan × cena z ostatniej PZ.
          </p>
        </div>
        <p className="text-[11px] text-slate-500">
          {result
            ? result.synced_at
              ? `Odczyt z XL: ${formatDateTime(result.synced_at)} (raz na dobę o 2:00)`
              : 'Brak odczytu z XL (odczyt raz na dobę o 2:00)'
            : ''}
        </p>
      </div>

      <div className="mb-4 grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
        <SummaryTile
          number={summary ? fmtInt(summary.items) : '…'}
          label="Pozycje"
          hint="towary ze stanem bez sprzedaży od progu"
        />
        <SummaryTile
          number={summary ? fmtMoney(summary.value) : '…'}
          numberClass="text-amber-800"
          label="Wartość zapasu"
          hint={
            summary && summary.value_unknown > 0
              ? `netto, ilość × cena zakupu · ${fmtInt(summary.value_unknown)} bez ceny zakupu`
              : 'netto, ilość × cena zakupu partii na stanie'
          }
          hintTitle={
            summary && summary.value_unknown > 0
              ? 'Tych towarów nie ma w wartości — XL nie podał wartości partii, a towar nie ma PZ z ceną. Suma jest przez to zaniżona.'
              : undefined
          }
        />
        <SummaryTile
          number={summary ? fmtInt(summary.without_card) : '…'}
          label="W tym bez karty"
          hint={card === 'without' ? 'filtr włączony — kliknij, żeby zdjąć' : 'towar XL bez karty w katalogu'}
          active={card === 'without'}
          onClick={() => setFilters({ card: card === 'without' ? null : 'without' })}
        />
        <SummaryTile
          number={summary ? fmtInt(summary.never_sold) : '…'}
          label="Nigdy niesprzedane"
          hint={neverSold ? 'brak sprzedaży w całej historii XL' : 'wyłączone w filtrze „Także nigdy niesprzedane”'}
        />
      </div>

      <div className="mb-4 flex flex-wrap items-end gap-x-3 gap-y-2 rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs shadow-sm">
        <div className="flex flex-col gap-0.5 text-[11px] text-slate-500">
          <span id="inventory-months-label">Bez sprzedaży od</span>
          <div className="inline-flex overflow-hidden rounded border border-slate-300" role="group" aria-labelledby="inventory-months-label">
            {MONTHS.map((m, i) => {
              const active = m === months
              return (
                <button
                  key={m}
                  type="button"
                  aria-pressed={active}
                  onClick={() => setFilters({ months: m === DEFAULT_MONTHS ? null : m })}
                  className={`px-2 py-1 text-xs tabular-nums ${i > 0 ? 'border-l border-slate-300' : ''} ${
                    active ? 'bg-blue-600 font-semibold text-white' : 'bg-white text-slate-700 hover:bg-slate-50'
                  }`}
                >
                  {m}
                </button>
              )
            })}
          </div>
        </div>
        <span className="pb-1 text-xs text-slate-500">mies.</span>
        <label
          className="flex items-center gap-1.5 pb-1 text-xs text-slate-700"
          title="Towary bez żadnej sprzedaży w historii XL — tylko gdy najstarsza partia na stanie jest starsza niż próg albo jej data jest nieznana (świeżo przyjęty towar nie trafi na listę)."
        >
          <input
            type="checkbox"
            checked={neverSold}
            onChange={(e) => setFilters({ never_sold: e.target.checked ? null : '0' })}
          />
          Także nigdy niesprzedane
        </label>
        <label className="flex flex-col gap-0.5 text-[11px] text-slate-500">
          Karta
          <select
            className="rounded border border-slate-300 bg-white px-1.5 py-1 text-xs text-slate-800"
            value={card}
            onChange={(e) => setFilters({ card: e.target.value })}
          >
            {CARD_OPTIONS.map((o) => (
              <option key={o.value || 'all'} value={o.value}>
                {o.label}
              </option>
            ))}
          </select>
        </label>
        <label className="flex flex-col gap-0.5 text-[11px] text-slate-500">
          Grupa
          <select
            className="rounded border border-slate-300 bg-white px-1.5 py-1 text-xs text-slate-800"
            value={group}
            onChange={(e) => setFilters({ group: e.target.value })}
            title="Grupa asortymentu XL po pierwszej literze kodu"
          >
            <option value="">wszystkie</option>
            {GROUPS.map((g) => (
              <option key={g} value={g}>
                {groupLabel(g)}
              </option>
            ))}
          </select>
        </label>
        <label className="flex flex-col gap-0.5 text-[11px] text-slate-500">
          Ostatni dostawca
          <input
            type="text"
            className="w-40 rounded border border-slate-300 bg-white px-1.5 py-1 text-xs text-slate-800"
            placeholder="np. UVEX"
            value={supplierInput}
            onChange={(e) => setSupplierInput(e.target.value)}
          />
        </label>
        <label className="flex min-w-[14rem] flex-1 flex-col gap-0.5 text-[11px] text-slate-500">
          Szukaj
          <input
            type="search"
            className="rounded border border-slate-300 bg-white px-1.5 py-1 text-xs text-slate-800"
            placeholder="kod XL, nazwa, Nazwa1 albo SKU karty"
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
          Nie udało się wczytać zapasów: {err}{' '}
          <button type="button" className="font-medium underline" onClick={() => void load()}>
            Spróbuj ponownie
          </button>
        </p>
      )}

      <div className="overflow-x-auto rounded-xl bg-white p-4 shadow-sm">
        {controls}
        <table className="w-full text-left text-xs">
          <thead>
            <tr className="border-b bg-slate-50">
              <th className="w-8 px-1 py-2 text-right font-normal text-slate-500">Lp.</th>
              <th className="whitespace-nowrap p-2 font-semibold text-slate-700">Zdjęcie</th>
              <th className="whitespace-nowrap p-2" aria-sort={ariaSort(sort, dir, ['code', 'name'])}>
                <span className="inline-flex items-center gap-2">
                  <SortButton label="Kod XL" k="code" sort={sort} dir={dir} onSort={clickSort} />
                  <span className="text-slate-300">/</span>
                  <SortButton label="Nazwa" k="name" sort={sort} dir={dir} onSort={clickSort} />
                </span>
              </th>
              <th className="whitespace-nowrap p-2 font-semibold text-slate-700">Karta</th>
              <SortTh label="Stan" k="stock" sort={sort} dir={dir} onSort={clickSort} align="right" />
              <SortTh label="Wartość" k="value" sort={sort} dir={dir} onSort={clickSort} align="right" />
              <SortTh label="Ostatnia sprzedaż" k="last_sale" sort={sort} dir={dir} onSort={clickSort} />
              <SortTh label="Najstarsza partia" k="oldest_lot" sort={sort} dir={dir} onSort={clickSort} />
              <th className="whitespace-nowrap p-2 font-semibold text-slate-700">Ostatni zakup</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((row, i) => (
              <InventoryTableRow
                key={row.id}
                row={row}
                index={(from ?? 1) + i}
                striped={i % 2 === 1}
                onOpenCard={setPreviewId}
              />
            ))}
            {rows.length === 0 && (
              <tr>
                <td colSpan={colCount} className="p-8 text-center text-slate-500">
                  {loading || (!result && !err) ? (
                    'Ładowanie…'
                  ) : err && !result ? (
                    'Nie udało się wczytać zapasów.'
                  ) : (
                    <>
                      Brak towarów bez sprzedaży od {months} mies.
                      {hasFilters && (
                        <button type="button" className="ml-1 text-blue-600 hover:underline" onClick={clearFilters}>
                          Wyczyść filtry
                        </button>
                      )}
                    </>
                  )}
                </td>
              </tr>
            )}
          </tbody>
        </table>
        {controls && rows.length > 0 && <div className="mt-2 border-t pt-1">{controls}</div>}

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
  hintTitle,
  active,
  onClick,
}: {
  number: string
  numberClass?: string
  label: string
  hint: string
  hintTitle?: string
  active?: boolean
  onClick?: () => void
}) {
  const body = (
    <>
      <div className={`text-xl font-semibold tabular-nums ${numberClass}`}>{number}</div>
      <div className="text-xs font-medium text-slate-800">{label}</div>
      <div className="text-[11px] text-slate-500" title={hintTitle}>
        {hint}
      </div>
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
      title={active ? 'Kliknij, żeby zdjąć ten filtr' : 'Pokaż tylko te towary'}
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

function InventoryTableRow({
  row,
  index,
  striped,
  onOpenCard,
}: {
  row: InventoryRow
  index: number
  striped: boolean
  onOpenCard: (productId: number) => void
}) {
  const unit = erpUnitLabel(row.unit)
  const card = row.card
  const lp = row.last_purchase
  const foreign = lp ? erpForeignPrice(lp) : null
  return (
    <tr className={`border-b align-top hover:bg-sky-50 ${striped ? 'bg-slate-100/60' : ''}`}>
      <td className="w-8 px-1 py-2 text-right tabular-nums text-slate-400" title={`Towar XL ${row.xl_gid}`}>
        {index}
      </td>
      <td className="p-2">
        {card ? (
          card.thumb_url ? (
            <button
              type="button"
              onClick={() => onOpenCard(card.id)}
              className="block overflow-hidden rounded border border-slate-200 bg-slate-50"
              title="Otwórz okno weryfikacji karty"
            >
              <img src={card.thumb_url} alt="" className="h-12 w-12 bg-white object-contain" />
            </button>
          ) : (
            <span className="text-slate-400">—</span>
          )
        ) : (
          <span
            className="inline-block whitespace-nowrap rounded bg-slate-100 px-1.5 py-0.5 text-[11px] text-slate-500"
            title="Towar XL nie jest połączony z kartą w katalogu"
          >
            bez karty
          </span>
        )}
      </td>
      <td className="min-w-[12rem] max-w-[22rem] p-2">
        <span className="font-mono text-slate-900">{row.code}</span>
        {row.archived && (
          <span
            className="ml-1.5 inline-block rounded bg-amber-100 px-1 text-[10px] font-medium text-amber-800"
            title="Towar jest archiwalny w XL, a nadal ma stan"
          >
            archiwalny w XL
          </span>
        )}
        <div className="line-clamp-2 break-words text-slate-800" title={row.name}>
          {row.name}
        </div>
        {row.name1 && <div className="break-words font-mono text-[11px] text-slate-400">{row.name1}</div>}
      </td>
      <td className="min-w-[12rem] max-w-[20rem] p-2">
        {card ? (
          <button
            type="button"
            onClick={() => onOpenCard(card.id)}
            className="block w-full text-left"
            title={`${card.name} — kliknij, aby otworzyć okno weryfikacji karty${
              card.link_status === 'confirmed' ? ' (powiązanie potwierdzone ręcznie)' : ' (powiązanie automatyczne)'
            }`}
          >
            <span className="font-mono text-[11px] text-blue-600 hover:underline">{card.sku}</span>
            {row.cards_count > 1 && (
              <span
                className="ml-1 text-[11px] text-slate-500"
                title={`Towar ma ${row.cards_count} ${plural(row.cards_count, 'powiązaną kartę', 'powiązane karty', 'powiązanych kart')} — pokazana jedna`}
              >
                +{row.cards_count - 1}
              </span>
            )}
            <span className="line-clamp-2 break-words text-slate-800 hover:underline">{card.name}</span>
            <span className="block text-[11px] text-slate-500">{card.manufacturer ?? 'producent nieznany'}</span>
          </button>
        ) : (
          <span className="text-slate-400">—</span>
        )}
      </td>
      <td className="whitespace-nowrap p-2 text-right">
        <span
          className="font-semibold tabular-nums text-slate-800"
          title={`Wszystkie magazyny: ${erpQty(row.stock_total)}${unit} · HANDEL: ${erpQty(row.stock_trade)}${unit}`}
        >
          {erpQty(row.stock_total)}
        </span>
        <span className="text-slate-500">{unit}</span>
        <WarehouseChips warehouses={row.warehouses} unit={unit} />
      </td>
      <td className="whitespace-nowrap p-2 text-right tabular-nums">
        {row.stock_value != null ? (
          <span
            className="block"
            title={
              row.value_source === 'lots'
                ? 'Ilość × cena zakupu każdej partii leżącej na magazynie (wartość partii z XL)'
                : `${erpQty(row.stock_total)}${unit} × ${erpUnitPrice(row.last_purchase?.unit_price_pln ?? null)} zł z ostatniej PZ — XL nie podał jeszcze wartości partii (odczyt nocny o 2:00). PZ poprawiona później przez RW/PW daje tu złą kwotę.`
            }
          >
            <span className="font-semibold text-slate-900">{fmtMoney(row.stock_value)}</span>
            {row.value_source === 'last_purchase' && (
              <span className="block text-[10px] font-normal text-slate-500">wg ostatniej PZ</span>
            )}
          </span>
        ) : (
          <span className="text-slate-400" title="XL nie podał wartości partii, a towar nie ma PZ z ceną">
            —
          </span>
        )}
      </td>
      <td className="whitespace-nowrap p-2">
        {row.last_sale_at ? (
          <DateWithAge iso={row.last_sale_at} />
        ) : (
          <span
            className="rounded bg-amber-100 px-1.5 py-0.5 font-medium text-amber-800"
            title="Brak sprzedaży (faktura, paragon, WZ) w całej historii XL"
          >
            nigdy
          </span>
        )}
      </td>
      <td className="whitespace-nowrap p-2">
        {row.oldest_lot_at ? (
          <DateWithAge iso={row.oldest_lot_at} />
        ) : (
          <span className="text-slate-400" title="Data przyjęcia partii nieznana">
            —
          </span>
        )}
      </td>
      <td className="p-2">
        {lp && (lp.unit_price_pln != null || lp.date || lp.supplier) ? (
          <>
            <div className="whitespace-nowrap tabular-nums text-slate-800">
              {lp.unit_price_pln != null ? (
                <>
                  {erpUnitPrice(lp.unit_price_pln)} zł
                  {row.unit && <span className="text-slate-500">/{row.unit}</span>}
                </>
              ) : (
                <span className="text-slate-400">cena nieznana</span>
              )}
              {foreign && <span className="ml-1 text-[11px] text-slate-500">({foreign})</span>}
            </div>
            <div className="text-[11px] text-slate-500">{lp.date ? formatDate(lp.date) : 'data nieznana'}</div>
            {lp.supplier && (
              <div className="max-w-[10rem] truncate text-[11px] text-slate-500" title={lp.supplier}>
                {lp.supplier}
              </div>
            )}
          </>
        ) : (
          <span className="text-slate-400">—</span>
        )}
      </td>
    </tr>
  )
}

function DateWithAge({ iso }: { iso: string }) {
  return (
    <>
      <div className="tabular-nums text-slate-700">{formatDate(iso)}</div>
      <div className="text-[11px] text-slate-500">{ageLabel(iso)}</div>
    </>
  )
}

/** Do 3 magazynów pod stanem (magazyny HANDEL na zielono, jak ErpStockInline), reszta jako „+n”. */
function WarehouseChips({ warehouses, unit }: { warehouses: InventoryWarehouse[]; unit: string }) {
  const nonZero = warehouses.filter((w) => w.quantity !== 0)
  if (nonZero.length === 0) return null
  const shown = nonZero.slice(0, INLINE_WAREHOUSES)
  const hidden = nonZero.slice(INLINE_WAREHOUSES)
  const describe = (w: InventoryWarehouse) =>
    `${w.code} — ${w.name}: ${erpQty(w.quantity)}${unit}${w.value != null ? ` · ${fmtMoney(w.value)}` : ' · brak wartości z XL'}`
  return (
    <div className="mt-1 flex flex-wrap justify-end gap-1 text-[11px]">
      {shown.map((w) => (
        <span
          key={w.code}
          title={describe(w)}
          className={`rounded border px-1.5 py-0.5 tabular-nums ${
            isTradeWarehouse(w.name)
              ? 'border-emerald-200 bg-emerald-50 text-emerald-900'
              : 'border-slate-200 bg-slate-50 text-slate-600'
          }`}
        >
          {w.code} {erpQty(w.quantity)}
        </span>
      ))}
      {hidden.length > 0 && (
        <span className="py-0.5 text-slate-400" title={hidden.map(describe).join('\n')}>
          +{hidden.length}
        </span>
      )}
    </div>
  )
}

function ariaSort(sort: SortKey, dir: SortDir, keys: SortKey[]): 'ascending' | 'descending' | 'none' {
  if (!keys.includes(sort)) return 'none'
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
  sort: SortKey
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
  sort: SortKey
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

/** Strona X z Y, wybór na stronę i numery stron — jak ProductListControls na liście Produkty. */
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
