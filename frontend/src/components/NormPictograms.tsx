import type { ReactNode } from 'react'
import type { Product } from '../lib/api'

type IconKind =
  | 'mechanical'
  | 'chemical'
  | 'biological'
  | 'heat'
  | 'cold'
  | 'static'
  | 'welding'
  | 'heat-clothing'
  | 'hi-vis'
  | 'rain'
  | 'ce'

type PictogramItem = {
  kind: IconKind
  /** Oznaczenie normy bez roku wydania albo „Kat. N” — nad piktogramem. */
  label: string
  /** Kod skuteczności dosłownie ze źródła (obcięte tylko spacje i nawiasy wokół); null = brak w źródle. */
  code: string | null
}

/**
 * Oznaczenie normy na początku tekstu: [PN-]EN [ISO] numer[-część][:rok][+A1:rok].
 * Rok przyjmujemy tylko jako 19xx/20xx bez dalszych znaków — „EN 388:2121X” to kod, nie rok.
 * Czterocyfrowa liczba z zakresu lat po dwukropku jest brana za rok: lepiej nie pokazać kodu,
 * niż pokazać rok jako kod.
 */
const DESIGNATION_RE =
  /^\s*(PN\s*-\s*)?EN(\s+ISO)?\s*(\d{3,5})(?!\d)(?:-(\d{1,2})(?![\dA-Za-z]))?(?:\s*:\s*(?:19|20)\d{2}(?![\dA-Za-z]))?(?:\s*[+/]\s*A\d{1,2}(?:\s*:\s*(?:19|20)\d{2}(?![\dA-Za-z]))?)?/i

/** Słowa dopuszczalne w kodzie obok symboli: „Typ A”, „Klasa 2”. */
const CODE_WORD_RE = /^(typ|type|klasa|class|kl\.?|level|poziom)$/i
/** Symbol kodu: wielkie litery i cyfry („4121X”, „AJKOPT”, „3/1”) albo zaczynający się cyfrą („4121x”). */
const CODE_TOKEN_RE = /^(?:[0-9A-Z][0-9A-Z/:.+-]*|\d[0-9a-z/:.+-]*)$/

/** Rodzina normy → piktogram. Normy ogólne (EN ISO 13688, EN ISO 21420, EN 420) celowo bez piktogramu. */
function familyIcon(num: string, part: string | undefined): IconKind | null {
  switch (num) {
    case '388':
      return 'mechanical'
    case '374':
      if (part === undefined || part === '1') return 'chemical'
      return part === '5' ? 'biological' : null
    case '407':
      return 'heat'
    case '511':
      return 'cold'
    case '1149':
      return 'static'
    case '12477':
      return 'welding'
    case '11612':
      return 'heat-clothing'
    case '20471':
      return 'hi-vis'
    case '343':
      return 'rain'
    default:
      return null
  }
}

type ParsedDesignation = { kind: IconKind; label: string; rest: string }

function parseDesignation(text: string): ParsedDesignation | null {
  const m = DESIGNATION_RE.exec(text)
  if (!m) return null
  const [whole, pn, iso, num, part] = m
  const kind = familyIcon(num, part)
  if (!kind) return null
  const label = `${pn ? 'PN-' : ''}EN${iso ? ' ISO' : ''} ${num}${part ? `-${part}` : ''}`
  return { kind, label, rest: text.slice(whole.length) }
}

/**
 * Kod skuteczności z reszty tekstu po oznaczeniu normy. Zdejmuje tylko interpunkcję wokół
 * („: X1XXXX”, „(3131X)”); gdy reszta nie wygląda na kod (opis słowny, kolejna norma) — null.
 */
function extractCode(rest: string): string | null {
  let s = rest.replace(/^[\s:;,./\-–—]+/, '').replace(/[\s;,.]+$/, '')
  if (s.startsWith('(') && s.endsWith(')') && !/[()]/.test(s.slice(1, -1))) {
    s = s.slice(1, -1).trim()
  }
  if (s === '' || s.length > 40 || /[()]/.test(s)) return null
  if (/\b(EN|ISO|PN)\b/i.test(s)) return null
  const tokens = s.split(/[\s,]+/).filter(Boolean)
  return tokens.every((t) => CODE_WORD_RE.test(t) || CODE_TOKEN_RE.test(t)) ? s : null
}

/** „Kategoria 3”, „Category III”, „Kat. 2” — kategoria ŚOI z karty producenta. */
const CATEGORY_RE = /^\s*(?:kategoria|category|kat\.?)\s*(III|II|I|[1-3])\s*$/i

