import { useCallback, useEffect, useRef, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useAuth } from '../auth'
import { applyCheckboxRange } from '../lib/checkboxRange'
import {
  api,
  can,
  type CardBrief,
  type CardMatch,
  type CardMatchKind,
  type CardMatchPlan,
  type CardMatchPlanPosition,
  type CardMatchSignal,
  type CardMatchStatus,
  type CardMatchSummary,
} from '../lib/api'
import { currencyLabel, formatDateTime, formatPrice } from '../lib/priceChange'

type Page = {
  data: CardMatch[]
  current_page: number
  last_page: number
  per_page: number
  total: number
}

const PER_PAGE = 50

/** Zakładka ekranu: trzy rodzaje propozycji do decyzji, potem wspólne Niepewne / Odrzucone / Zrobione. */
type TabKey = 'merge' | 'size_merge' | 'split' | 'conflict' | 'rejected' | 'merged'

type TabDef = {
  key: TabKey
  label: string
  status: CardMatchStatus
  kind: CardMatchKind | null
  count: (s: CardMatchSummary) => number | undefined
  empty: string
}

const TABS: TabDef[] = [
  {
    key: 'merge',
    label: 'Do decyzji',
    status: 'pending',
    kind: 'merge',
    count: (s) => s.by_kind?.merge?.pending,
    empty:
      'Brak propozycji. Propozycje powstają z EAN-ów i kodów producenta zapisanych przy synchronizacji kont B2B ' +
      'i imporcie cenników — kolejne pojawią się po najbliższych przebiegach.',
  },
  {
    key: 'size_merge',
    label: 'Łączenie rozmiarów',
    status: 'pending',
    kind: 'size_merge',
    count: (s) => s.by_kind?.size_merge?.pending,
    empty:
      'Brak propozycji łączenia rozmiarów — żaden dystrybutor nie trzyma na jednej karcie rozmiarów, które producent ' +
      'ma na osobnych kartach w tej samej cenie.',
  },
  {
    key: 'split',
    label: 'Rozdzielanie',
    status: 'pending',
    kind: 'split',
    count: (s) => s.by_kind?.split?.pending,
    empty:
      'Brak propozycji rozdzielania — żadna karta dystrybutora nie łączy wyrobów, które producent ma na osobnych kartach.',
  },
  {
    key: 'conflict',
    label: 'Niepewne',
    status: 'conflict',
    kind: null,
    count: (s) => s.conflict,
    empty: 'Brak niepewnych propozycji — każdy znaleziony klucz prowadzi do kart producenta bez przeszkód.',
  },
  {
    key: 'rejected',
    label: 'Odrzucone',
    status: 'rejected',
    kind: null,
    count: (s) => s.rejected,
    empty: 'Nic nie odrzucono.',
  },
  {
    key: 'merged',
    label: 'Zrobione',
    status: 'merged',
    kind: null,
    count: (s) => s.merged,
    empty: 'Jeszcze nic nie połączono.',
  },
]

/** Zakładka z adresu: ?tab=…, a stare odnośniki ?status=… (pending → „Do decyzji”) nadal trafiają we właściwe miejsce. */
function tabFromParams(params: URLSearchParams): TabKey {
  const tab = params.get('tab')
  if (TABS.some((t) => t.key === tab)) return tab as TabKey
  const status = params.get('status')
  if (status === 'conflict' || status === 'rejected' || status === 'merged') return status
  return 'merge'
}

const KIND_LABEL: Record<CardMatchKind, string> = {
  merge: 'Połącz',
  size_merge: 'Łączenie rozmiarów',
  split: 'Rozdzielanie',
}

function KindBadge({ kind }: { kind: CardMatchKind }) {
  const cls =
    kind === 'size_merge'
      ? 'bg-sky-100 text-sky-800'
      : kind === 'split'
        ? 'bg-violet-100 text-violet-800'
        : 'bg-slate-100 text-slate-700'
  return <span className={`inline-block rounded px-1.5 py-px text-[11px] font-medium ${cls}`}>{KIND_LABEL[kind]}</span>
}

/** Plakietka „rozmiar S (mały)” / „kolor” / „nie wiadomo”; w dymku skąd to wiemy (signal_why z API). */
function SignalBadge({ signal, sizeLabel, why }: { signal: CardMatchSignal; sizeLabel?: string | null; why?: string }) {
  const text =
    signal === 'size' ? (sizeLabel ? `rozmiar ${sizeLabel}` : 'rozmiar') : signal === 'color' ? 'kolor' : 'nie wiadomo'
  const cls =
    signal === 'size'
      ? 'bg-sky-100 text-sky-800'
      : signal === 'color'
        ? 'bg-violet-100 text-violet-800'
        : 'bg-amber-100 text-amber-800'
  return (
    <span
      className={`inline-block rounded px-1.5 py-px text-[11px] ${cls} ${why ? 'cursor-help' : ''}`}
      title={why || undefined}
    >
      {text}
    </span>
  )
}

/** Polska liczba mnoga: 1 → one, 2–4 (bez 12–14) → few, reszta → many. */
function plural(n: number, one: string, few: string, many: string): string {
  const mod10 = n % 10
  const mod100 = n % 100
  if (n === 1) return one
  return mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14) ? few : many
}

/** „1 propozycja”, „3 propozycje”, „25 propozycji”. */
function proposalsLabel(n: number): string {
  return `${n.toLocaleString('pl-PL')} ${plural(n, 'propozycja', 'propozycje', 'propozycji')}`
}

/** Karty producenta z planu po id (do SKU w podsumowaniach pod tabelką). */
function planCards(plan: CardMatchPlan): Map<number, CardBrief> {
  const cards = new Map<number, CardBrief>()
  for (const p of plan.positions) {
    if (p.target) cards.set(p.target.id, p.target)
  }
  return cards
}

