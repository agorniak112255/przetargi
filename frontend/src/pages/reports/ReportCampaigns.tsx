import { Fragment, useState, type ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../../auth'
import { BarList, Insights, KpiRow, ReportCard, ReportFrame, SectionNote, type Insight, type Kpi } from '../../components/ReportKit'
import { can, canAny, downloadFile } from '../../lib/api'
import { CAMPAIGN_STATUS_LABEL, type CampaignStatus } from '../../lib/campaigns'
import { errorText } from '../../lib/campaignFormat'
import { plural } from '../../lib/plural'
import {
  campaignsReportPath,
  fmtBigZl,
  fmtDec,
  fmtPrice,
  groupInt,
  longDate,
  NBSP,
  sharePct,
  shareValue,
  shortDate,
  useReportData,
  type CampaignsReport,
  type CampaignsReportCampaign,
  type CampaignsReportFigures,
  type CampaignsReportItem,
  type CampaignsReportPerson,
  type Tone,
} from '../../lib/reports'

/**
 * Raport „Wynik kampanii” (GET /reports/campaigns?month=&user_id=, CampaignsReport w App\Services\Reports): ile
 * pieniędzy zamrożonych w zalegającym towarze wróciło ze sprzedaży odbiorcom kampanii mailowych — koszt zakupu sprzedanego towaru,
 * który w dniu wysyłki leżał bez sprzedaży co najmniej stagnant_days dni (najwyżej do stanu z dnia wysyłki). Podstawa
 * do premii handlowców, więc każda liczba ma źródło: brak danych pokazujemy słowami, nigdy jako zero; koszt szacowany
 * jest oznaczony, a pozycje faktur można pobrać do sprawdzenia (CSV z tymi samymi filtrami).
 *
 * Widoczność (campaign_report_scope, liczy serwer): own — tylko własne kampanie; team — kierownik zespołu widzi
 * członków swoich zespołów; all — wszyscy. Przy team/all ranking osób, klik w osobę zawęża raport do niej.
 */

const SCOPE_LABEL: Record<CampaignsReport['scope'], string> = {
  own: 'tylko moje kampanie',
  team: 'mój zespół',
  all: 'wszyscy pracownicy',
}

const NO_DATA = 'brak danych'

function days(n: number): string {
  return `${groupInt(n)}${NBSP}${plural(n, 'dzień', 'dni', 'dni')}`
}

function lines(n: number): string {
  return `${groupInt(n)}${NBSP}${plural(n, 'pozycja', 'pozycje', 'pozycji')}`
}

/** Kwota albo „brak danych” (null to nie zero). */
function zl(v: number | null): string {
  return v == null ? NO_DATA : fmtBigZl(v)
}

function pct(v: number | null): string {
  return v == null ? NO_DATA : `${fmtDec(v)}%`
}

/** Ilość z jednostką: „12,5 szt.”; jednostka nieznana — sama liczba. */
function qty(n: number, unit: string | null): string {
  return unit ? `${fmtDec(n, 2)}${NBSP}${unit}` : fmtDec(n, 2)
}

/** Jak długo towar leżał przed mailem — z migawki z dnia wysyłki. */
function idleText(it: { idle_days: number | null; never_sold: boolean; lot_age_days: number | null }): string {
  if (it.idle_days != null) return `${days(it.idle_days)} bez sprzedaży`
  if (it.never_sold) return it.lot_age_days != null ? `nigdy niesprzedany, partia leżała ${days(it.lot_age_days)}` : 'nigdy niesprzedany, wiek partii nieznany'
  return NO_DATA
}

/** Dzisiejsza data w strefie przeglądarki (RRRR-MM-DD) — do porównania z final_after. */
function todayIso(): string {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

/** „3 października 2026, 05:41” — moment nocnego odczytu pełnymi słowami. */
function readStamp(iso: string): string {
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return iso
  return `${longDate(iso)}, ${d.toLocaleTimeString('pl-PL', { hour: '2-digit', minute: '2-digit' })}`
}

/** Odzysk: od 100 zł za 100 zł kosztu klient zapłacił co najmniej tyle, ile towar kosztował. */
function recoveryTone(p: number | null): Tone {
  if (p == null) return 'neutral'
  return p >= 100 ? 'good' : 'warn'
}

export function ReportCampaigns() {
  const [month, setMonth] = useState<string | null>(null)
  const [userId, setUserId] = useState<number | null>(null)
  const [csvError, setCsvError] = useState('')
  const [csvBusy, setCsvBusy] = useState(false)
  const state = useReportData<CampaignsReport>(campaignsReportPath(month, userId))
  const d = state.data

  const exportCsv = async () => {
    setCsvError('')
    setCsvBusy(true)
    // te same filtry co na ekranie; miesiąc domyślny brany z odpowiedzi, żeby plik i ekran się zgadzały
    const m = month ?? d?.month ?? null
    const path = campaignsReportPath(m, userId).replace(/^\/reports\/campaigns/, '/reports/campaigns/csv')
    try {
      await downloadFile(path, `wynik-kampanii-${m ?? 'biezacy-miesiac'}.csv`)
    } catch (ex) {
      setCsvError(errorText(ex, 'Nie udało się pobrać pozycji faktur.'))
    } finally {
      setCsvBusy(false)
    }
  }

  const selectCls = 'rounded-md border border-slate-300 bg-white px-2 py-1.5 text-sm text-slate-900'

  return (
    <ReportFrame
      state={state}
      note={
        d
          ? d.data_until
            ? `faktury i paragony z ERP XL według odczytu z ${readStamp(d.data_until)}`
            : 'nie ma jeszcze nocnego odczytu faktur i paragonów z ERP XL'
          : undefined
      }
      toolbar={
        <>
          <label className="flex items-center gap-2 text-xs text-slate-600">
            Miesiąc
            <select className={selectCls} value={d?.month ?? month ?? ''} disabled={!d} onChange={(e) => setMonth(e.target.value)}>
              {(d?.months ?? []).map((m) => (
                <option key={m.key} value={m.key}>
                  {m.label}
                </option>
              ))}
            </select>
          </label>
          {d && d.scope !== 'own' && (
            <label className="flex items-center gap-2 text-xs text-slate-600">
              Osoba
              <select
                className={selectCls}
                value={userId ?? ''}
                onChange={(e) => setUserId(e.target.value === '' ? null : Number(e.target.value))}
              >
                <option value="">{d.scope === 'team' ? 'Cały mój zespół' : 'Wszyscy'}</option>
                {d.people_options.map((p) => (
                  <option key={p.user_id} value={p.user_id}>
                    {p.name}
                  </option>
                ))}
              </select>
            </label>
          )}
          <button
            type="button"
            disabled={!d || csvBusy}
            onClick={() => void exportCsv()}
            className="rounded-md bg-emerald-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-emerald-700 disabled:opacity-50"
            title="Plik do Excela: każda pozycja faktury, paragonu i korekty przypisana kampaniom w tym miesiącu, z kosztem, źródłem kosztu i dopasowaniem klienta"
          >
            {csvBusy ? 'Pobieranie…' : 'Pobierz pozycje faktur (CSV)'}
          </button>
          {csvError && (
            <span className="text-xs text-red-700" role="alert">
              {csvError}
            </span>
          )}
        </>
      }
    >
      {(data) => <CampaignsBody d={data} onPickUser={setUserId} />}
    </ReportFrame>
  )
}

function CampaignsBody({ d, onPickUser }: { d: CampaignsReport; onPickUser: (id: number | null) => void }) {
  const label = d.months.find((m) => m.key === d.month)?.label ?? d.month
  const final = todayIso() >= d.final_after
  const status = d.closed
    ? final
      ? 'miesiąc zamknięty, liczby ostateczne'
      : `miesiąc zamknięty, liczby ostateczne od ${longDate(d.final_after)}`
    : `miesiąc w toku, liczby ostateczne od ${longDate(d.final_after)}`
  const picked = d.user_id != null ? (d.people_options.find((p) => p.user_id === d.user_id)?.name ?? null) : null
  const empty = d.campaigns.length === 0 && d.totals.sales_net === 0 && d.totals.buyers === 0

  return (
    <>
      <div className="mb-3 flex flex-wrap items-center gap-x-3 gap-y-1">
        <h2 className="text-base font-semibold text-slate-900">
          {label} · <span className="font-normal text-slate-600">{status}</span>
        </h2>
        <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-600">{SCOPE_LABEL[d.scope]}</span>
        {picked && d.scope !== 'own' && (
          <span className="inline-flex items-center gap-1.5 rounded-full bg-blue-50 px-2 py-0.5 text-[11px] text-blue-800">
            Tylko: {picked}
            <button type="button" onClick={() => onPickUser(null)} className="font-medium underline">
              pokaż wszystkich
            </button>
          </span>
        )}
      </div>

      {empty ? (
        <SectionNote>
          W tym miesiącu nie ma kampanii, których okno sprzedaży obejmuje ten miesiąc, ani faktur przypisanych kampaniom. Okno kampanii trwa od
          dnia maila do klienta do 30. dnia po starcie wysyłki.
        </SectionNote>
      ) : (
        <>
          <KpiRow items={kpisOf(d)} />
          <Insights items={insightsOf(d)} />
          {d.scope !== 'own' && d.user_id == null && d.people.length > 0 && <PeopleCard d={d} onPickUser={onPickUser} />}
          <TopItemsCard d={d} />
          <CampaignsCard d={d} />
        </>
      )}
      <NotesCard d={d} />
    </>
  )
}

/**
 * Link do strony kampanii tylko dla tych, których ta strona wpuści: podgląd wszystkich kampanii (campaigns.view /
 * campaigns.manage) albo własna kampania z campaigns.use. Inaczej sam tekst — kierownik zespołu albo zarząd z samym
 * raportem trafiłby na przekierowanie.
 */
function CampaignLink({ id, ownerId, className, children }: { id: number; ownerId: number | null; className: string; children: ReactNode }) {
  const { user } = useAuth()
  const allowed = canAny(user, ['campaigns.view', 'campaigns.manage']) || (ownerId !== null && user?.id === ownerId && can(user, 'campaigns.use'))
  if (!allowed) return <span className={className.replace(/text-blue-700|hover:underline|app-link/g, '').trim()}>{children}</span>
  return (
    <Link to={`/kampanie/${id}`} className={className}>
      {children}
    </Link>
  )
}

/** Uwolnione pieniądze: 0 zł przy pozycjach bez danych z dnia wysyłki albo bez kosztu to „brak danych”, nie zero. */
function freedZl(f: { freed_capital: number; freed_unknown_lines: number }): string {
  return f.freed_capital === 0 && f.freed_unknown_lines > 0 ? NO_DATA : fmtBigZl(f.freed_capital)
}

/** To samo przy towarze bez danych z dnia wysyłki (nie wiadomo, czy zalegał). */
function itemFreedZl(it: CampaignsReportItem, freed: number): string {
  const unknown = it.stagnant === null
  return freed === 0 && unknown ? NO_DATA : fmtBigZl(freed)
}

/* ---------- Wskaźniki i najważniejsze ---------- */

function kpisOf(d: CampaignsReport): Kpi[] {
  const t = d.totals
  // 0 zł przy pozycjach bez danych z dnia wysyłki albo bez kosztu to nie fakt, tylko brak wiedzy
  const freedUnknown = t.freed_capital === 0 && t.freed_unknown_lines > 0
  const freedSub: string[] = ['koszt zakupu sprzedanego zalegającego towaru']
  if (t.avg_idle_days != null) freedSub.push(`leżał średnio ${days(Math.round(t.avg_idle_days))}`)
  if (t.freed_estimated > 0) freedSub.push(`w tym szacunek ${fmtBigZl(t.freed_estimated)}`)
  const salesSub = [`${groupInt(t.buyers)} ${plural(t.buyers, 'kupujący klient', 'kupujących klientów', 'kupujących klientów')}`]
  if (t.corrections_net !== 0) salesSub.push(`korekty ${fmtBigZl(t.corrections_net)}`)
  const marginSub: string[] = []
  if (t.margin_percent != null) marginSub.push(`${fmtDec(t.margin_percent)}% sprzedaży ze znanym kosztem`)
  if (t.lines_without_cost > 0) marginSub.push(`${lines(t.lines_without_cost)} bez kosztu`)
  if (t.below_cost_lines > 0) marginSub.push(`${lines(t.below_cost_lines)} poniżej kosztu`)
  return [
    {
      label: 'Uwolnione pieniądze',
      value: freedUnknown ? NO_DATA : fmtBigZl(t.freed_capital),
      tone: t.freed_capital > 0 ? 'good' : 'neutral',
      sub: freedUnknown ? `${lines(t.freed_unknown_lines)} bez danych z dnia wysyłki albo bez kosztu` : freedSub.join(' · '),
    },
    {
      label: 'Sprzedaż odbiorcom kampanii',
      value: fmtBigZl(t.sales_net),
      sub: `netto · ${salesSub.join(' · ')}`,
    },
    {
      label: 'Odzysk',
      value: t.recovery_percent == null ? NO_DATA : `${fmtDec(t.recovery_percent)}${NBSP}zł`,
      tone: recoveryTone(t.recovery_percent),
      sub:
        t.recovery_percent == null
          ? freedUnknown
            ? `${lines(t.freed_unknown_lines)} bez danych z dnia wysyłki albo bez kosztu`
            : 'brak sprzedaży zalegającego towaru ze znanym kosztem'
          : `tyle klienci zapłacili za każde 100${NBSP}zł kosztu zalegającego towaru`,
    },
    {
      label: 'Marża',
      value: zl(t.margin),
      tone: t.margin != null && t.margin < 0 ? 'bad' : t.below_cost_lines > 0 ? 'warn' : 'neutral',
      sub: marginSub.length > 0 ? marginSub.join(' · ') : 'brak pozycji sprzedaży',
    },
    {
      label: 'Kampanie i maile',
      value: groupInt(t.campaigns),
      sub: `${plural(t.campaigns, 'kampania', 'kampanie', 'kampanii')} z oknem sprzedaży w miesiącu · ${groupInt(t.recipients_sent)} ${plural(t.recipients_sent, 'mail wysłany', 'maile wysłane', 'maili wysłanych')} w miesiącu`,
    },
  ]
}

function insightsOf(d: CampaignsReport): Insight[] {
  const out: Insight[] = []
  const t = d.totals
  if (t.freed_capital === 0 && t.sales_net > 0 && t.freed_unknown_lines === 0) {
    out.push({
      tone: 'warn',
      text:
        d.warnings.over_stock_lines > 0 ? (
          <>Zalegający towar klienci kupili ponad stan z dnia wysyłki (stan zużyła wcześniejsza sprzedaż) — uwolnione pieniądze wynoszą 0{NBSP}zł.</>
        ) : (
          <>Klienci kampanii kupowali, ale żaden sprzedany towar nie zalegał w dniu wysyłki — uwolnione pieniądze wynoszą 0{NBSP}zł.</>
        ),
    })
  }
  if (d.scope !== 'own' && d.user_id == null && d.people.length > 1 && d.people[0].freed_capital > 0) {
    const top = d.people[0]
    out.push({
      tone: 'good',
      text: (
        <>
          Najwięcej uwolnionych pieniędzy: <b>{top.name}</b> — {fmtBigZl(top.freed_capital)} ({sharePct(top.freed_capital, t.freed_capital) ?? '—'}{' '}
          całości).
        </>
      ),
    })
  }
  const item = d.top_items[0]
  if (item && item.freed_capital > 0) {
    out.push({
      tone: 'good',
      text: (
        <>
          Najwięcej uwolnił towar <b>{item.name}</b> ({item.code}): {fmtBigZl(item.freed_capital)}, przed mailem {idleText(item)}.
        </>
      ),
    })
  }
  if (t.below_cost_lines > 0) {
    out.push({
      tone: 'bad',
      text: (
        <>
          {lines(t.below_cost_lines)} sprzedano poniżej kosztu zakupu — łączna strata{' '}
          {fmtBigZl(t.below_cost_loss)}.
        </>
      ),
    })
  }
  return out.slice(0, 4)
}

/* ---------- Ranking osób ---------- */

function PeopleCard({ d, onPickUser }: { d: CampaignsReport; onPickUser: (id: number) => void }) {
  return (
    <ReportCard
      className="mb-4"
      title="Ranking osób"
      hint="Kto w miesiącu uwolnił najwięcej pieniędzy z zalegającego towaru u odbiorców swoich kampanii. Kliknij osobę, żeby zobaczyć tylko jej wyniki."
    >
      <div className="mb-4 max-w-3xl">
        <BarList
          fill="bg-teal-600"
          format={fmtBigZl}
          total={d.totals.freed_capital > 0 ? d.totals.freed_capital : undefined}
          rows={d.people.map((p) => ({
            key: String(p.user_id),
            label: (
              <button type="button" onClick={() => onPickUser(p.user_id)} className="app-link truncate text-left text-blue-700 hover:underline">
                {p.name}
                {freedZl(p) === NO_DATA && <span className="ml-1 text-slate-500">(uwolnione: {NO_DATA})</span>}
              </button>
            ),
            value: p.freed_capital,
          }))}
        />
      </div>
      <div className="overflow-x-auto">
        <table className="app-table w-full min-w-[60rem] text-left text-xs">
          <thead>
            <tr className="border-b bg-slate-50 text-slate-500">
              <th className="p-2 font-medium">Osoba</th>
              <th className="p-2 text-right font-medium">Kampanie</th>
              <th className="p-2 text-right font-medium">Maile</th>
              <th className="p-2 text-right font-medium">Kupujący</th>
              <th className="p-2 text-right font-medium">Sprzedaż netto</th>
              <th className="p-2 text-right font-medium">Uwolnione</th>
              <th className="p-2 text-right font-medium" title="Ile klienci zapłacili za każde 100 zł kosztu zalegającego towaru">
                Odzysk na 100 zł
              </th>
              <th className="p-2 text-right font-medium">Marża</th>
              <th className="p-2 text-right font-medium">Poniżej kosztu</th>
            </tr>
          </thead>
          <tbody>
            {d.people.map((p) => (
              <PersonRow key={p.user_id} p={p} onPick={() => onPickUser(p.user_id)} />
            ))}
          </tbody>
        </table>
      </div>
    </ReportCard>
  )
}

function PersonRow({ p, onPick }: { p: CampaignsReportPerson; onPick: () => void }) {
  return (
    <tr className="border-b border-slate-100">
      <td className="p-2">
        <button type="button" onClick={onPick} className="app-link text-left font-medium text-blue-700 hover:underline">
          {p.name}
        </button>
        {p.avg_idle_days != null && <span className="block text-[11px] text-slate-500">towar leżał średnio {days(Math.round(p.avg_idle_days))}</span>}
      </td>
      <td className="app-num p-2 text-right tabular-nums">{groupInt(p.campaigns)}</td>
      <td className="app-num p-2 text-right tabular-nums">{groupInt(p.recipients_sent)}</td>
      <td className="app-num p-2 text-right tabular-nums">{groupInt(p.buyers)}</td>
      <td className="app-num p-2 text-right whitespace-nowrap tabular-nums">{fmtBigZl(p.sales_net)}</td>
      <td className="app-num p-2 text-right whitespace-nowrap tabular-nums">
        <b className="font-semibold text-slate-900">{freedZl(p)}</b>
        {p.freed_estimated > 0 && <span className="block text-[11px] font-normal text-slate-500">w tym szacunek {fmtBigZl(p.freed_estimated)}</span>}
      </td>
      <td className={`app-num p-2 text-right whitespace-nowrap tabular-nums ${p.recovery_percent != null && p.recovery_percent < 100 ? 'text-amber-800' : ''}`}>
        {p.recovery_percent == null ? <span className="text-slate-500">{NO_DATA}</span> : `${fmtDec(p.recovery_percent)}${NBSP}zł`}
      </td>
      <td className="app-num p-2 text-right whitespace-nowrap tabular-nums">
        <MarginValue margin={p.margin} percent={p.margin_percent} />
      </td>
      <td className="app-num p-2 text-right whitespace-nowrap tabular-nums">
        <BelowCost f={p} />
      </td>
    </tr>
  )
}

function MarginValue({ margin, percent }: { margin: number | null; percent: number | null }) {
  if (margin == null) return <span className="text-slate-500">{NO_DATA}</span>
  return (
    <>
      <span className={margin < 0 ? 'text-rose-700' : ''}>{fmtBigZl(margin)}</span>
      {percent != null && <span className="block text-[11px] text-slate-500">{fmtDec(percent)}%</span>}
    </>
  )
}

function BelowCost({ f }: { f: CampaignsReportFigures }) {
  if (f.below_cost_lines === 0) return <span className="text-slate-400">żadnej pozycji</span>
  return (
    <span className="text-rose-700">
      {lines(f.below_cost_lines)}
      <span className="block text-[11px]">strata {fmtBigZl(f.below_cost_loss)}</span>
    </span>
  )
}

/* ---------- Towary ---------- */

function TopItemsCard({ d }: { d: CampaignsReport }) {
  return (
    <ReportCard
      className="mb-4"
      title="Towary, które najlepiej zeszły"
      hint="Dziesięć towarów z największymi uwolnionymi pieniędzmi w miesiącu i jak długo leżały w magazynie przed mailem."
    >
      {d.top_items.length === 0 ? (
        <SectionNote>
          {d.warnings.freed_unknown_lines > 0
            ? 'W tym miesiącu nie ma towaru z policzonymi uwolnionymi pieniędzmi — przy części pozycji brakuje danych z dnia wysyłki albo kosztu (zobacz zastrzeżenia na dole).'
            : 'W tym miesiącu klienci kampanii nie kupili towaru, który zalegał w dniu wysyłki.'}
        </SectionNote>
      ) : (
        <div className="overflow-x-auto">
          <table className="app-table w-full min-w-[46rem] text-left text-xs">
            <thead>
              <tr className="border-b bg-slate-50 text-slate-500">
                <th className="p-2 font-medium">Towar</th>
                <th className="p-2 font-medium">Przed mailem</th>
                <th className="p-2 text-right font-medium">Ilość</th>
                <th className="p-2 text-right font-medium">Sprzedaż netto</th>
                <th className="p-2 text-right font-medium">Uwolnione</th>
              </tr>
            </thead>
            <tbody>
              {d.top_items.map((it) => (
                <tr key={`${it.campaign_id}-${it.erp_item_id}`} className="border-b border-slate-100">
                  <td className="w-[40%] max-w-0 p-2">
                    <span className="block truncate font-medium text-slate-800" title={it.name}>
                      {it.name}
                    </span>
                    <span className="text-[11px] text-slate-500">
                      <span className="app-code">{it.code}</span> · kampania{' '}
                      <CampaignLink
                        id={it.campaign_id}
                        ownerId={d.campaigns.find((c) => c.id === it.campaign_id)?.user_id ?? null}
                        className="app-link text-blue-700 hover:underline"
                      >
                        {it.campaign_code}
                      </CampaignLink>{' '}
                      · {it.user_name}
                    </span>
                  </td>
                  <td className="p-2 text-slate-700">{idleText(it)}</td>
                  <td className="app-num p-2 text-right whitespace-nowrap tabular-nums">{qty(it.quantity, it.unit)}</td>
                  <td className="app-num p-2 text-right whitespace-nowrap tabular-nums">{fmtBigZl(it.sales_net)}</td>
                  <td className="app-num p-2 text-right whitespace-nowrap tabular-nums font-semibold text-slate-900">{fmtBigZl(it.freed_capital)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </ReportCard>
  )
}

/* ---------- Kampanie ---------- */

function CampaignsCard({ d }: { d: CampaignsReport }) {
  const [open, setOpen] = useState<Set<number>>(() => new Set())
  const toggle = (id: number) =>
    setOpen((prev) => {
      const next = new Set(prev)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })

  return (
    <ReportCard
      className="mb-4"
      title="Kampanie"
      hint="Kampanie, których okno sprzedaży obejmuje ten miesiąc: wynik w miesiącu i w całym oknie do dziś. Rozwiń kampanię, żeby zobaczyć jej towary."
    >
      {d.campaigns.length === 0 ? (
        <SectionNote>Żadna kampania nie ma okna sprzedaży w tym miesiącu.</SectionNote>
      ) : (
        // relative: napis sr-only (position absolute) w nagłówku zostaje w przewijanym kontenerze, nie poszerza strony
        <div className="relative overflow-x-auto">
          <table className="app-table w-full min-w-[64rem] text-left text-xs">
            <thead>
              <tr className="border-b bg-slate-50 text-slate-500">
                <th className="p-2 font-medium">Kampania</th>
                <th className="p-2 font-medium">Okno sprzedaży</th>
                <th className="p-2 text-right font-medium">Maile</th>
                <th className="p-2 text-right font-medium">Sprzedaż w miesiącu</th>
                <th className="p-2 text-right font-medium">Uwolnione w miesiącu</th>
                <th className="p-2 text-right font-medium">Marża w miesiącu</th>
                <th className="p-2 font-medium" title="Uwolnione w całym oknie wobec wartości zalegającego towaru w ofercie (stan z dnia wysyłki × koszt)">
                  Całe okno: uwolnione / zalegający w ofercie
                </th>
                <th className="p-2 font-medium">
                  <span className="sr-only">Towary</span>
                </th>
              </tr>
            </thead>
            <tbody>
              {d.campaigns.map((c) => (
                <CampaignRows key={c.id} c={c} open={open.has(c.id)} onToggle={() => toggle(c.id)} />
              ))}
            </tbody>
          </table>
        </div>
      )}
    </ReportCard>
  )
}

function CampaignRows({ c, open, onToggle }: { c: CampaignsReportCampaign; open: boolean; onToggle: () => void }) {
  const w = c.window
  const detailsId = `campaign-items-${c.id}`
  return (
    <Fragment>
      <tr className={`border-b border-slate-100 align-top ${open ? 'bg-slate-50' : ''}`}>
        <td className="min-w-[15rem] p-2">
          <CampaignLink id={c.id} ownerId={c.user_id} className="app-link block font-medium text-blue-700 hover:underline">
            {c.name}
          </CampaignLink>
          <span className="text-[11px] text-slate-500">
            <span className="app-code">{c.code}</span> · {c.user_name} · {CAMPAIGN_STATUS_LABEL[c.status as CampaignStatus] ?? c.status}
          </span>
        </td>
        <td className="p-2 whitespace-nowrap">
          <span className="text-slate-800">
            {shortDate(c.started_at)} – {shortDate(c.window_end)}
          </span>
          <span className={`block text-[11px] ${c.window_open ? 'text-blue-700' : 'text-slate-500'}`}>
            {c.window_open ? `trwa do ${longDate(c.window_end)}` : 'okno zakończone'}
          </span>
        </td>
        <td className="app-num p-2 text-right tabular-nums">{groupInt(c.recipients_sent)}</td>
        <td className="app-num p-2 text-right whitespace-nowrap tabular-nums">
          {fmtBigZl(c.month.sales_net)}
          <span className="block text-[11px] text-slate-500">
            {groupInt(c.month.buyers)} {plural(c.month.buyers, 'kupujący', 'kupujących', 'kupujących')}
          </span>
        </td>
        <td className="app-num p-2 text-right whitespace-nowrap tabular-nums">
          <b className="font-semibold text-slate-900">{freedZl(c.month)}</b>
          {c.month.freed_estimated > 0 && <span className="block text-[11px] font-normal text-slate-500">w tym szacunek {fmtBigZl(c.month.freed_estimated)}</span>}
        </td>
        <td className="app-num p-2 text-right whitespace-nowrap tabular-nums">
          <MarginValue margin={c.month.margin} percent={c.month.margin_percent} />
        </td>
        <td className="p-2">
          {w.offered_stock_value == null ? (
            <span className="text-slate-500">
              {fmtBigZl(w.freed_capital)} · wartość w ofercie: {NO_DATA}
            </span>
          ) : (
            <span className="flex items-center gap-2">
              <span className="hidden h-1.5 w-16 shrink-0 overflow-hidden rounded-full bg-slate-100 sm:block" aria-hidden>
                <span className="block h-full rounded-full bg-teal-600" style={{ width: `${shareValue(w.freed_capital, w.offered_stock_value)}%` }} />
              </span>
              <span className="whitespace-nowrap tabular-nums">
                {fmtBigZl(w.freed_capital)} z {fmtBigZl(w.offered_stock_value)}
                <span className="ml-1 text-slate-500">({pct(w.effectiveness_percent)})</span>
              </span>
            </span>
          )}
        </td>
        <td className="p-2 text-right">
          <button
            type="button"
            onClick={onToggle}
            aria-expanded={open}
            aria-controls={detailsId}
            className="rounded-md border border-slate-300 bg-white px-2 py-1 text-[11px] whitespace-nowrap text-slate-700 hover:bg-slate-50"
          >
            {open ? 'Zwiń' : `Towary (${groupInt(c.items.length)})`}
          </button>
        </td>
      </tr>
      {open && (
        <tr id={detailsId} className="border-b border-slate-200 bg-slate-50">
          <td colSpan={8} className="p-2 pt-0">
            <CampaignDetails c={c} />
          </td>
        </tr>
      )}
    </Fragment>
  )
}

function StagnantBadge({ value }: { value: boolean | null }) {
  if (value == null) return <span className="text-[11px] text-slate-500">{NO_DATA}</span>
  return value ? (
    <span className="rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-medium text-amber-800">zalegał</span>
  ) : (
    <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-600">nie zalegał</span>
  )
}

function CampaignDetails({ c }: { c: CampaignsReportCampaign }) {
  const w = c.window
  const facts: ReactNode[] = [
    <>
      W całym oknie: sprzedaż {fmtBigZl(w.sales_net)}, uwolnione {fmtBigZl(w.freed_capital)}, marża {zl(w.margin)}, {groupInt(w.buyers)}{' '}
      {plural(w.buyers, 'kupujący', 'kupujących', 'kupujących')}.
    </>,
  ]
  if (c.taken_over.lines > 0) {
    facts.push(
      <>
        Przejęte przez późniejszy mail innej kampanii: {lines(c.taken_over.lines)} za {fmtBigZl(c.taken_over.sales_net)}
        {c.taken_over.by.length > 0 && (
          <>
            {' '}
            (
            {c.taken_over.by.map((b, i) => (
              <Fragment key={b.campaign_id}>
                {i > 0 && ', '}
                <CampaignLink id={b.campaign_id} ownerId={null} className="app-link text-blue-700 hover:underline">
                  {b.code}
                </CampaignLink>{' '}
                {b.user_name}
              </Fragment>
            ))}
            )
          </>
        )}{' '}
        — liczą się tamtej kampanii.
      </>,
    )
  }
  return (
    <div className="rounded-lg border border-slate-200 bg-white p-3">
      <ul className="mb-2 space-y-0.5 text-[11px] text-slate-600">
        {facts.map((f, i) => (
          <li key={i}>{f}</li>
        ))}
      </ul>
      {c.items.length === 0 ? (
        <SectionNote>Kampania nie ma towarów z ERP XL.</SectionNote>
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full min-w-[60rem] text-left text-[11px]">
            <thead>
              <tr className="border-b border-slate-200 text-slate-500">
                <th className="p-1.5 font-medium">Towar</th>
                <th className="p-1.5 font-medium">W dniu wysyłki</th>
                <th className="p-1.5 text-right font-medium">Stan i wartość w ofercie</th>
                <th className="p-1.5 text-right font-medium" title="Cena z oferty w mailu wobec średniej ceny netto z faktur i paragonów w całym oknie">
                  Cena w ofercie / uzyskana
                </th>
                <th className="p-1.5 text-right font-medium">Sprzedano: miesiąc / okno</th>
                <th className="p-1.5 text-right font-medium">Uwolnione: miesiąc / okno</th>
                <th className="p-1.5 text-right font-medium">Marża w miesiącu</th>
                <th className="p-1.5 text-right font-medium" title="Ilość sprzedana ponad stan z dnia wysyłki — nie liczy się do uwolnionych">
                  Ponad stan
                </th>
              </tr>
            </thead>
            <tbody>
              {c.items.map((it) => (
                <ItemRow key={it.erp_item_id} it={it} />
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}

function ItemRow({ it }: { it: CampaignsReportItem }) {
  const w = it.window
  return (
    <tr className="border-b border-slate-100 align-top last:border-0">
      <td className="min-w-[13rem] p-1.5">
        <span className="block text-slate-800">{it.name}</span>
        <span className="app-code text-slate-500">{it.code}</span>
      </td>
      <td className="min-w-[11rem] p-1.5">
        <StagnantBadge value={it.stagnant} />
        <span className="mt-0.5 block text-slate-500">{it.snap_source == null ? 'kampania sprzed raportu' : idleText(it)}</span>
        {it.snap_source != null && it.idle_days != null && (
          <span className="block text-slate-500">
            najstarsza partia: {it.lot_age_days == null ? 'wiek nieznany' : days(it.lot_age_days)}
          </span>
        )}
      </td>
      <td className="app-num p-1.5 text-right whitespace-nowrap tabular-nums">
        {it.stock_at_send == null ? <span className="text-slate-500">{NO_DATA}</span> : qty(it.stock_at_send, it.unit)}
        <span className="block text-slate-500">{zl(it.offered_value)}</span>
      </td>
      <td className="app-num p-1.5 text-right whitespace-nowrap tabular-nums">
        {it.promo_price == null ? <span className="text-slate-500">{NO_DATA}</span> : fmtPrice(it.promo_price, 'PLN')}
        <span className="block text-slate-500">{w.avg_price == null ? 'brak sprzedaży' : fmtPrice(w.avg_price, 'PLN')}</span>
      </td>
      <td className="max-w-[13rem] p-1.5 text-right tabular-nums">
        <span className="whitespace-nowrap">
          {qty(it.month.quantity, it.unit)} / {qty(w.quantity, it.unit)}
        </span>
        <span className="block text-slate-500">
          {groupInt(w.buyers)} {plural(w.buyers, 'kupujący', 'kupujących', 'kupujących')}
          {w.first_sale_days != null && `, pierwsza sprzedaż ${days(w.first_sale_days)} od startu`}
        </span>
      </td>
      <td className="app-num p-1.5 text-right whitespace-nowrap tabular-nums">
        <b className="font-semibold text-slate-900">{itemFreedZl(it, it.month.freed_capital)}</b> / {itemFreedZl(it, w.freed_capital)}
        {it.cost_estimated && <span className="block text-amber-800">koszt szacowany</span>}
      </td>
      <td className="app-num p-1.5 text-right whitespace-nowrap tabular-nums">
        {it.month.margin == null ? <span className="text-slate-500">{NO_DATA}</span> : <span className={it.month.margin < 0 ? 'text-rose-700' : ''}>{fmtBigZl(it.month.margin)}</span>}
      </td>
      <td className={`app-num p-1.5 text-right whitespace-nowrap tabular-nums ${w.over_stock_quantity > 0 ? 'font-medium text-amber-800' : 'text-slate-400'}`}>
        {w.over_stock_quantity > 0 ? qty(w.over_stock_quantity, it.unit) : 'nie'}
      </td>
    </tr>
  )
}

/* ---------- Zastrzeżenia i reguła ---------- */

function NotesCard({ d }: { d: CampaignsReport }) {
  const w = d.warnings
  const notes: string[] = []
  // orzeczenie zgodne z liczbą: 1 pozycja ma, 2 pozycje mają, 5 pozycji ma
  const verb = (n: number, one: string, few: string) => plural(n, one, few, one)
  if (w.cost_estimated_lines > 0) {
    notes.push(
      `${lines(w.cost_estimated_lines)} ${verb(w.cost_estimated_lines, 'ma', 'mają')} koszt szacowany (ilość × koszt jednostki z dnia wysyłki), bo ERP XL nie podał kosztu — w kafelkach „w tym szacunek”.`,
    )
  }
  if (d.totals.lines_without_cost > 0) {
    const n = d.totals.lines_without_cost
    notes.push(`${lines(n)} nie ${verb(n, 'ma', 'mają')} kosztu ani w ERP XL, ani z dnia wysyłki — marża jest tam nieznana i nie wchodzi do marży.`)
  }
  if (w.multi_card_lines > 0) {
    notes.push(
      `${lines(w.multi_card_lines)} ${verb(w.multi_card_lines, 'dotyczy', 'dotyczą')} klientów dopasowanych po adresie e-mail, który jest na kilku kartach w ERP XL.`,
    )
  }
  if (w.over_stock_lines > 0) {
    notes.push(`${lines(w.over_stock_lines)} sprzedano częściowo ponad stan z dnia wysyłki — nadwyżka nie liczy się do uwolnionych pieniędzy.`)
  }
  if (w.unlinked_corrections > 0) {
    const n = w.unlinked_corrections
    notes.push(
      `${groupInt(n)} ${plural(n, 'korekta', 'korekty', 'korekt')} nie ${verb(n, 'ma', 'mają')} przypisanej faktury ani paragonu — nie ${verb(n, 'liczy', 'liczą')} się do wyników.`,
    )
  }
  if (w.freed_unknown_lines > 0) {
    const n = w.freed_unknown_lines
    notes.push(
      `${lines(n)} nie ${verb(n, 'wchodzi', 'wchodzą')} do uwolnionych pieniędzy, bo brakuje danych z dnia wysyłki (czy towar zalegał, jaki był stan) albo kosztu — uwolnione mogą być przez to zaniżone, nigdy zawyżone.`,
    )
  }
  if (w.no_snapshot_items > 0) {
    const n = w.no_snapshot_items
    notes.push(
      `${groupInt(n)} ${plural(n, 'towar', 'towary', 'towarów')} z kampanii sprzed raportu nie ${verb(n, 'ma', 'mają')} danych z dnia wysyłki — nie wiadomo, czy ${plural(n, 'zalegał', 'zalegały', 'zalegały')}, więc nie ${verb(n, 'liczy', 'liczą')} się do uwolnionych pieniędzy.`,
    )
  }
  return (
    <ReportCard title="Zastrzeżenia do danych i reguła liczenia" hint="Co w liczbach tego miesiąca jest szacunkiem albo zostało pominięte i według jakich zasad liczymy.">
      {notes.length === 0 ? (
        <SectionNote>Brak zastrzeżeń do danych tego miesiąca.</SectionNote>
      ) : (
        <ul className="mb-3 list-disc space-y-1 pl-5 text-xs text-slate-700">
          {notes.map((n) => (
            <li key={n}>{n}</li>
          ))}
        </ul>
      )}
      <p className="mt-3 text-xs leading-relaxed whitespace-pre-line text-slate-600">{d.rule}</p>
      <p className="mt-1.5 text-xs text-slate-500">
        Towar zalegający: w dniu wysyłki co najmniej {days(d.stagnant_days)} bez sprzedaży albo nigdy niesprzedany, gdy najstarsza partia leżała co
        najmniej tyle samo.
      </p>
    </ReportCard>
  )
}
