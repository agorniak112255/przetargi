import type { DescriptionLayoutBlock, Product } from './api'
import { descriptionProse } from './descriptionHighlight'

export const DEFAULT_EXPORT_BLOCKS: DescriptionLayoutBlock[] = [
  { id: 'description', visible: true, emphasis: 'none' },
  { id: 'attributes', visible: true, emphasis: 'none' },
  { id: 'specs', visible: true, emphasis: 'none' },
  { id: 'features', visible: true, emphasis: 'none' },
  { id: 'materials', visible: true, emphasis: 'none' },
  { id: 'norms', visible: true, emphasis: 'none' },
  { id: 'certificates', visible: true, emphasis: 'none' },
  { id: 'use_cases', visible: true, emphasis: 'none' },
  { id: 'sources', visible: true, emphasis: 'muted' },
]

export const DEFAULT_CARD_BLOCKS: DescriptionLayoutBlock[] = [
  { id: 'description', visible: true, emphasis: 'none' },
  { id: 'attributes', visible: true, emphasis: 'none' },
  { id: 'specs', visible: true, emphasis: 'none' },
  { id: 'features', visible: true, emphasis: 'none' },
  { id: 'materials', visible: true, emphasis: 'none' },
  { id: 'norms', visible: true, emphasis: 'none' },
  { id: 'certificates', visible: true, emphasis: 'none' },
  { id: 'use_cases', visible: true, emphasis: 'none' },
  { id: 'documents', visible: true, emphasis: 'none' },
  { id: 'sources', visible: true, emphasis: 'muted' },
]

export const BLOCK_LABELS: Record<string, string> = {
  description: 'Opis',
  attributes: 'Atrybuty BHP',
  specs: 'Specyfikacja',
  features: 'Cechy',
  materials: 'Materiały',
  norms: 'Normy',
  certificates: 'Certyfikaty',
  use_cases: 'Zastosowanie',
  documents: 'Pliki PDF',
  sources: 'Źródła',
}

export const EMPHASIS_LABELS: Record<string, string> = {
  none: 'Brak',
  highlight: 'Podświetlenie',
  accent: 'Wyróżnienie',
  muted: 'Wyciszone',
  strong: 'Mocne',
}

export const EMPHASIS_CLASS: Record<string, string> = {
  none: '',
  highlight: 'rounded-lg border border-amber-200 bg-amber-50 px-3 py-2',
  accent: 'rounded-lg border border-violet-200 bg-violet-50 px-3 py-2',
  muted: 'opacity-75',
  strong: 'rounded-lg border-l-4 border-indigo-600 bg-slate-50 px-3 py-2',
}

const ATTR_LABELS: Array<[string, string]> = [
  ['kategoria_bhp', 'Kategoria'],
  ['material', 'Materiał'],
  ['klasa_ochrony', 'Klasa'],
  ['poziomy_en388', 'EN 388'],
  ['rozmiar', 'Rozmiar'],
  ['kod_producenta', 'Kod'],
]

export type AttributePair = { label: string; value: string }

export type DocumentItem = { url: string; title: string; meta: string }

export type LayoutSection =
  | { id: string; kind: 'prose'; title: string; text: string; emphasis: string }
  | { id: string; kind: 'list'; title: string; items: string[]; emphasis: string }
  | { id: string; kind: 'attributes'; title: string; pairs: AttributePair[]; extra: string; emphasis: string }
  | { id: string; kind: 'documents'; title: string; items: DocumentItem[]; emphasis: string }
  | { id: string; kind: 'sources'; title: string; items: string[]; emphasis: string }

function sameBlocks(a: DescriptionLayoutBlock[], b: DescriptionLayoutBlock[]): boolean {
  if (a.length !== b.length) return false
  return a.every((block, i) => {
    const other = b[i]
    return (
      other != null &&
      block.id === other.id &&
      block.visible === other.visible &&
      block.emphasis === other.emphasis
    )
  })
}

export function exportBlocksFromCard(card: DescriptionLayoutBlock[]): DescriptionLayoutBlock[] {
  const allowed = new Set(DEFAULT_EXPORT_BLOCKS.map((b) => b.id))
  const seen = new Set<string>()
  const out: DescriptionLayoutBlock[] = []
  for (const block of card) {
    if (!allowed.has(block.id) || seen.has(block.id)) continue
    out.push({ ...block })
    seen.add(block.id)
  }
  for (const block of DEFAULT_EXPORT_BLOCKS) {
    if (!seen.has(block.id)) out.push({ ...block })
  }
  return out
}

export function exportFollowsCard(layout: {
  inherit_export: boolean
  card: DescriptionLayoutBlock[]
  export: DescriptionLayoutBlock[]
}): boolean {
  if (layout.inherit_export) return true
  return sameBlocks(layout.export, DEFAULT_EXPORT_BLOCKS) && !sameBlocks(layout.card, DEFAULT_CARD_BLOCKS)
}

export function cardBlocksFor(product: Product): DescriptionLayoutBlock[] {
  const blocks = product.description_layout?.card
  return Array.isArray(blocks) && blocks.length > 0 ? blocks : DEFAULT_CARD_BLOCKS
}

export function listItems(product: Product, id: string): string[] {
  const payload = product.enrichment_payload
  const raw = payload?.[id as 'specs' | 'features' | 'materials' | 'norms' | 'certificates' | 'use_cases']
  const items = Array.isArray(raw) ? raw.filter((s): s is string => typeof s === 'string' && s.trim() !== '') : []
  if (id === 'norms' && product.norms && !items.some((s) => s.includes(product.norms ?? ''))) {
    return [product.norms, ...items]
  }
  return items
}

