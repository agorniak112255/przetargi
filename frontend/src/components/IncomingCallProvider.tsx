import { useCallback, useEffect, useRef, useState } from 'react'
import { useAuth } from '../auth'
import { can } from '../lib/api'
import { callPageUrl, declineCall, fetchCall, RING_SECONDS } from '../lib/calls'
import { avatarColor, CHAT_PERMISSION, initials } from '../lib/chat'
import { onRealtime, type ChatCallRingingEvent } from '../lib/realtime'
import { isAddonSession } from '../lib/tokenStore'

/**
 * Dzwonek w całej aplikacji (montowany w Layout — strona samej rozmowy go nie ma, więc tam nie dzwoni).
 *
 * chat.call.ringing → okno „{kto} dzwoni” z Odbierz / Odbierz bez kamery / Odrzuć i dźwięk przez 45 s.
 * Okno znika, gdy chat.call.updated ma status ≠ ringing albo reason declined/joined (kontrakt §4), po 45 s,
 * po odebraniu lub odrzuceniu w innej karcie (BroadcastChannel) i po ponownym połączeniu, jeśli serwer mówi,
 * że rozmowa już nie dzwoni.
 *
 * Okno pokazuje każda otwarta karta (użytkownik patrzy na jedną z nich), ale dźwięk gra tylko jedna —
 * ta, która dostanie blokadę navigator.locks (widoczna karta próbuje od razu, ukryte chwilę później).
 */

type Ringing = ChatCallRingingEvent & { until: number }

const CHANNEL = 'supon-call-ring'
const LOCK_PREFIX = 'supon-call-ring-'
/** Ukryta karta czeka tyle, zanim sięgnie po dźwięk — pierwszeństwo ma karta, na którą użytkownik patrzy. */
const HIDDEN_TAB_DELAY_MS = 400

// ——— dźwięk (WebAudio) ———

let audioCtx: AudioContext | null = null
let unlockInstalled = false

/**
 * AudioContext tworzymy dopiero przy pierwszym kliknięciu na stronie (wtedy przeglądarka pozwala grać).
 * Bez kliknięcia kontekst zostaje wstrzymany i dzwonek jest tylko oknem.
 */
function installAudioUnlock() {
  if (unlockInstalled || typeof window === 'undefined') return
  unlockInstalled = true
  const unlock = () => {
    try {
      if (!audioCtx) audioCtx = new AudioContext()
      if (audioCtx.state === 'suspended') void audioCtx.resume().catch(() => {})
    } catch {
      /* przeglądarka bez WebAudio — dzwonek bez dźwięku */
    }
  }
  window.addEventListener('pointerdown', unlock, { capture: true, passive: true })
  window.addEventListener('keydown', unlock, { capture: true, passive: true })
}

function ringContext(): AudioContext | null {
  try {
    if (!audioCtx) audioCtx = new AudioContext()
    if (audioCtx.state === 'suspended') void audioCtx.resume().catch(() => {})
    return audioCtx
  } catch {
    return null
  }
}

/** Jeden „dzwonek”: dwa krótkie dwutony. */
function playRingOnce(ctx: AudioContext) {
  if (ctx.state !== 'running') return
  const start = ctx.currentTime + 0.02
  for (const offset of [0, 0.5]) {
    const gain = ctx.createGain()
    gain.gain.setValueAtTime(0, start + offset)
    gain.gain.linearRampToValueAtTime(0.12, start + offset + 0.03)
    gain.gain.setValueAtTime(0.12, start + offset + 0.32)
    gain.gain.linearRampToValueAtTime(0, start + offset + 0.38)
    gain.connect(ctx.destination)
    for (const freq of [660, 880]) {
      const osc = ctx.createOscillator()
      osc.type = 'sine'
      osc.frequency.value = freq
      osc.connect(gain)
      osc.start(start + offset)
      osc.stop(start + offset + 0.4)
    }
  }
}

/** Gra dzwonek co 3 s, dopóki nie zostanie zatrzymany; zwraca funkcję zatrzymującą. */
function startRingSound(): () => void {
  const ctx = ringContext()
  if (!ctx) return () => {}
  playRingOnce(ctx)
  const timer = window.setInterval(() => playRingOnce(ctx), 3000)
  return () => window.clearInterval(timer)
}

type LockManagerLike = {
  request: (
    name: string,
    options: { ifAvailable: boolean },
    callback: (lock: unknown) => Promise<void>,
  ) => Promise<unknown>
}

function lockManager(): LockManagerLike | null {
  const locks = (navigator as Navigator & { locks?: LockManagerLike }).locks
  return locks && typeof locks.request === 'function' ? locks : null
}

