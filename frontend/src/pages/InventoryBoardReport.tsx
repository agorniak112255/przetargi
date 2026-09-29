import { useCallback, useEffect, useRef, useState } from 'react'
import { api, type InventoryBoardBucket, type InventoryBoardReport as BoardReport } from '../lib/api'

/**
 * Raport zapasów dla zarządu: jedna strona, gotowe progi, bez filtrów i tabel (GET /api/inventory/board).
 * Odbiorcy to osoby niekoniecznie biegłe w komputerze — duże litery, każda liczba podpisana, zero żargonu
 * magazynowego, same fakty bez zaleceń. Strona niczego nie przelicza poza formatem kwot i dat;
 * wszystkie liczby pochodzą z serwera (ERP XL, odczyt nocny). Drukuje się na jednej kartce A4.
 */

/** Twarda spacja między grupami cyfr — liczba nie łamie się na końcu wiersza. */
const NBSP = ' '

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

/** Polska liczba mnoga: 1 → one, 2–4 (bez 12–14) → few, reszta → many (jak na stronie Zapasy). */
function plural(n: number, one: string, few: string, many: string): string {
  const mod10 = n % 10
  const mod100 = n % 100
  if (n === 1) return one
  return mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14) ? few : many
}

function positions(n: number): string {
  return `${groupInt(n)} ${plural(n, 'pozycja', 'pozycje', 'pozycji')}`
}

function times(n: number): string {
  return `${groupInt(n)} ${plural(n, 'raz', 'razy', 'razy')}`
}

/** „29 września 2026” — z ISO (data albo data z godziną); null, gdy daty nie da się odczytać. */
function longDate(iso: string): string | null {
  // Sama data 'YYYY-MM-DD' jako dzień kalendarzowy, bez przesunięcia strefy czasowej.
  const d = /^\d{4}-\d{2}-\d{2}$/.test(iso) ? new Date(`${iso}T12:00:00`) : new Date(iso)
  if (Number.isNaN(d.getTime())) return null
  return d.toLocaleDateString('pl-PL', { day: 'numeric', month: 'long', year: 'numeric' })
}

/** Ostatnia sprzedaż jako „06.2025” (miesiąc.rok). */
function monthYear(iso: string): string {
  const [y, m] = iso.slice(0, 10).split('-')
  return y && m ? `${m}.${y}` : iso
}

function fmtQuantity(q: number, unit: string | null): string {
  const n = q.toLocaleString('pl-PL', { maximumFractionDigits: 2, useGrouping: false })
  const [int, frac] = n.split(',')
  const text = frac ? `${groupInt(Number(int))},${frac}` : groupInt(q)
  return unit ? `${text}${NBSP}${unit}` : text
}

function bucket(list: InventoryBoardBucket[], months: number): InventoryBoardBucket | null {
  return list.find((b) => b.months === months) ?? null
}

// Druk: bez menu (print:hidden w Layout) i przycisków, czarno na białym, jedna kartka A4.
// Klasy „board-*” istnieją tylko na tej stronie, więc style nie ruszają innych widoków.
const PRINT_CSS = `
@media print {
  @page { size: A4 portrait; margin: 10mm; }
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
}
`

type Tone = 'neutral' | 'amber' | 'red'

const TONE: Record<Tone, { box: string; number: string }> = {
  neutral: { box: 'border-slate-300 bg-white', number: 'text-slate-900' },
  amber: { box: 'border-amber-300 bg-amber-50', number: 'text-amber-900' },
  red: { box: 'border-red-300 bg-red-50', number: 'text-red-800' },
}

export function InventoryBoardReport() {
  const [report, setReport] = useState<BoardReport | null>(null)
  const [loading, setLoading] = useState(true)
  const [failed, setFailed] = useState(false)
  const seq = useRef(0)

  const load = useCallback(async () => {
    const my = ++seq.current
    setLoading(true)
    setFailed(false)
    try {
      const res = await api<BoardReport>('/inventory/board')
      if (my === seq.current) setReport(res)
    } catch {
      if (my === seq.current) setFailed(true)
    } finally {
      if (my === seq.current) setLoading(false)
    }
  }, [])

  useEffect(() => {
    void load()
  }, [load])

  if (loading && !report) {
    return (
      <div className="board-report mx-auto max-w-6xl py-10">
        <p className="text-3xl font-semibold text-slate-800" role="status">
          Wczytywanie raportu…
        </p>
      </div>
    )
  }

  if (failed || !report) {
    return (
      <div className="board-report mx-auto max-w-6xl py-10">
        <p className="text-2xl font-semibold text-red-800" role="alert">
          Nie udało się wczytać raportu. Spróbuj ponownie.
        </p>
        <button
          type="button"
          onClick={() => void load()}
          disabled={loading}
          className="mt-6 rounded-lg border border-slate-300 bg-white px-6 py-3 text-xl font-medium text-slate-800 shadow-sm hover:bg-slate-50 disabled:opacity-60"
        >
          {loading ? 'Wczytywanie…' : 'Spróbuj ponownie'}
        </button>
      </div>
    )
  }

  return <BoardView report={report} />
}