/** Dzieli surową kolumnę norm po przecinkach, ale nie w nawiasach („EN 374-1 (Typ A, AJKOPT)”). */
function splitNormsColumn(raw: string): string[] {
  const out: string[] = []
  let depth = 0
  let cur = ''
  for (const ch of raw) {
    if (ch === '(') depth++
    else if (ch === ')') depth = Math.max(0, depth - 1)
    if ((ch === ',' || ch === ';') && depth === 0) {
      out.push(cur)
      cur = ''
    } else {
      cur += ch
    }
  }
  out.push(cur)
  return out.map((s) => s.trim()).filter(Boolean)
}

function dedupe(items: PictogramItem[]): PictogramItem[] {
  const seen = new Set<string>()
  return items.filter((it) => {
    const key = `${it.label}|${it.code ?? ''}`
    if (seen.has(key)) return false
    seen.add(key)
    return true
  })
}

/** Pozycje z wierszy karty producenta — kod to dosłowna wartość wiersza. */
function itemsFromManufacturerRows(rows: { label: string; value?: string }[]): PictogramItem[] {
  const items: PictogramItem[] = []
  for (const row of rows) {
    const value = row.value?.trim() || null
    const cat = CATEGORY_RE.exec(row.label ?? '')
    if (cat) {
      items.push({ kind: 'ce', label: `Kat. ${cat[1]}`, code: value })
      continue
    }
    const d = parseDesignation(row.label ?? '')
    if (!d) continue
    items.push({ kind: d.kind, label: d.label, code: value ?? extractCode(d.rest) })
  }
  return dedupe(items)
}

/** Pozycje z listy norm karty (opis ze źródeł) albo surowej kolumny norm. */
function itemsFromNormStrings(norms: string[]): PictogramItem[] {
  const items: PictogramItem[] = []
  for (const n of norms) {
    const d = parseDesignation(n)
    if (!d) continue
    items.push({ kind: d.kind, label: d.label, code: extractCode(d.rest) })
  }
  return dedupe(items)
}

function collectItems(product: Product): { items: PictogramItem[]; sourceTitle: string } {
  const mn = product.manufacturer_norms
  const rows = (mn?.rows ?? []).filter((r) => r && typeof r.label === 'string' && r.label.trim() !== '')
  if (rows.length > 0) {
    const url = mn?.source?.url?.trim()
    return {
      items: itemsFromManufacturerRows(rows),
      sourceTitle: url ? `Z karty producenta: ${url}` : 'Z karty producenta',
    }
  }
  const fromPayload = (product.enrichment_payload?.norms ?? []).filter((n) => typeof n === 'string' && n.trim() !== '')
  const norms = fromPayload.length > 0 ? fromPayload : splitNormsColumn(product.norms ?? '')
  return { items: itemsFromNormStrings(norms), sourceTitle: 'Z norm karty (opis ze źródeł)' }
}

/** Obrys tarczy wspólny dla piktogramów norm. */
const SHIELD = 'M20 2 L37 7 V21 C37 32 29.5 39 20 42 C10.5 39 3 32 3 21 V7 Z'

