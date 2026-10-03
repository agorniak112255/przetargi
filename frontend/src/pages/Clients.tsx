import { Fragment, useEffect, useMemo, useRef, useState, type FormEvent, type MouseEvent } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useAuth } from '../auth'
import { api, can, fetchClientCard, type ClientRecord } from '../lib/api'
import { fmtDate } from '../lib/campaignFormat'
import { formatPln } from '../lib/campaigns'
import { sortDate, sortRows, useTableSort } from '../lib/tableSort'
import { SortTh } from '../components/CampaignsUi'
import { ClientFields } from '../components/client/ClientFields'
import { ClientOwnerPicker, type OwnerOption } from '../components/client/ClientOwnerPicker'
import { nipText } from '../components/client/clientText'

/** Wiersz listy (GET /clients?details=1): pełne dane klienta, liczba przetargów i opiekun w aplikacji. */
type Client = ClientRecord & {
  tenders_count: number
  owner?: OwnerOption | null
}

type SortKey = 'name' | 'nip' | 'city' | 'manager' | 'sales' | 'last_sale' | 'tenders'
type SourceFilter = 'all' | 'xl' | 'manual'

/** Filtr opiekuna: klient bez opiekuna w XL i w panelu. */
const NO_MANAGER = '__none'

/** Opiekun z XL, a gdy go brak — opiekun w panelu (jak kolumna „Opiekun”). */
function managerName(c: Client): string | null {
  return c.account_manager ?? c.owner?.name ?? null
}

/**
 * Klucz miejscowości do filtra: XL zapisuje tę samą miejscowość różnie („DĄBROWA GÓRNICZA”, „Dąbrowa Górnicza”,
 * „Dabrowa Gornicza”) — wielkie litery bez polskich znaków, jak CustomersReport::cityKey.
 */
function cityKey(city: string | null): string | null {
  const trimmed = (city ?? '').trim().replace(/\s+/g, ' ')
  if (!trimmed) return null
  return trimmed
    .toLocaleUpperCase('pl')
    .replace(/Ł/g, 'L')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
}

type Option = { value: string; label: string; count: number }

/** Opcje miejscowości: na klucz pisownia najczęstsza (remis — z małymi literami, potem z polskimi znakami). */
function cityOptions(rows: Client[]): Option[] {
  const groups = new Map<string, Map<string, number>>()
  for (const c of rows) {
    const key = cityKey(c.city)
    if (!key) continue
    const spelling = (c.city ?? '').trim().replace(/\s+/g, ' ')
    const spellings = groups.get(key) ?? new Map<string, number>()
    spellings.set(spelling, (spellings.get(spelling) ?? 0) + 1)
    groups.set(key, spellings)
  }
  const score = (s: string) => (s !== s.toLocaleUpperCase('pl') ? 2 : 0) + (cityKey(s) !== s.toLocaleUpperCase('pl') ? 1 : 0)
  return [...groups.entries()]
    .map(([value, spellings]) => {
      const sorted = [...spellings.entries()].sort((a, b) => b[1] - a[1] || score(b[0]) - score(a[0]))
      return { value, label: sorted[0][0], count: sorted.reduce((sum, [, n]) => sum + n, 0) }
    })
    .sort((a, b) => a.label.localeCompare(b.label, 'pl', { sensitivity: 'base' }))
}

function managerOptions(rows: Client[]): Option[] {
  const counts = new Map<string, number>()
  for (const c of rows) {
    const name = managerName(c)
    if (name) counts.set(name, (counts.get(name) ?? 0) + 1)
  }
  return [...counts.entries()]
    .map(([value, count]) => ({ value, label: value, count }))
    .sort((a, b) => a.label.localeCompare(b.label, 'pl', { sensitivity: 'base' }))
}

function salesValue(c: Client): number | null {
  return c.sales_net == null ? null : Number(c.sales_net)
}

function addressLine(c: Client): string {
  const street = [c.street, c.address_line2].filter(Boolean).join(', ')
  const town = [c.postal_code, c.city].filter(Boolean).join(' ')
  return [street, town].filter(Boolean).join(', ')
}

function matches(c: Client, q: string): boolean {
  if (!q) return true
  const hay = [
    c.name,
    c.acronym,
    c.nip,
    c.regon,
    c.city,
    c.street,
    c.voivodeship,
    c.phone,
    c.phone2,
    c.account_manager,
    ...(c.emails ?? []),
    ...(c.contacts ?? []).flatMap((p) => [p.name, p.email, p.phone, p.mobile]),
  ]
    .filter(Boolean)
    .join(' ')
    .toLocaleLowerCase('pl')
  const digits = q.replace(/\D+/g, '')
  return q
    .toLocaleLowerCase('pl')
    .split(/\s+/)
    .filter(Boolean)
    .every((word) => hay.includes(word)) || (digits.length >= 5 && (c.nip ?? '').replace(/\D+/g, '').includes(digits))
}

