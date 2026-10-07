import { useEffect, useState, type FormEvent } from 'react'
import { Navigate } from 'react-router-dom'
import { useAuth } from '../auth'
import { api, ApiError } from '../lib/api'

/** Krok z kodem e-mailem: challenge z odpowiedzi 403 na /login — trzymany tylko w pamięci (nigdy w localStorage). */
type CodeStep = {
  challenge: string
  emailHint: string
  /** kod został wysłany — widać pole na kod */
  sent: boolean
  /** ważność kodu w minutach z odpowiedzi serwera */
  validMinutes: number
}

type SendResponse = { ok: boolean; email_hint: string; resend_after: number; expires_in: number }

function errText(ex: unknown, fallback: string): string {
  return ex instanceof Error && ex.message ? ex.message : fallback
}

function retryAfter(ex: ApiError): number {
  const n = Number(ex.body.retry_after)
  return Number.isFinite(n) && n > 0 ? Math.ceil(n) : 0
}

/** Odliczanie do ponownej wysyłki jako minuty:sekundy, np. 0:45 (przy limicie godzinowym 56:12). */
function waitText(seconds: number): string {
  return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`
}

export function Login() {
  const { user, login, verifyNetworkCode, loading, signedOutNotice } = useAuth()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const [codeStep, setCodeStep] = useState<CodeStep | null>(null)
  const [code, setCode] = useState('')
  const [codeError, setCodeError] = useState('')
  /** sekundy do możliwej ponownej wysyłki kodu */
  const [resendLeft, setResendLeft] = useState(0)

  useEffect(() => {
    if (resendLeft <= 0) return
    const t = window.setTimeout(() => setResendLeft((s) => Math.max(0, s - 1)), 1000)
    return () => window.clearTimeout(t)
  }, [resendLeft])

  if (!loading && user) return <Navigate to="/" replace />

  function backToPassword(message = '') {
    setCodeStep(null)
    setCode('')
    setCodeError('')
    setResendLeft(0)
    setError(message)
  }

  async function onSubmitPassword() {
    try {
      await login(email, password)
    } catch (err) {
      if (
        err instanceof ApiError &&
        err.status === 403 &&
        err.body.reason === 'network' &&
        err.body.code_login === true &&
        typeof err.body.challenge === 'string'
      ) {
        setCodeStep({
          challenge: err.body.challenge,
          emailHint: typeof err.body.email_hint === 'string' ? err.body.email_hint : 'Twój adres e-mail',
          sent: false,
          validMinutes: 10,
        })
        setCode('')
        setCodeError('')
        setResendLeft(0)
        return
      }
      setError(errText(err, 'Błąd logowania'))
    }
  }

  async function sendCode(step: CodeStep) {
    setError('')
    setCodeError('')
    try {
      const res = await api<SendResponse>('/login/network-code/send', {
        method: 'POST',
        body: JSON.stringify({ challenge: step.challenge }),
      })
      setCodeStep({
        ...step,
        sent: true,
        emailHint: res.email_hint || step.emailHint,
        validMinutes: res.expires_in > 0 ? Math.round(res.expires_in / 60) : step.validMinutes,
      })
      setResendLeft(res.resend_after > 0 ? res.resend_after : 60)
    } catch (err) {
      if (err instanceof ApiError && err.status === 422 && err.body.reason === 'challenge_invalid') {
        backToPassword(err.message)
        return
      }
      if (err instanceof ApiError && err.status === 429) setResendLeft(retryAfter(err))
      setError(errText(err, 'Nie udało się wysłać kodu.'))
    }
  }

  async function verifyCode(step: CodeStep) {
    setError('')
    setCodeError('')
    try {
      await verifyNetworkCode(step.challenge, code)
    } catch (err) {
      if (err instanceof ApiError && err.status === 422) {
        if (err.body.reason === 'challenge_invalid') {
          backToPassword(err.message)
          return
        }
        const fieldErrors = (err.body.errors as Record<string, string[]> | undefined)?.code
        if (fieldErrors && fieldErrors.length > 0) {
          setCodeError(fieldErrors.join(' '))
          return
        }
      }
      setError(errText(err, 'Nie udało się potwierdzić kodu.'))
    }
  }

  async function onSubmit(e: FormEvent) {
    e.preventDefault()
    setBusy(true)
    setError('')
    try {
      if (codeStep === null) await onSubmitPassword()
      else if (!codeStep.sent) await sendCode(codeStep)
      else await verifyCode(codeStep)
    } finally {
      setBusy(false)
    }
  }

  async function onResend() {
    if (codeStep === null) return
    setBusy(true)
    try {
      await sendCode(codeStep)
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="app-login flex min-h-screen items-center justify-center bg-slate-900 p-4">
      <form onSubmit={onSubmit} className="app-login-card w-full max-w-sm rounded-xl bg-white p-6 shadow-lg">
        <h1 className="mb-1 text-xl font-bold text-slate-900">Przetargi Supon</h1>
        <p className="mb-4 text-sm text-slate-500">Logowanie — dział handlowy</p>
        {error && <p className="mb-3 rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
        {codeStep === null ? (
          <>
            {!error && signedOutNotice && <p className="mb-3 rounded bg-amber-50 p-2 text-sm text-amber-800">{signedOutNotice}</p>}
            <label className="mb-3 block text-sm">
              E-mail
              <input
                type="email"
                name="email"
                autoComplete="username"
                required
                className="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
              />
            </label>
            <label className="mb-4 block text-sm">
              Hasło
              <input
                type="password"
                name="password"
                autoComplete="current-password"
                required
                className="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
              />
            </label>
            <button
              type="submit"
              disabled={busy}
              className="w-full rounded bg-blue-600 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
            >
              {busy ? 'Logowanie…' : 'Zaloguj'}
            </button>
          </>
        ) : (
          <>
            <p className="mb-3 rounded bg-amber-50 p-2 text-sm text-amber-800">
              Jesteś poza siecią firmy. Potwierdź dostęp kodem — wyślemy go na <strong>{codeStep.emailHint}</strong>. Po
              potwierdzeniu konto działa z tego miejsca przez 24 godziny.
            </p>
            {!codeStep.sent ? (
              <button
                type="submit"
                disabled={busy}
                className="w-full rounded bg-blue-600 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
              >
                {busy ? 'Wysyłanie…' : 'Wyślij kod na e-mail'}
              </button>
            ) : (
              <>
                <p className="mb-3 text-sm text-slate-600">
                  Kod wysłany na {codeStep.emailHint}. Jest ważny {codeStep.validMinutes} minut.
                </p>
                <label className="mb-1 block text-sm">
                  Kod z e-maila (6 cyfr)
                  <input
                    type="text"
                    name="code"
                    inputMode="numeric"
                    autoComplete="one-time-code"
                    pattern="[0-9]{6}"
                    maxLength={6}
                    required
                    autoFocus
                    className="mt-1 w-full rounded border border-slate-300 px-3 py-2 font-mono text-lg tracking-widest"
                    value={code}
                    aria-invalid={codeError !== ''}
                    onChange={(e) => {
                      setCode(e.target.value.replace(/\D/g, '').slice(0, 6))
                      setCodeError('')
                    }}
                  />
                </label>
                {codeError && <p className="mb-2 text-sm text-red-700">{codeError}</p>}
                <button
                  type="submit"
                  disabled={busy || code.length !== 6}
                  className="mt-3 w-full rounded bg-blue-600 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
                >
                  {busy ? 'Sprawdzanie…' : 'Potwierdź'}
                </button>
                <button
                  type="button"
                  disabled={busy || resendLeft > 0}
                  onClick={() => void onResend()}
                  className="mt-2 w-full rounded bg-slate-200 py-2 text-sm text-slate-800 hover:bg-slate-300 disabled:opacity-50"
                >
                  {resendLeft > 0 ? `Wyślij kod ponownie (za ${waitText(resendLeft)})` : 'Wyślij kod ponownie'}
                </button>
              </>
            )}
            <button
              type="button"
              disabled={busy}
              onClick={() => backToPassword()}
              className="mt-3 w-full text-center text-sm text-blue-700 underline disabled:opacity-50"
            >
              Wróć do logowania
            </button>
          </>
        )}
      </form>
    </div>
  )
}
