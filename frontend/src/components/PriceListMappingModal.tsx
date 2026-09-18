import { useEffect, useMemo } from 'react'

export type SheetColumn = { index: number; label: string; sample: string }

export type SheetMapping = {
  sheet: string
  include: boolean
  header_excel_row: number
  columns: Record<string, number | null>
  available_columns?: SheetColumn[]
  locked_columns?: string[]
  repeating_headers: boolean
  confidence: number
}

export type MappingPreviewRow = {
  sku: string
  name: string
  catalog_price_net: number
  discount_percent: number
  purchase_price: number
  currency?: string | null
  category?: string | null
  pack_qty?: number | null
  packaging?: string | null
}

/**
 * Role kolumn do ręcznej korekty przed importem. Kolejność jest kolejnością w oknie — najpierw to,
 * bez czego importu nie ma, potem reszta.
 */
export const COLUMN_ROLES: Array<{
  key: string
  label: string
  hint?: string
  required?: boolean
}> = [
  { key: 'sku', label: 'Kod / symbol' },
  { key: 'name', label: 'Nazwa', required: true },
  { key: 'name_extra', label: 'Nazwa — druga część', hint: 'doklejana do nazwy' },
  { key: 'model_name', label: 'Rodzina / model' },
  { key: 'catalog_price', label: 'Cena katalogowa', required: true },
  { key: 'discount', label: 'Upust %' },
  { key: 'purchase', label: 'Cena zakupu' },
  { key: 'category', label: 'Grupa asortymentowa' },
  { key: 'ean', label: 'EAN' },
  { key: 'pack_qty', label: 'Ilość w opakowaniu' },
  { key: 'packaging', label: 'Opakowanie' },
  { key: 'currency', label: 'Waluta' },
]

/** Numer kolumny w postaci, w jakiej widzi ją człowiek w arkuszu: 0 → A, 27 → AB. */
export function columnLetter(index: number): string {
  let n = index
  let out = ''
  do {
    out = String.fromCharCode(65 + (n % 26)) + out
    n = Math.floor(n / 26) - 1
  } while (n >= 0)
  return out
}

type Props = {
  open: boolean
  busy: boolean
  /** mapowanie zmienione, ale plik nie został jeszcze odczytany na nowo */
  dirty: boolean
  sheets: SheetMapping[]
  rows: MappingPreviewRow[]
  productsFound: number
  rowsTotal: number
  skipped: number
  errors: string[]
  defaultCurrency: string
  /** upust i cena po upuście liczone tak samo jak przy potwierdzeniu importu */
  effectiveFor: (row: MappingPreviewRow) => { discount: number; purchase: number }
  onSetColumn: (sheet: string, role: string, index: number | null) => void
  onSetInclude: (sheet: string, include: boolean) => void
  onRefresh: () => void
  onClose: () => void
}

