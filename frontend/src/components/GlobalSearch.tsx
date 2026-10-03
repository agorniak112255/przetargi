import { useEffect, useMemo, useRef, useState, type KeyboardEvent } from 'react'
import { Link } from 'react-router-dom'
import { globalSearch, type GlobalSearchHit, type GlobalSearchResponse } from '../lib/api'

/** Opóźnienie po ostatnim znaku, zanim pójdzie żądanie. */
const DEBOUNCE_MS = 200

const MIN_LENGTH = 2

const MAX_LENGTH = 100

const LIST_ID = 'global-search-list'

/**
 * Znacznik w historii (location.state): strona otwarta z wyszukiwania — lista Produkty przepisuje wtedy frazę z adresu
 * do pola także przy tej samej frazie co poprzednio (Products.tsx sprawdza klucz fromGlobalSearch).
 */
const GLOBAL_SEARCH_STATE = { fromGlobalSearch: true } as const

type Option =
  | { kind: 'hit'; id: string; url: string; hit: GlobalSearchHit }
  | { kind: 'more'; id: string; url: string; label: string }

function stockLabel(stock: NonNullable<GlobalSearchHit['stock']>): string {
  const n = Number(stock.quantity)
  const qty = Number.isFinite(n) ? n.toLocaleString('pl-PL', { maximumFractionDigits: 3 }) : stock.quantity
  return `na stanie ${qty}${stock.unit ? ` ${stock.unit}` : ''}`
}

/**
 * Jedno pole wyszukiwania dla całej aplikacji (Ctrl+K albo Cmd+K, przycisk „Szukaj” w pasku bocznym) — GET /search.
 * Żądanie 200 ms po ostatnim znaku, poprzednie przerywane (spóźniona odpowiedź nie nadpisuje nowszej); strzałki
 * wybierają wynik, Enter otwiera, Escape zamyka. Grupy i wyniki przychodzą już przycięte do uprawnień użytkownika.
 * Enter „klika” wybrany link (a nie woła navigate()), więc strażniki niezapisanych zmian, które łapią kliknięcie <a>
 * w fazie przechwytywania, działają tak samo jak przy myszy. Okno jest modalne: Escape i Tab obsługuje nasłuch na
 * window w fazie przechwytywania — Escape nie dociera do okien pod spodem, a Tab nie wychodzi pod nakładkę.
 */
