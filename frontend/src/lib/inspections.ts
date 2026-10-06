import { api, downloadFile } from './api'
import { plural } from './plural'

/**
 * Przeglądy (06.10.2026) — typy i wywołania API. Lista klientów z terminem przeglądu liczonym z faktur ERP XL:
 * termin = data ostatniej sprzedaży pozycji (usługi przeglądu albo zakupu urządzenia) + interwał wpisany przez
 * człowieka. Stan (zaległy / nadchodzący) i liczby dni liczy serwer według polskiej daty — strona tylko wyświetla.
 */

export type InspectionStatus = 'overdue' | 'upcoming'

/** Rodzaj pozycji w ERP XL: 1 = towar (urządzenie), 4 = usługa (przegląd). */
export const XL_TYPE_GOODS = 1
export const XL_TYPE_SERVICE = 4

/** Interwały do wyboru (decyzja właściciela 06.10.2026), w miesiącach — jak InspectionPosition::INTERVALS. */
export const INSPECTION_INTERVALS = [1, 3, 6, 9, 12, 15, 18, 24] as const

export type InspectionDismissalReason = 'other_company' | 'resigned' | 'skip' | 'other'

export const DISMISSAL_REASONS: InspectionDismissalReason[] = ['other_company', 'resigned', 'skip', 'other']

export const DISMISSAL_REASON_LABEL: Record<InspectionDismissalReason, string> = {
  other_company: 'Robi przeglądy u innej firmy',
  resigned: 'Zrezygnował',
  skip: 'Pomiń bez podawania powodu',
  other: 'Inny powód',
}

export type InspectionDismissal = {
  id: number
  /** RRRR-MM-DD; null = na zawsze. */
  until_on: string | null
  reason: InspectionDismissalReason
  note: string | null
  user_name: string | null
  created_at: string
}

export type InspectionCustomer = {
  xl_gid: number
  acronym: string
  name: string | null
  nip: string | null
  city: string | null
  emails: string[]
  archived: boolean
  /** false = klienta nie ma w kartotece ERP XL odczytanej przez aplikację (acronym = „Klient XL {numer}”). */
  known: boolean
  /** Z kartoteki ERP XL dosłownie (odczyt nocny); null = brak w kartotece albo brak prawa odczytu (details_unavailable). */
  street: string | null
  address_line2: string | null
  postal_code: string | null
  voivodeship: string | null
  phones: string[]
  contacts: InspectionContact[]
  account_manager: { name: string; email: string | null } | null
  /** Operator ERP XL, który najczęściej wystawiał klientowi dokumenty (24 miesiące). */
  main_operator: string | null
  /** Ostatnia sprzedaż klientowi (dowolny towar), RRRR-MM-DD. */
  last_sale_on: string | null
  /** Karta w zakładce Klienci (tylko klienci powyżej progu sprzedaży); null = brak karty. */
  client_id: number | null
  details_synced_at: string | null
  /** Zatwierdzone adresy z sieci (są też w emails — adresy z karty XL i z sieci razem). */
  web_emails: string[]
  /** Propozycje adresów z sieci czekające na decyzję („Użyj” / „Odrzuć”). */
  pending_email_suggestions: number
}

/**
 * Adres e-mail klienta znaleziony w sieci — propozycja z dowodem; do ofert trafia dopiero po „Użyj”.
 * evidence: nip — na stronie źródłowej jest NIP klienta; name — strona firmy o podobnej nazwie, bez NIP-u (sprawdź).
 */
export type CustomerEmailSuggestion = {
  id: number
  email: string
  source: 'website' | 'directory' | 'regon' | 'ceidg'
  source_url: string | null
  source_host: string | null
  evidence: 'nip' | 'name'
  status: 'pending' | 'accepted' | 'rejected'
  decided_by_name: string | null
  decided_at: string | null
  found_at: string | null
}

export type CustomerEmailLookup = { checked_at: string | null; found: number; error: boolean }

/** Osoba kontaktowa z karty klienta w ERP XL (bez archiwalnych). */
export type InspectionContact = {
  name: string | null
  position: string | null
  email: string | null
  phone: string | null
  mobile: string | null
}

export type InspectionDocument = { number: string; issued_on: string; quantity: number }

export type InspectionDuePosition = {
  due_id: number
  position_id: number
  xl_gid: number
  xl_type: number
  code: string
  name: string
  unit: string | null
  interval_months: number
  due_on: string
  status: InspectionStatus
  days_left: number | null
  overdue_days: number | null
  /** Ile wizyt (zakupów) czeka na przegląd — kilka obiektów albo kilka zakupów w roku. */
  open_count: number
  open_quantity: number
  last_on: string | null
  last_quantity: number | null
  /** Wartość netto ostatniej wizyty z faktur — tylko dla pracownika, nie trafia do oferty. */
  last_net: number | null
  last_documents: InspectionDocument[]
  first_on: string | null
  location: string | null
  location_name: string | null
  operator_ident: string | null
  recipient: { xl_gid: number; name: string | null } | null
  /** Inna karta klienta z tym samym NIP-em ma przegląd po dacie ostatniego przeglądu tego wiersza. */
  same_nip_newer: boolean
  dismissal: InspectionDismissal | null
}

