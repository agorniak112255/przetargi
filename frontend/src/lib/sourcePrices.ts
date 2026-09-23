import type { ProductSourcePrice } from './api'

/**
 * Kolejność cen źródeł: porównywalne od najtańszej (price_rank z backendu), potem pozostałe w kolejności z API.
 * Starsza odpowiedź bez price_rank — kolejność z API bez zmian. Wspólna dla karty wyrobu i okna weryfikacji karty.
 */
export function sortSourcePrices(slots: ProductSourcePrice[]): ProductSourcePrice[] {
  const ranked = (s: ProductSourcePrice) => s.comparable === true && s.price_rank != null
  return slots
    .map((s, i) => ({ s, i }))
    .sort((a, b) => {
      const ra = ranked(a.s)
      const rb = ranked(b.s)
      if (ra && rb) return a.s.price_rank! - b.s.price_rank! || a.i - b.i
      if (ra !== rb) return ra ? -1 : 1
      return a.i - b.i
    })
    .map(({ s }) => s)
}
