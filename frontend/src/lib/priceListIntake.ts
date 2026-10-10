/**
 * Cenniki z plików nowym sposobem (10.10.2026): cennik → plik → importer per cennik → mapa kart do stron.
 * Typy i wywołania API według kontraktu SUPON_AI_Plan_Cenniki_Importer_2026-10-10 (sekcje 4, 5).
 */
import { api, downloadFile } from './api'
import type { EnrichmentSitesMode, FilePriceList } from './priceListSources'

/** Stan przyjęcia cennika liczony na serwerze (PriceList::intakeStatus). */
export type IntakeStatus =
  | 'legacy'
  | 'awaiting_file'
  | 'awaiting_importer'
  | 'importer_missing'
  | 'ready'
  | 'imported'
  | 'failed'

export type PriceListFileStatus = 'new' | 'imported' | 'failed' | 'superseded'

export type FileView = {
  id: number
  original_name: string
  sha256: string
  size: number
  status: PriceListFileStatus
  error: string | null
  importer_key: string | null
  importer_version: number | null
  imported_at: string | null
  created_at: string | null
  uploaded_by_name: string | null
}

export type IntakeView = {
  id: number
  manufacturer: string
  manufacturer_key: string
  version: string
  source_policy: string | null
  importer_key: string | null
  importer_label: string | null
  importer_notes: string | null
  status: IntakeStatus
  /** Strony producenta per marka (klucz marki → hosty), zapisane jako ręczne strony producenta. */
  manufacturer_hosts: Record<string, string[]>
  enrichment_sites: string[]
  enrichment_sites_mode: EnrichmentSitesMode
  suggested_prices: boolean
  discount_percent: number | null
  latest_file: FileView | null
  /** human_url = karty z adresem wskazanym przez człowieka (nie liczą się do unresolved). */
  pins: { pinned: number; unresolved: number; total: number; human_url?: number }
  /** Zapisany rabat zadziała przy następnym imporcie pliku (nie przelicza obecnych kart). */
  discount_applies_on_next_import?: boolean
}

/** Wiersz zakładki „Z pliku” — każdy niesie `intake`; cennik dawnym sposobem ma w nim status „legacy”. */
export type FilePriceListRow = FilePriceList & { intake?: IntakeView | null }

/** Przyjęcie cennika nowym sposobem (cennik → plik → importer); null = cennik dawnym sposobem. */
export function intakeOf(row: FilePriceListRow | null | undefined): IntakeView | null {
  const intake = row?.intake ?? null

  return intake && intake.status !== 'legacy' ? intake : null
}

/** POST /price-lists/intake i PATCH /price-lists/{id}/intake. */
export type IntakePayload = {
  manufacturer: string
  version: string
  manufacturer_hosts: Record<string, string[]>
  enrichment_sites: string[]
  enrichment_sites_mode: EnrichmentSitesMode
  suggested_prices: boolean
  importer_notes: string
  discount_percent: number | null
}

export type ImporterOption = {
  key: string
  label: string
  version: number
  manufacturer_keys: string[]
}

export type PreviewAction = 'create' | 'update' | 'skip' | 'blocked'

export type SourceKind = 'manufacturer' | 'supplier' | 'shop'

export type MatchKind = 'exact_code' | 'short_code' | 'ean' | 'model' | 'parts_table'

export type PreviewSources = {
  manufacturer: number
  supplier: number
  shop: number
  unresolved: number
  human_url: number
  b2b_description: number
  not_checked: number
}

/** POST /price-lists/{id}/files/{file}/preview — podgląd bez zapisów. */
export type PreviewView = {
  importer: { key: string; version: number }
  rows_total: number
  rows: Record<PreviewAction, number>
  skipped: { ref: string | number | null; sku: string | null; reason: string }[]
  price_changes: { sku: string; name: string | null; old: number | string | null; new: number | string | null }[]
  sources: PreviewSources
  unresolved: { sku: string; name: string | null; reason: string | null; candidates: unknown }[]
  samples: {
    sku: string
    name: string | null
    action: PreviewAction | string
    url: string | null
    source_kind: SourceKind | string | null
    match_kind: MatchKind | string | null
  }[]
  notes: string[]
  not_in_preview: string[]
  /** Pełne liczby — listy skipped / price_changes / unresolved są obcięte (100–200 pozycji). */
  price_changes_total?: number
  skipped_total?: number
  unresolved_total?: number
}

/** POST /price-lists/{id}/files/{file}/import (201). */
export type IntakeImportResult = {
  created: number
  updated: number
  skipped: number
  errors: string[] | number
  price_changes: unknown[] | number
  map_job: string
}

export type SourcePinState = 'unresolved' | 'pinned'

export type SourcePinRow = {
  product_id: number
  sku: string
  name: string
  url: string | null
  source_kind: SourceKind | string | null
  match_kind: MatchKind | string | null
  match_key: string | null
  unresolved_reason: string | null
  candidates: unknown
  human_url: string | null
}

export type SourcePinsResponse = {
  data: SourcePinRow[]
  meta: { current_page?: number; last_page?: number; per_page?: number; total?: number; page?: number }
}

export const INTAKE_STATUS_LABEL: Record<IntakeStatus, string> = {
  legacy: 'Dawny sposób',
  awaiting_file: 'Czeka na plik',
  awaiting_importer: 'Czeka na importer — przygotuje programista',
  importer_missing: 'Importer nie jest wdrożony',
  ready: 'Gotowy do importu',
  imported: 'Zaimportowany',
  failed: 'Błąd',
}

