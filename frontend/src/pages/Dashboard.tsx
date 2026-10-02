import { useEffect, useState, type ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../auth'
import { api, can } from '../lib/api'
import { TENDER_STATUS_FLOW, tenderStatusLabel } from '../lib/tenderStatus'
import { NavIcon, type NavIconName } from '../components/NavIcon'

/** Odpowiedź GET /dashboard — sekcja null, gdy użytkownik nie ma dostępu do modułu (DashboardController). */
export type Dash = {
  tenders: {
    active: number
    value_net: number
    avg_margin_percent: number | null
    deadline_soon: number
    stages: { status: string; count: number; value_net: number }[]
    upcoming: { id: number; number: string; title: string | null; client: string | null; status: string; deadline: string }[]
  } | null
  products: {
    total: number
    with_description: number
    missing_description: number
    missing_images: number
    manual_review: number
    vector_indexed: number
    substitutes_pending: number
  } | null
  stock: {
    as_of: string | null
    buckets: Record<StockBucket, { items: number; value: number } | null> | null
    top_unsold: { code: string; name: string; value: number; last_sale_at: string | null }[]
  } | null
  prices: {
    accounts: { id: number; label: string; state: AccountState; finished_at: string | null; message: string | null; progress: number | null }[] | null
    prices_changed_7d: number | null
    file_imports: { id: number; manufacturer: string | null; rows_total: number; prices_changed: number; created_at: string | null }[] | null
  } | null
  campaigns: {
    drafts: number
    next: { id: number; name: string; scheduled_at: string | null } | null
    sent_30d: number
    replies_30d: number
    recent: { id: number; name: string; status: string; sent_at: string | null; sent: number; clicked: number; replies: number; drop_percent: number | null }[]
  } | null
}

type StockBucket = 'stock' | 'no_sale_6' | 'no_sale_12' | 'no_sale_24' | 'never_sold'
type AccountState = 'ok' | 'failed' | 'running' | 'off' | 'cancelled' | 'never'

const DAY = 86400000

/** Kolor etapu przetargu na pasku (tło i liczba na nim); zatwierdzona w akcencie szablonu. */
const STAGE_FILL: Record<string, string> = {
  draft: 'bg-slate-300 text-slate-700',
  wycena: 'bg-blue-500 text-white',
  akceptacja_km: 'bg-violet-400 text-white',
  akceptacja_dyrektor: 'bg-violet-500 text-white',
  zatwierdzona: 'app-dash-accent bg-emerald-600 text-white',
  exported: 'bg-amber-500 text-amber-950',
}

const ACCOUNT_STATES: { state: AccountState[]; label: string; dot: string; box: string }[] = [
  { state: ['ok'], label: 'bez błędów', dot: 'app-dash-accent bg-emerald-600', box: 'bg-slate-100' },
  { state: ['failed'], label: 'błąd', dot: 'bg-red-600', box: 'bg-red-50 text-red-800' },
  { state: ['running'], label: 'trwa', dot: 'bg-blue-500', box: 'bg-slate-100' },
  { state: ['cancelled', 'never'], label: 'przerwane / jeszcze nie sprawdzane', dot: 'bg-slate-400', box: 'bg-slate-100' },
  { state: ['off'], label: 'wyłączone', dot: 'bg-slate-300', box: 'bg-slate-100 text-slate-500' },
]

const nf = (n: number) => n.toLocaleString('pl-PL')

/** „1,85 mln zł” od miliona, niżej pełne złote: „214 600 zł”. */
function money(v: number): string {
  if (Math.abs(v) >= 1e6) return `${(v / 1e6).toLocaleString('pl-PL', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} mln zł`
  return `${nf(Math.round(v))} zł`
}

const thousands = (v: number) => `${nf(Math.round(v / 1000))} tys. zł`

/** Data z backendu (YYYY-MM-DD albo ISO) jako dzień lokalny. */
function localDay(value: string): Date {
  const [y, m, d] = value.slice(0, 10).split('-').map(Number)
  return new Date(y, m - 1, d)
}

function daysFromToday(value: string): number {
  return Math.round((localDay(value).getTime() - new Date(new Date().toDateString()).getTime()) / DAY)
}

const shortDate = (value: string) => localDay(value).toLocaleDateString('pl-PL', { day: '2-digit', month: '2-digit' })

/** Polska odmiana: 1 termin, 2–4 terminy, 5 terminów (12–14 też „terminów”). */
function plural(n: number, one: string, few: string, many: string): string {
  if (n === 1) return one
  const tens = n % 100
  const last = n % 10
  return last >= 2 && last <= 4 && (tens < 12 || tens > 14) ? few : many
}

function inDays(days: number): string {
  if (days <= 0) return 'dziś'
  if (days === 1) return 'jutro'
  return `za ${days} dni`
}

/** Pełne miesiące od daty do dziś. */
function monthsSince(value: string): number {
  const from = localDay(value)
  const now = new Date()
  return Math.max(0, (now.getFullYear() - from.getFullYear()) * 12 + now.getMonth() - from.getMonth() - (now.getDate() < from.getDate() ? 1 : 0))
}

export function Dashboard() {
  const { user } = useAuth()
  const [data, setData] = useState<Dash | null>(null)
  const [error, setError] = useState(false)

  useEffect(() => {
    api<Dash>('/dashboard').then(setData, () => setError(true))
  }, [])

  if (error) return <p className="text-sm text-red-600">Nie udało się wczytać dashboardu. Odśwież stronę.</p>
  if (!data) return <p className="text-sm text-slate-500">Ładowanie…</p>

  return <DashboardView data={data} stockLink={can(user, 'inventory.view') ? '/zapasy' : '/raport-zapasow'} />
}

/** Sam widok dashboardu (bez pobierania danych) — używa go też Pomoc z przykładowymi danymi. */
export function DashboardView({ data, stockLink }: { data: Dash; stockLink: string }) {
  const today = new Date().toLocaleDateString('pl-PL', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })
  const pricesLink = data.prices?.accounts ? '/price-lists/b2b' : '/price-lists'
  // para kart w wierszu: gdy jednej brakuje (brak uprawnienia), druga bierze całą szerokość
  const pair = (left: unknown, right: unknown, wide: string, narrow: string) =>
    [left && right ? wide : '@5xl:col-span-12', left && right ? narrow : '@5xl:col-span-12'] as const
  const [tendersSpan, productsSpan] = pair(data.tenders, data.products, '@5xl:col-span-8', '@5xl:col-span-4')
  const [stockSpan, pricesSpan] = pair(data.stock, data.prices, '@5xl:col-span-7', '@5xl:col-span-5')
  const empty = !data.tenders && !data.products && !data.stock && !data.prices && !data.campaigns

  return (
    <div className="app-dash @container">
      <div className="mb-4 flex flex-wrap items-baseline gap-x-3.5 gap-y-1">
        <h1 className="app-page-title text-xl font-semibold">Dashboard</h1>
        <span className="text-sm text-slate-500">{today}</span>
      </div>
      {empty && <p className="text-sm text-slate-500">Nie masz dostępu do żadnego modułu pokazywanego na dashboardzie.</p>}
      <div className="grid grid-cols-1 gap-3.5 @5xl:grid-cols-12">
        {data.tenders && <TendersCard t={data.tenders} span={tendersSpan} />}
        {data.products && <ProductsCard p={data.products} span={productsSpan} />}
        {data.stock && <StockCard s={data.stock} span={stockSpan} to={stockLink} />}
        {data.prices && <PricesCard p={data.prices} span={pricesSpan} to={pricesLink} />}
        {data.campaigns && <CampaignsCard c={data.campaigns} />}
      </div>
    </div>
  )
}

