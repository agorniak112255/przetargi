import { useCallback, useEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { api, type AppNotificationData } from '../lib/api'
import { NavIcon } from './NavIcon'

type AppNotification = {
  id: string
  type: string
  data: AppNotificationData & {
    inviter_name?: string
  }
  read_at: string | null
  created_at: string | null
}

/**
 * Link powiadomienia: nowe rodzaje mają url (ścieżka w aplikacji), starsze — przetarg albo kampanię z pól danych.
 * Adres spoza aplikacji (pełny adres z http) pomijamy — dzwonek prowadzi tylko po aplikacji.
 */
function notificationLink(data: AppNotification['data']): string | null {
  if (data.url && data.url.startsWith('/') && !data.url.startsWith('//')) return data.url
  if (data.tender_id) return `/tenders/${data.tender_id}`
  if (data.campaign_id) return `/kampanie/${data.campaign_id}`
  if (data.inquiry_id) return `/inquiries/${data.inquiry_id}`
  return null
}

/** Kiedy: „dziś 8:41”, „wczoraj 16:05”, „30.09 11:02” (czas przeglądarki). */
function notificationWhen(iso: string | null): string {
  if (!iso) return ''
  const at = new Date(iso)
  if (Number.isNaN(at.getTime())) return ''
  const time = at.toLocaleTimeString('pl-PL', { hour: 'numeric', minute: '2-digit' })
  const today = new Date()
  const yesterday = new Date(today.getFullYear(), today.getMonth(), today.getDate() - 1)
  const sameDay = (a: Date, b: Date) =>
    a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate()
  if (sameDay(at, today)) return `dziś ${time}`
  if (sameDay(at, yesterday)) return `wczoraj ${time}`
  return `${at.getDate()}.${String(at.getMonth() + 1).padStart(2, '0')} ${time}`
}

export function NotificationBell({ collapsed = false }: { collapsed?: boolean }) {
  const [open, setOpen] = useState(false)
  const [rows, setRows] = useState<AppNotification[]>([])
  const [unread, setUnread] = useState(0)
  const boxRef = useRef<HTMLDivElement | null>(null)

  const load = useCallback(async () => {
    try {
      const res = await api<{ data: AppNotification[]; unread_count: number }>('/notifications?limit=15')
      setRows(res.data)
      setUnread(res.unread_count)
    } catch {
      /* ignore */
    }
  }, [])

  useEffect(() => {
    void load()
    const t = window.setInterval(() => void load(), 60000)
    return () => window.clearInterval(t)
  }, [load])

  useEffect(() => {
    if (!open) return
    function onDoc(e: MouseEvent) {
      if (!boxRef.current?.contains(e.target as Node)) setOpen(false)
    }
    document.addEventListener('mousedown', onDoc)
    return () => document.removeEventListener('mousedown', onDoc)
  }, [open])

  async function markRead(id: string) {
    await api(`/notifications/${id}/read`, { method: 'POST' })
    await load()
  }

  async function markAll() {
    await api('/notifications/read-all', { method: 'POST' })
    await load()
  }

  return (
    <div className="relative m-4" ref={boxRef}>
      <button
        type="button"
        onClick={() => {
          setOpen((v) => !v)
          void load()
        }}
        data-tip={collapsed ? (unread > 0 ? `Powiadomienia: ${unread}` : 'Powiadomienia') : undefined}
        className="app-sidebar-btn relative w-full rounded bg-slate-700 px-3 py-2 text-left text-xs hover:bg-slate-600"
      >
        <NavIcon name="notifications" className="app-nav-icon" />
        <span className="app-nav-label">Powiadomienia</span>
        {unread > 0 && (
          <span className="app-badge absolute right-2 top-1.5 rounded-full bg-sky-400 px-1.5 py-0.5 text-[10px] font-bold text-slate-900">
            {unread}
          </span>
        )}
      </button>
      {open && (
        <div className="app-popover absolute bottom-full left-0 z-40 mb-2 w-80 rounded-xl border border-slate-600 bg-slate-900 p-2 shadow-xl">
          <div className="mb-2 flex items-center justify-between gap-2 px-1">
            <span className="text-[11px] font-semibold text-slate-200">Ostatnie</span>
            <span className="flex items-center gap-3">
              {unread > 0 && (
                <button type="button" className="text-[10px] text-sky-300 hover:underline" onClick={() => void markAll()}>
                  Oznacz wszystkie jako przeczytane
                </button>
              )}
              <Link
                to="/account#powiadomienia"
                className="text-[10px] text-sky-300 hover:underline"
                onClick={() => setOpen(false)}
              >
                Ustawienia
              </Link>
            </span>
          </div>
          <div className="max-h-80 space-y-1 overflow-auto">
            {rows.length === 0 && <p className="px-2 py-3 text-[11px] text-slate-400">Brak powiadomień</p>}
            {rows.map((n) => {
              const link = notificationLink(n.data)
              const title = n.data.title ?? n.data.message ?? 'Powiadomienie'
              const detail = n.data.body ?? n.data.tender_title
              const when = notificationWhen(n.created_at)
              const body = (
                <div
                  className={`app-popover-item rounded-lg px-2 py-2 text-[11px] ${
                    n.read_at ? 'bg-slate-800/60 text-slate-400' : 'bg-slate-800 text-slate-100'
                  }`}
                >
                  <div className="font-medium">{title}</div>
                  {detail && <div className="mt-0.5 line-clamp-3 whitespace-pre-line text-slate-400">{detail}</div>}
                  {when && <div className="mt-0.5 text-[10px] text-slate-500">{when}</div>}
                </div>
              )
              return (
                <div key={n.id} onClick={() => void markRead(n.id)}>
                  {link ? (
                    <Link to={link} onClick={() => setOpen(false)}>
                      {body}
                    </Link>
                  ) : (
                    body
                  )}
                </div>
              )
            })}
          </div>
        </div>
      )}
    </div>
  )
}
