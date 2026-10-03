import type { ClientRecord, InquiryClientLinkSource, InquiryOutcome } from '../../lib/api'

/** Dzisiejszy dzień w Polsce jako „RRRR-MM-DD” (przypomnienia notatek liczy serwer w czasie polskim). */
export function polishToday(): string {
  return new Intl.DateTimeFormat('sv-SE', { timeZone: 'Europe/Warsaw', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date())
}

/** „RRRR-MM-DD” + n dni (kalendarzowo, bez wpływu zmiany czasu). */
export function addDays(ymd: string, days: number): string {
  const d = new Date(`${ymd}T12:00:00Z`)
  d.setUTCDate(d.getUTCDate() + days)
  return d.toISOString().slice(0, 10)
}

/** NIP do pokazania: prefiks kraju tylko przy firmach zagranicznych — „PL” przy polskim NIP-ie nic nie wnosi. */
export function nipText(c: Pick<ClientRecord, 'nip' | 'nip_prefix'>): string | null {
  if (!c.nip) return null
  return c.nip_prefix && c.nip_prefix !== 'PL' && !c.nip.startsWith(c.nip_prefix) ? `${c.nip_prefix} ${c.nip}` : c.nip
}

/** Reguła powiązania zapytania z klientem słowami (jak InquiryClientLinker::RULES). */
export const LINK_RULE_LABEL: Record<InquiryClientLinkSource, string> = {
  manual: 'wybrane przez handlowca',
  email: 'ten sam adres e-mail co w ERP XL',
  nip: 'NIP z maila',
}

export const OUTCOME_LABEL: Record<InquiryOutcome, string> = {
  ordered: 'zamówił',
  partial: 'zamówił część',
  not_ordered: 'nie zamówił',
  unknown: 'nie wiadomo, czy zamówił',
}

/** Rodzaj dokumentu sprzedaży z ERP XL (erp_sale_documents.kind). */
export const DOCUMENT_KIND_LABEL: Record<string, string> = {
  invoice: 'Faktura',
  receipt: 'Paragon',
  export_invoice: 'Faktura eksportowa',
  invoice_correction: 'Korekta faktury',
  receipt_correction: 'Korekta paragonu',
}

/** Czas między dwiema chwilami słowami: „30 minutach”, „2 godzinach”, „3 dniach” (do „Odpowiedź po …”). */
export function elapsedText(fromIso: string, toIso: string): string | null {
  const ms = new Date(toIso).getTime() - new Date(fromIso).getTime()
  if (Number.isNaN(ms) || ms < 0) return null
  const minutes = Math.round(ms / 60000)
  if (minutes < 60) return minutes === 1 ? '1 minucie' : `${minutes} minutach`
  const hours = Math.round(minutes / 60)
  if (hours < 48) return hours === 1 ? '1 godzinie' : `${hours} godzinach`
  const days = Math.round(hours / 24)
  return `${days} dniach`
}
