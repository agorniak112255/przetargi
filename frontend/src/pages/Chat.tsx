import {
  useCallback,
  useEffect,
  useLayoutEffect,
  useMemo,
  useRef,
  useState,
  useSyncExternalStore,
  type FormEvent,
  type KeyboardEvent as ReactKeyboardEvent,
  type ReactNode,
} from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { useAuth } from '../auth'
import { ApiError } from '../lib/api'
import {
  addParticipants,
  avatarColor,
  CHAT_COUNTER_FROM,
  CHAT_MAX_LENGTH,
  createChannel,
  dayLabel,
  deleteMessage,
  fetchChatUsers,
  fetchConversation,
  fetchConversations,
  fetchMessages,
  foldText,
  initials,
  leaveConversation,
  listTime,
  mailDateLabel,
  markRead,
  messagePreview,
  newClientUuid,
  openDirect,
  safeAppPath,
  sameDay,
  sendMessage,
  timeOf,
  unreadLabel,
  type ChatConversation,
  type ChatMessage,
  type ChatUser,
} from '../lib/chat'
import { useChatUnread } from '../lib/chatUnread'
import { onRealtime, useRealtimeStatus } from '../lib/realtime'

/**
 * Czat firmowy: po lewej kanały i osoby, po prawej otwarta rozmowa.
 * ?c={id} otwiera rozmowę, ?u={id} otwiera (albo zakłada) rozmowę 1:1 z tą osobą.
 * Dane zawsze z API; zdarzenia z serwera (lib/realtime.ts) tylko każą je dociągnąć.
 */

const THREAD_POLL_MS = 10_000
/** Zapas przy połączeniu na żywo — zdarzenie mogło przepaść, a przeglądarka dalej jest „połączona”. */
const THREAD_LIVE_BACKUP_MS = 2 * 60_000
/** Co który obieg odpytywania wczytujemy całą ostatnią stronę — tak docierają usunięcia (after_id ich nie widzi). */
const THREAD_RECONCILE_EVERY = 6
const USERS_POLL_MS = 60_000
const PAGE = 50
/** Kolejne wiadomości tej samej osoby w tym odstępie — bez powtarzania imienia. */
const GROUP_MS = 5 * 60_000

function errorText(ex: unknown, fallback: string): string {
  return ex instanceof Error && ex.message ? ex.message : fallback
}

function subscribeVisibility(cb: () => void): () => void {
  document.addEventListener('visibilitychange', cb)
  return () => document.removeEventListener('visibilitychange', cb)
}

function useDocumentVisible(): boolean {
  return useSyncExternalStore(
    subscribeVisibility,
    () => document.visibilityState === 'visible',
    () => true,
  )
}

/** Scalanie po id (nowsza wersja wygrywa — np. usunięta), rosnąco. */
function mergeMessages(prev: ChatMessage[], incoming: ChatMessage[]): ChatMessage[] {
  if (incoming.length === 0) return prev
  const byId = new Map<number, ChatMessage>()
  for (const m of prev) byId.set(m.id, m)
  for (const m of incoming) byId.set(m.id, m)
  return [...byId.values()].sort((a, b) => a.id - b.id)
}

type Pending = {
  client_uuid: string
  body: string
  status: 'sending' | 'failed'
  error?: string
}

// ——— drobne elementy ———

function Avatar({
  name,
  userId,
  online,
  channel,
}: {
  name: string
  userId?: number | null
  online?: boolean | null
  channel?: boolean
}) {
  if (channel) {
    return (
      <span className="grid h-7 w-7 shrink-0 place-items-center rounded-md bg-slate-100 text-sm text-slate-500" aria-hidden="true">
        #
      </span>
    )
  }
  return (
    <span
      className={`relative grid h-7 w-7 shrink-0 place-items-center rounded-full text-[11px] font-semibold ${avatarColor(userId)}`}
      aria-hidden="true"
    >
      {userId ? initials(name) : '?'}
      {online !== undefined && online !== null && (
        <span
          className={`absolute -bottom-px -right-px h-2.5 w-2.5 rounded-full ring-2 ring-slate-50 ${
            online ? 'bg-green-600' : 'bg-slate-400'
          }`}
        />
      )}
    </span>
  )
}

function CountBadge({ n }: { n: number }) {
  if (n <= 0) return null
  return (
    <span className="min-w-[18px] rounded-full bg-sky-600 px-1.5 text-center text-[10.5px] font-semibold leading-[17px] text-white">
      {unreadLabel(n)}
    </span>
  )
}

const URL_RE = /https?:\/\/[^\s<>"']+/g
const TRAILING = /[.,;:!?)\]}»”’]+$/

/** Tekst wiadomości jako tekst; adresy http/https jako odnośniki w nowej karcie. */
function MessageText({ text }: { text: string }) {
  const parts: ReactNode[] = []
  let last = 0
  for (const m of text.matchAll(URL_RE)) {
    const start = m.index ?? 0
    let url = m[0]
    const tail = url.match(TRAILING)?.[0] ?? ''
    if (tail) url = url.slice(0, -tail.length)
    let ok = false
    try {
      const parsed = new URL(url)
      ok = parsed.protocol === 'http:' || parsed.protocol === 'https:'
    } catch {
      ok = false
    }
    if (!ok) continue
    if (start > last) parts.push(text.slice(last, start))
    parts.push(
      <a key={start} href={url} target="_blank" rel="noopener noreferrer" className="break-all text-sky-700 underline">
        {url}
      </a>,
    )
    last = start + url.length
  }
  if (last < text.length) parts.push(text.slice(last))
  return <div className="whitespace-pre-wrap break-words text-[13px] leading-snug">{parts}</div>
}

