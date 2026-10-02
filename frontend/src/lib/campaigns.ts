import { api } from './api'
import { publicDir } from './publicDir'

/** Kampanie reklamowe — typy i wywołania API (kontrakt pierwszego wydania, 01.10.2026). */

export type CampaignStatus = 'draft' | 'scheduled' | 'sending' | 'sent' | 'cancelled'
export type CampaignLayout =
  | 'grid3'
  | 'grid2'
  | 'list'
  | 'grid2_desc'
  | 'list_desc'
  | 'pricelist'
export type CampaignXlMode = 'items' | 'group' | 'mine'

export const CAMPAIGN_LAYOUT_LABEL: Record<CampaignLayout, string> = {
  grid3: 'Siatka po 3',
  grid2: 'Siatka po 2',
  list: 'Lista z opisem',
  grid2_desc: 'Siatka po 2 z opisem',
  list_desc: 'Lista z opisem i normami',
  pricelist: 'Cennik (tabela)',
}

/** Co pokazuje układ — podpowiedź pod wyborem układu. */
export const CAMPAIGN_LAYOUT_HINT: Record<CampaignLayout, string> = {
  grid3: 'Zdjęcie, nazwa, cena i przycisk — trzy produkty w rzędzie, karty równej wysokości.',
  grid2: 'Większe zdjęcia, dwa produkty w rzędzie, karty równej wysokości.',
  list: 'Zdjęcie z lewej, obok nazwa, krótki opis i cena.',
  grid2_desc: 'Dwa w rzędzie z krótkim opisem pod nazwą.',
  list_desc: 'Zdjęcie z lewej, krótki opis i normy z karty (np. EN 388).',
  pricelist: 'Tabela bez zdjęć: nazwa, kod, cena, stan i „Zapytaj”.',
}

/** Układy, w których mail pokazuje krótki opis produktu. */
export const LAYOUTS_WITH_DESCRIPTION: CampaignLayout[] = ['list', 'grid2_desc', 'list_desc']

/**
 * Element maila (kolejność w tablicy = kolejność w mailu). Pola zawsze obecne, puste = '' / null.
 * Produkty dokładnie raz, logo (header) i stopka najwyżej raz — pilnuje tego też serwer.
 */
export type CampaignBlock =
  | { type: 'header'; logo: string | null }
  | { type: 'heading'; text: string }
  | { type: 'text'; text: string }
  | { type: 'image'; asset: string | null; alt: string; url: string }
  | { type: 'products'; layout: CampaignLayout }
  | { type: 'button'; label: string; url: string }
  | { type: 'footer'; text: string }

export type CampaignBlockType = CampaignBlock['type']

export const CAMPAIGN_BLOCK_LABEL: Record<CampaignBlockType, string> = {
  header: 'Logo',
  heading: 'Nagłówek',
  text: 'Tekst',
  image: 'Grafika',
  products: 'Produkty',
  button: 'Przycisk',
  footer: 'Stopka',
}

/** Jak CampaignBlocks (serwer): najwyżej 20 elementów. */
export const MAX_CAMPAIGN_BLOCKS = 20

/** Kolory maila (przycisk, akcenty) — jak BRAND_COLORS serwera; null = pierwszy (zieleń SUPON). */
export const BRAND_COLORS = ['#0b7d6a', '#1f5fa8', '#b3261e', '#c25e00', '#5b3fa0', '#2f3a40'] as const
export const DEFAULT_BRAND_COLOR = BRAND_COLORS[0]
export const BRAND_COLOR_LABEL: Record<string, string> = {
  '#0b7d6a': 'Zieleń SUPON',
  '#1f5fa8': 'Niebieski',
  '#b3261e': 'Czerwony',
  '#c25e00': 'Pomarańczowy',
  '#5b3fa0': 'Fioletowy',
  '#2f3a40': 'Grafitowy',
}

/** „Standard SUPON” — jak CampaignBlocks::standard(). */
export function standardCampaignBlocks(): CampaignBlock[] {
  return [
    { type: 'header', logo: null },
    { type: 'heading', text: '' },
    { type: 'text', text: '' },
    { type: 'products', layout: 'grid3' },
    { type: 'footer', text: '' },
  ]
}

