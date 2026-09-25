import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../auth'
import { api, can } from '../lib/api'
import { pageLabel } from '../lib/pageLabel'

type SessionRow = {
  token_id: number
  user: { id: number; name: string; email: string; role: string }
  ip_address: string | null
  user_agent: string | null
  path: string | null
  presence_at: string | null
  last_used_at: string | null
  created_at: string | null
  status: 'active' | 'idle'
}

type SessionsResponse = {
  data: SessionRow[]
  meta: { active_minutes: number; window_minutes: number; generated_at: string }
}

type UserActivityRow = {
  id: number
  name: string
  email: string
  role: string
  last_login_at: string | null
  last_seen_at: string | null
  online: boolean
  /** Wszystkie niewylogowane logowania konta. */
  sessions_count: number
  /** Z ruchem w ostatnich meta.stale_days dniach. */
  recent_sessions_count: number
  /** Bez ruchu dłużej — nadal otwierają konto, dopóki ktoś ich nie wyloguje. */
  stale_sessions_count: number
}

type UsersActivityResponse = {
  data: UserActivityRow[]
  meta: { active_minutes: number; stale_days: number; generated_at: string }
}

const REFRESH_MS = 30_000

function parseTime(iso: string | null): number | null {
  if (!iso) return null
  const t = new Date(iso).getTime()
  return Number.isNaN(t) ? null : t
}

function formatWhen(iso: string | null): string {
  const t = parseTime(iso)
  return t == null ? '—' : new Date(t).toLocaleString('pl-PL')
}

/** „przed chwilą” / „N min temu” / data i godzina. */
function formatRelative(iso: string | null, now: number): string {
  const t = parseTime(iso)
  if (t == null) return '—'
  const minutes = Math.floor((now - t) / 60_000)
  if (minutes < 1) return 'przed chwilą'
  if (minutes < 60) return `${minutes} min temu`
  return new Date(t).toLocaleString('pl-PL')
}

/** Późniejszy z dwóch znaczników czasu. */
function latest(a: string | null, b: string | null): string | null {
  const ta = parseTime(a)
  const tb = parseTime(b)
  if (ta == null) return tb == null ? null : b
  if (tb == null) return a
  return tb > ta ? b : a
}

function browserLabel(ua: string | null): string {
  if (!ua) return '—'
  const browser = /Edg(e|A|iOS)?\//.test(ua)
    ? 'Edge'
    : /Firefox\/|FxiOS\//.test(ua)
      ? 'Firefox'
      : /Chrome\/|CriOS\//.test(ua)
        ? 'Chrome'
        : /Safari\//.test(ua)
          ? 'Safari'
          : null
  const os = /Windows/.test(ua)
    ? 'Windows'
    : /Android/.test(ua)
      ? 'Android'
      : /iPhone|iPad|iPod/.test(ua)
        ? 'iOS'
        : /Mac OS X|Macintosh/.test(ua)
          ? 'macOS'
          : /Linux/.test(ua)
            ? 'Linux'
            : null
  const parts = [browser, os].filter(Boolean)
  return parts.length > 0 ? parts.join(' · ') : 'inna'
}

function StatusDot({ active, label }: { active: boolean; label: string }) {
  return (
    <span className="inline-flex items-center gap-1.5 whitespace-nowrap">
      <span
        aria-hidden="true"
        className={`inline-block h-2 w-2 rounded-full ${active ? 'bg-emerald-500' : 'bg-slate-300'}`}
      />
      <span className={active ? 'text-emerald-700' : 'text-slate-500'}>{label}</span>
    </span>
  )
}

