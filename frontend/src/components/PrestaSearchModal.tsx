import { useEffect, useState } from 'react'
import { api } from '../lib/api'

export type PrestaCandidate = {
  presta_id: number
  method: string
  score: number
  action: 'auto' | 'review'
  reference: string
  ean13: string
  name: string
  manufacturer: string
  url: string
  description_preview: string
}

export type PrestaSearchResult = {
  product_id: number
  sku: string
  configured: boolean
  candidates: PrestaCandidate[]
  auto: PrestaCandidate | null
}

type Props = {
  open: boolean
  title?: string
  items: PrestaSearchResult[]
  loading?: boolean
  error?: string
  onClose: () => void
  onApplied: () => void
}

const METHOD_LABEL: Record<string, string> = {
  ean: 'EAN',
  reference: 'SKU = symbol',
  fuzzy_model: 'Model (literówka OK)',
  name: 'Nazwa — do akceptacji',
  manual: 'Ręcznie',
}

export function PrestaSearchModal({
  open,
  title = 'Wyszukiwanie w Presta',
  items,
  loading = false,
  error = '',
  onClose,
  onApplied,
}: Props) {
  const [busyId, setBusyId] = useState<string | null>(null)
  const [localErr, setLocalErr] = useState('')
  const [localMsg, setLocalMsg] = useState('')
  const [force, setForce] = useState(false)

  const importable = items
    .map((item) => {
      const cand = item.auto ?? item.candidates[0] ?? null
      return cand ? { product_id: item.product_id, cand } : null
    })
    .filter((row): row is { product_id: number; cand: PrestaCandidate } => row !== null)

  useEffect(() => {
    if (!open) return
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose()
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [open, onClose])

  if (!open) return null

  async function apply(productId: number, cand: PrestaCandidate) {
    setBusyId(`${productId}:${cand.presta_id}`)
    setLocalErr('')
    setLocalMsg('')
    try {
      await api(`/products/${productId}/presta-apply`, {
        method: 'POST',
        body: JSON.stringify({
          presta_id: cand.presta_id,
          force,
          method: cand.method,
          score: cand.score,
        }),
      })
      onApplied()
    } catch (ex) {
      setLocalErr(ex instanceof Error ? ex.message : 'Nie udało się uzupełnić ze sklepu')
    } finally {
      setBusyId(null)
    }
  }

  async function applyAll() {
    if (importable.length === 0) return
    setBusyId('all')
    setLocalErr('')
    setLocalMsg('')
    try {
      const res = await api<{ applied: number; skipped: number; failed: number; errors: string[] }>(
        '/products/presta-apply-batch',
        {
          method: 'POST',
          body: JSON.stringify({
            force,
            items: importable.map(({ product_id, cand }) => ({
              product_id,
              presta_id: cand.presta_id,
              method: cand.method,
              score: cand.score,
            })),
          }),
        },
      )
      const parts = [`Zaimportowano ${res.applied}`]
      if (res.skipped > 0) parts.push(`pominięto ${res.skipped} (już mają opis)`)
      if (res.failed > 0) parts.push(`błędy ${res.failed}`)
      setLocalMsg(parts.join(' · '))
      if (res.errors.length > 0) {
        setLocalErr(res.errors.join(' '))
      }
      onApplied()
    } catch (ex) {
      setLocalErr(ex instanceof Error ? ex.message : 'Nie udało się zaimportować wszystkich')
    } finally {
      setBusyId(null)
    }
  }

  return (
    <div className="fixed inset-0 z-50 flex items-start justify-center bg-black/40 p-4 pt-10">
      <div className="max-h-[85vh] w-full max-w-3xl overflow-auto rounded-xl bg-white p-4 shadow-lg">
        <div className="mb-3 flex items-start justify-between gap-3">
          <div>
            <p className="text-sm font-semibold text-slate-900">{title}</p>
            <p className="mt-1 text-xs text-slate-500">
              Osobny cykl od „Pobierz” / AI. Ceny ze sklepu nie są kopiowane. Słabe trafienia
              wymagają akceptacji.
            </p>
          </div>
          <div className="flex shrink-0 gap-2">
            <button
              type="button"
              disabled={busyId !== null || loading || importable.length === 0}
              onClick={() => void applyAll()}
              className="rounded bg-emerald-700 px-3 py-1.5 text-xs text-white disabled:opacity-50"
              title="Dla każdego SKU zapisuje najlepszą kartę (nie wszystkie warianty naraz)"
            >
              {busyId === 'all' ? 'Import…' : `Zaimportuj wszystkie${importable.length > 0 ? ` (${importable.length})` : ''}`}
            </button>
            <button type="button" onClick={onClose} className="rounded border px-2 py-1 text-xs">
              Zamknij
            </button>
          </div>
        </div>

        {localMsg && (
          <p className="mb-2 rounded bg-emerald-50 px-3 py-2 text-xs text-emerald-800">{localMsg}</p>
        )}
        {(error || localErr) && (
          <p className="mb-2 rounded bg-red-50 px-3 py-2 text-xs text-red-700">{error || localErr}</p>
        )}

        <label className="mb-3 flex items-center gap-2 text-xs text-slate-600">
          <input type="checkbox" checked={force} onChange={(e) => setForce(e.target.checked)} />
          Nadpisz istniejący opis i dociągnij zdjęcia ponownie
        </label>

        {loading && <p className="text-sm text-slate-500">Szukam w Preście…</p>}

        {!loading && items.length === 0 && (
          <p className="text-sm text-slate-500">Brak wyników do pokazania.</p>
        )}

        {items.map((item) => (
          <div key={item.product_id} className="mb-4 rounded-lg border border-slate-200 p-3">
            <p className="text-xs font-semibold text-slate-800">
              Cennik: <code>{item.sku}</code>
            </p>
            {!item.configured && (
              <p className="mt-1 text-xs text-amber-800">
                Sklep nie jest skonfigurowany — Administracja → Sklep Presta.
              </p>
            )}
            {item.configured && item.candidates.length === 0 && (
              <p className="mt-1 text-xs text-slate-500">Brak karty w sklepie dla tego SKU.</p>
            )}
            <div className="mt-2 space-y-2">
              {item.candidates.map((c) => (
                <div
                  key={c.presta_id}
                  className={`rounded border px-3 py-2 ${
                    c.action === 'auto' ? 'border-emerald-200 bg-emerald-50/60' : 'border-amber-200 bg-amber-50/50'
                  }`}
                >
                  <div className="flex flex-wrap items-start justify-between gap-2">
                    <div className="min-w-0 flex-1">
                      <p className="text-sm font-medium text-slate-900">
                        {c.reference || '—'} · {c.name}
                      </p>
                      <p className="text-[11px] text-slate-600">
                        {c.manufacturer || '—'} · {METHOD_LABEL[c.method] ?? c.method} · {c.score}%
                        {c.action === 'review' ? ' · do akceptacji' : ' · pewne'}
                      </p>
                      {c.description_preview && (
                        <p className="mt-1 line-clamp-2 text-[11px] text-slate-500">{c.description_preview}</p>
                      )}
                      {c.url && (
                        <a
                          href={c.url}
                          target="_blank"
                          rel="noreferrer"
                          className="mt-1 inline-block text-[11px] text-emerald-800 underline"
                        >
                          Karta w sklepie
                        </a>
                      )}
                    </div>
                    <button
                      type="button"
                      disabled={busyId !== null}
                      onClick={() => void apply(item.product_id, c)}
                      className="rounded bg-emerald-700 px-2 py-1 text-[11px] text-white disabled:opacity-50"
                    >
                      {busyId === `${item.product_id}:${c.presta_id}` ? 'Zapis…' : 'Uzupełnij z tej karty'}
                    </button>
                  </div>
                </div>
              ))}
            </div>
          </div>
        ))}
      </div>
    </div>
  )
}
