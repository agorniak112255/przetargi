import { Fragment, useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Link, NavLink, useSearchParams } from 'react-router-dom'
import { useAuth } from '../auth'
import { BTN, BTN_PRIMARY, BTN_SM, Chip, ErrorBar, INPUT, Modal, Pager } from '../components/CampaignsUi'
import { can, type User } from '../lib/api'
import { applyCheckboxRange } from '../lib/checkboxRange'
import { errorText, fmtDate, fmtDateTime, fmtInt, fmtQty } from '../lib/campaignFormat'
import { formatPln } from '../lib/campaigns'
import {
  DISMISSAL_REASONS,
  DISMISSAL_REASON_LABEL,
  createInspectionDismissal,
  deleteInspectionDismissal,
  downloadInspectionsExport,
  downloadInspectionsReport,
  getInspectionCustomer,
  intervalLabel,
  lastEventLabel,
  listInspections,
  monthsLabel,
  xlTypeLabel,
  type InspectionCustomer,
  type InspectionCustomerDetail,
  type InspectionDismissal,
  type InspectionDismissalReason,
  type InspectionDuePosition,
  type InspectionListResponse,
  type InspectionRow,
  type InspectionStatus,
} from '../lib/inspections'
import { createInspectionOffers, type InspectionOffersResult } from '../lib/offers'
import { plural } from '../lib/plural'

/**
 * Przeglądy (/przeglady): klienci, którym zbliża się albo minął termin przeglądu (gaśnice, hydranty, legalizacje…).
 * Termin liczy serwer z faktur i WZ w ERP XL: ostatnia sprzedaż pozycji + interwał wpisany przez człowieka w „Pozycjach
 * przeglądów”. Strona tylko wyświetla i filtruje; stan filtrów w adresie (link odtwarza widok). Z zaznaczonych
 * klientów: raport PDF, plik Excela i szkice ofert przeglądu (moduł Oferty, bez cen).
 */

type SortKey = 'due' | 'customer' | 'value'
type StatusFilter = '' | InspectionStatus

const DAYS = ['7', '14', '30', '60', '90', '180', '365'] as const
const DEFAULT_DAYS = '30'
const SORTS: { value: SortKey; label: string }[] = [
  { value: 'due', label: 'najbliższy termin' },
  { value: 'customer', label: 'klient (alfabetycznie)' },
  { value: 'value', label: 'wartość ostatnich przeglądów' },
]
const PER_PAGE_OPTIONS = ['20', '50', '100', '200'] as const
const DEFAULT_PER_PAGE = '50'
const SEARCH_DEBOUNCE_MS = 300
/** Limity serwera: szkice ofert najwyżej dla 50 klientów naraz, raport PDF dla 200. */
const MAX_OFFERS = 50
const MAX_REPORT = 200
/** Ile pozycji widać w wierszu klienta przed rozwinięciem. */
const INLINE_POSITIONS = 3

const STATUS_OPTIONS: { value: StatusFilter; label: string }[] = [
  { value: '', label: 'wszystkie' },
  { value: 'overdue', label: 'zaległe' },
  { value: 'upcoming', label: 'nadchodzące' },
]

function pick<T extends string>(value: string | null, allowed: readonly T[], fallback: T): T {
  return value !== null && (allowed as readonly string[]).includes(value) ? (value as T) : fallback
}

function daysLabel(n: number): string {
  return `${fmtInt(n)} ${plural(n, 'dzień', 'dni', 'dni')}`
}

function customerTitle(c: InspectionCustomer): string {
  return c.name ? `${c.acronym} — ${c.name}` : c.acronym
}

function dismissalText(d: InspectionDismissal): string {
  return d.until_on ? `pominięty do ${fmtDate(d.until_on)}` : 'pominięty na zawsze'
}

function canDismissFor(user: User | null | undefined): boolean {
  return can(user, 'inspections.offer') || can(user, 'inspections.manage')
}

/** Zakładki modułu: terminy klientów (inspections.view) i pozycje przeglądów (inspections.manage). */
export function InspectionsTabs() {
  const { user } = useAuth()
  const tabs = [
    ...(can(user, 'inspections.view') ? [{ to: '/przeglady', label: 'Terminy klientów', end: true }] : []),
    ...(can(user, 'inspections.manage') ? [{ to: '/przeglady/pozycje', label: 'Pozycje przeglądów', end: false }] : []),
  ]
  if (tabs.length < 2) return null
  return (
    <nav className="app-tabs mb-4 flex gap-1 border-b border-slate-200">
      {tabs.map((t) => (
        <NavLink
          key={t.to}
          to={t.to}
          end={t.end}
          className={({ isActive }) =>
            `app-tab -mb-px border-b-2 px-3 py-2 text-sm ${
              isActive
                ? 'app-tab--active border-blue-600 font-semibold text-blue-700'
                : 'border-transparent text-slate-600 hover:text-slate-900'
            }`
          }
        >
          {t.label}
        </NavLink>
      ))}
    </nav>
  )
}

/** Stan terminu: zaległy (czerwony), dziś albo w ciągu tygodnia (bursztynowy), później (szary). */
function StatusChip({
  status,
  daysLeft,
  overdueDays,
}: {
  status: InspectionStatus
  daysLeft: number | null
  overdueDays: number | null
}) {
  if (status === 'overdue') {
    return <Chip tone="red">zaległy {overdueDays != null ? daysLabel(overdueDays) : ''}</Chip>
  }
  if (daysLeft === 0) return <Chip tone="amber">termin dziś</Chip>
  if (daysLeft != null && daysLeft <= 7) return <Chip tone="amber">za {daysLabel(daysLeft)}</Chip>
  return <Chip tone="slate">{daysLeft != null ? `za ${daysLabel(daysLeft)}` : 'nadchodzący'}</Chip>
}

export function Inspections() {
  const { user } = useAuth()
  const canView = can(user, 'inspections.view')
  if (!canView) {
    // sama „Pozycje przeglądów” (inspections.manage) bez prawa do listy terminów
    return (
      <div>
        <InspectionsTabs />
        <h1 className="text-xl font-semibold">Przeglądy</h1>
        <p className="mt-2 text-sm text-slate-600">
          Lista terminów klientów wymaga uprawnienia „Przeglądy — podgląd”. Możesz ustawiać{' '}
          <Link to="/przeglady/pozycje" className="text-blue-600 hover:underline">
            pozycje przeglądów
          </Link>
          .
        </p>
      </div>
    )
  }
  return <InspectionsList />
}

