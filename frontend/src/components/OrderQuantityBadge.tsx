import type { OrderQuantity, ProductSourcePrice } from '../lib/api'
import { formatOrderQty, orderableQty, orderQtyLabel, orderQtyTitle, orderRestricts } from '../lib/orderQuantity'

/**
 * Warunek zamawiania u dostawcy obowiązującej ceny (np. UVEX: „po 10 szt.”). Z podaną ilością (pozycja przetargu,
 * zapytanie) dopisuje, ile trzeba zamówić, gdy potrzeba nie jest pełną paczką — samej ilości nie zmienia.
 * Brak warunku albo warunek bez ograniczenia — nic.
 */
export function OrderQuantityBadge({
  oq,
  qty,
  className = '',
  block = false,
}: {
  oq: OrderQuantity | null | undefined
  /** Potrzebna ilość w jednostce karty; bez niej sam znaczek. */
  qty?: number | null
  className?: string
  /** Osobna linia (komórka listy). */
  block?: boolean
}) {
  if (!oq || (!oq.varies && !orderRestricts(oq))) return null
  const title = orderQtyTitle(oq)
  const order = orderableQty(qty, oq)
  const unit = (oq.unit ?? '').trim() || 'szt.'
  return (
    <span className={`${block ? 'block w-fit' : 'inline-flex'} items-center gap-1 ${className}`} title={title}>
      <span className="inline-block whitespace-nowrap rounded border border-amber-300 bg-amber-50 px-1.5 py-0.5 text-[10px] font-semibold text-amber-800">
        zamawiane {orderQtyLabel(oq)}
      </span>
      {order != null && (
        <span className="whitespace-nowrap text-[10px] font-semibold text-amber-800">
          → zamówisz {formatOrderQty(order)} {unit}
        </span>
      )}
    </span>
  )
}

/** Warunek zamawiania jednego źródła (wiersz „Ceny ze źródeł”) — te same napisy co znaczek karty. */
export function SlotOrderQuantity({ slot, className = '' }: { slot: ProductSourcePrice; className?: string }) {
  return (
    <OrderQuantityBadge
      className={className}
      oq={{
        min: slot.order_min_qty ?? null,
        step: slot.order_step_qty ?? null,
        unit: slot.order_unit ?? null,
        varies: slot.order_varies ?? false,
        source_key: slot.source_key,
        source_label: slot.source_label,
      }}
    />
  )
}