function Card({
  icon,
  title,
  to,
  linkLabel,
  span,
  attention,
  children,
}: {
  icon: NavIconName
  title: string
  to: string
  linkLabel: string
  span: string
  attention?: ReactNode
  children: ReactNode
}) {
  return (
    <section className={`app-card app-dash-card @container flex min-w-0 flex-col rounded-xl bg-white p-4 shadow-sm ${span}`}>
      <div className="mb-3 flex flex-wrap items-center gap-x-2.5 gap-y-1">
        <span className="app-dash-icon grid h-[26px] w-[26px] shrink-0 place-items-center rounded-md bg-blue-50 text-blue-600">
          <NavIcon name={icon} className="h-[15px] w-[15px]" />
        </span>
        <h2 className="text-sm font-semibold">{title}</h2>
        <Link to={to} className="app-link ml-auto text-xs font-medium whitespace-nowrap text-blue-600 hover:underline">
          {linkLabel} →
        </Link>
      </div>
      <div className="flex-1">{children}</div>
      {attention}
    </section>
  )
}

/** Dolna linia karty: jedna rzecz wymagająca uwagi (tone: alert = czerwień, warn = bursztyn, info = szary). */
function Attention({ tone, children }: { tone: 'alert' | 'warn' | 'info'; children: ReactNode }) {
  const color = tone === 'alert' ? 'text-red-600' : tone === 'warn' ? 'text-amber-600' : 'text-slate-400'
  return (
    <div className="mt-3 flex items-start gap-2 border-t border-dashed border-slate-200 pt-2.5 text-[12.5px] text-slate-700">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" className={`mt-px h-[15px] w-[15px] shrink-0 ${color}`} aria-hidden="true">
        {tone === 'info' ? <path d="M5 12h14M13 6l6 6-6 6" /> : <path d="M12 3l10 18H2zM12 10v5M12 18v.5" />}
      </svg>
      <span>{children}</span>
    </div>
  )
}

