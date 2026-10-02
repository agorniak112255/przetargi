import { useEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import type {
  Substitute,
  SubstituteCardLite,
  SubstituteEvidenceParam,
  SubstituteEvidenceValue,
  SubstitutePrice,
} from '../lib/api'
import { formatPln } from '../lib/campaigns'
import {
  diffPercentText,
  evidenceSourceLabel,
  paramSummaryText,
  substituteCard,
  substituteStatusLabel,
  substituteTypeLabel,
} from '../lib/substitutes'

/* ---------- Drobne elementy wspólne dla tablicy i macierzy ---------- */

const THUMB_SIZE = { lg: 'h-14 w-14', md: 'h-11 w-11', sm: 'h-9 w-9' } as const

/** Miniatura karty; bez zdjęcia — szary obrys z ikoną, żeby rząd kart się nie rozjeżdżał. */
export function SubstituteThumb({ url, size = 'md' }: { url: string | null | undefined; size?: keyof typeof THUMB_SIZE }) {
  const box = THUMB_SIZE[size]
  return (
    <span className={`${box} flex shrink-0 items-center justify-center overflow-hidden rounded-lg border border-slate-200 bg-white`}>
      {url ? (
        <img src={url} alt="" className={`${box} object-contain`} loading="lazy" />
      ) : (
        <svg viewBox="0 0 24 24" className="h-5 w-5 text-slate-300" fill="none" stroke="currentColor" strokeWidth="1.5" aria-label="brak zdjęcia">
          <rect x="3" y="4" width="18" height="16" rx="2" />
          <circle cx="9" cy="10" r="1.8" />
          <path d="m4 18 5-5 4 4 3-3 4 4" />
        </svg>
      )}
    </span>
  )
}

/** Producent: ciemna plakietka dla karty głównej, jasna dla zamiennika. */
export function ManufacturerBadge({ name, main = false }: { name: string | null | undefined; main?: boolean }) {
  if (!name) return <span className="text-[10px] text-slate-400">producent nieznany</span>
  return (
    <span
      className={`inline-block max-w-full truncate rounded-md px-1.5 py-0.5 align-middle text-[10px] font-semibold uppercase tracking-wide ${
        main ? 'bg-slate-800 text-white' : 'border border-slate-300 bg-slate-50 text-slate-700'
      }`}
      title={name}
    >
      {name}
    </span>
  )
}

const STATUS_PILL: Record<string, string> = {
  oczekuje: 'bg-amber-100 text-amber-800',
  zatwierdzony: 'bg-emerald-100 text-emerald-800',
  odrzucony: 'bg-rose-100 text-rose-700',
}

export function StatusPill({ status }: { status: string }) {
  return (
    <span className={`whitespace-nowrap rounded-full px-2 py-0.5 text-[10px] font-medium ${STATUS_PILL[status] ?? 'bg-slate-100 text-slate-600'}`}>
      {substituteStatusLabel(status)}
    </span>
  )
}

const TYPE_TAG: Record<string, string> = {
  preferowany: 'border-emerald-300 text-emerald-800',
  premium: 'border-blue-200 text-blue-700',
  tanszy: 'border-slate-300 text-slate-700',
  awaryjny: 'border-slate-300 text-slate-500',
}

export function TypeTag({ type }: { type: string }) {
  return (
    <span className={`whitespace-nowrap rounded border px-1.5 py-px text-[10px] font-medium ${TYPE_TAG[type] ?? 'border-slate-300 text-slate-600'}`}>
      {substituteTypeLabel(type)}
    </span>
  )
}

export function StaleBadge({ reason }: { reason?: string | null }) {
  return (
    <span
      className="whitespace-nowrap rounded border border-amber-300 bg-amber-50 px-1.5 py-px text-[10px] font-medium text-amber-800"
      title={reason ? `Automat już tej pary nie potwierdza: ${reason}` : 'Zatwierdzona para, której automat już nie potwierdza'}
    >
      nieaktualne
    </span>
  )
}

export function SourceTag({ source }: { source: string | null | undefined }) {
  return (
    <span className="whitespace-nowrap text-[10px] text-slate-400" title={source === 'automat' ? 'Propozycja automatu' : 'Wpisany ręcznie'}>
      {source === 'automat' ? 'automat' : 'ręczny'}
    </span>
  )
}

/** Cena zamiennika i różnica do karty głównej; ceny w różnych jednostkach — bez procentu, z „?” i powodem. */
export function SubstitutePriceTag({ price, compact = false }: { price: SubstitutePrice | null | undefined; compact?: boolean }) {
  if (!price) return <span className="text-slate-400">—</span>
  const diff = price.comparable ? price.diff_percent : null
  return (
    <span className={`inline-flex flex-wrap items-baseline gap-x-1.5 tabular-nums ${compact ? 'text-xs' : 'text-sm'}`}>
      <span className="font-semibold text-slate-800">{price.sub_pln != null ? formatPln(price.sub_pln) : 'brak ceny'}</span>
      {diff != null ? (
        <span
          className={`text-[11px] ${diff < 0 ? 'font-semibold text-emerald-700' : 'text-slate-500'}`}
          title={diff < 0 ? 'Taniej niż karta główna' : diff > 0 ? 'Drożej niż karta główna' : 'Ta sama cena'}
        >
          {diffPercentText(diff)}
        </span>
      ) : (
        <span
          className="inline-flex h-4 w-4 cursor-help items-center justify-center rounded-full border border-slate-300 text-[10px] font-semibold text-slate-500"
          title={price.note ?? 'Cen nie da się porównać automatycznie.'}
          aria-label={price.note ?? 'Cen nie da się porównać automatycznie.'}
        >
          ?
        </span>
      )}
    </span>
  )
}

/* ---------- Macierz porównania ---------- */

const RELATION: Record<SubstituteEvidenceParam['relation'], { mark: string; label: string; className: string }> = {
  equal: { mark: '=', label: 'równe karcie głównej', className: 'bg-emerald-50 text-emerald-700' },
  higher: { mark: '↑', label: 'wyższe niż karta główna', className: 'bg-emerald-100 text-emerald-800' },
  meets: { mark: '≥', label: 'spełnia (inny zapis)', className: 'bg-slate-100 text-slate-700' },
}

function quoteTitle(v: SubstituteEvidenceValue): string {
  const src = evidenceSourceLabel(v)
  const head = v.inferred ? `Wniosek automatu (${src || 'z tekstu karty'})` : `Źródło: ${src || 'karta'}`
  return v.quote ? `${head}\n„${v.quote}”` : `${head}\nBez cytatu.`
}

/** Wartość parametru z etykietą źródła; wniosek automatu kursywą, brak — szary napis, nigdy jako fakt. */
function ValueCell({ v }: { v: SubstituteEvidenceValue | null | undefined }) {
  const text = v?.text ?? v?.value
  if (!v || !text) return <span className="text-slate-400">brak na karcie</span>
  return (
    <span className="block min-w-0" title={quoteTitle(v)}>
      <span className={`break-words ${v.inferred ? 'italic text-slate-600' : 'font-medium text-slate-800'}`}>{text}</span>
      <span className="block text-[10px] leading-tight text-slate-400">
        {evidenceSourceLabel(v)}
        {v.inferred && <span className="text-amber-700"> · wniosek automatu</span>}
        {v.quote && <span aria-hidden> · „…”</span>}
      </span>
    </span>
  )
}

type MatrixParam = { key: string; label: string; main: SubstituteEvidenceValue | null }

/** Unia parametrów ze wszystkich zamienników; kolejność z pierwszego wiersza z dowodami, potem kolejne nowe klucze. */
function collectParams(rows: Substitute[]): MatrixParam[] {
  const out = new Map<string, MatrixParam>()
  for (const r of rows) {
    for (const p of r.evidence?.params ?? []) {
      const known = out.get(p.key)
      if (!known) out.set(p.key, { key: p.key, label: p.label, main: p.main })
      else if (!known.main && p.main) known.main = p.main
    }
  }
  return [...out.values()]
}

export type SubstituteDecision = 'zatwierdzony' | 'odrzucony' | 'oczekuje'

type MatrixProps = {
  mainCard: SubstituteCardLite | null
  rows: Substitute[]
  /** kolumna, którą handlowiec kliknął na liście — podświetlona i przewinięta do widoku */
  focusId?: number | null
  canApprove: boolean
  canManage: boolean
  busy: boolean
  onDecide: (row: Substitute, status: SubstituteDecision, note: string) => void
  onEdit: (row: Substitute) => void
  onDelete: (row: Substitute) => void
}

/**
 * Porównanie karty głównej z zamiennikami parametr po parametrze — z dowodów automatu (evidence).
 * Każda wartość ma etykietę pola karty, cytat w podpowiedzi; brak wartości to „brak na karcie”.
 */
export function SubstituteMatrix({ mainCard, rows, focusId, canApprove, canManage, busy, onDecide, onEdit, onDelete }: MatrixProps) {
  const params = collectParams(rows)
  const focusRef = useRef<HTMLTableCellElement | null>(null)

  useEffect(() => {
    focusRef.current?.scrollIntoView({ block: 'nearest', inline: 'nearest' })
  }, [focusId])

  const notChecked = [...new Set(rows.flatMap((r) => r.evidence?.not_checked ?? []))]
  const extras = rows
    .map((r) => ({ row: r, card: substituteCard(r), extra: r.evidence?.extra_in_sub ?? [] }))
    .filter((x) => x.extra.length > 0)
  const anyEvidence = params.length > 0
  const colBase = 'border-b border-l border-slate-200 p-2 align-top'
  const focusCol = (r: Substitute) => (r.id === focusId ? 'bg-amber-50' : '')

  return (
    <div>
      <p className="mb-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-slate-500">
        {Object.values(RELATION).map((r) => (
          <span key={r.mark} className="inline-flex items-center gap-1">
            <span className={`inline-flex h-4 w-4 items-center justify-center rounded text-[11px] font-bold ${r.className}`}>{r.mark}</span>
            {r.label}
          </span>
        ))}
        <span>
          <i className="text-slate-600">kursywa</i> — wniosek automatu
        </span>
        <span>najedź na wartość, by zobaczyć cytat z karty</span>
      </p>

      <div className="overflow-x-auto rounded-xl border border-slate-200">
        <table className="w-full border-separate border-spacing-0 text-left text-xs">
          <thead>
            <tr>
              <th className="sticky left-0 z-10 w-24 min-w-[6rem] border-b border-slate-200 bg-white p-2 text-[11px] font-medium text-slate-500 sm:w-36">
                Parametr
              </th>
              <th className={`${colBase} min-w-[12rem] bg-slate-50`}>
                <p className="mb-1 text-[10px] font-semibold uppercase tracking-wide text-slate-500">Karta główna</p>
                {mainCard ? <CardHead card={mainCard} main /> : <span className="text-slate-400">—</span>}
              </th>
              {rows.map((r) => {
                const card = substituteCard(r)
                return (
                  <th key={r.id} ref={r.id === focusId ? focusRef : undefined} className={`${colBase} min-w-[13rem] font-normal ${focusCol(r)}`}>
                    <div className="mb-1 flex flex-wrap items-center gap-1">
                      <TypeTag type={r.type} />
                      <StatusPill status={r.approval_status} />
                      {r.evidence?.stale && <StaleBadge reason={r.evidence.stale.reason} />}
                      <SourceTag source={r.source} />
                    </div>
                    {card ? <CardHead card={card} /> : <span className="text-slate-400">karta usunięta</span>}
                    {r.summary && r.summary.params > 0 && (
                      <p className="mt-1 text-[11px] text-slate-500">{paramSummaryText(r.summary)}</p>
                    )}
                  </th>
                )
              })}
            </tr>
          </thead>
          <tbody>
            {params.map((p, i) => (
              <tr key={p.key}>
                <th className="sticky left-0 z-10 border-b border-slate-200 bg-white p-2 align-top text-[11px] font-medium text-slate-600">
                  {p.label}
                </th>
                <td className={`${colBase} bg-slate-50`}>
                  <ValueCell v={p.main} />
                </td>
                {rows.map((r) => {
                  if (!r.evidence?.params?.length) {
                    // wiersz bez dowodów (ręczny) — jedna komórka na całą wysokość parametrów
                    return i === 0 ? (
                      <td key={r.id} rowSpan={params.length} className={`${colBase} text-[11px] text-slate-400 ${focusCol(r)}`}>
                        {r.source === 'automat' ? 'Brak dowodów automatu.' : 'Wpisany ręcznie — automat nie porównał parametrów.'}
                      </td>
                    ) : null
                  }
                  const ep = r.evidence.params.find((x) => x.key === p.key)
                  if (!ep) {
                    return (
                      <td key={r.id} className={`${colBase} text-[11px] text-slate-400 ${focusCol(r)}`}>
                        nie porównano
                      </td>
                    )
                  }
                  // relacja tylko przy odczytanej wartości — „brak na karcie” nie może wyglądać na zgodność
                  const rel = ep.sub?.text || ep.sub?.value ? RELATION[ep.relation] : undefined
                  return (
                    <td key={r.id} className={`${colBase} ${focusCol(r)}`}>
                      <div className="flex items-start gap-1.5">
                        {rel && (
                          <span
                            className={`mt-px inline-flex h-4 w-4 shrink-0 items-center justify-center rounded text-[11px] font-bold ${rel.className}`}
                            title={rel.label}
                            aria-label={rel.label}
                          >
                            {rel.mark}
                          </span>
                        )}
                        <ValueCell v={ep.sub} />
                      </div>
                      {ep.note && <p className="mt-0.5 text-[10px] text-slate-500">{ep.note}</p>}
                    </td>
                  )
                })}
              </tr>
            ))}
            <tr>
              <th className="sticky left-0 z-10 border-b border-slate-200 bg-white p-2 align-top text-[11px] font-medium text-slate-600">
                Cena netto
              </th>
              <td className={`${colBase} bg-slate-50 tabular-nums`}>
                <span className="text-sm font-semibold text-slate-800">
                  {mainCard?.price_pln != null ? formatPln(mainCard.price_pln) : <span className="text-xs font-normal text-slate-400">brak ceny</span>}
                </span>
                {mainCard?.currency && mainCard.currency !== 'PLN' && (
                  <span className="block text-[10px] text-slate-400">przeliczone z {mainCard.currency}</span>
                )}
              </td>
              {rows.map((r) => (
                <td key={r.id} className={`${colBase} ${focusCol(r)}`}>
                  <SubstitutePriceTag price={r.price} />
                  {r.price && !r.price.comparable && r.price.note && (
                    <span className="block text-[10px] text-slate-500">{r.price.note}</span>
                  )}
                </td>
              ))}
            </tr>
            <tr>
              <th className="sticky left-0 z-10 border-b border-slate-200 bg-white p-2 align-top text-[11px] font-medium text-slate-600">
                Producent / SKU
              </th>
              <td className={`${colBase} bg-slate-50`}>
                <ProducerSku card={mainCard} />
              </td>
              {rows.map((r) => (
                <td key={r.id} className={`${colBase} ${focusCol(r)}`}>
                  <ProducerSku card={substituteCard(r)} />
                </td>
              ))}
            </tr>
            <tr>
              <th className="sticky left-0 z-10 bg-white p-2 align-top text-[11px] font-medium text-slate-600">Decyzja</th>
              <td className="border-l border-slate-200 bg-slate-50 p-2 align-top text-[11px] text-slate-400">—</td>
              {rows.map((r) => (
                <td key={r.id} className={`border-l border-slate-200 p-2 align-top ${focusCol(r)}`}>
                  <DecisionBox
                    key={r.approval_status}
                    row={r}
                    canApprove={canApprove}
                    canManage={canManage}
                    busy={busy}
                    onDecide={onDecide}
                    onEdit={onEdit}
                    onDelete={onDelete}
                  />
                </td>
              ))}
            </tr>
          </tbody>
        </table>
      </div>

      {!anyEvidence && (
        <p className="mt-2 text-[11px] text-slate-500">
          Żaden z tych zamienników nie ma dowodów automatu — porównaj karty samodzielnie.
        </p>
      )}

      <div className="mt-3 grid gap-2 text-xs sm:grid-cols-2">
        <div className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
          <p className="font-medium text-slate-700">Nie sprawdzono automatem</p>
          <p className="mt-0.5 text-slate-600">
            {notChecked.length > 0 ? notChecked.join(', ') : anyEvidence ? '—' : 'wszystkie parametry (brak dowodów)'}
          </p>
          <p className="mt-1 text-[11px] text-slate-500">Te cechy handlowiec sprawdza sam przed zatwierdzeniem.</p>
        </div>
        <div className="rounded-lg border border-slate-200 px-3 py-2">
          <p className="font-medium text-slate-700">Dodatkowo u zamiennika</p>
          {extras.length === 0 ? (
            <p className="mt-0.5 text-slate-500">—</p>
          ) : (
            <ul className="mt-0.5 space-y-0.5 text-slate-600">
              {extras.map((x) => (
                <li key={x.row.id}>
                  <span className="text-slate-500">{x.card ? `${x.card.manufacturer ?? ''} ${x.card.sku}`.trim() : `#${x.row.id}`}:</span>{' '}
                  {x.extra.join(', ')}
                </li>
              ))}
            </ul>
          )}
        </div>
      </div>
    </div>
  )
}

function CardHead({ card, main = false }: { card: SubstituteCardLite; main?: boolean }) {
  return (
    <div className="flex min-w-0 gap-2">
      <Link to={`/products/${card.id}`} target="_blank" rel="noopener" title="Otwórz kartę w nowej karcie przeglądarki">
        <SubstituteThumb url={card.thumb_url} size="md" />
      </Link>
      <div className="min-w-0">
        <ManufacturerBadge name={card.manufacturer} main={main} />
        <Link
          to={`/products/${card.id}`}
          target="_blank"
          rel="noopener"
          className="mt-0.5 line-clamp-2 break-words text-xs font-medium text-slate-800 hover:underline"
          title={card.name}
        >
          {card.name}
        </Link>
      </div>
    </div>
  )
}

function ProducerSku({ card }: { card: SubstituteCardLite | null }) {
  if (!card) return <span className="text-slate-400">—</span>
  return (
    <span className="block">
      <span className="text-slate-700">{card.manufacturer ?? '—'}</span>
      <Link to={`/products/${card.id}`} target="_blank" rel="noopener" className="block break-all font-mono text-[11px] text-blue-600 hover:underline">
        {card.sku}
      </Link>
      {!card.has_description && <span className="block text-[10px] text-slate-400">karta bez opisu</span>}
    </span>
  )
}

function DecisionBox({
  row,
  canApprove,
  canManage,
  busy,
  onDecide,
  onEdit,
  onDelete,
}: {
  row: Substitute
  canApprove: boolean
  canManage: boolean
  busy: boolean
  onDecide: MatrixProps['onDecide']
  onEdit: MatrixProps['onEdit']
  onDelete: MatrixProps['onDelete']
}) {
  const [note, setNote] = useState('')
  const pending = row.approval_status === 'oczekuje'

  return (
    <div className="space-y-1.5 text-[11px]">
      {row.reason ? (
        <p className="whitespace-pre-line text-slate-600">{row.reason}</p>
      ) : (
        <p className="text-slate-400">Bez uzasadnienia.</p>
      )}
      {row.evidence?.stale && <p className="text-amber-800">Nieaktualne: {row.evidence.stale.reason}</p>}
      {!pending && (
        <p className="text-slate-500">
          {substituteStatusLabel(row.approval_status)}
          {row.approver?.name ? ` · ${row.approver.name}` : ''}
          {row.decision_note && <span className="block text-slate-600">Notatka: {row.decision_note}</span>}
        </p>
      )}
      {canApprove && pending && (
        <>
          <textarea
            rows={2}
            maxLength={1000}
            className="w-full rounded-md border border-slate-300 bg-white px-2 py-1 text-[11px]"
            placeholder="Notatka do decyzji (opcjonalnie)"
            value={note}
            onChange={(e) => setNote(e.target.value)}
          />
          <div className="flex flex-wrap gap-1.5">
            <button
              type="button"
              disabled={busy}
              className="rounded-md bg-emerald-600 px-2.5 py-1 font-medium text-white hover:bg-emerald-700 disabled:opacity-50"
              onClick={() => onDecide(row, 'zatwierdzony', note)}
            >
              Zatwierdź
            </button>
            <button
              type="button"
              disabled={busy}
              className="rounded-md border border-rose-300 px-2.5 py-1 font-medium text-rose-700 hover:bg-rose-50 disabled:opacity-50"
              onClick={() => onDecide(row, 'odrzucony', note)}
            >
              Odrzuć
            </button>
          </div>
        </>
      )}
      {(canApprove && !pending) || canManage ? (
        <div className="flex flex-wrap gap-x-3 gap-y-1">
          {canApprove && !pending && (
            <button type="button" disabled={busy} className="text-slate-500 underline hover:text-slate-800 disabled:opacity-50" onClick={() => onDecide(row, 'oczekuje', '')}>
              Cofnij decyzję
            </button>
          )}
          {canManage && (
            <button type="button" disabled={busy} className="text-slate-500 underline hover:text-slate-800 disabled:opacity-50" onClick={() => onEdit(row)}>
              Edytuj
            </button>
          )}
          {canManage && row.source !== 'automat' && (
            <button type="button" disabled={busy} className="text-rose-700 underline hover:text-rose-800 disabled:opacity-50" onClick={() => onDelete(row)}>
              Usuń
            </button>
          )}
        </div>
      ) : null}
    </div>
  )
}
