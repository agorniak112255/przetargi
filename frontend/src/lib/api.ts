import { publicDir } from './publicDir'
import { getToken } from './tokenStore'
import type { CheckSource } from '../components/RequirementCheckList'

const API_URL = `${publicDir()}/api`

function token(): string | null {
  return getToken()
}

/**
 * Błąd odpowiedzi API z zachowanym kodem HTTP i ciałem odpowiedzi.
 * Dziedziczy po Error, więc dotychczasowe `ex instanceof Error ? ex.message : …`
 * działa bez zmian; potrzebne tam, gdzie liczy się treść błędu (np. 409 przy
 * zapytaniu założonym już przez kogoś innego).
 */
export class ApiError extends Error {
  readonly status: number
  readonly body: Record<string, unknown>

  constructor(message: string, status: number, body: Record<string, unknown>) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.body = body
  }
}

/** Zdarzenie okna: klucz odrzucony przez dostęp z sieci (detail = komunikat serwera). */
export const NETWORK_BLOCKED_EVENT = 'supon:network-blocked'

export async function api<T>(path: string, options: RequestInit = {}): Promise<T> {
  const headers = new Headers(options.headers)
  headers.set('Accept', 'application/json')
  if (options.body && !(options.body instanceof FormData)) {
    headers.set('Content-Type', 'application/json')
  }
  const t = token()
  if (t) {
    headers.set('Authorization', `Bearer ${t}`)
  }

  const res = await fetch(`${API_URL}${path}`, { ...options, headers })
  const text = await res.text()
  let body: Record<string, unknown> = {}
  if (text) {
    try {
      body = JSON.parse(text) as Record<string, unknown>
    } catch {
      throw new Error(
        res.ok
          ? 'Odpowiedź serwera jest uszkodzona lub zbyt duża (JSON). Spróbuj ponownie — dla XLSX użyj analizy AI (mapowanie) albo Import prosty.'
          : `Błąd API ${res.status}: niepoprawna odpowiedź serwera.`,
      )
    }
  }
  if (!res.ok) {
    const errors = body.errors as Record<string, string[]> | undefined
    const msg =
      (typeof body.message === 'string' ? body.message : null) ??
      (errors ? Object.values(errors).flat().join(' ') : null) ??
      `Błąd API ${res.status}`
    // konto „tylko z sieci lokalnej” poza nią — serwer już nie przyjmuje klucza; auth.tsx wylogowuje z tym komunikatem
    if (res.status === 401 && body.reason === 'network') {
      window.dispatchEvent(new CustomEvent<string>(NETWORK_BLOCKED_EVENT, { detail: String(msg) }))
    }
    throw new ApiError(String(msg), res.status, body)
  }
  return body as T
}

export type User = {
  id: number
  name: string
  email: string
  role: string
  roles: string[]
  permissions: string[]
  /** Wybrany wygląd; null = użytkownik jeszcze nie wybrał (obowiązuje zapis z tego komputera). */
  ui_preferences?: {
    template: string | null
    mode: 'light' | 'dark' | 'system' | null
  }
  /** Marża, z którą startuje każda nowa odpowiedź na zapytanie (w procentach). */
  default_margin_percent?: number
  /** Zakres raportu „Wynik kampanii” (CampaignReportScope); null = brak dostępu. */
  campaign_report_scope?: 'own' | 'team' | 'all' | null
}

/**
 * Ceny specjalne kont B2B (cena konta niższa niż cennik bazowy po rabacie standardowym). Bez uprawnienia backend
 * wszędzie podaje cenę standardową, a historię cen takich kont bez kwot (prices_hidden).
 */
export const PERM_SUPPLIER_SPECIAL_VIEW = 'prices.supplier_special.view'

export function can(user: User | null | undefined, permission: string): boolean {
  return Boolean(user?.permissions?.includes(permission))
}

export function canAny(user: User | null | undefined, permissions: string[]): boolean {
  return permissions.some((p) => can(user, p))
}

export type Tender = {
  id: number
  number: string
  title: string
  status: string
  ai_percent: number
  offer_value_net: string | null
  margin_percent: string | null
  target_margin_percent: string | number | null
  deadline: string | null
  /** Godzina składania ofert w czasie polskim „HH:MM”; null = nie wpisano (formatowanie: lib/tenderDeadline). */
  deadline_time?: string | null
  /** Numer ogłoszenia (Biuletyn „2026/BZP 00431178/01” albo TED „606345-2026”) — osobno od wewnętrznego `number`. */
  notice_number?: string | null
  notice_source?: NoticeSource | null
  /** Wynik przetargu w całości, wyliczany z części; null = bez wyniku. */
  result_status?: TenderResultStatus | null
  last_activity_at: string | null
  items_count?: number
  client?: { id: number; name: string }
  owner?: { id: number; name: string }
}

export type NoticeSource = 'bzp' | 'ted'

/* ---------- Wynik przetargu (GET/PUT /tenders/{id}/result) ---------- */

/** won = wygrany, partial = częściowo wygrany, lost = przegrany, cancelled = unieważniony, not_submitted = nie złożyliśmy oferty */
export type TenderResultStatus = 'won' | 'partial' | 'lost' | 'cancelled' | 'not_submitted'

export type LotOutcome = 'won' | 'lost' | 'cancelled' | 'not_submitted'

/** cena / nie spełniliśmy wymagania / termin dostawy / błąd formalny / inny */
export type LossReason = 'price' | 'requirement' | 'delivery' | 'formal' | 'other'

export type Competitor = {
  id: number
  name: string
  nip: string | null
}

/** Firma z listy podpowiedzi (competitor_id) albo nowa, wpisana ręcznie (name + opcjonalny NIP). */
export type CompetitorInput = { competitor_id: number } | { name: string; nip?: string | null }

export type TenderLotOffer = {
  id: number
  competitor: Competitor
  /** kwota jak w źródle, jako tekst „1234.56” */
  price: string
  currency: string
  source: 'manual' | 'bzp'
}

export type TenderLot = {
  /** null = część wirtualna (przetarg bez zapisanych części), powstaje przy pierwszym zapisie */
  id: number | null
  lot_no: number
  name: string | null
  cpv_main: string | null
  estimated_value: string | null
  our_net: string | null
  our_vat_rate: string | null
  /** brutto wyliczone jawnie z our_net i our_vat_rate */
  our_gross: string | null
  outcome: LotOutcome | null
  winner: Competitor | null
  /** NIP zwycięzcy, gdy nie przeszedł sprawdzenia sumy kontrolnej — dokładnie jak w źródle */
  winner_national_id_raw: string | null
  winner_price: string | null
  currency: string
  offers_count: number | null
  lowest_price: string | null
  highest_price: string | null
  loss_reason: LossReason | null
  note: string | null
  /** nasza cena brutto minus cena zwycięzcy z ogłoszenia (kwota jako tekst); procent liczony od naszej ceny brutto; null bez stawki VAT */
  price_gap: { amount: string; percent: number } | null
  /** pola wpisane przez człowieka — Biuletyn ich nie nadpisuje */
  manual_fields: string[]
  /** pola wypełnione z Biuletynu */
  bzp_fields: string[]
  bzp_notice_number: string | null
  /** sprzeczność Biuletynu z wpisem człowieka (opis dla użytkownika) */
  bzp_conflict: string | null
  decided_by: { id: number; name: string } | null
  decided_at: string | null
  offers: TenderLotOffer[]
}

export type BzpNoticeRef = {
  id: number
  notice_number: string
  published_at: string | null
  url: string | null
}

export type TenderResultResponse = {
  tender_id: number
  result_status: TenderResultStatus | null
  can_edit: boolean
  notice_number: string | null
  notice_source: NoticeSource | null
  bzp: {
    contract_notice: BzpNoticeRef | null
    result_notice: BzpNoticeRef | null
    checked_at: string | null
  }
  lots: TenderLot[]
  /** tylko po „Sprawdź w Biuletynie” */
  bzp_message?: string
}

/** Kwoty można wysłać jako liczbę albo tekst; pominięte pole = bez zmian, null = wyczyść. */
export type TenderLotUpdate = {
  id?: number | null
  lot_no: number
  name?: string | null
  cpv_main?: string | null
  estimated_value?: string | number | null
  our_net?: string | number | null
  our_vat_rate?: string | number | null
  outcome?: LotOutcome | null
  winner?: CompetitorInput | null
  winner_national_id_raw?: string | null
  winner_price?: string | number | null
  currency?: string
  offers_count?: number | null
  lowest_price?: string | number | null
  highest_price?: string | number | null
  /** tylko przy outcome = lost */
  loss_reason?: LossReason | null
  note?: string | null
  /** oferty innych firm — zastępowane w całości */
  offers?: (CompetitorInput & { price: string | number; currency?: string })[]
  /**
   * true = człowiek potwierdził numer części (np. „startowaliśmy w części 1 ogłoszenia wieloczęściowego”) —
   * Biuletyn wpisze dane tej części; false = zdejmij potwierdzenie. Zmiana lot_no bez true zdejmuje je na serwerze.
   */
  lot_no_confirmed?: boolean
}

export function fetchTenderResult(tenderId: number): Promise<TenderResultResponse> {
  return api<TenderResultResponse>(`/tenders/${tenderId}/result`)
}

export function saveTenderResult(tenderId: number, lots: TenderLotUpdate[]): Promise<TenderResultResponse> {
  return api<TenderResultResponse>(`/tenders/${tenderId}/result`, { method: 'PUT', body: JSON.stringify({ lots }) })
}

export function deleteTenderLot(tenderId: number, lotId: number): Promise<{ ok: true }> {
  return api<{ ok: true }>(`/tenders/${tenderId}/result/lots/${lotId}`, { method: 'DELETE' })
}

/** Dopasowanie do ogłoszeń z Biuletynu już zapisanych w bazie (bez zapytań do Biuletynu). */
export function checkTenderBzp(tenderId: number): Promise<TenderResultResponse> {
  return api<TenderResultResponse>(`/tenders/${tenderId}/result/bzp-check`, { method: 'POST' })
}

/** Podpowiedzi firm konkurencji (najwyżej 20). */
export function searchCompetitors(q: string): Promise<Competitor[]> {
  return api<{ data: Competitor[] }>(`/competitors?q=${encodeURIComponent(q)}`).then((r) => r.data)
}

/* ---------- Wzmianki „@” w komentarzach przetargu ---------- */

export type MentionCandidate = {
  id: number
  name: string
  /** rola słowami (np. „Opiekun przetargu”, „Zaproszony”) */
  role: string
}

export function fetchMentionCandidates(tenderId: number): Promise<MentionCandidate[]> {
  return api<{ data: MentionCandidate[] }>(`/tenders/${tenderId}/mention-candidates`).then((r) => r.data)
}

/**
 * Los e-maila o zaproszeniu do przetargu (POST /tenders/{t}/invitations): wysłany, błąd poczty albo osoba
 * wyłączyła e-maile o zaproszeniach (powiadomienie w aplikacji dostała).
 */
export type InvitationEmailStatus = 'sent' | 'failed' | 'opted_out'

export type TenderInvitationCreateResponse = {
  email_sent: boolean
  /** starsze odpowiedzi serwera go nie mają — wtedy liczy się email_sent */
  email_status?: InvitationEmailStatus
}

/* ---------- Powiadomienia (Moje konto › Powiadomienia, dzwonek) ---------- */

export type NotificationEventKey =
  | 'tender_deadline'
  | 'tender_result_needed'
  | 'tender_mention'
  | 'tender_invitation'
  | 'inquiry_analysis_ready'
  | 'campaign_reply'
  | 'client_note_reminder'
  | 'offer_validity_ending'
  | 'system_alert'

/** 7 dni przed / 3 dni przed / ostatni dzień roboczy przed / w dniu terminu 3 godziny przed (wymaga godziny) */
export type DeadlineOffset = '7d' | '3d' | 'last_workday' | '3h'

export type NotificationPreferences = {
  events: {
    key: NotificationEventKey
    label: string
    description: string
    bell: boolean
    mail: boolean
    default_bell: boolean
    default_mail: boolean
  }[]
  deadline_offsets: DeadlineOffset[]
  deadline_offset_options: { key: DeadlineOffset; label: string; needs_time: boolean }[]
  /** adres, na który przychodzą e-maile */
  email: string
  /** false = poczta wychodząca aplikacji nie jest skonfigurowana (e-maile nie wyjdą) */
  mail_configured: boolean
}

export type NotificationPreferencesUpdate = {
  events: Partial<Record<NotificationEventKey, { bell: boolean; mail: boolean }>>
  deadline_offsets: DeadlineOffset[]
}

export function fetchNotificationPreferences(): Promise<NotificationPreferences> {
  return api<NotificationPreferences>('/me/notification-preferences')
}

export function saveNotificationPreferences(body: NotificationPreferencesUpdate): Promise<NotificationPreferences> {
  return api<NotificationPreferences>('/me/notification-preferences', { method: 'PUT', body: JSON.stringify(body) })
}

/**
 * notifications.data powiadomienia w dzwonku. Nowe rodzaje mają title/body/url; stare (zaproszenie, błąd
 * kampanii) tylko message i własne pola — dzwonek pokazuje title ?? message, a link url ?? jak dotąd.
 */
export type AppNotificationData = {
  type?: string
  title?: string
  body?: string
  /** ścieżka w aplikacji, np. „/tenders/12?tab=wynik” */
  url?: string
  message?: string
  tender_id?: number
  tender_number?: string
  tender_title?: string
  campaign_id?: number
  inquiry_id?: number
}

/* ---------- Administracja › Stan systemu ---------- */

export type SystemGapKind =
  | 'tenders_without_time'
  | 'tenders_without_notice'
  | 'salespeople_without_operator'
  | 'clients_without_xl'
  | 'sold_items_without_card'
  | 'clients_without_manager'
  | 'salespeople_without_employee'

export type SystemAlertRow = {
  id: number
  kind: string
  title: string
  /** początek problemu; null, gdy serwer go nie zna */
  since: string | null
  last_message: string | null
  emailed_at: string | null
  muted: boolean
  url: string | null
}