function Stat({ label, value, unit }: { label: string; value: string; unit?: string }) {
  return (
    <div className="min-w-0">
      <div className="app-dash-label text-[11px] font-medium tracking-wide text-slate-400 uppercase">{label}</div>
      <div className="text-[26px] leading-tight font-semibold tracking-tight tabular-nums">
        {value}
        {unit && <small className="ml-1 text-sm font-medium tracking-normal text-slate-500">{unit}</small>}
      </div>
    </div>
  )
}

function SubLabel({ children }: { children: ReactNode }) {
  return <div className="app-dash-label mb-2 text-[11px] font-medium tracking-wide text-slate-400 uppercase">{children}</div>
}

function Bar({ percent, fill }: { percent: number; fill: string }) {
  return (
    <div className="h-1.5 overflow-hidden rounded bg-slate-100">
      <div className={`h-full rounded ${fill}`} style={{ width: `${Math.min(100, Math.max(percent, percent > 0 ? 0.8 : 0))}%` }} />
    </div>
  )
}

function TendersCard({ t, span }: { t: NonNullable<Dash['tenders']>; span: string }) {
  const stages = TENDER_STATUS_FLOW.map((status) => t.stages.find((s) => s.status === status)).filter(
    (s): s is NonNullable<typeof s> => !!s && s.count > 0,
  )
  const maxCount = Math.max(1, ...stages.map((s) => s.count))
  const [valueNum, valueUnit] = splitUnit(money(t.value_net))
  const first = t.upcoming[0]

  return (
    <Card
      icon="tenders"
      title="Przetargi"
      to="/tenders"
      linkLabel="Wszystkie przetargi"
      span={span}
      attention={
        t.deadline_soon > 0 && first ? (
          <Attention tone="alert">
            <b>
              {t.deadline_soon} {plural(t.deadline_soon, 'termin', 'terminy', 'terminów')} w ciągu 7 dni.
            </b>{' '}
            Najbliższy {shortDate(first.deadline)}: {first.client ?? first.number}, status: {tenderStatusLabel(first.status).toLowerCase()}.{' '}
            <Link to="/tenders?filter=deadline_soon" className="app-link text-blue-600 hover:underline">
              Pokaż wszystkie
            </Link>
          </Attention>
        ) : (
          <Attention tone="info">Żaden przetarg w toku nie ma terminu w ciągu 7 dni.</Attention>
        )
      }
    >
      <div className="grid grid-cols-1 gap-3.5 @md:grid-cols-3">
        <Stat label="W toku" value={nf(t.active)} />
        <Stat label="Wartość ofert netto" value={valueNum} unit={valueUnit} />
        <Stat label="Średnia marża" value={t.avg_margin_percent !== null ? t.avg_margin_percent.toLocaleString('pl-PL') : '—'} unit={t.avg_margin_percent !== null ? '%' : undefined} />
      </div>
      <div className="mt-4 grid grid-cols-1 gap-5 @2xl:grid-cols-[minmax(0,1.1fr)_minmax(0,1fr)]">
        <div>
          <SubLabel>Etapy i wartość ofert</SubLabel>
          {stages.length === 0 && <p className="text-xs text-slate-500">Brak przetargów.</p>}
          <div className="grid gap-2">
            {stages.map((s) => (
              <div key={s.status} className="grid grid-cols-[104px_minmax(0,1fr)_84px] items-center gap-2.5 text-[12.5px]">
                <span className="truncate">{tenderStatusLabel(s.status)}</span>
                <span className="h-[22px] overflow-hidden rounded bg-slate-100">
                  <span
                    className={`flex h-full min-w-6 items-center rounded pl-1.5 text-[11.5px] font-semibold ${STAGE_FILL[s.status] ?? 'bg-slate-400 text-white'}`}
                    style={{ width: `${(s.count / maxCount) * 100}%` }}
                  >
                    {s.count}
                  </span>
                </span>
                <span className="text-right text-slate-500 tabular-nums">{s.value_net > 0 ? thousands(s.value_net) : '—'}</span>
              </div>
            ))}
          </div>
        </div>
        <div>
          <SubLabel>Najbliższe terminy</SubLabel>
          {t.upcoming.length === 0 && <p className="text-xs text-slate-500">Brak nadchodzących terminów.</p>}
          <div className="grid gap-1.5">
            {t.upcoming.map((u) => {
              const days = daysFromToday(u.deadline)
              return (
                <Link
                  key={u.id}
                  to={`/tenders/${u.id}`}
                  className="grid grid-cols-[46px_minmax(0,1fr)_auto] items-center gap-2.5 rounded-lg bg-slate-50 px-2.5 py-1.5 text-[12.5px] text-inherit hover:bg-slate-100 hover:no-underline"
                >
                  <span className={`font-semibold tabular-nums ${days <= 3 ? 'text-red-600' : ''}`}>{shortDate(u.deadline)}</span>
                  <span className="truncate" title={[u.client, u.number].filter(Boolean).join(' · ')}>
                    {u.client ?? u.title ?? u.number} <span className="app-code font-mono text-[11px] text-slate-500">{u.number}</span>
                  </span>
                  <span
                    className={`rounded-full px-2 py-0.5 text-[11px] font-semibold whitespace-nowrap ${
                      days <= 3 ? 'bg-red-50 text-red-700' : days <= 7 ? 'bg-amber-50 text-amber-700' : 'bg-slate-100 text-slate-600'
                    }`}
                  >
                    {inDays(days)}
                  </span>
                </Link>
              )
            })}
          </div>
        </div>
      </div>
    </Card>
  )
}

