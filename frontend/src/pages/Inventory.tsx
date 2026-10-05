import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useAuth } from '../auth'
import { AddToCampaignMenu, CAMPAIGN_MAX_ITEMS } from '../components/AddToCampaignMenu'
import { AddToOfferMenu } from '../components/AddToOfferMenu'
import { CampaignPickBanner } from '../components/CampaignPickBanner'
import { OfferPickBanner } from '../components/OfferPickBanner'
import { useCampaignTarget } from '../lib/campaignTarget'
import { useOfferTarget } from '../lib/offerTarget'
import { InventoryTabs } from '../components/InventoryTabs'
import { ProductVerifyModal } from '../components/ProductVerifyModal'
import {
  api,
  can,
  type InventoryBoardWarehouses,
  type InventoryResponse,
  type InventoryRow,
  type InventoryWarehouse,
} from '../lib/api'
import { applyCheckboxRange } from '../lib/checkboxRange'
import { erpForeignPrice, erpQty, erpUnitLabel, erpUnitPrice, isTradeWarehouse } from '../lib/erpStock'
import { formatDate, formatDateTime, formatPrice } from '../lib/priceChange'

/**
 * Zapasy: towary z ERP XL, które mają stan (wszystkie magazyny albo handlowe / usługowe) i nie sprzedały się od N miesięcy —
 * ile ich jest i ile pieniędzy w nich leży. Dane tylko z XL (GET /api/inventory); strona nic nie liczy
 * poza wiekiem dat („X mies. temu”). Stan filtrów, sortowania i strony w adresie — link odtwarza widok.
 * Z uprawnieniem campaigns.use wiersze można zaznaczać (także na kilku stronach) i dodać do kampanii.
 */

type SortKey = 'value' | 'stock' | 'last_sale' | 'oldest_lot' | 'last_purchase' | 'code' | 'name'
type SortDir = 'asc' | 'desc'
type CardFilter = '' | 'with' | 'without'

/** '0' = bez warunku (wszystkie). */
const MONTHS = ['0', '1', '2', '3', '6', '9', '12', '18', '24'] as const
const DEFAULT_MONTHS = '6'
/** Wiek partii sięga dalej niż brak sprzedaży: także 3, 4 i 5 lat. */
const LOT_MONTHS = [...MONTHS, '36', '48', '60'] as const
const DEFAULT_LOT_MONTHS = '0'
const YEAR_LABELS: Record<string, string> = { '36': '3 lata', '48': '4 lata', '60': '5 lat' }
const SORT_KEYS: readonly SortKey[] = ['value', 'stock', 'last_sale', 'oldest_lot', 'last_purchase', 'code', 'name']
/** Kierunek po kliknięciu nowej kolumny: kwoty i stany od największych, daty od najstarszych, teksty alfabetycznie. */
const DEFAULT_DIR: Record<SortKey, SortDir> = {
  value: 'desc',
  stock: 'desc',
  last_sale: 'asc',
  oldest_lot: 'asc',
  last_purchase: 'asc',
  code: 'asc',
  name: 'asc',
}
const PER_PAGE_OPTIONS = ['20', '50', '100', '200'] as const
const DEFAULT_PER_PAGE = '50'
const SEARCH_DEBOUNCE_MS = 300
/** Ile magazynów widać pod stanem; reszta jako „+n” (jak ErpStockInline). */
const INLINE_WAREHOUSES = 3

/**
 * Magazyny jak w raporcie dla zarządu, domyślnie handlowe (decyzja właściciela 02.10.2026) — w adresie bez parametru;
 * API bez parametru liczy wszystkie, więc strona wysyła wybór zawsze.
 */
