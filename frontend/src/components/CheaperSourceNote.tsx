import type { CheaperSource } from '../lib/api'
import { formatPct, formatPrice } from '../lib/priceChange'

/**
 * „taniej u P4S: 7,90 zł (−9%)” — tańsze porównywalne źródło niż cena obowiązująca karty.
 * Tylko informacja: cena karty i cena oferty dalej liczone od ceny obowiązującej (pierwszeństwo producenta),
 * stąd pełne wyjaśnienie w podpowiedzi. Brak pola (starsze API) albo null — nic.
 */
export function CheaperSourceNote({
  cheaper,
  className = '',
}: {
  cheaper: CheaperSource | null | undefined
  className?: string
}) {
  if (!cheaper) return null
  const price = `${formatPrice(cheaper.purchase_price_pln)} zł`
  const title =
    `Taniej u ${cheaper.label}: cena zakupu netto ${price} (${formatPct(cheaper.diff_pct)} względem obowiązującej ` +
    'ceny zakupu, przeliczone na złote po kursie NBP). Karta produktu nadal liczy z obowiązującej ceny ' +
    '(pierwszeństwo ma cennik producenta) — wszystkie źródła porównasz na karcie produktu, w tabeli „Ceny ze źródeł”.'
  return (
    <span className={`block text-[11px] text-emerald-700 ${className}`} title={title}>
      taniej u {cheaper.label}: <b className="tabular-nums">{price}</b> ({formatPct(cheaper.diff_pct)})
    </span>
  )
}
