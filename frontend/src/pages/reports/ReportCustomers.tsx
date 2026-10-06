import { useState } from 'react'
import { Link } from 'react-router-dom'
import { BarList, ColumnChart, Insights, KpiRow, ReportCard, ReportFrame, SectionNote, type Insight } from '../../components/ReportKit'
import { plural } from '../../lib/plural'
import { groupInt, shareValue, sharePct, shortDate, stampDate, useReportData, type CustomersReportData } from '../../lib/reports'

/**
 * Raport „Klienci ERP” (GET /reports/customers): kontrahenci z Comarch ERP XL wg daty ostatniej sprzedaży (FS/PA),
 * odpływ, zasięg mailowy (adresy bez wypisanych i bez skrzynek faktur/księgowości) i to, co kupuje najwięcej
 * klientów. Liczby dokumentów, nie wartości — ERP XL nie daje jeszcze historii wartości sprzedaży. „Operator” to osoba,
 * która wystawiała klientowi najwięcej dokumentów, nie przypisany opiekun.
 */

export function ReportCustomers() {
  const state = useReportData<CustomersReportData>('/reports/customers')
  return <ReportFrame state={state}>{(d) => <CustomersBody d={d} />}</ReportFrame>
}

function insightsOf(d: CustomersReportData): Insight[] {
  const t = d.totals
  const out: Insight[] = []
  out.push({
    tone: 'neutral',
    text: (
      <>
        W ostatnich 6 miesiącach kupowało <b>{groupInt(t.active_6m)}</b> {plural(t.active_6m, 'klient', 'klientów', 'klientów')}; w 24
        miesiącach — {groupInt(t.buying_24m)}.
      </>
    ),
  })
  const lapsed = t.dormant_6_12 + t.lapsing_12_24
  if (lapsed > 0) {
    out.push({
      tone: 'warn',
      text: (
        <>
          {groupInt(lapsed)} {plural(lapsed, 'klient przestał', 'klientów przestało', 'klientów przestało')} kupować: {groupInt(t.dormant_6_12)} od 6–12
          miesięcy i {groupInt(t.lapsing_12_24)} od 12–24 miesięcy.
        </>
      ),
    })
  }
  const active12 = t.active_6m + t.dormant_6_12
  if (active12 > 0) {
    out.push({
      tone: shareValue(t.reachable_active_12m, active12) >= 60 ? 'good' : 'warn',
      text: (
        <>
          Do kampanii można napisać do {sharePct(t.reachable_active_12m, active12)} klientów aktywnych w ostatnich 12 miesiącach ({groupInt(t.reachable_active_12m)} z{' '}
          {groupInt(active12)}) — reszta nie ma w XL użytecznego adresu e-mail albo wypisała się z kampanii.
        </>
      ),
    })
  }
  return out
}

/** Ilu operatorów pokazać przed „Pokaż wszystkich” (serwer sortuje od największej liczby aktywnych klientów). */
const OPERATORS_SHOWN = 12

