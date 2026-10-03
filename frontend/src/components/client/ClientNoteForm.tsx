import { useId, useMemo, useState, type FormEvent } from 'react'
import type { ClientNoteInput } from '../../lib/api'
import { errorText, fmtDate } from '../../lib/campaignFormat'
import { addDays, polishToday } from './clientText'
import { useUnsavedGuard } from './useUnsavedGuard'

type ReminderMode = 'none' | 'week' | 'date'

const BODY_MAX = 5000

/**
 * Formularz notatki o kliencie (nowa albo edycja). Przypomnienie: nie przypominaj / za tydzień / w wybrany dzień —
 * przypomina autorowi notatki aplikacja (dzwonek i e-mail według ustawień w „Moje konto”), od 7:00 w wybranym dniu.
 * Przy edycji wysyła tylko zmienione pola — dawny dzień przypomnienia (już miniony) zostaje nietknięty.
 */
export function ClientNoteForm({
  initial,
  submitLabel,
  onSubmit,
  onCancel,
  compact = false,
}: {
  /** edycja: obecna treść i dzień przypomnienia; brak — nowa notatka */
  initial?: { body: string; remind_on: string | null }
  submitLabel: string
  /** przy edycji tylko zmienione pola; rzuca błąd API */
  onSubmit: (input: Partial<ClientNoteInput>) => Promise<void>
  onCancel?: () => void
  compact?: boolean
}) {
  const ids = useId()
  const today = polishToday()
  const weekLater = addDays(today, 7)
  const [body, setBody] = useState(initial?.body ?? '')
  const [mode, setMode] = useState<ReminderMode>(initial?.remind_on ? 'date' : 'none')
  const [date, setDate] = useState(initial?.remind_on ?? weekLater)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')

  const remindOn = mode === 'none' ? null : mode === 'week' ? weekLater : date || null
  const changes = useMemo(() => {
    const out: Partial<ClientNoteInput> = {}
    if (!initial || body !== initial.body) out.body = body
    if (!initial || remindOn !== initial.remind_on) out.remind_on = remindOn
    return out
  }, [initial, body, remindOn])
  const dirty = initial ? Object.keys(changes).length > 0 : body.trim() !== ''
  useUnsavedGuard(dirty, 'Notatka o kliencie nie jest zapisana. Opuścić stronę bez zapisu? Tekst przepadnie.')

  // dzień z przeszłości tylko wtedy, gdy to niezmienione dawne przypomnienie
  const pastDate = mode === 'date' && date !== '' && date < today && date !== initial?.remind_on
  const invalid = body.trim() === '' || body.length > BODY_MAX || (mode === 'date' && date === '') || pastDate

  async function submit(e: FormEvent) {
    e.preventDefault()
    if (invalid || busy) return
    if (initial && Object.keys(changes).length === 0) {
      onCancel?.()
      return
    }
    setBusy(true)
    setError('')
    try {
      await onSubmit(changes)
      if (!initial) {
        setBody('')
        setMode('none')
        setDate(weekLater)
      }
    } catch (ex) {
      setError(errorText(ex, 'Nie udało się zapisać notatki.'))
    } finally {
      setBusy(false)
    }
  }

  return (
    <form onSubmit={submit} noValidate className="space-y-2 text-xs">
      <div>
        <label htmlFor={`${ids}-body`} className={compact ? 'sr-only' : 'mb-1 block text-slate-600'}>
          Treść notatki
        </label>
        <textarea
          id={`${ids}-body`}
          value={body}
          onChange={(e) => setBody(e.target.value)}
          rows={compact ? 3 : 4}
          maxLength={BODY_MAX}
          placeholder="Na przykład: ustalenia z rozmowy, planowane zakupy, kto decyduje"
          className="w-full rounded border border-slate-300 px-2 py-1.5 text-sm"
        />
      </div>
      <div className="flex flex-wrap items-end gap-2">
        <div>
          <label htmlFor={`${ids}-mode`} className="mb-1 block text-slate-600">
            Przypomnij mi
          </label>
          <select
            id={`${ids}-mode`}
            value={mode}
            onChange={(e) => setMode(e.target.value as ReminderMode)}
            className="rounded border border-slate-300 px-2 py-1.5"
          >
            <option value="none">nie przypominaj</option>
            <option value="week">za tydzień ({fmtDate(weekLater)})</option>
            <option value="date">w wybrany dzień</option>
          </select>
        </div>
        {mode === 'date' && (
          <div>
            <label htmlFor={`${ids}-date`} className="mb-1 block text-slate-600">
              Dzień przypomnienia
            </label>
            <input
              id={`${ids}-date`}
              type="date"
              value={date}
              min={today}
              onChange={(e) => setDate(e.target.value)}
              className="rounded border border-slate-300 px-2 py-1"
            />
          </div>
        )}
      </div>
      {mode !== 'none' && (
        <p className="text-slate-500">Przypomnienie przyjdzie do Ciebie jako autora notatki w wybranym dniu od 7:00 (dzwonek i e-mail według ustawień w „Moje konto”).</p>
      )}
      {pastDate && <p className="text-red-700">Dzień przypomnienia nie może być w przeszłości.</p>}
      {error && <p className="rounded bg-red-50 px-2 py-1 text-red-700">{error}</p>}
      <div className="flex justify-end gap-2">
        {onCancel && (
          <button type="button" onClick={onCancel} className="rounded border border-slate-300 px-3 py-1.5 hover:bg-slate-50">
            Anuluj
          </button>
        )}
        <button type="submit" disabled={invalid || busy} className="rounded bg-blue-600 px-3 py-1.5 text-white hover:bg-blue-700 disabled:opacity-50">
          {busy ? 'Zapisuję…' : submitLabel}
        </button>
      </div>
    </form>
  )
}
