import { useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../lib/api'

type RunStatus = 'running' | 'ok' | 'failed' | 'cancelled'
type RunTrigger = 'manual' | 'schedule' | 'cli'

type SyncRunSummary = {
  id: number
  status: RunStatus
  trigger: RunTrigger
  started_at: string
  finished_at: string | null
  updated_at: string
  total: number | null
  processed: number
  created: number
  updated: number
  unchanged: number
  skipped: number
  prices_changed: number
  descriptions: number
  images: number
  current_sku: string | null
  message: string | null
  cancel_requested: boolean
}

type SyncLogEntry = { at: string; level: 'info' | 'warn' | 'error'; text: string }

type PriceChange = {
  product_id: number
  sku: string
  name: string
  catalog_old: number | null
  catalog_new: number | null
  catalog_pct: number | null
  purchase_old: number | null
  purchase_new: number | null
  discount_old: number | null
  discount_new: number | null
  direction: 'up' | 'down' | 'flat'
  at: string
}

type SyncRunFull = SyncRunSummary & { log: SyncLogEntry[]; price_changes: PriceChange[] }

type SyncProgress = {
  scheduler: { last_seen_at: string | null; healthy: boolean }
  sync_requested_at: string | null
  run: SyncRunFull | null
  recent_runs: SyncRunSummary[]
}

type Props = {
  account: { id: number; username: string; connector_label: string | null }
  canManage: boolean
  onClose: () => void
  onChanged: () => void
}

const STATUS_LABEL: Record<RunStatus, string> = {
  running: 'Trwa',
  ok: 'Zakończone',
  failed: 'Błąd',
  cancelled: 'Zatrzymane',
}

const STATUS_CLASS: Record<RunStatus, string> = {
  running: 'bg-sky-100 text-sky-800',
  ok: 'bg-green-100 text-green-800',
  failed: 'bg-red-100 text-red-800',
  cancelled: 'bg-slate-200 text-slate-600',
}

const TRIGGER_LABEL: Record<RunTrigger, string> = {
  manual: 'ręcznie',
  schedule: 'wg harmonogramu',
  cli: 'z konsoli',
}

const LOG_CLASS: Record<SyncLogEntry['level'], string> = {
  info: 'text-slate-700',
  warn: 'text-amber-700',
  error: 'text-red-700',
}

function formatDateTime(value: string | null): string {
  return value ? new Date(value).toLocaleString('pl-PL') : ''
}

/** Godzina, gdy dziś; pełna data i godzina w pozostałych przypadkach. */
function formatWhen(value: string): string {
  const d = new Date(value)
  if (d.toDateString() === new Date().toDateString()) {
    return d.toLocaleTimeString('pl-PL', { hour: '2-digit', minute: '2-digit' })
  }
  return d.toLocaleString('pl-PL')
}

function formatClock(value: string): string {
  return new Date(value).toLocaleTimeString('pl-PL', {
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
  })
}

function formatDuration(ms: number): string {
  const total = Math.max(0, Math.round(ms / 1000))
  const h = Math.floor(total / 3600)
  const m = Math.floor((total % 3600) / 60)
  const s = total % 60
  if (h > 0) return `${h} h ${String(m).padStart(2, '0')} min`
  if (m > 0) return `${m} min ${String(s).padStart(2, '0')} s`
  return `${s} s`
}

function formatNumber(value: number): string {
  return value.toLocaleString('pl-PL')
}

export function B2bSyncProgressModal({ account, canManage, onClose, onChanged }: Props) {
  const [data, setData] = useState<SyncProgress | null>(null)
  const [err, setErr] = useState('')
  const [actionErr, setActionErr] = useState('')
  const [busy, setBusy] = useState(false)
  const [showRecent, setShowRecent] = useState(false)
  const [now, setNow] = useState(() => Date.now())

  const requestSeq = useRef(0)
  const runningRunId = useRef<number | null>(null)
  const onChangedRef = useRef(onChanged)
  const logBox = useRef<HTMLDivElement | null>(null)
  const stickToBottom = useRef(true)

  useEffect(() => {
    onChangedRef.current = onChanged
  }, [onChanged])

  const load = useCallback(async () => {
    const seq = ++requestSeq.current
    try {
      const res = await api<SyncProgress>(`/b2b-accounts/${account.id}/sync-progress`)
      if (seq !== requestSeq.current) return
      const run = res.run
      const stillRunning = run !== null && run.status === 'running'
      if (
        runningRunId.current !== null &&
        !(stillRunning && run.id === runningRunId.current)
      ) {
        onChangedRef.current()
      }
      runningRunId.current = stillRunning ? run.id : null
      setData(res)
      setNow(Date.now())
      setErr('')
    } catch (ex) {
      if (seq !== requestSeq.current) return
      setErr(ex instanceof Error ? ex.message : 'Nie udało się pobrać postępu')
    }
  }, [account.id])

  const run = data?.run ?? null
  const running = run?.status === 'running'
  const requested = Boolean(data?.sync_requested_at)
  const live = running || requested

  useEffect(() => {
    void load()
  }, [load])

  useEffect(() => {
    const t = window.setInterval(() => void load(), live ? 3000 : 20000)
    return () => window.clearInterval(t)
  }, [load, live])

  useEffect(() => {
    if (!running) return
    const t = window.setInterval(() => setNow(Date.now()), 1000)
    return () => window.clearInterval(t)
  }, [running])

  useEffect(() => {
    function onKey(e: KeyboardEvent) {
      if (e.key === 'Escape') onClose()
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [onClose])

  const log = run?.log ?? []
  const lastLog = log.length > 0 ? log[log.length - 1] : null
  const logKey = `${run?.id ?? ''}|${log.length}|${lastLog?.at ?? ''}|${lastLog?.text ?? ''}`

  useLayoutEffect(() => {
    const el = logBox.current
    if (el && stickToBottom.current) {
      el.scrollTop = el.scrollHeight
    }
  }, [logKey])

  function onLogScroll() {
    const el = logBox.current
    if (!el) return
    stickToBottom.current = el.scrollHeight - el.scrollTop - el.clientHeight < 24
  }

  async function requestSync() {
    setBusy(true)
    setActionErr('')
    try {
      await api(`/b2b-accounts/${account.id}/sync`, { method: 'POST' })
      stickToBottom.current = true
      await load()
      onChanged()
    } catch (ex) {
      setActionErr(ex instanceof Error ? ex.message : 'Nie udało się zlecić sprawdzenia')
    } finally {
      setBusy(false)
    }
  }

  async function cancelSync() {
    if (
      !window.confirm(
        `Zatrzymać pobieranie cennika${account.connector_label ? ` ${account.connector_label}` : ''}? Przebieg zatrzyma się przy najbliższej okazji.`,
      )
    ) {
      return
    }
    setBusy(true)
    setActionErr('')
    try {
      await api(`/b2b-accounts/${account.id}/sync-cancel`, { method: 'POST' })
      await load()
    } catch (ex) {
      setActionErr(ex instanceof Error ? ex.message : 'Nie udało się zatrzymać pobierania')
    } finally {
      setBusy(false)
    }
  }

  const recentRuns = (data?.recent_runs ?? []).filter((r) => r.id !== run?.id)

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
      role="dialog"
      aria-modal="true"
      onClick={onClose}
    >
      <div
        className="flex max-h-[88vh] w-full max-w-2xl flex-col overflow-hidden rounded-xl bg-white shadow-lg"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="flex items-start justify-between gap-3 border-b border-slate-200 px-4 py-3">
          <div className="min-w-0">
            <p className="text-sm font-semibold text-slate-900">
              Pobieranie cennika — {account.connector_label ?? 'importer B2B'}
            </p>
            <p className="truncate text-xs text-slate-500">Konto: {account.username}</p>
          </div>
          <button
            type="button"
            onClick={onClose}
            aria-label="Zamknij"
            className="rounded px-2 py-0.5 text-lg leading-none text-slate-500 hover:bg-slate-100 hover:text-slate-900"
          >
            ×
          </button>
        </div>

        <div className="min-h-0 flex-1 space-y-3 overflow-auto px-4 py-3 text-xs">
          {err && (
            <p className="rounded bg-red-50 px-3 py-2 text-red-700">
              {err}
              {data ? ' Pokazuję ostatnio pobrany stan.' : ''}
            </p>
          )}
          {data === null && !err && <p className="text-slate-500">Ładowanie…</p>}

          {data !== null && !data.scheduler.healthy && (
            <p className="rounded border border-amber-200 bg-amber-50 px-3 py-2 text-amber-900">
              Harmonogram serwera nie działa (ostatnio:{' '}
              {data.scheduler.last_seen_at ? formatDateTime(data.scheduler.last_seen_at) : 'nigdy'}) —
              zlecone i nocne pobieranie nie wystartuje. Uruchomienie ręczne: polecenie{' '}
              <span className="font-mono">b2b:sync {account.id}</span> na serwerze.
            </p>
          )}

          {data?.sync_requested_at && !running && (
            <p className="rounded border border-blue-200 bg-blue-50 px-3 py-2 text-blue-900">
              Zlecono {formatWhen(data.sync_requested_at)} — czeka na harmonogram (sprawdza co 5 min).
            </p>
          )}

          {data !== null && run === null && !data.sync_requested_at && (
            <p className="text-slate-500">Cennik nie był jeszcze pobierany.</p>
          )}

          {run !== null && <RunDetails run={run} now={now} />}

          {run !== null && <PriceChangesSection key={run.id} changes={run.price_changes ?? []} />}

          {run !== null && (
            <div>
              <p className="mb-1 font-medium text-slate-600">Dziennik</p>
              <div
                ref={logBox}
                onScroll={onLogScroll}
                className="max-h-64 overflow-auto rounded border border-slate-200 bg-slate-50 px-2 py-1.5 font-mono text-[11px] leading-snug"
              >
                {log.length === 0 ? (
                  <p className="text-slate-400">Brak wpisów w dzienniku.</p>
                ) : (
                  log.map((entry, i) => (
                    <p key={`${entry.at}-${i}`} className={`whitespace-pre-wrap ${LOG_CLASS[entry.level]}`}>
                      <span className="text-slate-400">{formatClock(entry.at)}</span> {entry.text}
                    </p>
                  ))
                )}
              </div>
            </div>
          )}

          {recentRuns.length > 0 && (
            <div>
              <button
                type="button"
                onClick={() => setShowRecent((v) => !v)}
                className="font-medium text-slate-600 hover:text-slate-900"
                aria-expanded={showRecent}
              >
                {showRecent ? '▾' : '▸'} Poprzednie przebiegi ({recentRuns.length})
              </button>
              {showRecent && (
                <div className="mt-1 overflow-x-auto">
                  <table className="w-full text-left text-[11px]">
                    <thead className="text-slate-500">
                      <tr className="border-b border-slate-200">
                        <th className="py-1 pr-2 font-medium">Data</th>
                        <th className="py-1 pr-2 font-medium">Tryb</th>
                        <th className="py-1 pr-2 font-medium">Status</th>
                        <th className="py-1 pr-2 text-right font-medium">Sprawdzone</th>
                        <th className="py-1 pr-2 text-right font-medium">Nowe</th>
                        <th className="py-1 pr-2 text-right font-medium">Zmiany cen</th>
                        <th className="py-1 text-right font-medium">Pominięte</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100 text-slate-700">
                      {recentRuns.map((r) => (
                        <tr key={r.id} title={r.message ?? undefined}>
                          <td className="whitespace-nowrap py-1 pr-2">{formatDateTime(r.started_at)}</td>
                          <td className="whitespace-nowrap py-1 pr-2">{TRIGGER_LABEL[r.trigger] ?? r.trigger}</td>
                          <td className="py-1 pr-2">
                            <span
                              className={`rounded px-1.5 py-0.5 text-[10px] font-medium ${STATUS_CLASS[r.status] ?? 'bg-slate-100 text-slate-700'}`}
                            >
                              {STATUS_LABEL[r.status] ?? r.status}
                            </span>
                          </td>
                          <td className="whitespace-nowrap py-1 pr-2 text-right tabular-nums">
                            {formatNumber(r.processed)}
                            {r.total !== null ? ` / ${formatNumber(r.total)}` : ''}
                          </td>
                          <td className="py-1 pr-2 text-right tabular-nums">{formatNumber(r.created)}</td>
                          <td className="py-1 pr-2 text-right tabular-nums">{formatNumber(r.prices_changed)}</td>
                          <td className="py-1 text-right tabular-nums">{formatNumber(r.skipped)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </div>
          )}
        </div>

        <div className="flex flex-wrap items-center justify-end gap-2 border-t border-slate-200 px-4 py-3">
          {actionErr && <p className="mr-auto text-xs text-red-700">{actionErr}</p>}
          {canManage && running && run !== null && !run.cancel_requested && (
            <button
              type="button"
              disabled={busy}
              onClick={() => void cancelSync()}
              className="rounded border border-red-300 bg-red-50 px-3 py-1.5 text-xs font-medium text-red-800 hover:bg-red-100 disabled:opacity-50"
            >
              Zatrzymaj
            </button>
          )}
          {canManage && (
            <button
              type="button"
              disabled={busy || data === null || running || requested}
              onClick={() => void requestSync()}
              className="rounded bg-blue-600 px-3 py-1.5 text-xs text-white hover:bg-blue-700 disabled:opacity-50"
            >
              Sprawdź teraz
            </button>
          )}
          <button
            type="button"
            onClick={onClose}
            className="rounded border border-slate-300 px-3 py-1.5 text-xs hover:bg-slate-50"
          >
            Zamknij
          </button>
        </div>
      </div>
    </div>
  )
}

function RunDetails({ run, now }: { run: SyncRunFull; now: number }) {
  const running = run.status === 'running'
  const startedMs = new Date(run.started_at).getTime()
  const endMs = running ? now : run.finished_at ? new Date(run.finished_at).getTime() : null
  const elapsedMs = endMs !== null ? endMs - startedMs : null

  const percent =
    run.total !== null && run.total > 0
      ? Math.min(100, Math.floor((run.processed / run.total) * 100))
      : null

  let etaMs: number | null = null
  if (running && run.total !== null && run.processed >= 10 && elapsedMs !== null && elapsedMs > 0) {
    const perItemMs = elapsedMs / run.processed
    etaMs = Math.max(0, run.total - run.processed) * perItemMs
  }

  const counters: [string, number][] = [
    ['Nowe', run.created],
    ['Zaktualizowane', run.updated],
    ['Bez zmian', run.unchanged],
    ['Pominięte', run.skipped],
    ['Zmiany cen', run.prices_changed],
    ['Nowe opisy', run.descriptions],
    ['Zdjęcia', run.images],
  ]

  return (
    <div className="space-y-2">
      <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-slate-600">
        <span className={`rounded px-1.5 py-0.5 text-[11px] font-medium ${STATUS_CLASS[run.status] ?? 'bg-slate-100 text-slate-700'}`}>
          {STATUS_LABEL[run.status] ?? run.status}
        </span>
        <span>{TRIGGER_LABEL[run.trigger] ?? run.trigger}</span>
        <span>start: {formatDateTime(run.started_at)}</span>
        {elapsedMs !== null && (
          <span>
            {running ? 'trwa' : 'czas'}: {formatDuration(elapsedMs)}
          </span>
        )}
        {run.cancel_requested && running && <span className="font-medium text-amber-700">Zatrzymywanie…</span>}
      </div>

      <div>
        <div className="mb-1 flex flex-wrap items-baseline justify-between gap-2">
          <span className="text-slate-700">
            <b className="tabular-nums">{formatNumber(run.processed)}</b>
            {run.total !== null ? (
              <>
                {' '}
                / <span className="tabular-nums">{formatNumber(run.total)}</span> produktów
                {percent !== null && <span className="text-slate-500"> ({percent}%)</span>}
              </>
            ) : (
              <span className="text-slate-500"> sprawdzonych — łączna liczba jeszcze nieznana</span>
            )}
          </span>
          {etaMs !== null && <span className="text-slate-500">pozostało ok. {formatDuration(etaMs)}</span>}
        </div>
        <div className="h-2 overflow-hidden rounded bg-slate-200">
          {percent !== null ? (
            <div
              className={`h-full transition-all ${
                run.status === 'failed'
                  ? 'bg-red-400'
                  : run.status === 'cancelled'
                    ? 'bg-slate-400'
                    : running
                      ? 'animate-pulse bg-blue-500'
                      : 'bg-green-500'
              }`}
              style={{ width: `${running ? Math.max(2, percent) : percent}%` }}
            />
          ) : (
            <div className={`h-full w-full ${running ? 'animate-pulse bg-blue-300' : 'bg-slate-300'}`} />
          )}
        </div>
        {running && (
          <p className="mt-1 truncate text-slate-600">
            Teraz: {run.current_sku ? <span className="font-mono">{run.current_sku}</span> : '—'}
          </p>
        )}
      </div>

      <div className="grid grid-cols-4 gap-1.5 sm:grid-cols-7">
        {counters.map(([label, value]) => (
          <div key={label} className="rounded bg-slate-100 px-2 py-1">
            <p className="text-sm font-semibold tabular-nums text-slate-900">{formatNumber(value)}</p>
            <p className="truncate text-[10px] text-slate-500" title={label}>
              {label}
            </p>
          </div>
        ))}
      </div>

      {run.message && (
        <p
          className={`whitespace-pre-wrap rounded px-3 py-2 ${
            run.status === 'failed' ? 'bg-red-50 text-red-700' : 'bg-slate-50 text-slate-700'
          }`}
        >
          {run.message}
        </p>
      )}
    </div>
  )
}

function formatPrice(value: number | null): string {
  return value === null
    ? '—'
    : value.toLocaleString('pl-PL', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function formatShortDate(value: string): string {
  const d = new Date(value)
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${pad(d.getDate())}.${pad(d.getMonth() + 1)} ${pad(d.getHours())}:${pad(d.getMinutes())}`
}

function pctChange(oldValue: number | null, newValue: number | null): number | null {
  if (oldValue === null || newValue === null || oldValue === 0) return null
  return ((newValue - oldValue) / oldValue) * 100
}

function PctBadge({ pct }: { pct: number | null }) {
  if (pct === null) return null
  const rounded = Math.round(pct * 10) / 10
  const cls = rounded > 0 ? 'text-red-700' : rounded < 0 ? 'text-green-700' : 'text-slate-500'
  const sign = rounded > 0 ? '+' : ''
  return (
    <span className={`ml-1 ${cls}`}>
      ({sign}
      {rounded.toLocaleString('pl-PL', { maximumFractionDigits: 1 })}%)
    </span>
  )
}

function PriceChangesSection({ changes }: { changes: PriceChange[] }) {
  const [open, setOpen] = useState(changes.length <= 20)
  const [filter, setFilter] = useState('')

  const needle = filter.trim().toLocaleLowerCase('pl-PL')
  const rows = [...changes]
    .reverse()
    .filter(
      (c) =>
        needle === '' ||
        c.sku.toLocaleLowerCase('pl-PL').includes(needle) ||
        c.name.toLocaleLowerCase('pl-PL').includes(needle),
    )

  return (
    <div>
      <div className="mb-1 flex flex-wrap items-center justify-between gap-2">
        <button
          type="button"
          onClick={() => setOpen((v) => !v)}
          className="font-medium text-slate-600 hover:text-slate-900"
          aria-expanded={open}
        >
          {open ? '▾' : '▸'} Zmiany cen ({changes.length})
        </button>
        {open && changes.length > 20 && (
          <input
            type="search"
            value={filter}
            onChange={(e) => setFilter(e.target.value)}
            placeholder="Filtruj po kodzie lub nazwie"
            className="w-48 rounded border border-slate-300 px-2 py-0.5 text-[11px]"
          />
        )}
      </div>
      {open &&
        (changes.length === 0 ? (
          <p className="text-slate-400">W tym przebiegu nie zmieniła się żadna cena.</p>
        ) : (
          <div className="max-h-64 overflow-auto rounded border border-slate-200">
            <table className="w-full text-left text-[11px]">
              <thead className="sticky top-0 bg-slate-50 text-slate-500">
                <tr className="border-b border-slate-200">
                  <th className="px-2 py-1 font-medium">Data</th>
                  <th className="px-2 py-1 font-medium">Kod</th>
                  <th className="px-2 py-1 font-medium">Nazwa</th>
                  <th className="px-2 py-1 text-right font-medium">Zakup</th>
                  <th className="px-2 py-1 text-right font-medium">Katalog</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100 text-slate-700">
                {rows.map((c, i) => (
                  <tr key={`${c.product_id}-${c.at}-${i}`}>
                    <td className="whitespace-nowrap px-2 py-1 tabular-nums">{formatShortDate(c.at)}</td>
                    <td className="whitespace-nowrap px-2 py-1">
                      <Link className="font-mono text-blue-700 hover:underline" to={`/products/${c.product_id}`}>
                        {c.sku}
                      </Link>
                    </td>
                    <td className="max-w-[14rem] truncate px-2 py-1" title={c.name}>
                      {c.name}
                    </td>
                    <td className="whitespace-nowrap px-2 py-1 text-right tabular-nums">
                      {formatPrice(c.purchase_old)} → {formatPrice(c.purchase_new)} zł
                      <PctBadge pct={pctChange(c.purchase_old, c.purchase_new)} />
                    </td>
                    <td className="whitespace-nowrap px-2 py-1 text-right tabular-nums text-slate-500">
                      {formatPrice(c.catalog_old)} → {formatPrice(c.catalog_new)}
                    </td>
                  </tr>
                ))}
                {rows.length === 0 && (
                  <tr>
                    <td colSpan={5} className="px-2 py-2 text-slate-400">
                      Brak zmian pasujących do filtra.
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        ))}
    </div>
  )
}
