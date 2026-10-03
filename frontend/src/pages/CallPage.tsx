import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode, type RefObject } from 'react'
import { Link, useParams, useSearchParams } from 'react-router-dom'
import {
  ConnectionState,
  createLocalVideoTrack,
  DisconnectReason,
  type LocalVideoTrack,
  type Participant,
  type RemoteParticipant,
  Room,
  RoomEvent,
  Track,
  VideoPresets,
} from 'livekit-client'
import { useAuth } from '../auth'
import { ApiError } from '../lib/api'
import {
  fetchCall,
  isCallFinished,
  joinCall,
  leaveCall,
  type Call,
} from '../lib/calls'
import { avatarColor, fetchConversation, initials } from '../lib/chat'
import { onRealtime } from '../lib/realtime'

/**
 * Rozmowa głosowa / wideo w osobnej karcie: /czat/rozmowa/:id (bez paska bocznego, ładowana leniwie
 * razem z livekit-client). Kontrakt: scratchpad/rozmowy-kontrakt.md §5 i §7 (W6, D2).
 *
 * Najpierw ekran „Dołącz do rozmowy” (podgląd kamery, przełączniki, urządzenia) — kliknięcie „Dołącz” daje
 * przeglądarce zgodę na dźwięk. Potem POST join → pokój LiveKit. Rozmowy nie kończymy przy zamknięciu
 * karty (odświeżenie strony zakończyłoby rozmowę 1:1) — o wyjściu serwer dowiaduje się z LiveKit.
 *
 * Siatka: najwyżej 9 kafelków (udostępniany ekran, Ty, mówiący, ostatnio mówiący), reszta osób tylko na
 * liście. Pokój łączymy bez automatycznej subskrypcji: dźwięk odbieramy od wszystkich, obraz tylko od osób
 * widocznych w siatce.
 */

const MAX_TILES = 9
const MAX_SCREENS = 2
const DEVICES_KEY = 'supon_call_devices'

type Devices = { audioinput: string; videoinput: string; audiooutput: string }
type JoinSettings = { mic: boolean; camera: boolean; devices: Devices }
type Phase = 'loading' | 'lobby' | 'joining' | 'in-call' | 'over'
type OverReason = 'left' | 'finished' | 'moved' | 'removed' | 'lost'

function errorText(ex: unknown, fallback: string): string {
  return ex instanceof Error && ex.message ? ex.message : fallback
}

function readDevices(): Devices {
  const empty: Devices = { audioinput: '', videoinput: '', audiooutput: '' }
  try {
    const raw = JSON.parse(localStorage.getItem(DEVICES_KEY) ?? 'null') as Partial<Devices> | null
    if (!raw || typeof raw !== 'object') return empty
    return {
      audioinput: typeof raw.audioinput === 'string' ? raw.audioinput : '',
      videoinput: typeof raw.videoinput === 'string' ? raw.videoinput : '',
      audiooutput: typeof raw.audiooutput === 'string' ? raw.audiooutput : '',
    }
  } catch {
    return empty
  }
}

function saveDevices(d: Devices) {
  try {
    localStorage.setItem(DEVICES_KEY, JSON.stringify(d))
  } catch {
    /* bez zapisu — wybór działa do zamknięcia karty */
  }
}

/** Komunikat po polsku dla błędu kamery / mikrofonu z przeglądarki. */
function mediaErrorText(what: 'mic' | 'camera', ex: unknown): string {
  const name = ex instanceof Error ? ex.name : ''
  const device = what === 'mic' ? 'mikrofon' : 'kamerę'
  const Device = what === 'mic' ? 'Mikrofon' : 'Kamera'
  if (name === 'NotAllowedError' || name === 'SecurityError' || name === 'PermissionDeniedError') {
    return `Przeglądarka nie ma zgody na ${device}. Kliknij ikonę kłódki obok adresu strony, zezwól na ${device} i włącz ${what === 'mic' ? 'go' : 'ją'} jeszcze raz.`
  }
  if (name === 'NotFoundError' || name === 'OverconstrainedError' || name === 'DevicesNotFoundError') {
    return `Nie znaleziono urządzenia (${device}). Sprawdź, czy jest podłączone, albo wybierz inne w „Ustawieniach”.`
  }
  if (name === 'NotReadableError' || name === 'TrackStartError' || name === 'AbortError') {
    return `${Device} jest zajęt${what === 'mic' ? 'y' : 'a'} przez inny program (np. Teams albo Zoom). Zamknij go i spróbuj jeszcze raz.`
  }
  return `Nie udało się włączyć: ${device}.`
}

async function listDevices(kind: MediaDeviceKind): Promise<MediaDeviceInfo[]> {
  try {
    const list = await Room.getLocalDevices(kind, false)
    return list.filter((d) => d.deviceId !== '')
  } catch {
    return []
  }
}

// ——— ikony (kształty z makiety czatu) ———

type IconName = 'mic' | 'micoff' | 'video' | 'videooff' | 'screen' | 'hang' | 'hand' | 'people' | 'gear'

function Icon({ name, className = 'h-5 w-5' }: { name: IconName; className?: string }) {
  const paths: Record<IconName, ReactNode> = {
    mic: (
      <>
        <rect x="9" y="3" width="6" height="11" rx="3" />
        <path d="M5 11a7 7 0 0 0 14 0M12 18v3" />
      </>
    ),
    micoff: (
      <path d="M3 3l18 18M9 9v2a3 3 0 0 0 5 2.2M15 9.3V6a3 3 0 0 0-5.7-1.3M5 11a7 7 0 0 0 11.2 5.6M19 11a7 7 0 0 1-.6 2.8M12 18v3" />
    ),
    video: (
      <>
        <rect x="3" y="6" width="12" height="12" rx="2" />
        <path d="M15 10l6-3v10l-6-3z" />
      </>
    ),
    videooff: (
      <>
        <path d="M3 3l18 18" />
        <path d="M15 10l6-3v10l-3-1.5M13 6H5a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2v-1" />
      </>
    ),
    screen: (
      <>
        <rect x="3" y="4" width="18" height="12" rx="2" />
        <path d="M8 20h8M12 16v4M12 13V7M9.5 9.5L12 7l2.5 2.5" />
      </>
    ),
    hang: <path d="M3 14.5c5-5 13-5 18 0l-2.5 2.5-3.5-1.5v-2.5a10 10 0 0 0-6 0v2.5L5.5 17z" />,
    hand: (
      <path d="M8 13V5.5a1.5 1.5 0 0 1 3 0V11M11 10V4.5a1.5 1.5 0 0 1 3 0V11M14 10.5V6a1.5 1.5 0 0 1 3 0v7c0 4-2.5 7-6 7-2.5 0-4-1.2-5.3-3.4L4 13.3a1.5 1.5 0 0 1 2.5-1.6L8 13.5" />
    ),
    people: (
      <>
        <circle cx="9" cy="8" r="3.2" />
        <path d="M3 19a6 6 0 0 1 12 0M16 5.5a3 3 0 0 1 0 5.8M18 19a6 6 0 0 0-3-5.2" />
      </>
    ),
    gear: (
      <>
        <circle cx="12" cy="12" r="3" />
        <path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z" />
      </>
    ),
  }
  return (
    <svg
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth={1.8}
      strokeLinecap="round"
      strokeLinejoin="round"
      className={className}
      aria-hidden="true"
    >
      {paths[name]}
    </svg>
  )
}

