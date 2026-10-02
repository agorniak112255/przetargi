import { useEffect, useRef, useState, type ReactNode } from 'react'
import { coverageTone, groupInt, shareValue, sharePct, stampDate, type Tone } from '../lib/reports'

/**
 * Wspólne klocki stron „Raporty”: karta sekcji, kafelki wskaźników, ramka „Najważniejsze”, wykres kolumnowy
 * (pojedynczy, skumulowany), wykres rozbieżny (w górę / w dół), lista pasków i pasek udziału w tabeli.
 *
 * Wykresy to ręczne SVG w rzeczywistej szerokości (ResizeObserver) — jak w raporcie dla zarządu — ale kolory serii,
 * siatki i napisów idą przez klasy Tailwinda (fill-*, stroke-*), więc tryb ciemny przemapowuje je sam. Słupki cienkie
 * (≤ 24 px) z zaokrąglonym końcem, 2 px przerwy między segmentami, siatka włosowa; podgląd wartości pod kursorem
 * i z klawiatury. Napisy zawsze w kolorach tekstu, nigdy w kolorze serii.
 */

/** Kolory tonu: kropka/pasek. Literały pełne — skrypt motywów widzi tylko takie. */
const TONE_FILL: Record<Tone, string> = {
  neutral: 'bg-blue-600',
  good: 'bg-teal-600',
  warn: 'bg-amber-500',
  bad: 'bg-rose-700',
}

export function ReportCard({
  title,
  hint,
  aside,
  children,
  className = '',
}: {
  title: string
  hint?: ReactNode
  aside?: ReactNode
  children: ReactNode
  className?: string
}) {
  return (
    <section className={`app-card app-report-card min-w-0 rounded-xl bg-white p-4 shadow-sm ${className}`}>
      <header className="mb-3 flex flex-wrap items-start justify-between gap-x-4 gap-y-1">
        <div className="min-w-0">
          <h2 className="app-card-title text-sm font-semibold text-slate-900">{title}</h2>
          {hint && <p className="mt-0.5 text-xs text-slate-500">{hint}</p>}
        </div>
        {aside}
      </header>
      {children}
    </section>
  )
}

export type Kpi = {
  label: string
  value: string
  sub?: ReactNode
  tone?: Tone
  /** Udział 0–100 rysowany paskiem pod liczbą (np. pokrycie kart opisem). */
  meter?: number | null
}

const KPI_COLS: Record<number, string> = {
  3: 'lg:grid-cols-3',
  4: 'lg:grid-cols-4',
  5: 'lg:grid-cols-3 xl:grid-cols-5',
  6: 'lg:grid-cols-3 xl:grid-cols-6',
}

/** Rząd kafelków wskaźników; kolumny wg liczby kafelków (bez układu 5 + 1). */
export function KpiRow({ items }: { items: Kpi[] }) {
  return (
    <div className={`app-report-kpis mb-4 grid grid-cols-2 gap-3 ${KPI_COLS[items.length] ?? 'lg:grid-cols-4'}`}>
      {items.map((k) => (
        <div key={k.label} className="app-report-kpi flex min-w-0 flex-col rounded-xl bg-white p-4 shadow-sm" data-tone={k.tone ?? 'neutral'}>
          <span className="flex items-center gap-1.5 text-xs text-slate-500">
            {k.tone && k.tone !== 'neutral' && <span className={`h-2 w-2 shrink-0 rounded-full ${TONE_FILL[k.tone]}`} aria-hidden />}
            <span className="truncate">{k.label}</span>
          </span>
          <b className="mt-1 text-2xl leading-tight font-semibold text-slate-900">{k.value}</b>
          {k.meter != null && (
            <span className="mt-2 block h-1.5 overflow-hidden rounded-full bg-slate-100" aria-hidden>
              <span className={`block h-full rounded-full ${TONE_FILL[k.tone ?? 'neutral']}`} style={{ width: `${Math.max(1.5, k.meter)}%` }} />
            </span>
          )}
          {k.sub && <span className="mt-1.5 text-xs text-slate-500">{k.sub}</span>}
        </div>
      ))}
    </div>
  )
}

