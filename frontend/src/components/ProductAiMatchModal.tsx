import { useEffect, useState } from 'react'
import { api, type Product } from '../lib/api'
import {
  AI_SEARCH_MIN_CHARS,
  externalHintsFrom,
  isAiSearchTimeout,
  searchProductsByAiWithTimeout,
} from '../lib/productAiSearch'
import { ProductVerifyModal } from './ProductVerifyModal'

export type AiMatchPick = {
  id: number
  sku: string
  name: string
  description?: string | null
  manufacturer?: string | null
  purchase_price?: string | number | null
  purchase_price_pln?: number | null
  catalog_price_net?: string | number | null
  currency?: string | null
  score: number
  reason?: string | null
  source?: 'ai' | 'catalog'
}

type ExternalHint = { url: string; title: string }

type SearchKind = 'catalog' | 'ai' | 'web'

type Props = {
  open: boolean
  initialQuery: string
  initialWeb?: boolean
  initialMode?: SearchKind
  allowCompanion?: boolean
  hasMainProduct?: boolean
  onClose: () => void
  onSelect: (product: AiMatchPick) => void
  onSelectCompanion?: (product: AiMatchPick) => void
  onSelectPair?: (main: AiMatchPick, companion: AiMatchPick) => void
  onAddExternal?: (hint: ExternalHint) => void
}

function orderSetPicks(picks: AiMatchPick[]): AiMatchPick[] {
  if (picks.length < 2) return picks
  const jacket = picks.find((p) => /bluz|kurtk/i.test(p.name))
  const pants = picks.find((p) => /spodn|ogrodniczk/i.test(p.name))
  if (jacket && pants && jacket.id !== pants.id) {
    return [jacket, pants]
  }
  return picks.slice(0, 2)
}

