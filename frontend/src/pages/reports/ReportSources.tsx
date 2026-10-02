import { useState } from 'react'
import { SortTh } from '../../components/CampaignsUi'
import { ColumnChart, Insights, KpiRow, ReportCard, ReportFrame, SectionNote, type Insight } from '../../components/ReportKit'
import { plural } from '../../lib/plural'
import {
  ageFromHours,
  dayMonth,
  groupInt,
  longDate,
  shortDate,
  trimLeadingEmpty,
  useReportData,
  type RunState,
  type SourcesReportData,
} from '../../lib/reports'
import { sortDate, sortRows, useTableSort } from '../../lib/tableSort'

/**
 * Raport „Źródła danych” (GET /reports/sources): świeżość kont B2B dostawców (świeżość liczona od ostatniego UDANEGO
 * przebiegu — nieudany też kończy przebieg) i cenników z plików. Bez loginów kont; komunikat przebiegu tylko
 * z uprawnieniem do kont B2B.
 */

const RUN_LABEL: Record<RunState, string> = {
  ok: 'Udany',
  partial: 'Częściowy',
  failed: 'Błąd',
  interrupted: 'Przerwany',
  cancelled: 'Anulowany',
  running: 'W trakcie',
}

/** Pigułka stanu: kropka w kolorze stanu + słowo (nigdy sam kolor). */
const RUN_PILL: Record<RunState, string> = {
  ok: 'bg-emerald-50 text-emerald-800',
  partial: 'bg-amber-50 text-amber-800',
  failed: 'bg-red-50 text-red-800',
  interrupted: 'bg-red-50 text-red-800',
  cancelled: 'bg-slate-100 text-slate-700',
  running: 'bg-sky-50 text-sky-800',
}

const RUN_DOT: Record<RunState, string> = {
  ok: 'bg-teal-600',
  partial: 'bg-amber-500',
  failed: 'bg-rose-700',
  interrupted: 'bg-rose-700',
  cancelled: 'bg-slate-400',
  running: 'bg-sky-600',
}

const FREQ_LABEL: Record<'off' | 'daily' | 'weekly', string> = { off: 'wyłączona', daily: 'codziennie', weekly: 'co tydzień' }

/** Ile kont pokazać przed „Pokaż wszystkie” (konta z problemem zawsze widać). */
const ACCOUNTS_SHOWN = 12

type AccountKey ='label' | 'status' | 'ok' | 'products' | 'seen' | 'failed' | 'prices'
type FileKey = 'manufacturer' | 'imported' | 'rows' | 'changed' | 'imports'

export function ReportSources() {
  const state = useReportData<SourcesReportData>('/reports/sources')
  return (
    <ReportFrame state={state} note="dane odświeżane co 10 min">
      {(d) => <SourcesBody d={d} />}
    </ReportFrame>
  )
}

function insightsOf(d: SourcesReportData): Insight[] {
  const out: Insight[] = []
  if (d.b2b) {
    const failing = d.b2b.accounts.filter((a) => a.failed_streak > 0).sort((a, b) => b.failed_streak - a.failed_streak)
    if (failing.length > 0) {
      const worst = failing[0]
      const names = failing.slice(0, 3).map((a) => a.label).join(', ')
      out.push({
        tone: 'bad',
        text:
          worst.failed_streak > 1 ? (
            <>
              {groupInt(failing.length)} {plural(failing.length, 'konto kończy', 'konta kończą', 'kont kończy')} ostatnie przebiegi błędem;
              najdłużej <b>{worst.label}</b> — {groupInt(worst.failed_streak)} razy z rzędu.
            </>
          ) : (
            <>
              Ostatni przebieg zakończył się błędem na {groupInt(failing.length)}{' '}
              {plural(failing.length, 'koncie', 'kontach', 'kontach')}: <b>{names}</b>
              {failing.length > 3 ? ' i innych' : ''}.
            </>
          ),
      })
    } else {
      out.push({ tone: 'good', text: <>Wszystkie konta B2B zakończyły ostatni przebieg bez błędu.</> })
    }
    if (d.b2b.totals.stale > 0) {
      out.push({
        tone: 'warn',
        text: (
          <>
            {groupInt(d.b2b.totals.stale)} {plural(d.b2b.totals.stale, 'konto ma', 'konta mają', 'kont ma')} ceny starsze, niż wynika z
            harmonogramu (tygodniowe ponad {d.thresholds.weekly_days} dni, codzienne ponad {d.thresholds.daily_hours} godzin).
          </>
        ),
      })
    }
    const last30 = d.b2b.daily.reduce(
      (acc, x) => ({ ok: acc.ok + x.ok + x.partial, bad: acc.bad + x.failed + x.interrupted }),
      { ok: 0, bad: 0 },
    )
    if (last30.ok + last30.bad > 0) {
      out.push({
        tone: last30.bad / (last30.ok + last30.bad) > 0.2 ? 'warn' : 'neutral',
        text: (
          <>
            W 30 dniach: {groupInt(last30.ok)} udanych przebiegów i {groupInt(last30.bad)} nieudanych lub przerwanych.
          </>
        ),
      })
    }
  }
  if (d.files) {
    const old = d.files.filter((f) => f.old)
    if (old.length > 0) {
      out.push({
        tone: 'warn',
        text: (
          <>
            {groupInt(old.length)} {plural(old.length, 'cennik z pliku ma', 'cenniki z pliku mają', 'cenników z pliku ma')} import starszy niż{' '}
            {d.thresholds.file_old_days} dni — warto sprawdzić, czy producent nie wydał nowszego.
          </>
        ),
      })
    }
  }
  return out
}

