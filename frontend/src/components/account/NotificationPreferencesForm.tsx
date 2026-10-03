import { useEffect, useRef, useState, type FormEvent } from 'react'
import { useLocation } from 'react-router-dom'
import {
  fetchNotificationPreferences,
  saveNotificationPreferences,
  type DeadlineOffset,
  type NotificationEventKey,
  type NotificationPreferences,
} from '../../lib/api'

type Channels = Record<NotificationEventKey, { bell: boolean; mail: boolean }>

function channelsFrom(prefs: NotificationPreferences): Channels {
  const out = {} as Channels
  for (const e of prefs.events) out[e.key] = { bell: e.bell, mail: e.mail }
  return out
}

/**
 * Moje konto › Powiadomienia: co przychodzi do dzwonka, a co także e-mailem, i kiedy przypominać o terminie
 * składania ofert. Lista zdarzeń przychodzi z serwera (tylko te, które działają i dotyczą tej osoby).
 */
export function NotificationPreferencesForm() {
  const location = useLocation()
  const sectionRef = useRef<HTMLFormElement | null>(null)
  const [prefs, setPrefs] = useState<NotificationPreferences | null>(null)
  const [channels, setChannels] = useState<Channels | null>(null)
  const [offsets, setOffsets] = useState<DeadlineOffset[]>([])
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const [msg, setMsg] = useState('')

  useEffect(() => {
    let alive = true
    fetchNotificationPreferences()
      .then((p) => {
        if (!alive) return
        setPrefs(p)
        setChannels(channelsFrom(p))
        setOffsets(p.deadline_offsets)
      })
      .catch((e: unknown) => {
        if (alive) setErr(e instanceof Error ? e.message : 'Nie udało się wczytać ustawień powiadomień.')
      })
    return () => {
      alive = false
    }
  }, [])

  // link „Ustawienia” z dzwonka: /account#powiadomienia — przewiń do sekcji, gdy już się wczytała
  useEffect(() => {
    if (location.hash === '#powiadomienia' && prefs !== null) {
      sectionRef.current?.scrollIntoView({ block: 'start' })
    }
  }, [location.hash, prefs])

  function toggle(key: NotificationEventKey, channel: 'bell' | 'mail') {
    setMsg('')
    setChannels((c) => (c ? { ...c, [key]: { ...c[key], [channel]: !c[key][channel] } } : c))
  }

  function toggleOffset(key: DeadlineOffset) {
    setMsg('')
    setOffsets((list) => (list.includes(key) ? list.filter((k) => k !== key) : [...list, key]))
  }

  async function onSubmit(e: FormEvent) {
    e.preventDefault()
    if (!channels) return
    setBusy(true)
    setErr('')
    setMsg('')
    try {
      const saved = await saveNotificationPreferences({ events: channels, deadline_offsets: offsets })
      setPrefs(saved)
      setChannels(channelsFrom(saved))
      setOffsets(saved.deadline_offsets)
      setMsg('Zapisano. Nowe ustawienia obowiązują od następnego powiadomienia.')
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Nie udało się zapisać ustawień.')
    } finally {
      setBusy(false)
    }
  }

  const deadlineOff = channels !== null && channels.tender_deadline !== undefined
    && !channels.tender_deadline.bell && !channels.tender_deadline.mail

  return (
    <form
      id="powiadomienia"
      ref={sectionRef}
      onSubmit={(e) => void onSubmit(e)}
      className="mb-4 scroll-mt-4 rounded-xl bg-white p-4 shadow-sm"
    >
      <h2 className="mb-1 text-sm font-semibold">Powiadomienia</h2>
      <p className="mb-3 text-xs text-slate-500">
        Zaznacz, gdzie chcesz dostawać informację. Dzwonek jest w menu po lewej, e-mail przychodzi na adres z Twojego
        konta{prefs?.email ? ` (${prefs.email})` : ''}.
      </p>
      {err && <p className="mb-3 rounded bg-red-50 px-3 py-2 text-xs text-red-700">{err}</p>}
      {msg && <p className="mb-3 rounded bg-emerald-50 px-3 py-2 text-xs text-emerald-700">{msg}</p>}
      {prefs !== null && !prefs.mail_configured && (
        <p className="mb-3 rounded bg-amber-50 px-3 py-2 text-xs text-amber-800">
          Poczta wychodząca aplikacji nie jest jeszcze ustawiona, więc e-maile z powiadomieniami nie wyjdą. Wybór
          zapisze się i zacznie działać, gdy administrator ustawi pocztę w Administracji. Dzwonek działa już teraz.
        </p>
      )}

      {prefs === null || channels === null ? (
        !err && <p className="text-xs text-slate-500">Wczytuję ustawienia…</p>
      ) : (
        <>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-200 text-left text-xs text-slate-500">
                  <th className="py-2 pr-3 font-medium">Zdarzenie</th>
                  <th className="w-24 py-2 text-center font-medium">W dzwonku</th>
                  <th className="w-24 py-2 text-center font-medium">E-mailem</th>
                </tr>
              </thead>
              <tbody>
                {prefs.events.map((e) => (
                  <tr key={e.key} className="border-b border-slate-100 align-top last:border-0">
                    <td className="py-2 pr-3">
                      <div className="font-medium text-slate-800">{e.label}</div>
                      {e.description && <div className="text-xs text-slate-500">{e.description}</div>}
                    </td>
                    <td className="py-2 text-center">
                      <input
                        type="checkbox"
                        className="h-4 w-4"
                        checked={channels[e.key]?.bell ?? false}
                        onChange={() => toggle(e.key, 'bell')}
                        aria-label={`${e.label} — w dzwonku`}
                      />
                    </td>
                    <td className="py-2 text-center">
                      <input
                        type="checkbox"
                        className="h-4 w-4"
                        checked={channels[e.key]?.mail ?? false}
                        onChange={() => toggle(e.key, 'mail')}
                        aria-label={`${e.label} — e-mailem`}
                      />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <fieldset className="mt-4" disabled={deadlineOff}>
            <legend className="mb-2 text-xs font-medium text-slate-700">Przypominaj o terminie składania oferty:</legend>
            <div className="flex flex-wrap gap-2">
              {prefs.deadline_offset_options.map((o) => {
                const on = offsets.includes(o.key)
                return (
                  <label
                    key={o.key}
                    className={`inline-flex cursor-pointer items-center gap-1.5 rounded-full border px-3 py-1 text-xs ${
                      on ? 'border-blue-600 bg-blue-50 text-blue-800' : 'border-slate-300 text-slate-600'
                    }`}
                  >
                    <input type="checkbox" checked={on} onChange={() => toggleOffset(o.key)} />
                    {o.label}
                  </label>
                )
              })}
            </div>
            <p className="mt-2 text-xs text-slate-500">
              {deadlineOff
                ? 'Przypomnienia o terminie są wyłączone — zaznacz dzwonek albo e-mail przy terminie składania oferty.'
                : 'Przypomnienia w dniach przed terminem przychodzą od 7:00. Przypomnienie 3 godziny przed działa tylko w przetargach z wpisaną godziną składania. Dni robocze to poniedziałek–piątek; święta nie są uwzględniane.'}
            </p>
          </fieldset>

          <div className="mt-4 flex justify-end">
            <button
              type="submit"
              disabled={busy}
              className="rounded bg-blue-600 px-3 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-50"
            >
              Zapisz powiadomienia
            </button>
          </div>
        </>
      )}
    </form>
  )
}
