import type { DescriptionLayoutBlock, Product } from './api'
import { descriptionProse } from './descriptionHighlight'
import { attributeNorms, DEFAULT_EXPORT_BLOCKS, listItems } from './descriptionLayout'
import { productDisplayName } from './productLabel'

/**
 * Oferta dla klienta z karty wyrobu: gotowy fragment HTML (tabele i style w linii — Outlook, Gmail, edytory
 * sklepów) w jednym z trzech układów. Wszystko pochodzi z karty, tak jak pokazuje ją panel (API oddaje atrybuty
 * i normy przeliczone z hierarchii źródeł). Poza ofertą zostają dane wewnętrzne: ceny zakupu, dostawcy
 * i tabelki z ich sklepów, źródła opisu, stany ERP. Rozmiarów nie dokładamy — dobiera je handlowiec.
 */

export type OfferTemplateId = 'classic' | 'bold' | 'datasheet'

export const OFFER_TEMPLATES: { id: OfferTemplateId; label: string; hint: string }[] = [
  { id: 'classic', label: 'Klasyczna', hint: 'Granat i biel, zdjęcie obok najważniejszych danych i ceny.' },
  { id: 'bold', label: 'Wyrazista', hint: 'Ciemny nagłówek, duże zdjęcie, cena na żółtym pasku.' },
  { id: 'datasheet', label: 'Karta techniczna', hint: 'Zwarte tabele w numerowanych działach — dla zakupów i BHP.' },
]

type Row = { label: string; value: string }

export type OfferDocument = { title: string; kind: string; url: string }

export type OfferData = {
  name: string
  manufacturer: string | null
  /** Kod producenta z atrybutów, a bez niego SKU karty. */
  code: Row | null
  /** Rodzaj wyrobu z atrybutu kategoria_bhp. */
  kind: string | null
  images: string[]
  paragraphs: string[]
  params: Row[]
  /** Pozycje specyfikacji bez podziału „etykieta: wartość”. */
  notes: string[]
  features: string[]
  materials: string[]
  useCases: string[]
  norms: string[]
  certificates: string[]
  documents: OfferDocument[]
}

const KIND_LABELS: Record<string, string> = {
  rekawice: 'Rękawice ochronne',
  obuwie: 'Obuwie robocze',
  odziez: 'Odzież ochronna',
  ochrona_glowy: 'Ochrona głowy',
  ochrona_twarzy: 'Ochrona twarzy',
  ochrona_oczu: 'Ochrona oczu',
  ochrona_sluchu: 'Ochrona słuchu',
  drogi_oddechowe: 'Ochrona dróg oddechowych',
  asekuracja: 'Sprzęt asekuracyjny',
  ochrona_kolan: 'Ochrona kolan',
}

const DOCUMENT_KINDS: Record<string, string> = {
  datasheet: 'Karta techniczna',
  certificate: 'Certyfikat',
  manual: 'Instrukcja',
  warranty: 'Gwarancja',
  size_chart: 'Tabela rozmiarów',
}

const MAX_IMAGES = 6

/** Etykieta nad nazwą wyrobu — klient od razu widzi, że to oferta, a nie sama karta produktu. */
const OFFER_TAG = 'Oferta handlowa'

/** Rozmiary dobiera handlowiec do zamówienia — oferta z karty ich nie wypisuje. */
const SIZE_ROW_RE = /^(rozmiar|rozmiary|rozmiarówka|size|sizes)\b/i

const COMMERCIAL_SECTION_RE = /handlow|cen[ay]|logisty|dostaw|zamawian|magazyn|dostępno/i
const COMMERCIAL_ROW_RE =
  /^(kod|indeks|symbol|ean|gtin|cena|ceny|stawka|vat|rabat|zamawian|minimum|jednostka|opakowanie|marka|producent|grupa|kategoria|stan|dostępno|termin|norm[ay]?$)/i

function clean(value: unknown): string {
  return typeof value === 'string' ? value.replace(/\s+/g, ' ').trim() : ''
}

function key(value: string): string {
  return value.toLocaleLowerCase('pl').replace(/\s+/g, ' ').trim()
}

/** Adres bezwzględny http(s) — oferta wychodzi poza panel, więc ścieżka względna nie zadziała u klienta. */
export function absoluteUrl(url: string | null | undefined, base: string): string | null {
  if (!url) return null
  try {
    const u = new URL(url, base)
    return u.protocol === 'http:' || u.protocol === 'https:' ? u.href : null
  } catch {
    return null
  }
}

