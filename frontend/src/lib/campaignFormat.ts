import { ApiError } from './api'

/** Pomocnicze funkcje stron Kampanie (bez komponentów — osobny plik, żeby odświeżanie Vite działało po komponentach). */

export type CampaignsTab = 'mine' | 'all' | 'groups' | 'suppressed'

const TAB_PARAM: Record<CampaignsTab, string | null> = {
  mine: null,
  all: 'wszystkie',
  groups: 'grupy',
  suppressed: 'wypisani',
}

export function campaignsTabFromParam(value: string | null): CampaignsTab {
  const found = (Object.entries(TAB_PARAM) as [CampaignsTab, string | null][]).find(([, v]) => v === value)
  return found ? found[0] : 'mine'
}

export function campaignsTabHref(tab: CampaignsTab): string {
  const p = TAB_PARAM[tab]
  return p ? `/kampanie?tab=${p}` : '/kampanie'
}

/** Treść błędu API do paska na stronie (ApiError.message = message albo zebrane errors z Laravela). */
export function errorText(ex: unknown, fallback: string): string {
  if (ex instanceof ApiError) return ex.message
  return ex instanceof Error && ex.message ? ex.message : fallback
}

/** 'YYYY-MM-DD' albo ISO → 'DD.MM.RRRR'; null → '—'. */
export function fmtDate(iso: string | null | undefined): string {
  if (!iso) return '—'
  const d = new Date(/^\d{4}-\d{2}-\d{2}$/.test(iso) ? `${iso}T00:00:00` : iso)
  if (Number.isNaN(d.getTime())) return iso
  return d.toLocaleDateString('pl-PL', { day: '2-digit', month: '2-digit', year: 'numeric' })
}

export function fmtDateTime(iso: string | null | undefined): string {
  if (!iso) return '—'
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return iso
  return `${fmtDate(iso)}, ${d.toLocaleTimeString('pl-PL', { hour: '2-digit', minute: '2-digit' })}`
}

export function fmtInt(n: number | null | undefined): string {
  return n == null ? '—' : n.toLocaleString('pl-PL')
}

const qtyFormat = new Intl.NumberFormat('pl-PL', { maximumFractionDigits: 2 })

/** Ilość z jednostką: „420 par”, „2,5 kg”; null → '—'. */
export function fmtQty(n: number | null | undefined, unit?: string | null): string {
  if (n == null) return '—'
  return `${qtyFormat.format(n)}${unit ? ` ${unit}` : ''}`
}

/** Kwota do pola edycji: „89,00” (bez separatora tysięcy); null → ''. */
export function moneyInputValue(n: number | null | undefined): string {
  if (n == null) return ''
  return n.toLocaleString('pl-PL', { minimumFractionDigits: 2, maximumFractionDigits: 2, useGrouping: false })
}

/**
 * Kwota wpisana przez człowieka: „89,00”, „1 234,5”, „89.5”, „89 zł”. Puste → null, niepoprawne → undefined.
 * Kropka i przecinek to separator dziesiętny (kwoty w kampanii nie mają separatora tysięcy z kropką).
 */
export function parseMoney(text: string): number | null | undefined {
  const t = text.replace(/\s| /g, '').replace(/zł$/i, '').replace(',', '.')
  if (t === '') return null
  if (!/^\d+(\.\d{1,2})?$/.test(t)) return undefined
  return Math.round(Number(t) * 100) / 100
}
