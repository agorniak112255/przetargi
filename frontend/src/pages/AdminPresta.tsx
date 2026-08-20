import { useEffect, useState, type FormEvent } from 'react'
import { api } from '../lib/api'

type PrestaSettings = {
  enabled: boolean
  host: string
  port: number
  database: string
  username: string
  prefix: string
  id_lang: number
  shop_url: string
  has_password: boolean
  source: string
}

export function AdminPresta() {
  const [err, setErr] = useState('')
  const [msg, setMsg] = useState('')
  const [busy, setBusy] = useState(false)
  const [enabled, setEnabled] = useState(false)
  const [host, setHost] = useState('')
  const [port, setPort] = useState('3306')
  const [database, setDatabase] = useState('')
  const [username, setUsername] = useState('')
  const [password, setPassword] = useState('')
  const [hasPassword, setHasPassword] = useState(false)
  const [prefix, setPrefix] = useState('ps_')
  const [idLang, setIdLang] = useState('1')
  const [shopUrl, setShopUrl] = useState('https://supon.rzeszow.pl')
  const [source, setSource] = useState('')

  async function load() {
    const data = await api<PrestaSettings>('/admin/presta-settings')
    setEnabled(Boolean(data.enabled))
    setHost(data.host ?? '')
    setPort(String(data.port || 3306))
    setDatabase(data.database ?? '')
    setUsername(data.username ?? '')
    setHasPassword(Boolean(data.has_password))
    setPrefix(data.prefix || 'ps_')
    setIdLang(String(data.id_lang || 1))
    setShopUrl(data.shop_url || 'https://supon.rzeszow.pl')
    setSource(data.source)
  }

  useEffect(() => {
    void load().catch((e: Error) => setErr(e.message))
  }, [])

  async function onSave(e: FormEvent) {
    e.preventDefault()
    setBusy(true)
    setErr('')
    setMsg('')
    try {
      const body: Record<string, unknown> = {
        enabled,
        host: host.trim() || null,
        port: Number(port),
        database: database.trim() || null,
        username: username.trim() || null,
        prefix: prefix.trim() || 'ps_',
        id_lang: Number(idLang),
        shop_url: shopUrl.trim() || null,
      }
      if (password.trim()) body.password = password.trim()
      await api('/admin/presta-settings', {
        method: 'PUT',
        body: JSON.stringify(body),
      })
      setPassword('')
      setMsg('Zapisano ustawienia sklepu.')
      await load()
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Błąd zapisu')
    } finally {
      setBusy(false)
    }
  }

  async function onTest() {
    setBusy(true)
    setErr('')
    setMsg('')
    try {
      const res = await api<{ ok: boolean; message: string }>('/admin/presta-settings/test', {
        method: 'POST',
        body: '{}',
      })
      setMsg(res.message)
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Błąd testu')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div>
      {err && <p className="mb-2 text-sm text-red-600">{err}</p>}
      {msg && <p className="mb-2 text-sm text-green-700">{msg}</p>}

      <form onSubmit={(e) => void onSave(e)} className="mb-4 grid max-w-3xl gap-3 rounded-xl bg-white p-4 shadow-sm sm:grid-cols-2">
        <div className="sm:col-span-2">
          <h2 className="text-sm font-semibold">Sklep PrestaShop (tylko odczyt)</h2>
          <p className="mt-1 text-xs text-slate-500">
            Osobny cykl „Wyszukaj w Presta” na liście produktów. Nie miesza się z pobieraniem AI /
            Tavily. Ceny i stany ze sklepu nie są zapisywane
            {source ? ` · źródło: ${source}` : ''}.
          </p>
        </div>

        <label className="flex items-center gap-2 text-xs sm:col-span-2">
          <input type="checkbox" checked={enabled} onChange={(e) => setEnabled(e.target.checked)} />
          Włącz połączenie
        </label>

        <label className="text-xs">
          Host MySQL
          <input
            className="mt-1 w-full rounded border px-2 py-1.5 text-sm"
            value={host}
            onChange={(e) => setHost(e.target.value)}
            placeholder="127.0.0.1 albo vpn.supon.rzeszow.pl"
          />
        </label>
        <label className="text-xs">
          Port
          <input
            className="mt-1 w-full rounded border px-2 py-1.5 text-sm"
            value={port}
            onChange={(e) => setPort(e.target.value)}
          />
        </label>
        <label className="text-xs">
          Baza
          <input
            className="mt-1 w-full rounded border px-2 py-1.5 text-sm"
            value={database}
            onChange={(e) => setDatabase(e.target.value)}
            placeholder="supon_presta"
          />
        </label>
        <label className="text-xs">
          Użytkownik (SELECT)
          <input
            className="mt-1 w-full rounded border px-2 py-1.5 text-sm"
            value={username}
            onChange={(e) => setUsername(e.target.value)}
            placeholder="rag_readonly"
          />
        </label>
        <label className="text-xs sm:col-span-2">
          Hasło {hasPassword ? '(zapisane, wpisz aby zmienić)' : ''}
          <input
            type="password"
            className="mt-1 w-full rounded border px-2 py-1.5 text-sm"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            autoComplete="new-password"
          />
        </label>
        <label className="text-xs">
          Prefiks tabel
          <input
            className="mt-1 w-full rounded border px-2 py-1.5 text-sm"
            value={prefix}
            onChange={(e) => setPrefix(e.target.value)}
          />
        </label>
        <label className="text-xs">
          id_lang (PL = 1)
          <input
            className="mt-1 w-full rounded border px-2 py-1.5 text-sm"
            value={idLang}
            onChange={(e) => setIdLang(e.target.value)}
          />
        </label>
        <label className="text-xs sm:col-span-2">
          URL sklepu
          <input
            className="mt-1 w-full rounded border px-2 py-1.5 text-sm"
            value={shopUrl}
            onChange={(e) => setShopUrl(e.target.value)}
          />
        </label>

        <div className="flex gap-2 sm:col-span-2">
          <button
            type="submit"
            disabled={busy}
            className="rounded bg-slate-800 px-3 py-1.5 text-xs text-white disabled:opacity-50"
          >
            Zapisz
          </button>
          <button
            type="button"
            disabled={busy}
            onClick={() => void onTest()}
            className="rounded border border-slate-300 px-3 py-1.5 text-xs disabled:opacity-50"
          >
            Test połączenia
          </button>
        </div>
      </form>
    </div>
  )
}
