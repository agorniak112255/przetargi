import { useCallback, useEffect, useRef, useState, type KeyboardEvent as ReactKeyboardEvent, type ReactNode } from 'react'
import { createPortal } from 'react-dom'
import { useSearchParams } from 'react-router-dom'
import {
  api,
  type InventoryBoardBucket,
  type InventoryBoardGroupKey,
  type InventoryBoardItemRow,
  type InventoryBoardItemsBucket,
  type InventoryBoardItemsResponse,
  type InventoryBoardMoveRow,
  type InventoryBoardMovesResponse,
  type InventoryBoardReport as BoardReport,
  type InventoryBoardWarehouses as Warehouses,
} from '../lib/api'

/**
 * Raport zapasów dla zarządu: jedna strona z gotowymi progami (GET /api/inventory/board?warehouses=…).
 * Odbiorcy to osoby niekoniecznie biegłe w komputerze — duże litery, każda liczba podpisana, zero żargonu
 * magazynowego, same fakty bez zaleceń. Każda kwota z napisem „Pokaż listę ›” otwiera okno z listą towarów
 * (GET /api/inventory/board/items) albo dokumentów (GET /api/inventory/board/moves), żeby liczbę dało się
 * sprawdzić. Strona niczego nie przelicza poza formatem kwot, dat i udziałów procentowych; wszystkie liczby
 * pochodzą z serwera (program magazynowy, odczyt z nocy). Strona drukuje się na jednej kartce A4, okno —
 * przyciskiem „Drukuj” w oknie — samo.
 */

/** Twarda spacja między grupami cyfr — liczba nie łamie się na końcu wiersza. */
const NBSP = ' '

/** Liczba całkowita z odstępem co 3 cyfry także dla 4 cyfr („7 589”) — Intl pl-PL grupuje dopiero od 5. */
function groupInt(n: number): string {
  const rounded = Math.round(Math.abs(n))
  const digits = String(rounded).replace(/\B(?=(\d{3})+(?!\d))/g, NBSP)
  return n < 0 && rounded > 0 ? `−${digits}` : digits
}

/**
 * Kwota do czytania na głos: od 1 mln „12,3 mln zł”, od 10 tys. „712 tys. zł”, poniżej dokładnie „7 589 zł”
 * (pełne złote — grosze nic nie mówią zarządowi).
 */
function fmtBig(value: number): string {
  const abs = Math.abs(value)
  const sign = value < 0 ? '−' : ''
  if (abs >= 10_000) {
    const thousands = Math.round(abs / 1000)
    if (thousands < 1000) return `${sign}${groupInt(thousands)}${NBSP}tys.${NBSP}zł`
    const millions = (Math.round(abs / 100_000) / 10).toFixed(1).replace(/\.0$/, '').replace('.', ',')
    return `${sign}${millions}${NBSP}mln${NBSP}zł`
  }
  return `${sign}${groupInt(abs)}${NBSP}zł`
}

/** Pełne złote z odstępem tysięcy („7 589 zł”) — wiersze tabel w oknie. */
function fmtZl(value: number): string {
  return `${groupInt(value)}${NBSP}zł`
}

/** Cena za jednostkę: poniżej 100 zł z groszami („12,50 zł”), wyżej pełne złote („290 zł”). */
function fmtUnitCost(value: number): string {
  if (Math.abs(value) >= 100) return fmtZl(value)
  const [int, frac] = Math.abs(value).toFixed(2).split('.')
  return `${value < 0 ? '−' : ''}${groupInt(Number(int))},${frac}${NBSP}zł`
}

/** Polska liczba mnoga: 1 → one, 2–4 (bez 12–14) → few, reszta → many (jak na stronie Zapasy). */
function plural(n: number, one: string, few: string, many: string): string {
  const mod10 = n % 10
  const mod100 = n % 100
  if (n === 1) return one
  return mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14) ? few : many
}

function goods(n: number): string {
  return `${groupInt(n)} ${plural(n, 'towar', 'towary', 'towarów')}`
}

function times(n: number): string {
  return `${groupInt(n)} ${plural(n, 'raz', 'razy', 'razy')}`
}

/** „3 miesiące”, „1 miesiąc”, „6 miesięcy”. */
function monthsLabel(n: number): string {
  return `${n} ${plural(n, 'miesiąc', 'miesiące', 'miesięcy')}`
}

/** „10%” (zaokrąglone), „mniej niż 1%”; null, gdy całości nie ma (nie dzielimy przez zero). */
function pct(part: number, whole: number): string | null {
  if (!(whole > 0)) return null
  const p = (part / whole) * 100
  if (p <= 0) return '0%'
  if (p < 1) return 'mniej niż 1%'
  return `${Math.round(p)}%`
}

/** „29 września 2026” — z ISO (data albo data z godziną); null, gdy daty nie da się odczytać. */
function longDate(iso: string): string | null {
  // Sama data 'YYYY-MM-DD' jako dzień kalendarzowy, bez przesunięcia strefy czasowej.
  const d = /^\d{4}-\d{2}-\d{2}$/.test(iso) ? new Date(`${iso}T12:00:00`) : new Date(iso)
  if (Number.isNaN(d.getTime())) return null
  return d.toLocaleDateString('pl-PL', { day: 'numeric', month: 'long', year: 'numeric' })
}

/** Ostatnia sprzedaż jako „06.2025” (miesiąc.rok) — lista najdroższych na stronie. */
function monthYear(iso: string): string {
  const [y, m] = iso.slice(0, 10).split('-')
  return y && m ? `${m}.${y}` : iso
}

/** Mianownik, bo w tabeli miesiąc stoi sam („czerwiec 2025”), a Intl bywa niekonsekwentny co do przypadka. */
const MONTHS = [
  'styczeń',
  'luty',
  'marzec',
  'kwiecień',
  'maj',
  'czerwiec',
  'lipiec',
  'sierpień',
  'wrzesień',
  'październik',
  'listopad',
  'grudzień',
]

/** Rok i miesiąc z 'YYYY-MM-DD…'; null, gdy nie da się odczytać. */
function yearMonth(iso: string): { y: number; m: number } | null {
  const match = /^(\d{4})-(\d{2})/.exec(iso)
  if (!match) return null
  const y = Number(match[1])
  const m = Number(match[2])
  return m >= 1 && m <= 12 ? { y, m } : null
}

/** „czerwiec 2025”. */
function monthName(iso: string): string {
  const ym = yearMonth(iso)
  return ym ? `${MONTHS[ym.m - 1]} ${ym.y}` : iso
}

/** Ile pełnych miesięcy kalendarzowych minęło od `iso` do `ref` (daty odczytu danych); null, gdy nie wiadomo. */
function monthsSince(iso: string, ref: string | null): number | null {
  const from = yearMonth(iso)
  const refDate = ref ? new Date(ref) : new Date()
  if (!from || Number.isNaN(refDate.getTime())) return null
  const diff = refDate.getFullYear() * 12 + refDate.getMonth() + 1 - (from.y * 12 + from.m)
  return diff >= 0 ? diff : null
}

/** „(15 mies. temu)”, powyżej 24 mies. „(4 lata temu)”. */
function agoText(months: number): string {
  if (months === 0) return '(w tym miesiącu)'
  if (months <= 24) return `(${months} mies. temu)`
  const years = Math.floor(months / 12)
  return `(${years} ${plural(years, 'rok', 'lata', 'lat')} temu)`
}

