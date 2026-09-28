import { Fragment, useCallback, useEffect, useRef, useState } from 'react'
import { PriceListsTabs } from '../components/PriceListsTabs'
import { api } from '../lib/api'
import { plural } from '../lib/plural'
import { currencyLabel, formatDateTime, formatPrice } from '../lib/priceChange'

type ExclusionStatus = 'active' | 'restored' | 'all'

type ExclusionPosition = {
  id: number
  source_key: string
  /** np. „B2B P4S”, „Cennik z pliku CEDERROTH”. */
  source_label: string
  /** 'sku' = pozycja rozpoznawana po SKU karty (źródło bez własnego kodu pozycji). */
  match_kind: 'position' | 'sku'
  position_key: string
  remote_sku: string | null
  position_label: string | null
  hits: number
  last_hit_at: string | null
  restored_at: string | null
  restored_by: string | null
}

type ExclusionGroup = {
  deletion_id: string
  product: {
    id: number
    sku: string
    name: string
    manufacturer: string | null
    purchase_price: string | number | null
    catalog_price_net: string | number | null
    currency: string | null
  }
  deleted_at: string
  deleted_by: string | null
  active_count: number
  positions: ExclusionPosition[]
}

type ExclusionPage = {
  data: ExclusionGroup[]
  meta: { current_page: number; last_page: number; total: number }
}

const STATUS_OPTIONS: { value: ExclusionStatus; label: string }[] = [
  { value: 'active', label: 'Aktywne' },
  { value: 'restored', label: 'Przywrócone' },
  { value: 'all', label: 'Wszystkie' },
]

const SEARCH_DELAY_MS = 350

const RESTORE_NOTE =
  'Przy najbliższym imporcie cennika lub synchronizacji B2B wróci jako nowa karta (bez dawnych zdjęć i historii).'

function priceLine(p: ExclusionGroup['product']): string | null {
  const cur = currencyLabel(p.currency)
  const parts: string[] = []
  if (p.purchase_price !== null && p.purchase_price !== '') parts.push(`zakup ${formatPrice(p.purchase_price)} ${cur}`)
  if (p.catalog_price_net !== null && p.catalog_price_net !== '') {
    parts.push(`katalogowa ${formatPrice(p.catalog_price_net)} ${cur}`)
  }
  return parts.length > 0 ? parts.join(' · ') : null
}

function hitsText(pos: ExclusionPosition): string {
  if (pos.hits <= 0) return 'jeszcze nie pominięto'
  const last = pos.last_hit_at ? ` (ostatnio ${formatDateTime(pos.last_hit_at)})` : ''
  return `pominięto ${pos.hits.toLocaleString('pl-PL')}×${last}`
}

/**
 * Cenniki → „Usunięte z pominięciem”: karty usunięte z opcją pomijania przy imporcie i ich pozycje ze źródeł.
 * Przywrócenie pozycji zdejmuje pominięcie — najbliższy import albo synchronizacja założy kartę od nowa.
 */
