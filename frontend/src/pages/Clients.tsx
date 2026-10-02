import { Fragment, useEffect, useMemo, useState, type FormEvent, type ReactNode } from 'react'
import { api } from '../lib/api'
import { fmtDate, fmtDateTime } from '../lib/campaignFormat'
import { formatPln } from '../lib/campaigns'
import { sortDate, sortRows, useTableSort } from '../lib/tableSort'
import { SortTh } from '../components/CampaignsUi'

type Contact = { name?: string; position?: string; email?: string; phone?: string; mobile?: string }

type Client = {
  id: number
  name: string
  acronym: string | null
  nip: string | null
  nip_prefix: string | null
  regon: string | null
  street: string | null
  address_line2: string | null
  postal_code: string | null
  city: string | null
  county: string | null
  commune: string | null
  voivodeship: string | null
  country: string | null
  phone: string | null
  phone2: string | null
  fax: string | null
  emails: string[] | null
  website: string | null
  contacts: Contact[] | null
  account_manager: string | null
  account_manager_email: string | null
  source: 'manual' | 'erp_xl'
  xl_gid: number | null
  xl_archived: boolean
  sales_year: number | null
  sales_net: string | null
  sale_documents: number | null
  last_sale_at: string | null
  xl_synced_at: string | null
  tenders_count: number
  owner?: { name: string } | null
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

function nipText(c: Client): string | null {
  if (!c.nip) return null
  // prefiks kraju z XL tylko przy firmach zagranicznych — „PL” przy polskim NIP-ie nic nie wnosi
  return c.nip_prefix && c.nip_prefix !== 'PL' && !c.nip.startsWith(c.nip_prefix) ? `${c.nip_prefix} ${c.nip}` : c.nip
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

function Field({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div>
      <dt className="text-[11px] text-slate-500">{label}</dt>
      <dd className="break-words">{children || '—'}</dd>
    </div>
  )
}

function ClientDetails({ c }: { c: Client }) {
  const contacts = c.contacts ?? []
  return (
    <div className="grid gap-4 bg-slate-50 p-3 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)]">
      <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-xs">
        <Field label="Pełna nazwa">{c.name}</Field>
        <Field label="Akronim w XL">{c.acronym}</Field>
        <Field label="NIP">{nipText(c)}</Field>
        <Field label="REGON">{c.regon}</Field>
        <Field label="Ulica">{[c.street, c.address_line2].filter(Boolean).join(', ')}</Field>
        <Field label="Kod i miejscowość">{[c.postal_code, c.city].filter(Boolean).join(' ')}</Field>
        <Field label="Powiat / gmina">{[c.county, c.commune].filter(Boolean).join(' / ')}</Field>
        <Field label="Województwo / kraj">{[c.voivodeship, c.country].filter(Boolean).join(' / ')}</Field>
        <Field label="Telefon">{[c.phone, c.phone2].filter(Boolean).join(', ')}</Field>
        <Field label="Faks">{c.fax}</Field>
        <Field label="E-mail">
          {(c.emails ?? []).length > 0
            ? (c.emails ?? []).map((e) => (
                <a key={e} href={`mailto:${e}`} className="mr-2 text-blue-700 hover:underline">
                  {e}
                </a>
              ))
            : null}
        </Field>
        <Field label="Strona WWW">{c.website}</Field>
        <Field label="Opiekun w XL">
          {c.account_manager}
          {c.account_manager_email ? <span className="text-slate-500"> · {c.account_manager_email}</span> : null}
        </Field>
        <Field label="Opiekun w panelu">{c.owner?.name}</Field>
        {c.xl_gid != null && (
          <>
            <Field label={`Zakupy netto ${c.sales_year ?? ''}`}>
              {formatPln(salesValue(c))}
              {c.sale_documents ? <span className="text-slate-500"> · {c.sale_documents} dok.</span> : null}
            </Field>
            <Field label="Odczyt z ERP XL">
              {fmtDateTime(c.xl_synced_at)} <span className="text-slate-500">(kontrahent nr {c.xl_gid})</span>
            </Field>
          </>
        )}
      </dl>
      <div className="text-xs">
        <h3 className="mb-1 text-[11px] text-slate-500">Osoby kontaktowe ({contacts.length})</h3>
        {contacts.length === 0 ? (
          <p className="text-slate-500">Brak osób kontaktowych na karcie XL.</p>
        ) : (
          <table className="w-full text-left">
            <thead>
              <tr className="border-b text-slate-500">
                <th className="py-1 pr-2 font-normal">Osoba</th>
                <th className="py-1 pr-2 font-normal">Stanowisko</th>
                <th className="py-1 pr-2 font-normal">E-mail</th>
                <th className="py-1 font-normal">Telefon</th>
              </tr>
            </thead>
            <tbody>
              {contacts.map((p, i) => (
                <tr key={i} className="border-b border-slate-200 align-top">
                  <td className="py-1 pr-2">{p.name ?? '—'}</td>
                  <td className="py-1 pr-2">{p.position ?? '—'}</td>
                  <td className="py-1 pr-2">
                    {p.email ? (
                      <a href={`mailto:${p.email}`} className="text-blue-700 hover:underline">
                        {p.email}
                      </a>
                    ) : (
                      '—'
                    )}
                  </td>
                  <td className="py-1">{[p.phone, p.mobile].filter(Boolean).join(', ') || '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </div>
  )
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
                      onClick={() => setExpanded(isOpen ? null : c.id)}
                      aria-expanded={isOpen}
                    >
                      <td className="p-2">
                        <span className="mr-1 text-slate-400">{isOpen ? '▾' : '▸'}</span>
                        <span className="font-medium">{c.name}</span>
                        {c.acronym && c.acronym !== c.name && <div className="pl-4 text-slate-500">{c.acronym}</div>}
                        {c.xl_archived && (
                          <span className="ml-4 rounded bg-amber-100 px-1 text-[10px] text-amber-800">archiwalny w XL</span>
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
                          <ClientDetails c={c} />
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
