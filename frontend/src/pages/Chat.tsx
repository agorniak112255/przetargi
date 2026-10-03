import {
  createContext,
  useCallback,
  useContext,
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
import { publicDir } from '../lib/publicDir'
import {
  callDurationLabel,
  callKindLabel,
  callPageUrl,
  declineCall,
  fetchCall,
  fetchCallsConfig,
  isCallStatusNewerOrSame,
  leaveCall,
  startCall,
  type CallKind,
  type ChatCallMeta,
} from '../lib/calls'
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
import { plural } from '../lib/plural'
import { onRealtime, useRealtimeStatus, type ChatCallUpdatedEvent } from '../lib/realtime'

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
/** Pole pisania rośnie do ok. 5 linii (5 × 20 px + odstępy), dalej przewija się w środku. */
const COMPOSER_MAX_PX = 124

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

type IconName =
  | 'search'
  | 'plus'
  | 'phone'
  | 'video'
  | 'user-plus'
  | 'leave'
  | 'send'
  | 'back'
  | 'chat'
  | 'document'
  | 'mail'
  | 'trash'

const ICONS: Record<IconName, ReactNode> = {
  search: (
    <>
      <circle cx="11" cy="11" r="6.5" />
      <path d="M20 20l-4.2-4.2" />
    </>
  ),
  plus: <path d="M12 5v14M5 12h14" />,
  phone: (
    <path d="M5.5 4h3l1.6 4.2-2.1 1.3a11 11 0 0 0 6.5 6.5l1.3-2.1 4.2 1.6v3a1.6 1.6 0 0 1-1.7 1.6A16 16 0 0 1 3.9 5.7 1.6 1.6 0 0 1 5.5 4z" />
  ),
  video: (
    <>
      <rect x="3" y="6.5" width="12" height="11" rx="2.5" />
      <path d="M15 10.5l6-3.5v10l-6-3.5" />
    </>
  ),
  'user-plus': (
    <>
      <circle cx="9" cy="8" r="3.5" />
      <path d="M3 20a6 6 0 0 1 12 0M19 8v6M16 11h6" />
    </>
  ),
  leave: (
    <>
      <path d="M10 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h4" />
      <path d="M15 16l4-4-4-4M19 12H9" />
    </>
  ),
  send: <path d="M21 3L3 10.5l7.2 3.3L13.5 21zM21 3l-10.8 10.8" />,
  back: <path d="M15 18l-6-6 6-6" />,
  chat: <path d="M4 5h16v11H9l-5 4zM8 9.5h8M8 12.5h5" />,
  document: (
    <>
      <path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z" />
      <path d="M14 3v5h5M9 13h6M9 17h4" />
    </>
  ),
  mail: (
    <>
      <rect x="3" y="5" width="18" height="14" rx="2" />
      <path d="M3.5 6.5l8.5 6.5 8.5-6.5" />
    </>
  ),
  trash: <path d="M4 7h16M9 7V4.5h6V7M6.5 7l1 13h9l1-13M10 11v5M14 11v5" />,
}

function Icon({ name, className = 'h-4 w-4' }: { name: IconName; className?: string }) {
  return (
    <svg
      className={className}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.8"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
    >
      {ICONS[name]}
    </svg>
  )
}

type AvatarSize = 'sm' | 'md' | 'lg'

const AVATAR_SIZE: Record<AvatarSize, string> = {
  sm: 'h-8 w-8 text-[11px]',
  md: 'h-10 w-10 text-[13px]',
  lg: 'h-11 w-11 text-sm',
}

const CHANNEL_SIZE: Record<AvatarSize, string> = {
  sm: 'h-8 w-8 rounded-lg text-sm',
  md: 'h-10 w-10 rounded-xl text-base',
  lg: 'h-11 w-11 rounded-xl text-lg',
}

const DOT_SIZE: Record<AvatarSize, string> = {
  sm: 'h-2.5 w-2.5',
  md: 'h-3 w-3',
  lg: 'h-3.5 w-3.5',
}

/** Kółko z inicjałami; zielony znacznik tylko dla osób dostępnych (obramowanie w kolorze karty, także nocą). */
function Avatar({
  name,
  userId,
  online,
  channel,
  size = 'md',
}: {
  name: string
  userId?: number | null
  online?: boolean | null
  channel?: boolean
  size?: AvatarSize
}) {
  if (channel) {
    return (
      <span
        className={`grid shrink-0 place-items-center bg-slate-100 font-semibold text-slate-500 ${CHANNEL_SIZE[size]}`}
        aria-hidden="true"
      >
        #
      </span>
    )
  }
  return (
    <span
      className={`relative grid shrink-0 place-items-center rounded-full font-semibold ${AVATAR_SIZE[size]} ${avatarColor(userId)}`}
      aria-hidden="true"
    >
      {userId ? initials(name) : '?'}
      {online ? (
        <span
          className={`absolute -bottom-0.5 -right-0.5 rounded-full bg-green-500 ring-2 ring-[color:var(--t-surface,#fff)] ${DOT_SIZE[size]}`}
        />
      ) : null}
    </span>
  )
}

function CountBadge({ n }: { n: number }) {
  if (n <= 0) return null
  return (
    <span className="min-w-[20px] shrink-0 rounded-full bg-sky-600 px-1.5 text-center text-[11px] font-semibold leading-5 text-white">
      {unreadLabel(n)}
    </span>
  )
}

/** Imię do kafelka „Dostępni teraz” — pierwszy wyraz, pełne imię i nazwisko w podpowiedzi. */
function firstName(name: string): string {
  return name.trim().split(/\s+/)[0] || name
}

const URL_RE = /https?:\/\/[^\s<>"']+/g
const TRAILING = /[.,;:!?)\]}»”’]+$/

/** Tekst wiadomości jako tekst; adresy http/https jako odnośniki w nowej karcie. */
function MessageText({ text, own = false }: { text: string; own?: boolean }) {
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
      <a
        key={start}
        href={url}
        target="_blank"
        rel="noopener noreferrer"
        className={`break-all underline underline-offset-2 ${own ? 'font-medium text-white' : 'text-sky-700'}`}
      >
        {url}
      </a>,
    )
    last = start + url.length
  }
  if (last < text.length) parts.push(text.slice(last))
  return <div className="whitespace-pre-wrap break-words text-[13px] leading-[1.45]">{parts}</div>
}