function CustomersBody({ d }: { d: CustomersReportData }) {
  const t = d.totals
  const active12 = t.active_6m + t.dormant_6_12
  const [allOperators, setAllOperators] = useState(false)
  const operators = allOperators ? d.operators : d.operators.slice(0, OPERATORS_SHOWN)
  return (
    <>
      <p className="mb-3 text-xs text-slate-500">
        Dane z ERP XL z synchronizacji: {stampDate(d.synced_at)}. Liczymy faktury (także wystawione do WZ) i paragony z ostatnich 24 miesięcy — liczby
        dokumentów, nie wartości sprzedaży.
      </p>
      <Insights items={insightsOf(d)} />
      <KpiRow
        items={[
          { label: 'Kupujący w 24 miesiącach', value: groupInt(t.buying_24m), sub: `z ${groupInt(t.customers)} kontrahentów w ERP XL` },
          { label: 'Aktywni do 6 miesięcy', value: groupInt(t.active_6m), tone: 'good', sub: 'ostatni zakup w półroczu' },
          { label: 'Uśpieni 6–12 miesięcy', value: groupInt(t.dormant_6_12), tone: t.dormant_6_12 > 0 ? 'warn' : 'neutral', sub: 'warto przypomnieć się' },
          { label: 'Odchodzący 12–24 miesięcy', value: groupInt(t.lapsing_12_24), tone: t.lapsing_12_24 > 0 ? 'bad' : 'neutral', sub: 'ostatni zakup ponad rok temu' },
          {
            label: 'Zasięg mailowy',
            value: sharePct(t.reachable_active_12m, active12) ?? '—',
            meter: shareValue(t.reachable_active_12m, active12),
            tone: shareValue(t.reachable_active_12m, active12) >= 60 ? 'good' : 'warn',
            sub: `${groupInt(t.reachable_active_12m)} aktywnych z adresem e-mail`,
          },
        ]}
      />

      <div className="mb-4 grid gap-4 lg:grid-cols-3">
        <ReportCard className="lg:col-span-2" title="Kiedy klienci kupili ostatnio" hint="Liczba klientów według liczby miesięcy od ostatniej faktury lub paragonu.">
          <ColumnChart
            label="Klienci według ostatniego zakupu"
            height={210}
            series={[{ key: 'customers', label: 'Klienci', fill: 'fill-blue-600', swatch: 'bg-blue-600' }]}
            columns={d.recency.map((r) => ({
              key: r.label,
              label: `${r.from_months}–${r.to_months}`,
              title: `Ostatni zakup ${r.from_months}–${r.to_months} miesięcy temu`,
              values: { customers: r.customers },
            }))}
          />
        </ReportCard>
        <ReportCard title="Miasta" hint="Klienci aktywni w ostatnich 12 miesiącach.">
          {d.cities.length === 0 ? (
            <SectionNote>Brak aktywnych klientów.</SectionNote>
          ) : (
            <BarList rows={d.cities.map((c) => ({ key: c.city, label: c.city, value: c.customers }))} fill="bg-teal-600" />
          )}
        </ReportCard>
      </div>

      <ReportCard
        className="mb-4"
        title="Klienci według operatora ERP XL"
        hint="Operator = osoba, która wystawiała klientowi najwięcej dokumentów (nie przypisany opiekun). Pomaga zobaczyć, czyi klienci odchodzą."
      >
        {d.operators.length === 0 ? (
          <SectionNote>Brak danych o operatorach.</SectionNote>
        ) : (
          <div className="overflow-x-auto">
            <table className="app-table w-full min-w-[40rem] text-left text-xs">
              <thead>
                <tr className="border-b bg-slate-50 text-slate-500">
                  <th className="p-2 font-medium">Operator</th>
                  <th className="p-2 font-medium">Aktywni / uśpieni / odchodzący</th>
                  <th className="p-2 text-right font-medium">Aktywni do 6 miesięcy</th>
                  <th className="p-2 text-right font-medium">Uśpieni</th>
                  <th className="p-2 text-right font-medium">Odchodzący</th>
                  <th className="p-2 text-right font-medium">Z e-mailem</th>
                </tr>
              </thead>
              <tbody>
                {operators.map((o) => {
                  const all = o.active_6m + o.dormant_6_12 + o.lapsing_12_24
                  return (
                    <tr key={o.operator} className="border-b border-slate-100">
                      <td className="p-2">
                        <span className="font-medium text-slate-800">{o.name ?? o.operator}</span>
                        {o.name && <span className="app-code ml-1.5 text-[11px] text-slate-500">{o.operator}</span>}
                      </td>
                      <td className="p-2">
                        <span className="flex h-2 w-40 gap-0.5 overflow-hidden rounded-full bg-slate-100" aria-hidden>
                          {o.active_6m > 0 && <span className="h-full bg-teal-600" style={{ width: `${(o.active_6m / Math.max(1, all)) * 100}%` }} />}
                          {o.dormant_6_12 > 0 && <span className="h-full bg-amber-500" style={{ width: `${(o.dormant_6_12 / Math.max(1, all)) * 100}%` }} />}
                          {o.lapsing_12_24 > 0 && <span className="h-full bg-rose-700" style={{ width: `${(o.lapsing_12_24 / Math.max(1, all)) * 100}%` }} />}
                        </span>
                      </td>
                      <td className="app-num p-2 text-right tabular-nums">{groupInt(o.active_6m)}</td>
                      <td className="app-num p-2 text-right tabular-nums">{groupInt(o.dormant_6_12)}</td>
                      <td className="app-num p-2 text-right tabular-nums">{groupInt(o.lapsing_12_24)}</td>
                      <td className="app-num p-2 text-right tabular-nums">{groupInt(o.reachable_active_12m)}</td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
            {d.operators.length > OPERATORS_SHOWN && (
              <button type="button" onClick={() => setAllOperators((v) => !v)} className="mt-2 text-xs font-medium text-blue-700 hover:underline">
                {allOperators ? 'Zwiń listę' : `Pokaż wszystkich operatorów (${groupInt(d.operators.length)})`}
              </button>
            )}
            <p className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-[11px] text-slate-500">
              <span className="inline-flex items-center gap-1.5">
                <span className="h-2 w-2 rounded-sm bg-teal-600" aria-hidden /> aktywni do 6 miesięcy
              </span>
              <span className="inline-flex items-center gap-1.5">
                <span className="h-2 w-2 rounded-sm bg-amber-500" aria-hidden /> uśpieni 6–12 miesięcy
              </span>
              <span className="inline-flex items-center gap-1.5">
                <span className="h-2 w-2 rounded-sm bg-rose-700" aria-hidden /> odchodzący 12–24 miesięcy
              </span>
            </p>
          </div>
        )}
      </ReportCard>

      <div className="mb-4 grid gap-4 lg:grid-cols-2">
        <ReportCard title="Najczęściej kupujący" hint="Według liczby faktur i paragonów w ostatnich 24 miesiącach.">
          <div className="overflow-x-auto">
            <table className="app-table w-full min-w-[30rem] text-left text-xs">
              <thead>
                <tr className="border-b bg-slate-50 text-slate-500">
                  <th className="p-2 font-medium">Klient</th>
                  <th className="p-2 text-right font-medium">Dokumentów</th>
                  <th className="p-2 text-right font-medium">Towarów</th>
                  <th className="p-2 text-right font-medium">Ostatnio</th>
                </tr>
              </thead>
              <tbody>
                {d.top_customers.map((c) => (
                  <tr key={c.id} className="border-b border-slate-100">
                    <td className="w-[52%] max-w-0 p-2">
                      <span className="block truncate font-medium text-slate-800" title={c.name}>
                        {c.acronym ?? c.name}
                      </span>
                      <span className="block truncate text-[11px] text-slate-500">
                        {c.acronym ? c.name : ''}
                        {c.city ? `${c.acronym ? ' · ' : ''}${c.city}` : ''}
                      </span>
                    </td>
                    <td className="app-num p-2 text-right tabular-nums">{groupInt(c.documents_24m)}</td>
                    <td className="app-num p-2 text-right tabular-nums">{groupInt(c.items_24m)}</td>
                    <td className="app-num p-2 text-right whitespace-nowrap tabular-nums">{shortDate(c.last_sale_at)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </ReportCard>
        <ReportCard title="Towary kupowane przez najwięcej klientów" hint="Liczba różnych klientów w ostatnich 24 miesiącach — wskazówka, co warto mieć zawsze w ofercie.">
          <div className="overflow-x-auto">
            <table className="app-table w-full min-w-[30rem] text-left text-xs">
              <thead>
                <tr className="border-b bg-slate-50 text-slate-500">
                  <th className="p-2 font-medium">Towar</th>
                  <th className="p-2 text-right font-medium">Klientów</th>
                  <th className="p-2 text-right font-medium">Dokumentów</th>
                </tr>
              </thead>
              <tbody>
                {d.top_items.map((it) => (
                  <tr key={it.erp_item_id} className="border-b border-slate-100">
                    <td className="w-[64%] max-w-0 p-2">
                      {it.product_id ? (
                        <Link to={`/products/${it.product_id}`} className="app-link block truncate font-medium text-blue-700 hover:underline" title={it.name}>
                          {it.name}
                        </Link>
                      ) : (
                        <span className="block truncate font-medium text-slate-800" title={it.name}>
                          {it.name}
                        </span>
                      )}
                      <span className="app-code text-[11px] text-slate-500">{it.code}</span>
                      {!it.product_id && <span className="ml-1.5 text-[11px] text-amber-800">bez karty w katalogu</span>}
                    </td>
                    <td className="app-num p-2 text-right tabular-nums">{groupInt(it.customers)}</td>
                    <td className="app-num p-2 text-right tabular-nums">{groupInt(it.documents)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </ReportCard>
      </div>
      {t.archived > 0 && (
        <p className="text-[11px] text-slate-500">
          Pominięto {groupInt(t.archived)} {plural(t.archived, 'kontrahenta archiwalnego', 'kontrahentów archiwalnych', 'kontrahentów archiwalnych')} z XL.
        </p>
      )}
    </>
  )
}
