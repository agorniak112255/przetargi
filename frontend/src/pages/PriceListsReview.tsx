import { Fragment, useCallback, useEffect, useMemo, useRef, useState, type FormEvent } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useAuth } from '../auth'
import { PriceListsTabs } from '../components/PriceListsTabs'
import { canAny } from '../lib/api'
import { plural } from '../lib/plural'
import { formatDateTime } from '../lib/priceChange'
import { MODEL_NOTE, siteLabel } from '../lib/priceListSources'
import {
  fetchDescriptionVersions,
  fetchReviews,
  FILES_FROM_PREVIOUS_NOTE,
  identityLabel,
  restoreDescriptionVersion,
  REVIEW_REASON_HINT,
  REVIEW_REASON_LABEL,
  REVIEW_REASONS,
  reviewProduct,
  reviewReasonLabel,
  type DescriptionVersion,
  type DescriptionVersionsResponse,
  type EvidenceEntry,
  type ReviewListResponse,
  type ReviewReason,
  type ReviewRow,
  type ReviewRowModel,
} from '../lib/productReview'

const PER_PAGE = 50

const SELECT_CLASS = 'rounded border border-slate-300 bg-white px-1.5 py-1 text-xs text-slate-800'
const LABEL_CLASS = 'flex flex-col gap-0.5 text-[11px] text-slate-500'

const REASON_TONE: Record<ReviewReason, string> = {
  identity_soft: 'bg-amber-100 text-amber-900',
  identity_none: 'bg-red-100 text-red-800',
  worse_version: 'bg-violet-100 text-violet-900',
  rejected_source: 'bg-slate-200 text-slate-800',
  manufacturer_missing: 'bg-orange-100 text-orange-900',
  source_unmapped: 'bg-amber-200 text-amber-900',
}

const STATUS_LABEL: Record<DescriptionVersion['status'], string> = {
  published: 'opublikowana',
  proposed: 'propozycja',
  superseded: 'zastąpiona',
  rejected: 'odrzucona',
  shadow: 'próbna (nie trafiła na kartę)',
}

const ORIGIN_LABEL: Record<string, string> = {
  enrichment: 'pobranie opisu',
  sku_cache: 'z innej karty o tym samym kodzie',
  restore: 'przywrócenie',
  review_approve: 'zatwierdzenie w przeglądzie',
  legacy_baseline: 'opis sprzed zapisu wersji',
  stored_sources: 'z zapisanych stron',
  model_shared: 'opis wspólny modelu — przepisany z karty, która pobrała go dla modelu',
}

const DECISION_LABEL: Record<NonNullable<DescriptionVersion['decision']>, string> = {
  approved: 'zatwierdzona',
  rejected: 'odrzucona',
  url_given: 'wskazano właściwą stronę',
  restored: 'przywrócona',
}

const FIELD_LABEL: Record<string, string> = {
  norms: 'norma',
  certificates: 'certyfikat',
  materials: 'materiał',
  specs: 'parametr',
}

function fieldLabel(field: string): string {
  if (field.startsWith('attributes.')) return `cecha: ${field.slice('attributes.'.length).replace(/_/g, ' ')}`
  return FIELD_LABEL[field] ?? field
}

function n(value: number): string {
  return value.toLocaleString('pl-PL')
}

function isReason(value: string | null): value is ReviewReason {
  return value !== null && (REVIEW_REASONS as string[]).includes(value)
}

/** Wpisy dowodów z wersji — tylko poprawne obiekty (payload starszych wersji bywa pusty). */
function evidenceEntries(version: DescriptionVersion | null): EvidenceEntry[] {
  if (!version || !Array.isArray(version.evidence)) return []
  return version.evidence.filter(
    (e): e is EvidenceEntry =>
      typeof e === 'object' && e !== null && typeof e.field === 'string' && typeof e.value === 'string',
  )
}

function errorText(ex: unknown, fallback: string): string {
  return ex instanceof Error && ex.message ? ex.message : fallback
}

/** Sąsiednie wiersze listy o tym samym kluczu modelu; karta bez modelu stoi sama (model = null). */
type ModelGroup = { model: ReviewRowModel | null; rows: ReviewRow[] }

function groupByModel(rows: ReviewRow[]): ModelGroup[] {
  const groups: ModelGroup[] = []
  for (const row of rows) {
    const last = groups[groups.length - 1]
    if (row.model && last?.model && last.model.key === row.model.key) {
      last.rows.push(row)
    } else {
      groups.push({ model: row.model ?? null, rows: [row] })
    }
  }
  return groups
}

/** Skrót tekstu wersji, o której jest decyzja w wierszu: propozycja, a bez niej opis z karty. */
function decidedTextOf(row: ReviewRow): string | null {
  return (row.proposal ?? row.published)?.description_sha1 ?? null
}

/**
 * Rodzaj decyzji zbiorczej dla grupy modelu: 'proposal' = każda karta ma propozycję (Zatwierdź / Odrzuć propozycje),
 * 'published' = żadna nie ma (tylko Zatwierdź). Null = jedna karta albo różne powody / rodzaje decyzji — wtedy
 * decyzje tylko per karta. Null także wtedy, gdy decyzja NIE dotyczy tego samego tekstu na wszystkich kartach (różne
 * propozycje albo różne opisy, np. pobrane osobno, każda ze swojej strony — jedno „Zatwierdź” zatwierdziłoby różne
 * teksty naraz i zdjęło blokady adresów). Propozycje od jednego lidera mają ten sam tekst, więc przechodzą.
 */
