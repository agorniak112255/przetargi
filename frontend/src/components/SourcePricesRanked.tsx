import type { Product } from '../lib/api'
import { currencyLabel, formatPct, formatPrice, pctClass } from '../lib/priceChange'
import { sortSourcePrices } from '../lib/sourcePrices'
import { SlotOrderQuantity } from './OrderQuantityBadge'

/**
 * Zwarta lista cen wszystkich źródeł karty od najtańszej — do okna weryfikacji karty, gdzie pełnej tabeli z karty
 * wyrobu nie ma miejsca. Cena karty dalej to cena obowiązująca (pierwszeństwo producenta); tańsze źródło to informacja.
 */
export function SourcePricesRanked({ product }: { product: Product }) {
  const slots = product.source_prices ?? []
  if (slots.length === 0) return null
  const cardCurrency = product.currency ?? 'PLN'

  return (
    <div className="rounded border border-slate-200 bg-white text-xs">
      <div className="border-b border-slate-100 px-2 py-1 font-semibold text-slate-700">
        Ceny zakupu u dostawców — od najtańszej
      </div>
      <ul>
        {sortSourcePrices(slots).map((s) => {
          const currency = s.currency ?? cardCurrency
          const foreign = currency.toUpperCase() !== 'PLN'
          const outside = s.comparable === false
          return (
            <li
              key={s.source_key}
              className={`flex items-start justify-between gap-2 border-b border-slate-100 px-2 py-1 last:border-b-0 ${
                s.is_cheapest ? 'bg-emerald-50' : ''
              } ${outside ? 'text-slate-500' : 'text-slate-800'}`}
            >
              <div className="min-w-0">
                <div className="truncate" title={s.source_key}>
                  {s.source_label}
                </div>
                <div className="flex flex-wrap gap-1">
                  {s.is_effective && (
                    <span className="rounded-full bg-emerald-100 px-1.5 text-[10px] font-medium text-emerald-800">
                      obowiązuje
                    </span>
                  )}
                  {s.is_cheapest && (
                    <span className="rounded-full bg-emerald-600 px-1.5 text-[10px] font-medium text-white">najtaniej</span>
                  )}
                  {outside && s.not_comparable_reason && (
                    <span className="text-[10px] text-slate-500">{s.not_comparable_reason}</span>
                  )}
                  <SlotOrderQuantity slot={s} />
                </div>
              </div>
              <div className="shrink-0 text-right tabular-nums">
                <b>
                  {formatPrice(s.purchase_price)} {currencyLabel(currency)}
                </b>
                {foreign && s.purchase_price_pln != null && (
                  <span className="block text-[10px] text-slate-500">≈ {formatPrice(s.purchase_price_pln)} zł</span>
                )}
                {!s.is_effective && s.diff_to_effective_pct != null && (
                  <span className={`block text-[10px] ${outside ? 'text-slate-400' : pctClass(s.diff_to_effective_pct)}`}>
                    {formatPct(s.diff_to_effective_pct)}
                  </span>
                )}
              </div>
            </li>
          )
        })}
      </ul>
    </div>
  )
}
