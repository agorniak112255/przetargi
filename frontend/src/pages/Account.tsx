import { useEffect, useState, type FormEvent, type ReactNode } from 'react'
import { useAppearance } from '../appearanceContext'
import { useAuth } from '../auth'
import { api, can, type User } from '../lib/api'
import { TEMPLATES, type AppearanceMode, type AppearanceTemplate, type Scheme } from '../lib/appearance'
import {
  getMailAccount,
  saveMailAccount,
  testMailAccount,
  type UserMailAccount,
  type UserMailAccountInput,
} from '../lib/campaigns'

const schemeLabel: Record<Scheme, string> = {
  dark: 'Noc',
  light: 'Dzień',
}

const modes: { id: AppearanceMode; label: string }[] = [
  { id: 'dark', label: 'Noc' },
  { id: 'light', label: 'Dzień' },
  { id: 'system', label: 'Jak w systemie' },
]

function SwatchBar({ colors, small }: { colors: string[]; small?: boolean }) {
  return (
    <div className={`flex overflow-hidden rounded border border-slate-200 ${small ? 'h-4' : 'h-8'}`}>
      {colors.map((c, i) => (
        <span key={`${c}-${i}`} className="flex-1" style={{ background: c }} />
      ))}
    </div>
  )
}

function TemplateSwatches({ template }: { template: AppearanceTemplate }) {
  if (template.schemes.length === 1) {
    return <SwatchBar colors={template.swatches[template.schemes[0]]} />
  }
  return (
    <div className="space-y-1">
      {template.schemes.map((s) => (
        <div key={s} className="flex items-center gap-2">
          <span className="w-9 shrink-0 text-[11px] text-slate-500">{schemeLabel[s]}</span>
          <div className="flex-1">
            <SwatchBar colors={template.swatches[s]} small />
          </div>
        </div>
      ))}
    </div>
  )
}

/** Zmiana własnego hasła: wymaga dotychczasowego, po zapisie pozostałe sesje konta są wylogowywane. */
function PasswordForm() {
  const [currentPassword, setCurrentPassword] = useState('')
  const [password, setPassword] = useState('')
  const [passwordConfirmation, setPasswordConfirmation] = useState('')
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const [msg, setMsg] = useState('')

  async function onSubmit(e: FormEvent) {
    e.preventDefault()
    setBusy(true)
    setErr('')
    setMsg('')
    try {
      const res = await api<{ message: string }>('/me/password', {
        method: 'POST',
        body: JSON.stringify({
          current_password: currentPassword,
          password,
          password_confirmation: passwordConfirmation,
        }),
      })
      setCurrentPassword('')
      setPassword('')
      setPasswordConfirmation('')
      setMsg(res.message)
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Błąd')
    } finally {
      setBusy(false)
    }
  }

  return (
    <form onSubmit={(e) => void onSubmit(e)} className="mb-4 rounded-xl bg-white p-4 shadow-sm">
      <h2 className="mb-3 text-sm font-semibold">Zmiana hasła</h2>
      {err && <p className="mb-3 rounded bg-red-50 px-3 py-2 text-xs text-red-700">{err}</p>}
      {msg && <p className="mb-3 rounded bg-emerald-50 px-3 py-2 text-xs text-emerald-700">{msg}</p>}
      <div className="grid max-w-xl gap-2 sm:grid-cols-[10rem_1fr] sm:items-center">
        <label className="text-sm text-slate-500" htmlFor="current-password">
          Dotychczasowe hasło
        </label>
        <input
          id="current-password"
          type="password"
          autoComplete="current-password"
          className="rounded border px-2 py-1.5 text-sm"
          value={currentPassword}
          onChange={(e) => setCurrentPassword(e.target.value)}
          required
        />
        <label className="text-sm text-slate-500" htmlFor="new-password">
          Nowe hasło
        </label>
        <input
          id="new-password"
          type="password"
          autoComplete="new-password"
          minLength={8}
          className="rounded border px-2 py-1.5 text-sm"
          value={password}
          onChange={(e) => setPassword(e.target.value)}
          required
        />
        <label className="text-sm text-slate-500" htmlFor="new-password-2">
          Powtórz nowe hasło
        </label>
        <input
          id="new-password-2"
          type="password"
          autoComplete="new-password"
          minLength={8}
          className="rounded border px-2 py-1.5 text-sm"
          value={passwordConfirmation}
          onChange={(e) => setPasswordConfirmation(e.target.value)}
          required
        />
      </div>
      <p className="mt-2 text-xs text-slate-500">
        Hasło musi mieć co najmniej 8 znaków. Po zmianie pozostałe zalogowane urządzenia zostaną wylogowane.
      </p>
      <button
        type="submit"
        disabled={busy}
        className="mt-3 rounded bg-blue-600 px-3 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-50"
      >
        Zmień hasło
      </button>
    </form>
  )
}

