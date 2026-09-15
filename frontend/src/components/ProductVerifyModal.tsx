import { useEffect, useMemo, useRef, useState } from 'react'
import { api, appHref, type Product } from '../lib/api'
import {
  countFindHits,
  findAllOffsets,
  findBrandOffsets,
  KEY_TERMS_MIN_TOKENS,
  queryHighlightTokens,
} from '../lib/descriptionHighlight'
import { productDisplayName } from '../lib/productLabel'
import { DescriptionLayoutView, descriptionSearchText } from './DescriptionLayoutView'
import { RequirementCheckTable } from './RequirementCheckTable'

type Props = {
  productId: number | null
  query?: string
  onClose: () => void
}

type RequirementTerms = {
  head: string
  noun: { label: string; stem: string } | null
  norms: { label: string; needles: string[] }[]
}

type KeyChip = { key: string; label: string; find: string; count: number }

export function ProductVerifyModal({ productId, query = '', onClose }: Props) {
  const [product, setProduct] = useState<Product | null>(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const [find, setFind] = useState('')
  const [findIndex, setFindIndex] = useState(0)
  const [imageIndex, setImageIndex] = useState(0)
  // Wynik i przełącznik trzymane razem z kluczem, dla którego powstały — po zmianie
  // zapytania/produktu stare wartości same przestają obowiązywać, bez resetu w efekcie.
  const [fetchedTerms, setFetchedTerms] = useState<{ query: string; terms: RequirementTerms } | null>(null)
  const [showAllFor, setShowAllFor] = useState<string | null>(null)
  const bodyRef = useRef<HTMLDivElement>(null)

  const allTokens = useMemo(() => queryHighlightTokens(query), [query])
  const open = productId != null
  const wantsKeyTerms = open && allTokens.length > KEY_TERMS_MIN_TOKENS

  useEffect(() => {
    if (!wantsKeyTerms) return
    let cancelled = false
    void api<RequirementTerms>('/products/requirement-terms', {
      method: 'POST',
      body: JSON.stringify({ query }),
    })
      .then((terms) => {
        if (!cancelled) setFetchedTerms({ query, terms })
      })
      // Funkcja pomocnicza: bez odpowiedzi zostaje pełna lista słów, jak przy krótkim zapytaniu.
      .catch(() => {})
    return () => {
      cancelled = true
    }
  }, [wantsKeyTerms, query])

  useEffect(() => {
    if (productId == null) return
    setLoading(true)
    setError('')
    setProduct(null)
    setFind('')
    setFindIndex(0)
    setImageIndex(0)
    setShowAllFor(null)
    void api<Product>(`/products/${productId}`)
      .then(setProduct)
      .catch((e) => setError(e instanceof Error ? e.message : 'Nie udało się pobrać produktu'))
      .finally(() => setLoading(false))
  }, [productId])

  useEffect(() => {
    if (productId == null) return
    function onKey(e: KeyboardEvent) {
      if (e.key === 'Escape') {
        e.stopPropagation()
        onClose()
      }
    }
    document.addEventListener('keydown', onKey, true)
    return () => document.removeEventListener('keydown', onKey, true)
  }, [productId, onClose])

  const bodyText = useMemo(() => (product ? descriptionSearchText(product) : ''), [product])

  const findHits = useMemo(() => findAllOffsets(bodyText, find), [bodyText, find])
  const tokenHitCounts = useMemo(
    () => Object.fromEntries(allTokens.map((t) => [t, countFindHits(bodyText, t)])),
    [bodyText, allTokens],
  )

  const terms = wantsKeyTerms && fetchedTerms?.query === query ? fetchedTerms.terms : null
  const keyTerms = useMemo(() => {
    if (!terms) return null
    const stem = terms.noun?.stem.trim() ?? ''
    const nounKey = terms.noun?.label.toLocaleLowerCase('pl')
    const kind: KeyChip[] = []
    if (terms.noun && stem !== '') {
      kind.push({ key: `n:${stem}`, label: terms.noun.label, find: stem, count: countFindHits(bodyText, stem) })
    }
    const headWords = queryHighlightTokens(terms.head).filter((w) => w.toLocaleLowerCase('pl') !== nounKey)
    for (const w of headWords) {
      kind.push({ key: `w:${w}`, label: w, find: w, count: countFindHits(bodyText, w) })
    }
    // Igły jednej normy się nie nakładają, więc suma trafień to liczba wystąpień normy.
    // Granica słowa, bo „EN 166” jako podciąg trafiłoby też w „EN 1660”.
    // Kliknięcie wpisuje zapis najczęstszy w opisie — przy „EN166” w karcie wyszukiwarka
    // z etykietą „EN 166” pokazałaby 0, choć chip ma trafienia.
    const norms: KeyChip[] = terms.norms.map((n) => {
      const hits = n.needles.map((needle) => ({ needle, count: findBrandOffsets(bodyText, needle).length }))
      const best = hits.reduce((top, h) => (h.count > top.count ? h : top), { needle: n.label, count: 0 })
      return {
        key: `e:${n.label}`,
        label: n.label,
        find: best.needle,
        count: hits.reduce((sum, h) => sum + h.count, 0),
      }
    })
    if (kind.length + norms.length === 0) return null
    // Podświetlenie w opisie ma pokazywać dokładnie to, co liczą chipy.
    const seen = new Set<string>()
    const tokens = [stem, ...headWords, ...terms.norms.flatMap((n) => n.needles)]
      .map((t) => t.trim())
      .filter((t) => {
        const k = t.toLocaleLowerCase('pl')
        if (t === '' || seen.has(k)) return false
        seen.add(k)
        return true
      })
    return { kind, norms, tokens }
  }, [terms, bodyText])

  const showAll = showAllFor === `${productId}|${query}`
  const keyMode = keyTerms != null && !showAll
  const tokens = keyMode ? keyTerms.tokens : allTokens
  const keyChips = keyTerms ? [...keyTerms.kind, ...keyTerms.norms] : []

  useEffect(() => {
    setFindIndex(0)
  }, [find])

  useEffect(() => {
    if (findHits.length === 0) return
    const el = bodyRef.current?.querySelector(`[data-find-hit="${findIndex}"]`)
    el?.scrollIntoView({ block: 'center', behavior: 'smooth' })
  }, [findIndex, findHits.length, bodyText])

  if (productId == null) return null

  const images = product?.images ?? []
  const image = images[imageIndex] ?? images[0]
  const hitCount = findHits.length
  const safeIndex = hitCount === 0 ? 0 : ((findIndex % hitCount) + hitCount) % hitCount

  function jump(delta: number) {
    if (hitCount === 0) return
    setFindIndex((i) => ((i + delta) % hitCount + hitCount) % hitCount)
  }

  return (
    <div
      className="fixed inset-0 z-[70] flex items-center justify-center bg-slate-950/70 p-3 sm:p-6"
      role="dialog"
      aria-modal="true"
      aria-labelledby="verify-title"
      onClick={onClose}
    >
      <div
        className="flex max-h-[94vh] w-full max-w-6xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="flex items-start justify-between gap-3 border-b border-slate-100 bg-gradient-to-r from-violet-700 to-indigo-600 px-5 py-4 text-white">
          <div className="min-w-0">
            <p className="text-xs font-medium uppercase tracking-wide text-violet-200">Weryfikacja karty</p>
            {product && (
              <>
                <h2 id="verify-title" className="mt-0.5 truncate text-lg font-semibold">
                  {productDisplayName(product, 160)}
                </h2>
                <p className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-violet-100">
                  <span className="truncate">{product.sku}</span>
                  {product.manufacturer?.trim() ? (
                    <span className="rounded-md bg-amber-300 px-1.5 py-0.5 text-[11px] font-bold uppercase tracking-wide text-amber-950">
                      {product.manufacturer.trim()}
                    </span>
                  ) : null}
                  {product.category ? <span className="truncate">{product.category}</span> : null}
                </p>
              </>
            )}
            {loading && <p className="text-sm text-violet-100">Ładowanie karty…</p>}
            {error && <p className="text-sm text-red-100">{error}</p>}
          </div>
          <div className="flex shrink-0 items-center gap-2">
            <a
              href={appHref(`/products/${productId}`)}
              target="_blank"
              rel="noreferrer"
              className="rounded-md border border-white/30 px-2.5 py-1 text-xs font-medium hover:bg-white/10"
            >
              Otwórz w nowej karcie
            </a>
            <button
              type="button"
              onClick={onClose}
              className="rounded-md border border-white/30 px-2.5 py-1 text-xs font-medium hover:bg-white/10"
            >
              Zamknij
            </button>
          </div>
        </div>

        <div className="flex flex-wrap items-center gap-2 border-b border-slate-100 bg-slate-50 px-5 py-2.5">
          <label className="flex min-w-[16rem] flex-1 items-center gap-2">
            <span className="shrink-0 text-xs font-medium text-slate-600">Szukaj w opisie</span>
            <input
              type="search"
              value={find}
              onChange={(e) => setFind(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === 'Enter') {
                  e.preventDefault()
                  jump(e.shiftKey ? -1 : 1)
                }
              }}
              placeholder="wpisz frazę — Enter następna, Shift+Enter poprzednia"
              className="w-full rounded-md border border-slate-300 bg-white px-2.5 py-1.5 text-sm focus:border-violet-400 focus:outline-none focus:ring-2 focus:ring-violet-200"
            />
          </label>
          <span className="text-xs tabular-nums text-slate-500">
            {find.trim().length >= 2
              ? hitCount === 0
                ? '0 trafień (0)'
                : `${safeIndex + 1} / ${hitCount} (${hitCount})`
              : keyMode
                ? `${keyChips.length} znaczących fraz (${keyChips.reduce((n, c) => n + c.count, 0)})`
                : allTokens.length > 0
                  ? `${allTokens.length} fraz z zapytania (${allTokens.reduce((n, t) => n + (tokenHitCounts[t] ?? 0), 0)})`
                  : ''}
          </span>
          <button
            type="button"
            disabled={hitCount === 0}
            onClick={() => jump(-1)}
            className="rounded border border-slate-300 px-2 py-1 text-xs disabled:opacity-40"
          >
            Poprzednie
          </button>
          <button
            type="button"
            disabled={hitCount === 0}
            onClick={() => jump(1)}
            className="rounded border border-slate-300 px-2 py-1 text-xs disabled:opacity-40"
          >
            Następne
          </button>
        </div>

        {keyMode ? (
          <div className="flex flex-wrap items-start gap-x-3 gap-y-1.5 border-b border-slate-100 px-5 py-2">
            {[
              { label: 'Rodzaj:', chips: keyTerms.kind },
              { label: 'Normy:', chips: keyTerms.norms },
            ].map((row) =>
              row.chips.length > 0 ? (
                <div key={row.label} className="flex flex-wrap gap-1.5">
                  <span className="text-[11px] font-medium text-slate-500">{row.label}</span>
                  {row.chips.map((c) => (
                    <button
                      key={c.key}
                      type="button"
                      onClick={() => setFind(c.find)}
                      // Zero trafień często znaczy tylko inną formę wyrazu, nie brak cechy — stąd szary, nie alarm.
                      title={c.count === 0 ? 'Brak dosłownego zapisu w opisie — sprawdź inną formę wyrazu' : undefined}
                      className={`rounded-full px-2 py-0.5 text-[11px] font-medium ${
                        c.count === 0
                          ? 'bg-slate-100 text-slate-500 hover:bg-slate-200'
                          : 'bg-amber-100 text-amber-950 hover:bg-amber-200'
                      }`}
                    >
                      {c.label} ({c.count})
                    </button>
                  ))}
                </div>
              ) : null,
            )}
            <button
              type="button"
              onClick={() => setShowAllFor(`${productId}|${query}`)}
              className="text-[11px] font-medium text-violet-700 hover:underline"
            >
              pokaż wszystkie słowa ({allTokens.length})
            </button>
          </div>
        ) : (
          allTokens.length > 0 && (
            <div className="flex flex-wrap gap-1.5 border-b border-slate-100 px-5 py-2">
              <span className="text-[11px] font-medium text-slate-500">Z zapytania:</span>
              {allTokens.map((t) => (
                <button
                  key={t}
                  type="button"
                  onClick={() => setFind(t)}
                  className="rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-medium text-amber-950 hover:bg-amber-200"
                >
                  {t} ({tokenHitCounts[t] ?? 0})
                </button>
              ))}
              {keyTerms && (
                <button
                  type="button"
                  onClick={() => setShowAllFor(null)}
                  className="text-[11px] font-medium text-violet-700 hover:underline"
                >
                  tylko znaczące
                </button>
              )}
            </div>
          )
        )}

        {product && (
          <RequirementCheckTable
            productId={productId}
            query={query}
            onFind={setFind}
            findHitCount={(p) => countFindHits(bodyText, p)}
          />
        )}

        <div className="grid min-h-0 flex-1 grid-cols-1 overflow-hidden lg:grid-cols-[minmax(280px,38%)_1fr]">
          <div className="flex flex-col gap-3 border-b border-slate-100 bg-slate-50 p-4 lg:border-b-0 lg:border-r">
            {image ? (
              <img
                src={image.url}
                alt={product?.name ?? ''}
                className="max-h-[52vh] w-full rounded-lg border border-slate-200 bg-white object-contain"
              />
            ) : (
              <div className="flex h-48 items-center justify-center rounded-lg border border-dashed border-slate-300 text-sm text-slate-400">
                Brak zdjęcia
              </div>
            )}
            {images.length > 1 && (
              <div className="flex flex-wrap gap-1.5">
                {images.map((img, i) => (
                  <button
                    key={img.id}
                    type="button"
                    onClick={() => setImageIndex(i)}
                    className={`h-14 w-14 overflow-hidden rounded border ${
                      i === imageIndex ? 'border-violet-600 ring-2 ring-violet-300' : 'border-slate-200'
                    }`}
                  >
                    <img src={img.url} alt="" className="h-full w-full object-cover" />
                  </button>
                ))}
              </div>
            )}
            {product && (
              <div className="grid grid-cols-2 gap-2 text-xs text-slate-700">
                <span>
                  Zakup: <b>{product.purchase_price} {product.currency ?? 'PLN'}</b>
                  {(product.currency ?? 'PLN').toUpperCase() !== 'PLN' && product.purchase_price_pln != null ? (
                    <span className="block text-[10px] text-slate-500">≈ {product.purchase_price_pln} zł</span>
                  ) : null}
                </span>
                <span>
                  Katalog: <b>{product.catalog_price_net} {product.currency ?? 'PLN'}</b>
                  {(product.currency ?? 'PLN').toUpperCase() !== 'PLN' && product.price_pln != null ? (
                    <span className="block text-[10px] text-slate-500">≈ {product.price_pln} zł</span>
                  ) : null}
                </span>
              </div>
            )}
          </div>

          <div ref={bodyRef} className="min-h-0 overflow-y-auto px-5 py-4">
            {product ? (
              <DescriptionLayoutView
                product={product}
                queryTokens={tokens}
                findPhrase={find}
                activeFindIndex={safeIndex}
              />
            ) : (
              !loading && <p className="text-sm text-slate-500">Brak opisu w karcie.</p>
            )}
          </div>
        </div>
      </div>
    </div>
  )
}