/** Liczba różnych kart producenta w planie (z pozycji; bez nich — z conflict_product_ids). */
function planTargetCount(m: CardMatch, plan: CardMatchPlan): number {
  const ids = new Set(plan.positions.map((p) => p.target_product_id).filter((id): id is number => id !== null))
  return ids.size > 0 ? ids.size : (m.conflict_product_ids?.length ?? 0)
}

/**
 * Zdanie „co proponujemy” z kontraktu ekranu. Niepewne (albo plan z blokadami) — lista powodów; inaczej opis
 * rodzaju. Tylko fakty z planu: „różnych cenach” wyłącznie przy equal_prices=false, rozmiary tylko przy sygnale size.
 */
function planSentence(m: CardMatch, plan: CardMatchPlan): { intro: string; items: string[] } {
  if (m.status === 'conflict' || plan.blockers.length > 0) {
    const items = plan.blockers.map((b) => b.text).filter((t) => t !== '')
    return {
      intro: 'Nie da się jeszcze zdecydować automatycznie:',
      items: items.length > 0 ? items : [m.reason ?? 'powód nieznany'],
    }
  }
  const d = plan.source_label
  const p = plan.positions[0]?.target?.manufacturer ?? m.brand ?? 'producent'
  const n = planTargetCount(m, plan)
  if (m.kind === 'size_merge') {
    const sizes = (plan.suggested?.sizes ?? []).map((s) => s.label ?? s.code).join(', ')
    return {
      intro:
        `${d} ma jeden wyrób w ${n} rozmiarach, ${p} ma osobną kartę na każdy rozmiar w tej samej cenie. ` +
        `Po połączeniu: jedna karta ${p}${sizes ? ` z rozmiarami ${sizes}` : ''} i ceną ${d} obok.`,
      items: [],
    }
  }
  const after = ` Po rozdzieleniu: cena ${d} trafi na każdą z ${n} kart ${p}, a karta ${d} zniknie.`
  if (plan.signal === 'color') {
    return {
      intro: `${d} trzyma ${n} ${plural(n, 'kolor', 'kolory', 'kolorów')} na jednej karcie, ${p} ma osobną kartę na każdy kolor.${after}`,
      items: [],
    }
  }
  const variants = plan.signal === 'size' ? 'rozmiarach' : 'wariantach'
  if (!plan.equal_prices) {
    return { intro: `${d} ma jeden wyrób w ${n} ${variants}, ${p} ma osobne karty w różnych cenach.${after}`, items: [] }
  }
  if (plan.signal === 'size' && !plan.same_owner) {
    return {
      intro: `${d} ma jeden wyrób w ${n} rozmiarach, ${p} ma osobne karty z różnych źródeł (inny właściciel karty).${after}`,
      items: [],
    }
  }
  return { intro: `${d} ma jeden wyrób w ${n} wariantach, ${p} ma osobną kartę na każdy wariant.${after}`, items: [] }
}

function positionKeyLabel(p: CardMatchPlanPosition): string | null {
  if (!p.matched_value) return null
  if (p.matched_by === 'ean') return `EAN ${p.matched_value}`
  if (p.matched_by === 'manufacturer_code') return `kod producenta ${p.matched_value}`
  return `${p.matched_by ?? 'klucz'} ${p.matched_value}`
}

function tabClass(active: boolean): string {
  return `-mb-px border-b-2 px-3 py-2 text-sm ${
    active ? 'border-blue-600 font-semibold text-blue-700' : 'border-transparent text-slate-600 hover:text-slate-900'
  }`
}

/** „1 para”, „3 pary”, „25 par”, „22 pary”. */
function pairsLabel(n: number): string {
  return `${n.toLocaleString('pl-PL')} ${plural(n, 'para', 'pary', 'par')}`
}

function priceText(price: string | null, currency: string | null): string {
  if (price === null || price === '') return '—'
  return `${formatPrice(price)} ${currencyLabel(currency)}`
}

/** „EAN 5901234567890” / „kod producenta IF016FPS” — wartość dosłownie z API (znormalizowana przez backend). */
function keyLabel(m: CardMatch): string {
  if (m.matched_by === 'ean') return 'ten sam EAN'
  if (m.matched_by === 'manufacturer_code') return 'ten sam kod producenta'
  return m.matched_by
}

function sourceLabelFor(m: CardMatch): string | null {
  if (!m.matched_source_key) return null
  const all = [...(m.target?.sources ?? []), ...(m.source?.sources ?? [])]
  return all.find((s) => s.source_key === m.matched_source_key)?.label ?? null
}

type BriefSource = CardBrief['sources'][number]

/**
 * Ceny źródeł od najtańszej zakupu. Tylko przy jednej walucie — bez kursu (CardBrief nie niesie ceny w PLN)
 * EUR i PLN nie da się uczciwie ułożyć, wtedy kolejność z API. Źródło bez ceny zakupu na końcu.
 */
function sortByPurchase(sources: BriefSource[]): BriefSource[] {
  const currencies = new Set(sources.filter((s) => s.purchase_price).map((s) => (s.currency ?? 'PLN').toUpperCase()))
  if (currencies.size > 1) return sources
  const price = (s: BriefSource) => (s.purchase_price ? Number(s.purchase_price) : Number.POSITIVE_INFINITY)
  return [...sources].sort((a, b) => price(a) - price(b))
}

/** Po połączeniu obie karty to jedna — ceny wszystkich dostawców razem, od najtańszej (null przy różnych walutach). */
function mergedSources(m: CardMatch): BriefSource[] | null {
  const seen = new Set<string>()
  const all = [...(m.target?.sources ?? []), ...(m.source?.sources ?? [])].filter((s) => {
    if (seen.has(s.source_key)) return false
    seen.add(s.source_key)
    return true
  })
  if (all.length < 2) return null
  const sorted = sortByPurchase(all)
  return sorted === all ? null : sorted
}