function SourcesBody({ d }: { d: SourcesReportData }) {
  const b = d.b2b
  return (
    <>
      <Insights items={insightsOf(d)} />
      {b && (
        <KpiRow
          items={[
            { label: 'Konta B2B', value: groupInt(b.totals.accounts), sub: `${groupInt(b.totals.scheduled)} z synchronizacją według harmonogramu` },
            {
              label: 'Ceny aktualne',
              value: groupInt(b.totals.fresh),
              tone: b.totals.fresh === b.totals.scheduled ? 'good' : 'neutral',
              sub: 'ostatni udany przebieg w terminie',
            },
            { label: 'Ceny przeterminowane', value: groupInt(b.totals.stale), tone: b.totals.stale > 0 ? 'warn' : 'good', sub: 'udany przebieg za dawno' },
            { label: 'Ostatni przebieg z błędem', value: groupInt(b.totals.failing), tone: b.totals.failing > 0 ? 'bad' : 'good', sub: 'błąd albo przerwany' },
            ...(d.files
              ? [
                  {
                    label: 'Cenniki z pliku',
                    value: groupInt(d.files.length),
                    tone: d.files.some((f) => f.old) ? ('warn' as const) : ('neutral' as const),
                    sub: `${groupInt(d.files.filter((f) => f.old).length)} starszych niż ${d.thresholds.file_old_days} dni`,
                  },
                ]
              : []),
          ]}
        />
      )}

      {b ? (
        <>
          <ReportCard
            className="mb-4"
            title="Przebiegi synchronizacji B2B — ostatnie 30 dni"
            hint="Każdy przebieg według dnia rozpoczęcia. „Częściowy” = część pozycji pominięta, ceny reszty zapisane."
          >
            <ColumnChart
              label="Przebiegi synchronizacji B2B"
              height={190}
              series={[
                { key: 'ok', label: 'Udane', fill: 'fill-teal-600', swatch: 'bg-teal-600' },
                { key: 'partial', label: 'Częściowe', fill: 'fill-amber-500', swatch: 'bg-amber-500' },
                { key: 'failed', label: 'Błąd lub przerwany', fill: 'fill-rose-700', swatch: 'bg-rose-700' },
                { key: 'cancelled', label: 'Anulowane', fill: 'fill-slate-400', swatch: 'bg-slate-400' },
              ]}
              columns={trimLeadingEmpty(b.daily, (x) => x.ok + x.partial + x.failed + x.cancelled + x.interrupted === 0, 7).map((x) => ({
                key: x.day,
                label: dayMonth(x.day),
                title: longDate(x.day),
                values: { ok: x.ok, partial: x.partial, failed: x.failed + x.interrupted, cancelled: x.cancelled },
              }))}
            />
          </ReportCard>
          <AccountsTable accounts={b.accounts} />
        </>
      ) : null}

      {d.files ? (
        <FilesTable files={d.files} oldDays={d.thresholds.file_old_days} />
      ) : (
        <ReportCard title="Cenniki z plików">
          <SectionNote>Cenniki z plików widać z uprawnieniem „Cenniki — podgląd”.</SectionNote>
        </ReportCard>
      )}
    </>
  )
}

