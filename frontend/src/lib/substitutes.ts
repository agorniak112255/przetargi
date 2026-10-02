import type { SubstituteCardLite, SubstituteEvidenceValue, SubstituteParamSummary, Substitute } from './api'
import { SOURCE_LABEL } from '../components/RequirementCheckList'

export const SUBSTITUTE_TYPES = [
  { value: 'preferowany', label: 'Preferowany' },
  { value: 'tanszy', label: 'Tańszy' },
  { value: 'premium', label: 'Premium' },
  { value: 'awaryjny', label: 'Awaryjny' },
] as const

export const SUBSTITUTE_STATUSES = [
  { value: 'oczekuje', label: 'Do decyzji' },
  { value: 'zatwierdzony', label: 'Zatwierdzony' },
  { value: 'odrzucony', label: 'Odrzucony' },
] as const

export const SUBSTITUTE_SOURCES = [
  { value: 'automat', label: 'Automat' },
  { value: 'reczny', label: 'Ręczny' },
] as const

export function substituteTypeLabel(v: string): string {
  return SUBSTITUTE_TYPES.find((t) => t.value === v)?.label ?? v
}

export function substituteStatusLabel(v: string): string {
  return SUBSTITUTE_STATUSES.find((s) => s.value === v)?.label ?? v
}

/** Polska odmiana liczebnika: 1 → one, 2–4 (bez 12–14) → few, reszta → many. */
export function plural(n: number, one: string, few: string, many: string): string {
  if (n === 1) return one
  const d = n % 10
  const dd = n % 100
  return d >= 2 && d <= 4 && (dd < 12 || dd > 14) ? few : many
}

/** „4 z 5 parametrów równych, 1 wyższy” — z liczników evidence; bez dowodów pusty napis. */
export function paramSummaryText(s: SubstituteParamSummary | null | undefined): string {
  if (!s || s.params <= 0) return ''
  const meets = Math.max(0, s.params - s.equal - s.higher)
  const parts = [
    `${s.equal} z ${s.params} ${plural(s.params, 'parametru', 'parametrów', 'parametrów')} ${s.equal === 1 ? 'równy' : 'równych'}`,
  ]
  if (s.higher > 0) parts.push(`${s.higher} ${plural(s.higher, 'wyższy', 'wyższe', 'wyższych')}`)
  if (meets > 0) parts.push(`${meets} ${plural(meets, 'spełnia', 'spełniają', 'spełnia')} (inny zapis)`)
  return parts.join(', ')
}

/** Różnica ceny zamiennika do karty głównej: „−12,8%”, „+5,0%”. */
export function diffPercentText(v: number): string {
  const abs = Math.abs(v).toLocaleString('pl-PL', { minimumFractionDigits: 1, maximumFractionDigits: 1 })
  if (v < 0) return `−${abs}%`
  if (v > 0) return `+${abs}%`
  return '0%'
}

/** Etykieta pola karty, z którego pochodzi wartość; 'derived' — automat wywnioskował ją z tekstu karty. */
export function evidenceSourceLabel(v: SubstituteEvidenceValue | null | undefined): string {
  if (!v?.source) return ''
  if (v.source === 'derived') return 'odczyt automatu'
  return SOURCE_LABEL[v.source] ?? v.source
}

/** Skrót karty zamiennika z odpowiedzi byMain; starsza odpowiedź (bez `card`) — z relacji substitute_product. */
export function substituteCard(row: Substitute): SubstituteCardLite | null {
  if (row.card) return row.card
  const p = row.substitute_product
  if (!p) return null
  return {
    id: p.id,
    sku: p.sku,
    name: p.name,
    manufacturer: p.manufacturer ?? null,
    family: null,
    family_label: null,
    thumb_url: null,
    price_pln: null,
    currency: null,
    has_description: false,
  }
}
