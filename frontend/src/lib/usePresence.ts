import { useEffect } from 'react'
import { useLocation } from 'react-router-dom'
import { api } from './api'

const HEARTBEAT_MS = 60_000

function sendPresence(path: string): void {
  // Tylko pathname — w query/hash bywają wyszukiwane frazy. Błędy bez komunikatu i bez ponowień.
  void api<unknown>('/me/presence', {
    method: 'POST',
    body: JSON.stringify({ path: path.slice(0, 255) }),
  }).catch(() => {})
}

/**
 * Sygnał obecności dla ekranu „Aktywne sesje”: przy zmianie podstrony, co minutę
 * (tylko przy widocznej karcie) i od razu po powrocie do karty.
 */
export function usePresence(enabled: boolean): void {
  const { pathname } = useLocation()

  useEffect(() => {
    if (!enabled) return
    sendPresence(pathname)

    const timer = window.setInterval(() => {
      if (document.visibilityState === 'visible') sendPresence(pathname)
    }, HEARTBEAT_MS)
    const onVisibility = () => {
      if (document.visibilityState === 'visible') sendPresence(pathname)
    }
    document.addEventListener('visibilitychange', onVisibility)

    return () => {
      window.clearInterval(timer)
      document.removeEventListener('visibilitychange', onVisibility)
    }
  }, [enabled, pathname])
}