export function PriceListMappingModal({
  open,
  busy,
  dirty,
  sheets,
  rows,
  productsFound,
  rowsTotal,
  skipped,
  errors,
  defaultCurrency,
  effectiveFor,
  onSetColumn,
  onSetInclude,
  onRefresh,
  onClose,
}: Props) {
  useEffect(() => {
    if (!open) return
    function onKey(e: KeyboardEvent) {
      if (e.key === 'Escape' && !busy) onClose()
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [open, busy, onClose])

  /**
   * Dwie pozycje z tym samym kodem to przy imporcie jedna karta — druga nadpisze pierwszą. Zdarza się
   * to, gdy cennik nie ma kolumny kodu i kod powstaje z nazwy, a nazwy się powtarzają.
   */
  const duplicateCodes = useMemo(() => {
    const seen = new Set<string>()
    const duplicated = new Set<string>()
    for (const row of rows) {
      const sku = row.sku.trim()
      if (sku === '') continue
      if (seen.has(sku)) duplicated.add(sku)
      else seen.add(sku)
    }
    return duplicated
  }, [rows])

  if (!open) return null

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/55 p-4"
      role="dialog"
      aria-modal="true"
      aria-labelledby="price-list-mapping-title"
      onClick={() => {
        if (!busy) onClose()
      }}
    >
      <div
        className="relative flex max-h-[92vh] w-full max-w-6xl flex-col overflow-hidden rounded-2xl bg-white shadow-xl"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="bg-gradient-to-r from-indigo-700 to-blue-600 px-5 py-4 text-white">
          <p id="price-list-mapping-title" className="text-base font-semibold">
            Mapowanie kolumn cennika
          </p>
          <p className="mt-1 text-xs text-indigo-100">
            Według tego mapowania plik zostanie odczytany. Popraw kolumnę, która wskazuje co innego,
            niż powinna — ręczna poprawka nie jest już zmieniana automatycznie.
          </p>
        </div>

        {busy && (
          <div className="absolute inset-0 z-10 flex flex-col items-center justify-center bg-white/90">
            <span className="inline-block h-8 w-8 animate-spin rounded-full border-4 border-indigo-600 border-t-transparent" />
            <p className="mt-3 text-sm font-semibold text-indigo-900">Czytam plik…</p>
          </div>
        )}

        <div className="min-h-0 flex-1 overflow-y-auto px-5 py-4 text-xs">
          {errors.length > 0 && (
            <ul className="mb-3 rounded border border-red-200 bg-red-50 px-4 py-2 text-red-800">
              {errors.slice(0, 5).map((e, i) => (
                <li key={i} className="list-disc">
                  {e}
                </li>
              ))}
            </ul>
          )}

          {sheets.map((s) => (
            <div key={s.sheet} className="mb-3 rounded-lg border border-slate-200 bg-slate-50/60 p-3">
              <div className="mb-2 flex flex-wrap items-center gap-3">
                <label className="flex items-center gap-1.5 text-sm font-semibold text-slate-800">
                  <input
                    type="checkbox"
                    checked={s.include}
                    onChange={(e) => onSetInclude(s.sheet, e.target.checked)}
                  />
                  {s.sheet}
                </label>
                <span className="text-[11px] text-slate-500">
                  nagłówek: wiersz {s.header_excel_row} · pewność {Math.round(s.confidence * 100)}%
                </span>
              </div>
              {s.include &&
                ((s.available_columns?.length ?? 0) > 0 ? (
                  <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                    {COLUMN_ROLES.map((role) => {
                      const value = s.columns[role.key]
                      const chosen =
                        typeof value === 'number'
                          ? s.available_columns?.find((c) => c.index === value)
                          : undefined
                      return (
                        <label key={role.key} className="block">
                          <span className="block text-[11px] font-medium text-slate-600">
                            {role.label}
                            {role.required && <span className="text-red-600"> *</span>}
                            {role.hint && (
                              <span className="font-normal text-slate-400"> — {role.hint}</span>
                            )}
                          </span>
                          <select
                            className="mt-0.5 w-full rounded border border-slate-300 bg-white px-2 py-1"
                            value={typeof value === 'number' ? String(value) : ''}
                            onChange={(e) =>
                              onSetColumn(
                                s.sheet,
                                role.key,
                                e.target.value === '' ? null : Number(e.target.value),
                              )
                            }
                          >
                            <option value="">— brak —</option>
                            {s.available_columns!.map((c) => (
                              <option key={c.index} value={c.index}>
                                {columnLetter(c.index)}
                                {c.label ? `: ${c.label}` : ''}
                              </option>
                            ))}
                          </select>
                          <span className="mt-0.5 block truncate text-[11px] text-slate-500">
                            {chosen?.sample ? `przykład: ${chosen.sample}` : ' '}
                          </span>
                          {role.key === 'sku' && typeof value !== 'number' && (
                            <span className="block text-[11px] text-amber-700">
                              bez kolumny kodu karty dostaną kod wyliczony z nazwy
                            </span>
                          )}
                          {role.key === 'name_extra' &&
                            typeof value === 'number' &&
                            value === s.columns.name && (
                              <span className="block text-[11px] text-amber-700">
                                ta sama kolumna co nazwa — nic się nie doklei
                              </span>
                            )}
                        </label>
                      )
                    })}
                  </div>
                ) : (
                  <p className="text-[11px] text-slate-500">
                    Kolumny tego arkusza wczytają się po odświeżeniu podglądu.
                  </p>
                ))}
            </div>
          ))}

          <div className="mb-2 flex flex-wrap items-center gap-2">
            <h3 className="text-sm font-semibold text-slate-800">
              Tak zostanie zaimportowane{' '}
              <span className="font-normal text-slate-500">
                (pierwsze {rows.length} z {productsFound})
              </span>
            </h3>
            {dirty && (
              <span className="rounded border border-amber-300 bg-amber-50 px-2 py-0.5 text-[11px] text-amber-900">
                Mapowanie zmienione — odczytuję plik na nowo…
              </span>
            )}
          </div>

          {duplicateCodes.size > 0 && (
            <p className="mb-2 rounded border border-amber-300 bg-amber-50 px-3 py-1.5 text-[11px] text-amber-900">
              Powtórzone kody w podglądzie ({duplicateCodes.size}) — pozycje o tym samym kodzie zapiszą
              się jako jedna karta. Wskaż kolumnę z kodem producenta albo dołóż drugą część nazwy.
            </p>
          )}
          <div className="overflow-x-auto rounded-lg border border-slate-200">
            <table className="w-full text-left">
              <thead className="sticky top-0 bg-slate-100">
                <tr className="border-b border-slate-200">
                  <th className="p-2 font-semibold">Lp.</th>
                  <th className="p-2 font-semibold">Kod / SKU</th>
                  <th className="p-2 font-semibold">Nazwa</th>
                  <th className="p-2 font-semibold">Grupa</th>
                  <th className="p-2 text-right font-semibold">Cena katalogowa</th>
                  <th className="p-2 font-semibold">Waluta</th>
                  <th className="p-2 text-right font-semibold">Upust %</th>
                  <th className="p-2 text-right font-semibold">Cena po upuście</th>
                  <th className="p-2 font-semibold">Opakowanie</th>
                </tr>
              </thead>
              <tbody>
                {rows.length === 0 && (
                  <tr>
                    <td className="p-3 text-slate-500" colSpan={9}>
                      Przy tym mapowaniu plik nie daje żadnej pozycji. Sprawdź kolumnę nazwy i ceny.
                    </td>
                  </tr>
                )}
                {rows.map((p, i) => {
                  const { discount, purchase } = effectiveFor(p)
                  return (
                    <tr key={`${p.sku}-${i}`} className="border-b border-slate-100 even:bg-slate-50/60">
                      <td className="p-2 text-slate-400">{i + 1}</td>
                      <td
                        className={`p-2 font-medium ${
                          duplicateCodes.has(p.sku.trim()) ? 'text-amber-700' : 'text-slate-800'
                        }`}
                        title={
                          duplicateCodes.has(p.sku.trim())
                            ? 'ten kod powtarza się w cenniku — pozycje zapiszą się jako jedna karta'
                            : undefined
                        }
                      >
                        {p.sku}
                      </td>
                      <td className="p-2">{p.name}</td>
                      <td className="p-2 text-slate-600">{p.category ?? '—'}</td>
                      <td className="p-2 text-right tabular-nums">
                        {p.catalog_price_net.toFixed(2)}
                      </td>
                      <td className="p-2 text-slate-600">{p.currency ?? defaultCurrency}</td>
                      <td className="p-2 text-right tabular-nums">{discount}%</td>
                      <td className="p-2 text-right font-medium tabular-nums text-slate-900">
                        {purchase.toFixed(2)}
                      </td>
                      <td className="p-2 text-slate-600">
                        {p.packaging ?? (p.pack_qty != null ? String(p.pack_qty) : '—')}
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        </div>

        <div className="flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 px-5 py-3 text-xs">
          <span className="text-slate-600">
            Do importu: <span className="font-semibold text-blue-700">{productsFound}</span> pozycji
            {' · '}przeskanowano {rowsTotal} wierszy{' · '}pominięto {skipped}
          </span>
          <div className="flex flex-wrap items-center gap-2">
            <button
              type="button"
              className="rounded border border-slate-300 px-3 py-2 font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-50"
              onClick={onRefresh}
              disabled={busy}
            >
              Odśwież podgląd
            </button>
            <button
              type="button"
              className="rounded bg-indigo-600 px-3 py-2 font-semibold text-white hover:bg-indigo-700 disabled:opacity-50"
              onClick={onClose}
              disabled={busy || dirty}
              title={dirty ? 'Najpierw odśwież podgląd' : undefined}
            >
              Gotowe
            </button>
          </div>
        </div>
      </div>
    </div>
  )
}