/** Domyślna marża konta: z nią startuje każda nowa odpowiedź na zapytanie. */
function MarginForm() {
  const { user, replaceUser } = useAuth()
  const saved = user?.default_margin_percent ?? 18
  const [value, setValue] = useState(String(saved).replace('.', ','))
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const [msg, setMsg] = useState('')

  async function onSubmit(e: FormEvent) {
    e.preventDefault()
    setBusy(true)
    setErr('')
    setMsg('')
    try {
      const res = await api<User>('/me/margin', {
        method: 'PATCH',
        body: JSON.stringify({ default_margin_percent: value }),
      })
      replaceUser(res)
      setValue(String(res.default_margin_percent ?? '').replace('.', ','))
      setMsg('Zapisano. Nowe odpowiedzi na zapytania zaczną się od tej marży.')
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Błąd')
    } finally {
      setBusy(false)
    }
  }

  return (
    <form onSubmit={(e) => void onSubmit(e)} className="mb-4 rounded-xl bg-white p-4 shadow-sm">
      <h2 className="mb-3 text-sm font-semibold">Oferty</h2>
      {err && <p className="mb-3 rounded bg-red-50 px-3 py-2 text-xs text-red-700">{err}</p>}
      {msg && <p className="mb-3 rounded bg-emerald-50 px-3 py-2 text-xs text-emerald-700">{msg}</p>}
      <div className="grid max-w-xl gap-2 sm:grid-cols-[10rem_1fr] sm:items-center">
        <label className="text-sm text-slate-500" htmlFor="default-margin">
          Domyślna marża
        </label>
        <div className="flex items-center gap-2">
          <input
            id="default-margin"
            inputMode="decimal"
            className="w-24 rounded border px-2 py-1.5 text-sm"
            value={value}
            onChange={(e) => setValue(e.target.value)}
            required
          />
          <span className="text-sm text-slate-500">%</span>
        </div>
      </div>
      <p className="mt-2 text-xs text-slate-500">
        Każda nowa odpowiedź na zapytanie startuje z ceną oferty: zakup + ta marża. Dla pojedynczego listu
        zmienisz ją na stronie odpowiedzi. Z tą marżą okno „Oferta dla klienta” proponuje cenę.
      </p>
      <button
        type="submit"
        disabled={busy}
        className="mt-3 rounded bg-blue-600 px-3 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-50"
      >
        Zapisz marżę
      </button>
    </form>
  )
}

/** Port i szyfrowanie. 25 i 2525 tylko po otwarciu „zaawansowanych” (dozwolone porty: backend campaigns.smtp_ports). */
const PORT_CHOICES: { key: string; port: number; scheme: 'smtp' | 'smtps'; label: string; rare?: boolean }[] = [
  { key: '587', port: 587, scheme: 'smtp', label: '587 · STARTTLS' },
  { key: '465', port: 465, scheme: 'smtps', label: '465 · SSL' },
  { key: '25', port: 25, scheme: 'smtp', label: '25 · STARTTLS', rare: true },
  { key: '2525', port: 2525, scheme: 'smtp', label: '2525 · STARTTLS', rare: true },
]
const RATE_CHOICES = [30, 60, 100, 150, 200, 300]

type MailForm = {
  from_name: string
  from_address: string
  host: string
  portKey: string
  username: string
  password: string
  verify_peer: boolean
  rate_per_hour: number
  copy_to_self: boolean
  signature: string
  imap_enabled: boolean
  imap_host: string
}

