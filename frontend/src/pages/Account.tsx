import { useState, type FormEvent } from 'react'
import { useAppearance } from '../appearanceContext'
import { useAuth } from '../auth'
import { api } from '../lib/api'
import { TEMPLATES, type AppearanceMode, type AppearanceTemplate, type Scheme } from '../lib/appearance'

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
