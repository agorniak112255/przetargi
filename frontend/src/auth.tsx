import { createContext, useCallback, useContext, useEffect, useState, type ReactNode } from 'react'
import { api, ApiError, can, NETWORK_BLOCKED_EVENT, type User } from './lib/api'
import { CHAT_PERMISSION } from './lib/chat'
import { startRealtime, stopRealtime } from './lib/realtime'
import { clearToken, getToken, isAddonSession, setToken } from './lib/tokenStore'

type AuthCtx = {
  user: User | null
  loading: boolean
  /**
   * Zapisane logowanie nie dało się sprawdzić (sieć, błąd serwera, wdrożenie). Klucz zostaje w przeglądarce —
   * po powrocie serwera `retry` wpuszcza bez ponownego logowania; null = brak problemu.
   */
  connectionError: string | null
  /** Powód wylogowania przez serwer (konto „tylko z sieci lokalnej” poza nią) — strona logowania go pokazuje. */
  signedOutNotice: string | null
  retry: () => Promise<void>
  login: (email: string, password: string) => Promise<void>
  logout: () => Promise<void>
  /** Podmienia dane konta po zapisie ustawień, które zwracają świeży obiekt użytkownika. */
  replaceUser: (user: User) => void
}

const Ctx = createContext<AuthCtx | null>(null)

function connectionErrorText(ex: unknown): string {
  if (ex instanceof ApiError) return `Serwer odpowiedział błędem ${ex.status}.`
  // fetch bez odpowiedzi (brak sieci, serwer wyłączony) rzuca TypeError z angielskim komunikatem przeglądarki.
  if (ex instanceof TypeError) return 'Brak połączenia z serwerem.'
  return ex instanceof Error ? ex.message : 'Brak połączenia z serwerem.'
}

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null)
  const [loading, setLoading] = useState(true)
  const [connectionError, setConnectionError] = useState<string | null>(null)
  const [signedOutNotice, setSignedOutNotice] = useState<string | null>(null)

  // Serwer odrzucił klucz przez dostęp z sieci (lib/api.ts) — klucz już nie zadziała, więc od razu ekran logowania.
  useEffect(() => {
    function onBlocked(e: Event) {
      clearToken()
      setConnectionError(null)
      setSignedOutNotice((e as CustomEvent<string>).detail || 'Wylogowano: to konto może pracować tylko z sieci lokalnej.')
      setUser(null)
    }
    window.addEventListener(NETWORK_BLOCKED_EVENT, onBlocked)
    return () => window.removeEventListener(NETWORK_BLOCKED_EVENT, onBlocked)
  }, [])

  const verify = useCallback(async () => {
    if (!getToken()) return
    try {
      setUser(await api<User>('/me'))
      setConnectionError(null)
    } catch (ex) {
      // Tylko 401 znaczy, że serwer już nie zna tego klucza. Po chwilowym błędzie wylogowanie kazałoby zalogować
      // się od nowa, a stary klucz zostałby na serwerze jako kolejna „sesja”.
      if (ex instanceof ApiError && ex.status === 401) {
        clearToken()
        setConnectionError(null)
      } else {
        setConnectionError(connectionErrorText(ex))
      }
    }
  }, [])

  useEffect(() => {
    void verify().finally(() => setLoading(false))
  }, [verify])

  // Połączenie czatu w czasie rzeczywistym: nowe po zalogowaniu (także innej osoby), zamknięte po wylogowaniu.
  const realtimeUserId = user && can(user, CHAT_PERMISSION) ? user.id : null
  useEffect(() => {
    if (realtimeUserId === null) return
    void startRealtime(realtimeUserId)
    return () => stopRealtime()
  }, [realtimeUserId])

  async function login(email: string, password: string) {
    const data = await api<{ token: string; user: User }>('/login', {
      method: 'POST',
      body: JSON.stringify({ email, password }),
    })
    setToken(data.token)
    setConnectionError(null)
    setSignedOutNotice(null)
    setUser(data.user)
  }

  async function logout() {
    try {
      // Klucz dodatku Thunderbirda (karta czatu w Thunderbirdzie) zostaje na serwerze — skasowany odłączyłby dodatek.
      if (!isAddonSession()) await api('/logout', { method: 'POST' })
    } finally {
      clearToken()
      setConnectionError(null)
      setUser(null)
    }
  }

  return (
    <Ctx.Provider value={{ user, loading, connectionError, signedOutNotice, retry: verify, login, logout, replaceUser: setUser }}>
      {children}
    </Ctx.Provider>
  )
}

/** Przekazuje istniejące logowanie do osobnego drzewa React (podgląd prawdziwej strony w Pomocy) bez ponownego /me. */
export function AuthBridge({ value, children }: { value: AuthCtx; children: ReactNode }) {
  return <Ctx.Provider value={value}>{children}</Ctx.Provider>
}

export function useAuth() {
  const ctx = useContext(Ctx)
  if (!ctx) throw new Error('useAuth outside provider')
  return ctx
}
