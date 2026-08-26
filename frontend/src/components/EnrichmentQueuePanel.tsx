import { useEffect, useState } from 'react'
import { api, parseActiveEnrichment, type EnrichmentBatch } from '../lib/api'

const SCOPE_LABEL: Record<string, string> = {
  product: 'Produkt',
  products: 'Produkty',
  price_list: 'Cennik',
}

type Props = {
  /** Odśwież listę produktów / cenników po zatrzymaniu */
  onChanged?: () => void
}

export function EnrichmentQueuePanel({ onChanged }: Props) {
  const [batches, setBatches] = useState<EnrichmentBatch[]>([])
  const [queuedProducts, setQueuedProducts] = useState(0)
  const [runningProducts, setRunningProducts] = useState(0)
  const [busyId, setBusyId] = useState<number | null>(null)
  const [stoppingAll, setStoppingAll] = useState(false)
  const [err, setErr] = useState('')
  const [msg, setMsg] = useState('')

  async function load() {
    try {
      const state = parseActiveEnrichment(await api<unknown>('/product-enrichment-batches/active'))
      setBatches(state.batches)
      setQueuedProducts(state.queued_products)
      setRunningProducts(state.running_products)
    } catch {
      /* zostaw ostatni postęp — chwilowy błąd API nie może schować paska */
    }
  }

  useEffect(() => {
    void load()
    const t = window.setInterval(() => void load(), 2500)
    return () => window.clearInterval(t)
  }, [])

  async function cancelBatch(batch: EnrichmentBatch) {
    if (
      !window.confirm(
        `Zatrzymać batch #${batch.id}? Usunie oczekujące joby z kolejki; bieżący produkt przerwie się przy najbliższym checkpointcie.`,
      )
    ) {
      return
    }
    setBusyId(batch.id)
    setErr('')
    setMsg('')
    try {
      const res = await api<{
        message: string
        batch: EnrichmentBatch
        removed_jobs: number
      }>(`/product-enrichment-batches/${batch.id}/cancel`, { method: 'POST', body: '{}' })
      setMsg(
        `${res.message} Usunięto jobów: ${res.removed_jobs}. OK ${res.batch.done}/${res.batch.total}.`,
      )
      await load()
      onChanged?.()
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Nie udało się zatrzymać batcha')
    } finally {
      setBusyId(null)
    }
  }

  async function stopAll() {
    if (
      !window.confirm(
        'Zatrzymać WSZYSTKIE pobierania opisów? Usunie joby z kolejki (także te schowane po aktualizacji) i przerwie workery.',
      )
    ) {
      return
    }
    setStoppingAll(true)
    setErr('')
    setMsg('')
    try {
      const res = await api<{
        message: string
        removed_jobs: number
        marked_products: number
        cancelled_batches: number
      }>('/product-enrichment-batches/stop-all', { method: 'POST', body: '{}' })
      setMsg(
        `${res.message} Joby: ${res.removed_jobs}, produkty: ${res.marked_products}, batche: ${res.cancelled_batches}.`,
      )
      await load()
      onChanged?.()
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Nie udało się zatrzymać pobierań')
    } finally {
      setStoppingAll(false)
    }
  }

  const ghostCount = queuedProducts + runningProducts
  if (batches.length === 0 && ghostCount === 0 && !msg && !err) {
    return null
  }

  return (
    <div className="mb-4 rounded-lg border border-amber-200 bg-amber-50/80 p-3">
      <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
        <p className="text-xs font-semibold text-amber-950">Aktywne joby enrichmentu</p>
        <div className="flex flex-wrap gap-1.5">
          <button
            type="button"
            onClick={() => void load()}
            className="rounded border border-amber-300 bg-white px-2 py-0.5 text-[11px] text-amber-900"
          >
            Odśwież
          </button>
          {(batches.length > 0 || ghostCount > 0) && (
            <button
              type="button"
              disabled={stoppingAll}
              onClick={() => void stopAll()}
              className="rounded border border-red-400 bg-red-600 px-2 py-0.5 text-[11px] font-medium text-white hover:bg-red-700 disabled:opacity-50"
            >
              {stoppingAll ? 'Zatrzymuję…' : 'Zatrzymaj wszystko'}
            </button>
          )}
        </div>
      </div>
      {err && <p className="mb-2 text-xs text-red-700">{err}</p>}
      {msg && <p className="mb-2 text-xs text-green-800">{msg}</p>}
      {batches.length === 0 && ghostCount > 0 && (
        <p className="mb-2 text-[11px] text-amber-950">
          Batch zniknął z listy, ale w bazie jest {queuedProducts} w kolejce i {runningProducts} w
          trakcie. To nie chowa procesów — kliknij <strong>Zatrzymaj wszystko</strong>, żeby je
          zabić.
        </p>
      )}
      {batches.length === 0 && ghostCount === 0 ? (
        <p className="text-[11px] text-amber-900/80">Brak aktywnych batchy.</p>
      ) : (
        <ul className="space-y-2">
          {batches.map((b) => (
            <li
              key={b.id}
              className="flex flex-wrap items-center justify-between gap-2 rounded border border-amber-200 bg-white px-2 py-1.5"
            >
              <div className="min-w-0 text-[11px] text-slate-700">
                <p className="font-medium">
                  #{b.id} · {SCOPE_LABEL[b.scope] ?? b.scope}
                  {b.scope_id ? ` #${b.scope_id}` : ''} · {b.status}
                </p>
                <p className="truncate text-slate-500" title={b.message ?? ''}>
                  {b.done + b.failed}/{b.total}
                  {b.current_sku ? ` · teraz: ${b.current_sku}` : ''}
                  {b.message ? ` · ${b.message}` : ''}
                </p>
                <div className="mt-1 h-1.5 w-40 overflow-hidden rounded bg-slate-200">
                  <div
                    className="h-full bg-amber-500 transition-all"
                    style={{ width: `${Math.max(4, b.progress_percent)}%` }}
                  />
                </div>
              </div>
              <button
                type="button"
                disabled={busyId === b.id}
                onClick={() => void cancelBatch(b)}
                className="rounded border border-red-300 bg-red-50 px-2 py-1 text-[11px] font-medium text-red-800 hover:bg-red-100 disabled:opacity-50"
              >
                {busyId === b.id ? 'Zatrzymuję…' : 'Zatrzymaj'}
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
