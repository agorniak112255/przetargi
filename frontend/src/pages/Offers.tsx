import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useAuth } from '../auth'
import { BTN, BTN_PRIMARY, BTN_SM, Chip, ConfirmDialog, ErrorBar, INPUT } from '../components/CampaignsUi'
import { can } from '../lib/api'
import { errorText, fmtDateTime, fmtInt } from '../lib/campaignFormat'
import { foldText } from '../lib/chat'
import { createOffer, deleteOffer, listOffers, type OfferKind, type OfferListRow } from '../lib/offers'
import { plural } from '../lib/plural'

/**
 * Oferty: lista ofert dla klientów (wybrane produkty z ceną) — wysyłka ze skrzynki „Moja poczta” albo
 * nowa wiadomość w Thunderbirdzie (dodatek). Oferta jest zawsze edytowalna przez autora; historia wysyłek jest w ofercie.
 * Oferty przeglądu (kind = inspection) przygotowuje moduł Przeglądy — tu są na tej samej liście, ze znacznikiem.
 * Widoczność (06.10.2026): własne oferty, a z uprawnieniem w roli także oferty innych osób — te tylko do podglądu
 * (can_edit = false; serwer odrzuca zmianę, wysyłkę i usunięcie).
 * Wyszukiwanie, filtry i sortowanie liczone w przeglądarce na liście z serwera (bez zapamiętywania).
 */

type ColKey = 'offer' | 'kind' | 'customer' | 'author' | 'items' | 'recipients' | 'last_sent' | 'updated'
type SortDir = 'asc' | 'desc'
type SentFilter = '' | 'sent' | 'unsent'

const KIND_LABEL: Record<OfferKind, string> = { products: 'Produkty', inspection: 'Przegląd' }
/** Wartość filtra Autora dla ofert bez autora (konto usunięte). */
const NO_AUTHOR = 'none'

/** Kolumny z sortowaniem; liczby i daty zaczynają od malejąco (najnowsze, największe), tekst od rosnąco. */
const COLUMNS: { key: ColKey; label: string; title?: string; right?: boolean; firstDir: SortDir }[] = [
  { key: 'offer', label: 'Oferta', firstDir: 'asc' },
  { key: 'kind', label: 'Rodzaj', firstDir: 'asc' },
  { key: 'customer', label: 'Klient', title: 'Klient oferty przeglądu (oferty z produktami nie mają klienta)', firstDir: 'asc' },
  { key: 'author', label: 'Autor', title: 'Osoba, która przygotowała ofertę i wysyła ją ze swojej skrzynki', firstDir: 'asc' },
  { key: 'items', label: 'Pozycje', right: true, firstDir: 'desc' },
  {
    key: 'recipients',
    label: 'Wysłano do',
    title: 'Adresy, do których mail wyszedł (wszystkie wysyłki razem)',
    right: true,
    firstDir: 'desc',
  },
  { key: 'last_sent', label: 'Ostatnia wysyłka', firstDir: 'desc' },
  { key: 'updated', label: 'Zmieniona', firstDir: 'desc' },
]

type TextFilters = { offer: string; customer: string; items: string; last_sent: string; updated: string }
const EMPTY_TEXT_FILTERS: TextFilters = { offer: '', customer: '', items: '', last_sent: '', updated: '' }

function subjectText(r: OfferListRow): string {
  return r.subject.trim() || 'bez tematu'
}

/** Klient tylko przy ofercie przeglądu; oferta z produktami — „—”. */
function customerText(r: OfferListRow): string {
  return r.kind === 'inspection' && r.customer_name ? r.customer_name : '—'
}

function recipientsText(r: OfferListRow): string {
  return r.recipients_count > 0
    ? `${fmtInt(r.recipients_count)} ${plural(r.recipients_count, 'adresu', 'adresów', 'adresów')}`
    : '—'
}

/** Teksty kolumn w formie wyświetlanej — do filtrów „zawiera” i wyszukiwania. */
function displayTexts(r: OfferListRow): Record<ColKey, string> {
  return {
    offer: `${subjectText(r)} ${r.code ?? ''}`,
    kind: KIND_LABEL[r.kind],
    customer: customerText(r),
    author: r.author ? `${r.author.name} ${r.author.email}` : '—',
    items: fmtInt(r.items_count),
    recipients: `${recipientsText(r)} ${r.recipients_count} ${r.recipient_emails.join(' ')}`,
    last_sent: fmtDateTime(r.last_sent_at),
    updated: fmtDateTime(r.updated_at),
  }
}

