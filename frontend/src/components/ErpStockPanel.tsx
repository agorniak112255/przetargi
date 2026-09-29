import type { ErpCardStock, ErpLinkedItem, ErpPurchase } from '../lib/api'
import { formatDate, formatDateTime } from '../lib/priceChange'

const qtyFormat = new Intl.NumberFormat('pl-PL', { maximumFractionDigits: 2 })

function qty(value: number): string {
  return qtyFormat.format(value)
}

/** Cena jednostkowa z PZ: poniżej 1 zł z 4 miejscami (zatyczki po 0,3153 zł), wyżej z 2. */
function unitPrice(value: number | null): string {
  if (value == null) return '—'
  return value.toLocaleString('pl-PL', {
    minimumFractionDigits: 2,
    maximumFractionDigits: Math.abs(value) < 1 ? 4 : 2,
  })
}

function unitLabel(unit: string | null): string {
  return unit ? ` ${unit}` : ''
}

function purchaseText(p: ErpPurchase): string {
  const foreign = p.currency && p.currency.toUpperCase() !== 'PLN' && p.document_price != null
  return [
    `${unitPrice(p.unit_price_pln)} zł${p.unit ? `/${p.unit}` : ''}`,
    foreign ? `(${unitPrice(p.document_price)} ${p.currency})` : '',
    p.date ? `· ${formatDate(p.date)}` : '',
    p.supplier ? `· ${p.supplier}` : '',
    `· ${qty(p.quantity)}${unitLabel(p.unit)}`,
  ]
    .filter(Boolean)
    .join(' ')
}

function linkLabel(item: ErpLinkedItem): string {
  if (item.status === 'confirmed') return 'potwierdzone ręcznie'
  const where = item.method === 'name1' ? 'Nazwa1' : item.method === 'xl_code' ? 'kod XL' : 'nazwa'
  return `automatycznie — kod „${item.matched_value ?? ''}” (${where})`
}

/**
 * Stan i ostatnie zakupy z Comarch ERP XL dla towarów powiązanych z kartą. Stan HANDEL = magazyny „Magazyn HANDEL…”;
 * pozostałe magazyny (kontraktowe, przecen) widać w rozbiciu. Dane XL stoją obok cen karty — ich nie zmieniają.
 */
export function ErpStockPanel({ erp }: { erp: ErpCardStock | null | undefined }) {
  if (!erp) return null
  const { items, suggested } = erp
  if (items.length === 0) {
    return (
      <div className="mt-3 rounded-xl bg-white p-4 text-xs text-slate-600 shadow-sm">
        <b className="text-slate-800">ERP XL:</b> kod karty pasuje do {suggested} towar{suggested === 1 ? 'u' : 'ów'} XL, ale
        powiązanie jest niepewne (brak zgodnego dostawcy, marki albo nazwy) — stanu nie pokazujemy do czasu potwierdzenia.
      </div>
    )
  }
  const last = erp.last_purchase
  const warehouses = (erp.warehouses ?? []).filter((w) => w.quantity !== 0)

  return (
    <div className="mt-3 rounded-xl bg-white p-4 shadow-sm">
      <div className="mb-2 flex flex-wrap items-baseline justify-between gap-2">
        <h2 className="text-sm font-semibold">Stan i zakupy w ERP XL</h2>
        {erp.synced_at && (
          <span className={`text-[11px] ${erp.stale ? 'font-semibold text-amber-700' : 'text-slate-500'}`}>
            {erp.stale ? 'Stan może być nieaktualny — ' : ''}odczyt z XL: {formatDateTime(erp.synced_at)}
          </span>
        )}
      </div>

      <div className="flex flex-wrap gap-x-6 gap-y-1 text-sm">
        {erp.stock_trade != null ? (
          <span title="Suma magazynów „Magazyn HANDEL…” w XL">
            Stan HANDEL:{' '}
            <b className={erp.stock_trade > 0 ? 'text-emerald-700' : 'text-slate-500'}>
              {qty(erp.stock_trade)}
              {unitLabel(erp.unit)}
            </b>
          </span>
        ) : (
          <span className="text-slate-600">Stan — różne jednostki towarów XL, każdy osobno niżej</span>
        )}
        {last && (
          <span title={`PZ nr ${last.document_id}, towar XL ${last.xl_code}`}>
            Ostatni zakup: <b>{purchaseText(last)}</b>
          </span>
        )}
      </div>

      {warehouses.length > 0 && (
        <div className="mt-2 flex flex-wrap gap-1 text-[11px]">
          {warehouses.map((w) => (
            <span
              key={w.code}
              title={w.name}
              className={`rounded border px-1.5 py-0.5 tabular-nums ${
                /^magazyn handel/i.test(w.name) ? 'border-emerald-200 bg-emerald-50 text-emerald-900' : 'border-slate-200 bg-slate-50 text-slate-600'
              }`}
            >
              {w.code}: {qty(w.quantity)}
            </span>
          ))}
        </div>
      )}

      <div className="mt-3 overflow-x-auto">
        <table className="w-full text-left text-xs">
          <thead>
            <tr className="border-b bg-slate-50">
              <th className="p-2">Towar XL</th>
              <th className="p-2 text-right">HANDEL</th>
              <th className="p-2 text-right">Wszystkie mag.</th>
              <th className="p-2">Ostatnie zakupy (PZ)</th>
              <th className="p-2">Ostatnia sprzedaż</th>
              <th className="p-2">Powiązanie</th>
            </tr>
          </thead>
          <tbody>
            {items.map((item) => (
              <tr key={item.xl_gid} className="border-b align-top">
                <td className="p-2">
                  <div className="font-mono">{item.code}</div>
                  <div className="text-slate-600">{item.name}</div>
                  {item.name1 && <div className="text-slate-400">{item.name1}</div>}
                  {item.archived && <div className="text-amber-700">archiwalny w XL</div>}
                </td>
                <td className="whitespace-nowrap p-2 text-right tabular-nums">
                  {qty(item.stock_trade)}
                  {unitLabel(item.unit)}
                </td>
                <td className="whitespace-nowrap p-2 text-right tabular-nums text-slate-500">
                  {qty(item.stock_total)}
                  {unitLabel(item.unit)}
                </td>
                <td className="p-2">
                  {item.purchases.length === 0 ? (
                    <span className="text-slate-400">brak PZ</span>
                  ) : (
                    item.purchases.map((p) => (
                      <div key={`${p.document_id}-${p.date}`} className="whitespace-nowrap" title={`PZ nr ${p.document_id}`}>
                        {purchaseText(p)}
                      </div>
                    ))
                  )}
                </td>
                <td className="whitespace-nowrap p-2">{item.last_sale_at ? formatDate(item.last_sale_at) : '—'}</td>
                <td className="p-2 text-slate-600">{linkLabel(item)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {suggested > 0 && (
        <p className="mt-2 text-[11px] text-slate-500">
          Kod karty pasuje jeszcze do {suggested} towar{suggested === 1 ? 'u' : 'ów'} XL z niepewnym powiązaniem — nie są
          liczone w stanie.
        </p>
      )}
    </div>
  )
}