function mailFormFrom(a: UserMailAccount, user: User | null): MailForm {
  const portKey = PORT_CHOICES.some((c) => c.port === a.port) ? String(a.port) : '587'
  return {
    // Nowa skrzynka: nadawca podpowiedziany z konta w aplikacji — do poprawienia przed zapisem.
    from_name: a.from_name ?? (a.configured ? '' : (user?.name ?? '')),
    from_address: a.from_address ?? (a.configured ? '' : (user?.email ?? '')),
    host: a.host ?? '',
    portKey,
    username: a.username ?? '',
    password: '',
    verify_peer: a.verify_peer,
    rate_per_hour: a.rate_per_hour,
    copy_to_self: a.copy_to_self,
    signature: a.signature ?? '',
    imap_enabled: a.imap_enabled,
    imap_host: a.imap_host ?? '',
  }
}

/** „30.09, 14:20” — jak w makiecie stanu skrzynki. */
function shortDateTime(iso: string): string {
  return new Date(iso).toLocaleString('pl-PL', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })
}

const inputClass = 'rounded border px-2 py-1.5 text-sm'

function MailField({ id, label, hint, children }: { id: string; label: string; hint?: string; children: ReactNode }) {
  return (
    <div className="flex flex-col gap-1">
      <label htmlFor={id} className="text-xs text-slate-500">
        {label}
      </label>
      {children}
      {hint && <span className="text-[11px] text-slate-400">{hint}</span>}
    </div>
  )
}

/**
 * Moja poczta: skrzynka SMTP użytkownika, z której wychodzą jego kampanie. Hasło nigdy nie wraca z serwera
 * (has_password); puste pole przy zapisie = bez zmiany. Test wysyła wiadomość na adres nadawcy.
 */
