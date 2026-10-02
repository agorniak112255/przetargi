import { useState } from 'react'
import { Link } from 'react-router-dom'
import { SortTh } from '../../components/CampaignsUi'
import {
  BarList,
  ColumnChart,
  Insights,
  KpiRow,
  ReportCard,
  ReportFrame,
  SectionNote,
  ShareCell,
  type Insight,
} from '../../components/ReportKit'
import { plural } from '../../lib/plural'
import {
  cards,
  coverageTone,
  dayMonth,
  groupInt,
  sharePct,
  shareValue,
  shortDate,
  trimLeadingEmpty,
  useReportData,
  weekRange,
  type CatalogReportData,
} from '../../lib/reports'
import { sortRows, useTableSort } from '../../lib/tableSort'

/**
 * Raport „Baza wiedzy” (GET /reports/catalog): kompletność kart, z których AI dobiera wyroby — opis, zdjęcie, normy
 * potwierdzone u producenta i dokumenty — w całym katalogu, wśród kart sprzedawanych (ERP XL) i wg producenta.
 */

const ENRICHMENT_LABEL: Record<keyof CatalogReportData['totals']['enrichment'], string> = {
  done: 'Opis uzupełniony przez AI',
  none: 'Bez wzbogacania AI',
  manual: 'Do ręcznego opisu',
  failed: 'Błąd wzbogacania',
  queued: 'W kolejce',
  running: 'W trakcie',
}

const DOC_KIND_LABEL: Record<string, string> = {
  datasheet: 'Karta katalogowa',
  certificate: 'Certyfikat / nieokreślony',
  manual: 'Instrukcja',
  other: 'Inny dokument',
  size_chart: 'Tabela rozmiarów',
  warranty: 'Gwarancja',
}

const MISSING_LABEL: Record<CatalogReportData['gaps_sold'][number]['missing'][number], string> = {
  description: 'opis',
  image: 'zdjęcie',
  manufacturer_norms: 'normy producenta',
  documents: 'dokumenty',
}

const COVERAGE: { key: 'with_description' | 'with_image' | 'with_manufacturer_norms' | 'with_documents'; label: string }[] = [
  { key: 'with_description', label: 'Opis' },
  { key: 'with_image', label: 'Zdjęcie' },
  { key: 'with_manufacturer_norms', label: 'Normy potwierdzone u producenta' },
  { key: 'with_documents', label: 'Dokumenty (karty, certyfikaty, instrukcje)' },
]

type MfKey = 'manufacturer' | 'products' | 'desc' | 'image' | 'mnorms' | 'tnorms' | 'docs' | 'failed'

export function ReportCatalog() {
  const state = useReportData<CatalogReportData>('/reports/catalog')
  return (
    <ReportFrame state={state} note="dane odświeżane co 10 min">
      {(d) => <CatalogBody d={d} />}
    </ReportFrame>
  )
}

function insightsOf(d: CatalogReportData): Insight[] {
  const t = d.totals
  const out: Insight[] = []
  const weakest = [...COVERAGE].sort((a, b) => t[a.key] - t[b.key])[0]
  const weakShare = shareValue(t[weakest.key], t.products)
  out.push({
    tone: coverageTone(t[weakest.key], t.products),
    text: (
      <>
        Najsłabiej pokryte: <b>{weakest.label.toLowerCase()}</b> — ma je {sharePct(t[weakest.key], t.products)} kart (
        {groupInt(t[weakest.key])} z {groupInt(t.products)}).{' '}
        {weakShare < 50 &&
          weakest.key === 'with_manufacturer_norms' &&
          'To największa luka bazy wiedzy przy przetargach, które wymagają norm.'}
      </>
    ),
  })
  if (d.sold && d.sold.products > 0) {
    out.push({
      tone: coverageTone(d.sold.with_manufacturer_norms, d.sold.products),
      text: (
        <>
          Wśród {cards(d.sold.products)} sprzedawanych w ostatnich {d.sold.window_months} mies. opis ma{' '}
          {sharePct(d.sold.with_description, d.sold.products)}, a normy producenta{' '}
          {sharePct(d.sold.with_manufacturer_norms, d.sold.products)}.
        </>
      ),
    })
  }
  const bigGap = d.manufacturers
    .filter((m) => m.manufacturer !== '(brak producenta)')
    .map((m) => ({ m, missing: m.products - m.with_description }))
    .sort((a, b) => b.missing - a.missing)[0]
  if (bigGap && bigGap.missing > 0) {
    out.push({
      tone: 'warn',
      text: (
        <>
          Najwięcej kart bez opisu ma <b>{bigGap.m.manufacturer}</b>: {groupInt(bigGap.missing)} z {groupInt(bigGap.m.products)}.
        </>
      ),
    })
  }
  const e = t.enrichment
  if (e.failed + e.manual > 0) {
    out.push({
      tone: e.failed > 0 ? 'bad' : 'warn',
      text: (
        <>
          {groupInt(e.failed)} {plural(e.failed, 'karta ma', 'karty mają', 'kart ma')} błąd wzbogacania, a {groupInt(e.manual)} czeka na
          ręczny opis.
        </>
      ),
    })
  }
  return out
}

