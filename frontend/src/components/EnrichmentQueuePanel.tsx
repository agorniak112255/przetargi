import { useEffect, useState } from 'react'
import { EnrichmentBatchLogModal } from './EnrichmentBatchLogModal'
import {
  api,
  enrichmentPriceListHref,
  enrichmentProductHref,
  parseActiveEnrichment,
  type EnrichmentBatch,
} from '../lib/api'

const SCOPE_LABEL: Record<string, string> = {
  product: 'Produkt',
  products: 'Produkty',
  price_list: 'Cennik',
}

type Props = {
  /** Odśwież listę produktów / cenników po zatrzymaniu */
  onChanged?: () => void
}

function EnrichmentJobRow({
  batch,
  busy,
  onCancel,
  onLog,
}: {
  batch: EnrichmentBatch
  busy: boolean
  onCancel?: () => void
  onLog: () => void
}) {
  const priceHref = enrichmentPriceListHref(batch)
  const productHref = enrichmentProductHref(batch)
  const header = (
    <>
      #{batch.id} · {SCOPE_LABEL[batch.scope] ?? batch.scope}
      {batch.scope_id ? ` #${batch.scope_id}` : ''}
      {batch.manufacturer ? ` · ${batch.manufacturer}` : ''} · {batch.status}
    </>
  )

  return (
    <li className="flex flex-wrap items-center justify-between gap-2 rounded border border-amber-200 bg-white px-2 py-1.5">
      <div className="min-w-0 text-[11px] text-slate-700">
        {priceHref ? (
          <a
            href={priceHref}
            target="_blank"
            rel="noopener noreferrer"
            className="block font-medium text-blue-800 hover:underline"
            title={`Cennik: ${batch.manufacturer}`}
          >
            {header}
          </a>
        ) : (
          <p className="font-medium">{header}</p>
        )}
        {batch.manufacturer && <p className="text-slate-600">Producent: {batch.manufacturer}</p>}
        <p className="truncate text-slate-500" title={batch.message ?? ''}>
          {batch.done + batch.failed}/{batch.total}
          {batch.current_sku ? (
            <>
              {' · teraz: '}
              {productHref ? (
                <a
                  href={productHref}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="font-medium text-blue-700 hover:underline"
                  title={`Produkt ${batch.current_sku}`}
                >
                  {batch.current_sku}
                </a>
              ) : (
                batch.current_sku
              )}
            </>
          ) : null}
          {batch.message ? ` · ${batch.message}` : ''}
        </p>
        <div className="mt-1 h-1.5 w-40 overflow-hidden rounded bg-slate-200">
          <div
            className="h-full bg-amber-500 transition-all"
            style={{ width: `${Math.max(4, batch.progress_percent)}%` }}
          />
        </div>
      </div>
      <div className="flex shrink-0 flex-wrap gap-1">
        <button
          type="button"
          onClick={onLog}
          className="rounded border border-slate-300 bg-white px-2 py-1 text-[11px] font-medium text-slate-800 hover:bg-slate-50"
        >
          Log produktów
        </button>
        {onCancel ? (
          <button
            type="button"
            disabled={busy}
            onClick={onCancel}
            className="rounded border border-red-300 bg-red-50 px-2 py-1 text-[11px] font-medium text-red-800 hover:bg-red-100 disabled:opacity-50"
          >
            {busy ? 'Zatrzymuję…' : 'Zatrzymaj'}
          </button>
        ) : null}
      </div>
    </li>
  )
}

export function EnrichmentQueuePanel({ onChanged }: Props) {
  const [batches, setBatches] = useState<EnrichmentBatch[]>([])
  const [recent, setRecent] = useState<EnrichmentBatch[]>([])
  const [queuedProducts, setQueuedProducts] = useState(0)
  const [runningProducts, setRunningProducts] = useState(0)
  const [busyId, setBusyId] = useState<number | null>(null)
  const [stoppingAll, setStoppingAll] = useState(false)
  const [logBatch, setLogBatch] = useState<EnrichmentBatch | null>(null)
  const [err, setErr] = useState('')
  const [msg, setMsg] = useState('')

  async function load() {
    try {
      const state = parseActiveEnrichment(await api<unknown>('/product-enrichment-batches/active'))
      setBatches(state.batches)
      setRecent(state.recent)
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
  if (batches.length === 0 && recent.length === 0 && ghostCount === 0 && !msg && !err) {
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
            <EnrichmentJobRow
              key={b.id}
              batch={b}
              busy={busyId === b.id}
              onCancel={() => void cancelBatch(b)}
              onLog={() => setLogBatch(b)}
            />
          ))}
        </ul>
      )}
      {recent.length > 0 && (
        <div className="mt-3">
          <p className="mb-1 text-[11px] font-medium text-amber-950">Ostatnie joby</p>
          <ul className="space-y-2">
            {recent.map((b) => (
              <EnrichmentJobRow
                key={b.id}
                batch={b}
                busy={false}
                onLog={() => setLogBatch(b)}
              />
            ))}
          </ul>
        </div>
      )}
      <EnrichmentBatchLogModal batch={logBatch} onClose={() => setLogBatch(null)} />
    </div>
  )
}