// ——— wspólne klocki ———

/** Okrągły przycisk sterowania z podpisem (jak w makiecie). */
function ControlButton({
  label,
  icon,
  onClick,
  tone = 'normal',
  pressed,
  disabled,
}: {
  label: string
  icon: IconName
  onClick: () => void
  tone?: 'normal' | 'off' | 'active' | 'hang'
  pressed?: boolean
  disabled?: boolean
}) {
  const look =
    tone === 'off'
      ? 'border-[#f1f5f9] bg-[#f1f5f9] text-[#0f172a] hover:bg-[#e2e8f0]'
      : tone === 'active'
        ? 'border-sky-600 bg-sky-900 text-[#e0f2fe] hover:bg-sky-800'
        : tone === 'hang'
          ? 'w-16 border-red-600 bg-red-600 text-white hover:bg-red-700'
          : 'border-[#334155] bg-[#1e293b] text-[#e2e8f0] hover:bg-[#334155]'
  return (
    <div className="grid justify-items-center gap-1">
      <button
        type="button"
        onClick={onClick}
        disabled={disabled}
        aria-pressed={pressed}
        aria-label={label}
        title={label}
        className={`grid h-[46px] min-w-[46px] place-items-center rounded-full border disabled:opacity-50 ${look}`}
      >
        <Icon name={icon} />
      </button>
      <span className="max-w-[86px] truncate text-center text-[10.5px] text-[#94a3b8]">{label}</span>
    </div>
  )
}

function DeviceSelect({
  label,
  kind,
  devices,
  value,
  onChange,
  emptyLabel,
}: {
  label: string
  kind: MediaDeviceKind
  devices: MediaDeviceInfo[]
  value: string
  onChange: (kind: MediaDeviceKind, id: string) => void
  emptyLabel: string
}) {
  return (
    <label className="grid gap-1 text-[11.5px] text-[#94a3b8]">
      {label}
      <select
        value={devices.some((d) => d.deviceId === value) ? value : ''}
        onChange={(e) => onChange(kind, e.target.value)}
        className="w-full rounded border border-[#334155] bg-[#0f172a] px-2 py-1.5 text-[12.5px] text-[#e2e8f0]"
      >
        <option value="">{emptyLabel}</option>
        {devices
          .filter((d) => d.deviceId !== 'default')
          .map((d, i) => (
            <option key={d.deviceId} value={d.deviceId}>
              {d.label || `${label} ${i + 1}`}
            </option>
          ))}
      </select>
    </label>
  )
}

type DeviceLists = Record<'audioinput' | 'videoinput' | 'audiooutput', MediaDeviceInfo[]>

function useDeviceLists(refreshKey: unknown): DeviceLists {
  const [lists, setLists] = useState<DeviceLists>({ audioinput: [], videoinput: [], audiooutput: [] })
  useEffect(() => {
    let cancelled = false
    const load = () => {
      void Promise.all([listDevices('audioinput'), listDevices('videoinput'), listDevices('audiooutput')]).then(
        ([audioinput, videoinput, audiooutput]) => {
          if (!cancelled) setLists({ audioinput, videoinput, audiooutput })
        },
      )
    }
    load()
    navigator.mediaDevices?.addEventListener?.('devicechange', load)
    return () => {
      cancelled = true
      navigator.mediaDevices?.removeEventListener?.('devicechange', load)
    }
  }, [refreshKey])
  return lists
}

/** Czy przeglądarka pozwala wybrać głośniki (Chrome, Edge — tak; Firefox, Safari — zwykle nie). */
const canPickSpeaker = typeof HTMLMediaElement !== 'undefined' && 'setSinkId' in HTMLMediaElement.prototype

function Elapsed({ since }: { since: string | null }) {
  const [now, setNow] = useState(() => Date.now())
  useEffect(() => {
    const timer = window.setInterval(() => setNow(Date.now()), 1000)
    return () => window.clearInterval(timer)
  }, [])
  if (!since) return null
  const start = new Date(since).getTime()
  if (Number.isNaN(start)) return null
  const s = Math.max(0, Math.floor((now - start) / 1000))
  const h = Math.floor(s / 3600)
  const m = Math.floor((s % 3600) / 60)
  const sec = s % 60
  const mm = String(m).padStart(h ? 2 : 1, '0')
  return <span>{`${h ? `${h}:` : ''}${mm}:${String(sec).padStart(2, '0')}`}</span>
}

function peopleLabel(n: number): string {
  if (n === 1) return '1 osoba'
  const last = n % 10
  const lastTwo = n % 100
  if (last >= 2 && last <= 4 && (lastTwo < 12 || lastTwo > 14)) return `${n} osoby`
  return `${n} osób`
}

// ——— ekran „Dołącz do rozmowy” ———