export function PriceListsExcluded() {
  const [q, setQ] = useState('')
  const [debouncedQ, setDebouncedQ] = useState('')
  const [status, setStatus] = useState<ExclusionStatus>('active')
  const [page, setPage] = useState(1)
  const [result, setResult] = useState<ExclusionPage | null>(null)
  const [loading, setLoading] = useState(false)
  const [err, setErr] = useState('')
  const [msg, setMsg] = useState('')
  /** Klucz przywracanej grupy lub pozycji ('g:<deletion_id>' / 'p:<id>'); null = nic w toku. */
  const [restoring, setRestoring] = useState<string | null>(null)
  const requestSeq = useRef(0)

  useEffect(() => {
    const timer = window.setTimeout(() => {
      setDebouncedQ(q.trim())
      setPage(1)
    }, SEARCH_DELAY_MS)
    return () => window.clearTimeout(timer)
  }, [q])

  const load = useCallback(async () => {
    const seq = ++requestSeq.current
    setLoading(true)
    setErr('')
    try {
      const params = new URLSearchParams()
      params.set('status', status)
      params.set('page', String(page))
      if (debouncedQ) params.set('q', debouncedQ)
      const res = await api<ExclusionPage>(`/import-exclusions?${params.toString()}`)
      if (seq !== requestSeq.current) return
      // Po przywróceniu ostatniej pozycji strona mogła zniknąć — cofamy na ostatnią istniejącą.
      if (res.data.length === 0 && page > 1 && res.meta.last_page < page) {
        setPage(Math.max(1, res.meta.last_page))
        return
      }
      setResult(res)
    } catch (ex) {
      if (seq !== requestSeq.current) return
      setErr(ex instanceof Error ? ex.message : 'Nie udało się wczytać listy')
    } finally {
      if (seq === requestSeq.current) setLoading(false)
    }
  }, [status, page, debouncedQ])

  useEffect(() => {
    void load()
  }, [load])

  async function restore(ids: number[], key: string, question: string) {
    if (ids.length === 0 || restoring !== null) return
    if (!window.confirm(`${question}\n\n${RESTORE_NOTE}`)) return
    setRestoring(key)
    setErr('')
    setMsg('')
    try {
      const res = await api<{ message: string; restored: number }>('/import-exclusions/restore', {
        method: 'POST',
        body: JSON.stringify({ ids }),
      })
      setMsg(res.message)
      await load()
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Nie udało się przywrócić')
    } finally {
      setRestoring(null)
    }
  }

  const groups = result?.data ?? []
  const meta = result?.meta ?? null

  return (
    <div>
      <PriceListsTabs />
      <h1 className="text-xl font-semibold">Usunięte z pominięciem</h1>
      <p className="mb-3 max-w-4xl text-xs text-slate-500">
        Karty usunięte z opcją pomijania. Import cennika i synchronizacja B2B nie zakładają ich od nowa. Przywrócona
        pozycja wróci przy najbliższym imporcie lub synchronizacji jako nowa karta (bez dawnych zdjęć i historii).
      </p>

      <div className="mb-3 flex flex-wrap items-center gap-2">
        <input
          type="search"
          value={q}
          onChange={(e) => setQ(e.target.value)}
          placeholder="Szukaj: SKU, nazwa, producent, kod u dostawcy…"
          className="w-full max-w-sm rounded border border-slate-300 bg-white px-2 py-1.5 text-sm"
        />
        <div className="flex overflow-hidden rounded border border-slate-300 text-xs" role="group" aria-label="Stan">
          {STATUS_OPTIONS.map((opt) => (
            <button
              key={opt.value}
              type="button"
              aria-pressed={status === opt.value}
              onClick={() => {
                setStatus(opt.value)
                setPage(1)
              }}
              className={`border-l border-slate-300 px-3 py-1.5 first:border-l-0 ${
                status === opt.value ? 'bg-blue-600 text-white' : 'bg-white text-slate-700 hover:bg-slate-50'
              }`}
            >
              {opt.label}
            </button>
          ))}
        </div>
        {meta && (
          <span className="text-xs text-slate-500">
            {meta.total.toLocaleString('pl-PL')} {plural(meta.total, 'karta', 'karty', 'kart')}
          </span>
        )}
        {loading && <span className="text-xs text-slate-400">Ładowanie…</span>}
      </div>

      {msg && <p className="mb-2 rounded bg-green-50 px-3 py-2 text-xs text-green-800">{msg}</p>}
      {err && <p className="mb-2 rounded bg-red-50 px-3 py-2 text-xs text-red-700">{err}</p>}

      <div className="overflow-x-auto rounded-xl bg-white shadow-sm">
        <table className={`min-w-full text-left text-xs ${loading && result ? 'opacity-60' : ''}`}>
          <thead className="border-b bg-slate-50 text-slate-500">
            <tr>
              <th className="px-3 py-2">Źródło</th>
              <th className="px-3 py-2">Kod pozycji</th>
              <th className="px-3 py-2">U dostawcy</th>
              <th className="px-3 py-2">Pomijanie</th>
              <th className="px-3 py-2">Stan</th>
              <th className="px-3 py-2" />
            </tr>
          </thead>
          <tbody>
            {groups.length === 0 && (
              <tr>
                <td colSpan={6} className="px-3 py-6 text-center text-sm text-slate-500">
                  {loading
                    ? 'Ładowanie…'
                    : debouncedQ
                      ? 'Nic nie pasuje do wyszukiwania.'
                      : status === 'active'
                        ? 'Brak kart pomijanych przy imporcie.'
                        : status === 'restored'
                          ? 'Brak przywróconych pozycji.'
                          : 'Brak kart usuniętych z pominięciem.'}
                </td>
              </tr>
            )}
            {groups.map((g) => {
              const activeIds = g.positions.filter((p) => p.restored_at === null).map((p) => p.id)
              const groupKey = `g:${g.deletion_id}`
              const prices = priceLine(g.product)
              return (
                <Fragment key={g.deletion_id}>
                  <tr className="border-t-2 border-slate-200 bg-slate-50/70 align-top">
                    <td colSpan={5} className="px-3 py-2">
                      <div className="flex flex-wrap items-baseline gap-x-2">
                        <span className="font-mono text-slate-700">{g.product.sku}</span>
                        <span className="text-sm font-medium text-slate-900">{g.product.name}</span>
                        {g.product.manufacturer && <span className="text-slate-500">{g.product.manufacturer}</span>}
                      </div>
                      <div className="mt-0.5 text-slate-500">
                        Usunięto {formatDateTime(g.deleted_at)}
                        {g.deleted_by ? ` przez ${g.deleted_by}` : ''}
                        {prices ? ` · ${prices}` : ''}
                        {' · '}
                        {g.active_count > 0
                          ? `pomijane: ${g.active_count} ${plural(g.active_count, 'pozycja', 'pozycje', 'pozycji')}`
                          : 'nic nie jest już pomijane'}
                      </div>
                    </td>
                    <td className="px-3 py-2 text-right">
                      {g.active_count > 0 && activeIds.length > 0 && (
                        <button
                          type="button"
                          disabled={restoring !== null}
                          onClick={() =>
                            void restore(
                              activeIds,
                              groupKey,
                              `Przywrócić ${activeIds.length === 1 ? 'pozycję' : `wszystkie pozycje (${activeIds.length})`} karty ${g.product.sku}?`,
                            )
                          }
                          className="whitespace-nowrap rounded border border-blue-300 px-2 py-1 text-[11px] text-blue-700 hover:bg-blue-50 disabled:opacity-50"
                          title="Zdejmuje pomijanie ze wszystkich aktywnych pozycji tej karty"
                        >
                          {restoring === groupKey ? 'Przywracam…' : 'Przywróć wszystkie'}
                        </button>
                      )}
                    </td>
                  </tr>
                  {g.positions.length === 0 && (
                    <tr className="border-t border-slate-100">
                      <td colSpan={6} className="px-3 py-2 text-slate-400">
                        Brak pozycji w tym widoku.
                      </td>
                    </tr>
                  )}
                  {g.positions.map((pos) => {
                    const posKey = `p:${pos.id}`
                    const active = pos.restored_at === null
                    return (
                      <tr key={pos.id} className="border-t border-slate-100 align-top">
                        <td className="px-3 py-1.5 pl-6 text-slate-700">{pos.source_label}</td>
                        <td className="px-3 py-1.5">
                          <span className="font-mono text-slate-800">{pos.position_key}</span>
                          {pos.match_kind === 'sku' && <span className="ml-1 text-slate-500">po SKU karty</span>}
                        </td>
                        <td className="px-3 py-1.5 text-slate-700">
                          {pos.remote_sku && <span className="font-mono">{pos.remote_sku}</span>}
                          {pos.remote_sku && pos.position_label && ' · '}
                          {pos.position_label}
                          {!pos.remote_sku && !pos.position_label && <span className="text-slate-400">—</span>}
                        </td>
                        <td className="whitespace-nowrap px-3 py-1.5 text-slate-600">{hitsText(pos)}</td>
                        <td className="px-3 py-1.5">
                          {active ? (
                            <span className="rounded bg-amber-100 px-1.5 py-0.5 text-amber-900">aktywna</span>
                          ) : (
                            <span className="text-slate-600">
                              przywrócona {formatDateTime(pos.restored_at ?? '')}
                              {pos.restored_by ? ` przez ${pos.restored_by}` : ''}
                            </span>
                          )}
                        </td>
                        <td className="px-3 py-1.5 text-right">
                          {active && (
                            <button
                              type="button"
                              disabled={restoring !== null}
                              onClick={() =>
                                void restore(
                                  [pos.id],
                                  posKey,
                                  `Przywrócić pozycję ${pos.position_key} (${pos.source_label}) karty ${g.product.sku}?`,
                                )
                              }
                              className="whitespace-nowrap rounded border border-slate-300 px-2 py-1 text-[11px] text-slate-700 hover:bg-slate-50 disabled:opacity-50"
                              title="Zdejmuje pomijanie tej pozycji"
                            >
                              {restoring === posKey ? 'Przywracam…' : 'Przywróć'}
                            </button>
                          )}
                        </td>
                      </tr>
                    )
                  })}
                </Fragment>
              )
            })}
          </tbody>
        </table>
      </div>

      {meta && meta.last_page > 1 && (
        <div className="mt-3 flex items-center gap-3 text-sm">
          <button
            type="button"
            disabled={loading || page <= 1}
            className="rounded border px-2 py-1 disabled:opacity-40"
            onClick={() => setPage((prev) => Math.max(1, prev - 1))}
          >
            ← Poprzednia
          </button>
          <span className="text-slate-600">
            Strona {meta.current_page} / {meta.last_page}
          </span>
          <button
            type="button"
            disabled={loading || page >= meta.last_page}
            className="rounded border px-2 py-1 disabled:opacity-40"
            onClick={() => setPage((prev) => prev + 1)}
          >
            Następna →
          </button>
        </div>
      )}
    </div>
  )
}