function dateValue(iso: string | null): number | null {
  if (!iso) return null
  const t = Date.parse(iso)
  return Number.isNaN(t) ? null : t
}

/** Wartość do sortowania; null = brak (zawsze na końcu, w obu kierunkach). */
function sortValue(r: OfferListRow, key: ColKey): string | number | null {
  switch (key) {
    case 'offer':
      return r.subject.trim() || null
    case 'kind':
      return KIND_LABEL[r.kind]
    case 'customer':
      return customerText(r) === '—' ? null : customerText(r)
    case 'author':
      return r.author?.name ?? null
    case 'items':
      return r.items_count
    case 'recipients':
      return r.recipients_count
    case 'last_sent':
      return dateValue(r.last_sent_at)
    case 'updated':
      return dateValue(r.updated_at)
  }
}

function compareRows(a: OfferListRow, b: OfferListRow, key: ColKey, dir: SortDir): number {
  const va = sortValue(a, key)
  const vb = sortValue(b, key)
  if (va === null || vb === null) {
    if (va !== vb) return va === null ? 1 : -1
  } else {
    const cmp = typeof va === 'number' && typeof vb === 'number' ? va - vb : String(va).localeCompare(String(vb), 'pl')
    if (cmp !== 0) return dir === 'asc' ? cmp : -cmp
  }
  // remis: najpierw kod oferty (ten sam kierunek), potem nowsza oferta wyżej
  if (key === 'offer') {
    const cmp = (a.code ?? '').localeCompare(b.code ?? '', 'pl')
    if (cmp !== 0) return dir === 'asc' ? cmp : -cmp
  }
  return b.id - a.id
}

function SortMark({ active, dir }: { active: boolean; dir: SortDir }) {
  if (!active) return <span className="ml-1 text-slate-300">↕</span>
  return <span className="ml-1 text-blue-600">{dir === 'asc' ? '↑' : '↓'}</span>
}

