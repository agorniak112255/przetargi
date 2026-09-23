import { useEffect, useRef, useState } from 'react'
import { api } from '../lib/api'
import { conflictsLabel } from '../lib/useRequirementCheck'
import {
  Finding,
  type AiCardConflicts,
  type CardFieldConflict,
  type CheckRow,
  type RequirementCheck,
} from './RequirementCheckList'

type Props = {
  open: boolean
  onClose: () => void
  productId: number
  productName?: string
  check: RequirementCheck | null
  /** `check` jeszcze się wczytuje — bez tego null wyglądałby jak błąd porównania */
  loading?: boolean
  /** klik cytatu; gdy brak — cytaty nieklikalne */
  onFind?: (phrase: string) => void
  /** gdy podane — przycisk „Otwórz weryfikację karty” */
  onOpenVerify?: () => void
}

type AiState = { productId: number; data: AiCardConflicts | null; loading: boolean; error: string }

/**
 * Sprzeczności karty z reguł (niespełnione wymagania + pola karty sobie przeczące) i, na żądanie,
 * z modelu. Wynik modelu nie wlicza się do licznika — to wniosek do sprawdzenia, nie fakt.
 */
export function CardConflictsModal({ open, onClose, productId, productName, check, loading = false, onFind, onOpenVerify }: Props) {
  // Wynik modelu trzymany z produktem, dla którego powstał — inny produkt go nie widzi.
  const [ai, setAi] = useState<AiState | null>(null)
  const requestRef = useRef(0)

  useEffect(() => {
    if (!open) return
    // Nasłuch na window w fazie capture rusza przed nasłuchami na document (okno weryfikacji pod spodem),
    // a stopPropagation nie pozwala im zamknąć okna razem z tym modalem.
    function onKey(e: KeyboardEvent) {
      if (e.key === 'Escape') {
        e.stopPropagation()
        onClose()
      }
    }
    window.addEventListener('keydown', onKey, true)
    return () => window.removeEventListener('keydown', onKey, true)
  }, [open, onClose])

  if (!open) return null

  const aiState = ai?.productId === productId ? ai : null

  function runAi(refresh: boolean) {
    const id = ++requestRef.current
    const pid = productId
    setAi((prev) => ({ productId: pid, data: prev?.productId === pid ? prev.data : null, loading: true, error: '' }))
    void api<AiCardConflicts>(`/products/${pid}/conflicts/ai`, {
      method: 'POST',
      body: JSON.stringify({ refresh }),
    })
      .then((data) => {
        if (requestRef.current === id) setAi({ productId: pid, data, loading: false, error: '' })
      })
      .catch((e) => {
        if (requestRef.current !== id) return
        setAi((prev) => ({
          productId: pid,
          data: prev?.productId === pid ? prev.data : null,
          loading: false,
          error: e instanceof Error ? e.message : 'Nie udało się sprawdzić modelem',
        }))
      })
  }

  const handleFind = onFind
    ? (phrase: string) => {
        onClose()
        onFind(phrase)
      }
    : undefined

  const conflicts = check?.conflicts
  const rowsByKey = new Map((check?.groups ?? []).flatMap((g) => g.rows).map((r) => [r.key, r] as const))
  const count = conflicts?.count ?? 0

  return (
    <div
      className="fixed inset-0 z-[80] flex items-center justify-center bg-slate-950/50 p-4"
      role="dialog"
      aria-modal="true"
      aria-labelledby="card-conflicts-title"
      // Klik w tło nie może dojść do tła okna pod spodem — zamknąłby oba.
      onClick={(e) => {
        e.stopPropagation()
        onClose()
      }}
    >
      <div
        className="flex max-h-[80vh] w-full max-w-lg flex-col overflow-hidden rounded-lg bg-white shadow-xl"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="border-b border-slate-100 px-4 py-3">
          <p id="card-conflicts-title" className="text-sm font-semibold text-slate-900" title="Liczone regułami — bez wyniku AI">
            {conflicts && count > 0
              ? `⚠ ${conflictsLabel(conflicts.requirement.length, conflicts.card_fields.length)}`
              : 'Sprawdzenie karty'}
          </p>
          {productName && (
            <p className="mt-0.5 truncate text-xs text-slate-500" title={productName}>
              {productName}
            </p>
          )}
        </div>

        <div className="min-h-0 flex-1 space-y-3 overflow-y-auto px-4 py-3 text-xs">
          {!conflicts ? (
            <p className="text-slate-500">{loading ? 'Wczytuję porównanie z wymaganiem…' : 'Porównanie regułami niedostępne.'}</p>
          ) : count === 0 ? (
            <p className="text-slate-600">Reguły nie znalazły niespełnionych wymagań ani sprzecznych pól karty.</p>
          ) : (
            <>
              {conflicts.requirement.length > 0 && (
                <section>
                  <h3 className="mb-1 text-[11px] font-semibold uppercase tracking-wide text-rose-700">
                    Karta nie spełnia wymagania ({conflicts.requirement.length})
                  </h3>
                  <ul className="space-y-1.5">
                    {conflicts.requirement.map((key) => (
                      <RequirementItem key={key} rowKey={key} row={rowsByKey.get(key)} onFind={handleFind} />
                    ))}
                  </ul>
                </section>
              )}
              {conflicts.card_fields.length > 0 && (
                <section>
                  <h3 className="mb-1 text-[11px] font-semibold uppercase tracking-wide text-amber-700">
                    Pola karty sobie przeczą ({conflicts.card_fields.length})
                  </h3>
                  <ul className="space-y-1.5">
                    {conflicts.card_fields.map((c) => (
                      <CardFieldItem key={c.key} conflict={c} onFind={handleFind} manufacturerSource={check?.manufacturer_source ?? null} />
                    ))}
                  </ul>
                </section>
              )}
            </>
          )}

          <section className="rounded-lg border border-violet-200 bg-violet-50/50 px-3 py-2">
            <div className="flex flex-wrap items-baseline justify-between gap-x-2">
              <h3 className="text-[11px] font-semibold uppercase tracking-wide text-violet-800">
                Sprawdzenie karty przez AI
              </h3>
              <span className="text-[10px] italic text-violet-600">wniosek AI — sprawdź cytaty</span>
            </div>
            {/* Użytkownik mylił „AI nie znalazło” z „karta spełnia przetarg” — AI dostaje samą kartę, bez wymagania. */}
            <p className="mt-0.5 text-[11px] text-violet-700/80">
              Sprawdza specyfikację, normy i opis karty pod kątem sprzeczności — np. różnych norm lub klas podanych w
              różnych miejscach.
            </p>
            {aiState?.loading ? (
              <p className="mt-1.5 flex items-center gap-1.5 text-violet-700">
                <span className="inline-block h-3 w-3 animate-spin rounded-full border-2 border-violet-600 border-t-transparent" />
                AI sprawdza kartę…
              </p>
            ) : aiState?.data ? (
              <AiResult data={aiState.data} onFind={handleFind} onRefresh={() => runAi(true)} />
            ) : (
              <button
                type="button"
                onClick={() => runAi(false)}
                className="mt-1.5 rounded bg-violet-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-violet-700"
              >
                Sprawdź kartę AI
              </button>
            )}
            {aiState?.error && !aiState.loading && <p className="mt-1.5 text-rose-700">{aiState.error}</p>}
          </section>
        </div>

        <div className="flex justify-end gap-2 border-t border-slate-100 px-4 py-2.5">
          {onOpenVerify && (
            <button
              type="button"
              onClick={() => {
                onClose()
                onOpenVerify()
              }}
              className="rounded border border-violet-200 bg-violet-50 px-3 py-1.5 text-xs font-medium text-violet-800 hover:bg-violet-100"
            >
              Otwórz weryfikację karty
            </button>
          )}
          <button
            type="button"
            onClick={onClose}
            className="rounded border border-slate-300 px-3 py-1.5 text-xs text-slate-700 hover:bg-slate-50"
          >
            Zamknij
          </button>
        </div>
      </div>
    </div>
  )
}