export type Insight = { tone: Tone; text: ReactNode }

/** „Najważniejsze”: 2–4 zdania policzone z danych raportu (same fakty, bez zaleceń wymyślonych ponad dane). */
export function Insights({ items }: { items: Insight[] }) {
  if (items.length === 0) return null
  return (
    <section className="app-report-insights mb-4 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3" aria-label="Najważniejsze">
      <h2 className="mb-1.5 text-xs font-semibold tracking-wide text-slate-500 uppercase">Najważniejsze</h2>
      <ul className="grid gap-x-6 gap-y-1.5 text-sm text-slate-800 md:grid-cols-2">
        {items.map((it, i) => (
          <li key={i} className="flex gap-2">
            <span className={`mt-1.5 h-2 w-2 shrink-0 rounded-full ${TONE_FILL[it.tone]}`} aria-hidden />
            <span>{it.text}</span>
          </li>
        ))}
      </ul>
    </section>
  )
}

/** Przełącznik okresu / widoku (gęsty, jak w Statystykach AI). */
export function Segmented<T extends string | number>({
  options,
  value,
  onChange,
  label,
}: {
  options: { value: T; label: string }[]
  value: T
  onChange: (v: T) => void
  label: string
}) {
  return (
    <div className="inline-flex items-center gap-1 rounded-lg bg-slate-100 p-0.5" role="group" aria-label={label}>
      {options.map((o) => {
        const on = o.value === value
        return (
          <button
            key={String(o.value)}
            type="button"
            aria-pressed={on}
            onClick={() => onChange(o.value)}
            className={`rounded-md px-2.5 py-1 text-xs ${on ? 'bg-white font-semibold text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900'}`}
          >
            {o.label}
          </button>
        )
      })}
    </div>
  )
}

/** Pasek udziału w komórce tabeli: tor + wypełnienie w tonie progu + procent. */
export function ShareCell({ part, whole, tone }: { part: number; whole: number; tone?: Tone }) {
  const t = tone ?? coverageTone(part, whole)
  const label = sharePct(part, whole) ?? '—'
  return (
    <span className="inline-flex items-center justify-end gap-2" title={`${groupInt(part)} z ${groupInt(whole)}`}>
      <span className="hidden h-1.5 w-14 overflow-hidden rounded-full bg-slate-100 sm:block" aria-hidden>
        <span className={`block h-full rounded-full ${TONE_FILL[t]}`} style={{ width: `${shareValue(part, whole)}%` }} />
      </span>
      <span className="w-10 text-right tabular-nums">{label}</span>
    </span>
  )
}

/** Lista poziomych pasków (np. miasta, rodzaje dokumentów) — wartość na końcu paska, skala do największej. */
export function BarList({
  rows,
  format = groupInt,
  fill = 'bg-blue-600',
  total,
}: {
  rows: { key: string; label: ReactNode; value: number; note?: ReactNode }[]
  format?: (n: number) => string
  fill?: string
  /** Gdy podane — obok wartości udział w całości. */
  total?: number
}) {
  const max = Math.max(1, ...rows.map((r) => r.value))
  return (
    <ul className="grid gap-1.5">
      {rows.map((r) => (
        <li key={r.key} className="grid grid-cols-[minmax(0,11rem)_minmax(3rem,1fr)_auto] items-center gap-3 text-xs">
          <span className="truncate text-slate-700" title={typeof r.label === 'string' ? r.label : undefined}>
            {r.label}
          </span>
          <span className="h-2 overflow-hidden rounded-full bg-slate-100" aria-hidden>
            <span className={`block h-full rounded-full ${fill}`} style={{ width: `${Math.max(1.5, (r.value / max) * 100)}%` }} />
          </span>
          <span className="text-right whitespace-nowrap tabular-nums text-slate-900">
            {format(r.value)}
            {total != null && <span className="ml-1 text-slate-500">({sharePct(r.value, total) ?? '—'})</span>}
            {r.note}
          </span>
        </li>
      ))}
    </ul>
  )
}

/* ---------- Wykresy SVG ---------- */