const DEFAULT_WAREHOUSES: InventoryBoardWarehouses = 'trade'
const WAREHOUSE_OPTIONS: { value: InventoryBoardWarehouses; label: string }[] = [
  { value: 'trade', label: 'handlowe' },
  { value: 'service', label: 'usługowe' },
  { value: 'all', label: 'wszystkie' },
]
const WAREHOUSE_LABEL: Record<InventoryBoardWarehouses, string> = {
  all: 'wszystkie magazyny',
  trade: 'magazyny handlowe',
  service: 'magazyny usługowe',
}

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
  const { user } = useAuth()
  const canCampaign = can(user, 'campaigns.use')
  const canOffer = can(user, 'offers.use')
  // zaznaczanie wierszy służy kampanii i ofercie — wystarczy jedno z uprawnień
  const canSelect = canCampaign || canOffer
  const canRwPw = can(user, 'inventory.view')
  const [params, setParams] = useSearchParams()
  // lista otwarta z kreatora kampanii (?kampania=ID) — „Dodaj do K-…” i powrót do kampanii
  const campaignPick = useCampaignTarget()
  // lista otwarta z oferty (?oferta=ID) — „Dodaj do OF-…” i powrót do oferty
  const offerPick = useOfferTarget()

  const months = pick(params.get('months'), MONTHS, DEFAULT_MONTHS)
  const neverSold = params.get('never_sold') !== '0'
  const lotMonths = pick(params.get('lot_months'), LOT_MONTHS, DEFAULT_LOT_MONTHS)
  const card = pick<CardFilter>(params.get('card'), ['', 'with', 'without'], '')
  const group = pick<string>(params.get('group'), GROUPS, '')
  const supplier = params.get('supplier') ?? ''
  const search = params.get('search') ?? ''
  // oddział: cyfry z początku kodu magazynu (01 = Rzeszów); '' = wszystkie
  const location = /^\d{1,10}$/.test(params.get('location') ?? '') ? (params.get('location') as string) : ''
  const warehouses = pick<InventoryBoardWarehouses>(params.get('warehouses'), ['all', 'trade', 'service'], DEFAULT_WAREHOUSES)
  const sort = pick<SortKey>(params.get('sort'), SORT_KEYS, 'value')
  const dir = pick<SortDir>(params.get('dir'), ['asc', 'desc'], DEFAULT_DIR[sort])
  const page = Math.max(1, Math.floor(Number(params.get('page'))) || 1)
  const perPage = pick(params.get('per_page'), PER_PAGE_OPTIONS, DEFAULT_PER_PAGE)

  const apiQuery = useMemo(() => {
    const qs = new URLSearchParams()
    qs.set('months', months)
    qs.set('never_sold', neverSold ? '1' : '0')
    if (lotMonths !== DEFAULT_LOT_MONTHS) qs.set('lot_months', lotMonths)
    if (card) qs.set('card', card)
    if (group) qs.set('group', group)
    if (location) qs.set('location', location)
    qs.set('warehouses', warehouses)
    if (supplier.trim()) qs.set('supplier', supplier.trim())
    if (search.trim()) qs.set('search', search.trim())
    qs.set('sort', sort)
    qs.set('dir', dir)
    qs.set('page', String(page))
    qs.set('per_page', perPage)
    return qs.toString()
  }, [months, neverSold, lotMonths, card, group, location, warehouses, supplier, search, sort, dir, page, perPage])

  const [result, setResult] = useState<InventoryResponse | null>(null)
  const [loading, setLoading] = useState(false)
  const [err, setErr] = useState('')
  const [previewId, setPreviewId] = useState<number | null>(null)
  const seq = useRef(0)
  /** Zaznaczone do kampanii: id → wiersz, żeby zaznaczenie i suma wartości przetrwały zmianę strony i filtrów. */
  const [selected, setSelected] = useState<Map<number, InventoryRow>>(() => new Map())
  /** Kotwica Shift+klik — indeks wiersza na bieżącej stronie; po wczytaniu innej strony kasowana. */
  const selectAnchor = useRef<number | null>(null)

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
      selectAnchor.current = null
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
      for (const keep of ['per_page', 'kampania']) {
        const v = prev.get(keep)
        if (v) next.set(keep, v)
      }
      return next
    })
  }

  const hasFilters = Boolean(
    months !== DEFAULT_MONTHS ||
      !neverSold ||
      lotMonths !== DEFAULT_LOT_MONTHS ||
      card ||
      group ||
      location ||
      warehouses !== DEFAULT_WAREHOUSES ||
      supplier ||
      search,
  )
  const rows = result?.data ?? []
  const meta = result?.meta
  const summary = result?.summary
  const from = meta && rows.length > 0 ? (meta.current_page - 1) * meta.per_page + 1 : null
  const to = from != null ? from + rows.length - 1 : null
  const pages = meta ? pageNumbers(meta.current_page, Math.max(1, meta.last_page)) : []
  const colCount = 10 + (canSelect ? 1 : 0)
  // Oddziały z odpowiedzi; wybrany z adresu zostaje na liście także przed pierwszą odpowiedzią.
  const locations = result?.locations ?? []
  const locationName = locations.find((l) => l.key === location)?.name ?? result?.location_name ?? (location ? `Magazyny ${location}` : '')
  const locationOptions = location && !locations.some((l) => l.key === location) ? [...locations, { key: location, name: locationName }] : locations
  const allVisibleSelected = rows.length > 0 && rows.every((r) => selected.has(r.id))
  const selectedRows = [...selected.values()]
  const selectedValue = selectedRows.reduce((sum, r) => sum + (r.stock_value ?? 0), 0)
  const selectedWithoutValue = selectedRows.filter((r) => r.stock_value == null).length

  /** Klik w wierszu (Shift = zakres od ostatnio klikniętego) — ta sama reguła co na liście Produkty. */
  function toggleRow(index: number, shiftKey: boolean) {
    const ids = rows.map((r) => r.id)
    const current: Record<number, boolean> = {}
    for (const id of selected.keys()) current[id] = true
    const applied = applyCheckboxRange(ids, current, selectAnchor.current, index, shiftKey)
    selectAnchor.current = applied.anchorIndex
    const next = new Map<number, InventoryRow>()
    for (const [id, row] of selected) if (applied.selected[id]) next.set(id, row)
    for (const row of rows) if (applied.selected[row.id]) next.set(row.id, row)
    setSelected(next)
  }

  function toggleAllVisible() {
    const next = new Map(selected)
    if (allVisibleSelected) {
      for (const r of rows) next.delete(r.id)
      selectAnchor.current = null
    } else {
      for (const r of rows) next.set(r.id, r)
      selectAnchor.current = rows.length - 1
    }
    setSelected(next)
  }

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
      <InventoryTabs />
      {/* przypięte u góry: do której kampanii dobierasz towar i co zaznaczono — widoczne przy przewijaniu */}
      <div className="app-sticky-bar sticky top-0 z-30 -mx-1 space-y-2 px-1 pb-2 empty:hidden">
        <CampaignPickBanner {...campaignPick} what="towar" />
        {canOffer && <OfferPickBanner {...offerPick} what="towar" />}
        {canSelect && selected.size > 0 && (
          <div className="app-bulk-bar flex flex-wrap items-center justify-between gap-x-4 gap-y-2 rounded-xl bg-slate-800 px-4 py-2.5 text-sm text-white shadow-xl">
            <span>
              <b className="tabular-nums">{fmtInt(selected.size)}</b>{' '}
              {plural(selected.size, 'zaznaczona', 'zaznaczone', 'zaznaczonych')} · razem ok.{' '}
              <b className="tabular-nums">{fmtInt(Math.round(selectedValue))} zł</b> zapasu
              {selectedWithoutValue > 0 && (
                <span className="text-slate-300"> ({fmtInt(selectedWithoutValue)} bez wartości z XL)</span>
              )}
              {canCampaign && selected.size > CAMPAIGN_MAX_ITEMS && (
                <span className="mt-0.5 block text-xs text-amber-300">
                  Kampania mieści najwyżej {CAMPAIGN_MAX_ITEMS} pozycji — {canOffer ? 'do kampanii odznacz' : 'odznacz'}{' '}
                  {fmtInt(selected.size - CAMPAIGN_MAX_ITEMS)}.
                </span>
              )}
            </span>
            <div className="flex items-center gap-2">
              <button
                type="button"
                onClick={() => {
                  setSelected(new Map())
                  selectAnchor.current = null
                }}
                className="rounded border border-slate-500 px-3 py-1.5 text-xs text-white hover:bg-slate-700"
              >
                Wyczyść
              </button>
              {/* lista otwarta z kampanii albo z oferty — tylko przycisk tego, z czego przyszedłeś */}
              {canOffer && campaignPick.campaignId === null && (
                <AddToOfferMenu
                  erpItemIds={[...selected.keys()]}
                  target={offerPick.target}
                  placement="down"
                  buttonClassName="rounded bg-emerald-500 px-3 py-1.5 text-xs font-semibold text-slate-900 hover:bg-emerald-400 disabled:opacity-50"
                />
              )}
              {canCampaign && offerPick.offerId === null && (
                <AddToCampaignMenu
                  erpItemIds={[...selected.keys()]}
                  target={campaignPick.target}
                  placement="down"
                  buttonClassName="rounded bg-sky-500 px-3 py-1.5 text-xs font-semibold text-slate-900 hover:bg-sky-400 disabled:opacity-50"
                />
              )}
            </div>
          </div>
        )}
      </div>
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
                {result?.lot_cutoff ? ` · partia leży od ${formatDate(result.lot_cutoff)} lub dłużej` : ''}
                {location ? ` · oddział ${locationName}` : ''}
                {` · ${WAREHOUSE_LABEL[warehouses]}`}
                {loading ? ' · ładowanie…' : ''}
              </>
            ) : loading ? (
              'Ładowanie…'
            ) : (
              ''
            )}
          </p>
          <p className="mt-0.5 max-w-3xl text-xs text-slate-600">
            Towary z Comarch ERP XL, które mają stan ({WAREHOUSE_LABEL[warehouses]}) i nie sprzedały się od wybranej
            liczby miesięcy. Wartość = ilość × cena zakupu partii leżących na magazynie (z XL); dopóki XL nie poda partii —
            stan × cena z ostatniej PZ.
            {location &&
              ` Oddział ${locationName}: stan, wartość, najstarsza partia i ostatnia sprzedaż tylko z magazynów oddziału (faktury, paragony i WZ wystawione z tych magazynów).`}
            {canCampaign && canOffer
              ? ` Zaznacz pozycje i dodaj je do kampanii (najwyżej ${CAMPAIGN_MAX_ITEMS}) albo do oferty dla klienta (Shift+klik: zakres).`
              : canCampaign
                ? ` Zaznacz pozycje i dodaj je do kampanii (najwyżej ${CAMPAIGN_MAX_ITEMS}, Shift+klik: zakres).`
                : canOffer
                  ? ' Zaznacz pozycje i dodaj je do oferty dla klienta (Shift+klik: zakres).'
                  : ''}
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
              : result?.lot_cutoff
                ? `tylko sztuki z dostaw przyjętych do ${formatDate(result.lot_cutoff)}`
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
        <MonthSegments
          id="inventory-months"
          label="Bez sprzedaży od"
          options={MONTHS}
          value={months}
          title="Ostatnia sprzedaż (faktura, paragon, WZ) starsza niż wybrany próg; „wszystkie” = bez warunku sprzedaży"
          onChange={(m) => setFilters({ months: m === DEFAULT_MONTHS ? null : m })}
        />
        <label
          className={`flex items-center gap-1.5 pb-1 text-xs ${months === '0' ? 'text-slate-400' : 'text-slate-700'}`}
          title="Towary bez żadnej sprzedaży w historii XL — tylko gdy najstarsza partia na stanie jest starsza niż próg albo jej data jest nieznana (świeżo przyjęty towar nie trafi na listę)."
        >
          <input
            type="checkbox"
            checked={neverSold}
            disabled={months === '0'}
            onChange={(e) => setFilters({ never_sold: e.target.checked ? null : '0' })}
          />
          Także nigdy niesprzedane
        </label>
        <MonthSegments
          id="inventory-lot-months"
          label="Partia leży od (mies. / lat)"
          options={LOT_MONTHS}
          value={lotMonths}
          title="Najstarsza partia na stanie przyjęta co najmniej tyle miesięcy temu. Uwaga: RW + PW zakłada nową partię i „odmładza” tę datę (znacznik RW/PW)."
          onChange={(m) => setFilters({ lot_months: m === DEFAULT_LOT_MONTHS ? null : m })}
        />
        <label className="flex flex-col gap-0.5 text-[11px] text-slate-500">
          Oddział
          <select
            className="rounded border border-slate-300 bg-white px-1.5 py-1 text-xs text-slate-800"
            value={location}
            onChange={(e) => setFilters({ location: e.target.value || null })}
            title="Magazyny oddziału — łączone po cyfrach na początku kodu (01H, 01MTU… = Rzeszów). Stan, wartość i wiek partii tylko z tych magazynów."
          >
            <option value="">wszystkie</option>
            {locationOptions.map((l) => (
              <option key={l.key} value={l.key}>
                {l.name} ({l.key})
              </option>
            ))}
          </select>
        </label>
        <label className="flex flex-col gap-0.5 text-[11px] text-slate-500">
          Magazyny
          <select
            className="rounded border border-slate-300 bg-white px-1.5 py-1 text-xs text-slate-800"
            value={warehouses}
            onChange={(e) => setFilters({ warehouses: e.target.value === DEFAULT_WAREHOUSES ? null : e.target.value })}
            title="Jak w raporcie zapasów: handlowe — towar na sprzedaż, usługowe — towar trzymany dla klientów (słownik magazynów). Stan, wartość i wiek partii tylko z tych magazynów; ostatnia sprzedaż bez podziału na handlowe i usługowe."
          >
            {WAREHOUSE_OPTIONS.map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </select>
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
              {canSelect && (
                <th className="w-8 p-2">
                  <input
                    type="checkbox"
                    checked={allVisibleSelected}
                    disabled={rows.length === 0}
                    onChange={toggleAllVisible}
                    title="Zaznacz / odznacz widoczne. Na wierszu: Shift+klik zaznacza zakres."
                    aria-label="Zaznacz wszystkie widoczne"
                  />
                </th>
              )}
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
              <SortTh label="Ostatni zakup" k="last_purchase" sort={sort} dir={dir} onSort={clickSort} />
              <th className="whitespace-nowrap p-2 font-semibold text-slate-700" title="Średnia cena zakupu partii na stanie; pod nią ostatnia PZ">Cena zakupu</th>
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
                canRwPw={canRwPw}
                location={location ? { key: location, name: locationName } : null}
                warehouses={warehouses}
                lotCutoff={result?.lot_cutoff ?? null}
                selection={
                  canSelect
                    ? { checked: selected.has(row.id), onToggle: (shiftKey) => toggleRow(i, shiftKey) }
                    : undefined
                }
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
                      Brak towarów przy wybranych filtrach.
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
  canRwPw,
  location,
  warehouses,
  lotCutoff,
  selection,
}: {
  row: InventoryRow
  index: number
  striped: boolean
  onOpenCard: (productId: number) => void
  /** Link do zakładki RW → PW tylko z uprawnieniem inventory.view (handlowiec widzi sam znacznik). */
  canRwPw: boolean
  /** Filtr oddziału: stan i magazyny pod nim tylko z oddziału; null = wszystkie magazyny. */
  location: { key: string; name: string } | null
  /** Filtr magazynów: stan i magazyny pod nim (przychodzą z API) tylko z handlowych / usługowych. */
  warehouses: InventoryBoardWarehouses
  /** Filtr „partia leży od”: ilość i wartość tylko z partii przyjętych najpóźniej tego dnia ('YYYY-MM-DD'). */
  lotCutoff: string | null
  /** Kolumna zaznaczania do kampanii; brak = bez kolumny. */
  selection?: { checked: boolean; onToggle: (shiftKey: boolean) => void }
}) {
  const unit = erpUnitLabel(row.unit)
  const card = row.card
  const lp = row.last_purchase
  const foreign = lp ? erpForeignPrice(lp) : null
  return (
    <tr
      className={`border-b align-top hover:bg-sky-50 ${
        selection?.checked ? 'bg-blue-50/40' : striped ? 'bg-slate-100/60' : ''
      }`}
    >
      {selection && (
        <td className="select-none p-2">
          <input
            type="checkbox"
            checked={selection.checked}
            title="Shift+klik zaznacza wszystkie od ostatnio klikniętej"
            onMouseDown={(e) => {
              if (!e.shiftKey) return
              e.preventDefault()
              selection.onToggle(true)
            }}
            onChange={(e) => {
              if ((e.nativeEvent as MouseEvent).shiftKey) return
              selection.onToggle(false)
            }}
            aria-label={`Zaznacz ${row.code}`}
          />
        </td>
      )}
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
          title={`${location || warehouses !== 'all' ? `${location ? `Oddział ${location.name}, ` : ''}${WAREHOUSE_LABEL[warehouses]}: ${erpQty(row.quantity)}${unit} · ` : ''}Wszystkie magazyny: ${erpQty(row.stock_total)}${unit} · HANDEL: ${erpQty(row.stock_trade)}${unit}`}
        >
          {erpQty(row.quantity)}
        </span>
        <span className="text-slate-500">{unit}</span>
        <WarehouseChips
          warehouses={location ? row.warehouses.filter((w) => w.location === location.key) : row.warehouses}
          unit={unit}
        />
        {lotCutoff && row.stock_in_scope !== row.quantity && (
          <div
            className="mt-0.5 text-[11px] text-amber-800"
            title={`Ilość i wartość tylko z dostaw przyjętych do ${formatDate(lotCutoff)}; nowsze dostawy nie są liczone`}
          >
            z {erpQty(row.stock_in_scope)} na stanie
          </div>
        )}
        {(location || warehouses !== 'all') && row.stock_total !== row.quantity && !lotCutoff && (
          <div className="mt-0.5 text-[11px] text-slate-500" title="Stan we wszystkich magazynach (wszystkie oddziały, handlowe i usługowe)">
            razem {erpQty(row.stock_total)}
          </div>
        )}
      </td>
      <td className="whitespace-nowrap p-2 text-right tabular-nums">
        {row.stock_value != null ? (
          <span
            className="block"
            title={
              row.value_source === 'lots'
                ? 'Ilość × cena zakupu każdej partii leżącej na magazynie (wartość partii z XL)'
                : `${erpQty(row.quantity)}${unit} × ${erpUnitPrice(row.last_purchase?.unit_price_pln ?? null)} zł z ostatniej PZ — XL nie podał jeszcze wartości partii (odczyt nocny o 2:00). PZ poprawiona później przez RW/PW daje tu złą kwotę.`
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
            title={
              location
                ? `Brak sprzedaży (faktura, paragon, WZ) z magazynów oddziału ${location.name} w całej historii XL`
                : 'Brak sprzedaży (faktura, paragon, WZ) w całej historii XL'
            }
          >
            {location ? 'nigdy w oddziale' : 'nigdy'}
          </span>
        )}
        {location && row.last_sale_any_at && row.last_sale_any_at !== row.last_sale_at && (
          <div className="mt-0.5 text-[11px] text-slate-500" title="Ostatnia sprzedaż z dowolnego magazynu (wszystkie oddziały)">
            gdziekolwiek: {formatDate(row.last_sale_any_at)}
          </div>
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
        {row.rw_pw_pairs > 0 &&
          // Nowa partia z PW „odmładza” datę najstarszej partii — stąd znacznik w tej kolumnie.
          (canRwPw ? (
            <Link
              to={`/zapasy/rw-pw?search=${encodeURIComponent(row.code)}`}
              className="mt-1 inline-block rounded bg-amber-100 px-1.5 py-0.5 text-[11px] font-medium tabular-nums text-amber-800 hover:underline"
              title={RW_PW_HINT}
            >
              RW/PW ×{row.rw_pw_pairs}
            </Link>
          ) : (
            <span
              className="mt-1 inline-block rounded bg-amber-100 px-1.5 py-0.5 text-[11px] font-medium tabular-nums text-amber-800"
              title={RW_PW_HINT}
            >
              RW/PW ×{row.rw_pw_pairs}
            </span>
          ))}
      </td>
      <td className="whitespace-nowrap p-2">
        {lp?.date ? (
          <>
            <DateWithAge iso={lp.date} />
            {lp.quantity != null && (
              <div className="text-[11px] tabular-nums text-slate-500" title="Ilość z ostatniej PZ">
                {erpQty(lp.quantity)}
                {unit}
              </div>
            )}
            {lp.supplier && (
              <div className="max-w-[10rem] truncate text-[11px] text-slate-500" title={lp.supplier}>
                {lp.supplier}
              </div>
            )}
          </>
        ) : (
          <span className="text-slate-400" title="Brak PZ tego towaru w programie magazynowym">
            —
          </span>
        )}
      </td>
      <td className="p-2">
        {row.unit_cost != null && (
          <div
            className="whitespace-nowrap tabular-nums font-medium text-slate-900"
            title="Średnia cena zakupu towaru na stanie: wartość partii ÷ ilość (z XL)"
          >
            {erpUnitPrice(row.unit_cost)} zł
            {row.unit && <span className="font-normal text-slate-500">/{row.unit}</span>}
            <span className="ml-1 text-[10px] font-normal text-slate-500">z partii</span>
          </div>
        )}
        {lp && (lp.unit_price_pln != null || lp.date || lp.supplier) ? (
          <>
            {lp.unit_price_pln != null && pzMismatch(row.unit_cost, lp.unit_price_pln) ? (
              <div
                className="mt-0.5 whitespace-nowrap rounded bg-amber-100 px-1 text-[11px] tabular-nums text-amber-900"
                title="Cena z ostatniej PZ mocno odbiega od ceny partii na stanie — PZ mogła być przyjęta w złej ilości i poprawiona później (np. RW + PW). Wiarygodna jest cena z partii."
              >
                PZ {erpUnitPrice(lp.unit_price_pln)} zł — niezgodna z partiami
              </div>
            ) : (
              <div
                className={`whitespace-nowrap tabular-nums ${row.unit_cost != null ? 'text-[11px] text-slate-500' : 'text-slate-800'}`}
              >
                {lp.unit_price_pln != null ? (
                  <>
                    {row.unit_cost != null && 'ost. PZ '}
                    {erpUnitPrice(lp.unit_price_pln)} zł
                    {row.unit && <span className="text-slate-500">/{row.unit}</span>}
                  </>
                ) : (
                  <span className="text-slate-400">cena nieznana</span>
                )}
                {foreign && <span className="ml-1 text-[11px] text-slate-500">({foreign})</span>}
              </div>
            )}
          </>
        ) : (
          row.unit_cost == null && <span className="text-slate-400">—</span>
        )}
      </td>
    </tr>
  )
}

const RW_PW_HINT =
  'W ostatnich 12 mies. towar był wydany RW i przyjęty z powrotem PW — najstarsza partia może być młodsza niż towar naprawdę leży.'

/** Segmenty miesięcy: „wszystkie” (bez warunku) i progi 1–24 mies. — ten sam wygląd dla sprzedaży i wieku partii. */
function MonthSegments<T extends string>({
  id,
  label,
  options,
  value,
  title,
  onChange,
}: {
  id: string
  label: string
  options: readonly T[]
  value: T
  title: string
  onChange: (value: T) => void
}) {
  return (
    <div className="flex items-end gap-1.5">
      <div className="flex flex-col gap-0.5 text-[11px] text-slate-500" title={title}>
        <span id={`${id}-label`}>{label}</span>
        <div className="inline-flex overflow-hidden rounded border border-slate-300" role="group" aria-labelledby={`${id}-label`}>
          {options.map((m, i) => {
            const active = m === value
            return (
              <button
                key={m}
                type="button"
                aria-pressed={active}
                onClick={() => onChange(m)}
                className={`px-2 py-1 text-xs tabular-nums ${i > 0 ? 'border-l border-slate-300' : ''} ${
                  active ? 'bg-blue-600 font-semibold text-white' : 'bg-white text-slate-700 hover:bg-slate-50'
                }`}
              >
                {m === '0' ? 'wszystkie' : (YEAR_LABELS[m] ?? m)}
              </button>
            )
          })}
        </div>
      </div>
      {!options.some((o) => YEAR_LABELS[o]) && <span className="pb-1 text-xs text-slate-500">mies.</span>}
    </div>
  )
}

/** Cena z PZ różni się od ceny partii o więcej niż połowę (w którąkolwiek stronę) — PZ do sprawdzenia. */
function pzMismatch(unitCost: number | null, pzPrice: number): boolean {
  if (unitCost == null || unitCost <= 0 || pzPrice <= 0) return false
  const ratio = pzPrice / unitCost
  return ratio > 1.5 || ratio < 1 / 1.5
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
