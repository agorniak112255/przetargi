import { useEffect, useState } from 'react'
import { api, type Product } from '../lib/api'
import { ProductVerifyModal } from './ProductVerifyModal'

export type AiMatchPick = {
  id: number
  sku: string
  name: string
  description?: string | null
  manufacturer?: string | null
  purchase_price?: string | number | null
  catalog_price_net?: string | number | null
  currency?: string | null
  score: number
  reason?: string | null
}

type ExternalHint = { url: string; title: string }

type Props = {
  open: boolean
  initialQuery: string
  initialWeb?: boolean
  onClose: () => void
  onSelect: (product: AiMatchPick) => void
  onAddExternal?: (hint: ExternalHint) => void
}

export function ProductAiMatchModal({
  open,
  initialQuery,
  initialWeb = false,
  onClose,
  onSelect,
  onAddExternal,
}: Props) {
  const [query, setQuery] = useState(initialQuery)
  const [busy, setBusy] = useState<'catalog' | 'web' | false>(false)
  const [error, setError] = useState('')
  const [results, setResults] = useState<AiMatchPick[]>([])
  const [externalHints, setExternalHints] = useState<ExternalHint[]>([])
  const [describeId, setDescribeId] = useState<number | null>(null)

  useEffect(() => {
    if (!open) return
    setQuery(initialQuery)
    setError('')
    setResults([])
    setExternalHints([])
    setDescribeId(null)
  }, [open, initialQuery, initialWeb])

  useEffect(() => {
    if (!open) return
    function onKey(e: KeyboardEvent) {
      if (e.key === 'Escape' && !busy && describeId == null) onClose()
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [open, busy, describeId, onClose])

  async function runSearch(web: boolean, text = query) {
    const q = text.trim()
    if (q.length < 3) {
      setError('Wpisz co najmniej 3 znaki zapytania.')
      return
    }
    setBusy(web ? 'web' : 'catalog')
    setError('')
    setResults([])
    setExternalHints([])
    try {
      const res = await api<{
        products: Product[]
        ai_note?: string | null
        external_hint?: ExternalHint | null
        external_hints?: ExternalHint[]
      }>('/products/ai-search', {
        method: 'POST',
        body: JSON.stringify({ query: q, limit: web ? 8 : 5, web }),
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
      const hints =
        res.external_hints ?? (res.external_hint ? [res.external_hint] : [])
      setResults(mapped)
      setExternalHints(hints)
      if (mapped.length === 0 && hints.length === 0) {
        setError(
          res.ai_note ??
            (web
              ? 'Nie znaleziono strony produktu w internecie.'
              : 'AI nie znalazło pasującego produktu w katalogu.'),
        )
      }
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Błąd wyszukiwania AI')
    } finally {
      setBusy(false)
    }
  }

  useEffect(() => {
    if (!open || !initialWeb) return
    void runSearch(true, initialQuery)
    // eslint-disable-next-line react-hooks/exhaustive-deps -- tylko przy otwarciu w trybie internet
  }, [open, initialWeb, initialQuery])

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
              AI — katalog (top 5). AI Internet — strony produktu spoza bazy.
            </p>
          </div>
          <button
            type="button"
            disabled={Boolean(busy)}
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
              disabled={Boolean(busy)}
              onChange={(e) => setQuery(e.target.value)}
              placeholder="np. rękawice nitrylowe ze ściągaczem RNITZ…"
            />
          </label>

          <div className="flex flex-wrap gap-2">
            <button
              type="button"
              disabled={Boolean(busy)}
              onClick={() => void runSearch(false)}
              className="rounded bg-violet-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-violet-700 disabled:opacity-50"
            >
              {busy === 'catalog' ? 'Szukam…' : 'Szukaj AI (top 5)'}
            </button>
            <button
              type="button"
              disabled={Boolean(busy)}
              onClick={() => void runSearch(true)}
              className="rounded bg-red-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-red-700 disabled:opacity-50"
            >
              {busy === 'web' ? 'Szukam w sieci…' : 'AI Internet'}
            </button>
          </div>

          {error && <p className="text-xs text-red-600">{error}</p>}

          {externalHints.length > 0 && (
            <div className="space-y-1.5">
              <p className="text-xs font-medium text-slate-600">Wyniki z internetu:</p>
              {externalHints.map((hint) => (
                <div
                  key={hint.url}
                  className="rounded-md border border-orange-300 bg-orange-50 px-3 py-2"
                >
                  <a
                    href={hint.url}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="block text-left"
                  >
                    <span className="rounded bg-orange-600 px-1 py-px text-[9px] font-bold uppercase tracking-wide text-white">
                      Link zewnętrzny
                    </span>
                    <span className="mt-1 block text-xs font-medium text-orange-950 underline">
                      {hint.title}
                    </span>
                  </a>
                  {onAddExternal && (
                    <button
                      type="button"
                      disabled={Boolean(busy)}
                      onClick={() => onAddExternal(hint)}
                      className="mt-2 rounded bg-orange-700 px-2 py-1 text-[11px] font-medium text-white hover:bg-orange-800 disabled:opacity-50"
                    >
                      Dodaj do oferty
                    </button>
                  )}
                </div>
              ))}
            </div>
          )}

          {results.length > 0 && (
            <div className="space-y-1.5">
              <p className="text-xs font-medium text-slate-600">Wybierz produkt:</p>
              {results.map((r) => (
                <div
                  key={r.id}
                  className="rounded-md border border-violet-200 bg-violet-50 px-3 py-2"
                >
                  <div className="flex items-start justify-between gap-2">
                    <span className="text-sm font-medium text-violet-950">
                      {r.sku} · {r.name}
                    </span>
                    <span className="shrink-0 text-xs text-violet-700">{r.score}%</span>
                  </div>
                  <div className="mt-0.5 flex flex-wrap gap-x-3 gap-y-0.5 text-[11px] text-slate-700">
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
                  </div>
                  {r.reason && <p className="mt-0.5 text-[11px] text-slate-600">{r.reason}</p>}
                  <div className="mt-2 flex flex-wrap gap-1.5">
                    <button
                      type="button"
                      disabled={Boolean(busy)}
                      onClick={() => setDescribeId(r.id)}
                      className="rounded border border-violet-400 bg-white px-2 py-1 text-[11px] font-medium text-violet-800 hover:bg-violet-100 disabled:opacity-50"
                    >
                      Opis
                    </button>
                    <button
                      type="button"
                      disabled={Boolean(busy)}
                      onClick={() => onSelect(r)}
                      className="rounded bg-violet-600 px-2 py-1 text-[11px] font-medium text-white hover:bg-violet-700 disabled:opacity-50"
                    >
                      Wybierz
                    </button>
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
      </div>
      <ProductVerifyModal
        productId={describeId}
        query={query}
        onClose={() => setDescribeId(null)}
      />
    </div>
  )
}