/** Szablon maila: własny albo wspólny (wspólne prowadzi administrator). */
export type CampaignTemplate = {
  id: number
  name: string
  is_shared: boolean
  owner: { id: number; name: string } | null
  can_edit: boolean
  brand_color: string | null
  blocks: CampaignBlock[]
  updated_at: string
}

export type CampaignAsset = { uuid: string; url: string; width: number; height: number }

/** Adres wgranego obrazka do podglądu w edytorze (względem API, jak inne żądania). */
export function campaignAssetUrl(uuid: string): string {
  return `${publicDir()}/api/campaign-assets/${encodeURIComponent(uuid)}`
}
export type ContactBasis = 'customer' | 'consent'

export const CAMPAIGN_STATUS_LABEL: Record<CampaignStatus, string> = {
  draft: 'Projekt',
  scheduled: 'Zaplanowana',
  sending: 'Wysyłka',
  sent: 'Wysłana',
  cancelled: 'Anulowana',
}

export type PageMeta = { current_page: number; last_page: number; per_page: number; total: number }

export type CampaignAudience = {
  list_ids: number[]
  /**
   * Odznaczone adresy w wybranej grupie (okno „Pokaż / wybierz”). Brak wpisu = cała grupa; adresy dopisane do grupy
   * później dochodzą. Kontakt odznaczony w jednej grupie, a obecny w innej wybranej bez odznaczenia, dostaje maila.
   */
  list_exclusions: { list_id: number; contact_ids: number[] }[]
  xl: {
    mode: CampaignXlMode | null
    months: 12 | 24
    only_mine: boolean
    /** null = cała kategoria; lista = tylko klienci zaznaczeni w oknie „Pokaż / wybierz”. */
    customer_ids?: number[] | null
  }
}

export type CampaignResult = {
  stock_at_send: number | null
  stock_after_7d: number | null
  stock_after_30d: number | null
  /** 0–100: ile stanu zeszło (z 30 dni, a gdy brak — z 7 dni). */
  drop_percent: number | null
}

export type CampaignListRow = {
  id: number
  code: string
  name: string
  subject: string
  status: CampaignStatus
  author: { id: number; name: string }
  items_count: number
  recipients_total: number
  sent: number
  failed: number
  /** Odbiorcy, którzy kliknęli link w mailu (bez skanerów poczty). */
  clicked?: number
  /** Odpowiedzi klientów odczytane ze skrzynki autora (IMAP). */
  replies?: number
  created_at: string
  /** Zaplanowana godzina startu (UTC, ISO). */
  scheduled_at?: string | null
  sending_started_at: string | null
  sent_at: string | null
  /** Suma wartości zapasu pozycji (koszt zakupu), null = brak danych. */
  stock_value: number | null
  result: CampaignResult | null
  /** Kupili odbiorcy (faktury i paragony XL, 30 dni od wysyłki); null = projekt. */
  sales?: { customers: number; net_value: number; complete: boolean } | null
}

export type CampaignSalesItem = {
  erp_item_id: number
  code: string
  name: string
  unit: string | null
  quantity_recipients: number
  quantity_others: number
  value_recipients: number
  value_others: number
}

export type CampaignSalesBuyer = {
  customer_id: number
  acronym: string
  name: string | null
  /** Adres, na który poszedł mail. */
  email: string
  sold_at: string | null
  code: string
  item_name: string
  unit: string | null
  quantity: number
  net_value: number
  document_number: string
}

/** „Kupili odbiorcy kampanii”: sprzedaż pozycji kampanii z XL od wysyłki przez `days` dni. */
export type CampaignSales = {
  from: string
  to: string
  days: number
  /** Okres się skończył — wynik już się nie zmieni (poza korektami w XL). */
  complete: boolean
  synced_at: string | null
  recipients_sent: number
  /** Ilu odbiorców da się śledzić: klient XL albo adres z karty kontrahenta XL. */
  recipients_in_xl: number
  recipients: { customers: number; net_value: number }
  others: { customers: number; net_value: number }
  items: CampaignSalesItem[]
  buyers: CampaignSalesBuyer[]
  buyers_truncated: boolean
}

export type CampaignItemWarnings = {
  below_cost: boolean
  no_stock: boolean
  no_image: boolean
  other_campaigns: { id: number; code: string; name: string; author: string }[]
}

/** Kolor z BRAND_COLORS, link tylko https://. */
export type CampaignItemLink = { url: string; label: string; color: string }