/** Jedna strona pary: miniatura, SKU (link do karty), nazwa, producent, cena karty i ceny źródeł. */
function CardSide({
  card,
  snapshot,
  highlightSourceKey,
}: {
  card: CardBrief | null
  snapshot?: CardMatch['source_snapshot']
  highlightSourceKey?: string | null
}) {
  if (!card) {
    if (snapshot) {
      return (
        <div className="min-w-0">
          <p className="font-mono text-[11px] text-slate-500">{snapshot.sku}</p>
          <p className="line-clamp-2 break-words text-slate-700" title={snapshot.name}>
            {snapshot.name}
          </p>
          <p className="text-[11px] text-slate-500">{snapshot.manufacturer ?? '—'}</p>
          <p className="mt-1 text-[11px] text-slate-400">Karty już nie ma (dane z chwili propozycji).</p>
        </div>
      )
    }
    return <span className="text-slate-400">—</span>
  }

  return (
    <div className="flex min-w-0 gap-2">
      <Link
        to={`/products/${card.id}`}
        target="_blank"
        rel="noopener"
        className="block h-16 w-16 shrink-0 overflow-hidden rounded border border-slate-200 bg-white"
        title="Otwórz kartę w nowej karcie przeglądarki"
      >
        {card.thumb_url ? (
          <img src={card.thumb_url} alt="" className="h-16 w-16 object-contain" loading="lazy" />
        ) : (
          <span className="flex h-full items-center justify-center text-[10px] text-slate-400">brak zdjęcia</span>
        )}
      </Link>
      <div className="min-w-0 flex-1">
        <Link
          to={`/products/${card.id}`}
          target="_blank"
          rel="noopener"
          className="font-mono text-[11px] text-blue-600 hover:underline"
          title={`Karta #${card.id} — otwiera się w nowej karcie przeglądarki`}
        >
          {card.sku}
        </Link>
        <p className="line-clamp-2 break-words text-slate-800" title={card.name}>
          {card.name}
        </p>
        <p className="text-[11px] text-slate-500">
          {card.manufacturer ?? '—'}
          {' · '}
          {card.has_description ? (
            <span className="text-emerald-700">z opisem</span>
          ) : (
            <span className="text-slate-400">bez opisu</span>
          )}
        </p>
        <p className="mt-0.5 text-[11px] text-slate-600">
          Cena karty: <b className="tabular-nums">{priceText(card.purchase_price, card.currency)}</b>
        </p>
        {card.sources.length > 0 && (
          <ul className="mt-0.5 space-y-px text-[11px] text-slate-600">
            {sortByPurchase(card.sources).map((s) => (
              <li
                key={s.source_key}
                className={s.source_key === highlightSourceKey ? 'font-medium text-slate-900' : undefined}
                title={s.source_key === highlightSourceKey ? 'Z tego źródła pochodzi klucz dopasowania' : undefined}
              >
                {s.label}: <span className="tabular-nums">{priceText(s.purchase_price, s.currency)}</span>
              </li>
            ))}
          </ul>
        )}
      </div>
    </div>
  )
}

/** Tabelka planu „pozycja u dystrybutora → karta producenta” + różnice cen i (size_merge) co zostanie. */
function PlanTable({ m, plan }: { m: CardMatch; plan: CardMatchPlan }) {
  const d = plan.source_label
  const p = plan.positions[0]?.target?.manufacturer ?? m.brand ?? 'producenta'
  const cards = planCards(plan)
  const cardLabel = (id: number) => cards.get(id)?.sku ?? `#${id}`
  const suggested = m.kind === 'size_merge' ? plan.suggested : null

  return (
    <div className="mt-2">
      <table className="w-full text-left text-xs">
        <thead>
          <tr className="border-b bg-slate-50 text-[11px] text-slate-600">
            <th className="w-44 p-1.5 font-medium">Pozycja u {d}</th>
            <th className="p-1.5 font-medium">Karta {p}</th>
            <th className="w-32 p-1.5 font-medium">Rozmiar / kolor</th>
          </tr>
        </thead>
        <tbody>
          {plan.positions.map((pos) => {
            const key = positionKeyLabel(pos)
            return (
              <tr key={`${pos.source_key}|${pos.position_key}`} className="border-b border-slate-100 align-top">
                <td className="p-1.5">
                  <p className="break-words text-slate-800">{pos.label ?? <span className="text-slate-400">bez etykiety</span>}</p>
                  <p className="break-all font-mono text-[11px] text-slate-500">{pos.remote_sku ?? pos.position_key}</p>
                  {pos.source_label !== d && <p className="text-[11px] text-slate-500">u {pos.source_label}</p>}
                  {key && <p className="break-all text-[11px] text-slate-500">{key}</p>}
                </td>
                <td className="min-w-[14rem] p-1.5">
                  {pos.target ? (
                    <CardSide card={pos.target} />
                  ) : pos.target_ids && pos.target_ids.length > 0 ? (
                    <div>
                      <p className="text-amber-800">Wskazuje kilka kart producenta:</p>
                      <p className="mt-0.5 flex flex-wrap gap-x-2">
                        {pos.target_ids.map((id) => (
                          <Link
                            key={id}
                            to={`/products/${id}`}
                            target="_blank"
                            rel="noopener"
                            className="text-blue-600 hover:underline"
                          >
                            karta #{id}
                          </Link>
                        ))}
                      </p>
                    </div>
                  ) : (
                    <span className="text-amber-800">brak karty producenta</span>
                  )}
                </td>
                <td className="p-1.5">
                  <SignalBadge signal={pos.signal} sizeLabel={pos.size_label} why={pos.signal_why} />
                </td>
              </tr>
            )
          })}
        </tbody>
      </table>

      {plan.price_differences.map((diff) => (
        <p key={diff.source_key} className="mt-1 rounded bg-amber-50 px-2 py-1 text-[11px] text-amber-900">
          Różne ceny w {diff.label}:{' '}
          {diff.values.map((v) => `${cardLabel(v.product_id)} ${priceText(v.purchase_price, v.currency)}`).join(' · ')}
        </p>
      ))}

      {suggested && (
        <div className="mt-1 space-y-px rounded bg-slate-50 px-2 py-1 text-[11px] text-slate-700">
          <p>
            Zostanie karta:{' '}
            <Link
              to={`/products/${suggested.keep_product_id}`}
              target="_blank"
              rel="noopener"
              className="font-mono text-blue-600 hover:underline"
            >
              {cardLabel(suggested.keep_product_id)}
            </Link>
          </p>
          <p>Podpowiedź nazwy karty modelu: {suggested.common_name ?? '—'}</p>
          <p>
            Kody rozmiarów:{' '}
            {suggested.sizes.length > 0
              ? suggested.sizes.map((s) => `${s.label ?? '—'} ${s.code}`).join('; ')
              : '—'}
          </p>
        </div>
      )}
    </div>
  )
}