export type SystemStatus = {
  checked_at: string
  scheduler: { last_seen_at: string | null; ok: boolean }
  tiles: {
    tasks: { ok: number; total: number; last_finished_at: string | null }
    b2b: { ok: number; total: number; failing: number }
    inquiries_queue: { waiting: number; oldest_wait_seconds: number | null }
    model: { searches_24h: number; avg_seconds: number | null; last_at: string | null } | null
  }
  alerts: SystemAlertRow[]
  tasks: {
    task: string
    label: string
    /** kiedy zadanie rusza, słowami w czasie polskim */
    schedule_pl: string
    enabled: boolean
    last: {
      started_at: string | null
      finished_at: string | null
      duration_ms: number | null
      status: string
      output_tail: string | null
    } | null
  }[]
  gaps: { kind: SystemGapKind; label: string; count: number }[]
}

export type SystemGapRow = {
  id: number
  label: string
  detail: string | null
  url: string | null
}

export function fetchSystemStatus(): Promise<SystemStatus> {
  return api<SystemStatus>('/admin/system-status')
}

/** Stan systemu › „Kolejki teraz” (QueueSnapshot): kto zajmuje pracowników kolejek i co czeka. */
export type SystemQueues = {
  checked_at: string
  queues: {
    key: string
    label: string
    running: number
    waiting: number
    /** zadania odłożone na później (ponowienie z opóźnieniem) */
    delayed: number
    oldest_wait_seconds: number | null
    longest_running_seconds: number | null
    /** w trakcie dłużej niż limit zadania — pracownik został przerwany, zadanie wróci do kolejki */
    over_timeout: number
    jobs: { type: string; label: string; running: number; waiting: number; sources: { label: string; count: number }[] }[]
    /** rodzaje policzone z części zadań (bardzo długa kolejka) */
    sampled: boolean
  }[]
  batches: { id: number; status: string; total: number; done: number; failed: number; message: string | null; current_sku: string | null; created_at: string | null }[]
  failed_24h: { type: string; label: string; count: number; last_at: string | null; last_error: string }[]
}

export function fetchSystemQueues(): Promise<SystemQueues> {
  return api<SystemQueues>('/admin/system-status/queues')
}

export function fetchSystemGaps(kind: SystemGapKind): Promise<SystemGapRow[]> {
  return api<{ data: SystemGapRow[] }>(`/admin/system-status/gaps/${kind}`).then((r) => r.data)
}

export function muteSystemAlert(alertId: number): Promise<SystemAlertRow> {
  return api<SystemAlertRow>(`/admin/system-alerts/${alertId}/mute`, { method: 'POST' })
}

export function unmuteSystemAlert(alertId: number): Promise<SystemAlertRow> {
  return api<SystemAlertRow>(`/admin/system-alerts/${alertId}/unmute`, { method: 'POST' })
}

/** Usuwanie tła ze zdjęcia (rembg): queued = w kolejce, done = wycięte (oryginał do przywrócenia), failed/skipped z powodem. */
export type ProductImageBackground = {
  status: 'queued' | 'done' | 'failed' | 'skipped'
  note: string | null
  removed_at: string | null
}

export type ProductImage = {
  id: number
  url: string
  thumb_url?: string
  source_url: string | null
  is_primary: boolean
  sort_order: number
  /** null = usuwania tła nikt nie zlecał */
  background?: ProductImageBackground | null
}

export type DescriptionLayoutBlock = {
  id: string
  visible: boolean
  emphasis: 'none' | 'highlight' | 'accent' | 'muted' | 'strong' | string
}

export type DescriptionLayoutResolved = {
  kategoria_bhp: string
  label: string
  card: DescriptionLayoutBlock[]
  export: DescriptionLayoutBlock[]
}

export type ProductDocument = {
  id: number
  url: string
  source_url?: string | null
  title?: string | null
  kind?: string
  size_bytes?: number
  sort_order?: number
}

/** Ostatnia zmiana ceny z historii (import cennika albo synchronizacja B2B). */
export type ProductPriceChange = {
  at: string
  /** Waluta cen zmiany; null = wiersz historii sprzed zapisu waluty (wtedy waluta karty). */
  currency: string | null
  source: string | null
  source_label: string
  purchase_old: number | null
  purchase_new: number | null
  catalog_old: number | null
  catalog_new: number | null
  /** Procent od zakupu; od katalogu, gdy zakupu nie da się porównać lub zmienił się tylko katalog. */
  pct: number | null
  pct_basis: 'purchase' | 'catalog' | null
  purchase_pct: number | null
  catalog_pct: number | null
}

export type ProductPriceHistoryRow = {
  id: number
  product_id: number
  price_list_id: number | null
  catalog_price_net: string | null
  purchase_price: string | null
  /** null = wiersz historii sprzed zapisu waluty (wtedy waluta karty). */
  currency: string | null
  source: string | null
  source_label: string
  created_at: string
  updated_at: string | null
  price_list: { id: number; manufacturer: string; version: string; created_at: string | null } | null
  /** Poprzednia cena tego samego źródła; null przy pierwszym wpisie źródła albo innej walucie. */
  purchase_old: number | null
  catalog_old: number | null
  purchase_pct: number | null
  catalog_pct: number | null
  /** Pierwszy wpis tego źródła na karcie (dodanie ceny, nie zmiana). */
  first_in_source: boolean
  /** Ceny konta B2B z oceną ceny specjalnej ukryte (brak uprawnienia) — kwoty są null; starsze API bez pola. */
  prices_hidden?: boolean
}

/** Ostatnia zmiana ceny wersji (ceny jako tekst z dwoma miejscami po przecinku). */
export type ProductVariantPriceChange = {
  purchase_old: string | null
  purchase_new: string | null
  pct: number | null
  at: string | null
  b2b_sync_run_id: number | null
}

/**
 * Wersja karty u dostawcy (np. format × podłoże znaku) albo rozmiar karty z własną ceną (kind „size”) z ceną konta;
 * etykieta i atrybuty dosłownie ze źródła.
 */
export type ProductVariant = {
  id: number
  remote_id: string
  /** Kod rozmiaru u dostawcy; wersje Sign Project nie mają. */
  sku: string | null
  label: string
  attributes: Record<string, string>
  purchase_price: string | null
  list_price_net: string | null
  /** Niższa cena tego rozmiaru przy pełnym kartonie (BIG); null = sklep jej nie podaje. Karton — carton_qty slotu. */
  carton_price_net?: string | null
  currency: string | null
  vat_rate: number | null
  unit: string | null
  /** Dostępność rozmiaru dosłownie ze sklepu dostawcy; null = sklep nie podał. */
  availability: string | null
  source_url: string | null
  sort_order: number
  price_checked_at: string | null
  last_seen_at: string | null
  /** Wersja zniknęła z listy dostawcy — nie liczy się do „od–do”. */
  removed_at: string | null
  last_price_change: ProductVariantPriceChange | null
  /** Źródło wiersza (np. „b2b:15”) i jego etykieta — po połączeniu kart rozmiary mogą być z kilku kont; starsze API bez pól. */
  source?: string
  source_label?: string
}

/** Aktywny wariant karty wybranej w pozycji przetargu (main_product.active_variants) — skrót ProductVariant do wyboru w ofercie. */
export type ProductActiveVariant = Pick<
  ProductVariant,
  'id' | 'sku' | 'label' | 'purchase_price' | 'currency' | 'availability' | 'sort_order' | 'removed_at'
> & {
  product_id: number
  kind: ProductVariants['kind']
  /** cena zakupu wariantu w PLN (backend: TenderPricingService); null = bez ceny albo waluty bez kursu */
  purchase_price_pln?: number | null
}

export type ProductVariants = {
  /**
   * „version” — wersje Sign Project (karta z ceną 0, ceny tylko w wersjach); „size” — rozmiary w różnych cenach
   * (karta ma własną cenę = najniższy rozmiar). Karta z oboma pokazuje same wersje.
   */
  kind: 'version' | 'size'
  count: number
  active_count: number
  /** Tylko z aktywnych wersji z ceną; null przy różnych walutach albo braku cen. */
  min_price: string | null
  max_price: string | null
  currency: string | null
  source_label: string | null
  dimensions: string[]
  items: ProductVariant[]
}

export type ProductVariantPriceHistoryRow = {
  id: number
  purchase_price: string | null
  list_price_net: string | null
  currency: string | null
  source: string
  source_label: string
  b2b_sync_run_id: number | null
  created_at: string
  /** null dla pierwszego wpisu wersji (dodanie ceny, nie zmiana). */
  purchase_old: string | null
  purchase_pct: number | null
  /** Ceny konta B2B z oceną ceny specjalnej ukryte (brak uprawnienia) — kwoty są null; starsze API bez pola. */
  prices_hidden?: boolean
}

/** Model dostawcy w karcie łączonej: numer artykułu, nazwa u dostawcy (bez rozmiaru) i jego rozmiary. */
export type ProductSourceModel = { number: string; name: string | null; sizes: string[] }

export type Product = {
  id: number
  sku: string
  name: string
  model_name?: string | null
  manufacturer: string
  category: string | null
  assortment_group_id?: number | null
  description?: string | null
  /** Rozmiary/kody albo formaty wersji karty (ze źródła, do wyszukiwania); null = brak. */
  variant_summary?: string | null
  norms: string | null
  /** Normy i kody odczytane dosłownie z karty producenta (strona producenta albo łącznik B2B); null = brak odczytu. */
  manufacturer_norms?: {
    source?: { connector?: string; brand?: string; url?: string; synced_at?: string }
    rows?: { label: string; value?: string }[]
    en388?: string
    normy_en?: string[]
  } | null
  /** Parametry wypisane w kolumnach cennika dostawcy — cytat z dokumentu, nie odczyt ze strony. */
  price_list_attributes?: Record<string, string> | null
  /** Parametry wpisane ręcznie — jedyne dane karty, których nie rusza automatyka. */
  manual_specs?: { label: string; value: string }[] | null
  catalog_price_net: string
  purchase_price: string
  discount_percent?: string
  currency?: string | null
  price_pln?: number | null
  purchase_price_pln?: number | null
  stock: number
  pack_qty?: number | null
  packaging?: string | null
  shop_source_url?: string | null
  substitutes_count?: number
  enrichment_status?: 'none' | 'queued' | 'running' | 'done' | 'failed' | 'manual'
  /** Opis zapisany przez cennik B2B i niezmieniony — AI nie nadpisuje go zbiorczo, pojedynczo po potwierdzeniu. */
  description_from_b2b?: boolean
  /** Uzupełnianie opisu B2B ze stron konta: karta w kolejce albo obecny opis z uzupełnienia (hosts = strony źródłowe). */
  description_supplement?: DescriptionSupplement | null
  enriched_at?: string | null
  enrichment_error?: string | null
  enrichment_trace?: {
    at?: string
    sku?: string
    name?: string
    manufacturer?: string
    steps?: { t: string; m: string; url?: string; urls?: string[]; why?: string[] }[]
  } | null
  price_change_percent?: number | null
  price_history_latest_at?: string | null
  last_price_change?: ProductPriceChange | null
  /** Karta szczegółów: null, gdy karta nie ma wersji. */
  variants?: ProductVariants | null
  /** Lista produktów: liczba aktywnych wersji i najniższa cena wersji. */
  variants_count?: number
  variants_min_price?: string | null
  variants_currency?: string | null
  /** Lista produktów z wyszukiwaniem: kody towarów ERP XL (pewne powiązania), po których karta się znalazła. */
  erp_codes?: string[]
  /** Lista produktów z wyszukiwaniem: numery ze źródła ceny (np. drugi kolor karty łączonej, „0723381-0 (black)”),
   *  po których karta się znalazła; bez numeru równego SKU karty. */
  matched_codes?: string[]
  /** Modele dostawcy połączone w karcie (co najmniej dwa) — lista produktów i karta produktu. */
  source_models?: ProductSourceModel[]
  /** Karta pozycji przetargu: aktywne warianty (kolor, rozmiar, kod) do wyboru w ofercie. */
  active_variants?: ProductActiveVariant[]
  enrichment_payload?: {
    features?: string[]
    specs?: string[]
    norms?: string[]
    certificates?: string[]
    materials?: string[]
    use_cases?: string[]
    source_urls?: string[]
    confidence?: number
    attributes?: {
      kategoria_bhp?: string | null
      kod_producenta?: string | null
      material?: string | null
      materialy?: string[]
      normy_en?: string[]
      klasa_ochrony?: string | null
      rozmiar?: string | null
      poziomy_en388?: string | null
    } | null
  } | null
  description_layout?: DescriptionLayoutResolved | null
  images?: ProductImage[]
  images_count?: number
  documents?: ProductDocument[]
  documents_count?: number
  ai_match_percent?: number
  ai_match_reason?: string | null
  presta_export?: {
    presta_id: number
    url: string
    status: string
  } | null
  accessories?: ProductAccessory[]
  /** Karta szczegółów: ceny karty osobno dla każdego źródła (plik, konta B2B). */
  source_prices?: ProductSourcePrice[]
  /** Karta szczegółów: kurs, po którym porównano ceny źródeł w PLN. */
  source_prices_rates?: SourcePricesRates | null
  /** Karta szczegółów: stan i ostatnie zakupy z Comarch ERP XL (towary powiązane z kartą); null = brak powiązania. */
  erp_xl?: ErpCardStock | null
  /** Lista produktów i karta pozycji przetargu: tańsze źródło niż obowiązujące; null = brak. */
  cheaper_source?: CheaperSource | null
  /** Karta szczegółów: tabelki z kart wyrobu u dostawców (product_shop_cards) — osobno od opisu. */
  shop_fields?: ProductShopCardSource[]
  /** Lista produktów: karta ma wiersze ze sklepu dostawcy (sama flaga; treść dopiero w karcie szczegółów). */
  has_shop_fields?: boolean
  /** Lista produktów: ocena ceny konta B2B z karty względem cennika bazowego dostawcy; null = brak oceny. */
  supplier_special?: SupplierSpecial | null
  /** Lista i karta szczegółów: warunek zamawiania obowiązującego źródła ceny (np. po 10 szt.); null = brak. */
  order_quantity?: OrderQuantity | null
  special_prices?: Array<{
    id: number
    client_id: number | null
    client_name: string
    price: string
    currency: string
    valid_from: string | null
    contract_ref: string | null
  }>
}

