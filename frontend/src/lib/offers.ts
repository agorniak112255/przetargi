import { api, downloadFile } from './api'
import type { CampaignItemLink, CampaignLayout } from './campaigns'

/**
 * Oferty dla klientów — typy i wywołania API (05.10.2026). Lekka oferta: wybrane produkty z ceną, wysyłka ze
 * skrzynki „Moja poczta” (osobny mail na adres) albo nowa wiadomość w Thunderbirdzie (dodatek). Wygląd maila jak kampania.
 * Oferta jest zawsze edytowalna; każda wysyłka zapisuje dokładnie to, co dostał klient (sends).
 */

export type OfferRecipientStatus = 'sent' | 'failed' | 'skipped'

/**
 * Forma oferty (zapamiętana przy ofercie): body — produkty w treści maila; pdf — krótki mail, oferta w załączniku PDF;
 * both — treść maila i ten sam wygląd w załączniku PDF.
 */
export type OfferDelivery = 'body' | 'pdf' | 'both'

export const OFFER_DELIVERIES: OfferDelivery[] = ['body', 'pdf', 'both']

/** Jednostka ceny pozycji wybrana przez handlowca; null = jednostka towaru XL, a bez niej „szt”. */
export type OfferPriceUnit = 'szt' | 'para' | 'opak' | 'karton'

/** Kod jednostki → to, co idzie w mailu po „/” (OfferItem::PRICE_UNITS na serwerze). */
export const OFFER_PRICE_UNIT_LABEL: Record<OfferPriceUnit, string> = {
  szt: 'szt',
  para: 'para',
  opak: 'opak.',
  karton: 'karton',
}

export const OFFER_PRICE_UNITS = Object.keys(OFFER_PRICE_UNIT_LABEL) as OfferPriceUnit[]

/** Ceny w mailu oferty produktowej: netto albo brutto (netto × (1 + VAT)). */
export type OfferPriceMode = 'net' | 'gross'

export const OFFER_PRICE_MODES: OfferPriceMode[] = ['net', 'gross']

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
  /** Cena netto w ofercie; null = do uzupełnienia (wysyłka i Thunderbird wymagają ceny). */
  price_net: number | null
  /** Cena brutto z serwera: price_net × (1 + vat_percent/100), zaokrąglona; null = brak ceny. Liczona zawsze. */
  price_gross: number | null
  /** Wybrana jednostka ceny; null = jednostka towaru XL (unit), a bez niej „szt”. */
  price_unit: OfferPriceUnit | null
  /** To, co idzie w mailu po „/” przy cenie (etykieta wyboru albo jednostka XL albo „szt”). */
  price_unit_label: string
  /**
   * Jednostka ceny inna niż jednostka towaru XL — koszt zakupu i stan są w jednostce XL (albo za sztukę), więc
   * porównanie ceny z kosztem nie ma sensu (warnings.below_cost jest wtedy false).
   */
  unit_mismatch: boolean
  /** Rozmiary w mailu („Rozmiary: S, XXXL”); null = bez tej linii. Przy rozmiarach mail nie pokazuje stanu. */
  sizes: string | null
  /** Rozmiary karty pozycji do szybkiego wyboru (w kolejności z karty); [] = karta bez rozmiarów. */
  size_choices: string[]
  note: string | null
  /** Opis wpisany przy pozycji; null = w mailu idzie card_excerpt. */
  description: string | null
  card_excerpt: string | null
  card: { id: number; sku: string; name: string; thumb_url: string | null } | null
  image_url: string | null
  /** Przycisk z linkiem w mailu i PDF (np. do sklepu); null = bez przycisku. Oferta nie ma „Zapytaj o ofertę”. */
  link: CampaignItemLink | null
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
  /** Forma użyta w tej wysyłce. */
  delivery: OfferDelivery
  /** Zapisany plik PDF, który dostali klienci (wysyłka w formie pdf albo both). */
  has_pdf: boolean
  recipients: OfferRecipient[]
}

/**
 * Rodzaj oferty: products — produkty z cenami; inspection — oferta przeglądu dla jednego klienta z modułu Przeglądy
 * (zaczepna, bez cen: co i kiedy wymaga przeglądu).
 */
export type OfferKind = 'products' | 'inspection'

/** Klient oferty przeglądu (z kartoteki ERP XL); adresy e-mail z karty klienta — do wstawienia przy wysyłce. */
export type OfferCustomer = {
  xl_gid: number
  acronym: string
  name: string | null
  city: string | null
  emails: string[]
}

/** Wiersz oferty przeglądu: urządzenie lub usługa, ilość, ostatni przegląd lub zakup, termin — bez ceny. */
export type OfferInspectionLine = {
  id: number
  position: number
  inspection_position_id: number | null
  xl_gid: number | null
  name: string
  unit: string | null
  quantity: number | null
  /** RRRR-MM-DD z faktur ERP XL; null = brak. */
  last_on: string | null
  /** RRRR-MM-DD; termin wyliczony, handlowiec może go poprawić. */
  due_on: string | null
  note: string | null
}

export type OfferInspectionLinePatch = {
  quantity?: number | null
  due_on?: string | null
  note?: string | null
  position?: number
}

/** Autor oferty — nadawca maili ze swojej skrzynki. */
export type OfferAuthor = { id: number; name: string; email: string }