/** Jak długo leżał: „8 mies.”, od roku „4 lata 3 mies.”. */
function lotAgeText(months: number): string {
  if (months <= 0) return 'mniej niż miesiąc'
  if (months < 12) return `${months} mies.`
  const years = Math.floor(months / 12)
  const rest = months % 12
  return `${years} ${plural(years, 'rok', 'lata', 'lat')}${rest > 0 ? ` ${rest} mies.` : ''}`
}

function fmtQuantity(q: number, unit: string | null): string {
  const n = q.toLocaleString('pl-PL', { maximumFractionDigits: 2, useGrouping: false })
  const [int, frac] = n.split(',')
  const text = frac ? `${groupInt(Number(int))},${frac}` : groupInt(q)
  return unit ? `${text}${NBSP}${unit}` : text
}

function bucket(list: InventoryBoardBucket[] | undefined, months: number): InventoryBoardBucket | null {
  return list?.find((b) => b.months === months) ?? null
}

/** Magazyny w adresie: ?magazyny=uslugowe / wszystkie; handlowe (domyślne) bez parametru. */
const WAREHOUSE_PARAM: Record<Warehouses, string | null> = { trade: null, service: 'uslugowe', all: 'wszystkie' }

function warehousesFromParam(value: string | null): Warehouses {
  if (value === 'uslugowe') return 'service'
  if (value === 'wszystkie') return 'all'
  return 'trade'
}

const WAREHOUSE_OPTIONS: { value: Warehouses; label: string }[] = [
  { value: 'trade', label: 'Magazyny handlowe' },
  { value: 'service', label: 'Magazyny usługowe' },
  { value: 'all', label: 'Wszystkie magazyny' },
]

const WAREHOUSE_LABEL: Record<Warehouses, string> = {
  trade: 'Magazyny handlowe',
  service: 'Magazyny usługowe',
  all: 'Wszystkie magazyny',
}

/** Dopisek do tytułu okna. */
const WAREHOUSE_SUFFIX: Record<Warehouses, string> = {
  trade: '(magazyny handlowe)',
  service: '(magazyny usługowe)',
  all: '(wszystkie magazyny)',
}

/** Początek zdania „Najważniejsze”. */
const WAREHOUSE_WHERE: Record<Warehouses, string> = {
  trade: 'Na magazynach handlowych',
  service: 'Na magazynach usługowych',
  all: 'Na wszystkich magazynach',
}

/** Podpis kafelka „cały towar”. */
const WAREHOUSE_STOCK_LABEL: Record<Warehouses, string> = {
  trade: 'cały towar w magazynach handlowych',
  service: 'cały towar w magazynach usługowych',
  all: 'cały towar we wszystkich magazynach',
}

// Druk strony: bez menu (print:hidden w Layout) i przycisków, czarno na białym, jedna kartka A4.
// Okno z listą (portal w <body>) w zwykłym druku znika; „Drukuj” w oknie ustawia na <body> klasę
// board-print-modal i wtedy drukuje się samo okno (nagłówek + tabela), bez strony pod spodem.
// Klasy „board-*” istnieją tylko na tej stronie, więc style nie ruszają innych widoków.
const PRINT_CSS = `
@media print {
  @page { size: A4 portrait; margin: 10mm; }
  html, body { overflow: visible !important; height: auto !important; }
  html, body, .app-shell, .app-main { background: #fff !important; }
  .app-main { padding: 0 !important; overflow: visible !important; }
  .board-report { zoom: 0.66; max-width: none !important; margin: 0 !important; }
  .board-report, .board-report * {
    color: #000 !important;
    background: #fff !important;
    box-shadow: none !important;
    border-color: #666 !important;
    print-color-adjust: exact;
    -webkit-print-color-adjust: exact;
  }
  .board-block { break-inside: avoid; page-break-inside: avoid; }
  .board-modal { display: none !important; }
  body.board-print-modal > * { display: none !important; }
  body.board-print-modal > .board-modal {
    display: block !important;
    position: static !important;
    padding: 0 !important;
    background: #fff !important;
  }
  body.board-print-modal .board-modal-panel {
    display: block !important;
    max-width: none !important;
    max-height: none !important;
    overflow: visible !important;
    border: 0 !important;
    box-shadow: none !important;
  }
  body.board-print-modal .board-modal-scroll { overflow: visible !important; }
  body.board-print-modal .board-modal thead th { position: static !important; }
  body.board-print-modal .board-modal-noprint { display: none !important; }
  body.board-print-modal .board-modal, body.board-print-modal .board-modal * {
    color: #000 !important;
    background: #fff !important;
    border-color: #666 !important;
  }
  body.board-print-modal .board-modal tr { break-inside: avoid; page-break-inside: avoid; }
}
`

/** Co pokazać w oknie: lista towarów z koszyka albo lista dokumentów (wydany i od razu przyjęty z powrotem). */
type DetailsRequest =
  | {
      kind: 'items'
      bucket: InventoryBoardItemsBucket
      group: InventoryBoardGroupKey | null
      /** Napis z kafelka — nagłówek okna. */
      label: string
    }
  | {
      kind: 'moves'
      scope: 'all' | 'unexplained'
      /** Osoba z listy „kto wystawił” (nazwisko dosłownie jak z API) albo null = wszyscy. */
      person: { operator: string; name: string; count: number; total: number } | null
      /** Nagłówek okna (bez osoby); z osobą nagłówek to „Dokumenty wystawione przez: …”. */
      label: string
    }

type Tone = 'neutral' | 'amber' | 'red'

const TONE: Record<Tone, { box: string; number: string }> = {
  neutral: { box: 'border-slate-300 bg-white', number: 'text-slate-900' },
  amber: { box: 'border-amber-300 bg-amber-50', number: 'text-amber-900' },
  red: { box: 'border-red-300 bg-red-50', number: 'text-red-800' },
}

/** Fokus z klawiatury: gruba niebieska obwódka (kolor nie jest tu jedyną informacją — to tylko wskaźnik fokusu). */
const FOCUS = 'focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-blue-500'

const BIG_BUTTON = `rounded-lg border border-slate-300 bg-white px-6 py-3 text-xl font-medium text-slate-800 shadow-sm hover:bg-slate-50 disabled:opacity-50 ${FOCUS}`

/** Widoczny napis przy każdej klikalnej liczbie. */
function ShowList() {
  return (
    <span className="block text-lg font-semibold text-blue-700 underline underline-offset-4 print:hidden">
      Pokaż listę ›
    </span>
  )
}

