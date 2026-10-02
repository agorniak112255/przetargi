import { useState } from 'react'
import { Link } from 'react-router-dom'
import { DivergingChart, Insights, KpiRow, ReportCard, ReportFrame, Segmented, SectionNote, type Insight } from '../../components/ReportKit'
import { plural } from '../../lib/plural'
import {
  dayMonth,
  fmtPrice,
  groupInt,
  longDate,
  shortDate,
  signedPct,
  useReportData,
  weekRange,
  type PriceMove,
  type PricesReportData,
} from '../../lib/reports'

/**
 * Raport „Ruchy cen” (GET /reports/prices?days=): zmiany cen u dostawców. Zmiana = nowa cena źródła różna od jego
 * poprzedniej (w tej samej walucie). Osobno: nowe ceny źródeł (pierwszy zapis — nie zmiana), nasze zmiany rabatu
 * i skoki podejrzane o inną jednostkę (×4 i więcej) — poza medianą i listami największych zmian.
 */

const PERIODS = [
  { value: 7, label: '7 dni' },
  { value: 30, label: '30 dni' },
  { value: 90, label: '90 dni' },
]

export function ReportPrices() {
  const [days, setDays] = useState(30)
  const state = useReportData<PricesReportData>(`/reports/prices?days=${days}`)
  return (
    <ReportFrame
      state={state}
      note="dane odświeżane co 10 min"
      toolbar={<Segmented label="Okres" options={PERIODS} value={days} onChange={setDays} />}
    >
      {(d) => <PricesBody d={d} />}
    </ReportFrame>
  )
}

/** Istotne (≥ 1%) podwyżki i obniżki — suma tygodni (seria obejmuje cały okres). */
function significant(d: PricesReportData) {
  return d.weekly.reduce((acc, w) => ({ up: acc.up + w.increases, down: acc.down + w.decreases }), { up: 0, down: 0 })
}

function insightsOf(d: PricesReportData): Insight[] {
  const t = d.totals
  const out: Insight[] = []
  const sig = significant(d)
  if (t.changes === 0 && t.suspicious === 0) {
    out.push({ tone: 'neutral', text: <>W tym okresie dostawcy nie zmienili żadnej ceny.</> })
    return out
  }
  if (t.changes > 0) out.push({
    tone: sig.up > sig.down ? 'warn' : 'good',
    text: (
      <>
        {groupInt(t.changes)} {plural(t.changes, 'zmiana', 'zmiany', 'zmian')} cen na {groupInt(t.products)}{' '}
        {plural(t.products, 'karcie', 'kartach', 'kartach')}
        {t.minor_changes > 0 && <>, z czego {groupInt(t.minor_changes)} to drobne korekty poniżej 1%</>}. Istotnych podwyżek:{' '}
        <b>{groupInt(sig.up)}</b>, obniżek: <b>{groupInt(sig.down)}</b>.
      </>
    ),
  })
  // serwer sortuje źródła wg zmian istotnych; podwyżki/obniżki w wierszu są już bez drobnych korekt
  const topSource = d.sources[0]
  if (topSource && topSource.increases + topSource.decreases > 0) {
    out.push({
      tone: 'neutral',
      text: (
        <>
          Najwięcej istotnych zmian: <b>{topSource.source_label}</b> — ↑ {groupInt(topSource.increases)}, ↓ {groupInt(topSource.decreases)} (typowo{' '}
          {signedPct(topSource.median_pct)}).
        </>
      ),
    })
  }
  const big = d.top_increases[0]
  if (big) {
    out.push({
      tone: 'warn',
      text: (
        <>
          Największa podwyżka: {big.name} ({big.manufacturer ?? 'bez producenta'}) {signedPct(big.pct)}.
        </>
      ),
    })
  }
  if (t.suspicious > 0) {
    out.push({
      tone: 'bad',
      text: (
        <>
          {groupInt(t.suspicious)} {plural(t.suspicious, 'skok', 'skoki', 'skoków')} ceny co najmniej czterokrotny — prawdopodobnie inna jednostka
          (opakowanie / sztuka); do sprawdzenia na karcie.
        </>
      ),
    })
  }
  return out
}

