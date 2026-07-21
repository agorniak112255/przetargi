import { useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { api } from '../lib/api'

type CompareProduct = {
  product_id: number
  sku: string
  name: string
  manufacturer: string
  siwz_score?: number | null
}

type CompareRow = {
  key: string
  label: string
  values: Array<string | number | null>
  requirement: string | null
  match: 'same' | 'diff' | 'empty'
}

type CompareResponse = {
  products: CompareProduct[]
  requirement: string | null
  rows: CompareRow[]
  summary: {
    winner_product_id: number | null
    tie: boolean
    diffs: number
  }
}

function cellClass(row: CompareRow, productId: number, winnerProductId: number | null): string {
  if (row.key === 'match_siwz' && winnerProductId === productId) {
    return 'bg-emerald-100 font-semibold text-emerald-900'
  }
  if (row.key === 'match_siwz' && winnerProductId !== null) return 'bg-rose-50 text-rose-800'
  if (row.match === 'diff') return 'bg-amber-50 text-amber-950'
  return ''
}

function fmt(v: string | number | null | undefined): string {
  if (v == null || v === '') return '—'
  if (typeof v === 'number') {
    return Number.isInteger(v)
      ? String(v)
      : v.toLocaleString('pl-PL', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
  }
  return String(v)
}

export function ProductCompare() {
  const [params, setParams] = useSearchParams()
  const selectedIds = Array.from(
    new Set(
      (params.getAll('ids[]').length > 0
        ? params.getAll('ids[]')
        : [params.get('a'), params.get('b')].filter((id): id is string => Boolean(id))
      ).filter((id) => /^\d+$/.test(id)),
    ),
  ).slice(0, 5)
  const selectedKey = selectedIds.join(',')
  const requirementParam = params.get('requirement') ?? ''
  const [requirement, setRequirement] = useState(params.get('requirement') ?? '')
  const [data, setData] = useState<CompareResponse | null>(null)
  const [err, setErr] = useState('')
  const [loading, setLoading] = useState(false)

  useEffect(() => {
    if (selectedIds.length < 2) {
      setData(null)
      return
    }
    let cancelled = false
    setLoading(true)
    setErr('')
    const qs = new URLSearchParams()
    selectedIds.forEach((id) => qs.append('ids[]', id))
    if (requirementParam) qs.set('requirement', requirementParam)
    void api<CompareResponse>(`/products/compare?${qs}`)
      .then((res) => {
        if (!cancelled) setData(res)
      })
      .catch((e: unknown) => {
        if (!cancelled) setErr(e instanceof Error ? e.message : 'Błąd porównania')
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })
    return () => {
      cancelled = true
    }
    // selectedKey stabilizuje listę identyfikatorów z URL.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selectedKey, requirementParam])

  function applyRequirement() {
    const next = new URLSearchParams(params)
    if (requirement.trim()) next.set('requirement', requirement.trim())
    else next.delete('requirement')
    setParams(next)
  }

  function removeProduct(productId: number) {
    if (selectedIds.length <= 2) return
    const next = new URLSearchParams(params)
    next.delete('a')
    next.delete('b')
    next.delete('ids[]')
    selectedIds
      .filter((id) => Number(id) !== productId)
      .forEach((id) => next.append('ids[]', id))
    setParams(next)
  }

  if (selectedIds.length < 2) {
    return (
      <div>
        <h1 className="text-xl font-semibold">Porównanie produktów</h1>
        <p className="mt-2 text-sm text-slate-500">
          Wybierz od 2 do 5 produktów w wynikach cross-ref.
        </p>
        <Link to="/products" className="mt-3 inline-block text-sm text-blue-600 hover:underline">
          ← Produkty
        </Link>
      </div>
    )
  }

  return (
    <div>
      <Link to="/products" className="text-xs text-blue-600 hover:underline">
        ← Produkty
      </Link>
      <h1 className="mt-2 text-xl font-semibold">Porównanie produktów</h1>
      {data && (
        <p className="mt-1 text-sm text-slate-500">
          Wybrano {data.products.length}/5 produktów
          {data.summary.diffs > 0 ? ` · różnic: ${data.summary.diffs}` : ''}
        </p>
      )}

      <div className="mt-3 flex flex-wrap items-end gap-2 rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
        <div className="min-w-[16rem] flex-1">
          <label className="mb-1 block text-xs font-medium text-slate-600">Wymaganie SIWZ (opcjonalnie)</label>
          <textarea
            className="w-full rounded border border-slate-300 px-2 py-1.5 text-sm"
            rows={2}
            value={requirement}
            onChange={(e) => setRequirement(e.target.value)}
            placeholder="Wklej treść pozycji SIWZ — pokażemy % dopasowania każdego produktu"
          />
        </div>
        <button
          type="button"
          onClick={applyRequirement}
          className="rounded bg-violet-600 px-3 py-2 text-xs font-semibold text-white hover:bg-violet-700"
        >
          Porównaj z SIWZ
        </button>
      </div>

      {err && <p className="mt-2 rounded bg-red-50 px-3 py-2 text-xs text-red-700">{err}</p>}
      {loading && <p className="mt-2 text-xs text-slate-400">Ładowanie…</p>}

      {data && !loading && (
        <div className="mt-4 overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
          <table
            className="w-full table-fixed text-left text-xs"
            style={{ minWidth: `${190 + data.products.length * 220 + (data.requirement ? 200 : 0)}px` }}
          >
            <thead>
              <tr className="border-b bg-slate-50">
                <th className="sticky left-0 z-20 w-48 bg-slate-100 p-3">Atrybut</th>
                {data.products.map((product, index) => (
                  <th key={product.product_id} className="w-56 border-l border-slate-200 p-3 align-top">
                    <div className="flex items-start justify-between gap-2">
                      <div className="min-w-0">
                        <p className="text-[10px] uppercase text-slate-400">Produkt {index + 1}</p>
                        <Link
                          to={`/products/${product.product_id}`}
                          className="block truncate font-semibold text-blue-700 hover:underline"
                        >
                          {product.sku}
                        </Link>
                        <p className="mt-0.5 truncate text-[10px] font-normal text-slate-600">
                          {product.manufacturer}
                        </p>
                        <p className="mt-1 line-clamp-2 text-[10px] font-normal text-slate-500">
                          {product.name}
                        </p>
                        {product.siwz_score != null && (
                          <span className="mt-1 inline-block rounded bg-violet-100 px-1.5 py-0.5 text-[10px] text-violet-800">
                            SIWZ {product.siwz_score}%
                          </span>
                        )}
                      </div>
                      {data.products.length > 2 && (
                        <button
                          type="button"
                          onClick={() => removeProduct(product.product_id)}
                          className="rounded px-1 text-sm text-slate-400 hover:bg-rose-50 hover:text-rose-700"
                          aria-label={`Usuń ${product.sku} z porównania`}
                        >
                          ×
                        </button>
                      )}
                    </div>
                  </th>
                ))}
                {data.requirement ? <th className="p-2">SIWZ</th> : null}
              </tr>
            </thead>
            <tbody>
              {data.rows.map((row) => (
                <tr key={row.key} className="border-b align-top">
                  <td className="sticky left-0 z-10 bg-white p-3 font-medium text-slate-600">{row.label}</td>
                  {data.products.map((product, index) => (
                    <td
                      key={product.product_id}
                      className={`border-l border-slate-100 p-3 ${cellClass(
                        row,
                        product.product_id,
                        data.summary.winner_product_id,
                      )}`}
                    >
                      {fmt(row.values[index])}
                    </td>
                  ))}
                  {data.requirement ? (
                    <td className="border-l border-slate-200 bg-violet-50/40 p-3 text-slate-600">
                      {fmt(row.requirement)}
                    </td>
                  ) : null}
                </tr>
              ))}
            </tbody>
          </table>
          {data.requirement && (
            <p className="border-t px-3 py-2 text-[11px] text-slate-500">
              SIWZ: {data.requirement}
              {data.summary.winner_product_id !== null &&
                ` · najlepiej pasuje ${
                  data.products.find((product) => product.product_id === data.summary.winner_product_id)
                    ?.sku ?? 'wybrany produkt'
                }`}
              {data.summary.tie && ' · remis'}
            </p>
          )}
        </div>
      )}
    </div>
  )
}
