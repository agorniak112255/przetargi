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
