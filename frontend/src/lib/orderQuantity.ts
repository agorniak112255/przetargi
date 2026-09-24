import type { OrderQuantity } from './api'

type Condition = Pick<OrderQuantity, 'min' | 'step'> & { varies?: boolean }

/** Ilość bez zer po przecinku ze źródła („10.0000” → „10”, 2.5 → „2,5”). */
export function formatOrderQty(qty: number): string {
  return String(Number(qty.toFixed(4))).replace('.', ',')
}

/** Jednostka ze źródła; brak (np. Delta Plus nie podaje jednostki ceny) — bez jednostki, nie zgadujemy „szt.”. */
function unitSuffix(unit: string | null | undefined): string {
  const u = (unit ?? '').trim()
  return u !== '' ? ` ${u}` : ''
}

/** Czy warunek ogranicza zamówienie (minimum > 1 albo krok > 1). Warunek zależny od rozmiaru — osobno (varies). */
export function orderRestricts(oq: Condition | null | undefined): boolean {
  if (!oq) return false
  return (oq.min != null && oq.min > 1) || (oq.step != null && oq.step > 1)
}

/** Krótki napis: „po 10 szt.”, „min. 5 szt.”, „min. 15 szt., po 10 szt.”, „zależy od rozmiaru”. */
export function orderQtyLabel(oq: Condition & Pick<OrderQuantity, 'unit'>): string {
  if (oq.varies) return 'zależnie od rozmiaru'
  const unit = unitSuffix(oq.unit)
  const { min, step } = oq
  if (step != null && step > 1 && min != null && Math.abs(min - step) < 0.0001) {
    return `po ${formatOrderQty(step)}${unit}`
  }
  const parts: string[] = []
  if (min != null && min > 1) parts.push(`min. ${formatOrderQty(min)}${unit}`)
  if (step != null && step > 1) parts.push(`po ${formatOrderQty(step)}${unit}`)
  return parts.join(', ')
}

/**
 * Najmniejsza ilość do zamówienia u dostawcy, która pokrywa potrzebę. Jak pole ilości w sklepie (HTML min/step):
 * dozwolone są min, min+step, min+2·step… — przy min = step to pełne paczki. null = potrzeba już jest dozwoloną
 * ilością, warunek nie ogranicza albo ilość nieznana.
 */
export function orderableQty(qty: number | null | undefined, oq: Condition | null | undefined): number | null {
  if (qty == null || !Number.isFinite(qty) || qty <= 0 || !oq || oq.varies || !orderRestricts(oq)) return null
  const min = oq.min != null && oq.min > 0 ? oq.min : 0
  const step = oq.step != null && oq.step > 0 ? oq.step : null
  let need: number
  if (qty <= min) need = min
  else if (step == null) need = qty
  else need = min + Math.ceil((qty - min) / step - 1e-9) * step
  return Math.abs(need - qty) < 1e-9 ? null : Number(need.toFixed(4))
}

type PriceCondition = Pick<OrderQuantity, 'price_note' | 'price_carton_qty' | 'unit'>

/** Czy cena obowiązującego źródła ma warunek (np. Delta Plus: cena tylko za pełny karton). */
export function hasPriceCondition(pc: PriceCondition | null | undefined): boolean {
  return (pc?.price_note ?? '').trim() !== ''
}

/** Krótki napis: „cena przy pełnym kartonie (120)”; bez jednej ilości w kartonie — „cena warunkowa”. */
export function priceConditionLabel(pc: PriceCondition): string {
  return pc.price_carton_qty != null
    ? `cena przy pełnym kartonie (${formatOrderQty(pc.price_carton_qty)}${unitSuffix(pc.unit)})`
    : 'cena warunkowa'
}

/**
 * Ilość, która nie jest pełnym kartonem (ani wielokrotnością kartonu) — cena źródła jej nie dotyczy. false, gdy ilość
 * albo karton nieznane.
 */
export function partialCarton(qty: number | null | undefined, pc: PriceCondition | null | undefined): boolean {
  const carton = pc?.price_carton_qty
  if (!hasPriceCondition(pc) || carton == null || carton <= 0 || qty == null || !Number.isFinite(qty) || qty <= 0) {
    return false
  }
  const cartons = qty / carton
  return Math.abs(cartons - Math.round(cartons)) > 1e-9 || Math.round(cartons) === 0
}

/** Podpowiedź warunku ceny: przypis źródła dosłownie i ilość w kartonie. */
export function priceConditionTitle(oq: OrderQuantity): string {
  const lines = [`${oq.source_label}: „${(oq.price_note ?? '').trim()}”`]
  lines.push(
    oq.price_carton_qty != null
      ? `Ilość w kartonie: ${formatOrderQty(oq.price_carton_qty)}${unitSuffix(oq.unit)}`
      : 'Ilość w kartonie różna dla rozmiarów albo nieczytelna — szczegóły na karcie dostawcy.',
  )
  lines.push('Przy niepełnym kartonie ta cena nie obowiązuje — cenę potwierdź u dostawcy.')
  return lines.join('\n')
}

/** Pełny opis do podpowiedzi: źródło, minimum i krok. */
export function orderQtyTitle(oq: OrderQuantity): string {
  if (oq.varies) {
    return `${oq.source_label}: rozmiary tej karty mają różne warunki zamawiania — szczegóły na karcie dostawcy (Informacje handlowe).`
  }
  const unit = unitSuffix(oq.unit)
  const lines = [`${oq.source_label}: zamawianie ${orderQtyLabel(oq)}`]
  if (oq.min != null) lines.push(`Najmniejsza ilość: ${formatOrderQty(oq.min)}${unit}`)
  if (oq.step != null && oq.step > 1) lines.push(`Krok ilości: ${formatOrderQty(oq.step)}${unit}`)
  if (oq.step == null) lines.push('Kroku sklep nie podaje — ponad minimum dowolna ilość.')
  lines.push('Warunek ze sklepu dostawcy.')
  return lines.join('\n')
}