function bulkKind(model: ReviewRowModel | null, rows: ReviewRow[]): 'proposal' | 'published' | null {
  if (!model || rows.length < 2) return null
  const [first] = rows
  const withProposal = first.proposal !== null
  const text = decidedTextOf(first)
  const uniform =
    text !== null &&
    rows.every(
      (r) =>
        r.review_reason === first.review_reason &&
        (r.proposal !== null) === withProposal &&
        decidedTextOf(r) === text,
    )
  return uniform ? (withProposal ? 'proposal' : 'published') : null
}

/**
 * Cenniki → „Do przeglądu”: karty cenników z plików, których opis handlowiec powinien sprawdzić — opis ze strony bez
 * kodu wyrobu albo niepotwierdzonej, propozycja gorsza od obecnego opisu, propozycja z odrzuconej strony.
 * Filtry i strona siedzą w adresie (link z zakładki „Z pliku” otwiera listę cennika).
 */
export function PriceListsReview() {
  const { user } = useAuth()
  const canAct = canAny(user, ['products.review', 'price_lists.import'])
  const [params, setParams] = useSearchParams()

  const priceListId = /^\d+$/.test(params.get('price_list_id') ?? '') ? Number(params.get('price_list_id')) : null
  const reasonParam = params.get('reason')
  const reason = isReason(reasonParam) ? reasonParam : null
  const page = Math.max(1, Math.floor(Number(params.get('page'))) || 1)

  const [result, setResult] = useState<ReviewListResponse | null>(null)
  const [loading, setLoading] = useState(false)
  const [err, setErr] = useState('')
  const [msg, setMsg] = useState('')
  const [open, setOpen] = useState<Record<number, boolean>>({})
  /** Karta, przy której trwa akcja; null = nic w toku. */
  const [busy, setBusy] = useState<number | null>(null)
  /** Decyzja zbiorcza w toku („Zastosuj do N kart modelu”): klucz modelu, decyzja i która karta z ilu. */
  const [bulk, setBulk] = useState<{ key: string; action: 'approve' | 'reject'; step: number; total: number } | null>(
    null,
  )
  /** Karta z otwartym polem „Wskaż właściwą stronę”. */
  const [urlFor, setUrlFor] = useState<number | null>(null)
  const [urlValue, setUrlValue] = useState('')
  const [urlErr, setUrlErr] = useState('')
  /** Zwiększany po akcji — rozwinięte szczegóły wczytują historię wersji od nowa. */
  const [detailsToken, setDetailsToken] = useState(0)
  const requestSeq = useRef(0)

  const setFilters = useCallback(
    (patch: Record<string, string | null>, opts: { keepPage?: boolean; replace?: boolean } = {}) => {
      setParams(
        (prev) => {
          const next = new URLSearchParams(prev)
          for (const [k, v] of Object.entries(patch)) {
            if (v === null || v === '') next.delete(k)
            else next.set(k, v)
          }
          if (!opts.keepPage) next.delete('page')
          return next
        },
        { replace: opts.replace },
      )
    },
    [setParams],
  )

  const load = useCallback(async () => {
    const seq = ++requestSeq.current
    setLoading(true)
    setErr('')
    try {
      const res = await fetchReviews({ price_list_id: priceListId, reason, page, per_page: PER_PAGE })
      if (seq !== requestSeq.current) return
      // po decyzji przy ostatniej karcie strona mogła zniknąć — cofamy na ostatnią istniejącą
      const lastPage = Math.max(1, Math.ceil(res.meta.total / res.meta.per_page))
      if (res.data.length === 0 && page > lastPage) {
        setFilters({ page: lastPage > 1 ? String(lastPage) : null }, { keepPage: true, replace: true })
        return
      }
      setResult(res)
    } catch (ex) {
      if (seq !== requestSeq.current) return
      setErr(errorText(ex, 'Nie udało się wczytać listy do przeglądu'))
    } finally {
      if (seq === requestSeq.current) setLoading(false)
    }
  }, [priceListId, reason, page, setFilters])

  useEffect(() => {
    void load()
  }, [load])

  const rows = useMemo(() => result?.data ?? [], [result])
  /** Grupa modelu każdego wiersza (sąsiednie wiersze o tym samym kluczu) — nagłówek stoi przed pierwszym wierszem grupy. */
  const rowGroup = useMemo(() => {
    const map = new Map<number, ModelGroup>()
    for (const group of groupByModel(rows)) {
      for (const row of group.rows) map.set(row.product_id, group)
    }
    return map
  }, [rows])
  const meta = result?.meta ?? null
  const counts = result?.counts ?? null
  const lastPage = meta ? Math.max(1, Math.ceil(meta.total / meta.per_page)) : 1
  const hasFilters = priceListId !== null || reason !== null
  const listMissing = priceListId !== null && counts !== null && !counts.by_price_list.some((l) => l.id === priceListId)

  async function act(row: ReviewRow, action: 'approve' | 'reject') {
    if (busy !== null) return
    const proposalId = row.proposal?.version_id ?? null
    const publishedId = row.published?.version_id ?? null
    const publishedUrl = row.published?.primary_source_url ?? null
    if (action === 'approve') {
      const question = proposalId
        ? `Zastąpić obecny opis karty ${row.sku} nowym opisem (propozycją)?`
        : publishedId
          ? `Opis karty ${row.sku} jest dobry i zostaje na karcie?`
          : `Karta ${row.sku} zostaje bez opisu i znika z listy? Opis można dopisać później ręcznie albo wskazać stronę producenta.`
      if (!window.confirm(question)) return
    } else {
      // odrzucenie blokuje adres źródła wersji — wersja bez adresu niczego nie blokuje i nie wolno tego obiecywać
      const question = proposalId
        ? `Odrzucić nowy opis karty ${row.sku}? Obecny opis zostaje na karcie.`
        : publishedUrl
          ? `Odrzucić opis karty ${row.sku} jako opis z cudzej strony? Opis zostanie usunięty razem ze zdjęciami i plikami z tej strony i pobrany ponownie z pominięciem tej strony (do tego czasu wraca poprzedni opis, jeśli był).`
          : `Odrzucić opis karty ${row.sku}? Opis zostanie usunięty razem ze zdjęciami i plikami z jego pobrania i pobrany ponownie (do tego czasu wraca poprzedni opis, jeśli był). Opis nie ma zapisanej strony źródła, więc żadna strona nie zostanie pominięta — jeśli znasz właściwą stronę wyrobu, wskaż ją zamiast odrzucać.`
      if (!window.confirm(question)) return
    }
    setBusy(row.product_id)
    setErr('')
    setMsg('')
    try {
      // Zatwierdzenie wskazuje wersję, którą handlowiec widział: propozycję albo opis na karcie — serwer odmawia (409),
      // gdy od wczytania listy doszła propozycja. Wiersz bez żadnej wersji idzie bez niej (serwer zdejmuje sam powód).
      // Odrzucenie zawsze wskazuje wersję (propozycję albo opis).
      const versionId = proposalId ?? publishedId
      const res = await reviewProduct(row.product_id, versionId ? { action, version_id: versionId } : { action })
      const clearedNote = res.shop_source_url_cleared
        ? ' Adres strony wskazany wcześniej ręcznie usunięto z karty — ponowne pobranie go nie użyje.'
        : ''
      setMsg(
        action === 'approve'
          ? `Zatwierdzono opis karty ${row.sku}.${res.files_from_previous ? ` ${FILES_FROM_PREVIOUS_NOTE}` : ''}`
          : res.batch_id
            ? publishedUrl
              ? `Odrzucono opis karty ${row.sku} — opis zostanie pobrany ponownie z pominięciem tej strony (pobieranie trwa w tle).${clearedNote}`
              : `Odrzucono opis karty ${row.sku} — opis zostanie pobrany ponownie (pobieranie trwa w tle).${clearedNote}`
            : `Odrzucono nowy opis karty ${row.sku} — obecny opis zostaje.`,
      )
      setDetailsToken((t) => t + 1)
      await load()
    } catch (ex) {
      setErr(errorText(ex, 'Nie udało się zapisać decyzji'))
    } finally {
      setBusy(null)
    }
  }

  /**
   * „Zastosuj do N kart modelu”: ta sama decyzja zapisana kolejno dla każdej karty grupy istniejącym wywołaniem per
   * karta (bez nowego endpointu). Błąd jednej karty (409: od wczytania listy doszła propozycja albo opis z B2B; 422)
   * nie przerywa reszty — komunikat zbiorczy wymienia karty, którym się nie udało, z odpowiedzią serwera.
   * Odrzucenie opisu z karty (bez propozycji) zbiorczo nie istnieje: każde blokuje stronę i zleca pobranie —
   * decyzja per karta.
   */
  async function actGroup(model: ReviewRowModel, groupRows: ReviewRow[], action: 'approve' | 'reject') {
    if (busy !== null || bulk !== null) return
    const kind = bulkKind(model, groupRows)
    if (kind === null || (action === 'reject' && kind !== 'proposal')) return
    const count = groupRows.length
    const question =
      action === 'approve'
        ? kind === 'proposal'
          ? `Zastąpić obecne opisy ${count} kart modelu „${model.stem}” nowymi opisami (propozycjami)? Decyzja zostanie zapisana kolejno dla każdej karty.`
          : `Opisy ${count} kart modelu „${model.stem}” są dobre i zostają na kartach? Decyzja zostanie zapisana kolejno dla każdej karty.`
        : `Odrzucić nowe opisy ${count} kart modelu „${model.stem}”? Obecne opisy zostają na kartach. Decyzja zostanie zapisana kolejno dla każdej karty.`
    if (!window.confirm(question)) return
    setErr('')
    setMsg('')
    let ok = 0
    const failures: string[] = []
    let filesFromPrevious = false
    try {
      for (let i = 0; i < groupRows.length; i++) {
        const row = groupRows[i]
        setBulk({ key: model.key, action, step: i + 1, total: count })
        setBusy(row.product_id)
        const versionId = row.proposal?.version_id ?? row.published?.version_id ?? null
        try {
          const res = await reviewProduct(row.product_id, versionId ? { action, version_id: versionId } : { action })
          ok++
          if (res.files_from_previous) filesFromPrevious = true
        } catch (ex) {
          failures.push(`${row.sku} — ${errorText(ex, 'nie udało się zapisać decyzji')}`)
        }
      }
    } finally {
      setBusy(null)
      setBulk(null)
    }
    setDetailsToken((t) => t + 1)
    // komunikaty po odświeżeniu listy — load() czyści błąd na starcie, a lista nieudanych kart ma zostać widoczna
    await load()
    if (ok > 0) {
      setMsg(
        (action === 'approve'
          ? `Zatwierdzono opisy ${ok} z ${count} kart modelu „${model.stem}”.`
          : `Odrzucono nowe opisy ${ok} z ${count} kart modelu „${model.stem}” — obecne opisy zostają.`) +
          (filesFromPrevious ? ` ${FILES_FROM_PREVIOUS_NOTE}` : ''),
      )
    }
    if (failures.length > 0) {
      setErr(
        `Nie udało się zapisać decyzji dla ${failures.length} z ${count} kart modelu „${model.stem}”: ${failures.join('; ')}`,
      )
    }
  }

  async function submitUrl(e: FormEvent, row: ReviewRow) {
    e.preventDefault()
    const url = urlValue.trim()
    if (busy !== null || url === '') return
    if (!/^https?:\/\//i.test(url)) {
      setUrlErr('Podaj pełny adres strony, zaczynający się od http:// albo https://')
      return
    }
    setBusy(row.product_id)
    setUrlErr('')
    setErr('')
    setMsg('')
    try {
      await reviewProduct(row.product_id, { action: 'url', url })
      setMsg(`Zapisano stronę dla karty ${row.sku} — opis zostanie pobrany z niej ponownie (pobieranie trwa w tle).`)
      setUrlFor(null)
      setUrlValue('')
      setDetailsToken((t) => t + 1)
      await load()
    } catch (ex) {
      // 422: adres sklepu B2B, adres niepoprawny, odmowa kolejki — komunikat z serwera przy polu
      setUrlErr(errorText(ex, 'Nie udało się zapisać adresu'))
    } finally {
      setBusy(null)
    }
  }

  async function onRestored(row: ReviewRow, version: DescriptionVersion, filesFromPrevious: boolean) {
    setMsg(
      `Przywrócono wersję opisu z ${version.created_at ? formatDateTime(version.created_at) : 'historii'} na karcie ${row.sku}.` +
        (filesFromPrevious ? ` ${FILES_FROM_PREVIOUS_NOTE}` : ''),
    )
    setErr('')
    setDetailsToken((t) => t + 1)
    await load()
  }

  return (
    <div>
      <PriceListsTabs />
      <h1 className="text-xl font-semibold">Opisy do przeglądu</h1>
      <p className="mb-3 max-w-4xl text-xs text-slate-500">
        Karty z cenników z plików, których opis warto sprawdzić: program zapisał opis ze strony bez kodu wyrobu albo
        niepotwierdzonej, albo nowe pobranie dało opis gorszy od obecnego. Otwórz stronę źródłową, porównaj z wyrobem
        i zdecyduj: „Zatwierdź”, „Odrzuć” albo „Wskaż właściwą stronę”. Karty tego samego modelu (ten sam wyrób
        w różnych wymiarach i kolorach) stoją obok siebie pod wspólnym nagłówkiem — decyzja jest zawsze per karta,
        a „Zastosuj do N kart modelu” wykonuje ją kolejno dla każdej karty modelu.
      </p>

      <div className="mb-3 flex flex-wrap items-end gap-x-3 gap-y-2 rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs shadow-sm">
        <label className={LABEL_CLASS}>
          Cennik
          <select
            className={`${SELECT_CLASS} max-w-[18rem]`}
            value={priceListId ?? ''}
            onChange={(e) => setFilters({ price_list_id: e.target.value })}
          >
            <option value="">wszystkie</option>
            {listMissing && <option value={priceListId ?? ''}>cennik nr {priceListId} (0)</option>}
            {counts?.by_price_list.map((l) => (
              <option key={l.id} value={l.id}>
                {l.manufacturer || `cennik nr ${l.id}`} ({n(l.count)})
              </option>
            ))}
          </select>
        </label>
        <label className={LABEL_CLASS}>
          Powód
          <select className={SELECT_CLASS} value={reason ?? ''} onChange={(e) => setFilters({ reason: e.target.value })}>
            <option value="">wszystkie</option>
            {REVIEW_REASONS.map((r) => (
              <option key={r} value={r}>
                {REVIEW_REASON_LABEL[r]} ({n(counts?.by_reason[r] ?? 0)})
              </option>
            ))}
          </select>
        </label>
        {hasFilters && (
          <button
            type="button"
            onClick={() => setFilters({ price_list_id: null, reason: null })}
            className="mb-0.5 rounded border border-slate-300 bg-white px-2.5 py-1 text-xs hover:bg-slate-50"
          >
            Wyczyść filtry
          </button>
        )}
        {meta && (
          <span className="mb-1 text-slate-600">
            Do przeglądu: <b>{n(meta.total)}</b> {plural(meta.total, 'karta', 'karty', 'kart')}
          </span>
        )}
        {loading && <span className="mb-1 text-slate-400">Ładowanie…</span>}
      </div>

      {msg && <p className="mb-2 rounded bg-green-50 px-3 py-2 text-xs text-green-800">{msg}</p>}
      {err && <p className="mb-2 rounded bg-red-50 px-3 py-2 text-xs text-red-700">{err}</p>}

      <div className="overflow-x-auto rounded-xl bg-white shadow-sm">
        <table className={`min-w-full text-left text-xs ${loading && result ? 'opacity-60' : ''}`}>
          <thead className="border-b bg-slate-50 text-slate-500">
            <tr>
              <th className="px-3 py-2">Zdjęcie</th>
              <th className="px-3 py-2">SKU</th>
              <th className="px-3 py-2">Nazwa</th>
              <th className="px-3 py-2">Producent</th>
              <th className="px-3 py-2">Strona źródłowa</th>
              <th className="px-3 py-2">Powód</th>
              <th className="px-3 py-2" />
            </tr>
          </thead>
          <tbody>
            {rows.length === 0 && (
              <tr>
                <td colSpan={7} className="px-3 py-6 text-center text-sm text-slate-500">
                  {loading
                    ? 'Ładowanie…'
                    : hasFilters
                      ? 'Nic nie czeka na przegląd przy tych filtrach.'
                      : 'Żaden opis nie czeka na przegląd.'}
                </td>
              </tr>
            )}
            {rows.map((row) => {
              const isOpen = Boolean(open[row.product_id])
              const rowBusy = busy === row.product_id
              const rejectId = row.proposal?.version_id ?? row.published?.version_id ?? null
              const group = rowGroup.get(row.product_id) ?? null
              const model = group?.model ?? null
              // grupa widoczna = co najmniej dwie sąsiednie karty tego modelu na tej stronie listy
              const grouped = group !== null && model !== null && group.rows.length >= 2
              return (
                <Fragment key={row.product_id}>
                  {group && model && group.rows.length >= 2 && group.rows[0] === row && (
                    <ModelGroupHeader
                      model={model}
                      rows={group.rows}
                      canAct={canAct}
                      disabled={busy !== null || bulk !== null}
                      progress={bulk !== null && bulk.key === model.key ? bulk : null}
                      onAct={(action) => void actGroup(model, group.rows, action)}
                    />
                  )}
                  <tr className={`border-t border-slate-100 align-top ${isOpen ? 'bg-blue-50/40' : ''}`}>
                    <td className="px-3 py-2">
                      {row.image_url ? (
                        <img
                          src={row.image_url}
                          alt=""
                          loading="lazy"
                          className="h-12 w-12 rounded border border-slate-200 bg-white object-contain"
                        />
                      ) : (
                        <span className="flex h-12 w-12 items-center justify-center rounded border border-dashed border-slate-200 text-[10px] text-slate-400">
                          brak
                        </span>
                      )}
                    </td>
                    <td className="whitespace-nowrap px-3 py-2 font-mono text-slate-700">{row.sku}</td>
                    <td className="px-3 py-2">
                      <Link to={`/products/${row.product_id}`} className="font-medium text-blue-700 hover:underline">
                        {row.name}
                      </Link>
                      {typeof row.model?.shared_from === 'number' && (
                        <p className="mt-0.5 text-[11px]">
                          <Link
                            to={`/products/${row.model.shared_from}`}
                            className="rounded bg-indigo-50 px-1 py-0.5 text-indigo-800 hover:underline"
                            title="Opis przepisany z karty, która pobrała go dla całego modelu — ten sam tekst; dane i dowody przeliczone dla tej karty"
                          >
                            opis wspólny z karty #{row.model.shared_from}
                          </Link>
                        </p>
                      )}
                      {model && !grouped && model.in_review > 1 && (
                        <p className="mt-0.5 cursor-help text-[11px] text-slate-500" title={MODEL_NOTE}>
                          model {model.stem}: do przeglądu {n(model.in_review)}{' '}
                          {plural(model.in_review, 'karta', 'karty', 'kart')} tego modelu
                        </p>
                      )}
                    </td>
                    <td className="px-3 py-2 text-slate-700">{row.manufacturer || '—'}</td>
                    <td className="max-w-[16rem] px-3 py-2">
                      {row.primary_source_url ? (
                        <a
                          href={row.primary_source_url}
                          target="_blank"
                          rel="noopener noreferrer"
                          className="break-all text-blue-700 hover:underline"
                          title="Otwiera stronę, z której pobrano opis, w nowej karcie"
                        >
                          {siteLabel(row.primary_source_url)}
                        </a>
                      ) : (
                        <span className="text-slate-400">nie zapisano</span>
                      )}
                    </td>
                    <td className="px-3 py-2">
                      <span
                        className={`inline-block cursor-help rounded px-1.5 py-0.5 font-medium ${REASON_TONE[row.review_reason] ?? ''}`}
                        title={REVIEW_REASON_HINT[row.review_reason]}
                      >
                        {reviewReasonLabel(row.review_reason)}
                      </span>
                      {row.review_since && (
                        <p className="mt-0.5 text-[11px] text-slate-400">od {formatDateTime(row.review_since)}</p>
                      )}
                    </td>
                    <td className="px-3 py-2 text-right">
                      <div className="flex flex-col items-end gap-1">
                        {canAct && (
                          <div className="flex flex-wrap justify-end gap-1">
                            <button
                              type="button"
                              disabled={busy !== null}
                              onClick={() => void act(row, 'approve')}
                              className="whitespace-nowrap rounded border border-emerald-300 px-2 py-1 text-[11px] font-medium text-emerald-800 hover:bg-emerald-50 disabled:opacity-50"
                              title={
                                row.proposal
                                  ? 'Nowy opis (propozycja) zastępuje obecny opis karty'
                                  : row.published
                                    ? 'Opis jest dobry — zostaje na karcie, karta znika z listy'
                                    : 'Karta zostaje bez opisu i znika z listy'
                              }
                            >
                              {rowBusy ? 'Zapisuję…' : 'Zatwierdź'}
                            </button>
                            <button
                              type="button"
                              disabled={busy !== null || rejectId === null}
                              onClick={() => void act(row, 'reject')}
                              className="whitespace-nowrap rounded border border-red-300 px-2 py-1 text-[11px] font-medium text-red-700 hover:bg-red-50 disabled:opacity-50"
                              title={
                                rejectId === null
                                  ? 'Karta nie ma zapisanej wersji opisu do odrzucenia — wskaż właściwą stronę'
                                  : row.proposal
                                    ? 'Nowy opis odpada, obecny zostaje'
                                    : row.published?.primary_source_url
                                      ? 'Opis z cudzej strony: znika z karty razem ze zdjęciami i plikami z tej strony, opis zostanie pobrany ponownie z pominięciem tej strony'
                                      : 'Opis bez zapisanej strony źródła: znika z karty razem ze zdjęciami i plikami z jego pobrania i zostanie pobrany ponownie (żadna strona nie zostanie pominięta)'
                              }
                            >
                              Odrzuć
                            </button>
                            <button
                              type="button"
                              disabled={busy !== null}
                              onClick={() => {
                                setUrlErr('')
                                setUrlValue('')
                                setUrlFor((cur) => (cur === row.product_id ? null : row.product_id))
                              }}
                              className="whitespace-nowrap rounded border border-blue-300 px-2 py-1 text-[11px] font-medium text-blue-700 hover:bg-blue-50 disabled:opacity-50"
                              aria-expanded={urlFor === row.product_id}
                            >
                              Wskaż właściwą stronę
                            </button>
                          </div>
                        )}
                        <button
                          type="button"
                          className="whitespace-nowrap rounded-full border border-slate-300 px-3 py-0.5 text-[11px] text-slate-700 hover:bg-slate-50"
                          aria-expanded={isOpen}
                          onClick={() => setOpen((prev) => ({ ...prev, [row.product_id]: !prev[row.product_id] }))}
                        >
                          {row.proposal ? 'Porównaj opisy' : 'Opis i historia'} {isOpen ? '▴' : '▾'}
                        </button>
                      </div>
                    </td>
                  </tr>
                  {canAct && urlFor === row.product_id && (
                    <tr className="bg-blue-50/40">
                      <td colSpan={7} className="px-3 pb-3">
                        <form onSubmit={(e) => void submitUrl(e, row)} className="flex flex-wrap items-start gap-2">
                          <label className={`${LABEL_CLASS} min-w-[20rem] flex-1`}>
                            Adres strony z tym wyrobem (producent albo sklep internetowy)
                            <input
                              type="url"
                              autoFocus
                              value={urlValue}
                              onChange={(e) => setUrlValue(e.target.value)}
                              placeholder="https://…"
                              className={SELECT_CLASS}
                            />
                          </label>
                          <button
                            type="submit"
                            disabled={busy !== null || urlValue.trim() === ''}
                            className="mt-4 rounded bg-blue-600 px-3 py-1 text-xs font-medium text-white hover:bg-blue-700 disabled:opacity-50"
                          >
                            {rowBusy ? 'Zapisuję…' : 'Zapisz i pobierz opis'}
                          </button>
                          <button
                            type="button"
                            onClick={() => setUrlFor(null)}
                            className="mt-4 rounded border border-slate-300 bg-white px-3 py-1 text-xs hover:bg-slate-50"
                          >
                            Anuluj
                          </button>
                          {urlErr && <p className="w-full rounded bg-red-50 px-2 py-1 text-xs text-red-700">{urlErr}</p>}
                        </form>
                      </td>
                    </tr>
                  )}
                  {isOpen && (
                    <tr className="border-b bg-slate-50/60">
                      <td colSpan={7} className="p-3">
                        <ReviewDetails
                          key={`${row.product_id}:${detailsToken}`}
                          row={row}
                          canAct={canAct}
                          onRestored={(v, filesFromPrevious) => void onRestored(row, v, filesFromPrevious)}
                        />
                      </td>
                    </tr>
                  )}
                </Fragment>
              )
            })}
          </tbody>
        </table>
      </div>

      {meta && lastPage > 1 && (
        <div className="mt-3 flex items-center gap-3 text-sm">
          <button
            type="button"
            disabled={loading || page <= 1}
            className="rounded border px-2 py-1 disabled:opacity-40"
            onClick={() => setFilters({ page: page - 1 > 1 ? String(page - 1) : null }, { keepPage: true })}
          >
            ← Poprzednia
          </button>
          <span className="text-slate-600">
            Strona {meta.page} / {lastPage}
          </span>
          <button
            type="button"
            disabled={loading || page >= lastPage}
            className="rounded border px-2 py-1 disabled:opacity-40"
            onClick={() => setFilters({ page: String(page + 1) }, { keepPage: true })}
          >
            Następna →
          </button>
        </div>
      )}
    </div>
  )
}

