import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useAuth } from '../auth'
import { ProductSearchSelect } from '../components/ProductSearchSelect'
import { applyCheckboxRange } from '../lib/checkboxRange'
import {
  api,
  can,
  type ErpAdminItem,
  type ErpAdminLink,
  type ErpAdminLinker,
  type ErpAdminSummary,
  type ErpOutcome,
} from '../lib/api'
import { erpQty, erpUnitLabel } from '../lib/erpStock'
import { formatDate, formatDateTime } from '../lib/priceChange'

type ItemsPage = {
  data: ErpAdminItem[]
  /** linkers: kto ile połączył przy tych filtrach (bez filtra „Połączył”). */
  meta: { current_page: number; last_page: number; per_page: number; total: number; linkers: ErpAdminLinker[] }
}

type StatusFilter = '' | 'linked' | 'auto' | 'confirmed' | 'review' | 'no_card' | 'no_code' | 'rejected' | 'unlinked'
type SortKey = 'stock' | 'last_sale' | 'last_purchase' | 'code' | 'name' | 'status' | 'card' | 'linked_at' | 'linked_by'
type SortDir = 'asc' | 'desc'
const SORT_KEYS: readonly SortKey[] = [
  'stock',
  'last_sale',
  'last_purchase',
  'code',
  'name',
  'status',
  'card',
  'linked_at',
  'linked_by',
]
const DATE_RE = /^\d{4}-\d{2}-\d{2}$/

const STATUS_OPTIONS: { value: StatusFilter; label: string }[] = [
  { value: '', label: 'Wszystkie' },
  { value: 'unlinked', label: 'Bez karty (wszystko poza połączonymi)' },
  { value: 'review', label: 'Do decyzji' },
  { value: 'no_card', label: 'Kod bez karty w katalogu' },
  { value: 'no_code', label: 'Bez kodu w nazwie' },
  { value: 'linked', label: 'Połączone (auto + potwierdzone)' },
  { value: 'auto', label: 'Połączone automatycznie' },
  { value: 'confirmed', label: 'Potwierdzone ręcznie' },
  { value: 'rejected', label: 'Odrzucone' },
]

const GROUP_LABEL: Record<string, string> = {
  A: 'odzież',
  B: 'obuwie',
  S: 'sprzęt ochronny',
  T: 'technika',
  H: 'higiena',
  other: 'inne',
}
const GROUPS = ['A', 'B', 'S', 'T', 'H', 'other']
const SOLD_MONTHS = ['3', '6', '12']
const PER_PAGE_OPTIONS = [50, 100, 200]
const DEFAULT_DIR: Record<SortKey, SortDir> = {
  stock: 'desc',
  last_sale: 'desc',
  last_purchase: 'desc',
  code: 'asc',
  name: 'asc',
  status: 'asc',
  card: 'asc',
  linked_at: 'desc',
  linked_by: 'asc',
}
const SEARCH_DEBOUNCE_MS = 300
/** Limit bulk-confirm z kontraktu API. */
const BULK_MAX = 200

function groupLabel(g: string): string {
  return g === 'other' ? 'inne' : `${g} ${GROUP_LABEL[g] ?? ''}`.trim()
}