export type InspectionLastOffer = { offer_id: number; code: string | null; sent_at: string; user_name: string | null }

export type InspectionRow = {
  customer: InspectionCustomer
  due_on: string
  status: InspectionStatus
  days_left: number | null
  overdue_days: number | null
  positions: InspectionDuePosition[]
  last_offer: InspectionLastOffer | null
  dismissal: InspectionDismissal | null
}

export type InspectionListMeta = {
  total: number
  page: number
  per_page: number
  today: string
  days: number
  mine_unavailable: boolean
  locations: { code: string; name: string }[]
  positions: { id: number; name: string; interval_months: number }[]
  permissions: { manage: boolean; offer: boolean }
}

export type InspectionListResponse = { data: InspectionRow[]; meta: InspectionListMeta }

export type InspectionHistoryLine = {
  issued_on: string
  sold_on: string | null
  document_number: string
  /** Faktura ze spinacza WZ (towar wydany przez WZ — faktura do WZ nie ma w ERP XL własnych pozycji). */
  invoice_number: string | null
  xl_gid: number
  code: string
  name: string
  quantity: number
  net_value: number | null
  is_correction: boolean
  location: string | null
  operator_ident: string | null
}

export type InspectionCustomerDetail = {
  customer: InspectionCustomer
  positions: InspectionDuePosition[]
  history: InspectionHistoryLine[]
  /** Oferty przeglądu do klienta; sent_at null = szkic jeszcze niewysłany. */
  offers: (Omit<InspectionLastOffer, 'sent_at'> & { sent_at: string | null })[]
  dismissals: InspectionDismissal[]
  /**
   * Pola kartoteki, do których aplikacja nie ma prawa odczytu w ERP XL: street, address_line2, postal_code, phone,
   * phone2, contact_phone, contact_mobile itd. — ekran pisze „brak dostępu”, a nie „brak w kartotece”.
   */
  details_unavailable: string[]
  email_suggestions: CustomerEmailSuggestion[]
  /** Ostatnie szukanie adresu w sieci; null = jeszcze nie szukano. */
  email_lookup: CustomerEmailLookup | null
}

export type InspectionPositionSource = 'manual' | 'suggestion'

export type InspectionPositionRow = {
  id: number
  xl_gid: number
  xl_type: number
  code: string
  name: string
  unit: string | null
  interval_months: number
  renewed_by: { xl_gid: number; code: string; name: string } | null
  note: string | null
  active: boolean
  source: InspectionPositionSource
  pattern: { id: number; name: string } | null
  /** Historia sprzedaży towaru od 2019 roku już odczytana z ERP XL (usługi są czytane zawsze). */
  history_loaded: boolean
  customers_due: number
  customers_overdue: number
  created_by_name: string | null
  updated_by_name: string | null
  updated_at: string
}

export type InspectionCatalogType = 'all' | 'goods' | 'service'

export type InspectionCatalogItem = {
  xl_gid: number
  xl_type: number
  code: string
  name: string
  unit: string | null
  /** Już zdefiniowana pozycja przeglądu; null = jeszcze nie. */
  position_id: number | null
  customers_24m: number
  quantity_24m: number
}

export type InspectionPositionInput = {
  xl_gid: number
  interval_months: number
  renewed_by_xl_gid: number | null
  note?: string | null
}

export type InspectionPositionPatch = {
  interval_months?: number
  renewed_by_xl_gid?: number | null
  note?: string | null
  active?: boolean
}

export type InspectionSuggestionCandidate = {
  xl_gid: number
  xl_type: number
  code: string
  name: string
  unit: string | null
  interval_months: number
  renewed_by: { xl_gid: number; code: string; name: string } | null
  customers_24m: number
  quantity_24m: number
  /** Oznaczenie modelu z nazwy, np. „GP-4”. */
  token: string
}

export type InspectionSuggestionGroup = {
  pattern: { id: number; name: string; interval_months: number }
  candidates: InspectionSuggestionCandidate[]
}

const json = (body: unknown): RequestInit => ({ body: JSON.stringify(body) })

/** JSON pierwszy: błędy (422, 403) wracają z polskim komunikatem, a nie jako przekierowanie/HTML. */
const PDF_ACCEPT = 'application/json, application/pdf'
const XLSX_ACCEPT = 'application/json, application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'

/** Lista klientów z terminem przeglądu; query = gotowe parametry filtra (bez „?”). */
export function listInspections(query: string) {
  return api<InspectionListResponse>(`/inspections?${query}`)
}

export function getInspectionCustomer(xlGid: number) {
  return api<InspectionCustomerDetail>(`/inspections/customers/${xlGid}`)
}