/** Jak walidacja serwera: nazwa przycisku najwyżej tyle znaków. */
export const ITEM_LINK_LABEL_MAX = 40

export type CampaignItemCard = { id: number; sku: string; name: string; thumb_url: string | null }

export type CampaignItem = {
  id: number
  position: number
  erp_item_id: number | null
  product_id: number | null
  /** Kod XL albo SKU karty. */
  code: string
  name: string
  unit: string | null
  /** Stan magazynów handlowych teraz (nocny odczyt XL). */
  stock: number | null
  stock_synced_at: string | null
  unit_cost: number | null
  suggested_price: number | null
  promo_price_net: number | null
  price_before_net: number | null
  note: string | null
  /** Krótki opis wpisany przy pozycji; null = w mailu idzie card_excerpt. */
  description: string | null
  /** Drugi przycisk w mailu obok „Zapytaj o ofertę” (np. do sklepu); null = brak. */
  link: CampaignItemLink | null
  /** Wycinek opisu karty (pierwsze zdania) — nigdy tekst dopisany; null = karta bez sensownego opisu. */
  card_excerpt: string | null
  card_norms: string[]
  card: CampaignItemCard | null
  card_suggestion: { product_id: number; sku: string; name: string; thumb_url: string | null } | null
  image_url: string | null
  warnings: CampaignItemWarnings
  snapshot: {
    name: string | null
    code: string | null
    price: number | null
    stock: number | null
    stock_at: string | null
    image_url: string | null
    description: string | null
    norms: string[]
  } | null
  stock_after_7d: number | null
  stock_after_30d: number | null
}

export type CampaignTotals = { recipients: number; sent: number; failed: number; skipped: number }

export type Campaign = {
  id: number
  code: string
  name: string
  subject: string
  preheader: string | null
  heading: string | null
  intro: string | null
  layout: CampaignLayout
  valid_until: string | null
  /** Ostatnio zastosowany szablon (null = Standard SUPON albo szablon usunięty). */
  template_id: number | null
  template_name: string | null
  /** Elementy maila (stara kampania bez bloków — przeliczone z nagłówka, tekstu i układu). */
  blocks: CampaignBlock[]
  /** null = domyślny kolor (zieleń SUPON). */
  brand_color: string | null
  status: CampaignStatus
  audience: CampaignAudience
  author: { id: number; name: string }
  can_edit: boolean
  /** Autor albo „Kampanie — wszystkie”; sam podgląd (campaigns.view) = false: tylko odczyt i test do siebie. */
  can_manage?: boolean
  /** Projekt: autor; wysłana albo anulowana: uprawnienie „Kampanie — usuwanie wysłanych”. */
  can_delete?: boolean
  /** „Dopisz odbiorców”: autor, kampania wysłana albo w wysyłce, oferta ważna, ≤ 30 dni od startu. */
  can_add_recipients?: boolean
  created_at: string
  updated_at: string
  /** Zaplanowana godzina startu (UTC, ISO). */
  scheduled_at?: string | null
  /** Zaplanowana wysyłka nie wystartowała — powód (kampania wróciła do projektu). */
  schedule_error?: string | null
  sending_started_at: string | null
  sent_at: string | null
  totals: CampaignTotals | null
  items: CampaignItem[]
  warnings: string[]
  /** null = projekt (jeszcze nie wysyłany). */
  sales?: CampaignSales | null
  /** Kliknięcia w linki maila (ludzie; skanery poczty tylko w `bots`); null = projekt. */
  clicks?: CampaignClicks | null
  /** Odpowiedzi klientów ze skrzynki autora (IMAP, tylko nagłówki); null = projekt. */
  replies?: CampaignReplies | null
}

export type CampaignReplies = {
  total: number
  /** Ilu odbiorców kampanii odpowiedziało (odpowiedzi z nieznanych adresów liczą się tylko w `total`). */
  recipients: number
  /** Odczyt odpowiedzi włączony w „Moja poczta” autora. */
  enabled: boolean
  checked_at: string | null
  error: string | null
  /** Najnowsze 100. */
  list: CampaignReplyRow[]
}

