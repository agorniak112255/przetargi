import { useCallback, useEffect, useRef, useState, type ReactNode } from 'react'
import { Link } from 'react-router-dom'
import {
  fetchSystemGaps,
  fetchSystemQueues,
  fetchSystemStatus,
  muteSystemAlert,
  unmuteSystemAlert,
  type SystemAlertRow,
  type SystemGapKind,
  type SystemGapRow,
  type SystemQueues,
  type SystemStatus,
} from '../lib/api'

/**
 * Administracja › Stan systemu (makieta, ekran 15): kafle, „Wymaga uwagi” z wyciszaniem e-maila, przebiegi zadań
 * (godziny czasu polskiego) i „Dane do uzupełnienia” z rozwijaną listą.
 */

/** Pola, które serwer dokłada ponad typ z lib/api (SystemStatusService) — opcjonalne, gdyby ich zabrakło. */
type TaskRow = SystemStatus['tasks'][number] & { nightly?: boolean; only_failures?: boolean }
type AlertRow = SystemAlertRow & { failures?: number; last_failed_at?: string | null }
type ModelTile = NonNullable<SystemStatus['tiles']['model']> & { unavailable_24h?: number }

const REFRESH_MS = 60_000
const TZ = 'Europe/Warsaw'

function plural(n: number, one: string, few: string, many: string): string {
  if (n === 1) return one
  const tens = n % 100
  const last = n % 10
  return last >= 2 && last <= 4 && (tens < 12 || tens > 14) ? few : many
}

const nf = (n: number) => n.toLocaleString('pl-PL')

