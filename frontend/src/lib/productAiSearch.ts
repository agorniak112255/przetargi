import { api, type Product } from './api'

/** Ten sam limit co „Szukaj w katalogu” na /products. */
export const CATALOG_AI_SEARCH_LIMIT = 40
export const WEB_AI_SEARCH_LIMIT = 8
export const AI_SEARCH_MIN_CHARS = 3
export const AI_SEARCH_TIMEOUT_MS = 180_000

export type ProductAiExternalHint = { url: string; title: string }

export type ProductAiSearchResult = {
  query: string
  total: number
  products: Product[]
  needed?: string
  search_phrases?: string[]
  ai_note?: string | null
  external_hint?: ProductAiExternalHint | null
  external_hints?: ProductAiExternalHint[]
}

export function isAiSearchTimeout(ex: unknown): boolean {
  return (
    (ex instanceof DOMException && ex.name === 'AbortError') ||
    (ex instanceof Error && /abort/i.test(ex.message))
  )
}

export function externalHintsFrom(res: ProductAiSearchResult): ProductAiExternalHint[] {
  return res.external_hints ?? (res.external_hint ? [res.external_hint] : [])
}

/** Komplet (bluza+spodnie) albo dwa produkty po „+”, nie same normy EN. */
export function isDualRequirement(text: string): boolean {
  const t = text.toLowerCase()
  if (/(bluza|kurtk).{0,32}(spodn|ogrodniczk)|(spodn|ogrodniczk).{0,32}(bluza|kurtk)/.test(t)) {
    return true
  }
  const parts = text.split(/\s\+\s/)
  if (parts.length < 2) return false
  const productish = parts.filter((part) => {
    const s = part.trim()
    if (s === '') return false
    if (/^(en|iso|pn)\b/i.test(s)) return false
    return /[a-ząćęłńóśźż]{4,}/i.test(s)
  })
  return productish.length >= 2
}

/** Jedyna funkcja katalogowego AI — /products i modal przetargu. */
export async function searchProductsByAi(
  query: string,
  options: { web?: boolean; signal?: AbortSignal } = {},
): Promise<ProductAiSearchResult> {
  const web = options.web === true

  return api<ProductAiSearchResult>('/products/ai-search', {
    method: 'POST',
    body: JSON.stringify({
      query,
      ...(web ? { limit: WEB_AI_SEARCH_LIMIT } : {}),
      web,
    }),
    signal: options.signal,
  })
}

export async function searchProductsByAiWithTimeout(
  query: string,
  options: { web?: boolean } = {},
): Promise<ProductAiSearchResult> {
  const controller = new AbortController()
  const timer = window.setTimeout(() => controller.abort(), AI_SEARCH_TIMEOUT_MS)
  try {
    return await searchProductsByAi(query, { ...options, signal: controller.signal })
  } finally {
    window.clearTimeout(timer)
  }
}