export function InventoryBoardReport() {
  const [params, setParams] = useSearchParams()
  const warehouses = warehousesFromParam(params.get('magazyny'))
  const [report, setReport] = useState<BoardReport | null>(null)
  const [loading, setLoading] = useState(true)
  const [failed, setFailed] = useState(false)
  const [details, setDetails] = useState<DetailsRequest | null>(null)
  const seq = useRef(0)

  const load = useCallback(async () => {
    const my = ++seq.current
    setLoading(true)
    setFailed(false)
    try {
      const res = await api<BoardReport>(`/inventory/board?warehouses=${warehouses}`)
      if (my === seq.current) setReport(res)
    } catch {
      if (my === seq.current) setFailed(true)
    } finally {
      if (my === seq.current) setLoading(false)
    }
  }, [warehouses])

  useEffect(() => {
    void load()
  }, [load])

  const chooseWarehouses = (value: Warehouses) => {
    setParams(
      (prev) => {
        const next = new URLSearchParams(prev)
        const param = WAREHOUSE_PARAM[value]
        if (param) next.set('magazyny', param)
        else next.delete('magazyny')
        return next
      },
      { replace: true },
    )
  }

  const closeDetails = useCallback(() => setDetails(null), [])

  // Których magazynów dotyczą pokazane liczby: echo z serwera (w trakcie przełączania to jeszcze poprzednie).
  const shown: Warehouses = report?.warehouses ?? warehouses
  const asOf = report?.as_of ? longDate(report.as_of) : null

  return (
    <div className="board-report mx-auto max-w-6xl pb-10 text-lg text-slate-900">
      <style>{PRINT_CSS}</style>

      <header className="board-block mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 className="text-4xl font-semibold text-slate-900">Zapasy — raport dla zarządu</h1>
          {report && (
            <p className="mt-3 text-2xl text-slate-800">{asOf ? `Stan na ${asOf}` : 'Brak daty odczytu danych'}</p>
          )}
          <p className="mt-1 hidden text-2xl font-semibold text-slate-900 print:block">{WAREHOUSE_LABEL[shown]}</p>
          <p className="mt-1 text-lg text-slate-700">Dane z programu magazynowego, dane z nocy</p>
        </div>
        <button type="button" onClick={() => window.print()} className={`${BIG_BUTTON} print:hidden`}>
          Drukuj / zapisz PDF
        </button>
      </header>

      <WarehouseSwitch value={warehouses} onChange={chooseWarehouses} />

      {failed ? (
        <div className="py-6">
          <p className="text-2xl font-semibold text-red-800" role="alert">
            Nie udało się wczytać raportu. Spróbuj ponownie.
          </p>
          <button type="button" onClick={() => void load()} disabled={loading} className={`mt-6 ${BIG_BUTTON}`}>
            {loading ? 'Wczytywanie…' : 'Spróbuj ponownie'}
          </button>
        </div>
      ) : !report ? (
        <p className="py-6 text-3xl font-semibold text-slate-800" role="status">
          Wczytywanie raportu…
        </p>
      ) : (
        <>
          {loading && (
            <p className="mb-4 text-xl font-semibold text-slate-800 print:hidden" role="status">
              Wczytywanie…
            </p>
          )}
          <div className={loading ? 'opacity-50' : undefined} aria-busy={loading}>
            <BoardView report={report} warehouses={shown} onOpen={setDetails} />
          </div>
        </>
      )}

      {details && report && (
        <BoardDetailsModal
          request={details}
          warehouses={shown}
          stockValue={report.stock.value}
          asOf={report.as_of}
          onClose={closeDetails}
        />
      )}
    </div>
  )
}

function WarehouseSwitch({ value, onChange }: { value: Warehouses; onChange: (value: Warehouses) => void }) {
  return (
    <div className="board-block mb-8 print:hidden">
      <div
        className="inline-flex flex-wrap overflow-hidden rounded-xl border-2 border-slate-300"
        role="group"
        aria-label="Które magazyny"
      >
        {WAREHOUSE_OPTIONS.map((o, i) => {
          const active = o.value === value
          return (
            <button
              key={o.value}
              type="button"
              aria-pressed={active}
              onClick={() => onChange(o.value)}
              className={`px-6 py-3 text-xl ${i > 0 ? 'border-l-2 border-slate-300' : ''} ${FOCUS} ${
                active ? 'bg-blue-600 font-semibold text-white' : 'bg-white text-slate-800 hover:bg-slate-50'
              }`}
            >
              {active ? '✓ ' : ''}
              {o.label}
            </button>
          )
        })}
      </div>
      <p className="mt-2 text-lg text-slate-700">Handlowe — towar na sprzedaż. Usługowe — towar trzymany dla klientów.</p>
    </div>
  )
}