/** Kafelek wewnątrz dymka (link, mail, rozmowa) — biały, z ikoną rodzaju po lewej. */
function CardTile({
  icon,
  iconClass,
  label,
  spaced,
  children,
}: {
  icon: IconName
  iconClass: string
  label: string
  spaced: boolean
  children: ReactNode
}) {
  return (
    <div
      className={`flex gap-2.5 rounded-xl bg-white sm:min-w-[220px] p-2.5 text-xs text-slate-700 ring-1 ring-slate-200/70 ${spaced ? 'mt-2' : ''}`}
    >
      <span className={`grid h-8 w-8 shrink-0 place-items-center rounded-lg ${iconClass}`}>
        <Icon name={icon} />
      </span>
      <div className="grid min-w-0 flex-1 content-start gap-0.5">
        <span className="text-[10.5px] font-medium uppercase tracking-wide text-slate-500">{label}</span>
        {children}
      </div>
    </div>
  )
}

/**
 * Małe okno czatu z Thunderbirda (/czat-okno): odnośniki do zapytań i przetargów otwieramy w nowym oknie — dodatek
 * przenosi je do zwykłej przeglądarki (w okienku nie ma miejsca na aplikację, a Thunderbird nie trzyma logowania).
 */
const CompactChatContext = createContext(false)

function LinkCard({ m }: { m: ChatMessage }) {
  const navigate = useNavigate()
  const compact = useContext(CompactChatContext)
  const link = m.meta?.link
  if (!link) return null
  const path = safeAppPath(link.path)
  const kindLabel = link.type === 'inquiry' ? 'Zapytanie' : 'Przetarg'
  const openLabel = link.item ? `Otwórz pozycję ${link.item}` : link.type === 'inquiry' ? 'Otwórz zapytanie' : 'Otwórz przetarg'
  return (
    <CardTile icon="document" iconClass="bg-sky-50 text-sky-700" label={kindLabel} spaced={Boolean(m.body)}>
      <span className="font-semibold text-slate-800">{link.title}</span>
      {link.item ? <span className="text-slate-600">Pozycja {link.item}</span> : null}
      {path ? (
        <button
          type="button"
          onClick={() => {
            if (compact) window.open(new URL(`${publicDir()}${path}`, window.location.origin).href, '_blank')
            else navigate(path)
          }}
          className="mt-0.5 justify-self-start font-medium text-sky-700 hover:underline"
        >
          {openLabel} →
        </button>
      ) : (
        <span className="text-slate-500">Odnośnik niedostępny</span>
      )}
    </CardTile>
  )
}

function MailCard({ m }: { m: ChatMessage }) {
  const mail = m.meta?.mail
  if (!mail) return null
  const date = mailDateLabel(mail.date)
  return (
    <CardTile icon="mail" iconClass="bg-violet-50 text-violet-700" label="Mail z Thunderbirda" spaced={Boolean(m.body)}>
      <span className="font-semibold text-slate-800">{mail.subject || '(bez tematu)'}</span>
      <span className="text-slate-600">
        Od: {mail.from}
        {date ? ` · ${date}` : ''}
      </span>
      {mail.body ? (
        <details className="mt-0.5">
          <summary className="cursor-pointer select-none font-medium text-violet-700">Pokaż treść maila</summary>
          <div className="mt-1.5 max-h-72 overflow-y-auto whitespace-pre-wrap break-words rounded-lg bg-slate-50 p-2 text-slate-700 ring-1 ring-slate-200/70">
            {mail.body}
          </div>
        </details>
      ) : (
        <span className="text-slate-500">Bez treści — tylko temat, nadawca i data.</span>
      )}
    </CardTile>
  )
}

