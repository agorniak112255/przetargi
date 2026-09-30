import { api } from './api'

/** Kampanie reklamowe — typy i wywołania API (kontrakt pierwszego wydania, 01.10.2026). */

export type CampaignStatus = 'draft' | 'sending' | 'sent' | 'cancelled'
export type CampaignLayout = 'grid3' | 'grid2' | 'list'
export type CampaignXlMode = 'items' | 'group' | 'mine'
export type ContactBasis = 'customer' | 'consent'

export const CAMPAIGN_STATUS_LABEL: Record<CampaignStatus, string> = {
  draft: 'Projekt',
  sending: 'Wysyłka',
  sent: 'Wysłana',
  cancelled: 'Anulowana',
}

export type PageMeta = { current_page: number; last_page: number; per_page: number; total: number }

export type CampaignAudience = {
  list_ids: number[]
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
  created_at: string
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
  status: CampaignStatus
  audience: CampaignAudience
  author: { id: number; name: string }
  can_edit: boolean
  created_at: string
  updated_at: string
  sending_started_at: string | null
  sent_at: string | null
  totals: CampaignTotals | null
  items: CampaignItem[]
  warnings: string[]
  /** null = projekt (jeszcze nie wysyłany). */
  sales?: CampaignSales | null
}

export type CampaignPatch = Partial<
  Pick<Campaign, 'name' | 'subject' | 'preheader' | 'heading' | 'intro' | 'layout' | 'valid_until' | 'audience'>
>

export type CampaignItemPatch = Partial<{
  promo_price_net: number | null
  price_before_net: number | null
  note: string | null
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

export type ImportResult = { added: number; already: number; invalid: string[]; suppressed: number }

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

export function sendCampaign(id: number) {
  return api<Campaign>(`/campaigns/${id}/send`, { method: 'POST' })
}

export function cancelCampaign(id: number) {
  return api<Campaign>(`/campaigns/${id}/cancel`, { method: 'POST' })
}

export function campaignRecipients(id: number, params: { status?: CampaignRecipientStatus | ''; page?: number } = {}) {
  const q = new URLSearchParams()
  if (params.status) q.set('status', params.status)
  if (params.page) q.set('page', String(params.page))
  const qs = q.toString()
  return api<{ data: CampaignRecipientRow[]; meta: PageMeta }>(`/campaigns/${id}/recipients${qs ? `?${qs}` : ''}`)
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
  return api<{ ok: boolean; message: string }>('/me/mail-account/test', { method: 'POST' })
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

export type ErpOperator = { ident: string; name: string | null; customers: number; user: { id: number; name: string } | null }

export function listErpOperators() {
  return api<{ data: ErpOperator[] }>('/admin/erp-operators')
}

/** Kwota netto po polsku: „89,00 zł”. */
export function formatPln(value: number | null | undefined): string {
  if (value === null || value === undefined || Number.isNaN(value)) return '—'
  return `${value.toLocaleString('pl-PL', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} zł`
}
