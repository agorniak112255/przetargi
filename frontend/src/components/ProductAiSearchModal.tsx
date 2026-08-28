import { useEffect, useState } from 'react'

type Busy = 'catalog' | 'web' | false

type Props = {
  open: boolean
  busy: Busy
  error?: string
  initialQuery?: string
  onClose: () => void
  onSearch: (query: string, web: boolean) => void
}

export function ProductAiSearchModal({
  open,
  busy,
  error = '',
  initialQuery = '',
  onClose,
  onSearch,
}: Props) {
  const [query, setQuery] = useState(initialQuery)

  useEffect(() => {
    if (!open) return
    setQuery(initialQuery)
  }, [open, initialQuery])

  useEffect(() => {
    if (!open) return
    function onKey(e: KeyboardEvent) {
      if (e.key === 'Escape' && !busy) onClose()
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [open, busy, onClose])

  if (!open) return null

  const tooShort = query.trim().length < 3

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/55 p-4"
      role="dialog"
      aria-modal="true"
      aria-labelledby="ai-search-title"
      onClick={() => {
        if (!busy) onClose()
      }}
    >
      <div
        className="relative w-full max-w-lg overflow-hidden rounded-2xl bg-white shadow-xl"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="bg-gradient-to-r from-indigo-600 to-violet-600 px-5 py-4 text-white">
          <p id="ai-search-title" className="text-base font-semibold">
            Szukanie AI
          </p>
          <p className="mt-1 text-xs text-indigo-100">
            Opisz zastosowanie, substancję albo normę — model przeszuka opisy w katalogu.
          </p>
        </div>

        {busy && (
          <div className="absolute inset-0 z-10 flex flex-col items-center justify-center bg-white/90">
            <span className="inline-block h-8 w-8 animate-spin rounded-full border-4 border-indigo-600 border-t-transparent" />
            <p className="mt-3 text-sm font-semibold text-indigo-900">
              {busy === 'web' ? 'Szukam w internecie…' : 'Szukam w katalogu…'}
            </p>
            <p className="mt-1 text-xs text-slate-500">Nie zamykaj okna</p>
          </div>
        )}

        <div className="space-y-3 px-5 py-4">
          <label className="block text-xs font-medium text-slate-600">
            Wymaganie
            <textarea
              autoFocus
              rows={4}
              disabled={Boolean(busy)}
              className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-200"
              placeholder="np. rękawice do pieca szkła 500°C, EN 407"
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === 'Enter' && !e.shiftKey && !tooShort && !busy) {
                  e.preventDefault()
                  onSearch(query.trim(), false)
                }
              }}
            />
          </label>
          {error && <p className="text-xs text-red-600">{error}</p>}
          <div className="grid grid-cols-2 gap-2">
            <button
              type="button"
              disabled={Boolean(busy) || tooShort}
              onClick={() => onSearch(query.trim(), false)}
              className="rounded-lg bg-indigo-600 px-3 py-2.5 text-xs font-semibold text-white hover:bg-indigo-700 disabled:opacity-50"
            >
              Szukaj w katalogu
            </button>
            <button
              type="button"
              disabled={Boolean(busy) || tooShort}
              onClick={() => onSearch(query.trim(), true)}
              className="rounded-lg bg-red-600 px-3 py-2.5 text-xs font-semibold text-white hover:bg-red-700 disabled:opacity-50"
            >
              AI Internet
            </button>
          </div>
          <p className="text-center text-[11px] text-slate-400">
            Enter = katalog · Shift+Enter = nowa linia · min. 3 znaki
          </p>
        </div>

        <div className="border-t border-slate-100 px-5 py-3 text-right">
          <button
            type="button"
            disabled={Boolean(busy)}
            onClick={onClose}
            className="rounded border border-slate-300 px-3 py-1.5 text-xs hover:bg-slate-50 disabled:opacity-50"
          >
            Anuluj
          </button>
        </div>
      </div>
    </div>
  )
}
