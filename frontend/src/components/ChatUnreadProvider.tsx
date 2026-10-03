import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { useAuth } from '../auth'
import { can } from '../lib/api'
import { CHAT_PERMISSION, fetchUnread, unreadLabel } from '../lib/chat'
import { ChatUnreadContext, useChatUnread } from '../lib/chatUnread'
import { onRealtime, useRealtimeStatus } from '../lib/realtime'

const POLL_MS = 30_000
/**
 * Zapas przy połączeniu na żywo: zdarzenie może przepaść (serwer wiadomości odpowiedział za wolno, zła konfiguracja),
 * a przeglądarka dalej jest „połączona” — wtedy licznik stałby do następnej wiadomości.
 */
const LIVE_BACKUP_POLL_MS = 3 * 60_000
const TITLE_PREFIX = /^\(\d+\+?\) /

/**
 * Licznik nieprzeczytanych dla menu i tytułu karty („(3) Przetargi Supon”).
 * Przy działającym połączeniu w czasie rzeczywistym licznik odświeża się na sygnał (i zapasowo co 3 min); bez niego —
 * co 30 s i po powrocie do karty, tylko gdy karta jest widoczna.
 */
export function ChatUnreadProvider({ children }: { children: ReactNode }) {
  const { user } = useAuth()
  const enabled = can(user, CHAT_PERMISSION)
  const [unreadTotal, setUnread] = useState<number | null>(null)
  const live = useRealtimeStatus() === 'connected'
  // Każdy zapis podbija numer; odpowiedź starszego zapytania nie nadpisuje nowszej wartości.
  const seq = useRef(0)

  const setUnreadTotal = useCallback((n: number) => {
    seq.current++
    setUnread(n)
  }, [])

  const refreshUnread = useCallback(() => {
    if (!enabled) return
    const started = ++seq.current
    fetchUnread().then(
      (r) => {
        if (started === seq.current) setUnread(r.unread_total)
      },
      () => {},
    )
  }, [enabled])

  useEffect(() => {
    if (!enabled) {
      setUnread(null)
      return
    }
    refreshUnread()
  }, [enabled, refreshUnread])

  useEffect(() => {
    if (!enabled) return
    const offs = [
      onRealtime('chat.message', () => refreshUnread()),
      onRealtime('chat.read', (e) => setUnreadTotal(e.unread_total)),
      onRealtime('chat.conversation', () => refreshUnread()),
      onRealtime('chat.deleted', () => refreshUnread()),
      onRealtime('connected', () => refreshUnread()),
    ]
    return () => offs.forEach((off) => off())
  }, [enabled, refreshUnread, setUnreadTotal])

  useEffect(() => {
    if (!enabled) return
    const timer = window.setInterval(
      () => {
        if (document.visibilityState === 'visible') refreshUnread()
      },
      live ? LIVE_BACKUP_POLL_MS : POLL_MS,
    )
    const onVisibility = () => {
      if (document.visibilityState === 'visible') refreshUnread()
    }
    document.addEventListener('visibilitychange', onVisibility)
    return () => {
      window.clearInterval(timer)
      document.removeEventListener('visibilitychange', onVisibility)
    }
  }, [enabled, live, refreshUnread])

  useEffect(() => {
    const base = document.title.replace(TITLE_PREFIX, '')
    document.title = unreadTotal && unreadTotal > 0 ? `(${unreadLabel(unreadTotal)}) ${base}` : base
  }, [unreadTotal])

  // Po wylogowaniu tytuł wraca do zwykłego.
  useEffect(
    () => () => {
      document.title = document.title.replace(TITLE_PREFIX, '')
    },
    [],
  )

  const value = useMemo(
    () => ({ unreadTotal, setUnreadTotal, refreshUnread }),
    [unreadTotal, setUnreadTotal, refreshUnread],
  )
  return <ChatUnreadContext.Provider value={value}>{children}</ChatUnreadContext.Provider>
}

/** Plakietka z liczbą nieprzeczytanych przy pozycji „Czat” w menu. */
export function ChatNavBadge() {
  const { unreadTotal } = useChatUnread()
  if (!unreadTotal || unreadTotal <= 0) return null
  return (
    <span
      className="app-badge float-right ml-2 rounded-full bg-sky-400 px-1.5 py-0.5 text-[10px] font-bold leading-none text-slate-900"
      aria-label={`Nieprzeczytane: ${unreadTotal}`}
    >
      {unreadLabel(unreadTotal)}
    </span>
  )
}
