import { api } from './api'

/**
 * Czat firmowy — typy i wywołania API zgodne z kontraktem (GET/POST /chat/…).
 * Serwer jest źródłem prawdy: zdarzenia z websocketu są tylko sygnałem do dociągnięcia danych.
 */

export const CHAT_PERMISSION = 'chat'
/** Najdłuższa wiadomość, którą przyjmuje serwer. */
export const CHAT_MAX_LENGTH = 4000
/** Od tylu znaków pole wpisywania pokazuje licznik. */
export const CHAT_COUNTER_FROM = 3500

export type ChatUser = {
  id: number
  name: string
  online: boolean
  is_me: boolean
}

export type ChatPerson = { id: number; name: string }

export type ChatLinkMeta = {
  type: 'inquiry' | 'tender'
  id: number
  item: number | null
  title: string
  path: string
}

export type ChatMailMeta = {
  subject: string
  from: string
  date: string | null
  message_id: string | null
  body: string | null
}

export type ChatMessage = {
  id: number
  conversation_id: number
  kind: 'text' | 'link' | 'mail' | 'system'
  body: string | null
  meta: { link?: ChatLinkMeta; mail?: ChatMailMeta } | null
  /** null przy kind ≠ system = konto usunięte. */
  user: ChatPerson | null
  deleted: boolean
  client_uuid: string | null
  created_at: string
}

export type ChatConversation = {
  id: number
  type: 'channel' | 'direct'
  /** Dla rozmowy 1:1 — imię drugiej osoby. */
  name: string
  everyone: boolean
  other_user: ChatUser | null
  participants: ChatPerson[]
  last_message: ChatMessage | null
  unread: number
  last_read_message_id: number | null
}

/** Link do zapytania albo przetargu wysyłany z ich stron („Wyślij w czacie”). */
export type ChatLinkInput = { type: 'inquiry' | 'tender'; id: number; item: number | null }

export type ChatSendBody = {
  client_uuid: string
  body: string | null
  link?: ChatLinkInput | null
}

export function fetchChatUsers(): Promise<{ data: ChatUser[] }> {
  return api('/chat/users')
}

export function fetchConversations(): Promise<{ data: ChatConversation[]; unread_total: number }> {
  return api('/chat/conversations')
}

export function fetchConversation(id: number): Promise<{ data: ChatConversation }> {
  return api(`/chat/conversations/${id}`)
}

export function openDirect(userId: number): Promise<{ data: ChatConversation }> {
  return api('/chat/conversations', { method: 'POST', body: JSON.stringify({ user_id: userId }) })
}

export function createChannel(name: string, userIds: number[]): Promise<{ data: ChatConversation }> {
  return api('/chat/conversations', { method: 'POST', body: JSON.stringify({ name, user_ids: userIds }) })
}

export function addParticipants(id: number, userIds: number[]): Promise<{ data: ChatConversation }> {
  return api(`/chat/conversations/${id}/participants`, { method: 'POST', body: JSON.stringify({ user_ids: userIds }) })
}

export function leaveConversation(id: number): Promise<unknown> {
  return api(`/chat/conversations/${id}/leave`, { method: 'POST' })
}

export function fetchMessages(
  id: number,
  params: { after_id?: number; before_id?: number; limit?: number } = {},
): Promise<{ data: ChatMessage[]; has_more: boolean }> {
  const q = new URLSearchParams()
  if (params.after_id !== undefined) q.set('after_id', String(params.after_id))
  if (params.before_id !== undefined) q.set('before_id', String(params.before_id))
  if (params.limit !== undefined) q.set('limit', String(params.limit))
  const qs = q.toString()
  return api(`/chat/conversations/${id}/messages${qs ? `?${qs}` : ''}`)
}

export function sendMessage(id: number, body: ChatSendBody): Promise<{ data: ChatMessage }> {
  return api(`/chat/conversations/${id}/messages`, { method: 'POST', body: JSON.stringify(body) })
}

/** Wiadomość do osoby — serwer sam znajduje albo zakłada rozmowę 1:1. */
export function sendDirectMessage(
  userId: number,
  body: ChatSendBody,
): Promise<{ data: ChatMessage; conversation_id: number }> {
  return api(`/chat/direct/${userId}/messages`, { method: 'POST', body: JSON.stringify(body) })
}

export function deleteMessage(id: number): Promise<{ data: ChatMessage }> {
  return api(`/chat/messages/${id}`, { method: 'DELETE' })
}

export function markRead(id: number, messageId: number): Promise<{ unread_total: number }> {
  return api(`/chat/conversations/${id}/read`, { method: 'POST', body: JSON.stringify({ message_id: messageId }) })
}

export function fetchUnread(): Promise<{ unread_total: number }> {
  return api('/chat/unread')
}

