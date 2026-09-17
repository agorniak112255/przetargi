import {
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
  type FormEvent,
  type KeyboardEvent,
} from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { BusyLabel, useBusySeconds } from '../components/Busy'
import { InquiryContactChip, InquiryContactModal } from '../components/InquiryContact'
import { useAuth } from '../auth'
import { ApiError, api, can } from '../lib/api'
import type {
  InquiryChannelFilter,
  InquiryDuplicateConflict,
  InquiryDuplicateRef,
  InquiryListItem,
  InquiryListResponse,
  InquiryPayload,
  InquiryPreferences,
  InquiryScope,
  InquiryStatusFilter,
  InquiryTone,
} from '../types/inquiry'

type ClientRow = { id: number; name: string }
type DirectoryUser = { id: number; name: string; email: string }

const priceModeLabel: Record<InquiryPreferences['price_mode'], string> = {
  none: 'bez cen',
  catalog: 'cena katalogowa',
  catalog_margin: 'katalog + marża',
}

const PER_PAGE_CHOICES = [25, 50, 100]
const DEFAULT_PER_PAGE = 25

const statusOptions: { id: InquiryStatusFilter; label: string }[] = [
  { id: 'all', label: 'Wszystkie' },
  { id: 'waiting', label: 'Do wysłania' },
  { id: 'replied', label: 'Wysłane' },
]

const channelOptions: { id: InquiryChannelFilter; label: string }[] = [
  { id: 'all', label: 'Wszystkie kanały' },
  { id: 'thunderbird', label: 'Z Thunderbirda' },
  { id: 'web', label: 'Wklejone w przeglądarce' },
]

const channelLabel: Record<string, string> = {
  thunderbird: 'Thunderbird',
  web: 'wklejone',
}

/** Data i godzina — na liście liczy się gęstość, więc krótki zapis. */
function dateTime(value: string | null): string {
  if (!value) return '—'
  const d = new Date(value)
  return Number.isNaN(d.getTime())
    ? value
    : d.toLocaleString('pl-PL', { dateStyle: 'short', timeStyle: 'short' })
}

/** Wyciąga ciało 409 z POST /inquiries; null dla każdego innego błędu. */
function duplicateConflict(ex: unknown): InquiryDuplicateConflict | null {
  if (!(ex instanceof ApiError) || ex.status !== 409) return null
  const duplicate = ex.body.duplicate as InquiryDuplicateRef | undefined
  if (!duplicate || typeof duplicate.id !== 'number') return null
  return { message: ex.message, duplicate }
}

const matchNote: Record<string, string> = {
  message_id: 'to ten sam mail',
  fingerprint: 'ta sama treść maila (inny identyfikator wiadomości)',
}

/** Odmiana „osoba” przez liczbę — 1 osoba, 2 osoby, 5 osób. */
function peopleWord(n: number): string {
  if (n === 1) return 'osoba'
  const last = n % 10
  const teen = n % 100
  return last >= 2 && last <= 4 && (teen < 12 || teen > 14) ? 'osoby' : 'osób'
}

/** Znacznik kopii na liście: ile innych osób ma ten mail albo czyją kopią jest wiersz. */
function DuplicateCell({ row }: { row: InquiryListItem }) {
  const others = row.duplicates_count ?? 0
  const copyOf = row.duplicate_of_id ?? null
  if (others === 0 && copyOf == null) return <span className="text-slate-400">—</span>
  return (
    <div className="space-y-0.5">
      {others > 0 && (
        <span
          className="inline-block rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-medium text-amber-800"
          title={`Ten sam mail ma też ${others} ${peopleWord(others)}`}
        >
          też {others} {peopleWord(others)}
        </span>
      )}
      {copyOf != null && (
        <Link
          to={`/inquiries/${copyOf}`}
          className="block text-[11px] text-slate-400 hover:underline"
          title={`To kopia zapytania #${copyOf}`}
        >
          kopia #{copyOf}
        </Link>
      )}
    </div>
  )
}

function StatusChip({ row }: { row: InquiryListItem }) {
  if (row.replied_at) {
    return (
      <span className="rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-medium text-emerald-800">
        Wysłano
      </span>
    )
  }
  if (row.send_requested_at) {
    return (
      <span className="rounded-full bg-blue-100 px-2 py-0.5 text-[11px] font-medium text-blue-800">
        Czeka na Thunderbirda
      </span>
    )
  }
  if (row.attention_count > 0) {
    return (
      <span className="rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-medium text-amber-800">
        Do sprawdzenia ({row.attention_count})
      </span>
    )
  }
  return (
    <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-600">
      Szkic
    </span>
  )
}