export function buildOfferData(product: Product, base: string): OfferData {
  const configured = product.description_layout?.export
  const blocks: DescriptionLayoutBlock[] =
    Array.isArray(configured) && configured.length > 0 ? configured : DEFAULT_EXPORT_BLOCKS
  // Układ „na zewnątrz” z szablonu rodziny (ten sam co opis w sklepie); blok spoza listy — pokazujemy.
  const visible = (id: string) => blocks.find((b) => b.id === id)?.visible ?? true

  const attrs = product.enrichment_payload?.attributes ?? null
  const manufacturer = clean(product.manufacturer) || null
  const producerCode = clean(attrs?.kod_producenta)
  const sku = clean(product.sku)
  const code: Row | null = producerCode
    ? { label: 'Kod producenta', value: producerCode }
    : sku
      ? { label: 'Kod produktu', value: sku }
      : null
  const kindKey = clean(attrs?.kategoria_bhp)
  const kind = kindKey === '' || kindKey === 'inne' ? null : (KIND_LABELS[kindKey] ?? kindKey.replace(/_/g, ' '))

  const params: Row[] = []
  const notes: string[] = []
  const seen = new Set<string>()
  const headerValues = new Set([manufacturer, code?.value].filter((v): v is string => !!v).map(key))
  const addParam = (label: string, value: string) => {
    const l = clean(label)
    const v = clean(value)
    if (l === '' || v === '') return
    const k = `${key(l)}|${key(v)}`
    if (seen.has(k)) return
    seen.add(k)
    params.push({ label: l, value: v })
  }

  if (visible('manual_specs')) {
    for (const row of product.manual_specs ?? []) addParam(row.label, row.value)
  }
  if (visible('attributes') && attrs) {
    addParam('Materiał', clean(attrs.material))
    addParam('Klasa ochrony', clean(attrs.klasa_ochrony))
    addParam('Poziomy EN 388', clean(attrs.poziomy_en388))
  }
  if (visible('specs')) {
    for (const item of listItems(product, 'specs')) {
      const m = /^([^:]{2,48}):\s*(.+)$/s.exec(item.trim())
      if (!m) {
        notes.push(clean(item))
        continue
      }
      // Producent i kod stoją już w nagłówku oferty.
      if (headerValues.has(key(m[2])) || SIZE_ROW_RE.test(m[1].trim())) continue
      addParam(m[1], m[2])
    }
  }

  // Tabelki ze sklepów dostawców: tylko wiersze o wyrobie. Działy i wiersze handlowe (kod dostawcy, cena, VAT,
  // warunki zamawiania, opakowanie) zostają w panelu; normy pokazuje karta, producent i kod stoją w nagłówku.
  for (const source of product.shop_fields ?? []) {
    for (const section of source.sections) {
      if (COMMERCIAL_SECTION_RE.test(section.section)) continue
      for (const row of section.rows) {
        const label = clean(row.name)
        if (label === '' || COMMERCIAL_ROW_RE.test(label) || SIZE_ROW_RE.test(label) || headerValues.has(key(clean(row.value)))) continue
        addParam(label.charAt(0).toLocaleUpperCase('pl') + label.slice(1), row.value)
      }
    }
  }

  let norms = visible('norms') ? listItems(product, 'norms').map(clean).filter(Boolean) : []
  if (norms.length === 0 && visible('attributes')) {
    norms = attributeNorms(product)
      .split(',')
      .map(clean)
      .filter(Boolean)
  }

  const images: string[] = []
  const sortedImages = [...(product.images ?? [])].sort(
    (a, b) => Number(b.is_primary) - Number(a.is_primary) || a.sort_order - b.sort_order,
  )
  for (const img of sortedImages) {
    const url = absoluteUrl(img.url, base)
    if (url && !images.includes(url)) images.push(url)
    if (images.length >= MAX_IMAGES) break
  }

  const documents: OfferDocument[] = []
  for (const doc of product.documents ?? []) {
    const url = absoluteUrl(doc.url, base)
    if (!url || documents.some((d) => d.url === url)) continue
    const kindLabel = (doc.kind && DOCUMENT_KINDS[doc.kind]) || 'Dokument'
    documents.push({ title: clean(doc.title) || `${kindLabel}.pdf`, kind: kindLabel, url })
  }

  const prose = visible('description') ? descriptionProse(product.description) : ''
  const paragraphs = prose
    .split(/\n\s*\n/)
    .map((p) => p.trim())
    .filter(Boolean)

  const list = (id: string) => (visible(id) ? listItems(product, id).map(clean).filter(Boolean) : [])

  return {
    name: productDisplayName(product, 160),
    manufacturer,
    code,
    kind,
    images,
    paragraphs,
    params,
    notes: notes.filter(Boolean),
    features: list('features'),
    materials: list('materials'),
    useCases: list('use_cases'),
    norms,
    certificates: list('certificates'),
    documents,
  }
}

