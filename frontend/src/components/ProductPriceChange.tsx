import type { ProductPriceChange } from '../lib/api'
import {
  currencyLabel,
  formatDate,
  formatPct,
  formatPrice,
  isFlatPct,
  isRecentPriceChange,
  pctClass,
  priceChangeSummary,
} from '../lib/priceChange'

/** Kompaktowa adnotacja do komórki ceny na liście: „↑ 8,7% · 15.09.2026”. */
export function PriceChangeNote({
  change,
  currency,
}: {
  change: ProductPriceChange
  currency: string | null | undefined
}) {
  const pct = change.pct
  const arrow = pct === null ? '•' : isFlatPct(pct) ? '→' : pct > 0 ? '↑' : '↓'
  return (
    <span
      className={`mt-0.5 block text-[10px] tabular-nums ${pctClass(pct)} ${isRecentPriceChange(change.at) ? '' : 'opacity-60'}`}
      title={priceChangeSummary(change, currency)}
    >
      {arrow} {pct === null ? 'zmiana' : formatPct(pct, false)}
      {change.pct_basis === 'catalog' ? ' kat.' : ''} · {formatDate(change.at)}
    </span>
  )
}

/** „36,72 → 39,90 zł (+8,7%)”; bez poprzedniej wartości sama nowa cena. */
export function PriceStep({
  oldValue,
  newValue,
  pct,
  currency,
}: {
  oldValue: number | string | null
  newValue: number | string | null
  pct: number | null
  currency: string | null | undefined
}) {
  const cur = currencyLabel(currency)
  if (oldValue === null) {
    return (
      <span className="tabular-nums">
        {formatPrice(newValue)} {newValue !== null ? cur : ''}
      </span>
    )
  }
  return (
    <span className="tabular-nums">
      <span className="text-slate-500">{formatPrice(oldValue)}</span> → {formatPrice(newValue)} {cur}
      {pct !== null && <span className={`ml-1 ${pctClass(pct)}`}>({formatPct(pct)})</span>}
    </span>
  )
}
