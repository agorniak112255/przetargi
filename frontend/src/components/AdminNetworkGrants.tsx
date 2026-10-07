import { useCallback, useEffect, useState } from 'react'
import { api } from '../lib/api'
import { fmtDateTime } from '../lib/campaignFormat'

type NetworkGrant = {
  id: number
  user: { id: number; name: string; email: string }
  /** adres IPv4 albo sieć IPv6 w postaci `2a02:a311:...::/64` */
  ip: string
  created_at: string
  expires_at: string
}

/**
 * Aktywne dostępy spoza sieci lokalnej potwierdzone kodem z e-maila (konta „z sieci lokalnej, spoza niej z kodem
 * e-mailem”) — w Administracji → Role pod adresami sieci lokalnej. Dostęp trwa 24 godziny od wpisania kodu;
 * „Odbierz” kończy go od razu (także dla dodatku Thunderbirda pracującego z tego adresu).
 */
export function AdminNetworkGrants() {
  const [grants, setGrants] = useState<NetworkGrant[] | null>(null)
  const [err, setErr] = useState('')
  const [msg, setMsg] = useState('')
  const [busy, setBusy] = useState(false)

  const load = useCallback(async () => {
    setBusy(true)
    setErr('')
    try {
      const res = await api<{ grants: NetworkGrant[] }>('/admin/network-access-grants')
      setGrants(res.grants)
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Błąd')
    } finally {
      setBusy(false)
    }
  }, [])

  useEffect(() => {
    void load()
  }, [load])

  async function revoke(g: NetworkGrant) {
    if (!window.confirm(`Odebrać dostęp z kodem osobie ${g.user.name} (adres ${g.ip})? Spoza sieci lokalnej będzie musiała znowu wpisać kod.`)) return
    setBusy(true)
    setErr('')
    setMsg('')
    try {
      await api(`/admin/network-access-grants/${g.id}`, { method: 'DELETE' })
      setMsg(`Odebrano dostęp: ${g.user.name}, ${g.ip}.`)
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Błąd')
    } finally {
      setBusy(false)
    }
    await load()
  }

  return (
    <section className="mt-8 max-w-4xl rounded-xl bg-white p-4 shadow-sm">
      <div className="mb-1 flex flex-wrap items-center justify-between gap-2">
        <h2 className="text-sm font-semibold text-slate-800">Dostęp spoza sieci z kodem — aktywne</h2>
        <button
          type="button"
          disabled={busy}
          onClick={() => {
            setMsg('')
            void load()
          }}
          className="rounded bg-slate-200 px-2 py-0.5 text-xs text-slate-800 hover:bg-slate-300 disabled:opacity-50"
        >
          Odśwież
        </button>
      </div>
      <p className="mb-3 text-xs text-slate-500">
        Konto albo grupa z ustawieniem „z sieci lokalnej, spoza niej z kodem e-mailem” poza biurem dostaje kod na
        e-mail. Po wpisaniu kodu konto pracuje z tego adresu przez 24 godziny (także dodatek do Thunderbirda). Zmiana
        hasła odbiera wszystkie takie dostępy. „Odbierz” kończy dostęp od razu.
      </p>
      {err && <p className="mb-2 text-sm text-red-600">{err}</p>}
      {msg && <p className="mb-2 text-sm text-green-700">{msg}</p>}
      {grants !== null && grants.length === 0 && (
        <p className="text-sm text-slate-500">Nikt nie ma teraz dostępu z kodem.</p>
      )}
      {grants !== null && grants.length > 0 && (
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b text-left text-xs text-slate-500">
                <th className="p-2 font-medium">Osoba</th>
                <th className="p-2 font-medium">Adres</th>
                <th className="p-2 font-medium">Od kiedy</th>
                <th className="p-2 font-medium">Do kiedy</th>
                <th className="p-2" />
              </tr>
            </thead>
            <tbody>
              {grants.map((g) => (
                <tr key={g.id} className="border-b last:border-0">
                  <td className="p-2">
                    <div className="text-slate-900">{g.user.name}</div>
                    <div className="text-xs text-slate-500">{g.user.email}</div>
                  </td>
                  <td className="p-2 font-mono text-xs break-all">{g.ip}</td>
                  <td className="p-2 whitespace-nowrap">{fmtDateTime(g.created_at)}</td>
                  <td className="p-2 whitespace-nowrap">{fmtDateTime(g.expires_at)}</td>
                  <td className="p-2 text-right">
                    <button
                      type="button"
                      disabled={busy}
                      onClick={() => void revoke(g)}
                      className="rounded bg-red-100 px-2 py-1 text-xs text-red-700 disabled:opacity-50"
                    >
                      Odbierz
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  )
}