/** Cena wpisana przez handlowca: „29,90”, „1 234,5 zł”. null = pole puste; NaN = wpis, którego nie da się odczytać. */
export function parseOfferPrice(input: string): number | null {
  const raw = input.replace(/zł|pln|netto/gi, '').replace(/[\s ]/g, '').replace(',', '.')
  if (raw === '') return null
  if (!/^\d+(\.\d{1,2})?$/.test(raw)) return Number.NaN
  const n = Number(raw)
  return n > 0 ? n : Number.NaN
}

export function formatOfferPrice(value: number): string {
  return `${value.toLocaleString('pl-PL', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} zł`
}

export function renderOfferHtml(id: OfferTemplateId, data: OfferData, price: number | null, date: Date): string {
  const ctx: Ctx = { data, price: price == null ? null : formatOfferPrice(price), date: formatDate(date) }
  if (id === 'bold') return boldTemplate(ctx)
  if (id === 'datasheet') return datasheetTemplate(ctx)
  return classicTemplate(ctx)
}

/** Podgląd w ramce: fragment oferty w minimalnym dokumencie. */
export function offerPreviewDocument(fragment: string): string {
  return `<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>${OFFER_TAG}</title><base target="_blank"></head><body style="margin:0;background:#ffffff">${fragment}</body></html>`
}

/** Wersja tekstowa do schowka obok HTML — dla pól bez formatowania. */
export function renderOfferText(data: OfferData, price: number | null, date: Date): string {
  const lines: string[] = [`${OFFER_TAG.toLocaleUpperCase('pl')} z dnia ${formatDate(date)}`, '', data.name]
  const head = [data.manufacturer, data.code ? `${data.code.label}: ${data.code.value}` : null, data.kind].filter(Boolean)
  if (head.length > 0) lines.push(head.join(' · '))
  if (price != null) lines.push('', `Cena netto: ${formatOfferPrice(price)}`)
  if (data.paragraphs.length > 0) lines.push('', ...data.paragraphs)
  const block = (title: string, items: string[]) => {
    if (items.length > 0) lines.push('', `${title}:`, ...items.map((s) => `• ${s}`))
  }
  block('Parametry techniczne', [...data.params.map((r) => `${r.label}: ${r.value}`), ...data.notes])
  block('Normy', data.norms)
  block('Certyfikaty', data.certificates)
  block('Cechy', data.features)
  block('Materiały', data.materials)
  block('Zastosowanie', data.useCases)
  block('Dokumenty', data.documents.map((d) => `${d.kind}: ${d.url}`))
  return lines.join('\n')
}

// ---------------------------------------------------------------------------------------------------------
// Szablony. Tabele z atrybutami width/bgcolor obok stylów w linii — Outlook (Word) ignoruje większość CSS.

type Ctx = { data: OfferData; price: string | null; date: string }

const FONT = "'Segoe UI',Arial,Helvetica,sans-serif"

function formatDate(date: Date): string {
  return date.toLocaleDateString('pl-PL', { day: '2-digit', month: '2-digit', year: 'numeric' })
}

function esc(value: string): string {
  return value
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;')
}

function text(value: string): string {
  return esc(value).replace(/\n/g, '<br>')
}

function img(url: string, width: number, alt: string, extra = ''): string {
  return `<img src="${esc(url)}" width="${width}" alt="${esc(alt)}" style="display:block;width:${width}px;max-width:100%;height:auto;border:0;outline:none;text-decoration:none;margin:0 auto;${extra}">`
}

function table(content: string, style = '', attrs = ''): string {
  return `<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" ${attrs} style="border-collapse:collapse;${style}">${content}</table>`
}

