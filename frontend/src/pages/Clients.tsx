import { Fragment, useCallback, useEffect, useMemo, useRef, useState, type FormEvent, type MouseEvent } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { useAuth } from '../auth'
import { api, can, fetchClientCard, type ClientRecord } from '../lib/api'
import { fmtDate } from '../lib/campaignFormat'
import { formatPln, type PageMeta } from '../lib/campaigns'
import type { SortDir } from '../lib/tableSort'
import { Pager, SortTh } from '../components/CampaignsUi'
import { ClientFields } from '../components/client/ClientFields'
import { ClientOwnerPicker, type OwnerOption } from '../components/client/ClientOwnerPicker'
import { nipText } from '../components/client/clientText'

/** Wiersz listy (GET /clients?page=…): pełne dane klienta, liczba przetargów i opiekun w aplikacji. */
type Client = ClientRecord & {
  tenders_count: number
  owner?: OwnerOption | null
}

type Option = { value: string; label: string; count: number }

/** Strona listy; summary — liczniki i opcje filtrów z całej listy (ClientList na serwerze). */
type ClientPage = {
  data: Client[]
  meta: PageMeta
  summary: {
    total: number
    xl: number
    without_manager: number
    sales_year: number | null
    cities: Option[]
    managers: Option[]
  }
}

type SortKey = 'name' | 'nip' | 'city' | 'manager' | 'sales' | 'last_sale' | 'tenders'
type SourceFilter = 'all' | 'xl' | 'manual'

const SORT_KEYS: readonly SortKey[] = ['name', 'nip', 'city', 'manager', 'sales', 'last_sale', 'tenders']
/** Liczby i daty po kliknięciu najpierw malejąco, teksty rosnąco. */
const DESC_FIRST: readonly SortKey[] = ['sales', 'last_sale', 'tenders']
const DEFAULT_SORT: SortKey = 'sales'
const PER_PAGE_OPTIONS = ['25', '50', '100', '200'] as const
const DEFAULT_PER_PAGE = '50'
const SEARCH_DEBOUNCE_MS = 300

/** Filtr opiekuna: klient bez opiekuna w XL i w panelu (ClientList::NO_MANAGER). */
const NO_MANAGER = '__none'

function pick<T extends string>(value: string | null, allowed: readonly T[], fallback: T): T {
  return value !== null && (allowed as readonly string[]).includes(value) ? (value as T) : fallback
}

function defaultDir(key: SortKey): SortDir {
  return DESC_FIRST.includes(key) ? 'desc' : 'asc'
}

function salesValue(c: Client): number | null {
  return c.sales_net == null ? null : Number(c.sales_net)
}

function addressLine(c: Client): string {
  const street = [c.street, c.address_line2].filter(Boolean).join(', ')
  const town = [c.postal_code, c.city].filter(Boolean).join(' ')
  return [street, town].filter(Boolean).join(', ')
}

