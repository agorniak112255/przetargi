import { useEffect, useState } from 'react'
import { api } from '../lib/api'

type LocalNetwork = { id?: number; address: string; label: string | null }

type LocalNetworksResponse = {
  networks: LocalNetwork[]
  /** adres, z którego serwer widzi to żądanie (publiczny adres biura albo domu) */
  your_ip: string | null
  your_ip_is_local: boolean
  /** false = kontrola wyłączona w .env (NETWORK_ACCESS_ENFORCE=false) */
  enforced: boolean
}

type Row = { address: string; label: string }

function toRows(networks: LocalNetwork[]): Row[] {
  return networks.map((n) => ({ address: n.address, label: n.label ?? '' }))
}

/**
 * Adresy sieci lokalnej w Administracji → Role (sekcja pod uprawnieniami, przed Zespołami). Konto albo grupa „tylko z sieci lokalnej” pracuje tylko
 * z tych adresów. Aplikacja stoi na serwerze w Internecie, więc liczy się publiczny adres biura (ten, który serwer
 * widzi jako „Twój adres”), a nie adresy 192.168.… z sieci wewnętrznej.
 */
export function AdminLocalNetworks() {
  const [data, setData] = useState<LocalNetworksResponse | null>(null)
  const [rows, setRows] = useState<Row[]>([])
  const [err, setErr] = useState('')
  const [msg, setMsg] = useState('')
  const [busy, setBusy] = useState(false)

  function apply(res: LocalNetworksResponse) {
    setData(res)
    setRows(toRows(res.networks))
  }

  useEffect(() => {
    let cancelled = false
    api<LocalNetworksResponse>('/admin/local-networks')
      .then((res) => {
        if (!cancelled) apply(res)
      })
      .catch((e: Error) => {
        if (!cancelled) setErr(e.message)
      })
    return () => {
      cancelled = true
    }
  }, [])

  const dirty = data !== null && JSON.stringify(rows) !== JSON.stringify(toRows(data.networks))
  const yourIp = data?.your_ip ?? null
  const yourIpListed = yourIp !== null && rows.some((r) => r.address.trim() === yourIp)

  function update(i: number, patch: Partial<Row>) {
    setRows((prev) => prev.map((r, j) => (j === i ? { ...r, ...patch } : r)))
  }

  async function save() {
    setBusy(true)
    setErr('')
    setMsg('')
    try {
      const res = await api<LocalNetworksResponse>('/admin/local-networks', {
        method: 'PUT',
        body: JSON.stringify({
          networks: rows
            .filter((r) => r.address.trim() !== '' || r.label.trim() !== '')
            .map((r) => ({ address: r.address, label: r.label.trim() || null })),
        }),
      })
      apply(res)
      setMsg('Zapisano adresy sieci lokalnej.')
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Błąd')
    } finally {
      setBusy(false)
    }
  }

  return (
    <section id="siec-lokalna" className="mt-8 max-w-4xl rounded-xl bg-white p-4 shadow-sm">
      <h2 className="mb-1 text-sm font-semibold text-slate-800">Sieć lokalna — adresy IP</h2>
      <p className="mb-3 text-xs text-slate-500">
        Konto albo grupa z ustawieniem „tylko z sieci lokalnej” zaloguje się i będzie pracować tylko z tych adresów
        (dotyczy też dodatku do Thunderbirda). Wpisz publiczny adres biura — taki, jaki pokazuje się niżej jako „Twój
        adres”, gdy jesteś w biurze — albo zakres, np. <code>91.189.223.0/24</code>. Adresy 192.168.… z sieci
        wewnętrznej tu nie działają: serwer ich nie widzi.
      </p>
      {data && !data.enforced && (
        <p className="mb-2 rounded bg-amber-50 p-2 text-xs text-amber-800">
          Kontrola dostępu z sieci jest wyłączona na serwerze (NETWORK_ACCESS_ENFORCE=false) — teraz wszyscy pracują z
          każdej sieci.
        </p>
      )}
      {yourIp && (
        <p className="mb-3 text-sm">
          Twój adres: <code className="font-semibold">{yourIp}</code>{' '}
          {data?.your_ip_is_local ? (
            <span className="text-green-700">— w sieci lokalnej</span>
          ) : (
            <span className="text-slate-500">— poza siecią lokalną</span>
          )}
          {!yourIpListed && (
            <button
              type="button"
              disabled={busy}
              onClick={() => setRows((prev) => [...prev, { address: yourIp, label: '' }])}
              className="ml-2 rounded bg-slate-200 px-2 py-0.5 text-xs text-slate-800 hover:bg-slate-300 disabled:opacity-50"
            >
              Dodaj mój adres
            </button>
          )}
        </p>
      )}
      {err && <p className="mb-2 text-sm text-red-600">{err}</p>}
      {msg && <p className="mb-2 text-sm text-green-700">{msg}</p>}
      {data && rows.length === 0 && <p className="mb-2 text-sm text-slate-500">Brak adresów.</p>}
      <ul className="mb-3 space-y-1.5">
        {rows.map((r, i) => (
          <li key={i} className="flex flex-wrap items-center gap-2">
            <input
              aria-label="Adres IP albo zakres"
              placeholder="np. 91.189.223.20"
              className="w-56 rounded border px-2 py-1 font-mono text-sm"
              value={r.address}
              onChange={(e) => update(i, { address: e.target.value })}
            />
            <input
              aria-label="Opis adresu"
              placeholder="opis, np. Biuro Rzeszów"
              className="min-w-[12rem] flex-1 rounded border px-2 py-1 text-sm"
              value={r.label}
              maxLength={120}
              onChange={(e) => update(i, { label: e.target.value })}
            />
            <button
              type="button"
              disabled={busy}
              onClick={() => setRows((prev) => prev.filter((_, j) => j !== i))}
              className="rounded bg-red-100 px-2 py-1 text-xs text-red-700 disabled:opacity-50"
            >
              Usuń
            </button>
          </li>
        ))}
      </ul>
      <div className="flex flex-wrap gap-2">
        <button
          type="button"
          disabled={busy || data === null}
          onClick={() => setRows((prev) => [...prev, { address: '', label: '' }])}
          className="rounded bg-slate-200 px-3 py-1.5 text-sm text-slate-800 hover:bg-slate-300 disabled:opacity-50"
        >
          Dodaj adres
        </button>
        <button
          type="button"
          disabled={busy || !dirty}
          onClick={() => void save()}
          className="rounded bg-blue-600 px-3 py-1.5 text-sm text-white hover:bg-blue-700 disabled:opacity-50"
        >
          Zapisz adresy
        </button>
      </div>
    </section>
  )
}
