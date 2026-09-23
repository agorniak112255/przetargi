import { useEffect, useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../auth'
import { B2bDiscountRulesModal } from '../components/B2bDiscountRulesModal'
import { B2bManufacturerRulesModal } from '../components/B2bManufacturerRulesModal'
import { B2bSyncProgressModal } from '../components/B2bSyncProgressModal'
import { PriceListsTabs } from '../components/PriceListsTabs'
import { api, can } from '../lib/api'

type SyncFrequency = 'off' | 'daily' | 'weekly'

type B2bAccount = {
  id: number
  username: string
  contractor_code: string | null
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
  /** Dostawca wysyła kod na e-mail przy każdym logowaniu (3M) — pobieranie zleca się przez „Zaloguj kodem”. */
  requires_login_code: boolean
  /** Kiedy ostatnio zapisano sesję sklepu po logowaniu kodem (ISO 8601). */
  connector_session_saved_at: string | null
  created_by: { id: number; name: string } | null
  updated_by: { id: number; name: string } | null
  updated_at: string | null
}

type Connector = {
  key: string
  label: string
  host: string
  /** Witryna publiczna (protekt.pl) nie ma konta u dostawcy — bez hasła. */
  requires_password: boolean
  /** Konto ma reguły rabatu (przycisk „Rabaty”); co znaczą — w discount_rules_mode. */
  uses_discount_rules: boolean
  /**
   * price = ceny ze strony są katalogowe, cena zakupu powstaje z rabatów konta (protekt.pl);
   * standard = rabat standardowy od cennika bazowego, do wykrycia ceny specjalnej B2B (UVEX).
   */
  discount_rules_mode?: 'price' | 'standard' | null
  /** Logowanie wymaga kodu z e-maila (3M) — harmonogram nocny się nie uda. */
  requires_login_code: boolean
}

type FormState = {
  id: number | null
  username: string
  contractor_code: string
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
  contractor_code: '',
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
  /** Rabaty: witryny z samą ceną katalogową (protekt.pl) i łączniki z cennikiem bazowym (UVEX). */
  const usesDiscountRules = (key: string | null) =>
    connectors.some((c) => c.key === key && c.uses_discount_rules)
  const usesStandardDiscounts = (key: string | null) =>
    connectors.some((c) => c.key === key && c.discount_rules_mode === 'standard')

  /**
   * Łącznik wybrany w formularzu albo wykryty z wpisanych witryn — tak samo jak na serwerze,
   * żeby pole hasła znikało od razu po wpisaniu adresu witryny publicznej.
   */
  const formConnector = (f: FormState): Connector | undefined => {
    if (f.connector) return connectors.find((c) => c.key === f.connector)
    const sites = f.sites.toLowerCase()
    return connectors.find((c) => sites.includes(c.host))
  }

  const formNeedsPassword = (f: FormState) => formConnector(f)?.requires_password ?? true
  const [rows, setRows] = useState<B2bAccount[]>([])
  const [connectors, setConnectors] = useState<Connector[]>([])
  const [loading, setLoading] = useState(true)
  const [form, setForm] = useState<FormState | null>(null)
  const [busy, setBusy] = useState(false)
  const [revealed, setRevealed] = useState<Record<number, string>>({})
  const [visible, setVisible] = useState<Record<number, boolean>>({})
  const [progressAccount, setProgressAccount] = useState<B2bAccount | null>(null)
  const [discountAccount, setDiscountAccount] = useState<B2bAccount | null>(null)
  const [manufacturersAccount, setManufacturersAccount] = useState<B2bAccount | null>(null)
  const [codeLoginAccount, setCodeLoginAccount] = useState<B2bAccount | null>(null)
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
      contractor_code: row.contractor_code ?? '',
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
        contractor_code: form.contractor_code.trim() || null,
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

  /** Dostawca z kodem z e-maila: zamiast zlecać od razu, otwórz okno logowania kodem. */
  function startSync(row: B2bAccount) {
    if (row.requires_login_code) {
      setMsg('')
      setErr('')
      setCodeLoginAccount(row)
      return
    }
    void onRequestSync(row)
  }

  /** Po udanym kodzie serwer sam zleca pobieranie — dalej jak po „Sprawdź teraz”. */
  async function onCodeLoginDone(updated: B2bAccount) {
    setCodeLoginAccount(null)
    setMsg('Zalogowano kodem i zlecono sprawdzenie cennika — ruszy w ciągu minuty i działa w tle.')
    setProgressAccount(updated)
    await load().catch(() => {})
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
  // akcje importera przy koncie: obrys jak Edytuj/Usuń, „Sprawdź teraz” wypełnione jako główna akcja
  const actionBtn = 'rounded-full border border-blue-300 px-3 py-1 text-xs font-medium text-blue-700 hover:bg-blue-50 disabled:opacity-50'
  const primaryActionBtn = 'rounded-full bg-blue-600 px-3 py-1 text-xs font-medium text-white hover:bg-blue-700 disabled:opacity-50'
  const specialActionBtn = 'rounded-full border border-emerald-300 px-3 py-1 text-xs font-medium text-emerald-700 hover:bg-emerald-50'

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
              Kod kontrahenta <span className="text-slate-400">(tylko gdy witryna go wymaga, np. UVEX, Procera — NIP)</span>
              <input
                autoComplete="off"
                className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5"
                value={form.contractor_code}
                onChange={(e) => setForm({ ...form, contractor_code: e.target.value })}
              />
            </label>
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
            {formNeedsPassword(form) ? (
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
            ) : (
              <p className="self-end rounded bg-slate-50 px-3 py-2 text-xs text-slate-600">
                Ta witryna jest publiczna — nie ma logowania, więc hasło nie jest potrzebne. Ceny na stronie
                są katalogowe; upusty ustawisz przyciskiem „Rabaty" przy koncie.
              </p>
            )}
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
                {formConnector(form)?.requires_login_code && (
                  <span className="mt-1 block text-amber-700">
                    Ten dostawca wymaga kodu z e-maila przy każdym logowaniu, więc automatyczne pobieranie w nocy
                    się nie uda. Zostaw „Wyłączone” i pobieraj ręcznie przyciskiem „Zaloguj kodem” przy koncie.
                  </span>
                )}
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
                {row.contractor_code && (
                  <div>
                    <p className="mb-1 text-xs font-medium text-slate-600">Kod kontrahenta</p>
                    <div className={fieldBox}>
                      <span className="min-w-0 flex-1 truncate">{row.contractor_code}</span>
                      <button
                        type="button"
                        className={iconBtn}
                        title="Kopiuj kod kontrahenta"
                        onClick={() => void copy(row.contractor_code ?? '', 'kod kontrahenta')}
                      >
                        Kopiuj
                      </button>
                    </div>
                  </div>
                )}
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
                    <div className="flex flex-wrap items-center gap-2">
                      <button
                        type="button"
                        className={actionBtn}
                        onClick={() => setProgressAccount(row)}
                      >
                        Postęp i log
                      </button>
                      {usesDiscountRules(row.connector) && (
                        <button
                          type="button"
                          className={actionBtn}
                          onClick={() => setDiscountAccount(row)}
                          title={
                            usesStandardDiscounts(row.connector)
                              ? 'Rabaty standardowe na arkusze cennika bazowego — do wykrywania ceny specjalnej B2B'
                              : 'Rabaty od ceny katalogowej ze strony — dają cenę zakupu'
                          }
                        >
                          Rabaty
                        </button>
                      )}
                      <button
                        type="button"
                        className={actionBtn}
                        onClick={() => setManufacturersAccount(row)}
                        title="Producenci, których karty pobiera to konto — czy ten cennik ustala ich cenę i opis"
                      >
                        Producenci
                      </button>
                      {usesStandardDiscounts(row.connector) && (
                        <Link
                          className={specialActionBtn}
                          to={`/products?b2b_account=${row.id}&b2b_label=${encodeURIComponent(row.connector_label ?? row.username)}&supplier_special=special`}
                          title="Karty tego konta z ceną niższą niż cennik bazowy minus rabat standardowy"
                        >
                          Ceny specjalne
                        </Link>
                      )}
                      {canManage && (
                        <button
                          type="button"
                          className={primaryActionBtn}
                          disabled={busy || row.last_sync_status === 'running' || row.sync_requested_at !== null}
                          onClick={() => startSync(row)}
                        >
                          {row.requires_login_code ? 'Zaloguj kodem' : 'Sprawdź teraz'}
                        </button>
                      )}
                    </div>
                  </div>
                  {row.requires_login_code && (
                    <p className="text-slate-500">
                      {row.connector_session_saved_at
                        ? `Zalogowano kodem: ${formatDate(row.connector_session_saved_at)}`
                        : 'Wymaga logowania kodem z e-maila'}
                    </p>
                  )}
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
          onRequestSync={
            progressAccount.requires_login_code
              ? () => {
                  const row = progressAccount
                  setProgressAccount(null)
                  startSync(row)
                }
              : undefined
          }
        />
      )}

      {codeLoginAccount && (
        <B2bCodeLoginModal
          account={codeLoginAccount}
          onClose={() => setCodeLoginAccount(null)}
          onDone={(updated) => void onCodeLoginDone(updated)}
        />
      )}

      {discountAccount && (
        <B2bDiscountRulesModal
          account={discountAccount}
          canManage={canManage}
          onClose={() => setDiscountAccount(null)}
        />
      )}

      {manufacturersAccount && (
        <B2bManufacturerRulesModal
          account={manufacturersAccount}
          canManage={canManage}
          onClose={() => setManufacturersAccount(null)}
        />
      )}
    </div>
  )
}