export type CampaignReplyRow = {
  id: number
  from_email: string
  from_name: string | null
  subject: string
  /** Kod towaru z tematu „Zapytanie K-… KOD” — tylko gdy to jeden z kodów pozycji kampanii. */
  item_code: string | null
  /** code = temat z przycisku „Zapytaj o ofertę”, thread = odpowiedź na mail kampanii. */
  matched_by: 'code' | 'thread'
  received_at: string | null
  recipient_email: string | null
}

export type CampaignClicks = {
  /** Ilu odbiorców kliknęło. */
  recipients: number
  total: number
  bots: number
  /** offer = „Zapytaj o ofertę”, product = strona produktu, link = drugi przycisk pozycji. */
  items: { campaign_item_id: number; offer: number; product: number; link: number }[]
}

export type CampaignPatch = Partial<
  Pick<
    Campaign,
    'name' | 'subject' | 'preheader' | 'heading' | 'intro' | 'layout' | 'valid_until' | 'audience' | 'blocks' | 'brand_color'
  >
>

export type CampaignItemPatch = Partial<{
  promo_price_net: number | null
  price_before_net: number | null
  note: string | null
  description: string | null
  /** Drugi przycisk: wszystkie trzy razem; pusty link_url usuwa przycisk. */
  link_url: string | null
  link_label: string | null
  link_color: string | null
  /** null = główna karta towaru XL. */
  product_id: number | null
  position: number
}>

export type AudiencePreview = {
  lists: { contacts: number }
  xl: { customers: number; emails: number }
  total_raw: number
  duplicates: number
  invalid: number
  excluded_generic: number
  suppressed: number
  capped: number
  /** Już odbiorcy tej kampanii (dopisywanie do wysłanej) — nie dostaną maila drugi raz. */
  already?: number
  final: number
  without_mailbox: boolean
  sample: { email: string; name: string | null; source: 'list' | 'xl' }[]
  /** Dlaczego część odbiorców odpadła, np. „nie masz przypisanego operatora XL”. */
  warnings?: string[]
}

export type CampaignPreview = {
  subject: string
  preheader: string | null
  from: { name: string; address: string } | null
  html: string
}

export type CampaignRecipientStatus = 'pending' | 'sending' | 'sent' | 'failed' | 'skipped'

export type CampaignRecipientRow = {
  id: number
  email: string
  name: string | null
  source: 'list' | 'xl'
  status: CampaignRecipientStatus
  error: string | null
  sent_at: string | null
  unsubscribed_at: string | null
  first_clicked_at?: string | null
  clicks?: number
  replied_at?: string | null
  /** Kiedy trafił do kampanii — później niż start wysyłki = dopisany. */
  created_at?: string | null
}

export type MailingList = {
  id: number
  name: string
  is_shared: boolean
  owner: { id: number; name: string }
  contacts_count: number
  basis_counts: { customer: number; consent: number }
  can_edit: boolean
  updated_at: string
}

export type MailingListContact = {
  id: number
  email: string
  name: string | null
  company: string | null
  basis: ContactBasis
  basis_note: string | null
  added_at: string
  suppressed: boolean
}

export type ImportResult = {
  added: number
  already: number
  invalid: string[]
  suppressed: number
  /** Tylko import z pliku: */
  invalid_count?: number
  duplicates?: number
  empty?: number
  no_consent?: number
}

/** Pole kontaktu, do którego użytkownik przypisuje kolumnę pliku. */
export type ContactImportField = 'email' | 'first_name' | 'last_name' | 'name' | 'company' | 'consent'

export type ContactFilePreview = {
  file_name: string
  sheets: string[]
  sheet: number
  /** Niepuste wiersze arkusza razem z nagłówkiem. */
  total_rows: number
  max_rows: number
  has_header: boolean
  column_count: number
  /** Pierwsze wiersze (z nagłówkiem), każdy dopełniony do column_count. */
  rows: string[][]
  suggested: (ContactImportField | null)[]
}

export type EmailSuppression = {
  id: number
  email: string
  reason: 'unsubscribe' | 'bounce' | 'manual'
  note: string | null
  campaign: { id: number; code: string } | null
  created_at: string
}

export type UserMailAccount = {
  configured: boolean
  from_name: string | null
  from_address: string | null
  host: string | null
  port: number
  scheme: 'smtp' | 'smtps' | null
  username: string | null
  has_password: boolean
  verify_peer: boolean
  rate_per_hour: number
  copy_to_self: boolean
  signature: string | null
  verified_at: string | null
  last_error: string | null
  /** Liczenie odpowiedzi: odczyt nagłówków skrzynki (IMAP, tylko do odczytu). */
  imap_enabled: boolean
  /** null = ten sam serwer co SMTP. */
  imap_host: string | null
  imap_port: number
  imap_checked_at: string | null
  imap_error: string | null
}

