import { useEffect, useState } from 'react'
import { EnrichmentBatchLogModal } from '../components/EnrichmentBatchLogModal'
import { api, type EnrichmentBatch } from '../lib/api'

const SCOPE_LABEL: Record<string, string> = {
  product: 'Produkt',
  products: 'Produkty',
  price_list: 'Cennik',
}

const STATUS_LABEL: Record<string, string> = {
  done: 'OK',
  failed: 'Błąd',
  cancelled: 'Anulowany',
}

const STATUS_CLASS: Record<string, string> = {
  done: 'bg-green-100 text-green-800',
  failed: 'bg-red-100 text-red-800',
  cancelled: 'bg-slate-200 text-slate-700',
}

type HistoryResponse = {
  data: EnrichmentBatch[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
}

function formatWhen(iso: string | null | undefined): string {
  if (!iso) return '—'
  try {
    return new Date(iso).toLocaleString('pl-PL')
  } catch {
    return iso
  }
}

export function AdminEnrichmentLogs() {
  const [rows, setRows] = useState<EnrichmentBatch[]>([])
  const [page, setPage] = useState(1)
  const [lastPage, setLastPage] = useState(1)
  const [total, setTotal] = useState(0)
  const [status, setStatus] = useState('')
  const [err, setErr] = useState('')
  const [busy, setBusy] = useState(false)
  const [logBatch, setLogBatch] = useState<EnrichmentBatch | null>(null)

  async function load(nextPage = page, nextStatus = status) {
    setBusy(true)
    setErr('')
    try {
      const params = new URLSearchParams()
      params.set('page', String(nextPage))
      params.set('per_page', '40')
      if (nextStatus !== '') {
        params.set('status', nextStatus)
      }
      const data = await api<HistoryResponse>(`/product-enrichment-batches/history?${params}`)
      setRows(data.data)
      setPage(data.meta.current_page)
      setLastPage(data.meta.last_page)
      setTotal(data.meta.total)
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Błąd')
    } finally {
      setBusy(false)
    }
  }

  useEffect(() => {
    void load(1, status)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [status])

  return (
    <div>
      <p className="mb-3 text-sm text-slate-600">
        Zakończone pobierania opisów i zdjęć ({total}). Kliknij wiersz, żeby otworzyć log produktów.
      </p>

      {err && <p className="mb-2 text-sm text-red-600">{err}</p>}

      <div className="mb-3 flex flex-wrap gap-1">
        {[
          ['', 'Wszystkie'],
          ['done', 'OK'],
          ['failed', 'Błąd'],
          ['cancelled', 'Anulowane'],
        ].map(([key, label]) => (
          <button
            key={key || 'all'}
            type="button"
            onClick={() => setStatus(key)}
            className={`rounded px-2 py-1 text-xs ${
              status === key
                ? 'bg-slate-800 text-white'
                : 'border border-slate-200 bg-white text-slate-700 hover:bg-slate-50'
            }`}
          >
            {label}
          </button>
        ))}
      </div>

      <div className="overflow-x-auto rounded-xl bg-white shadow-sm">
        <table className="min-w-full text-left text-sm">
          <thead className="border-b bg-slate-50 text-xs uppercase text-slate-500">
            <tr>
              <th className="px-3 py-2">#</th>
              <th className="px-3 py-2">Zakończono</th>
              <th className="px-3 py-2">Zakres</th>
              <th className="px-3 py-2">Producent</th>
              <th className="px-3 py-2">Wynik</th>
              <th className="px-3 py-2">Status</th>
              <th className="px-3 py-2">Kto</th>
              <th className="px-3 py-2" />
            </tr>
          </thead>
          <tbody>
            {rows.length === 0 && (
              <tr>
                <td colSpan={8} className="px-3 py-6 text-center text-slate-500">
                  {busy ? 'Ładowanie…' : 'Brak zakończonych jobów.'}
                </td>
              </tr>
            )}
            {rows.map((row) => (
              <tr
                key={row.id}
                className="cursor-pointer border-b last:border-0 hover:bg-slate-50"
                onClick={() => setLogBatch(row)}
              >
                <td className="whitespace-nowrap px-3 py-2 font-medium text-slate-800">#{row.id}</td>
                <td className="whitespace-nowrap px-3 py-2 text-slate-700">
                  {formatWhen(row.updated_at ?? row.created_at)}
                </td>
                <td className="px-3 py-2 text-slate-700">
                  {SCOPE_LABEL[row.scope] ?? row.scope}
                  {row.scope_id ? ` #${row.scope_id}` : ''}
                </td>
                <td className="px-3 py-2 text-slate-600">{row.manufacturer ?? '—'}</td>
                <td className="whitespace-nowrap px-3 py-2 text-slate-700">
                  {row.done} OK / {row.failed} błędów / {row.total}
                </td>
                <td className="px-3 py-2">
                  <span
                    className={`rounded px-1.5 py-0.5 text-[11px] font-medium ${STATUS_CLASS[row.status] ?? 'bg-slate-100 text-slate-700'}`}
                  >
                    {STATUS_LABEL[row.status] ?? row.status}
                  </span>
                </td>
                <td className="px-3 py-2 text-slate-600">{row.created_by_name ?? '—'}</td>
                <td className="px-3 py-2 text-right">
                  <button
                    type="button"
                    className="text-xs text-blue-600 hover:underline"
                    onClick={(e) => {
                      e.stopPropagation()
                      setLogBatch(row)
                    }}
                  >
                    Log
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <div className="mt-3 flex items-center gap-3 text-sm">
        <button
          type="button"
          disabled={busy || page <= 1}
          className="rounded border px-2 py-1 disabled:opacity-40"
          onClick={() => void load(page - 1)}
        >
          ← Poprzednia
        </button>
        <span className="text-slate-600">
          Strona {page} / {lastPage}
        </span>
        <button
          type="button"
          disabled={busy || page >= lastPage}
          className="rounded border px-2 py-1 disabled:opacity-40"
          onClick={() => void load(page + 1)}
        >
          Następna →
        </button>
      </div>

      <EnrichmentBatchLogModal batch={logBatch} onClose={() => setLogBatch(null)} />
    </div>
  )
}
