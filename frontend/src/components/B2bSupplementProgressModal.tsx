import { useEffect, useState } from 'react'
import { api, appHref } from '../lib/api'

/** Karta w oknie postępu uzupełniania opisów (GET /b2b-accounts/{id}/supplement-progress). */
type SupplementRow = {
  product_id: number
  sku: string
  name: string
  status: string
  stage: string | null
  message: string | null
  source_urls: string[]
  attempts: number
  started_at: string | null
  attempted_at: string | null
  retry_at: string | null
  updated_at: string | null
}

type SupplementProgress = {
  counts: Record<string, number>
  waiting_search: number
  next_retry_at: string | null
  running: SupplementRow[]
  recent: SupplementRow[]
}

const STATUS_LABEL: Record<string, string> = {
  running: 'W trakcie',
  queued: 'Czeka na wyszukiwarkę',
  replaced: 'Uzupełniony',
  kept_b2b: 'Bez zmian',
  no_pages: 'Brak stron',
  failed: 'Do ponowienia',
  cancelled: 'Zatrzymane',
}

const STATUS_CLASS: Record<string, string> = {
  running: 'bg-sky-100 text-sky-800',
  queued: 'bg-amber-100 text-amber-900',
  replaced: 'bg-green-100 text-green-800',
  kept_b2b: 'bg-slate-100 text-slate-700',
  no_pages: 'bg-slate-100 text-slate-500',
  failed: 'bg-red-100 text-red-800',
  cancelled: 'bg-slate-200 text-slate-600',
}

/** Godzina czasu polskiego („14:05”) — serwer zapisuje czas w UTC. */
function plTime(iso: string | null): string {
  if (iso === null) return '—'
  return new Date(iso).toLocaleTimeString('pl-PL', { timeZone: 'Europe/Warsaw', hour: '2-digit', minute: '2-digit' })
}

function secondsSince(iso: string | null, now: number): string {
  if (iso === null) return ''
  const s = Math.max(0, Math.round((now - new Date(iso).getTime()) / 1000))
  return s < 60 ? `${s} s` : `${Math.floor(s / 60)} min ${s % 60} s`
}

function hostOf(url: string): string {
  try {
    return new URL(url).hostname.replace(/^www\./, '')
  } catch {
    return url
  }
}

type Props = {
  account: { id: number; username: string; connector_label: string | null } | null
  canManage: boolean
  onClose: () => void
  /** Po „Zatrzymaj” — odśwież liczniki na liście kont. */
  onChanged: () => void
}

