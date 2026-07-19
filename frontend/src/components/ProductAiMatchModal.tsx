import { useEffect, useState } from 'react'
import { api, type Product } from '../lib/api'

export type AiMatchPick = {
  id: number
  sku: string
  name: string
  manufacturer?: string | null
  purchase_price?: string | number | null
  catalog_price_net?: string | number | null
  currency?: string | null
  score: number
  reason?: string | null
}

type Props = {
  open: boolean
  initialQuery: string
  onClose: () => void
  onSelect: (product: AiMatchPick) => void
}

export function ProductAiMatchModal({ open, initialQuery, onClose, onSelect }: Props) {
  const [query, setQuery] = useState(initialQuery)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [results, setResults] = useState<AiMatchPick[]>([])

  useEffect(() => {
    if (!open) return
    setQuery(initialQuery)
    setError('')
    setResults([])
  }, [open, initialQuery])

  useEffect(() => {
    if (!open) return
    function onKey(e: KeyboardEvent) {
      if (e.key === 'Escape' && !busy) onClose()
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [open, busy, onClose])

  async function runSearch() {
    const q = query.trim()
    if (q.length < 3) {
      setError('Wpisz co najmniej 3 znaki zapytania.')
      return
    }
    setBusy(true)
    setError('')
    setResults([])
    try {
      const res = await api<{ products: Product[] }>('/products/ai-search', {
        method: 'POST',
        body: JSON.stringify({ query: q, limit: 5 }),
      })
      const mapped: AiMatchPick[] = (res.products ?? []).slice(0, 5).map((p) => ({
        id: p.id,
        sku: p.sku,
        name: p.name,
        manufacturer: p.manufacturer,
        purchase_price: p.purchase_price,
        catalog_price_net: p.catalog_price_net,
        currency: p.currency ?? 'PLN',
        score: p.ai_match_percent ?? 0,
        reason: p.ai_match_reason ?? null,
      }))
      setResults(mapped)
      if (mapped.length === 0) {
        setError('AI nie znalazło pasujących produktów. Zmień treść zapytania.')
      }
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Błąd wyszukiwania AI')
    } finally {
      setBusy(false)
    }
  }

  if (!open) return null

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
      role="dialog"
      aria-modal="true"
      onClick={() => {
        if (!busy) onClose()
      }}
    >
      <div
        className="flex max-h-[85vh] w-full max-w-xl flex-col overflow-hidden rounded-xl bg-white shadow-lg"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="flex items-start justify-between gap-3 border-b border-slate-100 px-4 py-3">
          <div>
            <p className="text-sm font-semibold text-slate-900">Wyszukiwanie AI</p>
            <p className="text-xs text-slate-500">
              Wpisz wymaganie — model zaproponuje do 5 produktów z bazy.
            </p>
          </div>
          <button
            type="button"
            disabled={busy}
            onClick={onClose}
            className="rounded border border-slate-300 px-2 py-1 text-xs disabled:opacity-50"
          >
            Zamknij
          </button>
        </div>

        <div className="space-y-3 overflow-y-auto px-4 py-3">
          <label className="block">
            <span className="mb-1 block text-xs font-medium text-slate-600">Zapytanie</span>
            <textarea
              className="min-h-[88px] w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm"
              value={query}
              disabled={busy}
              onChange={(e) => setQuery(e.target.value)}
              placeholder="np. rękawice nitrylowe ze ściągaczem RNITZ…"
            />
          </label>

          <button
            type="button"
            disabled={busy}
            onClick={() => void runSearch()}
            className="rounded bg-violet-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-violet-700 disabled:opacity-50"
          >
            {busy ? 'Szukam…' : 'Szukaj AI (top 5)'}
          </button>

          {error && <p className="text-xs text-red-600">{error}</p>}

          {results.length > 0 && (
            <div className="space-y-1.5">
              <p className="text-xs font-medium text-slate-600">Wybierz produkt:</p>
              {results.map((r) => (
                <button
                  key={r.id}
                  type="button"
                  disabled={busy}
                  onClick={() => onSelect(r)}
                  className="flex w-full flex-col rounded-md border border-violet-200 bg-violet-50 px-3 py-2 text-left transition hover:border-violet-400 hover:bg-violet-100"
                >
                  <span className="flex items-center justify-between gap-2">
                    <span className="text-sm font-medium text-violet-950">
                      {r.sku} · {r.name}
                    </span>
                    <span className="shrink-0 text-xs text-violet-700">{r.score}%</span>
                  </span>
                  <span className="mt-0.5 flex flex-wrap gap-x-3 gap-y-0.5 text-[11px] text-slate-700">
                    <span>
                      Producent: <b>{r.manufacturer || '—'}</b>
                    </span>
                    <span>
                      Zakup:{' '}
                      <b>
                        {r.purchase_price != null && r.purchase_price !== ''
                          ? `${r.purchase_price} ${r.currency ?? 'PLN'}`
                          : '—'}
                      </b>
                    </span>
                    {r.catalog_price_net != null && r.catalog_price_net !== '' && (
                      <span>
                        Katalog: <b>{r.catalog_price_net} {r.currency ?? 'PLN'}</b>
                      </span>
                    )}
                  </span>
                  {r.reason && <span className="mt-0.5 text-[11px] text-slate-600">{r.reason}</span>}
                </button>
              ))}
            </div>
          )}
        </div>
      </div>
    </div>
  )
}
