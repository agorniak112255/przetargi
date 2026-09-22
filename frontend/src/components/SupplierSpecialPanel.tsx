import type { Product } from '../lib/api'
import { currencyLabel, formatPct, formatPrice } from '../lib/priceChange'
import { SUPPLIER_SPECIAL_INFERENCE_NOTE } from '../lib/supplierSpecial'

/**
 * Wyraźna informacja o cenie specjalnej B2B przy cenie karty (okno weryfikacji, karta produktu). Ocena dotyczy
 * ceny widocznej na karcie (product.supplier_special z API); cennik bazowy i rabat standardowy bierzemy ze slotu
 * B2B o tej samej cenie zakupu. „standard” i brak oceny — nic.
 */
export function SupplierSpecialPanel({ product, className = '' }: { product: Product; className?: string }) {
  const special = product.supplier_special
  if (!special || special.status === 'standard') return null

  const cur = currencyLabel(product.currency)
  const slot = (product.source_prices ?? []).find(
    (s) =>
      s.base_price_net != null &&
      s.supplier_special != null &&
      s.purchase_price != null &&
      Number(s.purchase_price) === Number(product.purchase_price),
  )
  const standardPct = slot?.standard_discount_percent != null ? Number(slot.standard_discount_percent) : null

  if (special.status === 'special') {
    return (
      <div className={`rounded-lg border-2 border-emerald-600 bg-emerald-50 px-3 py-2 text-emerald-900 ${className}`}>
        <p className="text-sm font-bold uppercase tracking-wide">Cena specjalna B2B</p>
        <p className="mt-0.5 text-xs">
          Taniej o <b>{formatPrice(special.saving_net)} {cur}</b> od ceny standardowej{' '}
          <b>{formatPrice(special.standard_price)} {cur}</b>
          {slot?.base_price_net != null && (
            <>
              {' '}
              (cennik bazowy {formatPrice(slot.base_price_net)} {cur}
              {standardPct != null && <> − rabat standardowy {formatPct(standardPct, false)}</>})
            </>
          )}
          . Rabat faktyczny: <b>{formatPct(special.actual_discount_percent, false)}</b>
          {slot?.base_price_category && <> · arkusz „{slot.base_price_category}”</>}.
        </p>
        <p className="mt-1 text-[10px] text-emerald-800">{SUPPLIER_SPECIAL_INFERENCE_NOTE}</p>
      </div>
    )
  }

  return (
    <div className={`rounded border border-amber-300 bg-amber-50 px-3 py-1.5 text-xs text-amber-900 ${className}`}>
      Cena konta powyżej ceny standardowej o <b>{formatPrice(Math.abs(special.saving_net))} {cur}</b> (standardowa{' '}
      {formatPrice(special.standard_price)} {cur}, rabat faktyczny {formatPct(special.actual_discount_percent, false)}
      {standardPct != null && <> zamiast {formatPct(standardPct, false)}</>}).
    </div>
  )
}