/**
 * Dźwięk tylko w jednej karcie: kto weźmie blokadę, ten dzwoni, dopóki nie wywoła się zwrócona funkcja.
 * Bez navigator.locks (stara przeglądarka) dzwoni każda karta.
 */
function ringInOneTab(callId: number): () => void {
  let stopped = false
  let stopSound: (() => void) | null = null
  let release: (() => void) | null = null
  const locks = lockManager()

  const begin = () => {
    if (stopped) return
    if (!locks) {
      stopSound = startRingSound()
      return
    }
    void locks
      .request(`${LOCK_PREFIX}${callId}`, { ifAvailable: true }, (lock) => {
        if (!lock || stopped) return Promise.resolve()
        stopSound = startRingSound()
        return new Promise<void>((resolve) => {
          release = resolve
        })
      })
      .catch(() => {})
  }

  const delay = document.visibilityState === 'visible' ? 0 : HIDDEN_TAB_DELAY_MS
  const timer = window.setTimeout(begin, delay)

  return () => {
    stopped = true
    window.clearTimeout(timer)
    stopSound?.()
    stopSound = null
    release?.()
    release = null
  }
}

// ——— okno ———

type TabMessage = { type: 'handled'; call_id: number }

/**
 * Karta czatu w Thunderbirdzie (przycisk „Czat” na pasku przestrzeni) to ta sama aplikacja, ale tam dzwoni już
 * dodatek: powiadomienie z dźwiękiem, a kliknięcie otwiera rozmowę w przeglądarce. Drugi dzwonek z karty byłby
 * podwójny, a rozmowa i tak nie odbywa się w Thunderbirdzie.
 */