export type ProductAccessory = {
  id: number
  source: string
  score: number
  method: string | null
  related_product_id: number | null
  sku: string | null
  name: string | null
  manufacturer: string | null
  short_description?: string | null
  image_url?: string | null
  in_presta?: boolean
  presta_id?: number | null
  presta_url?: string | null
  matched: boolean
}

/**
 * Uzupełnianie opisu B2B ze stron konta (ProductController::descriptionOrigins): stan w toku albo obecny opis
 * z uzupełnienia. retry_at w UTC (ISO) — w panelu pokazujemy czas lokalny.
 */
export type DescriptionSupplement = {
  state: 'queued' | 'running' | 'waiting_search' | 'cancelled' | 'supplemented'
  hosts: string[]
  described_at: string | null
  stage: string | null
  retry_at: string | null
}

/** Karta w toku uzupełniania — lista odświeża się, dopóki na stronie są takie karty. */
export function descriptionSupplementPending(s: DescriptionSupplement | null | undefined): boolean {
  return s?.state === 'queued' || s?.state === 'running' || s?.state === 'waiting_search'
}

/** Etykieta stanu uzupełniania na liście i karcie; null = karta bez stanu uzupełniania. */
export function descriptionSupplementLabel(
  s: DescriptionSupplement | null | undefined,
): { label: string; title: string; tone: 'progress' | 'waiting' | 'stopped' | 'done' } | null {
  if (!s) return null
  const hosts = s.hosts.length > 0 ? `: ${s.hosts.join(', ')}` : ''
  switch (s.state) {
    case 'queued':
      return { label: 'Uzupełnianie w kolejce', title: 'Opis z cennika B2B czeka w kolejce na uzupełnienie ze stron wskazanych przy koncie', tone: 'progress' }
    case 'running':
      return { label: 'Uzupełnianie w toku', title: s.stage ? `Etap: ${s.stage}` : 'Model uzupełnia opis ze stron konta', tone: 'progress' }
    case 'waiting_search': {
      const at = s.retry_at ? new Date(s.retry_at).toLocaleTimeString('pl-PL', { hour: '2-digit', minute: '2-digit' }) : null
      return {
        label: at ? `Czeka na wyszukiwarkę (ponowienie o ${at})` : 'Czeka na wyszukiwarkę',
        title: 'Wyszukiwarka ma przerwę po blokadzie — karta wróci do uzupełniania sama',
        tone: 'waiting',
      }
    }
    case 'cancelled':
      return { label: 'Uzupełnianie zatrzymane', title: 'Zatrzymane przyciskiem — „Uzupełnij krótkie opisy” wznowi kartę', tone: 'stopped' }
    case 'supplemented':
      return { label: 'Z B2B + strony', title: `Opis z cennika B2B uzupełniony ze stron${hosts}`, tone: 'done' }
  }
}

/** Pytanie przed uzupełnianiem AI karty z opisem z cennika B2B (description_from_b2b). */
export const B2B_DESCRIPTION_OVERWRITE_CONFIRM =
  'Ta karta ma opis ze sklepu dostawcy (cennik B2B).\n\nUzupełnianie AI zastąpi go opisem z internetu, a kolejne pobranie cennika go nie przywróci.\n\nNadpisać opis?'

/**
 * Warunki zakupu u obowiązującego źródła ceny karty (od niego kupujemy): warunek zamawiania (UVEX: tylko po 10 szt.)
 * i warunek ceny (Delta Plus: cena za pełny karton). Backend podaje obiekt tylko, gdy któryś z nich jest; inaczej null.
 */
export type OrderQuantity = {
  min: number | null
  step: number | null
  unit: string | null
  /** Rozmiary karty mają różne warunki (min i step null) — szczegóły na karcie dostawcy. */
  varies?: boolean
  /** Warunek ceny dosłownie ze źródła (Delta Plus: „Cena jednostkowa za pełny karton…”); null = brak. */
  price_note?: string | null
  /** Ilość w kartonie, dla której obowiązuje cena; null przy przypisie = różne kartony rozmiarów. */
  price_carton_qty?: number | null
  /**
   * Rozmiary w różnych cenach: cena karty to najniższy rozmiar, to najwyższa cena rozmiaru (netto, jak ceny karty);
   * null = jedna cena.
   */
  size_price_max?: string | null
  /** Waluta size_price_max (slot), np. „PLN”; null bez rozmiarów w różnych cenach. */
  size_price_currency?: string | null
  source_key: string
  source_label: string
}

/** Cena karty z jednego źródła (product_source_prices); is_effective = z tego slotu pochodzi cena karty. */
export type ProductSourcePrice = {
  source_key: string
  source_label: string
  catalog_price_net: string | null
  purchase_price: string | null
  discount_percent: string | null
  currency: string | null
  /** Dostępność u dostawcy dosłownie ze źródła; null = źródło jej nie podaje. */
  availability: string | null
  /** Warunek zamawiania u dostawcy (sklep B2B): najmniejsza ilość i krok; step null = bez kroku; null = źródło nie podaje. */
  order_min_qty?: number | null
  order_step_qty?: number | null
  order_unit?: string | null
  /** Rozmiary karty mają u tego dostawcy różne warunki zamawiania. */
  order_varies?: boolean
  /** Warunek ceny tego źródła (np. cena tylko za pełny karton) i ilość w kartonie. */
  price_note?: string | null
  price_carton_qty?: number | null
  /**
   * Druga cena konta: niższa cena przy pełnym kartonie carton_qty (BIG: „ab 96 Paar 0,92 €”). purchase_price zostaje
   * ceną od minimum zamówienia; null = źródło jej nie podaje.
   */
  carton_price_net?: string | null
  carton_qty?: number | null
  checked_at: string | null
  migrated: boolean
  is_effective: boolean
  /** Dlaczego cena z tego źródła nie obowiązuje (np. pierwszeństwo cennika producenta); null = brak powodu. */
  ignored_reason?: string | null
  /** Cena z cennika bazowego dostawcy (np. xlsx UVEX) — obok ceny konta, catalog_price_net bez zmian. */
  base_price_net?: string | null
  /** Arkusz cennika bazowego (np. „Hełmy”) — po nim dobierany rabat standardowy. */
  base_price_category?: string | null
  base_price_code?: string | null
  /** Pochodzenie ceny bazowej: plik · arkusz · pozycja · data pobrania. */
  base_price_source?: string | null
  /** Rabat standardowy kategorii z reguł konta; null = brak reguły dla arkusza. */
  standard_discount_percent?: string | null
  supplier_special?: SupplierSpecial | null
  // Porównanie źródeł (SourcePriceComparison) — starsze odpowiedzi API tych pól nie mają.
  /** Cena zakupu netto przeliczona na PLN (kurs NBP); null = brak ceny zakupu albo nieznana waluta. */
  purchase_price_pln?: number | null
  /** false = poza porównaniem (powód w not_comparable_reason), np. cennik sugerowany. */
  comparable?: boolean
  not_comparable_reason?: string | null
  /** 1..n wśród porównywalnych, rosnąco po cenie zakupu w PLN; null = poza porównaniem. */
  price_rank?: number | null
  is_cheapest?: boolean
  /** Różnica do ceny obowiązującej karty w %, np. −9.0; wiersz obowiązujący 0.0; null = brak ceny obowiązującej. */
  diff_to_effective_pct?: number | null
}

/** Kurs użyty do porównania cen źródeł; fallback = NBP nie odpowiedział, kurs zastępczy. */
export type SourcePricesRates = {
  as_of: string | null
  source: 'nbp' | 'fallback'
}

/** Stan towaru w jednym magazynie Comarch ERP XL. */
export type ErpWarehouseStock = {
  code: string
  name: string
  quantity: number
}

/** Pozycja PZ z ERP XL; unit_price_pln = wartość księgowa / ilość (jednostka podstawowa towaru). */
export type ErpPurchase = {
  date: string | null
  supplier: string | null
  quantity: number
  unit: string | null
  unit_price_pln: number | null
  document_price: number | null
  currency: string | null
  document_id: number
}

export type ErpLinkedItem = {
  xl_gid: number
  code: string
  name: string
  name1: string | null
  unit: string | null
  archived: boolean
  /** auto = kod + dowód (dostawca, marka, nazwa); confirmed = potwierdzone ręcznie. */
  status: 'auto' | 'confirmed'
  method: 'name' | 'name1' | 'xl_code' | 'card_name' | 'search' | 'manual'
  matched_value: string | null
  stock_trade: number
  stock_total: number
  warehouses: ErpWarehouseStock[]
  last_sale_at: string | null
  purchases: ErpPurchase[]
}

/** Blok karty „Stan w ERP XL”; sumy null przy różnych jednostkach powiązanych towarów. */
export type ErpCardStock = {
  items: ErpLinkedItem[]
  unit: string | null
  stock_trade: number | null
  warehouses: ErpWarehouseStock[] | null
  last_purchase: (ErpPurchase & { xl_code: string }) | null
  /** Towary XL z niepewnym powiązaniem (do sprawdzenia) — nie liczone w stanie. */
  suggested: number
  synced_at: string | null
  stale: boolean
  /** Ceny zakupu z PZ ukryte — karta ma konto B2B z oceną ceny specjalnej, a brak uprawnienia; starsze API bez pola. */
  prices_hidden?: boolean
}

/**
 * Wynik łączenia towaru XL z kartami (Administracja → Powiązania z ERP XL); null = jeszcze nie przeliczone.
 * no_match / family_conflict = kod jest, ale bez karty w katalogu; no_code = bez kodu w nazwie XL.
 */
export type ErpOutcome =
  | 'auto'
  | 'confirmed'
  | 'suggested'
  | 'ambiguous'
  | 'name_suggested'
  | 'search_suggested'
  | 'no_match'
  | 'family_conflict'
  | 'no_code'
  | 'rejected'
  | null

/** Powiązanie towaru XL z kartą; product null = karta usunięta. */
export type ErpAdminLink = {
  id: number
  status: 'auto' | 'suggested' | 'confirmed' | 'rejected'
  /** card_name = kod XL znaleziony w NAZWIE karty; search = towar bez kodu, karta z wyszukiwarki po nazwie modelu. */
  method: 'name' | 'name1' | 'xl_code' | 'card_name' | 'search' | 'manual'
  /** Kod dosłownie z XL. */
  matched_value: string | null
  evidence: {
    supplier_match?: boolean
    supplier?: string | null
    brand_in_name?: boolean
    other_brand?: string | null
    shared_words?: string[]
    weak_code?: boolean
    candidates?: number
    tier?: string
    /** search: ten sam rodzaj wyrobu po obu stronach */
    same_family?: boolean
    /** search: pozycja w wynikach wyszukiwarki */
    rank?: number
  } | null
  decided_at: string | null
  /** Imię / nazwa użytkownika. */
  decided_by: string | null
  /** Kiedy automat połączył tę kartę (zostaje po potwierdzeniu); null = automat jej nie łączył albo nie wiadomo. */
  auto_linked_at: string | null
  product: { id: number; sku: string; name: string; manufacturer: string | null } | null
}

/** Towar z Comarch ERP XL z powiązaniami (kolejność links: confirmed, auto, suggested, rejected). */
export type ErpAdminItem = {
  id: number
  xl_gid: number
  code: string
  name: string
  name1: string | null
  unit: string | null
  archived: boolean
  stock_trade: number
  stock_total: number
  /** Wartość zapasu jak w Zapasach: ilość × cena zakupu, wszystkie magazyny; null = bez stanu albo bez ceny zakupu. */
  stock_value: number | null
  /** 'YYYY-MM-DD' */
  last_sale_at: string | null
  last_purchase_at: string | null
  last_supplier: string | null
  outcome: ErpOutcome
  /** Kod z XL, którym próbowano łączyć (także przy no_match). */
  match_value: string | null
  /**
   * Kto i kiedy połączył towar z kartą (najnowsze potwierdzenie, bez niego automat); null = niepołączony.
   * auto_at przy potwierdzonej karcie = automat połączył ją wcześniej („po automacie”).
   */
  linked: { auto: boolean; by: string | null; at: string | null; auto_at: string | null } | null
  links: ErpAdminLink[]
}

/** Kto ile towarów połączył: key = wartość filtra linked_by ('auto' = automat, id osoby; null = osoba usunięta). */
export type ErpAdminLinker = { key: string | null; name: string; count: number }

/** Liczniki ekranu powiązań — tylko towary aktywne (bez archiwalnych i usuniętych z XL). */
export type ErpAdminSummary = {
  total: number
  synced_at: string | null
  by_outcome: Partial<Record<NonNullable<ErpOutcome>, number>>
  unlinked_sold_12m: number
  unlinked_in_stock: number
  /** Zalegające bez karty jak w Zapasach (stan, bez sprzedaży od `months` mies.); value_unknown = bez ceny zakupu. */
  unlinked_stale: { months: number; items: number; value: number; value_unknown: number }
  groups: { group: string; total: number; linked: number }[]
  /** Wszyscy, którzy łączyli (automat pierwszy) — opcje filtra „Połączył”. */
  linkers: ErpAdminLinker[]
}

/** Magazyn towaru na stronie Zapasy; value = wartość księgowa netto partii w PLN (null = brak z XL). */
export type InventoryWarehouse = {
  code: string
  name: string
  quantity: number
  value: number | null
  /** Oddział: cyfry z początku kodu (01H, 01MTU → '01'); null = kod bez cyfr. */
  location: string | null
}

/** Oddział magazynów do filtra Zapasów i raportu dla zarządu (key = cyfry z początku kodu magazynu, np. '01'). */
export type InventoryLocation = { key: string; name: string }