/**
 * „Co proponujemy” dla size_merge / split: plakietka rodzaju (poza zakładkami rodzaju), zdanie po ludzku i tabelka
 * planu — w Odrzuconych / Zrobionych zwinięta, żeby historia była gęsta.
 */
function PlanProposal({ m, showKind, collapsed }: { m: CardMatch; showKind: boolean; collapsed: boolean }) {
  const plan = m.plan
  const header =
    showKind || m.signal === 'unknown' ? (
      <p className="mb-1 flex flex-wrap items-center gap-2">
        {showKind && <KindBadge kind={m.kind} />}
        {m.signal === 'unknown' && <span className="text-[11px] text-amber-800">rozmiar czy kolor — nie wiadomo</span>}
      </p>
    ) : null

  if (!plan) {
    return (
      <div>
        {header}
        <p className="text-amber-800">{m.reason ?? 'Brak planu tej propozycji — odśwież propozycje.'}</p>
      </div>
    )
  }

  const sentence = planSentence(m, plan)
  return (
    <div>
      {header}
      <p className={sentence.items.length > 0 ? 'text-amber-800' : 'text-slate-800'}>{sentence.intro}</p>
      {sentence.items.length > 0 && (
        <ul className="mt-0.5 list-disc pl-5 text-amber-800">
          {sentence.items.map((t, i) => (
            <li key={i}>{t}</li>
          ))}
        </ul>
      )}
      {collapsed ? (
        <details className="mt-1">
          <summary className="cursor-pointer text-[11px] text-blue-600">pokaż pozycje ({plan.positions.length})</summary>
          <PlanTable m={m} plan={plan} />
        </details>
      ) : (
        <PlanTable m={m} plan={plan} />
      )}
    </div>
  )
}