export type UserMailAccountInput = {
  from_name: string
  from_address: string
  host: string
  port: number
  scheme: 'smtp' | 'smtps' | null
  username: string
  /** Puste = bez zmiany zapisanego hasła. */
  password?: string
  verify_peer: boolean
  rate_per_hour: number
  copy_to_self: boolean
  signature: string | null
  imap_enabled?: boolean
  imap_host?: string | null
}

const json = (body: unknown): RequestInit => ({ body: JSON.stringify(body) })

export function listCampaigns(params: { scope?: 'mine' | 'all'; status?: CampaignStatus | ''; page?: number } = {}) {
  const q = new URLSearchParams()
  if (params.scope) q.set('scope', params.scope)
  if (params.status) q.set('status', params.status)
  if (params.page) q.set('page', String(params.page))
  const qs = q.toString()
  return api<{ data: CampaignListRow[]; meta: PageMeta }>(`/campaigns${qs ? `?${qs}` : ''}`)
}

export function createCampaign(body: { name?: string; erp_item_ids?: number[]; product_ids?: number[] } = {}) {
  return api<Campaign>('/campaigns', { method: 'POST', ...json(body) })
}

export function getCampaign(id: number) {
  return api<Campaign>(`/campaigns/${id}`)
}

export function updateCampaign(id: number, patch: CampaignPatch) {
  return api<Campaign>(`/campaigns/${id}`, { method: 'PATCH', ...json(patch) })
}

export function deleteCampaign(id: number) {
  return api<{ message?: string }>(`/campaigns/${id}`, { method: 'DELETE' })
}

export function duplicateCampaign(id: number) {
  return api<Campaign>(`/campaigns/${id}/duplicate`, { method: 'POST' })
}

/** Pozycja rankingu „Zaproponuj pozycje” — zalegający towar z punktami i powodami. */
export type CampaignSuggestion = {
  erp_item_id: number
  code: string
  name: string
  unit: string
  stock: number
  stock_value: number | null
  last_sale_at: string | null
  oldest_lot_at: string | null
  /** Klienci z e-mailem, którzy kupowali towar w 24 mies. */
  buyers: number
  card: { id: number; sku: string; name: string; thumb_url: string | null } | null
  has_description: boolean
  score: number
  reasons: string[]
}

export function campaignSuggestions(id: number, mine: boolean) {
  return api<{ data: CampaignSuggestion[]; free: number; mine_available: boolean; min_months: number }>(
    `/campaigns/${id}/suggestions${mine ? '?mine=1' : ''}`,
  )
}

export function addCampaignItems(id: number, body: { erp_item_ids?: number[]; product_ids?: number[] }) {
  return api<Campaign>(`/campaigns/${id}/items`, { method: 'POST', ...json(body) })
}

export function updateCampaignItem(id: number, itemId: number, patch: CampaignItemPatch) {
  return api<Campaign>(`/campaigns/${id}/items/${itemId}`, { method: 'PATCH', ...json(patch) })
}

export function removeCampaignItem(id: number, itemId: number) {
  return api<Campaign>(`/campaigns/${id}/items/${itemId}`, { method: 'DELETE' })
}

export function campaignAudience(id: number) {
  return api<AudiencePreview>(`/campaigns/${id}/audience`)
}

export function campaignPreview(id: number) {
  return api<CampaignPreview>(`/campaigns/${id}/preview`)
}

export function sendCampaignTest(id: number, email?: string) {
  return api<{ message: string }>(`/campaigns/${id}/test`, { method: 'POST', ...json(email ? { email } : {}) })
}

/**
 * Start wysyłki. `recipientsChecksum` z listy pokazanej przed wysyłką — gdy lista na serwerze jest już inna,
 * serwer odpowiada 422 (errors.recipients_checksum) i nic nie wysyła.
 */
export function sendCampaign(id: number, recipientsChecksum?: string) {
  return api<Campaign>(`/campaigns/${id}/send`, {
    method: 'POST',
    ...(recipientsChecksum ? json({ recipients_checksum: recipientsChecksum }) : {}),
  })
}