/** „1,85 mln zł” → ['1,85 mln', 'zł'] — liczba duża, jednostka mała. */
function splitUnit(text: string): [string, string] {
  const i = text.lastIndexOf(' ')
  return [text.slice(0, i), text.slice(i + 1)]
}

function ProductsCard({ p, span }: { p: NonNullable<Dash['products']>; span: string }) {
  const total = Math.max(1, p.total)
  const pct = Math.round((p.with_description / total) * 100)
  const rows: { label: string; value: number; fill: string }[] = [
    { label: `Z opisem (${pct}%)`, value: p.with_description, fill: 'app-dash-accent bg-blue-600' },
    { label: 'W wyszukiwarce AI', value: p.vector_indexed, fill: 'app-dash-accent bg-blue-600' },
    { label: 'Bez opisu', value: p.missing_description, fill: 'bg-amber-500' },
    { label: 'Bez zdjęcia', value: p.missing_images, fill: 'bg-amber-500' },
    { label: 'Do ręcznego opisu', value: p.manual_review, fill: 'bg-red-500' },
  ]

  return (
    <Card
      icon="products"
      title="Produkty"
      to="/products"
      linkLabel="Katalog"
      span={span}
      attention={
        p.missing_description > 0 ? (
          <Attention tone="warn">
            <b>{nf(p.missing_description)}</b> {plural(p.missing_description, 'karta', 'karty', 'kart')} bez opisu. Takie karty nie trafiają do propozycji przetargowych.
          </Attention>
        ) : (
          <Attention tone="info">Każda karta ma opis.</Attention>
        )
      }
    >
      <Stat label="Karty w katalogu" value={nf(p.total)} />
      <div className="mt-3.5 grid gap-2.5">
        {rows.map((r) => (
          <div key={r.label} className="grid grid-cols-[minmax(0,1fr)_auto] gap-x-2.5 gap-y-1 text-[12.5px]">
            <span>{r.label}</span>
            <b className="text-right tabular-nums">{nf(r.value)}</b>
            <div className="col-span-2">
              <Bar percent={(r.value / total) * 100} fill={r.fill} />
            </div>
          </div>
        ))}
      </div>
      <Link to="/substitutes" className="mt-3 flex justify-between text-[12.5px] text-inherit hover:underline">
        <span className="text-slate-600">Zamienniki do akceptacji</span>
        <b className="tabular-nums">{nf(p.substitutes_pending)}</b>
      </Link>
    </Card>
  )
}