function PricesBody({ d }: { d: PricesReportData }) {
  const t = d.totals
  const sig = significant(d)
  const historyNote =
    d.history_since && d.history_since > d.from ? `Historia cen jest zapisywana od ${longDate(d.history_since)} — wcześniejszych zmian nie ma w danych.` : null
  return (
    <>
      {(historyNote || d.masked) && (
        <p className="mb-3 rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-xs text-sky-800">
          {historyNote}
          {historyNote && d.masked ? ' ' : ''}
          {d.masked && 'Bez zmian kont B2B z ceną specjalną dostawcy — widzi je tylko osoba z uprawnieniem do cen specjalnych.'}
        </p>
      )}
      <Insights items={insightsOf(d)} />
      <KpiRow
        items={[
          { label: 'Zmiany cen', value: groupInt(t.changes), sub: `${groupInt(t.products)} kart · ${groupInt(t.sources)} źródeł` },
          { label: 'Podwyżki ≥ 1%', value: groupInt(sig.up), tone: sig.up > 0 ? 'warn' : 'neutral', sub: `wszystkich podwyżek: ${groupInt(t.increases)}` },
          { label: 'Obniżki ≥ 1%', value: groupInt(sig.down), tone: sig.down > 0 ? 'good' : 'neutral', sub: `wszystkich obniżek: ${groupInt(t.decreases)}` },
          { label: 'Drobne korekty', value: groupInt(t.minor_changes), sub: 'zmiana poniżej 1% (zaokrąglenia, kurs)' },
          {
            label: 'Typowa zmiana zakupu',
            value: signedPct(t.median_pct),
            sub: t.q1_pct != null && t.q3_pct != null ? `połowa zmian: ${signedPct(t.q1_pct)} … ${signedPct(t.q3_pct)}` : 'mediana',
          },
          {
            label: 'Nowe ceny źródeł',
            value: groupInt(t.additions),
            sub: `pierwszy zapis ceny · rabaty: ${groupInt(t.discount_changes)}`,
          },
        ]}
      />

      <div className="mb-4 grid gap-4 lg:grid-cols-3">
        <ReportCard className="lg:col-span-2" title="Podwyżki i obniżki — tydzień po tygodniu" hint="Zmiany o co najmniej 1%, bez drobnych korekt i bez skoków podejrzanych o inną jednostkę.">
          <DivergingChart
            label="Podwyżki i obniżki cen"
            up={{ key: 'up', label: 'Podwyżki', fill: 'fill-orange-600', swatch: 'bg-orange-600' }}
            down={{ key: 'down', label: 'Obniżki', fill: 'fill-sky-600', swatch: 'bg-sky-600' }}
            columns={d.weekly.map((w) => ({
              key: w.week_start,
              label: dayMonth(w.week_start),
              title: `Tydzień ${weekRange(w.week_start)}`,
              up: w.increases,
              down: w.decreases,
            }))}
          />
        </ReportCard>
        <ReportCard title="Producenci z największą liczbą istotnych zmian" hint="Zmiany o co najmniej 1%; po prawej typowa zmiana ceny zakupu.">
          {d.manufacturers.length === 0 ? (
            <SectionNote>Brak zmian w okresie.</SectionNote>
          ) : (
            <MovesBalance rows={d.manufacturers.slice(0, 10).map((m) => ({ key: m.manufacturer, label: m.manufacturer, ...m }))} />
          )}
        </ReportCard>
      </div>

      <ReportCard className="mb-4" title="Źródła cen" hint="Cennik z pliku albo konto B2B, z którego przyszła zmiana. Mediana liczona od ceny zakupu.">
        {d.sources.length === 0 ? (
          <SectionNote>Brak zmian w okresie.</SectionNote>
        ) : (
          <div className="overflow-x-auto">
            <table className="app-table w-full min-w-[36rem] text-left text-xs">
              <thead>
                <tr className="border-b bg-slate-50 text-slate-500">
                  <th className="p-2 font-medium">Źródło</th>
                  <th className="p-2 text-right font-medium">Zmian</th>
                  <th className="p-2 font-medium">W górę / w dół (≥ 1%)</th>
                  <th className="p-2 text-right font-medium">Drobne &lt; 1%</th>
                  <th className="p-2 text-right font-medium">Mediana</th>
                  <th className="p-2 text-right font-medium">Największa podwyżka</th>
                </tr>
              </thead>
              <tbody>
                {d.sources.map((s) => (
                  <tr key={s.source_label} className="border-b border-slate-100">
                    <td className="p-2 font-medium text-slate-800">{s.source_label}</td>
                    <td className="app-num p-2 text-right tabular-nums">{groupInt(s.changes)}</td>
                    <td className="p-2">
                      <SplitBar up={s.increases} down={s.decreases} />
                    </td>
                    <td className="app-num p-2 text-right text-slate-500 tabular-nums">{groupInt(s.minor)}</td>
                    <td className="app-num p-2 text-right tabular-nums">{signedPct(s.median_pct)}</td>
                    <td className="app-num p-2 text-right tabular-nums">{s.max_pct != null && s.max_pct > 0 ? signedPct(s.max_pct) : '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </ReportCard>

      <div className="mb-4 grid gap-4 lg:grid-cols-2">
        <MovesList title="Największe podwyżki" moves={d.top_increases} empty="Brak podwyżek w okresie." />
        <MovesList title="Największe obniżki" moves={d.top_decreases} empty="Brak obniżek w okresie." />
      </div>
      {d.suspicious.length > 0 && (
        <MovesList
          title="Skoki do sprawdzenia"
          hint="Nowa cena co najmniej 4 razy wyższa albo niższa od poprzedniej — zwykle zmiana jednostki (karton / sztuka) albo pomyłka w cenniku."
          moves={d.suspicious}
          empty=""
        />
      )}
    </>
  )
}

/** Proporcja podwyżek do obniżek jednym paskiem (pomarańczowy w górę, niebieski w dół) z liczbami obok. */
function SplitBar({ up, down }: { up: number; down: number }) {
  const total = up + down
  return (
    <span className="inline-flex items-center gap-2">
      <span className="flex h-2 w-24 gap-0.5 overflow-hidden rounded-full bg-slate-100" aria-hidden>
        {up > 0 && <span className="h-full rounded-l-full bg-orange-600" style={{ width: `${(up / Math.max(1, total)) * 100}%` }} />}
        {down > 0 && <span className="h-full rounded-r-full bg-sky-600" style={{ width: `${(down / Math.max(1, total)) * 100}%` }} />}
      </span>
      <span className="whitespace-nowrap tabular-nums text-slate-600">
        ↑ {groupInt(up)} · ↓ {groupInt(down)}
      </span>
    </span>
  )
}

function MovesBalance({ rows }: { rows: { key: string; label: string; changes: number; increases: number; decreases: number; median_pct: number | null }[] }) {
  return (
    <ul className="grid gap-2 text-xs">
      {rows.map((r) => (
        <li key={r.key} className="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3">
          <span className="truncate font-medium text-slate-800" title={r.label}>
            {r.label}
          </span>
          <span className="tabular-nums text-slate-600">{signedPct(r.median_pct)}</span>
          <span className="col-span-2">
            <SplitBar up={r.increases} down={r.decreases} />
          </span>
        </li>
      ))}
    </ul>
  )
}

function MovesList({ title, hint, moves, empty }: { title: string; hint?: string; moves: PriceMove[]; empty: string }) {
  return (
    <ReportCard title={title} hint={hint}>
      {moves.length === 0 ? (
        <SectionNote>{empty}</SectionNote>
      ) : (
        <ul className="divide-y divide-slate-100 text-xs">
          {moves.map((m, i) => (
            <li key={`${m.product_id}-${m.at}-${i}`} className="flex items-start justify-between gap-4 py-1.5">
              <div className="min-w-0">
                <Link to={`/products/${m.product_id}`} className="app-link block truncate font-medium text-blue-700 hover:underline" title={m.name}>
                  {m.name}
                </Link>
                <span className="block truncate text-[11px] text-slate-500">
                  <span className="app-code">{m.sku}</span>
                  {m.manufacturer ? ` · ${m.manufacturer}` : ''} · {m.source_label} · {shortDate(m.at)}
                  {m.basis === 'catalog' ? ' · cena katalogowa' : ''}
                </span>
              </div>
              <div className="shrink-0 text-right">
                <span className={`app-num block font-semibold whitespace-nowrap tabular-nums ${m.pct > 0 ? 'text-orange-700' : 'text-sky-700'}`}>
                  {m.pct > 0 ? '↑' : '↓'} {signedPct(m.pct)}
                </span>
                <span className="app-num block text-[11px] whitespace-nowrap text-slate-500 tabular-nums">
                  {fmtPrice(m.old, m.currency)} → {fmtPrice(m.new, m.currency)}
                </span>
              </div>
            </li>
          ))}
        </ul>
      )}
    </ReportCard>
  )
}
