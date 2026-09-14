import { Fragment, useMemo, useState, type MouseEvent } from 'react'
import { PriceStep } from './ProductPriceChange'
import { api, type ProductVariant, type ProductVariantPriceHistoryRow, type ProductVariants } from '../lib/api'
import {
  currencyLabel,
  formatDate,
  formatDateTime,
  formatPct,
  formatPrice,
  isFlatPct,
  pctClass,
} from '../lib/priceChange'

type PriceSort = 'none' | 'asc' | 'desc'

type HistoryState =
  | { status: 'loading' }
  | { status: 'error'; message: string }
  | { status: 'ok'; rows: ProductVariantPriceHistoryRow[] }

/** Cena 0 albo brak = wersja bez ceny (jak na karcie). */
function priceValue(v: ProductVariant): number | null {
  if (v.purchase_price === null) return null
  const n = Number(v.purchase_price)
  return Number.isFinite(n) && n > 0 ? n : null
}

/** Sekcja „Wersje (n)” karty: filtry po członach wersji, sortowanie po cenie, historia ceny po kliknięciu wiersza. */
export function ProductVariantsTable({ productId, variants }: { productId: number; variants: ProductVariants }) {
  const [filters, setFilters] = useState<Record<string, string>>({})
  const [search, setSearch] = useState('')
  const [sort, setSort] = useState<PriceSort>('none')
  const [showRemoved, setShowRemoved] = useState(false)
  const [expanded, setExpanded] = useState<number | null>(null)
  const [history, setHistory] = useState<Record<number, HistoryState>>({})
  const [copied, setCopied] = useState<number | null>(null)

  const dims = variants.dimensions
  const removedCount = variants.count - variants.active_count

  const pool = useMemo(
    () => (showRemoved ? variants.items : variants.items.filter((v) => v.removed_at === null)),
    [variants.items, showRemoved],
  )

  const options = useMemo(() => {
    const out: Record<string, string[]> = {}
    for (const dim of dims) {
      const seen: string[] = []
      for (const v of pool) {
        const value = v.attributes?.[dim]
        if (value !== undefined && value !== '' && !seen.includes(value)) seen.push(value)
      }
      out[dim] = seen
    }
    return out
  }, [dims, pool])

  const rows = useMemo(() => {
    const needle = search.trim().toLocaleLowerCase('pl-PL')
    const filtered = pool.filter((v) => {
      for (const dim of dims) {
        const wanted = filters[dim] ?? ''
        if (wanted !== '' && v.attributes?.[dim] !== wanted) return false
      }
      return needle === '' || v.label.toLocaleLowerCase('pl-PL').includes(needle)
    })
    if (sort === 'none') return filtered
    // Bez ceny zawsze na końcu, niezależnie od kierunku.
    return [...filtered].sort((a, b) => {
      const pa = priceValue(a)
      const pb = priceValue(b)
      if (pa === null && pb === null) return 0
      if (pa === null) return 1
      if (pb === null) return -1
      return sort === 'asc' ? pa - pb : pb - pa
    })
  }, [pool, dims, filters, search, sort])

  const columnCount = Math.max(1, dims.length) + 6
  const filtersActive = search.trim() !== '' || Object.values(filters).some((f) => f !== '')

  async function toggleRow(v: ProductVariant) {
    if (expanded === v.id) {
      setExpanded(null)
      return
    }
    setExpanded(v.id)
    const current = history[v.id]
    if (current?.status === 'ok' || current?.status === 'loading') return
    setHistory((h) => ({ ...h, [v.id]: { status: 'loading' } }))
    try {
      const res = await api<{ data: ProductVariantPriceHistoryRow[] }>(
        `/products/${productId}/variants/${v.id}/price-history`,
      )
      setHistory((h) => ({ ...h, [v.id]: { status: 'ok', rows: res.data ?? [] } }))
    } catch (ex) {
      setHistory((h) => ({
        ...h,
        [v.id]: { status: 'error', message: ex instanceof Error ? ex.message : 'Nie udało się pobrać historii ceny' },
      }))
    }
  }

  function copyPrice(e: MouseEvent, v: ProductVariant) {
    e.stopPropagation()
    const price = priceValue(v)
    if (price === null) return
    void navigator.clipboard
      .writeText(price.toFixed(2).replace('.', ','))
      .then(() => {
        setCopied(v.id)
        window.setTimeout(() => setCopied((c) => (c === v.id ? null : c)), 1500)
      })
      .catch(() => undefined)
  }

  function cycleSort() {
    setSort((s) => (s === 'none' ? 'asc' : s === 'asc' ? 'desc' : 'none'))
  }

  return (
    <div className="mb-4 rounded-xl bg-white p-4 shadow-sm">
      <div className="mb-2 flex flex-wrap items-baseline justify-between gap-2">
        <h2 className="text-sm font-semibold">Wersje ({variants.active_count})</h2>
        <p className="text-[11px] text-slate-500">
          {variants.source_label ? `Ceny konta ${variants.source_label} · ` : ''}kliknij wiersz, aby zobaczyć historię ceny
        </p>
      </div>

      <div className="mb-2 flex flex-wrap items-end gap-2 text-xs">
        {dims.map((dim) => (
          <label key={dim} className="flex flex-col gap-0.5">
            <span className="text-[11px] font-medium text-slate-600">{dim}</span>
            <select
              className="max-w-[16rem] rounded border border-slate-300 bg-white px-2 py-1 text-xs"
              value={filters[dim] ?? ''}
              onChange={(e) => setFilters((f) => ({ ...f, [dim]: e.target.value }))}
            >
              <option value="">wszystkie ({options[dim]?.length ?? 0})</option>
              {(options[dim] ?? []).map((value) => (
                <option key={value} value={value}>
                  {value}
                </option>
              ))}
            </select>
          </label>
        ))}
        <label className="flex flex-col gap-0.5">
          <span className="text-[11px] font-medium text-slate-600">Szukaj</span>
          <input
            type="search"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="w nazwie wersji"
            className="w-48 rounded border border-slate-300 px-2 py-1 text-xs"
          />
        </label>
        {filtersActive && (
          <button
            type="button"
            onClick={() => {
              setFilters({})
              setSearch('')
            }}
            className="rounded border border-slate-300 px-2 py-1 text-xs hover:bg-slate-50"
          >
            Wyczyść filtry
          </button>
        )}
        {removedCount > 0 && (
          <label className="flex items-center gap-1 py-1 text-slate-600">
            <input type="checkbox" checked={showRemoved} onChange={(e) => setShowRemoved(e.target.checked)} />
            pokaż wycofane ({removedCount})
          </label>
        )}
        <span className="ml-auto py-1 text-[11px] text-slate-500">
          Pokazano {rows.length} z {pool.length}
        </span>
      </div>

      <div className="overflow-x-auto">
        <table className="w-full text-left text-xs">
          <thead>
            <tr className="border-b bg-slate-50">
              {dims.length > 0 ? (
                dims.map((dim) => (
                  <th key={dim} className="p-2">
                    {dim}
                  </th>
                ))
              ) : (
                <th className="p-2">Wersja</th>
              )}
              <th className="p-2 text-right">
                <button
                  type="button"
                  onClick={cycleSort}
                  className="font-semibold hover:text-blue-700"
                  title="Sortuj po cenie (rosnąco / malejąco / kolejność dostawcy)"
                >
                  Cena konta netto{sort === 'asc' ? ' ▲' : sort === 'desc' ? ' ▼' : ''}
                </button>
              </th>
              <th className="p-2 text-right">VAT</th>
              <th className="p-2">Jedn.</th>
              <th className="p-2">Sprawdzono</th>
              <th className="p-2 text-right">Zmiana</th>
              <th className="p-2">
                <span className="sr-only">Link</span>
              </th>
            </tr>
          </thead>
          <tbody>
            {rows.map((v) => {
              const removed = v.removed_at !== null
              const open = expanded === v.id
              const price = priceValue(v)
              const hasAttributes = Object.keys(v.attributes ?? {}).length > 0
              const removedBadge = removed && (
                <span
                  className="ml-1 rounded bg-slate-100 px-1 text-[10px] text-slate-500"
                  title={v.removed_at ? `Brak u dostawcy od ${formatDateTime(v.removed_at)}` : undefined}
                >
                  wycofana
                </span>
              )
              return (
                <Fragment key={v.id}>
                  <tr
                    onClick={() => void toggleRow(v)}
                    aria-expanded={open}
                    title={v.label}
                    className={`cursor-pointer border-b hover:bg-slate-50 ${removed ? 'text-slate-400' : ''} ${open ? 'bg-blue-50/40' : ''}`}
                  >
                    {dims.length === 0 || !hasAttributes ? (
                      <td className="p-2" colSpan={Math.max(1, dims.length)}>
                        {v.label}
                        {removedBadge}
                      </td>
                    ) : (
                      dims.map((dim, i) => (
                        <td key={dim} className="p-2">
                          {v.attributes[dim] ?? '—'}
                          {i === 0 && removedBadge}
                        </td>
                      ))
                    )}
                    <td className="whitespace-nowrap p-2 text-right tabular-nums">
                      {price !== null ? (
                        <>
                          <b className={removed ? 'font-normal' : ''}>
                            {formatPrice(v.purchase_price)} {currencyLabel(v.currency)}
                          </b>
                          <button
                            type="button"
                            onClick={(e) => copyPrice(e, v)}
                            className="ml-1 rounded border border-slate-200 px-1 text-[10px] text-slate-500 hover:bg-slate-100"
                            title="Kopiuj cenę do schowka"
                          >
                            {copied === v.id ? 'skopiowano' : 'kopiuj'}
                          </button>
                        </>
                      ) : (
                        <span className="text-slate-400">brak ceny</span>
                      )}
                      {v.list_price_net !== null && (
                        <span className="block text-[10px] text-slate-500">
                          katalog {formatPrice(v.list_price_net)} {currencyLabel(v.currency)}
                        </span>
                      )}
                    </td>
                    <td className="whitespace-nowrap p-2 text-right tabular-nums">
                      {v.vat_rate !== null ? `${v.vat_rate.toLocaleString('pl-PL')}%` : '—'}
                    </td>
                    <td className="whitespace-nowrap p-2">{v.unit ?? '—'}</td>
                    <td
                      className="whitespace-nowrap p-2 tabular-nums"
                      title={v.price_checked_at ? formatDateTime(v.price_checked_at) : undefined}
                    >
                      {v.price_checked_at ? formatDate(v.price_checked_at) : '—'}
                    </td>
                    <td className="whitespace-nowrap p-2 text-right">
                      <VariantChange variant={v} />
                    </td>
                    <td className="p-2">
                      {v.source_url && (
                        <a
                          href={v.source_url}
                          target="_blank"
                          rel="noreferrer"
                          onClick={(e) => e.stopPropagation()}
                          className="text-blue-700 hover:underline"
                          title="Otwórz wersję u dostawcy"
                        >
                          ↗
                        </a>
                      )}
                    </td>
                  </tr>
                  {open && (
                    <tr className="border-b">
                      <td colSpan={columnCount} className="bg-slate-50 px-3 py-2">
                        <VariantHistory state={history[v.id]} currency={v.currency} />
                      </td>
                    </tr>
                  )}
                </Fragment>
              )
            })}
            {rows.length === 0 && (
              <tr>
                <td colSpan={columnCount} className="p-2 text-slate-400">
                  {pool.length === 0 ? 'Brak aktywnych wersji.' : 'Brak wersji pasujących do filtrów.'}
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  )
}

/** „↑ 2,1%” z podpowiedzią „0,95 → 0,97 zł · 10.09.2026 02:00”. */
function VariantChange({ variant }: { variant: ProductVariant }) {
  const change = variant.last_price_change
  if (!change) return <span className="text-slate-300">—</span>
  const pct = change.pct
  const arrow = pct === null ? '•' : isFlatPct(pct) ? '→' : pct > 0 ? '↑' : '↓'
  const cur = currencyLabel(variant.currency)
  return (
    <span
      className={`tabular-nums ${pctClass(pct)}`}
      title={`${formatPrice(change.purchase_old)} → ${formatPrice(change.purchase_new)} ${cur}${change.at ? ` · ${formatDateTime(change.at)}` : ''}`}
    >
      {arrow} {pct === null ? 'zmiana' : formatPct(pct, false)}
    </span>
  )
}

function VariantHistory({ state, currency }: { state: HistoryState | undefined; currency: string | null }) {
  if (!state || state.status === 'loading') return <p className="text-slate-500">Ładowanie historii ceny…</p>
  if (state.status === 'error') return <p className="text-red-700">{state.message}</p>
  if (state.rows.length === 0) return <p className="text-slate-500">Brak historii ceny tej wersji.</p>
  return (
    <table className="text-left text-[11px]">
      <thead className="text-slate-500">
        <tr>
          <th className="py-0.5 pr-4 font-medium">Data</th>
          <th className="py-0.5 pr-4 font-medium">Źródło</th>
          <th className="py-0.5 font-medium">Cena konta netto</th>
        </tr>
      </thead>
      <tbody className="text-slate-700">
        {state.rows.map((h, i) => {
          const first = i === state.rows.length - 1 && h.purchase_old === null
          return (
            <tr key={h.id}>
              <td className="whitespace-nowrap py-0.5 pr-4 tabular-nums">{formatDateTime(h.created_at)}</td>
              <td className="py-0.5 pr-4" title={h.source}>
                {h.source_label}
                {first && <span className="ml-1 text-slate-400">(dodanie ceny)</span>}
              </td>
              <td className="whitespace-nowrap py-0.5">
                <PriceStep
                  oldValue={h.purchase_old}
                  newValue={h.purchase_price}
                  pct={h.purchase_pct}
                  currency={h.currency ?? currency}
                />
              </td>
            </tr>
          )
        })}
      </tbody>
    </table>
  )
}
