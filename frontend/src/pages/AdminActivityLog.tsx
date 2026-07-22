import { Fragment, useEffect, useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { api, type User } from '../lib/api'

type ActivityUser = {
  id: number | null
  name: string
  email: string | null
}

type ActivityRow = {
  id: number
  action: string
  label: string | null
  user: ActivityUser
  subject_type: string | null
  subject_id: number | null
  ip_address: string | null
  meta: Record<string, unknown> | null
  created_at: string | null
}

type ActivityResponse = {
  data: ActivityRow[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
    retention_days: number
    actions: string[]
  }
}

function formatWhen(iso: string | null): string {
  if (!iso) return '—'
  try {
    return new Date(iso).toLocaleString('pl-PL')
  } catch {
    return iso
  }
}

function subjectLabel(type: string | null, id: number | null): string {
  if (!type || !id) return '—'
  const short = type.includes('\\') ? type.split('\\').pop() ?? type : type
  return `${short} #${id}`
}

export function AdminActivityLog() {
  const [rows, setRows] = useState<ActivityRow[]>([])
  const [users, setUsers] = useState<User[]>([])
  const [actions, setActions] = useState<string[]>([])
  const [page, setPage] = useState(1)
  const [lastPage, setLastPage] = useState(1)
  const [total, setTotal] = useState(0)
  const [retention, setRetention] = useState(120)
  const [err, setErr] = useState('')
  const [busy, setBusy] = useState(false)
  const [userId, setUserId] = useState('')
  const [action, setAction] = useState('')
  const [q, setQ] = useState('')
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [expanded, setExpanded] = useState<number | null>(null)

  async function load(nextPage = page) {
    setBusy(true)
    setErr('')
    try {
      const params = new URLSearchParams()
      params.set('page', String(nextPage))
      params.set('per_page', '50')
      if (userId) params.set('user_id', userId)
      if (action) params.set('action', action)
      if (q.trim()) params.set('q', q.trim())
      if (from) params.set('from', from)
      if (to) params.set('to', to)

      const data = await api<ActivityResponse>(`/admin/activity-logs?${params.toString()}`)
      setRows(data.data)
      setPage(data.meta.current_page)
      setLastPage(data.meta.last_page)
      setTotal(data.meta.total)
      setRetention(data.meta.retention_days)
      setActions(data.meta.actions)
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Błąd')
    } finally {
      setBusy(false)
    }
  }

  useEffect(() => {
    void api<User[]>('/admin/users')
      .then(setUsers)
      .catch(() => {
        /* lista użytkowników opcjonalna przy filtrze */
      })
    void load(1)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  function onFilter(e: FormEvent) {
    e.preventDefault()
    void load(1)
  }

  return (
    <div>
      <div className="mb-4 flex flex-wrap items-center gap-3">
        <h1 className="text-xl font-semibold">Administracja · Logi</h1>
        <Link to="/admin" className="text-xs text-blue-600 hover:underline">
          ← Użytkownicy
        </Link>
        <Link to="/admin/roles" className="text-xs text-blue-600 hover:underline">
          Role i uprawnienia →
        </Link>
      </div>

      <p className="mb-3 text-sm text-slate-600">
        Historia logowań i działań w systemie. Retencja: {retention} dni ({total} wpisów).
      </p>

      {err && <p className="mb-2 text-sm text-red-600">{err}</p>}

      <form
        onSubmit={onFilter}
        className="mb-4 grid max-w-5xl gap-2 rounded-xl bg-white p-4 shadow-sm sm:grid-cols-2 lg:grid-cols-6"
      >
        <select
          className="rounded border px-2 py-1.5 text-sm lg:col-span-2"
          value={userId}
          onChange={(e) => setUserId(e.target.value)}
        >
          <option value="">Wszyscy użytkownicy</option>
          {users.map((u) => (
            <option key={u.id} value={u.id}>
              {u.name} ({u.email})
            </option>
          ))}
        </select>
        <select
          className="rounded border px-2 py-1.5 text-sm lg:col-span-2"
          value={action}
          onChange={(e) => setAction(e.target.value)}
        >
          <option value="">Wszystkie akcje</option>
          {actions.map((a) => (
            <option key={a} value={a}>
              {a}
            </option>
          ))}
        </select>
        <input
          type="date"
          className="rounded border px-2 py-1.5 text-sm"
          value={from}
          onChange={(e) => setFrom(e.target.value)}
        />
        <input
          type="date"
          className="rounded border px-2 py-1.5 text-sm"
          value={to}
          onChange={(e) => setTo(e.target.value)}
        />
        <input
          className="rounded border px-2 py-1.5 text-sm lg:col-span-4"
          placeholder="Szukaj (nazwa, e-mail, IP, akcja…)"
          value={q}
          onChange={(e) => setQ(e.target.value)}
        />
        <button
          type="submit"
          disabled={busy}
          className="rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700 disabled:opacity-50 lg:col-span-2"
        >
          Filtruj
        </button>
      </form>

      <div className="overflow-x-auto rounded-xl bg-white shadow-sm">
        <table className="min-w-full text-left text-sm">
          <thead className="border-b bg-slate-50 text-xs uppercase text-slate-500">
            <tr>
              <th className="px-3 py-2">Kiedy</th>
              <th className="px-3 py-2">Użytkownik</th>
              <th className="px-3 py-2">Akcja</th>
              <th className="px-3 py-2">Obiekt</th>
              <th className="px-3 py-2">IP</th>
              <th className="px-3 py-2" />
            </tr>
          </thead>
          <tbody>
            {rows.length === 0 && (
              <tr>
                <td colSpan={6} className="px-3 py-6 text-center text-slate-500">
                  {busy ? 'Ładowanie…' : 'Brak wpisów.'}
                </td>
              </tr>
            )}
            {rows.map((row) => (
              <Fragment key={row.id}>
                <tr className="border-b last:border-0">
                  <td className="whitespace-nowrap px-3 py-2 text-slate-700">{formatWhen(row.created_at)}</td>
                  <td className="px-3 py-2">
                    <div className="font-medium text-slate-800">{row.user.name}</div>
                    {row.user.email && <div className="text-xs text-slate-500">{row.user.email}</div>}
                  </td>
                  <td className="px-3 py-2">
                    <div className="text-slate-800">{row.label ?? row.action}</div>
                    <div className="font-mono text-xs text-slate-400">{row.action}</div>
                  </td>
                  <td className="px-3 py-2 text-slate-600">{subjectLabel(row.subject_type, row.subject_id)}</td>
                  <td className="px-3 py-2 font-mono text-xs text-slate-500">{row.ip_address ?? '—'}</td>
                  <td className="px-3 py-2 text-right">
                    <button
                      type="button"
                      className="text-xs text-blue-600 hover:underline"
                      onClick={() => setExpanded(expanded === row.id ? null : row.id)}
                    >
                      {expanded === row.id ? 'Ukryj' : 'Szczegóły'}
                    </button>
                  </td>
                </tr>
                {expanded === row.id && (
                  <tr className="border-b bg-slate-50">
                    <td colSpan={6} className="px-3 py-2">
                      <pre className="max-h-64 overflow-auto whitespace-pre-wrap text-xs text-slate-700">
                        {JSON.stringify(row.meta ?? {}, null, 2)}
                      </pre>
                    </td>
                  </tr>
                )}
              </Fragment>
            ))}
          </tbody>
        </table>
      </div>

      <div className="mt-3 flex items-center gap-3 text-sm">
        <button
          type="button"
          disabled={busy || page <= 1}
          className="rounded border px-2 py-1 disabled:opacity-40"
          onClick={() => void load(page - 1)}
        >
          ← Poprzednia
        </button>
        <span className="text-slate-600">
          Strona {page} / {lastPage}
        </span>
        <button
          type="button"
          disabled={busy || page >= lastPage}
          className="rounded border px-2 py-1 disabled:opacity-40"
          onClick={() => void load(page + 1)}
        >
          Następna →
        </button>
      </div>
    </div>
  )
}
