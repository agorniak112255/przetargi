import Echo from 'laravel-echo'
import Pusher, { type ChannelAuthorizationHandler } from 'pusher-js'
import { useSyncExternalStore } from 'react'
import { api } from './api'

/**
 * Połączenie z serwerem zdarzeń (Laravel Reverb) dla czatu.
 *
 * Zdarzenie to tylko sygnał — pełne dane zawsze dociągamy z API, a po każdym (ponownym) połączeniu
 * strony odświeżają to, co pokazują (zdarzenie „connected”), bo w przerwie mogły przepaść sygnały.
 * Gdy serwer zdarzeń nie jest włączony (GET /realtime → null) albo połączenie nie działa, status
 * jest inny niż „connected” i strony odpytują API same (licznik co 30 s, otwarta rozmowa co 10 s).
 *
 * Jedna instancja na zalogowaną osobę: auth.tsx uruchamia ją po zalogowaniu i zamyka po wylogowaniu.
 */

export type RealtimeStatus = 'off' | 'connecting' | 'connected' | 'disconnected'

type RealtimeConfig = { key: string; host: string; port: number; scheme: 'https' | 'http' }

/** Odpowiedź POST /broadcasting/auth (podpis kanału prywatnego). */
type ChannelAuthorizationData = { auth: string; channel_data?: string; shared_secret?: string }

export type ChatMessageEvent = {
  conversation_id: number
  message_id: number
  kind: string
  user: { id: number; name: string } | null
  preview: string
  conversation_name: string
  created_at: string
}

export type ChatReadEvent = { conversation_id: number; last_read_message_id: number; unread_total: number }

export type ChatConversationEvent = { conversation_id: number }

export type ChatDeletedEvent = { conversation_id: number; message_id: number }

type RealtimeEvents = {
  'chat.message': ChatMessageEvent
  'chat.read': ChatReadEvent
  'chat.conversation': ChatConversationEvent
  'chat.deleted': ChatDeletedEvent
  /** Kanał (ponownie) zasubskrybowany — czas odświeżyć dane z API. */
  connected: Record<string, never>
}

type EventName = keyof RealtimeEvents
type Handler<K extends EventName> = (payload: RealtimeEvents[K]) => void

let status: RealtimeStatus = 'off'
let echo: Echo<'reverb'> | null = null
let generation = 0
let stopStatusWatch: (() => void) | null = null

const statusListeners = new Set<() => void>()
const handlers: { [K in EventName]: Set<Handler<K>> } = {
  'chat.message': new Set(),
  'chat.read': new Set(),
  'chat.conversation': new Set(),
  'chat.deleted': new Set(),
  connected: new Set(),
}

function setStatus(next: RealtimeStatus) {
  if (status === next) return
  status = next
  statusListeners.forEach((l) => l())
}

function emit<K extends EventName>(name: K, payload: RealtimeEvents[K]) {
  ;(handlers[name] as Set<Handler<K>>).forEach((h) => {
    try {
      h(payload)
    } catch {
      /* błąd jednego odbiorcy nie zatrzymuje pozostałych */
    }
  })
}

/** Nasłuch zdarzenia; zwraca funkcję odpinającą (do zwrócenia z useEffect). */
export function onRealtime<K extends EventName>(name: K, handler: Handler<K>): () => void {
  const set = handlers[name] as Set<Handler<K>>
  set.add(handler)
  return () => {
    set.delete(handler)
  }
}

export function getRealtimeStatus(): RealtimeStatus {
  return status
}

function subscribeStatus(listener: () => void): () => void {
  statusListeners.add(listener)
  return () => {
    statusListeners.delete(listener)
  }
}

/** Stan połączenia do decyzji „odpytywać API czy czekać na sygnał”. */
export function useRealtimeStatus(): RealtimeStatus {
  return useSyncExternalStore(subscribeStatus, getRealtimeStatus, getRealtimeStatus)
}

/** Zamyka połączenie (wylogowanie, inna osoba, brak uprawnienia). */
export function stopRealtime(): void {
  generation++
  stopStatusWatch?.()
  stopStatusWatch = null
  if (echo) {
    try {
      echo.disconnect()
    } catch {
      /* połączenie i tak porzucamy */
    }
    echo = null
  }
  setStatus('off')
}

/** Nowe połączenie dla zalogowanej osoby. Bez konfiguracji serwera zostaje „off” — strony odpytują API. */
export async function startRealtime(userId: number): Promise<void> {
  stopRealtime()
  const gen = generation
  let config: RealtimeConfig | null = null
  try {
    config = (await api<{ realtime: RealtimeConfig | null }>('/realtime')).realtime
  } catch {
    config = null
  }
  // W międzyczasie wylogowanie albo kolejne uruchomienie — ta konfiguracja jest już nieaktualna.
  if (gen !== generation || !config || !config.key || !config.host) return

  const tls = config.scheme === 'https'
  setStatus('connecting')
  let instance: Echo<'reverb'>
  try {
    instance = new Echo({
      broadcaster: 'reverb',
      key: config.key,
      wsHost: config.host,
      wsPort: config.port,
      wssPort: config.port,
      forceTLS: tls,
      enabledTransports: ['ws', 'wss'],
      disableStats: true,
      withoutInterceptors: true,
      Pusher,
      // Autoryzacja kanału prywatnego przez wspólne api() — ten sam token co reszta aplikacji.
      channelAuthorization: {
        customHandler: ((params, callback) => {
          api<ChannelAuthorizationData>('/broadcasting/auth', {
            method: 'POST',
            body: JSON.stringify({ socket_id: params.socketId, channel_name: params.channelName }),
          }).then(
            (data) => callback(null, data),
            (ex: unknown) => callback(ex instanceof Error ? ex : new Error('Brak dostępu do kanału czatu.'), null),
          )
        }) satisfies ChannelAuthorizationHandler,
      },
    })
  } catch {
    setStatus('disconnected')
    return
  }
  echo = instance

  stopStatusWatch = instance.connector.onConnectionChange((s) => {
    if (gen !== generation) return
    // „connected” ustawia dopiero udana subskrypcja kanału (niżej) — samo połączenie bez kanału nic nie daje.
    if (s === 'connecting') setStatus('connecting')
    else if (s !== 'connected') setStatus('disconnected')
  })

  instance
    .private(`user.${userId}`)
    .subscribed(() => {
      if (gen !== generation) return
      setStatus('connected')
      emit('connected', {})
    })
    .error(() => {
      if (gen !== generation) return
      setStatus('disconnected')
    })
    .listen('.chat.message', (e: ChatMessageEvent) => {
      if (gen === generation) emit('chat.message', e)
    })
    .listen('.chat.read', (e: ChatReadEvent) => {
      if (gen === generation) emit('chat.read', e)
    })
    .listen('.chat.conversation', (e: ChatConversationEvent) => {
      if (gen === generation) emit('chat.conversation', e)
    })
    .listen('.chat.deleted', (e: ChatDeletedEvent) => {
      if (gen === generation) emit('chat.deleted', e)
    })
}
