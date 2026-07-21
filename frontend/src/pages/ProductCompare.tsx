import { useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { api } from '../lib/api'

type CompareRow = {
  key: string
  label: string
  a: string | number | null
  b: string | number | null
  requirement: string | null
  match: 'same' | 'diff' | 'empty'
}

type CompareResponse = {
  a: { product_id: number; sku: string; name: string; manufacturer: string; siwz_score?: number | null }
  b: { product_id: number; sku: string; name: string; manufacturer: string; siwz_score?: number | null }
  requirement: string | null
  rows: CompareRow[]
  summary: { a_score: number | null; b_score: number | null; winner: string | null; diffs: number }
}

function cellClass(match: string, side: 'a' | 'b', winner: string | null, key: string): string {
  if (key === 'match_siwz' && winner === side) return 'bg-emerald-50 font-semibold text-emerald-900'
  if (key === 'match_siwz' && winner && winner !== 'tie' && winner !== side) return 'bg-rose-50 text-rose-800'
  if (match === 'diff') return 'bg-amber-50 text-amber-950'
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
  const aId = params.get('a') ?? ''
  const bId = params.get('b') ?? ''
  const [requirement, setRequirement] = useState(params.get('requirement') ?? '')
  const [data, setData] = useState<CompareResponse | null>(null)
  const [err, setErr] = useState('')
  const [loading, setLoading] = useState(false)

  useEffect(() => {
    if (!aId || !bId) {
      setData(null)
      return
    }
    let cancelled = false
    setLoading(true)
    setErr('')
    const qs = new URLSearchParams({ a: aId, b: bId })
    const req = params.get('requirement')
    if (req) qs.set('requirement', req)
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
  }, [aId, bId, params])

  function applyRequirement() {
    const next = new URLSearchParams(params)
    if (requirement.trim()) next.set('requirement', requirement.trim())
    else next.delete('requirement')
    setParams(next)
  }

  if (!aId || !bId) {
    return (
      <div>
        <h1 className="text-xl font-semibold">Porównanie produktów</h1>
        <p className="mt-2 text-sm text-slate-500">
          Wybierz dwa produkty (parametry <code>?a=</code> i <code>?b=</code>) z katalogu lub cross-ref.
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
      <h1 className="mt-2 text-xl font-semibold">Porównanie A vs B</h1>
      {data && (
        <p className="mt-1 text-sm text-slate-500">
          <Link to={`/products/${data.a.product_id}`} className="text-blue-600 hover:underline">
            {data.a.sku}
          </Link>
          {' · '}
          {data.a.manufacturer}
          {'  vs  '}
          <Link to={`/products/${data.b.product_id}`} className="text-blue-600 hover:underline">
            {data.b.sku}
          </Link>
          {' · '}
          {data.b.manufacturer}
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
            placeholder="Wklej treść pozycji SIWZ — pokażemy % dopasowania A i B"
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
        <div className="mt-4 overflow-x-auto rounded-xl bg-white shadow-sm">
          <table className="w-full text-left text-xs">
            <thead>
              <tr className="border-b bg-slate-50">
                <th className="p-2 w-40">Atrybut</th>
                <th className="p-2">
                  A · {data.a.sku}
                  {data.summary.a_score != null ? ` (${data.summary.a_score}%)` : ''}
                </th>
                <th className="p-2">
                  B · {data.b.sku}
                  {data.summary.b_score != null ? ` (${data.summary.b_score}%)` : ''}
                </th>
                {data.requirement ? <th className="p-2">SIWZ</th> : null}
              </tr>
            </thead>
            <tbody>
              {data.rows.map((row) => (
                <tr key={row.key} className="border-b align-top">
                  <td className="p-2 font-medium text-slate-600">{row.label}</td>
                  <td className={`p-2 ${cellClass(row.match, 'a', data.summary.winner, row.key)}`}>
                    {fmt(row.a)}
                  </td>
                  <td className={`p-2 ${cellClass(row.match, 'b', data.summary.winner, row.key)}`}>
                    {fmt(row.b)}
                  </td>
                  {data.requirement ? (
                    <td className="p-2 text-slate-500">{fmt(row.requirement)}</td>
                  ) : null}
                </tr>
              ))}
            </tbody>
          </table>
          {data.requirement && (
            <p className="border-t px-3 py-2 text-[11px] text-slate-500">
              SIWZ: {data.requirement}
              {data.summary.winner === 'a' && ' · lepiej pasuje A'}
              {data.summary.winner === 'b' && ' · lepiej pasuje B'}
              {data.summary.winner === 'tie' && ' · remis'}
            </p>
          )}
        </div>
      )}
    </div>
  )
}
