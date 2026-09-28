import { useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react'
import { api, type B2bSizeMerge, type B2bSizeMergeState } from '../lib/api'

type MergeStatus = B2bSizeMergeState['status']

type Props = {
  account: { id: number; username: string; connector_label: string | null }
  canManage: boolean
  onClose: () => void
}

const STATUS_LABEL: Record<MergeStatus, string> = {
  queued: 'W kolejce',
  running: 'Trwa',
  done: 'Zakończone',
  failed: 'Błąd',
}

const STATUS_CLASS: Record<MergeStatus, string> = {
  queued: 'bg-slate-200 text-slate-700',
  running: 'bg-sky-100 text-sky-800',
  done: 'bg-green-100 text-green-800',
  failed: 'bg-red-100 text-red-800',
}

const POLL_JOB_MS = 2500
const POLL_SYNC_MS = 10000

function formatDateTime(value: string | null): string {
  return value ? new Date(value).toLocaleString('pl-PL') : ''
}

function formatNumber(value: number): string {
  return value.toLocaleString('pl-PL')
}

/** „1 wyrób”, „3 wyroby”, „5 wyrobów”. */
function products(n: number): string {
  const tens = n % 100
  const units = n % 10
  const few = units >= 2 && units <= 4 && (tens < 12 || tens > 14)
  return `${formatNumber(n)} ${n === 1 ? 'wyrób' : few ? 'wyroby' : 'wyrobów'}`
}

function isActive(state: B2bSizeMergeState | null): boolean {
  return state !== null && (state.status === 'queued' || state.status === 'running')
}

export function B2bSizeMergeModal({ account, canManage, onClose }: Props) {
  const [data, setData] = useState<B2bSizeMerge | null>(null)
  const [err, setErr] = useState('')
  const [actionErr, setActionErr] = useState('')
  const [busy, setBusy] = useState(false)
  const [withTenders, setWithTenders] = useState(false)

  const requestSeq = useRef(0)
  /** Znacznik „z przetargami” przejmujemy raz — z ostatniego przebiegu, potem decyduje użytkownik. */
  const withTendersInitialized = useRef(false)
  const linesBox = useRef<HTMLDivElement | null>(null)
  const stickToBottom = useRef(true)

  const applyResponse = useCallback((res: B2bSizeMerge) => {
    setData(res)
    if (!withTendersInitialized.current) {
      withTendersInitialized.current = true
      if (res.state) setWithTenders(res.state.with_tenders)
    }
  }, [])

  const load = useCallback(async () => {
    const seq = ++requestSeq.current
    try {
      const res = await api<B2bSizeMerge>(`/b2b-accounts/${account.id}/size-merge`)
      if (seq !== requestSeq.current) return
      applyResponse(res)
      setErr('')
    } catch (ex) {
      if (seq !== requestSeq.current) return
      setErr(ex instanceof Error ? ex.message : 'Nie udało się pobrać stanu scalania')
    }
  }, [account.id, applyResponse])

  const state = data?.state ?? null
  const active = isActive(state)
  const syncRunning = data?.sync_running ?? false
  const pollMs = active ? POLL_JOB_MS : syncRunning ? POLL_SYNC_MS : null

  useEffect(() => {
    void load()
  }, [load])

  useEffect(() => {
    if (pollMs === null) return
    const t = window.setInterval(() => void load(), pollMs)
    return () => window.clearInterval(t)
  }, [load, pollMs])

  useEffect(() => {
    function onKey(e: KeyboardEvent) {
      if (e.key === 'Escape') onClose()
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [onClose])

  const lines = state?.lines ?? []
  const linesKey = `${state?.started_at ?? ''}|${lines.length}|${lines[lines.length - 1] ?? ''}`

  useLayoutEffect(() => {
    const el = linesBox.current
    if (el && stickToBottom.current) {
      el.scrollTop = el.scrollHeight
    }
  }, [linesKey])

  function onLinesScroll() {
    const el = linesBox.current
    if (!el) return
    stickToBottom.current = el.scrollHeight - el.scrollTop - el.clientHeight < 24
  }

  const spread = data?.spread ?? null
  const blocked =
    busy || data === null || active || syncRunning || spread === null || spread.reason !== null || spread.total === 0

  /** Liczba „do scalenia” z ostatniego pełnego podglądu z tym samym znacznikiem „z przetargami”. */
  const previewCount =
    state !== null &&
    state.mode === 'preview' &&
    state.status === 'done' &&
    state.limit === null &&
    state.with_tenders === withTenders
      ? state.to_merge
      : null

  async function start(mode: 'preview' | 'apply', limit: number | null) {
    if (mode === 'apply') {
      const question =
        limit !== null
          ? `Scalić najwyżej ${products(limit)} (próba)? Łączone karty zostaną usunięte (kopia zapasowa na serwerze).`
          : previewCount !== null
            ? `Scalić ${products(previewCount)}? Łączone karty zostaną usunięte (kopia zapasowa na serwerze).`
            : `Scalić wszystkie wyroby z listy (${formatNumber(spread?.total ?? 0)}) bez wcześniejszego podglądu? Łączone karty zostaną usunięte (kopia zapasowa na serwerze).`
      if (!window.confirm(question)) return
    }
    setBusy(true)
    setActionErr('')
    try {
      const res = await api<B2bSizeMerge>(`/b2b-accounts/${account.id}/size-merge`, {
        method: 'POST',
        body: JSON.stringify({ mode, limit, with_tenders: withTenders }),
      })
      requestSeq.current++
      stickToBottom.current = true
      applyResponse(res)
    } catch (ex) {
      setActionErr(ex instanceof Error ? ex.message : 'Nie udało się zlecić scalania')
      await load()
    } finally {
      setBusy(false)
    }
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
          <div className="min-w-0">
            <p className="text-sm font-semibold text-slate-900">
              Scalanie rozmiarów — {account.connector_label ?? 'importer B2B'}
            </p>
            <p className="truncate text-xs text-slate-500">
              Konto: {account.username} · karty rozbite dawniej według ceny rozmiaru
            </p>
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
          <div className="space-y-1 rounded bg-slate-50 px-3 py-2 text-slate-600">
            <p>
              Wyrób rozbity na kilka kart według ceny rozmiaru zostaje scalony w jedną kartę — ta, która zostaje,
              dostaje wszystkie rozmiary z cenami.
            </p>
            <p>
              Pozostałe karty są usuwane po przeniesieniu przetargów, cenników, zdjęć i powiązań; przed zmianą
              powstaje kopia zapasowa. Nietypowe przypadki są pomijane z podaniem powodu.
            </p>
          </div>

          {err && (
            <p className="rounded bg-red-50 px-3 py-2 text-red-700">
              {err}
              {data ? ' Pokazuję ostatnio pobrany stan.' : ''}
            </p>
          )}
          {data === null && !err && <p className="text-slate-500">Ładowanie…</p>}

          {spread !== null &&
            (spread.reason !== null ? (
              <p className="rounded border border-amber-200 bg-amber-50 px-3 py-2 text-amber-900">{spread.reason}</p>
            ) : (
              <p className="text-slate-700">
                Lista z przebiegu #{spread.run_id ?? '—'}
                {spread.finished_at ? ` (${formatDateTime(spread.finished_at)})` : ''}:{' '}
                <b className="tabular-nums">{products(spread.total)}</b> na kilku kartach
                {spread.truncated && (
                  <span className="text-amber-700" title="Przebieg zapisał tylko część listy">
                    {' '}
                    · lista niepełna
                  </span>
                )}
              </p>
            ))}

          {syncRunning && (
            <p className="rounded border border-blue-200 bg-blue-50 px-3 py-2 text-blue-900">
              Trwa pobieranie cennika tego konta — scalanie będzie dostępne po jego zakończeniu.
            </p>
          )}

          {canManage && data !== null && (
            <label className="flex items-center gap-2 text-slate-700">
              <input
                type="checkbox"
                checked={withTenders}
                disabled={busy || active}
                onChange={(e) => setWithTenders(e.target.checked)}
              />
              Także z przetargami na droższym rozmiarze
            </label>
          )}

          {state !== null && <MergeStateDetails state={state} />}

          {state !== null && (
            <div>
              <p className="mb-1 font-medium text-slate-600">
                Wyroby{lines.length >= 300 ? ' (ostatnie 300)' : ''}
              </p>
              <div
                ref={linesBox}
                onScroll={onLinesScroll}
                className="max-h-64 overflow-auto rounded border border-slate-200 bg-slate-50 px-2 py-1.5 font-mono text-[11px] leading-snug"
              >
                {lines.length === 0 ? (
                  <p className="text-slate-400">Brak wpisów.</p>
                ) : (
                  lines.map((line, i) => (
                    <p
                      key={i}
                      className={`whitespace-pre-wrap ${/^[–-]/.test(line) ? 'text-amber-700' : 'text-slate-800'}`}
                    >
                      {line}
                    </p>
                  ))
                )}
              </div>
            </div>
          )}
        </div>

        <div className="flex flex-wrap items-center justify-end gap-2 border-t border-slate-200 px-4 py-3">
          {actionErr && <p className="mr-auto text-xs text-red-700">{actionErr}</p>}
          {!canManage && (
            <span className="mr-auto text-xs text-slate-500">Podgląd — brak uprawnień do scalania.</span>
          )}
          {canManage && (
            <>
              <button
                type="button"
                disabled={blocked}
                onClick={() => void start('preview', null)}
                className="rounded border border-blue-300 px-3 py-1.5 text-xs font-medium text-blue-700 hover:bg-blue-50 disabled:opacity-50"
                title="Przegląda całą listę bez zmian w kartach"
              >
                Podgląd
              </button>
              <button
                type="button"
                disabled={blocked}
                onClick={() => void start('apply', 10)}
                className="rounded border border-red-300 bg-red-50 px-3 py-1.5 text-xs font-medium text-red-800 hover:bg-red-100 disabled:opacity-50"
                title="Scala najwyżej 10 wyrobów — do sprawdzenia wyniku na kartach"
              >
                Scal 10 (próba)
              </button>
              <button
                type="button"
                disabled={blocked}
                onClick={() => void start('apply', null)}
                className="rounded bg-red-700 px-3 py-1.5 text-xs text-white hover:bg-red-800 disabled:opacity-50"
              >
                Scal wszystkie
              </button>
            </>
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

function MergeStateDetails({ state }: { state: B2bSizeMergeState }) {
  const active = isActive(state)
  const preview = state.mode === 'preview'
  const percent = state.total > 0 ? Math.min(100, Math.floor((state.processed / state.total) * 100)) : null

  const counters: [string, number][] = preview
    ? [
        ['Do scalenia', state.to_merge],
        ['Rozmiarów', state.sizes],
        ['Przetargów', state.tenders],
        ['SKU zmienione', state.sku_renamed],
      ]
    : [
        ['Do scalenia', state.to_merge],
        ['Scalono', state.merged],
        ['Rozmiarów', state.sizes],
        ['Przetargów', state.tenders],
        ['SKU zmienione', state.sku_renamed],
      ]

  return (
    <div className="space-y-2">
      <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-slate-600">
        <span className={`rounded px-1.5 py-0.5 text-[11px] font-medium ${STATUS_CLASS[state.status] ?? 'bg-slate-100 text-slate-700'}`}>
          {STATUS_LABEL[state.status] ?? state.status}
        </span>
        <span className="font-medium text-slate-700">
          {preview ? 'Podgląd — karty bez zmian' : state.limit !== null ? `Scalanie (próba, najwyżej ${formatNumber(state.limit)})` : 'Scalanie'}
        </span>
        {state.with_tenders && <span>także z przetargami na droższym rozmiarze</span>}
        <span>start: {formatDateTime(state.started_at)}</span>
        {state.finished_at && <span>koniec: {formatDateTime(state.finished_at)}</span>}
      </div>

      <div>
        <div className="mb-1 text-slate-700">
          <b className="tabular-nums">{formatNumber(state.processed)}</b> /{' '}
          <span className="tabular-nums">{formatNumber(state.total)}</span> wyrobów z listy
          {percent !== null && <span className="text-slate-500"> ({percent}%)</span>}
        </div>
        <div className="h-2 overflow-hidden rounded bg-slate-200">
          <div
            className={`h-full transition-all ${
              state.status === 'failed'
                ? 'bg-red-400'
                : state.status === 'queued'
                  ? 'bg-slate-400'
                  : active
                    ? 'animate-pulse bg-blue-500'
                    : 'bg-green-500'
            }`}
            style={{ width: `${active ? Math.max(2, percent ?? 0) : (percent ?? 0)}%` }}
          />
        </div>
      </div>

      <div className="grid grid-cols-3 gap-1.5 sm:grid-cols-5">
        {counters.map(([label, value]) => (
          <div key={label} className="rounded bg-slate-100 px-2 py-1">
            <p className="text-sm font-semibold tabular-nums text-slate-900">{formatNumber(value)}</p>
            <p className="truncate text-[10px] text-slate-500" title={label}>
              {label}
            </p>
          </div>
        ))}
      </div>

      {state.skipped.length > 0 && (
        <div>
          <p className="mb-1 font-medium text-slate-600">Pominięte</p>
          <table className="w-full text-left text-[11px]">
            <thead className="text-slate-500">
              <tr className="border-b border-slate-200">
                <th className="py-1 pr-2 font-medium">Powód</th>
                <th className="w-20 py-1 text-right font-medium">Wyrobów</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100 text-slate-700">
              {state.skipped.map((s) => (
                <tr key={s.reason}>
                  <td className="py-1 pr-2">{s.reason}</td>
                  <td className="py-1 text-right tabular-nums">{formatNumber(s.count)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {state.backup_path && (
        <p className="text-slate-600">
          Kopia zapasowa: <span className="break-all font-mono">{state.backup_path}</span>
        </p>
      )}

      {state.error && (
        <p className="whitespace-pre-wrap rounded bg-red-50 px-3 py-2 text-red-700">{state.error}</p>
      )}
    </div>
  )
}
