import { Fragment, useEffect, useRef, useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../lib/api'

type ProgressMode = 'analyze' | 'import' | null

const ANALYZE_STEPS: Array<{ at: number; pct: number; label: string }> = [
  { at: 0, pct: 6, label: 'Wysyłanie pliku…' },
  { at: 3, pct: 14, label: 'Odczyt tekstu z PDF (także duże pliki)…' },
  { at: 10, pct: 28, label: 'Dzielę cennik na części…' },
  { at: 20, pct: 42, label: 'AI analizuje kolejne części…' },
  { at: 45, pct: 58, label: 'Scalanie produktów z części…' },
  { at: 80, pct: 72, label: 'Nadal pracuję — duży PDF może trwać kilka minut…' },
  { at: 140, pct: 85, label: 'Domknięcie mapowania…' },
  { at: 200, pct: 92, label: 'Prawie gotowe…' },
]

const IMPORT_STEPS: Array<{ at: number; pct: number; label: string }> = [
  { at: 0, pct: 15, label: 'Przygotowanie importu…' },
  { at: 2, pct: 40, label: 'Zapis produktów do bazy…' },
  { at: 8, pct: 70, label: 'Aktualizacja istniejących SKU…' },
  { at: 20, pct: 90, label: 'Finalizacja…' },
]

function stepFor(steps: typeof ANALYZE_STEPS, elapsed: number) {
  let current = steps[0]
  for (const s of steps) {
    if (elapsed >= s.at) current = s
  }
  return current
}

type HistoryKind = 'prices' | 'updates' | 'skips'

type PriceChange = {
  sku: string
  name: string
  catalog_old: number
  catalog_new: number
  catalog_pct: number
  purchase_old: number
  purchase_new: number
  discount_old: number
  discount_new: number
  direction: 'up' | 'down' | 'flat'
}

type UpdatedProduct = {
  sku: string
  name: string
  catalog_old: number
  catalog_new: number
  purchase_old: number
  purchase_new: number
  discount_old: number
  discount_new: number
  price_changed?: boolean
  fields?: string[]
}

type SkippedDetail = {
  reason: string
  row?: number | null
  sheet?: string | null
  sku?: string | null
  name?: string | null
}

type PriceList = {
  id: number
  manufacturer: string
  version: string
  original_filename: string | null
  rows_total: number
  products_created: number
  products_updated: number
  prices_changed?: number
  rows_skipped: number
  errors: string[] | null
  price_changes?: PriceChange[] | null
  updated_products?: UpdatedProduct[] | null
  skipped_details?: SkippedDetail[] | null
  created_at: string
  importer?: { name: string }
}

type ImportResult = {
  created: number
  updated: number
  skipped: number
  errors: string[]
  prices_changed?: number
  price_changes?: PriceChange[]
}

type ProductRow = {
  sku: string
  name: string
  catalog_price_net: number
  discount_percent: number
  purchase_price: number
  currency?: string | null
  pack_qty?: number | null
  packaging?: string | null
}

const CURRENCIES = ['PLN', 'EUR', 'USD', 'GBP', 'CHF', 'CZK', 'SEK', 'NOK', 'DKK'] as const

type Analysis = {
  source?: string
  mapping: {
    manufacturer_detected: string | null
    currency: string | null
    notes: string
    sheets: Array<{
      sheet: string
      include: boolean
      header_excel_row: number
      columns: Record<string, number | null>
      repeating_headers: boolean
      confidence: number
    }>
  } | null
  products?: ProductRow[]
  preview: ProductRow[]
  products_found: number
  rows_total: number
  skipped: number
  errors_count: number
  model: string
  meta?: { manufacturer: string; version: string; source: string }
}

/** Szybka podpowiedź z nazwy pliku (docelowo serwer i tak zweryfikuje). */
function guessMetaFromFilename(name: string): { manufacturer: string; version: string } {
  const base = name.replace(/\.[^.]+$/i, '')
  const lower = base.toLowerCase()
  const brands: Array<[string, string[]]> = [
    ['3M', ['3m']],
    ['Ansell', ['ansell', 'alphatec', 'hyflex']],
    ['Ansell', ['ema body', 'ema']],
    ['PROS', ['pros', 'ajgroup', 'aj group']],
    ['Lebon', ['lebon']],
    ['Debstoko', ['debstoko', 'stoko']],
    ['PANTHER', ['panther']],
    ['Ardon', ['ardon']],
    ['JS GLOVES', ['js gloves', 'szewczyk', 'js-gloves']],
    ['uvex', ['uvex']],
    ['MAPA', ['mapa']],
    ['Rostaing', ['rostaing']],
  ]
  let manufacturer = ''
  for (const [brand, needles] of brands) {
    if (needles.some((n) => lower.includes(n))) {
      manufacturer = brand
      break
    }
  }
  let version = ''
  const m1 = base.match(/\b(20\d{2})[-_.](\d{2})\b/)
  const m2 = base.match(/\b(\d{1,2})[.\-](\d{1,2})[.\-](20\d{2})\b/)
  const m3 = base.match(/\b(20\d{2})(\d{2})(\d{2})\b/)
  const m4 = base.match(/\b(20\d{2})\b/)
  const mYear = base.match(/(?<![A-Za-z0-9])(20\d{2})(?![A-Za-z0-9])/)
  if (m1) version = `${m1[1]}-${m1[2]}`
  else if (m2) version = `${m2[3]}-${String(m2[2]).padStart(2, '0')}`
  else if (m3) version = `${m3[1]}-${m3[2]}`
  else if (mYear) version = mYear[1]
  else if (m4) version = m4[1]
  return { manufacturer, version }
}

const btnPrimary =
  'inline-flex items-center justify-center rounded-md bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50'
const btnAi =
  'inline-flex items-center justify-center rounded-md bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50'
const btnSecondary =
  'inline-flex items-center justify-center rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50'

export function PriceLists() {
  const [rows, setRows] = useState<PriceList[]>([])
  const [manufacturer, setManufacturer] = useState('')
  const [version, setVersion] = useState('')
  const [category, setCategory] = useState('')
  const [file, setFile] = useState<File | null>(null)
  const [busy, setBusy] = useState(false)
  const [progressMode, setProgressMode] = useState<ProgressMode>(null)
  const [progressPct, setProgressPct] = useState(0)
  const [progressLabel, setProgressLabel] = useState('')
  const [elapsedSec, setElapsedSec] = useState(0)
  const [msg, setMsg] = useState('')
  const [err, setErr] = useState('')
  const [analysis, setAnalysis] = useState<Analysis | null>(null)
  const [lastPriceChanges, setLastPriceChanges] = useState<PriceChange[]>([])
  const [lastPricesChanged, setLastPricesChanged] = useState(0)
  const [expandedHistory, setExpandedHistory] = useState<{ id: number; kind: HistoryKind } | null>(
    null,
  )
  const [historyCache, setHistoryCache] = useState<Record<number, PriceList>>({})
  const [historyDetailsLoading, setHistoryDetailsLoading] = useState<number | null>(null)
  const progressTimer = useRef<number | null>(null)

  async function ensureHistoryDetails(row: PriceList): Promise<PriceList> {
    if (historyCache[row.id]) return historyCache[row.id]
    if (
      (row.price_changes && row.price_changes.length > 0) ||
      (row.updated_products && row.updated_products.length > 0) ||
      (row.skipped_details && row.skipped_details.length > 0)
    ) {
      setHistoryCache((prev) => ({ ...prev, [row.id]: row }))
      return row
    }
    setHistoryDetailsLoading(row.id)
    try {
      const full = await api<PriceList>(`/price-lists/${row.id}`)
      setHistoryCache((prev) => ({ ...prev, [row.id]: full }))
      return full
    } catch {
      setHistoryCache((prev) => ({ ...prev, [row.id]: row }))
      return row
    } finally {
      setHistoryDetailsLoading(null)
    }
  }

  async function toggleHistory(row: PriceList, kind: HistoryKind, count: number) {
    if (count <= 0) return
    if (expandedHistory?.id === row.id && expandedHistory.kind === kind) {
      setExpandedHistory(null)
      return
    }
    setExpandedHistory({ id: row.id, kind })
    await ensureHistoryDetails(row)
  }

  function renderPriceChangesTable(changes: PriceChange[], totalHint?: number) {
    const list = changes.slice(0, 100)
    return (
      <>
        <table className="w-full text-left">
          <thead>
            <tr className="border-b bg-slate-50">
              <th className="p-2">SKU</th>
              <th className="p-2">Nazwa</th>
              <th className="p-2">Cena kat. stara → nowa</th>
              <th className="p-2">Zmiana</th>
              <th className="p-2">Po upuście</th>
              <th className="p-2">Upust</th>
            </tr>
          </thead>
          <tbody>
            {list.map((c) => (
              <tr key={c.sku} className="border-b">
                <td className="p-2 font-medium">{c.sku}</td>
                <td className="p-2">{c.name}</td>
                <td className="p-2">
                  {c.catalog_old} → {c.catalog_new}
                </td>
                <td
                  className={`p-2 font-semibold ${
                    c.direction === 'up'
                      ? 'text-red-600'
                      : c.direction === 'down'
                        ? 'text-green-700'
                        : 'text-slate-500'
                  }`}
                >
                  {c.catalog_pct > 0 ? '+' : ''}
                  {c.catalog_pct}%
                </td>
                <td className="p-2">
                  {c.purchase_old} → {c.purchase_new}
                </td>
                <td className="p-2">
                  {c.discount_old}% → {c.discount_new}%
                </td>
              </tr>
            ))}
            {list.length === 0 && (
              <tr>
                <td colSpan={6} className="p-3 text-slate-400">
                  Brak zapisanych szczegółów dla tego importu (starsze importy mogą nie mieć listy).
                </td>
              </tr>
            )}
          </tbody>
        </table>
        {(totalHint ?? changes.length) > list.length && (
          <p className="mt-2 text-slate-500">
            Pokazano {list.length} z {totalHint ?? changes.length} pozycji.
          </p>
        )}
      </>
    )
  }

  function renderUpdatedProductsTable(items: UpdatedProduct[], totalHint?: number) {
    const list = items.slice(0, 100)
    return (
      <>
        <table className="w-full text-left">
          <thead>
            <tr className="border-b bg-slate-50">
              <th className="p-2">SKU</th>
              <th className="p-2">Nazwa</th>
              <th className="p-2">Cena kat.</th>
              <th className="p-2">Po upuście</th>
              <th className="p-2">Upust</th>
              <th className="p-2">Co się zmieniło</th>
            </tr>
          </thead>
          <tbody>
            {list.map((u) => (
              <tr key={u.sku} className="border-b">
                <td className="p-2 font-medium">{u.sku}</td>
                <td className="p-2">{u.name}</td>
                <td className="p-2">
                  {u.catalog_old} → {u.catalog_new}
                </td>
                <td className="p-2">
                  {u.purchase_old} → {u.purchase_new}
                </td>
                <td className="p-2">
                  {u.discount_old}% → {u.discount_new}%
                </td>
                <td className="p-2 text-slate-600">
                  {(u.fields ?? []).join(', ') || '—'}
                  {u.price_changed ? ' · zmiana ceny' : ''}
                </td>
              </tr>
            ))}
            {list.length === 0 && (
              <tr>
                <td colSpan={6} className="p-3 text-slate-400">
                  Brak listy zaktualizowanych SKU dla tego importu. Przy zmianach cen użyj kolumny
                  „Zmiany cen”. Nowe importy zapiszą pełną listę Update.
                </td>
              </tr>
            )}
          </tbody>
        </table>
        {(totalHint ?? items.length) > list.length && (
          <p className="mt-2 text-slate-500">
            Pokazano {list.length} z {totalHint ?? items.length} aktualizacji.
          </p>
        )}
      </>
    )
  }

  function renderSkippedDetailsTable(
    items: SkippedDetail[],
    errors: string[] | null | undefined,
    totalHint?: number,
  ) {
    let list = items.slice(0, 100)
    if (list.length === 0 && errors && errors.length > 0) {
      list = errors.slice(0, 100).map((reason) => ({ reason }))
    }
    return (
      <>
        <table className="w-full text-left">
          <thead>
            <tr className="border-b bg-slate-50">
              <th className="p-2">Wiersz</th>
              <th className="p-2">Arkusz</th>
              <th className="p-2">SKU</th>
              <th className="p-2">Nazwa</th>
              <th className="p-2">Powód</th>
            </tr>
          </thead>
          <tbody>
            {list.map((s, i) => (
              <tr key={`${s.row ?? 'x'}-${i}`} className="border-b">
                <td className="p-2">{s.row ?? '—'}</td>
                <td className="p-2">{s.sheet ?? '—'}</td>
                <td className="p-2 font-medium">{s.sku ?? '—'}</td>
                <td className="p-2">{s.name ?? '—'}</td>
                <td className="p-2 text-slate-700">{s.reason}</td>
              </tr>
            ))}
            {list.length === 0 && (
              <tr>
                <td colSpan={5} className="p-3 text-slate-400">
                  Brak szczegółów pominięć (np. same puste wiersze bez zapisu). Nowe importy zapiszą
                  podsumowanie i przykłady.
                </td>
              </tr>
            )}
          </tbody>
        </table>
        {(totalHint ?? list.length) > list.length && (
          <p className="mt-2 text-slate-500">
            Pokazano {list.length} z {totalHint ?? list.length} pominięć.
          </p>
        )}
      </>
    )
  }

  function historyCountButton(
    row: PriceList,
    kind: HistoryKind,
    count: number,
    activeClass: string,
  ) {
    const open = expandedHistory?.id === row.id && expandedHistory.kind === kind
    if (count <= 0) {
      return <span className="text-slate-400">0</span>
    }
    return (
      <button
        type="button"
        onClick={() => void toggleHistory(row, kind, count)}
        className={`font-medium underline decoration-dotted underline-offset-2 ${activeClass} ${
          open ? 'opacity-100' : 'opacity-90 hover:opacity-100'
        }`}
      >
        {count}
        {open ? ' ▴' : ' ▾'}
      </button>
    )
  }

  function applyImportResult(res: ImportResult, prefix: string) {
    const changed = res.prices_changed ?? 0
    setLastPricesChanged(changed)
    setLastPriceChanges(res.price_changes ?? [])
    setMsg(
      `${prefix}: +${res.created} nowych, ${res.updated} zaktualizowanych, ${res.skipped} pominiętych` +
        (changed > 0 ? `, zmiany ceny: ${changed}` : ', bez zmian cen') +
        (res.errors.length ? `. Uwagi: ${res.errors.slice(0, 3).join('; ')}` : '.'),
    )
  }

  function startProgress(mode: ProgressMode) {
    setProgressMode(mode)
    setProgressPct(mode === 'analyze' ? 5 : 10)
    setProgressLabel(mode === 'analyze' ? 'Start analizy…' : 'Start importu…')
    setElapsedSec(0)
    const started = Date.now()
    if (progressTimer.current) window.clearInterval(progressTimer.current)
    progressTimer.current = window.setInterval(() => {
      const elapsed = Math.floor((Date.now() - started) / 1000)
      setElapsedSec(elapsed)
      const steps = mode === 'analyze' ? ANALYZE_STEPS : IMPORT_STEPS
      const step = stepFor(steps, elapsed)
      setProgressPct(step.pct)
      setProgressLabel(step.label)
    }, 400)
  }

  function finishProgress(ok: boolean) {
    if (progressTimer.current) {
      window.clearInterval(progressTimer.current)
      progressTimer.current = null
    }
    if (ok) {
      setProgressPct(100)
      setProgressLabel('Gotowe')
    }
    window.setTimeout(() => {
      setProgressMode(null)
      setProgressPct(0)
      setProgressLabel('')
      setElapsedSec(0)
    }, ok ? 600 : 0)
  }

  useEffect(() => {
    return () => {
      if (progressTimer.current) window.clearInterval(progressTimer.current)
    }
  }, [])

  async function load() {
    setRows(await api<PriceList[]>('/price-lists'))
  }

  useEffect(() => {
    void load()
  }, [])

  function buildFormData(): FormData {
    const fd = new FormData()
    if (!file) throw new Error('Wybierz plik cennika (XLSX/PDF).')
    fd.append('file', file)
    fd.append('manufacturer', manufacturer)
    fd.append('version', version)
    if (category) fd.append('category', category)
    return fd
  }

  async function onAnalyze() {
    if (!file) {
      setErr('Wybierz plik cennika (XLSX, XLS, CSV lub PDF).')
      return
    }
    setBusy(true)
    startProgress('analyze')
    setErr('')
    setMsg('')
    setAnalysis(null)
    try {
      const fd = new FormData()
      fd.append('file', file)
      fd.append('manufacturer', manufacturer)
      const res = await api<Analysis>('/price-lists/analyze', { method: 'POST', body: fd })
      const defaultCur = res.mapping?.currency ?? 'PLN'
      const withCurrency = (list: ProductRow[] | undefined) =>
        (list ?? []).map((p) => ({ ...p, currency: p.currency ?? defaultCur }))
      setAnalysis({
        ...res,
        products: withCurrency(res.products),
        preview: withCurrency(res.preview),
      })
      const detectedManuf =
        res.meta?.manufacturer ?? res.mapping?.manufacturer_detected ?? null
      if (detectedManuf) setManufacturer(detectedManuf)
      if (res.meta?.version) setVersion(res.meta.version)
      setMsg(
        `AI (${res.model}): znaleziono ${res.products_found} produktów do importu` +
          ` (przeskanowano ${res.rows_total} wierszy, pominięto ${res.skipped}).` +
          ` Poniżej przykłady ${res.preview.length} z ${res.products_found}.` +
          (detectedManuf ? ` · producent: ${detectedManuf}` : '') +
          (res.meta?.version ? ` · wersja: ${res.meta.version}` : ''),
      )
      finishProgress(true)
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Błąd analizy AI')
      finishProgress(false)
    } finally {
      setBusy(false)
    }
  }

  async function onImportAi(e: FormEvent) {
    e.preventDefault()
    if (!file) {
      setErr('Wybierz plik cennika.')
      return
    }
    if (!analysis) {
      setErr('Najpierw uruchom „Analizuj AI”.')
      return
    }
    setBusy(true)
    startProgress('import')
    setErr('')
    setMsg('')
    try {
      const fd = buildFormData()
      fd.append('use_ai', '1')
      if (analysis.mapping) {
        fd.append('mapping', JSON.stringify(analysis.mapping))
      }
      // XLSX: import po mapowaniu (bez pełnej listy w JSON). PDF: lista produktów z analizy.
      const isSpreadsheet = analysis.source === 'spreadsheet'
      if (!isSpreadsheet && analysis.products && analysis.products.length > 0) {
        fd.append('products', JSON.stringify(analysis.products))
      }

      const res = await api<ImportResult>('/price-lists/import', { method: 'POST', body: fd })

      applyImportResult(res, 'Import AI OK')
      setFile(null)
      setAnalysis(null)
      await load()
      finishProgress(true)
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Błąd importu')
      finishProgress(false)
    } finally {
      setBusy(false)
    }
  }

  async function onImportSimple(e: FormEvent) {
    e.preventDefault()
    if (!file) {
      setErr('Wybierz plik XLSX/CSV (nie PDF).')
      return
    }
    if (file.name.toLowerCase().endsWith('.pdf')) {
      setErr('PDF wymaga „Analizuj AI”, potem „Importuj wg AI”.')
      return
    }
    setBusy(true)
    startProgress('import')
    setErr('')
    setMsg('')
    try {
      const fd = buildFormData()
      const res = await api<ImportResult>('/price-lists/import', { method: 'POST', body: fd })

      applyImportResult(res, 'Import OK')
      setFile(null)
      await load()
      finishProgress(true)
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Błąd importu')
      finishProgress(false)
    } finally {
      setBusy(false)
    }
  }

  return (
    <div>
      <h1 className="mb-2 text-xl font-semibold">Cenniki producentów</h1>
      <p className="mb-4 text-xs text-slate-500">
        Importujemy: <strong>nazwa</strong>, <strong>symbol/kod</strong>, <strong>cena</strong>,{' '}
        <strong>upust/marża</strong>, <strong>ilość w opak./kartonie</strong>, <strong>opakowanie</strong>.
        Duże PDF (nawet 30+ MB) analizowane tekstowo w częściach — może potrwać kilka minut. API:{' '}
        <Link className="text-blue-600" to="/ai-settings">
          Ustawienia AI
        </Link>
        .
      </p>

      {msg && <p className="mb-2 rounded bg-green-50 px-3 py-2 text-xs text-green-800">{msg}</p>}
      {err && <p className="mb-2 rounded bg-red-50 px-3 py-2 text-xs text-red-700">{err}</p>}

      <div className="mb-4 rounded-xl bg-white p-4 shadow-sm text-sm">
        <h2 className="mb-3 font-semibold">Import cennika → baza produktów</h2>
        <div className="grid gap-3 sm:grid-cols-3">
          <label className="block text-xs">
            Producent
            <input
              className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5"
              value={manufacturer}
              onChange={(e) => setManufacturer(e.target.value)}
              list="manufacturers"
              placeholder="z nazwy / treści pliku"
            />
            <datalist id="manufacturers">
              <option value="3M" />
              <option value="Ansell" />
              <option value="PROS" />
              <option value="Debstoko" />
              <option value="Lebon" />
              <option value="uvex" />
              <option value="MAPA" />
              <option value="Rostaing" />
            </datalist>
          </label>
          <label className="block text-xs">
            Wersja cennika
            <input
              className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5"
              value={version}
              onChange={(e) => setVersion(e.target.value)}
              placeholder="z nazwy pliku"
            />
          </label>
          <label className="block text-xs">
            Kategoria domyślna
            <input
              className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5"
              value={category}
              onChange={(e) => setCategory(e.target.value)}
              placeholder="opcjonalnie"
            />
          </label>
        </div>
        <div className="mt-3">
          <p className="mb-1 text-xs">Plik cennika (XLSX / XLS / CSV / PDF) *</p>
          <div className="flex flex-wrap items-center gap-2">
            <label className="inline-flex cursor-pointer items-center justify-center rounded-md bg-slate-800 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-slate-700">
              Przeglądaj…
              <input
                type="file"
                accept=".xlsx,.xls,.csv,.pdf,application/pdf,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                className="sr-only"
                onChange={(e) => {
                  const f = e.target.files?.[0] ?? null
                  setFile(f)
                  setAnalysis(null)
                  if (f) {
                    const guess = guessMetaFromFilename(f.name)
                    if (guess.manufacturer) setManufacturer(guess.manufacturer)
                    if (guess.version) setVersion(guess.version)
                  }
                }}
              />
            </label>
            <span className="text-xs text-slate-600">
              {file ? (
                <>
                  Wybrano: <span className="font-medium text-slate-800">{file.name}</span>
                </>
              ) : (
                'Nie wybrano pliku'
              )}
            </span>
          </div>
        </div>
        <div className="mt-4 flex flex-wrap gap-2">
          <button type="button" disabled={busy} onClick={() => void onAnalyze()} className={btnAi}>
            {progressMode === 'analyze' ? `Analizuję… ${progressPct}%` : '1. Analizuj AI'}
          </button>
          <button
            type="button"
            disabled={busy || !analysis}
            onClick={(e) => void onImportAi(e as unknown as FormEvent)}
            className={btnPrimary}
          >
            {progressMode === 'import' ? `Importuję… ${progressPct}%` : '2. Importuj wg AI'}
          </button>
          <button
            type="button"
            disabled={busy}
            onClick={(e) => void onImportSimple(e as unknown as FormEvent)}
            className={btnSecondary}
          >
            Import prosty (tylko XLSX)
          </button>
        </div>

        {progressMode && (
          <div className="mt-4 rounded-lg border border-indigo-100 bg-indigo-50/80 px-3 py-3">
            <div className="mb-1.5 flex items-center justify-between gap-2 text-xs">
              <span className="font-medium text-indigo-900">
                {progressMode === 'analyze' ? 'Analiza AI w toku' : 'Import w toku'}
                <span className="ml-2 inline-block h-2 w-2 animate-pulse rounded-full bg-indigo-500" />
              </span>
              <span className="tabular-nums text-indigo-800">
                {progressPct}% · {elapsedSec}s
              </span>
            </div>
            <div className="h-2.5 overflow-hidden rounded-full bg-indigo-100">
              <div
                className="h-full rounded-full bg-indigo-600 transition-[width] duration-500 ease-out"
                style={{ width: `${Math.min(100, progressPct)}%` }}
              />
            </div>
            <p className="mt-2 text-xs text-indigo-800">{progressLabel}</p>
            <p className="mt-0.5 text-[11px] text-indigo-600/80">
              Nie zamykaj karty — czekamy na odpowiedź serwera / modelu AI.
            </p>
          </div>
        )}
      </div>

      {analysis && (
        <div className="mb-4 rounded-xl bg-white p-4 shadow-sm text-xs">
          <h2 className="mb-2 text-sm font-semibold">Wynik analizy AI</h2>
          <p className="mb-2 text-slate-600">
            {analysis.mapping?.notes}
            {analysis.source ? ` · źródło: ${analysis.source}` : ''}
            {analysis.mapping?.manufacturer_detected
              ? ` · wykryty producent: ${analysis.mapping.manufacturer_detected}`
              : ''}
          </p>
          <div className="mb-3 flex flex-wrap items-center gap-2 rounded bg-slate-50 px-3 py-2">
            <span className="font-medium text-slate-700">
              Do importu: <span className="text-blue-700">{analysis.products_found}</span> unikalnych SKU
              {' · '}przeskanowano {analysis.rows_total} wierszy
              {' · '}pominięto {analysis.skipped}
              {analysis.errors_count > 0 ? ` · błędów: ${analysis.errors_count}` : ''}
            </span>
            <label className="ml-auto flex items-center gap-1.5 text-slate-600">
              Waluta domyślna
              <select
                className="rounded border border-slate-300 bg-white px-2 py-1 font-medium text-slate-800"
                value={analysis.mapping?.currency ?? 'PLN'}
                onChange={(e) => {
                  const cur = e.target.value
                  setAnalysis((prev) => {
                    if (!prev) return prev
                    const apply = (list: ProductRow[] | undefined) =>
                      (list ?? []).map((p) => ({ ...p, currency: cur }))
                    return {
                      ...prev,
                      mapping: prev.mapping
                        ? { ...prev.mapping, currency: cur }
                        : prev.mapping,
                      products: apply(prev.products),
                      preview: apply(prev.preview),
                    }
                  })
                }}
              >
                {CURRENCIES.map((c) => (
                  <option key={c} value={c}>
                    {c}
                  </option>
                ))}
              </select>
            </label>
          </div>
          {(analysis.mapping?.sheets?.length ?? 0) > 0 && (
            <table className="mb-3 w-full text-left">
              <thead>
                <tr className="border-b bg-slate-50">
                  <th className="p-2">Arkusz</th>
                  <th className="p-2">Import</th>
                  <th className="p-2">Nagłówek</th>
                  <th className="p-2">SKU / Nazwa / Cena</th>
                  <th className="p-2">Conf.</th>
                </tr>
              </thead>
              <tbody>
                {analysis.mapping!.sheets.map((s) => (
                  <tr key={s.sheet} className="border-b">
                    <td className="p-2">{s.sheet}</td>
                    <td className="p-2">{s.include ? 'tak' : 'nie'}</td>
                    <td className="p-2">wiersz {s.header_excel_row}</td>
                    <td className="p-2">
                      {s.columns.sku}/{s.columns.name}/{s.columns.catalog_price}
                    </td>
                    <td className="p-2">{Math.round(s.confidence * 100)}%</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
          <h3 className="mb-1 font-semibold">
            Podgląd (przykłady {analysis.preview.length} z {analysis.products_found})
          </h3>
          <table className="w-full text-left">
            <thead>
              <tr className="border-b bg-slate-50">
                <th className="p-2">Symbol / kod</th>
                <th className="p-2">Nazwa (Opis)</th>
                <th className="p-2">Cena katalogowa</th>
                <th className="p-2">Waluta</th>
                <th className="p-2">Upust %</th>
                <th className="p-2">Cena po upuście</th>
                <th className="p-2">Ilość/opak.</th>
                <th className="p-2">Opakowanie</th>
              </tr>
            </thead>
            <tbody>
              {analysis.preview.map((p) => (
                <tr key={p.sku} className="border-b">
                  <td className="p-2 font-medium">{p.sku}</td>
                  <td className="p-2">{p.name}</td>
                  <td className="p-2">{p.catalog_price_net}</td>
                  <td className="p-2">
                    <select
                      className="rounded border border-slate-300 bg-white px-1.5 py-0.5"
                      value={p.currency ?? analysis.mapping?.currency ?? 'PLN'}
                      onChange={(e) => {
                        const cur = e.target.value
                        setAnalysis((prev) => {
                          if (!prev) return prev
                          const patch = (list: ProductRow[] | undefined) =>
                            (list ?? []).map((row) =>
                              row.sku === p.sku ? { ...row, currency: cur } : row,
                            )
                          return {
                            ...prev,
                            preview: patch(prev.preview),
                            products: patch(prev.products?.length ? prev.products : prev.preview),
                          }
                        })
                      }}
                    >
                      {CURRENCIES.map((c) => (
                        <option key={c} value={c}>
                          {c}
                        </option>
                      ))}
                    </select>
                  </td>
                  <td className="p-2">{p.discount_percent}%</td>
                  <td className="p-2 font-medium text-slate-800">{p.purchase_price}</td>
                  <td className="p-2">{p.pack_qty ?? '—'}</td>
                  <td className="p-2">{p.packaging ?? '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {lastPriceChanges.length > 0 && (
        <div className="mb-4 rounded-xl bg-white p-4 shadow-sm text-xs">
          <h2 className="mb-2 text-sm font-semibold">
            Zmiany cen w ostatnim imporcie ({lastPricesChanged})
          </h2>
          {renderPriceChangesTable(lastPriceChanges, lastPricesChanged)}
        </div>
      )}

      <div className="rounded-xl bg-white p-4 shadow-sm">
        <h2 className="mb-3 text-sm font-semibold">Historia importów</h2>
        <p className="mb-2 text-[11px] text-slate-500">
          Kliknij liczbę w kolumnie Update / Zmiany cen / Skip, aby zobaczyć listę pozycji.
        </p>
        <table className="w-full text-left text-xs">
          <thead>
            <tr className="border-b bg-slate-50">
              <th className="p-2">Data</th>
              <th className="p-2">Producent</th>
              <th className="p-2">Wersja</th>
              <th className="p-2">Plik</th>
              <th className="p-2">Nowe</th>
              <th className="p-2">Update</th>
              <th className="p-2">Zmiany cen</th>
              <th className="p-2">Skip</th>
              <th className="p-2">Kto</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((r) => {
              const cached = historyCache[r.id] ?? r
              const kind = expandedHistory?.id === r.id ? expandedHistory.kind : null
              return (
              <Fragment key={r.id}>
                <tr className="border-b">
                  <td className="p-2">{new Date(r.created_at).toLocaleString('pl-PL')}</td>
                  <td className="p-2">{r.manufacturer}</td>
                  <td className="p-2">{r.version}</td>
                  <td className="p-2">{r.original_filename ?? '—'}</td>
                  <td className="p-2 text-green-700">{r.products_created}</td>
                  <td className="p-2">
                    {historyCountButton(r, 'updates', r.products_updated, 'text-blue-700')}
                  </td>
                  <td className="p-2">
                    {historyCountButton(r, 'prices', r.prices_changed ?? 0, 'text-amber-700')}
                  </td>
                  <td className="p-2">
                    {historyCountButton(r, 'skips', r.rows_skipped, 'text-slate-700')}
                  </td>
                  <td className="p-2">{r.importer?.name ?? '—'}</td>
                </tr>
                {kind && (
                  <tr
                    className={`border-b ${
                      kind === 'prices'
                        ? 'bg-amber-50/40'
                        : kind === 'updates'
                          ? 'bg-blue-50/40'
                          : 'bg-slate-50'
                    }`}
                  >
                    <td colSpan={9} className="p-3">
                      <p className="mb-2 font-semibold text-slate-700">
                        {kind === 'prices'
                          ? 'Zmiany cen'
                          : kind === 'updates'
                            ? 'Zaktualizowane produkty'
                            : 'Pominięte wiersze'}
                        {` — ${r.manufacturer} / ${r.version}`}
                        {r.original_filename ? ` · ${r.original_filename}` : ''}
                        {kind === 'prices' && ` · ${r.prices_changed ?? 0}`}
                        {kind === 'updates' && ` · ${r.products_updated}`}
                        {kind === 'skips' && ` · ${r.rows_skipped}`}
                      </p>
                      {historyDetailsLoading === r.id ? (
                        <p className="text-slate-500">Ładowanie szczegółów…</p>
                      ) : kind === 'prices' ? (
                        renderPriceChangesTable(cached.price_changes ?? [], r.prices_changed)
                      ) : kind === 'updates' ? (
                        renderUpdatedProductsTable(
                          cached.updated_products ?? [],
                          r.products_updated,
                        )
                      ) : (
                        renderSkippedDetailsTable(
                          cached.skipped_details ?? [],
                          cached.errors,
                          r.rows_skipped,
                        )
                      )}
                    </td>
                  </tr>
                )}
              </Fragment>
              )
            })}
            {rows.length === 0 && (
              <tr>
                <td colSpan={9} className="p-3 text-slate-400">
                  Brak importów — wgraj pierwszy cennik.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  )
}
