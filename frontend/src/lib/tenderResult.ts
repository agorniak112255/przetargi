import type { LossReason, LotOutcome, TenderResultStatus } from './api'

/** Wynik przetargu w całości (tenders.result_status) — w zdaniu „Wynik: …”. */
export const RESULT_STATUS_LABEL: Record<TenderResultStatus, string> = {
  won: 'wygrany',
  partial: 'częściowo wygrany',
  lost: 'przegrany',
  cancelled: 'unieważniony',
  not_submitted: 'nie złożyliśmy oferty',
}

/** Kolory znacznika wyniku przetargu (te same odcienie co znaczniki statusu w aplikacji). */
export const RESULT_STATUS_CLASS: Record<TenderResultStatus, string> = {
  won: 'border-emerald-200 bg-emerald-50 text-emerald-800',
  partial: 'border-amber-200 bg-amber-50 text-amber-800',
  lost: 'border-red-200 bg-red-50 text-red-700',
  cancelled: 'border-slate-200 bg-slate-100 text-slate-700',
  not_submitted: 'border-slate-200 bg-slate-100 text-slate-700',
}

/** Znacznik dla przetargu bez wpisanego wyniku. */
export const NO_RESULT_CLASS = 'border-slate-200 bg-white text-slate-500'

/** Wynik części zamówienia. */
export const LOT_OUTCOME_LABEL: Record<LotOutcome, string> = {
  won: 'wygrana',
  lost: 'przegrana',
  cancelled: 'unieważniona',
  not_submitted: 'nie złożyliśmy oferty',
}

export const LOT_OUTCOME_CLASS: Record<LotOutcome, string> = {
  won: 'border-emerald-200 bg-emerald-50 text-emerald-800',
  lost: 'border-red-200 bg-red-50 text-red-700',
  cancelled: 'border-slate-200 bg-slate-100 text-slate-700',
  not_submitted: 'border-slate-200 bg-slate-100 text-slate-700',
}

export const LOT_OUTCOMES: LotOutcome[] = ['won', 'lost', 'cancelled', 'not_submitted']

export const LOSS_REASON_LABEL: Record<LossReason, string> = {
  price: 'Cena',
  requirement: 'Nie spełniliśmy wymagania',
  delivery: 'Termin dostawy',
  formal: 'Błąd formalny',
  other: 'Inny',
}

export const LOSS_REASONS: LossReason[] = ['price', 'requirement', 'delivery', 'formal', 'other']

/** Pola części słowami — znaczniki „z Biuletynu”, „wpisane ręcznie” i historia zmian. */
export const LOT_FIELD_LABEL: Record<string, string> = {
  name: 'nazwa części',
  cpv_main: 'kod rodzaju zamówienia (CPV)',
  estimated_value: 'wartość części',
  our_net: 'nasza cena netto',
  our_vat_rate: 'stawka VAT',
  outcome: 'wynik',
  winner: 'wygrała firma',
  winner_price: 'cena zwycięzcy',
  currency: 'waluta',
  offers_count: 'liczba ofert',
  lowest_price: 'najniższa cena',
  highest_price: 'najwyższa cena',
  loss_reason: 'powód przegranej',
  note: 'notatka',
  lot_no: 'numer części potwierdzony',
}

export function resultStatusLabel(status: TenderResultStatus | null | undefined): string {
  return status ? RESULT_STATUS_LABEL[status] ?? status : 'bez wyniku'
}

/** „74 310,00 zł” albo „1 000,00 EUR”; pusty tekst bez kwoty. */
export function formatMoney(value: string | number | null | undefined, currency = 'PLN'): string {
  if (value === null || value === undefined || value === '') return ''
  const n = Number(value)
  if (!Number.isFinite(n)) return String(value)
  const amount = n.toLocaleString('pl-PL', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
  return `${amount} ${currency === 'PLN' ? 'zł' : currency}`
}

/** Stawka VAT bez zbędnych zer: „23”, „5,5”. */
export function formatVat(value: string | number | null | undefined): string {
  if (value === null || value === undefined || value === '') return ''
  const n = Number(value)
  return Number.isFinite(n) ? n.toLocaleString('pl-PL', { maximumFractionDigits: 2 }) : String(value)
}

/**
 * Kwota wpisana przez człowieka („74 310,00”, „74310.5”) → „74310.00”; null dla pustej; undefined, gdy nie da
 * się jej odczytać (bez zgadywania — formularz pokazuje błąd). Najwyżej dwa miejsca po przecinku.
 */
export function parseAmountInput(text: string): string | null | undefined {
  const compact = text.replace(/[\s ]/g, '').replace(',', '.')
  if (compact === '') return null
  const m = /^(\d{1,12})(?:\.(\d{1,2}))?$/.exec(compact)
  if (!m) return undefined
  return `${m[1]}.${(m[2] ?? '').padEnd(2, '0')}`
}

/** Kwota z API („74310.00”) do pola formularza („74310,00”). */
export function amountToInput(value: string | null | undefined): string {
  return value ? value.replace('.', ',') : ''
}

/**
 * Brutto z netto i VAT w groszach (jak serwer: zaokrąglenie do grosza, połówki w górę) — tylko podgląd
 * w formularzu; po zapisie obowiązuje our_gross z API.
 */
export function grossPreview(net: string | null | undefined, vat: string | null | undefined): string | null {
  const netNorm = net != null ? parseAmountInput(net) : null
  const vatNorm = vat != null ? parseAmountInput(vat) : null
  if (!netNorm || !vatNorm) return null
  const netCents = Math.round(Number(netNorm) * 100)
  const vatHundredths = Math.round(Number(vatNorm) * 100)
  if (vatHundredths > 10000) return null
  const grossCents = Math.floor((netCents * (10000 + vatHundredths) + 5000) / 10000)
  return (grossCents / 100).toFixed(2)
}