function LinkCard({ m }: { m: ChatMessage }) {
  const navigate = useNavigate()
  const link = m.meta?.link
  if (!link) return null
  const path = safeAppPath(link.path)
  const kindLabel = link.type === 'inquiry' ? 'Zapytanie' : 'Przetarg'
  const openLabel = link.item ? `Otwórz pozycję ${link.item}` : link.type === 'inquiry' ? 'Otwórz zapytanie' : 'Otwórz przetarg'
  return (
    <div className="mt-1 grid gap-0.5 border border-l-[3px] border-slate-200 border-l-sky-600 bg-slate-50 px-2.5 py-1.5 text-xs">
      <span className="text-[10.5px] uppercase tracking-wide text-slate-500">{kindLabel}</span>
      <span className="font-semibold text-slate-800">{link.title}</span>
      {link.item ? <span className="text-slate-600">Pozycja {link.item}</span> : null}
      {path ? (
        <button
          type="button"
          onClick={() => navigate(path)}
          className="justify-self-start font-medium text-sky-700 hover:underline"
        >
          {openLabel}
        </button>
      ) : (
        <span className="text-slate-500">Odnośnik niedostępny</span>
      )}
    </div>
  )
}

function MailCard({ m }: { m: ChatMessage }) {
  const mail = m.meta?.mail
  if (!mail) return null
  const date = mailDateLabel(mail.date)
  return (
    <div className="mt-1 grid gap-0.5 border border-l-[3px] border-slate-200 border-l-violet-700 bg-violet-50 px-2.5 py-1.5 text-xs">
      <span className="text-[10.5px] uppercase tracking-wide text-slate-500">Mail z Thunderbirda</span>
      <span className="font-semibold text-slate-800">{mail.subject || '(bez tematu)'}</span>
      <span className="text-slate-600">
        Od: {mail.from}
        {date ? ` · ${date}` : ''}
      </span>
      {mail.body ? (
        <details className="mt-0.5">
          <summary className="cursor-pointer select-none font-medium text-violet-800">Pokaż treść maila</summary>
          <div className="mt-1 max-h-72 overflow-y-auto whitespace-pre-wrap break-words rounded border border-slate-200 bg-white p-2 text-slate-700">
            {mail.body}
          </div>
        </details>
      ) : (
        <span className="text-slate-500">Bez treści — tylko temat, nadawca i data.</span>
      )}
    </div>
  )
}

function MessageItem({
  m,
  own,
  showHeader,
  confirming,
  onAskDelete,
  onCancelDelete,
  onDelete,
  deleting,
}: {
  m: ChatMessage
  own: boolean
  showHeader: boolean
  confirming: boolean
  onAskDelete: () => void
  onCancelDelete: () => void
  onDelete: () => void
  deleting: boolean
}) {
  if (m.kind === 'system') {
    return (
      <p className="self-center px-2 text-center text-[11.5px] text-slate-500">
        {m.body} · {timeOf(m.created_at)}
      </p>
    )
  }

  const content = m.deleted ? (
    <p className="text-[13px] italic text-slate-500">wiadomość usunięta</p>
  ) : (
    <>
      {m.body ? <MessageText text={m.body} /> : null}
      {m.kind === 'link' && <LinkCard m={m} />}
      {m.kind === 'mail' && <MailCard m={m} />}
    </>
  )

  const deleteControls =
    own && !m.deleted ? (
      confirming ? (
        <span className="flex items-center justify-end gap-2 text-[11px]">
          <span className="text-slate-600">Usunąć tę wiadomość?</span>
          <button
            type="button"
            disabled={deleting}
            onClick={onDelete}
            className="rounded bg-red-600 px-2 py-0.5 font-medium text-white hover:bg-red-700 disabled:opacity-50"
          >
            Usuń
          </button>
          <button type="button" onClick={onCancelDelete} className="text-slate-600 hover:underline">
            Anuluj
          </button>
        </span>
      ) : (
        <button
          type="button"
          onClick={onAskDelete}
          className="text-[11px] text-slate-500 opacity-0 hover:text-red-700 hover:underline focus:opacity-100 group-hover:opacity-100"
        >
          Usuń
        </button>
      )
    ) : null

  if (own) {
    return (
      <div className="group grid max-w-[78%] gap-0.5 self-end">
        <div className="flex items-center justify-end gap-2 text-[11.5px] text-slate-500">
          {deleteControls}
          <span>{timeOf(m.created_at)}</span>
        </div>
        <div className="rounded-lg border border-sky-100 bg-sky-50 px-2.5 py-1.5 text-slate-900">{content}</div>
      </div>
    )
  }

  const name = m.user ? m.user.name : 'Konto usunięte'
  return (
    <div className={`grid max-w-[78%] grid-cols-[28px_1fr] gap-2 ${showHeader ? '' : '-mt-1.5'}`}>
      {showHeader ? <Avatar name={name} userId={m.user?.id ?? null} /> : <span />}
      <div className="min-w-0">
        {showHeader && (
          <div className="text-[11.5px] text-slate-500">
            <span className={`mr-1.5 font-semibold ${m.user ? 'text-slate-900' : 'italic text-slate-500'}`}>{name}</span>
            {timeOf(m.created_at)}
          </div>
        )}
        <div className="text-slate-900">{content}</div>
      </div>
    </div>
  )
}

// ——— okno wyboru osób (nowy kanał, dodawanie osób) ———