export function AdminSessions() {
  const { user } = useAuth()
  const [sessions, setSessions] = useState<SessionsResponse | null>(null)
  const [users, setUsers] = useState<UsersActivityResponse | null>(null)
  const [err, setErr] = useState('')
  const [notice, setNotice] = useState('')
  const [busy, setBusy] = useState(false)
  const [revoking, setRevoking] = useState(false)
  const [now, setNow] = useState(() => Date.now())

  const load = useCallback(async () => {
    setBusy(true)
    setErr('')
    try {
      const [s, u] = await Promise.all([
        api<SessionsResponse>('/admin/sessions'),
        api<UsersActivityResponse>('/admin/users-activity'),
      ])
      setSessions(s)
      setUsers(u)
      setNow(Date.now())
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Błąd')
    } finally {
      setBusy(false)
    }
  }, [])

  useEffect(() => {
    void load()
    const timer = window.setInterval(() => {
      if (document.visibilityState === 'visible') void load()
    }, REFRESH_MS)
    return () => window.clearInterval(timer)
  }, [load])

  const sessionRows = sessions?.data ?? []
  const userRows = users?.data ?? []
  const activeCount = sessionRows.filter((r) => r.status === 'active').length
  const activeMinutes = sessions?.meta.active_minutes ?? users?.meta.active_minutes ?? 3
  const windowMinutes = sessions?.meta.window_minutes ?? 15
  const staleDays = users?.meta.stale_days ?? 30
  const staleTotal = userRows.reduce((sum, r) => sum + r.stale_sessions_count, 0)
  const canRevoke = can(user, 'admin.sessions.manage')
  const generatedAt = sessions?.meta.generated_at ?? users?.meta.generated_at ?? null

  async function revokeStale() {
    const question =
      `Wylogować sesje nieużywane od ponad ${staleDays} dni? Liczba sesji: ${staleTotal}.\n\n` +
      'Kto wróci do takiej przeglądarki albo do dodatku w Thunderbirdzie, zaloguje się ponownie. ' +
      `Sesje używane w ostatnich ${staleDays} dniach zostają.`
    if (!confirm(question)) return
    setRevoking(true)
    setErr('')
    setNotice('')
    try {
      const res = await api<{ deleted: number }>('/admin/sessions/stale', { method: 'DELETE' })
      setNotice(`Wylogowane stare sesje: ${res.deleted}.`)
      await load()
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Błąd')
    } finally {
      setRevoking(false)
    }
  }

  return (
    <div>
      <div className="mb-3 flex flex-wrap items-center gap-3">
        <button
          type="button"
          disabled={busy}
          onClick={() => void load()}
          className="rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700 disabled:opacity-50"
        >
          Odśwież
        </button>
        <span className="text-xs text-slate-500">
          {generatedAt ? `Stan na: ${formatWhen(generatedAt)} · ` : busy ? 'Ładowanie… · ' : ''}odświeżanie co 30 s
        </span>
      </div>

      {err && <p className="mb-2 text-sm text-red-600">{err}</p>}

      <section className="mb-6">
        <h2 className="mb-1 text-base font-semibold text-slate-800">
          Teraz w systemie{' '}
          <span className="text-sm font-normal text-slate-500">
            (aktywni: {activeCount} / sesje: {sessionRows.length})
          </span>
        </h2>
        <p className="mb-3 text-sm text-slate-600">
          Aktywny = sygnał z otwartej karty w ostatnich {activeMinutes} min. Pokazane sesje z ruchem w ostatnich{' '}
          {windowMinutes} min.
        </p>

        <div className="overflow-x-auto rounded-xl bg-white shadow-sm">
          <table className="min-w-full text-left text-sm">
            <thead className="border-b bg-slate-50 text-xs uppercase text-slate-500">
              <tr>
                <th className="px-3 py-2">Użytkownik</th>
                <th className="px-3 py-2">Podstrona</th>
                <th className="px-3 py-2">Status</th>
                <th className="px-3 py-2">Ostatni ruch</th>
                <th className="px-3 py-2">IP</th>
                <th className="px-3 py-2">Przeglądarka</th>
              </tr>
            </thead>
            <tbody>
              {sessionRows.length === 0 && (
                <tr>
                  <td colSpan={6} className="px-3 py-6 text-center text-slate-500">
                    {busy && !sessions ? 'Ładowanie…' : 'Nikt teraz nie korzysta z systemu.'}
                  </td>
                </tr>
              )}
              {sessionRows.map((row) => {
                const lastMove = latest(row.presence_at, row.last_used_at)
                return (
                  <tr key={row.token_id} className="border-b last:border-0">
                    <td className="px-3 py-2">
                      <div className="font-medium text-slate-800">{row.user.name}</div>
                      <div className="text-xs text-slate-500">{row.user.email}</div>
                      <div className="text-xs text-slate-500">{row.user.role}</div>
                    </td>
                    <td className="px-3 py-2">
                      {row.path ? (
                        <>
                          <Link to={row.path} className="text-blue-600 hover:underline">
                            {pageLabel(row.path)}
                          </Link>
                          <div className="font-mono text-xs text-slate-400">{row.path}</div>
                        </>
                      ) : (
                        <span className="text-slate-500">—</span>
                      )}
                    </td>
                    <td className="px-3 py-2">
                      <StatusDot active={row.status === 'active'} label={row.status === 'active' ? 'aktywny' : 'bezczynny'} />
                    </td>
                    <td className="whitespace-nowrap px-3 py-2 text-slate-700" title={formatWhen(lastMove)}>
                      {formatRelative(lastMove, now)}
                    </td>
                    <td className="px-3 py-2 font-mono text-xs text-slate-500">{row.ip_address ?? '—'}</td>
                    <td className="whitespace-nowrap px-3 py-2 text-slate-600" title={row.user_agent ?? undefined}>
                      {browserLabel(row.user_agent)}
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      </section>

      <section>
        <div className="mb-1 flex flex-wrap items-center justify-between gap-2">
          <h2 className="text-base font-semibold text-slate-800">Użytkownicy — ostatnie korzystanie</h2>
          {canRevoke && (
            <button
              type="button"
              disabled={revoking || !users || staleTotal === 0}
              onClick={() => void revokeStale()}
              className="rounded bg-red-100 px-3 py-1.5 text-sm text-red-700 hover:bg-red-200 disabled:opacity-50"
            >
              {revoking ? 'Wylogowuję…' : `Wyloguj stare sesje (${staleTotal})`}
            </button>
          )}
        </div>
        <p className="mb-3 text-sm text-slate-600">
          Sesja = jedno logowanie (przeglądarka albo dodatek w Thunderbirdzie), które nie zostało wylogowane. W użyciu =
          z ruchem w ostatnich {staleDays} dniach. Stara = bez ruchu dłużej, np. przeglądarka na innym komputerze — nadal
          otwiera konto, dopóki jej nie wylogujesz.
        </p>
        {notice && <p className="mb-2 text-sm text-emerald-700">{notice}</p>}
        <div className="overflow-x-auto rounded-xl bg-white shadow-sm">
          <table className="min-w-full text-left text-sm">
            <thead className="border-b bg-slate-50 text-xs uppercase text-slate-500">
              <tr>
                <th className="px-3 py-2">Użytkownik</th>
                <th className="px-3 py-2">Rola</th>
                <th className="px-3 py-2">Teraz</th>
                <th className="px-3 py-2">Ostatnio aktywny</th>
                <th className="px-3 py-2">Ostatnie logowanie</th>
                <th className="px-3 py-2">Sesje w użyciu</th>
                <th className="px-3 py-2">Stare sesje</th>
              </tr>
            </thead>
            <tbody>
              {userRows.length === 0 && (
                <tr>
                  <td colSpan={7} className="px-3 py-6 text-center text-slate-500">
                    {busy && !users ? 'Ładowanie…' : 'Brak użytkowników.'}
                  </td>
                </tr>
              )}
              {userRows.map((row) => (
                <tr key={row.id} className="border-b last:border-0">
                  <td className="px-3 py-2">
                    <div className="font-medium text-slate-800">{row.name}</div>
                    <div className="text-xs text-slate-500">{row.email}</div>
                  </td>
                  <td className="px-3 py-2 text-slate-600">{row.role}</td>
                  <td className="px-3 py-2">{row.online && <StatusDot active label="online" />}</td>
                  <td
                    className="whitespace-nowrap px-3 py-2 text-slate-700"
                    title={row.last_seen_at ? formatWhen(row.last_seen_at) : undefined}
                  >
                    {row.last_seen_at ? formatRelative(row.last_seen_at, now) : <span className="text-slate-500">nigdy</span>}
                  </td>
                  <td className="whitespace-nowrap px-3 py-2 text-slate-700">
                    {row.last_login_at ? formatWhen(row.last_login_at) : <span className="text-slate-500">brak danych</span>}
                  </td>
                  <td className="px-3 py-2 text-slate-700">{row.recent_sessions_count}</td>
                  <td className={`px-3 py-2 ${row.stale_sessions_count > 0 ? 'text-amber-700' : 'text-slate-400'}`}>
                    {row.stale_sessions_count}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>
    </div>
  )
}