function Lobby({
  call,
  title,
  defaults,
  joining,
  joinErr,
  previewRef,
  onJoin,
}: {
  call: Call
  title: string
  defaults: { mic: boolean; camera: boolean }
  joining: boolean
  joinErr: string
  previewRef: RefObject<LocalVideoTrack | null>
  onJoin: (s: JoinSettings) => void
}) {
  const [mic, setMic] = useState(defaults.mic)
  const [camera, setCamera] = useState(defaults.camera)
  const [devices, setDevices] = useState<Devices>(readDevices)
  const [cameraErr, setCameraErr] = useState('')
  const [granted, setGranted] = useState(0)
  const lists = useDeviceLists(granted)
  const videoEl = useRef<HTMLVideoElement>(null)
  const finished = isCallFinished(call.status)

  // Podgląd kamery: nowy przy włączeniu albo zmianie kamery, zatrzymany przy wyłączeniu i wyjściu z ekranu.
  useEffect(() => {
    if (!camera || finished) return
    let cancelled = false
    let track: LocalVideoTrack | null = null
    setCameraErr('')
    createLocalVideoTrack({
      deviceId: devices.videoinput || undefined,
      resolution: VideoPresets.h720.resolution,
    }).then(
      (t) => {
        if (cancelled) {
          t.stop()
          return
        }
        track = t
        previewRef.current = t
        if (videoEl.current) t.attach(videoEl.current)
        setGranted((g) => g + 1)
      },
      (ex: unknown) => {
        if (cancelled) return
        setCameraErr(mediaErrorText('camera', ex))
        setCamera(false)
      },
    )
    return () => {
      cancelled = true
      if (track) {
        track.detach()
        track.stop()
        if (previewRef.current === track) previewRef.current = null
      }
    }
  }, [camera, devices.videoinput, finished, previewRef])

  function pick(kind: MediaDeviceKind, id: string) {
    const next = { ...devices, [kind]: id }
    setDevices(next)
    saveDevices(next)
  }

  const others = call.joined_count
  const largeCallHint =
    !defaults.mic && !defaults.camera
      ? 'W dużej rozmowie mikrofon i kamera są na początku wyłączone. Włączysz je przyciskami w trakcie rozmowy.'
      : !defaults.camera && call.kind === 'video' && others >= 4
        ? 'W większej rozmowie kamera jest na początku wyłączona. Włączysz ją przyciskiem w trakcie rozmowy.'
        : ''
  return (
    <div className="grid min-h-screen place-items-center bg-[#0b1220] p-4 text-[#e2e8f0]">
      <div className="grid w-full max-w-[880px] gap-6 md:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)]">
        <div className="grid gap-3">
          <div className="relative grid aspect-video place-items-center overflow-hidden rounded-lg bg-[#172033]">
            {camera && !finished ? (
              <video ref={videoEl} autoPlay playsInline muted className="h-full w-full -scale-x-100 object-cover" />
            ) : (
              <span className="text-sm text-[#94a3b8]">Kamera wyłączona</span>
            )}
          </div>
          <div className="flex justify-center gap-3">
            <ControlButton
              label={mic ? 'Mikrofon' : 'Mikrofon wył.'}
              icon={mic ? 'mic' : 'micoff'}
              tone={mic ? 'normal' : 'off'}
              pressed={mic}
              disabled={finished}
              onClick={() => setMic((v) => !v)}
            />
            <ControlButton
              label={camera ? 'Kamera' : 'Kamera wył.'}
              icon={camera ? 'video' : 'videooff'}
              tone={camera ? 'normal' : 'off'}
              pressed={camera}
              disabled={finished}
              onClick={() => setCamera((v) => !v)}
            />
          </div>
          {cameraErr && <p className="rounded bg-[#450a0a] px-3 py-2 text-xs text-[#fecaca]">{cameraErr}</p>}
        </div>

        <div className="grid content-start gap-3">
          <div>
            <p className="text-[11px] uppercase tracking-wide text-[#64748b]">
              {call.kind === 'video' ? 'Rozmowa wideo' : 'Rozmowa głosowa'}
            </p>
            <h1 className="text-xl font-semibold text-[#f1f5f9]">{title}</h1>
            <p className="mt-1 text-sm text-[#94a3b8]">
              {finished
                ? 'Ta rozmowa już się zakończyła.'
                : others > 0
                  ? `W rozmowie: ${peopleLabel(others)}.`
                  : 'Nikt jeszcze nie dołączył.'}
            </p>
          </div>

          {!finished && (
            <>
              <div className="grid gap-2.5 rounded-lg border border-[#1e293b] bg-[#0f172a] p-3">
                <DeviceSelect
                  label="Mikrofon"
                  kind="audioinput"
                  devices={lists.audioinput}
                  value={devices.audioinput}
                  onChange={pick}
                  emptyLabel="Domyślny mikrofon"
                />
                <DeviceSelect
                  label="Kamera"
                  kind="videoinput"
                  devices={lists.videoinput}
                  value={devices.videoinput}
                  onChange={pick}
                  emptyLabel="Domyślna kamera"
                />
                {canPickSpeaker && (
                  <DeviceSelect
                    label="Głośniki lub słuchawki"
                    kind="audiooutput"
                    devices={lists.audiooutput}
                    value={devices.audiooutput}
                    onChange={pick}
                    emptyLabel="Domyślne głośniki"
                  />
                )}
                {lists.audioinput.length === 0 && (
                  <p className="text-[11px] text-[#64748b]">
                    Nazwy urządzeń pokażą się, gdy przeglądarka dostanie zgodę na mikrofon lub kamerę.
                  </p>
                )}
              </div>
              {largeCallHint && <p className="text-[11.5px] text-[#94a3b8]">{largeCallHint}</p>}
              <button
                type="button"
                disabled={joining}
                onClick={() => onJoin({ mic, camera, devices })}
                className="rounded bg-green-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-green-700 disabled:opacity-60"
              >
                {joining ? 'Łączę…' : 'Dołącz'}
              </button>
            </>
          )}
          {joinErr && <p className="rounded bg-[#450a0a] px-3 py-2 text-xs text-[#fecaca]">{joinErr}</p>}
          <Link
            to={`/czat?c=${call.conversation_id}`}
            className="justify-self-start text-xs font-medium text-[#7dd3fc] hover:underline"
          >
            ← Wróć do czatu
          </Link>
        </div>
      </div>
    </div>
  )
}

// ——— rozmowa ———

type RoomEmitter = {
  on: (event: string, listener: () => void) => unknown
  off: (event: string, listener: () => void) => unknown
}

const RENDER_EVENTS: string[] = [
  RoomEvent.ParticipantConnected,
  RoomEvent.ParticipantDisconnected,
  RoomEvent.TrackPublished,
  RoomEvent.TrackUnpublished,
  RoomEvent.TrackSubscribed,
  RoomEvent.TrackUnsubscribed,
  RoomEvent.TrackMuted,
  RoomEvent.TrackUnmuted,
  RoomEvent.LocalTrackPublished,
  RoomEvent.LocalTrackUnpublished,
  RoomEvent.ActiveSpeakersChanged,
  RoomEvent.ParticipantAttributesChanged,
  RoomEvent.ConnectionStateChanged,
  RoomEvent.AudioPlaybackStatusChanged,
  RoomEvent.TrackSubscriptionStatusChanged,
]

/** LiveKit trzyma stan w obiektach zmienianych w miejscu — każdy sygnał pokoju podbija licznik i rysuje widok od nowa. */
function useRoomTick(room: Room): number {
  const [tick, setTick] = useState(0)
  useEffect(() => {
    const emitter = room as unknown as RoomEmitter
    const bump = () => setTick((t) => t + 1)
    for (const e of RENDER_EVENTS) emitter.on(e, bump)
    return () => {
      for (const e of RENDER_EVENTS) emitter.off(e, bump)
    }
  }, [room])
  return tick
}

function VideoView({ track, mirror, contain }: { track: Track; mirror?: boolean; contain?: boolean }) {
  const ref = useRef<HTMLVideoElement>(null)
  useEffect(() => {
    const el = ref.current
    if (!el) return
    track.attach(el)
    return () => {
      track.detach(el)
    }
  }, [track])
  return (
    <video
      ref={ref}
      autoPlay
      playsInline
      muted
      className={`absolute inset-0 h-full w-full ${contain ? 'object-contain' : 'object-cover'} ${mirror ? '-scale-x-100' : ''}`}
    />
  )
}

function AudioView({ track }: { track: Track }) {
  const ref = useRef<HTMLAudioElement>(null)
  useEffect(() => {
    const el = ref.current
    if (!el) return
    track.attach(el)
    return () => {
      track.detach(el)
    }
  }, [track])
  return <audio ref={ref} autoPlay />
}

function userIdOf(p: Participant): number | null {
  const n = Number(p.identity)
  return Number.isInteger(n) && n > 0 ? n : null
}

