import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api, type Product } from '../lib/api'

type Page = {
  data: Product[]
  current_page: number
  last_page: number
  per_page: number
  total: number
  from: number | null
  to: number | null
}

function pageNumbers(current: number, last: number): Array<number | '…'> {
  if (last <= 7) {
    return Array.from({ length: last }, (_, i) => i + 1)
  }
  const pages = new Set<number>([1, last, current, current - 1, current + 1])
  if (current <= 3) {
    ;[2, 3, 4].forEach((n) => pages.add(n))
  }
  if (current >= last - 2) {
    ;[last - 1, last - 2, last - 3].forEach((n) => pages.add(n))
  }
  const sorted = [...pages].filter((n) => n >= 1 && n <= last).sort((a, b) => a - b)
  const out: Array<number | '…'> = []
  for (let i = 0; i < sorted.length; i++) {
    if (i > 0 && sorted[i] - sorted[i - 1] > 1) out.push('…')
    out.push(sorted[i])
  }
  return out
}

export function Products() {
  const [q, setQ] = useState('')
  const [debouncedQ, setDebouncedQ] = useState('')
  const [page, setPage] = useState(1)
  const [result, setResult] = useState<Page | null>(null)
  const [loading, setLoading] = useState(false)

  useEffect(() => {
    const t = window.setTimeout(() => {
      setDebouncedQ(q.trim())
      setPage(1)
    }, 300)
    return () => window.clearTimeout(t)
  }, [q])

  useEffect(() => {
    const params = new URLSearchParams({ page: String(page), per_page: '100' })
    if (debouncedQ) params.set('q', debouncedQ)
    const ac = new AbortController()
    setLoading(true)
    void api<Page>(`/products?${params}`)
      .then(setResult)
      .catch(() => {
        /* ignore abort/cancelled */
      })
      .finally(() => setLoading(false))
    return () => ac.abort()
  }, [debouncedQ, page])

  const pages = result ? pageNumbers(result.current_page, result.last_page) : []

  return (
    <div>
      <div className="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold">Produkty</h1>
          {result && (
            <p className="mt-1 text-xs text-slate-500">
              Łącznie <span className="font-medium text-slate-700">{result.total}</span>
              {result.from != null && result.to != null
                ? ` · wyświetlono ${result.from}–${result.to}`
                : ''}
              {' · '}
              {result.per_page}/stronę
              {loading ? ' · ładowanie…' : ''}
            </p>
          )}
        </div>
        <input
          className="w-full max-w-md rounded border border-slate-300 px-3 py-2 text-sm"
          placeholder="Szukaj kod, nazwa, producent…"
          value={q}
          onChange={(e) => setQ(e.target.value)}
        />
      </div>

      <div className="rounded-xl bg-white p-4 shadow-sm">
        <table className="w-full text-left text-xs">
          <thead>
            <tr className="border-b bg-slate-50">
              <th className="p-2">Kod</th>
              <th className="p-2">Nazwa</th>
              <th className="p-2">Producent</th>
              <th className="p-2">Netto</th>
              <th className="p-2">Waluta</th>
              <th className="p-2">Upust</th>
              <th className="p-2">Ilość/opak.</th>
              <th className="p-2">Opakowanie</th>
              <th className="p-2">Stan</th>
              <th className="p-2">Zam.</th>
            </tr>
          </thead>
          <tbody>
            {(result?.data ?? []).map((p) => (
              <tr key={p.id} className="border-b">
                <td className="p-2">
                  <Link className="text-blue-600 hover:underline" to={`/products/${p.id}`}>
                    {p.sku}
                  </Link>
                </td>
                <td className="p-2">{p.name}</td>
                <td className="p-2">{p.manufacturer}</td>
                <td className="p-2">{p.catalog_price_net}</td>
                <td className="p-2">{p.currency ?? 'PLN'}</td>
                <td className="p-2">{p.discount_percent != null ? `${p.discount_percent}%` : '—'}</td>
                <td className="p-2">{p.pack_qty ?? '—'}</td>
                <td className="p-2">{p.packaging ?? '—'}</td>
                <td className="p-2">{p.stock}</td>
                <td className="p-2">{p.substitutes_count ?? 0}</td>
              </tr>
            ))}
            {result && result.data.length === 0 && (
              <tr>
                <td colSpan={9} className="p-4 text-slate-400">
                  Brak produktów dla tego wyszukiwania.
                </td>
              </tr>
            )}
          </tbody>
        </table>

        {result && result.last_page > 1 && (
          <div className="mt-4 flex flex-wrap items-center justify-between gap-3 border-t pt-3">
            <p className="text-xs text-slate-500">
              Strona {result.current_page} z {result.last_page}
            </p>
            <nav className="flex flex-wrap items-center gap-1" aria-label="Paginacja">
              <button
                type="button"
                disabled={result.current_page <= 1}
                onClick={() => setPage((p) => Math.max(1, p - 1))}
                className="rounded border border-slate-300 px-2.5 py-1.5 text-xs disabled:opacity-40"
              >
                ← Poprzednia
              </button>
              {pages.map((n, i) =>
                n === '…' ? (
                  <span key={`e-${i}`} className="px-1 text-xs text-slate-400">
                    …
                  </span>
                ) : (
                  <button
                    key={n}
                    type="button"
                    onClick={() => setPage(n)}
                    className={`min-w-8 rounded px-2.5 py-1.5 text-xs ${
                      n === result.current_page
                        ? 'bg-blue-600 text-white'
                        : 'border border-slate-300 hover:bg-slate-50'
                    }`}
                  >
                    {n}
                  </button>
                ),
              )}
              <button
                type="button"
                disabled={result.current_page >= result.last_page}
                onClick={() => setPage((p) => Math.min(result.last_page, p + 1))}
                className="rounded border border-slate-300 px-2.5 py-1.5 text-xs disabled:opacity-40"
              >
                Następna →
              </button>
            </nav>
          </div>
        )}
      </div>
    </div>
  )
}
