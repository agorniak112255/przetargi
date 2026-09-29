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
  /** 'YYYY-MM-DD' */
  last_sale_at: string | null
  last_purchase_at: string | null
  last_supplier: string | null
  outcome: ErpOutcome
  /** Kod z XL, którym próbowano łączyć (także przy no_match). */
  match_value: string | null
  links: ErpAdminLink[]
}

/** Liczniki ekranu powiązań — tylko towary aktywne (bez archiwalnych i usuniętych z XL). */
export type ErpAdminSummary = {
  total: number
  synced_at: string | null
  by_outcome: Partial<Record<NonNullable<ErpOutcome>, number>>
  unlinked_sold_12m: number
  unlinked_in_stock: number
  groups: { group: string; total: number; linked: number }[]
}

/** Magazyn towaru na stronie Zapasy; value = wartość księgowa netto partii w PLN (null = brak z XL). */
export type InventoryWarehouse = {
  code: string
  name: string
  quantity: number
  value: number | null
}

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
  /** Ilość × cena zakupu w PLN: partie leżące na stanie (XL), a bez nich stan × cena ostatniej PZ; null = brak obu. */
  stock_value: number | null
  /** Skąd wartość: partie na stanie albo — do pierwszego odczytu partii — ostatnia PZ. */
  value_source: 'lots' | 'last_purchase' | null
  /** Średnia cena zakupu towaru na stanie = wartość partii ÷ ilość (PLN za jednostkę); null = brak wartości partii. */
  unit_cost: number | null
  /** Od największego stanu. */
  warehouses: InventoryWarehouse[]
  /** 'YYYY-MM-DD'; null = nigdy (FS, paragon, WZ). */
  last_sale_at: string | null
  /** Data przyjęcia najstarszej partii leżącej na stanie ('YYYY-MM-DD'). */
  oldest_lot_at: string | null
  last_purchase: {
    date: string | null
    supplier: string | null
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
  /** Cały towar osobno w magazynach handlowych i usługowych (niezależnie od `warehouses`). */
  split: { trade: { items: number; value: number }; service: { items: number; value: number } }
  /** ISO — kiedy odczytano dane z XL. */
  as_of: string | null
  /** Cały towar w wybranych magazynach. */
  stock: { items: number; value: number }
  /** months 6, 12, 24 — nie sprzedaje się od tylu miesięcy (sprzedaż liczona ze wszystkich magazynów). */
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

/** Wiersz listy towarów raportu dla zarządu. Kwoty w zł. */
export type InventoryBoardItemRow = {
  code: string
  name: string
  /** Nazwa z katalogu, gdy towar ma kartę. */
  card_name: string | null
  quantity: number
  unit: string | null
  /** Ilość × cena zakupu w wybranych magazynach; null = XL nie podaje ceny zakupu. */
  value: number | null
  /** Wartość ÷ ilość (zł za jednostkę). */
  unit_cost: number | null
  /** 'YYYY-MM-DD'; null = nigdy (sprzedaż ze wszystkich magazynów). */
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

/** GET /api/inventory/board/items — posortowane od największej wartości. */
export type InventoryBoardItemsResponse = {
  bucket: string
  /** Np. „Towar, który nie sprzedaje się od pół roku”. */
  title: string
  data: InventoryBoardItemRow[]
  meta: { current_page: number; last_page: number; per_page: number; total: number }
  totals: { items: number; value: number }
}

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

/** GET /api/inventory/board/moves — posortowane od najnowszego. */
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
  totals: { pairs: number; value: number }
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