function handRaised(p: Participant): boolean {
  return p.attributes?.hand === '1'
}

function micMuted(p: Participant): boolean {
  const pub = p.getTrackPublication(Track.Source.Microphone)
  return !pub || pub.isMuted
}

function cameraTrack(p: Participant): Track | null {
  const pub = p.getTrackPublication(Track.Source.Camera)
  return pub && !pub.isMuted && pub.track ? pub.track : null
}

function screenTrack(p: Participant): Track | null {
  const pub = p.getTrackPublication(Track.Source.ScreenShare)
  return pub && !pub.isMuted && pub.track ? pub.track : null
}

function hasScreen(p: Participant): boolean {
  return Boolean(p.getTrackPublication(Track.Source.ScreenShare))
}

/** Ważność do siatki: mówi teraz > mówił niedawno > ma kamerę > dołączył wcześniej. */
function speakScore(p: Participant): number {
  if (p.isSpeaking) return Number.MAX_SAFE_INTEGER
  return p.lastSpokeAt?.getTime() ?? 0
}

function byImportance(a: Participant, b: Participant): number {
  return (
    speakScore(b) - speakScore(a) ||
    Number(Boolean(b.getTrackPublication(Track.Source.Camera))) -
      Number(Boolean(a.getTrackPublication(Track.Source.Camera))) ||
    (a.joinedAt?.getTime() ?? 0) - (b.joinedAt?.getTime() ?? 0)
  )
}

/**
 * Osoby w siatce bez przeskakiwania kafelków: zostają te same miejsca, nowe osoby dochodzą na wolne,
 * a ktoś mówiący spoza siatki zastępuje osobę, która najdawniej mówiła.
 */
function pickGrid(prev: string[], remotes: RemoteParticipant[], capacity: number): string[] {
  if (capacity <= 0) return []
  const byId = new Map(remotes.map((p) => [p.identity, p]))
  const grid = prev.filter((id) => byId.has(id)).slice(0, capacity)
  const outside = remotes.filter((p) => !grid.includes(p.identity)).sort(byImportance)
  while (grid.length < capacity && outside.length > 0) {
    const next = outside.shift()
    if (next) grid.push(next.identity)
  }
  for (const speaker of outside.filter((p) => p.isSpeaking)) {
    let victim = -1
    let victimScore = Number.MAX_SAFE_INTEGER
    grid.forEach((id, i) => {
      const p = byId.get(id)
      if (!p || p.isSpeaking) return
      const s = speakScore(p)
      if (s < victimScore) {
        victimScore = s
        victim = i
      }
    })
    if (victim < 0) break
    grid[victim] = speaker.identity
  }
  return grid
}

function Tile({
  participant,
  name,
  isLocal,
}: {
  participant: Participant
  name: string
  isLocal: boolean
}) {
  const video = cameraTrack(participant)
  const muted = micMuted(participant)
  const uid = userIdOf(participant)
  return (
    <div
      className={`relative grid min-h-[110px] place-items-center overflow-hidden rounded-lg bg-[#172033] ${
        participant.isSpeaking ? 'outline outline-2 -outline-offset-2 outline-[#22c55e]' : ''
      }`}
    >
      {video ? (
        <VideoView track={video} mirror={isLocal} />
      ) : (
        <span
          className={`grid h-[74px] w-[74px] place-items-center rounded-full text-2xl font-semibold ${avatarColor(uid)}`}
          aria-hidden="true"
        >
          {initials(name)}
        </span>
      )}
      <span className="absolute bottom-2 left-2 flex max-w-[calc(100%-16px)] items-center gap-1.5 rounded bg-[#020617]/70 px-2 py-0.5 text-[11.5px] text-[#e2e8f0]">
        <Icon name={muted ? 'micoff' : 'mic'} className={`h-3 w-3 shrink-0 ${muted ? 'text-[#f87171]' : ''}`} />
        <span className="truncate">{isLocal ? 'Ty' : name}</span>
      </span>
      {handRaised(participant) && (
        <span
          className="absolute right-2 top-2 flex items-center gap-1 rounded bg-amber-400 px-1.5 py-0.5 text-[11px] font-semibold text-[#0f172a]"
          title="Podniesiona ręka"
        >
          <Icon name="hand" className="h-3.5 w-3.5" />
          Ręka
        </span>
      )}
    </div>
  )
}

function ScreenTile({
  participant,
  name,
  isLocal,
  onStop,
}: {
  participant: Participant
  name: string
  isLocal: boolean
  onStop: () => void
}) {
  const track = screenTrack(participant)
  return (
    <div
      className={`relative grid min-h-[160px] place-items-center overflow-hidden rounded-lg bg-[#020617] ${
        participant.isSpeaking ? 'outline outline-2 -outline-offset-2 outline-[#22c55e]' : ''
      }`}
    >
      {isLocal ? (
        // Własnego ekranu nie pokazujemy (obraz w obrazie bez końca) — tylko informacja i przycisk.
        <div className="grid justify-items-center gap-2 p-4 text-center text-sm text-[#cbd5e1]">
          <Icon name="screen" className="h-8 w-8" />
          Pokazujesz swój ekran pozostałym osobom.
          <button
            type="button"
            onClick={onStop}
            className="rounded bg-red-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-red-700"
          >
            Przestań pokazywać
          </button>
        </div>
      ) : track ? (
        <VideoView track={track} contain />
      ) : (
        <span className="text-sm text-[#94a3b8]">Wczytuję obraz ekranu…</span>
      )}
      <span className="absolute bottom-2 left-2 flex max-w-[calc(100%-16px)] items-center gap-1.5 rounded bg-[#020617]/70 px-2 py-0.5 text-[11.5px] text-[#e2e8f0]">
        <Icon name="screen" className="h-3 w-3 shrink-0" />
        <span className="truncate">{isLocal ? 'Twój ekran' : `${name} pokazuje ekran`}</span>
      </span>
    </div>
  )
}