/** Zdjęcia po `perRow` w wierszu, szerokość komórki stała — puste komórki domykają ostatni wiersz. */
function gallery(urls: string[], perRow: number, cell: number, gap: number, frame: string, alt: string): string {
  if (urls.length === 0) return ''
  let rows = ''
  for (let i = 0; i < urls.length; i += perRow) {
    let cells = ''
    for (let j = 0; j < perRow; j++) {
      const url = urls[i + j]
      if (j > 0) cells += `<td width="${gap}" style="width:${gap}px;font-size:0;line-height:0">&nbsp;</td>`
      cells += url
        ? `<td width="${cell}" valign="middle" align="center" style="width:${cell}px;${frame}">${img(url, cell - 12, alt)}</td>`
        : `<td width="${cell}" style="width:${cell}px"></td>`
    }
    rows += `<tr>${cells}</tr><tr><td colspan="${perRow * 2 - 1}" height="${gap}" style="height:${gap}px;font-size:0;line-height:0">&nbsp;</td></tr>`
  }
  return `<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse">${rows}</table>`
}

function paragraphs(data: OfferData, style: string): string {
  return data.paragraphs.map((p) => `<p style="margin:0 0 12px;${style}">${text(p)}</p>`).join('')
}

function headLine(data: OfferData, sep: string): string {
  return [
    data.manufacturer ? `<b>${esc(data.manufacturer)}</b>` : '',
    data.code ? `${esc(data.code.label)}: <b>${esc(data.code.value)}</b>` : '',
    data.kind ? esc(data.kind) : '',
  ]
    .filter(Boolean)
    .join(sep)
}

function footer(ctx: Ctx, color: string): string {
  const notes = [
    ctx.price ? 'Cena netto — do ceny należy doliczyć podatek VAT.' : '',
    ctx.data.images.length > 0 ? 'Zdjęcia mają charakter poglądowy.' : '',
  ].filter(Boolean)
  return notes.length > 0 ? `<p style="margin:0;font-size:11px;line-height:1.6;color:${color}">${notes.join(' ')}</p>` : ''
}

// --- 1. Klasyczna -------------------------------------------------------------------------------------------

