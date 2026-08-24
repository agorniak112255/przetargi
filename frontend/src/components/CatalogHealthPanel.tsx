import { useEffect, useState } from 'react'
import { api, type EnrichmentBatch } from '../lib/api'

type Report = {
  total: number
  missing_description: number
  missing_images: number
  missing_attributes: number
  not_enriched: number
  manual_review?: number
  with_description: number
  by_manufacturer: Array<{ manufacturer: string; count: number }>
  offer_markup_percent?: number
  vector?: { enabled: boolean; indexed: number; pending_jobs: number }
}

type Props = {
  canQueue: boolean
  manufacturerFilter?: string
  onQueued?: (batch: EnrichmentBatch) => void
}

export function CatalogHealthPanel({ canQueue, manufacturerFilter = '', onQueued }: Props) {
  const [report, setReport] = useState<Report | null>(null)
  const [busy, setBusy] = useState(false)
  const [msg, setMsg] = useState('')
  const [err, setErr] = useState('')

  async function load() {
    setErr('')
    try {
      const q = manufacturerFilter
        ? `?manufacturer=${encodeURIComponent(manufacturerFilter)}`
        : ''
      setReport(await api<Report>(`/products/catalog-health${q}`))
    } catch (e) {
      setErr(e instanceof Error ? e.message : 'Błąd raportu katalogu')
    }
  }

  useEffect(() => {
    void load()
  }, [manufacturerFilter])

  async function queue(reason: 'missing_description' | 'not_enriched') {
    setBusy(true)
    setMsg('')
    setErr('')
    try {
      const res = await api<{ message: string; batch: EnrichmentBatch }>(
        '/products/catalog-health/queue',
        {
          method: 'POST',
          body: JSON.stringify({
            reason,
            manufacturer: manufacturerFilter || null,
            force: false,
          }),
        },
      )
      setMsg(res.message)
      onQueued?.(res.batch)
      await load()
    } catch (e) {
      setErr(e instanceof Error ? e.message : 'Błąd kolejki')
    } finally {
      setBusy(false)
    }
  }

  async function backfillAttrs() {
    setBusy(true)
    setMsg('')
    setErr('')
    try {
      const res = await api<{ message: string }>(
        '/products/catalog-health/backfill-attributes',
        {
          method: 'POST',
          body: JSON.stringify({
            manufacturer: manufacturerFilter || null,
            force: false,
          }),
        },
      )
      setMsg(res.message)
      await load()
    } catch (e) {
      setErr(e instanceof Error ? e.message : 'Błąd backfill atrybutów')
    } finally {
      setBusy(false)
    }
  }

  if (!report) {
    return (
      <div className="mb-3 rounded-xl border border-slate-200 bg-white p-3 text-xs text-slate-500">
        {err || 'Ładowanie raportu katalogu…'}
      </div>
    )
  }

  return (
    <div className="mb-3 rounded-xl border border-amber-200 bg-amber-50/60 p-3 text-xs text-slate-800">
      <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
        <strong className="text-amber-950">Jakość katalogu</strong>
        <button
          type="button"
          disabled={busy}
          onClick={() => void load()}
          className="rounded border border-amber-300 bg-white px-2 py-1 text-[11px] disabled:opacity-50"
        >
          Odśwież
        </button>
      </div>
      <div className="mb-2 flex flex-wrap gap-2">
        <Stat label="Produkty" value={report.total} />
        <Stat label="Bez opisu" value={report.missing_description} warn />
        <Stat label="Bez zdjęć" value={report.missing_images} warn />
        <Stat label="Bez atrybutów BHP" value={report.missing_attributes} warn />
        <Stat label="Nie wzbogacone" value={report.not_enriched} warn />
        <Stat label="Z opisem" value={report.with_description} />
        {(report.manual_review ?? 0) > 0 && (
          <Stat label="Do ręcznego opisu" value={report.manual_review ?? 0} warn />
        )}
        {report.vector?.enabled && (
          <Stat
            label={
              report.vector.pending_jobs > 0
                ? `Wektory (indeksuje się, w kolejce ${report.vector.pending_jobs})`
                : 'Wektory'
            }
            value={report.vector.indexed}
          />
        )}
      </div>
      {canQueue && (
        <div className="flex flex-wrap gap-2">
          <button
            type="button"
            disabled={busy || report.missing_description === 0}
            onClick={() => void queue('missing_description')}
            className="rounded bg-amber-600 px-2 py-1.5 text-[11px] font-semibold text-white disabled:opacity-50"
          >
            Kolejka: brak opisu ({report.missing_description})
          </button>
          <button
            type="button"
            disabled={busy || report.not_enriched === 0}
            onClick={() => void queue('not_enriched')}
            className="rounded bg-violet-600 px-2 py-1.5 text-[11px] font-semibold text-white disabled:opacity-50"
          >
            Kolejka: nie wzbogacone ({report.not_enriched})
          </button>
          <button
            type="button"
            disabled={busy || report.missing_attributes === 0}
            onClick={() => void backfillAttrs()}
            className="rounded border border-slate-300 bg-white px-2 py-1.5 text-[11px] font-semibold text-slate-800 disabled:opacity-50"
          >
            Uzupełnij atrybuty lokalnie ({report.missing_attributes})
          </button>
        </div>
      )}
      <p className="mt-2 text-[10px] text-slate-600">
        Proponowany narzut oferty: +{report.offer_markup_percent ?? 18}% od zakupu (po upuście).
        {manufacturerFilter ? ` Filtr producenta: ${manufacturerFilter}.` : ''}
        Limit batcha z Ustawień AI.
      </p>
      {msg && <p className="mt-1 text-[11px] text-emerald-800">{msg}</p>}
      {err && <p className="mt-1 text-[11px] text-red-700">{err}</p>}
    </div>
  )
}

function Stat({ label, value, warn }: { label: string; value: number; warn?: boolean }) {
  return (
    <span
      className={`rounded border px-2 py-1 ${
        warn && value > 0
          ? 'border-amber-300 bg-white text-amber-900'
          : 'border-slate-200 bg-white text-slate-700'
      }`}
    >
      {label}: <b>{value}</b>
    </span>
  )
}
