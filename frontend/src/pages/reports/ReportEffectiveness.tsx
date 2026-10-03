import { useState } from 'react'
import { Link } from 'react-router-dom'
import { BarList, ReportCard, ReportFrame, Segmented, SectionNote } from '../../components/ReportKit'
import { downloadFile } from '../../lib/api'
import { errorText } from '../../lib/campaignFormat'
import { plural } from '../../lib/plural'
import { fmtDec, groupInt, useReportData, type EffectivenessPeriod, type EffectivenessReportData, type Tone } from '../../lib/reports'
import { formatDeadline } from '../../lib/tenderDeadline'

/**
 * Raport „Skuteczność przetargów” (GET /reports/effectiveness?period=, ekran 6 makiety): na górze jedno zdanie
 * z wnioskiem, pod nim według opiekuna i powody przegranych, w zwijanej sekcji konkurenci i rodzaje towaru, osobno
 * części unieważnione i przetargi bez wpisanego wyniku. Liczy części zamówień; okres po dacie terminu składania ofert.
 * Eksport do Excela: CSV ze średnikiem, wiersz na część.
 */

const PERIODS: { value: EffectivenessPeriod; label: string }[] = [
  { value: '90d', label: 'Ostatnie 90 dni' },
  { value: 'year', label: 'Ten rok' },
]

const PERIOD_PHRASE: Record<EffectivenessPeriod, string> = {
  '90d': 'Przez ostatnie 90 dni',
  year: 'W tym roku',
}

const EMPTY_PHRASE: Record<EffectivenessPeriod, string> = {
  '90d': 'W ostatnich 90 dniach',
  year: 'W tym roku',
}

/** Odmiana powodu przegranej w zdaniu „Najczęściej przegrywamy …”. */
const REASON_PHRASE: Record<string, string> = {
  price: 'ceną',
  requirement: 'na niespełnionym wymaganiu',
  delivery: 'terminem dostawy',
  formal: 'na błędzie formalnym',
  other: 'z innego powodu',
}

const BAR_FILL: Record<Tone, string> = {
  neutral: 'bg-blue-600',
  good: 'bg-teal-600',
  warn: 'bg-amber-500',
  bad: 'bg-rose-700',
}

/** Skuteczność: od 50% dobrze, od 33% uwaga, niżej źle. */
function rateTone(rate: number | null): Tone {
  if (rate == null) return 'neutral'
  return rate >= 50 ? 'good' : rate >= 33 ? 'warn' : 'bad'
}

function pct(rate: number | null): string {
  return rate == null ? '—' : `${fmtDec(rate)}%`
}

export function ReportEffectiveness() {
  const [period, setPeriod] = useState<EffectivenessPeriod>('90d')
  const [csvError, setCsvError] = useState('')
  const state = useReportData<EffectivenessReportData>(`/reports/effectiveness?period=${period}`)

  const exportCsv = async () => {
    setCsvError('')
    try {
      await downloadFile(`/reports/effectiveness/csv?period=${period}`, 'skutecznosc-przetargow.csv')
    } catch (ex) {
      setCsvError(errorText(ex, 'Nie udało się pobrać pliku do Excela.'))
    }
  }

  return (
    <ReportFrame
      state={state}
      toolbar={
        <>
          <Segmented label="Okres" options={PERIODS} value={period} onChange={setPeriod} />
          <button
            type="button"
            onClick={() => void exportCsv()}
            className="rounded-md bg-emerald-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-emerald-700"
          >
            Eksport do Excela
          </button>
          {csvError && (
            <span className="text-xs text-red-700" role="alert">
              {csvError}
            </span>
          )}
        </>
      }
      note="okres według terminu składania ofert"
    >
      {(d) => <EffectivenessBody d={d} />}
    </ReportFrame>
  )
}

function EffectivenessBody({ d }: { d: EffectivenessReportData }) {
  return (
    <>
      <Conclusion d={d} />
      <div className="mb-4 grid gap-4 lg:grid-cols-2">
        <OwnersCard d={d} />
        <ReasonsCard d={d} />
      </div>
      <details className="app-card mb-4 rounded-xl bg-white p-4 shadow-sm">
        <summary className="cursor-pointer text-sm font-semibold text-slate-900">Konkurenci i rodzaje towaru</summary>
        <div className="mt-3 grid gap-4 lg:grid-cols-2">
          <CompetitorsTable d={d} />
          <CategoriesTable d={d} />
        </div>
      </details>
      <div className="grid gap-4 lg:grid-cols-2">
        <NoResultCard d={d} />
        <CancelledCard d={d} />
      </div>
    </>
  )
}

