import { useEffect, useRef, useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../auth'
import { can } from '../lib/api'
import {
  CHAT_MAX_LENGTH,
  CHAT_PERMISSION,
  fetchChatUsers,
  fetchConversations,
  newClientUuid,
  sendDirectMessage,
  sendMessage,
  type ChatConversation,
  type ChatUser,
} from '../lib/chat'

type Target = { kind: 'user' | 'channel'; id: number }

function parseTarget(value: string): Target | null {
  const m = /^(u|c):(\d+)$/.exec(value)
  if (!m) return null
  return { kind: m[1] === 'u' ? 'user' : 'channel', id: Number(m[2]) }
}

/**
 * „Wyślij w czacie” na stronie zapytania i przetargu: wybór osoby albo kanału, komentarz,
 * wiadomość z linkiem. Tytuł i adres linku buduje serwer (sprawdza też, czy nadawca ma dostęp).
 * Bez uprawnienia do czatu przycisku nie ma.
 */
export function ShareToChatButton({
  link,
  items,
}: {
  link: { type: 'inquiry' | 'tender'; id: number }
  /** Pozycje do wskazania (np. pozycje zapytania); brak — link do całości. */
  items?: Array<{ number: number; label: string }>
}) {
  const { user } = useAuth()
  const [open, setOpen] = useState(false)
  const [sent, setSent] = useState<{ conversationId: number; name: string } | null>(null)

  if (!can(user, CHAT_PERMISSION)) return null

  return (
    <>
      <button
        type="button"
        onClick={() => {
          setSent(null)
          setOpen(true)
        }}
        className="rounded border border-slate-300 bg-white px-2 py-1.5 text-[11px] font-semibold text-slate-700 hover:bg-slate-50"
      >
        Wyślij w czacie
      </button>
      {sent && !open && (
        <span className="self-center text-[11px] text-green-700">
          Wysłano: {sent.name} ·{' '}
          <Link to={`/czat?c=${sent.conversationId}`} className="font-medium text-sky-700 hover:underline">
            otwórz rozmowę
          </Link>
        </span>
      )}
      {open && (
        <ShareDialog
          link={link}
          items={items ?? []}
          onClose={() => setOpen(false)}
          onSent={(conversationId, name) => {
            setSent({ conversationId, name })
            setOpen(false)
          }}
        />
      )}
    </>
  )
}

function ShareDialog({
  link,
  items,
  onClose,
  onSent,
}: {
  link: { type: 'inquiry' | 'tender'; id: number }
  items: Array<{ number: number; label: string }>
  onClose: () => void
  onSent: (conversationId: number, name: string) => void
}) {
  const [users, setUsers] = useState<ChatUser[] | null>(null)
  const [channels, setChannels] = useState<ChatConversation[]>([])
  const [loadErr, setLoadErr] = useState('')
  const [target, setTarget] = useState('')
  const [item, setItem] = useState('')
  const [comment, setComment] = useState('')
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  // Jeden identyfikator na wysyłkę do jednego adresata — ponowienie po błędzie nie zrobi dubla.
  const uuid = useRef<{ target: string; value: string } | null>(null)

  useEffect(() => {
    let off = false
    Promise.all([fetchChatUsers(), fetchConversations()]).then(
      ([u, c]) => {
        if (off) return
        setUsers(u.data.filter((x) => !x.is_me))
        setChannels(c.data.filter((x) => x.type === 'channel'))
      },
      (ex: unknown) => {
        if (!off) setLoadErr(ex instanceof Error ? ex.message : 'Nie udało się wczytać listy osób.')
      },
    )
    return () => {
      off = true
    }
  }, [])

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose()
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [onClose])

  const chosen = parseTarget(target)

  async function submit(e: FormEvent) {
    e.preventDefault()
    if (!chosen || busy) return
    if (!uuid.current || uuid.current.target !== target) uuid.current = { target, value: newClientUuid() }
    setBusy(true)
    setErr('')
    const body = {
      client_uuid: uuid.current.value,
      body: comment.trim() || null,
      link: { type: link.type, id: link.id, item: item ? Number(item) : null },
    }
    try {
      if (chosen.kind === 'user') {
        const r = await sendDirectMessage(chosen.id, body)
        onSent(r.conversation_id, users?.find((u) => u.id === chosen.id)?.name ?? 'osoba')
      } else {
        const r = await sendMessage(chosen.id, body)
        onSent(r.data.conversation_id, channels.find((c) => c.id === chosen.id)?.name ?? 'kanał')
      }
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Nie udało się wysłać.')
      setBusy(false)
    }
  }

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
      role="dialog"
      aria-modal="true"
      aria-label="Wyślij w czacie"
      onMouseDown={(e) => {
        if (e.target === e.currentTarget) onClose()
      }}
    >
      <form
        onSubmit={(e) => void submit(e)}
        className="w-full max-w-md space-y-3 rounded-xl bg-white p-4 text-left text-sm font-normal shadow-xl"
      >
        <div>
          <h2 className="text-base font-semibold text-slate-900">Wyślij w czacie</h2>
          <p className="text-xs text-slate-500">
            {link.type === 'inquiry'
              ? 'W czacie pojawi się odnośnik, który otwiera to zapytanie. Otworzą je tylko osoby, które mają do niego dostęp.'
              : 'W czacie pojawi się odnośnik, który otwiera ten przetarg. Otworzą go tylko osoby, które mają do niego dostęp.'}
          </p>
        </div>
        {loadErr && <p className="rounded bg-red-50 px-2 py-1.5 text-xs text-red-700">{loadErr}</p>}
        <label className="block text-xs text-slate-700">
          Do kogo
          <select
            value={target}
            onChange={(e) => setTarget(e.target.value)}
            disabled={users === null}
            className="mt-1 w-full rounded border border-slate-300 bg-white px-2 py-1.5 text-sm"
          >
            <option value="">{users === null && !loadErr ? 'Wczytuję…' : 'Wybierz osobę albo kanał'}</option>
            {users && users.length > 0 && (
              <optgroup label="Osoby">
                {users.map((u) => (
                  <option key={u.id} value={`u:${u.id}`}>
                    {u.name}
                    {u.online ? ' (w pracy)' : ''}
                  </option>
                ))}
              </optgroup>
            )}
            {channels.length > 0 && (
              <optgroup label="Kanały">
                {channels.map((c) => (
                  <option key={c.id} value={`c:${c.id}`}>
                    # {c.name}
                  </option>
                ))}
              </optgroup>
            )}
          </select>
        </label>
        {items.length > 0 && (
          <label className="block text-xs text-slate-700">
            Której pozycji dotyczy
            <select
              value={item}
              onChange={(e) => setItem(e.target.value)}
              className="mt-1 w-full rounded border border-slate-300 bg-white px-2 py-1.5 text-sm"
            >
              <option value="">{link.type === 'inquiry' ? 'Całe zapytanie' : 'Cały przetarg'}</option>
              {items.map((it) => (
                <option key={it.number} value={String(it.number)}>
                  {it.label}
                </option>
              ))}
            </select>
          </label>
        )}
        <label className="block text-xs text-slate-700">
          Komentarz (nieobowiązkowy)
          <textarea
            value={comment}
            onChange={(e) => setComment(e.target.value)}
            maxLength={CHAT_MAX_LENGTH}
            rows={3}
            placeholder="np. Zerkniesz na cenę?"
            className="mt-1 w-full resize-y rounded border border-slate-300 px-2 py-1.5 text-sm"
          />
        </label>
        {err && <p className="rounded bg-red-50 px-2 py-1.5 text-xs text-red-700">{err}</p>}
        <div className="flex justify-end gap-2">
          <button
            type="button"
            onClick={onClose}
            className="rounded border border-slate-300 bg-white px-3 py-1.5 text-xs text-slate-700 hover:bg-slate-50"
          >
            Anuluj
          </button>
          <button
            type="submit"
            disabled={!chosen || busy}
            className="rounded bg-sky-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-sky-700 disabled:opacity-50"
          >
            {busy ? 'Wysyłam…' : 'Wyślij'}
          </button>
        </div>
      </form>
    </div>
  )
}
