import type { OrderQuantity, ProductSourcePrice } from '../lib/api'
import {
  formatOrderQty,
  hasPriceCondition,
  orderableQty,
  orderQtyLabel,
  orderQtyTitle,
  orderRestricts,
  partialCarton,
  priceConditionLabel,
  priceConditionTitle,
  sizePriceLabel,
  sizePriceMax,
  sizePriceTitle,
} from '../lib/orderQuantity'
import { currencyLabel } from '../lib/priceChange'

/**
 * Warunki zakupu u dostawcy obowiązującej ceny: warunek zamawiania (np. UVEX: „po 10 szt.”) i warunek ceny (Delta
 * Plus: „cena przy pełnym kartonie (120)”). Z podaną ilością (pozycja przetargu, zapytanie) dopisuje, ile trzeba
 * zamówić, gdy potrzeba nie jest pełną paczką, i że niepełny karton ma cenę do potwierdzenia — samej ilości nie
 * zmienia. Rozmiary w różnych cenach: „cena od · rozmiary do Y zł” (cena karty to najniższy rozmiar). Brak warunków
 * albo warunek bez ograniczenia — nic.
 */
export function OrderQuantityBadge({
  oq,
  qty,
  className = '',
  block = false,
  show = 'all',
  currency,
  sizePrice = true,
}: {
  oq: OrderQuantity | null | undefined
  /** Potrzebna ilość w jednostce karty; bez niej same znaczki. */
  qty?: number | null
  className?: string
  /** Osobna linia (komórka listy). */
  block?: boolean
  /** Który warunek: oba, sam warunek zamawiania albo sam warunek ceny (wiersz „Ceny ze źródeł”: przy cenie). */
  show?: 'all' | 'order' | 'price'
  /** Waluta ceny rozmiarów (currencyLabel); bez niej — waluta slotu z oq, a bez niej „zł”. */
  currency?: string
  /** false — bez znaczka „cena od · rozmiary do…” (karta pokazuje go pełnym zdaniem przy cenie). */
  sizePrice?: boolean
}) {
  if (!oq) return null
  const showOrder = show !== 'price' && (oq.varies === true || orderRestricts(oq))
  const showPrice = show !== 'order' && hasPriceCondition(oq)
  const sizeMax = show !== 'order' && sizePrice ? sizePriceMax(oq) : null
  const sizeCurrency = currency ?? currencyLabel(oq.size_price_currency)
  if (!showOrder && !showPrice && sizeMax === null) return null
  const order = showOrder ? orderableQty(qty, oq) : null
  const partial = showPrice && partialCarton(qty, oq)
  const unit = (oq.unit ?? '').trim()
  return (
    <span className={`${block ? 'flex w-fit' : 'inline-flex'} flex-wrap items-center gap-1 ${className}`}>
      {showOrder && (
        <span className="inline-flex flex-wrap items-center gap-1" title={orderQtyTitle(oq)}>
          <span className="inline-block whitespace-nowrap rounded border border-amber-300 bg-amber-50 px-1.5 py-0.5 text-[10px] font-semibold text-amber-800">
            zamawiane {orderQtyLabel(oq)}
          </span>
          {order != null && (
            <span className="whitespace-nowrap text-[10px] font-semibold text-amber-800">
              → zamówisz {formatOrderQty(order)}{unit !== '' ? ` ${unit}` : ''}
            </span>
          )}
        </span>
      )}
      {showPrice && (
        <span className="inline-flex flex-wrap items-center gap-1" title={priceConditionTitle(oq)}>
          <span className="inline-block whitespace-nowrap rounded border border-sky-300 bg-sky-50 px-1.5 py-0.5 text-[10px] font-semibold text-sky-800">
            {priceConditionLabel(oq)}
          </span>
          {partial && (
            <span className="whitespace-nowrap text-[10px] font-semibold text-sky-800">→ niepełny karton: cena do potwierdzenia</span>
          )}
        </span>
      )}
      {sizeMax !== null && (
        <span
          className="inline-block whitespace-nowrap rounded border border-sky-300 bg-sky-50 px-1.5 py-0.5 text-[10px] font-semibold text-sky-800"
          title={sizePriceTitle(oq, sizeMax, sizeCurrency)}
        >
          {sizePriceLabel(sizeMax, sizeCurrency)}
        </span>
      )}
    </span>
  )
}

/** Warunki jednego źródła (wiersz „Ceny ze źródeł”) — te same napisy co znaczek karty. */
export function SlotOrderQuantity({
  slot,
  className = '',
  show = 'all',
}: {
  slot: ProductSourcePrice
  className?: string
  show?: 'all' | 'order' | 'price'
}) {
  return (
    <OrderQuantityBadge
      className={className}
      show={show}
      oq={{
        min: slot.order_min_qty ?? null,
        step: slot.order_step_qty ?? null,
        unit: slot.order_unit ?? null,
        varies: slot.order_varies ?? false,
        price_note: slot.price_note ?? null,
        price_carton_qty: slot.price_carton_qty ?? null,
        source_key: slot.source_key,
        source_label: slot.source_label,
      }}
    />
  )
}
