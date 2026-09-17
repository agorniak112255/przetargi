import { Fragment } from 'react'
import type { ProductShopCardSource } from '../lib/api'
import { formatDateTime } from '../lib/priceChange'

type Props = {
  /** Karty wyrobu u dostawców (Product.shop_fields) — po jednej na konto B2B. */
  sources: ProductShopCardSource[]
}

/**
 * Wiersze skopiowane z kart produktu u dostawców, z proweniencją (konto B2B, adres karty, czas pobrania).
 * To nie jest opis wyrobu — karta bez opisu nadal na niego czeka, więc widok nigdy nie podaje tych danych
 * jako opisu, tylko jako dane ze sklepu.
 */
export function ShopFieldsTables({ sources }: Props) {
  if (sources.length === 0) return null

  return (
    <>
      {sources.map((s) => (
        <div key={s.source_key} className="mb-3 last:mb-0">
          <p className="mb-1 text-xs text-slate-600">
            <span className="font-medium text-slate-800">{s.source_label}</span>
            {s.synced_at && <> · pobrano {formatDateTime(s.synced_at)}</>}
            {s.source_url && (
              <>
                {' · '}
                <a href={s.source_url} target="_blank" rel="noreferrer" className="text-blue-600 hover:underline">
                  Karta u dostawcy
                </a>
              </>
            )}
          </p>
          <div className="overflow-x-auto">
            <table className="w-full text-left text-xs">
              <tbody>
                {s.sections.map((section, si) => (
                  <Fragment key={si}>
                    {section.section !== '' && (
                      <tr className="border-b bg-slate-50 font-medium">
                        <td className="p-2" colSpan={2}>
                          {section.section}
                        </td>
                      </tr>
                    )}
                    {section.rows.map((row, ri) => (
                      <tr key={`${si}-${ri}`} className="border-b">
                        <td className="w-1/3 p-2 text-slate-500">{row.name}</td>
                        <td className="p-2 text-slate-800">{row.value}</td>
                      </tr>
                    ))}
                  </Fragment>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      ))}
    </>
  )
}
