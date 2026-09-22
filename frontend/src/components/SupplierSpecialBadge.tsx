import type { SupplierSpecial } from '../lib/api'
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
    return (
      <span
        className={`${block ? 'block w-fit' : 'inline-block'} whitespace-nowrap rounded bg-emerald-600 px-1.5 py-0.5 text-[10px] font-semibold text-white ${className}`}
        title={title}
      >
        Cena specjalna B2B
      </span>
    )
  }
  return (
    <span className={`${block ? 'block w-fit' : 'inline-block'} whitespace-nowrap text-[10px] text-amber-700 ${className}`} title={title}>
      ▲ powyżej ceny std.
    </span>
  )
}
