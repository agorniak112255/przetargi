import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../lib/api'

type CrossRefProduct = {
  product_id: number
  sku: string
  name: string
  manufacturer: string
  catalog_price_net: number | null
  match_percent: number
  cross_brand: boolean
  attributes?: {
    material?: string | null
    klasa_ochrony?: string | null
    poziomy_en388?: string | null
  } | null
}

type CrossRefResponse = {
  code: string
  seed: CrossRefProduct | null
  matches: CrossRefProduct[]
  total: number
}

function fmtPrice(v: number | null): string {
  if (v == null) return '—'
  return v.toLocaleString('pl-PL', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

export function CrossRefPanel({
  initialCode = '',
  autoRun = false,
}: {
  initialCode?: string
  autoRun?: boolean
}) {
  const [code, setCode] = useState(initialCode)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const [result, setResult] = useState<CrossRefResponse | null>(null)
  const [compareWith, setCompareWith] = useState<number | null>(null)

  async function run(override?: string) {
    const q = (override ?? code).trim()
    if (q.length < 2) {
      setErr('Podaj kod / SKU (min. 2 znaki)')
      return
    }
    setBusy(true)
    setErr('')
    try {
      const res = await api<CrossRefResponse>(
        `/products/cross-ref?code=${encodeURIComponent(q)}&limit=12`,
      )
      setResult(res)
      setCompareWith(null)
    } catch (e: unknown) {
      setResult(null)
      setErr(e instanceof Error ? e.message : 'Błąd cross-ref')
    } finally {
      setBusy(false)
    }
  }

  useEffect(() => {
    if (initialCode && initialCode !== code) {
      setCode(initialCode)
    }
    if (autoRun && initialCode.trim().length >= 2) {
      void run(initialCode)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps -- only on mount / initialCode from URL
  }, [initialCode, autoRun])

  const seedId = result?.seed?.product_id

  return (
    <div className="mb-4 rounded-xl border border-emerald-200 bg-emerald-50/40 p-3 shadow-sm">
      <label className="mb-1 block text-xs font-medium text-slate-700">
        Cross-ref po kodzie / SKU — zamienniki tej samej kategorii BHP (materiał, normy, klasa; nie po nazwie)
      </label>
      <div className="flex flex-wrap items-center gap-2">
        <input
          className="w-full max-w-xs rounded border border-slate-300 bg-white px-3 py-2 text-sm"
          placeholder="np. RNITZ, XG27B, 30-202"
          value={code}
          onChange={(e) => setCode(e.target.value)}
          onKeyDown={(e) => {
            if (e.key === 'Enter') {
              e.preventDefault()
              void run()
            }
          }}
        />
        <button
          type="button"
          disabled={busy}
          onClick={() => void run()}
          className="rounded bg-emerald-700 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-800 disabled:opacity-50"
        >
          {busy ? 'Szukam…' : 'Znajdź zamienniki'}
        </button>
      </div>
      {err && <p className="mt-2 text-xs text-rose-700">{err}</p>}
      {result && (
        <div className="mt-3 space-y-2">
          {result.seed ? (
            <p className="text-xs text-slate-600">
              Baza:{' '}
              <Link to={`/products/${result.seed.product_id}`} className="font-medium text-blue-700 hover:underline">
                {result.seed.sku}
              </Link>
              {' · '}
              {result.seed.manufacturer} · {result.seed.name}
            </p>
          ) : (
            <p className="text-xs text-amber-800">
              Brak dokładnego SKU „{result.code}” — wyniki na podstawie podobieństwa tekstu.
            </p>
          )}
          {result.matches.length === 0 ? (
            <p className="text-xs text-slate-500">Brak ekwiwalentów powyżej progu podobieństwa.</p>
          ) : (
            <div className="overflow-x-auto rounded-lg border border-emerald-100 bg-white">
              <table className="w-full text-left text-xs">
                <thead>
                  <tr className="border-b bg-slate-50">
                    <th className="p-2">%</th>
                    <th className="p-2">SKU</th>
                    <th className="p-2">Producent</th>
                    <th className="p-2">Nazwa</th>
                    <th className="p-2">Cena</th>
                    <th className="p-2">Atrybuty</th>
                    <th className="p-2"></th>
                  </tr>
                </thead>
                <tbody>
                  {result.matches.map((m) => (
                    <tr key={m.product_id} className="border-b align-top">
                      <td className="p-2 font-semibold text-violet-700">{m.match_percent}%</td>
                      <td className="p-2">
                        <Link to={`/products/${m.product_id}`} className="text-blue-600 hover:underline">
                          {m.sku}
                        </Link>
                        {m.cross_brand && (
                          <span className="ml-1 rounded bg-amber-100 px-1 text-[9px] text-amber-800">
                            inna marka
                          </span>
                        )}
                      </td>
                      <td className="p-2">{m.manufacturer}</td>
                      <td className="p-2 max-w-[14rem] truncate" title={m.name}>
                        {m.name}
                      </td>
                      <td className="p-2 whitespace-nowrap">{fmtPrice(m.catalog_price_net)}</td>
                      <td className="p-2 text-[10px] text-slate-500">
                        {[m.attributes?.material, m.attributes?.klasa_ochrony, m.attributes?.poziomy_en388]
                          .filter(Boolean)
                          .join(' · ') || '—'}
                      </td>
                      <td className="p-2">
                        {seedId ? (
                          <Link
                            to={`/products/compare?a=${seedId}&b=${m.product_id}`}
                            className="text-[10px] font-semibold text-emerald-800 hover:underline"
                          >
                            Porównaj
                          </Link>
                        ) : compareWith ? (
                          <Link
                            to={`/products/compare?a=${compareWith}&b=${m.product_id}`}
                            className="text-[10px] font-semibold text-emerald-800 hover:underline"
                          >
                            Porównaj
                          </Link>
                        ) : (
                          <button
                            type="button"
                            className="text-[10px] text-slate-600 hover:underline"
                            onClick={() => setCompareWith(m.product_id)}
                          >
                            Wybierz jako A
                          </button>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      )}
    </div>
  )
}
