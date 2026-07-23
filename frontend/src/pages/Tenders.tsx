import { useEffect, useState, type FormEvent } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { useAuth } from '../auth'
import { api, can, type Tender } from '../lib/api'

type Client = { id: number; name: string }

export function Tenders() {
  const { user } = useAuth()
  const navigate = useNavigate()
  const [params, setParams] = useSearchParams()
  const filter = params.get('filter') ?? ''
  const [rows, setRows] = useState<Tender[]>([])
  const [clients, setClients] = useState<Client[]>([])
  const [open, setOpen] = useState(false)
  const [title, setTitle] = useState('')
  const [clientId, setClientId] = useState('')
  const [deadline, setDeadline] = useState('')
  const [err, setErr] = useState('')
  const [busy, setBusy] = useState(false)
  const seeAll = can(user, 'tenders.view_all')

  async function load() {
    const qs = filter ? `?filter=${encodeURIComponent(filter)}` : ''
    const [t, c] = await Promise.all([
      api<Tender[]>(`/tenders${qs}`),
      api<Client[]>('/clients'),
    ])
    setRows(t)
    setClients(c)
    if (!clientId && c[0]) setClientId(String(c[0].id))
  }

  useEffect(() => {
    void load()
  }, [filter])

  async function onCreate(e: FormEvent) {
    e.preventDefault()
    setBusy(true)
    setErr('')
    try {
      const t = await api<Tender>('/tenders', {
        method: 'POST',
        body: JSON.stringify({
          title,
          client_id: Number(clientId),
          deadline: deadline || null,
        }),
      })
      setOpen(false)
      setTitle('')
      navigate(`/tenders/${t.id}`)
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Błąd tworzenia')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div>
      <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-xl font-semibold">Przetargi</h1>
        <div className="flex flex-wrap items-center gap-2">
          <select
            className="rounded border border-slate-300 px-2 py-1.5 text-xs"
            value={filter}
            onChange={(e) => {
              const v = e.target.value
              if (v) setParams({ filter: v })
              else setParams({})
            }}
          >
            <option value="">{seeAll ? 'Wszystkie przetargi' : 'Moje i zaproszenia'}</option>
            <option value="mine">Tylko moje (opiekun)</option>
            <option value="invited">Tylko zaproszenia</option>
            <option value="deadline_soon">Deadline &lt; 7 dni</option>
          </select>
          <button
            type="button"
            onClick={() => setOpen((v) => !v)}
            className="rounded bg-blue-600 px-3 py-2 text-xs text-white hover:bg-blue-700"
          >
            + Nowy przetarg
          </button>
        </div>
      </div>

      {open && (
        <form onSubmit={onCreate} className="mb-4 rounded-xl bg-white p-4 shadow-sm text-sm">
          <h2 className="mb-3 font-semibold">Nowy przetarg</h2>
          {err && <p className="mb-2 text-xs text-red-600">{err}</p>}
          <div className="grid gap-3 sm:grid-cols-3">
            <label className="block text-xs">
              Tytuł
              <input
                required
                className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5"
                value={title}
                onChange={(e) => setTitle(e.target.value)}
                placeholder="np. Pakiet rękawic Q2"
              />
            </label>
            <label className="block text-xs">
              Klient
              <select
                required
                className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5"
                value={clientId}
                onChange={(e) => setClientId(e.target.value)}
              >
                {clients.map((c) => (
                  <option key={c.id} value={c.id}>
                    {c.name}
                  </option>
                ))}
              </select>
            </label>
            <label className="block text-xs">
              Termin
              <input
                type="date"
                className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5"
                value={deadline}
                onChange={(e) => setDeadline(e.target.value)}
              />
            </label>
          </div>
          <button
            type="submit"
            disabled={busy}
            className="mt-3 rounded bg-blue-600 px-3 py-2 text-xs text-white disabled:opacity-50"
          >
            Utwórz
          </button>
        </form>
      )}

      <div className="rounded-xl bg-white p-4 shadow-sm">
        <table className="w-full text-left text-xs">
          <thead>
            <tr className="border-b bg-slate-50">
              <th className="p-2">Numer</th>
              <th className="p-2">Klient</th>
              <th className="p-2">Termin</th>
              <th className="p-2">Wartość</th>
              <th className="p-2">Poz.</th>
              <th className="p-2">Status</th>
              <th className="p-2">AI %</th>
              <th className="p-2">Opiekun</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((t) => (
              <tr key={t.id} className="border-b hover:bg-slate-50">
                <td className="p-2">
                  <Link className="font-medium text-blue-600 hover:underline" to={`/tenders/${t.id}`}>
                    {t.number}
                  </Link>
                </td>
                <td className="p-2">{t.client?.name}</td>
                <td className="p-2">
                  {t.deadline ?? '—'}
                  {t.deadline &&
                    new Date(t.deadline) <= new Date(Date.now() + 7 * 86400000) &&
                    new Date(t.deadline) >= new Date(new Date().toDateString()) && (
                      <span className="ml-1 font-semibold text-red-600" title="Termin w ciągu 7 dni">
                        !
                      </span>
                    )}
                </td>
                <td className="p-2">
                  {t.offer_value_net
                    ? `${Number(t.offer_value_net).toLocaleString('pl-PL')} zł`
                    : '—'}
                </td>
                <td className="p-2">{t.items_count ?? 0}</td>
                <td className="p-2">{t.status}</td>
                <td className="p-2">{t.ai_percent}%</td>
                <td className="p-2">{t.owner?.name}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}
