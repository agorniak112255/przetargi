import { useEffect, useState, type FormEvent } from 'react'
import { api } from '../lib/api'

type Client = {
  id: number
  name: string
  nip: string | null
  city: string | null
  tenders_count: number
  owner?: { name: string }
}

export function Clients() {
  const [rows, setRows] = useState<Client[]>([])
  const [open, setOpen] = useState(false)
  const [name, setName] = useState('')
  const [nip, setNip] = useState('')
  const [city, setCity] = useState('')
  const [err, setErr] = useState('')
  const [msg, setMsg] = useState('')
  const [busy, setBusy] = useState(false)

  async function load() {
    setRows(await api<Client[]>('/clients'))
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

  return (
    <div>
      <div className="mb-4 flex items-center justify-between gap-3">
        <h1 className="text-xl font-semibold">Klienci</h1>
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
        <table className="w-full text-left text-xs">
          <thead>
            <tr className="border-b bg-slate-50">
              <th className="p-2">Nazwa</th>
              <th className="p-2">NIP</th>
              <th className="p-2">Miasto</th>
              <th className="p-2">Przetargi</th>
              <th className="p-2">Opiekun</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((c) => (
              <tr key={c.id} className="border-b">
                <td className="p-2">{c.name}</td>
                <td className="p-2">{c.nip ?? '—'}</td>
                <td className="p-2">{c.city ?? '—'}</td>
                <td className="p-2">{c.tenders_count}</td>
                <td className="p-2">{c.owner?.name ?? '—'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}