export function Clients() {
  const [params, setParams] = useSearchParams()
  const q = params.get('q') ?? ''
  const source = pick<SourceFilter>(params.get('source'), ['all', 'xl', 'manual'], 'all')
  const cityFilter = params.get('city') ?? ''
  const managerFilter = params.get('manager') ?? ''
  const sortKey = pick<SortKey>(params.get('sort'), SORT_KEYS, DEFAULT_SORT)
  const dir = pick<SortDir>(params.get('dir'), ['asc', 'desc'], defaultDir(sortKey))
  const page = Math.max(1, Math.floor(Number(params.get('page'))) || 1)
  const perPage = pick(params.get('per_page'), PER_PAGE_OPTIONS, DEFAULT_PER_PAGE)

  const apiQuery = useMemo(() => {
    const qs = new URLSearchParams()
    qs.set('page', String(page))
    qs.set('per_page', perPage)
    if (q.trim()) qs.set('q', q.trim())
    if (source !== 'all') qs.set('source', source)
    if (cityFilter) qs.set('city', cityFilter)
    if (managerFilter) qs.set('manager', managerFilter)
    qs.set('sort', sortKey)
    qs.set('dir', dir)
    return qs.toString()
  }, [page, perPage, q, source, cityFilter, managerFilter, sortKey, dir])

  const [result, setResult] = useState<ClientPage | null>(null)
  const [loading, setLoading] = useState(false)
  const seq = useRef(0)
  const [open, setOpen] = useState(false)
  const [name, setName] = useState('')
  const [nip, setNip] = useState('')
  const [city, setCity] = useState('')
  const [err, setErr] = useState('')
  const [msg, setMsg] = useState('')
  const [busy, setBusy] = useState(false)
  const [expanded, setExpanded] = useState<number | null>(null)
  const navigate = useNavigate()
  const { user } = useAuth()
  const canManage = can(user, 'clients.manage')
  // lista osób do wyboru opiekuna — raz na wizytę, z karty klienta (serwer podaje ją tylko przy clients.manage)
  const ownerOptions = useRef<Promise<OwnerOption[]> | null>(null)

  // Pole wyszukiwania: wpis od razu w polu, do adresu (i zapytania) po 300 ms bez pisania — jak Zapasy.
  const [searchInput, setSearchInput] = useState(q)
  const pushedSearch = useRef(q)

  /** Zmiana filtra = strona 1 (chyba że keepPage); pusta wartość usuwa parametr z adresu. */
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
    const t = window.setTimeout(() => {
      pushedSearch.current = searchInput
      setFilters({ q: searchInput }, { replace: true })
    }, SEARCH_DEBOUNCE_MS)
    return () => window.clearTimeout(t)
  }, [searchInput, setFilters])

  const load = useCallback(async () => {
    const my = ++seq.current
    setLoading(true)
    setErr('')
    try {
      const res = await api<ClientPage>(`/clients?${apiQuery}`)
      // szybkie klikanie filtrów — spóźniona odpowiedź nie nadpisuje nowszej
      if (my !== seq.current) return
      setResult(res)
    } catch (ex) {
      if (my === seq.current) setErr(ex instanceof Error ? ex.message : 'Nie udało się wczytać klientów')
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
    const nextDir: SortDir = key === sortKey ? (dir === 'asc' ? 'desc' : 'asc') : defaultDir(key)
    setFilters({ sort: key === DEFAULT_SORT ? null : key, dir: nextDir === defaultDir(key) ? null : nextDir })
  }

  function goToPage(n: number) {
    setFilters({ page: n > 1 ? String(n) : null }, { keepPage: true })
  }

  function clearFilters() {
    setParams((prev) => {
      const next = new URLSearchParams()
      for (const keep of ['sort', 'dir', 'per_page']) {
        const v = prev.get(keep)
        if (v) next.set(keep, v)
      }
      return next
    })
  }

  function loadOwnerOptions(clientId: number): Promise<OwnerOption[]> {
    ownerOptions.current ??= fetchClientCard(clientId).then((card) => card.owner_options ?? [])
    const pending = ownerOptions.current
    // po błędzie kolejna próba pyta serwer od nowa
    pending.catch(() => {
      if (ownerOptions.current === pending) ownerOptions.current = null
    })
    return pending
  }

  /** Kliknięcie w wiersz otwiera kartę klienta (klawiaturą — link w nazwie); przyciski i linki w wierszu działają po swojemu. */
  function openCard(e: MouseEvent<HTMLTableRowElement>, id: number) {
    if (e.target instanceof Element && e.target.closest('a, button, input, select, textarea')) return
    if (window.getSelection()?.toString()) return
    // nowa karta przeglądarki — przez link w nazwie (Ctrl + kliknięcie działa tam samo)
    if (e.ctrlKey || e.metaKey || e.shiftKey) return
    navigate(`/clients/${id}`)
  }

  async function onCreate(e: FormEvent) {
    e.preventDefault()
    setBusy(true)
    setErr('')
    setMsg('')
    try {
      await api('/clients', {
        method: 'POST',
        body: JSON.stringify({
          name,
          nip: nip || null,
          city: city || null,
        }),
      })
      setName('')
      setNip('')
      setCity('')
      setOpen(false)
      setMsg('Klient dodany.')
      await load()
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Błąd zapisu')
    } finally {
      setBusy(false)
    }
  }

  const rows = result?.data ?? []
  const meta = result?.meta ?? null
  const summary = result?.summary ?? null
  const salesYear = summary?.sales_year ?? null
  const filtered = Boolean(q || source !== 'all' || cityFilter || managerFilter)
  const sort = { key: sortKey, dir }

  return (
    <div>
      <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold">Klienci</h1>
          {summary && (
            <p className="text-xs text-slate-500">
              {summary.total} klientów, w tym {summary.xl} z ERP XL
              {salesYear ? ` — zakupy netto za ${salesYear} r., odświeżane co noc` : ''}
            </p>
          )}
        </div>
        <button
          type="button"
          onClick={() => setOpen((v) => !v)}
          className="rounded bg-blue-600 px-3 py-2 text-xs text-white hover:bg-blue-700"
        >
          + Nowy klient
        </button>
      </div>

      {msg && <p className="mb-2 rounded bg-green-50 px-3 py-2 text-xs text-green-800">{msg}</p>}
      {err && <p className="mb-2 rounded bg-red-50 px-3 py-2 text-xs text-red-700">{err}</p>}

      {open && (
        <form onSubmit={onCreate} className="mb-4 rounded-xl bg-white p-4 shadow-sm text-sm">
          <h2 className="mb-3 font-semibold">Nowy klient</h2>
          <div className="grid gap-3 sm:grid-cols-3">
            <label className="block text-xs">
              Nazwa *
              <input
                required
                className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5"
                value={name}
                onChange={(e) => setName(e.target.value)}
                placeholder="np. Firma Sp. z o.o."
              />
            </label>
            <label className="block text-xs">
              NIP
              <input
                className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5"
                value={nip}
                onChange={(e) => setNip(e.target.value)}
                placeholder="0000000000"
              />
            </label>
            <label className="block text-xs">
              Miasto
              <input
                className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5"
                value={city}
                onChange={(e) => setCity(e.target.value)}
                placeholder="Rzeszów"
              />
            </label>
          </div>
          <button
            type="submit"
            disabled={busy}
            className="mt-3 rounded bg-blue-600 px-3 py-2 text-xs text-white disabled:opacity-50"
          >
            Zapisz klienta
          </button>
        </form>
      )}

      <div className="rounded-xl bg-white p-4 shadow-sm">
        <div className="mb-3 flex flex-wrap items-center gap-2 text-xs">
          <input
            type="search"
            value={searchInput}
            onChange={(e) => setSearchInput(e.target.value)}
            placeholder="Szukaj: nazwa, NIP, miasto, telefon, e-mail, osoba…"
            className="w-full max-w-md rounded border border-slate-300 px-2 py-1.5"
          />
          <select
            value={source}
            onChange={(e) => setFilters({ source: e.target.value === 'all' ? null : e.target.value })}
            className="rounded border border-slate-300 px-2 py-1.5"
            aria-label="Źródło klienta"
          >
            <option value="all">Wszyscy</option>
            <option value="xl">Z ERP XL</option>
            <option value="manual">Dopisani ręcznie</option>
          </select>
          <select
            value={cityFilter}
            onChange={(e) => setFilters({ city: e.target.value })}
            className="max-w-[14rem] rounded border border-slate-300 px-2 py-1.5"
            aria-label="Miejscowość"
          >
            <option value="">Wszystkie miejscowości</option>
            {cityFilter && !summary?.cities.some((o) => o.value === cityFilter) && <option value={cityFilter}>{cityFilter}</option>}
            {(summary?.cities ?? []).map((o) => (
              <option key={o.value} value={o.value}>
                {o.label} ({o.count})
              </option>
            ))}
          </select>
          <select
            value={managerFilter}
            onChange={(e) => setFilters({ manager: e.target.value })}
            className="max-w-[14rem] rounded border border-slate-300 px-2 py-1.5"
            aria-label="Opiekun"
          >
            <option value="">Wszyscy opiekunowie</option>
            {summary && summary.without_manager > 0 && (
              <option value={NO_MANAGER}>Bez opiekuna ({summary.without_manager})</option>
            )}
            {managerFilter && managerFilter !== NO_MANAGER && !summary?.managers.some((o) => o.value === managerFilter) && (
              <option value={managerFilter}>{managerFilter}</option>
            )}
            {(summary?.managers ?? []).map((o) => (
              <option key={o.value} value={o.value}>
                {o.label} ({o.count})
              </option>
            ))}
          </select>
          {(filtered || searchInput) && (
            <button
              type="button"
              onClick={() => {
                setSearchInput('')
                clearFilters()
              }}
              className="text-blue-700 hover:underline"
            >
              Wyczyść filtry
            </button>
          )}
          {meta && summary && (
            <span className="text-slate-500">{filtered ? `${meta.total} z ${summary.total}` : `${meta.total} pozycji`}</span>
          )}
          <label className="ml-auto inline-flex items-center gap-1 text-slate-500">
            <select
              className="rounded border border-slate-300 bg-white px-1.5 py-1"
              value={perPage}
              onChange={(e) => setFilters({ per_page: e.target.value === DEFAULT_PER_PAGE ? null : e.target.value })}
              title="Ile klientów na stronie"
            >
              {PER_PAGE_OPTIONS.map((n) => (
                <option key={n} value={n}>
                  {n}
                </option>
              ))}
            </select>
            na stronie
          </label>
        </div>
        <Pager meta={meta} disabled={loading} onPage={goToPage} />
        <div className={`overflow-x-auto ${loading && result ? 'opacity-60' : ''}`}>
          <table className="w-full text-left text-xs">
            <thead>
              <tr className="border-b bg-slate-50">
                <SortTh label="Nazwa" k="name" sort={sort} onSort={clickSort} />
                <SortTh label="NIP" k="nip" sort={sort} onSort={clickSort} />
                <SortTh label="Adres" k="city" sort={sort} onSort={clickSort} />
                <th className="p-2">Telefon</th>
                <th className="p-2">E-mail</th>
                <SortTh label="Opiekun" k="manager" sort={sort} onSort={clickSort} />
                <SortTh
                  label={salesYear ? `Zakupy netto ${salesYear}` : 'Zakupy netto'}
                  k="sales"
                  sort={sort}
                  onSort={clickSort}
                  align="right"
                />
                <SortTh label="Ostatnia faktura" k="last_sale" sort={sort} onSort={clickSort} />
                <SortTh label="Przetargi" k="tenders" sort={sort} onSort={clickSort} align="right" />
              </tr>
            </thead>
            <tbody>
              {rows.map((c) => {
                const isOpen = expanded === c.id
                const emails = c.emails ?? []
                return (
                  <Fragment key={c.id}>
                    <tr
                      className={`cursor-pointer border-b align-top hover:bg-slate-50 ${isOpen ? 'bg-slate-50' : ''}`}
                      onClick={(e) => openCard(e, c.id)}
                    >
                      <td className="p-2">
                        <button
                          type="button"
                          onClick={() => setExpanded(isOpen ? null : c.id)}
                          aria-expanded={isOpen}
                          aria-label={isOpen ? `Zwiń dane klienta ${c.name}` : `Rozwiń dane klienta ${c.name}`}
                          title={isOpen ? 'Zwiń dane klienta' : 'Rozwiń dane klienta i osoby kontaktowe'}
                          className="mr-1 rounded px-0.5 text-slate-400 hover:bg-slate-200 hover:text-slate-700"
                        >
                          {isOpen ? '▾' : '▸'}
                        </button>
                        <Link to={`/clients/${c.id}`} className="font-medium text-blue-700 hover:underline">
                          {c.name}
                        </Link>
                        {c.acronym && c.acronym !== c.name && <div className="pl-4 text-slate-500">{c.acronym}</div>}
                        {c.xl_archived && (
                          <span className="ml-4 rounded bg-amber-100 px-1 text-[10px] text-amber-800">archiwalny w ERP XL</span>
                        )}
                      </td>
                      <td className="whitespace-nowrap p-2">{nipText(c) ?? '—'}</td>
                      <td className="p-2">
                        {addressLine(c) || c.city || '—'}
                        {c.voivodeship && <div className="text-slate-500">woj. {c.voivodeship}</div>}
                      </td>
                      <td className="whitespace-nowrap p-2">{c.phone ?? c.phone2 ?? '—'}</td>
                      <td className="p-2">
                        {emails[0] ?? '—'}
                        {emails.length > 1 && <span className="text-slate-500"> +{emails.length - 1}</span>}
                      </td>
                      <td className="p-2">{c.account_manager ?? c.owner?.name ?? '—'}</td>
                      <td className="whitespace-nowrap p-2 text-right">{c.xl_gid != null ? formatPln(salesValue(c)) : '—'}</td>
                      <td className="whitespace-nowrap p-2">{fmtDate(c.last_sale_at)}</td>
                      <td className="p-2 text-right">{c.tenders_count}</td>
                    </tr>
                    {isOpen && (
                      <tr className="border-b">
                        <td colSpan={9} className="p-0">
                          <ClientFields
                            c={c}
                            owner={
                              <ClientOwnerPicker
                                clientId={c.id}
                                owner={c.owner ?? null}
                                canManage={canManage}
                                loadOptions={() => loadOwnerOptions(c.id)}
                                onSaved={(saved) => {
                                  setResult((prev) =>
                                    prev && {
                                      ...prev,
                                      data: prev.data.map((row) =>
                                        row.id === c.id ? { ...row, owner_id: saved.owner_id, owner: saved.owner ?? null } : row,
                                      ),
                                    },
                                  )
                                  // liczniki opiekunów w filtrze liczy serwer
                                  void load()
                                }}
                              />
                            }
                          />
                        </td>
                      </tr>
                    )}
                  </Fragment>
                )
              })}
              {result && rows.length === 0 && (
                <tr>
                  <td colSpan={9} className="p-4 text-center text-slate-500">
                    {summary?.total === 0 ? 'Brak klientów.' : 'Brak klientów pasujących do wyszukiwania.'}
                  </td>
                </tr>
              )}
              {!result && loading && (
                <tr>
                  <td colSpan={9} className="p-4 text-center text-slate-500">
                    Wczytywanie klientów…
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
        <Pager meta={meta} disabled={loading} onPage={goToPage} />
      </div>
    </div>
  )
}
