import { useEffect, useMemo, useRef, useState, type KeyboardEvent as ReactKeyboardEvent, type ReactNode } from 'react'
import { api, appHref, type Product } from '../lib/api'
import { queryHighlightTokens } from '../lib/descriptionHighlight'
import { currencyLabel, formatPrice } from '../lib/priceChange'
import { productDisplayName, productThumbUrl } from '../lib/productLabel'
import { descriptionSearchText } from './DescriptionLayoutView'
import { HighlightedDescription } from './HighlightedDescription'
import { ProductVerifyModal } from './ProductVerifyModal'

/** Opóźnienie po ostatnim znaku, zanim pójdzie żądanie. */
const DEBOUNCE_MS = 250
const MIN_LENGTH = 2
const PER_PAGE = 30
/** Fragment opisu w podglądzie — pełny opis jest w „Pełnym podglądzie”. */
const DESCRIPTION_CHARS = 1500
const LIST_ID = 'card-pick-list'

type SearchPage = { data: Product[]; total: number; current_page: number; last_page: number }
type Results = { query: string; rows: Product[]; total: number; page: number; lastPage: number }
type WordCount = { word: string; count: number }
/** Karta wskazana w oknie: z listy wyników (pełne dane) albo z listy startowej (tylko SKU i nazwa). */
export type CardPick = { id: number; sku: string; name: string; manufacturer: string | null }
export type CardPickOption = { key: string; pick: CardPick; product: Product | null; note: string | null }

/** „1 kartę”, „2 karty”, „5 kart”, „22 karty” (biernik po „Znaleziono”). */
function cardsLabel(n: number): string {
  if (n === 1) return 'kartę'
  const d = n % 10
  const dd = n % 100
  return d >= 2 && d <= 4 && (dd < 12 || dd > 14) ? 'karty' : 'kart'
}

function optionId(key: string): string {
  return `card-pick-${key}`
}

/**
 * Duże okno wyboru karty produktu: szukanie /products?words=all (każde wpisane słowo zawęża listę), lista po lewej,
 * podgląd wskazanej karty po prawej, „Pełny podgląd” w ProductVerifyModal (z-[70], nad tym oknem). Wspólne dla
 * „Połącz towar XL z kartą” (ErpCardPickerModal) i dodawania produktów do oferty. Wskazana karta jest trzymana jako
 * obiekt, nie indeks — nowe wyniki i „Pokaż więcej” nie zmieniają karty, którą zatwierdzi przycisk.
 */
