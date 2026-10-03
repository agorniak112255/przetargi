import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import {
  fetchTenderCalendar,
  type TenderCalendarEvent,
  type TenderCalendarFilter,
  type TenderCalendarResponse,
  type TenderCalendarState,
} from '../../lib/api'
import { formatDeadline } from '../../lib/tenderDeadline'
import { tenderStatusLabel } from '../../lib/tenderStatus'

const DAY_NAMES = ['Poniedziałek', 'Wtorek', 'Środa', 'Czwartek', 'Piątek', 'Sobota', 'Niedziela']

const MONTH_NAMES = [
  'Styczeń',
  'Luty',
  'Marzec',
  'Kwiecień',
  'Maj',
  'Czerwiec',
  'Lipiec',
  'Sierpień',
  'Wrzesień',
  'Październik',
  'Listopad',
  'Grudzień',
]

/** Kolory jak w makiecie (ekran 4): niebieski — gotowe, bursztyn — w toku, czerwony — braki blisko terminu. */
const STATE_CLASS: Record<TenderCalendarState, string> = {
  ready: 'border-l-4 border-blue-500 bg-blue-50 text-blue-900',
  in_progress: 'border-l-4 border-amber-500 bg-amber-50 text-amber-900',
  urgent: 'border-l-4 border-red-500 bg-red-50 text-red-800',
  result_needed: 'border border-l-4 border-dashed border-slate-500 bg-white font-semibold text-slate-900',
  closed: 'border-l-4 border-slate-300 bg-slate-100 text-slate-600',
}

const STATE_LABEL: Record<TenderCalendarState, string> = {
  ready: 'wycena gotowa',
  in_progress: 'wycena w toku',
  urgent: 'braki, termin blisko',
  result_needed: 'do zrobienia: wpisz wynik',
  closed: 'zamknięte',
}

/** Dłuższy opis stanu do podpowiedzi przy legendzie. */
const STATE_HINT: Record<TenderCalendarState, string> = {
  ready: 'Każda pozycja ma produkt i cenę.',
  in_progress: 'Są pozycje bez produktu albo bez ceny, a termin jest dalej niż za 3 dni.',
  urgent: 'Są pozycje bez produktu albo bez ceny (albo nie ma żadnej pozycji), a termin jest dziś lub w ciągu 3 dni.',
  result_needed: 'Termin minął (najwyżej 60 dni temu), a wynik przetargu nie jest wpisany.',
  closed: 'Oferta wysłana, przetarg w archiwum, odrzucony albo z wpisanym wynikiem.',
}

const LEGEND_ORDER: TenderCalendarState[] = ['ready', 'in_progress', 'urgent', 'result_needed', 'closed']

/** Miesiąc „RRRR-MM” z adresu albo bieżący (dzień z zegara przeglądarki — ta sama data co w Polsce poza nocą). */
function monthFromParam(value: string | null): { year: number; month: number } {
  const m = /^(\d{4})-(\d{2})$/.exec(value ?? '')
  if (m) {
    const month = Number(m[2])
    if (month >= 1 && month <= 12) return { year: Number(m[1]), month }
  }
  const now = new Date()
  return { year: now.getFullYear(), month: now.getMonth() + 1 }
}

function monthKey(year: number, month: number): string {
  return `${year}-${String(month).padStart(2, '0')}`
}

/** Daty jako dni kalendarza w UTC — bez przesunięć przy zmianie czasu. */
function isoDay(d: Date): string {
  return d.toISOString().slice(0, 10)
}

function addDays(d: Date, days: number): Date {
  return new Date(d.getTime() + days * 86400000)
}

/** Siatka miesiąca od poniedziałku: pełne tygodnie (5 albo 6), najwyżej 42 dni — w limicie 62 dni API. */
function monthGrid(year: number, month: number): { from: string; to: string; days: string[] } {
  const first = new Date(Date.UTC(year, month - 1, 1))
  const offset = (first.getUTCDay() + 6) % 7
  const daysInMonth = new Date(Date.UTC(year, month, 0)).getUTCDate()
  const weeks = Math.ceil((offset + daysInMonth) / 7)
  const start = addDays(first, -offset)
  const days = Array.from({ length: weeks * 7 }, (_, i) => isoDay(addDays(start, i)))
  return { from: days[0], to: days[days.length - 1], days }
}