function RequirementItem({
  rowKey,
  row,
  onFind,
}: {
  rowKey: string
  row: CheckRow | undefined
  onFind?: (phrase: string) => void
}) {
  if (!row) {
    return <li className="rounded border border-rose-100 bg-rose-50/50 px-2.5 py-1.5 text-slate-700">{rowKey}</li>
  }
  const positions = (row.positions ?? []).filter((p) => p.status !== 'skip')
  return (
    <li className="rounded border border-rose-100 bg-rose-50/50 px-2.5 py-1.5">
      <div className="flex flex-wrap items-baseline gap-x-1.5">
        <span className="font-medium text-slate-800">{row.label}</span>
        <span className="text-slate-500" title={row.required.quote}>
          wymagane: {row.required.text}
        </span>
      </div>
      <div className="mt-0.5 flex flex-wrap items-baseline gap-x-2.5 gap-y-0.5">
        <span className="text-[11px] text-slate-500">karta:</span>
        {row.card.length === 0 ? (
          <span className="italic text-slate-400">brak na karcie</span>
        ) : (
          row.card.map((f, i) => <Finding key={`${f.source}:${i}`} finding={f} onFind={onFind} />)
        )}
      </div>
      {positions.length > 0 && (
        <div className="mt-0.5 text-[11px] tabular-nums">
          {positions.map((p, i) => (
            <span key={`${p.name}:${i}`}>
              {i > 0 && <span className="text-slate-300"> · </span>}
              <span
                className={p.status === 'fail' ? 'font-semibold text-rose-700' : 'text-slate-500'}
                title={`${p.name}: wymagane ${p.required}, karta ${p.card ?? 'brak'}`}
              >
                {p.name} {p.required}→{p.card ?? '—'}
              </span>
            </span>
          ))}
        </div>
      )}
      {row.note && <p className="mt-0.5 text-[11px] text-slate-500">{row.note}</p>}
    </li>
  )
}

