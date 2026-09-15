import { useEffect, useState } from 'react'
import { api } from '../lib/api'

export type CheckStatus = 'ok' | 'fail' | 'missing' | 'unclear'

export type CheckSource = 'name' | 'norms' | 'specs' | 'features' | 'payload_norms' | 'materials' | 'description'

export type CheckFinding = {
  text: string
  source: CheckSource
  quote: string
  find: string | null
  verdict: CheckStatus
  value_mm?: number
}

export type CheckPosition = { name: string; required: string; card: string | null; status: CheckStatus | 'skip' }

export type CheckRow = {
  key: string
  label: string
  required: { text: string; quote: string; [k: string]: unknown }
  card: CheckFinding[]
  status: CheckStatus
  note: string | null
  positions: CheckPosition[] | null
  gate: string | null
}

export type RequirementCheck = {
  groups: { key: 'dimensions' | 'levels' | 'flags' | 'color'; label: string; rows: CheckRow[] }[]
}

type Props = {
  productId: number
  query: string
  onFind: (phrase: string) => void
  findHitCount: (phrase: string) => number
}

const SOURCE_LABEL: Record<CheckSource, string> = {
  name: 'nazwa',
  norms: 'normy',
  specs: 'specyfikacja',
  features: 'cechy',
  payload_norms: 'normy z opisu',
  materials: 'materiały',
  description: 'opis',
}

// „Brak na karcie” to niewiadoma, nie porażka — szary, nigdy czerwony.
const STATUS_BADGE: Record<CheckStatus, { label: string; className: string }> = {
  ok: { label: '✓ spełnia', className: 'border-emerald-200 bg-emerald-50 text-emerald-700' },
  fail: { label: '✗ nie spełnia', className: 'border-rose-200 bg-rose-50 text-rose-700' },
  missing: { label: 'brak na karcie', className: 'border-slate-200 bg-slate-50 italic text-slate-500' },
  unclear: { label: '? sprawdź', className: 'border-amber-200 bg-amber-50 text-amber-700' },
}

const SUMMARY: { status: CheckStatus; suffix: string; className: string }[] = [
  { status: 'ok', suffix: '✓', className: 'text-emerald-700' },
  { status: 'fail', suffix: '✗', className: 'text-rose-700' },
  { status: 'missing', suffix: 'brak', className: 'text-slate-500' },
  { status: 'unclear', suffix: '?', className: 'text-amber-700' },
]

const POSITION_CLASS: Record<CheckPosition['status'], string> = {
  ok: 'text-slate-700',
  fail: 'font-semibold text-rose-700',
  missing: 'italic text-slate-500',
  unclear: 'text-amber-700',
  skip: 'text-slate-400',
}