export function ProductAiMatchModal({
  open,
  initialQuery,
  initialWeb = false,
  initialMode,
  allowCompanion = false,
  hasMainProduct = false,
  onClose,
  onSelect,
  onSelectCompanion,
  onSelectPair,
  onAddExternal,
}: Props) {
  const [query, setQuery] = useState(initialQuery)
  const [busy, setBusy] = useState<SearchKind | false>(false)
  const [error, setError] = useState('')
  const [results, setResults] = useState<AiMatchPick[]>([])
  const [externalHints, setExternalHints] = useState<ExternalHint[]>([])
  const [describeId, setDescribeId] = useState<number | null>(null)
  const [selectedIds, setSelectedIds] = useState<number[]>([])

  useEffect(() => {
    if (!open) return
    setQuery(initialQuery)
    setError('')
    setResults([])
    setExternalHints([])
    setDescribeId(null)
    setSelectedIds([])
  }, [open, initialQuery, initialWeb, initialMode])

  useEffect(() => {
    if (!open) return
    function onKey(e: KeyboardEvent) {
      if (e.key === 'Escape' && !busy && describeId == null) onClose()
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [open, busy, describeId, onClose])

  async function runCatalogSearch(text = query) {
    const q = text.trim()
    if (q.length < 2) {
      setError('Wpisz co najmniej 2 znaki zapytania.')
      return
    }
    setBusy('catalog')
    setError('')
    setResults([])
    setExternalHints([])
    try {
      const res = await api<{ data: Product[] }>(
        `/products?q=${encodeURIComponent(q)}&per_page=20`,
      )
      const mapped: AiMatchPick[] = (res.data ?? []).map((p) => ({
        id: p.id,
        sku: p.sku,
        name: p.name,
        manufacturer: p.manufacturer,
        purchase_price: p.purchase_price,
        purchase_price_pln: p.purchase_price_pln ?? null,
        catalog_price_net: p.catalog_price_net,
        currency: p.currency ?? 'PLN',
        score: 100,
        reason: 'Trafienie w nazwę / SKU',
        source: 'catalog',
      }))
      setResults(mapped)
      if (mapped.length === 0) {
        setError('Brak produktów w katalogu dla tego zapytania.')
      }
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Błąd wyszukiwania')
    } finally {
      setBusy(false)
    }
  }

  async function runSearch(web: boolean, text = query) {
    const q = text.trim()
    if (q.length < AI_SEARCH_MIN_CHARS) {
      setError('Wpisz co najmniej 3 znaki zapytania.')
      return
    }
    setBusy(web ? 'web' : 'ai')
    setError('')
    setResults([])
    setExternalHints([])
    try {
      const res = await searchProductsByAiWithTimeout(q, { web })
      const mapped: AiMatchPick[] = (res.products ?? []).map((p) => ({
        id: p.id,
        sku: p.sku,
        name: p.name,
        manufacturer: p.manufacturer,
        purchase_price: p.purchase_price,
        purchase_price_pln: p.purchase_price_pln ?? null,
        catalog_price_net: p.catalog_price_net,
        currency: p.currency ?? 'PLN',
        score: p.ai_match_percent ?? 0,
        reason: p.ai_match_reason ?? null,
        source: 'ai',
      }))
      const hints = externalHintsFrom(res)
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
      setError(
        isAiSearchTimeout(e)
          ? 'Wyszukiwanie AI przekroczyło limit czasu (180 s).'
          : e instanceof Error
            ? e.message
            : 'Błąd wyszukiwania AI',
      )
    } finally {
      setBusy(false)
    }
  }

  const startMode: SearchKind = initialMode ?? (initialWeb ? 'web' : 'ai')

  useEffect(() => {
    if (!open) return
    if (startMode === 'web') {
      void runSearch(true, initialQuery)
    } else if (startMode === 'catalog') {
      void runCatalogSearch(initialQuery)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps -- tylko przy otwarciu
  }, [open, startMode, initialQuery])

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
            <p className="text-sm font-semibold text-slate-900">Wyszukiwanie produktu</p>
            <p className="text-xs text-slate-500">
              Szukaj — nazwa/SKU. Szukaj AI — to samo „Szukaj w katalogu” co na Produktach. AI Internet — poza bazą.
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
              onClick={() => void runCatalogSearch()}
              className="rounded bg-sky-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-sky-700 disabled:opacity-50"
            >
              {busy === 'catalog' ? 'Szukam…' : 'Szukaj'}
            </button>
            <button
              type="button"
              disabled={Boolean(busy)}
              onClick={() => void runSearch(false)}
              className="rounded bg-violet-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-violet-700 disabled:opacity-50"
            >
              {busy === 'ai' ? 'Szukam…' : 'Szukaj w katalogu'}
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
              <div className="flex items-center justify-between gap-2">
                <p className="text-xs font-medium text-slate-600">
                  {allowCompanion ? 'Wybierz produkt albo zaznacz dwa:' : 'Wybierz produkt:'}
                </p>
                {allowCompanion && onSelectPair && (
                  <button
                    type="button"
                    disabled={Boolean(busy) || selectedIds.length !== 2}
                    onClick={() => {
                      const picks = orderSetPicks(
                        selectedIds
                          .map((id) => results.find((row) => row.id === id))
                          .filter((row): row is AiMatchPick => row != null),
                      )
                      if (picks.length === 2) onSelectPair(picks[0], picks[1])
                    }}
                    className="rounded bg-violet-800 px-2 py-1 text-[11px] font-medium text-white hover:bg-violet-900 disabled:opacity-50"
                  >
                    Dodaj oba{selectedIds.length > 0 ? ` (${selectedIds.length}/2)` : ''}
                  </button>
                )}
              </div>
              {results.map((r) => (
                <div
                  key={r.id}
                  className={
                    r.source === 'catalog'
                      ? 'rounded-md border border-sky-200 bg-sky-50 px-3 py-2'
                      : 'rounded-md border border-violet-200 bg-violet-50 px-3 py-2'
                  }
                >
                  <div className="flex items-start justify-between gap-2">
                    {allowCompanion && (
                      <label className="mt-0.5 flex shrink-0 items-center">
                        <input
                          type="checkbox"
                          checked={selectedIds.includes(r.id)}
                          disabled={Boolean(busy)}
                          onChange={() =>
                            setSelectedIds((prev) => {
                              if (prev.includes(r.id)) return prev.filter((id) => id !== r.id)
                              if (prev.length >= 2) return [prev[0], r.id]
                              return [...prev, r.id]
                            })
                          }
                        />
                      </label>
                    )}
                    <span
                      className={
                        r.source === 'catalog'
                          ? 'min-w-0 flex-1 text-sm font-medium text-sky-950'
                          : 'min-w-0 flex-1 text-sm font-medium text-violet-950'
                      }
                    >
                      {r.sku} · {r.name}
                    </span>
                    {r.source === 'catalog' ? (
                      <span className="shrink-0 text-[10px] font-semibold uppercase text-sky-700">
                        nazwa
                      </span>
                    ) : (
                      <span className="shrink-0 text-xs text-violet-700">{r.score}%</span>
                    )}
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
                      {(r.currency ?? 'PLN').toUpperCase() !== 'PLN' && r.purchase_price_pln != null ? (
                        <span className="text-slate-500"> ≈ {r.purchase_price_pln} zł</span>
                      ) : null}
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
                    {allowCompanion && onSelectCompanion && (
                      <button
                        type="button"
                        disabled={Boolean(busy) || !hasMainProduct}
                        onClick={() => onSelectCompanion(r)}
                        className="rounded border border-violet-400 bg-white px-2 py-1 text-[11px] font-medium text-violet-800 hover:bg-violet-100 disabled:opacity-50"
                      >
                        Jako drugi
                      </button>
                    )}
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