/** Szuka adresu e-mail klienta w sieci teraz (kilkanaście–kilkadziesiąt sekund); zwraca wszystkie propozycje klienta. */
export function searchCustomerEmails(xlGid: number) {
  return api<{ found: number; error: string | null; suggestions: CustomerEmailSuggestion[]; lookup: CustomerEmailLookup | null }>(
    `/inspections/customers/${xlGid}/email-search`,
    { method: 'POST' },
  )
}

/** „Użyj” (accepted), „Odrzuć” (rejected) albo cofnięcie decyzji (pending). */
export function decideEmailSuggestion(id: number, status: CustomerEmailSuggestion['status']) {
  return api<{ suggestions: CustomerEmailSuggestion[] }>(`/inspections/email-suggestions/${id}`, {
    method: 'PATCH',
    ...json({ status }),
  })
}

function gidsQuery(gids: number[]): string {
  return gids.map((g) => `customer_xl_gids[]=${encodeURIComponent(String(g))}`).join('&')
}

/** Plik Excela: te same filtry co lista; z zaznaczeniem — tylko zaznaczeni klienci. */
export function downloadInspectionsExport(query: string, gids: number[]) {
  const qs = [query, gids.length > 0 ? gidsQuery(gids) : ''].filter(Boolean).join('&')
  return downloadFile(`/inspections/export?${qs}`, 'przeglady.xlsx', XLSX_ACCEPT)
}

/** Raport PDF dla zaznaczonych klientów (najwyżej 200). */
export function downloadInspectionsReport(gids: number[]) {
  return downloadFile(`/inspections/report?${gidsQuery(gids)}`, 'raport-przegladow.pdf', PDF_ACCEPT)
}

export function createInspectionDismissal(body: {
  customer_xl_gid: number
  position_id: number | null
  until_on: string | null
  reason: InspectionDismissalReason
  note: string | null
}) {
  return api<{ dismissal: InspectionDismissal }>('/inspections/dismissals', { method: 'POST', ...json(body) })
}

export function deleteInspectionDismissal(id: number) {
  return api<void>(`/inspections/dismissals/${id}`, { method: 'DELETE' })
}

/* ---------- Pozycje przeglądów (uprawnienie inspections.manage) ---------- */

export function listInspectionPositions() {
  return api<{ data: InspectionPositionRow[]; meta: { intervals: number[] } }>('/inspection-positions')
}

export function searchInspectionCatalog(q: string, type: InspectionCatalogType) {
  const qs = new URLSearchParams({ q, type })
  return api<{ data: InspectionCatalogItem[] }>(`/inspection-positions/catalog?${qs.toString()}`)
}

export function createInspectionPositions(items: InspectionPositionInput[]) {
  return api<{ data: InspectionPositionRow[] }>('/inspection-positions', { method: 'POST', ...json({ items }) })
}

export function updateInspectionPosition(id: number, patch: InspectionPositionPatch) {
  // odpowiedź nieużywana — strona po zapisie wczytuje listę od nowa (liczniki klientów po przebudowie terminów)
  return api<unknown>(`/inspection-positions/${id}`, {
    method: 'PATCH',
    ...json(patch),
  })
}

export function deleteInspectionPosition(id: number) {
  return api<void>(`/inspection-positions/${id}`, { method: 'DELETE' })
}

export function listInspectionSuggestions() {
  return api<{ data: InspectionSuggestionGroup[] }>('/inspection-positions/suggestions')
}

export function acceptInspectionSuggestions(
  patternId: number,
  items: { xl_gid: number; interval_months: number; renewed_by_xl_gid: number | null }[],
) {
  return api<{ data: InspectionPositionRow[] }>('/inspection-positions/suggestions/accept', {
    method: 'POST',
    ...json({ pattern_id: patternId, items }),
  })
}

export function rejectInspectionSuggestions(patternId: number, xlGids: number[]) {
  return api<void>('/inspection-positions/suggestions/reject', {
    method: 'POST',
    ...json({ pattern_id: patternId, xl_gids: xlGids }),
  })
}

/* ---------- Wspólne teksty ---------- */

/** „usługa” / „towar” — rodzaj pozycji w ERP XL. */
export function xlTypeLabel(xlType: number): string {
  return xlType === XL_TYPE_SERVICE ? 'usługa' : 'towar'
}

/** Co oznacza data ostatniej sprzedaży: usługa = przegląd, towar = zakup urządzenia. */
export function lastEventLabel(xlType: number): string {
  return xlType === XL_TYPE_SERVICE ? 'ostatni przegląd' : 'zakup urządzenia'
}

/** „1 miesiąc”, „3 miesiące”, „12 miesięcy” — bez skrótu „mies.”. */
export function monthsLabel(months: number): string {
  return `${months} ${plural(months, 'miesiąc', 'miesiące', 'miesięcy')}`
}

/** „co miesiąc”, „co 12 miesięcy”. */
export function intervalLabel(months: number): string {
  return months === 1 ? 'co miesiąc' : `co ${monthsLabel(months)}`
}