export function CardPickerModal({
  title,
  header,
  words = [],
  wordsLabel = '',
  presetOptions = [],
  presetHint = '',
  emptyHint,
  noteFor,
  badgesFor,
  canSubmit,
  notice,
  error,
  placeholder,
  ariaLabel,
  footerHint,
  submitLabel,
  previewQuery,
  onClose,
  onPick,
}: {
  title: string
  /** Pod tytułem (np. towar XL ze stanem i datami). */
  header?: ReactNode
  /** Słowa do kliknięcia nad listą (np. z nazwy towaru XL) — przy każdym liczba kart, które trafia. */
  words?: string[]
  wordsLabel?: string
  /** Lista przy pustej frazie (np. karty, które towar już ma, i propozycje automatu). */
  presetOptions?: CardPickOption[]
  presetHint?: string
  /** Pusta fraza i pusta lista startowa. */
  emptyHint: string
  /** Krótka etykieta przy wyniku wyszukiwania (np. „obecna karta”). */
  noteFor?: (productId: number) => string | null
  /** Dodatkowe znaczki przy karcie na liście (np. „odrzucona wcześniej”, „już w ofercie”). */
  badgesFor?: (productId: number) => ReactNode
  /** false = przycisk zatwierdzenia wyłączony dla wskazanej karty. */
  canSubmit?: (pick: CardPick) => boolean
  /** Nad przyciskami (np. ostrzeżenie albo „Dodano …”). */
  notice?: ReactNode
  error: string
  placeholder: string
  ariaLabel: string
  footerHint: string
  submitLabel: (pick: CardPick | null, busy: boolean) => string
  /** Fraza dla „Pełnego podglądu” (podświetlenie w opisie). */
  previewQuery: string
  onClose: () => void
  onPick: (productId: number, sku: string) => Promise<boolean>
}) {
  const [query, setQuery] = useState('')
  const [results, setResults] = useState<Results | null>(null)
  const [loading, setLoading] = useState(false)
  const [loadingMore, setLoadingMore] = useState(false)
  const [searchErr, setSearchErr] = useState('')
  const [retry, setRetry] = useState(0)
  const [pick, setPick] = useState<CardPick | null>(null)
  /** Pełne dane wskazanej karty z listy startowej (wiersz tej listy ma tylko SKU i nazwę). */
  const [fetched, setFetched] = useState<Product | null>(null)
  const [fetchErr, setFetchErr] = useState('')
  const [previewId, setPreviewId] = useState<number | null>(null)
  const [nameCounts, setNameCounts] = useState<WordCount[] | null>(null)
  const [phraseCounts, setPhraseCounts] = useState<{ query: string; words: WordCount[] } | null>(null)
  const [busy, setBusy] = useState(false)
  const inputRef = useRef<HTMLInputElement | null>(null)
  const dialogRef = useRef<HTMLDivElement | null>(null)
  const busyRef = useRef(busy)
  const onCloseRef = useRef(onClose)

  useEffect(() => {
    busyRef.current = busy
    onCloseRef.current = onClose
  }, [busy, onClose])

  const trimmed = query.trim()
  const tooShort = trimmed.length < MIN_LENGTH
  const current = results !== null && results.query === trimmed ? results : null
  const wordsKey = words.join(' ')
  const highlight = useMemo(
    () => [...new Set([...words, ...queryHighlightTokens(trimmed)].map((w) => w.toLocaleLowerCase('pl')))],
    [words, trimmed],
  )

  // fokus w polu szukania; po zamknięciu wraca na przycisk, którym otwarto okno
  useEffect(() => {
    const opener = document.activeElement instanceof HTMLElement ? document.activeElement : null
    inputRef.current?.focus()
    return () => {
      if (opener && document.contains(opener)) opener.focus()
    }
  }, [])

  // Escape w fazie bubble: otwarty „Pełny podgląd” łapie Escape wcześniej (capture) i zamyka tylko siebie
  useEffect(() => {
    function onKey(e: KeyboardEvent) {
      if (e.key === 'Escape' && !busyRef.current) onCloseRef.current()
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [])

  // ile kart trafia każde podpowiadane słowo — liczby przy chipach (jedno lekkie żądanie na otwarcie okna)
  useEffect(() => {
    if (wordsKey === '') return
    const controller = new AbortController()
    void api<{ words: WordCount[] }>(`/products/word-counts?q=${encodeURIComponent(wordsKey)}`, {
      signal: controller.signal,
    })
      .then((res) => {
        if (!controller.signal.aborted) setNameCounts(res.words)
      })
      // tylko podpowiedź — bez liczb chipy dalej działają
      .catch(() => {})
    return () => controller.abort()
  }, [wordsKey])

  useEffect(() => {
    if (tooShort) {
      setLoading(false)
      setSearchErr('')
      return
    }
    const controller = new AbortController()
    setLoading(true)
    setSearchErr('')
    const timer = window.setTimeout(() => {
      api<SearchPage>(`/products?q=${encodeURIComponent(trimmed)}&words=all&per_page=${PER_PAGE}&page=1`, {
        signal: controller.signal,
      })
        .then((res) => {
          if (controller.signal.aborted) return
          // klucz odpowiedzi = fraza wysłana z przeglądarki — stara odpowiedź nie miesza się z nową
          setResults({ query: trimmed, rows: res.data ?? [], total: res.total, page: res.current_page, lastPage: res.last_page })
        })
        .catch((ex: unknown) => {
          if (controller.signal.aborted) return
          setSearchErr(ex instanceof Error ? ex.message : 'Wyszukiwanie się nie udało.')
        })
        .finally(() => {
          if (!controller.signal.aborted) setLoading(false)
        })
    }, DEBOUNCE_MS)
    return () => {
      window.clearTimeout(timer)
      controller.abort()
    }
  }, [trimmed, tooShort, retry])

  // fraza z kilku słów bez wyników — ile kart trafia każde słowo osobno (podpowiedź, które słowo usunąć)
  const multiWord = trimmed.split(/\s+/).filter((w) => w.length >= MIN_LENGTH).length > 1
  const noHits = current !== null && current.rows.length === 0
  useEffect(() => {
    if (!noHits || !multiWord) return
    const controller = new AbortController()
    void api<{ words: WordCount[] }>(`/products/word-counts?q=${encodeURIComponent(trimmed)}`, { signal: controller.signal })
      .then((res) => {
        if (!controller.signal.aborted) setPhraseCounts({ query: trimmed, words: res.words })
      })
      .catch(() => {})
    return () => controller.abort()
  }, [noHits, multiWord, trimmed])

  const options = useMemo<CardPickOption[]>(() => {
    if (tooShort) return presetOptions
    return (current?.rows ?? []).map((p) => ({
      key: `p${p.id}`,
      pick: { id: p.id, sku: p.sku, name: p.name, manufacturer: p.manufacturer },
      product: p,
      note: noteFor?.(p.id) ?? null,
    }))
  }, [tooShort, presetOptions, current, noteFor])

  const activeIndex = pick === null ? -1 : options.findIndex((o) => o.pick.id === pick.id)
  const activeKey = activeIndex >= 0 ? options[activeIndex].key : null
  useEffect(() => {
    if (activeKey) document.getElementById(optionId(activeKey))?.scrollIntoView({ block: 'nearest' })
  }, [activeKey])

  // podgląd: dane z wiersza listy; wiersz listy startowej nie ma zdjęć, ceny ani opisu — dociągamy kartę
  const listed = pick === null ? null : (options.find((o) => o.pick.id === pick.id && o.product)?.product ?? null)
  const detail = listed ?? (fetched !== null && pick !== null && fetched.id === pick.id ? fetched : null)
  const needsFetch = pick !== null && listed === null
  useEffect(() => {
    if (!needsFetch || pick === null) return
    const controller = new AbortController()
    setFetchErr('')
    void api<Product>(`/products/${pick.id}`, { signal: controller.signal })
      .then((p) => {
        if (!controller.signal.aborted) setFetched(p)
      })
      .catch((ex: unknown) => {
        if (!controller.signal.aborted) setFetchErr(ex instanceof Error ? ex.message : 'Nie udało się pobrać karty.')
      })
    return () => controller.abort()
  }, [needsFetch, pick])

  const submitAllowed = pick !== null && (canSubmit?.(pick) ?? true)

  function choose(option: CardPickOption) {
    setPick(option.pick)
  }

  function searchWord(word: string, append: boolean) {
    setQuery((q) => (append && q.trim() !== '' ? `${q.trim()} ${word}` : word))
    inputRef.current?.focus()
  }

  async function submit() {
    if (pick === null || busy || !submitAllowed) return
    setBusy(true)
    try {
      await onPick(pick.id, pick.sku)
    } finally {
      setBusy(false)
    }
  }

  async function loadMore() {
    if (current === null || current.page >= current.lastPage || loadingMore) return
    const q = current.query
    setLoadingMore(true)
    try {
      const res = await api<SearchPage>(
        `/products?q=${encodeURIComponent(q)}&words=all&per_page=${PER_PAGE}&page=${current.page + 1}`,
      )
      setResults((prev) => {
        if (prev === null || prev.query !== q) return prev
        const seen = new Set(prev.rows.map((r) => r.id))
        return {
          ...prev,
          rows: [...prev.rows, ...(res.data ?? []).filter((r) => !seen.has(r.id))],
          page: res.current_page,
          lastPage: res.last_page,
          total: res.total,
        }
      })
    } catch (ex) {
      setSearchErr(ex instanceof Error ? ex.message : 'Nie udało się wczytać kolejnych kart.')
    } finally {
      setLoadingMore(false)
    }
  }

  function onInputKey(e: ReactKeyboardEvent<HTMLInputElement>) {
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault()
      if (options.length === 0) return
      const step = e.key === 'ArrowDown' ? 1 : -1
      const next = activeIndex < 0 ? (step > 0 ? 0 : options.length - 1) : (activeIndex + step + options.length) % options.length
      choose(options[next])
    } else if (e.key === 'Enter' && !e.ctrlKey && !e.metaKey) {
      e.preventDefault()
      if (activeIndex < 0 && options.length > 0) choose(options[0])
    }
  }

  function onDialogKey(e: ReactKeyboardEvent<HTMLDivElement>) {
    // Ctrl+K (wyszukiwarka całej aplikacji) otworzyłaby się pod oknem i zabrała Tab i Escape
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
      e.preventDefault()
      e.stopPropagation()
      return
    }
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
      e.preventDefault()
      void submit()
      return
    }
    // Tab krąży w oknie (w projekcie nie ma wspólnej pułapki fokusu)
    if (e.key === 'Tab' && dialogRef.current) {
      const stops = [
        ...dialogRef.current.querySelectorAll<HTMLElement>('input:not([disabled]), button:not([disabled]), a[href], [tabindex="0"]'),
      ].filter((el) => el.tabIndex >= 0 && el.offsetParent !== null)
      if (stops.length === 0) return
      const first = stops[0]
      const last = stops[stops.length - 1]
      if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault()
        first.focus()
      } else if (e.shiftKey && document.activeElement === first) {
        e.preventDefault()
        last.focus()
      }
    }
  }

  const countOf = (word: string) =>
    nameCounts?.find((c) => c.word.toLocaleLowerCase('pl') === word.toLocaleLowerCase('pl'))?.count
  const phraseWordCounts = phraseCounts !== null && phraseCounts.query === trimmed ? phraseCounts.words : null
  const hasMore = current !== null && current.page < current.lastPage

  return (
    <div
      className="fixed inset-0 z-[60] flex items-center justify-center bg-slate-950/50 p-2 sm:p-4"
      onMouseDown={(e) => {
        if (e.target === e.currentTarget && !busy) onClose()
      }}
    >
      <div
        ref={dialogRef}
        role="dialog"
        aria-modal="true"
        aria-labelledby="card-pick-title"
        onKeyDown={onDialogKey}
        className="flex h-[min(900px,94vh)] w-full max-w-6xl flex-col overflow-hidden rounded-xl bg-white shadow-2xl"
      >
        <div className="flex items-start gap-3 border-b border-slate-200 px-4 py-3">
          <div className="min-w-0 flex-1">
            <h3 id="card-pick-title" className="text-xs font-semibold uppercase tracking-wide text-slate-500">
              {title}
            </h3>
            {header}
          </div>
          <button
            type="button"
            onClick={onClose}
            disabled={busy}
            className="mr-1 shrink-0 rounded-md border border-slate-300 px-2.5 py-1 text-xs hover:bg-slate-50 disabled:opacity-50"
          >
            Zamknij <kbd className="ml-1 text-[10px] text-slate-500">Esc</kbd>
          </button>
        </div>

        {/* szukanie */}
        <div className="border-b border-slate-200 bg-slate-50 px-4 py-2.5">
          <div className="flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3 focus-within:border-blue-500 focus-within:ring-2 focus-within:ring-blue-100">
            <svg viewBox="0 0 24 24" className="h-4 w-4 shrink-0 text-slate-500" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
              <circle cx="11" cy="11" r="7" />
              <path d="M20 20l-4-4" />
            </svg>
            <input
              ref={inputRef}
              type="text"
              role="combobox"
              aria-expanded={options.length > 0}
              aria-controls={LIST_ID}
              aria-autocomplete="list"
              aria-activedescendant={activeKey ? optionId(activeKey) : undefined}
              aria-label={ariaLabel}
              placeholder={placeholder}
              maxLength={200}
              value={query}
              disabled={busy}
              onChange={(e) => setQuery(e.target.value)}
              onKeyDown={onInputKey}
              className="min-w-0 flex-1 bg-transparent py-2 text-sm outline-none"
            />
            {loading && <span className="shrink-0 text-[11px] text-slate-500">Szukam…</span>}
            {query !== '' && (
              <button
                type="button"
                onClick={() => {
                  setQuery('')
                  inputRef.current?.focus()
                }}
                className="shrink-0 rounded px-1.5 text-xs text-slate-500 hover:bg-slate-100"
                aria-label="Wyczyść frazę"
              >
                ✕
              </button>
            )}
          </div>
          {words.length > 0 && (
            <div className="mt-2 flex flex-wrap items-center gap-1.5 text-[11px]">
              <span className="text-slate-500">{wordsLabel}</span>
              {words.map((w) => {
                const n = countOf(w)
                return (
                  <button
                    key={w}
                    type="button"
                    onClick={(e) => searchWord(w, e.shiftKey)}
                    title={`Szukaj „${w}” (Shift+klik dopisuje słowo do frazy)${n !== undefined ? ` — kart: ${n}` : ''}`}
                    className={`rounded-full border px-2 py-0.5 font-mono ${
                      n === 0
                        ? 'border-slate-200 bg-white text-slate-400'
                        : 'border-slate-300 bg-white text-slate-800 hover:border-blue-400 hover:bg-blue-50'
                    }`}
                  >
                    {w}
                    {n !== undefined && <span className="ml-1 text-slate-500">{n}</span>}
                  </button>
                )
              })}
              <span className="text-slate-400">· liczba = ile kart trafia słowo · Shift+klik dopisuje</span>
            </div>
          )}
        </div>

        {/* lista + podgląd */}
        <div className="grid min-h-0 flex-1 grid-cols-1 md:grid-cols-[minmax(0,1.1fr)_minmax(0,1fr)]">
          <div className="flex min-h-0 flex-col border-slate-200 md:border-r">
            <p className="border-b border-slate-100 px-4 py-1.5 text-[11px] text-slate-500" aria-live="polite">
              {tooShort
                ? options.length > 0
                  ? presetHint
                  : emptyHint
                : current === null
                  ? 'Szukam…'
                  : `Znaleziono ${current.total.toLocaleString('pl-PL')} ${cardsLabel(current.total)}${
                      current.total > current.rows.length ? ` · pokazano ${current.rows.length}` : ''
                    }`}
            </p>
            <div className="min-h-0 flex-1 overflow-y-auto">
              {searchErr && (
                <p className="px-4 py-2 text-xs text-red-700">
                  {searchErr}{' '}
                  <button type="button" className="underline" onClick={() => setRetry((n) => n + 1)}>
                    Spróbuj ponownie
                  </button>
                </p>
              )}
              {noHits && (
                <div className="px-4 py-3 text-xs text-slate-600">
                  <p>Żadna karta nie ma wszystkich słów frazy „{trimmed}”.</p>
                  {multiWord && (
                    <div className="mt-2 flex flex-wrap items-center gap-1.5">
                      <span className="text-slate-500">Szukaj jednego słowa:</span>
                      {(phraseWordCounts ?? []).map((c) => (
                        <button
                          key={c.word}
                          type="button"
                          onClick={() => searchWord(c.word, false)}
                          className={`rounded-full border px-2 py-0.5 font-mono ${
                            c.count === 0
                              ? 'border-slate-200 text-slate-400'
                              : 'border-blue-300 bg-blue-50 text-blue-800 hover:bg-blue-100'
                          }`}
                        >
                          {c.word} <span className="text-slate-500">{c.count}</span>
                        </button>
                      ))}
                      {phraseWordCounts === null && <span className="text-slate-400">liczę…</span>}
                    </div>
                  )}
                </div>
              )}
              <ul id={LIST_ID} role="listbox" aria-label="Karty do wyboru" className="divide-y divide-slate-100">
                {options.map((o) => {
                  const selected = pick?.id === o.pick.id
                  const p = o.product
                  const thumb = p ? productThumbUrl(p) : null
                  return (
                    <li
                      key={o.key}
                      id={optionId(o.key)}
                      role="option"
                      aria-selected={selected}
                      onClick={() => choose(o)}
                      onDoubleClick={() => setPreviewId(o.pick.id)}
                      className={`flex cursor-pointer items-start gap-3 px-4 py-2 ${
                        selected ? 'bg-blue-50 ring-1 ring-inset ring-blue-300' : 'hover:bg-slate-50'
                      }`}
                    >
                      <span className="flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded border border-slate-200 bg-white">
                        {thumb ? (
                          <img src={thumb} alt="" loading="lazy" className="h-full w-full object-contain" />
                        ) : (
                          <span className="text-[9px] text-slate-400">{p ? 'bez zdjęcia' : ''}</span>
                        )}
                      </span>
                      <span className="min-w-0 flex-1">
                        <span className="flex flex-wrap items-center gap-x-2">
                          <span className="font-mono text-xs font-semibold text-slate-900">{o.pick.sku}</span>
                          {o.pick.manufacturer && <span className="text-[11px] text-slate-500">{o.pick.manufacturer}</span>}
                          {o.note && (
                            <span className="rounded bg-violet-50 px-1.5 text-[10px] text-violet-800">{o.note}</span>
                          )}
                          {badgesFor?.(o.pick.id)}
                          {p && !p.description?.trim() && (
                            <span className="rounded bg-slate-100 px-1.5 text-[10px] text-slate-600">brak opisu</span>
                          )}
                        </span>
                        <span className="line-clamp-2 text-xs text-slate-700" title={o.pick.name}>
                          {p ? productDisplayName(p, 140) : o.pick.name}
                        </span>
                        {p && (p.erp_codes?.length ?? 0) > 0 && (
                          <span className="block font-mono text-[10px] text-slate-500">kod w XL: {p.erp_codes!.join(', ')}</span>
                        )}
                      </span>
                      <span className="flex shrink-0 flex-col items-end gap-1">
                        {p && (
                          <span className="whitespace-nowrap text-xs text-slate-700">
                            {formatPrice(p.purchase_price)} {currencyLabel(p.currency)}
                          </span>
                        )}
                        <button
                          type="button"
                          tabIndex={-1}
                          onClick={(e) => {
                            e.stopPropagation()
                            setPreviewId(o.pick.id)
                          }}
                          className="rounded border border-slate-300 bg-white px-2 py-0.5 text-[11px] text-slate-700 hover:bg-slate-50"
                        >
                          Podgląd
                        </button>
                      </span>
                    </li>
                  )
                })}
              </ul>
              {hasMore && (
                <div className="px-4 py-3 text-center">
                  <button
                    type="button"
                    disabled={loadingMore}
                    onClick={() => void loadMore()}
                    className="rounded border border-slate-300 px-3 py-1 text-xs hover:bg-slate-50 disabled:opacity-50"
                  >
                    {loadingMore ? 'Wczytuję…' : `Pokaż więcej (${current!.total - current!.rows.length})`}
                  </button>
                </div>
              )}
            </div>
          </div>

          {/* podgląd wskazanej karty */}
          <aside className="hidden min-h-0 flex-col overflow-y-auto bg-slate-50/60 md:flex" aria-label="Podgląd karty">
            {pick === null ? (
              <div className="m-auto max-w-xs px-6 text-center text-xs text-slate-500">
                Kliknij kartę na liście albo przejdź strzałkami ↑ ↓ — tu zobaczysz zdjęcie, cenę i opis.
              </div>
            ) : (
              <div className="space-y-3 p-4">
                <div className="flex gap-3">
                  <div className="flex h-40 w-40 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-slate-200 bg-white">
                    {detail && productThumbUrl(detail) ? (
                      <img src={productThumbUrl(detail) ?? ''} alt="" className="h-full w-full object-contain" />
                    ) : (
                      <span className="text-[11px] text-slate-400">{detail ? 'bez zdjęcia' : '…'}</span>
                    )}
                  </div>
                  <div className="min-w-0 flex-1 space-y-1 text-xs">
                    <p className="font-mono text-sm font-semibold text-slate-900">{pick.sku}</p>
                    <p className="text-slate-800">{detail?.name ?? pick.name}</p>
                    {(detail?.manufacturer ?? pick.manufacturer) && (
                      <p className="text-slate-600">Producent: {detail?.manufacturer ?? pick.manufacturer}</p>
                    )}
                    {detail && (
                      <p className="text-slate-600">
                        Cena zakupu:{' '}
                        <span className="font-medium text-slate-900">
                          {formatPrice(detail.purchase_price)} {currencyLabel(detail.currency)}
                        </span>
                        {detail.currency && detail.currency.toUpperCase() !== 'PLN' && detail.purchase_price_pln != null && (
                          <> (≈ {formatPrice(detail.purchase_price_pln)} zł)</>
                        )}
                      </p>
                    )}
                    {detail && (detail.pack_qty || detail.packaging) && (
                      <p className="text-slate-600">
                        Opakowanie: {[detail.packaging, detail.pack_qty ? `${detail.pack_qty} szt.` : null].filter(Boolean).join(' · ')}
                      </p>
                    )}
                    {detail && (detail.images?.length ?? 0) > 1 && (
                      <p className="text-slate-500">Zdjęć: {detail.images!.length}</p>
                    )}
                    <div className="flex flex-wrap gap-1.5 pt-1">
                      <button
                        type="button"
                        onClick={() => setPreviewId(pick.id)}
                        className="rounded border border-slate-300 bg-white px-2 py-1 text-[11px] hover:bg-slate-50"
                      >
                        Pełny podgląd karty
                      </button>
                      <a
                        href={appHref(`/products/${pick.id}`)}
                        target="_blank"
                        rel="noreferrer"
                        className="rounded border border-slate-300 bg-white px-2 py-1 text-[11px] text-slate-700 hover:bg-slate-50"
                      >
                        Otwórz kartę ↗
                      </a>
                    </div>
                  </div>
                </div>
                {fetchErr && !detail && <p className="text-xs text-red-700">{fetchErr}</p>}
                {detail && (
                  <div className="rounded-lg border border-slate-200 bg-white p-3">
                    <p className="mb-1 text-[11px] font-semibold uppercase tracking-wide text-slate-500">Opis</p>
                    {(() => {
                      const text = descriptionSearchText(detail)
                      if (text.trim() === '') return <p className="text-xs text-slate-500">Karta nie ma opisu.</p>
                      const cut = text.length > DESCRIPTION_CHARS
                      return (
                        <>
                          <HighlightedDescription
                            text={cut ? `${text.slice(0, DESCRIPTION_CHARS)}…` : text}
                            queryTokens={highlight}
                            findPhrase=""
                            activeFindIndex={0}
                            className="whitespace-pre-wrap break-words [overflow-wrap:anywhere] text-xs leading-relaxed text-slate-700"
                          />
                          {cut && (
                            <button
                              type="button"
                              onClick={() => setPreviewId(pick.id)}
                              className="mt-1 text-[11px] text-blue-700 underline"
                            >
                              Cały opis w pełnym podglądzie
                            </button>
                          )}
                        </>
                      )
                    })()}
                  </div>
                )}
              </div>
            )}
          </aside>
        </div>

        {/* decyzja */}
        <div className="flex flex-wrap items-center gap-3 border-t border-slate-200 px-4 py-2.5">
          <div className="min-w-0 flex-1 space-y-0.5 text-[11px]">
            {notice}
            {error && <p className="text-red-700">{error}</p>}
            <p className="text-slate-500">{footerHint}</p>
          </div>
          <button
            type="button"
            onClick={onClose}
            disabled={busy}
            className="rounded border border-slate-300 px-3 py-1.5 text-xs hover:bg-slate-50 disabled:opacity-50"
          >
            Anuluj
          </button>
          <button
            type="button"
            disabled={busy || !submitAllowed}
            onClick={() => void submit()}
            className="rounded bg-blue-600 px-4 py-1.5 text-xs font-medium text-white hover:bg-blue-700 disabled:opacity-40"
          >
            {submitLabel(pick, busy)}
          </button>
        </div>
      </div>

      <ProductVerifyModal productId={previewId} query={previewQuery} onClose={() => setPreviewId(null)} />
    </div>
  )
}