function classicTemplate({ data, price, date }: Ctx): string {
  const navy = '#0f2742'
  const muted = '#5b6b82'
  const line = '#e2e8f0'
  const section = (title: string, body: string) =>
    body === ''
      ? ''
      : `<tr><td style="padding:8px 36px 22px">
<p style="margin:0 0 10px;padding-bottom:8px;border-bottom:2px solid ${navy};font-size:13px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:${navy}">${esc(title)}</p>
${body}</td></tr>`

  const [main, ...rest] = data.images
  const photo = main
    ? table(`<tr><td align="center" bgcolor="#ffffff" style="padding:14px;border:1px solid ${line};background:#ffffff">${img(main, 262, data.name)}</td></tr>`) +
      (rest.length > 0
        ? `<div style="height:10px;line-height:10px;font-size:0">&nbsp;</div>${gallery(rest.slice(0, 4), 4, 66, 6, `border:1px solid ${line};padding:4px;background:#ffffff`, data.name)}`
        : '')
    : ''

  const keyRows = [
    ...(data.manufacturer ? [{ label: 'Producent', value: data.manufacturer }] : []),
    ...(data.code ? [data.code] : []),
    ...(data.kind ? [{ label: 'Rodzaj', value: data.kind }] : []),
    ...(data.norms.length > 0 ? [{ label: 'Normy', value: data.norms.slice(0, 4).join(', ') }] : []),
  ]
  const facts = table(
    keyRows
      .map(
        (r) =>
          `<tr><td valign="top" style="padding:8px 12px 8px 0;border-bottom:1px solid ${line};font-size:12px;color:${muted};width:38%">${esc(r.label)}</td><td valign="top" style="padding:8px 0;border-bottom:1px solid ${line};font-size:13px;font-weight:600;color:${navy}">${esc(r.value)}</td></tr>`,
      )
      .join(''),
  )
  const priceBox = price
    ? `<div style="height:18px;line-height:18px;font-size:0">&nbsp;</div>${table(
        `<tr><td bgcolor="${navy}" style="padding:16px 20px;background:${navy}">
<p style="margin:0;font-size:11px;letter-spacing:1.5px;text-transform:uppercase;color:#b8c4d6">Cena netto</p>
<p style="margin:4px 0 0;font-size:30px;line-height:1.2;font-weight:700;color:#ffffff">${esc(price)}</p></td></tr>`,
      )}`
    : ''

  const top = main
    ? `<tr><td style="padding:4px 36px 26px">${table(
        `<tr><td width="292" valign="top" style="width:292px">${photo}</td><td width="28" style="width:28px">&nbsp;</td><td valign="top">${facts}${priceBox}</td></tr>`,
      )}</td></tr>`
    : `<tr><td style="padding:4px 36px 26px">${facts}${priceBox}</td></tr>`

  const params = [...data.params.map((r) => [r.label, r.value] as const), ...data.notes.map((n) => ['', n] as const)]
  const paramsTable =
    params.length === 0
      ? ''
      : table(
          params
            .map(
              ([label, value], i) =>
                `<tr><td ${i % 2 === 0 ? 'bgcolor="#f5f7fa" ' : ''}valign="top" style="padding:9px 12px;font-size:12px;color:${muted};width:38%;${i % 2 === 0 ? 'background:#f5f7fa;' : ''}">${esc(label)}</td><td ${i % 2 === 0 ? 'bgcolor="#f5f7fa" ' : ''}valign="top" style="padding:9px 12px;font-size:13px;color:#1e293b;font-weight:600;${i % 2 === 0 ? 'background:#f5f7fa;' : ''}">${text(value)}</td></tr>`,
            )
            .join(''),
        )

  const checks = (items: string[]) =>
    items.length === 0
      ? ''
      : table(
          items
            .map(
              (s) =>
                `<tr><td width="22" valign="top" style="width:22px;padding:4px 0;font-size:14px;line-height:1.5;color:${navy};font-weight:700">&#10003;</td><td valign="top" style="padding:4px 0;font-size:13px;line-height:1.5;color:#1e293b">${text(s)}</td></tr>`,
            )
            .join(''),
        )
  const badges = (items: string[]) =>
    items
      .map(
        (s) =>
          `<span style="display:inline-block;margin:0 6px 6px 0;padding:5px 11px;border:1px solid #c7d2e0;background:#f3f6fa;font-size:12px;font-weight:700;color:${navy}">${esc(s)}</span>`,
      )
      .join('')
  const docs =
    data.documents.length === 0
      ? ''
      : table(
          data.documents
            .map(
              (d) =>
                `<tr><td style="padding:7px 0;border-bottom:1px solid ${line};font-size:13px"><span style="display:inline-block;margin-right:10px;padding:2px 6px;background:${navy};color:#ffffff;font-size:10px;font-weight:700;letter-spacing:.5px">PDF</span><a href="${esc(d.url)}" style="color:${navy};font-weight:600;text-decoration:underline">${esc(d.title)}</a> <span style="color:${muted};font-size:12px">· ${esc(d.kind)}</span></td></tr>`,
            )
            .join(''),
        )

  return `<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#eef1f5" style="border-collapse:collapse;background:#eef1f5;font-family:${FONT}">
<tr><td align="center" style="padding:28px 12px">
<table role="presentation" width="680" cellpadding="0" cellspacing="0" border="0" bgcolor="#ffffff" style="border-collapse:collapse;width:680px;max-width:100%;background:#ffffff;border:1px solid ${line};border-top:5px solid ${navy}">
<tr><td style="padding:30px 36px 18px">
${table(`<tr><td valign="middle"><span style="display:inline-block;padding:6px 14px;background:${navy};color:#ffffff;font-size:11px;font-weight:700;letter-spacing:2.5px;text-transform:uppercase">${OFFER_TAG}</span></td><td align="right" valign="middle" style="font-size:12px;color:${muted}">z dnia <b style="color:${navy}">${esc(date)}</b></td></tr>`)}
<h1 style="margin:16px 0 6px;font-size:25px;line-height:1.3;font-weight:700;color:${navy}">${esc(data.name)}</h1>
<p style="margin:0;font-size:13px;line-height:1.6;color:${muted}">${headLine(data, ' &nbsp;·&nbsp; ')}</p>
</td></tr>
${top}
${section('Opis produktu', paragraphs(data, 'font-size:14px;line-height:1.65;color:#1e293b'))}
${section('Parametry techniczne', paramsTable)}
${section('Cechy produktu', checks(data.features))}
${section('Normy i certyfikaty', badges([...data.norms, ...data.certificates]))}
${section('Materiały', checks(data.materials))}
${section('Zastosowanie', checks(data.useCases))}
${section('Dokumenty do pobrania', docs)}
<tr><td style="padding:14px 36px 26px;border-top:1px solid ${line}">${footer({ data, price, date }, muted)}</td></tr>
</table>
</td></tr>
</table>`
}

// --- 2. Wyrazista -------------------------------------------------------------------------------------------

