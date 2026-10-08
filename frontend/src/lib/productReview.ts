/**
 * Przegląd opisów kart cenników z plików (etap 1, 08.10.2026) — typy odpowiedzi API i wywołania według kontraktu
 * z SUPON_AI_Plan_Etap1_Opisy_2026-10-08 (GET /product-reviews, historia wersji opisu, decyzje, przywrócenie wersji,
 * tekst zapisanej strony źródła).
 */
import { api, apiBlob } from './api'

/** Powód, dla którego opis czeka na przegląd (Product::REVIEW_*). */
export type ReviewReason =
  | 'identity_soft'
  | 'identity_none'
  | 'worse_version'
  | 'rejected_source'
  | 'manufacturer_missing'

export const REVIEW_REASONS: ReviewReason[] = [
  'identity_soft',
  'identity_none',
  'worse_version',
  'rejected_source',
  'manufacturer_missing',
]

/** Krótka nazwa powodu (filtr, wiersz listy). */
export const REVIEW_REASON_LABEL: Record<ReviewReason, string> = {
  identity_soft: 'strona bez kodu wyrobu',
  identity_none: 'strona niepotwierdzona',
  worse_version: 'nowy opis gorszy od obecnego',
  rejected_source: 'odrzucona strona',
  manufacturer_missing: 'brak strony producenta',
}

/** Wyjaśnienie powodu prostym językiem — co handlowiec ma sprawdzić. */
export const REVIEW_REASON_HINT: Record<ReviewReason, string> = {
  identity_soft:
    'Opis zapisano ze strony, na której zgadzają się nazwa i producent, ale nie ma kodu wyrobu. Sprawdź, czy to ten sam wyrób.',
  identity_none:
    'Program nie potwierdził, że strona dotyczy tego wyrobu — ani kodu, ani nazwy z producentem. Sprawdź stronę albo wskaż właściwą.',
  worse_version:
    'Nowe pobranie dało opis słabiej potwierdzony niż obecny. Obecny opis zostaje na karcie, nowy czeka jako propozycja.',
  rejected_source:
    'Nowy opis pochodzi ze strony, którą ktoś wcześniej odrzucił. Obecny opis zostaje na karcie, nowy czeka jako propozycja.',
  manufacturer_missing:
    'Opis tego producenta bierzemy tylko z jego własnej strony, a program jej nie znalazł. Opisu ze sklepu program nie zapisuje — jeśli karta miała taki opis, został zdjęty z karty i jest w historii wersji („Przywróć”); zdjęcia i pliki zostały. Wskaż adres strony producenta albo opisz ręcznie.',
}

export function reviewReasonLabel(reason: string | null | undefined): string {
  return reason && reason in REVIEW_REASON_LABEL ? REVIEW_REASON_LABEL[reason as ReviewReason] : (reason ?? '')
}

/** Werdykt tożsamości strony: hard = kod wyrobu na stronie, soft = nazwa i producent, none = sama heurystyka. */
export type IdentityVerdict = 'hard' | 'soft' | 'none'

export const IDENTITY_LABEL: Record<IdentityVerdict, string> = {
  hard: 'potwierdzona kodem',
  soft: 'bez kodu wyrobu',
  none: 'niepotwierdzona',
}

export function identityLabel(verdict: string | null | undefined): string {
  return verdict && verdict in IDENTITY_LABEL ? IDENTITY_LABEL[verdict as IdentityVerdict] : 'nieznana'
}

export type ReviewVersionRef = {
  version_id: number
  identity_verdict: IdentityVerdict | null
  evidence_count: number | null
  /** Adres strony źródła tej wersji — odrzucenie opisu z karty blokuje go; bez adresu niczego nie blokuje. */
  primary_source_url: string | null
  /** Skrót tekstu tej wersji — ten sam skrót na kartach modelu = ten sam opis (decyzja zbiorcza ma sens). */
  description_sha1: string | null
}

/**
 * Model karty (etap 2 opisów z cenników): klucz liczony w locie dla marki z grupowaniem (Coba) — ten sam wyrób
 * w różnych wymiarach i kolorach. Null = karta jest sama sobie modelem (marka bez grupowania).
 */
export type ReviewRowModel = {
  key: string
  /** Rdzeń nazwy modelu — nazwa bez rozmiarów, wymiarów i kolorów. */
  stem: string
  /** Ile kart tego modelu jest na całej liście przy bieżących filtrach (przed stronicowaniem). */
  in_review: number
  /** Karta, od której ta karta dostała opis wspólny modelu; null = karta sama pobrała opis. */
  shared_from: number | null
}