const STOCK_BARS: { key: StockBucket; label: string; old: boolean }[] = [
  { key: 'no_sale_6', label: 'ponad pół roku', old: false },
  { key: 'no_sale_12', label: 'ponad rok', old: true },
  { key: 'no_sale_24', label: 'ponad 2 lata', old: true },
  { key: 'never_sold', label: 'nigdy', old: true },
]

/** Krok osi: 1, 2, 2,5 albo 5 × 10^n — trzy, cztery linie siatki. */
function niceStep(max: number): number {
  const raw = max / 3
  const pow = 10 ** Math.floor(Math.log10(raw))
  const step = [1, 2, 2.5, 5, 10].find((m) => m * pow >= raw) ?? 10
  return step * pow
}

function StockChart({ bars }: { bars: { label: string; value: number; old: boolean }[] }) {
  const W = 420
  const H = 190
  const L = 52
  const R = 6
  const T = 18
  const B = 26
  const max = Math.max(...bars.map((b) => b.value), 1)
  const step = niceStep(max)
  const top = Math.ceil(max / step) * step
  const ticks = Array.from({ length: Math.round(top / step) + 1 }, (_, i) => i * step)
  const y = (v: number) => T + (H - T - B) * (1 - v / top)
  const cw = (W - L - R) / bars.length
  const tick = (v: number) => (v === 0 ? '0' : v >= 1e6 ? `${(v / 1e6).toLocaleString('pl-PL')} mln` : `${nf(v / 1000)} tys.`)

  return (
    <svg viewBox={`0 0 ${W} ${H}`} className="block w-full" role="img" aria-label="Wartość towaru bez sprzedaży, w tysiącach złotych">
      {ticks.map((v) => (
        <g key={v}>
          <line x1={L} x2={W - R} y1={y(v)} y2={y(v)} className="stroke-slate-200" strokeWidth="1" />
          <text x={L - 6} y={y(v) + 3.5} textAnchor="end" className="fill-current text-[10.5px] text-slate-400">
            {tick(v)}
          </text>
        </g>
      ))}
      {bars.map((b, i) => {
        const w = cw * 0.56
        const x = L + i * cw + (cw - w) / 2
        return (
          <g key={b.label}>
            <rect x={x} y={y(b.value)} width={w} height={y(0) - y(b.value)} rx="3" className={b.old ? 'fill-amber-500' : 'app-dash-accent-fill fill-blue-600'} />
            <text x={x + w / 2} y={y(b.value) - 5} textAnchor="middle" className="fill-current text-[10.5px] font-semibold text-slate-700">
              {nf(Math.round(b.value / 1000))}
            </text>
            <text x={x + w / 2} y={H - 8} textAnchor="middle" className="fill-current text-[10.5px] text-slate-500">
              {b.label}
            </text>
          </g>
        )
      })}
    </svg>
  )
}

