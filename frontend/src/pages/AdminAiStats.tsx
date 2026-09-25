import { Fragment, useCallback, useEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../lib/api'

type AiTask = 'product_search' | 'tender_match' | 'tender_item' | 'inquiry' | 'battlecard'

type ModelState = 'ranked' | 'empty' | 'unavailable' | 'skipped'

type OutlierKey = 'top_tokens' | 'no_cards' | 'small_pool' | 'model_failed' | 'model_empty'

type TokenStats = {
  sum: number
  avg: number | null
  p50: number | null
  p95: number | null
  max: number | null
}

type AiStatsAgg = {
  task: AiTask
  events: number
  /** Pozycje z zapisanym kosztem (prompt_tokens); z nich liczą się średnie i percentyle tokenów. */
  with_usage: number
  /** Pozycje, w których model oceniał karty (rank_calls ≥ 1). */
  model_asked: number
  prompt_tokens: TokenStats
  completion_tokens: TokenStats
  llm_calls: { avg: number | null; max: number | null }
  rank_cards: { avg: number | null; p50: number | null; min: number | null }
  candidate_count: { avg: number | null; p50: number | null }
  states: Record<ModelState, number>
  unavailable_pct: number | null
  empty_pct: number | null
  fallback: number
  /** Tylko wyszukiwarka — fale przetargu mają wspólny czas. */
  duration_ms: { avg: number | null; p50: number | null; p95: number | null } | null
}

type AiStatsStage = {
  stage: string
  cards?: number | null
  source?: string | null
  prompt_tokens?: number | null
  completion_tokens?: number | null
  reasoning_tokens?: number | null
  calls?: number | null
  failed_calls?: number | null
}

type AiStatsRow = {
  id: number
  created_at: string | null
  task: AiTask
  query: string
  model_state: ModelState | null
  prompt_tokens: number | null
  completion_tokens: number | null
  reasoning_tokens: number | null
  llm_calls: number | null
  rank_calls: number | null
  rank_card_count: number | null
  candidate_count: number
  result_count: number
  model: string | null
  provider: string | null
  fallback: boolean | null
  ai_note: string | null
  stages: AiStatsStage[]
  context: {
    type: 'tender' | 'inquiry'
    id: number
    label: string
    url: string | null
    line_nos: number[]
  } | null
  run_id: string | null
  user: { id: number; name: string } | null
}

type AiStatsResponse = {
  range: { from: string; to: string; days: number; timezone: string }
  recording_enabled: boolean
  usage_since: string | null
  thresholds: { small_pool: number }
  summary: AiStatsAgg[]
  daily: (AiStatsAgg & { date: string })[]
  histogram: { edges: number[]; counts: Partial<Record<AiTask, number[]>> }
  outliers: Record<OutlierKey, AiStatsRow[]>
}

const TASK_LABEL: Record<AiTask, string> = {
  product_search: 'Wyszukiwarka',
  tender_match: 'Przetarg — Dopasuj wszystkie',
  tender_item: 'Przetarg — pojedyncza pozycja',
  inquiry: 'Zapytania z poczty',
  battlecard: 'Zamienniki',
}

const TASKS: AiTask[] = ['product_search', 'tender_match', 'tender_item', 'inquiry', 'battlecard']

const STATE_LABEL: Record<ModelState, string> = {
  ranked: 'ocenił',
  empty: 'pusta ocena',
  unavailable: 'awaria',
  skipped: 'bez oceny',
}

const STATE_CLASS: Record<ModelState, string> = {
  ranked: 'bg-green-100 text-green-800',
  empty: 'bg-amber-100 text-amber-800',
  unavailable: 'bg-red-100 text-red-800',
  skipped: 'bg-slate-200 text-slate-700',
}

const STAGE_LABEL: Record<string, string> = {
  understand: 'Zrozumienie zapytania',
  rank: 'Ocena kart',
  rewrite: 'Przeformułowanie',
  rank_after_rewrite: 'Ocena po przeformułowaniu',
}

const OUTLIER_TABS: { key: OutlierKey; label: string }[] = [
  { key: 'top_tokens', label: 'Najdroższe' },
  { key: 'no_cards', label: 'Bez kart do oceny' },
  { key: 'small_pool', label: 'Mało kart' },
  { key: 'model_failed', label: 'Awaria modelu' },
  { key: 'model_empty', label: 'Pusta ocena' },
]

const DAY_OPTIONS = [7, 30, 90]
const LIMIT_OPTIONS = [20, 50, 100]
const SMALL_POOL_OPTIONS = [8, 12, 16, 20, 24]

function fmtInt(n: number | null | undefined): string {
  return n == null ? '—' : Math.round(n).toLocaleString('pl-PL')
}

function fmtDec(n: number | null | undefined): string {
  return n == null ? '—' : n.toLocaleString('pl-PL', { maximumFractionDigits: 1 })
}

function fmtPct(n: number | null | undefined): string {
  return n == null ? '—' : `${fmtDec(n)}%`
}

function fmtSeconds(ms: number | null | undefined): string {
  return ms == null ? '—' : `${(ms / 1000).toLocaleString('pl-PL', { maximumFractionDigits: 1 })} s`
}

function fmtDay(ymd: string): string {
  const [y, m, d] = ymd.split('-')
  return d && m && y ? `${d}.${m}.${y}` : ymd
}

function fmtWhen(iso: string | null | undefined): string {
  if (!iso) return '—'
  const t = new Date(iso)
  return Number.isNaN(t.getTime()) ? iso : t.toLocaleString('pl-PL')
}

function binLabel(edges: number[], i: number): string {
  const k = (v: number) => (v === 0 ? '0' : (v / 1000).toLocaleString('pl-PL'))
  return i === edges.length - 1 ? `≥ ${k(edges[i])} tys.` : `${k(edges[i])}–${k(edges[i + 1])} tys.`
}

function StateBadge({ state }: { state: ModelState | null }) {
  if (!state) return <span className="text-slate-500">—</span>
  return (
    <span className={`whitespace-nowrap rounded px-1.5 py-0.5 text-[11px] font-medium ${STATE_CLASS[state] ?? 'bg-slate-100 text-slate-700'}`}>
      {STATE_LABEL[state] ?? state}
    </span>
  )
}

function outlierHint(key: OutlierKey, smallPool: number): string {
  switch (key) {
    case 'top_tokens':
      return 'Pozycje z największą liczbą tokenów wejścia (suma wszystkich odpowiedzi modelu dla pozycji).'
    case 'no_cards':
      return 'Model nie dostał żadnej karty do oceny i pozycja została bez wyniku — zwykle pusta pula z wyszukiwania.'
    case 'small_pool':
      return `Model oceniał mniej niż ${smallPool} kart — mało kandydatów z wyszukiwania. Najmniejsza pula na górze.`
    case 'model_failed':
      return 'Model nie odpowiedział (limit, błąd serwera, przekroczony czas) — pozycja bez jego oceny.'
    case 'model_empty':
      return 'Model odpowiedział, ale nie wskazał żadnej karty.'
  }
}

export function AdminAiStats() {
  const [days, setDays] = useState(7)
  const [task, setTask] = useState<AiTask | ''>('')
  const [limit, setLimit] = useState(20)
  const [smallPool, setSmallPool] = useState(24)
  const [data, setData] = useState<AiStatsResponse | null>(null)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const [tab, setTab] = useState<OutlierKey>('top_tokens')
  const [expanded, setExpanded] = useState<Set<number>>(() => new Set())
  const requestId = useRef(0)

  const load = useCallback(async () => {
    const id = ++requestId.current
    setBusy(true)
    setErr('')
    try {
      const params = new URLSearchParams({
        days: String(days),
        limit: String(limit),
        small_pool: String(smallPool),
      })
      if (task) params.set('task', task)
      const res = await api<AiStatsResponse>(`/admin/ai-stats?${params}`)
      // Szybkie przełączanie zakresu — spóźniona odpowiedź nie nadpisuje nowszej.
      if (id !== requestId.current) return
      setData(res)
      setExpanded(new Set())
    } catch (ex) {
      if (id === requestId.current) setErr(ex instanceof Error ? ex.message : 'Błąd')
    } finally {
      if (id === requestId.current) setBusy(false)
    }
  }, [days, task, limit, smallPool])

  useEffect(() => {
    void load()
  }, [load])

  function toggle(id: number) {
    setExpanded((prev) => {
      const next = new Set(prev)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })
  }

  const summary = data?.summary ?? []
  const withUsage = summary.filter((a) => a.with_usage > 0)
  const productSearch = summary.find((a) => a.task === 'product_search' && a.duration_ms && a.events > 0)
  const rows = data?.outliers[tab] ?? []
  const edges = data?.histogram.edges ?? []

  return (
    <div className="space-y-5">
      <div>
        <p className="text-sm text-slate-600">
          Koszt (tokeny) i przebieg wyszukiwań AI na pozycję: ile kart zobaczył model, jak duża była pula i czy model
          odpowiedział. Listy niżej pomagają znaleźć pozycje drogie albo źle obsłużone.
        </p>
        {data && (
          <p className="mt-1 text-xs text-slate-500">
            Zakres: {fmtDay(data.range.from)} – {fmtDay(data.range.to)} (czas polski) ·{' '}
            {data.usage_since
              ? `koszt zapisywany od ${fmtWhen(data.usage_since)}`
              : 'koszt nie jest jeszcze zapisywany — brak pozycji z tokenami'}
          </p>
        )}
      </div>

      {data && !data.recording_enabled && (
        <p className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
          Zapis zdarzeń wyszukiwania jest wyłączony (AI_SEARCH_EVENTS_ENABLED) — nowe pozycje nie trafiają do statystyk.
        </p>
      )}

      <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
        <div className="flex flex-wrap items-center gap-1">
          <span className="mr-1 text-xs text-slate-500">Zakres</span>
          {DAY_OPTIONS.map((d) => (
            <button
              key={d}
              type="button"
              onClick={() => setDays(d)}
              className={`rounded px-2 py-1 text-xs ${
                days === d
                  ? 'bg-slate-800 text-white'
                  : 'border border-slate-200 bg-white text-slate-700 hover:bg-slate-50'
              }`}
            >
              {d} dni
            </button>
          ))}
        </div>
        <div className="flex flex-wrap items-center gap-1">
          <span className="mr-1 text-xs text-slate-500">Rodzaj</span>
          {([''] as (AiTask | '')[]).concat(TASKS).map((t) => (
            <button
              key={t || 'all'}
              type="button"
              onClick={() => setTask(t)}
              className={`rounded px-2 py-1 text-xs ${
                task === t
                  ? 'bg-slate-800 text-white'
                  : 'border border-slate-200 bg-white text-slate-700 hover:bg-slate-50'
              }`}
            >
              {t ? TASK_LABEL[t] : 'Wszystkie'}
            </button>
          ))}
        </div>
        <button
          type="button"
          disabled={busy}
          onClick={() => void load()}
          className="rounded bg-slate-800 px-3 py-1 text-xs text-white hover:bg-slate-700 disabled:opacity-50"
        >
          {busy ? 'Ładowanie…' : 'Odśwież'}
        </button>
      </div>

      {err && <p className="text-sm text-red-600">{err}</p>}

      <section>
        <h2 className="mb-2 text-base font-semibold text-slate-800">Podsumowanie</h2>
        <div className="overflow-x-auto rounded-xl bg-white shadow-sm">
          <table className="min-w-full text-left text-xs">
            <thead className="border-b bg-slate-50 text-[11px] text-slate-500">
              <tr className="uppercase">
                <th className="px-3 pt-2" rowSpan={2}>
                  Rodzaj
                </th>
                <th className="px-1.5 pt-2 text-center" colSpan={3}>
                  Pozycje
                </th>
                <th className="border-l px-1.5 pt-2 text-center" colSpan={5}>
                  Tokeny wejścia
                </th>
                <th className="border-l px-1.5 pt-2 text-center" colSpan={3}>
                  Tokeny wyjścia
                </th>
                <th className="border-l px-1.5 pt-2 text-center" colSpan={2}>
                  Wywołania
                </th>
                <th className="border-l px-1.5 pt-2 text-center" colSpan={3}>
                  Karty do oceny
                </th>
                <th className="border-l px-1.5 pt-2 text-center" colSpan={2}>
                  Pula
                </th>
                <th className="border-l px-1.5 pt-2 text-center" colSpan={3}>
                  Model
                </th>
              </tr>
              <tr className="text-right">
                <th className="px-1.5 pb-2">wszystkie</th>
                <th className="px-1.5 pb-2" title="Pozycje z zapisanym kosztem — z nich liczą się średnie i percentyle tokenów">
                  z kosztem
                </th>
                <th className="px-1.5 pb-2" title="Model oceniał karty (co najmniej jedno wywołanie oceny)">
                  model pytany
                </th>
                <th className="border-l px-1.5 pb-2">suma</th>
                <th className="px-1.5 pb-2">śr.</th>
                <th className="px-1.5 pb-2" title="Połowa pozycji zużyła mniej">p50</th>
                <th className="px-1.5 pb-2" title="95 na 100 pozycji zużyło mniej">p95</th>
                <th className="px-1.5 pb-2">maks.</th>
                <th className="border-l px-1.5 pb-2">śr.</th>
                <th className="px-1.5 pb-2">p95</th>
                <th className="px-1.5 pb-2">maks.</th>
                <th className="border-l px-1.5 pb-2">śr.</th>
                <th className="px-1.5 pb-2">maks.</th>
                <th className="border-l px-1.5 pb-2">śr.</th>
                <th className="px-1.5 pb-2">p50</th>
                <th className="px-1.5 pb-2">min</th>
                <th className="border-l px-1.5 pb-2" title="Kandydaci z wyszukiwania przed oceną modelu">
                  śr.
                </th>
                <th className="px-1.5 pb-2">p50</th>
                <th className="border-l px-1.5 pb-2" title="Odsetek pozycji ze znanym stanem modelu">
                  awaria
                </th>
                <th className="px-1.5 pb-2" title="Model odpowiedział bez żadnej karty">
                  pusta
                </th>
                <th className="px-1.5 pb-2" title="Odpowiedź z zapasowej konfiguracji modelu">
                  zapas
                </th>
              </tr>
            </thead>
            <tbody>
              {summary.length === 0 && (
                <tr>
                  <td colSpan={22} className="px-3 py-6 text-center text-slate-500">
                    {busy ? 'Ładowanie…' : 'Brak danych.'}
                  </td>
                </tr>
              )}
              {summary.map((a) => (
                <tr key={a.task} className="border-b text-right tabular-nums last:border-0">
                  <td className="min-w-[11rem] px-3 py-1.5 text-left text-sm font-medium text-slate-800">{TASK_LABEL[a.task] ?? a.task}</td>
                  <td className="px-1.5 py-1.5 text-slate-700">{fmtInt(a.events)}</td>
                  <td className="px-1.5 py-1.5 text-slate-700">{fmtInt(a.with_usage)}</td>
                  <td className="px-1.5 py-1.5 text-slate-700">{fmtInt(a.model_asked)}</td>
                  <td className="border-l px-1.5 py-1.5 font-medium text-slate-800">{fmtInt(a.prompt_tokens.sum)}</td>
                  <td className="px-1.5 py-1.5 text-slate-700">{fmtInt(a.prompt_tokens.avg)}</td>
                  <td className="px-1.5 py-1.5 text-slate-700">{fmtInt(a.prompt_tokens.p50)}</td>
                  <td className="px-1.5 py-1.5 text-slate-700">{fmtInt(a.prompt_tokens.p95)}</td>
                  <td className="px-1.5 py-1.5 text-slate-700">{fmtInt(a.prompt_tokens.max)}</td>
                  <td className="border-l px-1.5 py-1.5 text-slate-700">{fmtInt(a.completion_tokens.avg)}</td>
                  <td className="px-1.5 py-1.5 text-slate-700">{fmtInt(a.completion_tokens.p95)}</td>
                  <td className="px-1.5 py-1.5 text-slate-700">{fmtInt(a.completion_tokens.max)}</td>
                  <td className="border-l px-1.5 py-1.5 text-slate-700">{fmtDec(a.llm_calls.avg)}</td>
                  <td className="px-1.5 py-1.5 text-slate-700">{fmtInt(a.llm_calls.max)}</td>
                  <td className="border-l px-1.5 py-1.5 text-slate-700">{fmtDec(a.rank_cards.avg)}</td>
                  <td className="px-1.5 py-1.5 text-slate-700">{fmtInt(a.rank_cards.p50)}</td>
                  <td className="px-1.5 py-1.5 text-slate-700">{fmtInt(a.rank_cards.min)}</td>
                  <td className="border-l px-1.5 py-1.5 text-slate-700">{fmtDec(a.candidate_count.avg)}</td>
                  <td className="px-1.5 py-1.5 text-slate-700">{fmtInt(a.candidate_count.p50)}</td>
                  <td className={`border-l px-1.5 py-1.5 ${a.states.unavailable > 0 ? 'text-red-700' : 'text-slate-700'}`}>
                    {fmtPct(a.unavailable_pct)}
                  </td>
                  <td className={`px-1.5 py-1.5 ${a.states.empty > 0 ? 'text-amber-700' : 'text-slate-700'}`}>
                    {fmtPct(a.empty_pct)}
                  </td>
                  <td className="px-1.5 py-1.5 text-slate-700">{fmtInt(a.fallback)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <p className="mt-2 text-xs text-slate-500">
          Tokeny = suma wszystkich odpowiedzi modelu dla pozycji (także ponowień, pustych i uciętych). Średnie i
          percentyle tylko z pozycji z zapisanym kosztem. Karty do oceny — ile kart model zobaczył w ostatniej ocenie
          (tylko gdy był pytany). Awaria i pusta ocena — odsetek pozycji, dla których znany jest stan modelu.
          {productSearch?.duration_ms && (
            <>
              {' '}
              Wyszukiwarka — czas odpowiedzi: śr. {fmtSeconds(productSearch.duration_ms.avg)}, p50{' '}
              {fmtSeconds(productSearch.duration_ms.p50)}, p95 {fmtSeconds(productSearch.duration_ms.p95)}.
            </>
          )}
        </p>
      </section>

      <section>
        <h2 className="mb-2 text-base font-semibold text-slate-800">Rozkład tokenów wejścia na pozycję</h2>
        {data && withUsage.length === 0 && (
          <p className="text-sm text-slate-500">Brak pozycji z zapisanym kosztem w tym zakresie.</p>
        )}
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
          {withUsage.map((a) => {
            const counts = data?.histogram.counts[a.task] ?? []
            const top = Math.max(1, ...counts)
            return (
              <div key={a.task} className="rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
                <p className="mb-2 text-xs font-semibold text-slate-800">
                  {TASK_LABEL[a.task]} <span className="font-normal text-slate-500">· {fmtInt(a.with_usage)} poz.</span>
                </p>
                <div className="space-y-1">
                  {counts.map((c, i) => (
                    <div key={i} className="grid grid-cols-[5.5rem_1fr_5.5rem] items-center gap-2 text-xs">
                      <span className="text-slate-600">{binLabel(edges, i)}</span>
                      <div className="h-2.5 rounded bg-slate-100">
                        <div
                          className={`h-2.5 rounded ${(edges[i] ?? 0) >= 20000 ? 'bg-amber-500' : 'bg-sky-500'}`}
                          style={{ width: `${(c / top) * 100}%` }}
                        />
                      </div>
                      <span className="text-right tabular-nums text-slate-700">
                        {fmtInt(c)}{' '}
                        <span className="text-slate-500">
                          ({fmtPct(a.with_usage > 0 ? Math.round((c * 1000) / a.with_usage) / 10 : null)})
                        </span>
                      </span>
                    </div>
                  ))}
                </div>
              </div>
            )
          })}
        </div>

        {data && data.daily.length > 0 && (
          <details className="mt-3 rounded-xl border border-slate-200 bg-white text-sm shadow-sm">
            <summary className="cursor-pointer px-3 py-2 text-xs font-medium text-slate-700">Dzień po dniu</summary>
            <div className="overflow-x-auto border-t">
              <table className="min-w-full text-left text-sm">
                <thead className="border-b bg-slate-50 text-xs uppercase text-slate-500">
                  <tr>
                    <th className="px-3 py-2">Dzień</th>
                    <th className="px-3 py-2">Rodzaj</th>
                    <th className="px-3 py-2 text-right">Pozycje</th>
                    <th className="px-3 py-2 text-right">Tokeny wejścia</th>
                    <th className="px-3 py-2 text-right">śr.</th>
                    <th className="px-3 py-2 text-right">p95</th>
                    <th className="px-3 py-2 text-right">Awaria</th>
                    <th className="px-3 py-2 text-right">Pusta</th>
                  </tr>
                </thead>
                <tbody>
                  {[...data.daily].reverse().map((d) => (
                    <tr key={`${d.date}|${d.task}`} className="border-b tabular-nums last:border-0">
                      <td className="whitespace-nowrap px-3 py-1.5 text-slate-700">{fmtDay(d.date)}</td>
                      <td className="whitespace-nowrap px-3 py-1.5 text-slate-700">{TASK_LABEL[d.task] ?? d.task}</td>
                      <td className="px-3 py-1.5 text-right text-slate-700">{fmtInt(d.events)}</td>
                      <td className="px-3 py-1.5 text-right text-slate-800">{fmtInt(d.prompt_tokens.sum)}</td>
                      <td className="px-3 py-1.5 text-right text-slate-700">{fmtInt(d.prompt_tokens.avg)}</td>
                      <td className="px-3 py-1.5 text-right text-slate-700">{fmtInt(d.prompt_tokens.p95)}</td>
                      <td className={`px-3 py-1.5 text-right ${d.states.unavailable > 0 ? 'text-red-700' : 'text-slate-700'}`}>
                        {fmtPct(d.unavailable_pct)}
                      </td>
                      <td className={`px-3 py-1.5 text-right ${d.states.empty > 0 ? 'text-amber-700' : 'text-slate-700'}`}>
                        {fmtPct(d.empty_pct)}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </details>
        )}
      </section>

      <section>
        <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
          <h2 className="text-base font-semibold text-slate-800">Pozycje do sprawdzenia</h2>
          <div className="flex flex-wrap items-center gap-3 text-xs text-slate-600">
            <label className="flex items-center gap-1">
              Mało kart: poniżej
              <select
                className="rounded border border-slate-300 bg-white px-1 py-0.5 text-xs"
                value={smallPool}
                onChange={(e) => setSmallPool(Number(e.target.value))}
              >
                {SMALL_POOL_OPTIONS.map((n) => (
                  <option key={n} value={n}>
                    {n}
                  </option>
                ))}
              </select>
            </label>
            <label className="flex items-center gap-1">
              Na liście
              <select
                className="rounded border border-slate-300 bg-white px-1 py-0.5 text-xs"
                value={limit}
                onChange={(e) => setLimit(Number(e.target.value))}
              >
                {LIMIT_OPTIONS.map((n) => (
                  <option key={n} value={n}>
                    {n}
                  </option>
                ))}
              </select>
            </label>
          </div>
        </div>

        <nav className="mb-2 flex flex-wrap gap-1 border-b border-slate-200">
          {OUTLIER_TABS.map((t) => {
            const count = data?.outliers[t.key]?.length ?? 0
            return (
              <button
                key={t.key}
                type="button"
                onClick={() => setTab(t.key)}
                className={`-mb-px border-b-2 px-3 py-2 text-sm ${
                  tab === t.key
                    ? 'border-blue-600 font-semibold text-blue-700'
                    : 'border-transparent text-slate-600 hover:text-slate-900'
                }`}
              >
                {t.label}
                {data && (
                  <span className="ml-1 text-xs font-normal text-slate-500">
                    ({count}
                    {count >= limit ? '+' : ''})
                  </span>
                )}
              </button>
            )
          })}
        </nav>
        <p className="mb-2 text-xs text-slate-500">
          {outlierHint(tab, data?.thresholds.small_pool ?? smallPool)} Pokazane najwyżej {limit} pozycji.
        </p>

        <div className="overflow-x-auto rounded-xl bg-white shadow-sm">
          <table className="min-w-full text-left text-sm">
            <thead className="border-b bg-slate-50 text-xs uppercase text-slate-500">
              <tr>
                <th className="px-3 py-2">Kiedy / rodzaj</th>
                <th className="px-3 py-2">Zapytanie</th>
                <th className="px-3 py-2 text-right" title="Tokeny wejścia / wyjścia">
                  Tokeny we / wy
                </th>
                <th className="px-3 py-2 text-right">Wywołania</th>
                <th className="px-3 py-2 text-right" title="Karty w ostatniej ocenie / pula z wyszukiwania / wynik">
                  Karty / pula / wynik
                </th>
                <th className="px-3 py-2">Model</th>
                <th className="px-3 py-2">Skąd</th>
                <th className="px-3 py-2" />
              </tr>
            </thead>
            <tbody>
              {rows.length === 0 && (
                <tr>
                  <td colSpan={8} className="px-3 py-6 text-center text-slate-500">
                    {busy && !data ? 'Ładowanie…' : 'Brak takich pozycji w tym zakresie.'}
                  </td>
                </tr>
              )}
              {rows.map((r) => {
                const open = expanded.has(r.id)
                return (
                  <Fragment key={r.id}>
                    <tr className="border-b align-top last:border-0">
                      <td className="px-3 py-2">
                        <div className="whitespace-nowrap text-slate-700">{fmtWhen(r.created_at)}</div>
                        <div className="text-[11px] text-slate-500">{TASK_LABEL[r.task] ?? r.task}</div>
                      </td>
                      <td className="min-w-[16rem] max-w-md px-3 py-2">
                        <p className="line-clamp-2 break-words text-slate-800" title={r.query}>
                          {r.query}
                        </p>
                      </td>
                      <td className="whitespace-nowrap px-3 py-2 text-right tabular-nums text-slate-700">
                        {fmtInt(r.prompt_tokens)} / {fmtInt(r.completion_tokens)}
                        {r.reasoning_tokens != null && r.reasoning_tokens > 0 && (
                          <div className="text-[11px] text-slate-500">rozumowanie {fmtInt(r.reasoning_tokens)}</div>
                        )}
                      </td>
                      <td className="whitespace-nowrap px-3 py-2 text-right tabular-nums text-slate-700">
                        {fmtInt(r.llm_calls)}
                        {r.rank_calls != null && <div className="text-[11px] text-slate-500">ocen: {r.rank_calls}</div>}
                      </td>
                      <td className="whitespace-nowrap px-3 py-2 text-right tabular-nums text-slate-700">
                        {fmtInt(r.rank_card_count)} / {fmtInt(r.candidate_count)} / {fmtInt(r.result_count)}
                      </td>
                      <td className="px-3 py-2">
                        <StateBadge state={r.model_state} />
                        {r.fallback && (
                          <span className="ml-1 rounded bg-slate-200 px-1.5 py-0.5 text-[11px] text-slate-700">zapas</span>
                        )}
                      </td>
                      <td className="min-w-[11rem] px-3 py-2 text-xs">
                        {r.context ? (
                          <>
                            {r.context.url ? (
                              <Link to={r.context.url} className="text-blue-600 hover:underline">
                                {r.context.label}
                              </Link>
                            ) : (
                              <span className="text-slate-600">{r.context.label}</span>
                            )}
                            {r.context.line_nos.length > 0 && (
                              <div className="text-slate-500">poz. {r.context.line_nos.join(', ')}</div>
                            )}
                          </>
                        ) : (
                          <span className="text-slate-500">—</span>
                        )}
                        {r.user && <div className="text-slate-500">{r.user.name}</div>}
                      </td>
                      <td className="whitespace-nowrap px-3 py-2 text-right">
                        <button
                          type="button"
                          onClick={() => toggle(r.id)}
                          aria-expanded={open}
                          className="text-xs text-blue-600 hover:underline"
                        >
                          {open ? 'Zwiń' : 'Etapy'}
                        </button>
                      </td>
                    </tr>
                    {open && (
                      <tr className="border-b bg-slate-50 last:border-0">
                        <td colSpan={8} className="px-3 py-2">
                          <RowDetails row={r} />
                        </td>
                      </tr>
                    )}
                  </Fragment>
                )
              })}
            </tbody>
          </table>
        </div>
      </section>
    </div>
  )
}

function RowDetails({ row }: { row: AiStatsRow }) {
  return (
    <div className="space-y-2 text-xs">
      <div className="flex flex-wrap gap-x-4 gap-y-1 text-slate-600">
        <span>
          Model: <span className="text-slate-800">{row.model ?? '—'}</span>
        </span>
        <span>
          Dostawca: <span className="text-slate-800">{row.provider ?? '—'}</span>
        </span>
        {row.run_id && (
          <span>
            Przebieg: <span className="font-mono text-slate-700">{row.run_id}</span>
          </span>
        )}
        <span>
          Zdarzenie: <span className="font-mono text-slate-700">#{row.id}</span>
        </span>
      </div>
      {row.ai_note && <p className="text-slate-700">Uwaga dla użytkownika: {row.ai_note}</p>}
      {row.stages.length === 0 ? (
        <p className="text-slate-500">Brak zapisanych etapów.</p>
      ) : (
        <table className="text-left">
          <thead className="text-[11px] uppercase text-slate-500">
            <tr>
              <th className="py-1 pr-4">Etap</th>
              <th className="py-1 pr-4 text-right">Karty</th>
              <th className="py-1 pr-4 text-right">Tokeny we</th>
              <th className="py-1 pr-4 text-right">Tokeny wy</th>
              <th className="py-1 pr-4 text-right">Rozumowanie</th>
              <th className="py-1 pr-4 text-right">Wywołania</th>
              <th className="py-1 pr-4 text-right">Nieudane</th>
            </tr>
          </thead>
          <tbody className="tabular-nums">
            {row.stages.map((s, i) => (
              <tr key={i}>
                <td className="py-0.5 pr-4 text-slate-800">
                  {STAGE_LABEL[s.stage] ?? s.stage}
                  {s.source && <span className="text-slate-500"> ({s.source})</span>}
                </td>
                <td className="py-0.5 pr-4 text-right text-slate-700">{fmtInt(s.cards)}</td>
                <td className="py-0.5 pr-4 text-right text-slate-700">{fmtInt(s.prompt_tokens)}</td>
                <td className="py-0.5 pr-4 text-right text-slate-700">{fmtInt(s.completion_tokens)}</td>
                <td className="py-0.5 pr-4 text-right text-slate-700">{fmtInt(s.reasoning_tokens)}</td>
                <td className="py-0.5 pr-4 text-right text-slate-700">{fmtInt(s.calls)}</td>
                <td className={`py-0.5 pr-4 text-right ${s.failed_calls ? 'text-red-700' : 'text-slate-700'}`}>
                  {fmtInt(s.failed_calls)}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  )
}