function StatePill({ state }: { state: RunState | null }) {
  if (!state) return <span className="text-slate-400">nigdy</span>
  return (
    <span className={`inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[11px] whitespace-nowrap ${RUN_PILL[state]}`}>
      <span className={`h-1.5 w-1.5 rounded-full ${RUN_DOT[state]}`} aria-hidden />
      {RUN_LABEL[state]}
    </span>
  )
}

function AccountsTable({ accounts }: { accounts: NonNullable<SourcesReportData['b2b']>['accounts'] }) {
  const [sort, toggle] = useTableSort<AccountKey>(['ok', 'products', 'seen', 'failed', 'prices'])
  const severity = (a: (typeof accounts)[number]) => (a.failed_streak > 0 ? 0 : a.stale ? 1 : 2)
  const sorted = sortRows(accounts, sort, (a, k) => {
    switch (k) {
      case 'label':
        return a.label
      case 'status':
        return severity(a)
      case 'ok':
        // konto bez udanego przebiegu jest „najstarsze” — przy sortowaniu nie może trafić na koniec listy
        return a.hours_since_ok ?? Number.POSITIVE_INFINITY
      case 'products':
        return a.products
      case 'seen':
        return a.seen_7d_pct
      case 'failed':
        return a.failed_30d
      case 'prices':
        return a.last_prices_changed
    }
  })
  const showMessage = accounts.some((a) => a.message !== null)
  const [all, setAll] = useState(false)
  // serwer zwraca najpierw konta z problemami — przy domyślnej kolejności zawsze wszystkie z problemem widoczne
  const problems = accounts.filter((a) => a.failed_streak > 0 || a.stale).length
  const limit = Math.max(ACCOUNTS_SHOWN, problems)
  const shown = all || sort ? sorted : sorted.slice(0, limit)

  return (
    <ReportCard
      className="mb-4"
      title="Konta B2B dostawców"
      hint="Na górze konta z problemami. „Widziane w 7 dniach” — jaka część powiązanych pozycji pojawiła się u dostawcy w ostatnim tygodniu."
    >
      <div className="overflow-x-auto">
        <table className="app-table w-full min-w-[52rem] text-left text-xs">
          <thead>
            <tr className="border-b bg-slate-50 text-slate-500">
              <SortTh label="Dostawca" k="label" sort={sort} onSort={toggle} />
              <SortTh label="Ostatni przebieg" k="status" sort={sort} onSort={toggle} />
              <SortTh label="Ostatni udany" k="ok" sort={sort} onSort={toggle} align="right" />
              <SortTh label="Kart" k="products" sort={sort} onSort={toggle} align="right" />
              <SortTh label="Widziane w 7 dniach" k="seen" sort={sort} onSort={toggle} align="right" />
              <SortTh label="Nieudane / 30 dni" k="failed" sort={sort} onSort={toggle} align="right" />
              <SortTh label="Zmiany cen" k="prices" sort={sort} onSort={toggle} align="right" />
            </tr>
          </thead>
          <tbody>
            {shown.map((a) => (
              <tr key={a.id} className="border-b border-slate-100 align-top">
                <td className="p-2">
                  <span className="font-medium text-slate-800">{a.label}</span>
                  <span className="block text-[11px] text-slate-500">synchronizacja {FREQ_LABEL[a.frequency]}</span>
                  {showMessage && a.message && (a.failed_streak > 0 || a.last_status === 'partial') && (
                    <span className="mt-0.5 block max-w-md text-[11px] text-slate-600">{a.message}</span>
                  )}
                </td>
                <td className="p-2">
                  <StatePill state={a.last_status} />
                  {a.failed_streak > 1 && <span className="ml-1.5 text-[11px] text-red-700">{groupInt(a.failed_streak)}× z rzędu</span>}
                  {a.last_run_at && <span className="block pt-0.5 text-[11px] text-slate-500">{shortDate(a.last_run_at)}</span>}
                </td>
                <td className={`p-2 text-right whitespace-nowrap ${a.stale ? 'font-semibold text-amber-800' : 'text-slate-700'}`}>
                  {a.last_ok_at ? `${ageFromHours(a.hours_since_ok)} temu` : 'nigdy'}
                </td>
                <td className="app-num p-2 text-right tabular-nums">{groupInt(a.products)}</td>
                <td className="app-num p-2 text-right tabular-nums">{a.seen_7d_pct == null ? '—' : `${Math.round(a.seen_7d_pct)}%`}</td>
                <td className={`app-num p-2 text-right tabular-nums ${a.failed_30d > 0 ? 'text-red-700' : 'text-slate-400'}`}>
                  {groupInt(a.failed_30d)} / {groupInt(a.runs_30d)}
                </td>
                <td className="app-num p-2 text-right tabular-nums">{a.last_prices_changed == null ? '—' : groupInt(a.last_prices_changed)}</td>
              </tr>
            ))}
            {sorted.length === 0 && (
              <tr>
                <td colSpan={7} className="p-3 text-slate-500">
                  Brak kont B2B.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
      {!sort && accounts.length > limit && (
        <button type="button" onClick={() => setAll((v) => !v)} className="mt-2 text-xs font-medium text-blue-700 hover:underline">
          {all ? 'Zwiń listę' : `Pokaż wszystkie konta (${groupInt(accounts.length)})`}
        </button>
      )}
    </ReportCard>
  )
}

function FilesTable({ files, oldDays }: { files: NonNullable<SourcesReportData['files']>; oldDays: number }) {
  const [sort, toggle] = useTableSort<FileKey>(['rows', 'changed', 'imports'], { key: 'imported', dir: 'asc' })
  const sorted = sortRows(files, sort, (f, k) => {
    switch (k) {
      case 'manufacturer':
        return f.manufacturer
      case 'imported':
        return sortDate(f.last_import_at)
      case 'rows':
        return f.rows_total
      case 'changed':
        return f.prices_changed
      case 'imports':
        return f.imports_12m
    }
  })
  return (
    <ReportCard title="Cenniki z plików" hint={`Ostatni import pliku od producenta. Na pomarańczowo — starsze niż ${oldDays} dni.`}>
      <div className="overflow-x-auto">
        <table className="app-table w-full min-w-[40rem] text-left text-xs">
          <thead>
            <tr className="border-b bg-slate-50 text-slate-500">
              <SortTh label="Producent" k="manufacturer" sort={sort} onSort={toggle} />
              <SortTh label="Ostatni import" k="imported" sort={sort} onSort={toggle} />
              <SortTh label="Pozycji" k="rows" sort={sort} onSort={toggle} align="right" />
              <SortTh label="Zmian cen" k="changed" sort={sort} onSort={toggle} align="right" />
              <SortTh label="Importów w ostatnim roku" k="imports" sort={sort} onSort={toggle} align="right" />
            </tr>
          </thead>
          <tbody>
            {sorted.map((f) => (
              <tr key={f.price_list_id} className="border-b border-slate-100">
                <td className="p-2">
                  <span className="font-medium text-slate-800">{f.manufacturer}</span>
                  {f.version && <span className="ml-1.5 text-slate-500">{f.version}</span>}
                  {f.suggested && (
                    <span className="ml-1.5 rounded-full bg-slate-100 px-1.5 py-0.5 text-[10px] text-slate-600" title="Ceny sugerowane, nie zakupu">
                      sugerowany
                    </span>
                  )}
                </td>
                <td className={`p-2 whitespace-nowrap ${f.old ? 'font-semibold text-amber-800' : 'text-slate-700'}`}>
                  {shortDate(f.last_import_at)}
                  {f.days_ago != null && <span className="ml-1 font-normal text-slate-500">({groupInt(f.days_ago)} dni)</span>}
                </td>
                <td className="app-num p-2 text-right tabular-nums">{f.rows_total == null ? '—' : groupInt(f.rows_total)}</td>
                <td className="app-num p-2 text-right tabular-nums">{f.prices_changed == null ? '—' : groupInt(f.prices_changed)}</td>
                <td className="app-num p-2 text-right tabular-nums">{groupInt(f.imports_12m)}</td>
              </tr>
            ))}
            {sorted.length === 0 && (
              <tr>
                <td colSpan={5} className="p-3 text-slate-500">
                  Brak cenników importowanych z pliku.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </ReportCard>
  )
}
