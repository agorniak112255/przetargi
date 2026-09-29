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
  type InventoryBoardItemsSort,
  type InventoryBoardMoveRow,
  type InventoryBoardMovesResponse,
  type InventoryBoardMovesSort,
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
  { value: 'trade', label: 'Handlowe' },
  { value: 'service', label: 'Usługowe' },
  { value: 'all', label: 'Wszystkie' },
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

/** Podpis kafelka „cały towar” (magazyny widać w przełączniku obok tytułu, na wydruku pod tytułem). */
const WAREHOUSE_STOCK_LABEL: Record<Warehouses, string> = {
  trade: 'cały towar (handlowe)',
  service: 'cały towar (usługowe)',
  all: 'cały towar (wszystkie)',
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
  .board-report { zoom: 0.62; max-width: none !important; margin: 0 !important; }
  .board-report, .board-report * {
    color: #000 !important;
    background: #fff !important;
    box-shadow: none !important;
    border-color: #666 !important;
    print-color-adjust: exact;
    -webkit-print-color-adjust: exact;
  }
  .board-block { break-inside: avoid; page-break-inside: avoid; }
  /* Pasek na pulpicie: wypełnienie szare (ogólna reguła wyżej robi każde tło białe). */
  .board-report .board-bar-fill { background: #999 !important; }
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
  /* Lista w skali: tabela ma układ ekranowy, nazwy mieszczą się w linii — towary ok. 15–20 pozycji na A4;
     dokumenty mają więcej tekstu w wierszu (numery, osoby, opisy), więc drukują się mniejsze. */
  body.board-print-modal .board-modal-panel { zoom: 0.8; }
  body.board-print-modal .board-modal-panel[data-kind="moves"] { zoom: 0.68; }
  body.board-print-modal .board-modal thead th { position: static !important; }
  /* „Razem …” raz, na końcu listy — nie na każdej stronie. */
  body.board-print-modal .board-modal tfoot { display: table-row-group; }
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
    <span className="text-lg font-semibold text-blue-700 underline underline-offset-4 print:hidden">
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
    <div className="board-report mx-auto max-w-7xl pb-10 text-lg text-slate-900">
      <style>{PRINT_CSS}</style>

      <header className="board-block mb-3 flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="text-3xl font-semibold text-slate-900">Zapasy — raport dla zarządu</h1>
          <p className="mt-1 text-xl text-slate-800">
            {report ? (asOf ? `Stan na ${asOf}` : 'Brak daty odczytu danych') : ''}
            <span className="text-lg text-slate-700">{report ? ' · ' : ''}dane z programu magazynowego z nocy</span>
          </p>
          <p className="mt-1 hidden text-2xl font-semibold text-slate-900 print:block">{WAREHOUSE_LABEL[shown]}</p>
        </div>
        <div className="flex flex-wrap items-center gap-3 print:hidden">
          <span className="text-lg text-slate-700">Magazyny:</span>
          <WarehouseSwitch value={warehouses} onChange={chooseWarehouses} />
          <button
            type="button"
            onClick={() => window.print()}
            className={`rounded-lg border border-slate-300 bg-white px-4 py-2 text-xl font-medium text-slate-800 shadow-sm hover:bg-slate-50 ${FOCUS}`}
          >
            Drukuj / PDF
          </button>
        </div>
      </header>

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
              className={`px-5 py-2 text-xl ${i > 0 ? 'border-l-2 border-slate-300' : ''} ${FOCUS} ${
                active ? 'bg-blue-600 font-semibold text-white' : 'bg-white text-slate-800 hover:bg-slate-50'
              }`}
            >
              {active ? '✓ ' : ''}
              {o.label}
            </button>
          )
        })}
    </div>
  )
}