function BoardView({ report }: { report: BoardReport }) {
  const asOf = report.as_of ? longDate(report.as_of) : null
  const noSale6 = bucket(report.no_sale, 6)
  const noSale12 = bucket(report.no_sale, 12)
  const noSale24 = bucket(report.no_sale, 24)
  const lot12 = bucket(report.lot_age, 12)
  const lot36 = bucket(report.lot_age, 36)
  const lot60 = bucket(report.lot_age, 60)
  const moves = report.internal_moves
  const movesFrom = longDate(moves.from)
  const empty = report.stock.items === 0

  return (
    <div className="board-report mx-auto max-w-6xl pb-10 text-lg text-slate-900">
      <style>{PRINT_CSS}</style>

      <header className="board-block mb-8 flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 className="text-4xl font-semibold text-slate-900">Zapasy — raport dla zarządu</h1>
          <p className="mt-3 text-2xl text-slate-800">
            {asOf ? `Stan na ${asOf}` : 'Brak daty odczytu danych'}
          </p>
          <p className="mt-1 text-lg text-slate-700">Dane z ERP XL, odczyt nocny</p>
        </div>
        <button
          type="button"
          onClick={() => window.print()}
          className="rounded-lg border border-slate-300 bg-white px-6 py-3 text-xl font-medium text-slate-800 shadow-sm hover:bg-slate-50 print:hidden"
        >
          Drukuj / zapisz PDF
        </button>
      </header>

      {empty ? (
        <p className="text-2xl text-slate-800">
          Brak danych o towarze w magazynach. ERP XL nie przekazał jeszcze stanu magazynów.
        </p>
      ) : (
        <>
          <section className="mb-8 grid gap-4 sm:grid-cols-3">
            <BigTile
              tone="neutral"
              amount={report.stock.value}
              label="cały towar w magazynach"
              sub={positions(report.stock.items)}
            />
            <BigTile
              tone="amber"
              amount={noSale6?.value ?? null}
              label="nie sprzedaje się od pół roku"
              sub={noSale6 ? positions(noSale6.items) : 'brak danych'}
            />
            <BigTile
              tone="red"
              amount={noSale12?.value ?? null}
              label="nie sprzedaje się ponad rok"
              sub={noSale12 ? positions(noSale12.items) : 'brak danych'}
              extra={
                noSale24
                  ? `w tym ponad 2 lata: ${fmtBig(noSale24.value)} (${positions(noSale24.items)})`
                  : undefined
              }
            />
          </section>

          <section className="board-block mb-8">
            <h2 className="mb-3 text-2xl font-semibold text-slate-900">Jak długo towar leży w magazynie</h2>
            <div className="grid gap-4 sm:grid-cols-3">
              <AgeTile bucket={lot12} label="leży w magazynie ponad rok" />
              <AgeTile bucket={lot36} label="leży w magazynie ponad 3 lata" />
              <AgeTile bucket={lot60} label="leży w magazynie ponad 5 lat" />
            </div>
            <p className="mt-3 text-lg text-slate-700">Część tego towaru wciąż się sprzedaje, tylko wolno.</p>
          </section>

          <section className="board-block mb-8 rounded-2xl border border-slate-300 bg-white px-6 py-4 shadow-sm">
            <p className="text-2xl text-slate-900">
              Nigdy nie sprzedany:{' '}
              <span className="font-semibold tabular-nums">{fmtBig(report.never_sold.value)}</span>{' '}
              <span className="text-slate-700">({positions(report.never_sold.items)})</span>
            </p>
            <p className="mt-1 text-lg text-slate-700">
              Towar, który leży w magazynie ponad pół roku i nie sprzedał się ani razu.
            </p>
          </section>

          <section className="board-block mb-8">
            <h2 className="mb-3 text-2xl font-semibold text-slate-900">
              Najdroższe towary, które nie sprzedają się ponad rok
            </h2>
            {report.top_unsold.length === 0 ? (
              <p className="text-xl text-slate-800">Nie ma takiego towaru.</p>
            ) : (
              <ol className="divide-y divide-slate-200 rounded-2xl border border-slate-300 bg-white shadow-sm">
                {report.top_unsold.map((item, i) => (
                  <li key={`${item.code}-${i}`} className="flex items-start gap-4 px-6 py-3">
                    <span className="w-8 shrink-0 text-2xl font-semibold text-slate-700 tabular-nums">{i + 1}.</span>
                    <div className="min-w-0 flex-1">
                      <div className="text-xl font-medium break-words text-slate-900">
                        {item.card_name ?? item.name}
                      </div>
                      <div className="text-lg text-slate-700">
                        {fmtQuantity(item.quantity, item.unit)} ·{' '}
                        {item.last_sale_at
                          ? `ostatnia sprzedaż ${monthYear(item.last_sale_at)}`
                          : 'nigdy nie sprzedany'}
                      </div>
                    </div>
                    <span className="shrink-0 text-2xl font-semibold text-slate-900 tabular-nums">
                      {fmtBig(item.value)}
                    </span>
                  </li>
                ))}
              </ol>
            )}
          </section>

          <section className="board-block mb-8 rounded-2xl border border-slate-300 bg-white px-6 py-5 shadow-sm">
            <h2 className="mb-3 text-2xl font-semibold text-slate-900">Towar wydany i przyjęty z powrotem jako nowy</h2>
            <p className="text-xl text-slate-900">
              W ostatnich 12 miesiącach
              {movesFrom ? <span className="text-slate-700"> (od {movesFrom})</span> : null}:{' '}
              {moves.total > 0 ? (
                <>
                  <strong className="tabular-nums">{times(moves.total)}</strong> (najczęściej zamiana rozmiaru).
                </>
              ) : (
                <strong>ani razu.</strong>
              )}
            </p>
            {moves.total > 0 && (
              <p className="mt-2 text-xl text-slate-900">
                Bez żadnego wyjaśnienia:{' '}
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
            {moves.total > 0 && (
              <p className="mt-1 text-lg text-slate-700">
                „Bez wyjaśnienia” = ten sam rozmiar i kolor, a na dokumencie nie ma opisu, dlaczego towar wydano i
                przyjęto z powrotem.
              </p>
            )}
            {moves.unexplained > 0 && moves.people.length > 0 && (
              <div className="mt-4">
                <p className="text-lg font-medium text-slate-800">Kto wystawił najwięcej takich wydań:</p>
                <ul className="mt-1 grid gap-x-8 sm:grid-cols-2">
                  {moves.people.map((p, i) => (
                    <li key={`${p.name}-${i}`} className="text-xl text-slate-900">
                      {p.name} — <span className="tabular-nums">{times(p.count)}</span>
                    </li>
                  ))}
                </ul>
              </div>
            )}
            <p className="mt-4 text-lg text-slate-700">
              Szczegóły i dokumenty: zakładka Zapasy → RW → PW (administrator).
            </p>
          </section>
        </>
      )}

      <footer className="board-block border-t border-slate-300 pt-4 text-base text-slate-700">
        <p>Wartość = cena zakupu towaru, który leży w magazynie, według ERP XL. Wszystkie magazyny razem.</p>
        <p className="mt-1">
          „Nie sprzedaje się od pół roku” = od 6 miesięcy ani jednej sprzedaży tego towaru; ta kwota obejmuje też
          towar, który nie sprzedaje się ponad rok. „Leży w magazynie ponad rok” = najstarsza dostawa tego towaru,
          która jeszcze jest w magazynie, przyszła ponad rok temu; kwota obejmuje cały zapas takich towarów.
        </p>
        {report.value_unknown > 0 && (
          <p className="mt-1">
            {positions(report.value_unknown)} nie ma w kwotach — ERP XL nie podaje ich ceny zakupu.
          </p>
        )}
      </footer>
    </div>
  )
}

function BigTile({
  tone,
  amount,
  label,
  sub,
  extra,
}: {
  tone: Tone
  amount: number | null
  label: string
  sub: string
  extra?: string
}) {
  const t = TONE[tone]
  return (
    <div className={`board-block rounded-2xl border-2 px-6 py-5 shadow-sm ${t.box}`}>
      <div className={`text-4xl font-semibold tabular-nums ${t.number}`}>{amount === null ? '—' : fmtBig(amount)}</div>
      <div className="mt-2 text-xl font-medium text-slate-900">{label}</div>
      <div className="mt-1 text-lg text-slate-700">{sub}</div>
      {extra && <div className="mt-2 text-lg text-slate-800">{extra}</div>}
    </div>
  )
}

function AgeTile({ bucket: b, label }: { bucket: InventoryBoardBucket | null; label: string }) {
  return (
    <div className="board-block rounded-2xl border border-slate-300 bg-white px-6 py-4 shadow-sm">
      <div className="text-3xl font-semibold text-slate-800 tabular-nums">{b ? fmtBig(b.value) : '—'}</div>
      <div className="mt-2 text-xl text-slate-900">{label}</div>
      <div className="mt-1 text-lg text-slate-700">{b ? positions(b.items) : 'brak danych'}</div>
    </div>
  )
}