/** Wpis o rozmowie głosowej/wideo — zielona ikona; stan z meta.call (ta sama wiadomość zmienia się z rozmową). */
function CallCard({
  m,
  own,
  calling,
  isDirect,
  declined,
  ending,
  onJoin,
  onCallBack,
  onEnd,
  onDecline,
}: {
  m: ChatMessage
  own: boolean
  calling: boolean
  isDirect: boolean
  declined: boolean
  ending: boolean
  onJoin: (callId: number) => void
  onCallBack: (kind: CallKind) => void
  onEnd: (callId: number) => void
  onDecline: (callId: number) => void
}) {
  const call = m.meta?.call
  if (!call) return null
  const kind: CallKind = call.kind === 'video' ? 'video' : 'audio'
  let text: string
  let action: ReactNode = null
  if (call.status === 'missed') {
    text = 'Nieodebrane połączenie'
    action = (
      <button
        type="button"
        disabled={calling}
        onClick={() => onCallBack(kind)}
        className="mt-0.5 justify-self-start font-medium text-sky-700 hover:underline disabled:opacity-50"
      >
        {own ? 'Zadzwoń ponownie' : 'Oddzwoń'}
      </button>
    )
  } else if (call.status === 'ended') {
    const duration = callDurationLabel(call.duration_seconds)
    text = duration ? `Rozmowa · ${duration}` : 'Rozmowa zakończona'
  } else {
    const green = 'rounded-full bg-green-600 px-3 py-1 font-medium text-white hover:bg-green-700'
    const red =
      'rounded-full px-3 py-1 font-medium text-red-700 ring-1 ring-red-200 hover:bg-red-50 disabled:opacity-50'
    const ringing = call.status === 'ringing'
    let primary: ReactNode
    let secondary: ReactNode = null
    if (ringing && own) {
      // Dzwonię i nikt jeszcze nie odebrał — mogę wrócić do karty rozmowy albo przestać dzwonić.
      text = 'Dzwonię…'
      primary = (
        <button type="button" onClick={() => onJoin(call.id)} className={green}>
          Wróć do rozmowy
        </button>
      )
      secondary = (
        <button type="button" disabled={ending} onClick={() => onEnd(call.id)} className={red}>
          Zakończ
        </button>
      )
    } else if (ringing && !declined) {
      text = 'Dzwoni…'
      primary = (
        <button type="button" onClick={() => onJoin(call.id)} className={green}>
          Odbierz
        </button>
      )
      secondary = (
        <button type="button" disabled={ending} onClick={() => onDecline(call.id)} className={red}>
          Odrzuć
        </button>
      )
    } else {
      text = ringing ? 'Odrzucono — rozmowa jeszcze trwa' : 'Rozmowa trwa'
      primary = (
        <button type="button" onClick={() => onJoin(call.id)} className={green}>
          Dołącz
        </button>
      )
      // Rozmowę 1:1 kończy każda z dwóch osób (dla obu); grupowa trwa, dopóki ktoś w niej jest.
      if (isDirect) {
        secondary = (
          <button type="button" disabled={ending} onClick={() => onEnd(call.id)} className={red}>
            Zakończ
          </button>
        )
      }
    }
    action = (
      <span className="mt-1 flex flex-wrap items-center gap-2">
        {primary}
        {secondary}
      </span>
    )
  }
  return (
    <CardTile
      icon={kind === 'video' ? 'video' : 'phone'}
      iconClass={call.status === 'missed' ? 'bg-red-50 text-red-700' : 'bg-green-50 text-green-700'}
      label={callKindLabel(kind)}
      spaced={Boolean(m.body)}
    >
      <span className="font-semibold text-slate-800">{text}</span>
      {action}
    </CardTile>
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
  calling,
  isDirect,
  declinedCall,
  endingCall,
  onJoinCall,
  onCallBack,
  onEndCall,
  onDeclineCall,
}: {
  m: ChatMessage
  own: boolean
  showHeader: boolean
  confirming: boolean
  onAskDelete: () => void
  onCancelDelete: () => void
  onDelete: () => void
  deleting: boolean
  calling: boolean
  isDirect: boolean
  declinedCall: boolean
  endingCall: boolean
  onJoinCall: (callId: number) => void
  onCallBack: (kind: CallKind) => void
  onEndCall: (callId: number) => void
  onDeclineCall: (callId: number) => void
}) {
  if (m.kind === 'system') {
    return (
      <p className="my-2 self-center px-2 text-center text-[11.5px] text-slate-500">
        {m.body} · {timeOf(m.created_at)}
      </p>
    )
  }

  // Tekst w dymku, karta (link, mail, rozmowa) pod nim jako osobny kafelek — karta w niebieskim dymku
  // wyglądała jak gruba niebieska ramka.
  const text = m.deleted ? (
    <p className="text-[13px] italic">wiadomość usunięta</p>
  ) : m.body ? (
    <MessageText text={m.body} own={own} />
  ) : null
  const card = m.deleted ? null : m.kind === 'link' ? (
    <LinkCard m={m} />
  ) : m.kind === 'mail' ? (
    <MailCard m={m} />
  ) : m.kind === 'call' ? (
    <CallCard
      m={m}
      own={own}
      calling={calling}
      isDirect={isDirect}
      declined={declinedCall}
      ending={endingCall}
      onJoin={onJoinCall}
      onCallBack={onCallBack}
      onEnd={onEndCall}
      onDecline={onDeclineCall}
    />
  ) : null
  const bubble = m.deleted
    ? `rounded-2xl px-3 py-1.5 text-slate-500 ring-1 ring-slate-200 ${own ? 'rounded-tr-md' : 'rounded-tl-md'}`
    : own
      ? 'min-w-0 rounded-2xl rounded-tr-md bg-sky-600 px-3 py-2 text-white'
      : 'min-w-0 rounded-2xl rounded-tl-md bg-slate-100 px-3 py-2 text-slate-900'
  const content = (
    <div className={`flex min-w-0 flex-col gap-1 ${own ? 'items-end' : 'items-start'}`}>
      {text && <div className={bubble}>{text}</div>}
      {card}
    </div>
  )
  const time = (
    <span className="shrink-0 pb-1 text-[10.5px] tabular-nums text-slate-400" title={new Date(m.created_at).toLocaleString('pl-PL')}>
      {timeOf(m.created_at)}
    </span>
  )

  if (own) {
    // Wpisu o rozmowie nie da się usunąć (serwer odpowiada 422) — bez przycisku „Usuń”.
    const canDelete = !m.deleted && m.kind !== 'call'
    return (
      <div className={`group flex flex-col items-end ${showHeader ? 'mt-3' : 'mt-0.5'}`}>
        <div className="flex max-w-[78%] items-end gap-1.5">
          {canDelete && !confirming && (
            <button
              type="button"
              onClick={onAskDelete}
              title="Usuń wiadomość"
              aria-label="Usuń wiadomość"
              className="mb-0.5 grid h-6 w-6 shrink-0 place-items-center rounded-full text-slate-400 opacity-0 hover:bg-red-50 hover:text-red-700 focus:opacity-100 group-hover:opacity-100"
            >
              <Icon name="trash" className="h-3.5 w-3.5" />
            </button>
          )}
          {time}
          {content}
        </div>
        {canDelete && confirming && (
          <span className="mt-1 flex items-center gap-2 text-[11px]">
            <span className="text-slate-600">Usunąć tę wiadomość?</span>
            <button
              type="button"
              disabled={deleting}
              onClick={onDelete}
              className="rounded-full bg-red-600 px-2.5 py-0.5 font-medium text-white hover:bg-red-700 disabled:opacity-50"
            >
              Usuń
            </button>
            <button type="button" onClick={onCancelDelete} className="text-slate-600 hover:underline">
              Anuluj
            </button>
          </span>
        )}
      </div>
    )
  }

  const name = m.user ? m.user.name : 'Konto usunięte'
  return (
    <div className={`flex max-w-[78%] items-start gap-2 ${showHeader ? 'mt-3' : 'mt-0.5'}`}>
      {showHeader ? <Avatar name={name} userId={m.user?.id ?? null} size="sm" /> : <span className="w-8 shrink-0" />}
      <div className="flex min-w-0 flex-col items-start">
        {showHeader && (
          <span className={`mb-0.5 px-1 text-[11.5px] font-semibold ${m.user ? 'text-slate-700' : 'italic text-slate-500'}`}>
            {name}
          </span>
        )}
        <div className="flex min-w-0 max-w-full items-end gap-1.5">
          {content}
          {time}
        </div>
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
                  <Avatar name={u.name} userId={u.id} online={u.online} size="sm" />
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
  callsEnabled,
}: {
  conversation: ChatConversation
  me: number
  users: ChatUser[]
  live: boolean
  callsEnabled: boolean
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
  const [calling, setCalling] = useState(false)
  const [endingCall, setEndingCall] = useState(false)
  // Rozmowy grupowe odrzucone na tym ekranie — karta przestaje pokazywać „Odbierz/Odrzuć”.
  const [declinedCalls, setDeclinedCalls] = useState<Set<number>>(() => new Set())
  const [blockedCallUrl, setBlockedCallUrl] = useState('')
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
  // Najnowszy znany stan rozmów (po id wiadomości kind=call) — spóźniona odpowiedź z listy nie cofa „trwa” na „dzwoni”.
  const callMetas = useRef(new Map<number, ChatCallMeta>())

  const applyIncoming = useCallback(
    (list: ChatMessage[]) => {
      if (list.length === 0) return
      const known = deletedIds.current
      const metas = callMetas.current
      const incoming = list.map((m) => {
        if (known.has(m.id) && !m.deleted) return { ...m, deleted: true, body: null, meta: null }
        const call = m.kind === 'call' ? m.meta?.call : undefined
        if (call) {
          const ours = metas.get(m.id)
          if (ours && !isCallStatusNewerOrSame(ours.status, call.status)) return { ...m, meta: { ...m.meta, call: ours } }
          metas.set(m.id, call)
        }
        return m
      })
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

  /** Zmiana stanu rozmowy: świeży stan z GET /chat/calls/{id} i podmiana karty po message_id (W5), bez względu na stronę. */
  const patchCall = useCallback(
    async (e: ChatCallUpdatedEvent) => {
      let meta: ChatCallMeta = {
        id: e.call_id,
        kind: e.kind,
        status: e.status,
        duration_seconds: e.duration_seconds ?? null,
      }
      let messageId = e.message_id
      try {
        const r = await fetchCall(e.call_id)
        meta = { id: r.data.id, kind: r.data.kind, status: r.data.status, duration_seconds: r.data.duration_seconds }
        messageId = r.data.message_id ?? messageId
      } catch {
        /* zostaje stan ze zdarzenia */
      }
      if (!messageId) return
      const ours = callMetas.current.get(messageId)
      if (ours && !isCallStatusNewerOrSame(ours.status, meta.status)) return
      callMetas.current.set(messageId, meta)
      const prev = messagesRef.current
      if (!prev.some((m) => m.id === messageId && m.kind === 'call')) return
      commit(prev.map((m) => (m.id === messageId && m.kind === 'call' ? { ...m, meta: { ...m.meta, call: meta } } : m)))
    },
    [commit],
  )

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
      onRealtime('chat.call.updated', (e) => {
        if (e.conversation_id === id) void patchCall(e)
      }),
    ]
    return () => offs.forEach((off) => off())
  }, [id, loadNewer, commit, patchCall])

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

  /** Dołączenie do trwającej rozmowy — nowa karta od razu w kliknięciu (inaczej przeglądarka ją zablokuje). */
  function joinCallTab(callId: number) {
    setActionErr('')
    setBlockedCallUrl('')
    const url = callPageUrl(callId)
    if (!window.open(url, '_blank')) setBlockedCallUrl(url)
  }

  /**
   * Nowa rozmowa: kartę otwieramy pustą od razu w kliknięciu, a adres rozmowy wstawiamy po odpowiedzi serwera
   * (POST /calls zakłada pokój na serwerze rozmów — po takiej przerwie przeglądarka mogłaby zablokować nową kartę).
   * Gdy w rozmowie czatu już ktoś dzwoni albo rozmowa trwa, serwer zwraca tę rozmowę — wtedy po prostu dołączamy.
   */
  async function placeCall(kind: CallKind) {
    if (calling) return
    setCalling(true)
    setActionErr('')
    setBlockedCallUrl('')
    const tab = window.open('', '_blank')
    if (tab) {
      try {
        tab.document.title = 'Rozmowa'
        tab.document.body.textContent = 'Łączę z rozmową…'
      } catch {
        /* pusta karta bez napisu — nic złego */
      }
    }
    try {
      const r = await startCall(id, kind)
      // Pełny adres — pusta karta (about:blank) nie musi znać adresu aplikacji, od którego liczy się ścieżkę.
      const url = new URL(callPageUrl(r.data.id), window.location.origin).href
      if (tab && !tab.closed) tab.location.replace(url)
      else if (!window.open(url, '_blank')) setBlockedCallUrl(url)
      onListChanged()
    } catch (ex) {
      tab?.close()
      setActionErr(errorText(ex, 'Nie udało się zadzwonić.'))
    } finally {
      setCalling(false)
    }
  }

  /**
   * Zakończenie z czatu: dzwoniący przestaje dzwonić (bez odebrania = nieodebrane), w rozmowie 1:1 każda z osób
   * kończy ją dla obu (POST leave); „Odrzuć” — POST decline. Karta rozmowy (CallPage) dostaje chat.call.updated
   * i sama się rozłącza.
   */
  async function endCall(callId: number, decline: boolean) {
    if (endingCall) return
    setEndingCall(true)
    setActionErr('')
    try {
      const r = decline ? await declineCall(callId) : await leaveCall(callId)
      if (decline) setDeclinedCalls((prev) => new Set(prev).add(callId))
      await patchCall({
        call_id: r.data.id,
        conversation_id: r.data.conversation_id,
        message_id: r.data.message_id,
        status: r.data.status,
        kind: r.data.kind,
        reason: 'status',
        duration_seconds: r.data.duration_seconds,
      })
      onListChanged()
    } catch (ex) {
      setActionErr(errorText(ex, decline ? 'Nie udało się odrzucić rozmowy.' : 'Nie udało się zakończyć rozmowy.'))
    } finally {
      setEndingCall(false)
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
  const participantNames = conversation.participants.map((p) => p.name).join(', ')
  const participantCount = conversation.participants.length

  // Pole pisania rośnie z treścią do ok. 5 linii, dalej przewija się w środku.
  const composer = useRef<HTMLTextAreaElement>(null)
  useLayoutEffect(() => {
    const el = composer.current
    if (!el) return
    el.style.height = '0px'
    el.style.height = `${Math.min(el.scrollHeight, COMPOSER_MAX_PX)}px`
  }, [text])

  const roundBtn =
    'grid h-8 w-8 shrink-0 place-items-center rounded-full bg-slate-100 text-slate-700 hover:bg-slate-200 disabled:opacity-50 sm:h-9 sm:w-9'

  return (
    <section
      className="grid min-h-0 min-w-0 grid-cols-[minmax(0,1fr)] grid-rows-[auto_1fr_auto] overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200/70"
      aria-label={`Rozmowa: ${other || isChannel ? conversation.name : 'Konto usunięte'}`}
    >
      <div className="border-b border-slate-100 px-3 py-2.5 sm:px-4">
        <div className="flex items-center gap-2 sm:gap-3">
          <button
            type="button"
            onClick={onBack}
            title="Wróć do listy rozmów"
            aria-label="Wróć do listy rozmów"
            className="-ml-1 grid h-9 w-9 shrink-0 place-items-center rounded-full text-sky-700 hover:bg-slate-100 md:hidden"
          >
            <Icon name="back" className="h-5 w-5" />
          </button>
          {/* Na wąskim ekranie bez kółka — miejsce na nazwę i przyciski. */}
          <span className="hidden shrink-0 sm:block">
            {isChannel ? (
              <Avatar name={conversation.name} channel />
            ) : (
              <Avatar name={conversation.name} userId={other?.id ?? null} online={otherOnline} />
            )}
          </span>
          <div className="min-w-0 flex-1">
            <p className={`truncate text-[14.5px] font-semibold ${other || isChannel ? 'text-slate-900' : 'italic text-slate-500'}`}>
              {other || isChannel ? conversation.name : 'Konto usunięte'}
            </p>
            {isChannel ? (
              <p className="truncate text-[12px] text-slate-500" title={participantNames}>
                {conversation.everyone
                  ? 'Wszyscy pracownicy z dostępem do czatu'
                  : `${participantCount} ${plural(participantCount, 'osoba', 'osoby', 'osób')} · ${participantNames}`}
              </p>
            ) : other ? (
              <p className={`flex items-center gap-1.5 text-[12px] ${otherOnline ? 'text-green-700' : 'text-slate-500'}`}>
                <span
                  className={`h-2 w-2 shrink-0 rounded-full ${otherOnline ? 'bg-green-500' : 'bg-slate-300'}`}
                  aria-hidden="true"
                />
                {otherOnline ? 'Dostępny teraz' : 'Niedostępny'}
              </p>
            ) : null}
          </div>
          {!confirmLeave && (
            <div className="flex shrink-0 items-center gap-1 sm:gap-1.5">
              {callsEnabled && (isChannel || other) && (
                <>
                  <button
                    type="button"
                    disabled={calling}
                    onClick={() => void placeCall('audio')}
                    title="Zadzwoń"
                    aria-label="Zadzwoń"
                    className={roundBtn}
                  >
                    <Icon name="phone" />
                  </button>
                  <button
                    type="button"
                    disabled={calling}
                    onClick={() => void placeCall('video')}
                    title="Rozmowa wideo"
                    aria-label="Rozmowa wideo"
                    className={roundBtn}
                  >
                    <Icon name="video" />
                  </button>
                </>
              )}
              {canManage && (
                <>
                  <button
                    type="button"
                    onClick={() => setAddOpen(true)}
                    title="Dodaj osoby do kanału"
                    aria-label="Dodaj osoby do kanału"
                    className={roundBtn}
                  >
                    <Icon name="user-plus" />
                  </button>
                  <button
                    type="button"
                    onClick={() => setConfirmLeave(true)}
                    title="Wyjdź z kanału"
                    aria-label="Wyjdź z kanału"
                    className="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-slate-100 text-slate-700 hover:bg-red-50 hover:text-red-700 sm:h-9 sm:w-9"
                  >
                    <Icon name="leave" />
                  </button>
                </>
              )}
            </div>
          )}
        </div>
        {canManage && confirmLeave && (
          <div className="mt-2 flex flex-wrap items-center gap-2 rounded-xl bg-slate-50 px-3 py-2 text-xs ring-1 ring-slate-200/70">
            <span className="text-slate-700">Wyjść z kanału? Wiadomości przestaną do Ciebie przychodzić.</span>
            <span className="ml-auto flex items-center gap-2">
              <button
                type="button"
                disabled={leaving}
                onClick={() => void leave()}
                className="rounded-full bg-red-600 px-3 py-1 font-medium text-white hover:bg-red-700 disabled:opacity-50"
              >
                Wyjdź
              </button>
              <button type="button" onClick={() => setConfirmLeave(false)} className="px-1 text-slate-600 hover:underline">
                Anuluj
              </button>
            </span>
          </div>
        )}
        {actionErr && <p className="mt-2 rounded-lg bg-red-50 px-2.5 py-1.5 text-xs text-red-700">{actionErr}</p>}
        {blockedCallUrl && (
          <p className="mt-2 rounded-lg bg-amber-50 px-2.5 py-1.5 text-xs text-amber-800">
            Przeglądarka zablokowała nową kartę.{' '}
            <a href={blockedCallUrl} target="_blank" rel="noreferrer" className="font-medium underline">
              Otwórz rozmowę
            </a>
          </p>
        )}
      </div>

      <div
        ref={scroller}
        onScroll={onScroll}
        className="flex min-h-0 flex-col overflow-y-auto px-3 pb-3 pt-2 sm:px-4"
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
                className="rounded-full px-2.5 py-0.5 text-sky-700 hover:bg-slate-100 disabled:opacity-50"
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
          <p className="rounded-lg bg-red-50 px-2.5 py-1.5 text-xs text-red-700">
            {loadErr}{' '}
            <button type="button" onClick={() => void loadInitial()} className="font-medium underline">
              Spróbuj ponownie
            </button>
          </p>
        )}
        {loaded && messages.length === 0 && pending.length === 0 && (
          <div className="m-auto grid justify-items-center gap-2 py-6 text-center">
            <span className="grid h-12 w-12 place-items-center rounded-2xl bg-sky-50 text-sky-700">
              <Icon name="chat" className="h-6 w-6" />
            </span>
            <p className="text-xs text-slate-500">Nie ma jeszcze wiadomości. Napisz pierwszą.</p>
          </div>
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
                <span className="mb-1 mt-4 self-center rounded-full bg-slate-100 px-3 py-0.5 text-[11px] font-medium text-slate-500">
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
                calling={calling}
                isDirect={!isChannel}
                declinedCall={m.kind === 'call' && m.meta?.call ? declinedCalls.has(m.meta.call.id) : false}
                endingCall={endingCall}
                onJoinCall={joinCallTab}
                onCallBack={(kind) => void placeCall(kind)}
                onEndCall={(callId) => void endCall(callId, false)}
                onDeclineCall={(callId) => void endCall(callId, true)}
              />
            </div>
          )
        })}
        {pending.map((p) => (
          <div key={p.client_uuid} className="mt-1 flex flex-col items-end gap-0.5">
            <div className="flex max-w-[78%] items-end gap-1.5">
              <span className="shrink-0 pb-1 text-[10.5px] text-slate-400">
                {p.status === 'sending' ? 'Wysyłam…' : <span className="text-red-700">Nie wysłano</span>}
              </span>
              <div
                className={`min-w-0 rounded-2xl rounded-tr-md px-3 py-2 ${
                  p.status === 'failed' ? 'bg-red-50 text-slate-900 ring-1 ring-red-200' : 'bg-sky-600 text-white opacity-70'
                }`}
              >
                <MessageText text={p.body} own={p.status !== 'failed'} />
              </div>
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
        className="px-3 pb-3 pt-1 sm:px-4"
      >
        <div className="flex items-end gap-2 rounded-2xl bg-slate-100 py-1.5 pl-3.5 pr-1.5 focus-within:ring-2 focus-within:ring-sky-300">
          <textarea
            ref={composer}
            value={text}
            onChange={(e) => setText(e.target.value)}
            onKeyDown={onKeyDown}
            maxLength={CHAT_MAX_LENGTH}
            rows={1}
            placeholder="Napisz wiadomość…"
            aria-label="Treść wiadomości"
            title="Enter wysyła, Shift+Enter to nowa linia"
            className="max-h-[124px] min-h-9 w-full flex-1 resize-none overflow-y-auto border-0 bg-transparent py-2 text-[13px] leading-5 text-slate-900 placeholder:text-slate-400 focus:outline-none"
          />
          <button
            type="submit"
            disabled={!text.trim()}
            title="Wyślij (Enter)"
            aria-label="Wyślij"
            className="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-sky-600 text-white hover:bg-sky-700 disabled:opacity-40"
          >
            <Icon name="send" className="h-[18px] w-[18px]" />
          </button>
        </div>
        <small className="mt-1 flex justify-between gap-2 px-2 text-[10.5px] text-slate-400">
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
    </section>
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
  actions,
}: {
  active: boolean
  title: ReactNode
  preview: ReactNode
  time: string
  unread: number
  avatar: ReactNode
  onClick: () => void
  /** Szybkie przyciski (np. Zadzwoń/Wideo przy osobie) — nad prawą częścią kafelka po najechaniu albo fokusie. */
  actions?: ReactNode
}) {
  const hasUnread = unread > 0
  const row = (
    <button
      type="button"
      onClick={onClick}
      aria-current={active ? 'true' : undefined}
      className={`grid w-full grid-cols-[40px_minmax(0,1fr)] items-center gap-2.5 rounded-xl px-2.5 py-2 text-left ${
        active ? 'bg-sky-50 ring-1 ring-sky-200' : 'hover:bg-slate-50'
      }`}
    >
      {avatar}
      <span className="min-w-0">
        <span className="flex items-baseline gap-2">
          <span className="min-w-0 flex-1 truncate text-[13px] font-semibold text-slate-900">{title}</span>
          {time && (
            <span className={`shrink-0 text-[11px] tabular-nums ${hasUnread ? 'font-semibold text-sky-700' : 'text-slate-400'}`}>
              {time}
            </span>
          )}
        </span>
        <span className="mt-0.5 flex items-center gap-2">
          <span
            className={`min-w-0 flex-1 truncate text-[12px] ${hasUnread ? 'font-semibold text-slate-800' : 'text-slate-500'}`}
          >
            {preview}
          </span>
          <CountBadge n={unread} />
        </span>
      </span>
    </button>
  )
  if (!actions) return row
  return (
    <div className="group relative">
      {row}
      {/* na ekranie dotykowym (bez najeżdżania) przyciski widać zawsze */}
      <span className="absolute right-2 top-1/2 flex -translate-y-1/2 gap-1 rounded-full bg-white p-0.5 opacity-0 shadow-sm ring-1 ring-slate-200 transition-opacity focus-within:opacity-100 group-hover:opacity-100 [@media(hover:none)]:opacity-100">
        {actions}
      </span>
    </div>
  )
}

function SectionTitle({ children, action }: { children: ReactNode; action?: ReactNode }) {
  return (
    <div className="flex items-center justify-between px-2.5 pb-1 pt-3">
      <span className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{children}</span>
      {action}
    </div>
  )
}

type PersonRow = { key: string; user: ChatUser | null; conv: ChatConversation | null; name: string; online: boolean }

function lastMessageAt(r: PersonRow): number | null {
  const m = r.conv?.last_message
  if (!m) return null
  const t = Date.parse(m.created_at)
  return Number.isNaN(t) ? 0 : t
}

/**
 * Kolejność osób w grupie (dostępni / pozostali): najpierw z nieprzeczytanymi, potem wg ostatniej
 * wiadomości (najnowsze wyżej), osoby bez rozmowy na końcu alfabetycznie; konto usunięte na samym końcu.
 */
function comparePeople(a: PersonRow, b: PersonRow): number {
  if (!a.user !== !b.user) return a.user ? -1 : 1
  const ua = (a.conv?.unread ?? 0) > 0
  const ub = (b.conv?.unread ?? 0) > 0
  if (ua !== ub) return ua ? -1 : 1
  const ta = lastMessageAt(a)
  const tb = lastMessageAt(b)
  if (ta !== null && tb !== null) {
    return tb - ta || (b.conv?.last_message?.id ?? 0) - (a.conv?.last_message?.id ?? 0)
  }
  if (ta !== null) return -1
  if (tb !== null) return 1
  return a.name.localeCompare(b.name, 'pl')
}

/** Poziomy rząd kafelków z osobami dostępnymi teraz (pasek nad listą i skrót w pustym widoku). */
function AvailableStrip({ rows, onOpen }: { rows: PersonRow[]; onOpen: (r: PersonRow) => void }) {
  // Bez paska przewijania (zabierał miejsce i straszył strzałkami); kółko myszy przewija w bok, dotyk i gładzik — jak zwykle.
  return (
    <div
      className="flex min-w-0 gap-0.5 overflow-x-auto pb-1 [scrollbar-width:none]"
      onWheel={(e) => {
        const el = e.currentTarget
        if (el.scrollWidth > el.clientWidth && Math.abs(e.deltaY) > Math.abs(e.deltaX)) el.scrollLeft += e.deltaY
      }}
    >
      {rows.map((r) => (
        <button
          key={r.key}
          type="button"
          onClick={() => onOpen(r)}
          title={`Napisz do: ${r.name}`}
          aria-label={`Napisz do: ${r.name}`}
          className="grid w-[54px] shrink-0 justify-items-center gap-1 rounded-xl px-0.5 py-1.5 hover:bg-slate-50"
        >
          <span className="relative">
            <Avatar name={r.name} userId={r.user?.id ?? null} online size="lg" />
            {(r.conv?.unread ?? 0) > 0 && (
              <span className="absolute -right-1.5 -top-1">
                <CountBadge n={r.conv?.unread ?? 0} />
              </span>
            )}
          </span>
          <span className="w-full truncate text-center text-[11px] text-slate-600">{firstName(r.name)}</span>
        </button>
      ))}
    </div>
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

/**
 * `compact` — małe okno czatu otwierane przyciskiem „Czat” dodatku Thunderbirda (/czat-okno, ok. 400 px): bez nagłówka
 * strony i bez menu aplikacji, cała wysokość okna; układ jak na telefonie (lista albo rozmowa).
 */
export function Chat({ compact = false }: { compact?: boolean } = {}) {
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
  const [callingPerson, setCallingPerson] = useState(false)
  const [search, setSearch] = useState('')
  const [newChannelOpen, setNewChannelOpen] = useState(false)
  const [callsEnabled, setCallsEnabled] = useState(false)

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

  // Rozmowy głosowe i wideo — przyciski tylko, gdy serwer rozmów jest skonfigurowany.
  useEffect(() => {
    let cancelled = false
    fetchCallsConfig().then(
      (c) => {
        if (!cancelled) setCallsEnabled(Boolean(c.enabled))
      },
      () => {},
    )
    return () => {
      cancelled = true
    }
  }, [])

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

  // ——— lista: kanały, potem osoby — najpierw dostępne, potem pozostałe (kolejność w comparePeople) ———
  const q = foldText(search.trim())
  const match = (name: string) => !q || foldText(name).includes(q)

  const { channels, onlinePeople, otherPeople } = useMemo(() => {
    const list = conversations ?? []
    const chans = list.filter((c) => c.type === 'channel')
    const directs = list.filter((c) => c.type === 'direct')
    const byUser = new Map<number, ChatConversation>()
    for (const c of directs) if (c.other_user) byUser.set(c.other_user.id, c)
    const withMessages: PersonRow[] = directs
      .filter((c) => c.last_message)
      .map((c) => {
        const user = c.other_user ? (users.find((u) => u.id === c.other_user?.id) ?? c.other_user) : null
        return { key: `c${c.id}`, user, conv: c, name: c.other_user ? c.name : 'Konto usunięte', online: user?.online === true }
      })
    const shown = new Set(withMessages.map((r) => r.user?.id).filter((x): x is number => typeof x === 'number'))
    const rest: PersonRow[] = users
      .filter((u) => !u.is_me && !shown.has(u.id))
      .map((u) => ({ key: `u${u.id}`, user: u, conv: byUser.get(u.id) ?? null, name: u.name, online: u.online === true }))
    const all = [...withMessages, ...rest]
    return {
      channels: chans,
      onlinePeople: all.filter((r) => r.online).sort(comparePeople),
      otherPeople: all.filter((r) => !r.online).sort(comparePeople),
    }
  }, [conversations, users])

  const shownChannels = channels.filter((c) => match(c.name))
  const shownOnline = onlinePeople.filter((r) => match(r.name))
  const shownOther = otherPeople.filter((r) => match(r.name))

  function openPerson(r: PersonRow) {
    if (r.conv) openConversation(r.conv.id)
    else if (r.user) {
      setPageErr('')
      setParams({ u: String(r.user.id) })
    }
  }

  function personRow(r: PersonRow) {
    return (
      <ConversationRow
        key={r.key}
        active={r.conv !== null && r.conv.id === activeId}
        title={r.user ? r.name : <span className="italic text-slate-500">Konto usunięte</span>}
        preview={
          r.conv?.last_message ? (
            previewFor(r.conv, me)
          ) : (
            <span className="font-normal text-slate-400">{r.online ? 'Dostępny teraz' : 'Napisz pierwszą wiadomość'}</span>
          )
        }
        time={r.conv?.last_message ? listTime(r.conv.last_message.created_at) : ''}
        unread={r.conv?.unread ?? 0}
        avatar={<Avatar name={r.name} userId={r.user?.id ?? null} online={r.online} />}
        onClick={() => openPerson(r)}
        actions={
          callsEnabled && r.user ? (
            <>
              <button
                type="button"
                disabled={callingPerson}
                onClick={() => void callPerson(r, 'audio')}
                title={`Zadzwoń do: ${r.name}`}
                aria-label={`Zadzwoń do: ${r.name}`}
                className="grid h-8 w-8 place-items-center rounded-full text-slate-600 hover:bg-green-50 hover:text-green-700 disabled:opacity-50"
              >
                <Icon name="phone" className="h-4 w-4" />
              </button>
              <button
                type="button"
                disabled={callingPerson}
                onClick={() => void callPerson(r, 'video')}
                title={`Rozmowa wideo z: ${r.name}`}
                aria-label={`Rozmowa wideo z: ${r.name}`}
                className="grid h-8 w-8 place-items-center rounded-full text-slate-600 hover:bg-green-50 hover:text-green-700 disabled:opacity-50"
              >
                <Icon name="video" className="h-4 w-4" />
              </button>
            </>
          ) : undefined
        }
      />
    )
  }

  /**
   * Zadzwoń prosto z listy (bez otwierania rozmowy i przewijania do nagłówka): rozmowa 1:1 — istniejąca albo nowa —
   * i strona rozmowy w nowej karcie. Kartę otwieramy pustą w samym kliknięciu, adres wstawiamy po odpowiedzi serwera
   * (inaczej przeglądarka mogłaby zablokować nowe okno), jak przy przyciskach w nagłówku rozmowy.
   */
  async function callPerson(r: PersonRow, kind: CallKind) {
    if (callingPerson || !r.user) return
    setCallingPerson(true)
    setPageErr('')
    const tab = window.open('', '_blank')
    if (tab) {
      try {
        tab.document.title = 'Rozmowa'
        tab.document.body.textContent = 'Łączę z rozmową…'
      } catch {
        /* pusta karta bez napisu — nic złego */
      }
    }
    try {
      const conversationId = r.conv?.id ?? (await openDirect(r.user.id)).data.id
      const res = await startCall(conversationId, kind)
      const url = new URL(callPageUrl(res.data.id), window.location.origin).href
      if (tab && !tab.closed) tab.location.replace(url)
      else if (!window.open(url, '_blank')) setPageErr('Przeglądarka zablokowała nowe okno rozmowy — zezwól na wyskakujące okna dla tej strony.')
      void loadList()
    } catch (ex) {
      tab?.close()
      setPageErr(errorText(ex, 'Nie udało się zadzwonić.'))
    } finally {
      setCallingPerson(false)
    }
  }

  const panel = 'rounded-2xl bg-white shadow-sm ring-1 ring-slate-200/70'

  return (
    <CompactChatContext.Provider value={compact}>
    <div
      className={
        compact
          ? 'app-chat app-chat-window flex h-screen min-h-0 flex-col bg-slate-50 p-2'
          : 'app-chat flex h-[calc(100vh-2.5rem)] min-h-[480px] flex-col'
      }
    >
      {!compact && <h1 className="mb-3 text-xl font-semibold">Czat</h1>}
      {pageErr && <p className="mb-2 rounded-xl bg-red-50 px-3 py-2 text-xs text-red-700">{pageErr}</p>}
      <div className="grid min-h-0 flex-1 grid-cols-[minmax(0,1fr)] gap-3 md:grid-cols-[300px_minmax(0,1fr)]">
        <aside
          className={`min-h-0 min-w-0 flex-col overflow-hidden ${panel} ${active ? 'hidden md:flex' : 'flex'}`}
          aria-label="Rozmowy i osoby"
        >
          {onlinePeople.length > 0 && (
            <div className="border-b border-slate-100 px-2.5 pt-3">
              <p className="px-1 pb-1 text-[11px] font-semibold uppercase tracking-wide text-slate-500">Dostępni teraz</p>
              <AvailableStrip rows={onlinePeople} onOpen={openPerson} />
            </div>
          )}
          <div className="px-3 pb-1 pt-3">
            <div className="relative">
              <Icon
                name="search"
                className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400"
              />
              <input
                type="search"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                placeholder="Szukaj osoby lub rozmowy"
                aria-label="Szukaj osoby lub rozmowy"
                className="w-full rounded-xl border-0 bg-slate-100 py-2 pl-9 pr-3 text-[13px] text-slate-900 placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-sky-300"
              />
            </div>
          </div>
          <div className="min-h-0 flex-1 overflow-y-auto px-2 pb-3">
            {listErr && (
              <p className="mx-1 mt-2 rounded-lg bg-red-50 px-2.5 py-1.5 text-xs text-red-700">
                {listErr}{' '}
                <button type="button" onClick={() => void loadList()} className="font-medium underline">
                  Spróbuj ponownie
                </button>
              </p>
            )}
            {conversations === null && !listErr && <p className="px-2.5 pt-3 text-xs text-slate-500">Ładowanie…</p>}
            <SectionTitle
              action={
                <button
                  type="button"
                  onClick={() => setNewChannelOpen(true)}
                  title="Nowy kanał"
                  aria-label="Nowy kanał"
                  className="grid h-6 w-6 place-items-center rounded-full text-slate-500 hover:bg-slate-100 hover:text-sky-700"
                >
                  <Icon name="plus" className="h-4 w-4" />
                </button>
              }
            >
              Kanały
            </SectionTitle>
            <div className="space-y-0.5">
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
            </div>
            {conversations !== null && shownChannels.length === 0 && (
              <p className="px-2.5 py-1 text-[12px] text-slate-500">{q ? 'Brak pasujących kanałów.' : 'Brak kanałów.'}</p>
            )}
            {shownOnline.length > 0 && (
              <>
                <SectionTitle>Dostępni ({shownOnline.length})</SectionTitle>
                <div className="space-y-0.5">{shownOnline.map(personRow)}</div>
              </>
            )}
            {shownOther.length > 0 && (
              <>
                <SectionTitle>{onlinePeople.length > 0 ? 'Pozostali' : 'Osoby'}</SectionTitle>
                <div className="space-y-0.5">{shownOther.map(personRow)}</div>
              </>
            )}
            {users.length > 0 && shownOnline.length === 0 && shownOther.length === 0 && (
              <>
                <SectionTitle>Osoby</SectionTitle>
                <p className="px-2.5 py-1 text-[12px] text-slate-500">{q ? 'Nikogo takiego nie ma.' : 'Brak innych osób.'}</p>
              </>
            )}
          </div>
        </aside>

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
            callsEnabled={callsEnabled}
          />
        ) : (
          <section className={`hidden min-h-0 items-center justify-center p-6 md:flex ${panel}`}>
            {activeId || directUser ? (
              <p className="text-sm text-slate-500">Otwieram rozmowę…</p>
            ) : (
              <div className="grid max-w-md justify-items-center gap-3 text-center">
                <span className="grid h-16 w-16 place-items-center rounded-2xl bg-sky-50 text-sky-700">
                  <Icon name="chat" className="h-8 w-8" />
                </span>
                <div>
                  <p className="text-[15px] font-semibold text-slate-900">Wybierz rozmowę albo osobę z listy</p>
                  <p className="mt-1 text-[13px] text-slate-500">
                    Kanały są dla zespołów, a rozmowę z jedną osobą otworzysz, klikając jej imię.
                  </p>
                </div>
                {onlinePeople.length > 0 && (
                  <div className="mt-2 w-full rounded-2xl bg-slate-50 p-3 ring-1 ring-slate-200/70">
                    <p className="pb-1 text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                      Dostępni teraz ({onlinePeople.length})
                    </p>
                    <div className="flex justify-center">
                      <AvailableStrip rows={onlinePeople} onOpen={openPerson} />
                    </div>
                  </div>
                )}
              </div>
            )}
          </section>
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
    </CompactChatContext.Provider>
  )
}
