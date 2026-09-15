import { useEffect, useState, type FormEvent } from 'react'
import { useAuth } from '../auth'
import { B2bSyncProgressModal } from '../components/B2bSyncProgressModal'
import { PriceListsTabs } from '../components/PriceListsTabs'
import { api, can } from '../lib/api'

type SyncFrequency = 'off' | 'daily' | 'weekly'

type B2bAccount = {
  id: number
  username: string
  has_password: boolean
  sites: string[]
  note: string | null
  connector: string | null
  connector_label: string | null
  sync_frequency: SyncFrequency
  sync_images: boolean
  sync_requested_at: string | null
  last_sync_status: 'running' | 'ok' | 'failed' | 'cancelled' | null
  last_sync_started_at: string | null
  last_sync_finished_at: string | null
  last_sync_message: string | null
  last_price_list_id: number | null
  created_by: { id: number; name: string } | null
  updated_by: { id: number; name: string } | null
  updated_at: string | null
}

type Connector = { key: string; label: string; host: string }

type FormState = {
  id: number | null
  username: string
  password: string
  sites: string
  note: string
  connector: string
  sync_frequency: SyncFrequency
  sync_images: boolean
}

const EMPTY_FORM: FormState = {
  id: null,
  username: '',
  password: '',
  sites: '',
  note: '',
  connector: '',
  sync_frequency: 'off',
  sync_images: true,
}

const FREQUENCY_LABEL: Record<SyncFrequency, string> = {
  off: 'wyłączone',
  daily: 'codziennie (w nocy)',
  weekly: 'raz w tygodniu (w nocy)',
}

function siteHref(site: string): string {
  return /^https?:\/\//i.test(site) ? site : `https://${site}`
}