/** Identyfikator wiadomości do bezpiecznego ponowienia wysyłki. randomUUID działa tylko na https i localhost. */
export function newClientUuid(): string {
  if (typeof crypto.randomUUID === 'function') return crypto.randomUUID()
  const b = crypto.getRandomValues(new Uint8Array(16))
  b[6] = (b[6] & 0x0f) | 0x40
  b[8] = (b[8] & 0x3f) | 0x80
  const h = [...b].map((x) => x.toString(16).padStart(2, '0')).join('')
  return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`
}

/** Liczba na plakietce: powyżej 99 — „99+”. */
export function unreadLabel(n: number): string {
  return n > 99 ? '99+' : String(n)
}

/** Inicjały do kółka z osobą: „Marek Kowalski” → „MK”. */
export function initials(name: string): string {
  const parts = name.trim().split(/\s+/).filter(Boolean)
  if (parts.length === 0) return '?'
  const first = parts[0][0] ?? ''
  const last = parts.length > 1 ? (parts[parts.length - 1][0] ?? '') : (parts[0][1] ?? '')
  return (first + last).toUpperCase()
}

/** Stały kolor kółka dla osoby (pełne nazwy klas — skaner motywów nie widzi składanych). */
const AVATAR_COLORS = [
  'bg-blue-100 text-blue-800',
  'bg-green-100 text-green-800',
  'bg-rose-100 text-rose-800',
  'bg-amber-100 text-amber-800',
  'bg-violet-100 text-violet-800',
  'bg-teal-100 text-teal-800',
] as const

export function avatarColor(userId: number | null | undefined): string {
  if (!userId) return 'bg-slate-200 text-slate-700'
  return AVATAR_COLORS[userId % AVATAR_COLORS.length]
}

/**
 * Ścieżka z karty linku przechodzi do routera aplikacji tylko jako ścieżka wewnętrzna
 * („/inquiries/91”), nigdy jako adres innej strony („//host”, „https:”).
 */
export function safeAppPath(path: unknown): string | null {
  if (typeof path !== 'string') return null
  if (!path.startsWith('/') || path.startsWith('//') || path.includes('\\')) return null
  return path
}

/** Porównanie do wyszukiwania: bez wielkości liter i polskich znaków („lukasz” znajduje „Łukasz”). */
export function foldText(s: string): string {
  return s
    .toLocaleLowerCase('pl-PL')
    .replace(/ł/g, 'l')
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
}

/** Krótki opis wiadomości na liście rozmów. */
export function messagePreview(m: ChatMessage | null): string {
  if (!m) return ''
  if (m.deleted) return 'wiadomość usunięta'
  const body = (m.body ?? '').replace(/\s+/g, ' ').trim()
  if (m.kind === 'mail') return `Mail: ${m.meta?.mail?.subject ?? ''}${body ? ` · ${body}` : ''}`
  if (m.kind === 'link') return body || (m.meta?.link?.title ?? 'Link')
  return body
}

const timeFmt = new Intl.DateTimeFormat('pl-PL', { hour: '2-digit', minute: '2-digit' })
const dayFmt = new Intl.DateTimeFormat('pl-PL', { day: 'numeric', month: 'long', year: 'numeric' })
const shortFmt = new Intl.DateTimeFormat('pl-PL', { day: '2-digit', month: '2-digit' })

export function timeOf(iso: string): string {
  const d = new Date(iso)
  return Number.isNaN(d.getTime()) ? '' : timeFmt.format(d)
}

function dayKey(d: Date): string {
  return `${d.getFullYear()}-${d.getMonth()}-${d.getDate()}`
}

/** Napis na separatorze dnia: „Dzisiaj”, „Wczoraj” albo data. */
export function dayLabel(iso: string): string {
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return ''
  const now = new Date()
  const yesterday = new Date(now.getFullYear(), now.getMonth(), now.getDate() - 1)
  if (dayKey(d) === dayKey(now)) return 'Dzisiaj'
  if (dayKey(d) === dayKey(yesterday)) return 'Wczoraj'
  return dayFmt.format(d)
}

export function sameDay(a: string, b: string): boolean {
  const da = new Date(a)
  const db = new Date(b)
  return !Number.isNaN(da.getTime()) && !Number.isNaN(db.getTime()) && dayKey(da) === dayKey(db)
}

/** Godzina na liście rozmów: dziś — godzina, wcześniej — dzień i miesiąc. */
export function listTime(iso: string): string {
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return ''
  return dayKey(d) === dayKey(new Date()) ? timeFmt.format(d) : shortFmt.format(d)
}

/** Data maila: tekst z serwera bywa ISO albo już sformatowany — ISO pokazujemy po polsku, resztę bez zmian. */
export function mailDateLabel(value: string | null): string {
  if (!value) return ''
  const d = new Date(value)
  if (/^\d{4}-\d{2}-\d{2}/.test(value) && !Number.isNaN(d.getTime())) {
    return `${shortFmt.format(d)}, ${timeFmt.format(d)}`
  }
  return value
}