function CatalogBody({ d }: { d: CatalogReportData }) {
  const t = d.totals
  const producers = d.manufacturers.filter((m) => m.manufacturer !== '(brak producenta)').length
  const enrichmentRows = (Object.keys(ENRICHMENT_LABEL) as (keyof typeof ENRICHMENT_LABEL)[])
    .map((k) => ({ key: k, label: ENRICHMENT_LABEL[k], value: t.enrichment[k] }))
    .filter((r) => r.value > 0)
    .sort((a, b) => b.value - a.value)

  return (
    <>
      <Insights items={insightsOf(d)} />
      <KpiRow
        items={[
          { label: 'Karty w bazie', value: groupInt(t.products), sub: `${groupInt(producers)} ${plural(producers, 'producent', 'producentów', 'producentów')}` },
          {
            label: 'Z opisem',
            value: sharePct(t.with_description, t.products) ?? '—',
            meter: shareValue(t.with_description, t.products),
            tone: coverageTone(t.with_description, t.products),
            sub: `${groupInt(t.short_description)} krótszych niż ${d.short_description_chars} znaków`,
          },
          {
            label: 'Ze zdjęciem',
            value: sharePct(t.with_image, t.products) ?? '—',
            meter: shareValue(t.with_image, t.products),
            tone: coverageTone(t.with_image, t.products),
            sub: `${groupInt(t.products - t.with_image)} bez zdjęcia`,
          },
          {
            label: 'Normy u producenta',
            value: sharePct(t.with_manufacturer_norms, t.products) ?? '—',
            meter: shareValue(t.with_manufacturer_norms, t.products),
            tone: coverageTone(t.with_manufacturer_norms, t.products),
            sub: `normy w opisie: ${sharePct(t.with_norms_text, t.products) ?? '—'}`,
          },
          {
            label: 'Z dokumentami',
            value: sharePct(t.with_documents, t.products) ?? '—',
            meter: shareValue(t.with_documents, t.products),
            tone: coverageTone(t.with_documents, t.products),
            sub: 'karty katalogowe, certyfikaty, instrukcje',
          },
          {
            label: 'Indeks wektorowy',
            value: sharePct(t.vector_indexed, t.products) ?? '—',
            meter: shareValue(t.vector_indexed, t.products),
            tone: coverageTone(t.vector_indexed, t.products),
            sub: 'zindeksowane co najmniej raz',
          },
        ]}
      />

      <div className="mb-4 grid gap-4 lg:grid-cols-3">
        <ReportCard
          className="lg:col-span-2"
          title="Dodane i wzbogacone karty — tydzień po tygodniu"
          hint="Data dodania karty i data jej ostatniego wzbogacenia przez AI (karta wzbogacona ponownie liczy się raz, w ostatnim tygodniu)."
        >
          <ColumnChart
            label="Dodane i wzbogacone karty"
            mode="grouped"
            height={220}
            series={[
              { key: 'added', label: 'Dodane karty', fill: 'fill-slate-400', swatch: 'bg-slate-400' },
              { key: 'enriched', label: 'Opis uzupełniony przez AI', fill: 'fill-blue-600', swatch: 'bg-blue-600' },
            ]}
            columns={trimLeadingEmpty(d.weekly, (w) => w.added + w.enriched === 0).map((w) => ({
              key: w.week_start,
              label: dayMonth(w.week_start),
              title: `Tydzień ${weekRange(w.week_start)}`,
              values: { added: w.added, enriched: w.enriched },
            }))}
          />
        </ReportCard>
        <ReportCard title="Skąd opisy" hint="Stan wzbogacania kart przez AI. „Bez wzbogacania” to zwykle opis z cennika lub B2B.">
          <BarList rows={enrichmentRows} total={t.products} />
          <h3 className="mt-5 mb-2 text-xs font-semibold text-slate-700">Dokumenty wg rodzaju</h3>
          {d.documents_by_kind.length === 0 ? (
            <SectionNote>Żadna karta nie ma jeszcze dokumentów.</SectionNote>
          ) : (
            <BarList
              fill="bg-teal-600"
              rows={d.documents_by_kind.map((k) => ({ key: k.kind, label: DOC_KIND_LABEL[k.kind] ?? k.kind, value: k.products }))}
              format={(n) => cards(n)}
            />
          )}
        </ReportCard>
      </div>

      <div className="mb-4 grid gap-4 lg:grid-cols-2">
        <ReportCard
          title="Cały katalog a karty, które sprzedajemy"
          hint={
            d.sold
              ? `Karty powiązane z towarem z ERP XL sprzedanym w ostatnich ${d.sold.window_months} mies. (${cards(d.sold.products)}).`
              : 'Brak kart powiązanych z towarami ERP XL — porównanie pojawi się po powiązaniu.'
          }
        >
          <table className="app-table w-full text-left text-xs">
            <thead>
              <tr className="border-b bg-slate-50 text-slate-500">
                <th className="p-2 font-medium">Element karty</th>
                <th className="p-2 text-right font-medium">Cały katalog</th>
                <th className="p-2 text-right font-medium">Sprzedawane</th>
              </tr>
            </thead>
            <tbody>
              {COVERAGE.map((c) => (
                <tr key={c.key} className="border-b border-slate-100 last:border-0">
                  <td className="p-2 text-slate-700">{c.label}</td>
                  <td className="p-2 text-right">
                    <ShareCell part={t[c.key]} whole={t.products} />
                  </td>
                  <td className="p-2 text-right">{d.sold ? <ShareCell part={d.sold[c.key]} whole={d.sold.products} /> : '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </ReportCard>
        <ReportCard title="Sprzedawane karty z brakami" hint="Najpierw karty z największą liczbą braków, potem ostatnio sprzedane.">
          {d.gaps_sold.length === 0 ? (
            <SectionNote>{d.sold ? 'Wszystkie sprzedawane karty są kompletne.' : 'Brak powiązań kart z ERP XL.'}</SectionNote>
          ) : (
            <ul className="divide-y divide-slate-100 text-xs">
              {d.gaps_sold.map((g) => (
                <li key={g.id} className="flex items-start justify-between gap-3 py-1.5">
                  <div className="min-w-0">
                    <Link to={`/products/${g.id}`} className="app-link block truncate font-medium text-blue-700 hover:underline" title={g.name}>
                      {g.name}
                    </Link>
                    <span className="text-slate-500">
                      <span className="app-code">{g.sku}</span>
                      {g.manufacturer ? ` · ${g.manufacturer}` : ''} · sprzedaż {shortDate(g.last_sale_at)}
                    </span>
                  </div>
                  <span className="flex shrink-0 flex-wrap justify-end gap-1">
                    {g.missing.map((m) => (
                      <span key={m} className="rounded-full bg-amber-50 px-2 py-0.5 text-[11px] whitespace-nowrap text-amber-800">
                        brak: {MISSING_LABEL[m]}
                      </span>
                    ))}
                  </span>
                </li>
              ))}
            </ul>
          )}
        </ReportCard>
      </div>

      <ManufacturersTable rows={d.manufacturers} />
    </>
  )
}

function ManufacturersTable({ rows }: { rows: CatalogReportData['manufacturers'] }) {
  const [sort, toggle] = useTableSort<MfKey>(['products', 'desc', 'image', 'mnorms', 'tnorms', 'docs', 'failed'], { key: 'products', dir: 'desc' })
  const [query, setQuery] = useState('')
  const [all, setAll] = useState(false)
  const share = (part: number, whole: number) => (whole > 0 ? part / whole : null)
  const q = query.trim().toLocaleLowerCase('pl')
  const filtered = q ? rows.filter((r) => r.manufacturer.toLocaleLowerCase('pl').includes(q)) : rows
  const sorted = sortRows(filtered, sort, (r, k) => {
    switch (k) {
      case 'manufacturer':
        return r.manufacturer
      case 'products':
        return r.products
      case 'desc':
        return share(r.with_description, r.products)
      case 'image':
        return share(r.with_image, r.products)
      case 'mnorms':
        return share(r.with_manufacturer_norms, r.products)
      case 'tnorms':
        return share(r.with_norms_text, r.products)
      case 'docs':
        return share(r.with_documents, r.products)
      case 'failed':
        return r.enrichment_failed
    }
  })
  const shown = all || q ? sorted : sorted.slice(0, 25)

  return (
    <ReportCard
      title="Producenci"
      hint="Udział kart producenta z danym elementem. Kliknij nagłówek, żeby posortować — np. po normach, by znaleźć największe luki."
      aside={
        <input
          type="search"
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          placeholder="Szukaj producenta"
          aria-label="Szukaj producenta"
          className="w-48 rounded-md border border-slate-300 bg-white px-2 py-1 text-xs"
        />
      }
    >
      <div className="overflow-x-auto">
        <table className="app-table w-full min-w-[46rem] text-left text-xs">
          <thead>
            <tr className="border-b bg-slate-50 text-slate-500">
              <SortTh label="Producent" k="manufacturer" sort={sort} onSort={toggle} />
              <SortTh label="Kart" k="products" sort={sort} onSort={toggle} align="right" />
              <SortTh label="Opis" k="desc" sort={sort} onSort={toggle} align="right" />
              <SortTh label="Zdjęcie" k="image" sort={sort} onSort={toggle} align="right" />
              <SortTh label="Normy producenta" k="mnorms" sort={sort} onSort={toggle} align="right" />
              <SortTh label="Normy w opisie" k="tnorms" sort={sort} onSort={toggle} align="right" />
              <SortTh label="Dokumenty" k="docs" sort={sort} onSort={toggle} align="right" />
              <SortTh label="Błędy AI" k="failed" sort={sort} onSort={toggle} align="right" />
            </tr>
          </thead>
          <tbody>
            {shown.map((r) => (
              <tr key={r.manufacturer} className="border-b border-slate-100">
                <td className="p-2 font-medium text-slate-800">{r.manufacturer}</td>
                <td className="app-num p-2 text-right tabular-nums">{groupInt(r.products)}</td>
                <td className="p-2 text-right">
                  <ShareCell part={r.with_description} whole={r.products} />
                </td>
                <td className="p-2 text-right">
                  <ShareCell part={r.with_image} whole={r.products} />
                </td>
                <td className="p-2 text-right">
                  <ShareCell part={r.with_manufacturer_norms} whole={r.products} />
                </td>
                <td className="p-2 text-right">
                  <ShareCell part={r.with_norms_text} whole={r.products} />
                </td>
                <td className="p-2 text-right">
                  <ShareCell part={r.with_documents} whole={r.products} />
                </td>
                <td className={`app-num p-2 text-right tabular-nums ${r.enrichment_failed > 0 ? 'text-red-700' : 'text-slate-400'}`}>
                  {groupInt(r.enrichment_failed)}
                </td>
              </tr>
            ))}
            {shown.length === 0 && (
              <tr>
                <td colSpan={8} className="p-3 text-slate-500">
                  Brak producenta o tej nazwie.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
      {!q && rows.length > 25 && (
        <button type="button" onClick={() => setAll((v) => !v)} className="mt-2 text-xs font-medium text-blue-700 hover:underline">
          {all ? 'Pokaż 25 największych' : `Pokaż wszystkich (${groupInt(rows.length)})`}
        </button>
      )}
    </ReportCard>
  )
}
