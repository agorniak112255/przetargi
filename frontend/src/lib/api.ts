import { publicDir } from './publicDir'

const API_URL = `${publicDir()}/api`

function token(): string | null {
  return localStorage.getItem('supon_token')
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
}

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
  last_activity_at: string | null
  items_count?: number
  client?: { id: number; name: string }
  owner?: { id: number; name: string }
}

export type ProductImage = {
  id: number
  url: string
  thumb_url?: string
  source_url: string | null
  is_primary: boolean
  sort_order: number
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
}

/** Ostatnia zmiana ceny wersji (ceny jako tekst z dwoma miejscami po przecinku). */
export type ProductVariantPriceChange = {
  purchase_old: string | null
  purchase_new: string | null
  pct: number | null
  at: string | null
  b2b_sync_run_id: number | null
}

/** Wersja karty u dostawcy (np. format × podłoże znaku) z ceną konta; etykieta i atrybuty dosłownie ze źródła. */
export type ProductVariant = {
  id: number
  remote_id: string
  label: string
  attributes: Record<string, string>
  purchase_price: string | null
  list_price_net: string | null
  currency: string | null
  vat_rate: number | null
  unit: string | null
  source_url: string | null
  sort_order: number
  price_checked_at: string | null
  last_seen_at: string | null
  /** Wersja zniknęła z listy dostawcy — nie liczy się do „od–do”. */
  removed_at: string | null
  last_price_change: ProductVariantPriceChange | null
}

export type ProductVariants = {
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
}

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
  /** Lista produktów i karta pozycji przetargu: tańsze źródło niż obowiązujące; null = brak. */
  cheaper_source?: CheaperSource | null
  /** Karta szczegółów: tabelki z kart wyrobu u dostawców (product_shop_cards) — osobno od opisu. */
  shop_fields?: ProductShopCardSource[]
  /** Lista produktów: karta ma wiersze ze sklepu dostawcy (sama flaga; treść dopiero w karcie szczegółów). */
  has_shop_fields?: boolean
  /** Lista produktów: ocena ceny konta B2B z karty względem cennika bazowego dostawcy; null = brak oceny. */
  supplier_special?: SupplierSpecial | null
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

/** Pytanie przed uzupełnianiem AI karty z opisem z cennika B2B (description_from_b2b). */
export const B2B_DESCRIPTION_OVERWRITE_CONFIRM =
  'Ta karta ma opis ze sklepu dostawcy (cennik B2B).\n\nUzupełnianie AI zastąpi go opisem z internetu, a kolejne pobranie cennika go nie przywróci.\n\nNadpisać opis?'

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
  }>
}

/**
 * Para: karta dystrybutora (source, duplikat) → karta producenta (target, zostaje).
 * matched_value przychodzi znormalizowane (np. „IF016FPS” dla IF/016/F/PS) — pokazujemy dosłownie.
 */
export type CardMatch = {
  id: number
  status: CardMatchStatus
  matched_by: 'ean' | 'manufacturer_code' | string
  matched_value: string
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
  /** Tylko size_merge: która karta zostaje, podpowiedź nazwy i kody rozmiarów. */
  suggested: {
    keep_product_id: number
    common_name: string | null
    sizes: Array<{ product_id: number; label: string | null; code: string }>
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

export async function downloadFile(path: string, fallbackName: string): Promise<void> {
  const headers = new Headers({ Accept: '*/*' })
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