const INSIDE_THUNDERBIRD =
  isAddonSession() || (typeof navigator !== 'undefined' && /\bThunderbird\//.test(navigator.userAgent))

export function IncomingCallProvider() {
  const { user } = useAuth()
  const enabled = can(user, CHAT_PERMISSION) && !INSIDE_THUNDERBIRD
  const me = user?.id ?? 0
  const [calls, setCalls] = useState<Ringing[]>([])
  const callsRef = useRef<Ringing[]>([])
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const channel = useRef<BroadcastChannel | null>(null)
  // Rozmowy już obsłużone w tej karcie — spóźnione zdarzenie ringing nie otwiera okna drugi raz.
  const handled = useRef(new Set<number>())

  const commit = useCallback((next: Ringing[]) => {
    callsRef.current = next
    setCalls(next)
  }, [])

  const dismiss = useCallback(
    (callId: number) => {
      handled.current.add(callId)
      if (callsRef.current.some((c) => c.call_id === callId)) {
        commit(callsRef.current.filter((c) => c.call_id !== callId))
      }
    },
    [commit],
  )

  useEffect(() => {
    installAudioUnlock()
  }, [])

  // Inne karty tej przeglądarki: odebrane albo odrzucone gdzie indziej — tu też znika.
  useEffect(() => {
    if (!enabled || typeof BroadcastChannel === 'undefined') return
    const ch = new BroadcastChannel(CHANNEL)
    channel.current = ch
    ch.onmessage = (ev: MessageEvent<TabMessage>) => {
      if (ev.data?.type === 'handled' && typeof ev.data.call_id === 'number') dismiss(ev.data.call_id)
    }
    return () => {
      ch.close()
      channel.current = null
    }
  }, [enabled, dismiss])

  useEffect(() => {
    if (!enabled) {
      commit([])
      return
    }
    const offs = [
      onRealtime('chat.call.ringing', (e) => {
        if (e.started_by?.id === me || handled.current.has(e.call_id)) return
        if (callsRef.current.some((c) => c.call_id === e.call_id)) return
        commit([...callsRef.current, { ...e, until: Date.now() + RING_SECONDS * 1000 }])
      }),
      onRealtime('chat.call.updated', (e) => {
        if (e.status !== 'ringing' || e.reason === 'declined' || e.reason === 'joined') dismiss(e.call_id)
      }),
      // Po przerwie w połączeniu sygnał o końcu mógł przepaść — pytamy serwer o każdą dzwoniącą rozmowę.
      onRealtime('connected', () => {
        for (const c of callsRef.current) {
          fetchCall(c.call_id).then(
            (r) => {
              if (r.data.status !== 'ringing') dismiss(c.call_id)
            },
            () => {},
          )
        }
      }),
    ]
    return () => offs.forEach((off) => off())
  }, [enabled, me, commit, dismiss])

  // Po 45 s okno znika samo (serwer i tak zamieni rozmowę na nieodebraną).
  useEffect(() => {
    if (calls.length === 0) return
    const next = Math.min(...calls.map((c) => c.until))
    const timer = window.setTimeout(
      () => {
        const now = Date.now()
        const expired = callsRef.current.filter((c) => c.until <= now)
        if (expired.length > 0) commit(callsRef.current.filter((c) => c.until > now))
      },
      Math.max(0, next - Date.now()) + 50,
    )
    return () => window.clearTimeout(timer)
  }, [calls, commit])

  const current = calls[0] ?? null
  const currentId = current?.call_id ?? null

  // Dźwięk dla pokazanej rozmowy — tylko w jednej karcie.
  useEffect(() => {
    if (currentId === null) return
    return ringInOneTab(currentId)
  }, [currentId])

  useEffect(() => {
    setError('')
    setBusy(false)
  }, [currentId])

  function notifyOtherTabs(callId: number) {
    try {
      channel.current?.postMessage({ type: 'handled', call_id: callId } satisfies TabMessage)
    } catch {
      /* kanał zamknięty — inne karty dostaną zdarzenie z serwera */
    }
  }

  function answer(c: Ringing, camera: boolean) {
    // window.open od razu w kliknięciu — inaczej przeglądarka uzna to za wyskakujące okno.
    const opened = window.open(callPageUrl(c.call_id, camera), '_blank')
    if (!opened) {
      setError('Przeglądarka zablokowała nową kartę. Zezwól na wyskakujące okna dla tej strony i kliknij jeszcze raz.')
      return
    }
    notifyOtherTabs(c.call_id)
    dismiss(c.call_id)
  }

  async function decline(c: Ringing) {
    setBusy(true)
    setError('')
    try {
      await declineCall(c.call_id)
      notifyOtherTabs(c.call_id)
      dismiss(c.call_id)
    } catch {
      // Rozmowa mogła się już skończyć — okno i tak zamykamy, żeby nie wisiało.
      notifyOtherTabs(c.call_id)
      dismiss(c.call_id)
    } finally {
      setBusy(false)
    }
  }

  if (!enabled || !current) return null

  const video = current.kind === 'video'
  const isChannel = current.conversation_name !== current.started_by.name
  const caller = current.started_by.name
  return (
    <div
      role="alertdialog"
      aria-live="assertive"
      aria-label={`Połączenie przychodzące: ${caller}`}
      className="fixed right-4 top-4 z-[60] grid w-[300px] gap-2.5 rounded-lg border border-slate-300 bg-white p-3.5 text-slate-900 shadow-xl print:hidden"
    >
      <div className="grid grid-cols-[46px_1fr] items-center gap-2.5">
        <span
          className={`grid h-[46px] w-[46px] place-items-center rounded-full text-[15px] font-semibold ring-4 ring-green-600/30 animate-pulse motion-reduce:animate-none ${avatarColor(current.started_by.id)}`}
          aria-hidden="true"
        >
          {initials(caller)}
        </span>
        <div className="min-w-0">
          <b className="block truncate text-sm font-semibold">{caller}</b>
          <span className="block truncate text-xs text-slate-500">
            dzwoni · {video ? 'rozmowa wideo' : 'rozmowa głosowa'}
            {isChannel ? ` · kanał „${current.conversation_name}”` : ''}
          </span>
        </div>
      </div>
      <div className="grid grid-cols-2 gap-2">
        <button
          type="button"
          onClick={() => answer(current, video)}
          disabled={busy}
          className="inline-flex items-center justify-center gap-1.5 rounded bg-green-600 px-2.5 py-1.5 text-xs font-medium text-white hover:bg-green-700 disabled:opacity-50"
        >
          Odbierz
        </button>
        <button
          type="button"
          onClick={() => void decline(current)}
          disabled={busy}
          className="inline-flex items-center justify-center gap-1.5 rounded bg-red-600 px-2.5 py-1.5 text-xs font-medium text-white hover:bg-red-700 disabled:opacity-50"
        >
          Odrzuć
        </button>
      </div>
      {video && (
        <button
          type="button"
          onClick={() => answer(current, false)}
          disabled={busy}
          className="justify-self-center text-[11.5px] font-medium text-sky-700 hover:underline disabled:opacity-50"
        >
          Odbierz bez kamery
        </button>
      )}
      {calls.length > 1 && (
        <p className="text-center text-[11px] text-slate-500">Dzwoni też: {calls.length - 1}</p>
      )}
      {error && <p className="rounded bg-red-50 px-2 py-1.5 text-[11.5px] text-red-700">{error}</p>}
    </div>
  )
}
