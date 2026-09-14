import { useEffect, useState, type FormEvent } from 'react'
import { useAuth } from '../auth'
import { PriceListsTabs } from '../components/PriceListsTabs'
import { api, can } from '../lib/api'

type B2bAccount = {
  id: number
  username: string
  has_password: boolean
  sites: string[]
  note: string | null
  created_by: { id: number; name: string } | null
  updated_by: { id: number; name: string } | null
  updated_at: string | null
}

type FormState = {
  id: number | null
  username: string
  password: string
  sites: string
  note: string
}

const EMPTY_FORM: FormState = { id: null, username: '', password: '', sites: '', note: '' }

function siteHref(site: string): string {
  return /^https?:\/\//i.test(site) ? site : `https://${site}`
}

function siteLabel(site: string): string {
  return site.replace(/^https?:\/\//i, '').replace(/\/$/, '')
}

export function PriceListsB2b() {
  const { user } = useAuth()
  const canManage = can(user, 'b2b_accounts.manage')
  const [rows, setRows] = useState<B2bAccount[]>([])
  const [loading, setLoading] = useState(true)
  const [form, setForm] = useState<FormState | null>(null)
  const [busy, setBusy] = useState(false)
  const [revealed, setRevealed] = useState<Record<number, string>>({})
  const [visible, setVisible] = useState<Record<number, boolean>>({})
  const [msg, setMsg] = useState('')
  const [err, setErr] = useState('')

  async function load() {
    setRows(await api<B2bAccount[]>('/b2b-accounts'))
  }

  useEffect(() => {
    load()
      .catch((ex) => setErr(ex instanceof Error ? ex.message : 'Błąd wczytywania'))
      .finally(() => setLoading(false))
  }, [])

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
            Dane logowania do witryn B2B. Hasła są zaszyfrowane; każde odsłonięcie trafia do dziennika aktywności.
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
            className="rounded bg-blue-600 px-3 py-2 text-xs text-white hover:bg-blue-700"
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
            <div className="flex items-center justify-between gap-2 border-t border-slate-100 px-4 py-3">
              <p className="text-[11px] text-slate-400">
                {row.updated_by ? `Zmienił: ${row.updated_by.name}` : ''}
                {row.updated_at ? ` · ${new Date(row.updated_at).toLocaleString('pl-PL')}` : ''}
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
    </div>
  )
}