function InspectionsList() {
  const { user } = useAuth()
  const [params, setParams] = useSearchParams()

  const days = pick(params.get('days'), DAYS, DEFAULT_DAYS)
  const status = pick<StatusFilter>(params.get('status'), ['', 'overdue', 'upcoming'], '')
  const old = params.get('old') === '1'
  const location = /^\d{1,10}$/.test(params.get('location') ?? '') ? (params.get('location') as string) : ''
  const positionId = /^\d{1,10}$/.test(params.get('position_id') ?? '') ? (params.get('position_id') as string) : ''
  const mine = params.get('mine') === '1'
  const withEmail = params.get('with_email') === '1'
  const dismissed = params.get('dismissed') === '1'
  const q = params.get('q') ?? ''
  const sort = pick<SortKey>(params.get('sort'), ['due', 'customer', 'value'], 'due')
  const page = Math.max(1, Math.floor(Number(params.get('page'))) || 1)
  const perPage = pick(params.get('per_page'), PER_PAGE_OPTIONS, DEFAULT_PER_PAGE)

  /** Filtry bez stronicowania — te same idą do eksportu do Excela. */
  const filterQuery = useMemo(() => {
    const qs = new URLSearchParams()
    qs.set('days', days)
    if (status) qs.set('status', status)
    if (old) qs.set('old', '1')
    if (location) qs.set('location', location)
    if (positionId) qs.set('position_id', positionId)
    if (mine) qs.set('mine', '1')
    if (withEmail) qs.set('with_email', '1')
    if (dismissed) qs.set('dismissed', '1')
    if (q.trim()) qs.set('q', q.trim())
    qs.set('sort', sort)
    return qs.toString()
  }, [days, status, old, location, positionId, mine, withEmail, dismissed, q, sort])
  const apiQuery = `${filterQuery}&page=${page}&per_page=${perPage}`

  const [result, setResult] = useState<InspectionListResponse | null>(null)
  const [loading, setLoading] = useState(false)
  const [err, setErr] = useState('')
  const seq = useRef(0)
  /** Zaznaczeni klienci: numer XL → wiersz (zaznaczenie przetrwa zmianę strony i filtrów). */
  const [selected, setSelected] = useState<Map<number, InspectionRow>>(() => new Map())
  const selectAnchor = useRef<number | null>(null)
  const [expanded, setExpanded] = useState<Set<number>>(() => new Set())
  const [detailGid, setDetailGid] = useState<number | null>(null)
  const [dismissTarget, setDismissTarget] = useState<DismissTarget | null>(null)
  const [offersOpen, setOffersOpen] = useState(false)
  const [busy, setBusy] = useState<'' | 'report' | 'export' | 'export-selected'>('')
  const [actionErr, setActionErr] = useState('')

  const [qInput, setQInput] = useState(q)
  const pushedQ = useRef(q)

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

  // Adres zmieniony z zewnątrz (wstecz, „Wyczyść filtry”, link z oferty) — pole szukania idzie za nim.
  useEffect(() => {
    if (q !== pushedQ.current) {
      pushedQ.current = q
      setQInput(q)
    }
  }, [q])
  useEffect(() => {
    if (qInput === pushedQ.current) return
    const t = window.setTimeout(() => {
      pushedQ.current = qInput
      setFilters({ q: qInput }, { replace: true })
    }, SEARCH_DEBOUNCE_MS)
    return () => window.clearTimeout(t)
  }, [qInput, setFilters])

  const load = useCallback(async () => {
    const my = ++seq.current
    setLoading(true)
    setErr('')
    try {
      const res = await listInspections(apiQuery)
      // szybkie klikanie filtrów — spóźniona odpowiedź nie nadpisuje nowszej
      if (my !== seq.current) return
      selectAnchor.current = null
      setResult(res)
    } catch (ex) {
      if (my === seq.current) setErr(errorText(ex, 'Błąd wczytywania przeglądów.'))
    } finally {
      if (my === seq.current) setLoading(false)
    }
  }, [apiQuery])

  useEffect(() => {
    void load()
  }, [load])

  const rows = result?.data ?? []
  const meta = result?.meta
  const lastPage = meta ? Math.max(1, Math.ceil(meta.total / Math.max(1, meta.per_page))) : 1
  // strona za końcem listy (np. po pominięciu klientów) — wróć na ostatnią istniejącą
  useEffect(() => {
    if (meta && rows.length === 0 && meta.page > 1 && meta.page > lastPage) {
      setFilters({ page: lastPage > 1 ? String(lastPage) : null }, { keepPage: true, replace: true })
    }
  }, [meta, rows.length, lastPage, setFilters])

  const canOffer = meta?.permissions.offer ?? can(user, 'inspections.offer')
  const canDismiss = (meta?.permissions.offer || meta?.permissions.manage) ?? canDismissFor(user)
  const canManage = meta?.permissions.manage ?? can(user, 'inspections.manage')

  const locations = meta?.locations ?? []
  const locationOptions =
    location && !locations.some((l) => l.code === location) ? [...locations, { code: location, name: `oddział ${location}` }] : locations
  const positions = meta?.positions ?? []
  const positionOptions =
    positionId && !positions.some((p) => String(p.id) === positionId)
      ? [...positions, { id: Number(positionId), name: `pozycja ${positionId}`, interval_months: 0 }]
      : positions

  const hasFilters = Boolean(
    days !== DEFAULT_DAYS || status || old || location || positionId || mine || withEmail || dismissed || q,
  )

  function clearFilters() {
    setParams((prev) => {
      const next = new URLSearchParams()
      const keep = prev.get('per_page')
      if (keep) next.set('per_page', keep)
      return next
    })
  }

  const allVisibleSelected = rows.length > 0 && rows.every((r) => selected.has(r.customer.xl_gid))
  const selectedRows = [...selected.values()]
  const selectedGids = [...selected.keys()]

  function toggleRow(index: number, shiftKey: boolean) {
    const ids = rows.map((r) => r.customer.xl_gid)
    const current: Record<number, boolean> = {}
    for (const id of selected.keys()) current[id] = true
    const applied = applyCheckboxRange(ids, current, selectAnchor.current, index, shiftKey)
    selectAnchor.current = applied.anchorIndex
    const next = new Map<number, InspectionRow>()
    for (const [id, row] of selected) if (applied.selected[id]) next.set(id, row)
    for (const row of rows) if (applied.selected[row.customer.xl_gid]) next.set(row.customer.xl_gid, row)
    setSelected(next)
  }

  function toggleAllVisible() {
    const next = new Map(selected)
    if (allVisibleSelected) {
      for (const r of rows) next.delete(r.customer.xl_gid)
      selectAnchor.current = null
    } else {
      for (const r of rows) next.set(r.customer.xl_gid, r)
      selectAnchor.current = rows.length - 1
    }
    setSelected(next)
  }

  function toggleExpanded(gid: number) {
    setExpanded((cur) => {
      const next = new Set(cur)
      if (next.has(gid)) next.delete(gid)
      else next.add(gid)
      return next
    })
  }

  async function runDownload(kind: 'report' | 'export' | 'export-selected') {
    setBusy(kind)
    setActionErr('')
    try {
      if (kind === 'report') await downloadInspectionsReport(selectedGids)
      else await downloadInspectionsExport(filterQuery, kind === 'export-selected' ? selectedGids : [])
    } catch (ex) {
      setActionErr(errorText(ex, kind === 'report' ? 'Nie udało się przygotować raportu PDF.' : 'Nie udało się przygotować pliku Excela.'))
    } finally {
      setBusy('')
    }
  }

  const colCount = 9
  const today = meta?.today ?? ''

  return (
    <div>
      <InspectionsTabs />
      <div className="app-sticky-bar sticky top-0 z-30 -mx-1 space-y-2 px-1 pb-2 empty:hidden">
        {selected.size > 0 && (
          <div className="app-bulk-bar flex flex-wrap items-center justify-between gap-x-4 gap-y-2 rounded-xl bg-slate-800 px-4 py-2.5 text-sm text-white shadow-xl">
            <span>
              <b className="tabular-nums">{fmtInt(selected.size)}</b>{' '}
              {plural(selected.size, 'zaznaczony klient', 'zaznaczonych klientów', 'zaznaczonych klientów')}
              {selected.size > MAX_OFFERS && canOffer && (
                <span className="mt-0.5 block text-xs text-amber-300">
                  Oferty przygotujesz najwyżej dla {MAX_OFFERS} klientów naraz — odznacz {fmtInt(selected.size - MAX_OFFERS)}.
                </span>
              )}
            </span>
            <div className="flex flex-wrap items-center gap-2">
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
              <button
                type="button"
                disabled={busy !== '' || selected.size > MAX_REPORT}
                title={
                  selected.size > MAX_REPORT
                    ? `Raport PDF najwyżej dla ${MAX_REPORT} klientów naraz`
                    : 'Raport wewnętrzny dla zaznaczonych klientów: pozycje, ilości, ostatni przegląd, termin i wartość'
                }
                onClick={() => void runDownload('report')}
                className="rounded border border-slate-500 px-3 py-1.5 text-xs text-white hover:bg-slate-700 disabled:opacity-50"
              >
                {busy === 'report' ? 'Przygotowuję PDF…' : 'Raport PDF'}
              </button>
              <button
                type="button"
                disabled={busy !== '' || selected.size > MAX_REPORT}
                title={
                  selected.size > MAX_REPORT
                    ? `Eksport zaznaczonych najwyżej dla ${MAX_REPORT} klientów naraz — albo wyczyść zaznaczenie i wyeksportuj całą listę`
                    : 'Plik Excela z zaznaczonymi klientami: wszystkie ich terminy, niezależnie od filtrów — wiersz to klient i pozycja'
                }
                onClick={() => void runDownload('export-selected')}
                className="rounded border border-slate-500 px-3 py-1.5 text-xs text-white hover:bg-slate-700 disabled:opacity-50"
              >
                {busy === 'export-selected' ? 'Przygotowuję plik…' : 'Eksport do Excela'}
              </button>
              {canOffer && (
                <button
                  type="button"
                  disabled={selected.size > MAX_OFFERS}
                  onClick={() => setOffersOpen(true)}
                  className="rounded bg-emerald-500 px-3 py-1.5 text-xs font-semibold text-slate-900 hover:bg-emerald-400 disabled:opacity-50"
                >
                  Przygotuj oferty
                </button>
              )}
            </div>
          </div>
        )}
      </div>

      <div className="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold">Przeglądy</h1>
          <p className="mt-1 text-xs text-slate-500">
            {meta ? (
              <>
                Łącznie <span className="font-medium text-slate-700">{fmtInt(meta.total)}</span>{' '}
                {plural(meta.total, 'klient', 'klientów', 'klientów')} · termin w ciągu {daysLabel(meta.days)} lub zaległy
                {today ? ` · dziś ${fmtDate(today)}` : ''}
                {loading ? ' · ładowanie…' : ''}
              </>
            ) : loading ? (
              'Ładowanie…'
            ) : (
              ''
            )}
          </p>
          <p className="mt-0.5 max-w-3xl text-xs text-slate-600">
            Klienci, którym zbliża się albo minął termin przeglądu. Termin wylicza system z faktur i WZ w ERP XL: ostatni przegląd
            (usługa) albo zakup urządzenia (towar) plus interwał pozycji ustawiony w „Pozycjach przeglądów”. Zaznacz
            klientów, żeby zrobić raport, plik Excela albo przygotować oferty przeglądu.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          {canManage && (
            <Link to="/przeglady/pozycje" className={`${BTN} inline-block`}>
              Pozycje przeglądów
            </Link>
          )}
          <button
            type="button"
            className={BTN}
            disabled={busy !== '' || !meta || meta.total === 0}
            title="Plik Excela z całą listą przy obecnych filtrach — wiersz to klient i pozycja"
            onClick={() => void runDownload('export')}
          >
            {busy === 'export' ? 'Przygotowuję plik…' : 'Eksport całej listy do Excela'}
          </button>
        </div>
      </div>

      <div className="mb-4 flex flex-wrap items-end gap-x-3 gap-y-2 rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs shadow-sm">
        <div className="flex flex-col gap-0.5 text-[11px] text-slate-500">
          <span id="inspections-days-label">Termin w ciągu</span>
          <div className="inline-flex overflow-hidden rounded border border-slate-300" role="group" aria-labelledby="inspections-days-label">
            {DAYS.map((d, i) => {
              const active = d === days
              return (
                <button
                  key={d}
                  type="button"
                  aria-pressed={active}
                  onClick={() => setFilters({ days: d === DEFAULT_DAYS ? null : d })}
                  className={`px-2 py-1 text-xs tabular-nums ${i > 0 ? 'border-l border-slate-300' : ''} ${
                    active ? 'bg-blue-600 font-semibold text-white' : 'bg-white text-slate-700 hover:bg-slate-50'
                  }`}
                >
                  {d} dni
                </button>
              )
            })}
          </div>
        </div>
        <label className="flex flex-col gap-0.5 text-[11px] text-slate-500">
          Stan
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
          Oddział
          <select
            className="rounded border border-slate-300 bg-white px-1.5 py-1 text-xs text-slate-800"
            value={location}
            title="Oddział z magazynu ostatniej faktury przeglądu"
            onChange={(e) => setFilters({ location: e.target.value || null })}
          >
            <option value="">wszystkie</option>
            {locationOptions.map((l) => (
              <option key={l.code} value={l.code}>
                {l.name} ({l.code})
              </option>
            ))}
          </select>
        </label>
        <label className="flex flex-col gap-0.5 text-[11px] text-slate-500">
          Pozycja
          <select
            className="max-w-[16rem] rounded border border-slate-300 bg-white px-1.5 py-1 text-xs text-slate-800"
            value={positionId}
            onChange={(e) => setFilters({ position_id: e.target.value || null })}
          >
            <option value="">wszystkie</option>
            {positionOptions.map((p) => (
              <option key={p.id} value={String(p.id)}>
                {p.name}
                {p.interval_months > 0 ? ` (${intervalLabel(p.interval_months)})` : ''}
              </option>
            ))}
          </select>
        </label>
        <div className="flex flex-col gap-1 pb-0.5">
          <label
            className="flex items-center gap-1.5 text-xs text-slate-700"
            title="Klienci, u których ostatnią fakturę przeglądu wystawił Twój operator ERP XL"
          >
            <input type="checkbox" checked={mine} onChange={(e) => setFilters({ mine: e.target.checked ? '1' : null })} />
            Moi klienci
          </label>
          <label className="flex items-center gap-1.5 text-xs text-slate-700">
            <input
              type="checkbox"
              checked={withEmail}
              onChange={(e) => setFilters({ with_email: e.target.checked ? '1' : null })}
            />
            Tylko z adresem e-mail
          </label>
        </div>
        <div className="flex flex-col gap-1 pb-0.5">
          <label
            className="flex items-center gap-1.5 text-xs text-slate-700"
            title="Domyślnie lista ukrywa terminy zaległe dłużej niż trzy interwały pozycji — zwykle to klienci, którzy odeszli"
          >
            <input type="checkbox" checked={old} onChange={(e) => setFilters({ old: e.target.checked ? '1' : null })} />
            Pokaż starsze zaległe
          </label>
          <label className="flex items-center gap-1.5 text-xs text-slate-700" title="Klienci i pozycje oznaczone „Pomiń”">
            <input
              type="checkbox"
              checked={dismissed}
              onChange={(e) => setFilters({ dismissed: e.target.checked ? '1' : null })}
            />
            Pokaż pominiętych
          </label>
        </div>
        <label className="flex flex-col gap-0.5 text-[11px] text-slate-500">
          Sortuj
          <select
            className="rounded border border-slate-300 bg-white px-1.5 py-1 text-xs text-slate-800"
            value={sort}
            onChange={(e) => setFilters({ sort: e.target.value === 'due' ? null : e.target.value })}
          >
            {SORTS.map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </select>
        </label>
        <label className="flex min-w-[14rem] flex-1 flex-col gap-0.5 text-[11px] text-slate-500">
          Szukaj klienta
          <input
            type="search"
            className="rounded border border-slate-300 bg-white px-1.5 py-1 text-xs text-slate-800"
            placeholder="akronim, nazwa, NIP albo miejscowość"
            value={qInput}
            onChange={(e) => setQInput(e.target.value)}
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

      {meta?.mine_unavailable && mine && (
        <p className="mb-2 rounded border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">
          Twoje konto nie ma przypisanego operatora ERP XL, więc „Moi klienci” nie ma kogo pokazać. Operatora ustawia
          administrator w Administracja → Pracownicy (kolumna „Operator ERP XL”).
        </p>
      )}
      <ErrorBar message={actionErr} onClose={() => setActionErr('')} />
      {err && (
        <p className="mb-2 rounded bg-red-50 px-3 py-2 text-xs text-red-700">
          Nie udało się wczytać przeglądów: {err}{' '}
          <button type="button" className="font-medium underline" onClick={() => void load()}>
            Spróbuj ponownie
          </button>
        </p>
      )}

      <div className="overflow-x-auto rounded-xl bg-white p-4 shadow-sm">
        <div className="flex flex-wrap items-center justify-between gap-2 pb-2 text-xs text-slate-500">
          <label className="inline-flex items-center gap-1">
            <select
              className="rounded border border-slate-300 bg-white px-1.5 py-0.5 text-xs"
              value={perPage}
              onChange={(e) => setFilters({ per_page: e.target.value === DEFAULT_PER_PAGE ? null : e.target.value })}
              title="Ilu klientów na stronie"
            >
              {PER_PAGE_OPTIONS.map((n) => (
                <option key={n} value={n}>
                  {n}
                </option>
              ))}
            </select>
            klientów na stronę
          </label>
          <Pager
            meta={meta ? { current_page: meta.page, last_page: lastPage, per_page: meta.per_page, total: meta.total } : null}
            disabled={loading}
            onPage={(n) => setFilters({ page: n > 1 ? String(n) : null }, { keepPage: true })}
          />
        </div>
        <table className="w-full text-left text-xs">
          <thead>
            <tr className="border-b bg-slate-50">
              <th className="w-8 p-2">
                <input
                  type="checkbox"
                  checked={allVisibleSelected}
                  disabled={rows.length === 0}
                  onChange={toggleAllVisible}
                  title="Zaznacz / odznacz widocznych. Na wierszu: Shift+klik zaznacza zakres."
                  aria-label="Zaznacz wszystkich widocznych klientów"
                />
              </th>
              <th className="p-2">Klient</th>
              <th className="whitespace-nowrap p-2">Najbliższy termin</th>
              <th className="p-2">Co wymaga przeglądu</th>
              <th className="p-2">Ostatni przegląd lub zakup</th>
              <th
                className="p-2 text-right"
                title="Wartość netto ostatnich przeglądów i zakupów z faktur i WZ w ERP XL — tylko do Twojej wiadomości, nie trafia do oferty"
              >
                Wartość ostatnich
              </th>
              <th className="p-2">Adres e-mail, telefon</th>
              <th className="p-2">Ostatnia oferta przeglądu</th>
              <th className="p-2" />
            </tr>
          </thead>
          <tbody>
            {rows.map((row, i) => {
              const gid = row.customer.xl_gid
              return (
                <Fragment key={gid}>
                  <CustomerRow
                    row={row}
                    striped={i % 2 === 1}
                    checked={selected.has(gid)}
                    expanded={expanded.has(gid)}
                    canDismiss={canDismiss}
                    onToggle={(shift) => toggleRow(i, shift)}
                    onExpand={() => toggleExpanded(gid)}
                    onOpen={() => setDetailGid(gid)}
                    onDismiss={() => setDismissTarget({ customer: row.customer, position: null })}
                    onRestore={() => setDismissTarget({ customer: row.customer, position: null, restore: row.dismissal })}
                  />
                  {expanded.has(gid) && (
                    <tr className="border-b bg-slate-50/60">
                      <td />
                      <td colSpan={colCount - 1} className="px-2 pb-3 pt-1">
                        <PositionsTable
                          positions={row.positions}
                          canDismiss={canDismiss}
                          onDismiss={(p) => setDismissTarget({ customer: row.customer, position: p })}
                          onRestore={(p) => setDismissTarget({ customer: row.customer, position: p, restore: p.dismissal })}
                        />
                      </td>
                    </tr>
                  )}
                </Fragment>
              )
            })}
            {rows.length === 0 && (
              <tr>
                <td colSpan={colCount} className="p-8 text-center text-slate-500">
                  {loading || (!result && !err) ? (
                    'Ładowanie…'
                  ) : err && !result ? (
                    'Nie udało się wczytać przeglądów.'
                  ) : (
                    <>
                      Brak klientów z terminem przeglądu przy wybranych filtrach.
                      {meta && meta.positions.length === 0 && (
                        <span className="mt-1 block">
                          Nie ma jeszcze pozycji przeglądów — bez nich system nie wie, co i jak często sprawdzać.
                          {canManage && (
                            <>
                              {' '}
                              <Link to="/przeglady/pozycje" className="text-blue-600 hover:underline">
                                Dodaj pozycje przeglądów
                              </Link>
                            </>
                          )}
                        </span>
                      )}
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
        {rows.length > 0 && (
          <div className="mt-2 border-t pt-1">
            <Pager
              meta={meta ? { current_page: meta.page, last_page: lastPage, per_page: meta.per_page, total: meta.total } : null}
              disabled={loading}
              onPage={(n) => setFilters({ page: n > 1 ? String(n) : null }, { keepPage: true })}
            />
          </div>
        )}
        <p className="mt-2 text-[11px] text-slate-500">
          Daty, ilości, numery faktur i wartości pochodzą z faktur i WZ w ERP XL (bez paragonów). Termin jest wyliczony: ostatni
          przegląd lub zakup plus interwał pozycji — system nie wymyśla przepisów ani terminów. Kilka wizyt w roku (np. kilka
          obiektów) daje kilka terminów; lista pokazuje najwcześniejszy.
        </p>
      </div>

      {detailGid !== null && (
        <CustomerDetailModal
          xlGid={detailGid}
          canDismiss={canDismiss}
          onClose={() => setDetailGid(null)}
          onDismiss={(target) => setDismissTarget(target)}
          reloadKey={result}
        />
      )}
      {dismissTarget && (
        <DismissDialog
          target={dismissTarget}
          today={today}
          onClose={() => setDismissTarget(null)}
          onDone={() => {
            setDismissTarget(null)
            void load()
          }}
        />
      )}
      {offersOpen && (
        <PrepareOffersDialog
          rows={selectedRows}
          days={Number(days)}
          positionId={positionId ? Number(positionId) : null}
          positionName={positions.find((p) => String(p.id) === positionId)?.name ?? null}
          onClose={() => setOffersOpen(false)}
          onCreated={() => {
            setSelected(new Map())
            selectAnchor.current = null
          }}
        />
      )}
    </div>
  )
}

function CustomerRow({
  row,
  striped,
  checked,
  expanded,
  canDismiss,
  onToggle,
  onExpand,
  onOpen,
  onDismiss,
  onRestore,
}: {
  row: InspectionRow
  striped: boolean
  checked: boolean
  expanded: boolean
  canDismiss: boolean
  onToggle: (shiftKey: boolean) => void
  onExpand: () => void
  onOpen: () => void
  onDismiss: () => void
  onRestore: () => void
}) {
  const c = row.customer
  const shown = row.positions.slice(0, INLINE_POSITIONS)
  const hidden = row.positions.length - shown.length
  const lastOn = row.positions.reduce<string | null>((max, p) => (p.last_on && (!max || p.last_on > max) ? p.last_on : max), null)
  // Number(): kwota z bazy może przyjść jako tekst („91.40”) — bez tego suma skleiłaby napisy
  const nets = row.positions.map((p) => (p.last_net == null ? null : Number(p.last_net)))
  const netSum = nets.reduce<number>((sum, v) => sum + (v ?? 0), 0)
  const netMissing = nets.filter((v) => v == null).length
  const overdue = row.status === 'overdue'
  return (
    <tr
      className={`border-b align-top hover:bg-sky-50 ${
        checked ? 'bg-blue-50/40' : overdue ? 'bg-red-50/50' : striped ? 'bg-slate-100/60' : ''
      }`}
    >
      <td className="select-none p-2">
        <input
          type="checkbox"
          checked={checked}
          title="Shift+klik zaznacza wszystkich od ostatnio klikniętego"
          onMouseDown={(e) => {
            if (!e.shiftKey) return
            e.preventDefault()
            onToggle(true)
          }}
          onChange={(e) => {
            if ((e.nativeEvent as MouseEvent).shiftKey) return
            onToggle(false)
          }}
          aria-label={`Zaznacz ${c.acronym}`}
        />
      </td>
      <td className="min-w-[11rem] max-w-[18rem] p-2">
        <button
          type="button"
          onClick={onOpen}
          className="text-left font-semibold text-slate-900 hover:text-blue-700 hover:underline"
          title="Szczegóły klienta: terminy, historia faktur, oferty"
        >
          {c.acronym}
        </button>
        {c.name && <div className="line-clamp-2 break-words text-slate-700">{c.name}</div>}
        <div className="text-[11px] text-slate-500">
          {[c.city, c.nip ? `NIP ${c.nip}` : null].filter(Boolean).join(' · ') || `numer klienta w ERP XL ${c.xl_gid}`}
        </div>
        <div className="mt-0.5 flex flex-wrap gap-1">
          {!c.known && (
            <Chip tone="amber" title="Klienta nie ma w kartotece ERP XL odczytanej przez aplikację — widać tylko jego numer">
              brak w kartotece ERP XL
            </Chip>
          )}
          {c.archived && <Chip tone="amber">archiwalny w ERP XL</Chip>}
          {row.dismissal && (
            <Chip tone="slate" title={DISMISSAL_REASON_LABEL[row.dismissal.reason] ?? row.dismissal.reason}>
              {dismissalText(row.dismissal)}
            </Chip>
          )}
        </div>
      </td>
      <td className="whitespace-nowrap p-2">
        <div className="tabular-nums text-slate-800">{fmtDate(row.due_on)}</div>
        <StatusChip status={row.status} daysLeft={row.days_left} overdueDays={row.overdue_days} />
      </td>
      <td className="min-w-[13rem] p-2">
        <ul className="space-y-0.5">
          {shown.map((p) => (
            <li key={p.due_id} className="text-slate-800">
              <span className="break-words">{p.name}</span>
              <span className="whitespace-nowrap text-slate-500"> · {fmtQty(p.open_quantity, p.unit)}</span>
              {p.same_nip_newer && (
                <span className="ml-1">
                  <Chip tone="amber" title="Inna karta klienta z tym samym NIP-em ma przegląd po tej dacie — sprawdź przed ofertą">
                    sprawdź NIP
                  </Chip>
                </span>
              )}
            </li>
          ))}
        </ul>
        <button type="button" onClick={onExpand} aria-expanded={expanded} className="mt-0.5 text-[11px] text-blue-600 hover:underline">
          {expanded
            ? 'Zwiń szczegóły pozycji'
            : hidden > 0
              ? `Pokaż wszystkie pozycje (${row.positions.length}) ze szczegółami`
              : 'Pokaż szczegóły pozycji'}
        </button>
      </td>
      <td className="whitespace-nowrap p-2">
        {lastOn ? (
          <>
            <div className="tabular-nums text-slate-800">{fmtDate(lastOn)}</div>
            <div className="text-[11px] text-slate-500">z faktur i WZ w ERP XL</div>
          </>
        ) : (
          <span className="text-slate-400">brak danych</span>
        )}
      </td>
      <td className="whitespace-nowrap p-2 text-right tabular-nums">
        {netMissing === nets.length ? (
          <span className="text-slate-400" title="Faktury nie podały wartości">
            —
          </span>
        ) : (
          <span
            title={
              netMissing > 0
                ? `Suma bez ${netMissing} ${plural(netMissing, 'pozycji', 'pozycji', 'pozycji')} bez wartości na fakturze`
                : 'Wartość netto ostatnich przeglądów i zakupów z faktur i WZ w ERP XL — tylko do Twojej wiadomości'
            }
          >
            {formatPln(netSum)}
            {netMissing > 0 && <span className="block text-[10px] text-slate-500">niepełna</span>}
          </span>
        )}
      </td>
      <td className="min-w-[10rem] max-w-[13rem] p-2">
        {c.emails.length > 0 ? (
          <span className="block text-[11px] text-slate-700" title={c.emails.join('\n')}>
            <span className="block truncate font-mono">{c.emails[0]}</span>
            {c.emails.length > 1 && <span className="text-slate-500">i {c.emails.length - 1} inne</span>}
          </span>
        ) : (
          <Chip tone="amber" title="Karta klienta w ERP XL nie ma adresu e-mail">
            brak adresu e-mail
          </Chip>
        )}
        {c.phones.length > 0 && (
          <span className="mt-0.5 block font-mono text-[11px] text-slate-700" title="Telefon z karty klienta w ERP XL">
            tel. {c.phones[0]}
          </span>
        )}
      </td>
      <td className="whitespace-nowrap p-2">
        {row.last_offer ? (
          <>
            <Link to={`/oferty/${row.last_offer.offer_id}`} className="font-mono text-blue-600 hover:underline">
              {row.last_offer.code ?? `oferta ${row.last_offer.offer_id}`}
            </Link>
            <div className="text-[11px] text-slate-500">
              wysłana {fmtDate(row.last_offer.sent_at)}
              {row.last_offer.user_name ? ` przez ${row.last_offer.user_name}` : ''}
            </div>
          </>
        ) : (
          <span className="text-slate-400">nie wysyłano</span>
        )}
      </td>
      <td className="whitespace-nowrap p-2 text-right">
        <span className="inline-flex flex-col items-stretch gap-1">
          <button type="button" className={BTN_SM} onClick={onOpen}>
            Szczegóły
          </button>
          {canDismiss &&
            (row.dismissal ? (
              <button type="button" className={BTN_SM} onClick={onRestore}>
                Przywróć
              </button>
            ) : (
              <button type="button" className={BTN_SM} onClick={onDismiss} title="Ukryj klienta na liście — na zawsze albo do wybranej daty">
                Pomiń klienta
              </button>
            ))}
        </span>
      </td>
    </tr>
  )
}

/** Tabela pozycji klienta: ilość, ostatni przegląd lub zakup (z faktur), termin (wyliczony) — lista i okno szczegółów. */
function PositionsTable({
  positions,
  canDismiss,
  onDismiss,
  onRestore,
}: {
  positions: InspectionDuePosition[]
  canDismiss: boolean
  onDismiss: (p: InspectionDuePosition) => void
  onRestore: (p: InspectionDuePosition) => void
}) {
  if (positions.length === 0) return <p className="py-2 text-slate-500">Brak pozycji z terminem.</p>
  return (
    <div className="overflow-x-auto rounded border border-slate-200 bg-white">
      <table className="w-full text-left text-xs">
        <thead>
          <tr className="border-b bg-slate-50 text-slate-700">
            <th className="p-2">Pozycja</th>
            <th className="whitespace-nowrap p-2 text-right">Ilość do przeglądu</th>
            <th className="p-2">Ostatni przegląd lub zakup</th>
            <th className="p-2">Termin przeglądu</th>
            <th className="whitespace-nowrap p-2 text-right" title="Wartość netto ostatniej wizyty z faktur — tylko do Twojej wiadomości">
              Wartość ostatniej wizyty
            </th>
            <th className="p-2">Oddział i operator</th>
            {canDismiss && <th className="p-2" />}
          </tr>
        </thead>
        <tbody>
          {positions.map((p) => (
            <tr key={p.due_id} className="border-b align-top last:border-b-0">
              <td className="min-w-[14rem] p-2">
                <div className="font-medium text-slate-900">{p.name}</div>
                <div className="text-[11px] text-slate-500">
                  <span className="font-mono">{p.code}</span> · {xlTypeLabel(p.xl_type)} · przegląd {intervalLabel(p.interval_months)}
                </div>
                <div className="mt-0.5 flex flex-wrap gap-1">
                  {p.same_nip_newer && (
                    <Chip tone="amber">inna karta klienta z tym samym NIP-em ma przegląd po tej dacie — sprawdź przed ofertą</Chip>
                  )}
                  {p.dismissal && (
                    <Chip tone="slate" title={DISMISSAL_REASON_LABEL[p.dismissal.reason] ?? p.dismissal.reason}>
                      {dismissalText(p.dismissal)}
                    </Chip>
                  )}
                </div>
                {p.recipient && (
                  <div className="text-[11px] text-slate-500">
                    Odbiorca na fakturze: {p.recipient.name ?? `klient ERP XL ${p.recipient.xl_gid}`}
                  </div>
                )}
              </td>
              <td className="whitespace-nowrap p-2 text-right tabular-nums">
                <div className="font-medium text-slate-800">{fmtQty(p.open_quantity, p.unit)}</div>
                {p.open_count > 1 && (
                  <div className="text-[11px] text-slate-500" title="Kilka wizyt lub zakupów bez późniejszego przeglądu, np. kilka obiektów">
                    z {fmtInt(p.open_count)} {plural(p.open_count, 'wizyty', 'wizyt', 'wizyt')}
                  </div>
                )}
              </td>
              <td className="p-2">
                {p.last_on ? (
                  <>
                    <div className="whitespace-nowrap tabular-nums text-slate-800">
                      {fmtDate(p.last_on)}
                      {p.last_quantity != null && <span className="text-slate-500"> · {fmtQty(p.last_quantity, p.unit)}</span>}
                    </div>
                    <div className="text-[11px] text-slate-500">{lastEventLabel(p.xl_type)}, z faktur i WZ w ERP XL</div>
                    {p.last_documents.length > 0 && (
                      <div
                        className="text-[11px] text-slate-500"
                        title={p.last_documents
                          .map((d) => `${d.number} z ${fmtDate(d.issued_on)}: ${fmtQty(d.quantity, p.unit)}`)
                          .join('\n')}
                      >
                        <span className="font-mono">{p.last_documents[0].number}</span>
                        {p.last_documents.length > 1 && ` i ${p.last_documents.length - 1} kolejne`}
                      </div>
                    )}
                  </>
                ) : (
                  <span className="text-slate-400">brak danych</span>
                )}
              </td>
              <td className="p-2">
                <div className="whitespace-nowrap tabular-nums text-slate-800">{fmtDate(p.due_on)}</div>
                <StatusChip status={p.status} daysLeft={p.days_left} overdueDays={p.overdue_days} />
                <div className="mt-0.5 text-[11px] text-slate-500">
                  termin wyliczony:{' '}
                  {p.open_count > 1
                    ? `najwcześniejsza z ${p.open_count} wizyt bez przeglądu + ${monthsLabel(p.interval_months)}`
                    : `${lastEventLabel(p.xl_type)} + ${monthsLabel(p.interval_months)}`}
                </div>
              </td>
              <td className="whitespace-nowrap p-2 text-right tabular-nums">
                {p.last_net != null ? formatPln(Number(p.last_net)) : <span className="text-slate-400">—</span>}
              </td>
              <td className="p-2 text-[11px] text-slate-600">
                <div>{p.location_name ? `${p.location_name}${p.location ? ` (${p.location})` : ''}` : p.location ?? '—'}</div>
                {p.operator_ident && <div title="Operator ERP XL, który wystawił ostatnią fakturę">wystawił {p.operator_ident}</div>}
              </td>
              {canDismiss && (
                <td className="whitespace-nowrap p-2 text-right">
                  {p.dismissal ? (
                    <button type="button" className={BTN_SM} onClick={() => onRestore(p)}>
                      Przywróć
                    </button>
                  ) : (
                    <button type="button" className={BTN_SM} onClick={() => onDismiss(p)} title="Ukryj tylko tę pozycję tego klienta">
                      Pomiń pozycję
                    </button>
                  )}
                </td>
              )}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

/* ---------- Szczegóły klienta ---------- */

/** Nazwy pól kartoteki z details_unavailable — „brak dostępu w ERP XL” zamiast „brak w kartotece”. */
const DETAIL_FIELDS: Record<string, string> = {
  street: 'ulica',
  address_line2: 'adres',
  postal_code: 'kod pocztowy',
  phone: 'telefon',
  phone2: 'drugi telefon',
  contact_phone: 'telefony osób kontaktowych',
  contact_mobile: 'telefony komórkowe osób kontaktowych',
}

/**
 * Dane klienta z kartoteki ERP XL (dosłownie, odczyt nocny): adres, telefony, e-maile, osoby kontaktowe, opiekun,
 * ostatni zakup i link do karty klienta. Pole puste z powodu braku prawa odczytu jest opisane jako brak dostępu.
 */
function CustomerCardSection({ customer: c, unavailable }: { customer: InspectionCustomer; unavailable: string[] }) {
  const blocked = (field: string) => unavailable.includes(field)
  const address = [c.street, c.address_line2, [c.postal_code, c.city].filter(Boolean).join(' '), c.voivodeship]
    .filter((part): part is string => part != null && part.trim() !== '')
    .join(', ')
  const addressBlocked = blocked('street') || blocked('postal_code')
  const missing = (text: string) => <span className="text-slate-400">{text}</span>
  const blockedNote = Object.keys(DETAIL_FIELDS)
    .filter(blocked)
    .map((f) => DETAIL_FIELDS[f])

  return (
    <section>
      <h3 className="text-sm font-semibold text-slate-900">Dane klienta</h3>
      <p className="mb-1.5 text-slate-500">
        Z kartoteki ERP XL, dosłownie (odczyt co noc{c.details_synced_at ? `, ostatni ${fmtDateTime(c.details_synced_at)}` : ''}).
        {c.client_id != null && (
          <>
            {' '}
            <Link to={`/clients/${c.client_id}`} className="text-blue-600 hover:underline">
              Karta klienta w zakładce Klienci →
            </Link>
          </>
        )}
      </p>
      {!c.known ? (
        <p className="text-amber-800">
          Klienta nie ma w kartotece ERP XL odczytanej przez aplikację — numer klienta w ERP XL {c.xl_gid}.
        </p>
      ) : (
        <>
          <div className="grid gap-x-6 gap-y-1 rounded border border-slate-200 p-3 sm:grid-cols-[max-content_1fr]">
            <span className="text-slate-500">Numer w ERP XL, NIP</span>
            <span className="text-slate-800">
              {c.xl_gid}
              {c.nip ? ` · NIP ${c.nip}` : ''}
            </span>
            <span className="text-slate-500">Adres</span>
            <span className="text-slate-800">
              {address !== ''
                ? address
                : missing(addressBlocked ? 'aplikacja nie ma dostępu do adresu w ERP XL' : 'brak w kartotece')}
              {address !== '' && addressBlocked && !c.street && (
                <span className="ml-1 text-slate-400">(ulicy i kodu aplikacja nie może odczytać z ERP XL)</span>
              )}
            </span>
            <span className="text-slate-500">Telefon</span>
            <span className="text-slate-800">
              {c.phones.length > 0
                ? c.phones.map((p) => (
                    <a key={p} href={`tel:${p.replace(/[^\d+]/g, '')}`} className="mr-3 font-mono text-blue-600 hover:underline">
                      {p}
                    </a>
                  ))
                : missing(
                    blocked('phone')
                      ? 'aplikacja nie ma dostępu do telefonów w ERP XL — potrzebne uprawnienie od administratora ERP XL'
                      : 'brak w kartotece',
                  )}
            </span>
            <span className="text-slate-500">Adresy e-mail</span>
            <span>
              {c.emails.length > 0 ? (
                <span className="font-mono text-slate-800">{c.emails.join(', ')}</span>
              ) : (
                <span className="text-amber-800">brak adresu e-mail w ERP XL</span>
              )}
            </span>
            <span className="text-slate-500">Opiekun klienta w ERP XL</span>
            <span className="text-slate-800">
              {c.account_manager ? (
                <>
                  {c.account_manager.name}
                  {c.account_manager.email && <span className="ml-2 font-mono text-slate-600">{c.account_manager.email}</span>}
                </>
              ) : (
                missing('nie przypisano')
              )}
            </span>
            <span className="text-slate-500">Najczęściej wystawia dokumenty</span>
            <span className="text-slate-800">{c.main_operator ?? missing('brak danych')}</span>
            <span className="text-slate-500">Ostatni zakup (dowolny towar)</span>
            <span className="text-slate-800">{c.last_sale_on ? fmtDate(c.last_sale_on) : missing('brak danych')}</span>
          </div>

          <div className="mt-2">
            <div className="mb-1 font-medium text-slate-800">Osoby kontaktowe</div>
            {c.contacts.length === 0 ? (
              <p className="text-slate-400">Karta klienta w ERP XL nie ma osób kontaktowych.</p>
            ) : (
              <table className="w-full text-left">
                <thead>
                  <tr className="border-b bg-slate-50 text-slate-700">
                    <th className="p-2">Osoba</th>
                    <th className="p-2">Stanowisko</th>
                    <th className="p-2">Adres e-mail</th>
                    <th className="p-2">Telefon</th>
                  </tr>
                </thead>
                <tbody>
                  {c.contacts.map((p, i) => (
                    <tr key={`${p.name ?? ''}-${i}`} className="border-b last:border-b-0">
                      <td className="p-2 text-slate-800">{p.name ?? '—'}</td>
                      <td className="p-2 text-slate-600">{p.position ?? '—'}</td>
                      <td className="p-2 font-mono text-slate-700">{p.email ?? '—'}</td>
                      <td className="p-2 font-mono text-slate-700">
                        {[p.phone, p.mobile].filter(Boolean).join(', ') ||
                          (blocked('contact_phone') ? <span className="font-sans text-slate-400">brak dostępu w ERP XL</span> : '—')}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </div>
        </>
      )}
      {blockedNote.length > 0 && (
        <p className="mt-1.5 text-[11px] text-slate-500">
          Aplikacja nie ma jeszcze prawa odczytu w ERP XL: {blockedNote.join(', ')}. Po nadaniu uprawnienia przez administratora
          ERP XL dane pojawią się po najbliższym odczycie nocnym.
        </p>
      )}
    </section>
  )
}

function CustomerDetailModal({
  xlGid,
  canDismiss,
  onClose,
  onDismiss,
  reloadKey,
}: {
  xlGid: number
  canDismiss: boolean
  onClose: () => void
  onDismiss: (target: DismissTarget) => void
  /** Nowa odpowiedź listy (np. po pominięciu) — szczegóły wczytują się od nowa. */
  reloadKey: unknown
}) {
  const [data, setData] = useState<InspectionCustomerDetail | null>(null)
  const [err, setErr] = useState('')

  useEffect(() => {
    let cancelled = false
    setErr('')
    getInspectionCustomer(xlGid)
      .then((d) => {
        if (!cancelled) setData(d)
      })
      .catch((ex: unknown) => {
        if (!cancelled) setErr(errorText(ex, 'Nie udało się wczytać szczegółów klienta.'))
      })
    return () => {
      cancelled = true
    }
  }, [xlGid, reloadKey])

  const c = data?.customer
  return (
    <Modal title={c ? customerTitle(c) : 'Szczegóły klienta'} wide="full" onClose={onClose}>
      <ErrorBar message={err} />
      {!data ? (
        !err && <p className="text-xs text-slate-500">Wczytuję…</p>
      ) : (
        <div className="space-y-4 text-xs">
          {c && <CustomerCardSection customer={c} unavailable={data.details_unavailable} />}

          <section>
            <h3 className="text-sm font-semibold text-slate-900">Terminy przeglądów</h3>
            <p className="mb-1.5 text-slate-500">
              Wszystkie pozycje tego klienta z terminem — także poza oknem listy i pominięte.
            </p>
            <PositionsTable
              positions={data.positions}
              canDismiss={canDismiss}
              onDismiss={(p) => c && onDismiss({ customer: c, position: p })}
              onRestore={(p) => c && onDismiss({ customer: c, position: p, restore: p.dismissal })}
            />
          </section>

          <section>
            <h3 className="text-sm font-semibold text-slate-900">Historia faktur i wydań (WZ) z ERP XL</h3>
            <p className="mb-1.5 text-slate-500">
              Faktury, wydania zewnętrzne (WZ) i korekty tego klienta z pozycjami przeglądów i usługami, które je odnawiają —
              najnowsze u góry (najwyżej 300). Z nich system liczy terminy. Towar wydany przez WZ ma pozycje na WZ, a faktura
              do tego WZ jest podana pod numerem WZ.
            </p>
            {data.history.length === 0 ? (
              <p className="text-slate-500">Brak faktur.</p>
            ) : (
              <div className="max-h-80 overflow-auto rounded border border-slate-200">
                <table className="w-full text-left">
                  <thead className="sticky top-0 bg-slate-50">
                    <tr className="border-b text-slate-700">
                      <th className="p-2">Data dokumentu</th>
                      <th className="p-2">Data sprzedaży</th>
                      <th className="p-2">Dokument</th>
                      <th className="p-2">Pozycja</th>
                      <th className="p-2 text-right">Ilość</th>
                      <th className="p-2 text-right">Wartość netto</th>
                      <th className="p-2">Oddział i operator</th>
                    </tr>
                  </thead>
                  <tbody>
                    {data.history.map((h, i) => (
                      <tr key={`${h.document_number}-${h.xl_gid}-${i}`} className="border-b align-top last:border-b-0">
                        <td className="whitespace-nowrap p-2 tabular-nums">{fmtDate(h.issued_on)}</td>
                        <td className="whitespace-nowrap p-2 tabular-nums text-slate-600">
                          {h.sold_on && h.sold_on !== h.issued_on ? fmtDate(h.sold_on) : '—'}
                        </td>
                        <td className="whitespace-nowrap p-2">
                          <span className="font-mono">{h.document_number}</span>
                          {h.invoice_number && (
                            <div className="text-[11px] text-slate-500">
                              faktura <span className="font-mono">{h.invoice_number}</span>
                            </div>
                          )}
                          {h.is_correction && (
                            <span className="ml-1">
                              <Chip tone="amber">korekta</Chip>
                            </span>
                          )}
                        </td>
                        <td className="p-2">
                          {h.name} <span className="font-mono text-[11px] text-slate-500">{h.code}</span>
                        </td>
                        <td className="whitespace-nowrap p-2 text-right tabular-nums">{fmtQty(h.quantity)}</td>
                        <td className="whitespace-nowrap p-2 text-right tabular-nums">
                          {h.net_value != null ? formatPln(Number(h.net_value)) : '—'}
                        </td>
                        <td className="whitespace-nowrap p-2 text-[11px] text-slate-600">
                          {[h.location, h.operator_ident].filter(Boolean).join(' · ') || '—'}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </section>

          <section>
            <h3 className="text-sm font-semibold text-slate-900">Oferty przeglądu</h3>
            <p className="mb-1.5 text-slate-500">Oferty przeglądu przygotowane dla tego klienta — żeby nie wysłać drugiej tej samej.</p>
            {data.offers.length === 0 ? (
              <p className="text-slate-500">Nie było jeszcze ofert przeglądu.</p>
            ) : (
              <ul className="space-y-0.5">
                {data.offers.map((o) => (
                  <li key={o.offer_id}>
                    <Link to={`/oferty/${o.offer_id}`} className="font-mono text-blue-600 hover:underline">
                      {o.code ?? `oferta ${o.offer_id}`}
                    </Link>{' '}
                    <span className="text-slate-600">
                      {o.sent_at ? `wysłana ${fmtDateTime(o.sent_at)}` : 'jeszcze niewysłana'}
                      {o.user_name ? ` · ${o.user_name}` : ''}
                    </span>
                  </li>
                ))}
              </ul>
            )}
          </section>

          {data.dismissals.length > 0 && (
            <section>
              <h3 className="text-sm font-semibold text-slate-900">Pominięcia</h3>
              <p className="mb-1.5 text-slate-500">Kto i dlaczego ukrył tego klienta albo jego pozycje na liście.</p>
              <ul className="space-y-1">
                {data.dismissals.map((d) => (
                  <li key={d.id} className="flex flex-wrap items-baseline gap-x-2">
                    <span className="text-slate-800">{dismissalText(d)}</span>
                    <span className="text-slate-600">— {DISMISSAL_REASON_LABEL[d.reason] ?? d.reason}</span>
                    {d.note && <span className="text-slate-600">„{d.note}”</span>}
                    <span className="text-slate-500">
                      {d.user_name ?? 'nieznana osoba'}, {fmtDateTime(d.created_at)}
                    </span>
                    {canDismiss && c && (
                      <button
                        type="button"
                        className={BTN_SM}
                        onClick={() => onDismiss({ customer: c, position: null, restore: d })}
                      >
                        Przywróć
                      </button>
                    )}
                  </li>
                ))}
              </ul>
            </section>
          )}
        </div>
      )}
    </Modal>
  )
}

/* ---------- Pomiń / przywróć ---------- */

type DismissTarget = {
  customer: InspectionCustomer
  /** null = cały klient. */
  position: InspectionDuePosition | null
  /** Istniejące pominięcie do zdjęcia („Przywróć”); brak = nowe pominięcie. */
  restore?: InspectionDismissal | null
}

function DismissDialog({
  target,
  today,
  onClose,
  onDone,
}: {
  target: DismissTarget
  /** Dzisiejsza data z serwera (RRRR-MM-DD) — najwcześniejsza data „do” to jutro. */
  today: string
  onClose: () => void
  onDone: () => void
}) {
  const [reason, setReason] = useState<InspectionDismissalReason>('other_company')
  const [forever, setForever] = useState(true)
  const [until, setUntil] = useState('')
  const [note, setNote] = useState('')
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const who = target.position ? `${target.position.name} u ${target.customer.acronym}` : target.customer.acronym
  const restore = target.restore ?? null

  async function submit() {
    setErr('')
    if (!restore && !forever && (until === '' || (today !== '' && until <= today))) {
      setErr('Wybierz datę późniejszą niż dziś albo zaznacz „na zawsze”.')
      return
    }
    setBusy(true)
    try {
      if (restore) {
        await deleteInspectionDismissal(restore.id)
      } else {
        await createInspectionDismissal({
          customer_xl_gid: target.customer.xl_gid,
          position_id: target.position ? target.position.position_id : null,
          until_on: forever ? null : until,
          reason,
          note: note.trim() === '' ? null : note.trim(),
        })
      }
      onDone()
    } catch (ex) {
      setErr(errorText(ex, restore ? 'Nie udało się przywrócić.' : 'Nie udało się zapisać pominięcia.'))
      setBusy(false)
    }
  }

  return (
    <Modal
      title={restore ? `Przywrócić na listę: ${who}?` : target.position ? `Pomiń pozycję: ${who}` : `Pomiń klienta: ${who}`}
      busy={busy}
      onClose={onClose}
      footer={
        <>
          <button type="button" className={BTN} disabled={busy} onClick={onClose}>
            Anuluj
          </button>
          <button type="button" className={BTN_PRIMARY} disabled={busy} onClick={() => void submit()}>
            {busy ? 'Chwila…' : restore ? 'Przywróć' : 'Pomiń'}
          </button>
        </>
      }
    >
      {restore ? (
        <p className="text-slate-800">
          Teraz: {dismissalText(restore)} ({DISMISSAL_REASON_LABEL[restore.reason] ?? restore.reason}
          {restore.user_name ? `, ${restore.user_name}` : ''}). Po przywróceniu terminy wrócą na listę i do ofert
          przeglądu.
        </p>
      ) : (
        <div className="space-y-3 text-xs">
          <p className="text-slate-600">
            {target.position
              ? 'Ta pozycja tego klienta zniknie z listy terminów i z ofert przeglądu — pozostałe pozycje zostają.'
              : 'Klient zniknie z listy terminów i z ofert przeglądu.'}{' '}
            Wróci po wybranej dacie albo po kliknięciu „Przywróć” (pominiętych pokazuje filtr „Pokaż pominiętych”).
          </p>
          <label className="block font-medium text-slate-700">
            Powód
            <select
              className={`${INPUT} mt-1 block w-full`}
              value={reason}
              onChange={(e) => setReason(e.target.value as InspectionDismissalReason)}
            >
              {DISMISSAL_REASONS.map((r) => (
                <option key={r} value={r}>
                  {DISMISSAL_REASON_LABEL[r]}
                </option>
              ))}
            </select>
          </label>
          <fieldset className="space-y-1">
            <legend className="font-medium text-slate-700">Na jak długo</legend>
            <label className="flex items-center gap-1.5 text-slate-700">
              <input type="radio" checked={forever} onChange={() => setForever(true)} />
              na zawsze
            </label>
            <label className="flex flex-wrap items-center gap-1.5 text-slate-700">
              <input type="radio" checked={!forever} onChange={() => setForever(false)} />
              do dnia
              <input
                type="date"
                className={INPUT}
                value={until}
                min={today || undefined}
                onChange={(e) => {
                  setUntil(e.target.value)
                  setForever(false)
                }}
              />
            </label>
          </fieldset>
          <label className="block font-medium text-slate-700">
            Uwaga <span className="font-normal text-slate-500">— nieobowiązkowa, np. z kim robi przeglądy</span>
            <textarea
              className={`${INPUT} mt-1 block w-full`}
              rows={2}
              maxLength={500}
              value={note}
              onChange={(e) => setNote(e.target.value)}
            />
          </label>
        </div>
      )}
      {err && <p className="mt-3 rounded bg-red-50 px-3 py-2 text-xs text-red-700">{err}</p>}
    </Modal>
  )
}

/* ---------- Przygotuj oferty ---------- */

function PrepareOffersDialog({
  rows,
  days,
  positionId,
  positionName,
  onClose,
  onCreated,
}: {
  rows: InspectionRow[]
  days: number
  positionId: number | null
  positionName: string | null
  onClose: () => void
  onCreated: () => void
}) {
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const [result, setResult] = useState<InspectionOffersResult | null>(null)
  const sentBefore = rows.filter((r) => r.last_offer !== null)
  const withoutEmail = rows.filter((r) => r.customer.emails.length === 0)

  async function submit() {
    setBusy(true)
    setErr('')
    try {
      const res = await createInspectionOffers({
        customer_xl_gids: rows.map((r) => r.customer.xl_gid),
        days,
        position_ids: positionId !== null ? [positionId] : null,
      })
      setResult(res)
      onCreated()
    } catch (ex) {
      setErr(errorText(ex, 'Nie udało się przygotować ofert.'))
    } finally {
      setBusy(false)
    }
  }

  if (result) {
    return (
      <Modal
        title="Szkice ofert przeglądu"
        wide
        onClose={onClose}
        footer={
          <button type="button" className={BTN_PRIMARY} onClick={onClose}>
            Zamknij
          </button>
        }
      >
        <div className="space-y-3 text-xs">
          <p className="text-slate-600">
            Przygotowano {result.offers.length} {plural(result.offers.length, 'szkic', 'szkice', 'szkiców')} w module Oferty.
            Nic nie zostało wysłane — otwórz każdą ofertę, sprawdź wiersze i treść, wpisz adres i wyślij.
          </p>
          {result.offers.length > 0 && (
            <table className="w-full text-left">
              <thead>
                <tr className="border-b bg-slate-50 text-slate-700">
                  <th className="p-2">Oferta</th>
                  <th className="p-2">Klient</th>
                  <th className="p-2 text-right">Wiersze</th>
                  <th className="p-2">Adres e-mail</th>
                </tr>
              </thead>
              <tbody>
                {result.offers.map((o) => (
                  <tr key={o.id} className="border-b align-top">
                    <td className="whitespace-nowrap p-2">
                      <Link to={`/oferty/${o.id}`} className="font-mono text-blue-600 hover:underline">
                        {o.code ?? `oferta ${o.id}`}
                      </Link>
                    </td>
                    <td className="p-2">
                      <div className="text-slate-800">{o.customer_name}</div>
                      {o.previous && (
                        <div className="mt-0.5 text-amber-800">
                          {o.previous.sent_at ? (
                            <>
                              Uwaga: oferta przeglądu {o.previous.code ?? ''} wysłana {fmtDate(o.previous.sent_at)}
                              {o.previous.user_name ? ` przez ${o.previous.user_name}` : ''} — sprawdź, zanim wyślesz kolejną.
                            </>
                          ) : (
                            <>
                              Uwaga: {o.previous.user_name ?? 'inna osoba'} przygotował(a) {fmtDate(o.previous.created_at)} szkic oferty
                              przeglądu {o.previous.code ?? ''} dla tego klienta (jeszcze niewysłany) — uzgodnijcie, kto wysyła.
                            </>
                          )}
                        </div>
                      )}
                      {o.same_nip_newer_lines > 0 && (
                        <div className="mt-0.5 text-amber-800">
                          Uwaga: {o.same_nip_newer_lines === 1 ? 'przy jednej pozycji' : `przy ${fmtInt(o.same_nip_newer_lines)} pozycjach`} inna
                          karta ERP XL z tym samym numerem NIP ma późniejszy przegląd lub zakup — przegląd mógł już być zrobiony,
                          sprawdź przed wysyłką.
                        </div>
                      )}
                    </td>
                    <td className="whitespace-nowrap p-2 text-right tabular-nums">{fmtInt(o.lines_count)}</td>
                    <td className="p-2">
                      {o.emails.length > 0 ? (
                        <span className="break-all font-mono text-[11px]">{o.emails.join(', ')}</span>
                      ) : (
                        <Chip tone="amber">brak adresu e-mail</Chip>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
          {result.skipped.length > 0 && (
            <div>
              <p className="font-medium text-slate-700">Bez oferty ({result.skipped.length}):</p>
              <ul className="mt-1 list-disc space-y-0.5 pl-5 text-slate-600">
                {result.skipped.map((s) => (
                  <li key={s.customer_xl_gid}>
                    {s.customer_name} — {s.reason}
                  </li>
                ))}
              </ul>
            </div>
          )}
        </div>
      </Modal>
    )
  }

  return (
    <Modal
      title={`Przygotować oferty przeglądu dla ${rows.length} ${plural(rows.length, 'klienta', 'klientów', 'klientów')}?`}
      busy={busy}
      wide
      onClose={onClose}
      footer={
        <>
          <button type="button" className={BTN} disabled={busy} onClick={onClose}>
            Anuluj
          </button>
          <button type="button" className={BTN_PRIMARY} disabled={busy || rows.length === 0} onClick={() => void submit()}>
            {busy ? 'Przygotowuję…' : 'Przygotuj szkice ofert'}
          </button>
        </>
      }
    >
      <div className="space-y-2 text-xs text-slate-700">
        <p>
          Każdy klient dostanie osobny szkic oferty w module Oferty. W ofercie: pozycje z terminem w ciągu{' '}
          {daysLabel(days)} i zaległe{positionName ? `, tylko „${positionName}”` : ''}, bez pominiętych. Oferta nie ma cen
          — pokazuje klientowi, co i kiedy wymaga przeglądu. Nic nie zostanie wysłane, dopóki nie otworzysz oferty i nie
          klikniesz „Wyślij”.
        </p>
        {sentBefore.length > 0 && (
          <div className="rounded border border-amber-200 bg-amber-50 px-2 py-1.5 text-amber-900">
            <p className="font-medium">
              {sentBefore.length} {plural(sentBefore.length, 'klient dostał', 'klientów dostało', 'klientów dostało')} już
              ofertę przeglądu:
            </p>
            <ul className="mt-1 list-disc space-y-0.5 pl-5">
              {sentBefore.map((r) => (
                <li key={r.customer.xl_gid}>
                  {r.customer.acronym} — oferta wysłana {fmtDate(r.last_offer?.sent_at)}
                  {r.last_offer?.user_name ? ` przez ${r.last_offer.user_name}` : ''}
                </li>
              ))}
            </ul>
          </div>
        )}
        {withoutEmail.length > 0 && (
          <p className="text-amber-800">
            {withoutEmail.length} {plural(withoutEmail.length, 'klient nie ma', 'klientów nie ma', 'klientów nie ma')} adresu
            e-mail w ERP XL ({withoutEmail.map((r) => r.customer.acronym).join(', ')}) — adres wpiszesz w ofercie ręcznie.
          </p>
        )}
        {err && <ErrorBar message={err} />}
      </div>
    </Modal>
  )
}