/** Szerokość kontenera (wykres rysowany w rzeczywistych pikselach — napisy się nie rozciągają). */
function useWidth(min = 240) {
  const ref = useRef<HTMLDivElement>(null)
  const [width, setWidth] = useState(560)
  useEffect(() => {
    const el = ref.current
    if (!el) return
    // pierwszy pomiar od razu — ResizeObserver zgłasza rozmiar dopiero w następnej klatce
    if (el.clientWidth > 0) setWidth(Math.max(min, el.clientWidth))
    if (typeof ResizeObserver === 'undefined') return
    const ro = new ResizeObserver(([entry]) => setWidth(Math.max(min, Math.round(entry.contentRect.width))))
    ro.observe(el)
    return () => ro.disconnect()
  }, [min])
  return [ref, width] as const
}

/** 3–5 okrągłych podziałek od 0 do max (krok 1, 2, 2,5, 5 × 10^n; dla liczebności co najmniej 1). */
function countTicks(max: number): number[] {
  const top = Math.max(1, max)
  const raw = top / 4
  const power = 10 ** Math.floor(Math.log10(raw))
  const step = Math.max(1, [1, 2, 2.5, 5, 10].map((m) => m * power).find((s) => s >= raw) ?? 10 * power)
  const ticks: number[] = []
  for (let t = 0; t < top + step * 0.001; t += step) ticks.push(Math.round(t * 100) / 100)
  if (ticks[ticks.length - 1] < top) ticks.push(ticks[ticks.length - 1] + step)
  return ticks
}

/** Prostokąt z zaokrąglonym końcem z dala od osi (góra albo dół), kwadratowy przy osi. */
function barPath(x: number, y: number, w: number, h: number, roundTop: boolean, r = 4): string {
  if (h <= 0 || w <= 0) return ''
  const rr = Math.min(r, w / 2, h)
  if (roundTop) {
    return `M${x},${y + h}V${y + rr}Q${x},${y} ${x + rr},${y}H${x + w - rr}Q${x + w},${y} ${x + w},${y + rr}V${y + h}Z`
  }
  return `M${x},${y}V${y + h - rr}Q${x},${y + h} ${x + rr},${y + h}H${x + w - rr}Q${x + w},${y + h} ${x + w},${y + h - rr}V${y}Z`
}

export type ChartSeries = {
  key: string
  label: string
  /** Klasa wypełnienia słupka (fill-*) i znacznika legendy (bg-*). */
  fill: string
  swatch: string
}

export type ChartColumn = {
  key: string
  /** Podpis na osi (krótki). */
  label: string
  /** Tytuł podglądu (pełny, np. zakres tygodnia). */
  title: string
  values: Record<string, number>
}

function Legend({ series }: { series: ChartSeries[] }) {
  if (series.length < 2) return null
  return (
    <ul className="mb-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-600">
      {series.map((s) => (
        <li key={s.key} className="inline-flex items-center gap-1.5">
          <span className={`h-2.5 w-2.5 rounded-sm ${s.swatch}`} aria-hidden />
          {s.label}
        </li>
      ))}
    </ul>
  )
}

/** Podgląd wartości kolumny (HTML nad SVG, po tej stronie, gdzie jest miejsce). */
function Tip({ x, width, title, rows, total }: { x: number; width: number; title: string; rows: { s: ChartSeries; v: number }[]; total?: number }) {
  return (
    <div
      className="pointer-events-none absolute top-0 z-10 min-w-36 rounded-lg border border-slate-200 bg-white px-2.5 py-2 text-xs whitespace-nowrap text-slate-800 shadow-md"
      style={x > width / 2 ? { right: width - x + 12 } : { left: x + 12 }}
    >
      <div className="mb-1 font-semibold text-slate-900">{title}</div>
      {rows.map(({ s, v }) => (
        <div key={s.key} className="flex items-center justify-between gap-4">
          <span className="inline-flex items-center gap-1.5 text-slate-600">
            <span className={`h-2 w-2 rounded-sm ${s.swatch}`} aria-hidden />
            {s.label}
          </span>
          <span className="tabular-nums">{groupInt(v)}</span>
        </div>
      ))}
      {total != null && rows.length > 1 && (
        <div className="mt-1 flex justify-between gap-4 border-t border-slate-100 pt-1 text-slate-600">
          <span>Razem</span>
          <span className="tabular-nums text-slate-900">{groupInt(total)}</span>
        </div>
      )}
    </div>
  )
}