function BoardView({
  report,
  warehouses,
  onOpen,
}: {
  report: BoardReport
  warehouses: Warehouses
  onOpen: (request: DetailsRequest) => void
}) {
  const stockValue = report.stock.value
  const noSale6 = bucket(report.no_sale, 6)
  const noSale12 = bucket(report.no_sale, 12)
  const noSale24 = bucket(report.no_sale, 24)
  const stale36 = bucket(report.stale_lot, 36)
  const stale60 = bucket(report.stale_lot, 60)
  const groups = report.groups ?? []
  const moves = report.internal_moves
  const movesFrom = longDate(moves.from)
  const empty = report.stock.items === 0
  const share = (value: number) => pct(value, stockValue)
  const openItems = (b: InventoryBoardItemsBucket, label: string, group: InventoryBoardGroupKey | null = null) =>
    onOpen({ kind: 'items', bucket: b, group, label })

  if (empty) {
    return (
      <p className="text-2xl text-slate-800">
        Brak danych o towarze w tych magazynach. Program magazynowy nie przekazał jeszcze stanu magazynów.
      </p>
    )
  }

  const topSum = report.top_unsold.reduce((sum, item) => sum + item.value, 0)
  const topCount = report.top_unsold.length
  const restCount = noSale12 ? noSale12.items - topCount : 0

  return (
    <>
      <p className="mb-6 text-lg text-slate-700 print:hidden">
        Kwotę z napisem „Pokaż listę ›” można kliknąć — otworzy się lista towarów, z których się składa.
      </p>

      <section className="board-block mb-8 rounded-2xl border-2 border-slate-300 bg-white px-6 py-5 shadow-sm">
        <h2 className="text-2xl font-semibold text-slate-900">Najważniejsze</h2>
        <p className="mt-2 text-xl leading-relaxed text-slate-900">
          {WAREHOUSE_WHERE[warehouses]} leży towar za <strong className="tabular-nums">{fmtBig(stockValue)}</strong>.
          {noSale6 && (
            <>
              {' '}
              Za <strong className="tabular-nums">{fmtBig(noSale6.value)}</strong>
              {share(noSale6.value) ? ` (to ${share(noSale6.value)} wartości magazynu)` : ''} jest towar, który od
              pół roku nie sprzedał się ani razu
              {noSale12 ? (
                <>
                  , z czego <strong className="tabular-nums">{fmtBig(noSale12.value)}</strong> nie sprzedaje się ponad
                  rok
                </>
              ) : null}
              .
            </>
          )}{' '}
          {moves.unexplained > 0 ? (
            <>
              W ostatnich 12 miesiącach <strong className="tabular-nums">{times(moves.unexplained)}</strong> towar
              leżący co najmniej {monthsLabel(moves.min_lot_age_months)} wydano i przyjęto z powrotem bez żadnego opisu,
              na <strong className="tabular-nums">{fmtBig(moves.unexplained_value)}</strong>.
            </>
          ) : (
            <>
              W ostatnich 12 miesiącach ani razu nie wydano i nie przyjęto z powrotem bez opisu towaru leżącego co
              najmniej {monthsLabel(moves.min_lot_age_months)}.
            </>
          )}
        </p>
      </section>

      <section className="mb-8">
        <ClickTile
          className={`rounded-2xl border-2 px-6 py-5 shadow-sm ${TONE.neutral.box}`}
          onOpen={() => openItems('stock', 'Cały towar')}
        >
          <span className={`block text-4xl font-semibold tabular-nums ${TONE.neutral.number}`}>
            {fmtBig(stockValue)}
          </span>
          <span className="mt-2 block text-xl font-medium text-slate-900">{WAREHOUSE_STOCK_LABEL[warehouses]}</span>
          <span className="mt-1 block text-lg text-slate-700">{goods(report.stock.items)}</span>
          {report.split && (
            <span className="mt-1 block text-lg text-slate-700">
              {warehouses === 'all' ? 'w tym handlowe' : 'handlowe'}: {fmtBig(report.split.trade.value)} · usługowe:{' '}
              {fmtBig(report.split.service.value)}
            </span>
          )}
        </ClickTile>
      </section>

      <section className="board-block mb-8">
        <h2 className="mb-1 text-2xl font-semibold text-slate-900">Towar bez żadnej sprzedaży</h2>
        <p className="mb-3 text-lg text-slate-700">
          Wiersz „w tym” jest częścią kwoty z wiersza, pod którym jest wcięty, więc kwot się nie dodaje.
        </p>
        <ol className="space-y-3">
          {noSale6 && (
            <StepRow
              level={0}
              tone="amber"
              label="ponad pół roku bez sprzedaży"
              items={noSale6.items}
              value={noSale6.value}
              share={share(noSale6.value)}
              onOpen={() => openItems('no_sale_6', 'Nie sprzedaje się od pół roku')}
            />
          )}
          {noSale12 && (
            <StepRow
              level={1}
              tone="red"
              label="w tym ponad rok bez sprzedaży"
              items={noSale12.items}
              value={noSale12.value}
              share={share(noSale12.value)}
              onOpen={() => openItems('no_sale_12', 'Nie sprzedaje się ponad rok')}
            />
          )}
          {noSale24 && (
            <StepRow
              level={2}
              tone="red"
              label="w tym ponad 2 lata bez sprzedaży"
              items={noSale24.items}
              value={noSale24.value}
              share={share(noSale24.value)}
              onOpen={() => openItems('no_sale_24', 'Nie sprzedaje się ponad 2 lata')}
            />
          )}
          <StepRow
            level={1}
            tone="red"
            label="w tym ani razu nie sprzedany"
            items={report.never_sold.items}
            value={report.never_sold.value}
            share={share(report.never_sold.value)}
            onOpen={() => openItems('never_sold', 'Ani razu nie sprzedany')}
          />
        </ol>
        <p className="mt-3 text-lg text-slate-700">
          „Ani razu nie sprzedany” = towar, który leży w magazynie ponad pół roku i nie sprzedał się ani razu.
        </p>
      </section>

      <section className="board-block mb-8">
        <h2 className="mb-1 text-2xl font-semibold text-slate-900">
          Towar bez sprzedaży ponad rok — jak długo już leży
        </h2>
        <p className="mb-3 text-lg text-slate-700">Tylko towar, który od ponad roku nie sprzedał się ani razu.</p>
        <div className="grid gap-4 sm:grid-cols-2">
          <AgeTile
            bucket={stale36}
            label="najstarsza sztuka leży ponad 3 lata"
            share={stale36 ? share(stale36.value) : null}
            onOpen={() => openItems('stale_36', 'Bez sprzedaży ponad rok, leży ponad 3 lata')}
          />
          <AgeTile
            bucket={stale60}
            label="najstarsza sztuka leży ponad 5 lat"
            share={stale60 ? share(stale60.value) : null}
            onOpen={() => openItems('stale_60', 'Bez sprzedaży ponad rok, leży ponad 5 lat')}
          />
        </div>
        {report.lot_12_total && (
          <p className="mt-3 text-base text-slate-700">
            Dla porównania: {goods(report.lot_12_total.items)} ma w magazynie część sztuk przyjętych ponad rok temu.
            Cały zapas tych towarów jest wart {fmtBig(report.lot_12_total.value)}. Większość z nich normalnie się
            sprzedaje, tylko starsze sztuki jeszcze nie zeszły — dlatego nie liczymy ich w kwotach powyżej.
          </p>
        )}
      </section>

      {groups.length > 0 && (
        <section className="board-block mb-8">
          <h2 className="mb-3 text-2xl font-semibold text-slate-900">
            Gdzie leży towar bez sprzedaży ponad rok — według rodzaju
          </h2>
          <div className="overflow-x-auto rounded-2xl border border-slate-300 bg-white shadow-sm">
            <table className="w-full text-left text-lg">
              <thead>
                <tr className="border-b-2 border-slate-300 bg-slate-100">
                  <th scope="col" className="px-4 py-3 font-semibold text-slate-900">
                    Rodzaj
                  </th>
                  <th scope="col" className="px-4 py-3 text-right font-semibold text-slate-900">
                    Bez sprzedaży ponad rok
                  </th>
                  <th scope="col" className="px-4 py-3 text-right font-semibold text-slate-900">
                    Cały towar tego rodzaju
                  </th>
                  <th scope="col" className="px-4 py-3 text-right font-semibold text-slate-900">
                    Jaka to część
                  </th>
                  <th scope="col" className="px-4 py-3 print:hidden">
                    <span className="sr-only">Lista</span>
                  </th>
                </tr>
              </thead>
              <tbody>
                {groups.map((g) => (
                  <tr key={g.group} className="border-b border-slate-200 last:border-b-0">
                    <td className="px-4 py-3 text-xl font-medium text-slate-900">{g.label}</td>
                    <td className="px-4 py-3 text-right whitespace-nowrap">
                      <span className="block text-xl font-semibold tabular-nums">{fmtBig(g.unsold_value)}</span>
                      <span className="block text-base text-slate-700">{goods(g.unsold_items)}</span>
                    </td>
                    <td className="px-4 py-3 text-right whitespace-nowrap tabular-nums">{fmtBig(g.stock_value)}</td>
                    <td className="px-4 py-3 text-right whitespace-nowrap tabular-nums">
                      {pct(g.unsold_value, g.stock_value) ?? '—'}
                    </td>
                    <td className="px-4 py-2 text-right print:hidden">
                      {g.unsold_items > 0 && (
                        <button
                          type="button"
                          onClick={() => openItems('no_sale_12', `Bez sprzedaży ponad rok — ${g.label}`, g.group)}
                          className={`rounded-lg px-3 py-2 text-lg font-semibold whitespace-nowrap text-blue-700 underline underline-offset-4 hover:bg-slate-50 ${FOCUS}`}
                        >
                          Pokaż listę ›
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      )}

      <section className="board-block mb-8">
        <h2 className="mb-3 text-2xl font-semibold text-slate-900">
          Najdroższe towary, które nie sprzedają się ponad rok
        </h2>
        {topCount === 0 ? (
          <p className="text-xl text-slate-800">Nie ma takiego towaru.</p>
        ) : (
          <>
            <ol className="divide-y divide-slate-200 rounded-2xl border border-slate-300 bg-white shadow-sm">
              {report.top_unsold.map((item, i) => (
                <li key={`${item.code}-${i}`} className="flex items-start gap-4 px-6 py-3">
                  <span className="w-8 shrink-0 text-2xl font-semibold text-slate-700 tabular-nums">{i + 1}.</span>
                  <div className="min-w-0 flex-1">
                    <div className="text-xl font-medium break-words text-slate-900">{item.card_name ?? item.name}</div>
                    <div className="text-lg text-slate-700">
                      {fmtQuantity(item.quantity, item.unit)} ·{' '}
                      {item.last_sale_at ? `ostatnia sprzedaż ${monthYear(item.last_sale_at)}` : 'nigdy nie sprzedany'}
                    </div>
                  </div>
                  <span className="shrink-0 text-2xl font-semibold text-slate-900 tabular-nums">
                    {fmtBig(item.value)}
                  </span>
                </li>
              ))}
            </ol>
            {noSale12 && (
              <p className="mt-3 text-xl text-slate-900">
                {topCount === 1 ? 'Najdroższy towar to' : `${topCount} ${plural(topCount, 'najdroższy towar', 'najdroższe towary', 'najdroższych towarów')} to`}{' '}
                <strong className="tabular-nums">{fmtBig(topSum)}</strong> z{' '}
                <strong className="tabular-nums">{fmtBig(noSale12.value)}</strong>.
                {restCount > 0 && (
                  <>
                    {' '}
                    Reszta rozkłada się na {groupInt(restCount)}{' '}
                    {plural(restCount, 'drobniejszy towar', 'drobniejsze towary', 'drobniejszych towarów')}.
                  </>
                )}
              </p>
            )}
            <button
              type="button"
              onClick={() => openItems('no_sale_12', 'Nie sprzedaje się ponad rok')}
              className={`mt-4 ${BIG_BUTTON} print:hidden`}
            >
              Więcej — pokaż listę ›
            </button>
          </>
        )}
      </section>

      <section className="board-block mb-8 rounded-2xl border border-slate-300 bg-white px-6 py-5 shadow-sm">
        <h2 className="mb-3 text-2xl font-semibold text-slate-900">
          Zalegający towar wydany i od razu przyjęty z powrotem
        </h2>
        <p className="mb-3 text-lg text-slate-700">
          Tylko towar, który przed wydaniem leżał w magazynie co najmniej {monthsLabel(moves.min_lot_age_months)}. Po
          wydaniu i ponownym przyjęciu taki towar wygląda w systemie jak nowa dostawa.
        </p>
        <p className="text-xl text-slate-900">
          W ostatnich 12 miesiącach
          {movesFrom ? <span className="text-slate-700"> (od {movesFrom})</span> : null}:{' '}
          {moves.total > 0 ? (
            <>
              <strong className="tabular-nums">{times(moves.total)}</strong> (część z nich to zamiana rozmiaru albo
              koloru).
            </>
          ) : (
            <strong>ani razu.</strong>
          )}
        </p>
        {moves.total > 0 && (
          <InlineShowList
            onClick={() =>
              onOpen({ kind: 'moves', scope: 'all', person: null, label: 'Towar wydany i od razu przyjęty z powrotem' })
            }
          />
        )}
        {moves.total > 0 && (
          <p className="mt-4 text-xl text-slate-900">
            Bez żadnego opisu:{' '}
            {moves.unexplained > 0 ? (
              <>
                <strong className="tabular-nums">{times(moves.unexplained)}</strong>, wartość{' '}
                <strong className="tabular-nums">{fmtBig(moves.unexplained_value)}</strong>.
              </>
            ) : (
              <strong>ani razu.</strong>
            )}
          </p>
        )}
        {moves.unexplained > 0 && (
          <InlineShowList
            onClick={() =>
              onOpen({
                kind: 'moves',
                scope: 'unexplained',
                person: null,
                label: 'Towar wydany i od razu przyjęty z powrotem bez żadnego opisu',
              })
            }
          />
        )}
        {moves.total > 0 && (
          <p className="mt-2 text-lg text-slate-700">
            „Bez opisu” = ten sam rozmiar i kolor, a na dokumencie nie ma opisu, dlaczego towar wydano i przyjęto z
            powrotem.
          </p>
        )}
        {moves.unexplained > 0 && moves.people.length > 0 && (
          <div className="mt-5">
            <p className="text-lg font-medium text-slate-800">Kto wystawił najwięcej takich dokumentów bez opisu:</p>
            <ul className="mt-2 divide-y divide-slate-200">
              {moves.people.map((p, i) => (
                <li key={`${p.operator}-${i}`} className="flex flex-wrap items-center justify-between gap-x-6 gap-y-2 py-2">
                  <span className="text-xl text-slate-900">
                    {p.name} — <strong className="tabular-nums">{groupInt(p.count)}</strong> bez opisu (z{' '}
                    <span className="tabular-nums">{groupInt(p.total)}</span> wszystkich)
                  </span>
                  {p.operator ? (
                    <button
                      type="button"
                      onClick={() =>
                        onOpen({
                          kind: 'moves',
                          scope: 'unexplained',
                          person: { operator: p.operator, name: p.name, count: p.count, total: p.total },
                          label: `Dokumenty wystawione przez: ${p.name}`,
                        })
                      }
                      className={`rounded-lg border border-slate-300 bg-white px-4 py-2 text-lg font-semibold text-blue-700 shadow-sm hover:bg-slate-50 print:hidden ${FOCUS}`}
                    >
                      Pokaż dokumenty ({groupInt(p.count)})
                    </button>
                  ) : null}
                </li>
              ))}
            </ul>
          </div>
        )}
      </section>

      <footer className="board-block border-t border-slate-300 pt-4 text-base text-slate-700">
        <p>
          Wartość = cena zakupu towaru, który leży w magazynie, według programu magazynowego. Ilości i kwoty dotyczą
          wybranych magazynów ({WAREHOUSE_LABEL[warehouses].toLowerCase()}); sprzedaż liczymy ze wszystkich magazynów
          razem.
        </p>
        <p className="mt-1">
          „Nie sprzedaje się od pół roku” = od 6 miesięcy ani jednej sprzedaży tego towaru; ta kwota obejmuje też
          towar, który nie sprzedaje się ponad rok. „Najstarsza sztuka leży ponad 3 lata” = najstarsza dostawa tego
          towaru, która jeszcze jest w magazynie, przyszła ponad 3 lata temu; kwota obejmuje cały zapas takich
          towarów.
        </p>
        {report.value_unknown > 0 && (
          <p className="mt-1">
            {goods(report.value_unknown)} nie ma w kwotach — program magazynowy nie podaje ich ceny zakupu.
          </p>
        )}
      </footer>
    </>
  )
}

/** Kafelek w całości jest przyciskiem (fokus z klawiatury, Enter/Spacja) z widocznym „Pokaż listę ›”. */
function ClickTile({ className, onOpen, children }: { className: string; onOpen: () => void; children: ReactNode }) {
  return (
    <button
      type="button"
      onClick={onOpen}
      className={`board-block block w-full text-left transition-shadow hover:shadow-md ${FOCUS} ${className}`}
    >
      {children}
      <span className="mt-3 block">
        <ShowList />
      </span>
    </button>
  )
}

/** Wiersz „schodka”: poziom 1 i 2 wcięte pod wierszem, którego są częścią („w tym”). */
function StepRow({
  level,
  tone,
  label,
  items,
  value,
  share,
  onOpen,
}: {
  level: 0 | 1 | 2
  tone: Tone
  label: string
  items: number
  value: number
  share: string | null
  onOpen: () => void
}) {
  const t = TONE[tone]
  const indent = level === 0 ? '' : level === 1 ? 'ml-6 sm:ml-12' : 'ml-12 sm:ml-24'
  return (
    <li className={indent}>
      <button
        type="button"
        onClick={onOpen}
        className={`flex w-full flex-wrap items-center justify-between gap-x-6 gap-y-2 rounded-2xl border-2 px-5 py-4 text-left shadow-sm transition-shadow hover:shadow-md ${FOCUS} ${t.box}`}
      >
        <span className="block min-w-0">
          <span className="block text-xl font-medium text-slate-900">
            {level > 0 ? <span aria-hidden="true">↳ </span> : null}
            {label}
          </span>
          <span className="block text-lg text-slate-700">
            {goods(items)}
            {share ? ` · to ${share} wartości magazynu` : ''}
          </span>
        </span>
        <span className="block text-right">
          <span className={`block text-3xl font-semibold tabular-nums ${t.number}`}>{fmtBig(value)}</span>
          <ShowList />
        </span>
      </button>
    </li>
  )
}

function AgeTile({
  bucket: b,
  label,
  share,
  onOpen,
}: {
  bucket: InventoryBoardBucket | null
  label: string
  share: string | null
  onOpen: () => void
}) {
  const body = (
    <>
      <span className="block text-3xl font-semibold text-slate-800 tabular-nums">{b ? fmtBig(b.value) : '—'}</span>
      <span className="mt-2 block text-xl text-slate-900">{label}</span>
      <span className="mt-1 block text-lg text-slate-700">
        {b ? goods(b.items) : 'brak danych'}
        {b && share ? ` · to ${share} wartości magazynu` : ''}
      </span>
    </>
  )
  const box = 'rounded-2xl border border-slate-300 bg-white px-6 py-4 shadow-sm'
  if (!b) return <div className={`board-block ${box}`}>{body}</div>
  return (
    <ClickTile className={box} onOpen={onOpen}>
      {body}
    </ClickTile>
  )
}

/** „Pokaż listę ›” pod zdaniem z liczbą (sekcja dokumentów). */
function InlineShowList({ onClick }: { onClick: () => void }) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={`mt-1 rounded-lg px-1 py-1 text-left hover:bg-slate-50 print:hidden ${FOCUS}`}
    >
      <ShowList />
    </button>
  )
}

const PER_PAGE_OPTIONS = [10, 20, 50, 100] as const

type Loaded =
  | { kind: 'items'; res: InventoryBoardItemsResponse }
  | { kind: 'moves'; res: InventoryBoardMovesResponse }

function detailsPath(request: DetailsRequest, warehouses: Warehouses, perPage: number, page: number): string {
  const qs = new URLSearchParams()
  if (request.kind === 'items') {
    qs.set('bucket', request.bucket)
    if (request.group) qs.set('group', request.group)
  } else {
    qs.set('scope', request.scope)
    if (request.person) qs.set('operator', request.person.operator)
  }
  qs.set('warehouses', warehouses)
  qs.set('per_page', String(perPage))
  qs.set('page', String(page))
  return `/inventory/board/${request.kind}?${qs.toString()}`
}

/** Na górze okna dokumentów bez opisu — tekst uzgodniony z właścicielem, bez zmian. */
const UNEXPLAINED_NOTE =
  'Na tych dokumentach towar wydano z magazynu i zaraz przyjęto z powrotem w tej samej ilości, tym samym rozmiarze ' +
  'i kolorze, a w polu opisu nic nie wpisano. Po takim zapisie towar wygląda w systemie jak nowa dostawa. Lista ' +
  'pokazuje tylko to, co jest w dokumentach, i nie mówi, dlaczego tak zrobiono. Powód może wyjaśnić osoba, która ' +
  'wystawiła dokument.'

const TH = 'sticky top-0 z-10 border-b-2 border-slate-300 bg-slate-100 px-3 py-1.5 text-left font-semibold whitespace-nowrap text-slate-900 print:whitespace-normal'
const TD = 'px-3 py-1 align-top'
// Okno listy: mniejsze przyciski i odstępy niż na stronie raportu, żeby przy dużych literach mieściło się ok. 7 wierszy.
const MODAL_BUTTON = `rounded-lg border border-slate-300 bg-white px-4 py-1.5 text-lg font-medium text-slate-800 shadow-sm hover:bg-slate-50 disabled:opacity-50 ${FOCUS}`

/**
 * Okno z listą pod kwotą raportu: stronicowana tabela towarów albo dokumentów. Portal do <body>, bo przodek
 * strony (.app-main) w części szablonów ma container-type i wtedy „fixed” liczyłby się względem niego.
 * Nagłówek i stopka stałe, przewija się tylko tabela. Esc, klik w tło i „Zamknij” zamykają; fokus zostaje
 * w oknie i wraca na przycisk, który je otworzył. Spóźniona odpowiedź (szybkie klikanie) nie nadpisuje nowszej.
 */
function BoardDetailsModal({
  request,
  warehouses,
  stockValue,
  asOf,
  onClose,
}: {
  request: DetailsRequest
  warehouses: Warehouses
  stockValue: number
  asOf: string | null
  onClose: () => void
}) {
  const [perPage, setPerPage] = useState<number>(10)
  const [showNote, setShowNote] = useState(false)
  const [page, setPage] = useState(1)
  const [loaded, setLoaded] = useState<Loaded | null>(null)
  const [loading, setLoading] = useState(true)
  const [failed, setFailed] = useState(false)
  const seq = useRef(0)
  const panelRef = useRef<HTMLDivElement>(null)
  const scrollRef = useRef<HTMLDivElement>(null)
  const path = detailsPath(request, warehouses, perPage, page)
  const kind = request.kind

  const load = useCallback(async () => {
    const my = ++seq.current
    setLoading(true)
    setFailed(false)
    try {
      const next: Loaded =
        kind === 'items'
          ? { kind, res: await api<InventoryBoardItemsResponse>(path) }
          : { kind, res: await api<InventoryBoardMovesResponse>(path) }
      if (my === seq.current) setLoaded(next)
    } catch {
      if (my === seq.current) setFailed(true)
    } finally {
      if (my === seq.current) setLoading(false)
    }
  }, [kind, path])

  useEffect(() => {
    void load()
  }, [load])

  // Po zamknięciu okna odpowiedź w drodze niczego już nie ustawia.
  useEffect(
    () => () => {
      seq.current++
    },
    [],
  )

  // Nowa strona listy — tabela od góry.
  useEffect(() => {
    if (scrollRef.current) scrollRef.current.scrollTop = 0
  }, [loaded])

  // Blokada przewijania tła, fokus w oknie, po zamknięciu fokus wraca na przycisk, który je otworzył.
  useEffect(() => {
    const previous = document.activeElement instanceof HTMLElement ? document.activeElement : null
    const html = document.documentElement
    const body = document.body
    const htmlOverflow = html.style.overflow
    const bodyOverflow = body.style.overflow
    html.style.overflow = 'hidden'
    body.style.overflow = 'hidden'
    panelRef.current?.focus()
    return () => {
      html.style.overflow = htmlOverflow
      body.style.overflow = bodyOverflow
      body.classList.remove('board-print-modal')
      previous?.focus()
    }
  }, [])

  useEffect(() => {
    function onKey(e: KeyboardEvent) {
      if (e.key === 'Escape') {
        e.preventDefault()
        onClose()
      }
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [onClose])

  /** Tab i Shift+Tab krążą po elementach okna, nie uciekają na stronę pod spodem. */
  function trapFocus(e: ReactKeyboardEvent<HTMLDivElement>) {
    if (e.key !== 'Tab' || !panelRef.current) return
    const focusable = Array.from(
      panelRef.current.querySelectorAll<HTMLElement>('button:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])'),
    )
    if (focusable.length === 0) {
      e.preventDefault()
      return
    }
    const first = focusable[0]
    const last = focusable[focusable.length - 1]
    const active = document.activeElement
    if (e.shiftKey && (active === first || active === panelRef.current)) {
      e.preventDefault()
      last.focus()
    } else if (!e.shiftKey && active === last) {
      e.preventDefault()
      first.focus()
    }
  }

  function printList() {
    const body = document.body
    const done = () => {
      body.classList.remove('board-print-modal')
      window.removeEventListener('afterprint', done)
    }
    body.classList.add('board-print-modal')
    window.addEventListener('afterprint', done)
    window.print()
  }

  function choosePerPage(n: number) {
    setPerPage(n)
    setPage(1)
  }

  const meta = loaded?.res.meta ?? null
  const lastPage = Math.max(1, meta?.last_page ?? 1)
  const rowCount = loaded?.res.data.length ?? 0
  const firstNr = meta ? (meta.current_page - 1) * meta.per_page + 1 : 1
  const suffix = WAREHOUSE_SUFFIX[warehouses]
  const unexplained = request.kind === 'moves' && request.scope === 'unexplained'

  let title: string
  let summary: string | null = null
  if (request.kind === 'items') {
    title = `${request.label} ${suffix}`
    if (loaded?.kind === 'items') {
      const t = loaded.res.totals
      const share = request.bucket === 'stock' ? null : pct(t.value, stockValue)
      summary = `${goods(t.items)} · razem ${fmtBig(t.value)}${share ? ` · to ${share} wartości magazynu` : ''}`
    }
  } else if (request.person) {
    title = `Dokumenty wystawione przez: ${request.person.name} ${suffix}`
    const pairs = loaded?.kind === 'moves' ? loaded.res.totals.pairs : request.person.count
    const value = loaded?.kind === 'moves' ? loaded.res.totals.value : null
    summary =
      `W ostatnich 12 miesiącach: ${times(pairs)} bez opisu` +
      (value !== null ? `, razem ${fmtBig(value)}` : '') +
      ` (wszystkich takich wydań i przyjęć: ${groupInt(request.person.total)}).`
  } else {
    // Napis ze strony, nie `title` z API — serwer może już mieć dopisek magazynów i byłby podwójny.
    title = `${request.label} ${suffix}`
    if (loaded?.kind === 'moves') {
      summary = `${times(loaded.res.totals.pairs)} · razem ${fmtBig(loaded.res.totals.value)}`
    }
  }

  const modal = (
    <div
      className="board-modal fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-2"
      onClick={(e) => {
        if (e.target === e.currentTarget) onClose()
      }}
    >
      <div
        ref={panelRef}
        role="dialog"
        aria-modal="true"
        aria-labelledby="board-details-title"
        tabIndex={-1}
        onKeyDown={trapFocus}
        className="board-modal-panel flex max-h-full w-full max-w-7xl flex-col overflow-hidden rounded-2xl bg-white text-lg text-slate-900 shadow-lg outline-none"
      >
        <div className="border-b-2 border-slate-200 px-4 py-2.5">
          <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
            <div className="flex min-w-0 flex-1 flex-wrap items-baseline gap-x-4">
              <h2 id="board-details-title" className="text-xl font-semibold break-words text-slate-900">
                {title}
              </h2>
              {summary && <p className="text-lg text-slate-800">{summary}</p>}
              {request.kind === 'items' && <p className="text-base text-slate-700">Najpierw największe kwoty</p>}
            </div>
            <div className="board-modal-noprint flex shrink-0 flex-wrap gap-2">
              <button type="button" onClick={printList} disabled={!loaded || failed} className={MODAL_BUTTON}>
                Drukuj
              </button>
              <button type="button" onClick={onClose} className={MODAL_BUTTON}>
                Zamknij
              </button>
            </div>
          </div>
          {request.kind === 'moves' && (
            <p className="mt-1 text-base text-slate-800">
              <span className="mr-3 text-slate-700">Najpierw najnowsze.</span>
              {loaded?.kind === 'moves' && (
                <span className="mr-3 font-medium">
                  Tylko towar, który przed wydaniem leżał w magazynie co najmniej{' '}
                  {monthsLabel(loaded.res.min_lot_age_months)}.
                </span>
              )}
              {unexplained && (
                <button
                  type="button"
                  aria-expanded={showNote}
                  onClick={() => setShowNote((v) => !v)}
                  className={`board-modal-noprint rounded font-semibold text-blue-700 underline underline-offset-4 ${FOCUS}`}
                >
                  {showNote ? 'Ukryj objaśnienie' : 'Co pokazuje ta lista?'}
                </button>
              )}
            </p>
          )}
          {/* Długie objaśnienie zwinięte, żeby lista miała miejsce; na wydruku zawsze w całości. */}
          {unexplained && (
            <p className={`mt-1 text-base text-slate-800 ${showNote ? '' : 'hidden print:block'}`}>{UNEXPLAINED_NOTE}</p>
          )}
        </div>

        <div
          ref={scrollRef}
          className="board-modal-scroll min-h-0 flex-1 overflow-auto overscroll-contain"
          tabIndex={0}
          role="region"
          aria-label="Lista"
        >
          {failed ? (
            <div className="px-5 py-6">
              <p className="text-xl font-semibold text-red-800" role="alert">
                Nie udało się wczytać listy.
              </p>
              <button type="button" onClick={() => void load()} disabled={loading} className={`mt-4 ${BIG_BUTTON}`}>
                {loading ? 'Wczytywanie…' : 'Spróbuj ponownie'}
              </button>
            </div>
          ) : !loaded ? (
            <p className="px-5 py-6 text-2xl font-semibold text-slate-800" role="status">
              Wczytywanie listy…
            </p>
          ) : rowCount === 0 ? (
            <p className="px-5 py-6 text-xl text-slate-800">Brak pozycji.</p>
          ) : (
            <div className={loading ? 'opacity-60' : undefined}>
              {loaded.kind === 'items' ? (
                <ItemsTable rows={loaded.res.data} firstNr={firstNr} totalValue={loaded.res.totals.value} asOf={asOf} />
              ) : (
                <MovesTable rows={loaded.res.data} firstNr={firstNr} showWho={request.kind === 'moves' && !request.person} />
              )}
            </div>
          )}
        </div>

        {/* Liczba wierszy i strony na dole, w jednym pasku — góra okna zostaje niska, mieści się więcej wierszy. */}
        {!failed && meta && meta.total > PER_PAGE_OPTIONS[0] && (
          <div className="board-modal-noprint flex flex-wrap items-center justify-between gap-x-6 gap-y-2 border-t-2 border-slate-200 px-4 py-1.5">
            <div className="flex flex-wrap items-center gap-3">
              <span id="board-details-per-page" className="text-lg text-slate-800">
                Wierszy na stronie:
              </span>
              <div
                className="inline-flex overflow-hidden rounded-lg border-2 border-slate-300"
                role="group"
                aria-labelledby="board-details-per-page"
              >
                {PER_PAGE_OPTIONS.map((n, i) => {
                  const active = n === perPage
                  return (
                    <button
                      key={n}
                      type="button"
                      aria-pressed={active}
                      onClick={() => choosePerPage(n)}
                      className={`min-w-14 px-4 py-1 text-lg tabular-nums ${i > 0 ? 'border-l-2 border-slate-300' : ''} ${FOCUS} ${
                        active ? 'bg-blue-600 font-semibold text-white' : 'bg-white text-slate-800 hover:bg-slate-50'
                      }`}
                    >
                      {n}
                    </button>
                  )
                })}
              </div>
            </div>
            {loading && loaded && (
              <span className="text-lg font-semibold text-slate-800" role="status">
                Wczytywanie…
              </span>
            )}
            {lastPage > 1 && (
              <nav className="flex flex-wrap items-center gap-4" aria-label="Strony listy">
                <button type="button" onClick={() => setPage((p) => Math.max(1, p - 1))} disabled={page <= 1} className={MODAL_BUTTON}>
                  ‹ Poprzednie
                </button>
                <span className="text-lg text-slate-900 tabular-nums">
                  Strona {page} z {lastPage}
                </span>
                <button
                  type="button"
                  onClick={() => setPage((p) => Math.min(lastPage, p + 1))}
                  disabled={page >= lastPage}
                  className={MODAL_BUTTON}
                >
                  Następne ›
                </button>
              </nav>
            )}
          </div>
        )}
      </div>
    </div>
  )

  return createPortal(modal, document.body)
}

function ItemsTable({
  rows,
  firstNr,
  totalValue,
  asOf,
}: {
  rows: InventoryBoardItemRow[]
  firstNr: number
  totalValue: number
  asOf: string | null
}) {
  const pageSum = rows.reduce((sum, r) => sum + (r.value ?? 0), 0)
  const n = rows.length
  const share = pct(pageSum, totalValue)
  return (
    <table className="w-full border-collapse text-lg/snug">
      <thead>
        <tr>
          <th scope="col" className={`${TH} w-14`}>
            Nr
          </th>
          <th scope="col" className={`${TH} w-full`}>
            Towar
          </th>
          <th scope="col" className={`${TH} text-right`}>
            Ile leży
          </th>
          <th scope="col" className={`${TH} text-right`}>
            Wartość
          </th>
          <th scope="col" className={TH}>
            Ostatnia sprzedaż
          </th>
          <th scope="col" className={TH}>
            Najstarsza dostawa
          </th>
        </tr>
      </thead>
      <tbody className="bg-white">
        {rows.map((r, i) => {
          const since = r.last_sale_at ? monthsSince(r.last_sale_at, asOf) : null
          const second = [
            r.code,
            r.group_label ?? null,
            r.last_supplier ? `ostatnio kupiony od: ${r.last_supplier}` : null,
          ].filter((part): part is string => Boolean(part))
          return (
            <tr key={`${r.code}-${i}`} className="border-b border-slate-200 even:bg-slate-50">
              <td className={`${TD} text-slate-700 tabular-nums`}>{firstNr + i}</td>
              <td className={TD}>
                <span className="block font-semibold break-words text-slate-900">{r.card_name ?? r.name}</span>
                <span className="block w-0 min-w-full truncate text-base/snug text-slate-700 print:w-auto print:whitespace-normal" title={second.join(' · ')}>
                  {second.join(' · ')}
                </span>
              </td>
              <td className={`${TD} text-right whitespace-nowrap tabular-nums`}>{fmtQuantity(r.quantity, r.unit)}</td>
              <td className={`${TD} text-right whitespace-nowrap`}>
                <span className="block font-semibold tabular-nums">{r.value === null ? '—' : fmtZl(r.value)}</span>
                {r.value === null ? (
                  <span className="block text-base/snug text-slate-700">brak ceny zakupu</span>
                ) : r.unit_cost !== null ? (
                  <span className="block text-base/snug text-slate-700 tabular-nums">
                    {fmtUnitCost(r.unit_cost)}
                    {r.unit ? `/${r.unit}` : ' za jednostkę'}
                  </span>
                ) : null}
              </td>
              <td className={TD}>
                {r.last_sale_at ? (
                  <>
                    <span className="block whitespace-nowrap">{monthName(r.last_sale_at)}</span>
                    {since !== null && <span className="block text-base/snug text-slate-700">{agoText(since)}</span>}
                  </>
                ) : (
                  'ani razu'
                )}
              </td>
              <td className={`${TD} whitespace-nowrap`}>{r.oldest_lot_at ? monthName(r.oldest_lot_at) : '—'}</td>
            </tr>
          )
        })}
      </tbody>
      <tfoot>
        <tr className="border-t-2 border-slate-300">
          <td colSpan={6} className="px-3 py-2 text-lg font-semibold text-slate-900">
            Razem {n === 1 ? 'ten' : 'te'} {goods(n)}: {fmtZl(pageSum)}
            {share ? ` — to ${share} z ${fmtBig(totalValue)}` : ''}
          </td>
        </tr>
      </tfoot>
    </table>
  )
}

function MovesTable({ rows, firstNr, showWho }: { rows: InventoryBoardMoveRow[]; firstNr: number; showWho: boolean }) {
  return (
    <table className="w-full border-collapse text-lg/snug">
      <thead>
        <tr>
          <th scope="col" className={`${TH} w-14`}>
            Nr
          </th>
          <th scope="col" className={TH}>
            Data
          </th>
          <th scope="col" className={`${TH} w-full`}>
            Towar
          </th>
          {showWho && (
            <th scope="col" className={TH}>
              Kto wystawił
            </th>
          )}
          <th scope="col" className={`${TH} text-right`}>
            Ile i za ile
          </th>
          <th scope="col" className={TH}>
            Ile leżał
          </th>
          <th scope="col" className={`${TH} min-w-56 print:min-w-0`}>
            Opis
          </th>
        </tr>
      </thead>
      <tbody className="bg-white">
        {rows.map((r, i) => {
          const second = [`Wydanie ${r.rw_number} → Przyjęcie ${r.pw_number}`]
          if (r.approver_name) second.push(`zatwierdził(a): ${r.approver_name}`)
          const rwNote = r.rw_note?.trim() || null
          const pwNote = r.pw_note?.trim() || null
          return (
            <tr key={`${r.rw_number}-${r.pw_number}-${i}`} className="border-b border-slate-200 even:bg-slate-50">
              <td className={`${TD} text-slate-700 tabular-nums`}>{firstNr + i}</td>
              <td className={`${TD} whitespace-nowrap`}>{longDate(r.rw_date) ?? r.rw_date}</td>
              <td className={TD}>
                <span className="block font-semibold break-words text-slate-900">{r.card_name ?? r.item_name}</span>
                <span className="block w-0 min-w-full truncate text-base/snug text-slate-700 print:w-auto print:whitespace-normal" title={second.join(' · ')}>
                  {second.join(' · ')}
                </span>
                {!r.same_feature && (
                  <span className="block text-base/snug font-medium break-words text-slate-900">
                    Zmieniony rozmiar/kolor: {r.rw_features ?? '—'} → {r.pw_features ?? '—'}
                  </span>
                )}
              </td>
              {showWho && <td className={TD}>{r.operator_name ?? '—'}</td>}
              <td className={`${TD} text-right whitespace-nowrap tabular-nums`}>
                <span className="block">{fmtQuantity(r.quantity, r.unit)}</span>
                <span className="block font-semibold">{fmtZl(r.value)}</span>
              </td>
              <td className={`${TD} whitespace-nowrap`}>
                {r.lot_age_months === null ? '—' : lotAgeText(r.lot_age_months)}
              </td>
              <td className={`${TD} break-words`}>
                {rwNote || pwNote ? (
                  <>
                    {rwNote && <span className="block">{rwNote}</span>}
                    {pwNote && pwNote !== rwNote && (
                      <span className="block text-base/snug text-slate-700">przy przyjęciu: {pwNote}</span>
                    )}
                  </>
                ) : (
                  <span className="text-slate-600 italic">brak opisu</span>
                )}
              </td>
            </tr>
          )
        })}
      </tbody>
    </table>
  )
}