/** Symbol wewnątrz tarczy — rysunki własne, uproszczone. */
function symbol(kind: IconKind): ReactNode {
  switch (kind) {
    case 'mechanical':
      // dłoń rękawicy z nacięciami
      return (
        <>
          <path d="M15 21 V14 M18.5 20 V12 M22 20 V12.5 M25.5 21 V14.5" />
          <path d="M15 21 V27 C15 31 17.5 34 20.5 34 C24 34 25.5 31 25.5 27 V21 M15 24 L12 20" />
          <path d="M9.5 31 L16 26.5 M24 20.5 L30.5 16" />
        </>
      )
    case 'chemical':
      // kolba Erlenmeyera
      return (
        <>
          <path d="M16.5 12 H23.5 M18 12 V19.5 L12 31 C11.2 32.8 12.2 34 14 34 H26 C27.8 34 28.8 32.8 28 31 L22 19.5 V12" />
          <path d="M14.2 27 H25.8" />
        </>
      )
    case 'biological':
      // uproszczony znak zagrożenia biologicznego
      return (
        <>
          <circle cx="20" cy="17.5" r="5" />
          <circle cx="15" cy="26" r="5" />
          <circle cx="25" cy="26" r="5" />
          <circle cx="20" cy="23" r="1.8" fill="currentColor" />
        </>
      )
    case 'heat':
      return <path d="M20 34 C15 34 12.5 30.5 12.5 27 C12.5 22.5 16.5 20.5 16.5 15 C19.5 17 20.5 19.5 20.5 22 C21.8 20 22.5 17.5 22 13 C26 16 27.5 21.5 27.5 27 C27.5 30.5 25 34 20 34 Z" />
    case 'cold':
      // płatek śniegu
      return (
        <>
          <path d="M20 12 V34 M10.5 17.5 L29.5 28.5 M10.5 28.5 L29.5 17.5" />
          <path d="M17 14 L20 17 L23 14 M17 32 L20 29 L23 32" />
        </>
      )
    case 'static':
      // błyskawica — ładunek elektrostatyczny
      return <path d="M22.5 11 L13.5 25 H20 L17.5 35 L27 20.5 H20.5 Z" fill="currentColor" />
    case 'welding':
      // palnik i iskry
      return (
        <>
          <path d="M11 33 L21 23" strokeWidth={3.5} />
          <path d="M21 23 L25.5 18.5" />
          <path d="M28 11 V13.5 M33 16 H30.5 M31.5 12.5 L29.8 14.2 M31.5 19.5 L29.8 17.8" />
        </>
      )
    case 'heat-clothing':
      // kurtka z płomieniem
      return (
        <>
          <path d="M16 12 L11 15.5 V34 H29 V15.5 L24 12 C23 14.5 17 14.5 16 12 Z" />
          <path
            d="M20 31 C17.8 31 16.8 29.4 16.8 27.8 C16.8 25.8 18.6 24.9 18.6 22.5 C20 23.4 20.6 24.6 20.6 25.8 C21.3 24.9 21.6 23.8 21.4 22.2 C23 23.6 23.4 25.6 23.4 27.4 C23.4 29.4 22.2 31 20 31 Z"
            fill="currentColor"
          />
        </>
      )
    case 'hi-vis':
      // kamizelka z pasami odblaskowymi
      return (
        <>
          <path d="M15.5 11.5 L11 15 V34 H29 V15 L24.5 11.5 L22 18 H18 Z" />
          <path d="M11 24 H29 M11 29 H29" strokeWidth={2.5} />
        </>
      )
    case 'rain':
      // chmura z deszczem
      return (
        <>
          <path d="M13.5 23 C10 23 10 17.5 13.5 17.5 C14 13.5 19.5 12 22 15 C24 13 28.5 14 28 18 C31.5 18 31.5 23 28 23 Z" />
          <path d="M15 26.5 L14 29.5 M20 26.5 L19 29.5 M25 26.5 L24 29.5 M17.5 31.5 L16.5 34.5 M22.5 31.5 L21.5 34.5" />
        </>
      )
    case 'ce':
      return null
  }
}

function NormIcon({ kind }: { kind: IconKind }) {
  const common = {
    viewBox: '0 0 40 44',
    className: 'h-10 w-9 text-slate-800',
    fill: 'none',
    stroke: 'currentColor',
    strokeLinecap: 'round' as const,
    strokeLinejoin: 'round' as const,
    'aria-hidden': true,
  }
  if (kind === 'ce') {
    // znak CE bez tarczy (jak na kartach producentów)
    return (
      <svg {...common} strokeWidth={2.6}>
        <path d="M17.9 18.1 A7 7 0 1 0 17.9 27.9" />
        <path d="M33.9 18.1 A7 7 0 1 0 33.9 27.9 M22 23 H30" />
      </svg>
    )
  }
  return (
    <svg {...common} strokeWidth={1.8}>
      <path d={SHIELD} />
      {symbol(kind)}
    </svg>
  )
}

/**
 * Piktogramy norm karty (tarcze jak na kartach rękawic): oznaczenie normy nad ikoną, kod skuteczności pod nią.
 * Źródło: wiersze z karty producenta (manufacturer_norms.rows), a gdy ich brak — lista norm karty.
 * Kod pokazujemy tylko dosłownie ze źródła; gdy nie da się go pewnie wydzielić, piktogram jest bez kodu.
 * Normy nierozpoznane nie dostają piktogramu — zostają widoczne w tekście norm karty.
 */
export function NormPictograms({ product }: { product: Product }) {
  const { items, sourceTitle } = collectItems(product)
  if (items.length === 0) return null

  return (
    <div className="flex flex-wrap items-start gap-3" title={sourceTitle}>
      {items.map((it) => (
        <div
          key={`${it.label}|${it.code ?? ''}`}
          className="flex max-w-[6rem] flex-col items-center text-center"
          title={`${it.label}${it.code ? ` — ${it.code}` : ''}\n${sourceTitle}`}
        >
          <span className="text-xs font-medium leading-tight text-slate-700">{it.label}</span>
          <NormIcon kind={it.kind} />
          {it.code && (
            <span className="max-w-[6rem] break-words font-mono text-xs leading-tight text-slate-600">{it.code}</span>
          )}
        </div>
      ))}
    </div>
  )
}
