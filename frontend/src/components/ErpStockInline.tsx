import { useEffect, useState } from 'react'
import type { ErpCardStock, ErpWarehouseStock } from '../lib/api'
import {
  erpForeignPrice,
  erpLinkLabel,
  erpQty,
  erpUnitLabel,
  erpUnitPrice,
  isTradeWarehouse,
} from '../lib/erpStock'
import { formatDate, formatDateTime } from '../lib/priceChange'

/** Ile magazynów widać w pasku; reszta w oknie „zobacz więcej”. */
const INLINE_WAREHOUSES = 3

function nonZero(warehouses: ErpWarehouseStock[] | null | undefined): ErpWarehouseStock[] {
  return (warehouses ?? []).filter((w) => w.quantity !== 0)
}

/**
 * Krótki stan z ERP XL w oknie weryfikacji karty: stan HANDEL, największe magazyny i ostatni zakup. Gdy danych jest
 * więcej (magazyny, kilka towarów XL, kilka zakupów) — „zobacz więcej” otwiera okno z tabelami.
 */
export function ErpStockInline({ erp, title, className = '' }: { erp: ErpCardStock | null | undefined; title: string; className?: string }) {
  const [open, setOpen] = useState(false)
  if (!erp || erp.items.length === 0) return null

  const warehouses = nonZero(erp.warehouses)
  const shown = warehouses.slice(0, INLINE_WAREHOUSES)
  const hidden = warehouses.length - shown.length
  const purchases = erp.items.reduce((n, i) => n + i.purchases.length, 0)
  const more = hidden > 0 || erp.items.length > 1 || purchases > 1 || erp.stock_trade == null

  return (
    <div className={`flex flex-wrap items-center gap-x-2 gap-y-1 text-[11px] ${className}`}>
      <span className="font-medium text-slate-500" title={erp.synced_at ? `Odczyt z XL: ${formatDateTime(erp.synced_at)}` : undefined}>
        Stan XL{erp.stale ? ' (nieaktualny?)' : ''}:
      </span>
      {erp.stock_trade != null ? (
        <span
          className={`rounded-full px-2 py-0.5 font-semibold ${
            erp.stock_trade > 0 ? 'bg-emerald-100 text-emerald-900' : 'bg-slate-100 text-slate-600'
          }`}
          title="Suma magazynów „Magazyn HANDEL…”"
        >
          HANDEL {erpQty(erp.stock_trade)}
          {erpUnitLabel(erp.unit)}
        </span>
      ) : (
        <span className="text-slate-600">różne jednostki</span>
      )}
      {shown.map((w) => (
        <span
          key={w.code}
          title={w.name}
          className={`rounded border px-1.5 py-0.5 tabular-nums ${
            isTradeWarehouse(w.name) ? 'border-emerald-200 bg-emerald-50 text-emerald-900' : 'border-slate-200 bg-slate-50 text-slate-600'
          }`}
        >
          {w.code} {erpQty(w.quantity)}
        </span>
      ))}
      {hidden > 0 && <span className="text-slate-400">+{hidden}</span>}
      {more && (
        <button
          type="button"
          onClick={() => setOpen(true)}
          className="inline-flex items-center gap-1 rounded-md border border-violet-400 bg-violet-100 px-2 py-0.5 font-semibold text-violet-900 shadow-sm hover:border-violet-500 hover:bg-violet-200 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-violet-500"
          title="Wszystkie magazyny, towary XL i ostatnie zakupy"
        >
          Zobacz więcej
          <svg aria-hidden viewBox="0 0 20 20" fill="currentColor" className="h-3 w-3">
            <path
              fillRule="evenodd"
              d="M7.2 14.8a.75.75 0 0 1 0-1.06L10.94 10 7.2 6.26a.75.75 0 1 1 1.06-1.06l4.27 4.27a.75.75 0 0 1 0 1.06L8.26 14.8a.75.75 0 0 1-1.06 0Z"
              clipRule="evenodd"
            />
          </svg>
        </button>
      )}
      {open && <ErpStockDetailsModal erp={erp} title={title} onClose={() => setOpen(false)} />}
    </div>
  )
}

