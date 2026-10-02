import { useState, type ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { BarList, ColumnChart, Insights, KpiRow, ReportCard, ReportFrame, Segmented, SectionNote, ShareCell, type Insight } from '../../components/ReportKit'
import { downloadFile } from '../../lib/api'
import { CAMPAIGN_STATUS_LABEL, type CampaignStatus } from '../../lib/campaigns'
import { errorText } from '../../lib/campaignFormat'
import { plural } from '../../lib/plural'
import {
  dayMonth,
  fmtBigZl,
  fmtDec,
  groupInt,
  sharePct,
  shareValue,
  shortDate,
  trimLeadingEmpty,
  useReportData,
  weekRange,
  type SalesReportData,
} from '../../lib/reports'
import { tenderStatusLabel } from '../../lib/tenderStatus'

/**
 * Raport „Sprzedaż i oferty” (GET /reports/sales?days=): zapytania klientów z poczty (odpowiedziano = oznaczone jako
 * wysłane w aplikacji; zegar od daty maila klienta; kopie tego samego maila u kilku osób liczą się raz), przetargi
 * (dotychczasowe zestawienia wg statusu i opiekuna + terminy na 14 dni, CSV) i wysłane kampanie (kliknięcia bez
 * botów — otwarć maili nie śledzimy). Każda sekcja tylko z uprawnieniem do jej danych.
 */

const PERIODS = [
  { value: 30, label: '30 dni' },
  { value: 90, label: '90 dni' },
  { value: 180, label: '180 dni' },
]

const CHANNEL_LABEL: Record<string, string> = { thunderbird: 'Thunderbird (dodatek)', web: 'Wklejone w panelu', file: 'Z pliku klienta' }

export function ReportSales() {
  const [days, setDays] = useState(90)
  const state = useReportData<SalesReportData>(`/reports/sales?days=${days}`)
  return (
    <ReportFrame state={state} toolbar={<Segmented label="Okres" options={PERIODS} value={days} onChange={setDays} />}>
      {(d) => <SalesBody d={d} />}
    </ReportFrame>
  )
}

function SectionTitle({ title, scope, aside }: { title: string; scope?: 'all' | 'own'; aside?: ReactNode }) {
  return (
    <div className="mt-6 mb-3 flex flex-wrap items-center justify-between gap-2 first:mt-0">
      <h2 className="flex items-center gap-2 text-base font-semibold text-slate-900">
        {title}
        {scope && (
          <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-normal text-slate-600">
            {scope === 'all' ? 'cały zespół' : 'tylko moje'}
          </span>
        )}
      </h2>
      {aside}
    </div>
  )
}

function insightsOf(d: SalesReportData): Insight[] {
  const out: Insight[] = []
  const q = d.inquiries
  if (q && q.totals.received > 0) {
    out.push({
      tone: shareValue(q.totals.replied, q.totals.received) >= 80 ? 'good' : 'warn',
      text: (
        <>
          Z {groupInt(q.totals.received)} {plural(q.totals.received, 'zapytania', 'zapytań', 'zapytań')} odpowiedź wysłano na{' '}
          <b>{sharePct(q.totals.replied, q.totals.received)}</b>
          {q.totals.replied > 0 && <>, z czego {sharePct(q.totals.replied_1bd, q.totals.replied)} w ciągu dnia roboczego</>}.
        </>
      ),
    })
    if (q.totals.waiting_over_1bd > 0) {
      out.push({
        tone: 'bad',
        text: (
          <>
            {groupInt(q.totals.waiting_over_1bd)} {plural(q.totals.waiting_over_1bd, 'zapytanie czeka', 'zapytania czekają', 'zapytań czeka')} na
            odpowiedź dłużej niż dzień roboczy.
          </>
        ),
      })
    }
  }
  const t = d.tenders
  if (t && t.upcoming.length > 0) {
    const first = t.upcoming[0]
    out.push({
      tone: first.days_left <= 3 ? 'bad' : 'warn',
      text: (
        <>
          {groupInt(t.upcoming.length)} {plural(t.upcoming.length, 'przetarg ma', 'przetargi mają', 'przetargów ma')} termin w ciągu 14 dni;
          najbliższy {first.number} — {first.days_left <= 0 ? 'dziś' : `za ${groupInt(first.days_left)} ${plural(first.days_left, 'dzień', 'dni', 'dni')}`}.
        </>
      ),
    })
  }
  const c = d.campaigns
  if (c && c.totals.sent > 0) {
    out.push({
      tone: 'neutral',
      text: (
        <>
          Kampanie: {groupInt(c.totals.sent)} wysłanych maili, kliknęło {sharePct(c.totals.clicked, c.totals.sent)} odbiorców, odpowiedzi:{' '}
          {groupInt(c.totals.replies)}.
        </>
      ),
    })
  }
  return out
}

function SalesBody({ d }: { d: SalesReportData }) {
  return (
    <>
      <Insights items={insightsOf(d)} />
      {d.inquiries && <InquiriesSection q={d.inquiries} />}
      {d.tenders && <TendersSection t={d.tenders} />}
      {d.campaigns && <CampaignsSection c={d.campaigns} />}
      {!d.inquiries && !d.tenders && !d.campaigns && <SectionNote>Brak uprawnień do zapytań, przetargów i kampanii.</SectionNote>}
    </>
  )
}

function InquiriesSection({ q }: { q: NonNullable<SalesReportData['inquiries']> }) {
  const t = q.totals
  return (
    <>
      <SectionTitle title="Zapytania klientów" scope={q.scope} />
      <KpiRow
        items={[
          { label: 'Zapytania', value: groupInt(t.received), sub: t.duplicates > 0 ? `+ ${groupInt(t.duplicates)} kopii u innych osób` : 'bez kopii' },
          {
            label: 'Odpowiedziane',
            value: sharePct(t.replied, t.received) ?? '—',
            meter: shareValue(t.replied, t.received),
            tone: shareValue(t.replied, t.received) >= 80 ? 'good' : 'warn',
            sub: `${groupInt(t.replied)} z ${groupInt(t.received)}`,
          },
          {
            label: 'W ciągu dnia roboczego',
            value: sharePct(t.replied_1bd, t.replied) ?? '—',
            meter: shareValue(t.replied_1bd, t.replied),
            tone: shareValue(t.replied_1bd, t.replied) >= 80 ? 'good' : 'warn',
            sub: 'odpowiedzi od daty maila klienta',
          },
          {
            label: 'Czekają na odpowiedź',
            value: groupInt(t.waiting),
            tone: t.waiting_over_1bd > 0 ? 'bad' : 'neutral',
            sub: `${groupInt(t.waiting_over_1bd)} dłużej niż dzień roboczy`,
          },
          {
            label: 'Analiza AI nieudana',
            value: groupInt(t.analysis_failed),
            tone: t.analysis_failed > 0 ? 'warn' : 'good',
            sub: `w kolejce Thunderbirda: ${groupInt(t.in_thunderbird)}`,
          },
        ]}
      />
      <div className="mb-4 grid gap-4 lg:grid-cols-3">
        <ReportCard
          className="lg:col-span-2"
          title="Zapytania i odpowiedzi — tydzień po tygodniu"
          hint="Odpowiedź liczona w tygodniu, w którym przyszło zapytanie. Odpowiedź wysłana poza aplikacją nie jest widoczna."
        >
          <ColumnChart
            label="Zapytania i odpowiedzi"
            mode="grouped"
            height={210}
            series={[
              { key: 'received', label: 'Zapytania', fill: 'fill-slate-400', swatch: 'bg-slate-400' },
              { key: 'replied', label: 'Odpowiedziane', fill: 'fill-blue-600', swatch: 'bg-blue-600' },
            ]}
            columns={trimLeadingEmpty(q.weekly, (w) => w.received === 0).map((w) => ({
              key: w.week_start,
              label: dayMonth(w.week_start),
              title: `Tydzień ${weekRange(w.week_start)}`,
              values: { received: w.received, replied: w.replied },
            }))}
          />
        </ReportCard>
        <ReportCard title="Skąd przychodzą">
          {q.channels.length === 0 ? (
            <SectionNote>Brak zapytań w okresie.</SectionNote>
          ) : (
            <BarList
              rows={q.channels.map((c) => ({ key: c.channel, label: CHANNEL_LABEL[c.channel] ?? c.channel, value: c.received }))}
              total={t.received}
            />
          )}
        </ReportCard>
      </div>
      {q.people && q.people.length > 0 && (
        <ReportCard
          className="mb-4"
          title="Obsługa wg osoby"
          hint="Zapytania osoby, która je wkleiła lub przyjęła z Thunderbirda. Mail przejęty przez kilka osób liczy się każdej z nich."
        >
          <div className="overflow-x-auto">
            <table className="app-table w-full min-w-[34rem] text-left text-xs">
              <thead>
                <tr className="border-b bg-slate-50 text-slate-500">
                  <th className="p-2 font-medium">Osoba</th>
                  <th className="p-2 text-right font-medium">Zapytań</th>
                  <th className="p-2 text-right font-medium">Odpowiedziane</th>
                  <th className="p-2 text-right font-medium">W dzień roboczy</th>
                  <th className="p-2 text-right font-medium">Czeka</th>
                </tr>
              </thead>
              <tbody>
                {q.people.map((p) => (
                  <tr key={p.user_id} className="border-b border-slate-100">
                    <td className="p-2 font-medium text-slate-800">{p.name}</td>
                    <td className="app-num p-2 text-right tabular-nums">{groupInt(p.received)}</td>
                    <td className="p-2 text-right">
                      <ShareCell part={p.replied} whole={p.received} />
                    </td>
                    <td className="p-2 text-right">{p.replied > 0 ? <ShareCell part={p.replied_1bd} whole={p.replied} /> : '—'}</td>
                    <td className={`app-num p-2 text-right tabular-nums ${p.waiting > 0 ? 'font-semibold text-amber-800' : 'text-slate-400'}`}>
                      {groupInt(p.waiting)}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </ReportCard>
      )}
    </>
  )
}

function TendersSection({ t }: { t: NonNullable<SalesReportData['tenders']> }) {
  const [csvError, setCsvError] = useState('')
  const total = t.by_status.reduce((s, r) => s + r.count, 0)
  const exportCsv = async () => {
    setCsvError('')
    try {
      await downloadFile('/reports/csv', 'raport-przetargi.csv')
    } catch (ex) {
      setCsvError(errorText(ex, 'Nie udało się pobrać pliku CSV.'))
    }
  }
  return (
    <>
      <SectionTitle
        title="Przetargi"
        scope={t.scope}
        aside={
          <button type="button" onClick={() => void exportCsv()} className="rounded-md bg-emerald-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-emerald-700">
            Eksport CSV
          </button>
        }
      />
      {csvError && (
        <p className="mb-2 text-xs text-red-700" role="alert">
          {csvError}
        </p>
      )}
      <p className="-mt-2 mb-3 text-xs text-slate-500">Wszystkie przetargi w toku i zakończone — przełącznik okresu ich nie dotyczy.</p>
      {total === 0 ? (
        <SectionNote>Brak przetargów.</SectionNote>
      ) : (
        <>
          <ol className="mb-4 grid grid-cols-2 gap-2 sm:grid-cols-3 xl:grid-cols-6">
            {t.by_status.map((s) => (
              <li key={s.status} className="app-report-stage rounded-xl bg-white p-3 shadow-sm">
                <span className="block truncate text-xs text-slate-500">{tenderStatusLabel(s.status)}</span>
                <b className="block text-xl font-semibold text-slate-900">{groupInt(s.count)}</b>
                <span className="block text-xs text-slate-600">{fmtBigZl(s.offer_value_net)}</span>
                <span className="block text-[11px] text-slate-500">{s.avg_margin == null ? 'marża —' : `marża ${fmtDec(s.avg_margin)}%`}</span>
              </li>
            ))}
          </ol>
          <div className="mb-4 grid gap-4 lg:grid-cols-2">
            <ReportCard title="Terminy w ciągu 14 dni" hint="Przetargi w toku (bez wyeksportowanych, odrzuconych i archiwalnych).">
              {t.upcoming.length === 0 ? (
                <SectionNote>Żaden przetarg w toku nie ma terminu w najbliższych 14 dniach.</SectionNote>
              ) : (
                <ul className="divide-y divide-slate-100 text-xs">
                  {t.upcoming.map((u) => (
                    <li key={u.id} className="flex items-center justify-between gap-3 py-1.5">
                      <div className="min-w-0">
                        <Link to={`/tenders/${u.id}`} className="app-link block truncate font-medium text-blue-700 hover:underline" title={u.title}>
                          {u.number} · {u.title}
                        </Link>
                        <span className="text-slate-500">
                          {u.client ?? 'bez klienta'} · {tenderStatusLabel(u.status)}
                          {u.owner_name ? ` · ${u.owner_name}` : ''}
                        </span>
                      </div>
                      <span className="shrink-0 text-right">
                        <span
                          className={`block rounded-full px-2 py-0.5 text-[11px] font-medium whitespace-nowrap ${
                            u.days_left <= 3 ? 'bg-red-50 text-red-800' : 'bg-amber-50 text-amber-800'
                          }`}
                        >
                          {u.days_left <= 0 ? 'dziś' : `za ${groupInt(u.days_left)} ${plural(u.days_left, 'dzień', 'dni', 'dni')}`}
                        </span>
                        <span className="mt-0.5 block text-[11px] text-slate-500">{shortDate(u.deadline)}</span>
                      </span>
                    </li>
                  ))}
                </ul>
              )}
            </ReportCard>
            <ReportCard title="Wg opiekuna" hint="Wszystkie przetargi (bez względu na okres): wartość ofert netto i marża średnia ważona wartością.">
              <table className="app-table w-full text-left text-xs">
                <thead>
                  <tr className="border-b bg-slate-50 text-slate-500">
                    <th className="p-2 font-medium">Opiekun</th>
                    <th className="p-2 text-right font-medium">Przetargów</th>
                    <th className="p-2 text-right font-medium">Wartość</th>
                    <th className="p-2 text-right font-medium">Marża</th>
                  </tr>
                </thead>
                <tbody>
                  {t.by_owner.map((o) => (
                    <tr key={o.owner_id ?? 'none'} className="border-b border-slate-100">
                      <td className="p-2 font-medium text-slate-800">{o.owner_name}</td>
                      <td className="app-num p-2 text-right tabular-nums">{groupInt(o.count)}</td>
                      <td className="app-num p-2 text-right whitespace-nowrap tabular-nums">{fmtBigZl(o.offer_value_net)}</td>
                      <td className="app-num p-2 text-right tabular-nums">{o.avg_margin == null ? '—' : `${fmtDec(o.avg_margin)}%`}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </ReportCard>
          </div>
        </>
      )}
    </>
  )
}

function CampaignsSection({ c }: { c: NonNullable<SalesReportData['campaigns']> }) {
  return (
    <>
      <SectionTitle title="Kampanie" scope={c.scope} />
      <ReportCard
        title="Wysłane kampanie w okresie"
        hint="Kliknęli = odbiorcy, którzy kliknęli w link (bez automatów). Kupili = odbiorcy z fakturą na towar kampanii w ERP XL w ciągu 30 dni od wysyłki („na razie” — okres jeszcze trwa)."
      >
        {c.rows.length === 0 ? (
          <SectionNote>Brak wysłanych kampanii w okresie.</SectionNote>
        ) : (
          <div className="overflow-x-auto">
            <table className="app-table w-full min-w-[46rem] text-left text-xs">
              <thead>
                <tr className="border-b bg-slate-50 text-slate-500">
                  <th className="p-2 font-medium">Kampania</th>
                  <th className="p-2 text-right font-medium">Wysłane</th>
                  <th className="p-2 text-right font-medium">Kliknęli</th>
                  <th className="p-2 text-right font-medium">Odpowiedzi</th>
                  <th className="p-2 text-right font-medium">Wypisani</th>
                  <th className="p-2 text-right font-medium">Kupili</th>
                  <th className="p-2 text-right font-medium">Sprzedaż netto</th>
                </tr>
              </thead>
              <tbody>
                {c.rows.map((r) => (
                  <tr key={r.id} className="border-b border-slate-100">
                    <td className="w-[38%] max-w-0 p-2">
                      <Link to={`/kampanie/${r.id}`} className="app-link block truncate font-medium text-blue-700 hover:underline" title={r.name}>
                        {r.name}
                      </Link>
                      <span className="text-[11px] text-slate-500">
                        <span className="app-code">{r.code}</span> · {CAMPAIGN_STATUS_LABEL[r.status as CampaignStatus] ?? r.status} ·{' '}
                        {shortDate(r.started_at)}
                      </span>
                    </td>
                    <td className="app-num p-2 text-right tabular-nums">{groupInt(r.sent)}</td>
                    <td className="p-2 text-right">
                      <ShareCell part={r.clicked} whole={r.sent} tone="neutral" />
                    </td>
                    <td className="app-num p-2 text-right tabular-nums">{groupInt(r.replies)}</td>
                    <td className="app-num p-2 text-right tabular-nums">{groupInt(r.unsubscribed)}</td>
                    <td className="app-num p-2 text-right tabular-nums">{r.buyers == null ? '—' : groupInt(r.buyers)}</td>
                    <td className="app-num p-2 text-right whitespace-nowrap tabular-nums">
                      {r.sales_net == null ? '—' : fmtBigZl(r.sales_net)}
                      {r.sales_complete === false && (
                        <span className="block text-[11px] font-normal text-slate-500" title="Sprzedaż liczona przez 30 dni od wysyłki">
                          na razie
                        </span>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </ReportCard>
    </>
  )
}