function plural(n: number, one: string, few: string, many: string): string {
  if (n === 1) return one
  const d = n % 10
  const t = n % 100
  return d >= 2 && d <= 4 && (t < 12 || t > 14) ? few : many
}

/** Podpowiedź przy zdarzeniu: numer, tytuł, zamawiający, termin, status i braki. */
function eventTitle(e: TenderCalendarEvent): string {
  const lines = [`${e.number}${e.notice_number ? ` · ogłoszenie ${e.notice_number}` : ''}`, e.title]
  if (e.client) lines.push(`Zamawiający: ${e.client}`)
  lines.push(`Termin składania ofert: ${formatDeadline(e.date, e.time)}`)
  lines.push(`Status: ${tenderStatusLabel(e.status)} — ${STATE_LABEL[e.state]}`)
  if (e.state !== 'closed' && e.state !== 'result_needed') {
    const gaps: string[] = []
    if (e.missing.items === 0) gaps.push('brak pozycji')
    if (e.missing.without_product > 0)
      gaps.push(`${e.missing.without_product} ${plural(e.missing.without_product, 'pozycja', 'pozycje', 'pozycji')} bez produktu`)
    if (e.missing.without_price > 0)
      gaps.push(`${e.missing.without_price} ${plural(e.missing.without_price, 'pozycja', 'pozycje', 'pozycji')} bez ceny`)
    if (gaps.length > 0) lines.push(`Braki: ${gaps.join(', ')}`)
  }
  return lines.join('\n')
}

/**
 * Kalendarz terminów składania ofert (widok miesiąca, ekran 4 makiety) — GET /tenders/calendar; filter jak na liście
 * przetargów. Miesiąc w adresie (?miesiac=RRRR-MM), więc „Wstecz” po otwarciu przetargu wraca do tego samego miesiąca.
 */