/** Starsza cena zakupu dostaje znacznik — ceny sprzed roku rzadko są aktualne. */
const OLD_PURCHASE_DAYS = 365

/**
 * Ostatni zakup z ERP XL (najnowsza PZ powiązanych towarów) jako wyróżniony kafelek przy zdjęciach karty.
 * Cena za jednostkę podstawową w PLN z wartości PZ; waluta dokumentu obok, gdy zakup był w obcej walucie.
 */
export function ErpLastPurchaseTile({ erp, className = '' }: { erp: ErpCardStock | null | undefined; className?: string }) {
  const last = erp?.last_purchase
  if (!erp || !last || last.unit_price_pln == null) return null
  const foreign = erpForeignPrice(last)
  const days = last.date ? Math.floor((Date.now() - new Date(last.date).getTime()) / 86_400_000) : null
  const old = days != null && days > OLD_PURCHASE_DAYS

  return (
    <div
      className={`rounded-lg border-2 border-orange-300 bg-orange-50 px-3 py-2 text-xs text-slate-700 shadow-sm ${className}`}
      title={`Ostatnia PZ w ERP XL (dokument ${last.document_id}), towar XL ${last.xl_code}. Cena za jednostkę podstawową w PLN z wartości dokumentu.`}
    >
      <div className="text-[10px] font-semibold uppercase tracking-wide text-orange-800">Ostatni zakup · ERP XL</div>
      <div className="mt-0.5 text-lg font-bold leading-tight text-orange-950 tabular-nums">
        {erpUnitPrice(last.unit_price_pln)} zł
        {last.unit && <span className="text-xs font-semibold text-orange-800">/{last.unit}</span>}
      </div>
      {foreign && <div className="text-[11px] text-slate-600">({foreign})</div>}
      <div className="mt-0.5 text-[11px]">
        {last.date ? formatDate(last.date) : 'data nieznana'}
        {last.supplier ? ` · ${last.supplier}` : ''}
      </div>
      <div className="flex flex-wrap items-center gap-1 text-[10px] text-slate-500">
        <span className="font-mono">{last.xl_code}</span>
        {old && <span className="rounded bg-amber-100 px-1 font-semibold text-amber-900">ponad rok temu</span>}
      </div>
    </div>
  )
}

