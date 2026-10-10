import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import {
  fetchSourcePins,
  labelOf,
  MATCH_KIND_LABEL,
  SOURCE_KIND_LABEL,
  type IntakeView,
  type SourcePinRow,
  type SourcePinState,
  type SourcePinsResponse,
} from '../lib/priceListIntake'

const MAX_CANDIDATES = 5

/** Kandydat strony z importera — adres albo obiekt z adresem i tytułem; kształt zależy od importera. */
function candidateOf(value: unknown): { url: string | null; label: string } {
  if (typeof value === 'string') return { url: /^https?:\/\//i.test(value) ? value : null, label: value }
  if (value && typeof value === 'object') {
    const o = value as Record<string, unknown>
    const url = typeof o.url === 'string' ? o.url : null
    const title = typeof o.title === 'string' ? o.title : typeof o.label === 'string' ? o.label : null
    const why = typeof o.reason === 'string' ? o.reason : null
    const label = [title ?? url ?? JSON.stringify(value), why].filter(Boolean).join(' — ')
    return { url, label }
  }

  return { url: null, label: String(value) }
}

/** Strony, które importer rozważał dla karty bez strony (do sprawdzenia przez człowieka). */
export function CandidatesList({ candidates }: { candidates: unknown }) {
  const list = Array.isArray(candidates) ? candidates : candidates ? [candidates] : []
  if (list.length === 0) return <span className="text-slate-400">—</span>

  return (
    <ul className="space-y-0.5">
      {list.slice(0, MAX_CANDIDATES).map((c, i) => {
        const { url, label } = candidateOf(c)
        return (
          <li key={i} className="break-all">
            {url ? (
              <a className="text-blue-700 underline" href={url} target="_blank" rel="noopener noreferrer">
                {label}
              </a>
            ) : (
              label
            )}
          </li>
        )
      })}
      {list.length > MAX_CANDIDATES && <li className="text-slate-500">i {list.length - MAX_CANDIDATES} więcej</li>}
    </ul>
  )
}

type Props = {
  intake: IntakeView
  canReview: boolean
  onClose: () => void
}

/**
 * „Karty bez strony”: lista kart cennika, którym importer nie przypiął strony (GET source-pins?state=unresolved),
 * i druga zakładka z kartami z przypiętą stroną. Adres wskazuje się w „Do przeglądu” → „Wskaż właściwą stronę”.
 */
export function PriceListSourcePinsModal({ intake, canReview, onClose }: Props) {
  const [state, setState] = useState<SourcePinState>('unresolved')
  const [page, setPage] = useState(1)
  const [res, setRes] = useState<SourcePinsResponse | null>(null)
  const [loading, setLoading] = useState(true)
  const [err, setErr] = useState('')

  useEffect(() => {
    let cancelled = false
    setLoading(true)
    setErr('')
    fetchSourcePins(intake.id, state, page)
      .then((r) => {
        if (!cancelled) setRes(r)
      })
      .catch((ex) => {
        if (!cancelled) setErr(ex instanceof Error ? ex.message : 'Nie udało się wczytać kart')
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })

    return () => {
      cancelled = true
    }
  }, [intake.id, state, page])

  const rows: SourcePinRow[] = Array.isArray(res?.data) ? res.data : []
  const meta = res?.meta ?? {}
  const current = meta.current_page ?? meta.page ?? page
  const last = meta.last_page ?? (meta.total && meta.per_page ? Math.max(1, Math.ceil(meta.total / meta.per_page)) : current)
  const reviewHref = `/price-lists/review?price_list_id=${intake.id}&reason=source_unmapped`

  function tab(value: SourcePinState, label: string, count: number) {
    const active = state === value
    return (
      <button
        type="button"
        className={`rounded-full px-3 py-1 font-medium ${
          active ? 'bg-blue-600 text-white' : 'border border-blue-300 text-blue-700 hover:bg-blue-50'
        }`}
        aria-pressed={active}
        onClick={() => {
          if (active) return
          setState(value)
          setPage(1)
          setRes(null)
        }}
      >
        {label} ({count.toLocaleString('pl-PL')})
      </button>
    )
  }

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
      role="dialog"
      aria-modal="true"
      aria-labelledby="source-pins-title"
      onClick={onClose}
    >
      <div
        className="flex max-h-[90vh] w-full max-w-5xl flex-col rounded-xl bg-white p-4 text-xs shadow-lg"
        onClick={(e) => e.stopPropagation()}
      >
        <p id="source-pins-title" className="text-sm font-semibold text-slate-800">
          Strony kart — {intake.manufacturer} / {intake.version}
        </p>
        <p className="mt-1 rounded bg-sky-50 px-3 py-2 text-sky-900">
          Karta bez strony z importera nie dostaje opisu z internetu (obecny opis zostaje). Adres strony wyrobu wskazujesz
          w zakładce{' '}
          {canReview ? (
            <Link className="font-medium underline" to={reviewHref}>
              „Do przeglądu”
            </Link>
          ) : (
            '„Do przeglądu”'
          )}{' '}
          → „Wskaż właściwą stronę” (powód „Brak strony z importera — wskaż adres”). Adres wskazany ręcznie wygrywa z mapą
          importera.
        </p>
        <div className="mt-2 flex flex-wrap gap-2">
          {tab('unresolved', 'Bez strony', intake.pins?.unresolved ?? 0)}
          {tab('pinned', 'Z przypiętą stroną', intake.pins?.pinned ?? 0)}
        </div>

        <div className="mt-2 min-h-[10rem] flex-1 overflow-auto rounded border border-slate-200">
          {loading && <p className="p-3 text-slate-500">Ładowanie…</p>}
          {err && <p className="p-3 text-red-700">{err}</p>}
          {!loading && !err && rows.length === 0 && (
            <p className="p-3 text-slate-500">
              {state === 'unresolved' ? 'Wszystkie karty mają stronę z importera.' : 'Żadna karta nie ma jeszcze przypiętej strony.'}
            </p>
          )}
          {!loading && rows.length > 0 && (
            <table className="w-full text-left">
              <thead className="sticky top-0 bg-slate-50">
                <tr className="border-b text-slate-700">
                  <th className="p-1.5 font-semibold">Kod</th>
                  <th className="p-1.5 font-semibold">Nazwa</th>
                  {state === 'unresolved' ? (
                    <>
                      <th className="p-1.5 font-semibold">Dlaczego bez strony</th>
                      <th className="p-1.5 font-semibold">Strony do sprawdzenia</th>
                    </>
                  ) : (
                    <>
                      <th className="p-1.5 font-semibold">Strona</th>
                      <th className="p-1.5 font-semibold">Dopasowanie po</th>
                    </>
                  )}
                  <th className="p-1.5 font-semibold">Adres wskazany ręcznie</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => (
                  <tr key={row.product_id} className="border-b align-top">
                    <td className="p-1.5">
                      <Link className="font-mono text-blue-700 hover:underline" to={`/products/${row.product_id}`}>
                        {row.sku}
                      </Link>
                    </td>
                    <td className="p-1.5">{row.name}</td>
                    {state === 'unresolved' ? (
                      <>
                        <td className="p-1.5 text-amber-800">{row.unresolved_reason ?? '—'}</td>
                        <td className="p-1.5">
                          <CandidatesList candidates={row.candidates} />
                        </td>
                      </>
                    ) : (
                      <>
                        <td className="p-1.5">
                          {row.url ? (
                            <a className="break-all text-blue-700 underline" href={row.url} target="_blank" rel="noopener noreferrer">
                              {row.url}
                            </a>
                          ) : (
                            '—'
                          )}
                          {row.source_kind && (
                            <span className="block text-slate-500">{labelOf(SOURCE_KIND_LABEL, row.source_kind)}</span>
                          )}
                        </td>
                        <td className="p-1.5">
                          {labelOf(MATCH_KIND_LABEL, row.match_kind)}
                          {row.match_key && <span className="block font-mono text-slate-500">{row.match_key}</span>}
                        </td>
                      </>
                    )}
                    <td className="p-1.5">
                      {row.human_url ? (
                        <a className="break-all text-blue-700 underline" href={row.human_url} target="_blank" rel="noopener noreferrer">
                          {row.human_url}
                        </a>
                      ) : (
                        <span className="text-slate-400">—</span>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>

        <div className="mt-3 flex items-center justify-between gap-2">
          <div className="flex items-center gap-2">
            {last > 1 && (
              <>
                <button
                  type="button"
                  className="rounded border border-slate-300 px-2 py-1 disabled:opacity-50"
                  disabled={loading || current <= 1}
                  onClick={() => setPage((p) => Math.max(1, p - 1))}
                >
                  ← Poprzednia
                </button>
                <span className="text-slate-600">
                  Strona {current} z {last}
                </span>
                <button
                  type="button"
                  className="rounded border border-slate-300 px-2 py-1 disabled:opacity-50"
                  disabled={loading || current >= last}
                  onClick={() => setPage((p) => p + 1)}
                >
                  Następna →
                </button>
              </>
            )}
          </div>
          <button type="button" className="rounded border border-slate-300 px-3 py-1.5" onClick={onClose}>
            Zamknij
          </button>
        </div>
      </div>
    </div>
  )
}