/** Dopisanie odbiorców do wysłanej albo wysyłanej kampanii — tylko nowi z listy pokazanej w oknie (checksum). */
export function addCampaignRecipients(id: number, recipientsChecksum: string) {
  return api<{ added: number; campaign: Campaign }>(`/campaigns/${id}/recipients/add`, {
    method: 'POST',
    ...json({ recipients_checksum: recipientsChecksum }),
  })
}

export type AudienceSkipReason = 'invalid' | 'generic' | 'suppressed' | 'capped'

/** Adres z listy przed wysyłką; origin = nazwa grupy (source list) albo akronim klienta XL (source xl). */
export type AudienceRecipientRow = {
  email: string
  name: string | null
  source: 'list' | 'xl'
  origin: string
  /** null = wyślemy; inaczej powód pominięcia. */
  reason: AudienceSkipReason | null
}

/** Dokładna lista odbiorców przed „Wyślij teraz” / „Zaplanuj”; meta dotyczy wybranego widoku po szukaniu. */
export type AudienceRecipients = {
  summary: {
    /** Wyślemy do. */
    total: number
    from_lists: number
    from_xl: number
    duplicates: number
    /** Już odbiorcy tej kampanii (dopisywanie do wysłanej). */
    already?: number
    skipped: Record<AudienceSkipReason, number>
  }
  /** Odcisk listy „do wysyłki” — idzie z „Wyślij”, serwer sprawdza, że lista się nie zmieniła. */
  checksum: string
  warnings: string[]
  data: AudienceRecipientRow[]
  meta: PageMeta
}

export function campaignAudienceRecipients(
  id: number,
  params: { view?: 'send' | 'skipped'; search?: string; page?: number; per_page?: number } = {},
) {
  const q = new URLSearchParams()
  if (params.view) q.set('view', params.view)
  if (params.search) q.set('search', params.search)
  if (params.page) q.set('page', String(params.page))
  if (params.per_page) q.set('per_page', String(params.per_page))
  const qs = q.toString()
  return api<AudienceRecipients>(`/campaigns/${id}/audience/recipients${qs ? `?${qs}` : ''}`)
}

/** Zaplanowanie wysyłki; `at` = ISO z przesunięciem strefy (np. new Date(...).toISOString()). */
export function scheduleCampaign(id: number, at: string) {
  return api<Campaign>(`/campaigns/${id}/schedule`, { method: 'POST', ...json({ scheduled_at: at }) })
}

export function unscheduleCampaign(id: number) {
  return api<Campaign>(`/campaigns/${id}/unschedule`, { method: 'POST' })
}

export function cancelCampaign(id: number) {
  return api<Campaign>(`/campaigns/${id}/cancel`, { method: 'POST' })
}

/** „Sprawdź skrzynkę teraz” — odczyt odpowiedzi ze skrzynki autora od razu (zwykle co 10 minut). */
export function checkCampaignReplies(id: number) {
  return api<{ ok: boolean; new: number; message: string; replies: CampaignReplies }>(`/campaigns/${id}/replies/check`, {
    method: 'POST',
  })
}

export function campaignRecipients(
  id: number,
  params: { status?: CampaignRecipientStatus | ''; clicked?: boolean; page?: number; per_page?: number } = {},
) {
  const q = new URLSearchParams()
  if (params.status) q.set('status', params.status)
  if (params.clicked) q.set('clicked', '1')
  if (params.per_page) q.set('per_page', String(params.per_page))
  if (params.page) q.set('page', String(params.page))
  const qs = q.toString()
  return api<{ data: CampaignRecipientRow[]; meta: PageMeta }>(`/campaigns/${id}/recipients${qs ? `?${qs}` : ''}`)
}

/** Zastąpienie treści maila szablonem (tylko projekt); null = Standard SUPON. */
export function applyTemplate(id: number, templateId: number | null) {
  return api<Campaign>(`/campaigns/${id}/template`, { method: 'POST', ...json({ template_id: templateId }) })
}

/** Podgląd z niezapisanymi elementami i prawdziwymi pozycjami kampanii — nic nie zapisuje. */
export function previewDraft(id: number, body: { blocks: CampaignBlock[]; brand_color: string | null }) {
  return api<CampaignPreview>(`/campaigns/${id}/preview-draft`, { method: 'POST', ...json(body) })
}