/** Towar z ERP XL ze stanem, bez sprzedaży od progu (GET /api/inventory). */
export type InventoryRow = {
  id: number
  xl_gid: number
  code: string
  name: string
  name1: string | null
  unit: string | null
  /** Towar archiwalny w XL, a nadal ma stan. */
  archived: boolean
  /** Wszystkie magazyny — to jest „stan” na stronie Zapasy. */
  stock_total: number
  /** Magazyny HANDEL (informacyjnie). */
  stock_trade: number
  /**
   * Ilość w oddziale z filtra `location`; bez filtra = stock_total. Z filtrem wieku partii (`lot_months`) — tylko sztuki
   * z partii sprzed progu (tak samo stock_value i unit_cost).
   */
  quantity: number
  /** Cały stan w wybranych magazynach (oddział albo wszystkie) — przy filtrze wieku partii większy niż quantity. */
  stock_in_scope: number
  /**
   * Ilość × cena zakupu w PLN: partie leżące na stanie (XL), a bez nich stan × cena ostatniej PZ; null = brak obu.
   * Z filtrem oddziału — tylko jego magazyny (tak samo value_source, unit_cost i oldest_lot_at).
   */
  stock_value: number | null
  /** Skąd wartość: partie na stanie albo — do pierwszego odczytu partii — ostatnia PZ. */
  value_source: 'lots' | 'last_purchase' | null
  /** Średnia cena zakupu towaru na stanie = wartość partii ÷ ilość (PLN za jednostkę); null = brak wartości partii. */
  unit_cost: number | null
  /** Od największego stanu. */
  warehouses: InventoryWarehouse[]
  /** 'YYYY-MM-DD'; null = nigdy (FS, paragon, WZ). Z filtrem oddziału — dokumenty z magazynów oddziału. */
  last_sale_at: string | null
  /** Ostatnia sprzedaż z dowolnego magazynu (bez filtra oddziału = last_sale_at). */
  last_sale_any_at: string | null
  /** Data przyjęcia najstarszej partii leżącej na stanie ('YYYY-MM-DD'). */
  oldest_lot_at: string | null
  last_purchase: {
    date: string | null
    supplier: string | null
    /** Ilość z ostatniej PZ w jednostce towaru (`unit`). */
    quantity: number | null
    unit_price_pln: number | null
    document_price: number | null
    currency: string | null
  } | null
  card: {
    id: number
    sku: string
    name: string
    manufacturer: string | null
    thumb_url: string | null
    link_status: 'auto' | 'confirmed'
  } | null
  /** Ile kart powiązanych (pewnie / potwierdzone). */
  cards_count: number
  /** Ile par RW → PW tego towaru w ostatnich 12 mies. (nowa partia bez ruchu towaru — do wyjaśnienia). */
  rw_pw_pairs: number
}

export type InventoryResponse = {
  data: InventoryRow[]
  meta: { current_page: number; last_page: number; per_page: number; total: number }
  summary: { items: number; value: number; value_unknown: number; without_card: number; never_sold: number }
  /** 'YYYY-MM-DD' — próg: brak sprzedaży przed tą datą; null = bez warunku sprzedaży. */
  cutoff: string | null
  /** 'YYYY-MM-DD' — najstarsza partia przyjęta najpóźniej tego dnia; null = bez warunku wieku partii. */
  lot_cutoff: string | null
  /** Echo filtra oddziału; null = wszystkie. */
  location: string | null
  /** Echo filtra magazynów (parametr `warehouses`): all — wszystkie (domyślnie), trade — handlowe, service — usługowe. */
  warehouses: InventoryBoardWarehouses
  location_name: string | null
  /** Oddziały, w których jest jakikolwiek towar — opcje filtra. */
  locations: InventoryLocation[]
  /** ISO — odczyt z XL (raz na dobę o 2:00). */
  synced_at: string | null
}

/** Próg raportu dla zarządu: towar spełniający warunek od co najmniej `months` miesięcy. */
export type InventoryBoardBucket = { months: number; items: number; value: number }

/**
 * Które magazyny liczy raport dla zarządu (parametr `warehouses` we wszystkich /api/inventory/board*):
 * trade — handlowe (domyślnie), service — usługowe (towar trzymany dla klientów), all — wszystkie.
 */
export type InventoryBoardWarehouses = 'trade' | 'service' | 'all'

/** Raport zapasów dla zarządu (GET /api/inventory/board?warehouses=…, uprawnienie inventory.report.view). Kwoty w zł. */
export type InventoryBoardReport = {
  /** Echo parametru — których magazynów dotyczą ilości i kwoty w odpowiedzi. */
  warehouses: InventoryBoardWarehouses
  /** Echo oddziału (parametr `location` we wszystkich /api/inventory/board*); null = wszystkie oddziały. */
  location: string | null
  location_name: string | null
  /** Oddziały, w których jest jakikolwiek towar — opcje przełącznika. */
  locations: InventoryLocation[]
  /** Cały towar osobno w magazynach handlowych i usługowych (niezależnie od `warehouses`, w wybranym oddziale). */
  split: { trade: { items: number; value: number }; service: { items: number; value: number } }
  /** ISO — kiedy odczytano dane z XL. */
  as_of: string | null
  /** Cały towar w wybranych magazynach. */
  stock: { items: number; value: number }
  /** months 6, 12, 24 — nie sprzedaje się od tylu miesięcy (sprzedaż ze wszystkich magazynów, z oddziałem — z jego magazynów). */
  no_sale: InventoryBoardBucket[]
  /** Nigdy nie sprzedany (i leży dłużej niż pół roku). */
  never_sold: { items: number; value: number }
  /**
   * months 36, 60 — towar bez sprzedaży ponad rok, którego najstarsza sztuka leży ponad 3 / 5 lat
   * (kwoty są częścią no_sale 12; okna: bucket stale_36 / stale_60).
   */
  stale_lot: InventoryBoardBucket[]
  /** Wszystkie towary z sztukami starszymi niż rok (także te, które się sprzedają) — tylko do zdania drobnym drukiem. */
  lot_12_total: { items: number; value: number }
  /** Bez sprzedaży ponad rok według rodzaju, od największej unsold_value. */
  groups: InventoryBoardGroup[]
  /** 5 najdroższych pozycji, które nie sprzedają się ponad rok. */
  top_unsold: {
    code: string
    name: string
    quantity: number
    unit: string | null
    value: number
    /** 'YYYY-MM-DD'; null = nigdy. */
    last_sale_at: string | null
    /** Nazwa z katalogu, gdy towar ma kartę (czytelniejsza niż nazwa XL). */
    card_name: string | null
  }[]
  /** Towar wydany z magazynu i przyjęty z powrotem jako nowy (pary RW → PW). */
  internal_moves: {
    /** 'YYYY-MM-DD' — początek okresu (12 mies.). */
    from: string
    /** Wszystkich takich par (najczęściej zamiany rozmiarów). */
    total: number
    /** Bez zmiany rozmiaru/koloru i bez wyjaśnienia na dokumencie. */
    unexplained: number
    /** Wartość (zł) par bez wyjaśnienia. */
    unexplained_value: number
    /** Liczone tylko dla towaru, który przed wydaniem leżał co najmniej tyle miesięcy (3). */
    min_lot_age_months: number
    /**
     * Do 5 osób z największą liczbą przypadków bez wyjaśnienia (name — zapis jak w XL, bywa „Nazwisko Imię”;
     * operator — akronim operatora XL do zapytania /inventory/board/moves; count — bez wyjaśnienia;
     * total — wszystkich par tej osoby, także zamian rozmiaru).
     */
    people: { operator: string; name: string; count: number; total: number }[]
  }
  /** Ile pozycji bez wartości (nie ma ich w kwotach). */
  value_unknown: number
  /**
   * Jak długo leży towar w wybranych magazynach: progi narastające — sztuki z dostaw (partii) leżących co najmniej próg.
   * null = partii jeszcze nie odczytano z programu magazynowego (do pierwszego odczytu po wdrożeniu).
   */
  lot_age: {
    buckets: InventoryBoardLotAgeBucket[]
    /** Towarów z jakąkolwiek dostawą na stanie. */
    items: number
    /** Wartość wszystkich dostaw na stanie (także świeżych) — podstawa udziału %. */
    value: number
    /** Towary, których choć jednej dostawy nie da się wycenić (ta dostawa nie jest w kwotach). */
    value_unknown_items: number
  } | null
}

/**
 * Próg „jak długo leży” (narastająco): sztuki z dostaw przyjętych co najmniej `from_months` miesięcy temu (ponad rok jest
 * częścią ponad pół roku); świeże dostawy się nie liczą. from_months null = dostawy bez daty przyjęcia; to_months zawsze
 * null. items — towary z takimi dostawami. Okno: bucket = key.
 */
export type InventoryBoardLotAgeBucket = {
  key: InventoryBoardLotAgeKey
  from_months: number | null
  to_months: number | null
  items: number
  value: number
}

export type InventoryBoardLotAgeKey =
  | 'lot_age_6'
  | 'lot_age_12'
  | 'lot_age_24'
  | 'lot_age_36'
  | 'lot_age_48'
  | 'lot_age_60'
  | 'lot_age_unknown'

/** Pozycje i wartość koszyka w zapisie dnia; null = koszyka nie było w zapisie. */
export type InventoryHistoryTotal = { items: number; value: number } | null

/** „Leży ponad…” w zapisie dnia; items null w pierwszym zapisie nocnym (przedziały, liczby towarów nie da się złożyć). */
export type InventoryHistoryLotAge = { items: number | null; value: number } | null

/** Liczby kafelków raportu dla zarządu zapisane jednego dnia (historia zapasów). */
export type InventoryHistoryBuckets = {
  stock: InventoryHistoryTotal
  no_sale_6: InventoryHistoryTotal
  no_sale_12: InventoryHistoryTotal
  no_sale_24: InventoryHistoryTotal
  never_sold: InventoryHistoryTotal
  stale_36: InventoryHistoryTotal
  stale_60: InventoryHistoryTotal
  /** Leży w magazynie ponad pół roku / rok / 2 lata: sztuki z dostaw przyjętych co najmniej tyle temu. */
  lot_age_6: InventoryHistoryLotAge
  lot_age_12: InventoryHistoryLotAge
  lot_age_24: InventoryHistoryLotAge
}

/**
 * Historia zapasów (GET /api/inventory/board/history?warehouses=…&location=…&from=…&to=…): zapis co noc po odczycie
 * z programu magazynowego. Daty 'YYYY-MM-DD'.
 */
export type InventoryHistory = {
  warehouses: InventoryBoardWarehouses
  location: string | null
  /** Okres zastosowany (domyślnie 30 dni do ostatniego zapisu). */
  from: string
  to: string
  /** Pierwszy i ostatni zapisany dzień w ogóle; null = jeszcze nic nie zapisano. */
  first_date: string | null
  last_date: string | null
  /** true — długi okres: jeden punkt na tydzień (ostatni zapisany dzień tygodnia). */
  weekly: boolean
  /** source: 'live' — nocny zapis, 'xl_history' — dzień odtworzony z historii stanów programu magazynowego. */
  points: ({ date: string; source: string } & InventoryHistoryBuckets)[]
  /** Pierwszy i ostatni zapisany dzień okresu — te same dwa dni dla wszystkich oddziałów i magazynów. */
  compare: {
    start_date: string
    end_date: string
    /** Wszystkie oddziały (key '') i każdy oddział, w wybranych magazynach. */
    locations: { key: string; name: string; start: InventoryHistoryBuckets | null; end: InventoryHistoryBuckets | null }[]
    /** Magazyny XL wybranego oddziału (albo wszystkich) — sama wartość i liczba pozycji. */
    warehouses: { code: string; location: string | null; start: InventoryHistoryTotal; end: InventoryHistoryTotal }[]
  } | null
}

/** Rodzaj towaru w raporcie dla zarządu (parametr `group` w /api/inventory/board/items). */
export type InventoryBoardGroupKey = 'A' | 'B' | 'S' | 'T' | 'H' | 'other'

/** Wiersz „według rodzaju”: towar bez sprzedaży ponad rok na tle całego towaru tego rodzaju. Kwoty w zł. */
export type InventoryBoardGroup = {
  group: InventoryBoardGroupKey
  /** Nazwa do pokazania, np. „Odzież” (litery grupy nigdy nie pokazujemy). */
  label: string
  unsold_items: number
  unsold_value: number
  stock_value: number
}

/** Koszyk listy towarów pod kafelkiem raportu dla zarządu (GET /api/inventory/board/items?bucket=…). */
export type InventoryBoardItemsBucket =
  | 'stock'
  | 'no_sale_6'
  | 'no_sale_12'
  | 'no_sale_24'
  | 'never_sold'
  | 'stale_36'
  | 'stale_60'
  | InventoryBoardLotAgeKey

/** Wiersz listy towarów raportu dla zarządu. Kwoty w zł. */
export type InventoryBoardItemRow = {
  code: string
  name: string
  /** Nazwa z katalogu, gdy towar ma kartę. */
  card_name: string | null
  quantity: number
  unit: string | null
  /**
   * Ilość × cena zakupu w wybranych magazynach; null = XL nie podaje ceny zakupu. W oknie przedziału „jak długo leży”
   * (bucket lot_age_*) quantity, value i oldest_lot_at dotyczą tylko sztuk leżących co najmniej próg.
   */
  value: number | null
  /** Wartość ÷ ilość (zł za jednostkę). */
  unit_cost: number | null
  /** 'YYYY-MM-DD'; null = nigdy (sprzedaż ze wszystkich magazynów, z oddziałem — z jego magazynów). */
  last_sale_at: string | null
  /** 'YYYY-MM-DD' — od kiedy leży najstarsza dostawa, która jeszcze jest w wybranych magazynach. */
  oldest_lot_at: string | null
  last_supplier: string | null
  /**
   * Nazwa rodzaju („Odzież”…) do drugiej linii w oknie. Poza zamrożonym kontraktem — strona pokazuje ją,
   * gdy backend ją poda; bez niej rodzaj się nie wyświetla.
   */
  group_label?: string | null
}

/** GET /api/inventory/board/items — domyślnie od największej wartości; `search`, `sort`, `dir` zawężają i sortują całą listę. */
export type InventoryBoardItemsResponse = {
  bucket: string
  /** Np. „Towar, który nie sprzedaje się od pół roku”. */
  title: string
  data: InventoryBoardItemRow[]
  meta: { current_page: number; last_page: number; per_page: number; total: number }
  /** Cały koszyk (jak kafelek). */
  totals: { items: number; value: number }
  /** Po polu wyszukiwania (`search`); bez niego równe `totals`. */
  found: { items: number; value: number }
}

/** Kolumny okna towarów, po których można sortować (`sort`, `dir`). */
export type InventoryBoardItemsSort = 'name' | 'quantity' | 'value' | 'last_sale' | 'oldest_lot'