function InCall({
  room,
  call,
  title,
  names,
  onUnknownPerson,
  onHangUp,
}: {
  room: Room
  call: Call
  title: string
  names: Map<string, string>
  onUnknownPerson: () => void
  onHangUp: () => void
}) {
  const tick = useRoomTick(room)
  const [grid, setGrid] = useState<string[]>([])
  const [busy, setBusy] = useState<'mic' | 'camera' | 'screen' | 'hand' | null>(null)
  const [mediaErr, setMediaErr] = useState('')
  const [panel, setPanel] = useState<'people' | 'settings' | null>(null)
  const [devices, setDevices] = useState<Devices>(readDevices)
  const lists = useDeviceLists(panel === 'settings' ? 'open' : 'closed')

  const local = room.localParticipant
  const remotes = [...room.remoteParticipants.values()]
  const nameOf = (p: Participant) => (p === local ? 'Ty' : (names.get(p.identity) ?? 'Uczestnik'))

  // Ktoś spoza listy osób rozmowy — CallPage dociąga świeżą listę (imiona tylko z serwera, nie z LiveKit).
  const unknownKey = remotes
    .filter((p) => !names.has(p.identity))
    .map((p) => p.identity)
    .join(',')
  useEffect(() => {
    if (unknownKey) onUnknownPerson()
  }, [unknownKey, onUnknownPerson])

  const screens = [local, ...remotes].filter(hasScreen).slice(0, MAX_SCREENS)
  const screenIds = screens.map((p) => p.identity)
  const capacity = MAX_TILES - screens.length - 1
  // tick = sygnał zmiany obiektów pokoju (osoby, mówienie, ścieżki) — po nim siatka wybierana jest od nowa.
  useEffect(() => {
    setGrid((prev) => {
      const next = pickGrid(prev, [...room.remoteParticipants.values()], capacity)
      return next.length === prev.length && next.every((x, i) => x === prev[i]) ? prev : next
    })
  }, [room, tick, capacity])
  const gridKey = grid.join(',')
  const screenKey = screenIds.join(',')

  // Subskrypcje: dźwięk od wszystkich, obraz kamery tylko z siatki, obraz ekranu tylko pokazywanych ekranów.
  useEffect(() => {
    const inGrid = new Set(gridKey ? gridKey.split(',') : [])
    const shownScreens = new Set(screenKey ? screenKey.split(',') : [])
    for (const p of room.remoteParticipants.values()) {
      for (const pub of p.trackPublications.values()) {
        let want: boolean
        if (pub.kind === Track.Kind.Audio) want = true
        else if (pub.source === Track.Source.ScreenShare) want = shownScreens.has(p.identity)
        else want = inGrid.has(p.identity)
        if (pub.isDesired !== want) pub.setSubscribed(want)
      }
    }
  }, [room, tick, gridKey, screenKey])

  const remoteById = new Map(remotes.map((p) => [p.identity, p]))
  const gridPeople = grid.map((id) => remoteById.get(id)).filter((p): p is RemoteParticipant => Boolean(p))
  const tiles: Participant[] = [...gridPeople, local]

  const reconnecting =
    room.state === ConnectionState.Reconnecting || room.state === ConnectionState.SignalReconnecting
  const audioBlocked = !room.canPlaybackAudio
  const micOn = local.isMicrophoneEnabled
  const cameraOn = local.isCameraEnabled
  const screenOn = local.isScreenShareEnabled
  const hand = handRaised(local)
  const total = remotes.length + 1

  async function run(kind: 'mic' | 'camera' | 'screen' | 'hand', action: () => Promise<unknown>) {
    setBusy(kind)
    setMediaErr('')
    try {
      await action()
    } catch (ex) {
      if (kind === 'mic' || kind === 'camera') setMediaErr(mediaErrorText(kind, ex))
      else if (kind === 'hand') setMediaErr('Nie udało się zmienić podniesionej ręki. Spróbuj jeszcze raz.')
      // ekran: anulowanie wyboru okna to też błąd przeglądarki — nic nie pokazujemy
    } finally {
      setBusy(null)
    }
  }

  const toggleMic = () => void run('mic', () => local.setMicrophoneEnabled(!micOn))
  const toggleCamera = () => void run('camera', () => local.setCameraEnabled(!cameraOn))
  const toggleScreen = () =>
    void run('screen', () =>
      local.setScreenShareEnabled(!screenOn, { audio: true, selfBrowserSurface: 'exclude', systemAudio: 'exclude' }),
    )
  const toggleHand = () => void run('hand', () => local.setAttributes({ hand: hand ? '' : '1' }))

  function pickDevice(kind: MediaDeviceKind, id: string) {
    const next = { ...devices, [kind]: id }
    setDevices(next)
    saveDevices(next)
    // „Domyślny” = urządzenie systemowe; przeglądarka zna je pod id „default” tylko dla dźwięku.
    const target = id || (kind === 'videoinput' ? '' : 'default')
    if (target) {
      room.switchActiveDevice(kind, target).catch((ex: unknown) => {
        setMediaErr(kind === 'audiooutput' ? 'Nie udało się przełączyć głośników.' : mediaErrorText(kind === 'audioinput' ? 'mic' : 'camera', ex))
      })
    }
  }

  // Lista osób: Ty, potem podniesione ręce, potem alfabetycznie; na końcu zaproszeni, którzy nie dołączyli.
  const listed = [...remotes].sort(
    (a, b) => Number(handRaised(b)) - Number(handRaised(a)) || nameOf(a).localeCompare(nameOf(b), 'pl'),
  )
  const inRoom = new Set([local.identity, ...remotes.map((p) => p.identity)])
  const notJoined = call.members.filter((m) => !inRoom.has(String(m.id)) && m.state !== 'left')

  const audioTracks: { key: string; track: Track }[] = []
  for (const p of remotes) {
    for (const pub of p.audioTrackPublications.values()) {
      if (pub.track) audioTracks.push({ key: pub.trackSid, track: pub.track })
    }
  }

  const cols = tiles.length <= 1 ? 1 : tiles.length <= 4 ? 2 : 3

  return (
    <div className="flex h-screen min-h-[480px] bg-[#0b1220] text-[#e2e8f0]">
      {audioTracks.map((a) => (
        <AudioView key={a.key} track={a.track} />
      ))}
      <div className="flex min-w-0 flex-1 flex-col gap-2.5 p-3">
        <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-[12.5px] text-[#94a3b8]">
          <b className="font-semibold text-[#f1f5f9]">{title}</b>
          <span>
            {peopleLabel(total)} · <Elapsed since={call.answered_at ?? call.started_at} />
          </span>
          <Link to={`/czat?c=${call.conversation_id}`} target="_blank" className="text-[#7dd3fc] hover:underline">
            Czat tej rozmowy
          </Link>
          <span className="ml-auto text-[11px]">Rozmowa nie jest nagrywana</span>
        </div>

        {reconnecting && (
          <p className="rounded bg-[#451a03] px-3 py-1.5 text-xs text-[#fde68a]" role="status">
            Połączenie przerwane — łączę ponownie…
          </p>
        )}
        {audioBlocked && (
          <p className="flex flex-wrap items-center gap-2 rounded bg-[#0c4a6e] px-3 py-1.5 text-xs text-[#e0f2fe]" role="status">
            Przeglądarka wstrzymała dźwięk rozmowy.
            <button
              type="button"
              onClick={() => void room.startAudio().catch(() => {})}
              className="rounded bg-sky-600 px-2.5 py-1 font-medium text-white hover:bg-sky-700"
            >
              Włącz dźwięk
            </button>
          </p>
        )}
        {mediaErr && (
          <p className="flex items-start gap-2 rounded bg-[#450a0a] px-3 py-1.5 text-xs text-[#fecaca]" role="alert">
            <span className="flex-1">{mediaErr}</span>
            <button type="button" onClick={() => setMediaErr('')} className="font-medium underline">
              Zamknij
            </button>
          </p>
        )}

        <div
          className={`grid min-h-0 flex-1 gap-2.5 ${
            screens.length > 0 ? 'grid-cols-1 md:grid-cols-[minmax(0,3fr)_minmax(180px,1fr)]' : ''
          }`}
        >
          {screens.length > 0 && (
            <div className="grid min-h-0 gap-2.5" style={{ gridTemplateRows: `repeat(${screens.length}, minmax(0, 1fr))` }}>
              {screens.map((p) => (
                <ScreenTile
                  key={`screen-${p.identity}`}
                  participant={p}
                  name={nameOf(p)}
                  isLocal={p === local}
                  onStop={() => void run('screen', () => local.setScreenShareEnabled(false))}
                />
              ))}
            </div>
          )}
          <div
            className="grid min-h-0 auto-rows-fr gap-2.5 overflow-y-auto"
            style={{ gridTemplateColumns: screens.length > 0 ? '1fr' : `repeat(${cols}, minmax(0, 1fr))` }}
          >
            {tiles.map((p) => (
              <Tile key={p.identity} participant={p} name={nameOf(p)} isLocal={p === local} />
            ))}
          </div>
        </div>

        <div className="flex flex-wrap justify-center gap-3">
          <ControlButton
            label={micOn ? 'Mikrofon' : 'Mikrofon wył.'}
            icon={micOn ? 'mic' : 'micoff'}
            tone={micOn ? 'normal' : 'off'}
            pressed={micOn}
            disabled={busy === 'mic'}
            onClick={toggleMic}
          />
          <ControlButton
            label={cameraOn ? 'Kamera' : 'Kamera wył.'}
            icon={cameraOn ? 'video' : 'videooff'}
            tone={cameraOn ? 'normal' : 'off'}
            pressed={cameraOn}
            disabled={busy === 'camera'}
            onClick={toggleCamera}
          />
          <ControlButton
            label={screenOn ? 'Przestań pokazywać' : 'Pokaż ekran'}
            icon="screen"
            tone={screenOn ? 'active' : 'normal'}
            pressed={screenOn}
            disabled={busy === 'screen'}
            onClick={toggleScreen}
          />
          <ControlButton
            label={hand ? 'Opuść rękę' : 'Podnieś rękę'}
            icon="hand"
            tone={hand ? 'active' : 'normal'}
            pressed={hand}
            disabled={busy === 'hand'}
            onClick={toggleHand}
          />
          <ControlButton
            label={`Osoby (${total})`}
            icon="people"
            tone={panel === 'people' ? 'active' : 'normal'}
            pressed={panel === 'people'}
            onClick={() => setPanel((p) => (p === 'people' ? null : 'people'))}
          />
          <ControlButton
            label="Ustawienia"
            icon="gear"
            tone={panel === 'settings' ? 'active' : 'normal'}
            pressed={panel === 'settings'}
            onClick={() => setPanel((p) => (p === 'settings' ? null : 'settings'))}
          />
          <ControlButton label="Rozłącz" icon="hang" tone="hang" onClick={onHangUp} />
        </div>
      </div>

      {panel && (
        <aside className="fixed inset-y-0 right-0 z-20 flex w-[260px] max-w-[85vw] shrink-0 flex-col border-l border-[#1e293b] bg-[#0f172a] text-[12.5px] md:static md:max-w-none">
          {panel === 'people' ? (
            <>
              <h2 className="px-3 pb-1.5 pt-2.5 text-[10.5px] font-medium uppercase tracking-wide text-[#64748b]">
                W rozmowie ({total})
              </h2>
              <div className="min-h-0 flex-1 overflow-y-auto pb-2">
                {[local, ...listed].map((p) => {
                  const uid = userIdOf(p)
                  const name = nameOf(p)
                  return (
                    <div key={p.identity} className="flex items-center gap-2 px-3 py-1">
                      <span
                        className={`grid h-6 w-6 shrink-0 place-items-center rounded-full text-[10px] font-semibold ${avatarColor(uid)}`}
                        aria-hidden="true"
                      >
                        {p === local ? initials(names.get(p.identity) ?? 'Ty') : initials(name)}
                      </span>
                      <span className="min-w-0 flex-1 truncate">{name}</span>
                      {handRaised(p) && (
                        <span title="Podniesiona ręka" className="text-amber-400">
                          <Icon name="hand" className="h-3.5 w-3.5" />
                        </span>
                      )}
                      {hasScreen(p) && (
                        <span title="Pokazuje ekran" className="text-[#7dd3fc]">
                          <Icon name="screen" className="h-3.5 w-3.5" />
                        </span>
                      )}
                      <span title={p.isCameraEnabled ? 'Kamera włączona' : 'Kamera wyłączona'} className="text-[#64748b]">
                        <Icon name={p.isCameraEnabled ? 'video' : 'videooff'} className="h-3.5 w-3.5" />
                      </span>
                      <span
                        title={micMuted(p) ? 'Wyciszony mikrofon' : 'Mikrofon włączony'}
                        className={micMuted(p) ? 'text-[#f87171]' : 'text-[#22c55e]'}
                      >
                        <Icon name={micMuted(p) ? 'micoff' : 'mic'} className="h-3.5 w-3.5" />
                      </span>
                    </div>
                  )
                })}
                {notJoined.length > 0 && (
                  <>
                    <h3 className="px-3 pb-1 pt-3 text-[10.5px] font-medium uppercase tracking-wide text-[#64748b]">
                      Nie dołączyli ({notJoined.length})
                    </h3>
                    {notJoined.map((m) => (
                      <div key={m.id} className="flex items-center gap-2 px-3 py-1 text-[#64748b]">
                        <span className="min-w-0 flex-1 truncate">{m.name}</span>
                        <span className="text-[11px]">{m.state === 'declined' ? 'odrzucił(a)' : 'jeszcze nie dołączył(a)'}</span>
                      </div>
                    ))}
                  </>
                )}
              </div>
            </>
          ) : (
            <div className="grid content-start gap-3 p-3">
              <h2 className="text-[10.5px] font-medium uppercase tracking-wide text-[#64748b]">Ustawienia</h2>
              <DeviceSelect
                label="Mikrofon"
                kind="audioinput"
                devices={lists.audioinput}
                value={devices.audioinput}
                onChange={pickDevice}
                emptyLabel="Domyślny mikrofon"
              />
              <DeviceSelect
                label="Kamera"
                kind="videoinput"
                devices={lists.videoinput}
                value={devices.videoinput}
                onChange={pickDevice}
                emptyLabel="Domyślna kamera"
              />
              {canPickSpeaker && (
                <DeviceSelect
                  label="Głośniki lub słuchawki"
                  kind="audiooutput"
                  devices={lists.audiooutput}
                  value={devices.audiooutput}
                  onChange={pickDevice}
                  emptyLabel="Domyślne głośniki"
                />
              )}
            </div>
          )}
        </aside>
      )}
    </div>
  )
}

// ——— koniec rozmowy ———

function Over({
  reason,
  call,
  onRejoin,
}: {
  reason: OverReason
  call: Call | null
  onRejoin: () => void
}) {
  const finished = !call || isCallFinished(call.status)
  const title =
    reason === 'finished' || (reason === 'left' && finished)
      ? call?.status === 'missed'
        ? 'Połączenie nieodebrane'
        : 'Rozmowa zakończona'
      : reason === 'left'
        ? 'Rozłączyłeś się'
        : reason === 'moved'
          ? 'Rozmowa przeniesiona'
          : reason === 'removed'
            ? 'Rozłączono Cię z rozmowy'
            : 'Połączenie przerwane'
  const text =
    reason === 'moved'
      ? 'Dołączyłeś do tej rozmowy w innej karcie albo na innym urządzeniu — tutaj zostałeś rozłączony.'
      : reason === 'lost'
        ? 'Nie udało się utrzymać połączenia z serwerem rozmów. Sprawdź internet i dołącz ponownie.'
        : reason === 'finished' && call?.status === 'missed'
          ? 'Nikt nie odebrał albo połączenie zostało odrzucone. Możesz zadzwonić ponownie z czatu.'
          : ''
  return (
    <div className="grid min-h-screen place-items-center bg-[#0b1220] p-4 text-[#e2e8f0]">
      <div className="grid w-full max-w-sm justify-items-center gap-3 text-center">
        <h1 className="text-xl font-semibold text-[#f1f5f9]">{title}</h1>
        {text && <p className="text-sm text-[#94a3b8]">{text}</p>}
        <div className="flex flex-wrap justify-center gap-2">
          {!finished && (
            <button
              type="button"
              onClick={onRejoin}
              className="rounded bg-green-600 px-3 py-2 text-sm font-medium text-white hover:bg-green-700"
            >
              Dołącz ponownie
            </button>
          )}
          <button
            type="button"
            onClick={() => window.close()}
            className="rounded border border-[#334155] bg-[#1e293b] px-3 py-2 text-sm text-[#e2e8f0] hover:bg-[#334155]"
          >
            Zamknij kartę
          </button>
        </div>
        {call && (
          <Link to={`/czat?c=${call.conversation_id}`} className="text-xs font-medium text-[#7dd3fc] hover:underline">
            Przejdź do czatu
          </Link>
        )}
      </div>
    </div>
  )
}

// ——— strona ———

export default function CallPage() {
  const { id } = useParams()
  const callId = Number(id)
  const [params] = useSearchParams()
  const cameraParam = params.get('camera')
  const { user } = useAuth()

  const [phase, setPhase] = useState<Phase>('loading')
  const [call, setCall] = useState<Call | null>(null)
  const [title, setTitle] = useState('')
  const [loadErr, setLoadErr] = useState('')
  const [joinErr, setJoinErr] = useState('')
  const [overReason, setOverReason] = useState<OverReason>('left')
  const [room, setRoom] = useState<Room | null>(null)
  const roomRef = useRef<Room | null>(null)
  const previewRef = useRef<LocalVideoTrack | null>(null)
  const phaseRef = useRef<Phase>('loading')
  const [defaults, setDefaults] = useState<{ mic: boolean; camera: boolean } | null>(null)

  const goPhase = useCallback((p: Phase) => {
    phaseRef.current = p
    setPhase(p)
  }, [])

  const refreshCall = useCallback(async () => {
    try {
      const r = await fetchCall(callId)
      setCall(r.data)
      return r.data
    } catch {
      return null
    }
  }, [callId])

  // Pierwsze wczytanie: rozmowa + nazwa rozmowy czatu (kanał albo osoba).
  useEffect(() => {
    if (!Number.isInteger(callId) || callId <= 0) {
      setLoadErr('Nieprawidłowy adres rozmowy.')
      return
    }
    let cancelled = false
    fetchCall(callId).then(
      (r) => {
        if (cancelled) return
        const c = r.data
        setCall(c)
        // W6: kamera — rozmowa wideo i mniej niż 4 osoby (albo ?camera=1), mikrofon — mniej niż 10 osób.
        const camera = cameraParam === '1' ? true : cameraParam === '0' ? false : c.kind === 'video' && c.joined_count < 4
        setDefaults({ camera, mic: c.joined_count < 10 })
        goPhase('lobby')
        fetchConversation(c.conversation_id).then(
          (conv) => {
            if (!cancelled) setTitle(conv.data.type === 'channel' ? `Kanał „${conv.data.name}”` : conv.data.name)
          },
          () => {},
        )
      },
      (ex: unknown) => {
        if (cancelled) return
        setLoadErr(
          ex instanceof ApiError && ex.status === 404
            ? 'Tej rozmowy nie ma albo nie należysz do rozmowy w czacie, w której się odbywa.'
            : errorText(ex, 'Nie udało się wczytać rozmowy.'),
        )
      },
    )
    return () => {
      cancelled = true
    }
  }, [callId, cameraParam, goPhase])

  useEffect(() => {
    document.title = title ? `Rozmowa · ${title}` : 'Rozmowa'
  }, [title])

  const finish = useCallback(
    (reason: OverReason) => {
      const r = roomRef.current
      roomRef.current = null
      setRoom(null)
      if (r) void r.disconnect()
      setOverReason(reason)
      goPhase('over')
      void refreshCall()
    },
    [goPhase, refreshCall],
  )

  // Zakończenie z serwera (chat.call.updated) i świeże dane po ponownym połączeniu z serwerem zdarzeń.
  useEffect(() => {
    const offs = [
      onRealtime('chat.call.updated', (e) => {
        if (e.call_id !== callId) return
        if (isCallFinished(e.status)) {
          setCall((c) => (c ? { ...c, status: e.status, duration_seconds: e.duration_seconds } : c))
          const p = phaseRef.current
          if (p === 'in-call' || p === 'joining') finish('finished')
        }
        void refreshCall()
      }),
      onRealtime('connected', () => void refreshCall()),
    ]
    return () => offs.forEach((off) => off())
  }, [callId, finish, refreshCall])

  // Zamknięcie karty / przejście dalej — rozłączamy pokój (LiveKit sam zgłasza wyjście serwerowi).
  // `closedRef` zatrzymuje też łączenie w toku: wyjście z ekranu „Dołącz” przed odpowiedzią serwera nie może
  // zostawić w tle pokoju z włączonym mikrofonem.
  const closedRef = useRef(false)
  useEffect(() => {
    // ponowne zamontowanie (StrictMode w trybie deweloperskim) — strona znowu jest otwarta
    closedRef.current = false
    return () => {
      closedRef.current = true
      const r = roomRef.current
      roomRef.current = null
      if (r) void r.disconnect()
    }
  }, [])

  // Dociąganie imion nowych osób najwyżej raz na 3 s (prośba w tym czasie — jeszcze jedno pobranie po przerwie).
  const lastNamesFetch = useRef(0)
  const namesTimer = useRef<number | null>(null)
  const onUnknownPerson = useCallback(() => {
    if (namesTimer.current !== null) return
    const wait = Math.max(0, lastNamesFetch.current + 3000 - Date.now())
    namesTimer.current = window.setTimeout(() => {
      namesTimer.current = null
      lastNamesFetch.current = Date.now()
      void refreshCall()
    }, wait)
  }, [refreshCall])
  useEffect(
    () => () => {
      if (namesTimer.current !== null) window.clearTimeout(namesTimer.current)
    },
    [],
  )

  const names = useMemo(() => {
    const m = new Map<string, string>()
    for (const member of call?.members ?? []) m.set(String(member.id), member.name)
    if (user) m.set(String(user.id), user.name)
    return m
  }, [call, user])

  async function join(s: JoinSettings) {
    setJoinErr('')
    goPhase('joining')
    let res: Awaited<ReturnType<typeof joinCall>>
    try {
      res = await joinCall(callId)
    } catch (ex) {
      if (ex instanceof ApiError && ex.status === 409) {
        finish('finished')
        return
      }
      setJoinErr(
        ex instanceof ApiError && ex.status === 404
          ? 'Tej rozmowy nie ma albo nie masz do niej dostępu.'
          : errorText(ex, 'Nie udało się dołączyć do rozmowy.'),
      )
      goPhase('lobby')
      return
    }
    // W międzyczasie rozmowa się skończyła (chat.call.updated) albo strona została zamknięta — nie łączymy.
    if (closedRef.current || phaseRef.current !== 'joining') return
    setCall(res.data)

    const r = new Room({
      adaptiveStream: true,
      dynacast: true,
      videoCaptureDefaults: {
        deviceId: s.devices.videoinput || undefined,
        resolution: VideoPresets.h720.resolution,
      },
      audioCaptureDefaults: {
        deviceId: s.devices.audioinput || undefined,
        echoCancellation: true,
        noiseSuppression: true,
        autoGainControl: true,
      },
      audioOutput: s.devices.audiooutput ? { deviceId: s.devices.audiooutput } : undefined,
      publishDefaults: {
        simulcast: true,
        videoSimulcastLayers: [VideoPresets.h180, VideoPresets.h360],
      },
    })
    // Nieudane pierwsze łączenie też kończy się zdarzeniem Disconnected — to obsługuje catch niżej (powrót do ekranu dołączania).
    let connected = false
    r.on(RoomEvent.Disconnected, (reason?: DisconnectReason) => {
      if (roomRef.current !== r || !connected) return // sami się rozłączyliśmy, stary pokój albo pierwsze łączenie
      if (reason === DisconnectReason.DUPLICATE_IDENTITY) finish('moved')
      else if (reason === DisconnectReason.ROOM_DELETED || reason === DisconnectReason.ROOM_CLOSED) finish('finished')
      else if (reason === DisconnectReason.PARTICIPANT_REMOVED) finish('removed')
      else finish('lost')
    })
    roomRef.current = r
    try {
      await r.connect(res.join.url, res.join.token, { autoSubscribe: false })
    } catch (ex) {
      if (roomRef.current === r) roomRef.current = null
      void r.disconnect()
      if (phaseRef.current !== 'joining') return
      // Najczęstsza przyczyna: rozmowa skończyła się w trakcie łączenia (nikt nie odebrał, druga osoba się rozłączyła)
      // i serwer zamknął pokój — wtedy mówimy to wprost zamiast ogólnego „nie udało się połączyć”.
      try {
        const now = await fetchCall(callId)
        if (isCallFinished(now.data.status)) {
          setCall(now.data)
          finish('finished')
          return
        }
      } catch {
        /* stan nieznany — zostaje komunikat o połączeniu */
      }
      const detail = ex instanceof Error && ex.message ? ` (${ex.message.slice(0, 120)})` : ''
      console.warn('Rozmowa: łączenie z serwerem rozmów nie powiodło się', ex)
      setJoinErr(`Nie udało się połączyć z serwerem rozmów. Sprawdź internet i spróbuj jeszcze raz.${detail}`)
      goPhase('lobby')
      return
    }
    if (closedRef.current || roomRef.current !== r) {
      void r.disconnect()
      return
    }
    connected = true
    setRoom(r)
    goPhase('in-call')
    // Kliknięcie „Dołącz” to zgoda użytkownika — dźwięk zwykle rusza od razu; jeśli nie, pokaże się „Włącz dźwięk”.
    void r.startAudio().catch(() => {})

    // Podgląd z ekranu dołączania zwalnia kamerę, zanim pokój ją włączy.
    previewRef.current?.stop()
    previewRef.current = null
    const errors: string[] = []
    if (s.mic && !closedRef.current && roomRef.current === r) {
      try {
        await r.localParticipant.setMicrophoneEnabled(true)
      } catch (ex) {
        errors.push(mediaErrorText('mic', ex))
      }
    }
    if (s.camera && !closedRef.current && roomRef.current === r) {
      try {
        await r.localParticipant.setCameraEnabled(true)
      } catch (ex) {
        errors.push(mediaErrorText('camera', ex))
      }
    }
    if (errors.length > 0) setJoinErr(errors.join(' '))
  }

  function hangUp() {
    const r = roomRef.current
    roomRef.current = null
    setRoom(null)
    // Jawne wyjście: serwer kończy rozmowę 1:1 (i grupową, gdy nikt nie został).
    void leaveCall(callId)
      .then((res) => setCall(res.data))
      .catch(() => {})
    if (r) void r.disconnect()
    setOverReason('left')
    goPhase('over')
  }

  if (loadErr) {
    return (
      <div className="grid min-h-screen place-items-center bg-[#0b1220] p-4 text-center text-[#e2e8f0]">
        <div className="grid justify-items-center gap-3">
          <p className="text-sm">{loadErr}</p>
          <Link to="/czat" className="text-xs font-medium text-[#7dd3fc] hover:underline">
            Przejdź do czatu
          </Link>
        </div>
      </div>
    )
  }

  if (phase === 'loading' || !call || !defaults) {
    return <p className="min-h-screen bg-[#0b1220] p-8 text-sm text-[#94a3b8]">Ładowanie rozmowy…</p>
  }

  if (phase === 'over') {
    return (
      <Over
        reason={overReason}
        call={call}
        onRejoin={() => {
          setJoinErr('')
          goPhase('lobby')
        }}
      />
    )
  }

  if (phase === 'in-call' && room) {
    return (
      <div className="relative">
        <InCall
          room={room}
          call={call}
          title={title || 'Rozmowa'}
          names={names}
          onUnknownPerson={onUnknownPerson}
          onHangUp={hangUp}
        />
        {joinErr && (
          <p
            className="fixed bottom-24 left-1/2 z-10 flex max-w-[90vw] -translate-x-1/2 items-start gap-2 rounded bg-[#450a0a] px-3 py-2 text-xs text-[#fecaca] shadow-lg"
            role="alert"
          >
            <span>{joinErr}</span>
            <button type="button" onClick={() => setJoinErr('')} className="font-medium underline">
              Zamknij
            </button>
          </p>
        )}
      </div>
    )
  }

  return (
    <Lobby
      call={call}
      title={title || 'Rozmowa'}
      defaults={defaults}
      joining={phase === 'joining'}
      joinErr={joinErr}
      previewRef={previewRef}
      onJoin={(s) => void join(s)}
    />
  )
}