/**
 * Logowanie kodem z e-maila (3M): krok 1 wysyła kod, krok 2 go sprawdza — serwer zapisuje sesję sklepu
 * i od razu zleca pobieranie cennika (sesja żyje krótko).
 */
function B2bCodeLoginModal({
  account,
  onClose,
  onDone,
}: {
  account: B2bAccount
  onClose: () => void
  onDone: (updated: B2bAccount) => void
}) {
  const supplier = account.connector_label ?? 'dostawcy'
  const [step, setStep] = useState<'send' | 'code'>('send')
  const [code, setCode] = useState('')
  const [busy, setBusy] = useState(false)
  const [info, setInfo] = useState('')
  const [err, setErr] = useState('')

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape' && !busy) onClose()
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [onClose, busy])

  async function sendCode() {
    setBusy(true)
    setErr('')
    setInfo('')
    try {
      const res = await api<{ message?: string }>(`/b2b-accounts/${account.id}/login-code`, { method: 'POST' })
      setInfo(res.message ?? 'Kod wysłany na e-mail. Wpisz go poniżej.')
      setCode('')
      setStep('code')
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Nie udało się wysłać kodu')
    } finally {
      setBusy(false)
    }
  }

  async function verify(e: FormEvent) {
    e.preventDefault()
    if (code.length < 4) return
    setBusy(true)
    setErr('')
    try {
      const updated = await api<B2bAccount>(`/b2b-accounts/${account.id}/login-code/verify`, {
        method: 'POST',
        body: JSON.stringify({ code }),
      })
      onDone(updated)
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Nie udało się zalogować kodem')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
      role="dialog"
      aria-modal="true"
      onClick={() => {
        if (!busy) onClose()
      }}
    >
      <div
        className="flex w-full max-w-md flex-col overflow-hidden rounded-xl bg-white shadow-lg"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="flex items-start justify-between gap-3 border-b border-slate-200 px-4 py-3">
          <div className="min-w-0">
            <p className="text-sm font-semibold text-slate-900">Logowanie do {supplier}</p>
            <p className="truncate text-xs text-slate-500">Konto: {account.username}</p>
          </div>
          <button
            type="button"
            onClick={onClose}
            disabled={busy}
            aria-label="Zamknij"
            className="rounded px-2 py-0.5 text-lg leading-none text-slate-500 hover:bg-slate-100 hover:text-slate-900 disabled:opacity-50"
          >
            ×
          </button>
        </div>

        <div className="space-y-3 px-4 py-3 text-xs">
          <p className="rounded bg-slate-50 px-3 py-2 text-slate-600">
            {supplier} przy każdym logowaniu wysyła jednorazowy kod na e-mail konta. Po zalogowaniu pobieranie
            cennika rusza od razu.
          </p>
          {info && <p className="rounded bg-green-50 px-3 py-2 text-green-800">{info}</p>}
          {err && <p className="rounded bg-red-50 px-3 py-2 text-red-700">{err}</p>}

          {step === 'send' ? (
            <div className="flex justify-end gap-2">
              <button
                type="button"
                onClick={onClose}
                disabled={busy}
                className="rounded border border-slate-300 px-3 py-1.5 text-xs hover:bg-slate-50 disabled:opacity-50"
              >
                Anuluj
              </button>
              <button
                type="button"
                disabled={busy}
                onClick={() => void sendCode()}
                className="rounded bg-blue-600 px-3 py-1.5 text-xs text-white hover:bg-blue-700 disabled:opacity-50"
              >
                {busy ? 'Wysyłanie…' : 'Wyślij kod na e-mail'}
              </button>
            </div>
          ) : (
            <form onSubmit={verify} className="space-y-3">
              <label className="block">
                Kod z e-maila
                <input
                  autoFocus
                  inputMode="numeric"
                  autoComplete="one-time-code"
                  maxLength={10}
                  className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5 font-mono text-sm tracking-widest"
                  value={code}
                  onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))}
                />
              </label>
              <div className="flex items-center justify-between gap-2">
                <button
                  type="button"
                  disabled={busy}
                  onClick={() => void sendCode()}
                  className="text-blue-700 underline disabled:text-slate-400 disabled:no-underline"
                >
                  Wyślij nowy kod
                </button>
                <div className="flex gap-2">
                  <button
                    type="button"
                    onClick={onClose}
                    disabled={busy}
                    className="rounded border border-slate-300 px-3 py-1.5 text-xs hover:bg-slate-50 disabled:opacity-50"
                  >
                    Anuluj
                  </button>
                  <button
                    type="submit"
                    disabled={busy || code.length < 4}
                    className="rounded bg-blue-600 px-3 py-1.5 text-xs text-white hover:bg-blue-700 disabled:opacity-50"
                  >
                    {busy ? 'Logowanie…' : 'Zaloguj i pobierz'}
                  </button>
                </div>
              </div>
            </form>
          )}
        </div>
      </div>
    </div>
  )
}
