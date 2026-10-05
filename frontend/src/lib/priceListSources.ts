/**
 * Cenniki → „Z pliku”: źródła opisów przy cenniku z pliku (strony cennika, tryb) — typy odpowiedzi API według
 * kontraktu z planu SUPON_AI_Plan_Zrodla_opisow_cennikow_2026-10-05 oraz pomocnicze funkcje list stron.
 */

/** 'first' = najpierw strony cennika, potem reszta; 'only' = tylko producent i strony cennika. */
export type EnrichmentSitesMode = 'first' | 'only'

/** Największa liczba stron przy cenniku (EnrichmentSiteList::MAX). */
export const ENRICHMENT_SITES_MAX = 20

/** Karty cennika według źródła obowiązującego opisu. */
export type FilePriceListSources = {
  price_list_sites: number
  manufacturer: number
  other: number
  b2b: number
  none: number
}

export type FilePriceListHost = {
  host: string
  position: number
  on_search_sites: boolean
  indexed_pages: number
  described_cards: number
}

export type FilePriceListBatch = {
  id: number
  status: string
  total: number
  done: number
  failed: number
}

export type FilePriceList = {
  id: number
  manufacturer: string
  version: string
  enrichment_sites: string[]
  enrichment_sites_mode: EnrichmentSitesMode
  enrichment_sites_updated_at: string | null
  has_b2b_account: boolean
  cards: number
  described: number
  sources: FilePriceListSources
  /** Opisy sprzed enrichment_sites_updated_at. */
  stale: number
  queued: number
  running: number
  failed: number
  manual: number
  batch: FilePriceListBatch | null
  hosts: FilePriceListHost[]
}

/** GET /price-lists/files */
export type FilePriceListsResponse = {
  lists: FilePriceList[]
}

/** Pozycja listy Administracja → „Strony wyszukiwarka” (GET /price-lists/search-sites). */
export type SearchSiteOption = {
  host: string
  links: number
  manufacturers: string[]
  priority: number | null
  sources: string[]
}

export type SearchSitesResponse = {
  sites: SearchSiteOption[]
}

/** PATCH /price-lists/{id} — pola źródeł opisów. */
export type PriceListSourcesUpdate = {
  enrichment_sites: string[] | null
  enrichment_sites_mode: EnrichmentSitesMode
}

export type PriceListSourcesUpdateResponse = {
  message?: string
  enrichment_sites: string[] | null
  enrichment_sites_mode: EnrichmentSitesMode
  enrichment_sites_updated_at: string | null
}

export type SiteCheckHit = {
  url: string
  title: string | null
  host: string
  /** Pozycja strony na liście cennika (null = adres spoza zapisanych hostów). */
  position: number | null
  coded: boolean
}

/** POST /price-lists/{id}/site-check */
export type SiteCheckResponse = {
  product: { id: number; sku: string; name: string }
  hits: SiteCheckHit[]
}

/** Filtry ponownego pobierania opisów (POST /price-lists/{id}/enrich). */
export type ReenrichFilters = {
  only_not_from_sites: boolean
  skip_manufacturer: boolean
  /** Data (albo data z godziną); brak = bez filtra daty. */
  enriched_before?: string
}

/** POST /price-lists/{id}/enrich z apply:false */
export type EnrichPreviewResponse = {
  preview: true
  matched: number
  will_queue: number
  skipped_b2b: number
  limit: number
}

export function siteHref(site: string): string {
  return /^https?:\/\//i.test(site) ? site : `https://${site}`
}

export function siteLabel(site: string): string {
  return site.replace(/^https?:\/\//i, '').replace(/\/$/, '')
}

/** Klucz porównania strony jak host na serwerze (ManufacturerSite::normalizeHost): bez protokołu, ścieżki i „www.”. */
export function siteKey(site: string): string {
  return siteLabel(site.trim()).split(/[/?#]/)[0].replace(/^www\./i, '').toLowerCase()
}

/** Pole „jedna strona w wierszu” → lista w kolejności wpisu, bez pustych i bez powtórzeń. */
export function parseSiteLines(text: string): string[] {
  const seen = new Set<string>()
  const out: string[] = []
  for (const raw of text.split(/[\n,]/)) {
    const site = raw.trim()
    if (site === '') continue
    const key = siteKey(site)
    if (seen.has(key)) continue
    seen.add(key)
    out.push(site)
  }

  return out
}

/** Dopisuje strony na koniec pola tekstowego, pomijając te, które już w nim są. */
export function appendSites(text: string, sites: string[]): string {
  const present = new Set(parseSiteLines(text).map(siteKey))
  const added = sites.filter((s) => {
    const key = siteKey(s)
    if (key === '' || present.has(key)) return false
    present.add(key)
    return true
  })
  if (added.length === 0) return text
  const base = text.replace(/\s+$/, '')

  return base === '' ? added.join('\n') : `${base}\n${added.join('\n')}`
}

/** Wartość pola datetime-local (czas lokalny) z daty ISO. */
export function toLocalDateTimeInput(iso: string | null): string {
  if (!iso) return ''
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return ''
  const pad = (n: number) => String(n).padStart(2, '0')

  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
}