function boldTemplate({ data, price, date }: Ctx): string {
  const ink = '#15181d'
  const yellow = '#f6b600'
  const muted = '#6b7280'
  const line = '#e5e7eb'
  const section = (title: string, body: string) =>
    body === ''
      ? ''
      : `<tr><td style="padding:6px 36px 24px">
${table(`<tr><td width="4" bgcolor="${yellow}" style="width:4px;background:${yellow}">&nbsp;</td><td style="padding-left:12px;font-size:17px;font-weight:800;color:${ink}">${esc(title)}</td></tr>`, 'margin-bottom:12px')}
${body}</td></tr>`

  const [main, ...rest] = data.images
  const hero = `<tr><td bgcolor="${ink}" style="padding:30px 36px 28px;background:${ink}">
${table(`<tr><td valign="middle" style="padding-bottom:12px;border-bottom:1px solid #2d333b"><span style="font-size:13px;font-weight:800;letter-spacing:3px;text-transform:uppercase;color:${yellow}">${OFFER_TAG}</span></td><td align="right" valign="middle" style="padding-bottom:12px;border-bottom:1px solid #2d333b;font-size:12px;color:#9ca3af">z dnia <b style="color:#ffffff">${esc(date)}</b></td></tr>`)}
<h1 style="margin:18px 0 12px;font-size:28px;line-height:1.25;font-weight:800;color:#ffffff">${esc(data.name)}</h1>
<p style="margin:0;font-size:13px;color:#d1d5db">${data.manufacturer ? `<span style="display:inline-block;margin-right:10px;padding:3px 9px;background:${yellow};color:${ink};font-size:11px;font-weight:800;letter-spacing:1px;text-transform:uppercase">${esc(data.manufacturer)}</span>` : ''}${data.kind ? `<span style="margin-right:12px;font-size:12px;letter-spacing:1px;text-transform:uppercase;color:#9ca3af">${esc(data.kind)}</span>` : ''}${data.code ? `${esc(data.code.label)}: <b style="color:#ffffff">${esc(data.code.value)}</b>` : ''}</p>
</td></tr>`

  const photo = main
    ? `<tr><td align="center" bgcolor="#ffffff" style="padding:30px 36px 26px;background:#ffffff">${img(main, 420, data.name)}</td></tr>`
    : ''
  const priceBand = price
    ? `<tr><td bgcolor="${yellow}" style="padding:18px 36px;background:${yellow}">${table(
        `<tr><td valign="middle" style="font-size:13px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:${ink}">Cena netto</td><td align="right" valign="middle" style="font-size:32px;line-height:1.1;font-weight:800;color:${ink}">${esc(price)}</td></tr>`,
      )}</td></tr><tr><td style="height:26px;line-height:26px;font-size:0">&nbsp;</td></tr>`
    : `<tr><td style="height:6px;line-height:6px;font-size:0;border-top:4px solid ${yellow}">&nbsp;</td></tr>`

  // Parametry w dwóch kolumnach kafelków.
  const tiles = [...data.params, ...data.notes.map((n) => ({ label: '', value: n }))]
  let paramsGrid = ''
  if (tiles.length > 0) {
    let rows = ''
    for (let i = 0; i < tiles.length; i += 2) {
      const cell = (r: Row | undefined) =>
        r
          ? `<td width="296" valign="top" bgcolor="#f6f7f9" style="width:296px;padding:12px 14px;background:#f6f7f9;border-left:3px solid ${ink}">${r.label ? `<p style="margin:0 0 3px;font-size:11px;letter-spacing:.8px;text-transform:uppercase;color:${muted}">${esc(r.label)}</p>` : ''}<p style="margin:0;font-size:14px;font-weight:700;line-height:1.4;color:${ink}">${text(r.value)}</p></td>`
          : '<td width="296" style="width:296px"></td>'
      rows += `<tr>${cell(tiles[i])}<td width="16" style="width:16px">&nbsp;</td>${cell(tiles[i + 1])}</tr><tr><td colspan="3" style="height:12px;line-height:12px;font-size:0">&nbsp;</td></tr>`
    }
    paramsGrid = table(rows)
  }

  const bullets = (items: string[]) => {
    if (items.length === 0) return ''
    const half = Math.ceil(items.length / 2)
    const col = (list: string[]) =>
      table(
        list
          .map(
            (s) =>
              `<tr><td width="18" valign="top" style="width:18px;padding:6px 0"><div style="width:9px;height:9px;margin-top:5px;background:${yellow};font-size:0;line-height:0">&nbsp;</div></td><td valign="top" style="padding:4px 0;font-size:13px;line-height:1.55;color:#1f2937">${text(s)}</td></tr>`,
          )
          .join(''),
      )
    const rows = items.length > 3
      ? `<tr><td width="296" valign="top" style="width:296px">${col(items.slice(0, half))}</td><td width="16" style="width:16px">&nbsp;</td><td width="296" valign="top" style="width:296px">${col(items.slice(half))}</td></tr>`
      : `<tr><td>${col(items)}</td></tr>`
    return table(rows)
  }
  const pills = (items: string[]) =>
    items
      .map(
        (s) =>
          `<span style="display:inline-block;margin:0 6px 8px 0;padding:6px 12px;background:${ink};color:#ffffff;font-size:12px;font-weight:700;letter-spacing:.3px">${esc(s)}</span>`,
      )
      .join('')
  const docs = data.documents
    .map(
      (d) =>
        `<a href="${esc(d.url)}" style="display:inline-block;margin:0 8px 8px 0;padding:9px 14px;border:2px solid ${ink};color:${ink};font-size:13px;font-weight:700;text-decoration:none">&#8595; ${esc(d.kind)} <span style="font-weight:400;color:${muted}">· ${esc(d.title)}</span></a>`,
    )
    .join('')

  return `<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#e9ebee" style="border-collapse:collapse;background:#e9ebee;font-family:${FONT}">
<tr><td align="center" style="padding:28px 12px">
<table role="presentation" width="680" cellpadding="0" cellspacing="0" border="0" bgcolor="#ffffff" style="border-collapse:collapse;width:680px;max-width:100%;background:#ffffff">
${hero}
${photo}
${priceBand}
${section('Opis', paragraphs(data, 'font-size:14px;line-height:1.7;color:#1f2937'))}
${section('Parametry techniczne', paramsGrid)}
${section('Najważniejsze cechy', bullets(data.features))}
${section('Normy i certyfikaty', pills([...data.norms, ...data.certificates]))}
${section('Materiały', bullets(data.materials))}
${section('Zastosowanie', bullets(data.useCases))}
${section('Galeria', rest.length > 0 ? gallery(rest, 3, 196, 12, `border:1px solid ${line};padding:6px;background:#ffffff`, data.name) : '')}
${section('Dokumenty', docs)}
<tr><td bgcolor="${ink}" style="padding:18px 36px;background:${ink}">${footer({ data, price, date }, '#9ca3af') || '&nbsp;'}</td></tr>
</table>
</td></tr>
</table>`
}

// --- 3. Karta techniczna ------------------------------------------------------------------------------------

function datasheetTemplate({ data, price, date }: Ctx): string {
  const teal = '#0f766e'
  const ink = '#111827'
  const muted = '#64748b'
  const line = '#cbd5e1'
  let no = 0
  const section = (title: string, body: string) => {
    if (body === '') return ''
    no += 1
    return `<tr><td style="padding:0 28px 22px">
${table(`<tr><td bgcolor="#f1f5f9" style="padding:8px 12px;background:#f1f5f9;border:1px solid ${line};border-bottom:2px solid ${teal};font-size:12px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:${ink}"><span style="color:${teal}">${String(no).padStart(2, '0')}</span> &nbsp;${esc(title)}</td></tr>`)}
${body}</td></tr>`
  }
  const cellBorder = `border:1px solid ${line};border-top:0`
  /** Wiersze z wartością już w HTML (po esc/text) — linki do dokumentów składamy osobno. */
  const rowsHtml = (rows: (readonly [string, string])[]) =>
    rows.length === 0
      ? ''
      : table(
          rows
            .map(([label, html]) =>
              label
                ? `<tr><td width="36%" valign="top" style="width:36%;padding:7px 12px;${cellBorder};font-size:12px;color:${muted}">${esc(label)}</td><td valign="top" style="padding:7px 12px;${cellBorder};border-left:0;font-size:12px;font-weight:600;color:${ink}">${html}</td></tr>`
                : `<tr><td colspan="2" valign="top" style="padding:7px 12px;${cellBorder};font-size:12px;color:${ink}">${html}</td></tr>`,
            )
            .join(''),
        )
  const rowsTable = (rows: (readonly [string, string])[]) => rowsHtml(rows.map(([label, value]) => [label, text(value)] as const))
  const listTable = (items: string[]) => rowsTable(items.map((s) => ['', s] as const))

  const [main, ...rest] = data.images
  const ident = rowsTable([
    ['Nazwa', data.name],
    ...(data.manufacturer ? [['Producent', data.manufacturer] as const] : []),
    ...(data.code ? [[data.code.label, data.code.value] as const] : []),
    ...(data.kind ? [['Rodzaj', data.kind] as const] : []),
    ['Data oferty', date],
  ])
  const photo = main
    ? `${table(`<tr><td align="center" style="padding:10px;border:1px solid ${line}">${img(main, 196, data.name)}</td></tr>`)}${
        rest.length > 0
          ? `<div style="height:8px;line-height:8px;font-size:0">&nbsp;</div>${gallery(rest.slice(0, 3), 3, 64, 6, `border:1px solid ${line};padding:3px`, data.name)}`
          : ''
      }`
    : ''
  const identBlock = main
    ? table(
        `<tr><td width="218" valign="top" style="width:218px">${photo}</td><td width="20" style="width:20px">&nbsp;</td><td valign="top" style="border-top:1px solid ${line}">${ident}</td></tr>`,
      )
    : `<div style="border-top:1px solid ${line}">${ident}</div>`

  const normsRows = [
    ...data.norms.map((n) => ['Norma', n] as const),
    ...data.certificates.map((c) => ['Certyfikat', c] as const),
  ]
  const docsHtml =
    data.documents.length === 0
      ? ''
      : `<div style="border-top:1px solid ${line}">${rowsHtml(
          data.documents.map(
            (d) => [d.kind, `<a href="${esc(d.url)}" style="color:${teal};font-weight:700;text-decoration:underline">${esc(d.title)}</a>`] as const,
          ),
        )}</div>`
  const withTop = (html: string) => (html === '' ? '' : `<div style="border-top:1px solid ${line}">${html}</div>`)

  return `<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#f8fafc" style="border-collapse:collapse;background:#f8fafc;font-family:${FONT}">
<tr><td align="center" style="padding:24px 12px">
<table role="presentation" width="680" cellpadding="0" cellspacing="0" border="0" bgcolor="#ffffff" style="border-collapse:collapse;width:680px;max-width:100%;background:#ffffff;border:1px solid ${line}">
<tr><td style="padding:24px 28px 20px">${table(
    `<tr><td valign="top">
<p style="margin:0"><span style="display:inline-block;padding:5px 12px;background:${teal};color:#ffffff;font-size:11px;font-weight:700;letter-spacing:2.5px;text-transform:uppercase">${OFFER_TAG}</span><span style="display:inline-block;margin-left:10px;font-size:11px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:${teal}">Karta produktu · ${esc(date)}</span></p>
<h1 style="margin:12px 0 4px;font-size:22px;line-height:1.3;font-weight:700;color:${ink}">${esc(data.name)}</h1>
<p style="margin:0;font-size:12px;line-height:1.6;color:${muted}">${headLine(data, ' &nbsp;|&nbsp; ')}</p>
</td>${
      price
        ? `<td width="20" style="width:20px">&nbsp;</td><td width="170" valign="top" align="right" style="width:170px">${table(
            `<tr><td align="right" style="padding:10px 14px;border:2px solid ${teal}"><p style="margin:0;font-size:10px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:${teal}">Cena netto</p><p style="margin:4px 0 0;font-size:24px;line-height:1.15;font-weight:800;color:${ink};white-space:nowrap">${esc(price)}</p></td></tr>`,
          )}</td>`
        : ''
    }</tr>`,
    `border-bottom:3px solid ${teal};padding-bottom:16px`,
  )}</td></tr>
<tr><td style="padding:0 28px 22px">${identBlock}</td></tr>
${section('Opis', data.paragraphs.length > 0 ? `<div style="padding-top:12px">${paragraphs(data, 'font-size:13px;line-height:1.65;color:#1f2937')}</div>` : '')}
${section('Parametry techniczne', withTop(rowsTable([...data.params.map((r) => [r.label, r.value] as const), ...data.notes.map((n) => ['', n] as const)])))}
${section('Normy i certyfikaty', withTop(rowsTable(normsRows)))}
${section('Cechy', withTop(listTable(data.features)))}
${section('Materiały', withTop(listTable(data.materials)))}
${section('Zastosowanie', withTop(listTable(data.useCases)))}
${section('Dokumenty', docsHtml)}
<tr><td style="padding:12px 28px 22px;border-top:1px solid ${line}">${footer({ data, price, date }, muted)}</td></tr>
</table>
</td></tr>
</table>`
}