export function listTemplates() {
  return api<{ data: CampaignTemplate[] }>('/campaign-templates')
}

export function createTemplate(body: {
  name: string
  blocks: CampaignBlock[]
  brand_color?: string | null
  is_shared?: boolean
}) {
  return api<CampaignTemplate>('/campaign-templates', { method: 'POST', ...json(body) })
}

export function updateTemplate(
  id: number,
  body: Partial<{ name: string; blocks: CampaignBlock[]; brand_color: string | null; is_shared: boolean }>,
) {
  return api<CampaignTemplate>(`/campaign-templates/${id}`, { method: 'PATCH', ...json(body) })
}

export function deleteTemplate(id: number) {
  return api<{ message?: string }>(`/campaign-templates/${id}`, { method: 'DELETE' })
}

/** Podgląd szablonu z przykładowymi produktami. */
export function previewTemplate(body: { blocks: CampaignBlock[]; brand_color: string | null }) {
  return api<{ subject: string; html: string; text: string }>('/campaign-templates/preview', { method: 'POST', ...json(body) })
}

/** Obrazek do maila (logo, grafika): serwer zmniejsza do 1200 px i zapisuje jako JPG albo PNG. */
export function uploadCampaignAsset(file: File) {
  const form = new FormData()
  form.append('file', file)
  return api<CampaignAsset>('/campaign-assets', { method: 'POST', body: form })
}

export function listMailingLists() {
  return api<{ data: MailingList[] }>('/mailing-lists')
}

export function createMailingList(body: { name: string; is_shared?: boolean }) {
  return api<MailingList>('/mailing-lists', { method: 'POST', ...json(body) })
}

export function updateMailingList(id: number, body: { name?: string; is_shared?: boolean }) {
  return api<MailingList>(`/mailing-lists/${id}`, { method: 'PATCH', ...json(body) })
}

export function deleteMailingList(id: number) {
  return api<{ message?: string }>(`/mailing-lists/${id}`, { method: 'DELETE' })
}

export function mailingListContacts(id: number, params: { search?: string; page?: number } = {}) {
  const q = new URLSearchParams()
  if (params.search) q.set('search', params.search)
  if (params.page) q.set('page', String(params.page))
  const qs = q.toString()
  return api<{ data: MailingListContact[]; meta: PageMeta }>(`/mailing-lists/${id}/contacts${qs ? `?${qs}` : ''}`)
}

export function importMailingListContacts(id: number, body: { text: string; basis: ContactBasis; basis_note?: string }) {
  return api<ImportResult>(`/mailing-lists/${id}/import`, { method: 'POST', ...json(body) })
}

function contactFileForm(file: File, sheet: number) {
  const form = new FormData()
  form.append('file', file)
  form.append('sheet', String(sheet))
  return form
}

export function previewMailingListFile(id: number, file: File, sheet = 0) {
  return api<ContactFilePreview>(`/mailing-lists/${id}/import-file/preview`, { method: 'POST', body: contactFileForm(file, sheet) })
}

export function importMailingListFile(
  id: number,
  body: {
    file: File
    sheet: number
    has_header: boolean
    mapping: Partial<Record<ContactImportField, number>>
    basis: ContactBasis
    basis_note?: string
  },
) {
  const form = contactFileForm(body.file, body.sheet)
  form.append('has_header', body.has_header ? '1' : '0')
  for (const [field, column] of Object.entries(body.mapping)) {
    if (column !== undefined) form.append(`mapping[${field}]`, String(column))
  }
  form.append('basis', body.basis)
  if (body.basis_note) form.append('basis_note', body.basis_note)
  return api<ImportResult>(`/mailing-lists/${id}/import-file`, { method: 'POST', body: form })
}

export function removeMailingListContact(id: number, contactId: number) {
  return api<{ message?: string }>(`/mailing-lists/${id}/contacts/${contactId}`, { method: 'DELETE' })
}

export function listSuppressions(params: { search?: string; page?: number } = {}) {
  const q = new URLSearchParams()
  if (params.search) q.set('search', params.search)
  if (params.page) q.set('page', String(params.page))
  const qs = q.toString()
  return api<{ data: EmailSuppression[]; meta: PageMeta }>(`/email-suppressions${qs ? `?${qs}` : ''}`)
}