function PeopleModal({
  title,
  users,
  withName,
  submitLabel,
  onSubmit,
  onClose,
}: {
  title: string
  users: ChatUser[]
  withName: boolean
  submitLabel: string
  onSubmit: (name: string, ids: number[]) => Promise<void>
  onClose: () => void
}) {
  const [name, setName] = useState('')
  const [picked, setPicked] = useState<Set<number>>(new Set())
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose()
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [onClose])

  function toggle(id: number) {
    setPicked((prev) => {
      const next = new Set(prev)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })
  }

  const trimmed = name.trim()
  const canSubmit = picked.size > 0 && (!withName || (trimmed.length > 0 && trimmed.length <= 100))

  async function submit(e: FormEvent) {
    e.preventDefault()
    if (!canSubmit || busy) return
    setBusy(true)
    setErr('')
    try {
      await onSubmit(trimmed, [...picked])
    } catch (ex) {
      setErr(errorText(ex, 'Nie udało się zapisać.'))
      setBusy(false)
    }
  }

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
      role="dialog"
      aria-modal="true"
      aria-label={title}
      onMouseDown={(e) => {
        if (e.target === e.currentTarget) onClose()
      }}
    >
      <form onSubmit={(e) => void submit(e)} className="w-full max-w-sm space-y-3 rounded-xl bg-white p-4 text-sm shadow-xl">
        <h2 className="text-base font-semibold text-slate-900">{title}</h2>
        {withName && (
          <label className="block text-xs text-slate-700">
            Nazwa kanału
            <input
              autoFocus
              value={name}
              maxLength={100}
              onChange={(e) => setName(e.target.value)}
              placeholder="np. Przetargi"
              className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5 text-sm"
            />
          </label>
        )}
        <div>
          <p className="mb-1 text-xs text-slate-700">Osoby</p>
          {users.length === 0 ? (
            <p className="text-xs text-slate-500">Nie ma nikogo więcej do dodania.</p>
          ) : (
            <div className="max-h-64 space-y-0.5 overflow-y-auto rounded border border-slate-200 p-1">
              {users.map((u) => (
                <label key={u.id} className="flex cursor-pointer items-center gap-2 rounded px-1.5 py-1 hover:bg-slate-50">
                  <input type="checkbox" checked={picked.has(u.id)} onChange={() => toggle(u.id)} />
                  <Avatar name={u.name} userId={u.id} online={u.online} />
                  <span className="text-xs text-slate-800">{u.name}</span>
                </label>
              ))}
            </div>
          )}
        </div>
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
            disabled={!canSubmit || busy}
            className="rounded bg-sky-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-sky-700 disabled:opacity-50"
          >
            {busy ? 'Zapisuję…' : submitLabel}
          </button>
        </div>
      </form>
    </div>
  )
}

// ——— otwarta rozmowa ———

