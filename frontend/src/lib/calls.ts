import { api } from './api'
import { publicDir } from './publicDir'

/**
 * Rozmowy głosowe i wideo w czacie — typy i wywołania API (GET/POST /chat/calls/…).
 * Celowo bez livekit-client: ten plik ładuje się w całej aplikacji (dzwonek, karta wiadomości w czacie),
 * a biblioteka rozmów jest tylko w leniwie ładowanej stronie pages/CallPage.tsx.
 */

export type CallKind = 'audio' | 'video'
export type CallStatus = 'ringing' | 'active' | 'ended' | 'missed'
export type CallMemberState = 'invited' | 'connecting' | 'declined' | 'joined' | 'left'

export type CallMember = { id: number; name: string; state: CallMemberState }

export type Call = {
  id: number
  conversation_id: number
  kind: CallKind
  status: CallStatus
  started_by: { id: number; name: string } | null
  started_at: string
  answered_at: string | null
  ended_at: string | null
  duration_seconds: number | null
  message_id: number | null
  members: CallMember[]
  /** Osoby faktycznie połączone (potwierdzone przez serwer rozmów). */
  joined_count: number
}

/** Adres serwera rozmów i krótko ważny klucz wejścia do pokoju. */
export type CallJoin = { url: string; token: string }

export type CallsConfig = { enabled: boolean; max_participants: number }

/** meta.call wiadomości kind=call — ta sama wiadomość zmienia się razem ze stanem rozmowy. */
export type ChatCallMeta = {
  id: number
  kind: CallKind
  status: CallStatus
  duration_seconds: number | null
}

/** Jak długo dzwoni dzwonek (tyle samo co na serwerze — ring_seconds). */
export const RING_SECONDS = 45

export function fetchCallsConfig(): Promise<CallsConfig> {
  return api('/chat/calls/config')
}

/** Nowa rozmowa w rozmowie czatu; gdy już jakaś trwa albo dzwoni — serwer zwraca tę samą (200). */
export function startCall(conversationId: number, kind: CallKind): Promise<{ data: Call; join: CallJoin }> {
  return api(`/chat/conversations/${conversationId}/calls`, { method: 'POST', body: JSON.stringify({ kind }) })
}

export function fetchCall(id: number): Promise<{ data: Call }> {
  return api(`/chat/calls/${id}`)
}

export function joinCall(id: number): Promise<{ data: Call; join: CallJoin }> {
  return api(`/chat/calls/${id}/join`, { method: 'POST' })
}

export function declineCall(id: number): Promise<{ data: Call }> {
  return api(`/chat/calls/${id}/decline`, { method: 'POST' })
}

export function leaveCall(id: number): Promise<{ data: Call }> {
  return api(`/chat/calls/${id}/leave`, { method: 'POST' })
}

/**
 * Pełny adres strony rozmowy do window.open — z prefiksem aplikacji (XAMPP `/Przetargi`, produkcja bez).
 * camera: true/false wymusza stan kamery na ekranie „Dołącz do rozmowy”, undefined = domyślny.
 */
export function callPageUrl(id: number, camera?: boolean): string {
  const query = camera === undefined ? '' : `?camera=${camera ? 1 : 0}`
  return `${publicDir()}/czat/rozmowa/${id}${query}`
}

/** Status końcowy — późniejsze zdarzenia go nie zmieniają. */
export function isCallFinished(status: CallStatus): boolean {
  return status === 'ended' || status === 'missed'
}

const STATUS_RANK: Record<CallStatus, number> = { ringing: 0, active: 1, ended: 2, missed: 2 }

/** Czy stan `next` jest nowszy albo równy `prev` (ringing → active → ended/missed; wstecz nie wracamy). */
export function isCallStatusNewerOrSame(prev: CallStatus, next: CallStatus): boolean {
  return STATUS_RANK[next] >= STATUS_RANK[prev]
}

export function callKindLabel(kind: CallKind): string {
  return kind === 'video' ? 'Rozmowa wideo' : 'Rozmowa głosowa'
}

/** Czas rozmowy na karcie w czacie: „12 min”, krótsze niż minuta — „poniżej minuty”; brak danych — pusty napis. */
export function callDurationLabel(seconds: number | null): string {
  if (seconds === null || !Number.isFinite(seconds)) return ''
  if (seconds < 60) return 'poniżej minuty'
  const minutes = Math.round(seconds / 60)
  if (minutes < 60) return `${minutes} min`
  const h = Math.floor(minutes / 60)
  const m = minutes % 60
  return m ? `${h} godz. ${m} min` : `${h} godz.`
}