/**
 * Wykres kolumnowy liczebności: jedna seria, kilka serii skumulowanych (stacked) albo obok siebie (grouped).
 * Oś Y od zera, podpisy osi X przerzedzane, gdy się nie mieszczą.
 */
export function ColumnChart({
  columns,
  series,
  mode = 'stacked',
  height = 200,
  label,
}: {
  columns: ChartColumn[]
  series: ChartSeries[]
  mode?: 'stacked' | 'grouped'
  height?: number
  label: string
}) {
  const [box, width] = useWidth()
  const [hover, setHover] = useState<number | null>(null)
  const totals = columns.map((c) => series.reduce((sum, s) => sum + (c.values[s.key] ?? 0), 0))
  const peak = mode === 'stacked' ? Math.max(0, ...totals) : Math.max(0, ...columns.flatMap((c) => series.map((s) => c.values[s.key] ?? 0)))
  const ticks = countTicks(peak)
  const top = ticks[ticks.length - 1]
  const L = 8 + String(groupInt(top)).length * 7
  const R = 6
  const T = 8
  const B = 22
  const plotW = Math.max(10, width - L - R)
  const plotH = height - T - B
  const band = plotW / Math.max(1, columns.length)
  const y = (v: number) => T + plotH - (v / top) * plotH
  const labelEvery = Math.max(1, Math.ceil(columns.length / Math.max(1, Math.floor(plotW / 46))))
  const groupedW = Math.min(12, (band * 0.75) / Math.max(1, series.length) - 2)
  const stackW = Math.min(24, band * 0.62)

  const hovered = hover !== null ? columns[hover] : null
  const hx = hover !== null ? L + band * hover + band / 2 : 0
  const summary = `${label}: ${columns.length} okresów, najwięcej ${groupInt(peak)}`

  return (
    <div>
      <Legend series={series} />
      <div ref={box} className="relative">
        <svg
          width={width}
          height={height}
          className="block"
          role="img"
          aria-label={summary}
          tabIndex={0}
          onKeyDown={(e) => {
            if (e.key === 'ArrowRight') setHover((h) => Math.min(columns.length - 1, (h ?? -1) + 1))
            if (e.key === 'ArrowLeft') setHover((h) => Math.max(0, (h ?? columns.length) - 1))
            if (e.key === 'Escape') setHover(null)
          }}
          onBlur={() => setHover(null)}
        >
          {ticks.map((t) => (
            <g key={t}>
              <line x1={L} x2={width - R} y1={y(t)} y2={y(t)} className={t === 0 ? 'stroke-slate-300' : 'stroke-slate-200'} strokeWidth={1} />
              <text x={L - 6} y={y(t) + 3.5} textAnchor="end" fontSize="11" className="fill-slate-500 tabular-nums">
                {groupInt(t)}
              </text>
            </g>
          ))}
          {hovered && <rect x={L + band * hover!} y={T} width={band} height={plotH} className="fill-slate-100" />}
          {columns.map((c, i) => {
            const cx = L + band * i + band / 2
            if (mode === 'grouped') {
              const groupWidth = series.length * (groupedW + 2) - 2
              return (
                <g key={c.key}>
                  {series.map((s, si) => {
                    const v = c.values[s.key] ?? 0
                    const h = (v / top) * plotH
                    const x = cx - groupWidth / 2 + si * (groupedW + 2)
                    return <path key={s.key} d={barPath(x, y(v), groupedW, h, true, 3)} className={s.fill} />
                  })}
                </g>
              )
            }
            let base = 0
            const visible = series.filter((s) => (c.values[s.key] ?? 0) > 0)
            return (
              <g key={c.key}>
                {visible.map((s, si) => {
                  const v = c.values[s.key] ?? 0
                  const y0 = y(base)
                  base += v
                  const y1 = y(base)
                  // 2 px przerwy w kolorze tła między segmentami (dolny segment zaczyna się od osi)
                  const gap = si > 0 ? 2 : 0
                  const h = y0 - y1 - gap
                  if (h < 0.5) return null
                  return <path key={s.key} d={barPath(cx - stackW / 2, y1, stackW, h, si === visible.length - 1)} className={s.fill} />
                })}
              </g>
            )
          })}
          {columns.map((c, i) =>
            i % labelEvery === 0 ? (
              <text key={c.key} x={L + band * i + band / 2} y={height - 6} textAnchor="middle" fontSize="11" className="fill-slate-500">
                {c.label}
              </text>
            ) : null,
          )}
          {columns.map((c, i) => (
            <rect
              key={c.key}
              x={L + band * i}
              y={T}
              width={band}
              height={plotH}
              fill="transparent"
              onMouseEnter={() => setHover(i)}
              onMouseLeave={() => setHover(null)}
            />
          ))}
        </svg>
        {hovered && (
          <Tip
            x={hx}
            width={width}
            title={hovered.title}
            rows={series.map((s) => ({ s, v: hovered.values[s.key] ?? 0 }))}
            total={mode === 'stacked' ? totals[hover!] : undefined}
          />
        )}
      </div>
    </div>
  )
}

