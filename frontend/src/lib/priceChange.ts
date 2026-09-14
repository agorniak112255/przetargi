import type { ProductPriceChange } from './api'

export const RECENT_PRICE_CHANGE_DAYS = 30

export function currencyLabel(currency: string | null | undefined): string {
  const c = (currency ?? '').trim().toUpperCase()
  return c === '' || c === 'PLN' ? 'zł' : c
}

export function formatPrice(value: number | string | null | undefined): string {
  if (value === null || value === undefined || value === '') return '—'
  const n = Number(value)
  return Number.isFinite(n)
    ? n.toLocaleString('pl-PL', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
    : String(value)
}

export function formatPct(pct: number, signed = true): string {
  const abs = Math.abs(pct).toLocaleString('pl-PL', { maximumFractionDigits: 1 })
  if (!signed) return `${abs}%`
  return `${pct > 0 ? '+' : pct < 0 ? '−' : '±'}${abs}%`
}

export function isFlatPct(pct: number | null): boolean {
  return pct === null || Math.round(pct * 10) === 0
}

export function pctClass(pct: number | null): string {
  if (pct === null || isFlatPct(pct)) return 'text-slate-500'
  return pct > 0 ? 'text-red-600' : 'text-emerald-700'
}

export function formatDate(iso: string): string {
  return new Date(iso).toLocaleDateString('pl-PL', { day: '2-digit', month: '2-digit', year: 'numeric' })
}

export function formatDateTime(iso: string): string {
  const d = new Date(iso)
  return `${formatDate(iso)} ${d.toLocaleTimeString('pl-PL', { hour: '2-digit', minute: '2-digit' })}`
}

export function isRecentPriceChange(iso: string): boolean {
  return Date.now() - new Date(iso).getTime() <= RECENT_PRICE_CHANGE_DAYS * 24 * 3600 * 1000
}

/** „1 wersja”, „3 wersje”, „25 wersji”, „44 wersje”. */
export function variantCountLabel(n: number): string {
  const mod10 = n % 10
  const mod100 = n % 100
  const word =
    n === 1 ? 'wersja' : mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14) ? 'wersje' : 'wersji'
  return `${n.toLocaleString('pl-PL')} ${word}`
}

/** Cena karty z wersjami na liście: „od 0,97 zł · 44 wersje”; bez porównywalnej ceny sama liczba wersji. */
export function variantsFromLabel(
  count: number,
  minPrice: string | null | undefined,
  currency: string | null | undefined,
): string {
  const versions = variantCountLabel(count)
  return minPrice ? `od ${formatPrice(minPrice)} ${currencyLabel(currency)} · ${versions}` : versions
}

/** „Anro B2B: zakup 36,72 → 39,90 zł, katalog 40,80 → 40,80 zł · 15.09.2026 02:14” */
export function priceChangeSummary(change: ProductPriceChange, currency: string | null | undefined): string {
  const cur = currencyLabel(currency)
  return (
    `${change.source_label}: zakup ${formatPrice(change.purchase_old)} → ${formatPrice(change.purchase_new)} ${cur}, ` +
    `katalog ${formatPrice(change.catalog_old)} → ${formatPrice(change.catalog_new)} ${cur} · ${formatDateTime(change.at)}`
  )
}