/** Zdanie-wniosek z liczb raportu (bez ocen ponad dane) i linijka o tym, co poza liczeniem. */
function Conclusion({ d }: { d: EffectivenessReportData }) {
  const s = d.summary
  const parts: string[] = []
  if (s.decided_lots === 0) {
    parts.push(`${EMPTY_PHRASE[d.period]} nie ma jeszcze rozstrzygniętych części zamówień z wpisanym wynikiem.`)
  } else {
    parts.push(
      `${PERIOD_PHRASE[d.period]} wygraliśmy ${groupInt(s.won_lots)} z ${groupInt(s.decided_lots)} ${plural(s.decided_lots, 'rozstrzygniętej części', 'rozstrzygniętych części', 'rozstrzygniętych części')} zamówień (${pct(s.win_rate)}).`,
    )
    if (s.top_loss_reason) {
      parts.push(
        `Najczęściej przegrywamy ${REASON_PHRASE[s.top_loss_reason.reason] ?? 'z innego powodu'} (${groupInt(s.top_loss_reason.count)} z ${groupInt(s.lost_lots)} ${plural(s.lost_lots, 'przegranej', 'przegranych', 'przegranych')}).`,
      )
    } else if (s.lost_lots > 0) {
      parts.push(`Przy ${groupInt(s.lost_lots)} ${plural(s.lost_lots, 'przegranej', 'przegranych', 'przegranych')} nie wpisano powodu.`)
    }
    if (s.avg_price_gap_percent != null && s.price_gap_lots > 0) {
      const gap = s.avg_price_gap_percent
      parts.push(
        gap === 0
          ? `W przegranych częściach, w których znamy obie ceny (${groupInt(s.price_gap_lots)}), nasza cena brutto była średnio równa cenie zwycięzcy (cenę zwycięzcy z ogłoszenia przyjmujemy jako cenę z podatkiem VAT).`
          : `W przegranych częściach, w których znamy obie ceny (${groupInt(s.price_gap_lots)}), byliśmy średnio o ${fmtDec(Math.abs(gap))}% ${gap > 0 ? 'drożsi' : 'tańsi'} od zwycięzcy (cenę zwycięzcy z ogłoszenia przyjmujemy jako cenę z podatkiem VAT).`,
      )
    }
    if (s.weakest_category) {
      parts.push(
        `Najsłabiej idzie nam: ${s.weakest_category.label.toLowerCase()} — ${pct(s.weakest_category.win_rate)} wygranych z ${groupInt(s.weakest_category.decided)} rozstrzygniętych części.`,
      )
    }
  }

  const outside: string[] = []
  if (s.cancelled_lots > 0) outside.push(`${groupInt(s.cancelled_lots)} ${plural(s.cancelled_lots, 'unieważniona część', 'unieważnione części', 'unieważnionych części')}`)
  if (s.not_submitted_lots > 0) {
    outside.push(`${groupInt(s.not_submitted_lots)} ${plural(s.not_submitted_lots, 'część', 'części', 'części')} bez naszej oferty`)
  }
  if (s.no_result_tenders > 0) {
    const who = d.no_result_by_owner.map((o) => `${o.owner_name} ${groupInt(o.count)}`).join(', ')
    outside.push(
      `${groupInt(s.no_result_tenders)} ${plural(s.no_result_tenders, 'przetarg', 'przetargi', 'przetargów')} bez wpisanego wyniku${who ? ` (${who})` : ''}`,
    )
  }

  return (
    <section className="app-card mb-4 rounded-xl bg-white p-4 shadow-sm" aria-label="Wniosek">
      <p className="text-base leading-relaxed text-slate-900">{parts.join(' ')}</p>
      {outside.length > 0 && <p className="mt-1.5 text-sm text-slate-500">Poza tym liczeniem: {outside.join(', ')}.</p>}
      <p className="mt-1.5 text-xs text-slate-500">
        {d.scope === 'all' ? 'Wszystkie przetargi zespołu' : 'Tylko Twoje przetargi i te, do których Cię zaproszono'}
        {' · '}termin składania ofert od {formatDeadline(d.from)} do {formatDeadline(d.to)}. Liczy się każda część zamówienia osobno.
      </p>
    </section>
  )
}