function siteLabel(site: string): string {
  return site.replace(/^https?:\/\//i, '').replace(/\/$/, '')
}

function formatDate(value: string | null): string {
  return value ? new Date(value).toLocaleString('pl-PL') : ''
}

export function PriceListsB2b() {
  const { user } = useAuth()
  const canManage = can(user, 'b2b_accounts.manage')
  const [rows, setRows] = useState<B2bAccount[]>([])
  const [connectors, setConnectors] = useState<Connector[]>([])
  const [loading, setLoading] = useState(true)
  const [form, setForm] = useState<FormState | null>(null)
  const [busy, setBusy] = useState(false)
  const [revealed, setRevealed] = useState<Record<number, string>>({})
  const [visible, setVisible] = useState<Record<number, boolean>>({})
  const [progressAccount, setProgressAccount] = useState<B2bAccount | null>(null)
  const [msg, setMsg] = useState('')
  const [err, setErr] = useState('')

  async function load() {
    setRows(await api<B2bAccount[]>('/b2b-accounts'))
  }

  useEffect(() => {
    Promise.all([load(), api<Connector[]>('/b2b-connectors').then(setConnectors)])
      .catch((ex) => setErr(ex instanceof Error ? ex.message : 'Błąd wczytywania'))
      .finally(() => setLoading(false))
  }, [])

  const syncInProgress = rows.some((r) => r.last_sync_status === 'running' || r.sync_requested_at !== null)
  useEffect(() => {
    if (!syncInProgress) return
    const timer = window.setInterval(() => {
      void load().catch(() => {})
    }, 30000)
    return () => window.clearInterval(timer)
  }, [syncInProgress])

  async function fetchPassword(id: number): Promise<string> {
    if (revealed[id] !== undefined) return revealed[id]
    const res = await api<{ password: string }>(`/b2b-accounts/${id}/password`, { method: 'POST' })
    setRevealed((prev) => ({ ...prev, [id]: res.password }))
    return res.password
  }

  async function togglePassword(id: number) {
    setErr('')
    if (visible[id]) {
      setVisible((prev) => ({ ...prev, [id]: false }))
      return
    }
    try {
      await fetchPassword(id)
      setVisible((prev) => ({ ...prev, [id]: true }))
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Nie udało się pobrać hasła')
    }
  }

  async function copy(text: string | Promise<string>, label: string) {
    setErr('')
    setMsg('')
    try {
      await navigator.clipboard.writeText(await text)
      setMsg(`Skopiowano: ${label}.`)
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Nie udało się skopiować')
    }
  }

  function startEdit(row: B2bAccount) {
    setMsg('')
    setErr('')
    setForm({
      id: row.id,
      username: row.username,
      password: '',
      sites: row.sites.join('\n'),
      note: row.note ?? '',
      connector: row.connector ?? '',
      sync_frequency: row.sync_frequency,
      sync_images: row.sync_images,
    })
  }

  async function onSubmit(e: FormEvent) {
    e.preventDefault()
    if (!form) return
    setBusy(true)
    setErr('')
    setMsg('')
    try {
      const body = JSON.stringify({
        username: form.username,
        password: form.password,
        sites: form.sites.split(/[\n,]/).map((s) => s.trim()).filter(Boolean),
        note: form.note.trim() || null,
        connector: form.connector || null,
        sync_frequency: form.sync_frequency,
        sync_images: form.sync_images,
      })
      if (form.id === null) {
        await api('/b2b-accounts', { method: 'POST', body })
        setMsg('Konto B2B dodane.')
      } else {
        await api(`/b2b-accounts/${form.id}`, { method: 'PATCH', body })
        setRevealed((prev) => {
          const next = { ...prev }
          delete next[form.id as number]
          return next
        })
        setVisible((prev) => ({ ...prev, [form.id as number]: false }))
        setMsg('Konto B2B zapisane.')
      }
      setForm(null)
      await load()
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Błąd zapisu')
    } finally {
      setBusy(false)
    }
  }

  async function onDelete(row: B2bAccount) {
    if (!window.confirm(`Usunąć konto „${row.username}” (${row.sites.map(siteLabel).join(', ')})?`)) return
    setBusy(true)
    setErr('')
    setMsg('')
    try {
      await api(`/b2b-accounts/${row.id}`, { method: 'DELETE' })
      setMsg('Konto B2B usunięte.')
      await load()
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Błąd usuwania')
    } finally {
      setBusy(false)
    }
  }

  async function onRequestSync(row: B2bAccount) {
    setBusy(true)
    setErr('')
    setMsg('')
    try {
      await api(`/b2b-accounts/${row.id}/sync`, { method: 'POST' })
      setMsg('Zlecono sprawdzenie cennika — ruszy w ciągu minuty i działa w tle.')
      setProgressAccount(row)
      await load()
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Nie udało się zlecić sprawdzenia')
    } finally {
      setBusy(false)
    }
  }

  function renderSyncStatus(row: B2bAccount) {
    if (row.last_sync_status === 'running') {
      return <p className="text-blue-700">Trwa pobieranie cennika (od {formatDate(row.last_sync_started_at)}).</p>
    }
    if (row.sync_requested_at) {
      return <p className="text-blue-700">Zlecono sprawdzenie — ruszy w ciągu minuty.</p>
    }
    if (!row.last_sync_status) {
      return <p className="text-slate-500">Cennik nie był jeszcze pobierany.</p>
    }
    const failed = row.last_sync_status === 'failed'
    const cancelled = row.last_sync_status === 'cancelled'
    return (
      <div className={failed ? 'text-red-700' : 'text-slate-600'}>
        <p className="font-medium">
          {failed
            ? 'Ostatnie pobieranie nie powiodło się'
            : cancelled
              ? 'Ostatnie pobieranie zatrzymane ręcznie'
              : 'Ostatnie pobieranie'}
          : {formatDate(row.last_sync_finished_at)}
        </p>
        {row.last_sync_message && <p className="mt-0.5 whitespace-pre-wrap">{row.last_sync_message}</p>}
      </div>
    )
  }

  const fieldBox = 'flex items-center gap-2 rounded-lg bg-slate-100 px-3 py-2 text-sm'
  const iconBtn = 'rounded px-1.5 py-0.5 text-xs text-slate-600 hover:bg-slate-200 hover:text-slate-900'
  const outlineBtn = 'rounded-full border border-blue-300 px-4 py-1.5 text-xs font-medium text-blue-700 hover:bg-blue-50 disabled:opacity-50'

  return (
    <div>
      <PriceListsTabs />
      <div className="mb-4 flex items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold">Konta B2B dostawców</h1>
          <p className="text-xs text-slate-500">
            Dane logowania do witryn B2B. Dla obsługiwanych witryn cennik (ceny konta, nowe produkty, opisy, zdjęcia)
            pobiera się automatycznie wg harmonogramu. Hasła są zaszyfrowane; każde odsłonięcie trafia do dziennika.
          </p>
        </div>
        {canManage && (
          <button
            type="button"
            onClick={() => {
              setMsg('')
              setErr('')
              setForm(EMPTY_FORM)
            }}
            className="shrink-0 rounded bg-blue-600 px-3 py-2 text-xs text-white hover:bg-blue-700"
          >
            + Dodaj konto B2B
          </button>
        )}
      </div>

      {msg && <p className="mb-2 rounded bg-green-50 px-3 py-2 text-xs text-green-800">{msg}</p>}
      {err && <p className="mb-2 rounded bg-red-50 px-3 py-2 text-xs text-red-700">{err}</p>}

      {canManage && form && (
        <form onSubmit={onSubmit} className="mb-4 rounded-xl bg-white p-4 text-sm shadow-sm">
          <h2 className="mb-3 font-semibold">{form.id === null ? 'Nowe konto B2B' : 'Edycja konta B2B'}</h2>
          <div className="grid gap-3 sm:grid-cols-2">
            <label className="block text-xs">
              Nazwa użytkownika *
              <input
                required
                autoComplete="off"
                className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5"
                value={form.username}
                onChange={(e) => setForm({ ...form, username: e.target.value })}
              />
            </label>
            <label className="block text-xs">
              Hasło {form.id === null ? '*' : ''}
              <input
                type="password"
                required={form.id === null}
                autoComplete="new-password"
                className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5"
                value={form.password}
                onChange={(e) => setForm({ ...form, password: e.target.value })}
                placeholder={form.id === null ? '' : 'zostaw puste, aby nie zmieniać'}
              />
            </label>
            <label className="block text-xs">
              Witryny * <span className="text-slate-400">(jedna w linii)</span>
              <textarea
                required
                rows={3}
                className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5"
                value={form.sites}
                onChange={(e) => setForm({ ...form, sites: e.target.value })}
                placeholder="b2b.anro.net.pl"
              />
            </label>
            <label className="block text-xs">
              Notatka
              <textarea
                rows={3}
                className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5"
                value={form.note}
                onChange={(e) => setForm({ ...form, note: e.target.value })}
              />
            </label>
            <label className="block text-xs">
              Importer cennika
              <select
                className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5"
                value={form.connector}
                onChange={(e) => setForm({ ...form, connector: e.target.value })}
              >
                <option value="">Wykryj z witryny</option>
                {connectors.map((c) => (
                  <option key={c.key} value={c.key}>
                    {c.label} ({c.host})
                  </option>
                ))}
              </select>
            </label>
            <div className="grid grid-cols-2 gap-3">
              <label className="block text-xs">
                Sprawdzanie cennika
                <select
                  className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5"
                  value={form.sync_frequency}
                  onChange={(e) => setForm({ ...form, sync_frequency: e.target.value as SyncFrequency })}
                >
                  <option value="off">Wyłączone</option>
                  <option value="daily">Codziennie (w nocy)</option>
                  <option value="weekly">Raz w tygodniu (w nocy)</option>
                </select>
              </label>
              <label className="mt-5 flex items-center gap-2 text-xs">
                <input
                  type="checkbox"
                  checked={form.sync_images}
                  onChange={(e) => setForm({ ...form, sync_images: e.target.checked })}
                />
                Pobieraj zdjęcia
              </label>
            </div>
          </div>
          <div className="mt-3 flex gap-2">
            <button
              type="submit"
              disabled={busy}
              className="rounded bg-blue-600 px-3 py-2 text-xs text-white disabled:opacity-50"
            >
              Zapisz
            </button>
            <button
              type="button"
              className="rounded border border-slate-300 px-3 py-2 text-xs"
              onClick={() => setForm(null)}
            >
              Anuluj
            </button>
          </div>
        </form>
      )}

      {loading && <p className="text-sm text-slate-500">Ładowanie…</p>}
      {!loading && rows.length === 0 && (
        <p className="rounded-xl bg-white p-4 text-sm text-slate-500 shadow-sm">Brak zapisanych kont B2B.</p>
      )}

      <div className="grid gap-4 xl:grid-cols-2">
        {rows.map((row) => (
          <div key={row.id} className="rounded-xl bg-white shadow-sm">
            <div className="grid gap-4 p-4 sm:grid-cols-2">
              <div className="space-y-3">
                <div>
                  <p className="mb-1 text-xs font-medium text-slate-600">Nazwa użytkownika</p>
                  <div className={fieldBox}>
                    <span className="min-w-0 flex-1 truncate">{row.username}</span>
                    <button
                      type="button"
                      className={iconBtn}
                      title="Kopiuj nazwę użytkownika"
                      onClick={() => void copy(row.username, 'nazwa użytkownika')}
                    >
                      Kopiuj
                    </button>
                  </div>
                </div>
                <div>
                  <p className="mb-1 text-xs font-medium text-slate-600">Hasło</p>
                  <div className={fieldBox}>
                    <span className="min-w-0 flex-1 truncate font-mono">
                      {!row.has_password ? '—' : visible[row.id] ? revealed[row.id] : '••••••••'}
                    </span>
                    {row.has_password && (
                      <>
                        <button
                          type="button"
                          className={iconBtn}
                          title={visible[row.id] ? 'Ukryj hasło' : 'Pokaż hasło'}
                          onClick={() => void togglePassword(row.id)}
                        >
                          {visible[row.id] ? 'Ukryj' : 'Pokaż'}
                        </button>
                        <button
                          type="button"
                          className={iconBtn}
                          title="Kopiuj hasło"
                          onClick={() => void copy(fetchPassword(row.id), 'hasło')}
                        >
                          Kopiuj
                        </button>
                      </>
                    )}
                  </div>
                </div>
              </div>
              <div className="space-y-3">
                <div>
                  <p className="mb-1 text-xs font-medium text-slate-600">Witryny</p>
                  <ul className="space-y-1 text-sm">
                    {row.sites.map((site) => (
                      <li key={site}>
                        <a
                          className="text-blue-700 underline"
                          href={siteHref(site)}
                          target="_blank"
                          rel="noopener noreferrer"
                        >
                          {siteLabel(site)}
                        </a>
                      </li>
                    ))}
                  </ul>
                </div>
                <div>
                  <p className="mb-1 text-xs font-medium text-slate-600">Notatka</p>
                  <div className={`${fieldBox} whitespace-pre-wrap`}>
                    {row.note ? row.note : <span className="text-slate-400">Nie dodano notatki</span>}
                  </div>
                </div>
              </div>
            </div>
            <div className="space-y-2 border-t border-slate-100 px-4 py-3 text-xs">
              {row.connector_label ? (
                <>
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <p className="text-slate-700">
                      Importer: <b>{row.connector_label}</b> · sprawdzanie: <b>{FREQUENCY_LABEL[row.sync_frequency]}</b>
                      {row.sync_images ? ' · ze zdjęciami' : ' · bez zdjęć'}
                    </p>
                    <div className="flex gap-3">
                      <button
                        type="button"
                        className="text-blue-700 underline"
                        onClick={() => setProgressAccount(row)}
                      >
                        Postęp i log
                      </button>
                      {canManage && (
                        <button
                          type="button"
                          className="text-blue-700 underline disabled:text-slate-400 disabled:no-underline"
                          disabled={busy || row.last_sync_status === 'running' || row.sync_requested_at !== null}
                          onClick={() => void onRequestSync(row)}
                        >
                          Sprawdź teraz
                        </button>
                      )}
                    </div>
                  </div>
                  {renderSyncStatus(row)}
                </>
              ) : (
                <p className="text-slate-500">Dla tej witryny nie ma jeszcze importera cennika.</p>
              )}
            </div>
            <div className="flex items-center justify-between gap-2 border-t border-slate-100 px-4 py-3">
              <p className="text-[11px] text-slate-400">
                #{row.id}
                {row.updated_by ? ` · zmienił: ${row.updated_by.name}` : ''}
                {row.updated_at ? ` · ${formatDate(row.updated_at)}` : ''}
              </p>
              {canManage && (
                <div className="flex gap-2">
                  <button type="button" className={outlineBtn} disabled={busy} onClick={() => startEdit(row)}>
                    Edytuj
                  </button>
                  <button type="button" className={outlineBtn} disabled={busy} onClick={() => void onDelete(row)}>
                    Usuń
                  </button>
                </div>
              )}
            </div>
          </div>
        ))}
      </div>

      {progressAccount && (
        <B2bSyncProgressModal
          account={progressAccount}
          canManage={canManage}
          onClose={() => setProgressAccount(null)}
          onChanged={() => void load().catch(() => {})}
        />
      )}
    </div>
  )
}