export function Clients() {
  const [rows, setRows] = useState<Client[]>([])
  const [loaded, setLoaded] = useState(false)
  const [open, setOpen] = useState(false)
  const [name, setName] = useState('')
  const [nip, setNip] = useState('')
  const [city, setCity] = useState('')
  const [err, setErr] = useState('')
  const [msg, setMsg] = useState('')
  const [busy, setBusy] = useState(false)
  const [q, setQ] = useState('')
  const [source, setSource] = useState<SourceFilter>('all')
  const [cityFilter, setCityFilter] = useState('')
  const [managerFilter, setManagerFilter] = useState('')
  const [expanded, setExpanded] = useState<number | null>(null)
  const navigate = useNavigate()
  const { user } = useAuth()
  const canManage = can(user, 'clients.manage')
  // lista osób do wyboru opiekuna — raz na wizytę, z karty klienta (serwer podaje ją tylko przy clients.manage)
  const ownerOptions = useRef<Promise<OwnerOption[]> | null>(null)
  const [sort, toggleSort] = useTableSort<SortKey>(['sales', 'last_sale', 'tenders'], { key: 'sales', dir: 'desc' })

  async function load() {
    try {
      setRows(await api<Client[]>('/clients?details=1'))
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Nie udało się wczytać klientów')
    } finally {
      setLoaded(true)
    }
  }

  useEffect(() => {
    void load()
  }, [])

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

  const xlCount = rows.filter((c) => c.xl_gid != null).length
  const salesYear = rows.find((c) => c.sales_year != null)?.sales_year ?? null
  const cities = useMemo(() => cityOptions(rows), [rows])
  const managers = useMemo(() => managerOptions(rows), [rows])
  const withoutManager = rows.filter((c) => managerName(c) == null).length
  const visible = useMemo(() => {
    const query = q.trim()
    const filtered = rows.filter(
      (c) =>
        (source === 'all' || (source === 'xl') === (c.xl_gid != null)) &&
        (!cityFilter || cityKey(c.city) === cityFilter) &&
        (!managerFilter || (managerFilter === NO_MANAGER ? managerName(c) == null : managerName(c) === managerFilter)) &&
        matches(c, query),
    )
    return sortRows(filtered, sort, (c, key) => {
      switch (key) {
        case 'name':
          return c.name
        case 'nip':
          return c.nip
        case 'city':
          return c.city
        case 'manager':
          return managerName(c)
        case 'sales':
          return salesValue(c)
        case 'last_sale':
          return sortDate(c.last_sale_at)
        case 'tenders':
          return c.tenders_count
      }
    })
  }, [rows, q, source, cityFilter, managerFilter, sort])

  return (
    <div>
      <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold">Klienci</h1>
          {loaded && (
            <p className="text-xs text-slate-500">
              {rows.length} klientów, w tym {xlCount} z ERP XL
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
            value={q}
            onChange={(e) => setQ(e.target.value)}
            placeholder="Szukaj: nazwa, NIP, miasto, telefon, e-mail, osoba…"
            className="w-full max-w-md rounded border border-slate-300 px-2 py-1.5"
          />
          <select
            value={source}
            onChange={(e) => setSource(e.target.value as SourceFilter)}
            className="rounded border border-slate-300 px-2 py-1.5"
            aria-label="Źródło klienta"
          >
            <option value="all">Wszyscy</option>
            <option value="xl">Z ERP XL</option>
            <option value="manual">Dopisani ręcznie</option>
          </select>
          <select
            value={cityFilter}
            onChange={(e) => setCityFilter(e.target.value)}
            className="max-w-[14rem] rounded border border-slate-300 px-2 py-1.5"
            aria-label="Miejscowość"
          >
            <option value="">Wszystkie miejscowości</option>
            {cities.map((o) => (
              <option key={o.value} value={o.value}>
                {o.label} ({o.count})
              </option>
            ))}
          </select>
          <select
            value={managerFilter}
            onChange={(e) => setManagerFilter(e.target.value)}
            className="max-w-[14rem] rounded border border-slate-300 px-2 py-1.5"
            aria-label="Opiekun"
          >
            <option value="">Wszyscy opiekunowie</option>
            {withoutManager > 0 && <option value={NO_MANAGER}>Bez opiekuna ({withoutManager})</option>}
            {managers.map((o) => (
              <option key={o.value} value={o.value}>
                {o.label} ({o.count})
              </option>
            ))}
          </select>
          {(cityFilter || managerFilter || source !== 'all' || q) && (
            <button
              type="button"
              onClick={() => {
                setQ('')
                setSource('all')
                setCityFilter('')
                setManagerFilter('')
              }}
              className="text-blue-700 hover:underline"
            >
              Wyczyść filtry
            </button>
          )}
          <span className="text-slate-500">
            {visible.length === rows.length ? `${rows.length} pozycji` : `${visible.length} z ${rows.length}`}
          </span>
        </div>
        <div className="overflow-x-auto">
          <table className="w-full text-left text-xs">
            <thead>
              <tr className="border-b bg-slate-50">
                <SortTh label="Nazwa" k="name" sort={sort} onSort={toggleSort} />
                <SortTh label="NIP" k="nip" sort={sort} onSort={toggleSort} />
                <SortTh label="Adres" k="city" sort={sort} onSort={toggleSort} />
                <th className="p-2">Telefon</th>
                <th className="p-2">E-mail</th>
                <SortTh label="Opiekun" k="manager" sort={sort} onSort={toggleSort} />
                <SortTh
                  label={salesYear ? `Zakupy netto ${salesYear}` : 'Zakupy netto'}
                  k="sales"
                  sort={sort}
                  onSort={toggleSort}
                  align="right"
                />
                <SortTh label="Ostatnia faktura" k="last_sale" sort={sort} onSort={toggleSort} />
                <SortTh label="Przetargi" k="tenders" sort={sort} onSort={toggleSort} align="right" />
              </tr>
            </thead>
            <tbody>
              {visible.map((c) => {
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
                                onSaved={(saved) =>
                                  setRows((list) =>
                                    list.map((row) =>
                                      row.id === c.id ? { ...row, owner_id: saved.owner_id, owner: saved.owner ?? null } : row,
                                    ),
                                  )
                                }
                              />
                            }
                          />
                        </td>
                      </tr>
                    )}
                  </Fragment>
                )
              })}
              {loaded && visible.length === 0 && (
                <tr>
                  <td colSpan={9} className="p-4 text-center text-slate-500">
                    {rows.length === 0 ? 'Brak klientów.' : 'Brak klientów pasujących do wyszukiwania.'}
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  )
}