/** Kolumny okna dokumentów, po których można sortować (`sort`, `dir`). */
export type InventoryBoardMovesSort = 'date' | 'name' | 'operator' | 'value' | 'lot_age' | 'note'

/** Para RW → PW (towar wydany i przyjęty z powrotem jako nowy) na liście raportu dla zarządu. */
export type InventoryBoardMoveRow = {
  rw_number: string
  /** 'YYYY-MM-DD'. */
  rw_date: string
  pw_number: string
  pw_date: string
  item_code: string
  item_name: string
  card_name: string | null
  quantity: number
  unit: string | null
  /** zł. */
  value: number
  /** Jak długo leżała wydana partia (mies.); null = nie wiadomo. */
  lot_age_months: number | null
  lot_received_at: string | null
  /** Cecha (rozmiar/kolor) na wydaniu i na przyjęciu. */
  rw_features: string | null
  pw_features: string | null
  same_feature: boolean
  rw_note: string | null
  pw_note: string | null
  operator_name: string | null
  approver_name: string | null
}

/** GET /api/inventory/board/moves — domyślnie od najnowszego; `search`, `sort`, `dir` zawężają i sortują całą listę. */
export type InventoryBoardMovesResponse = {
  scope: 'all' | 'unexplained'
  operator: string | null
  operator_name: string | null
  /** Tylko towar, który przed wydaniem leżał co najmniej tyle miesięcy (3). */
  min_lot_age_months: number
  /** Np. „Wydane i przyjęte z powrotem bez wyjaśnienia — Domin Ewelina”. */
  title: string
  data: InventoryBoardMoveRow[]
  meta: { current_page: number; last_page: number; per_page: number; total: number }
  /** Cała lista. */
  totals: { pairs: number; value: number }
  /** Po polu wyszukiwania (`search`); bez niego równe `totals`. */
  found: { pairs: number; value: number }
}

/** Dokument RW albo PW z pary (GET /api/inventory/rw-pw). */
export type RwPwDoc = {
  /** Np. 'RW-15H/30/26/07'. */
  number: string
  /** 'YYYY-MM-DD'. */
  date: string
  /** Kod magazynu XL, np. '15H'. */
  warehouse: string | null
  quantity: number
  /** Wartość księgowa netto w PLN. */
  value: number
  /** Akronim operatora XL, który wystawił dokument. */
  operator: string | null
  /** Akronim operatora XL, który zatwierdził dokument (często ten sam). */
  approver: string | null
  /** Cecha partii (zwykle rozmiar): '38' albo 'L×2, XL×1'; null = bez cechy albo brak danych. */
  features: string | null
  /** Imię i nazwisko z XL dosłownie (bywa „Nazwisko Imię”): kto wystawił / kto zatwierdził. */
  operator_name: string | null
  approver_name: string | null
  /** Uwagi dokumentu w XL, np. „ZAMIANA ROZMIARÓW”; w PW często numer RW. */
  note: string | null
  /** Dokument obcy — tylko gdy inny niż własny numer. */
  foreign_number: string | null
}

/** Partia zdjęta przez RW: przyjęcie najstarszej i ile leżała do dnia RW. */
export type RwPwLot = {
  /** 'YYYY-MM-DD' — przyjęcie najstarszej partii. */
  received_at: string
  /** Pełne miesiące od przyjęcia najstarszej partii do dnia RW. */
  age_months: number | null
  /** Średni wiek partii ważony ilością (przy kilku partiach). */
  avg_age_months: number | null
  lots: number
  /** Dokument, którym weszła najstarsza partia, np. 'PZ-15H/350/21/08'. */
  source: string | null
  /** Najstarsza partia weszła przez PW — była już wcześniej „odnawiana”. */
  from_pw: boolean
}

/** Towar XL w parze RW → PW. */
export type RwPwItemRef = {
  erp_item_id: number | null
  code: string
  name: string
  unit: string | null
  stock_total: number | null
  card: { id: number; sku: string; name: string; manufacturer: string | null; thumb_url: string | null } | null
}

/** RW i PW tego samego towaru w tej samej ilości w krótkim odstępie — do wyjaśnienia. */
export type RwPwPair = {
  id: number
  item: RwPwItemRef
  rw: RwPwDoc & { lot: RwPwLot | null }
  pw: RwPwDoc
  gap_days: number
  same_value: boolean
  same_warehouse: boolean
  /** Ta sama cecha (rozmiar) partii RW i PW; false = zmiana rozmiaru, np. 38 → 39. */
  same_feature: boolean
}

export type RwPwItemRow = {
  item: RwPwItemRef
  pairs: number
  quantity: number
  value: number
  same_value: number
  operators: string[]
  /** Najstarsza partia zdjęta przez RW tego towaru (pełne miesiące). */
  max_age_months: number | null
  /** Ile par bez zmiany cechy (rozmiaru). */
  same_feature: number
  first_date: string
  last_date: string
}

/** operator = kto wystawił RW; pw_by_other = ile PW wystawił ktoś inny. */
export type RwPwOperatorRow = {
  operator: string
  /** Imię i nazwisko z XL; null, gdy nieznane. */
  operator_name: string | null
  pairs: number
  items: number
  value: number
  same_value: number
  last_date: string
  pw_by_other: number
  /** Średni wiek partii zdjętych przez RW tej osoby (pełne miesiące). */
  avg_age_months: number | null
  /** Ile par bez zmiany cechy (rozmiaru). */
  same_feature: number
}

type RwPwResponseBase = {
  meta: { current_page: number; last_page: number; per_page: number; total: number } | null
  /** Po tych samych filtrach co lista. */
  summary: { pairs: number; items: number; value: number; same_value: number; operators: number; same_feature: number }
  /** Akronimy do listy wyboru (w okresie, bez filtra operatora). */
  operators: string[]
  /** Akronim → imię i nazwisko z XL. */
  operator_names: Record<string, string>
  /** 'YYYY-MM-DD' — początek okresu. */
  from: string
  /** ISO — ostatni nocny odczyt z XL. */
  synced_at: string | null
}

export type RwPwResponse = RwPwResponseBase &
  (
    | { view: 'pairs'; data: RwPwPair[] }
    | { view: 'items'; data: RwPwItemRow[] }
    | { view: 'operators'; data: RwPwOperatorRow[] }
  )

/**
 * Tańsze porównywalne źródło niż obowiązujące (lista produktów, przetarg) — tylko informacja:
 * cena karty i oferty dalej z ceny obowiązującej (pierwszeństwo producenta).
 */
export type CheaperSource = {
  source_key: string
  label: string
  purchase_price_pln: number
  /** Ujemne, np. −9.0 = o 9% taniej od ceny obowiązującej. */
  diff_pct: number
}

/**
 * Ocena ceny konta B2B: special = niższa niż cennik bazowy × (1 − rabat standardowy).
 * Wniosek z porównania, nie potwierdzenie dostawcy. Kwoty w walucie slotu ceny.
 */
export type SupplierSpecial = {
  status: 'special' | 'standard' | 'worse_than_standard'
  standard_price: number
  actual_discount_percent: number
  /** O ile cena konta jest niższa od standardowej; ujemne = wyższa. */
  saving_net: number
  /** Cena z cennika bazowego i rabat standardowy kategorii — z nich cena normalna (standard_price). */
  base_price?: number
  standard_discount_percent?: number
  /** Kategoria cennika bazowego (UVEX: arkusz); tylko ocena ceny karty (lista, szczegóły). */
  category?: string | null
}

/** Jeden wiersz tabelki z karty wyrobu u dostawcy, dosłownie ze sklepu. */
export type ProductShopCardRow = {
  name: string
  value: string
}

/** Sekcja karty u dostawcy; pusta nazwa = sklep nie dzieli tabelki na sekcje. */
export type ProductShopCardSection = {
  section: string
  rows: ProductShopCardRow[]
}

/** Dane z karty wyrobu u jednego dostawcy (konto B2B) — to nie jest opis wyrobu, tylko kopia tabelki ze sklepu. */
export type ProductShopCardSource = {
  source_key: string
  source_label: string
  b2b_account_id: number
  /** Adres karty u dostawcy w chwili pobrania; null = źródło go nie podało. */
  source_url: string | null
  synced_at: string | null
  sections: ProductShopCardSection[]
}

/** Przebieg scalania kart rozbitych dawniej według ceny rozmiaru (b2b:merge-size-prices) zlecony z panelu. */
export type B2bSizeMergeState = {
  mode: 'preview' | 'apply'
  status: 'queued' | 'running' | 'done' | 'failed'
  with_tenders: boolean
  limit: number | null
  started_at: string
  updated_at: string
  finished_at: string | null
  /** Grupy z listy już przejrzane. */
  processed: number
  /** Grupy na liście. */
  total: number
  /** Grupy, które można scalić (podgląd) albo były do scalenia (scalanie). */
  to_merge: number
  /** Scalone do tej pory (w podglądzie 0). */
  merged: number
  sizes: number
  tenders: number
  sku_renamed: number
  /** Rodzaje pominięć z liczbą, od najczęstszego. */
  skipped: { reason: string; count: number }[]
  /** Ostatnie (do 300) wiersze po grupie: „+ …” scalona / do scalenia, „– …” pominięta z powodem. */
  lines: string[]
  backup_path: string | null
  error: string | null
}

export type B2bSizeMerge = {
  /** Lista wyrobów rozbitych na kilka kart z ostatniego przebiegu; reason = czemu listy nie ma. */
  spread: {
    run_id: number | null
    finished_at: string | null
    total: number
    truncated: boolean
    reason: string | null
  }
  state: B2bSizeMergeState | null
  sync_running: boolean
}

export type ProductKitSuggestion = {
  id: number
  sku: string | null
  name: string | null
  manufacturer: string | null
  short_description?: string | null
  image_url?: string | null
  in_presta?: boolean
  presta_id?: number | null
  presta_url?: string | null
  role?: string
  reason?: string
}

export type ProductKitSuggestions = {
  family: string | null
  family_label: string
  article_type: string | null
  prompt_version: string
  suggestions: ProductKitSuggestion[]
}

export type PrestaExportResult = {
  product_id: number
  sku: string
  action: string
  presta_id: number
  url: string
  sizes: string[]
  sizes_missing: string[]
  images: number
}

export type PrestaExportBatch = {
  exported?: number
  skipped?: number
  failed?: number
  queued?: number
  items?: PrestaExportResult[]
  errors?: string[]
  product_ids?: number[]
}

export type EnrichmentBatch = {
  id: number
  scope: string
  scope_id: number
  total: number
  done: number
  failed: number
  status: string
  force: boolean
  progress_percent: number
  current_sku?: string | null
  current_name?: string | null
  message?: string | null
  manufacturer?: string | null
  current_product_id?: number | null
  price_list_id?: number | null
  /**
   * Modele w partii (etap 2 opisów z cenników): model = ten sam wyrób w różnych wymiarach i kolorach, opis pobierany
   * raz na model. total/done liczą karty jak dotąd; null, gdy partia nie ma kluczy modelu.
   */
  models_total?: number | null
  models_done?: number | null
  created_by_name?: string | null
  created_at?: string | null
  updated_at?: string | null
}

export function appHref(path: string): string {
  const p = path.startsWith('/') ? path : `/${path}`
  return `${publicDir()}${p}`
}

export function enrichmentPriceListHref(batch: EnrichmentBatch): string | null {
  const manufacturer = batch.manufacturer?.trim()
  if (!manufacturer) {
    return null
  }
  return appHref(`/price-lists?manufacturer=${encodeURIComponent(manufacturer)}`)
}

export function enrichmentProductHref(batch: EnrichmentBatch): string | null {
  if (batch.current_product_id == null) {
    return null
  }
  return appHref(`/products/${batch.current_product_id}`)
}

export type EnrichmentBatchItem = {
  id: number
  product_id: number
  sku: string
  name: string
  status: string
  message: string | null
  updated_at: string | null
}

export type EnrichmentBatchLog = {
  batch: EnrichmentBatch
  items: EnrichmentBatchItem[]
  counts: Record<string, number>
}

export type ActiveEnrichmentState = {
  batches: EnrichmentBatch[]
  recent: EnrichmentBatch[]
  queued_products: number
  running_products: number
}

export function parseActiveEnrichment(res: unknown): ActiveEnrichmentState {
  if (Array.isArray(res)) {
    return { batches: res as EnrichmentBatch[], recent: [], queued_products: 0, running_products: 0 }
  }
  if (res && typeof res === 'object' && 'batches' in res) {
    const o = res as ActiveEnrichmentState
    return {
      batches: Array.isArray(o.batches) ? o.batches : [],
      recent: Array.isArray(o.recent) ? o.recent : [],
      queued_products: Number(o.queued_products ?? 0),
      running_products: Number(o.running_products ?? 0),
    }
  }
  return { batches: [], recent: [], queued_products: 0, running_products: 0 }
}

export type Substitute = {
  id: number
  main_product_id?: number
  substitute_product_id?: number
  type: string
  match_percent: number
  norms_ok?: boolean
  certs_ok?: boolean
  reason: string | null
  approval_status: string
  main_product?: Product
  substitute_product?: Product
  approver?: { id: number; name: string } | null
  /** 'reczny' — wpisany przez człowieka, 'automat' — propozycja generatora (do decyzji). Brak = starsza odpowiedź API. */
  source?: SubstituteSource
  evidence?: SubstituteEvidence | null
  generated_at?: string | null
  decision_note?: string | null
  /** Tylko w GET /products/{id}/substitutes: skrót karty zamiennika, porównanie cen i liczniki parametrów. */
  card?: SubstituteCardLite
  price?: SubstitutePrice
  summary?: SubstituteParamSummary
}

export type SubstituteSource = 'reczny' | 'automat'

/** Wartość parametru po jednej stronie pary; `source` jak CheckSource albo 'derived' (wniosek automatu, wtedy `inferred`). */
export type SubstituteEvidenceValue = {
  value: string | null
  text: string | null
  source: CheckSource | 'derived' | null
  quote: string | null
  inferred: boolean
}