function MailAccountForm() {
  const { user } = useAuth()
  const [account, setAccount] = useState<UserMailAccount | null>(null)
  const [saved, setSaved] = useState<MailForm | null>(null)
  const [form, setForm] = useState<MailForm | null>(null)
  const [advanced, setAdvanced] = useState(false)
  const [loadErr, setLoadErr] = useState('')
  const [busy, setBusy] = useState<'save' | 'test' | false>(false)
  const [err, setErr] = useState('')
  const [msg, setMsg] = useState('')
  const [test, setTest] = useState<{
    ok: boolean
    message: string
    imap?: { ok: boolean; message: string } | null
  } | null>(null)

  function apply(a: UserMailAccount) {
    const f = mailFormFrom(a, user)
    setAccount(a)
    setSaved(f)
    setForm(f)
  }

  async function load() {
    setLoadErr('')
    try {
      const a = await getMailAccount()
      apply(a)
      const rare = PORT_CHOICES.find((c) => c.port === a.port)?.rare ?? false
      setAdvanced(rare || !a.verify_peer || !!a.imap_host)
    } catch (ex) {
      setLoadErr(ex instanceof Error ? ex.message : 'Błąd')
    }
  }

  useEffect(() => {
    void load()
    // Raz przy wejściu na stronę; user z kontekstu służy tylko do podpowiedzi nadawcy.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  if (loadErr) {
    return (
      <section id="moja-poczta" className="mb-4 rounded-xl bg-white p-4 shadow-sm">
        <h2 className="mb-3 text-sm font-semibold">Moja poczta</h2>
        <p className="rounded bg-red-50 px-3 py-2 text-xs text-red-700">
          Nie udało się wczytać ustawień skrzynki: {loadErr}{' '}
          <button type="button" className="font-medium underline" onClick={() => void load()}>
            Spróbuj ponownie
          </button>
        </p>
      </section>
    )
  }
  if (!account || !form || !saved) {
    return (
      <section id="moja-poczta" className="mb-4 rounded-xl bg-white p-4 shadow-sm">
        <h2 className="mb-3 text-sm font-semibold">Moja poczta</h2>
        <p className="text-xs text-slate-500">Ładowanie…</p>
      </section>
    )
  }

  const dirty = form.password !== '' || JSON.stringify(form) !== JSON.stringify(saved)
  const choice = PORT_CHOICES.find((c) => c.key === form.portKey) ?? PORT_CHOICES[0]
  const portChoices = PORT_CHOICES.filter((c) => !c.rare || advanced || c.key === form.portKey)
  const rateChoices = RATE_CHOICES.includes(form.rate_per_hour)
    ? RATE_CHOICES
    : [...RATE_CHOICES, form.rate_per_hour].sort((a, b) => a - b)

  function set<K extends keyof MailForm>(key: K, value: MailForm[K]) {
    setForm((prev) => (prev ? { ...prev, [key]: value } : prev))
    setMsg('')
  }

  /** Zapis; true = zapisane. Pole hasła wysyłane tylko, gdy coś wpisano. */
  async function save(): Promise<boolean> {
    if (!form) return false
    const body: UserMailAccountInput = {
      from_name: form.from_name.trim(),
      from_address: form.from_address.trim(),
      host: form.host.trim(),
      port: choice.port,
      scheme: choice.scheme,
      username: form.username.trim(),
      verify_peer: form.verify_peer,
      rate_per_hour: form.rate_per_hour,
      copy_to_self: form.copy_to_self,
      signature: form.signature.trim() === '' ? null : form.signature,
      imap_enabled: form.imap_enabled,
      imap_host: form.imap_host.trim() === '' ? null : form.imap_host.trim(),
    }
    if (form.password !== '') body.password = form.password
    try {
      apply(await saveMailAccount(body))
      return true
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Błąd zapisu')
      return false
    }
  }

  async function onSubmit(e: FormEvent) {
    e.preventDefault()
    setBusy('save')
    setErr('')
    setMsg('')
    setTest(null)
    if (await save()) setMsg('Zapisano. Sprawdź połączenie przyciskiem obok.')
    setBusy(false)
  }

  async function onTest(formEl: HTMLFormElement | null) {
    setErr('')
    setMsg('')
    setTest(null)
    // Test idzie na zapisanych ustawieniach — zmiany w polach najpierw zapisujemy.
    if (dirty) {
      if (formEl && !formEl.reportValidity()) return
      setBusy('test')
      if (!(await save())) {
        setBusy(false)
        return
      }
    } else {
      setBusy('test')
    }
    try {
      setTest(await testMailAccount())
    } catch (ex) {
      setTest({ ok: false, message: ex instanceof Error ? ex.message : 'Błąd sprawdzania' })
    }
    // Stan skrzynki (verified_at / last_error) po teście — bez ruszania pól formularza.
    try {
      setAccount(await getMailAccount())
    } catch {
      /* stan odświeży się przy następnym wejściu */
    }
    setBusy(false)
  }

  return (
    <section id="moja-poczta" className="mb-4 rounded-xl bg-white p-4 shadow-sm">
      <h2 className="mb-1 text-sm font-semibold">Moja poczta</h2>
      <p className="mb-3 text-xs text-slate-500">
        Kampanie wychodzą z tej skrzynki. Hasło jest zapisane zaszyfrowane. Dane serwera poczty (SMTP) znajdziesz w
        ustawieniach programu pocztowego albo u administratora poczty.
      </p>
      <div className="grid gap-4 lg:grid-cols-[1fr_15rem]">
        <form onSubmit={(e) => void onSubmit(e)} className="space-y-3" autoComplete="off">
          {err && <p className="rounded bg-red-50 px-3 py-2 text-xs text-red-700">{err}</p>}
          {msg && <p className="rounded bg-emerald-50 px-3 py-2 text-xs text-emerald-700">{msg}</p>}
          <div className="grid gap-3 sm:grid-cols-2">
            <MailField id="mail-from-name" label="Nazwa nadawcy" hint="tak klient zobaczy nadawcę, np. „Jan Kowalski – SUPON”">
              <input
                id="mail-from-name"
                className={inputClass}
                value={form.from_name}
                maxLength={150}
                onChange={(e) => set('from_name', e.target.value)}
                required
              />
            </MailField>
            <MailField id="mail-from-address" label="Adres nadawcy" hint="na ten adres wrócą odpowiedzi klientów">
              <input
                id="mail-from-address"
                type="email"
                className={inputClass}
                value={form.from_address}
                maxLength={255}
                onChange={(e) => set('from_address', e.target.value)}
                required
              />
            </MailField>
          </div>
          <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <MailField id="mail-host" label="Serwer SMTP">
              <input
                id="mail-host"
                className={inputClass}
                value={form.host}
                maxLength={255}
                placeholder="np. mail.firma.pl"
                onChange={(e) => set('host', e.target.value)}
                required
              />
            </MailField>
            <MailField id="mail-port" label="Port i szyfrowanie">
              <select
                id="mail-port"
                className={inputClass}
                value={form.portKey}
                onChange={(e) => set('portKey', e.target.value)}
              >
                {portChoices.map((c) => (
                  <option key={c.key} value={c.key}>
                    {c.label}
                  </option>
                ))}
              </select>
            </MailField>
            <MailField id="mail-username" label="Login">
              <input
                id="mail-username"
                name="smtp-username"
                autoComplete="off"
                className={inputClass}
                value={form.username}
                maxLength={255}
                placeholder="zwykle pełny adres e-mail"
                onChange={(e) => set('username', e.target.value)}
                required
              />
            </MailField>
            <MailField id="mail-password" label="Hasło">
              <input
                id="mail-password"
                name="smtp-password"
                type="password"
                autoComplete="new-password"
                className={inputClass}
                value={form.password}
                placeholder={account.has_password ? 'zapisane — zostaw puste, żeby nie zmieniać' : ''}
                onChange={(e) => set('password', e.target.value)}
                required={!account.has_password}
              />
            </MailField>
          </div>
          <div className="grid gap-3 sm:grid-cols-2">
            <MailField id="mail-rate" label="Limit wysyłki" hint="zgodnie z limitem hostingu poczty">
              <select
                id="mail-rate"
                className={inputClass}
                value={form.rate_per_hour}
                onChange={(e) => set('rate_per_hour', Number(e.target.value))}
              >
                {rateChoices.map((n) => (
                  <option key={n} value={n}>
                    {n} maili na godzinę
                  </option>
                ))}
              </select>
            </MailField>
            <label className="flex items-center gap-2 self-center text-sm text-slate-700">
              <input
                type="checkbox"
                checked={form.copy_to_self}
                onChange={(e) => set('copy_to_self', e.target.checked)}
              />
              Wyślij jedną kopię kampanii do mnie
            </label>
          </div>
          <MailField
            id="mail-signature"
            label="Podpis"
            hint="zwykły tekst, pod każdą kampanią — np. imię i nazwisko, stanowisko, telefon"
          >
            <textarea
              id="mail-signature"
              rows={4}
              className={inputClass}
              value={form.signature}
              onChange={(e) => set('signature', e.target.value)}
            />
          </MailField>
          <label className="flex items-start gap-2 text-sm text-slate-700">
            <input
              type="checkbox"
              className="mt-0.5"
              checked={form.imap_enabled}
              onChange={(e) => set('imap_enabled', e.target.checked)}
            />
            <span>
              Licz odpowiedzi klientów na moje kampanie
              <span className="block text-[11px] text-slate-500">
                Co 10 minut program czyta z tej skrzynki tylko nadawcę, temat i datę nowych maili (bez treści) — we
                wszystkich folderach poza Wysłanymi, Koszem, Spamem i Szkicami. Nic w skrzynce nie zmienia i nie
                oznacza maili jako przeczytane.
              </span>
            </span>
          </label>
          <details
            className="rounded border border-slate-200 px-3 py-2 text-sm"
            open={advanced}
            onToggle={(e) => setAdvanced(e.currentTarget.open)}
          >
            <summary className="cursor-pointer text-xs font-medium text-slate-600">Ustawienia zaawansowane</summary>
            <div className="mt-2 space-y-2">
              <label className="flex items-center gap-2 text-sm text-slate-700">
                <input
                  type="checkbox"
                  checked={form.verify_peer}
                  onChange={(e) => set('verify_peer', e.target.checked)}
                />
                Sprawdzaj certyfikat serwera poczty (zalecane)
              </label>
              <p className="text-[11px] text-slate-500">
                Wyłącz tylko wtedy, gdy test pokazuje błąd certyfikatu, a serwer poczty jest Twojej firmy. Tu są też
                rzadkie porty 25 i 2525 — w polu „Port i szyfrowanie”, tylko gdy tak podaje dostawca poczty.
              </p>
              <MailField
                id="mail-imap-host"
                label="Serwer IMAP (odczyt odpowiedzi)"
                hint="puste = ten sam co serwer SMTP; zawsze port 993 z SSL, login i hasło jak wyżej"
              >
                <input
                  id="mail-imap-host"
                  className={`${inputClass} max-w-xs`}
                  value={form.imap_host}
                  maxLength={255}
                  placeholder={form.host.trim() || 'np. imap.firma.pl'}
                  onChange={(e) => set('imap_host', e.target.value)}
                />
              </MailField>
            </div>
          </details>
          <div className="flex flex-wrap items-center gap-2">
            <button
              type="submit"
              disabled={busy !== false}
              className="rounded bg-blue-600 px-3 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-50"
            >
              {busy === 'save' ? 'Zapisuję…' : 'Zapisz'}
            </button>
            <button
              type="button"
              disabled={busy !== false || (!account.configured && !dirty)}
              onClick={(e) => void onTest(e.currentTarget.form)}
              className="rounded border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50 disabled:opacity-50"
              title={
                !account.configured && !dirty
                  ? 'Najpierw uzupełnij i zapisz skrzynkę'
                  : 'Zapisuje zmiany, łączy się z serwerem, wysyła wiadomość testową na adres nadawcy i sprawdza odczyt odpowiedzi'
              }
            >
              {busy === 'test' ? 'Sprawdzam…' : 'Sprawdź połączenie i wyślij test do siebie'}
            </button>
          </div>
          {test && (
            <p
              className={`rounded px-3 py-2 text-xs ${test.ok ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700'}`}
            >
              {test.ok ? '✓ ' : ''}
              {test.message}
            </p>
          )}
          {test?.imap && (
            <p
              className={`rounded px-3 py-2 text-xs ${test.imap.ok ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700'}`}
            >
              {test.imap.ok ? '✓ ' : ''}
              {test.imap.message}
            </p>
          )}
        </form>
        <aside className="h-fit rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs">
          <h3 className="mb-2 font-semibold text-slate-700">Stan skrzynki</h3>
          <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5">
            <dt className="text-slate-500">Połączenie</dt>
            <dd className="text-right font-medium">
              {!account.configured ? (
                <span className="text-slate-500">nie ustawiona</span>
              ) : account.last_error ? (
                <span className="text-red-700">błąd</span>
              ) : account.verified_at ? (
                <span className="text-emerald-700">działa (sprawdzono {shortDateTime(account.verified_at)})</span>
              ) : (
                <span className="text-amber-800">nie sprawdzono</span>
              )}
            </dd>
            <dt className="text-slate-500">Limit</dt>
            <dd className="text-right tabular-nums">{account.rate_per_hour} na godz.</dd>
            <dt className="text-slate-500">Kopia do mnie</dt>
            <dd className="text-right">{account.copy_to_self ? 'tak' : 'nie'}</dd>
            <dt className="text-slate-500">Odpowiedzi</dt>
            <dd className="text-right">
              {!account.configured || !account.imap_enabled ? (
                <span className="text-slate-500">nie liczone</span>
              ) : account.imap_error ? (
                <span className="text-red-700">błąd odczytu</span>
              ) : account.imap_checked_at ? (
                <span className="text-emerald-700">liczone (odczyt {shortDateTime(account.imap_checked_at)})</span>
              ) : (
                <span className="text-slate-600">po pierwszej kampanii</span>
              )}
            </dd>
          </dl>
          {account.configured && account.imap_enabled && account.imap_error && (
            <p className="mt-2 break-words rounded bg-red-50 px-2 py-1.5 text-red-700">
              Odczyt odpowiedzi: {account.imap_error}
            </p>
          )}
          {account.configured && account.last_error && (
            <p className="mt-2 break-words rounded bg-red-50 px-2 py-1.5 text-red-700">
              Ostatni błąd: {account.last_error}
              {account.verified_at && (
                <span className="mt-1 block text-slate-600">
                  Ostatnio działała: {shortDateTime(account.verified_at)}
                </span>
              )}
            </p>
          )}
          <p className="mt-3 text-slate-500">
            Bez ustawionej skrzynki kampanię można przygotować, ale nie da się jej wysłać.
          </p>
        </aside>
      </div>
    </section>
  )
}

export function Account() {
  const { user } = useAuth()
  const { choice, resolved, setChoice, saveState, saveError } = useAppearance()

  const roles = user?.roles ?? []
  const roleText = roles.length > 1 ? roles.join(', ') : user?.role || roles[0] || '—'

  return (
    <div className="max-w-4xl">
      <h1 className="mb-4 text-xl font-semibold">Moje konto</h1>

      <section className="mb-4 rounded-xl bg-white p-4 shadow-sm">
        <h2 className="mb-3 text-sm font-semibold">Dane konta</h2>
        <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-[10rem_1fr]">
          <dt className="text-slate-500">Imię i nazwisko</dt>
          <dd>{user?.name || '—'}</dd>
          <dt className="text-slate-500">E-mail</dt>
          <dd>{user?.email || '—'}</dd>
          <dt className="text-slate-500">{roles.length > 1 ? 'Role' : 'Rola'}</dt>
          <dd>{roleText}</dd>
        </dl>
        <p className="mt-3 text-xs text-slate-500">Zmianę imienia, e-maila i roli wykonuje administrator.</p>
      </section>

      <MarginForm />

      {can(user, 'campaigns.use') && <MailAccountForm />}

      <PasswordForm />

      <section className="rounded-xl bg-white p-4 shadow-sm">
        <div className="mb-3 flex flex-wrap items-baseline justify-between gap-2">
          <h2 className="text-sm font-semibold">Wygląd</h2>
          <span className="text-xs" aria-live="polite">
            {saveState === 'saving' && <span className="text-slate-500">Zapisywanie…</span>}
            {saveState === 'saved' && <span className="text-emerald-700">Zapisano na koncie</span>}
          </span>
        </div>
        <p className="mb-3 text-xs text-slate-500">
          Kliknij szablon, żeby od razu zobaczyć zmianę. Wybór zapisuje się na koncie.
        </p>
        {saveState === 'error' && saveError && (
          <p className="mb-3 rounded bg-red-50 px-3 py-2 text-xs text-red-700">{saveError}</p>
        )}

        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {TEMPLATES.map((t) => {
            const selected = t.id === resolved.template.id
            return (
              <button
                key={t.id}
                type="button"
                aria-pressed={selected}
                onClick={() => setChoice({ template: t.id, mode: choice.mode })}
                className={`rounded-lg border p-3 text-left focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 ${
                  selected ? 'border-blue-600 ring-1 ring-blue-600' : 'border-slate-200 hover:border-slate-400'
                }`}
              >
                <TemplateSwatches template={t} />
                <div className="mt-3 flex items-center justify-between gap-2">
                  <span className="text-sm font-semibold">{t.label}</span>
                  {selected && (
                    <span className="rounded-full bg-blue-50 px-2 py-0.5 text-[11px] font-medium text-blue-700">
                      Wybrany
                    </span>
                  )}
                </div>
                <p className="mt-1 text-xs text-slate-500">{t.description}</p>
              </button>
            )
          })}
        </div>

        {resolved.template.schemes.length > 1 && (
          <div className="mt-4">
            <p className="mb-2 text-xs font-medium text-slate-700">Tryb</p>
            <div className="inline-flex flex-wrap gap-1 rounded-lg border border-slate-300 p-1">
              {modes.map((m) => {
                const active = resolved.mode === m.id
                return (
                  <button
                    key={m.id}
                    type="button"
                    aria-pressed={active}
                    onClick={() => setChoice({ template: resolved.template.id, mode: m.id })}
                    className={`rounded-md px-3 py-1.5 text-xs focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 ${
                      active ? 'bg-slate-800 text-white' : 'text-slate-600 hover:bg-slate-100'
                    }`}
                  >
                    {m.label}
                  </button>
                )
              })}
            </div>
            {resolved.mode === 'system' && (
              <p className="mt-2 text-xs text-slate-500">
                Teraz: {schemeLabel[resolved.scheme]} (według ustawień systemu).
              </p>
            )}
          </div>
        )}
      </section>
    </div>
  )
}
