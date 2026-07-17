import { createContext, useContext, useEffect, useState, type ReactNode } from 'react'
import { api, type User } from './lib/api'

type AuthCtx = {
  user: User | null
  loading: boolean
  login: (email: string, password: string) => Promise<void>
  logout: () => Promise<void>
}

const Ctx = createContext<AuthCtx | null>(null)

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    const t = localStorage.getItem('supon_token')
    if (!t) {
      setLoading(false)
      return
    }
    api<User>('/me')
      .then(setUser)
      .catch(() => localStorage.removeItem('supon_token'))
      .finally(() => setLoading(false))
  }, [])

  async function login(email: string, password: string) {
    const data = await api<{ token: string; user: User }>('/login', {
      method: 'POST',
      body: JSON.stringify({ email, password }),
    })
    localStorage.setItem('supon_token', data.token)
    setUser(data.user)
  }

  async function logout() {
    try {
      await api('/logout', { method: 'POST' })
    } finally {
      localStorage.removeItem('supon_token')
      setUser(null)
    }
  }

  return <Ctx.Provider value={{ user, loading, login, logout }}>{children}</Ctx.Provider>
}

export function useAuth() {
  const ctx = useContext(Ctx)
  if (!ctx) throw new Error('useAuth outside provider')
  return ctx
}