/**
 * Pulpit (wybór właściciela 29.09.2026, wariant B): na górze 4 główne kwoty, pod nimi paski „jak długo bez sprzedaży”
 * i dokumenty RW/PW z osobami, na dole najdroższe towary i rodzaje. Każda kwota to kafelek-przycisk otwierający listę.
 */
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
  const empty = report.stock.items === 0
  const share = (value: number) => pct(value, stockValue)
  const openItems = (b: InventoryBoardItemsBucket, label: string, group: InventoryBoardGroupKey | null = null) =>
    onOpen({ kind: 'items', bucket: b, group, label })
  const openMoves = (scope: 'all' | 'unexplained') =>
    onOpen({
      kind: 'moves',
      scope,
      person: null,
      label:
        scope === 'all'
          ? 'Towar wydany i od razu przyjęty z powrotem'
          : 'Towar wydany i od razu przyjęty z powrotem bez żadnego opisu',
    })

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
  // Paski czasu bez sprzedaży w skali największego (pół roku) — dłuższy okres jest jego częścią.
  const scale = noSale6?.value ?? 0
  const ageScale = noSale12?.value ?? 0

  return (
    <>
      <p className="mb-4 text-lg text-slate-700 print:hidden">
        Kafelek z napisem „Pokaż listę ›” można kliknąć — otworzy się lista towarów albo dokumentów.
      </p>

      <div className="mb-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4 print:grid-cols-4">
        <KpiTile
          tone="neutral"
          value={fmtBig(stockValue)}
          label={WAREHOUSE_STOCK_LABEL[warehouses]}
          detail={
            report.split && warehouses === 'all'
              ? `${goods(report.stock.items)} · handlowe ${fmtBig(report.split.trade.value)}, usługowe ${fmtBig(report.split.service.value)}`
              : goods(report.stock.items)
          }
          onOpen={() => openItems('stock', 'Cały towar')}
        />
        {noSale6 && (
          <KpiTile
            tone="amber"
            value={fmtBig(noSale6.value)}
            label="ponad pół roku bez sprzedaży"
            detail={`${goods(noSale6.items)}${share(noSale6.value) ? ` · ${share(noSale6.value)} magazynu` : ''}`}
            onOpen={() => openItems('no_sale_6', 'Nie sprzedaje się od pół roku')}
          />
        )}
        {noSale12 && (
          <KpiTile
            tone="red"
            value={fmtBig(noSale12.value)}
            label="ponad rok bez sprzedaży"
            detail={`${goods(noSale12.items)}${share(noSale12.value) ? ` · ${share(noSale12.value)} magazynu` : ''}`}
            onOpen={() => openItems('no_sale_12', 'Nie sprzedaje się ponad rok')}
          />
        )}
        {moves.unexplained > 0 ? (
          <KpiTile
            tone="red"
            value={fmtBig(moves.unexplained_value)}
            label="wydane i przyjęte bez opisu"
            detail={`${times(moves.unexplained)} w ostatnim roku`}
            onOpen={() => openMoves('unexplained')}
          />
        ) : (
          <div className="board-block rounded-2xl border-2 border-slate-300 bg-white px-5 py-4 shadow-sm">
            <span className="block text-4xl font-semibold text-slate-900">0</span>
            <span className="mt-1 block text-xl font-medium text-slate-900">wydane i przyjęte bez opisu</span>
            <span className="mt-1 block text-lg text-slate-700">ani razu w ostatnim roku</span>
          </div>
        )}
      </div>

      <div className="mb-4 grid gap-4 lg:grid-cols-5 print:grid-cols-5">
        <section className={`${PANEL} lg:col-span-3 print:col-span-3`}>
          <h2 className="text-2xl font-semibold text-slate-900">Jak długo towar nie sprzedaje się</h2>
          <p className="mb-2 text-base text-slate-700">
            Niższe paski są częścią pierwszego („w tym”), więc kwot się nie dodaje. % = część wartości magazynu.
            <span className="print:hidden"> Kliknij pasek, aby zobaczyć listę.</span>
          </p>
          <div className="space-y-1">
            {noSale6 && (
              <BarRow
                tone="amber"
                label="ponad pół roku"
                bucket={noSale6}
                scale={scale}
                share={share(noSale6.value)}
                onOpen={() => openItems('no_sale_6', 'Nie sprzedaje się od pół roku')}
              />
            )}
            {noSale12 && (
              <BarRow
                tone="red"
                label="w tym ponad rok"
                bucket={noSale12}
                scale={scale}
                share={share(noSale12.value)}
                onOpen={() => openItems('no_sale_12', 'Nie sprzedaje się ponad rok')}
              />
            )}
            {noSale24 && (
              <BarRow
                tone="red"
                label="w tym ponad 2 lata"
                bucket={noSale24}
                scale={scale}
                share={share(noSale24.value)}
                onOpen={() => openItems('no_sale_24', 'Nie sprzedaje się ponad 2 lata')}
              />
            )}
            <BarRow
              tone="red"
              label="w tym ani razu nie sprzedany"
              bucket={report.never_sold}
              scale={scale}
              share={share(report.never_sold.value)}
              onOpen={() => openItems('never_sold', 'Ani razu nie sprzedany')}
            />
          </div>
          <h3 className="mt-3 text-xl font-semibold text-slate-900">
            Bez sprzedaży ponad rok, a najstarsza sztuka leży:
          </h3>
          <div className="mt-1 space-y-1">
            {stale36 && (
              <BarRow
                tone="slate"
                label="ponad 3 lata"
                bucket={stale36}
                scale={ageScale}
                share={share(stale36.value)}
                onOpen={() => openItems('stale_36', 'Bez sprzedaży ponad rok, leży ponad 3 lata')}
              />
            )}
            {stale60 && (
              <BarRow
                tone="slate"
                label="ponad 5 lat"
                bucket={stale60}
                scale={ageScale}
                share={share(stale60.value)}
                onOpen={() => openItems('stale_60', 'Bez sprzedaży ponad rok, leży ponad 5 lat')}
              />
            )}
          </div>
        </section>

        <section className={`${PANEL} lg:col-span-2 print:col-span-2`}>
          <h2 className="text-2xl font-semibold text-slate-900">Wydane i od razu przyjęte z powrotem</h2>
          <p className="mb-1 text-base text-slate-700">
            Ostatnie 12 miesięcy; towar, który leżał co najmniej {monthsLabel(moves.min_lot_age_months)}. Po takim
            zapisie wygląda jak nowa dostawa.
          </p>
          {moves.total === 0 ? (
            <p className="text-xl text-slate-900">Ani razu.</p>
          ) : (
            <div className="space-y-2">
              <ListButton onClick={() => openMoves('all')}>
                <span className="flex-1 text-lg text-slate-900">wszystkie</span>
                <span className="text-2xl font-semibold text-slate-900 tabular-nums">{times(moves.total)}</span>
              </ListButton>
              {moves.unexplained > 0 ? (
                <ListButton onClick={() => openMoves('unexplained')}>
                  <span className="flex-1 text-lg text-slate-900">bez żadnego opisu</span>
                  <span className="text-2xl font-semibold text-red-800 tabular-nums">
                    {times(moves.unexplained)} · {fmtBig(moves.unexplained_value)}
                  </span>
                </ListButton>
              ) : (
                <p className="flex flex-wrap items-baseline justify-between gap-x-4 px-3 py-2">
                  <span className="text-lg text-slate-900">bez żadnego opisu</span>
                  <span className="text-2xl font-semibold text-slate-900">ani razu</span>
                </p>
              )}
            </div>
          )}
          {moves.unexplained > 0 && moves.people.length > 0 && (
            <>
              <h3 className="mt-2 text-xl font-semibold text-slate-900">Kto wystawił bez opisu</h3>
              <p className="px-3 text-base text-slate-700">Przy osobie: ile bez opisu z wszystkich jej takich dokumentów.</p>
              <ul className="mt-1 divide-y divide-slate-200">
                {moves.people.map((p, i) => (
                  <li key={`${p.operator}-${i}`}>
                    {p.operator ? (
                      <ListButton
                        onClick={() =>
                          onOpen({
                            kind: 'moves',
                            scope: 'unexplained',
                            person: { operator: p.operator, name: p.name, count: p.count, total: p.total },
                            label: `Dokumenty wystawione przez: ${p.name}`,
                          })
                        }
                      >
                        <PersonLine name={p.name} count={p.count} total={p.total} />
                      </ListButton>
                    ) : (
                      <div className="flex items-baseline justify-between gap-3 px-3 py-2">
                        <PersonLine name={p.name} count={p.count} total={p.total} />
                      </div>
                    )}
                  </li>
                ))}
              </ul>
            </>
          )}
        </section>
      </div>

      <div className="mb-4 grid gap-4 lg:grid-cols-5 print:grid-cols-5">
        <section className={`${PANEL} lg:col-span-3 print:col-span-3`}>
          <h2 className="text-2xl font-semibold text-slate-900">Najdroższe towary bez sprzedaży ponad rok</h2>
          {topCount === 0 ? (
            <p className="mt-2 text-xl text-slate-800">Nie ma takiego towaru.</p>
          ) : (
            <>
              <ol className="mt-2 divide-y divide-slate-200">
                {report.top_unsold.map((item, i) => (
                  <li key={`${item.code}-${i}`} className="flex items-start gap-3 py-2">
                    <span className="w-7 shrink-0 text-xl font-semibold text-slate-700 tabular-nums">{i + 1}.</span>
                    <div className="min-w-0 flex-1">
                      <div className="text-lg font-medium break-words text-slate-900">{item.card_name ?? item.name}</div>
                      <div className="text-base text-slate-700">
                        {fmtQuantity(item.quantity, item.unit)} ·{' '}
                        {item.last_sale_at ? `ostatnia sprzedaż ${monthYear(item.last_sale_at)}` : 'nigdy nie sprzedany'}
                      </div>
                    </div>
                    <span className="shrink-0 text-xl font-semibold text-slate-900 tabular-nums">{fmtBig(item.value)}</span>
                  </li>
                ))}
              </ol>
              <div className="mt-2 flex flex-wrap items-center justify-between gap-3">
                {noSale12 && (
                  <p className="text-base text-slate-800">
                    Te {groupInt(topCount)} to <strong className="tabular-nums">{fmtBig(topSum)}</strong> z{' '}
                    <strong className="tabular-nums">{fmtBig(noSale12.value)}</strong>
                    {restCount > 0 ? `; reszta to ${goods(restCount)}.` : '.'}
                  </p>
                )}
                <button
                  type="button"
                  onClick={() => openItems('no_sale_12', 'Nie sprzedaje się ponad rok')}
                  className={`rounded-lg border border-slate-300 bg-white px-4 py-2 text-lg font-semibold text-blue-700 shadow-sm hover:bg-slate-50 print:hidden ${FOCUS}`}
                >
                  Więcej — pokaż listę ›
                </button>
              </div>
            </>
          )}
        </section>

        {groups.length > 0 && (
          <section className={`${PANEL} lg:col-span-2 print:col-span-2`}>
            <h2 className="text-2xl font-semibold text-slate-900">Bez sprzedaży ponad rok — według rodzaju</h2>
            <div className="mt-2 grid grid-cols-2 gap-2">
              {groups.map((g) => {
                const body = (
                  <>
                    <span className="flex flex-wrap items-baseline justify-between gap-x-2">
                      <span className="text-lg font-semibold text-slate-900">{g.label}</span>
                      <span className="text-xl font-semibold text-slate-900 tabular-nums">{fmtBig(g.unsold_value)}</span>
                    </span>
                    <span className="block text-base text-slate-700">
                      {goods(g.unsold_items)}
                      {pct(g.unsold_value, g.stock_value) ? ` · ${pct(g.unsold_value, g.stock_value)} rodzaju` : ''}
                    </span>
                  </>
                )
                const box = 'flex flex-col rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-left'
                return g.unsold_items > 0 ? (
                  <button
                    key={g.group}
                    type="button"
                    onClick={() => openItems('no_sale_12', `Bez sprzedaży ponad rok — ${g.label}`, g.group)}
                    className={`${box} transition-shadow hover:shadow-md ${FOCUS}`}
                  >
                    {body}
                  </button>
                ) : (
                  <div key={g.group} className={box}>
                    {body}
                  </div>
                )
              })}
            </div>
            <p className="mt-2 text-base text-slate-700">„% rodzaju” = jaka część całego towaru tego rodzaju.</p>
          </section>
        )}
      </div>

      <footer className="board-block border-t border-slate-300 pt-3 text-base text-slate-700">
        <p>
          Wartość = cena zakupu towaru, który leży w magazynie, według programu magazynowego. Ilości i kwoty dotyczą
          wybranych magazynów ({WAREHOUSE_LABEL[warehouses].toLowerCase()}); sprzedaż liczymy ze wszystkich magazynów
          razem. Handlowe — towar na sprzedaż, usługowe — towar trzymany dla klientów. „Bez opisu” = ten sam rozmiar i
          kolor, a na dokumencie wydania nie ma opisu, dlaczego towar wydano i przyjęto z powrotem; liczba „z …” przy
          osobie to wszystkie jej takie dokumenty.
        </p>
        <p className="mt-1">
          „Ani razu nie sprzedany” = leży w magazynie ponad pół roku i nie sprzedał się ani razu. „Najstarsza sztuka
          leży ponad 3 lata” = najstarsza dostawa tego towaru, która jeszcze jest w magazynie, przyszła ponad 3 lata temu.
          {report.lot_12_total && (
            <>
              {' '}
              Dla porównania: {goods(report.lot_12_total.items)} ma część sztuk przyjętych ponad rok temu (zapas za{' '}
              {fmtBig(report.lot_12_total.value)}), ale większość z nich normalnie się sprzedaje — tego nie liczymy w
              kwotach powyżej.
            </>
          )}
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

/** Biały panel z grupą kafelków/pasków na pulpicie. */
const PANEL = 'board-block rounded-2xl border-2 border-slate-300 bg-white px-5 py-4 shadow-sm'

/** Górny rząd pulpitu: duża kwota z „Pokaż listę ›”, podpis, szczegół; cały kafelek jest przyciskiem. */
function KpiTile({
  tone,
  value,
  label,
  detail,
  onOpen,
}: {
  tone: Tone
  value: string
  label: string
  detail: string
  onOpen: () => void
}) {
  const t = TONE[tone]
  return (
    <button
      type="button"
      onClick={onOpen}
      className={`board-block flex w-full flex-col rounded-2xl border-2 px-5 py-3 text-left shadow-sm transition-shadow hover:shadow-md ${FOCUS} ${t.box}`}
    >
      <span className={`block text-4xl font-semibold tabular-nums ${t.number}`}>{value}</span>
      <span className="mt-1 block text-xl leading-snug font-medium text-slate-900">{label}</span>
      <span className="mt-auto flex flex-wrap items-baseline justify-between gap-x-3 pt-1">
        <span className="text-lg text-slate-700">{detail}</span>
        <ShowList />
      </span>
    </button>
  )
}

const BAR_FILL: Record<'amber' | 'red' | 'slate', { fill: string; number: string }> = {
  amber: { fill: 'bg-amber-400', number: 'text-amber-900' },
  red: { fill: 'bg-red-400', number: 'text-red-800' },
  slate: { fill: 'bg-slate-400', number: 'text-slate-900' },
}

/** Pasek „jak długo”: podpis, kwota, pasek w skali pierwszego i „Pokaż listę ›”; cały jest przyciskiem. */
function BarRow({
  tone,
  label,
  bucket: b,
  scale,
  share,
  onOpen,
}: {
  tone: 'amber' | 'red' | 'slate'
  label: string
  bucket: { items: number; value: number }
  scale: number
  share: string | null
  onOpen: () => void
}) {
  const t = BAR_FILL[tone]
  const width = scale > 0 && b.value > 0 ? Math.max(2, Math.min(100, (b.value / scale) * 100)) : 0
  return (
    <button
      type="button"
      onClick={onOpen}
      title="Pokaż listę"
      className={`grid w-full grid-cols-[minmax(0,15rem)_minmax(4rem,1fr)_auto] items-center gap-x-4 rounded-xl px-3 py-1.5 text-left hover:bg-slate-50 ${FOCUS}`}
    >
      <span className="min-w-0">
        <span className="block text-lg leading-snug font-medium text-slate-900">{label}</span>
        <span className="block text-base leading-snug text-slate-700">
          {goods(b.items)}
          {share ? ` · ${share}` : ''}
        </span>
      </span>
      <span className="block h-5 overflow-hidden rounded-full bg-slate-100 print:border">
        <span className={`board-bar-fill block h-full rounded-full ${t.fill}`} style={{ width: `${width}%` }} />
      </span>
      <span className="text-right whitespace-nowrap">
        <span className={`text-2xl font-semibold tabular-nums ${t.number}`}>{fmtBig(b.value)}</span>
        <span className="ml-2 text-xl font-semibold text-blue-700 print:hidden" aria-hidden="true">
          ›
        </span>
      </span>
    </button>
  )
}

/** Wiersz z liczbą w panelu dokumentów — cały jest przyciskiem otwierającym listę. */
function ListButton({ onClick, children }: { onClick: () => void; children: ReactNode }) {
  return (
    <button
      type="button"
      onClick={onClick}
      title="Pokaż dokumenty"
      className={`flex w-full items-baseline justify-between gap-x-4 rounded-xl px-3 py-1.5 text-left hover:bg-slate-50 ${FOCUS}`}
    >
      {children}
      <span className="shrink-0 text-xl font-semibold text-blue-700 print:hidden" aria-hidden="true">
        ›
      </span>
    </button>
  )
}

function PersonLine({ name, count, total }: { name: string; count: number; total: number }) {
  return (
    <>
      <span className="min-w-0 flex-1 truncate text-lg text-slate-900" title={name}>
        {name}
      </span>
      <span className="shrink-0 text-lg whitespace-nowrap text-slate-800">
        <strong className="text-xl tabular-nums">{groupInt(count)}</strong>{' '}
        <span className="text-slate-700">z {groupInt(total)}</span>
      </span>
    </>
  )
}

const PER_PAGE_OPTIONS = [10, 20, 50, 100] as const

/** Odpowiedź z wyszukiwaniem, dla którego przyszła — napisy „Znaleziono…” nie mieszają starej listy z nowym słowem. */
type Loaded =
  | { kind: 'items'; res: InventoryBoardItemsResponse; query: string }
  | { kind: 'moves'; res: InventoryBoardMovesResponse; query: string }

type SortKey = InventoryBoardItemsSort | InventoryBoardMovesSort
type SortDir = 'asc' | 'desc'
type Sort = { key: SortKey; dir: SortDir }
/** Kolumna do sortowania: napis nagłówka, kierunek po pierwszym kliknięciu i słowa do napisu „Kolejność: …”. */
type SortInfo = { label: string; first: SortDir; asc: string; desc: string }

const ITEM_SORTS: Record<InventoryBoardItemsSort, SortInfo> = {
  name: { label: 'Towar', first: 'asc', asc: 'od A do Z', desc: 'od Z do A' },
  quantity: { label: 'Ile leży', first: 'desc', asc: 'od najmniejszej ilości', desc: 'od największej ilości' },
  value: { label: 'Wartość', first: 'desc', asc: 'od najmniejszej kwoty', desc: 'od największej kwoty' },
  last_sale: { label: 'Ostatnia sprzedaż', first: 'asc', asc: 'najdawniej sprzedane najpierw', desc: 'ostatnio sprzedane najpierw' },
  oldest_lot: { label: 'Najstarsza dostawa', first: 'asc', asc: 'najstarsze dostawy najpierw', desc: 'najnowsze dostawy najpierw' },
}

const MOVE_SORTS: Record<InventoryBoardMovesSort, SortInfo> = {
  date: { label: 'Data', first: 'desc', asc: 'najstarsze najpierw', desc: 'najnowsze najpierw' },
  name: { label: 'Towar', first: 'asc', asc: 'od A do Z', desc: 'od Z do A' },
  operator: { label: 'Kto wystawił', first: 'asc', asc: 'od A do Z', desc: 'od Z do A' },
  value: { label: 'Ile i za ile', first: 'desc', asc: 'od najmniejszej kwoty', desc: 'od największej kwoty' },
  lot_age: { label: 'Ile leżał', first: 'desc', asc: 'najkrócej leżące najpierw', desc: 'najdłużej leżące najpierw' },
  note: { label: 'Opis', first: 'asc', asc: 'od A do Z, bez opisu na końcu', desc: 'od Z do A, bez opisu na końcu' },
}

/** Kolejność bez klikania w nagłówek — taka sama jak na serwerze bez parametru `sort`. */
const DEFAULT_SORT: Record<DetailsRequest['kind'], Sort> = { items: { key: 'value', dir: 'desc' }, moves: { key: 'date', dir: 'desc' } }

function sortInfo(kind: DetailsRequest['kind'], key: SortKey): SortInfo {
  return kind === 'items' ? ITEM_SORTS[key as InventoryBoardItemsSort] : MOVE_SORTS[key as InventoryBoardMovesSort]
}

/** „z 1 towaru”, „z 113 towarów” / „z 1 wiersza”, „z 21 wierszy” — po „z” zawsze dopełniacz. */
function outOf(kind: DetailsRequest['kind'], n: number): string {
  if (kind === 'items') return n === 1 ? '1 towaru' : `${groupInt(n)} towarów`
  return n === 1 ? '1 wiersza' : `${groupInt(n)} wierszy`
}

function detailsPath(
  request: DetailsRequest,
  warehouses: Warehouses,
  perPage: number,
  page: number,
  query: string,
  sort: Sort | null,
): string {
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
  if (query !== '') qs.set('search', query)
  if (sort) {
    qs.set('sort', sort.key)
    qs.set('dir', sort.dir)
  }
  return `/inventory/board/${request.kind}?${qs.toString()}`
}

/** Nagłówek kolumny jako przycisk: klik sortuje całą listę, drugi klik odwraca kolejność. */
function SortTh({
  column,
  info,
  sort,
  onSort,
  className = '',
}: {
  column: SortKey
  info: SortInfo
  sort: Sort
  onSort: (key: SortKey) => void
  className?: string
}) {
  const active = sort.key === column
  return (
    <th
      scope="col"
      className={`${TH} ${className}`}
      aria-sort={active ? (sort.dir === 'asc' ? 'ascending' : 'descending') : undefined}
    >
      <button
        type="button"
        onClick={() => onSort(column)}
        title={`Sortuj: ${info.label}`}
        className={`inline-flex items-center gap-1.5 rounded font-semibold underline-offset-4 hover:underline print:hidden ${FOCUS} ${
          active ? 'text-blue-800' : 'text-slate-900'
        }`}
      >
        {info.label}
        <span aria-hidden="true" className={active ? '' : 'text-slate-400'}>
          {active ? (sort.dir === 'asc' ? '▲' : '▼') : '↕'}
        </span>
      </button>
      <span className="hidden print:inline">
        {info.label}
        {active ? (sort.dir === 'asc' ? ' ▲' : ' ▼') : ''}
      </span>
    </th>
  )
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
const MODAL_BUTTON = `rounded-lg border border-slate-300 bg-white px-4 py-1 text-lg font-medium text-slate-800 shadow-sm hover:bg-slate-50 disabled:opacity-50 ${FOCUS}`

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
  // Pole wyszukiwania: to, co wpisano, i to, co poszło do serwera (po krótkiej przerwie w pisaniu).
  const [searchText, setSearchText] = useState('')
  const [query, setQuery] = useState('')
  // null = kolejność domyślna (bez parametru `sort`).
  const [sort, setSort] = useState<Sort | null>(null)
  const [loaded, setLoaded] = useState<Loaded | null>(null)
  const [loading, setLoading] = useState(true)
  const [failed, setFailed] = useState(false)
  const seq = useRef(0)
  const panelRef = useRef<HTMLDivElement>(null)
  const scrollRef = useRef<HTMLDivElement>(null)
  const path = detailsPath(request, warehouses, perPage, page, query, sort)
  const kind = request.kind
  const activeSort = sort ?? DEFAULT_SORT[kind]

  // Lista zawęża się w trakcie pisania — zapytanie po 300 ms bez nowego znaku, od pierwszej strony.
  useEffect(() => {
    const next = searchText.trim().replace(/\s+/g, ' ')
    if (next === query) return
    const timer = window.setTimeout(() => {
      setQuery(next)
      setPage(1)
    }, 300)
    return () => window.clearTimeout(timer)
  }, [searchText, query])

  function chooseSort(key: SortKey) {
    const current = sort ?? DEFAULT_SORT[kind]
    setSort(
      current.key === key
        ? { key, dir: current.dir === 'asc' ? 'desc' : 'asc' }
        : { key, dir: sortInfo(kind, key).first },
    )
    setPage(1)
  }

  /** Esc w wypełnionym polu czyści wyszukiwanie zamiast zamykać okno. */
  function onSearchKey(e: ReactKeyboardEvent<HTMLInputElement>) {
    if (e.key === 'Escape' && searchText !== '') {
      e.preventDefault()
      e.nativeEvent.stopPropagation()
      setSearchText('')
    }
  }

  const load = useCallback(async () => {
    const my = ++seq.current
    setLoading(true)
    setFailed(false)
    try {
      const next: Loaded =
        kind === 'items'
          ? { kind, res: await api<InventoryBoardItemsResponse>(path), query }
          : { kind, res: await api<InventoryBoardMovesResponse>(path), query }
      if (my === seq.current) setLoaded(next)
    } catch {
      if (my === seq.current) setFailed(true)
    } finally {
      if (my === seq.current) setLoading(false)
    }
  }, [kind, path, query])

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
  // Po wpisaniu w pole wyszukiwania: ile pasuje z całej listy.
  if (loaded && loaded.query !== '') {
    const found = loaded.kind === 'items' ? loaded.res.found.items : loaded.res.found.pairs
    const all = loaded.kind === 'items' ? loaded.res.totals.items : loaded.res.totals.pairs
    summary = `Znaleziono ${groupInt(found)} z ${outOf(kind, all)} dla „${loaded.query}” · razem ${fmtBig(loaded.res.found.value)}`
  }
  const sortLabel = sortInfo(kind, activeSort.key)
  const orderText = `Kolejność: „${sortLabel.label}” — ${sortLabel[activeSort.dir]}.`

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
        data-kind={kind}
        className="board-modal-panel flex max-h-full w-full max-w-7xl flex-col overflow-hidden rounded-2xl bg-white text-lg text-slate-900 shadow-lg outline-none"
      >
        <div className="border-b-2 border-slate-200 px-4 py-2.5">
          <div className="flex flex-wrap items-baseline gap-x-4">
            <h2 id="board-details-title" className="text-xl font-semibold break-words text-slate-900">
              {title}
            </h2>
            {summary && <p className="text-lg text-slate-800">{summary}</p>}
          </div>
          {/* Druga linia: wyszukiwanie po wszystkich kolumnach, kolejność i przyciski okna. */}
          <div className="mt-1.5 flex flex-wrap items-center gap-x-4 gap-y-1.5">
            <div className="board-modal-noprint relative">
              <input
                type="text"
                value={searchText}
                onChange={(e) => setSearchText(e.target.value)}
                onKeyDown={onSearchKey}
                enterKeyHint="search"
                maxLength={150}
                placeholder="Szukaj w liście…"
                aria-label="Szukaj w tej liście — po każdej kolumnie"
                title="Wpisz nazwę, kod, dostawcę, osobę, numer dokumentu, rok albo miesiąc — lista zawęża się od razu"
                className={`w-60 rounded-lg border-2 border-slate-300 bg-white py-1 pr-9 pl-3 text-lg text-slate-900 placeholder:text-slate-500 ${FOCUS}`}
              />
              {searchText !== '' && (
                <button
                  type="button"
                  onClick={() => setSearchText('')}
                  aria-label="Wyczyść wyszukiwanie"
                  title="Wyczyść"
                  className={`absolute inset-y-1 right-1 rounded px-2 text-xl leading-none text-slate-600 hover:text-slate-900 ${FOCUS}`}
                >
                  ×
                </button>
              )}
            </div>
            {/* Na ekranie kolejność pokazuje niebieski nagłówek ze strzałką; na wydruku — zdanie. */}
            <span className="hidden text-base text-slate-700 print:inline">{orderText}</span>
            {loaded?.kind === 'moves' && (
              <span className="text-base font-medium text-slate-800">
                Tylko towar, który przed wydaniem leżał {monthsLabel(loaded.res.min_lot_age_months)} lub dłużej.
              </span>
            )}
            {unexplained && (
              <button
                type="button"
                aria-expanded={showNote}
                onClick={() => setShowNote((v) => !v)}
                className={`board-modal-noprint rounded text-base font-semibold text-blue-700 underline underline-offset-4 ${FOCUS}`}
              >
                {showNote ? 'Ukryj objaśnienie' : 'Co pokazuje ta lista?'}
              </button>
            )}
            <div className="board-modal-noprint ml-auto flex shrink-0 flex-wrap gap-2">
              <button type="button" onClick={printList} disabled={!loaded || failed} className={MODAL_BUTTON}>
                Drukuj
              </button>
              <button type="button" onClick={onClose} className={MODAL_BUTTON}>
                Zamknij
              </button>
            </div>
          </div>
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
            <p className={`px-5 py-6 text-xl text-slate-800 ${loading ? 'opacity-60' : ''}`}>
              {loaded.query !== '' ? `Nic nie znaleziono dla „${loaded.query}”.` : 'Brak pozycji.'}
            </p>
          ) : (
            <div className={loading ? 'opacity-60' : undefined}>
              {loaded.kind === 'items' ? (
                <ItemsTable
                  rows={loaded.res.data}
                  firstNr={firstNr}
                  totalValue={loaded.res.totals.value}
                  asOf={asOf}
                  sort={activeSort}
                  onSort={chooseSort}
                />
              ) : (
                <MovesTable
                  rows={loaded.res.data}
                  firstNr={firstNr}
                  showWho={request.kind === 'moves' && !request.person}
                  sort={activeSort}
                  onSort={chooseSort}
                />
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
  sort,
  onSort,
}: {
  rows: InventoryBoardItemRow[]
  firstNr: number
  totalValue: number
  asOf: string | null
  sort: Sort
  onSort: (key: SortKey) => void
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
          <SortTh column="name" info={ITEM_SORTS.name} sort={sort} onSort={onSort} className="w-full" />
          <SortTh column="quantity" info={ITEM_SORTS.quantity} sort={sort} onSort={onSort} className="text-right" />
          <SortTh column="value" info={ITEM_SORTS.value} sort={sort} onSort={onSort} className="text-right" />
          <SortTh column="last_sale" info={ITEM_SORTS.last_sale} sort={sort} onSort={onSort} />
          <SortTh column="oldest_lot" info={ITEM_SORTS.oldest_lot} sort={sort} onSort={onSort} />
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

function MovesTable({
  rows,
  firstNr,
  showWho,
  sort,
  onSort,
}: {
  rows: InventoryBoardMoveRow[]
  firstNr: number
  showWho: boolean
  sort: Sort
  onSort: (key: SortKey) => void
}) {
  return (
    <table className="w-full border-collapse text-lg/snug">
      <thead>
        <tr>
          <th scope="col" className={`${TH} w-14`}>
            Nr
          </th>
          <SortTh column="date" info={MOVE_SORTS.date} sort={sort} onSort={onSort} />
          <SortTh column="name" info={MOVE_SORTS.name} sort={sort} onSort={onSort} className="w-full" />
          {showWho && <SortTh column="operator" info={MOVE_SORTS.operator} sort={sort} onSort={onSort} />}
          <SortTh column="value" info={MOVE_SORTS.value} sort={sort} onSort={onSort} className="text-right" />
          <SortTh column="lot_age" info={MOVE_SORTS.lot_age} sort={sort} onSort={onSort} />
          <SortTh column="note" info={MOVE_SORTS.note} sort={sort} onSort={onSort} className="min-w-56" />
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