export type SubstituteEvidenceParam = {
  key: string
  label: string
  main: SubstituteEvidenceValue | null
  sub: SubstituteEvidenceValue | null
  /** equal — ta sama wartość; higher — zamiennik wyżej; meets — spełnia (≥), zapis inny */
  relation: 'equal' | 'higher' | 'meets'
  note: string | null
}

/** Dowody generatora zamienników (evidence v1) — każdy parametr ochronny karty głównej z cytatem źródła. */
export type SubstituteEvidence = {
  version: number
  rules?: string
  generated_at?: string
  family?: string
  family_label?: string
  verdict?: 'preferowany' | 'premium'
  params: SubstituteEvidenceParam[]
  extra_in_sub?: string[]
  not_checked?: string[]
  fingerprints?: { main: string; sub: string }
  /** zatwierdzona para, której automat już nie potwierdza */
  stale?: { at: string; reason: string } | null
}

export type SubstituteCardLite = {
  id: number
  sku: string
  name: string
  manufacturer: string | null
  family: string | null
  family_label: string | null
  thumb_url: string | null
  /** po masce cen specjalnych i kursie NBP; null — brak ceny */
  price_pln: number | null
  /** waluta źródłowa karty */
  currency: string | null
  has_description: boolean
}

export type SubstitutePrice = {
  main_pln: number | null
  sub_pln: number | null
  diff_percent: number | null
  /** false — różne/nieznane jednostki sprzedaży albo brak ceny; powód w `note` */
  comparable: boolean
  note: string | null
}

export type SubstituteParamSummary = { params: number; equal: number; higher: number }

export type SubstituteLite = {
  id: number
  type: string
  approval_status: string
  source: SubstituteSource
  reason: string | null
  decision_note: string | null
  approver: { id: number; name: string } | null
  generated_at: string | null
  stale: boolean
  product: SubstituteCardLite
  price: SubstitutePrice
  summary: SubstituteParamSummary
}

export type SubstituteBoardGroup = {
  main: SubstituteCardLite
  chips: string[]
  substitutes: SubstituteLite[]
}

export type SubstituteBoardPage = {
  data: SubstituteBoardGroup[]
  current_page: number
  last_page: number
  per_page: number
  total: number
}

export type SubstituteSummary = {
  totals: {
    mains: number
    rows: number
    pending: number
    approved: number
    rejected: number
    auto: number
    manual: number
    stale: number
  }
  families: { key: string | null; label: string; mains: number }[]
  manufacturers: { name: string; mains: number }[]
}

/** GET /products/{id}/substitutes */
export type SubstitutesByMain = {
  main_product: Product
  main_card?: SubstituteCardLite
  substitutes: Substitute[]
}

/** Stan propozycji połączenia kart (ekran „Łączenie kart”). */
export type CardMatchStatus = 'pending' | 'conflict' | 'rejected' | 'merged'

/** Skrót karty do porównania obok siebie; ceny jako tekst jak w API, źródła z etykietą SourcePriceComparison. */
export type CardBrief = {
  id: number
  sku: string
  name: string
  manufacturer: string | null
  purchase_price: string | null
  currency: string | null
  thumb_url: string | null
  has_description: boolean
  sources: Array<{
    source_key: string
    label: string
    purchase_price: string | null
    currency: string | null
    /** Warunek zamawiania u dostawcy (jak ProductSourcePrice); brak pola = starsza odpowiedź API. */
    order_min_qty?: number | null
    order_step_qty?: number | null
    order_unit?: string | null
    order_varies?: boolean
    price_note?: string | null
    price_carton_qty?: number | null
  }>
}

/**
 * Para: karta dystrybutora (source, duplikat) → karta producenta (target, zostaje).
 * matched_value przychodzi znormalizowane (np. „IF016FPS” dla IF/016/F/PS) — pokazujemy dosłownie.
 */
export type CardMatch = {
  id: number
  status: CardMatchStatus
  /** „manual” = połączone ręcznie z listy produktów (wtedy matched_value null, notatka w decision_input.note). */
  matched_by: 'ean' | 'manufacturer_code' | 'manual' | string
  matched_value: string | null
  matched_source_key: string | null
  /** Marka kanoniczna (klucz, np. „anro”). */
  brand: string | null
  hits: number
  positions: number
  reason: string | null
  conflict_product_ids: number[] | null
  decided_by: { id: number; name: string } | null
  decided_at: string | null
  last_seen_at: string | null
  /** null po połączeniu (karta dystrybutora usunięta) — wtedy source_snapshot. */
  source: CardBrief | null
  source_snapshot: { sku: string; name: string; manufacturer: string | null } | null
  target: CardBrief | null
  /** merge = jedna karta producenta (target); size_merge / split = kilka kart, szczegóły w plan. */
  kind: CardMatchKind
  /** plan.signal albo null (merge). */
  signal: CardMatchSignal | null
  plan_hash: string | null
  plan: CardMatchPlan | null
  /**
   * Zapis decyzji z ekranu: łączenie rozmiarów (CardMatchDecisionInput, bez pola kind) albo rozdzielenie
   * (CardMatchSplitDecisionInput, kind „split” — odróżnia isSplitDecision); null dla pozostałych.
   */
  decision_input: CardMatchDecisionInput | CardMatchSplitDecisionInput | null
}

/**
 * Co zatwierdził człowiek przy „Połącz rozmiary” (POST /card-matches/{id}/merge-sizes): karta, która zostaje,
 * łączone karty, nazwa i lista rozmiarów, stan kart sprzed połączenia i pozycje wiodące kont producenta.
 * Bez pola kind — po tym odróżnia się od zapisu rozdzielenia.
 */
export type CardMatchDecisionInput = {
  keep_product_id: number
  drop_product_ids: number[]
  attached_source_product_id: number
  name: string
  name_suggested: string | null
  variant_summary: string
  confirm_sizes_only: boolean
  plan_hash: string
  cards_before: Array<{
    id: number
    sku: string
    name: string
    purchase_price: string | null
    currency: string | null
  }>
  anchors: Array<{ source_key: string; position_key: string }>
}

/**
 * Co zatwierdził człowiek przy „Rozdziel” (POST /card-matches/{id}/split): karta dystrybutora (po rozdzieleniu
 * usunięta), karty producenta, pozycja po pozycji, do której karty trafiła, stan kart sprzed zmiany i co przeniesiono.
 */
export type CardMatchSplitDecisionInput = {
  kind: 'split'
  plan_hash: string
  source_product_id: number
  /** Karty producenta rosnąco po id. */
  target_product_ids: number[]
  /** Kolejność jak w planie. */
  positions: Array<{
    /** „b2b:{id konta}”. */
    source_key: string
    /** Np. „B2B P4S”. */
    source_label: string
    /** remote_id pozycji u dostawcy. */
    position_key: string
    /** Kod pozycji u dostawcy, np. „236510”. */
    remote_sku: string | null
    /** Etykieta pozycji, np. „kolor biały”. */
    label: string | null
    target_product_id: number
    target_sku: string
  }>
  /** Pierwsza = karta dystrybutora, potem karty producenta rosnąco. */
  cards_before: Array<{
    id: number
    sku: string
    name: string
    purchase_price: string | null
    currency: string | null
  }>
  /** {id karty producenta: {pole: [stara, nowa]}}; puste, gdy ceny kart bez zmian. */
  card_changes: Record<string, Record<string, [unknown, unknown]>>
  slots_replaced: Array<{
    product_id: number
    source_key: string
    purchase_price: string | null
    currency: string | null
    checked_at: string | null
  }>
  redirects_orphaned: Array<{ source_key: string; position_key: string }>
  identifiers_moved: number
  /** Identyfikatory karty dystrybutora spoza pozycji planu — usunięte z kartą (są w kopii zapasowej). */
  identifiers_dropped: number
  /** Cenniki, w których karta dystrybutora była na liście (wpis konta dostał karty producenta). */
  price_lists: number[]
}

/** Zapis rozdzielenia (kind „split”); zapis łączenia rozmiarów pola kind nie ma. */
export function isSplitDecision(di: CardMatch['decision_input'] | undefined): di is CardMatchSplitDecisionInput {
  return di != null && 'kind' in di && di.kind === 'split'
}

/**
 * Rodzaj propozycji: merge — karta dystrybutora = jedna karta producenta; size_merge — dystrybutor ma jeden wyrób
 * w rozmiarach, producent osobne karty rozmiarów (łączymy karty producenta); split — karta dystrybutora trzyma
 * kilka wyrobów producenta (rozdzielamy).
 */
export type CardMatchKind = 'merge' | 'size_merge' | 'split'

/** Czym różnią się pozycje: rozmiar, kolor, albo nie wiadomo (bez zgadywania). */
export type CardMatchSignal = 'size' | 'color' | 'unknown'

/** Pozycja karty dystrybutora (rozmiar / kolor u źródła) i karta producenta, w którą trafia jej klucz. */
export type CardMatchPlanPosition = {
  source_key: string
  source_label: string
  position_key: string
  remote_sku: string | null
  label: string | null
  size_label: string | null
  /** null = pozycja bez karty (bez klucza albo w kilka kart — wtedy target_ids). */
  target_product_id: number | null
  target_ids: number[] | null
  target: CardBrief | null
  matched_by: string | null
  matched_value: string | null
  signal: CardMatchSignal
  /** Skąd sygnał, po ludzku (np. „etykieta P4S: rozmiar S (mały)”). */
  signal_why: string
}

/** Plan „pozycja → karta” dla propozycji z kilkoma kartami producenta (size_merge / split). */
export type CardMatchPlan = {
  version: 1
  signal: CardMatchSignal
  source_label: string
  same_owner: boolean
  equal_prices: boolean
  price_differences: Array<{
    source_key: string
    label: string
    values: Array<{ product_id: number; purchase_price: string | null; currency: string | null }>
  }>
  blockers: Array<{ code: string; text: string }>
  positions: CardMatchPlanPosition[]
  /**
   * Tylko size_merge: która karta zostaje, podpowiedź nazwy, kody rozmiarów i lista rozmiarów, jaka trafi na kartę
   * modelu (variant_summary — wyliczana przy odczycie, np. „Rozmiary: S (mały) (7000146845); M (średni) (…)”).
   */
  suggested: {
    keep_product_id: number
    common_name: string | null
    sizes: Array<{ product_id: number; label: string | null; code: string }>
    variant_summary: string
  } | null
}

export type CardMatchSummary = {
  pending: number
  conflict: number
  rejected: number
  merged: number
  refreshed_at: string | null
  by_kind: Record<CardMatchKind, { pending: number; conflict: number; rejected: number; merged: number }>
  /** Pomiar: propozycje z kilkoma kartami (do decyzji + niepewne) wg sygnału planu. */
  signals: Record<CardMatchSignal, number>
}

/** Karta w podglądzie ręcznego łączenia (POST /card-matches/manual/preview); ceny jako tekst jak w API. */
export type ManualMergeCard = {
  id: number
  sku: string
  name: string
  manufacturer: string | null
  /** Karta ma właściciela (cennik producenta) — wtedy to ona zostaje. */
  is_owner: boolean
  /** Kto jest właścicielem, np. „konto B2B 3M”; null = brak. */
  owner_label: string | null
  has_description: boolean
  images: number
  size_rows: number
  tender_items: number
  presta: boolean
  purchase_price: string | null
  currency: string | null
  sources: Array<{ source_key: string; label: string; purchase_price: string | null; currency: string | null }>
}

/** Ostrzeżenie podglądu; requires_confirm = bez potwierdzenia (confirm_brand) serwer nie połączy. */
export type ManualMergeWarning = {
  code: 'brand' | 'price_diff' | 'size_color' | 'tender_items' | string
  text: string
  requires_confirm: boolean
}

/** Co przejdzie z kart znikających do karty, która zostaje (liczby). */
export type ManualMergeMoves = {
  source_prices: number
  b2b_links: number
  images: number
  identifiers: number
  tender_items: number
  size_rows: number
  accessories: number
}

export type ManualMergePreview = {
  cards: ManualMergeCard[]
  /** Karta, która zostaje w tym podglądzie; null = nie da się wskazać (np. kilka kart producenta). */
  keep_product_id: number | null
  suggested_keep_id: number | null
  suggestion_reason: string
  /** Zostać może tylko podpowiedziana karta (karta producenta) — wybór innej to blokada. */
  keep_locked: boolean
  blockers: string[]
  warnings: ManualMergeWarning[]
  moves: ManualMergeMoves
  can_merge: boolean
  plan_hash: string
}

export type ManualMergeRequest = {
  product_ids: number[]
  keep_product_id: number
  plan_hash: string
  confirm_brand?: boolean
  note?: string | null
}

export type ManualMergeResult = {
  keep_product_id: number
  merged_product_ids: number[]
  candidate_ids: number[]
  backup_path: string
}

/** Podgląd ręcznego połączenia kart (tylko odczyt); keepId null/brak = podpowiedź serwera. */
export function manualMergePreview(productIds: number[], keepId?: number | null): Promise<ManualMergePreview> {
  return api<ManualMergePreview>('/card-matches/manual/preview', {
    method: 'POST',
    body: JSON.stringify({ product_ids: productIds, keep_product_id: keepId ?? null }),
  })
}

/**
 * Ręczne połączenie kart. 409 = dane zmieniły się od podglądu — świeży podgląd w ciele błędu
 * (manualMergeConflictPreview); 422 = blokada albo walidacja (komunikat w message).
 */
export function manualMerge(body: ManualMergeRequest): Promise<ManualMergeResult> {
  return api<ManualMergeResult>('/card-matches/manual', { method: 'POST', body: JSON.stringify(body) })
}

/** Świeży podgląd z odpowiedzi 409 ręcznego połączenia; null dla innych błędów albo odpowiedzi bez podglądu. */
export function manualMergeConflictPreview(ex: unknown): ManualMergePreview | null {
  if (!(ex instanceof ApiError) || ex.status !== 409) return null
  const preview = ex.body.preview
  if (preview && typeof preview === 'object' && Array.isArray((preview as ManualMergePreview).cards)) {
    return preview as ManualMergePreview
  }
  return null
}