export function CardMatches() {
  const { user } = useAuth()
  const canDecide = can(user, 'card_matches.decide')
  const [params, setParams] = useSearchParams()
  const tabKey = tabFromParams(params)
  const tab = TABS.find((t) => t.key === tabKey) ?? TABS[0]
  const status = tab.status
  const kind = tab.kind
  const page = Math.max(1, Number(params.get('page')) || 1)

  const [summary, setSummary] = useState<CardMatchSummary | null>(null)
  const [result, setResult] = useState<Page | null>(null)
  const [loading, setLoading] = useState(false)
  const [busy, setBusy] = useState(false)
  const [busyRowId, setBusyRowId] = useState<number | null>(null)
  const [refreshing, setRefreshing] = useState(false)
  const [msg, setMsg] = useState('')
  const [err, setErr] = useState('')
  /** Błąd z API przy konkretnym wierszu (pojedynczo albo z wyniku zbiorczego). */
  const [rowErrors, setRowErrors] = useState<Record<number, string>>({})
  const [selected, setSelected] = useState<Record<number, boolean>>({})
  const lastSelectIndex = useRef<number | null>(null)
  const requestSeq = useRef(0)

  const load = useCallback(async () => {
    const seq = ++requestSeq.current
    setLoading(true)
    try {
      const qs = new URLSearchParams({ status, page: String(page), per_page: String(PER_PAGE) })
      if (kind) qs.set('kind', kind)
      const [list, sum] = await Promise.all([
        api<Page>(`/card-matches?${qs}`),
        api<CardMatchSummary>('/card-matches/summary'),
      ])
      if (seq !== requestSeq.current) return
      setResult(list)
      setSummary(sum)
    } catch (ex) {
      if (seq !== requestSeq.current) return
      setErr(ex instanceof Error ? ex.message : 'Błąd wczytywania propozycji')
    } finally {
      if (seq === requestSeq.current) setLoading(false)
    }
  }, [status, kind, page])

  useEffect(() => {
    void load()
  }, [load])

  // Po akcji strona mogła się skończyć (połączono ostatnie wiersze ostatniej strony) — wróć na ostatnią istniejącą.
  useEffect(() => {
    if (result && result.data.length === 0 && result.current_page > 1 && result.current_page > result.last_page) {
      setParams((prev) => {
        const next = new URLSearchParams(prev)
        if (result.last_page > 1) next.set('page', String(result.last_page))
        else next.delete('page')
        return next
      })
    }
  }, [result, setParams])

  // Zaznaczenie dotyczy tylko widocznej strony jednej zakładki.
  useEffect(() => {
    setSelected({})
    lastSelectIndex.current = null
  }, [tabKey, page])

  function go(nextTab: TabKey, nextPage = 1) {
    setMsg('')
    setErr('')
    setRowErrors({})
    // Inna zakładka: nie pokazuj przez chwilę wierszy poprzedniej pod nowymi nagłówkami.
    if (nextTab !== tabKey) setResult(null)
    setParams((prev) => {
      const next = new URLSearchParams(prev)
      next.set('tab', nextTab)
      // stary parametr (?status=…) już niepotrzebny — zakładkę niesie ?tab
      next.delete('status')
      if (nextPage > 1) next.set('page', String(nextPage))
      else next.delete('page')
      return next
    })
  }

  const rows = result?.data ?? []
  // zaznaczanie i akcje zbiorcze tylko dla zwykłych par (kind=merge) w „Do decyzji”
  const selectableIds = rows.filter((m) => m.status === 'pending' && m.kind === 'merge').map((m) => m.id)
  const selectedIds = selectableIds.filter((id) => selected[id])
  const allVisibleSelected = selectableIds.length > 0 && selectableIds.every((id) => selected[id])
  const showSelect = canDecide && tabKey === 'merge'

  function toggleSelected(id: number, shiftKey: boolean) {
    const index = selectableIds.indexOf(id)
    if (index < 0) return
    setSelected((prev) => {
      const applied = applyCheckboxRange(selectableIds, prev, lastSelectIndex.current, index, shiftKey)
      lastSelectIndex.current = applied.anchorIndex
      return applied.selected
    })
  }

  function toggleSelectAllVisible() {
    if (selectableIds.length === 0) return
    const allOn = selectableIds.every((id) => selected[id])
    setSelected((prev) => {
      const next = { ...prev }
      for (const id of selectableIds) {
        if (allOn) delete next[id]
        else next[id] = true
      }
      return next
    })
    lastSelectIndex.current = allOn ? null : selectableIds.length - 1
  }

  function rowLabel(m: CardMatch): string {
    const src = m.source?.sku ?? m.source_snapshot?.sku ?? `#${m.id}`
    return m.target ? `${src} → ${m.target.sku}` : src
  }

  async function mergeOne(m: CardMatch) {
    if (!m.source || !m.target) return
    const ok = window.confirm(
      `Połączyć kartę dystrybutora ${m.source.sku} z kartą producenta ${m.target.sku}?\n\n` +
        `Zostaje karta producenta ${m.target.sku} (nazwa, opis, zdjęcie główne). Ceny i powiązania dystrybutora ` +
        `przejdą do jej „Ceny ze źródeł”, a karta ${m.source.sku} zniknie. Przed połączeniem zapisuje się kopia zapasowa.`,
    )
    if (!ok) return
    await runOne(m, 'merge')
  }

  async function rejectOne(m: CardMatch) {
    const note = window.prompt(
      `Odrzucić propozycję ${rowLabel(m)}? Para nie wróci przy kolejnym odświeżeniu.\n\n` +
        'Powód (niewymagany, np. „inny kolor”, „karton zamiast sztuki”):',
      '',
    )
    if (note === null) return
    await runOne(m, 'reject', note.trim())
  }

  async function runOne(m: CardMatch, action: 'merge' | 'reject', note = '') {
    setBusy(true)
    setBusyRowId(m.id)
    setMsg('')
    setErr('')
    setRowErrors((prev) => {
      const next = { ...prev }
      delete next[m.id]
      return next
    })
    try {
      await api<CardMatch>(`/card-matches/${m.id}/${action}`, {
        method: 'POST',
        body: JSON.stringify(action === 'reject' && note !== '' ? { note } : {}),
      })
      setMsg(action === 'merge' ? `Połączono: ${rowLabel(m)}.` : `Odrzucono: ${rowLabel(m)}.`)
      setSelected((prev) => {
        const next = { ...prev }
        delete next[m.id]
        return next
      })
    } catch (ex) {
      const reason = ex instanceof Error ? ex.message : 'Błąd'
      setRowErrors((prev) => ({ ...prev, [m.id]: reason }))
      setErr(`${action === 'merge' ? 'Nie połączono' : 'Nie odrzucono'} ${rowLabel(m)}: ${reason}`)
    } finally {
      setBusy(false)
      setBusyRowId(null)
      await load()
    }
  }

  async function runBulk(action: 'merge' | 'reject') {
    const ids = selectedIds.slice(0, 200)
    if (ids.length === 0) return
    const ok = window.confirm(
      action === 'merge'
        ? `Połączyć ${ids.length} zaznaczonych par?\n\nW każdej parze zostaje karta producenta, a karta dystrybutora ` +
            'znika (jej ceny i powiązania przechodzą do karty producenta). Przed każdym połączeniem zapisuje się ' +
            'kopia zapasowa; każda para jest sprawdzana jeszcze raz tuż przed połączeniem.'
        : `Odrzucić ${ids.length} zaznaczonych propozycji?\n\nTe pary nie wrócą przy kolejnym odświeżeniu.`,
    )
    if (!ok) return
    const byId = new Map(rows.map((m) => [m.id, m]))
    setBusy(true)
    setMsg('')
    setErr('')
    setRowErrors({})
    try {
      const res = await api<{ results: Array<{ id: number; ok: boolean; error: string | null }> }>(
        '/card-matches/bulk',
        { method: 'POST', body: JSON.stringify({ action, ids }) },
      )
      const results = res.results ?? []
      const done = results.filter((r) => r.ok)
      const failed = results.filter((r) => !r.ok)
      const verb = action === 'merge' ? 'Połączono' : 'Odrzucono'
      setMsg(`${verb} ${done.length} z ${ids.length}.${failed.length > 0 ? ` Nie udało się: ${failed.length} — powody niżej i przy wierszach.` : ''}`)
      if (failed.length > 0) {
        const errs: Record<number, string> = {}
        for (const f of failed) errs[f.id] = f.error ?? 'Błąd bez opisu'
        setRowErrors(errs)
        setErr(
          failed
            .map((f) => {
              const m = byId.get(f.id)
              return `${m ? rowLabel(m) : `#${f.id}`}: ${f.error ?? 'błąd bez opisu'}`
            })
            .join('\n'),
        )
      }
      // Zostają zaznaczone tylko te, których się nie udało.
      setSelected(Object.fromEntries(failed.map((f) => [f.id, true])))
      lastSelectIndex.current = null
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Błąd akcji zbiorczej')
    } finally {
      setBusy(false)
      await load()
    }
  }

  async function refreshCandidates() {
    setRefreshing(true)
    setMsg('')
    setErr('')
    try {
      const sum = await api<CardMatchSummary>('/card-matches/refresh', { method: 'POST', body: '{}' })
      setSummary(sum)
      const byKind = sum.by_kind
      setMsg(
        `Propozycje przeliczone: do decyzji ${sum.pending}` +
          (byKind
            ? ` (połącz ${byKind.merge?.pending ?? 0}, rozmiary ${byKind.size_merge?.pending ?? 0}, ` +
              `rozdzielanie ${byKind.split?.pending ?? 0})`
            : '') +
          `, niepewne ${sum.conflict}.`,
      )
      await load()
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Błąd odświeżania propozycji')
    } finally {
      setRefreshing(false)
    }
  }

  const decided = status === 'merged' || status === 'rejected'
  // „Łączenie rozmiarów” / „Rozdzielanie”: tylko do odczytu i odrzucenia — trzy kolumny, bez zaznaczania
  const planTab = tabKey === 'size_merge' || tabKey === 'split'
  // Niepewne / Odrzucone / Zrobione mieszają rodzaje — przy każdym wierszu plakietka rodzaju
  const mixedTab = !planTab && tabKey !== 'merge'
  const colCount = planTab ? 3 : 4 + (showSelect ? 1 : 0)

  /** Ostatnia kolumna wiersza: akcje (Połącz tylko dla kind=merge), powód niepewnej pary albo zapis decyzji. */
  function renderActions(m: CardMatch) {
    const rowErr = rowErrors[m.id]
    const isPlan = m.kind === 'size_merge' || m.kind === 'split'
    const rejectButton = (
      <button
        type="button"
        disabled={busy}
        onClick={() => void rejectOne(m)}
        className="rounded border border-slate-300 px-2.5 py-1 text-[11px] hover:bg-slate-50 disabled:opacity-50"
      >
        {busyRowId === m.id && isPlan ? '…' : 'Odrzuć'}
      </button>
    )
    return (
      <>
        {m.status === 'pending' && canDecide && !isPlan && (
          <div className="flex flex-wrap gap-1">
            <button
              type="button"
              disabled={busy || !m.source || !m.target}
              onClick={() => void mergeOne(m)}
              className="rounded bg-blue-600 px-2.5 py-1 text-[11px] text-white hover:bg-blue-700 disabled:opacity-50"
            >
              {busyRowId === m.id ? '…' : 'Połącz'}
            </button>
            {rejectButton}
          </div>
        )}
        {m.status === 'pending' && canDecide && isPlan && <div className="flex flex-wrap gap-1">{rejectButton}</div>}
        {m.status === 'pending' && !canDecide && <span className="text-slate-400">czeka na decyzję</span>}
        {m.status === 'pending' && isPlan && (
          <p className="mt-1 text-[11px] text-slate-500">
            Decyzja o {m.kind === 'size_merge' ? 'połączeniu rozmiarów' : 'rozdzieleniu'} będzie dostępna wkrótce — na
            razie sprawdź plan albo odrzuć.
          </p>
        )}
        {m.status === 'conflict' && (
          <div>
            {/* przy planie powody są w „Co proponujemy” — tu bez powtórzenia */}
            {!isPlan && <p className="text-amber-800">{m.reason ?? 'Niejednoznaczne — bez łączenia.'}</p>}
            {!isPlan && m.target && m.conflict_product_ids && m.conflict_product_ids.length > 0 && (
              <p className="mt-1 flex flex-wrap gap-x-2 text-[11px]">
                {m.conflict_product_ids.map((id) => (
                  <Link
                    key={id}
                    to={`/products/${id}`}
                    target="_blank"
                    rel="noopener"
                    className="text-blue-600 hover:underline"
                  >
                    karta #{id}
                  </Link>
                ))}
              </p>
            )}
            {canDecide && <div className={isPlan ? '' : 'mt-1'}>{rejectButton}</div>}
          </div>
        )}
        {decided && (
          <div className="text-[11px] text-slate-600">
            <p className={m.status === 'merged' ? 'font-medium text-emerald-700' : 'font-medium text-slate-700'}>
              {m.status === 'rejected'
                ? 'Odrzucono'
                : m.kind === 'size_merge'
                  ? 'Połączono rozmiary'
                  : m.kind === 'split'
                    ? 'Rozdzielono'
                    : 'Połączono'}
            </p>
            <p>{m.decided_by?.name ?? '—'}</p>
            <p className="text-slate-500">{m.decided_at ? formatDateTime(m.decided_at) : '—'}</p>
            {m.reason && <p className="mt-0.5 text-slate-500">{m.reason}</p>}
          </div>
        )}
        {rowErr && <p className="mt-1 text-[11px] text-red-700">{rowErr}</p>}
      </>
    )
  }

  return (
    <div>
      <div className="mb-3 flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold">Łączenie kart</h1>
          <p className="mt-1 max-w-3xl text-[12px] text-slate-600">
            Pary „karta dystrybutora → karta producenta” znalezione po tym samym EAN-ie albo kodzie producenta tej
            samej marki — nigdy po podobnej nazwie. Po połączeniu zostaje karta producenta z jej nazwą, opisem i
            zdjęciem głównym; ceny dystrybutora dochodzą do jej „Ceny ze źródeł”, a karta dystrybutora znika.
            Przed każdym połączeniem zapisuje się pełna kopia zapasowa.
          </p>
          {summary?.signals && (
            <p
              className="mt-1 text-[11px] text-slate-500"
              title="Propozycje z kilkoma kartami producenta (do decyzji i niepewne) — czym różnią się pozycje karty dystrybutora"
            >
              Pomiar: rozmiary {summary.signals.size ?? 0} · kolory {summary.signals.color ?? 0} · niepewne{' '}
              {summary.signals.unknown ?? 0}
            </p>
          )}
        </div>
        <div className="flex flex-col items-end gap-1">
          {canDecide && (
            <button
              type="button"
              disabled={refreshing || busy}
              onClick={() => void refreshCandidates()}
              className="rounded border border-slate-300 bg-white px-3 py-1.5 text-xs hover:bg-slate-50 disabled:opacity-50"
              title="Przelicza propozycje z identyfikatorów zapisanych na kartach (niczego nie łączy)"
            >
              {refreshing ? 'Odświeżam…' : 'Odśwież propozycje'}
            </button>
          )}
          <span className="text-[11px] text-slate-500">
            {summary?.refreshed_at
              ? `Ostatnio przeliczone: ${formatDateTime(summary.refreshed_at)}`
              : summary
                ? 'Propozycji jeszcze nie przeliczano'
                : ''}
          </span>
        </div>
      </div>

      <nav className="mb-3 flex flex-wrap gap-1 border-b border-slate-200">
        {TABS.map((t) => {
          const n = summary ? t.count(summary) : undefined
          return (
            <button key={t.key} type="button" className={tabClass(t.key === tabKey)} onClick={() => go(t.key)}>
              {t.label}
              {n !== undefined ? <span className="ml-1 tabular-nums text-slate-500">({n})</span> : null}
            </button>
          )
        })}
      </nav>

      {msg && <p className="mb-2 rounded bg-green-50 px-3 py-2 text-xs text-green-800">{msg}</p>}
      {err && <p className="mb-2 whitespace-pre-line rounded bg-red-50 px-3 py-2 text-xs text-red-700">{err}</p>}

      <div className="overflow-x-auto rounded-xl bg-white p-4 shadow-sm">
        {result && (
          <div className="flex flex-wrap items-center justify-between gap-3 pb-2">
            <div className="flex flex-wrap items-center gap-3">
              {showSelect && selectableIds.length > 0 && (
                <>
                  <label className="flex items-center gap-2 text-xs text-slate-600">
                    <input
                      type="checkbox"
                      checked={allVisibleSelected}
                      onChange={toggleSelectAllVisible}
                      title="Zaznacz / odznacz widoczne"
                    />
                    Zaznacz widoczne ({selectableIds.length})
                    {selectedIds.length > 0 ? ` · zaznaczono ${selectedIds.length}` : ''}
                    <span className="text-slate-400"> · Shift+klik: zakres</span>
                  </label>
                  <button
                    type="button"
                    disabled={busy || selectedIds.length === 0}
                    onClick={() => void runBulk('merge')}
                    className="rounded bg-blue-600 px-2.5 py-1 text-xs text-white hover:bg-blue-700 disabled:opacity-40"
                  >
                    Połącz zaznaczone{selectedIds.length > 0 ? ` (${selectedIds.length})` : ''}
                  </button>
                  <button
                    type="button"
                    disabled={busy || selectedIds.length === 0}
                    onClick={() => void runBulk('reject')}
                    className="rounded border border-slate-300 px-2.5 py-1 text-xs hover:bg-slate-50 disabled:opacity-40"
                  >
                    Odrzuć zaznaczone{selectedIds.length > 0 ? ` (${selectedIds.length})` : ''}
                  </button>
                </>
              )}
              <span className="text-xs text-slate-500">
                {tabKey === 'merge' ? pairsLabel(result.total) : proposalsLabel(result.total)}
                {loading ? ' · ładowanie…' : ''}
              </span>
            </div>
            {result.last_page > 1 && (
              <nav className="flex items-center gap-1 text-xs text-slate-500" aria-label="Paginacja">
                <span className="mr-1">
                  Strona {result.current_page} z {result.last_page}
                </span>
                <button
                  type="button"
                  disabled={loading || result.current_page <= 1}
                  onClick={() => go(tabKey, result.current_page - 1)}
                  className="rounded border border-slate-300 px-2.5 py-1 disabled:opacity-40"
                >
                  ← Poprzednia
                </button>
                <button
                  type="button"
                  disabled={loading || result.current_page >= result.last_page}
                  onClick={() => go(tabKey, result.current_page + 1)}
                  className="rounded border border-slate-300 px-2.5 py-1 disabled:opacity-40"
                >
                  Następna →
                </button>
              </nav>
            )}
          </div>
        )}

        <table className="w-full text-left text-xs">
          <thead>
            <tr className="border-b bg-slate-50">
              {showSelect && (
                <th className="w-8 p-2">
                  <input
                    type="checkbox"
                    checked={allVisibleSelected}
                    disabled={selectableIds.length === 0}
                    onChange={toggleSelectAllVisible}
                    title="Zaznacz / odznacz widoczne. Na wierszu: Shift+klik zaznacza zakres."
                    aria-label="Zaznacz wszystkie widoczne"
                  />
                </th>
              )}
              <th className="p-2">Karta dystrybutora</th>
              {planTab ? (
                <th className="p-2">Co proponujemy</th>
              ) : (
                <>
                  <th className="w-48 p-2">{mixedTab ? 'Dlaczego / co proponujemy' : 'Dlaczego to ten sam wyrób'}</th>
                  <th className="p-2">{mixedTab ? 'Karta producenta' : 'Karta producenta — zostaje'}</th>
                </>
              )}
              <th className="w-44 p-2">{decided ? 'Decyzja' : status === 'conflict' ? 'Powód' : 'Akcja'}</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((m, i) => {
              const matchedFrom = sourceLabelFor(m)
              const stripe = i % 2 === 1 ? 'bg-slate-100/60' : ''
              if (m.kind === 'size_merge' || m.kind === 'split') {
                // plan „pozycja → karta”: karta dystrybutora · zdanie + tabelka (w zakładkach mieszanych na dwie
                // kolumny) · odrzucenie; bez zaznaczania i akcji zbiorczych
                return (
                  <tr key={m.id} className={`border-b align-top ${stripe}`}>
                    {showSelect && <td className="p-2" />}
                    <td className="min-w-[16rem] max-w-[24rem] p-2">
                      <CardSide card={m.source} snapshot={m.source_snapshot} />
                    </td>
                    <td className="p-2" colSpan={planTab ? 1 : 2}>
                      <PlanProposal m={m} showKind={mixedTab} collapsed={decided} />
                    </td>
                    <td className="p-2">{renderActions(m)}</td>
                  </tr>
                )
              }
              return (
                <tr
                  key={m.id}
                  className={`border-b align-top ${selected[m.id] ? 'bg-blue-50/40' : i % 2 === 1 ? 'bg-slate-100/60' : ''}`}
                >
                  {showSelect && (
                    <td className="select-none p-2">
                      {m.status === 'pending' && (
                        <input
                          type="checkbox"
                          checked={Boolean(selected[m.id])}
                          title="Shift+klik zaznacza wszystkie od ostatnio klikniętej"
                          onMouseDown={(e) => {
                            if (!e.shiftKey) return
                            e.preventDefault()
                            toggleSelected(m.id, true)
                          }}
                          onChange={(e) => {
                            if ((e.nativeEvent as MouseEvent).shiftKey) return
                            toggleSelected(m.id, false)
                          }}
                          aria-label={`Zaznacz ${rowLabel(m)}`}
                        />
                      )}
                    </td>
                  )}
                  <td className="min-w-[16rem] max-w-[24rem] p-2">
                    <CardSide card={m.source} snapshot={m.source_snapshot} />
                  </td>
                  <td className="p-2">
                    {mixedTab && (
                      <p className="mb-1">
                        <KindBadge kind={m.kind ?? 'merge'} />
                      </p>
                    )}
                    <p className="text-slate-600">{keyLabel(m)}</p>
                    <p className="break-all font-mono text-[13px] font-semibold text-slate-900">{m.matched_value}</p>
                    <p className="mt-1 text-[11px] text-slate-500">
                      {m.brand ? <>marka {m.brand}</> : null}
                      {m.brand && matchedFrom ? ' · ' : null}
                      {matchedFrom ? <>z {matchedFrom}</> : null}
                    </p>
                    <p
                      className="text-[11px] text-slate-500"
                      title="Ile pozycji (rozmiarów) karty dystrybutora z kodem wskazuje tę kartę producenta"
                    >
                      trafione pozycje: {m.hits} z {m.positions}
                    </p>
                    <span className="mt-1 block text-lg leading-none text-slate-400" aria-hidden>
                      →
                    </span>
                    {m.source && m.target && (() => {
                      const merged = mergedSources(m)
                      if (!merged) return null
                      return (
                        <div className="mt-1 rounded bg-slate-50 px-1.5 py-1 text-[11px] text-slate-600">
                          <p className="font-medium text-slate-700">Po połączeniu — od najtańszej:</p>
                          <ol className="space-y-px">
                            {merged.map((s, i) => (
                              <li key={s.source_key} className={i === 0 && s.purchase_price ? 'font-medium text-emerald-800' : undefined}>
                                {s.label}: <span className="tabular-nums">{priceText(s.purchase_price, s.currency)}</span>
                                {i === 0 && s.purchase_price ? ' · najtaniej' : null}
                              </li>
                            ))}
                          </ol>
                        </div>
                      )
                    })()}
                  </td>
                  <td className="min-w-[16rem] max-w-[24rem] p-2">
                    {m.target ? (
                      <CardSide card={m.target} highlightSourceKey={m.matched_source_key} />
                    ) : m.conflict_product_ids && m.conflict_product_ids.length > 0 ? (
                      <div>
                        <p className="text-slate-600">Klucz wskazuje kilka kart producenta:</p>
                        <p className="mt-0.5 flex flex-wrap gap-x-2">
                          {m.conflict_product_ids.map((id) => (
                            <Link
                              key={id}
                              to={`/products/${id}`}
                              target="_blank"
                              rel="noopener"
                              className="text-blue-600 hover:underline"
                            >
                              karta #{id}
                            </Link>
                          ))}
                        </p>
                      </div>
                    ) : (
                      <span className="text-slate-400">—</span>
                    )}
                  </td>
                  <td className="p-2">{renderActions(m)}</td>
                </tr>
              )
            })}
            {rows.length === 0 && (
              <tr>
                <td colSpan={colCount} className="p-8 text-center text-slate-500">
                  {loading || !result ? (
                    'Ładowanie…'
                  ) : (
                    <>
                      <span className="mx-auto block max-w-xl">{tab.empty}</span>
                      {status === 'pending' && summary && !summary.refreshed_at && canDecide && (
                        <span className="mt-1 block text-slate-400">
                          Możesz też przeliczyć je teraz przyciskiem „Odśwież propozycje”.
                        </span>
                      )}
                    </>
                  )}
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  )
}
