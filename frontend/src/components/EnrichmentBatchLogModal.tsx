import { useEffect, useMemo, useState } from 'react'
import {
  api,
  appHref,
  type EnrichmentBatch,
  type EnrichmentBatchItem,
  type EnrichmentBatchLog,
} from '../lib/api'

const STATUS_LABEL: Record<string, string> = {
  failed: 'Błąd',
  manual: 'Wpisz ręcznie',
  running: 'W trakcie',
  queued: 'W kolejce',
  done: 'OK',
  skipped: 'Pominięty',
  cancelled: 'Anulowany',
}

const STATUS_CLASS: Record<string, string> = {
  failed: 'bg-red-100 text-red-800',
  manual: 'bg-amber-100 text-amber-900',
  running: 'bg-sky-100 text-sky-800',
  queued: 'bg-slate-100 text-slate-700',
  done: 'bg-green-100 text-green-800',
  skipped: 'bg-slate-100 text-slate-500',
  cancelled: 'bg-slate-200 text-slate-600',
}

type Props = {
  batch: EnrichmentBatch | null
  onClose: () => void
}

export function EnrichmentBatchLogModal({ batch, onClose }: Props) {
  const [sort, setSort] = useState<'status' | 'updated'>('status')
  const [status, setStatus] = useState('')
  const [log, setLog] = useState<EnrichmentBatchLog | null>(null)
  const [err, setErr] = useState('')
  const [loading, setLoading] = useState(false)

  async function load() {
    if (batch == null) {
      return
    }
    const qs = new URLSearchParams({ sort })
    if (status !== '') {
      qs.set('status', status)
    }
    setLoading(true)
    setErr('')
    try {
      setLog(
        await api<EnrichmentBatchLog>(`/product-enrichment-batches/${batch.id}/items?${qs}`),
      )
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Nie udało się pobrać logu')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    if (batch == null) {
      setLog(null)
      return
    }
    void load()
    const live = batch.status === 'queued' || batch.status === 'running'
    if (!live) {
      return
    }
    const t = window.setInterval(() => void load(), 3000)
    return () => window.clearInterval(t)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [batch?.id, sort, status, batch?.status])

  useEffect(() => {
    if (batch == null) {
      return
    }
    function onKey(e: KeyboardEvent) {
      if (e.key === 'Escape') {
        onClose()
      }
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [batch, onClose])

  const filters = useMemo(() => {
    const counts = log?.counts ?? {}
    return ['', 'failed', 'manual', 'running', 'queued', 'done', 'skipped', 'cancelled'].filter(
      (key) => key === '' || (counts[key] ?? 0) > 0 || key === status,
    )
  }, [log, status])

  if (batch == null) {
    return null
  }

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
      role="dialog"
      aria-modal="true"
      onClick={onClose}
    >
      <div
        className="flex max-h-[88vh] w-full max-w-3xl flex-col overflow-hidden rounded-xl bg-white shadow-lg"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="flex items-start justify-between gap-3 border-b border-slate-200 px-4 py-3">
          <div>
            <p className="text-sm font-semibold text-slate-900">Log batcha #{batch.id}</p>
            <p className="text-xs text-slate-500">
              {batch.done} OK · {batch.failed} błędów · {batch.total} łącznie
              {batch.manufacturer ? ` · ${batch.manufacturer}` : ''}
            </p>
          </div>
          <button
            type="button"
            onClick={onClose}
            className="rounded border border-slate-300 px-2 py-1 text-xs"
          >
            Zamknij
          </button>
        </div>

        <div className="flex flex-wrap items-center gap-2 border-b border-slate-100 px-4 py-2">
          <label className="text-[11px] text-slate-600">
            Sortuj
            <select
              value={sort}
              onChange={(e) => setSort(e.target.value === 'updated' ? 'updated' : 'status')}
              className="ml-1 rounded border border-slate-300 px-1.5 py-0.5 text-[11px]"
            >
              <option value="status">po statusie</option>
              <option value="updated">najnowsze</option>
            </select>
          </label>
          <div className="flex flex-wrap gap-1">
            {filters.map((key) => (
              <button
                key={key || 'all'}
                type="button"
                onClick={() => setStatus(key)}
                className={`rounded px-1.5 py-0.5 text-[11px] ${
                  status === key
                    ? 'bg-slate-800 text-white'
                    : 'border border-slate-200 bg-slate-50 text-slate-700'
                }`}
              >
                {key === '' ? 'Wszystkie' : (STATUS_LABEL[key] ?? key)}
                {key !== '' && log?.counts[key] != null ? ` ${log.counts[key]}` : ''}
              </button>
            ))}
          </div>
        </div>

        <div className="min-h-0 flex-1 overflow-auto px-2 py-2">
          {loading && log == null && <p className="px-2 text-xs text-slate-500">Ładowanie…</p>}
          {err && <p className="px-2 text-xs text-red-700">{err}</p>}
          {log != null && log.items.length === 0 && (
            <p className="px-2 text-xs text-slate-500">
              Brak pozycji w dzienniku. Batche sprzed tej funkcji nie mają historii — nowe joby
              zapisują każdy produkt.
            </p>
          )}
          <ul className="divide-y divide-slate-100">
            {(log?.items ?? []).map((item) => (
              <LogRow key={item.id} item={item} />
            ))}
          </ul>
        </div>
      </div>
    </div>
  )
}

function LogRow({ item }: { item: EnrichmentBatchItem }) {
  const href = appHref(`/products/${item.product_id}`)

  return (
    <li>
      <a
        href={href}
        target="_blank"
        rel="noopener noreferrer"
        className="flex items-start gap-2 px-2 py-1.5 text-[12px] hover:bg-slate-50"
        title="Otwórz produkt w nowej karcie"
      >
        <span
          className={`mt-0.5 shrink-0 rounded px-1.5 py-0.5 text-[10px] font-medium ${STATUS_CLASS[item.status] ?? 'bg-slate-100 text-slate-700'}`}
        >
          {STATUS_LABEL[item.status] ?? item.status}
        </span>
        <span className="min-w-0">
          <span className="block font-medium text-blue-800">{item.sku}</span>
          <span className="block truncate text-slate-600">{item.name}</span>
          {item.message ? (
            <span className="block truncate text-[11px] text-slate-500" title={item.message}>
              {item.message}
            </span>
          ) : null}
        </span>
      </a>
    </li>
  )
}