function OwnersCard({ d }: { d: EffectivenessReportData }) {
  return (
    <ReportCard title="Według opiekuna" hint="Rozstrzygnięte = wygrane i przegrane części.">
      {d.by_owner.length === 0 ? (
        <SectionNote>Brak rozstrzygniętych części w tym okresie.</SectionNote>
      ) : (
        <div className="overflow-x-auto">
          <table className="app-table w-full min-w-[22rem] text-left text-xs">
            <thead>
              <tr className="border-b bg-slate-50 text-slate-500">
                <th className="p-2 font-medium">Opiekun</th>
                <th className="p-2 text-right font-medium">Rozstrzygnięte</th>
                <th className="p-2 text-right font-medium">Wygrane</th>
                <th className="w-[34%] p-2 font-medium">Skuteczność</th>
              </tr>
            </thead>
            <tbody>
              {d.by_owner.map((o) => (
                <tr key={o.owner_id ?? 'none'} className="border-b border-slate-100">
                  <td className="p-2 font-medium text-slate-800">{o.owner_name}</td>
                  <td className="app-num p-2 text-right tabular-nums">{groupInt(o.decided)}</td>
                  <td className="app-num p-2 text-right tabular-nums">{groupInt(o.won)}</td>
                  <td className="p-2">
                    <span className="flex items-center gap-2">
                      <span className="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-100" aria-hidden>
                        <span className={`block h-full rounded-full ${BAR_FILL[rateTone(o.win_rate)]}`} style={{ width: `${Math.max(1.5, o.win_rate ?? 0)}%` }} />
                      </span>
                      <span className="w-12 text-right tabular-nums">{pct(o.win_rate)}</span>
                    </span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </ReportCard>
  )
}

function ReasonsCard({ d }: { d: EffectivenessReportData }) {
  const lost = d.summary.lost_lots
  return (
    <ReportCard
      title="Dlaczego przegrywamy"
      hint={lost > 0 ? `${groupInt(lost)} ${plural(lost, 'przegrana część', 'przegrane części', 'przegranych części')} — powód wpisuje opiekun w wyniku przetargu.` : undefined}
    >
      {d.loss_reasons.length === 0 ? (
        <SectionNote>Brak przegranych części w tym okresie.</SectionNote>
      ) : (
        <BarList
          fill="bg-rose-700"
          total={lost}
          rows={d.loss_reasons.map((r) => ({
            key: r.reason ?? 'none',
            label: r.reason == null ? <span className="text-slate-500">{r.label}</span> : r.label,
            value: r.count,
          }))}
        />
      )}
    </ReportCard>
  )
}

function CompetitorsTable({ d }: { d: EffectivenessReportData }) {
  return (
    <div className="min-w-0">
      <h3 className="mb-2 text-sm font-semibold text-slate-900">Kto z nami wygrywa</h3>
      {d.competitors.length === 0 ? (
        <SectionNote>Przy przegranych częściach nie ma jeszcze wpisanego zwycięzcy.</SectionNote>
      ) : (
        <div className="overflow-x-auto">
          <table className="app-table w-full min-w-[20rem] text-left text-xs">
            <thead>
              <tr className="border-b bg-slate-50 text-slate-500">
                <th className="p-2 font-medium">Firma</th>
                <th className="p-2 text-right font-medium">Wygrali z nami</th>
                <th className="p-2 text-right font-medium" title="Cena zwycięzcy wobec naszej ceny brutto — tylko części, w których znamy obie ceny. Cenę zwycięzcy z ogłoszenia przyjmujemy jako cenę z podatkiem VAT.">
                  Średnio taniej
                </th>
              </tr>
            </thead>
            <tbody>
              {d.competitors.map((c) => (
                <tr key={c.competitor_id} className="border-b border-slate-100">
                  <td className="p-2">
                    <span className="font-medium text-slate-800">{c.name}</span>
                    {c.nip && <span className="block text-[11px] text-slate-500">NIP {c.nip}</span>}
                  </td>
                  <td className="app-num p-2 text-right tabular-nums">{groupInt(c.won_against_us)}</td>
                  <td className="app-num p-2 text-right tabular-nums">
                    {c.avg_cheaper_percent == null
                      ? '—'
                      : c.avg_cheaper_percent < 0
                        ? `drożej o ${fmtDec(Math.abs(c.avg_cheaper_percent))}%`
                        : `${fmtDec(c.avg_cheaper_percent)}%`}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}

function CategoriesTable({ d }: { d: EffectivenessReportData }) {
  return (
    <div className="min-w-0">
      <h3 className="mb-2 text-sm font-semibold text-slate-900">Według rodzaju towaru w części</h3>
      {d.categories.length === 0 ? (
        <SectionNote>Rozstrzygnięte części nie mają kodu CPV, więc rodzaju towaru nie da się ustalić.</SectionNote>
      ) : (
        <div className="overflow-x-auto">
          <table className="app-table w-full min-w-[18rem] text-left text-xs">
            <thead>
              <tr className="border-b bg-slate-50 text-slate-500">
                <th className="p-2 font-medium">Rodzaj</th>
                <th className="p-2 text-right font-medium">Rozstrzygnięte</th>
                <th className="p-2 text-right font-medium">Skuteczność</th>
              </tr>
            </thead>
            <tbody>
              {d.categories.map((c) => {
                const tone = c.decided >= 3 ? rateTone(c.win_rate) : 'neutral'
                return (
                  <tr key={c.key} className="border-b border-slate-100">
                    <td className="p-2 font-medium text-slate-800">{c.label}</td>
                    <td className="app-num p-2 text-right tabular-nums">{groupInt(c.decided)}</td>
                    <td
                      className={`app-num p-2 text-right tabular-nums ${tone === 'good' ? 'text-teal-700' : tone === 'bad' ? 'text-rose-700' : 'text-slate-800'}`}
                    >
                      {pct(c.win_rate)}
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      )}
      {d.categories_note && <p className="mt-2 text-[11px] text-slate-500">{d.categories_note}</p>}
    </div>
  )
}

function NoResultCard({ d }: { d: EffectivenessReportData }) {
  const total = d.summary.no_result_tenders
  return (
    <ReportCard
      title="Bez wpisanego wyniku"
      hint="Termin składania ofert minął, a wynik nie jest wpisany (bez szkiców i odrzuconych). Nie liczą się do skuteczności."
    >
      {d.no_result.length === 0 ? (
        <SectionNote>Wszystkie przetargi po terminie mają wpisany wynik.</SectionNote>
      ) : (
        <>
          <ul className="divide-y divide-slate-100 text-xs">
            {d.no_result.map((t) => (
              <li key={t.tender_id} className="flex items-center justify-between gap-3 py-1.5">
                <div className="min-w-0">
                  <Link to={t.url} className="app-link block truncate font-medium text-blue-700 hover:underline" title={t.title}>
                    {t.number} · {t.title}
                  </Link>
                  <span className="text-slate-500">
                    {t.owner_name}
                    {t.notice_number ? ` · ogłoszenie ${t.notice_number}` : ''}
                  </span>
                </div>
                <span className="shrink-0 text-right text-[11px] text-slate-500">termin {formatDeadline(t.deadline, t.deadline_time)}</span>
              </li>
            ))}
          </ul>
          {total > d.no_result.length && (
            <p className="mt-2 text-[11px] text-slate-500">
              Pokazano {groupInt(d.no_result.length)} najstarszych z {groupInt(total)}.
            </p>
          )}
        </>
      )}
    </ReportCard>
  )
}

function CancelledCard({ d }: { d: EffectivenessReportData }) {
  return (
    <ReportCard title="Unieważnione części" hint="Zamawiający unieważnił postępowanie albo jego część. Nie liczą się do skuteczności.">
      {d.cancelled.length === 0 ? (
        <SectionNote>Brak unieważnionych części w tym okresie.</SectionNote>
      ) : (
        <ul className="divide-y divide-slate-100 text-xs">
          {d.cancelled.map((c) => (
            <li key={`${c.tender_id}-${c.lot_no}`} className="flex items-center justify-between gap-3 py-1.5">
              <div className="min-w-0">
                <Link to={c.url} className="app-link block truncate font-medium text-blue-700 hover:underline" title={c.title}>
                  {c.number} · {c.title}
                </Link>
                <span className="block truncate text-slate-500" title={c.lot_name ?? undefined}>
                  Część {c.lot_no}
                  {c.lot_name ? ` · ${c.lot_name}` : ''} · {c.owner_name}
                </span>
              </div>
              <span className="shrink-0 text-right text-[11px] text-slate-500">termin {formatDeadline(c.deadline, c.deadline_time)}</span>
            </li>
          ))}
        </ul>
      )}
    </ReportCard>
  )
}