function StockCard({ s, span, to }: { s: NonNullable<Dash['stock']>; span: string; to: string }) {
  const b = s.buckets
  const stock = b?.stock
  const [valueNum, valueUnit] = stock ? splitUnit(money(stock.value)) : ['—', '']
  const bars = STOCK_BARS.flatMap((d) => {
    const bucket = b?.[d.key]
    return bucket ? [{ label: d.label, value: bucket.value, old: d.old }] : []
  })
  const year = b?.no_sale_12

  return (
    <Card
      icon="inventory"
      title="Zapasy"
      to={to}
      linkLabel="Zapasy"
      span={span}
      attention={
        year ? (
          <Attention tone="warn">
            <b>{thousands(year.value)}</b> w towarze bez sprzedaży ponad rok ({nf(year.items)} {plural(year.items, 'pozycja', 'pozycje', 'pozycji')}).
            {s.as_of && <> Stan z {localDay(s.as_of).toLocaleDateString('pl-PL')}, magazyny handlowe.</>}
          </Attention>
        ) : (
          <Attention tone="info">Brak zapisu stanów z ERP XL. Liczby pojawią się po nocnym odczycie.</Attention>
        )
      }
    >
      <div className="flex flex-wrap gap-x-6 gap-y-1">
        <Stat label="Wartość magazynu" value={valueNum} unit={valueUnit} />
        <Stat label="Towary" value={stock ? nf(stock.items) : '—'} />
        <Stat label="Nigdy niesprzedane" value={b?.never_sold ? nf(b.never_sold.items) : '—'} />
      </div>
      <div className="mt-3 grid grid-cols-1 gap-4 @xl:grid-cols-[minmax(0,1.5fr)_minmax(0,1fr)]">
        <div>
          <SubLabel>Towar bez sprzedaży od…, tys. zł</SubLabel>
          {bars.length > 0 ? <StockChart bars={bars} /> : <p className="text-xs text-slate-500">Brak danych.</p>}
        </div>
        <div className="min-w-0">
          <SubLabel>Najwięcej zamrożonej gotówki</SubLabel>
          {s.top_unsold.length === 0 && <p className="text-xs text-slate-500">Brak towaru bez sprzedaży ponad rok.</p>}
          <ul className="grid gap-2 text-[12.5px]">
            {s.top_unsold.map((item) => (
              <li key={item.code} className="grid grid-cols-[minmax(0,1fr)_auto] gap-x-2.5 border-b border-slate-200 pb-2 last:border-0">
                <span className="truncate" title={`${item.code} · ${item.name}`}>
                  {item.name}
                </span>
                <b className="tabular-nums">{money(item.value)}</b>
                <small className="col-span-2 text-[11.5px] text-slate-400">
                  {item.last_sale_at ? `bez sprzedaży od ${monthsSince(item.last_sale_at)} mies.` : 'nigdy nie sprzedany'} · {item.code}
                </small>
              </li>
            ))}
          </ul>
        </div>
      </div>
    </Card>
  )
}