export type Offer = {
  id: number
  kind: OfferKind
  author: OfferAuthor | null
  /**
   * false = oferta innej osoby, widoczna z uprawnieniem „Oferty — podgląd wszystkich” albo „… wybranych osób”:
   * tylko podgląd (zmiana, wysyłka, usunięcie — 403).
   */
  can_edit: boolean
  /** Tylko oferta przeglądu; null dla ofert produktowych. */
  customer: OfferCustomer | null
  /** Wiersze oferty przeglądu; dla ofert produktowych pusta lista. */
  inspection_lines: OfferInspectionLine[]
  code: string | null
  subject: string
  intro: string | null
  layout: CampaignLayout
  delivery: OfferDelivery
  /** Ceny w mailu (tylko oferta produktowa): net — netto, gross — brutto z VAT vat_percent. */
  price_mode: OfferPriceMode
  /** Stawka VAT do cen brutto (stała z serwera, np. 23). */
  vat_percent: number
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
  kind: OfferKind
  /** Nazwa klienta oferty przeglądu; null dla ofert produktowych. */
  customer_name: string | null
  code: string | null
  subject: string
  items_count: number
  /** Adresy z udaną wysyłką (status sent), wszystkie wysyłki razem. */
  recipients_count: number
  /** Te adresy (bez powtórzeń) — do wyszukiwania na liście. */
  recipient_emails: string[]
  /** Autor oferty — nadawca maili ze swojej skrzynki. */
  author: OfferAuthor | null
  /** false = oferta innej osoby — tylko podgląd. */
  can_edit: boolean
  last_sent_at: string | null
  last_copied_at: string | null
  updated_at: string
}

export type OfferPatch = {
  subject?: string
  intro?: string | null
  layout?: CampaignLayout
  valid_until?: string | null
  delivery?: OfferDelivery
  /** Oferta przeglądu nie ma cen — serwer odrzuca (422). */
  price_mode?: OfferPriceMode
}

export type OfferItemPatch = {
  price_net?: number | null
  note?: string | null
  description?: string | null
  position?: number
  /** Pusty / null link usuwa przycisk (wtedy nazwa i kolor też null). */
  link_url?: string | null
  link_label?: string | null
  link_color?: string | null
  price_unit?: OfferPriceUnit | null
  /** Jedna linia, najwyżej 200 znaków; pusty / null = bez rozmiarów. */
  sizes?: string | null
}

export type OfferPreview = {
  subject: string
  /** „Imię <adres>” skrzynki autora; '' = brak skrzynki w „Moja poczta”. */
  from: string
  html: string
  text: string
  /** Id pozycji bez ceny — wysłać można dopiero po ich uzupełnieniu. */
  missing_prices: number[]
  /** Brak publicznego adresu aplikacji — w mailu nie będzie zdjęć ani baneru. */
  public_url_missing: boolean
  /** Forma, w jakiej przygotowano podgląd (przy pdf html/text to krótki mail bez produktów). */
  delivery: OfferDelivery
  /** Nazwa pliku PDF w załączniku, np. „Oferta-OF-0001.pdf”. */
  pdf_filename: string | null
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

export function updateOfferInspectionLine(id: number, lineId: number, patch: OfferInspectionLinePatch) {
  return api<Offer>(`/offers/${id}/inspection-lines/${lineId}`, { method: 'PATCH', ...json(patch) })
}

export function removeOfferInspectionLine(id: number, lineId: number) {
  return api<Offer>(`/offers/${id}/inspection-lines/${lineId}`, { method: 'DELETE' })
}

export type InspectionOffersResult = {
  offers: {
    id: number
    code: string | null
    customer_xl_gid: number
    customer_name: string
    lines_count: number
    emails: string[]
    /**
     * Najnowsza wysłana oferta przeglądu do tego klienta w ostatnich 90 dniach, a gdy jej nie ma — niewysłany szkic
     * innej osoby z ostatnich 14 dni (sent_at = null).
     */
    previous: { code: string | null; sent_at: string | null; created_at: string | null; user_name: string | null } | null
    /** Pozycje, przy których inna karta XL z tym samym NIP-em ma późniejszą sprzedaż (przegląd mógł już być zrobiony). */
    same_nip_newer_lines: number
  }[]
  skipped: { customer_xl_gid: number; customer_name: string; reason: string }[]
}

/**
 * Szkice ofert przeglądu dla zaznaczonych klientów (1–50): po jednej ofercie na klienta z wierszami o terminie
 * w ciągu `days` dni (zaległe też); position_ids zawęża do wybranych pozycji, null = wszystkie.
 */
export function createInspectionOffers(body: { customer_xl_gids: number[]; days: number; position_ids: number[] | null }) {
  return api<InspectionOffersResult>('/inspections/offers', { method: 'POST', ...json(body) })
}

export function offerPreview(id: number) {
  return api<OfferPreview>(`/offers/${id}/preview`)
}

export function sendOffer(id: number, emails: string[]) {
  return api<{ offer: Offer; results: OfferSendResult[] }>(`/offers/${id}/send`, { method: 'POST', ...json({ emails }) })
}

export function offerSentMail(id: number, sendId: number) {
  return api<OfferSentMail>(`/offers/${id}/sends/${sendId}`)
}

/** JSON pierwszy: błędy (422 brak ceny, 404, 429) wracają z polskim komunikatem, a nie jako przekierowanie/HTML. */
const PDF_ACCEPT = 'application/json, application/pdf'

/** PDF bieżącej oferty (wygląd jak mail). 422 z komunikatem serwera, gdy brak pozycji albo ceny. */
export function downloadOfferPdf(id: number, fallbackName = `Oferta-${id}.pdf`) {
  return downloadFile(`/offers/${id}/pdf`, fallbackName, PDF_ACCEPT)
}

/** Plik PDF dokładnie taki, jaki dostali klienci w danej wysyłce. */
export function downloadSentPdf(id: number, sendId: number, fallbackName = `Oferta-${id}-wysylka-${sendId}.pdf`) {
  return downloadFile(`/offers/${id}/sends/${sendId}/pdf`, fallbackName, PDF_ACCEPT)
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
