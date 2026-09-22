import type { SupplierSpecial } from './api'
import { currencyLabel, formatPct, formatPrice } from './priceChange'

/** Wniosek, nie potwierdzenie — dostawca potwierdza ceny specjalne mailem, którego system nie widzi. */
export const SUPPLIER_SPECIAL_INFERENCE_NOTE =
  'Wykryta z porównania z cennikiem bazowym dostawcy — potwierdzenie dostawca wysyła mailem.'

export function supplierSpecialSummary(s: SupplierSpecial, currency: string | null | undefined): string {
  const cur = currencyLabel(currency)
  const basis =
    s.base_price != null && s.standard_discount_percent != null
      ? ` = ${formatPrice(s.base_price)} ${cur} − ${formatPct(s.standard_discount_percent, false)}`
      : ''
  const standard = `Cena normalna${s.category ? ` (${s.category})` : ''}: ${formatPrice(s.standard_price)} ${cur}${basis}`
  const actual = `Rabat faktyczny od cennika bazowego: ${formatPct(s.actual_discount_percent, false)}`
  if (s.status === 'special') {
    return [
      'Cena specjalna B2B',
      standard,
      actual,
      `Taniej o ${formatPrice(s.saving_net)} ${cur} od ceny standardowej`,
      SUPPLIER_SPECIAL_INFERENCE_NOTE,
    ].join('\n')
  }
  if (s.status === 'worse_than_standard') {
    return [
      'Cena konta powyżej ceny standardowej',
      standard,
      actual,
      `Drożej o ${formatPrice(Math.abs(s.saving_net))} ${cur} — sprawdź arkusz cennika bazowego i rabat standardowy w Cenniki B2B → Rabaty`,
    ].join('\n')
  }
  return [standard, actual].join('\n')
}
