import type { ErpLinkedItem, ErpPurchase } from './api'
import { formatDate } from './priceChange'

const qtyFormat = new Intl.NumberFormat('pl-PL', { maximumFractionDigits: 2 })

/** Ilość z XL (stany bywają ułamkowe przy kg, m). */
export function erpQty(value: number): string {
  return qtyFormat.format(value)
}

/** Cena jednostkowa z PZ: poniżej 1 zł z 4 miejscami (zatyczki po 0,3153 zł), wyżej z 2. */
export function erpUnitPrice(value: number | null): string {
  if (value == null) return '—'
  return value.toLocaleString('pl-PL', {
    minimumFractionDigits: 2,
    maximumFractionDigits: Math.abs(value) < 1 ? 4 : 2,
  })
}

export function erpUnitLabel(unit: string | null): string {
  return unit ? ` ${unit}` : ''
}

/** Magazyn liczony do stanu handlowego (nazwa w XL „Magazyn HANDEL…”, jak erpxl.trade_warehouse_prefix). */
export function isTradeWarehouse(name: string): boolean {
  return /^magazyn handel/i.test(name.trim())
}

/** Cena PZ w walucie obcej obok ceny w PLN. */
export function erpForeignPrice(p: Pick<ErpPurchase, 'currency' | 'document_price'>): string | null {
  return p.currency && p.currency.toUpperCase() !== 'PLN' && p.document_price != null
    ? `${erpUnitPrice(p.document_price)} ${p.currency}`
    : null
}

export function erpPurchaseText(p: ErpPurchase): string {
  const foreign = erpForeignPrice(p)
  return [
    `${erpUnitPrice(p.unit_price_pln)} zł${p.unit ? `/${p.unit}` : ''}`,
    foreign ? `(${foreign})` : '',
    p.date ? `· ${formatDate(p.date)}` : '',
    p.supplier ? `· ${p.supplier}` : '',
    `· ${erpQty(p.quantity)}${erpUnitLabel(p.unit)}`,
  ]
    .filter(Boolean)
    .join(' ')
}

export function erpLinkLabel(item: ErpLinkedItem): string {
  if (item.status === 'confirmed') return 'potwierdzone ręcznie'
  const where = item.method === 'name1' ? 'Nazwa1' : item.method === 'xl_code' ? 'kod XL' : 'nazwa'
  return `automatycznie — kod „${item.matched_value ?? ''}” (${where})`
}
