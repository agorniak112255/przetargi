import { api } from './api'
import type { CampaignLayout } from './campaigns'

/**
 * Oferty dla klientów — typy i wywołania API (05.10.2026). Lekka oferta: wybrane produkty z ceną, wysyłka ze
 * skrzynki „Moja poczta” (osobny mail na adres) albo kopia do wklejenia w Thunderbirdzie. Wygląd maila jak kampania.
 * Oferta jest zawsze edytowalna; każda wysyłka zapisuje dokładnie to, co dostał klient (sends).
 */

export type OfferRecipientStatus = 'sent' | 'failed' | 'skipped'

export type OfferItem = {
  id: number
  position: number
  erp_item_id: number | null
  product_id: number | null
  code: string
  name: string
  unit: string | null
  /** Stan magazynów handlowych (towar XL); null = pozycja bez towaru XL. */
  stock: number | null
  /** Koszt zakupu: średni koszt partii towaru XL albo cena zakupu karty w zł; null = brak danych. */
  unit_cost: number | null
  /** Koszt × (1 + domyślna marża konta); null = brak kosztu. */
  suggested_price: number | null
  /** Cena netto w ofercie; null = do uzupełnienia (wysyłka i kopiowanie wymagają ceny). */
  price_net: number | null
  note: string | null
  /** Opis wpisany przy pozycji; null = w mailu idzie card_excerpt. */
  description: string | null
  card_excerpt: string | null
  card: { id: number; sku: string; name: string; thumb_url: string | null } | null
  image_url: string | null
  warnings: { below_cost: boolean; no_price: boolean; no_image: boolean }
}

export type OfferRecipient = {
  email: string
  status: OfferRecipientStatus
  error: string | null
  sent_at: string | null
}

export type OfferSend = {
  id: number
  created_at: string
  recipients: OfferRecipient[]
}

export type Offer = {
  id: number
  code: string | null
  subject: string
  intro: string | null
  layout: CampaignLayout
  /** YYYY-MM-DD albo null. */
  valid_until: string | null
  last_sent_at: string | null
  last_copied_at: string | null
  items: OfferItem[]
  /** Najnowsza wysyłka pierwsza. */
  sends: OfferSend[]
  limits: { max_items: number; max_recipients: number }
}

export type OfferListRow = {
  id: number
  code: string | null
  subject: string
  items_count: number
  /** Adresy z udaną wysyłką (status sent), wszystkie wysyłki razem. */
  recipients_count: number
  last_sent_at: string | null
  last_copied_at: string | null
  updated_at: string
}

export type OfferPatch = {
  subject?: string
  intro?: string | null
  layout?: CampaignLayout
  valid_until?: string | null
}

export type OfferItemPatch = {
  price_net?: number | null
  note?: string | null
  description?: string | null
  position?: number
}

export type OfferPreview = {
  subject: string
  /** „Imię <adres>” skrzynki autora; '' = brak skrzynki w „Moja poczta”. */
  from: string
  html: string
  text: string
  /** Id pozycji bez ceny — wysłać i skopiować można dopiero po ich uzupełnieniu. */
  missing_prices: number[]
  /** Brak publicznego adresu aplikacji — w mailu nie będzie zdjęć ani baneru. */
  public_url_missing: boolean
}

export type OfferSendResult = { email: string; status: OfferRecipientStatus; error: string | null }

export type OfferSentMail = { subject: string; html: string; text: string; created_at: string }

const json = (body: unknown): RequestInit => ({ body: JSON.stringify(body) })

export function listOffers() {
  return api<{ data: OfferListRow[] }>('/offers')
}

export function createOffer(body: { erp_item_ids?: number[]; product_ids?: number[] } = {}) {
  return api<Offer>('/offers', { method: 'POST', ...json(body) })
}

export function getOffer(id: number) {
  return api<Offer>(`/offers/${id}`)
}

export function updateOffer(id: number, patch: OfferPatch) {
  return api<Offer>(`/offers/${id}`, { method: 'PATCH', ...json(patch) })
}

export function deleteOffer(id: number) {
  return api<void>(`/offers/${id}`, { method: 'DELETE' })
}

export function addOfferItems(id: number, body: { erp_item_ids?: number[]; product_ids?: number[] }) {
  return api<Offer>(`/offers/${id}/items`, { method: 'POST', ...json(body) })
}

export function updateOfferItem(id: number, itemId: number, patch: OfferItemPatch) {
  return api<Offer>(`/offers/${id}/items/${itemId}`, { method: 'PATCH', ...json(patch) })
}

export function removeOfferItem(id: number, itemId: number) {
  return api<Offer>(`/offers/${id}/items/${itemId}`, { method: 'DELETE' })
}

export function offerPreview(id: number) {
  return api<OfferPreview>(`/offers/${id}/preview`)
}

/** Znacznik „skopiowana” — wysyłany bez czekania (schowek wymaga świeżego kliknięcia, kopiujemy z podglądu). */
export function markOfferCopied(id: number) {
  return api<void>(`/offers/${id}/copied`, { method: 'POST' })
}

export function sendOffer(id: number, emails: string[]) {
  return api<{ offer: Offer; results: OfferSendResult[] }>(`/offers/${id}/send`, { method: 'POST', ...json({ emails }) })
}

export function offerSentMail(id: number, sendId: number) {
  return api<OfferSentMail>(`/offers/${id}/sends/${sendId}`)
}

/** Adresy wpisane w pole: przecinek, średnik, spacja albo nowa linia; bez powtórzeń (wielkość liter bez znaczenia). */
export function parseEmails(raw: string): string[] {
  const seen = new Set<string>()
  const out: string[] = []
  for (const part of raw.split(/[\s,;]+/)) {
    const email = part.trim().replace(/^<|>$/g, '')
    if (email === '') continue
    const key = email.toLowerCase()
    if (seen.has(key)) continue
    seen.add(key)
    out.push(email)
  }
  return out
}