/** „3.10.2026, 9:14” w czasie polskim. */
function when(iso: string | null | undefined): string {
  if (!iso) return '—'
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return '—'
  return d.toLocaleString('pl-PL', { timeZone: TZ, day: 'numeric', month: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' })
}

/** „dziś 6:10” albo „2.10, 6:10” w czasie polskim. */
function shortWhen(iso: string | null | undefined): string {
  if (!iso) return '—'
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return '—'
  const day = (x: Date) => x.toLocaleDateString('pl-PL', { timeZone: TZ })
  const hm = d.toLocaleTimeString('pl-PL', { timeZone: TZ, hour: 'numeric', minute: '2-digit' })
  if (day(d) === day(new Date())) return `dziś ${hm}`
  return `${d.toLocaleDateString('pl-PL', { timeZone: TZ, day: 'numeric', month: 'numeric' })}, ${hm}`
}

/** Czas trwania pełnymi słowami: „40 sekund”, „6 minut”, „1 godzina 5 minut”. */
function duration(seconds: number): string {
  const s = Math.max(0, Math.round(seconds))
  if (s < 60) return `${s} ${plural(s, 'sekunda', 'sekundy', 'sekund')}`
  const minutes = Math.round(s / 60)
  if (minutes < 60) return `${minutes} ${plural(minutes, 'minuta', 'minuty', 'minut')}`
  const h = Math.floor(minutes / 60)
  const m = minutes % 60
  return `${h} ${plural(h, 'godzina', 'godziny', 'godzin')}${m > 0 ? ` ${m} ${plural(m, 'minuta', 'minuty', 'minut')}` : ''}`
}

function Tile({ label, value, tone, sub }: { label: string; value: string; tone?: 'ok' | 'bad' | 'warn'; sub: string }) {
  const color = tone === 'ok' ? 'text-emerald-700' : tone === 'bad' ? 'text-red-700' : tone === 'warn' ? 'text-amber-700' : 'text-slate-900'
  return (
    <div className="rounded-xl bg-white p-4 shadow-sm">
      <div className="text-[11px] font-medium tracking-wide text-slate-500 uppercase">{label}</div>
      <div className={`text-[26px] leading-tight font-semibold tabular-nums ${color}`}>{value}</div>
      <div className="text-xs text-slate-500">{sub}</div>
    </div>
  )
}

function Chip({ tone, children, title }: { tone: 'ok' | 'bad' | 'warn' | 'muted' | 'blue'; children: ReactNode; title?: string }) {
  const cls = {
    ok: 'bg-emerald-50 text-emerald-700',
    bad: 'bg-red-50 text-red-700',
    warn: 'bg-amber-50 text-amber-700',
    blue: 'bg-blue-50 text-blue-700',
    muted: 'bg-slate-100 text-slate-600',
  }[tone]
  return (
    <span title={title} className={`inline-block rounded-full px-2 py-0.5 text-[11px] font-semibold whitespace-nowrap ${cls}`}>
      {children}
    </span>
  )
}

function Card({ title, lead, children }: { title: string; lead?: string; children: ReactNode }) {
  return (
    <section className="min-w-0 rounded-xl bg-white p-4 shadow-sm">
      <h2 className="text-base font-semibold text-slate-800">{title}</h2>
      {lead && <p className="mt-0.5 text-xs text-slate-500">{lead}</p>}
      <div className="mt-3">{children}</div>
    </section>
  )
}

function tasksTile(s: SystemStatus) {
  const t = s.tiles.tasks
  const tone = t.total === 0 ? undefined : t.ok === t.total ? 'ok' : 'bad'
  return (
    <Tile
      label="Zadania nocne"
      value={`${nf(t.ok)} z ${nf(t.total)}`}
      tone={tone}
      sub={t.last_finished_at ? `ostatnie ${shortWhen(t.last_finished_at)}` : 'jeszcze żaden przebieg nie jest zapisany'}
    />
  )
}

function b2bTile(s: SystemStatus) {
  const b = s.tiles.b2b
  const other = b.total - b.ok - b.failing
  const sub =
    b.total === 0
      ? 'żadne konto nie ma harmonogramu'
      : b.failing > 0
        ? `${nf(b.failing)} ${plural(b.failing, 'nie działa', 'nie działają', 'nie działa')}`
        : other > 0
          ? `${nf(other)} w trakcie albo jeszcze nie sprawdzone`
          : 'wszystkie działają'
  return <Tile label="Konta dostawców" value={`${nf(b.ok)} z ${nf(b.total)}`} tone={b.failing > 0 ? 'bad' : b.total > 0 && other === 0 ? 'ok' : undefined} sub={sub} />
}

function queueTile(s: SystemStatus) {
  const q = s.tiles.inquiries_queue
  return (
    <Tile
      label="Zapytania czekające na analizę"
      value={nf(q.waiting)}
      tone={q.oldest_wait_seconds !== null && q.oldest_wait_seconds > 30 * 60 ? 'warn' : undefined}
      sub={q.oldest_wait_seconds !== null ? `najdłużej czeka ${duration(q.oldest_wait_seconds)}` : 'nic nie czeka'}
    />
  )
}

function modelTile(s: SystemStatus) {
  const m = s.tiles.model as ModelTile | null
  if (!m) return <Tile label="Dopasowywanie produktów" value="brak danych" sub="nikt jeszcze nie wyszukiwał produktów" />
  if (m.searches_24h === 0) {
    return <Tile label="Dopasowywanie produktów" value="bez ruchu" sub={`ostatnie wyszukiwanie ${shortWhen(m.last_at)}`} />
  }
  const unavailable = m.unavailable_24h ?? 0
  const down = unavailable > 0 && unavailable >= m.searches_24h
  const avg = m.avg_seconds !== null ? `odpowiedź średnio po ${m.avg_seconds.toLocaleString('pl-PL')} ${plural(Math.round(m.avg_seconds), 'sekundzie', 'sekundach', 'sekundach')}` : ''
  const sub = [
    avg,
    unavailable > 0 ? `model nie odpowiedział ${nf(unavailable)} ${plural(unavailable, 'raz', 'razy', 'razy')} w ciągu doby` : `${nf(m.searches_24h)} ${plural(m.searches_24h, 'wyszukiwanie', 'wyszukiwania', 'wyszukiwań')} w ciągu doby`,
  ]
    .filter(Boolean)
    .join(' · ')
  return <Tile label="Dopasowywanie produktów" value={down ? 'nie odpowiada' : 'działa'} tone={down ? 'bad' : unavailable > 0 ? 'warn' : 'ok'} sub={sub} />
}

const QUEUES_REFRESH_MS = 10_000

/**
 * „Kolejki teraz”: kto zajmuje pracowników każdej kolejki i czyja praca czeka. 05.10.2026 opisy cennika Ansella stały
 * 10 minut za 530 tłumaczeniami kart SIR w tej samej kolejce, a w panelu nie było tego widać.
 */
function QueuesCard() {
  const [data, setData] = useState<SystemQueues | null>(null)
  const [err, setErr] = useState('')

  useEffect(() => {
    let alive = true
    const load = async () => {
      try {
        const next = await fetchSystemQueues()
        if (alive) {
          setData(next)
          setErr('')
        }
      } catch (ex) {
        if (alive) setErr(ex instanceof Error ? ex.message : 'Nie udało się wczytać kolejek.')
      }
    }
    void load()
    const timer = window.setInterval(() => {
      if (document.visibilityState === 'visible') void load()
    }, QUEUES_REFRESH_MS)
    return () => {
      alive = false
      window.clearInterval(timer)
    }
  }, [])

  const busy = (data?.queues ?? []).filter((q) => q.running + q.waiting + q.delayed > 0)
  const idle = (data?.queues ?? []).filter((q) => q.running + q.waiting + q.delayed === 0)

  return (
    <Card title="Kolejki teraz" lead={`Co robią pracownicy kolejek i co czeka. Odświeżanie co 10 sekund${data ? ` · sprawdzono ${shortWhen(data.checked_at)}` : ''}.`}>
      {err && <p className="mb-2 text-sm text-red-600">{err}</p>}
      {!data && !err && <p className="text-xs text-slate-500">Ładowanie…</p>}
      {data && (
        <div className="space-y-3">
          {busy.length === 0 && <p className="text-sm text-emerald-700">Wszystkie kolejki są puste — nic nie czeka.</p>}
          {busy.map((q) => {
            const runningTypes = q.jobs.filter((j) => j.running > 0).map((j) => j.label)
            const blocked = q.jobs.filter((j) => j.running === 0 && j.waiting > 0)
            return (
              <div key={q.key} className="rounded-lg border border-slate-200 p-3">
                <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                  <span className="font-medium text-slate-800">{q.label}</span>
                  <span className="text-sm text-slate-700 tabular-nums">
                    w trakcie <b>{nf(q.running)}</b> · czeka <b>{nf(q.waiting)}</b>
                    {q.delayed > 0 && <> · odłożone na później {nf(q.delayed)}</>}
                  </span>
                  {q.oldest_wait_seconds !== null && <span className="text-xs text-slate-500">najdłużej czeka {duration(q.oldest_wait_seconds)}</span>}
                  {q.over_timeout > 0 && (
                    <Chip tone="warn" title="Zadanie trwa dłużej niż jego limit czasu — pracownik zostaje przerwany, a zadanie samo wraca do kolejki.">
                      {nf(q.over_timeout)} ponad limit czasu
                    </Chip>
                  )}
                </div>
                <ul className="mt-2 text-[13px]">
                  {q.jobs.map((j) => (
                    <li key={j.type} className="flex flex-wrap items-baseline gap-x-2 border-b border-slate-100 py-1 last:border-0">
                      <span className="min-w-0 text-slate-800">{j.label}</span>
                      <span className="text-slate-600 tabular-nums">
                        {j.running > 0 ? `${nf(j.running)} w trakcie` : 'nic w trakcie'}
                        {j.waiting > 0 ? `, ${nf(j.waiting)} czeka` : ''}
                      </span>
                      {j.sources.length > 0 && (
                        <span className="text-xs text-slate-500">{j.sources.map((s) => `${s.label} ${nf(s.count)}`).join(' · ')}</span>
                      )}
                    </li>
                  ))}
                </ul>
                {blocked.length > 0 && runningTypes.length > 0 && (
                  <p className="mt-2 rounded bg-amber-50 px-2 py-1 text-xs text-amber-800">
                    Czeka: {blocked.map((j) => `${j.label} (${nf(j.waiting)})`).join(', ')} — wszyscy pracownicy tej kolejki zajmują się teraz:{' '}
                    {runningTypes.join(', ')}.
                  </p>
                )}
                {q.sampled && <p className="mt-1 text-xs text-slate-400">Rodzaje zadań policzone z pierwszych 5 000 w kolejce.</p>}
              </div>
            )
          })}
          {idle.length > 0 && <p className="text-xs text-slate-500">Puste: {idle.map((q) => q.label).join(', ')}.</p>}

          {data.batches.length > 0 && (
            <div>
              <h3 className="mb-1 text-sm font-semibold text-slate-700">Pobieranie opisów w toku</h3>
              <ul className="text-[13px]">
                {data.batches.map((b) => (
                  <li key={b.id} className="flex flex-wrap items-baseline gap-x-2 border-b border-slate-100 py-1 last:border-0">
                    <span className="font-medium text-slate-800">#{b.id}</span>
                    <span className="tabular-nums">
                      gotowe {nf(b.done)} z {nf(b.total)}
                      {b.failed > 0 ? `, błędów ${nf(b.failed)}` : ''}
                    </span>
                    {b.message && <span className="text-xs text-slate-500">{b.message}</span>}
                    {b.created_at && <span className="text-xs text-slate-400">od {shortWhen(b.created_at)}</span>}
                  </li>
                ))}
              </ul>
            </div>
          )}

          {data.failed_24h.length > 0 && (
            <div>
              <h3 className="mb-1 text-sm font-semibold text-slate-700">Nieudane zadania w ostatniej dobie</h3>
              <ul className="text-[13px]">
                {data.failed_24h.map((f) => (
                  <li key={f.type} className="border-b border-slate-100 py-1 last:border-0">
                    <span className="text-slate-800">{f.label}</span> <b className="tabular-nums">{nf(f.count)}</b>
                    {f.last_at && <span className="text-xs text-slate-500"> · ostatnie {shortWhen(f.last_at)}</span>}
                    {f.last_error && <div className="truncate text-xs text-slate-500" title={f.last_error}>{f.last_error}</div>}
                  </li>
                ))}
              </ul>
            </div>
          )}
        </div>
      )}
    </Card>
  )
}

function AlertsCard({ alerts, onChanged }: { alerts: AlertRow[]; onChanged: (a: AlertRow) => void }) {
  const [busy, setBusy] = useState<number | null>(null)
  const [err, setErr] = useState('')

  async function toggle(a: AlertRow) {
    setBusy(a.id)
    setErr('')
    try {
      onChanged(a.muted ? await unmuteSystemAlert(a.id) : await muteSystemAlert(a.id))
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Nie udało się zapisać.')
    } finally {
      setBusy(null)
    }
  }

  return (
    <Card title="Wymaga uwagi" lead="Błędy zadań i kont dostawców. Administrator dostaje jeden e-mail na każdy problem; wyciszony problem nie wysyła e-maili.">
      {err && <p className="mb-2 text-sm text-red-600">{err}</p>}
      {alerts.length === 0 ? (
        <p className="text-sm text-emerald-700">Wszystko działa — nic nie wymaga uwagi.</p>
      ) : (
        <div className="overflow-x-auto">
          <table className="app-table min-w-full text-left text-sm">
            <thead className="border-b bg-slate-50 text-xs text-slate-500 uppercase">
              <tr>
                <th className="px-3 py-2">Co</th>
                <th className="px-3 py-2">Od kiedy</th>
                <th className="px-3 py-2">Ostatni komunikat</th>
                <th className="px-3 py-2">E-mail do administratora</th>
                <th className="px-3 py-2" />
              </tr>
            </thead>
            <tbody>
              {alerts.map((a) => (
                <tr key={a.id} className="border-b align-top last:border-0">
                  <td className="px-3 py-2 font-medium text-slate-800">
                    {a.title}
                    {(a.failures ?? 0) > 1 && <div className="text-xs font-normal text-slate-500">błędów: {nf(a.failures ?? 0)}</div>}
                  </td>
                  <td className="px-3 py-2 whitespace-nowrap text-slate-700">
                    {/* serwer nie zna początku problemu (np. incydent bez zapisanego pierwszego błędu) */}
                    {a.since ? when(a.since) : <span className="text-slate-400">nie wiadomo</span>}
                  </td>
                  <td className="max-w-md px-3 py-2 text-slate-600">
                    <span className="line-clamp-3 whitespace-pre-line">{a.last_message ?? '—'}</span>
                  </td>
                  <td className="px-3 py-2 whitespace-nowrap text-slate-700">
                    {a.muted ? <span className="text-slate-500">wyciszony</span> : a.emailed_at ? `wysłany ${shortWhen(a.emailed_at)}` : <span className="text-amber-700">nie wysłano</span>}
                  </td>
                  <td className="px-3 py-2 text-right whitespace-nowrap">
                    {a.url && (
                      <Link to={a.url} className="mr-2 rounded border border-slate-300 bg-white px-2.5 py-1 text-xs text-slate-700 hover:bg-slate-50 hover:no-underline">
                        Otwórz konta dostawców
                      </Link>
                    )}
                    <button
                      type="button"
                      disabled={busy === a.id}
                      onClick={() => void toggle(a)}
                      className="rounded border border-slate-300 bg-white px-2.5 py-1 text-xs text-slate-700 hover:bg-slate-50 disabled:opacity-50"
                    >
                      {a.muted ? 'Włącz e-mail' : 'Wycisz e-mail'}
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </Card>
  )
}

function resultChip(t: TaskRow): ReactNode {
  if (!t.enabled) return <Chip tone="muted">wyłączone</Chip>
  const last = t.last
  if (!last) return t.only_failures ? <Chip tone="ok">bez błędów</Chip> : <Chip tone="muted">jeszcze nie uruchomione</Chip>
  if (last.status === 'running') return <Chip tone="blue">trwa od {shortWhen(last.started_at)}</Chip>
  if (last.status === 'failed') {
    return (
      <Chip tone="bad" title={last.output_tail ?? undefined}>
        {t.only_failures ? `ostatni błąd ${shortWhen(last.finished_at)}` : 'błąd'}
      </Chip>
    )
  }
  return <Chip tone="ok">w porządku</Chip>
}

function TasksTable({ rows, frequent }: { rows: TaskRow[]; frequent?: boolean }) {
  return (
    <div className="overflow-x-auto">
      <table className="app-table min-w-[440px] text-left text-sm">
        <thead className="border-b bg-slate-50 text-xs text-slate-500 uppercase">
          <tr>
            <th className="px-3 py-2">Zadanie</th>
            <th className="px-3 py-2">Kiedy</th>
            {!frequent && <th className="px-3 py-2 text-right">Trwało</th>}
            <th className="px-3 py-2">Wynik</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((t) => {
            const last = t.last
            const failedTail = last?.status === 'failed' && last.output_tail ? last.output_tail.slice(-400) : null
            return (
              <tr key={t.task} className={`border-b align-top last:border-0 ${t.enabled ? '' : 'text-slate-400'}`}>
                <td className="px-3 py-2">
                  {t.label}
                  {last?.finished_at && !frequent && <div className="text-xs text-slate-400">ostatnio {shortWhen(last.finished_at)}</div>}
                  {failedTail && <pre className="mt-1 max-w-md overflow-x-auto rounded bg-red-50 p-2 text-[11px] whitespace-pre-wrap text-red-800">{failedTail}</pre>}
                </td>
                <td className="px-3 py-2 whitespace-nowrap">{t.schedule_pl}</td>
                {!frequent && (
                  <td className="px-3 py-2 text-right whitespace-nowrap tabular-nums">
                    {last?.duration_ms != null ? duration(last.duration_ms / 1000) : '—'}
                  </td>
                )}
                <td className="px-3 py-2">{resultChip(t)}</td>
              </tr>
            )
          })}
        </tbody>
      </table>
    </div>
  )
}

function GapsCard({ gaps }: { gaps: SystemStatus['gaps'] }) {
  const [open, setOpen] = useState<SystemGapKind | null>(null)
  const [rows, setRows] = useState<SystemGapRow[] | null>(null)
  const [err, setErr] = useState('')
  /** ostatnio otwarty rodzaj — spóźniona odpowiedź dla innego rodzaju (albo po zamknięciu) jest pomijana */
  const wanted = useRef<SystemGapKind | null>(null)

  async function toggle(kind: SystemGapKind) {
    if (open === kind) {
      wanted.current = null
      setOpen(null)
      return
    }
    wanted.current = kind
    setOpen(kind)
    setRows(null)
    setErr('')
    try {
      const list = await fetchSystemGaps(kind)
      if (wanted.current === kind) setRows(list)
    } catch (ex) {
      if (wanted.current === kind) setErr(ex instanceof Error ? ex.message : 'Nie udało się wczytać listy.')
    }
  }

  return (
    <Card title="Dane do uzupełnienia" lead="Od nich zależą raport skuteczności przetargów, pobieranie wyników z Biuletynu, podpowiedzi z ERP XL i cele handlowców.">
      <ul>
        {gaps.map((g) => (
          <li key={g.kind} className="border-b border-slate-100 py-2 last:border-0">
            <div className="flex items-center gap-3 text-sm">
              <span className={`h-2 w-2 shrink-0 rounded-full ${g.count > 0 ? 'bg-amber-500' : 'bg-emerald-500'}`} aria-hidden="true" />
              <span className="min-w-0 flex-1">{g.label}</span>
              <b className="tabular-nums">{nf(g.count)}</b>
              <button
                type="button"
                disabled={g.count === 0}
                onClick={() => void toggle(g.kind)}
                className="rounded border border-slate-300 bg-white px-2.5 py-1 text-xs text-slate-700 hover:bg-slate-50 disabled:opacity-40"
              >
                {open === g.kind ? 'Ukryj' : 'Pokaż'}
              </button>
            </div>
            {open === g.kind && (
              <div className="mt-2 rounded-lg bg-slate-50 p-2">
                {err && <p className="text-sm text-red-600">{err}</p>}
                {!err && rows === null && <p className="text-xs text-slate-500">Ładowanie…</p>}
                {rows && (
                  <>
                    <ul className="max-h-80 overflow-y-auto text-[13px]">
                      {rows.map((r) => (
                        <li key={r.id} className="flex flex-wrap items-baseline gap-x-2 border-b border-slate-200 py-1 last:border-0">
                          {r.url ? (
                            <Link to={r.url} className="app-link text-blue-700 hover:underline">
                              {r.label}
                            </Link>
                          ) : (
                            <span>{r.label}</span>
                          )}
                          {r.detail && <span className="text-xs text-slate-500">{r.detail}</span>}
                        </li>
                      ))}
                    </ul>
                    {rows.length < g.count && (
                      <p className="mt-1 text-xs text-slate-500">
                        Pokazano pierwsze {nf(rows.length)} z {nf(g.count)}.
                      </p>
                    )}
                  </>
                )}
              </div>
            )}
          </li>
        ))}
      </ul>
    </Card>
  )
}

export function AdminSystemStatus() {
  const [data, setData] = useState<SystemStatus | null>(null)
  const [err, setErr] = useState('')
  const [busy, setBusy] = useState(false)

  const load = useCallback(async () => {
    setBusy(true)
    setErr('')
    try {
      setData(await fetchSystemStatus())
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Nie udało się wczytać stanu systemu.')
    } finally {
      setBusy(false)
    }
  }, [])

  useEffect(() => {
    void load()
    const timer = window.setInterval(() => {
      if (document.visibilityState === 'visible') void load()
    }, REFRESH_MS)
    return () => window.clearInterval(timer)
  }, [load])

  const tasks = (data?.tasks ?? []) as TaskRow[]
  // bez pola „nightly” (starszy serwer) wszystkie zadania lądują w jednej tabeli
  const nightly = tasks.filter((t) => t.nightly !== false)
  const frequent = tasks.filter((t) => t.nightly === false)

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-3">
        <h2 className="text-lg font-semibold text-slate-900">Stan systemu</h2>
        <span className="text-xs text-slate-500">{data ? `sprawdzono ${when(data.checked_at)} · odświeżanie co minutę` : busy ? 'Ładowanie…' : ''}</span>
        <button
          type="button"
          disabled={busy}
          onClick={() => void load()}
          className="ml-auto rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700 disabled:opacity-50"
        >
          Odśwież
        </button>
      </div>

      {err && <p className="text-sm text-red-600">{err}</p>}

      {data && (
        <>
          {!data.scheduler.ok && (
            <p className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">
              Harmonogram zadań nie daje sygnału
              {data.scheduler.last_seen_at ? ` od ${when(data.scheduler.last_seen_at)}` : ''}. Zadania nocne i pobieranie z kont dostawców nie ruszą, dopóki
              na serwerze nie zacznie znowu działać uruchamianie zadań co minutę — przekaż to osobie, która opiekuje się serwerem.
            </p>
          )}

          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
            {tasksTile(data)}
            {b2bTile(data)}
            {queueTile(data)}
            {modelTile(data)}
          </div>

          <QueuesCard />

          <AlertsCard
            alerts={data.alerts as AlertRow[]}
            onChanged={(a) => setData((d) => (d ? { ...d, alerts: d.alerts.map((x) => (x.id === a.id ? { ...x, ...a } : x)) } : d))}
          />

          <div className="grid grid-cols-1 gap-4 xl:grid-cols-2">
            <Card title="Zadania nocne" lead="Godziny czasu polskiego.">
              <TasksTable rows={nightly} />
              {frequent.length > 0 && (
                <>
                  <h3 className="mt-4 mb-1 text-sm font-semibold text-slate-700">Zadania częste</h3>
                  <p className="mb-2 text-xs text-slate-500">Zadania uruchamiane co minutę, co 10 i co 15 minut zapisują tylko błędy — bez wpisu o każdym udanym przebiegu.</p>
                  <TasksTable rows={frequent} frequent />
                </>
              )}
            </Card>
            <GapsCard gaps={data.gaps} />
          </div>
        </>
      )}
    </div>
  )
}