/**
 * Wykres rozbieżny: seria „w górę” nad osią, „w dół” pod osią (np. podwyżki i obniżki cen w tygodniu).
 * Jedna skala dla obu stron.
 */
export function DivergingChart({
  columns,
  up,
  down,
  height = 220,
  label,
}: {
  columns: { key: string; label: string; title: string; up: number; down: number }[]
  up: ChartSeries
  down: ChartSeries
  height?: number
  label: string
}) {
  const [box, width] = useWidth()
  const [hover, setHover] = useState<number | null>(null)
  const peak = Math.max(1, ...columns.map((c) => Math.max(c.up, c.down)))
  const ticks = countTicks(peak)
  const top = ticks[ticks.length - 1]
  const L = 8 + String(groupInt(top)).length * 7
  const R = 6
  const T = 8
  const B = 22
  const plotW = Math.max(10, width - L - R)
  const half = (height - T - B) / 2
  const zero = T + half
  const band = plotW / Math.max(1, columns.length)
  const w = Math.min(24, band * 0.62)
  const scale = (v: number) => (v / top) * (half - 1)
  const labelEvery = Math.max(1, Math.ceil(columns.length / Math.max(1, Math.floor(plotW / 46))))
  const hovered = hover !== null ? columns[hover] : null
  const hx = hover !== null ? L + band * hover + band / 2 : 0

  return (
    <div>
      <Legend series={[up, down]} />
      <div ref={box} className="relative">
        <svg
          width={width}
          height={height}
          className="block"
          role="img"
          aria-label={`${label}: najwięcej ${groupInt(peak)} w tygodniu`}
          tabIndex={0}
          onKeyDown={(e) => {
            if (e.key === 'ArrowRight') setHover((h) => Math.min(columns.length - 1, (h ?? -1) + 1))
            if (e.key === 'ArrowLeft') setHover((h) => Math.max(0, (h ?? columns.length) - 1))
            if (e.key === 'Escape') setHover(null)
          }}
          onBlur={() => setHover(null)}
        >
          {ticks.map((t) => (
            <g key={t}>
              <line x1={L} x2={width - R} y1={zero - scale(t)} y2={zero - scale(t)} className={t === 0 ? 'stroke-slate-400' : 'stroke-slate-200'} />
              {t > 0 && <line x1={L} x2={width - R} y1={zero + scale(t)} y2={zero + scale(t)} className="stroke-slate-200" />}
              <text x={L - 6} y={zero - scale(t) + 3.5} textAnchor="end" fontSize="11" className="fill-slate-500 tabular-nums">
                {groupInt(t)}
              </text>
              {t > 0 && (
                <text x={L - 6} y={zero + scale(t) + 3.5} textAnchor="end" fontSize="11" className="fill-slate-500 tabular-nums">
                  {groupInt(t)}
                </text>
              )}
            </g>
          ))}
          {hovered && <rect x={L + band * hover!} y={T} width={band} height={half * 2} className="fill-slate-100" />}
          {columns.map((c, i) => {
            const cx = L + band * i + band / 2
            const hu = scale(c.up)
            const hd = scale(c.down)
            return (
              <g key={c.key}>
                {c.up > 0 && <path d={barPath(cx - w / 2, zero - 1 - hu, w, hu, true)} className={up.fill} />}
                {c.down > 0 && <path d={barPath(cx - w / 2, zero + 1, w, hd, false)} className={down.fill} />}
              </g>
            )
          })}
          {columns.map((c, i) =>
            i % labelEvery === 0 ? (
              <text key={c.key} x={L + band * i + band / 2} y={height - 6} textAnchor="middle" fontSize="11" className="fill-slate-500">
                {c.label}
              </text>
            ) : null,
          )}
          {columns.map((c, i) => (
            <rect
              key={c.key}
              x={L + band * i}
              y={T}
              width={band}
              height={half * 2}
              fill="transparent"
              onMouseEnter={() => setHover(i)}
              onMouseLeave={() => setHover(null)}
            />
          ))}
        </svg>
        {hovered && (
          <Tip
            x={hx}
            width={width}
            title={hovered.title}
            rows={[
              { s: up, v: hovered.up },
              { s: down, v: hovered.down },
            ]}
          />
        )}
      </div>
    </div>
  )
}

