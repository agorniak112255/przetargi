import { useState } from 'react'

export type CheckStatus = 'ok' | 'fail' | 'missing' | 'unclear'

export type CheckSource =
  | 'name'
  | 'norms'
  | 'manufacturer'
  | 'manual'
  | 'price_list'
  | 'shop_fields'
  | 'specs'
  | 'features'
  | 'payload_norms'
  | 'materials'
  | 'description'

export type CheckFinding = {
  text: string
  source: CheckSource
  quote: string
  find: string | null
  verdict: CheckStatus
  value_mm?: number
  /** Rok wydania normy, gdy karta podaje inne wydanie niż wymaganie — taki zapis nie jest oceniany. */
  edition?: string
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

/** Wartość parametru podana w kilku polach karty — np. „S1P” w nazwie i specyfikacji. */
export type CardFieldConflict = {
  key: string
  label: string
  /** klucz wiersza w `groups`, gdy przetarg pyta o ten parametr */
  row: string | null
  /** `edition` — rok wydania normy podany w polu (EN 388:2003 obok 2016 to inne wydanie, nie przekręcony kod) */
  values: { value: string; edition?: string; findings: Omit<CheckFinding, 'verdict'>[] }[]
}

/** Źródło przycisku „Sprzeczności (N)”: klucze wierszy fail + sprzeczne pola karty. Liczone regułami. */
export type RequirementConflicts = {
  count: number
  requirement: string[]
  card_fields: CardFieldConflict[]
}

export type RequirementCheck = {
  groups: { key: 'dimensions' | 'levels' | 'flags' | 'color' | 'package'; label: string; rows: CheckRow[] }[]
  conflicts: RequirementConflicts
  /** skąd są normy producenta (pola „producent”); null — karta ich nie ma */
  manufacturer_source?: { url: string | null; connector: string | null; synced_at: string | null; verified: boolean } | null
}

/** POST /products/{id}/conflicts/ai — sprzeczności znalezione przez model; każdy cytat sprawdzony w polach karty. */
export type AiCardConflicts = {
  conflicts: { parameter: string; explanation: string; quotes: CheckFinding[] }[]
  rejected: number
  checked_at: string
  cached: boolean
  prompt_version: string
}

/** GET /tenders/{id}/conflicts — skrót dla listy pozycji przetargu. */
export type TenderConflicts = {
  items: Record<string, { product_id: number; count: number; requirement: string[]; card_fields: string[] }>
}

type Props = {
  /** wynik `useRequirementCheck`; null — wyłączone, w trakcie albo błąd */
  check: RequirementCheck | null
  error: boolean
  onFind: (phrase: string) => void
  findHitCount: (phrase: string) => number
}

const SOURCE_LABEL: Record<CheckSource, string> = {
  name: 'nazwa',
  norms: 'normy',
  price_list: 'cennik dostawcy',
  shop_fields: 'tabela dostawcy',
  specs: 'specyfikacja',
  features: 'cechy',
  payload_norms: 'normy z opisu',
  materials: 'materiały',
  description: 'opis',
  manufacturer: 'producent',
  manual: 'wpisane ręcznie',
}

// „Brak na karcie” to niewiadoma, nie porażka — szary, nigdy czerwony.
const STATUS: Record<CheckStatus, { icon: string; label: string; iconClass: string }> = {
  fail: { icon: '✗', label: 'nie spełnia', iconClass: 'text-rose-600' },
  unclear: { icon: '?', label: 'sprawdź', iconClass: 'text-amber-600' },
  missing: { icon: '–', label: 'brak na karcie', iconClass: 'text-slate-400' },
  ok: { icon: '✓', label: 'spełnia', iconClass: 'text-emerald-600' },
}

/** Kolejność listy: najpierw to, co handlowiec musi obejrzeć. */
const ORDER: CheckStatus[] = ['fail', 'unclear', 'missing', 'ok']

const SUMMARY: { status: CheckStatus; suffix: string; className: string }[] = [
  { status: 'ok', suffix: '✓', className: 'text-emerald-700' },
  { status: 'fail', suffix: '✗', className: 'text-rose-700' },
  { status: 'missing', suffix: 'brak', className: 'text-slate-500' },
  { status: 'unclear', suffix: '?', className: 'text-amber-700' },
]

const POSITION_CLASS: Record<CheckPosition['status'], string> = {
  ok: 'text-slate-600',
  fail: 'font-semibold text-rose-700',
  missing: 'italic text-slate-500',
  unclear: 'text-amber-700',
  skip: 'text-slate-400',
}

/**
 * Porównanie parametrów wymagania z kartą w lewej kolumnie okna weryfikacji, pod zdjęciem —
 * nad opisem pełną szerokością zasłaniało połowę tekstu, który handlowiec właśnie sprawdza.
 * `data-requirement-check` pozwala oknu zmniejszyć zdjęcie, gdy lista jest widoczna.
 */
export function RequirementCheckList({ check, error, onFind, findHitCount }: Props) {
  // Przełącznik trzymany z wynikiem, dla którego powstał — nowy wynik (inny produkt/zapytanie) zwija ✓.
  const [showOkFor, setShowOkFor] = useState<RequirementCheck | null>(null)

  if (error) {
    return <p className="text-xs text-slate-500">Porównanie parametrów niedostępne</p>
  }
  // Porównanie to same reguły i wraca w milisekundach — linia „Porównuję…” tylko by migała.
  if (!check) return null

  const rows = (check.groups ?? []).flatMap((g) => g.rows)
  if (rows.length === 0) return null

  const counts = rows.reduce<Record<CheckStatus, number>>(
    (acc, r) => ({ ...acc, [r.status]: acc[r.status] + 1 }),
    { ok: 0, fail: 0, missing: 0, unclear: 0 },
  )
  const sorted = [...rows].sort((a, b) => ORDER.indexOf(a.status) - ORDER.indexOf(b.status))
  const needsAttention = counts.fail + counts.unclear + counts.missing > 0
  // Same ✓ pokazujemy od razu; przy problemach zwijamy je do jednej linii, żeby lista była krótka.
  const showOk = !needsAttention || showOkFor === check
  const visible = showOk ? sorted : sorted.filter((r) => r.status !== 'ok')

  return (
    <section data-requirement-check className="rounded-lg border border-slate-200 bg-white text-xs">
      <header className="flex flex-wrap items-baseline gap-x-2 border-b border-slate-100 px-2.5 py-1.5">
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
      </header>
      <ul>
        {visible.map((r) => (
          <CheckItem key={r.key} row={r} onFind={onFind} findHitCount={findHitCount} />
        ))}
      </ul>
      {needsAttention && counts.ok > 0 && (
        <button
          type="button"
          onClick={() => setShowOkFor(showOk ? null : check)}
          className="w-full border-t border-slate-100 px-2.5 py-1 text-left text-[11px] font-medium text-emerald-700 hover:bg-emerald-50"
        >
          {showOk ? '▾ zwiń spełnione' : `▸ spełnia (${counts.ok})`}
        </button>
      )}
    </section>
  )
}

function CheckItem({
  row,
  onFind,
  findHitCount,
}: {
  row: CheckRow
  onFind: (phrase: string) => void
  findHitCount: (phrase: string) => number
}) {
  const status = STATUS[row.status]
  const positions = (row.positions ?? []).filter((p) => p.status !== 'skip')
  // ✓ i „brak” mieszczą się w jednej linii; ✗ i ? pokazują dowód z karty pod spodem.
  const compact = row.status === 'ok' || row.status === 'missing'

  return (
    <li className="border-t border-slate-100 px-2.5 py-1 first:border-t-0">
      <div className="flex items-baseline gap-1.5">
        <span className={`w-3 shrink-0 text-center font-bold ${status.iconClass}`} title={status.label}>
          {status.icon}
        </span>
        <span className="font-medium text-slate-800">{row.label}</span>
        <span className="min-w-0 truncate text-slate-500" title={row.required.quote}>
          {row.required.text}
        </span>
        {compact && (
          <span className="ml-auto shrink-0 pl-1 text-right">
            {row.card.length === 0 ? (
              <span className="italic text-slate-400">brak na karcie</span>
            ) : (
              <>
                {/* „brak” obok samego kodu wyglądałby jak kod tego wydania — mówimy wprost, że to inne wydanie normy */}
                {row.status === 'missing' && row.card[0].edition && (
                  <span className="mr-1 italic text-slate-400" title={row.note ?? undefined}>
                    tylko inne wydanie ({row.card[0].edition}):
                  </span>
                )}
                <Finding finding={row.card[0]} onFind={onFind} findHitCount={findHitCount} />
              </>
            )}
          </span>
        )}
      </div>
      {!compact && (
        <div className="ml-[1.125rem] mt-0.5 space-y-0.5">
          {row.card.length === 0 ? (
            <span className="italic text-slate-400">brak na karcie</span>
          ) : (
            <div className="flex flex-wrap gap-x-2.5 gap-y-0.5">
              {row.card.map((f, i) => (
                <Finding key={`${f.source}:${i}`} finding={f} onFind={onFind} findHitCount={findHitCount} />
              ))}
            </div>
          )}
          {positions.length > 0 && (
            <div className="text-[11px] tabular-nums">
              {positions.map((p, i) => (
                <span key={`${p.name}:${i}`}>
                  {i > 0 && <span className="text-slate-300"> · </span>}
                  <span
                    className={POSITION_CLASS[p.status]}
                    title={`${p.name}: wymagane ${p.required}, karta ${p.card ?? 'brak'}`}
                  >
                    {p.name} {p.required}→{p.card ?? '—'}
                  </span>
                </span>
              ))}
            </div>
          )}
          {row.note && <p className="text-[11px] text-slate-500">{row.note}</p>}
        </div>
      )}
    </li>
  )
}

/** Wartość z karty z etykietą pola; klikalna, gdy da się ją znaleźć w opisie. */
export function Finding({
  finding,
  onFind,
  findHitCount,
  sourceOnly = false,
}: {
  finding: Pick<CheckFinding, 'text' | 'source' | 'quote' | 'find'>
  /** brak — znalezisko nieklikalne */
  onFind?: (phrase: string) => void
  /** brak — klikalne każde znalezisko z `find` (np. w oknie sprzeczności, bez opisu pod ręką) */
  findHitCount?: (phrase: string) => number
  /** sama etykieta pola — gdy wartość stoi już obok (sprzeczne pola karty) */
  sourceOnly?: boolean
}) {
  const { text, source, quote, find } = finding
  // Klik tylko wtedy, gdy wyszukiwarka okna naprawdę coś znajdzie — inaczej pokazałaby 0 trafień.
  const phrase = onFind && find != null && (!findHitCount || findHitCount(find) > 0) ? find : null
  const content = sourceOnly ? (
    <span className={phrase != null ? 'text-violet-700 group-hover:underline' : 'text-slate-600'}>
      {SOURCE_LABEL[source] ?? source}
    </span>
  ) : (
    <>
      <span className={phrase != null ? 'text-violet-700 group-hover:underline' : 'text-slate-800'}>{text}</span>{' '}
      <span className="text-[10px] text-slate-400">{SOURCE_LABEL[source] ?? source}</span>
    </>
  )
  return phrase != null && onFind ? (
    <button type="button" onClick={() => onFind(phrase)} title={quote} className="group text-left">
      {content}
    </button>
  ) : (
    <span title={quote}>{content}</span>
  )
}