function CardFieldItem({
  conflict,
  onFind,
  manufacturerSource,
}: {
  conflict: CardFieldConflict
  onFind?: (phrase: string) => void
  manufacturerSource: RequirementCheck['manufacturer_source']
}) {
  // Wartość z norm producenta rozstrzyga dopasowanie (backend stawia ją pierwszą) — pozostałe to zapisy ze sklepu
  // albo z opisu, które jej przeczą.
  const fromManufacturer = (v: CardFieldConflict['values'][number]) => v.findings.some((f) => f.source === 'manufacturer')
  const decided = conflict.values.some(fromManufacturer)
  return (
    <li className="rounded border border-amber-100 bg-amber-50/50 px-2.5 py-1.5">
      <p className="font-medium text-slate-800">{conflict.label}</p>
      {conflict.values.map((v, vi) => (
        <div key={`${v.value}:${v.edition ?? ''}:${vi}`} className="flex flex-wrap items-baseline gap-x-1">
          <span className="font-semibold text-amber-900">{v.value}</span>
          {v.edition && (
            <span className="text-[11px] text-slate-500" title="Rok wydania normy podany w polu karty">
              ({conflict.label}:{v.edition})
            </span>
          )}
          {fromManufacturer(v) && (
            <span className="rounded bg-emerald-100 px-1 text-[10px] font-semibold text-emerald-800" title="Ta wartość rozstrzyga dopasowanie">
              producent — rozstrzyga
            </span>
          )}
          <span className="text-slate-400">—</span>
          {v.findings.map((f, i) => (
            <span key={`${f.source}:${i}`}>
              {i > 0 && <span className="text-slate-400">, </span>}
              {/* Gdy zapis w polu jest taki jak wartość, wystarczy nazwa pola; inny zapis pokazujemy dosłownie. */}
              <Finding finding={f} onFind={onFind} sourceOnly={sameText(f.text, v.value)} />
            </span>
          ))}
          {fromManufacturer(v) && manufacturerSource?.url && (
            <a
              href={manufacturerSource.url}
              target="_blank"
              rel="noreferrer"
              className="text-[11px] text-sky-700 hover:underline"
              title={manufacturerSource.synced_at ? `odczyt ${formatDate(manufacturerSource.synced_at)}` : undefined}
            >
              źródło producenta
            </a>
          )}
        </div>
      ))}
      <p className="mt-0.5 text-[11px] text-amber-700">
        {decided
          ? 'dopasowanie bierze wartość producenta — pozostałe zapisy (sklep, opis) mu przeczą'
          : 'karta podaje różne wartości — sprawdź u producenta'}
      </p>
    </li>
  )
}

function AiResult({
  data,
  onFind,
  onRefresh,
}: {
  data: AiCardConflicts
  onFind?: (phrase: string) => void
  onRefresh: () => void
}) {
  return (
    <div className="mt-1.5 space-y-1.5">
      {data.conflicts.length === 0 ? (
        <p className="text-slate-600">
          AI nie znalazło sprzeczności w samej karcie — nazwa, normy, specyfikacja i opis podają te same wartości. To nie
          znaczy, że karta spełnia wymagania przetargu.
        </p>
      ) : (
        <ul className="space-y-1.5">
          {data.conflicts.map((c, i) => (
            <li key={`${c.parameter}:${i}`} className="rounded border border-violet-100 bg-white px-2.5 py-1.5">
              <p className="font-medium text-slate-800">{c.parameter}</p>
              <p className="text-slate-600">{c.explanation}</p>
              {c.quotes.length > 0 && (
                <div className="mt-0.5 flex flex-wrap gap-x-2.5 gap-y-0.5">
                  {c.quotes.map((q, qi) => (
                    <Finding key={`${q.source}:${qi}`} finding={q} onFind={onFind} />
                  ))}
                </div>
              )}
            </li>
          ))}
        </ul>
      )}
      {data.rejected > 0 && (
        <p className="text-[10px] text-slate-500">odrzucono {data.rejected} (cytaty nie zgadzały się z kartą)</p>
      )}
      <p className="text-[10px] text-slate-500">
        sprawdzono {formatDate(data.checked_at)}
        {data.cached ? ' (z pamięci)' : ''} ·{' '}
        <button type="button" onClick={onRefresh} className="font-medium text-violet-700 hover:underline">
          sprawdź ponownie
        </button>
      </p>
    </div>
  )
}

function sameText(a: string, b: string): boolean {
  const norm = (s: string) => s.replace(/\s+/g, ' ').trim().toLocaleLowerCase('pl')
  return norm(a) === norm(b)
}

function formatDate(value: string): string {
  const d = new Date(value)
  return Number.isNaN(d.getTime()) ? value : d.toLocaleString('pl-PL', { dateStyle: 'short', timeStyle: 'short' })
}
