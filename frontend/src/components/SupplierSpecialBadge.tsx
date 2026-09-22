import type { SupplierSpecial } from '../lib/api'
import { currencyLabel, formatPct, formatPrice } from '../lib/priceChange'
import { supplierSpecialSummary } from '../lib/supplierSpecial'

/**
 * Ocena ceny konta B2B względem cennika bazowego dostawcy. „special” — wyraźna plakietka,
 * „worse_than_standard” — dyskretna uwaga, „standard” i brak oceny — nic.
 */
export function SupplierSpecialBadge({
  special,
  currency,
  className = '',
  block = false,
}: {
  special: SupplierSpecial | null | undefined
  currency: string | null | undefined
  className?: string
  /** Osobna linia pod ceną (komórka listy). */
  block?: boolean
}) {
  if (!special || special.status === 'standard') return null
  const title = supplierSpecialSummary(special, currency)
  if (special.status === 'special') {
    const badge = (
      <span
        className={`${block ? 'block w-fit' : 'inline-block'} whitespace-nowrap rounded bg-emerald-600 px-1.5 py-0.5 text-[10px] font-semibold text-white ${block ? '' : className}`}
        title={title}
      >
        Cena specjalna B2B
      </span>
    )
    if (!block) return badge
    // w komórce listy: pod znacznikiem cena normalna kategorii (cennik bazowy − rabat standardowy)
    return (
      <span className={`block ${className}`} title={title}>
        {badge}
        <span className="block whitespace-nowrap text-[10px] text-slate-600">
          normalna: <b>{formatPrice(special.standard_price)} {currencyLabel(currency)}</b>
          {special.standard_discount_percent != null && <> (−{formatPct(special.standard_discount_percent, false)})</>}
        </span>
        {special.category && <span className="block whitespace-nowrap text-[10px] text-slate-500">{special.category}</span>}
      </span>
    )
  }
  return (
    <span className={`${block ? 'block w-fit' : 'inline-block'} whitespace-nowrap text-[10px] text-amber-700 ${className}`} title={title}>
      ▲ powyżej ceny std.
    </span>
  )
}