export function Inquiries() {
  const { user } = useAuth()
  const navigate = useNavigate()
  const [params, setParams] = useSearchParams()
  const [clients, setClients] = useState<ClientRow[]>([])
  const [prefs, setPrefs] = useState<InquiryPreferences | null>(null)
  const [body, setBody] = useState('')
  const [subject, setSubject] = useState('')
  const [clientId, setClientId] = useState('')
  const [tone, setTone] = useState<InquiryTone>('formal')
  const [more, setMore] = useState(false)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  // Ten sam mail prowadzi już ktoś inny (409) — zapytanie nie powstało, dopóki
  // użytkownik nie zdecyduje „Załóż mimo to”.
  const [dup, setDup] = useState<InquiryDuplicateConflict | null>(null)
  const prepareSec = useBusySeconds(busy)

  // Lista
  const [list, setList] = useState<InquiryListResponse | null>(null)
  const [listBusy, setListBusy] = useState(false)
  const [listErr, setListErr] = useState('')
  // rośnie po usunięciu zapytania — wymusza ponowne wczytanie strony listy
  const [reloadKey, setReloadKey] = useState(0)
  const [directory, setDirectory] = useState<DirectoryUser[]>([])
  const [contactRow, setContactRow] = useState<InquiryListItem | null>(null)

  // Filtry żyją w adresie strony — link do wyników da się podesłać i wrócić do niego wstecz.
  const q = params.get('q') ?? ''
  const status = (params.get('status') ?? 'all') as InquiryStatusFilter
  const channel = (params.get('channel') ?? 'all') as InquiryChannelFilter
  const scope: InquiryScope = params.get('scope') === 'all' ? 'all' : 'mine'
  const userId = params.get('user_id') ?? ''
  const from = params.get('from') ?? ''
  const to = params.get('to') ?? ''
  const page = Math.max(1, Number(params.get('page')) || 1)
  const perPageParam = Number(params.get('per_page'))
  const perPage = PER_PAGE_CHOICES.includes(perPageParam) ? perPageParam : DEFAULT_PER_PAGE

  const setFilters = useCallback(
    (patch: Record<string, string | null>) => {
      setParams(
        (prev) => {
          const next = new URLSearchParams(prev)
          for (const [key, value] of Object.entries(patch)) {
            if (value === null || value === '') next.delete(key)
            else next.set(key, value)
          }
          return next
        },
        { replace: true },
      )
    },
    [setParams],
  )

  // Szukanie z opóźnieniem: w polu trzymamy własny stan, do adresu trafia po ~300 ms.
  const [qDraft, setQDraft] = useState(q)
  const pushedQ = useRef(q)
  useEffect(() => {
    if (q !== pushedQ.current) {
      pushedQ.current = q
      setQDraft(q)
    }
  }, [q])
  useEffect(() => {
    if (qDraft === q) return
    const t = window.setTimeout(() => {
      pushedQ.current = qDraft
      setFilters({ q: qDraft.trim() || null, page: null })
    }, 300)
    return () => window.clearTimeout(t)
  }, [qDraft, q, setFilters])

  // Do API idą tylko parametry z kontraktu — obce wpisy w adresie zostają na froncie.
  const apiQuery = useMemo(() => {
    const sp = new URLSearchParams()
    if (q) sp.set('q', q)
    if (status !== 'all') sp.set('status', status)
    if (channel !== 'all') sp.set('channel', channel)
    if (scope === 'all') {
      sp.set('scope', 'all')
      if (userId) sp.set('user_id', userId)
    }
    if (from) sp.set('from', from)
    if (to) sp.set('to', to)
    sp.set('page', String(page))
    sp.set('per_page', String(perPage))
    return sp.toString()
  }, [q, status, channel, scope, userId, from, to, page, perPage])

  useEffect(() => {
    let cancelled = false
    setListBusy(true)
    setListErr('')
    api<InquiryListResponse>(`/inquiries?${apiQuery}`)
      .then((res) => {
        if (!cancelled) setList(res)
      })
      .catch((ex) => {
        if (cancelled) return
        setListErr(ex instanceof Error ? ex.message : 'Nie udało się wczytać listy')
      })
      .finally(() => {
        if (!cancelled) setListBusy(false)
      })
    return () => {
      cancelled = true
    }
  }, [apiQuery, reloadKey])

  useEffect(() => {
    void api<InquiryPreferences>('/inquiries/preferences')
      .then((p) => {
        setPrefs(p)
        setTone(p.tone)
      })
      .catch(() => {
        // brak preferencji — zostają domyślne
      })
    if (can(user, 'clients.view')) {
      void api<ClientRow[]>('/clients')
        .then((rows) => setClients(rows.map((c) => ({ id: c.id, name: c.name }))))
        .catch(() => setClients([]))
    }
  }, [user])

  const canViewAll = list?.meta.can_view_all ?? false

  // Katalog użytkowników pokazujemy tylko przy prawie do cudzych zapytań — handlowiec
  // i tak nie ma dostępu do `/users/directory`, więc pusta lista nie jest błędem.
  useEffect(() => {
    if (!canViewAll) return
    let cancelled = false
    void api<{ data: DirectoryUser[] }>('/users/directory')
      .then((res) => {
        if (!cancelled) setDirectory(Array.isArray(res.data) ? res.data : [])
      })
      .catch(() => {
        if (!cancelled) setDirectory([])
      })
    return () => {
      cancelled = true
    }
  }, [canViewAll])

  const canSubmit = !busy && body.trim().length >= 20

  /** Kasuje wyłącznie autor zapytania; serwer sprawdza to drugi raz. */
  async function removeRow(row: InquiryListItem) {
    const label = row.source_subject || row.reply_subject || `#${row.id}`
    const ok = window.confirm(
      `Usunąć zapytanie „${label}”?\n\n` +
        'Znika treść maila, dobrane pozycje i przygotowany list. Tego nie da się cofnąć.',
    )
    if (!ok) return

    setListErr('')
    try {
      await api(`/inquiries/${row.id}`, { method: 'DELETE' })
      setReloadKey((key) => key + 1)
    } catch (ex) {
      setListErr(ex instanceof Error ? ex.message : 'Nie udało się usunąć zapytania.')
    }
  }

  /** `force` pomija sprawdzenie duplikatu — ustawia je dopiero „Załóż mimo to”. */
  async function onPrepare(e?: FormEvent, force = false) {
    e?.preventDefault()
    if (!canSubmit) return
    setBusy(true)
    setErr('')
    setDup(null)
    try {
      const created = await api<InquiryPayload>('/inquiries', {
        method: 'POST',
        body: JSON.stringify({
          body,
          subject: subject.trim() || null,
          client_id: clientId ? Number(clientId) : null,
          tone,
          ...(force ? { force: true } : {}),
        }),
      })
      navigate(`/inquiries/${created.id}`)
    } catch (ex) {
      const conflict = duplicateConflict(ex)
      if (conflict) setDup(conflict)
      else setErr(ex instanceof Error ? ex.message : 'Błąd analizy')
      setBusy(false)
    }
  }

  function onBodyKey(e: KeyboardEvent<HTMLTextAreaElement>) {
    if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
      e.preventDefault()
      void onPrepare()
    }
  }

  const rows = list?.data ?? []
  const meta = list?.meta
  const lastPage = Math.max(1, meta?.last_page ?? 1)
  const total = meta?.total ?? 0
  const filtersActive = Boolean(
    q || status !== 'all' || channel !== 'all' || from || to || scope === 'all' || userId,
  )
  const contactSubtitle = contactRow
    ? contactRow.source_subject || contactRow.reply_subject || `Zapytanie #${contactRow.id}`
    : null

  return (
    <div>
      <h1 className="mb-1 text-xl font-semibold">Zapytania</h1>
      <p className="mb-4 text-sm text-slate-500">
        Wklej mail klienta. Dostaniesz gotowy list i listę pozycji, które warto sprawdzić przed
        wysłaniem.
      </p>

      {err && <p className="mb-3 rounded bg-red-50 px-3 py-2 text-xs text-red-700">{err}</p>}

      {dup && (
        <div
          className={`mb-3 rounded-lg border px-3 py-2.5 ${
            dup.duplicate.replied_at
              ? 'border-red-300 bg-red-50 text-red-900'
              : 'border-amber-300 bg-amber-50 text-amber-900'
          }`}
        >
          <p className="text-sm font-semibold">{dup.message}</p>
          <p className="mt-1 text-xs">
            Zapytanie #{dup.duplicate.id}
            {dup.duplicate.user?.name ? ` · prowadzi ${dup.duplicate.user.name}` : ''}
            {dup.duplicate.created_at ? ` · od ${dateTime(dup.duplicate.created_at)}` : ''}
            {dup.duplicate.match ? ` · ${matchNote[dup.duplicate.match] ?? dup.duplicate.match}` : ''}
          </p>
          {dup.duplicate.source_subject && (
            <p className="mt-0.5 text-xs">Temat: {dup.duplicate.source_subject}</p>
          )}
          <p className="mt-1 text-xs font-medium">
            {dup.duplicate.replied_at
              ? `Odpowiedź do klienta już poszła (${dateTime(dup.duplicate.replied_at)}) — drugie zapytanie grozi wysłaniem drugiej oferty.`
              : 'Odpowiedź do klienta jeszcze nie poszła.'}
          </p>
          <div className="mt-2 flex flex-wrap items-center gap-2">
            <Link
              to={`/inquiries/${dup.duplicate.id}`}
              className="rounded bg-blue-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-blue-700"
            >
              Otwórz zapytanie #{dup.duplicate.id}
            </Link>
            <button
              type="button"
              disabled={busy}
              onClick={() => void onPrepare(undefined, true)}
              className="rounded border border-slate-400 bg-white px-3 py-1.5 text-xs text-slate-700 hover:bg-slate-50 disabled:opacity-50"
            >
              Załóż mimo to
            </button>
            <button
              type="button"
              onClick={() => setDup(null)}
              className="text-xs text-slate-600 hover:underline"
            >
              Anuluj
            </button>
          </div>
        </div>
      )}

      <form onSubmit={(e) => void onPrepare(e)} className="mb-6 rounded-xl bg-white p-4 shadow-sm">
        <label className="block text-xs">
          Treść maila *
          <textarea
            required
            minLength={20}
            className="mt-1 min-h-[220px] w-full rounded border border-slate-300 px-2 py-1.5 text-sm"
            value={body}
            disabled={busy}
            onChange={(e) => setBody(e.target.value)}
            onKeyDown={onBodyKey}
            placeholder="Wklej całą treść zapytania od klienta…"
          />
        </label>

        <div className="mt-3 flex flex-wrap items-center gap-3">
          <button
            type="submit"
            disabled={!canSubmit}
            className={`rounded px-4 py-2 text-xs font-medium text-white ${
              busy ? 'cursor-wait bg-violet-600' : 'bg-blue-600 hover:bg-blue-700 disabled:opacity-50'
            }`}
          >
            {busy ? <BusyLabel label="Analizuję zapytanie" seconds={prepareSec} /> : 'Przygotuj odpowiedź'}
          </button>
          <span className="text-[11px] text-slate-400">Ctrl+Enter wysyła</span>
          <button
            type="button"
            disabled={busy}
            onClick={() => setMore((v) => !v)}
            className="text-xs text-blue-600 hover:underline disabled:opacity-50"
          >
            {more ? 'Mniej' : 'Więcej'}
          </button>
          {busy && (
            <span className="text-[11px] text-violet-800">Model liczy — poczekaj, nie odświeżaj strony.</span>
          )}
        </div>

        {more && (
          <div className="mt-3 grid gap-3 border-t border-slate-100 pt-3 sm:grid-cols-3">
            <label className="block text-xs">
              Temat (opcjonalnie)
              <input
                className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5 text-sm"
                value={subject}
                disabled={busy}
                onChange={(e) => setSubject(e.target.value)}
              />
            </label>
            {can(user, 'clients.view') && (
              <label className="block text-xs">
                Klient
                <select
                  className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5 text-sm"
                  value={clientId}
                  disabled={busy}
                  onChange={(e) => setClientId(e.target.value)}
                >
                  <option value="">— bez klienta —</option>
                  {clients.map((c) => (
                    <option key={c.id} value={c.id}>
                      {c.name}
                    </option>
                  ))}
                </select>
              </label>
            )}
            <label className="block text-xs">
              Ton
              <select
                className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5 text-sm"
                value={tone}
                disabled={busy}
                onChange={(e) => setTone(e.target.value as InquiryTone)}
              >
                <option value="formal">Formalny</option>
                <option value="handlowy">Handlowy</option>
              </select>
            </label>
            {prefs && (
              <p className="text-[11px] text-slate-500 sm:col-span-3">
                Ceny jak ostatnio: {priceModeLabel[prefs.price_mode]}
                {prefs.price_mode === 'catalog_margin' ? ` ${prefs.margin}%` : ''} — zmienisz na stronie
                odpowiedzi.
              </p>
            )}
          </div>
        )}
      </form>

      <div className="rounded-xl bg-white p-4 shadow-sm">
        <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
          <h2 className="text-sm font-semibold">Zapytania</h2>
          <span className="text-[11px] text-slate-400">
            {listBusy ? 'Wczytuję…' : `${total.toLocaleString('pl-PL')} zapytań`}
          </span>
        </div>

        <div className="mb-3 flex flex-wrap items-end gap-2">
          <label className="block text-xs">
            Szukaj
            <input
              className="mt-1 w-64 rounded border border-slate-300 px-2 py-1.5 text-sm"
              value={qDraft}
              onChange={(e) => setQDraft(e.target.value)}
              placeholder="temat, nadawca, firma, treść…"
            />
          </label>
          <label className="block text-xs">
            Status
            <select
              className="mt-1 rounded border border-slate-300 px-2 py-1.5 text-sm"
              value={status}
              onChange={(e) => setFilters({ status: e.target.value, page: null })}
            >
              {statusOptions.map((o) => (
                <option key={o.id} value={o.id}>
                  {o.label}
                </option>
              ))}
            </select>
          </label>
          <label className="block text-xs">
            Kanał
            <select
              className="mt-1 rounded border border-slate-300 px-2 py-1.5 text-sm"
              value={channel}
              onChange={(e) => setFilters({ channel: e.target.value, page: null })}
            >
              {channelOptions.map((o) => (
                <option key={o.id} value={o.id}>
                  {o.label}
                </option>
              ))}
            </select>
          </label>
          <label className="block text-xs">
            Od
            <input
              type="date"
              className="mt-1 rounded border border-slate-300 px-2 py-1.5 text-sm"
              value={from}
              onChange={(e) => setFilters({ from: e.target.value, page: null })}
            />
          </label>
          <label className="block text-xs">
            Do
            <input
              type="date"
              className="mt-1 rounded border border-slate-300 px-2 py-1.5 text-sm"
              value={to}
              onChange={(e) => setFilters({ to: e.target.value, page: null })}
            />
          </label>

          {canViewAll && (
            <>
              <div className="flex items-center gap-1 text-xs">
                <button
                  type="button"
                  onClick={() => setFilters({ scope: null, user_id: null, page: null })}
                  className={`rounded border px-2.5 py-1.5 ${
                    scope === 'mine'
                      ? 'border-blue-600 bg-blue-600 text-white'
                      : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50'
                  }`}
                >
                  Moje
                </button>
                <button
                  type="button"
                  onClick={() => setFilters({ scope: 'all', page: null })}
                  className={`rounded border px-2.5 py-1.5 ${
                    scope === 'all'
                      ? 'border-blue-600 bg-blue-600 text-white'
                      : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50'
                  }`}
                >
                  Wszyscy
                </button>
              </div>
              {scope === 'all' && directory.length > 0 && (
                <label className="block text-xs">
                  Użytkownik
                  <select
                    className="mt-1 rounded border border-slate-300 px-2 py-1.5 text-sm"
                    value={userId}
                    onChange={(e) => setFilters({ user_id: e.target.value, page: null })}
                  >
                    <option value="">— wszyscy —</option>
                    {directory.map((u) => (
                      <option key={u.id} value={u.id}>
                        {u.name}
                      </option>
                    ))}
                  </select>
                </label>
              )}
            </>
          )}

          {filtersActive && (
            <button
              type="button"
              onClick={() =>
                setFilters({
                  q: null,
                  status: null,
                  channel: null,
                  scope: null,
                  user_id: null,
                  from: null,
                  to: null,
                  page: null,
                })
              }
              className="rounded border border-slate-300 px-2.5 py-1.5 text-xs text-slate-700 hover:bg-slate-50"
            >
              Wyczyść filtry
            </button>
          )}
        </div>

        {listErr && <p className="mb-3 rounded bg-red-50 px-3 py-2 text-xs text-red-700">{listErr}</p>}

        {rows.length === 0 && !listBusy ? (
          <p className="py-4 text-xs text-slate-500">
            {filtersActive ? 'Brak zapytań dla tych filtrów.' : 'Nie ma jeszcze żadnych zapytań.'}
          </p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-left text-xs">
              <thead>
                <tr className="border-b bg-slate-50">
                  <th className="p-2">Temat</th>
                  <th className="p-2">Klient</th>
                  <th className="p-2">Nadawca</th>
                  <th className="p-2">Data maila</th>
                  <th className="p-2">Data zapytania</th>
                  <th className="p-2">Użytkownik</th>
                  <th className="p-2">Status</th>
                  <th className="p-2" title="Ten sam mail założony przez kilka osób">
                    Kopie
                  </th>
                  <th className="p-2">Kontakt</th>
                  <th className="p-2" />
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => (
                  <tr key={row.id} className="border-b align-top">
                    <td className="p-2">
                      <span className="text-slate-800">
                        {row.reply_subject || row.source_subject || `Zapytanie #${row.id}`}
                      </span>
                      {row.source_channel && (
                        <span className="ml-1.5 text-[11px] text-slate-400">
                          {channelLabel[row.source_channel] ?? row.source_channel}
                        </span>
                      )}
                    </td>
                    <td className="p-2">{row.client?.name ?? '—'}</td>
                    <td className="p-2">
                      {row.source_from_name || row.source_from_email ? (
                        <>
                          {row.source_from_name && (
                            <span className="block text-slate-800">{row.source_from_name}</span>
                          )}
                          {row.source_from_email && (
                            <span className="block text-[11px] text-slate-500">
                              {row.source_from_email}
                            </span>
                          )}
                        </>
                      ) : (
                        '—'
                      )}
                    </td>
                    <td className="p-2 whitespace-nowrap">{dateTime(row.source_sent_at)}</td>
                    <td className="p-2 whitespace-nowrap">{dateTime(row.created_at)}</td>
                    <td className="p-2">{row.user?.name ?? '—'}</td>
                    <td className="p-2">
                      <StatusChip row={row} />
                    </td>
                    <td className="p-2 whitespace-nowrap">
                      <DuplicateCell row={row} />
                    </td>
                    <td className="p-2">
                      <InquiryContactChip contact={row.contact} onOpen={() => setContactRow(row)} />
                    </td>
                    <td className="p-2 text-right whitespace-nowrap">
                      <Link className="text-blue-600 hover:underline" to={`/inquiries/${row.id}`}>
                        Otwórz
                      </Link>
                      {row.user?.id === user?.id && (
                        <button
                          type="button"
                          onClick={() => void removeRow(row)}
                          className="ml-3 text-red-600 hover:underline"
                        >
                          Usuń
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        <div className="mt-3 flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-3">
          <p className="flex flex-wrap items-center gap-1.5 text-xs text-slate-500">
            <span>
              Strona {meta?.page ?? page} z {lastPage}
            </span>
            <span>·</span>
            <span>{total.toLocaleString('pl-PL')} zapytań</span>
            <span>·</span>
            <label className="inline-flex items-center gap-1">
              <select
                className="rounded border border-slate-300 bg-white px-1.5 py-0.5 text-xs"
                value={perPage}
                onChange={(e) => setFilters({ per_page: e.target.value, page: null })}
                title="Ile wierszy na stronie"
              >
                {PER_PAGE_CHOICES.map((n) => (
                  <option key={n} value={n}>
                    {n}
                  </option>
                ))}
              </select>
              /stronę
            </label>
          </p>
          <nav className="flex items-center gap-1" aria-label="Paginacja">
            <button
              type="button"
              disabled={page <= 1 || listBusy}
              onClick={() => setFilters({ page: String(Math.max(1, page - 1)) })}
              className="rounded border border-slate-300 px-2.5 py-1.5 text-xs disabled:opacity-40"
            >
              ← Poprzednia
            </button>
            <button
              type="button"
              disabled={page >= lastPage || listBusy}
              onClick={() => setFilters({ page: String(Math.min(lastPage, page + 1)) })}
              className="rounded border border-slate-300 px-2.5 py-1.5 text-xs disabled:opacity-40"
            >
              Następna →
            </button>
          </nav>
        </div>
      </div>

      <InquiryContactModal
        contact={contactRow?.contact ?? null}
        subtitle={contactSubtitle}
        onClose={() => setContactRow(null)}
      />
    </div>
  )
}