/** Plik z API jako Blob (z kluczem logowania) — np. obrazek do pokazania przez URL.createObjectURL. */
export async function apiBlob(path: string, signal?: AbortSignal): Promise<Blob> {
  const headers = new Headers({ Accept: 'application/json, */*' })
  const t = token()
  if (t) headers.set('Authorization', `Bearer ${t}`)
  const res = await fetch(`${API_URL}${path}`, { headers, signal })
  if (!res.ok) {
    const body = await res.json().catch(() => ({}))
    throw new ApiError(typeof body.message === 'string' ? body.message : `Błąd API ${res.status}`, res.status, body)
  }
  return res.blob()
}

/**
 * `accept` — nagłówek Accept. Laravel oddaje błędy (422, 404, 429) jako JSON z komunikatem tylko wtedy, gdy JSON jest
 * pierwszy na liście — przy samym * / * walidacja kończy się przekierowaniem, a pobrany plik byłby stroną HTML.
 */
export async function downloadFile(path: string, fallbackName: string, accept = '*/*'): Promise<void> {
  const headers = new Headers({ Accept: accept })
  const t = token()
  if (t) headers.set('Authorization', `Bearer ${t}`)

  const res = await fetch(`${API_URL}${path}`, { headers })
  if (!res.ok) {
    const body = await res.json().catch(() => ({}))
    throw new Error(body.message ?? `Błąd pobierania ${res.status}`)
  }

  const blob = await res.blob()
  const cd = res.headers.get('Content-Disposition')
  const match = cd?.match(/filename="?([^"]+)"?/)
  const name = match?.[1] ?? fallbackName
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = name
  a.click()
  URL.revokeObjectURL(url)
}

/* ---------- Etapy 3–4: kalendarz terminów, wyszukiwanie, karta klienta, wynik zapytania, cele ---------- */

/**
 * Stan przetargu w kalendarzu (pierwszy pasujący): result_needed — po terminie, bez wyniku, do 60 dni; closed —
 * wysłany, archiwum, odrzucony albo z wynikiem; ready — są pozycje i wszystkie mają produkt i cenę; urgent — braki
 * i termin za ≤ 3 dni; in_progress — reszta.
 */
export type TenderCalendarState = 'ready' | 'in_progress' | 'urgent' | 'result_needed' | 'closed'

export type TenderCalendarEvent = {
  tender_id: number
  number: string
  notice_number: string | null
  title: string
  client: string | null
  status: string
  /** dzień terminu „na zegarze” w Polsce (RRRR-MM-DD) */
  date: string
  /** godzina składania „HH:MM” w czasie polskim; null = bez godziny */
  time: string | null
  state: TenderCalendarState
  missing: { items: number; without_product: number; without_price: number }
  url: string
}

/** mine — prowadzę; invited — zaproszono mnie; pusty — wszystkie, które mogę oglądać */
export type TenderCalendarFilter = '' | 'mine' | 'invited'

export type TenderCalendarResponse = {
  from: string
  to: string
  today: string
  events: TenderCalendarEvent[]
}

/** Zakres from–to najwyżej 62 dni (inaczej 422). */
export function fetchTenderCalendar(
  params: { from: string; to: string; filter?: TenderCalendarFilter },
  signal?: AbortSignal,
): Promise<TenderCalendarResponse> {
  const q = new URLSearchParams({ from: params.from, to: params.to })
  if (params.filter) q.set('filter', params.filter)
  return api<TenderCalendarResponse>(`/tenders/calendar?${q.toString()}`, { signal })
}

/** Osobisty adres kalendarza (ICS). url tylko w odpowiedzi na wygenerowanie — potem już nie do odczytania. */
export type CalendarFeed = {
  active: boolean
  scope: 'mine' | 'all' | null
  created_at: string | null
  used_at: string | null
  url?: string
}

export function fetchCalendarFeed(): Promise<CalendarFeed> {
  return api<CalendarFeed>('/me/calendar-feed')
}

/** Nowy adres (stary przestaje działać). scope „all” tylko z tenders.view_all. */
export function createCalendarFeed(scope: 'mine' | 'all'): Promise<CalendarFeed> {
  return api<CalendarFeed>('/me/calendar-feed', { method: 'POST', body: JSON.stringify({ scope }) })
}

export function deleteCalendarFeed(): Promise<{ ok: true }> {
  return api<{ ok: true }>('/me/calendar-feed', { method: 'DELETE' })
}

export type GlobalSearchGroupKey = 'products' | 'tenders' | 'inquiries' | 'clients'

export type GlobalSearchHit = {
  id: number
  title: string
  subtitle: string | null
  detail: string | null
  badge: string | null
  url: string
  /** stan z ERP XL — tylko z inventory.view (produkty) */
  stock?: { quantity: string; unit: string | null } | null
}

export type GlobalSearchResponse = {
  query: string
  groups: {
    key: GlobalSearchGroupKey
    label: string
    items: GlobalSearchHit[]
    has_more: boolean
    /**
     * „Pokaż wszystkie” — tylko produkty (/products?q=) i zapytania (/inquiries?q=, przy inquiries.view_all
     * z &scope=all). null — lista nie pokaże tych samych wyników (np. same cudze zapytania z inquiries.view_others).
     */
    more_url: string | null
  }[]
}

/** q: 2–100 znaków. */
export function globalSearch(q: string, signal?: AbortSignal): Promise<GlobalSearchResponse> {
  return api<GlobalSearchResponse>(`/search?q=${encodeURIComponent(q)}`, { signal })
}

/** Osoba kontaktowa z ERP XL (clients.contacts). */
export type ClientContact = { name?: string; position?: string; email?: string; phone?: string; mobile?: string }

/** Klient z zakładki Klienci (GET /clients?page=… albo ?details=1 — pełne dane; ręczny albo z ERP XL). */
export type ClientRecord = {
  id: number
  name: string
  acronym: string | null
  nip: string | null
  nip_prefix: string | null
  regon: string | null
  street: string | null
  address_line2: string | null
  postal_code: string | null
  city: string | null
  county: string | null
  commune: string | null
  voivodeship: string | null
  country: string | null
  phone: string | null
  phone2: string | null
  fax: string | null
  emails: string[] | null
  website: string | null
  contacts: ClientContact[] | null
  account_manager: string | null
  account_manager_email: string | null
  owner_id: number | null
  source: 'manual' | 'erp_xl'
  xl_gid: number | null
  xl_archived: boolean
  sales_year: number | null
  sales_net: string | null
  sale_documents: number | null
  last_sale_at: string | null
  xl_synced_at: string | null
}

export type InquiryOutcome = 'ordered' | 'partial' | 'not_ordered' | 'unknown'

/** Powód (tylko przy partial i not_ordered): cena, termin dostawy, kupił gdzie indziej, klient nie odpowiedział. */
export type InquiryOutcomeReason = 'price' | 'lead_time' | 'bought_elsewhere' | 'no_response'

/** Źródło powiązania zapytania z klientem: manual — wybrał handlowiec, email — ten sam adres co w ERP XL, nip — NIP z maila. */
export type InquiryClientLinkSource = 'manual' | 'email' | 'nip'

export type ClientCard = {
  client: ClientRecord & { owner: { id: number; name: string } | null; xl_manager_gid: number | null }
  /** opiekun z karty w ERP XL; user — konto przypisane temu pracownikowi XL */
  xl_manager: { name: string | null; email: string | null; user: { id: number; name: string } | null } | null
  /** opiekun w aplikacji (clients.owner_id) */
  app_owner: { id: number; name: string } | null
  /** do kogo klient się liczy (cele): pracownik XL zmapowany na konto, potem opiekun w aplikacji */
  assignment: { user: { id: number; name: string } | null; source: 'xl' | 'app' | null }
  tiles: {
    sales_year: { year: number; net: string; documents: number } | null
    last_sale: { date: string; document_number: string | null } | null
    /** null — brak uprawnienia do danych tej liczby */
    last_12m: { invoices: number; inquiries: number | null; tenders: number | null; ordered_inquiries: number | null }
  }
  /** najczęściej kupowane (24 miesiące), do 10 */
  top_items: {
    erp_item_id: number
    code: string
    name: string
    unit: string | null
    quantity: string
    documents: number
    last_sale_at: string | null
    product: { id: number; name: string } | null
  }[]
  sections: { inquiries: boolean; tenders: boolean; campaigns: boolean }
  can_manage: boolean
  documents_synced_at: string | null
  /** konta do wyboru opiekuna w aplikacji — tylko przy clients.manage */
  owner_options?: { id: number; name: string }[]
}

export type ClientTimelineType = 'all' | 'invoices' | 'inquiries' | 'tenders' | 'campaigns' | 'notes'

export type TimelineEvent =
  | {
      type: 'invoice'
      id: number
      date: string
      document_number: string
      kind: string
      net_value: string
      /** can_open — własne zapytanie albo inquiries.view_others; inaczej bez linku */
      confirmed_inquiry: { id: number; date: string; can_open: boolean } | null
    }
  | {
      type: 'inquiry'
      id: number
      date: string
      subject: string | null
      items_count: number
      replied_at: string | null
      outcome: InquiryOutcome | null
      user: { id: number; name: string }
      can_open: boolean
      link_source: InquiryClientLinkSource
    }
  | {
      type: 'tender'
      id: number
      date: string
      number: string
      title: string
      status: string
      result_status: TenderResultStatus | null
      url: string
    }
  | { type: 'campaign'; id: number; date: string; name: string; clicks: number; replied: boolean; user: { id: number; name: string } }
  | {
      type: 'note'
      id: number
      date: string
      body: string
      author: { id: number; name: string } | null
      remind_on: string | null
      can_edit: boolean
    }

export type ClientTimeline = {
  data: TimelineEvent[]
  /** źródło ucięte na 200 wpisach */
  truncated: Record<string, boolean>
}

export function fetchClientCard(clientId: number, signal?: AbortSignal): Promise<ClientCard> {
  return api<ClientCard>(`/clients/${clientId}`, { signal })
}

export function fetchClientTimeline(clientId: number, type: ClientTimelineType = 'all', signal?: AbortSignal): Promise<ClientTimeline> {
  return api<ClientTimeline>(`/clients/${clientId}/timeline?type=${type}`, { signal })
}

/** body do 5000 znaków; remind_on „RRRR-MM-DD” (dziś albo później) albo null — bez przypomnienia. */
export type ClientNoteInput = { body: string; remind_on: string | null }

export function createClientNote(clientId: number, input: ClientNoteInput): Promise<TimelineEvent> {
  return api<TimelineEvent>(`/clients/${clientId}/notes`, { method: 'POST', body: JSON.stringify(input) })
}

export function updateClientNote(clientId: number, noteId: number, input: Partial<ClientNoteInput>): Promise<TimelineEvent> {
  return api<TimelineEvent>(`/clients/${clientId}/notes/${noteId}`, { method: 'PATCH', body: JSON.stringify(input) })
}

export function deleteClientNote(clientId: number, noteId: number): Promise<{ ok: true }> {
  return api<{ ok: true }>(`/clients/${clientId}/notes/${noteId}`, { method: 'DELETE' })
}

/** Opiekun w aplikacji (clients.manage): id użytkownika albo null. */
export function updateClientOwner(clientId: number, ownerId: number | null): Promise<ClientRecord> {
  return api<ClientRecord>(`/clients/${clientId}`, { method: 'PATCH', body: JSON.stringify({ owner_id: ownerId }) })
}

export type InquiryOutcomeView = {
  outcome: InquiryOutcome | null
  reason: InquiryOutcomeReason | null
  by: { id: number; name: string } | null
  at: string | null
  /** dokument z ERP XL skopiowany z podpowiedzi przy potwierdzeniu */
  document: { number: string; date: string; net_value: string } | null
  can_edit: boolean
}

/** client null tylko przy source 'manual' — handlowiec świadomie wybrał „Bez klienta” (automat tego nie zmienia). */
export type InquiryClientLink = {
  client: { id: number; name: string } | null
  source: InquiryClientLinkSource
}

/** Podpowiedź „możliwe, że to zamówienie z tej oferty” — wniosek z ERP XL, nie fakt. */
export type InquiryOrderHint = {
  id: number
  document_number: string
  issued_at: string
  document_net: string
  matched_net: string
  /** towary w ofercie / z nich powiązane z towarem XL / znalezione na dokumencie */
  offered_items: number
  linked_items: number
  matched_items: number
}

/** no_client — brak pewnego powiązania z klientem z ERP XL; no_xl — ERP XL wyłączony; not_replied — bez odpowiedzi. */
export type InquiryOrderHints = {
  status: 'ok' | 'no_client' | 'no_xl' | 'not_replied'
  /** reguła słowami (pokazywana szaro przy podpowiedzi) */
  rule: string
  computed_at: string | null
  hints: InquiryOrderHint[]
}

/** Nowe pola GET /inquiries/{id} (etap 4). */
export type InquiryOutcomeFields = {
  outcome: InquiryOutcomeView
  client_link: InquiryClientLink | null
  offer_valid_until: string | null
  validity_text: string | null
  order_hints: InquiryOrderHints
}

/** Filtr listy zapytań: missing = wysłane bez wpisanego wyniku. */
export type InquiryOutcomeFilter = InquiryOutcome | 'missing'

/** Przed wysłaniem odpowiedzi → 422; reason tylko przy partial / not_ordered; hint_id kopiuje dokument z podpowiedzi. */
export function saveInquiryOutcome(
  inquiryId: number,
  body: { outcome: InquiryOutcome | null; reason: InquiryOutcomeReason | null; hint_id?: number | null },
): Promise<InquiryOutcomeView> {
  return api<InquiryOutcomeView>(`/inquiries/${inquiryId}/outcome`, { method: 'PUT', body: JSON.stringify(body) })
}

/** Wybór handlowca (autor zapytania): klient albo null — świadomie bez klienta (automat już tego nie zmieni). */
export function linkInquiryClient(inquiryId: number, clientId: number | null): Promise<{ client_link: InquiryClientLink | null }> {
  return api<{ client_link: InquiryClientLink | null }>(`/inquiries/${inquiryId}/client`, {
    method: 'PUT',
    body: JSON.stringify({ client_id: clientId }),
  })
}

/** Sprawa „Do zrobienia dziś”: oferta z zapytania kończy ważność, a wyniku nie ma (DashboardController::todo). */
export type DashTodoOfferValidityEnding = {
  kind: 'offer_validity_ending'
  inquiry_id: number
  client: string | null
  subject: string | null
  valid_until: string
  has_hint: boolean
  url: string
}