export const INTAKE_STATUS_TONE: Record<IntakeStatus, string> = {
  legacy: 'bg-slate-100 text-slate-700',
  awaiting_file: 'bg-slate-100 text-slate-700',
  awaiting_importer: 'bg-amber-100 text-amber-900',
  importer_missing: 'bg-orange-100 text-orange-900',
  ready: 'bg-blue-100 text-blue-800',
  imported: 'bg-emerald-100 text-emerald-800',
  failed: 'bg-red-100 text-red-800',
}

export const FILE_STATUS_LABEL: Record<PriceListFileStatus, string> = {
  new: 'czeka na import',
  imported: 'zaimportowany',
  failed: 'błąd',
  superseded: 'zastąpiony nowszym',
}

export const SOURCE_KIND_LABEL: Record<SourceKind, string> = {
  manufacturer: 'strona producenta',
  supplier: 'strona dostawcy',
  shop: 'strona sklepu',
}

export const MATCH_KIND_LABEL: Record<MatchKind, string> = {
  exact_code: 'pełny kod',
  short_code: 'skrócony kod',
  ean: 'kod EAN',
  model: 'model',
  parts_table: 'tabela części producenta',
}

export const PREVIEW_ACTION_LABEL: Record<PreviewAction, string> = {
  create: 'nowa karta',
  update: 'aktualizacja',
  skip: 'pominięty',
  blocked: 'zablokowany',
}

export function labelOf<K extends string>(labels: Record<K, string>, value: string | null | undefined): string {
  if (!value) return '—'
  return value in labels ? labels[value as K] : value
}

/** Rozszerzenia pliku cennika przyjmowane przez serwer (≤ 100 MB). */
export const INTAKE_FILE_ACCEPT = '.xlsx,.xls,.csv,.pdf'
export const INTAKE_FILE_MAX_BYTES = 100 * 1024 * 1024

/**
 * Klucz marki jak ManufacturerDomainResolver::brandKey na serwerze: część przed „/” i „(”, małe litery,
 * wszystko poza [a-z0-9] → „-”. Klucz tylko porządkuje formularz — serwer liczy go sam przy zapisie.
 */
export function brandKeyOf(manufacturer: string): string {
  const first = manufacturer.trim().split('/')[0].split('(')[0].trim().toLowerCase()

  return first.replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '')
}

export function createIntake(body: IntakePayload): Promise<{ price_list: IntakeView }> {
  return api<{ price_list: IntakeView }>('/price-lists/intake', { method: 'POST', body: JSON.stringify(body) })
}

export function updateIntake(priceListId: number, body: IntakePayload): Promise<{ price_list: IntakeView }> {
  return api<{ price_list: IntakeView }>(`/price-lists/${priceListId}/intake`, {
    method: 'PATCH',
    body: JSON.stringify(body),
  })
}

export function fetchImporters(): Promise<{ importers: ImporterOption[] }> {
  return api<{ importers: ImporterOption[] }>('/price-lists/importers')
}

export function setImporter(priceListId: number, importerKey: string | null): Promise<unknown> {
  return api<unknown>(`/price-lists/${priceListId}/importer`, {
    method: 'PATCH',
    body: JSON.stringify({ importer_key: importerKey }),
  })
}

/** 201 = nowy plik; 200 z duplicate: true = ten sam plik co najnowszy; 409 = starszy plik o tej samej treści. */
export type UploadFileResponse = { file: FileView; intake: IntakeView; duplicate?: boolean }

export function uploadPriceListFile(priceListId: number, file: File): Promise<UploadFileResponse> {
  const fd = new FormData()
  fd.append('file', file)

  return api<UploadFileResponse>(`/price-lists/${priceListId}/files`, { method: 'POST', body: fd })
}

export function fetchPriceListFiles(priceListId: number): Promise<{ files: FileView[] }> {
  return api<{ files: FileView[] }>(`/price-lists/${priceListId}/files`)
}

export function downloadPriceListFile(priceListId: number, file: FileView): Promise<void> {
  return downloadFile(`/price-lists/${priceListId}/files/${file.id}/download`, file.original_name, 'application/json, */*')
}

export function previewPriceListFile(priceListId: number, fileId: number, limit = 200): Promise<PreviewView> {
  return api<PreviewView>(`/price-lists/${priceListId}/files/${fileId}/preview`, {
    method: 'POST',
    body: JSON.stringify({ limit }),
  })
}

export function importPriceListFile(priceListId: number, fileId: number, describe: boolean): Promise<IntakeImportResult> {
  return api<IntakeImportResult>(`/price-lists/${priceListId}/files/${fileId}/import`, {
    method: 'POST',
    body: JSON.stringify({ describe }),
  })
}

export function fetchSourcePins(priceListId: number, state: SourcePinState, page = 1): Promise<SourcePinsResponse> {
  const qs = new URLSearchParams({ state, page: String(page) })

  return api<SourcePinsResponse>(`/price-lists/${priceListId}/source-pins?${qs.toString()}`)
}

/** Liczba z pola, które serwer może podać jako liczbę albo listę (errors, price_changes w wyniku importu). */
export function countOf(value: unknown[] | number | null | undefined): number {
  if (Array.isArray(value)) return value.length
  return typeof value === 'number' ? value : 0
}

export function formatBytes(size: number): string {
  if (size >= 1024 * 1024) return `${(size / (1024 * 1024)).toLocaleString('pl-PL', { maximumFractionDigits: 1 })} MB`
  if (size >= 1024) return `${Math.round(size / 1024).toLocaleString('pl-PL')} kB`

  return `${size} B`
}
