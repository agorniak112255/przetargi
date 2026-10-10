import type { SupplierSpecial } from './api'
import { currencyLabel, formatPct, formatPrice } from './priceChange'

/** Wniosek, nie potwierdzenie — dostawca potwierdza ceny specjalne mailem, którego system nie widzi. */
export const SUPPLIER_SPECIAL_INFERENCE_NOTE =
  'Wykryta z porównania z cennikiem bazowym dostawcy — potwierdzenie dostawca wysyła mailem.'

/** Cennik z pliku podaje obie ceny wprost (SECURA: kolumna 40% i kolumna 21%). */
export const FILE_SPECIAL_NOTE = 'Z cennika dostawcy: cena specjalna porównana z ceną normalną z tego samego pliku.'

export type SupplierSpecialSource = 'b2b' | 'file'

/** Podpisy zależnie od źródła oceny: konto B2B albo cennik z pliku. */
export function supplierSpecialLabels(source: SupplierSpecialSource | null | undefined) {
  if (source === 'file') {
    return {
      badge: 'Cena specjalna dostawcy',
      purchase: 'Cena z cennika',
      base: 'Cena katalogowa',
      note: FILE_SPECIAL_NOTE,
      worseHint: 'sprawdź kolumny cen w cenniku z pliku',
    }
  }
  return {
    badge: 'Cena specjalna B2B',
    purchase: 'Cena konta',
    base: 'Cennik bazowy',
    note: SUPPLIER_SPECIAL_INFERENCE_NOTE,
    worseHint: 'sprawdź arkusz cennika bazowego i rabat standardowy w Cenniki B2B → Rabaty',
  }
}

export function supplierSpecialSummary(s: SupplierSpecial, currency: string | null | undefined): string {
  const cur = currencyLabel(currency)
  const labels = supplierSpecialLabels(s.source)
  const basis =
    s.base_price != null && s.standard_discount_percent != null
      ? ` = ${formatPrice(s.base_price)} ${cur} − ${formatPct(s.standard_discount_percent, false)}`
      : ''
  const standard = `Cena normalna${s.category ? ` (${s.category})` : ''}: ${formatPrice(s.standard_price)} ${cur}${basis}`
  const actual = `Rabat faktyczny od ${s.source === 'file' ? 'ceny katalogowej' : 'cennika bazowego'}: ${formatPct(s.actual_discount_percent, false)}`
  if (s.status === 'special') {
    return [
      labels.badge,
      standard,
      actual,
      `Taniej o ${formatPrice(s.saving_net)} ${cur} od ceny standardowej`,
      labels.note,
    ].join('\n')
  }
  if (s.status === 'worse_than_standard') {
    return [
      `${labels.purchase} powyżej ceny standardowej`,
      standard,
      actual,
      `Drożej o ${formatPrice(Math.abs(s.saving_net))} ${cur} — ${labels.worseHint}`,
    ].join('\n')
  }
  return [standard, actual].join('\n')
}