/**
 * Rama raportu: pasek narzędzi (np. okres) i „Stan na …”, błąd z ponowieniem, szkielet przy pierwszym wczytaniu;
 * przy zmianie okresu poprzednie dane zostają przyciemnione do nadejścia nowych.
 */
export function ReportFrame<T extends { generated_at: string }>({
  state,
  toolbar,
  note,
  children,
}: {
  state: { data: T | null; stale: boolean; error: string; loading: boolean; reload: () => Promise<void> }
  toolbar?: ReactNode
  /** Krótka uwaga o źródle danych obok daty (np. „odświeżane co 10 min”). */
  note?: string
  children: (data: T) => ReactNode
}) {
  const { data, stale, error, loading, reload } = state
  return (
    <div aria-busy={loading}>
      <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
        <div className="flex flex-wrap items-center gap-2">{toolbar}</div>
        {data && (
          <span className="text-xs text-slate-500">
            Stan na {stampDate(data.generated_at)}
            {note ? ` · ${note}` : ''}
          </span>
        )}
      </div>
      {error && (
        <div className="mb-3 flex items-center justify-between gap-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-800" role="alert">
          <span>{error}</span>
          <button type="button" onClick={() => void reload()} className="shrink-0 font-medium underline">
            Spróbuj ponownie
          </button>
        </div>
      )}
      {!data && loading && <ReportSkeleton />}
      {data && stale && !loading && (
        <p className="mb-2 text-xs font-medium text-amber-800">Poniżej dane z poprzedniego wyboru — nowych nie udało się wczytać.</p>
      )}
      {data && <div className={`transition-opacity ${loading || stale ? 'opacity-50' : ''}`}>{children(data)}</div>}
    </div>
  )
}

function ReportSkeleton() {
  return (
    <div role="status" aria-label="Wczytywanie raportu" className="animate-pulse">
      <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
        {[0, 1, 2, 3].map((i) => (
          <div key={i} className="h-24 rounded-xl bg-white shadow-sm" />
        ))}
      </div>
      <div className="grid gap-4 lg:grid-cols-2">
        <div className="h-64 rounded-xl bg-white shadow-sm" />
        <div className="h-64 rounded-xl bg-white shadow-sm" />
      </div>
    </div>
  )
}

/** Stan sekcji bez danych: brak uprawnienia albo pusto — z wyjaśnieniem zamiast zer. */
export function SectionNote({ children }: { children: ReactNode }) {
  return <p className="rounded-lg border border-dashed border-slate-300 px-3 py-4 text-center text-xs text-slate-500">{children}</p>
}