function PricesCard({ p, span, to }: { p: NonNullable<Dash['prices']>; span: string; to: string }) {
  const accounts = p.accounts
  const failed = accounts?.filter((a) => a.state === 'failed') ?? []
  const ok = accounts?.filter((a) => a.state === 'ok').length ?? 0
  const style = (state: AccountState) => ACCOUNT_STATES.find((s) => s.state.includes(state)) ?? ACCOUNT_STATES[3]
  const present = ACCOUNT_STATES.filter((s) => accounts?.some((a) => s.state.includes(a.state)))

  return (
    <Card
      icon="price-lists"
      title="Cenniki"
      to={to}
      linkLabel={accounts ? 'Konta B2B' : 'Cenniki'}
      span={span}
      attention={
        accounts === null ? undefined : failed.length > 0 ? (
          <Attention tone="alert">
            <b>
              {failed.length} {plural(failed.length, 'konto', 'konta', 'kont')} z błędem:
            </b>{' '}
            {failed.map((a) => `${a.label}${a.message ? ` (${a.message})` : ''}`).join(', ')}
          </Attention>
        ) : (
          <Attention tone="info">Ostatnie sprawdzenie kont B2B bez błędów.</Attention>
        )
      }
    >
      <div className="flex flex-wrap gap-x-6 gap-y-1">
        {accounts && <Stat label="Konta B2B bez błędów" value={nf(ok)} unit={`z ${accounts.length}`} />}
        {p.prices_changed_7d !== null && <Stat label="Zmiany cen, 7 dni" value={nf(p.prices_changed_7d)} />}
      </div>
      {accounts && accounts.length > 0 && (
        <>
          <div className="mt-3 grid grid-cols-[repeat(auto-fill,minmax(104px,1fr))] gap-1.5">
            {accounts.map((a) => {
              const st = style(a.state)
              return (
                <div
                  key={a.id}
                  className={`flex min-w-0 items-center gap-1.5 rounded-md px-2 py-1 text-[11.5px] ${st.box}`}
                  title={[a.label, st.label, a.message].filter(Boolean).join(' · ')}
                >
                  <i className={`h-2 w-2 shrink-0 rounded-full ${st.dot}`} />
                  <span className="truncate">
                    {a.label}
                    {a.state === 'running' && a.progress !== null ? ` · ${a.progress}%` : ''}
                  </span>
                </div>
              )
            })}
          </div>
          <div className="mt-2 flex flex-wrap gap-x-3.5 gap-y-1 text-[11.5px] text-slate-500">
            {present.map((s) => (
              <span key={s.label} className="inline-flex items-center gap-1.5">
                <i className={`h-2 w-2 rounded-sm ${s.dot}`} />
                {s.label}
              </span>
            ))}
          </div>
        </>
      )}
      {p.file_imports && p.file_imports.length > 0 && (
        <dl className="mt-3 grid grid-cols-[minmax(0,1fr)_auto] gap-x-3 gap-y-1 text-[12.5px]">
          {p.file_imports.map((i) => (
            <div key={i.id} className="contents">
              <dt className="truncate text-slate-600">Cennik z pliku: {i.manufacturer ?? '—'}</dt>
              <dd className="text-right font-semibold tabular-nums">
                {i.created_at ? shortDate(i.created_at) : '—'} · {nf(i.rows_total)} {plural(i.rows_total, 'wiersz', 'wiersze', 'wierszy')}
              </dd>
            </div>
          ))}
        </dl>
      )}
    </Card>
  )
}