function Thread({
  conversation,
  me,
  users,
  live,
  onRead,
  onListChanged,
  onConversationUpdated,
  onLeft,
  onBack,
}: {
  conversation: ChatConversation
  me: number
  users: ChatUser[]
  live: boolean
  onRead: (conversationId: number, messageId: number, unreadTotal: number) => void
  onListChanged: () => void
  onConversationUpdated: (c: ChatConversation) => void
  onLeft: (conversationId: number) => void
  onBack: () => void
}) {
  const id = conversation.id
  const visible = useDocumentVisible()
  const [messages, setMessages] = useState<ChatMessage[]>([])
  const messagesRef = useRef<ChatMessage[]>([])
  const [pending, setPending] = useState<Pending[]>([])
  const [loaded, setLoaded] = useState(false)
  const loadedRef = useRef(false)
  const [loadErr, setLoadErr] = useState('')
  const [hasOlder, setHasOlder] = useState(false)
  const [loadingOlder, setLoadingOlder] = useState(false)
  const olderBusy = useRef(false)
  const newerBusy = useRef(false)
  const newerAgain = useRef(false)
  const [text, setText] = useState('')
  const [confirmDelete, setConfirmDelete] = useState<number | null>(null)
  const [deleting, setDeleting] = useState(false)
  const [actionErr, setActionErr] = useState('')
  const [addOpen, setAddOpen] = useState(false)
  const [confirmLeave, setConfirmLeave] = useState(false)
  const [leaving, setLeaving] = useState(false)
  const readUpTo = useRef(conversation.last_read_message_id ?? 0)

  const scroller = useRef<HTMLDivElement>(null)
  const stick = useRef(true)
  const restore = useRef<{ height: number; top: number } | null>(null)

  const commit = useCallback((next: ChatMessage[]) => {
    messagesRef.current = next
    setMessages(next)
  }, [])
  // Usunięcia, o których wiemy ze zdarzenia — także gdy zdarzenie wyprzedziło odpowiedź z tą wiadomością.
  const deletedIds = useRef(new Set<number>())

  const applyIncoming = useCallback(
    (list: ChatMessage[]) => {
      if (list.length === 0) return
      const known = deletedIds.current
      const incoming = known.size === 0
        ? list
        : list.map((m) => (known.has(m.id) && !m.deleted ? { ...m, deleted: true, body: null, meta: null } : m))
      commit(mergeMessages(messagesRef.current, incoming))
      const uuids = new Set(list.map((m) => m.client_uuid).filter(Boolean))
      if (uuids.size > 0) setPending((prev) => prev.filter((p) => !uuids.has(p.client_uuid)))
    },
    [commit],
  )

  const loadInitial = useCallback(async () => {
    setLoadErr('')
    try {
      const r = await fetchMessages(id, { limit: PAGE })
      stick.current = true
      applyIncoming(r.data)
      setHasOlder(r.has_more)
      loadedRef.current = true
      setLoaded(true)
    } catch (ex) {
      setLoadErr(errorText(ex, 'Nie udało się wczytać wiadomości.'))
    }
  }, [id, applyIncoming])

  /** Dociąga wszystko po ostatniej znanej wiadomości (kolejne wywołania w trakcie — jeszcze jeden obieg). */
  const loadNewer = useCallback(async () => {
    if (!loadedRef.current) return
    if (newerBusy.current) {
      newerAgain.current = true
      return
    }
    newerBusy.current = true
    try {
      do {
        newerAgain.current = false
        for (let guard = 0; guard < 20; guard++) {
          const list = messagesRef.current
          const lastId = list.length > 0 ? list[list.length - 1].id : 0
          const r = lastId
            ? await fetchMessages(id, { after_id: lastId, limit: 100 })
            : await fetchMessages(id, { limit: PAGE })
          applyIncoming(r.data)
          if (!lastId || !r.has_more || r.data.length === 0) break
        }
      } while (newerAgain.current)
    } catch {
      /* następna próba przy kolejnym sygnale albo odpytaniu */
    } finally {
      newerBusy.current = false
    }
  }, [id, applyIncoming])

  async function loadOlder() {
    const first = messagesRef.current[0]
    if (olderBusy.current || !hasOlder || !first) return
    olderBusy.current = true
    setLoadingOlder(true)
    try {
      const r = await fetchMessages(id, { before_id: first.id, limit: PAGE })
      const el = scroller.current
      if (el) restore.current = { height: el.scrollHeight, top: el.scrollTop }
      applyIncoming(r.data)
      setHasOlder(r.has_more)
    } catch (ex) {
      setActionErr(errorText(ex, 'Nie udało się wczytać starszych wiadomości.'))
    } finally {
      olderBusy.current = false
      setLoadingOlder(false)
    }
  }

  useEffect(() => {
    void loadInitial()
  }, [loadInitial])

  // Sygnały z serwera: nowa wiadomość w tej rozmowie albo ponowne połączenie.
  useEffect(() => {
    const offs = [
      onRealtime('chat.message', (e) => {
        if (e.conversation_id === id) void loadNewer()
      }),
      // autor usunął wiadomość — pokazujemy „wiadomość usunięta” bez ponownego wczytywania rozmowy
      onRealtime('chat.deleted', (e) => {
        if (e.conversation_id !== id) return
        deletedIds.current.add(e.message_id)
        const prev = messagesRef.current
        if (!prev.some((m) => m.id === e.message_id && !m.deleted)) return
        commit(prev.map((m) => (m.id === e.message_id ? { ...m, deleted: true, body: null, meta: null } : m)))
      }),
      onRealtime('connected', () => void loadNewer()),
    ]
    return () => offs.forEach((off) => off())
  }, [id, loadNewer, commit])

  /** Ostatnia strona od nowa — serwer zwraca też usunięte („wiadomość usunięta”), merge podmienia je po id. */
  const reconcileLatest = useCallback(async () => {
    if (!loadedRef.current) return
    try {
      applyIncoming((await fetchMessages(id, { limit: PAGE })).data)
    } catch {
      /* następna próba przy kolejnym obiegu */
    }
  }, [id, applyIncoming])

  // Bez połączenia w czasie rzeczywistym: co 10 s (co szósty obieg cała ostatnia strona), tylko przy widocznej karcie.
  // Przy połączeniu — zapasowo co 2 min, na wypadek zdarzenia, które nie doszło.
  useEffect(() => {
    if (!visible) return
    if (!live) void loadNewer()
    let tick = 0
    const timer = window.setInterval(
      () => {
        if (document.visibilityState !== 'visible') return
        tick++
        void loadNewer()
        if (live || tick % THREAD_RECONCILE_EVERY === 0) void reconcileLatest()
      },
      live ? THREAD_LIVE_BACKUP_MS : THREAD_POLL_MS,
    )
    return () => window.clearInterval(timer)
  }, [live, visible, loadNewer, reconcileLatest])

  // Przewijanie: po doczytaniu starszych zostajemy w tym samym miejscu, przy nowych — na dole, jeśli tam byliśmy.
  useLayoutEffect(() => {
    const el = scroller.current
    if (!el) return
    if (restore.current) {
      el.scrollTop = el.scrollHeight - restore.current.height + restore.current.top
      restore.current = null
      return
    }
    if (stick.current) el.scrollTop = el.scrollHeight
  }, [messages, pending, loaded])

  function onScroll() {
    const el = scroller.current
    if (!el) return
    stick.current = el.scrollHeight - el.scrollTop - el.clientHeight < 80
    if (el.scrollTop < 60) void loadOlder()
  }

  // Przeczytane: gdy rozmowa jest widoczna i doszła cudza wiadomość nowsza niż znacznik.
  useEffect(() => {
    if (!visible || messages.length === 0) return
    const lastId = messages[messages.length - 1].id
    const fresh = messages.some((m) => m.id > readUpTo.current && m.user?.id !== me && !m.deleted)
    if (!fresh) return
    const before = readUpTo.current
    readUpTo.current = lastId
    markRead(id, lastId).then(
      (r) => onRead(id, lastId, r.unread_total),
      () => {
        readUpTo.current = before
      },
    )
  }, [messages, visible, id, me, onRead])

  async function deliver(p: Pending) {
    try {
      const r = await sendMessage(id, { client_uuid: p.client_uuid, body: p.body })
      applyIncoming([r.data])
      setPending((prev) => prev.filter((x) => x.client_uuid !== p.client_uuid))
      onListChanged()
    } catch (ex) {
      setPending((prev) =>
        prev.map((x) =>
          x.client_uuid === p.client_uuid ? { ...x, status: 'failed', error: errorText(ex, 'Brak połączenia z serwerem.') } : x,
        ),
      )
    }
  }

  function send() {
    const body = text.trim()
    if (!body || body.length > CHAT_MAX_LENGTH) return
    const p: Pending = { client_uuid: newClientUuid(), body, status: 'sending' }
    stick.current = true
    setPending((prev) => [...prev, p])
    setText('')
    void deliver(p)
  }

  function retry(p: Pending) {
    // Ten sam identyfikator: jeśli pierwsza próba jednak doszła, serwer zwróci tę samą wiadomość zamiast dubla.
    const again: Pending = { ...p, status: 'sending', error: undefined }
    setPending((prev) => prev.map((x) => (x.client_uuid === p.client_uuid ? again : x)))
    void deliver(again)
  }

  function discard(p: Pending) {
    setPending((prev) => prev.filter((x) => x.client_uuid !== p.client_uuid))
    setText((t) => (t ? t : p.body))
  }

  async function removeMessage(messageId: number) {
    setDeleting(true)
    setActionErr('')
    try {
      const r = await deleteMessage(messageId)
      applyIncoming([r.data])
      setConfirmDelete(null)
      onListChanged()
    } catch (ex) {
      setActionErr(errorText(ex, 'Nie udało się usunąć wiadomości.'))
    } finally {
      setDeleting(false)
    }
  }

  async function leave() {
    setLeaving(true)
    setActionErr('')
    try {
      await leaveConversation(id)
      onLeft(id)
    } catch (ex) {
      setActionErr(errorText(ex, 'Nie udało się wyjść z kanału.'))
      setLeaving(false)
    }
  }

  function onKeyDown(e: ReactKeyboardEvent<HTMLTextAreaElement>) {
    if (e.key === 'Enter' && !e.shiftKey && !e.nativeEvent.isComposing) {
      e.preventDefault()
      send()
    }
  }

  const isChannel = conversation.type === 'channel'
  const other = conversation.other_user
  const otherOnline = other ? (users.find((u) => u.id === other.id)?.online ?? other.online) : null
  const participantIds = new Set(conversation.participants.map((p) => p.id))
  const canManage = isChannel && !conversation.everyone
  const candidates = users.filter((u) => !u.is_me && !participantIds.has(u.id))
  const length = text.length

  return (
    <div className="grid min-h-0 min-w-0 grid-rows-[auto_1fr_auto]">
      <div className="border-b border-slate-200 px-3.5 py-2">
        <div className="flex items-center gap-2.5">
          <button
            type="button"
            onClick={onBack}
            className="rounded px-1.5 py-0.5 text-xs text-sky-700 hover:bg-slate-100 md:hidden"
          >
            ← Rozmowy
          </button>
          {isChannel ? (
            <Avatar name={conversation.name} channel />
          ) : (
            <Avatar name={conversation.name} userId={other?.id ?? null} online={otherOnline} />
          )}
          <div className="min-w-0 flex-1">
            <p className="truncate text-[13.5px] font-semibold text-slate-900">
              {other || isChannel ? conversation.name : 'Konto usunięte'}
            </p>
            {isChannel ? (
              <p
                className="truncate text-[11.5px] text-slate-500"
                title={conversation.participants.map((p) => p.name).join(', ')}
              >
                {conversation.everyone
                  ? 'Wszyscy pracownicy z dostępem do czatu'
                  : `Osoby (${conversation.participants.length}): ${conversation.participants.map((p) => p.name).join(', ')}`}
              </p>
            ) : other ? (
              <p className={`text-[11.5px] ${otherOnline ? 'text-green-700' : 'text-slate-500'}`}>
                {otherOnline ? 'w pracy' : 'niedostępny'}
              </p>
            ) : null}
          </div>
          {canManage && !confirmLeave && (
            <>
              <button
                type="button"
                onClick={() => setAddOpen(true)}
                className="rounded border border-slate-300 bg-white px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50"
              >
                Dodaj osoby
              </button>
              <button
                type="button"
                onClick={() => setConfirmLeave(true)}
                className="rounded border border-slate-300 bg-white px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50"
              >
                Wyjdź z kanału
              </button>
            </>
          )}
          {canManage && confirmLeave && (
            <span className="flex items-center gap-2 text-xs">
              <span className="text-slate-700">Wyjść z kanału? Wiadomości przestaną do Ciebie przychodzić.</span>
              <button
                type="button"
                disabled={leaving}
                onClick={() => void leave()}
                className="rounded bg-red-600 px-2.5 py-1 font-medium text-white hover:bg-red-700 disabled:opacity-50"
              >
                Wyjdź
              </button>
              <button type="button" onClick={() => setConfirmLeave(false)} className="text-slate-600 hover:underline">
                Anuluj
              </button>
            </span>
          )}
        </div>
        {actionErr && <p className="mt-1.5 rounded bg-red-50 px-2 py-1 text-xs text-red-700">{actionErr}</p>}
      </div>

      <div
        ref={scroller}
        onScroll={onScroll}
        className="flex min-h-0 flex-col gap-2.5 overflow-y-auto bg-white px-4 py-2.5"
        role="log"
        aria-label="Wiadomości"
      >
        {loaded && (
          <div className="flex h-6 shrink-0 items-center justify-center text-[11px] text-slate-500">
            {hasOlder ? (
              <button
                type="button"
                disabled={loadingOlder}
                onClick={() => void loadOlder()}
                className="text-sky-700 hover:underline disabled:opacity-50"
              >
                {loadingOlder ? 'Wczytuję starsze…' : 'Wcześniejsze wiadomości'}
              </button>
            ) : (
              <span>Początek rozmowy</span>
            )}
          </div>
        )}
        {!loaded && !loadErr && <p className="text-xs text-slate-500">Ładowanie…</p>}
        {loadErr && (
          <p className="rounded bg-red-50 px-2 py-1.5 text-xs text-red-700">
            {loadErr}{' '}
            <button type="button" onClick={() => void loadInitial()} className="font-medium underline">
              Spróbuj ponownie
            </button>
          </p>
        )}
        {loaded && messages.length === 0 && pending.length === 0 && (
          <p className="self-center text-xs text-slate-500">Nie ma jeszcze wiadomości. Napisz pierwszą.</p>
        )}
        {messages.map((m, i) => {
          const prev = i > 0 ? messages[i - 1] : null
          const newDay = !prev || !sameDay(prev.created_at, m.created_at)
          const showHeader =
            newDay ||
            !prev ||
            prev.kind === 'system' ||
            (prev.user?.id ?? null) !== (m.user?.id ?? null) ||
            new Date(m.created_at).getTime() - new Date(prev.created_at).getTime() > GROUP_MS
          return (
            <div key={m.id} className="contents">
              {newDay && (
                <span className="self-center rounded-full bg-slate-100 px-2.5 text-[11px] text-slate-500">
                  {dayLabel(m.created_at)}
                </span>
              )}
              <MessageItem
                m={m}
                own={m.user?.id === me}
                showHeader={showHeader}
                confirming={confirmDelete === m.id}
                onAskDelete={() => setConfirmDelete(m.id)}
                onCancelDelete={() => setConfirmDelete(null)}
                onDelete={() => void removeMessage(m.id)}
                deleting={deleting}
              />
            </div>
          )
        })}
        {pending.map((p) => (
          <div key={p.client_uuid} className="grid max-w-[78%] gap-0.5 self-end">
            <div className="text-right text-[11.5px] text-slate-500">
              {p.status === 'sending' ? 'Wysyłam…' : <span className="text-red-700">Nie wysłano</span>}
            </div>
            <div
              className={`rounded-lg border px-2.5 py-1.5 text-slate-900 ${
                p.status === 'failed' ? 'border-red-200 bg-red-50' : 'border-sky-100 bg-sky-50 opacity-70'
              }`}
            >
              <MessageText text={p.body} />
            </div>
            {p.status === 'failed' && (
              <div className="flex items-center justify-end gap-2 text-[11px]">
                {p.error && <span className="text-red-700">{p.error}</span>}
                <button type="button" onClick={() => retry(p)} className="font-medium text-sky-700 hover:underline">
                  Wyślij ponownie
                </button>
                <button type="button" onClick={() => discard(p)} className="text-slate-600 hover:underline">
                  Nie wysyłaj
                </button>
              </div>
            )}
          </div>
        ))}
      </div>

      <form
        onSubmit={(e) => {
          e.preventDefault()
          send()
        }}
        className="grid grid-cols-[1fr_auto] items-end gap-2 border-t border-slate-200 px-3 py-2"
      >
        <textarea
          value={text}
          onChange={(e) => setText(e.target.value)}
          onKeyDown={onKeyDown}
          maxLength={CHAT_MAX_LENGTH}
          rows={Math.min(6, Math.max(1, text.split('\n').length))}
          placeholder="Napisz wiadomość…"
          aria-label="Treść wiadomości"
          className="max-h-40 min-h-[34px] w-full resize-none rounded border border-slate-300 px-2 py-1.5 text-[13px]"
        />
        <button
          type="submit"
          disabled={!text.trim()}
          className="rounded bg-sky-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-sky-700 disabled:opacity-50"
        >
          Wyślij
        </button>
        <small className="col-span-2 flex justify-between gap-2 text-[10.5px] text-slate-500">
          <span>Enter wysyła, Shift+Enter to nowa linia</span>
          {length > CHAT_COUNTER_FROM && (
            <span className={length >= CHAT_MAX_LENGTH ? 'font-semibold text-red-700' : 'text-amber-700'}>
              {length} / {CHAT_MAX_LENGTH} znaków
            </span>
          )}
        </small>
      </form>

      {addOpen && (
        <PeopleModal
          title={`Dodaj osoby do kanału „${conversation.name}”`}
          users={candidates}
          withName={false}
          submitLabel="Dodaj"
          onClose={() => setAddOpen(false)}
          onSubmit={async (_name, ids) => {
            const r = await addParticipants(id, ids)
            onConversationUpdated(r.data)
            setAddOpen(false)
            onListChanged()
          }}
        />
      )}
    </div>
  )
}