export type ReviewRow = {
  product_id: number
  sku: string
  name: string
  manufacturer: string
  price_list_id: number
  review_reason: ReviewReason
  review_since: string | null
  enrichment_status: string
  primary_source_url: string | null
  primary_source_kind: string | null
  identity: { verdict: IdentityVerdict | null; reason: string | null } | null
  evidence_summary: { explicit: number; inferred: number } | null
  image_url: string | null
  /** Opis na karcie (bieżąca wersja opublikowana). */
  published: ReviewVersionRef | null
  /** Nowy opis czekający na decyzję (worse_version, rejected_source). */
  proposal: (ReviewVersionRef & { created_at: string | null }) | null
  /** Model karty — front grupuje sąsiednie wiersze tego samego modelu; null = bez grupowania. */
  model: ReviewRowModel | null
}

export type ReviewListResponse = {
  data: ReviewRow[]
  meta: { total: number; page: number; per_page: number }
  counts: {
    by_reason: Partial<Record<ReviewReason, number>>
    by_price_list: { id: number; manufacturer: string; count: number }[]
  }
}

export type ReviewListFilters = {
  price_list_id?: number | null
  reason?: ReviewReason | null
  page?: number
  per_page?: number
}

/** Dowód z wersji opisu (EvidenceExtractor): explicit = wartość jest w źródle, inferred = bez pokrycia w źródle. */
export type EvidenceEntry = {
  field: string
  value: string
  quote: string | null
  source_sha256: string | null
  status: 'explicit' | 'inferred'
}

export type DescriptionVersionStatus = 'published' | 'proposed' | 'superseded' | 'rejected' | 'shadow'

export type DescriptionVersion = {
  id: number
  status: DescriptionVersionStatus
  origin: string
  identity_verdict: IdentityVerdict | null
  identity_reason: string | null
  evidence_count: number | null
  completeness: number | null
  review_reason: ReviewReason | null
  reason: string | null
  created_at: string | null
  decision: 'approved' | 'rejected' | 'url_given' | 'restored' | null
  decided_at: string | null
  decided_by: { id: number; name: string } | null
  description: string | null
  primary_source_url: string | null
  source_urls: string[]
  evidence: EvidenceEntry[]
  /** Odrzucony opis z karty blokuje swój adres dla automatu (zdejmuje to zatwierdzenie wersji z tego adresu). */
  url_blocked: boolean
  /** Opis zdjęty z karty bez nowego (brak strony producenta, opis był ze sklepu) — wraca przez „Przywróć”. */
  withdrawn: boolean
}

export type DescriptionVersionsResponse = {
  data: DescriptionVersion[]
  current_version_id: number | null
}

export type ReviewAction = 'approve' | 'reject' | 'url'

export type ReviewActionResponse = {
  review_reason: ReviewReason | null
  current_version_id: number | null
  batch_id: number | null
  shop_source_url: string | null
  /** Odrzucenie usunęło z karty adres strony wskazany ręcznie (prowadził na odrzuconą stronę albo karta została wyzerowana). */
  shop_source_url_cleared: boolean
  /** Opis trafił na kartę bez swoich zdjęć i plików — zostały te z poprzedniego opisu. */
  files_from_previous: boolean
}

export type RestoreVersionResponse = {
  current_version_id: number | null
  description: string | null
  /** Zdjęcia i pliki karty zostały te z poprzedniego opisu. */
  files_from_previous: boolean
}

/** Dopisek do komunikatu, gdy opis trafił na kartę bez swoich zdjęć i plików. */
export const FILES_FROM_PREVIOUS_NOTE = 'Zdjęcia i dokumenty są z poprzedniego opisu — sprawdź je na karcie produktu.'

export function fetchReviews(filters: ReviewListFilters): Promise<ReviewListResponse> {
  const qs = new URLSearchParams()
  if (filters.price_list_id) qs.set('price_list_id', String(filters.price_list_id))
  if (filters.reason) qs.set('reason', filters.reason)
  qs.set('page', String(filters.page ?? 1))
  qs.set('per_page', String(filters.per_page ?? 50))

  return api<ReviewListResponse>(`/product-reviews?${qs.toString()}`)
}

export function fetchDescriptionVersions(productId: number): Promise<DescriptionVersionsResponse> {
  return api<DescriptionVersionsResponse>(`/products/${productId}/description-versions`)
}

export function reviewProduct(
  productId: number,
  body: { action: ReviewAction; version_id?: number | null; url?: string; note?: string },
): Promise<ReviewActionResponse> {
  return api<ReviewActionResponse>(`/products/${productId}/review`, {
    method: 'POST',
    body: JSON.stringify(body),
  })
}

export function restoreDescriptionVersion(
  productId: number,
  versionId: number,
  note?: string,
): Promise<RestoreVersionResponse> {
  return api<RestoreVersionResponse>(`/products/${productId}/description-versions/${versionId}/restore`, {
    method: 'POST',
    body: JSON.stringify(note ? { note } : {}),
  })
}

/** Tekst strony źródła zapisany przy pobieraniu opisu (odpowiedź text/plain). */
export async function fetchSourceDocumentText(productId: number, documentId: number): Promise<string> {
  const blob = await apiBlob(`/products/${productId}/source-documents/${documentId}/text`)

  return blob.text()
}