/**
 * Nagłówek grupy modelu (co najmniej dwie sąsiednie karty tego modelu): rdzeń nazwy, liczba kart i — gdy wszystkie
 * karty grupy mają ten sam powód, rodzaj decyzji i ten sam tekst wersji, o której jest decyzja — „Zastosuj do N kart
 * modelu” z Zatwierdź / Odrzuć propozycje; karty z różnymi tekstami dostają zamiast tego uwagę „decyzje per karta”.
 */
function ModelGroupHeader({
  model,
  rows,
  canAct,
  disabled,
  progress,
  onAct,
}: {
  model: ReviewRowModel
  rows: ReviewRow[]
  canAct: boolean
  disabled: boolean
  /** Postęp decyzji zbiorczej tej grupy (null = nie trwa). */
  progress: { action: 'approve' | 'reject'; step: number; total: number } | null
  onAct: (action: 'approve' | 'reject') => void
}) {
  const count = rows.length
  const inReview = Math.max(model.in_review, count)
  const kind = bulkKind(model, rows)
  const sameText = rows.every((r) => decidedTextOf(r) !== null && decidedTextOf(r) === decidedTextOf(rows[0]))
  const label = (action: 'approve' | 'reject', idle: string) =>
    progress && progress.action === action ? `Zapisuję ${progress.step} z ${progress.total}…` : idle

  return (
    <tr className="border-t border-slate-200 bg-slate-100/70">
      <td colSpan={7} className="px-3 py-1.5">
        <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
          <span className="cursor-help font-semibold text-slate-800" title={MODEL_NOTE}>
            Model: {model.stem}
          </span>
          <span className="text-slate-600">
            {inReview > count
              ? `tu obok siebie ${n(count)} z ${n(inReview)} kart modelu do przeglądu (pozostałe dalej na liście albo na innych stronach)`
              : `${n(count)} ${plural(count, 'karta', 'karty', 'kart')} modelu do przeglądu`}
          </span>
          {canAct && !kind && !sameText && (
            <span
              className="cursor-help text-slate-500"
              title="Karty mają różne teksty (np. opisy pobrane osobno, każda ze swojej strony) — jedno „Zatwierdź” objęłoby różne opisy, więc decyzje tylko pojedynczo"
            >
              różne teksty opisów — decyzje per karta
            </span>
          )}
          {canAct && kind && (
            <span className="ml-auto flex flex-wrap items-center gap-1">
              <span
                className="cursor-help text-slate-500"
                title="Ta sama decyzja zapisana kolejno dla każdej karty modelu — decyzja jest zawsze per karta"
              >
                Zastosuj do {n(count)} kart modelu:
              </span>
              <button
                type="button"
                disabled={disabled}
                onClick={() => onAct('approve')}
                className="whitespace-nowrap rounded border border-emerald-300 bg-white px-2 py-0.5 text-[11px] font-medium text-emerald-800 hover:bg-emerald-50 disabled:opacity-50"
                title={
                  kind === 'proposal'
                    ? 'Nowe opisy (propozycje) zastępują obecne opisy wszystkich kart modelu'
                    : 'Opisy są dobre — zostają na kartach, karty znikają z listy'
                }
              >
                {label('approve', 'Zatwierdź')}
              </button>
              {kind === 'proposal' && (
                <button
                  type="button"
                  disabled={disabled}
                  onClick={() => onAct('reject')}
                  className="whitespace-nowrap rounded border border-red-300 bg-white px-2 py-0.5 text-[11px] font-medium text-red-700 hover:bg-red-50 disabled:opacity-50"
                  title="Nowe opisy odpadają, obecne zostają — na wszystkich kartach modelu; strony nie są blokowane"
                >
                  {label('reject', 'Odrzuć propozycje')}
                </button>
              )}
            </span>
          )}
        </div>
      </td>
    </tr>
  )
}

