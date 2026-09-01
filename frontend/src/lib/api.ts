// W dev: proxy Vite (/api → :8000). W prod: pełny URL lub względny /api.
const API_URL = import.meta.env.VITE_API_URL ?? '/api'

function token(): string | null {
  return localStorage.getItem('supon_token')
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
    throw new Error(String(msg))
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
  source_url: string | null
  is_primary: boolean
  sort_order: number
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

export type Product = {
  id: number
  sku: string
  name: string
  manufacturer: string
  category: string | null
  assortment_group_id?: number | null
  description?: string | null
  norms: string | null
  catalog_price_net: string
  purchase_price: string
  discount_percent?: string
  currency?: string | null
  price_pln?: number | null
  stock: number
  pack_qty?: number | null
  packaging?: string | null
  substitutes_count?: number
  enrichment_status?: 'none' | 'queued' | 'running' | 'done' | 'failed' | 'manual'
  enriched_at?: string | null
  enrichment_error?: string | null
  price_change_percent?: number | null
  price_history_latest_at?: string | null
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
  images?: ProductImage[]
  images_count?: number
  documents?: ProductDocument[]
  documents_count?: number
  ai_match_percent?: number
  ai_match_reason?: string | null
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
  const base = import.meta.env.BASE_URL.replace(/\/$/, '')
  const p = path.startsWith('/') ? path : `/${path}`
  return `${base}${p}`
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
