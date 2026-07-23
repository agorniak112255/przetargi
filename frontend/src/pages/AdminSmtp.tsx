import { useEffect, useState, type FormEvent } from 'react'
import { useAuth } from '../auth'
import { api } from '../lib/api'

type MailSettings = {
  mailer: string
  host: string | null
  port: number
  username: string | null
  has_password: boolean
  scheme: string | null
  from_address: string | null
  from_name: string | null
  verify_peer: boolean
  source: string
}

export function AdminSmtp() {
  const { user } = useAuth()
  const [err, setErr] = useState('')
  const [msg, setMsg] = useState('')
  const [busy, setBusy] = useState(false)
  const [mailer, setMailer] = useState('smtp')
  const [host, setHost] = useState('')
  const [port, setPort] = useState('587')
  const [username, setUsername] = useState('')
  const [password, setPassword] = useState('')
  const [hasPassword, setHasPassword] = useState(false)
  const [scheme, setScheme] = useState('')
  const [fromAddress, setFromAddress] = useState('')
  const [fromName, setFromName] = useState('')
  const [verifyPeer, setVerifyPeer] = useState(false)
  const [testTo, setTestTo] = useState(user?.email ?? '')
  const [source, setSource] = useState('')

  async function load() {
    const data = await api<MailSettings>('/admin/mail-settings')
    setMailer(data.mailer || 'smtp')
    setHost(data.host ?? '')
    setPort(String(data.port || 587))
    setUsername(data.username ?? '')
    setHasPassword(Boolean(data.has_password))
    setScheme(data.scheme ?? '')
    setFromAddress(data.from_address ?? '')
    setFromName(data.from_name ?? '')
    setVerifyPeer(Boolean(data.verify_peer))
    setSource(data.source)
    if (!testTo && user?.email) setTestTo(user.email)
  }

  useEffect(() => {
    void load().catch((e: Error) => setErr(e.message))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  async function onSave(e: FormEvent) {
    e.preventDefault()
    setBusy(true)
    setErr('')
    setMsg('')
    try {
      const body: Record<string, unknown> = {
        mailer,
        host: host.trim() || null,
        port: Number(port),
        username: username.trim() || null,
        scheme: scheme || null,
        from_address: fromAddress.trim() || null,
        from_name: fromName.trim() || null,
        verify_peer: verifyPeer,
      }
      if (password.trim()) body.password = password.trim()
      await api('/admin/mail-settings', {
        method: 'PUT',
        body: JSON.stringify(body),
      })
      setPassword('')
      setMsg('Zapisano ustawienia SMTP.')
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
      const res = await api<{ ok: boolean; message: string }>('/admin/mail-settings/test', {
        method: 'POST',
        body: JSON.stringify({ to: testTo.trim() }),
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
          <h2 className="text-sm font-semibold">Poczta wychodząca (SMTP)</h2>
          <p className="mt-1 text-xs text-slate-500">
            Używane m.in. przy zaproszeniach do przetargów. Hasło nie jest pokazywane po zapisie
            {source ? ` · źródło: ${source}` : ''}.
          </p>
        </div>

        <label className="text-xs">
          Sterownik
          <select
            className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5 text-sm"
            value={mailer}
            onChange={(e) => setMailer(e.target.value)}
          >
            <option value="smtp">SMTP</option>
            <option value="log">Log (tylko zapis w logach)</option>
          </select>
        </label>

        <label className="text-xs">
          Schemat TLS
          <select
            className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5 text-sm"
            value={scheme}
            onChange={(e) => setScheme(e.target.value)}
          >
            <option value="">Auto (STARTTLS na 587)</option>
            <option value="smtp">smtp</option>
            <option value="smtps">smtps (zwykle port 465)</option>
          </select>
        </label>

        <label className="text-xs">
          Host
          <input
            className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5 text-sm"
            value={host}
            onChange={(e) => setHost(e.target.value)}
            placeholder="mail.supon.rzeszow.pl"
          />
        </label>

        <label className="text-xs">
          Port
          <input
            className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5 text-sm"
            type="number"
            min={1}
            max={65535}
            value={port}
            onChange={(e) => setPort(e.target.value)}
          />
        </label>

        <label className="text-xs">
          Login
          <input
            className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5 text-sm"
            value={username}
            onChange={(e) => setUsername(e.target.value)}
            placeholder="przetargi@supon.rzeszow.pl"
            autoComplete="off"
          />
        </label>

        <label className="text-xs">
          Hasło {hasPassword ? '(zapisane — zostaw puste, by nie zmieniać)' : ''}
          <input
            className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5 text-sm"
            type="password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            placeholder={hasPassword ? '••••••••' : 'Hasło SMTP'}
            autoComplete="new-password"
          />
        </label>

        <label className="text-xs">
          Adres nadawcy
          <input
            className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5 text-sm"
            type="email"
            value={fromAddress}
            onChange={(e) => setFromAddress(e.target.value)}
            placeholder="przetargi@supon.rzeszow.pl"
          />
        </label>

        <label className="text-xs">
          Nazwa nadawcy
          <input
            className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5 text-sm"
            value={fromName}
            onChange={(e) => setFromName(e.target.value)}
            placeholder="Przetargi Supon"
          />
        </label>

        <label className="sm:col-span-2 flex items-center gap-2 text-xs text-slate-700">
          <input
            type="checkbox"
            checked={!verifyPeer}
            onChange={(e) => setVerifyPeer(!e.target.checked)}
          />
          Ignoruj błędy certyfikatu SSL (gdy serwer ma niepasujący certyfikat)
        </label>

        <button
          type="submit"
          disabled={busy}
          className="sm:col-span-2 rounded bg-blue-600 px-3 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-50"
        >
          Zapisz SMTP
        </button>
      </form>

      <div className="grid max-w-3xl gap-2 rounded-xl bg-white p-4 shadow-sm sm:grid-cols-[1fr_auto]">
        <label className="text-xs">
          E-mail testowy
          <input
            className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5 text-sm"
            type="email"
            value={testTo}
            onChange={(e) => setTestTo(e.target.value)}
            placeholder="adres@domena.pl"
          />
        </label>
        <div className="flex items-end">
          <button
            type="button"
            disabled={busy || !testTo.trim()}
            onClick={() => void onTest()}
            className="rounded bg-emerald-600 px-3 py-2 text-sm text-white hover:bg-emerald-700 disabled:opacity-50"
          >
            Wyślij test
          </button>
        </div>
      </div>
    </div>
  )
}