export function GlobalSearch({ open, onOpenChange }: { open: boolean; onOpenChange: (open: boolean) => void }) {
  const [query, setQuery] = useState('')
  const [data, setData] = useState<GlobalSearchResponse | null>(null)
  const [loading, setLoading] = useState(false)
  const [err, setErr] = useState('')
  const [active, setActive] = useState(0)
  const [retry, setRetry] = useState(0)
  const inputRef = useRef<HTMLInputElement | null>(null)
  const retryRef = useRef<HTMLButtonElement | null>(null)
  const openRef = useRef(open)
  const onOpenChangeRef = useRef(onOpenChange)

  useEffect(() => {
    openRef.current = open
    onOpenChangeRef.current = onOpenChange
  }, [open, onOpenChange])

  // Ctrl+K / Cmd+K — otwiera i zamyka z każdego miejsca aplikacji
  useEffect(() => {
    function onKey(e: globalThis.KeyboardEvent) {
      if ((e.ctrlKey || e.metaKey) && !e.altKey && !e.shiftKey && e.key.toLowerCase() === 'k') {
        e.preventDefault()
        onOpenChangeRef.current(!openRef.current)
      }
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [])

  // po otwarciu: fokus w polu z zaznaczonym poprzednim tekstem; po zamknięciu fokus wraca tam, skąd otwarto
  useEffect(() => {
    if (!open) return
    const opener = document.activeElement instanceof HTMLElement ? document.activeElement : null
    const input = inputRef.current
    input?.focus()
    input?.select()
    return () => {
      if (opener && document.contains(opener)) opener.focus()
    }
  }, [open])

  // okno modalne: Escape zamyka tylko wyszukiwanie (nie okno pod spodem, np. subskrypcję kalendarza), Tab i Shift+Tab
  // nie wychodzą poza okno: krążą między polem wyszukiwania a przyciskiem „Spróbuj ponownie”, gdy jest widoczny
  // (wyniki wybiera się strzałkami)
  useEffect(() => {
    if (!open) return
    function onKey(e: globalThis.KeyboardEvent) {
      if (e.key === 'Escape') {
        e.preventDefault()
        e.stopPropagation()
        onOpenChangeRef.current(false)
      } else if (e.key === 'Tab') {
        e.preventDefault()
        const stops = [inputRef.current, retryRef.current].filter((el): el is HTMLInputElement | HTMLButtonElement => el !== null)
        const at = stops.findIndex((el) => el === document.activeElement)
        const next = at < 0 ? 0 : (at + (e.shiftKey ? stops.length - 1 : 1)) % stops.length
        stops[next]?.focus()
      }
    }
    window.addEventListener('keydown', onKey, true)
    return () => window.removeEventListener('keydown', onKey, true)
  }, [open])

  const trimmed = query.trim()
  const tooShort = trimmed.length < MIN_LENGTH

  useEffect(() => {
    if (!open || tooShort) {
      setData(null)
      setLoading(false)
      setErr('')
      return
    }
    const controller = new AbortController()
    setLoading(true)
    setErr('')
    const timer = window.setTimeout(() => {
      globalSearch(trimmed, controller.signal)
        .then((res) => {
          if (controller.signal.aborted) return
          // klucz odpowiedzi = tekst wysłany z tej przeglądarki (serwer obcina spacje inaczej niż JavaScript)
          setData({ ...res, query: trimmed })
          setActive(0)
        })
        .catch((ex: unknown) => {
          if (controller.signal.aborted) return
          setErr(ex instanceof Error ? ex.message : 'Wyszukiwanie się nie udało.')
        })
        .finally(() => {
          if (!controller.signal.aborted) setLoading(false)
        })
    }, DEBOUNCE_MS)
    return () => {
      window.clearTimeout(timer)
      controller.abort()
    }
  }, [open, trimmed, tooShort, retry])

  // wyniki pasujące do wpisanego tekstu — stara odpowiedź nie miesza się z nowym pytaniem
  const current = data !== null && data.query === trimmed ? data : null

  const options = useMemo<Option[]>(() => {
    const list: Option[] = []
    for (const group of current?.groups ?? []) {
      for (const hit of group.items) {
        list.push({ kind: 'hit', id: `gs-${group.key}-${hit.id}`, url: hit.url, hit })
      }
      if (group.has_more && group.more_url) {
        list.push({ kind: 'more', id: `gs-${group.key}-more`, url: group.more_url, label: `Pokaż wszystkie: ${group.label.toLowerCase()}` })
      }
    }
    return list
  }, [current])

  // wybór strzałkami przewija listę do zaznaczonego wyniku
  const activeId = options.length === 0 ? null : options[Math.min(active, options.length - 1)].id
  useEffect(() => {
    if (activeId) document.getElementById(activeId)?.scrollIntoView({ block: 'nearest' })
  }, [activeId])

  if (!open) return null

  const activeIndex = options.length === 0 ? -1 : Math.min(active, options.length - 1)
  const activeOption = activeIndex >= 0 ? options[activeIndex] : null

  function close() {
    onOpenChange(false)
  }

  /** Enter = kliknięcie wybranego linku: przechodzi przez strażniki niezapisanych zmian jak kliknięcie myszą. */
  function openOption(option: Option) {
    document.getElementById(option.id)?.click()
  }

  function onInputKey(e: KeyboardEvent<HTMLInputElement>) {
    if (e.key === 'ArrowDown') {
      e.preventDefault()
      if (options.length > 0) setActive((activeIndex + 1) % options.length)
    } else if (e.key === 'ArrowUp') {
      e.preventDefault()
      if (options.length > 0) setActive((activeIndex - 1 + options.length) % options.length)
    } else if (e.key === 'Enter') {
      if (activeOption) {
        e.preventDefault()
        openOption(activeOption)
      }
    }
  }

  const groups = current?.groups ?? []
  const noAccess = current !== null && groups.length === 0
  const nothing = current !== null && groups.length > 0 && options.length === 0

  return (
    <div
      className="fixed inset-0 z-50 flex items-start justify-center bg-slate-900/40 p-4 pt-[10vh]"
      onMouseDown={(e) => {
        if (e.target === e.currentTarget) close()
      }}
    >
      <div
        role="dialog"
        aria-modal="true"
        aria-label="Szukaj w całej aplikacji"
        className="flex max-h-[80vh] w-full max-w-2xl flex-col overflow-hidden rounded-xl bg-white shadow-lg"
      >
        <div className="flex items-center gap-2 border-b border-slate-200 px-3 py-2">
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
            aria-activedescendant={activeOption?.id}
            aria-label="Szukaj w całej aplikacji"
            placeholder="Szukaj: produkt, kod, przetarg, zapytanie, klient"
            maxLength={MAX_LENGTH}
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            onKeyDown={onInputKey}
            className="min-w-0 flex-1 bg-transparent py-1.5 text-sm outline-none"
          />
          {loading && <span className="text-[11px] text-slate-500">Szukam…</span>}
          <kbd className="rounded border border-slate-300 px-1.5 py-0.5 text-[10px] text-slate-500">Esc</kbd>
        </div>

        <div className="min-h-0 flex-1 overflow-y-auto px-2 py-2 text-sm">
          {tooShort && (
            <p className="px-2 py-3 text-xs text-slate-500">
              Wpisz co najmniej {MIN_LENGTH} znaki. Szukam naraz w produktach (także po kodzie towaru z ERP XL),
              przetargach, zapytaniach i klientach — tylko w tym, do czego masz dostęp. Cen w wynikach nie ma.
            </p>
          )}
          {err && (
            <p className="px-2 py-2 text-xs text-red-600">
              {err}{' '}
              <button ref={retryRef} type="button" className="underline" onClick={() => setRetry((n) => n + 1)}>
                Spróbuj ponownie
              </button>
            </p>
          )}
          {noAccess && <p className="px-2 py-3 text-xs text-slate-500">Nie masz dostępu do żadnej z grup wyszukiwania.</p>}
          {nothing && <p className="px-2 py-3 text-xs text-slate-500">Nic nie znaleziono dla „{trimmed}”.</p>}

          <div id={LIST_ID} role="listbox" aria-label="Wyniki wyszukiwania">
            {groups.map((group) =>
              group.items.length === 0 ? null : (
                <div key={group.key} role="group" aria-labelledby={`gs-head-${group.key}`} className="mb-2">
                  <p
                    id={`gs-head-${group.key}`}
                    className="px-2 pb-1 pt-1 text-[11px] font-semibold uppercase tracking-wide text-slate-500"
                  >
                    {group.label}
                  </p>
                  {group.items.map((hit) => {
                    const id = `gs-${group.key}-${hit.id}`
                    const index = options.findIndex((o) => o.id === id)
                    const selected = index === activeIndex
                    return (
                      <Link
                        key={id}
                        id={id}
                        to={hit.url}
                        state={GLOBAL_SEARCH_STATE}
                        role="option"
                        aria-selected={selected}
                        tabIndex={-1}
                        onMouseMove={() => {
                          if (!selected) setActive(index)
                        }}
                        onClick={close}
                        className={`flex items-start gap-3 rounded-lg px-2 py-1.5 ${selected ? 'bg-blue-50' : 'hover:bg-slate-50'}`}
                      >
                        <span className="min-w-0 flex-1">
                          <span className="block truncate font-medium text-slate-900">{hit.title}</span>
                          {hit.subtitle && <span className="block truncate text-xs text-slate-500">{hit.subtitle}</span>}
                          {hit.detail && <span className="block truncate text-xs text-slate-500">{hit.detail}</span>}
                        </span>
                        {hit.stock && (
                          <span className="shrink-0 rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-700" title="Stan handlowy w ERP XL z ostatniej synchronizacji">
                            {stockLabel(hit.stock)}
                          </span>
                        )}
                        {hit.badge && (
                          <span className="shrink-0 rounded-full bg-blue-100 px-2 py-0.5 text-[11px] text-blue-800">{hit.badge}</span>
                        )}
                      </Link>
                    )
                  })}
                  {group.has_more && group.more_url && (() => {
                    const id = `gs-${group.key}-more`
                    const index = options.findIndex((o) => o.id === id)
                    const selected = index === activeIndex
                    return (
                      <Link
                        id={id}
                        to={group.more_url}
                        state={GLOBAL_SEARCH_STATE}
                        role="option"
                        aria-selected={selected}
                        tabIndex={-1}
                        onMouseMove={() => {
                          if (!selected) setActive(index)
                        }}
                        onClick={close}
                        className={`block rounded-lg px-2 py-1 text-xs text-blue-700 ${selected ? 'bg-blue-50' : 'hover:bg-slate-50'}`}
                      >
                        Pokaż wszystkie: {group.label.toLowerCase()} →
                      </Link>
                    )
                  })()}
                  {group.has_more && !group.more_url && (
                    <p className="px-2 py-0.5 text-[11px] text-slate-500">Jest więcej wyników — wpisz dokładniejszą frazę.</p>
                  )}
                </div>
              ),
            )}
          </div>
        </div>

        <p className="border-t border-slate-200 px-3 py-1.5 text-[11px] text-slate-500">
          Strzałki wybierają wynik, Enter otwiera, Escape zamyka. Skrót: Ctrl+K.
        </p>
      </div>
    </div>
  )
}