export function Offers() {
  const navigate = useNavigate()
  const { user } = useAuth()
  // nowa oferta produktowa tylko z „Oferty dla klientów”; oferty przeglądu powstają w module Przeglądy
  const canProducts = can(user, 'offers.use')
  const canInspections = can(user, 'inspections.offer')
  const [rows, setRows] = useState<OfferListRow[]>([])
  const [loading, setLoading] = useState(true)
  const [err, setErr] = useState('')
  const [busy, setBusy] = useState(false)
  const [toDelete, setToDelete] = useState<OfferListRow | null>(null)
  const [deleteBusy, setDeleteBusy] = useState(false)
  const [deleteErr, setDeleteErr] = useState('')
  const seq = useRef(0)

  const [query, setQuery] = useState('')
  const [textFilters, setTextFilters] = useState<TextFilters>(EMPTY_TEXT_FILTERS)
  const [kindFilter, setKindFilter] = useState<'' | OfferKind>('')
  const [authorFilter, setAuthorFilter] = useState('')
  const [sentFilter, setSentFilter] = useState<SentFilter>('')
  const [sortKey, setSortKey] = useState<ColKey>('updated')
  const [sortDir, setSortDir] = useState<SortDir>('desc')

  const load = useCallback(async () => {
    const my = ++seq.current
    setLoading(true)
    try {
      const res = await listOffers()
      if (my !== seq.current) return
      setRows(res.data)
      setErr('')
    } catch (ex) {
      if (my === seq.current) setErr(errorText(ex, 'Nie udało się wczytać ofert.'))
    } finally {
      if (my === seq.current) setLoading(false)
    }
  }, [])

  useEffect(() => {
    void load()
  }, [load])

  async function newOffer() {
    setBusy(true)
    setErr('')
    try {
      const o = await createOffer()
      navigate(`/oferty/${o.id}`)
    } catch (ex) {
      setErr(errorText(ex, 'Nie udało się założyć oferty.'))
      setBusy(false)
    }
  }

  async function confirmDelete() {
    if (!toDelete) return
    setDeleteBusy(true)
    setDeleteErr('')
    try {
      await deleteOffer(toDelete.id)
      setRows((r) => r.filter((x) => x.id !== toDelete.id))
      setToDelete(null)
    } catch (ex) {
      setDeleteErr(errorText(ex, 'Nie udało się usunąć oferty.'))
    } finally {
      setDeleteBusy(false)
    }
  }

  // autorzy obecni na liście — filtr Autora tylko, gdy jest z czego wybierać
  const authorOptions = useMemo(() => {
    const byKey = new Map<string, string>()
    for (const r of rows) {
      if (r.author) byKey.set(String(r.author.id), r.author.name)
      else byKey.set(NO_AUTHOR, 'bez autora')
    }
    return [...byKey.entries()]
      .map(([value, label]) => ({ value, label }))
      .sort((a, b) => (a.value === NO_AUTHOR ? 1 : b.value === NO_AUTHOR ? -1 : a.label.localeCompare(b.label, 'pl')))
  }, [rows])
  const showAuthorFilter = authorOptions.length > 1
  // wybrany autor zniknął z listy (np. po usunięciu jego ostatniej oferty) — filtr przestaje działać
  const authorActive = showAuthorFilter && authorFilter !== '' && authorOptions.some((o) => o.value === authorFilter)

  const words = useMemo(() => foldText(query).split(/\s+/).filter(Boolean), [query])
  const foldedTextFilters = useMemo(
    () => (Object.entries(textFilters) as [keyof TextFilters, string][])
      .map(([key, value]) => [key, foldText(value.trim())] as const)
      .filter(([, value]) => value !== ''),
    [textFilters],
  )

  const visible = useMemo(() => {
    const out = rows.filter((r) => {
      if (kindFilter && r.kind !== kindFilter) return false
      if (authorActive && (r.author ? String(r.author.id) : NO_AUTHOR) !== authorFilter) return false
      if (sentFilter === 'sent' && r.recipients_count === 0) return false
      if (sentFilter === 'unsent' && r.recipients_count > 0) return false
      if (foldedTextFilters.length === 0 && words.length === 0) return true
      const texts = displayTexts(r)
      for (const [key, value] of foldedTextFilters) {
        if (!foldText(texts[key]).includes(value)) return false
      }
      if (words.length > 0) {
        const haystack = foldText(Object.values(texts).join(' '))
        if (!words.every((w) => haystack.includes(w))) return false
      }
      return true
    })
    return out.sort((a, b) => compareRows(a, b, sortKey, sortDir))
  }, [rows, kindFilter, authorActive, authorFilter, sentFilter, foldedTextFilters, words, sortKey, sortDir])

  const filtersActive =
    query.trim() !== '' || foldedTextFilters.length > 0 || kindFilter !== '' || authorActive || sentFilter !== ''

  function clearFilters() {
    setQuery('')
    setTextFilters(EMPTY_TEXT_FILTERS)
    setKindFilter('')
    setAuthorFilter('')
    setSentFilter('')
  }

  function toggleSort(key: ColKey, firstDir: SortDir) {
    if (key === sortKey) setSortDir((d) => (d === 'asc' ? 'desc' : 'asc'))
    else {
      setSortKey(key)
      setSortDir(firstDir)
    }
  }

  const textFilter = (key: keyof TextFilters, label: string, placeholder = 'zawiera…') => (
    <input
      type="search"
      className={`${INPUT} w-full min-w-[5rem] font-normal`}
      aria-label={`Filtr kolumny ${label}`}
      placeholder={placeholder}
      value={textFilters[key]}
      onChange={(e) => setTextFilters((f) => ({ ...f, [key]: e.target.value }))}
    />
  )
  const selectClass = `${INPUT} w-full font-normal`

  return (
    <>
      <div className="app-page-head mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="app-page-title text-xl font-semibold">Oferty</h1>
          <p className="mt-1 max-w-3xl text-xs text-slate-600">
            Oferty dla klientów: wybrane produkty z ceną netto, wysłane ze skrzynki „Moja poczta” (osobny mail do
            każdego adresu) albo otwarte jako nowa wiadomość w Thunderbirdzie. Lista pokazuje Twoje oferty, a jeśli Twoja
            rola ma uprawnienie podglądu ofert innych osób — także ich oferty, tylko do podglądu (zmienia je i wysyła
            autor).
            {canInspections && (
              <>
                {' '}
                Oferty ze znacznikiem „Przegląd” przygotowujesz w module{' '}
                <Link to="/przeglady" className="text-blue-600 hover:underline">
                  Przeglądy
                </Link>{' '}
                — to przypomnienie dla jednego klienta, co i kiedy wymaga przeglądu, bez cen.
              </>
            )}
          </p>
        </div>
        {canProducts && (
          <button type="button" className={BTN_PRIMARY} disabled={busy} onClick={() => void newOffer()}>
            + Nowa oferta
          </button>
        )}
      </div>

      <ErrorBar message={err} onClose={() => setErr('')} />

      <div className="overflow-x-auto rounded-xl bg-white p-4 shadow-sm">
        <div className="mb-2 flex flex-wrap items-center gap-2 text-xs">
          <input
            type="search"
            className={`${INPUT} w-full max-w-md text-sm`}
            aria-label="Szukaj we wszystkich kolumnach"
            placeholder="Szukaj we wszystkich kolumnach"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
          />
          {filtersActive && (
            <button type="button" className={BTN} onClick={clearFilters}>
              Wyczyść filtry
            </button>
          )}
          <span className="ml-auto text-slate-500">
            {loading ? 'ładowanie…' : `Pokazano ${fmtInt(visible.length)} z ${fmtInt(rows.length)}`}
          </span>
        </div>
        <table className="w-full text-left text-xs">
          <thead>
            <tr className="border-b bg-slate-50">
              {COLUMNS.map((c) => (
                <th
                  key={c.key}
                  className={`p-2 ${c.right ? 'text-right' : ''}`}
                  title={c.title}
                  aria-sort={sortKey === c.key ? (sortDir === 'asc' ? 'ascending' : 'descending') : undefined}
                >
                  <button
                    type="button"
                    className="inline-flex items-center whitespace-nowrap font-semibold hover:text-blue-700"
                    onClick={() => toggleSort(c.key, c.firstDir)}
                  >
                    {c.label}
                    <SortMark active={sortKey === c.key} dir={sortDir} />
                  </button>
                </th>
              ))}
              <th className="p-2" />
            </tr>
            <tr className="border-b bg-slate-50 align-top">
              <th className="px-2 pb-2">{textFilter('offer', 'Oferta', 'temat albo kod…')}</th>
              <th className="px-2 pb-2">
                <select
                  className={selectClass}
                  aria-label="Filtr kolumny Rodzaj"
                  value={kindFilter}
                  onChange={(e) => setKindFilter(e.target.value as '' | OfferKind)}
                >
                  <option value="">wszystkie</option>
                  <option value="products">produkty</option>
                  <option value="inspection">przegląd</option>
                </select>
              </th>
              <th className="px-2 pb-2">{textFilter('customer', 'Klient')}</th>
              <th className="px-2 pb-2">
                {showAuthorFilter && (
                  <select
                    className={selectClass}
                    aria-label="Filtr kolumny Autor"
                    value={authorActive ? authorFilter : ''}
                    onChange={(e) => setAuthorFilter(e.target.value)}
                  >
                    <option value="">wszyscy</option>
                    {authorOptions.map((o) => (
                      <option key={o.value} value={o.value}>
                        {o.label}
                      </option>
                    ))}
                  </select>
                )}
              </th>
              <th className="px-2 pb-2">{textFilter('items', 'Pozycje')}</th>
              <th className="px-2 pb-2">
                <select
                  className={selectClass}
                  aria-label="Filtr kolumny Wysłano do"
                  value={sentFilter}
                  onChange={(e) => setSentFilter(e.target.value as SentFilter)}
                >
                  <option value="">wszystkie</option>
                  <option value="sent">wysłane</option>
                  <option value="unsent">niewysłane</option>
                </select>
              </th>
              <th className="px-2 pb-2">{textFilter('last_sent', 'Ostatnia wysyłka', 'np. 06.10…')}</th>
              <th className="px-2 pb-2">{textFilter('updated', 'Zmieniona', 'np. 06.10…')}</th>
              <th className="px-2 pb-2" />
            </tr>
          </thead>
          <tbody>
            {visible.map((r) => (
              <tr key={r.id} className="border-b align-top even:bg-slate-100">
                <td className="min-w-[14rem] p-2">
                  <Link to={`/oferty/${r.id}`} className="font-medium text-slate-900 hover:text-blue-700 hover:underline">
                    {r.subject.trim() || <span className="italic text-slate-500">bez tematu</span>}
                  </Link>
                  <div className="mt-0.5 flex flex-wrap items-center gap-1.5">
                    {r.code && <span className="app-code font-mono text-[11px] text-slate-500">{r.code}</span>}
                    {r.kind === 'inspection' && (
                      <Chip tone="blue" title="Oferta przeglądu z modułu Przeglądy — bez cen">
                        Przegląd
                      </Chip>
                    )}
                  </div>
                </td>
                <td className="whitespace-nowrap p-2 text-slate-700">{KIND_LABEL[r.kind]}</td>
                <td className="min-w-[8rem] p-2 text-slate-700">{customerText(r)}</td>
                <td className="p-2">
                  {r.author ? (
                    <>
                      <div className="whitespace-nowrap text-slate-800">{r.author.name}</div>
                      <div className="text-[11px] text-slate-500">{r.author.email}</div>
                    </>
                  ) : (
                    <span className="text-slate-400">—</span>
                  )}
                </td>
                <td
                  className="whitespace-nowrap p-2 text-right tabular-nums"
                  title={r.kind === 'inspection' ? 'Wiersze przeglądu w ofercie' : 'Produkty w ofercie'}
                >
                  {fmtInt(r.items_count)}
                </td>
                <td
                  className="p-2 text-right tabular-nums"
                  title={r.recipient_emails.length > 0 ? r.recipient_emails.join('\n') : undefined}
                >
                  <div className="whitespace-nowrap">{recipientsText(r)}</div>
                  {r.recipient_emails.length > 0 && (
                    <div className="text-[11px] text-slate-500">
                      {r.recipient_emails[0]}
                      {r.recipient_emails.length > 1 && ` i ${fmtInt(r.recipient_emails.length - 1)} więcej`}
                    </div>
                  )}
                </td>
                <td className="whitespace-nowrap p-2 tabular-nums text-slate-700">{fmtDateTime(r.last_sent_at)}</td>
                <td className="whitespace-nowrap p-2 tabular-nums text-slate-700">{fmtDateTime(r.updated_at)}</td>
                <td className="whitespace-nowrap p-2 text-right">
                  <span className="inline-flex items-center gap-1">
                    <Link to={`/oferty/${r.id}`} className={BTN_SM}>
                      Otwórz
                    </Link>
                    {r.can_edit ? (
                      <button
                        type="button"
                        className={`${BTN_SM} text-red-700`}
                        onClick={() => {
                          setDeleteErr('')
                          setToDelete(r)
                        }}
                      >
                        Usuń
                      </button>
                    ) : (
                      <span
                        className="px-1 text-[11px] text-slate-400"
                        title="Oferta innej osoby — zmienia ją, wysyła i usuwa autor"
                      >
                        tylko podgląd
                      </span>
                    )}
                  </span>
                </td>
              </tr>
            ))}
            {rows.length === 0 && (
              <tr>
                <td colSpan={COLUMNS.length + 1} className="p-8 text-center text-slate-500">
                  {loading ? (
                    'Ładowanie…'
                  ) : (
                    canProducts ? (
                      <>
                        Nie ma jeszcze ofert. Kliknij „+ Nowa oferta” — produkty dodasz w ofercie wyszukiwarką albo
                        z listy Produktów i Zapasów.
                      </>
                    ) : (
                      <>Nie ma jeszcze ofert. Oferty przeglądu przygotujesz w module Przeglądy przyciskiem „Przygotuj oferty”.</>
                    )
                  )}
                </td>
              </tr>
            )}
            {rows.length > 0 && visible.length === 0 && (
              <tr>
                <td colSpan={COLUMNS.length + 1} className="p-8 text-center text-slate-500">
                  Żadna oferta nie pasuje do wyszukiwania i filtrów.{' '}
                  <button type="button" className="text-blue-600 hover:underline" onClick={clearFilters}>
                    Wyczyść filtry
                  </button>
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>

      {toDelete && (
        <ConfirmDialog
          title="Usunąć ofertę?"
          danger
          confirmLabel="Usuń ofertę"
          busy={deleteBusy}
          error={deleteErr}
          onClose={() => setToDelete(null)}
          onConfirm={() => void confirmDelete()}
          message={
            <>
              <p>
                <b>
                  {toDelete.code ? `${toDelete.code} ` : ''}
                  {toDelete.subject.trim() || 'bez tematu'}
                </b>
                {toDelete.customer_name ? ` dla ${toDelete.customer_name}` : ''} — {toDelete.items_count}{' '}
                {plural(toDelete.items_count, 'pozycja', 'pozycje', 'pozycji')}. Oferta zniknie
                z listy razem z historią wysyłek. Tego nie da się cofnąć.
              </p>
              {toDelete.recipients_count > 0 && (
                <p className="text-xs text-slate-600">Maile, które już wyszły, zostają u klientów.</p>
              )}
            </>
          }
        />
      )}
    </>
  )
}