/** Własny cel na Dashboardzie (GET /me/sales-target); target null — brak celu w tym miesiącu. */
export type MySalesTarget = {
  month: string
  target: string | null
  sales: string
  percent: number | null
  workdays: { total: number; elapsed: number }
  clients_bought: number
  new_clients: number
}

export function fetchMySalesTarget(signal?: AbortSignal): Promise<MySalesTarget> {
  return api<MySalesTarget>('/me/sales-target', { signal })
}

/** Pracownik ERP XL (opiekun klientów) do przypisania kontu w Administracji → Użytkownicy. */
export type ErpEmployee = {
  gid: number
  name: string | null
  email: string | null
  /** liczba klientów z tym opiekunem */
  clients: number
  user: { id: number; name: string } | null
  /** propozycja: konto z tym samym e-mailem — tylko podpowiedź */
  suggested_user: { id: number; name: string } | null
}

export function fetchErpEmployees(): Promise<ErpEmployee[]> {
  return api<{ data: ErpEmployee[] }>('/admin/erp-employees').then((r) => r.data)
}

/* ---------- Ogłoszenia o zamówieniu z Biuletynu Zamówień Publicznych (GET /notices) ---------- */

/** new — bez decyzji i bez przetargu; created — jest przetarg z tym postępowaniem; skipped — pominięte przez zespół. */
export type NoticeTab = 'new' | 'created' | 'skipped'

/** Źródło ogłoszenia na liście; na razie tylko Biuletyn Zamówień Publicznych. */
export type NoticeListSource = 'bzp'

export type NoticeRow = {
  id: number
  source: NoticeListSource
  /** „2026/BZP 00431178/01” */
  notice_number: string
  published_at: string | null
  /** termin składania ofert (ISO, UTC); null — ogłoszenie go nie podaje */
  submitting_offers_at: string | null
  /** ten sam termin w czasie polskim, np. „13.10.2026, 10:00” */
  deadline_local: string | null
  order_object: string | null
  organization: {
    name: string | null
    city: string | null
    /** kod województwa „PL18” */
    province_code: string | null
    province_name: string | null
    /** 10 cyfr albo null */
    nip: string | null
  }
  /** etykiety rodzajów towaru (z kodów rodzaju zamówienia — CPV) */
  categories: string[]
  cpv_codes: string[]
  /** wartość podana w ogłoszeniu; null — nie podano */
  total_value: string | null
  lots_count: number
  /** strona postępowania na platformie zamawiającego (dokumenty) */
  procedure_url: string | null
  /** strona ogłoszenia w Biuletynie */
  notice_url: string | null
  /** przetarg założony z tym postępowaniem; can_open — użytkownik ma do niego dostęp */
  tender: { id: number; number: string; can_open: boolean } | null
  /** decyzja „pominięte” (wspólna dla zespołu); by null — konto usunięte */
  skipped: { by: { id: number; name: string } | null; at: string | null } | null
  /** termin składania minął */
  past: boolean
  /**
   * klient, którego „Załóż przetarg” użyje jako zamawiającego (po NIP-ie; przy kilku klientach z tym NIP-em — po NIP-ie
   * i nazwie; bez NIP-u — po nazwie); null z pustą listą `client_candidates` — zostanie dopisany nowy klient z danymi
   * z ogłoszenia (albo brak uprawnienia do zakładania przetargów, albo przetarg już jest)
   */
  client_match?: { id: number; name: string; matched_by: 'nip' | 'nip_name' | 'name' } | null
  /** kilku pasujących klientów (najwyżej 10) — zamawiającego wybiera człowiek; pusta lista — wybór niepotrzebny */
  client_candidates?: NoticeClientCandidate[]
}

/** Klient z zakładki Klienci pasujący do zamawiającego z ogłoszenia; nip — 10 cyfr albo null. */
export type NoticeClientCandidate = { id: number; name: string; nip: string | null; city: string | null }

export type NoticesResponse = {
  data: NoticeRow[]
  meta: { page: number; last_page: number; total: number }
  counts: Record<NoticeTab, number>
  categories: { key: string; label: string }[]
  provinces: { code: string; name: string }[]
  /** najpóźniejsze pobranie z Biuletynu; null — jeszcze nic nie pobrano */
  fetched_at: string | null
  source_note: string
}

/**
 * category — klucz rodzaju z `categories`; province — kod „PLxx”; past — pokaż też po terminie (zakładka „Nowe”);
 * source — źródło ogłoszeń (bez parametru serwer bierze Biuletyn Zamówień Publicznych — na razie jedyne źródło).
 */
export type NoticeListParams = {
  tab: NoticeTab
  source?: NoticeListSource
  category?: string
  province?: string
  q?: string
  past?: boolean
  page?: number
}

export function fetchNotices(params: NoticeListParams, signal?: AbortSignal): Promise<NoticesResponse> {
  const q = new URLSearchParams({ tab: params.tab })
  if (params.source) q.set('source', params.source)
  if (params.category) q.set('category', params.category)
  if (params.province) q.set('province', params.province)
  if (params.q) q.set('q', params.q)
  if (params.past) q.set('past', '1')
  if (params.page && params.page > 1) q.set('page', String(params.page))
  return api<NoticesResponse>(`/notices?${q.toString()}`, { signal })
}

/** Pominięcie jest wspólne dla zespołu (ogłoszenie znika z „Nowe” u wszystkich). */
export function skipNotice(noticeId: number): Promise<NoticeRow> {
  return api<NoticeRow>(`/notices/${noticeId}/skip`, { method: 'POST' })
}

export function unskipNotice(noticeId: number): Promise<NoticeRow> {
  return api<NoticeRow>(`/notices/${noticeId}/skip`, { method: 'DELETE' })
}

/** Ciało odpowiedzi 409 „Załóż przetarg”: przetarg z tym postępowaniem już jest; can_open — masz do niego dostęp. */
export type NoticeTenderConflict = { message: string; tender_id: number; tender_number: string; can_open: boolean }

/** Ciało odpowiedzi 422 „Załóż przetarg” przy kilku pasujących klientach (wybierz client_id i spróbuj ponownie). */
export type NoticeTenderClientChoice = {
  message: string
  errors?: Record<string, string[]>
  client_candidates?: NoticeClientCandidate[]
}

/**
 * Rodzaj dokumentu z e-Zamówień rozpoznany po nazwie (heurystyka serwera — podpowiedź, nie fakt): description — opis
 * przedmiotu zamówienia, form — formularz cenowy albo ofertowy, swz — specyfikacja warunków zamówienia, other — inny.
 */
export type NoticeDocumentKind = 'description' | 'form' | 'swz' | 'other'

/** Dokument postępowania z publicznej listy e-Zamówień; id — objectId z e-Zamówień („{ocds}_8”). */
export type NoticeDocument = {
  id: string
  name: string
  file_name: string
  published_at: string | null
  kind: NoticeDocumentKind
  /** plik w formacie, który odczyta import dokumentów przetargu (PDF, Word, Excel, CSV) */
  importable?: boolean
  /** podpowiedź zaznaczenia: opis przedmiotu zamówienia albo formularz cenowy/ofertowy, w formacie do odczytu, z pakietu z towarami BHP */
  suggested?: boolean
  /** numer pakietu/części z nazwy dokumentu („Pakiet nr 3”) — tylko gdy ogłoszenie ma taką część */
  lot_no?: number | null
  /** czy ten pakiet ma towary BHP (ocena aplikacji z kodów CPV i opisu części) */
  lot_bhp?: boolean | null
}

/** Sekcja ogłoszenia z Biuletynu — tekst słowo w słowo z ogłoszenia (bez znaczników HTML, z akapitami). */
export type NoticeSection = { key: string; title: string; text: string }

/** Część zamówienia zapisana przy pobraniu ogłoszenia (zostaje także po skasowaniu treści HTML ogłoszenia). */
export type NoticeLot = {
  lot_no: number | null
  name: string | null
  description: string | null
  cpv_main: string | null
  cpv_main_name: string | null
  /** tekst wartości z ogłoszenia, bez przeliczania; null — nie podano */
  estimated_value: string | null
  /** czy część ma towary BHP — ocena aplikacji (kody CPV z listy BHP albo środki ochrony w opisie), powód w bhp_reason */
  bhp?: boolean
  bhp_reason?: string | null
}

/** GET /notices/{id} — te same uprawnienia co lista. */
export type NoticeDetails = {
  row: NoticeRow
  /** w kolejności z ogłoszenia; puste i nieznane serwer pomija; pusta lista, gdy treść HTML jest już skasowana */
  sections: NoticeSection[]
  lots: NoticeLot[]
  documents: {
    /** true — lista dokumentów pobrana z e-Zamówień; false — dokumenty są na innej platformie albo lista nie odpowiada */
    available: boolean
    source: 'ezamowienia' | null
    items: NoticeDocument[]
    /** wyjaśnienie dla człowieka (np. „Dokumenty są na platformie … — pobierz je ze strony postępowania i dodaj tutaj”) */
    note: string
  }
  /** false — treść HTML ogłoszenia skasowana (po 30 dniach bez przetargu); sekcji brak, zostają części z opisami */
  html_available: boolean
  /** wyjaśnienie serwera, gdy treści HTML już nie ma */
  html_note?: string | null
  /** zakładanie przetargów i dodawanie dokumentów (tenders.create i tenders.import) — wybór dokumentów ma sens */
  can_import_documents?: boolean
}

export function fetchNoticeDetails(noticeId: number, signal?: AbortSignal): Promise<NoticeDetails> {
  return api<NoticeDetails>(`/notices/${noticeId}`, { signal })
}

/**
 * Towar odczytany modelem z treści ogłoszenia (NoticeItemsReader). Fakty sprawdzone w tekście ogłoszenia: quote_found
 * (cytat jest w ogłoszeniu), quantity (liczba stoi w cytacie, inaczej null), spec (wycinki z ogłoszenia w okolicy tej
 * pozycji, nie sformułowanie modelu). bhp — ocena modelu, nie fakt z ogłoszenia.
 */
export type NoticeItem = {
  lot_no: number | null
  /** sam rodzaj towaru (bez norm i cech) */
  name: string
  /** cechy przepisane z ogłoszenia, złączone „; ”; null — ogłoszenie ich nie podaje albo nie znaleziono cytatu */
  spec: string | null
  spec_fragments: string[]
  quantity: number | null
  unit: string | null
  quote: string
  quote_found: boolean
  bhp: boolean
}

/**
 * GET /notices/{id}/items — te same uprawnienia co szczegóły. items: null — wynik nie jest zapamiętany, a użytkownik
 * bez uprawnienia do zakładania przetargów nie uruchamia modelu (wyjaśnienie w note). Błędy (ApiError): 422 — ogłoszenie
 * bez opisu przedmiotu albo model nie odpowiedział; 503 — model zajęty.
 */
export type NoticeItemsResponse = {
  items: NoticeItem[] | null
  /** części ogłoszenia z oceną „towary BHP” (wnioskowanie z kodów CPV i opisu) */
  lots: { lot_no: number; name: string | null; bhp: boolean }[]
  /** 'subject' — sekcja „Przedmiot zamówienia”; 'lots' — skrócone opisy części (pełnej treści już nie ma) */
  source: 'subject' | 'lots' | null
  /** chwila odczytu modelem (ISO) */
  read_at: string | null
  /** true — wynik zapamiętany z wcześniejszego odczytu */
  cached: boolean
  /** wyjaśnienie dla osoby bez uprawnienia do zakładania przetargów, gdy wyniku nie ma w pamięci */
  note: string | null
}

/**
 * refresh — „Odczytaj ponownie” (model jeszcze raz; tylko z uprawnieniem do zakładania przetargów). cachedOnly — tylko
 * wynik zapamiętany (bez modelu i bez zajmowania miejsca na odczyt; items: null, gdy go nie ma). Błąd 422 z
 * `reason: 'no_description'` (ApiError.body) — ogłoszenie bez opisu przedmiotu, stan trwały.
 */
export function fetchNoticeItems(
  noticeId: number,
  options: { signal?: AbortSignal; refresh?: boolean; cachedOnly?: boolean } = {},
): Promise<NoticeItemsResponse> {
  const query = options.refresh ? '?refresh=1' : options.cachedOnly ? '?cached_only=1' : ''
  return api<NoticeItemsResponse>(`/notices/${noticeId}/items${query}`, { signal: options.signal })
}

/**
 * document_ids — dokumenty z e-Zamówień potwierdzone przez serwer jako należące do postępowania; serwer ich nie pobiera
 * — kreator pobiera i odczytuje je po jednym przez POST /tenders/{id}/documents/from-notice (TenderDetail).
 */
export type CreateTenderFromNoticeResult = { tender_id: number; document_ids?: string[] }

/**
 * Zakłada przetarg (szkic) z ogłoszenia; dane bierze z najnowszej wersji ogłoszenia postępowania. clientId — zamawiający
 * wybrany przez człowieka (dowolny istniejący klient). documentIds — dokumenty z listy e-Zamówień (NoticeDocument.id) do
 * odczytu w kreatorze (wymaga też tenders.import). Błędy (ApiError): 409 — przetarg z tym postępowaniem już jest (ciało
 * NoticeTenderConflict, drugi nie powstaje); 422 z `client_candidates` — kilku pasujących klientów, trzeba wybrać
 * (NoticeTenderClientChoice); 422 z `errors.document_ids` — dokumenty spoza tego postępowania albo nie z e-Zamówień;
 * 423 — ktoś inny zakłada w tej chwili przetarg z ogłoszenia.
 */
export function createTenderFromNotice(
  noticeId: number,
  options: { clientId?: number; documentIds?: string[] } = {},
): Promise<CreateTenderFromNoticeResult> {
  const body: { client_id?: number; document_ids?: string[] } = {}
  if (options.clientId) body.client_id = options.clientId
  if (options.documentIds && options.documentIds.length > 0) body.document_ids = options.documentIds
  return api<CreateTenderFromNoticeResult>(`/notices/${noticeId}/tender`, {
    method: 'POST',
    body: JSON.stringify(body),
  })
}