// ——— lista rozmów ———

function ConversationRow({
  active,
  title,
  preview,
  time,
  unread,
  avatar,
  onClick,
}: {
  active: boolean
  title: ReactNode
  preview: string
  time: string
  unread: number
  avatar: ReactNode
  onClick: () => void
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-current={active ? 'true' : undefined}
      className={`grid w-full grid-cols-[28px_1fr_auto] items-center gap-2 border-l-[3px] px-3 py-1.5 text-left ${
        active ? 'border-l-sky-600 bg-sky-50' : 'border-l-transparent hover:bg-slate-50'
      }`}
    >
      {avatar}
      <span className="min-w-0">
        <span className="block truncate text-[12.5px] font-semibold text-slate-900">{title}</span>
        {preview && <span className="block truncate text-[11.5px] text-slate-500">{preview}</span>}
      </span>
      <span className="flex flex-col items-end gap-0.5">
        {time && <span className="text-[10.5px] text-slate-400">{time}</span>}
        <CountBadge n={unread} />
      </span>
    </button>
  )
}

function previewFor(c: ChatConversation, me: number): string {
  const m = c.last_message
  if (!m) return ''
  const text = messagePreview(m)
  if (m.kind === 'system') return text
  if (m.user?.id === me) return `Ty: ${text}`
  if (c.type === 'channel') {
    const first = m.user ? m.user.name.split(/\s+/)[0] : 'Konto usunięte'
    return `${first}: ${text}`
  }
  return text
}

