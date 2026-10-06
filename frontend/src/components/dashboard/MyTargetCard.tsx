import { useEffect, useState } from 'react'
import { fetchMySalesTarget, type MySalesTarget } from '../../lib/api'
import { plural } from '../../lib/plural'
import { fmtDec, groupInt, NBSP } from '../../lib/reports'
import { NavIcon } from '../NavIcon'

/**
 * Kafelek „Mój cel w tym miesiącu” na Dashboardzie — GET /me/sales-target (fetchMySalesTarget w lib/api); pokazywany
 * tylko, gdy zalogowana osoba ma cel w bieżącym miesiącu (bez celu, przy błędzie i w trakcie wczytywania — nic).
 * Element siatki Dashboardu: cały wiersz.
 */

const MONTHS = ['styczeń', 'luty', 'marzec', 'kwiecień', 'maj', 'czerwiec', 'lipiec', 'sierpień', 'wrzesień', 'październik', 'listopad', 'grudzień']

function zl(value: string): string {
  const n = Number(value)
  return Number.isFinite(n) ? `${groupInt(n)}${NBSP}zł` : value
}

export function MyTargetCard() {
  const [data, setData] = useState<MySalesTarget | null>(null)

  useEffect(() => {
    const ctrl = new AbortController()
    fetchMySalesTarget(ctrl.signal)
      .then((res) => {
        if (!ctrl.signal.aborted) setData(res)
      })
      // kafelek jest dodatkiem — jego błąd nie psuje dashboardu
      .catch(() => {})
    return () => ctrl.abort()
  }, [])

  if (!data || data.target == null) return null

  const monthNo = Number(data.month.slice(5, 7))
  const monthLabel = MONTHS[monthNo - 1] ?? data.month
  const percent = data.percent
  const pace = data.workdays.total > 0 ? (data.workdays.elapsed / data.workdays.total) * 100 : 0
  const onPace = percent != null && percent >= pace
  const width = percent == null ? 0 : Math.max(1.5, Math.min(100, percent))

  return (
    <section className="app-card app-dash-card flex min-w-0 flex-col rounded-xl bg-white p-4 shadow-sm @5xl:col-span-12" aria-label="Mój cel w tym miesiącu">
      <div className="mb-3 flex flex-wrap items-center gap-x-2.5 gap-y-1">
        <span className="app-dash-icon grid h-[26px] w-[26px] shrink-0 place-items-center rounded-md bg-blue-50 text-blue-600">
          <NavIcon name="reports" className="h-[15px] w-[15px]" />
        </span>
        <h2 className="text-sm font-semibold">Mój cel: {monthLabel}</h2>
        <span className="ml-auto text-xs text-slate-500">
          {data.workdays.elapsed} z {data.workdays.total} {plural(data.workdays.total, 'dnia roboczego', 'dni roboczych', 'dni roboczych')}
        </span>
      </div>
      <div className="flex flex-wrap items-end gap-x-8 gap-y-3">
        <div className="min-w-0">
          <div className="app-dash-label text-[11px] font-medium tracking-wide text-slate-400 uppercase">Sprzedaż netto</div>
          <div className="text-[26px] leading-tight font-semibold tracking-tight tabular-nums">
            {zl(data.sales)}
            <small className="ml-1.5 text-sm font-medium tracking-normal text-slate-500">z {zl(data.target)}</small>
          </div>
        </div>
        <div className="min-w-[12rem] flex-1">
          <div className="mb-1 flex items-baseline justify-between text-xs">
            <span className="text-slate-500">Realizacja</span>
            <b className="tabular-nums text-slate-900">{percent == null ? '—' : `${fmtDec(percent)}%`}</b>
          </div>
          <span className="block h-2 overflow-hidden rounded-full bg-slate-100" aria-hidden>
            <span className={`block h-full rounded-full ${onPace ? 'bg-teal-600' : 'bg-amber-500'}`} style={{ width: `${width}%` }} />
          </span>
        </div>
        <div className="text-xs text-slate-600">
          Klienci, którzy kupili: <b className="tabular-nums text-slate-900">{groupInt(data.clients_bought)}</b>
          {', w tym nowi: '}
          <b className="tabular-nums text-slate-900">{groupInt(data.new_clients)}</b>
        </div>
      </div>
      <p className="mt-3 text-[11.5px] text-slate-500">
        Sprzedaż netto z faktur i paragonów w ERP XL (po korektach) Twoich klientów, według nocnego odczytu — dzisiejsze
        dokumenty pojawią się jutro. Faktura do WZ liczy się z towarów na jej WZ, a WZ jeszcze bez faktury — od dnia wydania.
      </p>
    </section>
  )
}
