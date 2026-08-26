import { useEffect, useState } from 'react'
import { api, type EnrichmentBatch } from '../lib/api'

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
  const [busyId, setBusyId] = useState<number | null>(null)
  const [err, setErr] = useState('')
  const [msg, setMsg] = useState('')

  async function load() {
    try {
      const list = await api<EnrichmentBatch[]>('/product-enrichment-batches/active')
      setBatches(Array.isArray(list) ? list : [])
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

  if (batches.length === 0 && !msg && !err) {
    return null
  }

  return (
    <div className="mb-4 rounded-lg border border-amber-200 bg-amber-50/80 p-3">
      <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
        <p className="text-xs font-semibold text-amber-950">Aktywne joby enrichmentu</p>
        <button
          type="button"
          onClick={() => void load()}
          className="rounded border border-amber-300 bg-white px-2 py-0.5 text-[11px] text-amber-900"
        >
          Odśwież
        </button>
      </div>
      {err && <p className="mb-2 text-xs text-red-700">{err}</p>}
      {msg && <p className="mb-2 text-xs text-green-800">{msg}</p>}
      {batches.length === 0 ? (
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