export function Chat() {
  const { user } = useAuth()
  const me = user?.id ?? 0
  const [params, setParams] = useSearchParams()
  const { setUnreadTotal, unreadTotal } = useChatUnread()
  const live = useRealtimeStatus() === 'connected'
  const visible = useDocumentVisible()

  const [users, setUsers] = useState<ChatUser[]>([])
  const [conversations, setConversations] = useState<ChatConversation[] | null>(null)
  const [listErr, setListErr] = useState('')
  const [pageErr, setPageErr] = useState('')
  const [search, setSearch] = useState('')
  const [newChannelOpen, setNewChannelOpen] = useState(false)

  const activeId = Number(params.get('c')) || null
  const directUser = Number(params.get('u')) || null

  const listBusy = useRef(false)
  const listAgain = useRef(false)

  const loadList = useCallback(async () => {
    if (listBusy.current) {
      listAgain.current = true
      return
    }
    listBusy.current = true
    try {
      do {
        listAgain.current = false
        const r = await fetchConversations()
        setConversations(r.data)
        setUnreadTotal(r.unread_total)
        setListErr('')
      } while (listAgain.current)
    } catch (ex) {
      setListErr(errorText(ex, 'Nie udało się wczytać rozmów.'))
    } finally {
      listBusy.current = false
    }
  }, [setUnreadTotal])

  const loadUsers = useCallback(async () => {
    try {
      setUsers((await fetchChatUsers()).data)
    } catch {
      /* lista osób zostaje poprzednia */
    }
  }, [])

  useEffect(() => {
    void loadList()
    void loadUsers()
  }, [loadList, loadUsers])

  // Obecność osób (zielona kropka) — co minutę przy widocznej karcie.
  useEffect(() => {
    if (!visible) return
    const timer = window.setInterval(() => void loadUsers(), USERS_POLL_MS)
    return () => window.clearInterval(timer)
  }, [visible, loadUsers])

  useEffect(() => {
    const offs = [
      onRealtime('chat.message', () => void loadList()),
      onRealtime('chat.conversation', () => void loadList()),
      onRealtime('chat.deleted', () => void loadList()),
      onRealtime('connected', () => {
        void loadList()
        void loadUsers()
      }),
    ]
    return () => offs.forEach((off) => off())
  }, [loadList, loadUsers])

  // Licznik w menu się zmienił (odpytywanie albo zapasowe sprawdzenie przy połączeniu na żywo) — odświeżamy listę.
  useEffect(() => {
    if (unreadTotal !== null) void loadList()
  }, [unreadTotal, loadList])

  const upsert = useCallback((c: ChatConversation) => {
    setConversations((prev) => {
      if (!prev) return [c]
      return prev.some((x) => x.id === c.id) ? prev.map((x) => (x.id === c.id ? c : x)) : [...prev, c]
    })
  }, [])

  // ?u= — rozmowa 1:1 z tą osobą (istniejąca albo nowa), potem adres ?c=.
  const handledUser = useRef<number | null>(null)
  useEffect(() => {
    if (!directUser || handledUser.current === directUser) return
    handledUser.current = directUser
    if (directUser === me) {
      setParams({}, { replace: true })
      return
    }
    setPageErr('')
    openDirect(directUser).then(
      (r) => {
        upsert(r.data)
        setParams({ c: String(r.data.id) }, { replace: true })
        handledUser.current = null
      },
      (ex: unknown) => {
        setPageErr(errorText(ex, 'Nie udało się otworzyć rozmowy z tą osobą.'))
        setParams({}, { replace: true })
        handledUser.current = null
      },
    )
  }, [directUser, me, setParams, upsert])

  // ?c= spoza listy (np. świeżo założona albo lista jeszcze stara) — dociągamy tę jedną rozmowę.
  const fetchingConv = useRef<number | null>(null)
  // Kanały opuszczone na tym ekranie: adres ?c= znika chwilę po liście — nie dociągamy ich ponownie.
  const leftIds = useRef(new Set<number>())
  useEffect(() => {
    if (!activeId || !conversations || conversations.some((c) => c.id === activeId)) return
    if (fetchingConv.current === activeId || leftIds.current.has(activeId)) return
    fetchingConv.current = activeId
    fetchConversation(activeId).then(
      (r) => {
        upsert(r.data)
        fetchingConv.current = null
      },
      (ex: unknown) => {
        fetchingConv.current = null
        setPageErr(
          ex instanceof ApiError && ex.status === 404
            ? 'Tej rozmowy nie ma albo nie należysz do niej.'
            : errorText(ex, 'Nie udało się otworzyć rozmowy.'),
        )
        setParams({}, { replace: true })
      },
    )
  }, [activeId, conversations, setParams, upsert])

  const active = activeId && conversations ? (conversations.find((c) => c.id === activeId) ?? null) : null

  const onRead = useCallback(
    (conversationId: number, messageId: number, total: number) => {
      setUnreadTotal(total)
      setConversations((prev) =>
        prev
          ? prev.map((c) =>
              c.id === conversationId
                ? { ...c, unread: 0, last_read_message_id: Math.max(c.last_read_message_id ?? 0, messageId) }
                : c,
            )
          : prev,
      )
    },
    [setUnreadTotal],
  )

  const onListChanged = useCallback(() => void loadList(), [loadList])

  const onLeft = useCallback(
    (conversationId: number) => {
      leftIds.current.add(conversationId)
      setParams({}, { replace: true })
      setConversations((prev) => (prev ? prev.filter((c) => c.id !== conversationId) : prev))
      void loadList()
    },
    [setParams, loadList],
  )

  function openConversation(id: number) {
    setPageErr('')
    setParams({ c: String(id) })
  }

  // ——— lista: kanały, potem osoby (z rozmową — wg ostatniej wiadomości, reszta alfabetycznie) ———
  const q = foldText(search.trim())
  const match = (name: string) => !q || foldText(name).includes(q)

  const { channels, people } = useMemo(() => {
    const list = conversations ?? []
    const chans = list.filter((c) => c.type === 'channel')
    const directs = list.filter((c) => c.type === 'direct')
    const byUser = new Map<number, ChatConversation>()
    for (const c of directs) if (c.other_user) byUser.set(c.other_user.id, c)
    type Row = { key: string; user: ChatUser | null; conv: ChatConversation | null; name: string }
    const withMessages: Row[] = directs
      .filter((c) => c.last_message)
      .map((c) => ({
        key: `c${c.id}`,
        user: c.other_user ? (users.find((u) => u.id === c.other_user?.id) ?? c.other_user) : null,
        conv: c,
        name: c.other_user ? c.name : 'Konto usunięte',
      }))
    const shown = new Set(withMessages.map((r) => r.user?.id).filter((x): x is number => typeof x === 'number'))
    const rest: Row[] = users
      .filter((u) => !u.is_me && !shown.has(u.id))
      .map((u) => ({ key: `u${u.id}`, user: u, conv: byUser.get(u.id) ?? null, name: u.name }))
      .sort((a, b) => a.name.localeCompare(b.name, 'pl'))
    return { channels: chans, people: [...withMessages, ...rest] }
  }, [conversations, users])

  const shownChannels = channels.filter((c) => match(c.name))
  const shownPeople = people.filter((r) => match(r.name))

  return (
    <div className="app-chat flex h-[calc(100vh-2.5rem)] min-h-[480px] flex-col">
      <h1 className="mb-3 text-xl font-semibold">Czat</h1>
      {pageErr && <p className="mb-2 rounded bg-red-50 px-3 py-2 text-xs text-red-700">{pageErr}</p>}
      <div className="grid min-h-0 flex-1 overflow-hidden rounded-md border border-slate-200 bg-white md:grid-cols-[250px_1fr]">
        <div className={`min-h-0 min-w-0 flex-col border-r border-slate-200 ${active ? 'hidden md:flex' : 'flex'}`}>
          <input
            type="search"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Szukaj osoby lub rozmowy"
            aria-label="Szukaj osoby lub rozmowy"
            className="m-2.5 rounded border border-slate-300 px-2 py-1 text-xs"
          />
          <div className="min-h-0 flex-1 overflow-y-auto pb-2">
            {listErr && (
              <p className="mx-2.5 mb-2 rounded bg-red-50 px-2 py-1.5 text-xs text-red-700">
                {listErr}{' '}
                <button type="button" onClick={() => void loadList()} className="font-medium underline">
                  Spróbuj ponownie
                </button>
              </p>
            )}
            {conversations === null && !listErr && <p className="px-3 text-xs text-slate-500">Ładowanie…</p>}
            <div className="flex items-center justify-between px-3 pb-1 pt-2">
              <span className="text-[10.5px] uppercase tracking-wide text-slate-500">Kanały</span>
              <button
                type="button"
                onClick={() => setNewChannelOpen(true)}
                className="text-[11px] font-medium text-sky-700 hover:underline"
              >
                + Nowy kanał
              </button>
            </div>
            {shownChannels.map((c) => (
              <ConversationRow
                key={c.id}
                active={c.id === activeId}
                title={c.name}
                preview={previewFor(c, me)}
                time={c.last_message ? listTime(c.last_message.created_at) : ''}
                unread={c.unread}
                avatar={<Avatar name={c.name} channel />}
                onClick={() => openConversation(c.id)}
              />
            ))}
            {conversations !== null && shownChannels.length === 0 && (
              <p className="px-3 py-1 text-[11.5px] text-slate-500">{q ? 'Brak pasujących kanałów.' : 'Brak kanałów.'}</p>
            )}
            <div className="px-3 pb-1 pt-3 text-[10.5px] uppercase tracking-wide text-slate-500">Osoby</div>
            {shownPeople.map((r) => (
              <ConversationRow
                key={r.key}
                active={r.conv !== null && r.conv.id === activeId}
                title={r.user ? r.name : <span className="italic text-slate-500">Konto usunięte</span>}
                preview={r.conv ? previewFor(r.conv, me) : ''}
                time={r.conv?.last_message ? listTime(r.conv.last_message.created_at) : ''}
                unread={r.conv?.unread ?? 0}
                avatar={<Avatar name={r.name} userId={r.user?.id ?? null} online={r.user ? r.user.online : null} />}
                onClick={() => {
                  if (r.conv) openConversation(r.conv.id)
                  else if (r.user) {
                    setPageErr('')
                    setParams({ u: String(r.user.id) })
                  }
                }}
              />
            ))}
            {users.length > 0 && shownPeople.length === 0 && (
              <p className="px-3 py-1 text-[11.5px] text-slate-500">{q ? 'Nikogo takiego nie ma.' : 'Brak innych osób.'}</p>
            )}
          </div>
        </div>

        {active ? (
          <Thread
            key={active.id}
            conversation={active}
            me={me}
            users={users}
            live={live}
            onRead={onRead}
            onListChanged={onListChanged}
            onConversationUpdated={upsert}
            onLeft={onLeft}
            onBack={() => setParams({})}
          />
        ) : (
          <div className="hidden min-h-0 items-center justify-center p-6 text-center text-sm text-slate-500 md:flex">
            {activeId || directUser ? 'Otwieram rozmowę…' : 'Wybierz rozmowę z listy albo osobę, do której chcesz napisać.'}
          </div>
        )}
      </div>

      {newChannelOpen && (
        <PeopleModal
          title="Nowy kanał"
          users={users.filter((u) => !u.is_me)}
          withName
          submitLabel="Załóż kanał"
          onClose={() => setNewChannelOpen(false)}
          onSubmit={async (name, ids) => {
            const r = await createChannel(name, ids)
            upsert(r.data)
            setNewChannelOpen(false)
            openConversation(r.data.id)
            void loadList()
          }}
        />
      )}
    </div>
  )
}
