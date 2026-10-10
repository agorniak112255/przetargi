import type { Product } from '../lib/api'
import { currencyLabel, formatPct, formatPrice } from '../lib/priceChange'
import { supplierSpecialLabels } from '../lib/supplierSpecial'

/**
 * Wyraźna informacja o cenie specjalnej przy cenie karty (okno weryfikacji, karta produktu) — konta B2B albo
 * cennika z pliku (SECURA: kolumna 40%). Ocena dotyczy ceny widocznej na karcie (product.supplier_special z API:
 * cena normalna kategorii = cennik bazowy − rabat standardowy, bez ponownego pobierania cennika). „standard”
 * i brak oceny — nic.
 */
export function SupplierSpecialPanel({ product, className = '' }: { product: Product; className?: string }) {
  const special = product.supplier_special
  if (!special || special.status === 'standard') return null

  const labels = supplierSpecialLabels(special.source)
  const cur = currencyLabel(product.currency)
  const category = special.category ? ` (${special.category})` : ''
  const basis =
    special.base_price != null && special.standard_discount_percent != null ? (
      <>
        {' '}
        = {labels.base.toLowerCase()} {formatPrice(special.base_price)} {cur} − rabat standardowy{' '}
        {formatPct(special.standard_discount_percent, false)}
      </>
    ) : null

  if (special.status === 'special') {
    return (
      <div className={`rounded-lg border-2 border-emerald-600 bg-emerald-50 px-3 py-2 text-emerald-900 ${className}`}>
        <p className="text-sm font-bold uppercase tracking-wide">{labels.badge}</p>
        <p className="mt-1 text-xs">
          Cena normalna{category}: <b>{formatPrice(special.standard_price)} {cur}</b>
          {basis}
        </p>
        <p className="text-xs">
          {labels.purchase}: <b>{formatPrice(product.purchase_price)} {cur}</b> — taniej o{' '}
          <b>{formatPrice(special.saving_net)} {cur}</b> (rabat faktyczny{' '}
          <b>{formatPct(special.actual_discount_percent, false)}</b>)
        </p>
        <p className="mt-1 text-[10px] text-emerald-800">{labels.note}</p>
      </div>
    )
  }

  return (
    <div className={`rounded border border-amber-300 bg-amber-50 px-3 py-1.5 text-xs text-amber-900 ${className}`}>
      {labels.purchase} powyżej ceny normalnej o <b>{formatPrice(Math.abs(special.saving_net))} {cur}</b> — cena
      normalna{category}: {formatPrice(special.standard_price)} {cur}
      {basis}; rabat faktyczny {formatPct(special.actual_discount_percent, false)}.
    </div>
  )
}
