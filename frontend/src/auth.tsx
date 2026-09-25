import { createContext, useCallback, useContext, useEffect, useState, type ReactNode } from 'react'
import { api, ApiError, type User } from './lib/api'

type AuthCtx = {
  user: User | null
  loading: boolean
  /**
   * Zapisane logowanie nie dało się sprawdzić (sieć, błąd serwera, wdrożenie). Klucz zostaje w przeglądarce —
   * po powrocie serwera `retry` wpuszcza bez ponownego logowania; null = brak problemu.
   */
  connectionError: string | null
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

  const verify = useCallback(async () => {
    if (!localStorage.getItem('supon_token')) return
    try {
      setUser(await api<User>('/me'))
      setConnectionError(null)
    } catch (ex) {
      // Tylko 401 znaczy, że serwer już nie zna tego klucza. Po chwilowym błędzie wylogowanie kazałoby zalogować
      // się od nowa, a stary klucz zostałby na serwerze jako kolejna „sesja”.
      if (ex instanceof ApiError && ex.status === 401) {
        localStorage.removeItem('supon_token')
        setConnectionError(null)
      } else {
        setConnectionError(connectionErrorText(ex))
      }
    }
  }, [])

  useEffect(() => {
    void verify().finally(() => setLoading(false))
  }, [verify])

  async function login(email: string, password: string) {
    const data = await api<{ token: string; user: User }>('/login', {
      method: 'POST',
      body: JSON.stringify({ email, password }),
    })
    localStorage.setItem('supon_token', data.token)
    setConnectionError(null)
    setUser(data.user)
  }

  async function logout() {
    try {
      await api('/logout', { method: 'POST' })
    } finally {
      localStorage.removeItem('supon_token')
      setConnectionError(null)
      setUser(null)
    }
  }

  return (
    <Ctx.Provider value={{ user, loading, connectionError, retry: verify, login, logout, replaceUser: setUser }}>
      {children}
    </Ctx.Provider>
  )
}

export function useAuth() {
  const ctx = useContext(Ctx)
  if (!ctx) throw new Error('useAuth outside provider')
  return ctx
}