export function addSuppression(body: { email: string; note?: string }) {
  return api<EmailSuppression>('/email-suppressions', { method: 'POST', ...json(body) })
}

export function deleteSuppression(id: number) {
  return api<{ message?: string }>(`/email-suppressions/${id}`, { method: 'DELETE' })
}

export function getMailAccount() {
  return api<UserMailAccount>('/me/mail-account')
}

export function saveMailAccount(body: UserMailAccountInput) {
  return api<UserMailAccount>('/me/mail-account', { method: 'PUT', ...json(body) })
}

export function testMailAccount() {
  return api<{ ok: boolean; message: string; imap?: { ok: boolean; message: string } | null }>('/me/mail-account/test', { method: 'POST' })
}

export type XlCustomerSort = 'documents' | 'last_sale' | 'acronym' | 'name' | 'city'

export type XlCustomerRow = {
  id: number
  acronym: string
  name: string | null
  city: string | null
  nip: string | null
  /** skipped: suppressed = wypisany z mailingu, generic = faktury@/księgowość@ — ten adres nie dostanie maila. */
  emails: { email: string; skipped: 'suppressed' | 'generic' | null }[]
  last_sale_at: string | null
  documents_24m: number
  main_operator: string | null
  /** Tylko „kupowali te towary”: ile pozycji kampanii ten klient kupował. */
  matched_items: number | null
}

export type XlCustomersResponse = {
  data: XlCustomerRow[]
  meta: PageMeta
  /** Wszyscy klienci kategorii (do „zaznacz wszystkich”). */
  ids: number[]
  /** Zapisany wybór tej samej kategorii; null = cała kategoria (albo zapisano inną kategorię). */
  selected_ids: number[] | null
  warnings: string[]
}

export function campaignXlCustomers(
  id: number,
  params: {
    mode: CampaignXlMode
    months: 12 | 24
    only_mine: boolean
    search?: string
    sort?: XlCustomerSort
    dir?: 'asc' | 'desc'
    page?: number
    per_page?: number
  },
) {
  const q = new URLSearchParams({ mode: params.mode, months: String(params.months), only_mine: params.only_mine ? '1' : '0' })
  if (params.search) q.set('search', params.search)
  if (params.sort) q.set('sort', params.sort)
  if (params.dir) q.set('dir', params.dir)
  if (params.page) q.set('page', String(params.page))
  if (params.per_page) q.set('per_page', String(params.per_page))
  return api<XlCustomersResponse>(`/campaigns/${id}/xl-customers?${q.toString()}`)
}

/** Adres grupy w oknie „Pokaż / wybierz” kampanii. */
export type ListContactRow = {
  id: number
  email: string
  name: string | null
  company: string | null
  basis: 'customer' | 'consent'
  basis_note: string | null
  added_at: string | null
  /** suppressed = wypisany z mailingu — nie dostanie maila. */
  skipped: 'suppressed' | null
  /** Inne wybrane grupy kampanii, w których ten adres jest (i nie jest odznaczony). */
  also_in: string[]
}

export type ListContactsResponse = {
  list: { id: number; name: string }
  data: ListContactRow[]
  meta: PageMeta
  /** Wszystkie adresy grupy (do „zaznacz wszystkich”). */
  ids: number[]
  /** Zapisane odznaczenia tej grupy w kampanii. */
  excluded_ids: number[]
}

export function campaignListContacts(
  id: number,
  params: { list_id: number; search?: string; page?: number; per_page?: number },
) {
  const q = new URLSearchParams({ list_id: String(params.list_id) })
  if (params.search) q.set('search', params.search)
  if (params.page) q.set('page', String(params.page))
  if (params.per_page) q.set('per_page', String(params.per_page))
  return api<ListContactsResponse>(`/campaigns/${id}/list-contacts?${q.toString()}`)
}

export type ErpOperator = { ident: string; name: string | null; customers: number; user: { id: number; name: string } | null }

export function listErpOperators() {
  return api<{ data: ErpOperator[] }>('/admin/erp-operators')
}

/** Kwota netto po polsku: „89,00 zł”. */
export function formatPln(value: number | null | undefined): string {
  if (value === null || value === undefined || Number.isNaN(value)) return '—'
  return `${value.toLocaleString('pl-PL', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} zł`
}