export function TenderCalendar({ filter }: { filter: TenderCalendarFilter }) {
  const [params, setParams] = useSearchParams()
  const { year, month } = monthFromParam(params.get('miesiac'))
  const grid = useMemo(() => monthGrid(year, month), [year, month])
  const [data, setData] = useState<TenderCalendarResponse | null>(null)
  const [loading, setLoading] = useState(true)
  const [err, setErr] = useState('')
  const [reload, setReload] = useState(0)
  const requestRef = useRef(0)

  useEffect(() => {
    const controller = new AbortController()
    const id = ++requestRef.current
    setLoading(true)
    setErr('')
    fetchTenderCalendar({ from: grid.from, to: grid.to, filter }, controller.signal)
      .then((res) => {
        if (requestRef.current !== id) return
        setData(res)
      })
      .catch((ex: unknown) => {
        if (controller.signal.aborted || requestRef.current !== id) return
        setErr(ex instanceof Error ? ex.message : 'Nie udało się pobrać kalendarza.')
      })
      .finally(() => {
        if (requestRef.current === id) setLoading(false)
      })
    return () => controller.abort()
  }, [grid.from, grid.to, filter, reload])

  const goMonth = useCallback(
    (delta: number) => {
      const d = new Date(Date.UTC(year, month - 1 + delta, 1))
      setParams(
        (prev) => {
          const next = new URLSearchParams(prev)
          next.set('miesiac', monthKey(d.getUTCFullYear(), d.getUTCMonth() + 1))
          return next
        },
        { replace: true },
      )
    },
    [year, month, setParams],
  )

  const goToday = useCallback(() => {
    setParams(
      (prev) => {
        const next = new URLSearchParams(prev)
        next.delete('miesiac')
        return next
      },
      { replace: true },
    )
  }, [setParams])

  // zdarzenia tylko z bieżącej odpowiedzi dla tej siatki (stare dane z innego miesiąca nie mieszają się z nową siatką)
  const fresh = data !== null && data.from === grid.from && data.to === grid.to ? data : null
  const byDay = useMemo(() => {
    const map = new Map<string, TenderCalendarEvent[]>()
    for (const e of fresh?.events ?? []) {
      const list = map.get(e.date) ?? []
      list.push(e)
      map.set(e.date, list)
    }
    return map
  }, [fresh])
  const today = fresh?.today ?? null
  const monthPrefix = monthKey(year, month)
  const inMonth = (fresh?.events ?? []).filter((e) => e.date.startsWith(monthPrefix)).length

  return (
    <div className="app-card rounded-xl bg-white p-4 shadow-sm">
      <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-2">
          <button
            type="button"
            onClick={() => goMonth(-1)}
            aria-label="Poprzedni miesiąc"
            className="rounded border border-slate-300 px-2 py-1 text-sm hover:bg-slate-50"
          >
            ‹
          </button>
          <h2 className="min-w-[10rem] text-center text-base font-semibold" aria-live="polite">
            {MONTH_NAMES[month - 1]} {year}
          </h2>
          <button
            type="button"
            onClick={() => goMonth(1)}
            aria-label="Następny miesiąc"
            className="rounded border border-slate-300 px-2 py-1 text-sm hover:bg-slate-50"
          >
            ›
          </button>
          <button
            type="button"
            onClick={goToday}
            className="rounded border border-slate-300 px-2 py-1 text-xs hover:bg-slate-50"
          >
            Bieżący miesiąc
          </button>
          {loading && <span className="text-xs text-slate-500">Ładowanie…</span>}
        </div>
        <ul className="flex flex-wrap gap-1.5 text-[11px]" aria-label="Legenda kolorów">
          {LEGEND_ORDER.map((s) => (
            <li key={s} className={`rounded px-1.5 py-0.5 ${STATE_CLASS[s]}`} title={STATE_HINT[s]}>
              {STATE_LABEL[s]}
            </li>
          ))}
        </ul>
      </div>

      {err && (
        <p className="mb-2 text-xs text-red-600">
          {err}{' '}
          <button type="button" className="underline" onClick={() => setReload((n) => n + 1)}>
            Spróbuj ponownie
          </button>
        </p>
      )}

      <div className="overflow-x-auto">
        <div className="grid min-w-[760px] grid-cols-7 overflow-hidden rounded-lg border border-slate-200 text-xs">
          {DAY_NAMES.map((d) => (
            <div key={d} className="border-b border-slate-200 bg-slate-50 px-2 py-1.5 font-medium text-slate-600">
              {d}
            </div>
          ))}
          {grid.days.map((day, i) => {
            const outside = !day.startsWith(monthPrefix)
            const isToday = day === today
            const events = byDay.get(day) ?? []
            return (
              <div
                key={day}
                className={`flex min-h-[96px] flex-col gap-1 border-b border-slate-100 p-1.5 ${i % 7 === 6 ? '' : 'border-r'} ${isToday ? 'bg-blue-50' : ''}`}
              >
                <span
                  className={`text-[11px] font-semibold tabular-nums ${isToday ? 'text-blue-700' : outside ? 'text-slate-400' : 'text-slate-500'}`}
                >
                  {Number(day.slice(8, 10))}
                  {isToday ? ' · dziś' : ''}
                </span>
                {events.map((e) => (
                  <Link
                    key={e.tender_id}
                    to={e.url}
                    title={eventTitle(e)}
                    className={`block rounded px-1.5 py-1 leading-tight hover:underline ${STATE_CLASS[e.state]}`}
                  >
                    {e.state === 'result_needed' ? 'Wpisz wynik: ' : ''}
                    {e.time ? <span className="tabular-nums">{e.time} </span> : null}
                    {e.client ?? e.title}
                  </Link>
                ))}
              </div>
            )
          })}
        </div>
      </div>
      <p className="mt-2 text-[11px] text-slate-500">
        {fresh && inMonth === 0 ? 'W tym miesiącu nie ma terminów składania ofert. ' : ''}
        Widać przetargi, które możesz oglądać, z wpisaną datą terminu składania — przetargi bez daty terminu nie
        trafiają do kalendarza. Najedź na przetarg, żeby zobaczyć szczegóły i braki; kliknięcie otwiera przetarg.
      </p>
    </div>
  )
}