function fmtInt(n: number | null | undefined): string {
  return n == null ? '—' : n.toLocaleString('pl-PL')
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

function itemsLabel(n: number): string {
  return `${fmtInt(n)} ${plural(n, 'towar', 'towary', 'towarów')}`
}

/** Powiązania, o których trzeba jeszcze zdecydować albo które można potwierdzić (auto / propozycja). */
function openLinks(item: ErpAdminItem): ErpAdminLink[] {
  return item.links.filter((l) => l.status === 'auto' || l.status === 'suggested')
}

function methodLabel(method: ErpAdminLink['method']): string {
  switch (method) {
    case 'name':
      return 'z nazwy XL'
    case 'name1':
      return 'z Nazwa1'
    case 'xl_code':
      return 'z kodu XL'
    case 'card_name':
      return 'z nazwy karty'
    case 'search':
      return 'z wyszukiwarki'
    case 'manual':
      return 'wybrane ręcznie'
  }
}

/** Krótki dowód powiązania z evidence — tylko to, co przysłał serwer; ostrzeżenia oznaczone warn. */
function evidenceBits(link: ErpAdminLink): { text: string; warn?: boolean; title?: string }[] {
  const e = link.evidence
  const bits: { text: string; warn?: boolean; title?: string }[] = []
  if (!e) return bits
  if (e.supplier_match) {
    bits.push({ text: 'dostawca = producent', title: e.supplier ? `Dostawca z XL: ${e.supplier}` : undefined })
  }
  if (e.brand_in_name) bits.push({ text: 'marka w nazwie' })
  if (e.shared_words && e.shared_words.length > 0) bits.push({ text: `wspólne: ${e.shared_words.join(', ')}` })
  if (e.other_brand) bits.push({ text: `inna marka: ${e.other_brand}`, warn: true })
  if (e.weak_code) bits.push({ text: 'krótki kod', warn: true, title: 'Kod jest krótki — łatwo o przypadkową zgodność' })
  return bits
}

const BADGE_BASE = 'inline-block max-w-[8rem] rounded px-1.5 py-0.5 text-[11px] font-medium leading-tight'

function OutcomeBadge({ item }: { item: ErpAdminItem }) {
  const o: ErpOutcome = item.outcome
  switch (o) {
    case 'auto':
      return <span className={`${BADGE_BASE} bg-green-100 text-green-800`}>połączone auto</span>
    case 'confirmed':
      return <span className={`${BADGE_BASE} bg-emerald-700 text-white`}>potwierdzone</span>
    case 'suggested':
      return (
        <span className={`${BADGE_BASE} bg-amber-100 text-amber-800`} title="Jedna karta, ale bez mocnego dowodu">
          do decyzji
        </span>
      )
    case 'ambiguous':
      return (
        <span className={`${BADGE_BASE} bg-amber-100 text-amber-800`} title="Kod pasuje do kilku kart">
          do decyzji
          <span className="block font-normal">kilka kart</span>
        </span>
      )
    case 'name_suggested':
      return (
        <span className={`${BADGE_BASE} bg-amber-100 text-amber-800`} title="Kod XL znaleziony w nazwie karty">
          do decyzji
          <span className="block font-normal">z nazwy karty</span>
        </span>
      )
    case 'search_suggested':
      return (
        <span
          className={`${BADGE_BASE} bg-amber-100 text-amber-800`}
          title="Towar bez kodu — karty z wyszukiwarki ze wspólną nazwą modelu i tym samym rodzajem wyrobu"
        >
          do decyzji
          <span className="block font-normal">z wyszukiwarki</span>
        </span>
      )
    case 'no_match':
    case 'family_conflict':
      return (
        <span
          className={`${BADGE_BASE} bg-slate-200 text-slate-700`}
          title={
            o === 'family_conflict'
              ? 'Kod znaleziony, ale karta jest z innej rodziny wyrobów — bez łączenia'
              : 'Nie ma karty z tym kodem w katalogu'
          }
        >
          kod bez karty{o === 'family_conflict' ? ' (inna rodzina)' : ''}
          {item.match_value && <span className="block break-all font-mono font-normal">{item.match_value}</span>}
        </span>
      )
    case 'no_code':
      return <span className={`${BADGE_BASE} bg-slate-100 text-slate-500`}>bez kodu</span>
    case 'rejected':
      return <span className={`${BADGE_BASE} bg-red-50 text-red-700`}>odrzucone</span>
    default:
      return <span className="text-[11px] text-slate-400">nie przeliczone</span>
  }
}

type Tile = {
  key: string
  label: string
  hint: string
  count: (s: ErpAdminSummary) => number
  /** Filtry ustawiane kliknięciem (pozostałe — grupa, dostawca, szukajka — zostają). */
  preset: { status: StatusFilter; in_stock: string; sold_months: string }
  tone: 'warn' | 'ok' | 'neutral'
}

const TILES: Tile[] = [
  {
    key: 'sold12',
    label: 'Z ruchem 12 mies. bez karty',
    hint: 'sprzedawane w ostatnim roku, bez połączonej karty',
    count: (s) => s.unlinked_sold_12m,
    preset: { status: 'unlinked', in_stock: '', sold_months: '12' },
    tone: 'warn',
  },
  {
    key: 'stock',
    label: 'Ze stanem bez karty',
    hint: 'stan HANDEL > 0, bez połączonej karty',
    count: (s) => s.unlinked_in_stock,
    preset: { status: 'unlinked', in_stock: '1', sold_months: '' },
    tone: 'warn',
  },
  {
    key: 'review',
    label: 'Do decyzji',
    hint: 'propozycje kart do potwierdzenia',
    count: (s) =>
      (s.by_outcome.suggested ?? 0) +
      (s.by_outcome.ambiguous ?? 0) +
      (s.by_outcome.name_suggested ?? 0) +
      (s.by_outcome.search_suggested ?? 0),
    preset: { status: 'review', in_stock: '', sold_months: '' },
    tone: 'warn',
  },
  {
    key: 'linked',
    label: 'Połączone',
    hint: 'automatycznie i potwierdzone',
    count: (s) => (s.by_outcome.auto ?? 0) + (s.by_outcome.confirmed ?? 0),
    preset: { status: 'linked', in_stock: '', sold_months: '' },
    tone: 'ok',
  },
  {
    key: 'no_card',
    label: 'Kod bez karty w katalogu',
    hint: 'kod jest w nazwie XL, karty brak',
    count: (s) => (s.by_outcome.no_match ?? 0) + (s.by_outcome.family_conflict ?? 0),
    preset: { status: 'no_card', in_stock: '', sold_months: '' },
    tone: 'neutral',
  },
  {
    key: 'no_code',
    label: 'Bez kodu w nazwie',
    hint: 'nie ma po czym szukać karty',
    count: (s) => s.by_outcome.no_code ?? 0,
    preset: { status: 'no_code', in_stock: '', sold_months: '' },
    tone: 'neutral',
  },
]

function tileNumberClass(tone: Tile['tone']): string {
  if (tone === 'warn') return 'text-amber-800'
  if (tone === 'ok') return 'text-emerald-700'
  return 'text-slate-800'
}

export function AdminErpItems() {
  const { user } = useAuth()
  const canManage = can(user, 'admin.erp_links.manage')
  const [params, setParams] = useSearchParams()

  const status = pick<StatusFilter>(
    params.get('status'),
    STATUS_OPTIONS.map((o) => o.value),
    '',
  )
  const group = pick(params.get('group'), GROUPS, '')
  const inStock = params.get('in_stock') === '1' ? '1' : ''
  const soldMonths = pick(params.get('sold_months'), SOLD_MONTHS, '')
  const supplier = params.get('supplier') ?? ''
  const search = params.get('search') ?? ''
  const linkedByParam = params.get('linked_by') ?? ''
  const linkedBy = /^(auto|[1-9]\d{0,9})$/.test(linkedByParam) ? linkedByParam : ''
  const linkedFromParam = params.get('linked_from') ?? ''
  const linkedFrom = DATE_RE.test(linkedFromParam) ? linkedFromParam : ''
  const linkedToParam = params.get('linked_to') ?? ''
  // „do” przed „od” — serwer odrzuca (422), więc taki zakres nie idzie do zapytania
  const linkedTo = DATE_RE.test(linkedToParam) && !(linkedFrom && linkedToParam < linkedFrom) ? linkedToParam : ''
  const sort = pick<SortKey>(params.get('sort'), SORT_KEYS, 'stock')
  const dir = pick<SortDir>(params.get('dir'), ['asc', 'desc'], DEFAULT_DIR[sort])
  const page = Math.max(1, Math.floor(Number(params.get('page'))) || 1)
  const perPage = PER_PAGE_OPTIONS.includes(Number(params.get('per_page'))) ? Number(params.get('per_page')) : 50

  const apiQuery = useMemo(() => {
    const qs = new URLSearchParams()
    if (status) qs.set('status', status)
    if (group) qs.set('group', group)
    if (inStock) qs.set('in_stock', '1')
    if (soldMonths) qs.set('sold_months', soldMonths)
    if (supplier.trim()) qs.set('supplier', supplier.trim())
    if (search.trim()) qs.set('search', search.trim())
    if (linkedBy) qs.set('linked_by', linkedBy)
    if (linkedFrom) qs.set('linked_from', linkedFrom)
    if (linkedTo) qs.set('linked_to', linkedTo)
    qs.set('sort', sort)
    qs.set('dir', dir)
    qs.set('page', String(page))
    qs.set('per_page', String(perPage))
    return qs.toString()
  }, [status, group, inStock, soldMonths, supplier, search, linkedBy, linkedFrom, linkedTo, sort, dir, page, perPage])

  const [summary, setSummary] = useState<ErpAdminSummary | null>(null)
  const [summaryErr, setSummaryErr] = useState('')
  const [result, setResult] = useState<ItemsPage | null>(null)
  const [loading, setLoading] = useState(false)
  const [listErr, setListErr] = useState('')
  const [actionErr, setActionErr] = useState('')
  const [msg, setMsg] = useState('')
  const [busyItemId, setBusyItemId] = useState<number | null>(null)
  const [bulkBusy, setBulkBusy] = useState(false)
  const [rowErrors, setRowErrors] = useState<Record<number, string>>({})
  const [selected, setSelected] = useState<Record<number, boolean>>({})
  const [pickFor, setPickFor] = useState<ErpAdminItem | null>(null)
  const lastSelectIndex = useRef<number | null>(null)
  const listSeq = useRef(0)
  const summarySeq = useRef(0)

  // Pola tekstowe: wpis od razu w polu, do adresu (i zapytania) po 300 ms bez pisania.
  const [searchInput, setSearchInput] = useState(search)
  const [supplierInput, setSupplierInput] = useState(supplier)
  const pushedSearch = useRef(search)
  const pushedSupplier = useRef(supplier)

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
      // bez trim — spacja na końcu to pisanie w toku (przycina zapytanie do API)
      setFilters({ search: searchInput, supplier: supplierInput }, { replace: true })
    }, SEARCH_DEBOUNCE_MS)
    return () => window.clearTimeout(t)
  }, [searchInput, supplierInput, setFilters])

  const loadSummary = useCallback(async () => {
    const seq = ++summarySeq.current
    try {
      const s = await api<ErpAdminSummary>('/admin/erp-items/summary')
      if (seq !== summarySeq.current) return
      setSummary(s)
      setSummaryErr('')
    } catch (ex) {
      if (seq === summarySeq.current) setSummaryErr(ex instanceof Error ? ex.message : 'Błąd wczytywania liczników')
    }
  }, [])

  const loadList = useCallback(async () => {
    const seq = ++listSeq.current
    setLoading(true)
    setListErr('')
    try {
      const res = await api<ItemsPage>(`/admin/erp-items?${apiQuery}`)
      // Szybkie klikanie filtrów — spóźniona odpowiedź nie nadpisuje nowszej.
      if (seq !== listSeq.current) return
      setResult(res)
    } catch (ex) {
      if (seq === listSeq.current) setListErr(ex instanceof Error ? ex.message : 'Błąd wczytywania towarów')
    } finally {
      if (seq === listSeq.current) setLoading(false)
    }
  }, [apiQuery])

  useEffect(() => {
    void loadSummary()
  }, [loadSummary])

  useEffect(() => {
    void loadList()
  }, [loadList])

  // Zaznaczenie i błędy przy wierszach dotyczą widocznej strony jednego zestawu filtrów.
  useEffect(() => {
    setSelected({})
    setRowErrors({})
    lastSelectIndex.current = null
  }, [apiQuery])

  // Strona za końcem listy (np. po zbiorczym potwierdzeniu w „Do decyzji”) — wróć na ostatnią istniejącą.
  useEffect(() => {
    const m = result?.meta
    if (result && result.data.length === 0 && m && m.current_page > 1 && m.current_page > m.last_page) {
      setFilters({ page: m.last_page > 1 ? String(m.last_page) : null }, { keepPage: true, replace: true })
    }
  }, [result, setFilters])

  const rows = result?.data ?? []
  const selectableIds = rows.filter((i) => openLinks(i).length > 0).map((i) => i.id)
  const selectedIds = selectableIds.filter((id) => selected[id])
  const allVisibleSelected = selectableIds.length > 0 && selectableIds.every((id) => selected[id])
  const showSelect = canManage
  const colCount = 9 + (showSelect ? 1 : 0)
  // opcje „Połączył”: wszyscy łączący z liczników; wybrana osoba zostaje, nawet gdy liczniki jeszcze się wczytują
  const linkerOptions = (summary?.linkers ?? []).filter((l): l is ErpAdminLinker & { key: string } => l.key !== null)
  if (linkedBy && !linkerOptions.some((l) => l.key === linkedBy)) {
    linkerOptions.push({ key: linkedBy, name: linkedBy === 'auto' ? 'automat' : `użytkownik #${linkedBy}`, count: 0 })
  }
  const resultLinkers = result?.meta.linkers ?? []

  function toggleSelected(id: number, shiftKey: boolean) {
    const index = selectableIds.indexOf(id)
    if (index < 0) return
    setSelected((prev) => {
      const applied = applyCheckboxRange(selectableIds, prev, lastSelectIndex.current, index, shiftKey)
      lastSelectIndex.current = applied.anchorIndex
      return applied.selected
    })
  }

  function toggleSelectAllVisible() {
    if (selectableIds.length === 0) return
    const allOn = selectableIds.every((id) => selected[id])
    setSelected((prev) => {
      const next = { ...prev }
      for (const id of selectableIds) {
        if (allOn) delete next[id]
        else next[id] = true
      }
      return next
    })
    lastSelectIndex.current = allOn ? null : selectableIds.length - 1
  }

  function replaceItem(item: ErpAdminItem) {
    setResult((prev) => (prev ? { ...prev, data: prev.data.map((i) => (i.id === item.id ? item : i)) } : prev))
    setSelected((prev) => {
      if (!prev[item.id]) return prev
      const next = { ...prev }
      delete next[item.id]
      return next
    })
  }

  async function runItemAction(item: ErpAdminItem, path: string, body: object, done: (next: ErpAdminItem) => string) {
    setBusyItemId(item.id)
    setMsg('')
    setActionErr('')
    setRowErrors((prev) => {
      if (!(item.id in prev)) return prev
      const next = { ...prev }
      delete next[item.id]
      return next
    })
    try {
      const res = await api<{ item: ErpAdminItem }>(path, { method: 'POST', body: JSON.stringify(body) })
      replaceItem(res.item)
      setMsg(done(res.item))
      void loadSummary()
      return true
    } catch (ex) {
      setRowErrors((prev) => ({ ...prev, [item.id]: ex instanceof Error ? ex.message : 'Błąd' }))
      return false
    } finally {
      setBusyItemId(null)
    }
  }

  function confirmLink(item: ErpAdminItem, link: ErpAdminLink) {
    const sku = link.product?.sku ?? `#${link.id}`
    void runItemAction(item, `/admin/erp-links/${link.id}/confirm`, {}, () => `Potwierdzono: ${item.code} → ${sku}.`)
  }

  function rejectLink(item: ErpAdminItem, link: ErpAdminLink) {
    const sku = link.product?.sku ?? `#${link.id}`
    if (link.status === 'confirmed' || link.status === 'auto') {
      const ok = window.confirm(
        `${link.status === 'confirmed' ? 'Odłączyć' : 'Odrzucić'} kartę ${sku} od towaru XL ${item.code}?\n\n` +
          'Stan i zakupy z tego towaru przestaną być widoczne na karcie. Automat nie połączy ich ponownie — ' +
          'połączyć można tylko ręcznie („Wybierz kartę…”).',
      )
      if (!ok) return
    }
    void runItemAction(item, `/admin/erp-links/${link.id}/reject`, {}, () =>
      link.status === 'confirmed' ? `Odłączono: ${item.code} ↛ ${sku}.` : `Odrzucono: ${item.code} ↛ ${sku}.`,
    )
  }

  async function linkManual(item: ErpAdminItem, productId: number, sku: string): Promise<boolean> {
    return runItemAction(item, `/admin/erp-items/${item.id}/link`, { product_id: productId }, () => `Połączono: ${item.code} → ${sku}.`)
  }

  async function bulkConfirm() {
    const chosen = rows.filter((i) => selected[i.id])
    const single = chosen.filter((i) => openLinks(i).length === 1)
    const skipped = chosen.length - single.length
    const ids = single.map((i) => openLinks(i)[0].id).slice(0, BULK_MAX)
    if (ids.length === 0) {
      setMsg('')
      setActionErr(
        skipped > 0
          ? `Zaznaczone towary mają po kilka propozycji — wybierz kartę przyciskiem „To ta” w wierszu.`
          : 'Nic do potwierdzenia.',
      )
      return
    }
    const ok = window.confirm(
      `Potwierdzić ${ids.length} ${plural(ids.length, 'powiązanie', 'powiązania', 'powiązań')}?` +
        (skipped > 0 ? `\n\nPominięte: ${skipped} (kilka propozycji kart — wybierz w wierszu „To ta”).` : ''),
    )
    if (!ok) return
    setBulkBusy(true)
    setMsg('')
    setActionErr('')
    try {
      const res = await api<{ confirmed: number }>('/admin/erp-links/bulk-confirm', {
        method: 'POST',
        body: JSON.stringify({ ids }),
      })
      setMsg(
        `Potwierdzono ${fmtInt(res.confirmed)} z ${ids.length}.` +
          (skipped > 0 ? ` Pominięto ${skipped} z kilkoma propozycjami — wybierz kartę „To ta” w wierszu.` : ''),
      )
      setSelected({})
      lastSelectIndex.current = null
    } catch (ex) {
      setActionErr(ex instanceof Error ? ex.message : 'Błąd potwierdzania zbiorczego')
    } finally {
      setBulkBusy(false)
      void loadSummary()
      await loadList()
    }
  }

  function applyTile(t: Tile) {
    const active = isTileActive(t)
    setMsg('')
    setFilters(active ? { status: null, in_stock: null, sold_months: null } : t.preset)
  }

  function isTileActive(t: Tile): boolean {
    return status === t.preset.status && inStock === t.preset.in_stock && soldMonths === t.preset.sold_months
  }

  function clickSort(key: SortKey) {
    const nextDir: SortDir = key === sort ? (dir === 'asc' ? 'desc' : 'asc') : DEFAULT_DIR[key]
    setFilters({ sort: key === 'stock' ? null : key, dir: nextDir === DEFAULT_DIR[key] ? null : nextDir })
  }

  function clearFilters() {
    setMsg('')
    setParams((prev) => {
      const next = new URLSearchParams()
      const pp = prev.get('per_page')
      if (pp) next.set('per_page', pp)
      return next
    })
  }

  const hasFilters = Boolean(
    status || group || inStock || soldMonths || supplier || search || linkedBy || linkedFromParam || linkedToParam,
  )
  const meta = result?.meta
  const busy = bulkBusy || busyItemId !== null

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h2 className="text-lg font-semibold text-slate-800">Powiązania z ERP XL</h2>
          <p className="mt-0.5 max-w-3xl text-xs text-slate-600">
            Towary z Comarch ERP XL i karty katalogu, z którymi są połączone. Połączony towar daje karcie stan
            magazynowy i ceny zakupu. Tu widać, czego brakuje, i można to poprawić.
          </p>
        </div>
        <p className="text-[11px] text-slate-500">
          {summary?.synced_at ? `Odczyt z XL: ${formatDateTime(summary.synced_at)}` : summary ? 'Brak odczytu z XL' : ''}
          {summary ? ` · aktywnych towarów: ${fmtInt(summary.total)}` : ''}
        </p>
      </div>

      {summaryErr && (
        <p className="rounded bg-red-50 px-3 py-2 text-xs text-red-700">
          Liczniki się nie wczytały: {summaryErr}{' '}
          <button type="button" className="font-medium underline" onClick={() => void loadSummary()}>
            Spróbuj ponownie
          </button>
        </p>
      )}

      <div className="grid gap-2 sm:grid-cols-3 xl:grid-cols-6">
        {TILES.map((t) => {
          const active = isTileActive(t)
          return (
            <button
              key={t.key}
              type="button"
              onClick={() => applyTile(t)}
              aria-pressed={active}
              title={active ? 'Kliknij, żeby zdjąć ten filtr' : 'Pokaż te towary'}
              className={`rounded-xl border px-3 py-2 text-left shadow-sm transition ${
                active
                  ? 'border-sky-400 bg-sky-50 ring-1 ring-sky-300'
                  : 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50'
              }`}
            >
              <div className={`text-xl font-semibold tabular-nums ${tileNumberClass(t.tone)}`}>
                {summary ? fmtInt(t.count(summary)) : '…'}
              </div>
              <div className="text-xs font-medium text-slate-800">{t.label}</div>
              <div className="text-[11px] text-slate-500">{t.hint}</div>
            </button>
          )
        })}
      </div>

      {summary && summary.groups.length > 0 && (
        <div className="flex flex-wrap items-center gap-x-1 gap-y-1 text-[11px] text-slate-600">
          <span className="mr-1 text-slate-500">Grupy (połączone / wszystkie):</span>
          {summary.groups.map((g) => {
            const active = group === g.group
            const pct = g.total > 0 ? Math.round((g.linked * 100) / g.total) : 0
            return (
              <button
                key={g.group}
                type="button"
                onClick={() => setFilters({ group: active ? null : g.group })}
                aria-pressed={active}
                className={`rounded border px-1.5 py-0.5 tabular-nums ${
                  active ? 'border-sky-400 bg-sky-50 text-sky-900' : 'border-slate-200 bg-white hover:bg-slate-50'
                }`}
                title={`${pct}% towarów grupy ma kartę`}
              >
                <span className="font-medium text-slate-800">{groupLabel(g.group)}</span> {fmtInt(g.linked)} /{' '}
                {fmtInt(g.total)} <span className="text-slate-500">({pct}%)</span>
              </button>
            )
          })}
        </div>
      )}

      <div className="flex flex-wrap items-end gap-x-3 gap-y-2 rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs shadow-sm">
        <label className="flex flex-col gap-0.5 text-[11px] text-slate-500">
          Status
          <select
            className="rounded border border-slate-300 bg-white px-1.5 py-1 text-xs text-slate-800"
            value={status}
            onChange={(e) => setFilters({ status: e.target.value })}
          >
            {STATUS_OPTIONS.map((o) => (
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
          Sprzedaż
          <select
            className="rounded border border-slate-300 bg-white px-1.5 py-1 text-xs text-slate-800"
            value={soldMonths}
            onChange={(e) => setFilters({ sold_months: e.target.value })}
          >
            <option value="">dowolna</option>
            {SOLD_MONTHS.map((m) => (
              <option key={m} value={m}>
                w ostatnich {m} mies.
              </option>
            ))}
          </select>
        </label>
        <label className="flex items-center gap-1.5 pb-1 text-xs text-slate-700">
          <input
            type="checkbox"
            checked={inStock === '1'}
            onChange={(e) => setFilters({ in_stock: e.target.checked ? '1' : null })}
          />
          tylko ze stanem HANDEL
        </label>
        <label className="flex flex-col gap-0.5 text-[11px] text-slate-500">
          Dostawca
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
        <label className="flex flex-col gap-0.5 text-[11px] text-slate-500">
          Połączył
          <select
            className="rounded border border-slate-300 bg-white px-1.5 py-1 text-xs text-slate-800"
            value={linkedBy}
            onChange={(e) => setFilters({ linked_by: e.target.value })}
          >
            <option value="">wszyscy</option>
            {linkerOptions.map((l) => (
              <option key={l.key} value={l.key}>
                {l.name}
              </option>
            ))}
          </select>
        </label>
        <label className="flex flex-col gap-0.5 text-[11px] text-slate-500">
          Połączone od
          <input
            type="date"
            className="rounded border border-slate-300 bg-white px-1.5 py-0.5 text-xs text-slate-800"
            value={linkedFromParam}
            max={linkedToParam || undefined}
            onChange={(e) => setFilters({ linked_from: e.target.value })}
          />
        </label>
        <label className="flex flex-col gap-0.5 text-[11px] text-slate-500">
          do
          <input
            type="date"
            className="rounded border border-slate-300 bg-white px-1.5 py-0.5 text-xs text-slate-800"
            value={linkedToParam}
            min={linkedFromParam || undefined}
            onChange={(e) => setFilters({ linked_to: e.target.value })}
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

      {msg && <p className="rounded bg-green-50 px-3 py-2 text-xs text-green-800">{msg}</p>}
      {actionErr && <p className="rounded bg-red-50 px-3 py-2 text-xs text-red-700">{actionErr}</p>}
      {listErr && (
        <p className="rounded bg-red-50 px-3 py-2 text-xs text-red-700">
          Nie udało się wczytać towarów: {listErr}{' '}
          <button type="button" className="font-medium underline" onClick={() => void loadList()}>
            Spróbuj ponownie
          </button>
        </p>
      )}

      <div className="overflow-x-auto rounded-xl bg-white shadow-sm">
        <div className="flex flex-wrap items-center gap-3 px-3 pt-2 pb-2 text-xs">
          {showSelect && selectableIds.length > 0 && (
            <>
              <label className="flex items-center gap-2 text-slate-600">
                <input type="checkbox" checked={allVisibleSelected} onChange={toggleSelectAllVisible} />
                Zaznacz widoczne do potwierdzenia ({selectableIds.length})
                <span className="text-slate-400">· Shift+klik: zakres</span>
              </label>
              <button
                type="button"
                disabled={busy || selectedIds.length === 0}
                onClick={() => void bulkConfirm()}
                className="rounded bg-blue-600 px-2.5 py-1 text-xs text-white hover:bg-blue-700 disabled:opacity-40"
              >
                {bulkBusy ? 'Potwierdzam…' : `Potwierdź zaznaczone${selectedIds.length > 0 ? ` (${selectedIds.length})` : ''}`}
              </button>
            </>
          )}
          <span className="text-slate-500">
            {meta ? itemsLabel(meta.total) : ''}
            {loading ? (meta ? ' · ładowanie…' : 'Ładowanie…') : ''}
          </span>
          {resultLinkers.length > 0 && (
            <span className="flex flex-wrap items-center gap-1 text-slate-500" title="Liczone przy tych filtrach, bez filtra „Połączył”">
              Połączyli:
              {resultLinkers.map((l) => {
                if (l.key === null) {
                  return (
                    <span key="removed" className="rounded border border-slate-200 bg-white px-1.5 py-0.5 tabular-nums">
                      <span className="text-slate-800">{l.name}</span> {fmtInt(l.count)}
                    </span>
                  )
                }
                const active = l.key === linkedBy
                return (
                  <button
                    key={l.key}
                    type="button"
                    onClick={() => setFilters({ linked_by: active ? null : l.key })}
                    aria-pressed={active}
                    className={`rounded border px-1.5 py-0.5 tabular-nums ${
                      active ? 'border-sky-400 bg-sky-50 text-sky-900' : 'border-slate-200 bg-white hover:bg-slate-50'
                    }`}
                  >
                    <span className="text-slate-800">{l.name}</span> {fmtInt(l.count)}
                  </button>
                )
              })}
            </span>
          )}
        </div>

        <table className="w-full text-left text-xs">
          <thead>
            <tr className="border-y bg-slate-50 text-[11px] text-slate-600">
              {showSelect && (
                <th className="w-8 p-2">
                  <input
                    type="checkbox"
                    checked={allVisibleSelected}
                    disabled={selectableIds.length === 0}
                    onChange={toggleSelectAllVisible}
                    aria-label="Zaznacz wszystkie widoczne do potwierdzenia"
                  />
                </th>
              )}
              <SortTh label="Kod XL" k="code" sort={sort} dir={dir} onSort={clickSort} />
              <SortTh label="Nazwa XL" k="name" sort={sort} dir={dir} onSort={clickSort} />
              <SortTh label="HANDEL" k="stock" sort={sort} dir={dir} onSort={clickSort} align="right" />
              <SortTh label="Ostatnia sprzedaż" k="last_sale" sort={sort} dir={dir} onSort={clickSort} />
              <SortTh label="Ostatni zakup / dostawca" k="last_purchase" sort={sort} dir={dir} onSort={clickSort} />
              <SortTh label="Status" k="status" sort={sort} dir={dir} onSort={clickSort} />
              <SortTh label="Karta" k="card" sort={sort} dir={dir} onSort={clickSort} />
              <th className="p-2 font-medium" aria-sort={ariaSort(sort, dir, ['linked_by', 'linked_at'])}>
                <span className="flex flex-col items-start gap-0.5">
                  <SortButton label="Połączył" k="linked_by" sort={sort} dir={dir} onSort={clickSort} />
                  <SortButton label="kiedy" k="linked_at" sort={sort} dir={dir} onSort={clickSort} />
                </span>
              </th>
              <th className="w-36 p-2 font-medium">Akcje</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((item, i) => {
              const open = openLinks(item)
              const selectable = open.length > 0
              const rowBusy = busyItemId === item.id
              return (
                <tr
                  key={item.id}
                  className={`border-b align-top ${selected[item.id] ? 'bg-blue-50/40' : i % 2 === 1 ? 'bg-slate-50' : ''}`}
                >
                  {showSelect && (
                    <td className="select-none p-2">
                      {selectable && (
                        <input
                          type="checkbox"
                          checked={Boolean(selected[item.id])}
                          title={
                            open.length > 1
                              ? 'Kilka propozycji — przy zbiorczym potwierdzeniu zostanie pominięty'
                              : 'Shift+klik zaznacza wszystkie od ostatnio klikniętego'
                          }
                          onMouseDown={(e) => {
                            if (!e.shiftKey) return
                            e.preventDefault()
                            toggleSelected(item.id, true)
                          }}
                          onChange={(e) => {
                            if ((e.nativeEvent as MouseEvent).shiftKey) return
                            toggleSelected(item.id, false)
                          }}
                          aria-label={`Zaznacz ${item.code}`}
                        />
                      )}
                    </td>
                  )}
                  <td className="whitespace-nowrap p-2">
                    <span className="font-mono text-slate-900">{item.code}</span>
                    {item.name1 && <div className="font-mono text-[11px] text-slate-400">{item.name1}</div>}
                    {item.archived && (
                      <span className="mt-0.5 inline-block rounded bg-slate-100 px-1 text-[10px] text-slate-500">archiwalny</span>
                    )}
                  </td>
                  <td className="p-2 text-slate-800">
                    <div className="min-w-[10rem] max-w-[20rem] break-words">{item.name}</div>
                  </td>
                  <td
                    className="whitespace-nowrap p-2 text-right tabular-nums"
                    title={`Wszystkie magazyny: ${erpQty(item.stock_total)}${erpUnitLabel(item.unit)}`}
                  >
                    <span className={item.stock_trade > 0 ? 'font-semibold text-emerald-700' : 'text-slate-400'}>
                      {erpQty(item.stock_trade)}
                    </span>
                    <span className="text-slate-500">{erpUnitLabel(item.unit)}</span>
                  </td>
                  <td className="whitespace-nowrap p-2 text-slate-700">
                    {item.last_sale_at ? formatDate(item.last_sale_at) : <span className="text-slate-400">—</span>}
                  </td>
                  <td className="p-2 text-slate-700">
                    {item.last_purchase_at ? formatDate(item.last_purchase_at) : <span className="text-slate-400">—</span>}
                    {item.last_supplier && (
                      <div className="max-w-[9rem] truncate text-[11px] text-slate-500" title={item.last_supplier}>
                        {item.last_supplier}
                      </div>
                    )}
                  </td>
                  <td className="p-2">
                    <OutcomeBadge item={item} />
                  </td>
                  <td className="p-2">
                    <LinksCell
                      item={item}
                      canManage={canManage}
                      disabled={busy}
                      onPick={(link) => confirmLink(item, link)}
                      onReject={(link) => rejectLink(item, link)}
                    />
                  </td>
                  <td className="whitespace-nowrap p-2 text-[11px]">
                    {item.linked ? (
                      <>
                        <div className={item.linked.auto ? 'text-slate-500' : 'font-medium text-slate-800'}>
                          {item.linked.auto ? 'automat' : item.linked.by}
                        </div>
                        {item.linked.at && <div className="text-slate-500">{formatDateTime(item.linked.at)}</div>}
                      </>
                    ) : (
                      <span className="text-slate-400">—</span>
                    )}
                  </td>
                  <td className="p-2">
                    {canManage ? (
                      <div className="flex flex-wrap gap-1">
                        {open.length === 1 && (
                          <>
                            <button
                              type="button"
                              disabled={busy}
                              onClick={() => confirmLink(item, open[0])}
                              className="rounded bg-blue-600 px-2 py-0.5 text-[11px] text-white hover:bg-blue-700 disabled:opacity-50"
                            >
                              {rowBusy ? '…' : 'Potwierdź'}
                            </button>
                            <button
                              type="button"
                              disabled={busy}
                              onClick={() => rejectLink(item, open[0])}
                              className="rounded border border-slate-300 px-2 py-0.5 text-[11px] hover:bg-slate-50 disabled:opacity-50"
                            >
                              Odrzuć
                            </button>
                          </>
                        )}
                        {item.links
                          .filter((l) => l.status === 'confirmed')
                          .map((l) => (
                            <button
                              key={l.id}
                              type="button"
                              disabled={busy}
                              onClick={() => rejectLink(item, l)}
                              title={`Odłącz kartę ${l.product?.sku ?? ''}`.trim()}
                              className="rounded border border-slate-300 px-2 py-0.5 text-[11px] hover:bg-slate-50 disabled:opacity-50"
                            >
                              {rowBusy ? '…' : 'Odłącz'}
                            </button>
                          ))}
                        <button
                          type="button"
                          disabled={busy}
                          onClick={() => {
                            setRowErrors((prev) => {
                              if (!(item.id in prev)) return prev
                              const next = { ...prev }
                              delete next[item.id]
                              return next
                            })
                            setPickFor(item)
                          }}
                          className="rounded border border-slate-300 px-2 py-0.5 text-[11px] hover:bg-slate-50 disabled:opacity-50"
                        >
                          Wybierz kartę…
                        </button>
                      </div>
                    ) : (
                      <span className="text-[11px] text-slate-400">tylko podgląd</span>
                    )}
                    {rowErrors[item.id] && <p className="mt-1 text-[11px] text-red-700">{rowErrors[item.id]}</p>}
                  </td>
                </tr>
              )
            })}
            {rows.length === 0 && (
              <tr>
                <td colSpan={colCount} className="p-8 text-center text-slate-500">
                  {loading || (!result && !listErr) ? (
                    'Ładowanie…'
                  ) : listErr && !result ? (
                    'Nie udało się wczytać towarów.'
                  ) : (
                    <>
                      Brak towarów dla tych filtrów.
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

        {meta && meta.total > 0 && (
          <div className="flex flex-wrap items-center justify-between gap-2 px-3 py-2 text-xs text-slate-500">
            <label className="flex items-center gap-1">
              Na stronie
              <select
                className="rounded border border-slate-300 bg-white px-1 py-0.5 text-xs text-slate-800"
                value={perPage}
                onChange={(e) => setFilters({ per_page: e.target.value === '50' ? null : e.target.value })}
              >
                {PER_PAGE_OPTIONS.map((n) => (
                  <option key={n} value={n}>
                    {n}
                  </option>
                ))}
              </select>
            </label>
            <nav className="flex items-center gap-1" aria-label="Paginacja">
              <span className="mr-1">
                Strona {meta.current_page} z {Math.max(1, meta.last_page)}
              </span>
              <button
                type="button"
                disabled={loading || meta.current_page <= 1}
                onClick={() => setFilters({ page: meta.current_page > 2 ? String(meta.current_page - 1) : null }, { keepPage: true })}
                className="rounded border border-slate-300 px-2.5 py-1 disabled:opacity-40"
              >
                ← Poprzednia
              </button>
              <button
                type="button"
                disabled={loading || meta.current_page >= meta.last_page}
                onClick={() => setFilters({ page: String(meta.current_page + 1) }, { keepPage: true })}
                className="rounded border border-slate-300 px-2.5 py-1 disabled:opacity-40"
              >
                Następna →
              </button>
            </nav>
          </div>
        )}
      </div>

      {pickFor && (
        <PickCardModal
          item={pickFor}
          onClose={() => setPickFor(null)}
          onLink={async (productId, sku) => {
            const ok = await linkManual(pickFor, productId, sku)
            if (ok) setPickFor(null)
            return ok
          }}
          error={rowErrors[pickFor.id] ?? ''}
        />
      )}
    </div>
  )
}

function SortTh({
  label,
  k,
  sort,
  dir,
  onSort,
  align,
}: {
  label: string
  k: SortKey
  sort: SortKey
  dir: SortDir
  onSort: (k: SortKey) => void
  align?: 'right'
}) {
  return (
    <th className={`p-2 font-medium ${align === 'right' ? 'text-right' : ''}`} aria-sort={ariaSort(sort, dir, [k])}>
      <SortButton label={label} k={k} sort={sort} dir={dir} onSort={onSort} />
    </th>
  )
}

function ariaSort(sort: SortKey, dir: SortDir, keys: SortKey[]): 'ascending' | 'descending' | 'none' {
  return keys.includes(sort) ? (dir === 'asc' ? 'ascending' : 'descending') : 'none'
}

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
      className={`inline-flex items-center gap-0.5 text-left hover:text-slate-900 ${active ? 'text-slate-900' : ''}`}
      title="Sortuj"
    >
      {label}
      <span className={active ? 'text-slate-700' : 'text-slate-300'} aria-hidden>
        {active ? (dir === 'asc' ? '▲' : '▼') : '↕'}
      </span>
    </button>
  )
}

/**
 * Karty powiązane z towarem (bez odrzuconych): SKU (nowa karta przeglądarki), producent, nazwa, skąd kod i dowód.
 * Przy kilku propozycjach każda karta ma „To ta” — potwierdza ją, pozostałe propozycje znikają (serwer).
 */
function LinksCell({
  item,
  canManage,
  disabled,
  onPick,
  onReject,
}: {
  item: ErpAdminItem
  canManage: boolean
  disabled: boolean
  onPick: (link: ErpAdminLink) => void
  onReject: (link: ErpAdminLink) => void
}) {
  const visible = item.links.filter((l) => l.status !== 'rejected')
  const rejected = item.links.filter((l) => l.status === 'rejected')
  const open = openLinks(item)
  const multi = open.length > 1
  const confirmedCount = item.links.filter((l) => l.status === 'confirmed' && l.product).length

  if (visible.length === 0) {
    return (
      <div className="text-[11px] text-slate-400">
        —
        {rejected.length > 0 && (
          <div title="Tych kart automat już nie zaproponuje">
            odrzucone: {rejected.map((l) => l.product?.sku ?? `#${l.id}`).join(', ')}
          </div>
        )}
      </div>
    )
  }

  return (
    <div className="min-w-[16rem] max-w-[26rem] space-y-1.5">
      {multi && (
        <p className="text-[11px] text-amber-800">
          {open.length} {plural(open.length, 'propozycja', 'propozycje', 'propozycji')} — wybierz właściwą kartę:
        </p>
      )}
      {visible.map((l) => {
        const bits = evidenceBits(l)
        const isOpen = l.status === 'auto' || l.status === 'suggested'
        return (
          <div key={l.id} className={`flex gap-2 ${multi ? 'rounded border border-slate-200 px-1.5 py-1' : ''}`}>
            <div className="min-w-0 flex-1">
              {l.product ? (
                <p className="flex flex-wrap items-baseline gap-x-1.5">
                  <Link
                    to={`/products/${l.product.id}`}
                    target="_blank"
                    rel="noopener"
                    className="font-mono text-[11px] text-blue-600 hover:underline"
                    title={`Karta #${l.product.id} — otwiera się w nowej karcie przeglądarki`}
                  >
                    {l.product.sku}
                  </Link>
                  <span className="text-[11px] text-slate-500">{l.product.manufacturer ?? 'producent nieznany'}</span>
                </p>
              ) : (
                <p className="text-[11px] text-slate-400">karta usunięta</p>
              )}
              {l.product && (
                <p className="line-clamp-2 break-words text-slate-800" title={l.product.name}>
                  {l.product.name}
                </p>
              )}
              <p className="text-[11px] text-slate-500">
                {l.matched_value ? (
                  <>
                    kod „<span className="font-mono text-slate-700">{l.matched_value}</span>” {methodLabel(l.method)}
                  </>
                ) : (
                  methodLabel(l.method)
                )}
                {bits.map((b) => (
                  <span key={b.text} className={b.warn ? 'text-amber-800' : undefined} title={b.title}>
                    {' · '}
                    {b.text}
                  </span>
                ))}
              </p>
              {/* jedna potwierdzona karta — kto i kiedy w kolumnie „Połączył”; przy kilku podpis każdej */}
              {l.status === 'confirmed' && confirmedCount > 1 && (l.decided_by || l.decided_at) && (
                <p className="text-[11px] text-slate-500">
                  potwierdził {l.decided_by ?? '—'}
                  {l.decided_at ? `, ${formatDateTime(l.decided_at)}` : ''}
                </p>
              )}
            </div>
            {multi && isOpen && canManage && (
              <div className="flex shrink-0 flex-col gap-1">
                <button
                  type="button"
                  disabled={disabled || !l.product}
                  onClick={() => onPick(l)}
                  className="rounded bg-blue-600 px-2 py-0.5 text-[11px] text-white hover:bg-blue-700 disabled:opacity-50"
                  title="Potwierdź tę kartę — pozostałe propozycje znikną"
                  aria-label={`To ta: ${l.product?.sku ?? `#${l.id}`}`}
                >
                  To ta
                </button>
                <button
                  type="button"
                  disabled={disabled}
                  onClick={() => onReject(l)}
                  className="rounded border border-slate-300 px-2 py-0.5 text-[11px] hover:bg-slate-50 disabled:opacity-50"
                  title="Odrzuć tylko tę propozycję"
                  aria-label={`Nie ta: ${l.product?.sku ?? `#${l.id}`}`}
                >
                  Nie ta
                </button>
              </div>
            )}
          </div>
        )
      })}
      {rejected.length > 0 && (
        <p className="text-[11px] text-slate-400" title="Tych kart automat już nie zaproponuje">
          odrzucone: {rejected.map((l) => l.product?.sku ?? `#${l.id}`).join(', ')}
        </p>
      )}
    </div>
  )
}

/** Okno ręcznego połączenia: wyszukiwarka kart (ProductSearchSelect) i „Połącz”. */
function PickCardModal({
  item,
  onClose,
  onLink,
  error,
}: {
  item: ErpAdminItem
  onClose: () => void
  onLink: (productId: number, sku: string) => Promise<boolean>
  error: string
}) {
  const [productId, setProductId] = useState('')
  const [sku, setSku] = useState('')
  const [busy, setBusy] = useState(false)
  const confirmed = item.links.find((l) => l.status === 'confirmed')

  useEffect(() => {
    function onKey(e: KeyboardEvent) {
      if (e.key === 'Escape' && !busy) onClose()
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [onClose, busy])

  async function submit() {
    const id = Number(productId)
    if (!id) return
    setBusy(true)
    try {
      await onLink(id, sku || `#${id}`)
    } finally {
      setBusy(false)
    }
  }

  return (
    <div
      className="fixed inset-0 z-[80] flex items-center justify-center bg-slate-950/50 p-4"
      role="dialog"
      aria-modal="true"
      aria-labelledby="erp-pick-title"
      onClick={() => {
        if (!busy) onClose()
      }}
    >
      <div className="w-full max-w-lg rounded-xl bg-white shadow-2xl" onClick={(e) => e.stopPropagation()}>
        <div className="flex items-start justify-between gap-3 border-b border-slate-100 px-4 py-3">
          <div className="min-w-0">
            <h3 id="erp-pick-title" className="text-sm font-semibold">
              Połącz towar XL z kartą
            </h3>
            <p className="text-xs text-slate-600">
              <span className="font-mono">{item.code}</span> — {item.name}
            </p>
            {item.name1 && <p className="font-mono text-[11px] text-slate-400">Nazwa1: {item.name1}</p>}
          </div>
          <button
            type="button"
            onClick={onClose}
            disabled={busy}
            className="rounded-md border border-slate-300 px-2.5 py-1 text-xs hover:bg-slate-50 disabled:opacity-50"
          >
            Zamknij
          </button>
        </div>
        <div className="space-y-2 px-4 py-3 text-xs">
          <p className="text-slate-600">
            Wpisz SKU, nazwę albo kod z karty i wybierz kartę z listy.
            {item.match_value && (
              <>
                {' '}
                Kod z nazwy XL: <span className="font-mono text-slate-800">{item.match_value}</span>.
              </>
            )}
          </p>
          <ProductSearchSelect
            products={[]}
            value={productId}
            disabled={busy}
            previewQuery={item.name}
            className="max-w-full"
            onChange={(id, p) => {
              setProductId(id)
              setSku(p?.sku ?? '')
            }}
          />
          {confirmed && (
            <p className="rounded bg-amber-50 px-2 py-1 text-[11px] text-amber-800">
              Ten towar ma już potwierdzoną kartę {confirmed.product?.sku ?? `#${confirmed.id}`}.
            </p>
          )}
          <p className="text-[11px] text-slate-500">Połączenie zapisze się jako potwierdzone ręcznie.</p>
          {error && <p className="text-[11px] text-red-700">{error}</p>}
        </div>
        <div className="flex justify-end gap-2 border-t border-slate-100 px-4 py-3">
          <button
            type="button"
            onClick={onClose}
            disabled={busy}
            className="rounded border border-slate-300 px-3 py-1.5 text-xs hover:bg-slate-50 disabled:opacity-50"
          >
            Anuluj
          </button>
          <button
            type="button"
            disabled={busy || !productId}
            onClick={() => void submit()}
            className="rounded bg-blue-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-blue-700 disabled:opacity-40"
          >
            {busy ? 'Łączę…' : 'Połącz'}
          </button>
        </div>
      </div>
    </div>
  )
}