function CampaignsCard({ c }: { c: NonNullable<Dash['campaigns']> }) {
  const next = c.next
  return (
    <Card icon="campaigns" title="Kampanie" to="/kampanie" linkLabel="Kampanie" span="@5xl:col-span-12">
      <div className="grid grid-cols-1 gap-5 @3xl:grid-cols-[260px_minmax(0,1fr)]">
        <div>
          <div className="rounded-lg bg-blue-50 px-3.5 py-3">
            <div className="app-dash-label text-[11px] font-medium tracking-wide text-slate-500 uppercase">Najbliższa wysyłka</div>
            {next ? (
              <Link to={`/kampanie/${next.id}`} className="mt-1 block text-inherit hover:underline">
                <b className="block">{next.name}</b>
                {next.scheduled_at && (
                  <span className="text-[12.5px] text-slate-600">
                    {new Date(next.scheduled_at).toLocaleString('pl-PL', { weekday: 'short', day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })}
                  </span>
                )}
              </Link>
            ) : (
              <span className="mt-1 block text-[12.5px] text-slate-600">Nic nie jest zaplanowane.</span>
            )}
          </div>
          <dl className="mt-3 grid grid-cols-[minmax(0,1fr)_auto] gap-x-3 gap-y-1 text-[12.5px]">
            <dt className="text-slate-600">Szkice do wysłania</dt>
            <dd className="text-right font-semibold tabular-nums">{nf(c.drafts)}</dd>
            <dt className="text-slate-600">Wysłane maile, 30 dni</dt>
            <dd className="text-right font-semibold tabular-nums">{nf(c.sent_30d)}</dd>
            <dt className="text-slate-600">Odpowiedzi klientów, 30 dni</dt>
            <dd className="text-right font-semibold tabular-nums">{nf(c.replies_30d)}</dd>
          </dl>
        </div>
        <div className="min-w-0 overflow-x-auto">
          {c.recent.length === 0 ? (
            <p className="text-xs text-slate-500">Nie wysłano jeszcze żadnej kampanii.</p>
          ) : (
            <table className="app-table w-full text-left text-xs">
              <thead>
                <tr className="border-b">
                  <th className="p-2">Ostatnie kampanie</th>
                  <th className="p-2">Wysłana</th>
                  <th className="p-2 text-right">Wysłane</th>
                  <th className="p-2 text-right">Kliknięcia</th>
                  <th className="p-2 text-right">Odpowiedzi</th>
                  <th className="p-2">Zapas zszedł</th>
                </tr>
              </thead>
              <tbody>
                {c.recent.map((r) => (
                  <tr key={r.id} className="border-b last:border-0">
                    <td className="p-2">
                      <Link to={`/kampanie/${r.id}`} className="app-link font-semibold text-inherit hover:underline">
                        {r.name}
                      </Link>
                      {r.status === 'sending' && <span className="ml-1.5 rounded-full bg-blue-50 px-1.5 text-[11px] text-blue-700">w trakcie</span>}
                    </td>
                    <td className="p-2 tabular-nums">{r.sent_at ? shortDate(r.sent_at) : '—'}</td>
                    <td className="p-2 text-right tabular-nums">{nf(r.sent)}</td>
                    <td className="p-2 text-right tabular-nums">
                      {nf(r.clicked)}
                      {r.sent > 0 && <span className="ml-1 text-slate-400">({Math.round((r.clicked / r.sent) * 1000) / 10}%)</span>}
                    </td>
                    <td className="p-2 text-right tabular-nums">{nf(r.replies)}</td>
                    <td className="p-2">
                      {r.drop_percent !== null ? (
                        <span className="inline-flex items-center gap-2">
                          <span className="w-16">
                            <Bar percent={r.drop_percent} fill="app-dash-accent bg-blue-600" />
                          </span>
                          <b className="tabular-nums">−{r.drop_percent.toLocaleString('pl-PL')}%</b>
                        </span>
                      ) : (
                        <span className="text-slate-400">—</span>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      </div>
    </Card>
  )
}