/** Rozwinięcie wiersza: obecny opis i propozycja, dowody z wersji, historia wersji z „Przywróć”. */
function ReviewDetails({
  row,
  canAct,
  onRestored,
}: {
  row: ReviewRow
  canAct: boolean
  onRestored: (version: DescriptionVersion, filesFromPrevious: boolean) => void
}) {
  const [data, setData] = useState<DescriptionVersionsResponse | null>(null)
  const [err, setErr] = useState('')
  const [restoring, setRestoring] = useState<number | null>(null)

  useEffect(() => {
    let cancelled = false
    fetchDescriptionVersions(row.product_id)
      .then((res) => {
        if (!cancelled) setData(res)
      })
      .catch((ex) => {
        if (!cancelled) setErr(errorText(ex, 'Nie udało się wczytać historii opisu'))
      })
    return () => {
      cancelled = true
    }
  }, [row.product_id])

  async function restore(version: DescriptionVersion) {
    if (restoring !== null) return
    const when = version.created_at ? formatDateTime(version.created_at) : 'z historii'
    if (!window.confirm(`Przywrócić na kartę ${row.sku} opis z ${when}? Obecny opis zostaje w historii.`)) return
    setRestoring(version.id)
    setErr('')
    try {
      const res = await restoreDescriptionVersion(row.product_id, version.id)
      onRestored(version, res.files_from_previous)
    } catch (ex) {
      // 409: opis z B2B albo wersja próbna — komunikat z serwera
      setErr(errorText(ex, 'Nie udało się przywrócić wersji'))
      setRestoring(null)
    }
  }

  if (err && !data) return <p className="rounded bg-red-50 px-3 py-2 text-xs text-red-700">{err}</p>
  if (!data) return <p className="text-xs text-slate-500">Ładowanie historii opisu…</p>

  const versions = data.data
  const current =
    versions.find((v) => v.id === data.current_version_id) ??
    versions.find((v) => v.id === row.published?.version_id) ??
    null
  const proposal = row.proposal ? (versions.find((v) => v.id === row.proposal?.version_id) ?? null) : null
  const evidenceFrom = proposal ?? current
  const evidence = evidenceEntries(evidenceFrom)

  return (
    <div className="space-y-3 text-xs">
      {err && <p className="rounded bg-red-50 px-3 py-2 text-red-700">{err}</p>}
      {REVIEW_REASON_HINT[row.review_reason] && <p className="text-slate-700">{REVIEW_REASON_HINT[row.review_reason]}</p>}
      {row.identity?.reason && (
        <p className="text-slate-600">
          Sprawdzenie strony przez program: <span className="text-slate-800">{row.identity.reason}</span>
        </p>
      )}

      <div className={`grid gap-3 ${proposal ? 'md:grid-cols-2' : ''}`}>
        <VersionText title="Obecny opis na karcie" version={current} empty="Karta nie ma zapisanej wersji opisu." />
        {proposal && <VersionText title="Propozycja — nowy opis" version={proposal} empty="" highlight />}
      </div>

      <div>
        <p className="mb-1 font-semibold text-slate-700">
          Dowody {proposal ? 'w propozycji' : 'w opisie'}{' '}
          <span className="font-normal text-slate-500">— co z opisu program znalazł dosłownie na stronie źródłowej</span>
        </p>
        {evidence.length === 0 ? (
          <p className="text-slate-500">Brak zapisanych dowodów dla tej wersji opisu.</p>
        ) : (
          <ul className="space-y-1">
            {evidence.map((e, i) => (
              <li key={`${e.field}:${e.value}:${i}`} className="rounded border border-slate-200 bg-white px-2 py-1">
                <span className="text-slate-500">{fieldLabel(e.field)}:</span> <b className="text-slate-800">{e.value}</b>{' '}
                {e.status === 'explicit' ? (
                  <span className="rounded bg-emerald-100 px-1 text-emerald-800">jest na stronie</span>
                ) : (
                  <span className="rounded bg-amber-100 px-1 text-amber-900">nie ma tego na stronie</span>
                )}
                {e.quote && <p className="mt-0.5 italic text-slate-600">„{e.quote}”</p>}
              </li>
            ))}
          </ul>
        )}
      </div>

      <div>
        <p className="mb-1 font-semibold text-slate-700">Historia wersji opisu</p>
        {versions.length === 0 ? (
          <p className="text-slate-500">Brak zapisanych wersji.</p>
        ) : (
          <table className="w-full text-left">
            <thead className="text-slate-500">
              <tr className="border-b">
                <th className="py-1 pr-2 font-medium">Zapisano</th>
                <th className="py-1 pr-2 font-medium">Stan</th>
                <th className="py-1 pr-2 font-medium">Skąd</th>
                <th className="py-1 pr-2 font-medium">Strona źródłowa</th>
                <th className="py-1 pr-2 font-medium">Potwierdzenie strony</th>
                <th className="py-1 pr-2 text-right font-medium">Dowody</th>
                <th className="py-1 pr-2 font-medium">Decyzja</th>
                <th className="py-1" />
              </tr>
            </thead>
            <tbody>
              {versions.map((v) => {
                const isCurrent = v.id === data.current_version_id
                const restorable =
                  canAct && !isCurrent && v.status !== 'shadow' && v.status !== 'proposed' && Boolean(v.description)
                return (
                  <tr key={v.id} className="border-b border-slate-100 align-top">
                    <td className="whitespace-nowrap py-1 pr-2">{v.created_at ? formatDateTime(v.created_at) : '—'}</td>
                    <td className="py-1 pr-2">
                      {isCurrent ? (
                        <b className="text-emerald-700">na karcie</b>
                      ) : v.withdrawn ? (
                        <span title="Opis zdjęty z karty bez nowego: producent ma opisy tylko z własnej strony, a ten był ze sklepu. „Przywróć” kładzie go z powrotem.">
                          zdjęta z karty
                        </span>
                      ) : (
                        (STATUS_LABEL[v.status] ?? v.status)
                      )}
                    </td>
                    <td className="py-1 pr-2 text-slate-600">{ORIGIN_LABEL[v.origin] ?? v.origin}</td>
                    <td className="max-w-[14rem] py-1 pr-2">
                      {v.primary_source_url ? (
                        <a
                          href={v.primary_source_url}
                          target="_blank"
                          rel="noopener noreferrer"
                          className="break-all text-blue-700 hover:underline"
                        >
                          {siteLabel(v.primary_source_url)}
                        </a>
                      ) : (
                        <span className="text-slate-400">—</span>
                      )}
                    </td>
                    <td className="py-1 pr-2">{v.identity_verdict ? identityLabel(v.identity_verdict) : '—'}</td>
                    <td className="py-1 pr-2 text-right tabular-nums">{v.evidence_count ?? '—'}</td>
                    <td className="py-1 pr-2 text-slate-600">
                      {v.decision ? DECISION_LABEL[v.decision] ?? v.decision : '—'}
                      {v.decided_by && ` (${v.decided_by.name}${v.decided_at ? `, ${formatDateTime(v.decided_at)}` : ''})`}
                      {v.url_blocked && (
                        <span
                          className="ml-1 rounded bg-red-50 px-1 text-[10px] text-red-700"
                          title="Automat nie zapisuje już opisu z tej strony; zatwierdzenie albo przywrócenie wersji z niej zdejmuje blokadę"
                        >
                          strona pomijana
                        </span>
                      )}
                    </td>
                    <td className="py-1 text-right">
                      {restorable && (
                        <button
                          type="button"
                          disabled={restoring !== null}
                          onClick={() => void restore(v)}
                          className="whitespace-nowrap rounded border border-slate-300 bg-white px-2 py-0.5 text-[11px] text-slate-700 hover:bg-slate-50 disabled:opacity-50"
                          title="Ten opis wraca na kartę; obecny zostaje w historii"
                        >
                          {restoring === v.id ? 'Przywracam…' : 'Przywróć'}
                        </button>
                      )}
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        )}
      </div>
    </div>
  )
}

function VersionText({
  title,
  version,
  empty,
  highlight = false,
}: {
  title: string
  version: DescriptionVersion | null
  empty: string
  highlight?: boolean
}) {
  return (
    <div className={`rounded border bg-white p-2 ${highlight ? 'border-violet-300' : 'border-slate-200'}`}>
      <p className="mb-1 font-semibold text-slate-700">{title}</p>
      {version ? (
        <>
          <p className="mb-1 text-[11px] text-slate-500">
            {version.created_at ? `${formatDateTime(version.created_at)} · ` : ''}
            strona: {identityLabel(version.identity_verdict)}
            {version.evidence_count !== null && ` · dowody: ${version.evidence_count}`}
            {version.primary_source_url && (
              <>
                {' · '}
                <a
                  href={version.primary_source_url}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="break-all text-blue-700 hover:underline"
                >
                  {siteLabel(version.primary_source_url)}
                </a>
              </>
            )}
          </p>
          {version.description ? (
            <p className="max-h-72 overflow-y-auto whitespace-pre-line text-slate-800">{version.description}</p>
          ) : (
            <p className="text-slate-400">Wersja bez tekstu opisu.</p>
          )}
        </>
      ) : (
        <p className="text-slate-500">{empty}</p>
      )}
    </div>
  )
}
