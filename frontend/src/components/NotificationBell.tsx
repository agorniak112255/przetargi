import { useCallback, useEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../lib/api'

type AppNotification = {
  id: string
  type: string
  data: {
    type?: string
    message?: string
    tender_id?: number
    tender_number?: string
    tender_title?: string
    inviter_name?: string
  }
  read_at: string | null
  created_at: string | null
}

export function NotificationBell() {
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
        className="relative w-full rounded bg-slate-700 px-3 py-2 text-left text-xs hover:bg-slate-600"
      >
        Powiadomienia
        {unread > 0 && (
          <span className="absolute right-2 top-1.5 rounded-full bg-sky-400 px-1.5 py-0.5 text-[10px] font-bold text-slate-900">
            {unread}
          </span>
        )}
      </button>
      {open && (
        <div className="absolute bottom-full left-0 z-40 mb-2 w-72 rounded-xl border border-slate-600 bg-slate-900 p-2 shadow-xl">
          <div className="mb-2 flex items-center justify-between px-1">
            <span className="text-[11px] font-semibold text-slate-200">Ostatnie</span>
            {unread > 0 && (
              <button type="button" className="text-[10px] text-sky-300 hover:underline" onClick={() => void markAll()}>
                Oznacz wszystkie
              </button>
            )}
          </div>
          <div className="max-h-72 space-y-1 overflow-auto">
            {rows.length === 0 && <p className="px-2 py-3 text-[11px] text-slate-400">Brak powiadomień</p>}
            {rows.map((n) => {
              const tenderId = n.data.tender_id
              const body = (
                <div
                  className={`rounded-lg px-2 py-2 text-[11px] ${
                    n.read_at ? 'bg-slate-800/60 text-slate-400' : 'bg-slate-800 text-slate-100'
                  }`}
                >
                  <div className="font-medium">{n.data.message ?? 'Powiadomienie'}</div>
                  {n.data.tender_title && (
                    <div className="mt-0.5 text-slate-400">{n.data.tender_title}</div>
                  )}
                </div>
              )
              return (
                <div key={n.id} onClick={() => void markRead(n.id)}>
                  {tenderId ? (
                    <Link to={`/tenders/${tenderId}`} onClick={() => setOpen(false)}>
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