export function RequirementCheckTable({ productId, query, onFind, findHitCount }: Props) {
  const key = `${productId}|${query}`
  const enabled = query.trim().length >= 3
  // Wynik i przełącznik trzymane z kluczem, dla którego powstały — jak w oknie weryfikacji.
  const [result, setResult] = useState<{ key: string; check: RequirementCheck | null } | null>(null)
  const [openFor, setOpenFor] = useState<{ key: string; open: boolean } | null>(null)

  useEffect(() => {
    if (!enabled) return
    let cancelled = false
    void api<RequirementCheck>(`/products/${productId}/requirement-check`, {
      method: 'POST',
      body: JSON.stringify({ query }),
    })
      .then((check) => {
        if (!cancelled) setResult({ key: `${productId}|${query}`, check })
      })
      .catch(() => {
        if (!cancelled) setResult({ key: `${productId}|${query}`, check: null })
      })
    return () => {
      cancelled = true
    }
  }, [enabled, productId, query])

  if (!enabled) return null

  const current = result?.key === key ? result : null
  // Porównanie to same reguły i wraca w milisekundach — linia „Porównuję…” tylko by migała,
  // a przy krótkim zapytaniu z wyszukiwarki pojawiała się i znikała bez żadnych wierszy.
  if (!current) return null
  if (!current.check) {
    return <p className="border-b border-slate-100 px-5 py-1.5 text-xs text-slate-500">Porównanie parametrów niedostępne</p>
  }

  const groups = (current.check.groups ?? []).filter((g) => g.rows.length > 0)
  if (groups.length === 0) return null

  const rows = groups.flatMap((g) => g.rows)
  const counts = rows.reduce<Record<CheckStatus, number>>(
    (acc, r) => ({ ...acc, [r.status]: acc[r.status] + 1 }),
    { ok: 0, fail: 0, missing: 0, unclear: 0 },
  )
  const needsAttention = counts.fail + counts.unclear > 0
  const open = openFor?.key === key ? openFor.open : needsAttention

  return (
    <div className="shrink-0 border-b border-slate-100 px-5 py-1.5">
      <button
        type="button"
        onClick={() => setOpenFor({ key, open: !open })}
        aria-expanded={open}
        className="flex items-center gap-2 text-xs"
      >
        <span className="w-3 text-slate-400">{open ? '▾' : '▸'}</span>
        <span className="font-medium text-slate-700">Parametry z wymagania</span>
        <span className="tabular-nums">
          {SUMMARY.filter((s) => counts[s.status] > 0).map((s, i) => (
            <span key={s.status}>
              {i > 0 && <span className="text-slate-300"> · </span>}
              <span className={s.className}>
                {counts[s.status]} {s.suffix}
              </span>
            </span>
          ))}
        </span>
      </button>

      {open && (
        <div className="mt-1.5 max-h-[30vh] overflow-auto rounded-md border border-slate-200">
          <table className="w-full border-collapse text-xs">
            <thead className="sticky top-0 z-10 bg-slate-100 text-left text-[11px] font-medium text-slate-600">
              <tr>
                <th className="px-2 py-1">Parametr</th>
                <th className="px-2 py-1">Wymaganie</th>
                <th className="px-2 py-1">Karta</th>
                <th className="px-2 py-1">Status</th>
              </tr>
            </thead>
            <tbody>
              {groups.map((g) => (
                <GroupRows key={g.key} label={g.label} rows={g.rows} onFind={onFind} findHitCount={findHitCount} />
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}

function GroupRows({
  label,
  rows,
  onFind,
  findHitCount,
}: {
  label: string
  rows: CheckRow[]
  onFind: (phrase: string) => void
  findHitCount: (phrase: string) => number
}) {
  return (
    <>
      <tr className="border-t border-slate-200 bg-slate-50">
        <th colSpan={4} className="px-2 py-0.5 text-left text-[10px] font-semibold uppercase tracking-wide text-slate-500">
          {label}
        </th>
      </tr>
      {rows.map((r) => (
        <tr key={r.key} className="border-t border-slate-100 align-top">
          <td className="whitespace-nowrap px-2 py-1 font-medium text-slate-700">{r.label}</td>
          <td className="px-2 py-1 text-slate-800" title={r.required.quote}>
            {r.required.text}
          </td>
          <td className="px-2 py-1">
            {r.card.length === 0 ? (
              <span className="italic text-slate-400">brak na karcie</span>
            ) : (
              <div className="flex flex-wrap gap-x-2.5 gap-y-0.5">
                {r.card.map((f, i) => (
                  <Finding key={`${f.source}:${i}`} finding={f} onFind={onFind} findHitCount={findHitCount} />
                ))}
              </div>
            )}
            {r.positions && r.positions.length > 0 && (
              <div className="mt-0.5 text-[11px] tabular-nums">
                {r.positions.map((p, i) => (
                  <span key={`${p.name}:${i}`}>
                    {i > 0 && <span className="text-slate-300"> · </span>}
                    <span
                      className={POSITION_CLASS[p.status]}
                      title={p.status === 'skip' ? `${p.name} — nie wymagane` : `${p.name}: wymagane ${p.required}, karta ${p.card ?? 'brak'}`}
                    >
                      {p.name} {p.required}→{p.card ?? '—'}
                    </span>
                  </span>
                ))}
              </div>
            )}
            {r.note && <p className="mt-0.5 text-[11px] text-slate-500">{r.note}</p>}
          </td>
          <td className="whitespace-nowrap px-2 py-1">
            <span className={`inline-block rounded border px-1.5 py-0.5 text-[11px] font-medium ${STATUS_BADGE[r.status].className}`}>
              {STATUS_BADGE[r.status].label}
            </span>
          </td>
        </tr>
      ))}
    </>
  )
}

function Finding({
  finding,
  onFind,
  findHitCount,
}: {
  finding: CheckFinding
  onFind: (phrase: string) => void
  findHitCount: (phrase: string) => number
}) {
  const { text, source, quote, find } = finding
  // Klik tylko wtedy, gdy wyszukiwarka okna naprawdę coś znajdzie — inaczej pokazałaby 0 trafień.
  const phrase = find != null && findHitCount(find) > 0 ? find : null
  const content = (
    <>
      <span className={phrase != null ? 'text-violet-700 group-hover:underline' : 'text-slate-800'}>{text}</span>{' '}
      <span className="text-[10px] text-slate-400">{SOURCE_LABEL[source] ?? source}</span>
    </>
  )
  return phrase != null ? (
    <button type="button" onClick={() => onFind(phrase)} title={quote} className="group text-left">
      {content}
    </button>
  ) : (
    <span title={quote}>{content}</span>
  )
}