export function B2bSupplementProgressModal({ account, canManage, onClose, onChanged }: Props) {
  const [data, setData] = useState<SupplementProgress | null>(null)
  const [err, setErr] = useState('')
  const [msg, setMsg] = useState('')
  const [busy, setBusy] = useState(false)
  const [filter, setFilter] = useState('')
  const [now, setNow] = useState(() => Date.now())

  async function load() {
    if (account === null) return
    try {
      setData(await api<SupplementProgress>(`/b2b-accounts/${account.id}/supplement-progress`))
      setErr('')
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Nie udało się pobrać postępu')
    }
    setNow(Date.now())
  }

  const counts = data?.counts ?? {}
  const pending = (counts.queued ?? 0) + (counts.running ?? 0)

  useEffect(() => {
    if (account === null) {
      setData(null)
      setMsg('')
      return
    }
    void load()
    // w toku — co 3 s (jak log wzbogacania), w spoczynku rzadziej
    const t = window.setInterval(() => void load(), pending > 0 ? 3000 : 15000)
    return () => window.clearInterval(t)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [account?.id, pending > 0])

  useEffect(() => {
    if (account === null) return
    function onKey(e: KeyboardEvent) {
      if (e.key === 'Escape') onClose()
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [account, onClose])

  /** Liczba przy filtrze wyników — „w kolejce” w wynikach to tylko karty czekające na wyszukiwarkę. */
  const filterCount = (key: string): number => (key === 'queued' ? (data?.waiting_search ?? 0) : (counts[key] ?? 0))
  const filters = ['', 'replaced', 'kept_b2b', 'no_pages', 'failed', 'queued', 'cancelled'].filter(
    (key) => key === '' || filterCount(key) > 0 || key === filter,
  )

  async function stop(all: boolean) {
    if (account === null) return
    if (
      all &&
      !window.confirm('Zatrzymać uzupełnianie opisów wszystkich kont B2B? Wznowienie: „Uzupełnij krótkie opisy” przy koncie.')
    ) {
      return
    }
    setBusy(true)
    setErr('')
    try {
      const res = await api<{ stopped: number; message: string }>(
        all ? '/b2b-accounts/supplement-stop-all' : `/b2b-accounts/${account.id}/supplement-stop`,
        { method: 'POST' },
      )
      setMsg(res.message)
      await load()
      onChanged()
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Nie udało się zatrzymać')
    } finally {
      setBusy(false)
    }
  }

  if (account === null) return null

  const done =
    (counts.replaced ?? 0) + (counts.kept_b2b ?? 0) + (counts.no_pages ?? 0) + (counts.failed ?? 0) + (counts.cancelled ?? 0)
  const total = done + pending
  const recent = (data?.recent ?? []).filter((r) => filter === '' || r.status === filter)

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
            <p className="text-sm font-semibold text-slate-900">
              Uzupełnianie opisów — {account.connector_label ?? account.username} (#{account.id})
            </p>
            <p className="text-xs text-slate-500">
              {pending > 0 ? `W toku: sprawdzono ${done} z ${total} kart` : `Nic w toku · sprawdzono ${done} kart`}
              {' · '}uzupełnione {counts.replaced ?? 0} · bez zmian {counts.kept_b2b ?? 0} · brak stron{' '}
              {counts.no_pages ?? 0} · do ponowienia {counts.failed ?? 0}
              {(counts.cancelled ?? 0) > 0 ? ` · zatrzymane ${counts.cancelled}` : ''}
            </p>
            {total > 0 && (
              <div className="mt-1.5 h-1.5 w-64 overflow-hidden rounded bg-slate-100">
                <div className="h-full bg-blue-600" style={{ width: `${Math.round((100 * done) / total)}%` }} />
              </div>
            )}
          </div>
          <div className="flex shrink-0 gap-1.5">
            {canManage && (
              <>
                <button
                  type="button"
                  disabled={busy || pending === 0}
                  onClick={() => void stop(false)}
                  className="rounded border border-red-300 px-2 py-1 text-xs text-red-700 disabled:opacity-40"
                  title="Karty tego konta w kolejce i w pracy — zatrzymane; wznowienie: „Uzupełnij krótkie opisy”"
                >
                  Zatrzymaj
                </button>
                <button
                  type="button"
                  disabled={busy}
                  onClick={() => void stop(true)}
                  className="rounded border border-red-300 px-2 py-1 text-xs text-red-700 disabled:opacity-40"
                  title="Uzupełnianie opisów wszystkich kont B2B"
                >
                  Zatrzymaj wszystko
                </button>
              </>
            )}
            <button type="button" onClick={onClose} className="rounded border border-slate-300 px-2 py-1 text-xs">
              Zamknij
            </button>
          </div>
        </div>

        <div className="min-h-0 flex-1 overflow-y-auto px-4 py-2 text-[12px]">
          {err && <p className="text-red-700">{err}</p>}
          {msg && <p className="text-green-700">{msg}</p>}
          {data === null && !err && <p className="text-slate-500">Ładowanie…</p>}

          {(data?.waiting_search ?? 0) > 0 && (
            <p className="mb-2 rounded bg-amber-50 px-2 py-1 text-amber-900">
              Czeka na wyszukiwarkę: {data?.waiting_search} kart — najbliższe ponowienie o {plTime(data?.next_retry_at ?? null)}{' '}
              (wyszukiwarka robi przerwę po zablokowaniu silników).
            </p>
          )}
          {(counts.queued ?? 0) - (data?.waiting_search ?? 0) > 0 && (
            <p className="mb-2 text-slate-600">W kolejce: {(counts.queued ?? 0) - (data?.waiting_search ?? 0)} kart.</p>
          )}

          {(data?.running ?? []).length > 0 && (
            <div className="mb-3">
              <p className="mb-1 text-[11px] font-semibold uppercase tracking-wide text-slate-500">Teraz obrabia</p>
              <ul className="divide-y divide-slate-100 rounded border border-sky-100 bg-sky-50/40">
                {(data?.running ?? []).map((r) => (
                  <li key={r.product_id} className="px-2 py-1.5">
                    <a
                      href={appHref(`/products/${r.product_id}`)}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="font-medium text-blue-800"
                    >
                      {r.sku}
                    </a>{' '}
                    <span className="text-slate-600">{r.name}</span>
                    <span className="block text-sky-800">
                      {r.stage ?? 'start'} · {secondsSince(r.started_at, now)}
                    </span>
                  </li>
                ))}
              </ul>
            </div>
          )}

          <div className="mb-1 flex flex-wrap items-center gap-1">
            <span className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Ostatnie wyniki</span>
            {filters.map((key) => (
              <button
                key={key || 'all'}
                type="button"
                onClick={() => setFilter(key)}
                className={`rounded px-1.5 py-0.5 text-[11px] ${
                  filter === key ? 'bg-slate-800 text-white' : 'border border-slate-200 bg-slate-50 text-slate-700'
                }`}
              >
                {key === '' ? 'Wszystkie' : (STATUS_LABEL[key] ?? key)}
                {key !== '' ? ` ${filterCount(key)}` : ''}
              </button>
            ))}
          </div>
          {data !== null && recent.length === 0 && <p className="text-slate-500">Brak wyników.</p>}
          <ul className="divide-y divide-slate-100">
            {recent.map((r) => (
              <li key={r.product_id} className="flex items-start gap-2 py-1.5">
                <span
                  className={`mt-0.5 shrink-0 rounded px-1.5 py-0.5 text-[10px] font-medium ${STATUS_CLASS[r.status] ?? 'bg-slate-100 text-slate-700'}`}
                >
                  {STATUS_LABEL[r.status] ?? r.status}
                </span>
                <span className="min-w-0">
                  <a
                    href={appHref(`/products/${r.product_id}`)}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="font-medium text-blue-800"
                  >
                    {r.sku}
                  </a>{' '}
                  <span className="text-slate-600">{r.name}</span>
                  <span className="ml-1 text-[11px] text-slate-400">{plTime(r.updated_at)}</span>
                  {r.status === 'queued' && r.retry_at !== null ? (
                    <span className="block text-[11px] text-amber-800">ponowienie o {plTime(r.retry_at)}</span>
                  ) : r.message ? (
                    <span className="block whitespace-normal text-[11px] text-slate-500">
                      {r.message}
                      {r.stage && r.status === 'failed' ? ` (etap: ${r.stage})` : ''}
                    </span>
                  ) : null}
                  {r.source_urls.length > 0 && (
                    <span className="block text-[11px] text-slate-500">
                      Źródła:{' '}
                      {r.source_urls.map((u, i) => (
                        <span key={u}>
                          {i > 0 ? ', ' : ''}
                          <a href={u} target="_blank" rel="noopener noreferrer" className="underline">
                            {hostOf(u)}
                          </a>
                        </span>
                      ))}
                    </span>
                  )}
                </span>
              </li>
            ))}
          </ul>
        </div>
      </div>
    </div>
  )
}