/** Okno ze szczegółami stanu z XL: magazyny, ostatnie zakupy, powiązane towary XL. */
export function ErpStockDetailsModal({ erp, title, onClose }: { erp: ErpCardStock; title: string; onClose: () => void }) {
  useEffect(() => {
    // Escape zamyka tylko to okno — okno weryfikacji pod spodem zostaje (nasłuch w fazie capture na window).
    function onKey(e: KeyboardEvent) {
      if (e.key === 'Escape') {
        e.stopPropagation()
        onClose()
      }
    }
    window.addEventListener('keydown', onKey, true)
    return () => window.removeEventListener('keydown', onKey, true)
  }, [onClose])

  const single = erp.stock_trade != null
  const warehouseGroups = single
    ? [{ key: 'all', label: null as string | null, unit: erp.unit, rows: nonZero(erp.warehouses) }]
    : erp.items.map((i) => ({ key: String(i.xl_gid), label: `${i.code} — ${i.name}`, unit: i.unit, rows: nonZero(i.warehouses) }))
  const purchases = erp.items
    .flatMap((i) => i.purchases.map((p) => ({ ...p, xl_code: i.code })))
    .sort((a, b) => (b.date ?? '').localeCompare(a.date ?? ''))
  const allWarehouses = erp.items.reduce((n, i) => n + i.stock_total, 0)

  return (
    <div
      className="fixed inset-0 z-[80] flex items-center justify-center bg-slate-950/50 p-4"
      role="dialog"
      aria-modal="true"
      aria-labelledby="erp-stock-title"
      onClick={(e) => {
        e.stopPropagation()
        onClose()
      }}
    >
      <div
        className="flex max-h-[85vh] w-full max-w-3xl flex-col overflow-hidden rounded-xl bg-white shadow-2xl"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="flex items-start justify-between gap-3 border-b border-slate-100 px-4 py-3">
          <div className="min-w-0">
            <h3 id="erp-stock-title" className="text-sm font-semibold">
              Stan i zakupy w ERP XL
            </h3>
            <p className="truncate text-xs text-slate-500">{title}</p>
          </div>
          <button type="button" onClick={onClose} className="rounded-md border border-slate-300 px-2.5 py-1 text-xs hover:bg-slate-50">
            Zamknij
          </button>
        </div>

        <div className="min-h-0 overflow-y-auto p-4">
          <div className="grid gap-2 sm:grid-cols-3">
            <div className="rounded-lg border border-emerald-200 bg-emerald-50 p-3">
              <p className="text-[11px] text-emerald-800">Stan HANDEL</p>
              <p className="text-lg font-semibold text-emerald-900 tabular-nums">
                {single ? `${erpQty(erp.stock_trade ?? 0)}${erpUnitLabel(erp.unit)}` : 'różne jednostki'}
              </p>
            </div>
            <div className="rounded-lg border border-slate-200 bg-slate-50 p-3">
              <p className="text-[11px] text-slate-500">Wszystkie magazyny</p>
              <p className="text-lg font-semibold tabular-nums">
                {single ? `${erpQty(allWarehouses)}${erpUnitLabel(erp.unit)}` : '—'}
              </p>
            </div>
            <div className="rounded-lg border border-slate-200 bg-slate-50 p-3">
              <p className="text-[11px] text-slate-500">Ostatni zakup</p>
              <p className="text-lg font-semibold tabular-nums">
                {erp.last_purchase
                  ? `${erpUnitPrice(erp.last_purchase.unit_price_pln)} zł${erp.last_purchase.unit ? `/${erp.last_purchase.unit}` : ''}`
                  : '—'}
              </p>
              {erp.last_purchase?.date && (
                <p className="text-[11px] text-slate-500">
                  {formatDate(erp.last_purchase.date)}
                  {erp.last_purchase.supplier ? ` · ${erp.last_purchase.supplier}` : ''}
                </p>
              )}
            </div>
          </div>

          <h4 className="mb-1 mt-4 text-xs font-semibold text-slate-700">Magazyny</h4>
          {warehouseGroups.map((g) => {
            const sum = g.rows.reduce((n, w) => n + w.quantity, 0)
            return (
              <div key={g.key} className="mb-3">
                {g.label && <p className="mb-1 text-[11px] text-slate-500">{g.label}</p>}
                {g.rows.length === 0 ? (
                  <p className="text-xs text-slate-400">Brak stanu na magazynach.</p>
                ) : (
                  <table className="w-full text-left text-xs">
                    <thead>
                      <tr className="border-b bg-slate-50">
                        <th className="p-2">Kod</th>
                        <th className="p-2">Magazyn</th>
                        <th className="p-2 text-right">Ilość</th>
                      </tr>
                    </thead>
                    <tbody>
                      {g.rows.map((w) => (
                        <tr key={w.code} className={`border-b ${isTradeWarehouse(w.name) ? 'bg-emerald-50/60' : ''}`}>
                          <td className="p-2 font-mono">{w.code}</td>
                          <td className="p-2">
                            {w.name}
                            {isTradeWarehouse(w.name) && (
                              <span className="ml-1 rounded bg-emerald-100 px-1 text-[10px] font-medium text-emerald-800">HANDEL</span>
                            )}
                          </td>
                          <td className="whitespace-nowrap p-2 text-right tabular-nums">
                            {erpQty(w.quantity)}
                            {erpUnitLabel(g.unit)}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                    <tfoot>
                      <tr className="font-semibold">
                        <td className="p-2" colSpan={2}>
                          Razem
                        </td>
                        <td className="whitespace-nowrap p-2 text-right tabular-nums">
                          {erpQty(sum)}
                          {erpUnitLabel(g.unit)}
                        </td>
                      </tr>
                    </tfoot>
                  </table>
                )}
              </div>
            )
          })}

          <h4 className="mb-1 mt-2 text-xs font-semibold text-slate-700">Ostatnie zakupy (PZ)</h4>
          {purchases.length === 0 ? (
            <p className="text-xs text-slate-400">Brak PZ w XL.</p>
          ) : (
            <table className="w-full text-left text-xs">
              <thead>
                <tr className="border-b bg-slate-50">
                  <th className="p-2">Data</th>
                  <th className="p-2">Dostawca</th>
                  <th className="p-2 text-right">Ilość</th>
                  <th className="p-2 text-right">Cena netto</th>
                  <th className="p-2">PZ</th>
                  {erp.items.length > 1 && <th className="p-2">Towar XL</th>}
                </tr>
              </thead>
              <tbody>
                {purchases.map((p) => (
                  <tr key={`${p.xl_code}-${p.document_id}-${p.date}`} className="border-b">
                    <td className="whitespace-nowrap p-2">{p.date ? formatDate(p.date) : '—'}</td>
                    <td className="p-2">{p.supplier ?? '—'}</td>
                    <td className="whitespace-nowrap p-2 text-right tabular-nums">
                      {erpQty(p.quantity)}
                      {erpUnitLabel(p.unit)}
                    </td>
                    <td className="whitespace-nowrap p-2 text-right tabular-nums">
                      {erpUnitPrice(p.unit_price_pln)} zł{p.unit ? `/${p.unit}` : ''}
                      {erpForeignPrice(p) && <div className="text-[11px] text-slate-500">{erpForeignPrice(p)}</div>}
                    </td>
                    <td className="p-2 font-mono text-slate-500">{p.document_id}</td>
                    {erp.items.length > 1 && <td className="p-2 font-mono">{p.xl_code}</td>}
                  </tr>
                ))}
              </tbody>
            </table>
          )}

          <h4 className="mb-1 mt-4 text-xs font-semibold text-slate-700">Towary XL powiązane z kartą</h4>
          <table className="w-full text-left text-xs">
            <thead>
              <tr className="border-b bg-slate-50">
                <th className="p-2">Kod XL</th>
                <th className="p-2">Nazwa</th>
                <th className="p-2 text-right">HANDEL</th>
                <th className="p-2">Ostatnia sprzedaż</th>
                <th className="p-2">Powiązanie</th>
              </tr>
            </thead>
            <tbody>
              {erp.items.map((i) => (
                <tr key={i.xl_gid} className="border-b align-top">
                  <td className="p-2 font-mono">{i.code}</td>
                  <td className="p-2">
                    {i.name}
                    {i.name1 && <div className="text-slate-400">{i.name1}</div>}
                  </td>
                  <td className="whitespace-nowrap p-2 text-right tabular-nums">
                    {erpQty(i.stock_trade)}
                    {erpUnitLabel(i.unit)}
                  </td>
                  <td className="whitespace-nowrap p-2">{i.last_sale_at ? formatDate(i.last_sale_at) : '—'}</td>
                  <td className="p-2 text-slate-600">{erpLinkLabel(i)}</td>
                </tr>
              ))}
            </tbody>
          </table>

          {erp.suggested > 0 && (
            <p className="mt-2 text-[11px] text-slate-500">
              Kod karty pasuje jeszcze do {erp.suggested} towar{erp.suggested === 1 ? 'u' : 'ów'} XL z niepewnym powiązaniem — nie są
              liczone w stanie.
            </p>
          )}
          {erp.synced_at && (
            <p className={`mt-2 text-[11px] ${erp.stale ? 'font-semibold text-amber-700' : 'text-slate-400'}`}>
              {erp.stale ? 'Stan może być nieaktualny — ' : ''}odczyt z XL: {formatDateTime(erp.synced_at)}
            </p>
          )}
        </div>
      </div>
    </div>
  )
}