export function attributePairs(product: Product): AttributePair[] {
  const attrs = product.enrichment_payload?.attributes
  if (!attrs) return []
  const pairs: AttributePair[] = []
  for (const [key, label] of ATTR_LABELS) {
    const val = attrs[key as keyof typeof attrs]
    if (typeof val === 'string' && val.trim() !== '') {
      pairs.push({ label, value: val })
    }
  }
  return pairs
}

export function attributeNorms(product: Product): string {
  const normy = product.enrichment_payload?.attributes?.normy_en
  return Array.isArray(normy) && normy.length > 0 ? normy.join(', ') : ''
}

function documentMeta(kind?: string, sizeBytes?: number): string {
  const type =
    kind === 'certificate' ? 'certyfikat' : kind === 'datasheet' ? 'karta' : 'PDF'
  const size = sizeBytes ? ` · ${Math.max(1, Math.round(sizeBytes / 1024))} KB` : ''
  return `${type}${size}`
}

export function layoutSections(product: Product, blocks = cardBlocksFor(product)): LayoutSection[] {
  const out: LayoutSection[] = []
  for (const block of blocks) {
    if (!block.visible) continue
    const emphasis = block.emphasis || 'none'
    const title = BLOCK_LABELS[block.id] ?? block.id
    if (block.id === 'description') {
      const text = descriptionProse(product.description)
      if (text) out.push({ id: block.id, kind: 'prose', title, text, emphasis })
      continue
    }
    if (block.id === 'attributes') {
      const pairs = attributePairs(product)
      const extra = attributeNorms(product)
      if (pairs.length > 0 || extra) {
        out.push({ id: block.id, kind: 'attributes', title, pairs, extra, emphasis })
      }
      continue
    }
    if (block.id === 'documents') {
      const docs = (product.documents ?? [])
        .filter((d) => d.url)
        .map((d) => ({
          url: d.url,
          title: d.title || 'Dokument.pdf',
          meta: documentMeta(d.kind, d.size_bytes),
        }))
      if (docs.length > 0) out.push({ id: block.id, kind: 'documents', title, items: docs, emphasis })
      continue
    }
    if (block.id === 'sources') {
      const urls = (product.enrichment_payload?.source_urls ?? []).filter((u) => /^https?:\/\//i.test(u))
      if (urls.length > 0) out.push({ id: block.id, kind: 'sources', title, items: urls.slice(0, 3), emphasis })
      continue
    }
    const items = listItems(product, block.id)
    if (items.length > 0) out.push({ id: block.id, kind: 'list', title, items, emphasis })
  }
  return out
}

export function layoutPlainText(sections: LayoutSection[]): string {
  return sections
    .map((section) => {
      if (section.kind === 'prose') return section.text
      if (section.kind === 'list') return `${section.title}:\n${section.items.map((s) => `• ${s}`).join('\n')}`
      if (section.kind === 'attributes') {
        const lines = section.pairs.map((p) => `${p.label}: ${p.value}`)
        if (section.extra) lines.push(`Normy: ${section.extra}`)
        return `${section.title}:\n${lines.join('\n')}`
      }
      if (section.kind === 'documents') {
        return `${section.title}:\n${section.items.map((d) => `• ${d.title}`).join('\n')}`
      }
      return `${section.title}: ${section.items.join(' · ')}`
    })
    .filter(Boolean)
    .join('\n\n')
}

export function samplePreviewProduct(): Product {
  return {
    id: 0,
    sku: '1024',
    name: 'Rękawice montażowe 1024',
    manufacturer: 'Urgent',
    category: 'Rękawice ochronne / Rękawice montażowe',
    norms: 'EN 388, EN 420',
    catalog_price_net: '1.08',
    purchase_price: '0.90',
    stock: 1,
    description:
      'Rękawice uniwersalne do prac montażowych i warsztatowych. Wkładka z poliestru, powłoka PU na dłoni poprawia chwyt. Dobra zręczność i dopasowanie, wysoka odporność na ścieranie.',
    enrichment_payload: {
      specs: [
        'SKU: 1024',
        'Producent: Urgent',
        'Materiał bazowy: Poliester',
        'Powłoka: PU',
        'Mankiet: Ściągacz',
        'Rozmiary: 7-10',
      ],
      features: [
        'Dobra zręczność i chwyt',
        'Wysoka odporność na ścieranie',
        'Zgodność z EN 388 i EN 420',
      ],
      materials: ['Poliester', 'Poliuretan (PU)'],
      norms: ['EN 388 (poziomy odporności: 4,1,3,1)', 'EN 420'],
      certificates: ['CE', 'Kategoria ochrony: II'],
      use_cases: ['Prace precyzyjne', 'Ogólne prace warsztatowe'],
      source_urls: ['https://example.com/urgent-1024'],
      attributes: {
        kategoria_bhp: 'rekawice',
        kod_producenta: '1024',
        material: 'Poliester',
        normy_en: ['EN 388', 'EN 420'],
        klasa_ochrony: 'II',
        rozmiar: '7-10',
      },
    },
    documents: [{ id: 1, url: '#', title: 'Karta produktu.pdf', kind: 'datasheet', size_bytes: 120000 }],
  }
}
