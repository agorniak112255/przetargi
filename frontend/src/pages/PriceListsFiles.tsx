import { Fragment, useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../auth'
import { EnrichmentProgressBanner } from '../components/EnrichmentProgressBanner'
import { PriceListsTabs } from '../components/PriceListsTabs'
import { ApiError, api, can, canAny, parseActiveEnrichment, type EnrichmentBatch } from '../lib/api'
import { applyCheckboxRange } from '../lib/checkboxRange'
import { plural } from '../lib/plural'
import { formatDateTime } from '../lib/priceChange'
import {
  appendSites,
  ENRICHMENT_SITES_MAX,
  parseSiteLines,
  siteHref,
  siteKey,
  toLocalDateTimeInput,
  type EnrichmentSitesMode,
  type EnrichPreviewResponse,
  type FilePriceList,
  type FilePriceListSources,
  type FilePriceListsResponse,
  type PriceListSourcesUpdate,
  type PriceListSourcesUpdateResponse,
  type ReenrichFilters,
  type SearchSiteOption,
  type SearchSitesResponse,
  type SiteCheckResponse,
} from '../lib/priceListSources'

const MODE_LABEL: Record<EnrichmentSitesMode, string> = {
  first: 'Najpierw strony cennika, potem pozostałe strony z listy i reszta internetu',
  only: 'Tylko producent i strony cennika (bez reszty internetu)',
}

const MODE_SHORT: Record<EnrichmentSitesMode, string> = {
  first: 'najpierw strony cennika',
  only: 'tylko producent i strony cennika',
}

const SOURCE_SEGMENTS: { key: keyof FilePriceListSources; label: string; color: string }[] = [
  { key: 'price_list_sites', label: 'ze stron cennika', color: 'bg-emerald-500' },
  { key: 'manufacturer', label: 'od producenta', color: 'bg-violet-500' },
  { key: 'b2b', label: 'z B2B', color: 'bg-indigo-400' },
  { key: 'other', label: 'z innych stron', color: 'bg-sky-400' },
  { key: 'none', label: 'bez opisu', color: 'bg-slate-300' },
]

const PRICE_LIST_SITES_PRIORITY_NOTE =
  'Strony cennika działają, gdy wyrób nie ma karty na stronie producenta — karta producenta zawsze ma pierwszeństwo.'

const NOT_INDEXED_NOTE = 'strona nie jest w indeksie — szukanie pójdzie przez wyszukiwarkę, wolniej'

const ACTIVE_POLL_MS = 3000
const LIST_POLL_MS = 15000

function n(value: number): string {
  return value.toLocaleString('pl-PL')
}

function isActiveStatus(status: string | undefined): boolean {
  return status === 'queued' || status === 'running'
}

function listIsDownloading(list: FilePriceList): boolean {
  return list.queued + list.running > 0 || isActiveStatus(list.batch?.status)
}

function batchPriceListId(batch: EnrichmentBatch): number | null {
  const id = batch.scope === 'price_list' ? batch.scope_id : batch.price_list_id
  return id != null && id > 0 ? id : null
}

/** Błędy walidacji zapisu źródeł rozdzielone na pole stron, tryb i resztę. */
type SaveErrors = { sites: string[]; mode: string[]; general: string }

function saveErrorsFrom(ex: unknown): SaveErrors {
  const out: SaveErrors = { sites: [], mode: [], general: '' }
  if (ex instanceof ApiError && ex.status === 422) {
    const errors = ex.body.errors as Record<string, string[]> | undefined
    if (errors && typeof errors === 'object') {
      for (const [field, messages] of Object.entries(errors)) {
        const list = Array.isArray(messages) ? messages.map(String) : [String(messages)]
        if (field.startsWith('enrichment_sites_mode')) out.mode.push(...list)
        else if (field.startsWith('enrichment_sites')) out.sites.push(...list)
        else out.general = [out.general, ...list].filter(Boolean).join(' ')
      }
      if (out.sites.length + out.mode.length > 0 || out.general !== '') return out
    }
  }
  out.general = ex instanceof Error ? ex.message : 'Nie udało się zapisać źródeł opisów'

  return out
}

/**
 * Cenniki → „Z pliku”: cenniki z plików ze źródłami opisów kart. Przy każdym cenniku strony (kolejność = ważność)
 * i tryb; „Sprawdź na karcie” (lokalny indeks stron) i „Pobierz opisy ponownie” (filtry → podgląd → zlecenie).
 */
export function PriceListsFiles() {
  const { user } = useAuth()
  const canEdit = can(user, 'price_lists.import')
  const canManageSearchSites = can(user, 'admin.search_sites.manage')
  const canSeeBatches = canEdit || can(user, 'products.view')
  const canSeeB2b = can(user, 'b2b_accounts.view')
  const canReview = canAny(user, ['products.review', 'price_lists.import'])

  const [lists, setLists] = useState<FilePriceList[]>([])
  const [loading, setLoading] = useState(true)
  const [err, setErr] = useState('')
  const [msg, setMsg] = useState('')
  const [query, setQuery] = useState('')
  const [open, setOpen] = useState<Record<number, boolean>>({})
  const [batches, setBatches] = useState<EnrichmentBatch[]>([])
  const [reenrichList, setReenrichList] = useState<FilePriceList | null>(null)

  const [searchSites, setSearchSites] = useState<SearchSiteOption[] | null>(null)
  const [searchSitesErr, setSearchSitesErr] = useState('')
  const searchSitesRequested = useRef(false)

  const loadSeq = useRef(0)
  const load = useCallback(async () => {
    const seq = ++loadSeq.current
    try {
      const res = await api<FilePriceListsResponse>('/price-lists/files')
      if (seq !== loadSeq.current) return
      setLists(Array.isArray(res.lists) ? res.lists : [])
    } finally {
      // „Ładowanie…” kończy tylko ostatnie wczytanie — starsze, odrzucone wywołanie pokazywało „Brak cenników”
      if (seq === loadSeq.current) setLoading(false)
    }
  }, [])

  useEffect(() => {
    load().catch((ex) => setErr(ex instanceof Error ? ex.message : 'Błąd wczytywania cenników'))
  }, [load])

  const listIds = useMemo(() => new Set(lists.map((l) => l.id)), [lists])
  const listIdsRef = useRef(listIds)
  useEffect(() => {
    listIdsRef.current = listIds
  }, [listIds])

  const anyActive = lists.some(listIsDownloading) || batches.length > 0

  // Pobieranie w toku: partie co 3 s (pasek postępu), lista z licznikami co 15 s — jej przeliczenie po kartach
  // jest cięższe — i jeszcze raz po końcu ostatniej partii, po liczby końcowe.
  useEffect(() => {
    if (!anyActive) return
    let cancelled = false
    let hadBatches = false
    const pullBatches = () => {
      if (!canSeeBatches) return
      void api<unknown>('/product-enrichment-batches/active')
        .then((res) => {
          if (cancelled) return
          const ours = parseActiveEnrichment(res).batches.filter((b) => {
            const id = batchPriceListId(b)
            return isActiveStatus(b.status) && id !== null && listIdsRef.current.has(id)
          })
          setBatches(ours)
          if (ours.length > 0) {
            hadBatches = true
          } else if (hadBatches) {
            hadBatches = false
            void load().catch(() => {})
          }
        })
        .catch(() => {
          /* sieć / uprawnienia — zostaje ostatni stan */
        })
    }
    pullBatches()
    const batchTimer = window.setInterval(pullBatches, ACTIVE_POLL_MS)
    const listTimer = window.setInterval(() => void load().catch(() => {}), LIST_POLL_MS)
    return () => {
      cancelled = true
      window.clearInterval(batchTimer)
      window.clearInterval(listTimer)
    }
  }, [anyActive, canSeeBatches, load])

  const loadSearchSites = useCallback(() => {
    if (searchSitesRequested.current) return
    searchSitesRequested.current = true
    setSearchSitesErr('')
    api<SearchSitesResponse>('/price-lists/search-sites')
      .then((res) => setSearchSites(Array.isArray(res.sites) ? res.sites : []))
      .catch((ex) => {
        searchSitesRequested.current = false
        setSearchSitesErr(ex instanceof Error ? ex.message : 'Nie udało się wczytać listy stron')
      })
  }, [])

  const visible = useMemo(() => {
    const q = query.trim().toLowerCase()
    return lists
      .filter((l) => q === '' || `${l.manufacturer} ${l.version}`.toLowerCase().includes(q))
      .sort(
        (a, b) =>
          a.manufacturer.localeCompare(b.manufacturer, 'pl', { sensitivity: 'base' }) ||
          b.id - a.id,
      )
  }, [lists, query])

  function onSaved(list: FilePriceList, res: PriceListSourcesUpdateResponse) {
    const sitesChanged =
      (res.enrichment_sites ?? []).map(siteKey).join('\n') !== list.enrichment_sites.map(siteKey).join('\n') ||
      res.enrichment_sites_mode !== list.enrichment_sites_mode
    setErr('')
    setMsg(
      `Zapisano źródła opisów cennika „${list.manufacturer} / ${list.version}”.` +
        (sitesChanged && list.described > 0
          ? ' Opisy pobrane wcześniej zostają bez zmian — żeby pobrać je według nowych stron, użyj „Pobierz opisy ponownie”.'
          : ''),
    )
    void load().catch((ex) => setErr(ex instanceof Error ? ex.message : 'Błąd odświeżania listy'))
  }

  function onQueued(list: FilePriceList, batch: EnrichmentBatch, count: number) {
    setReenrichList(null)
    setErr('')
    setMsg(
      `Zlecono ponowne pobranie opisów dla ${n(count)} ${plural(count, 'karty', 'kart', 'kart')} z „${list.manufacturer} / ${list.version}”. ` +
        'Pobieranie trwa w tle — możesz zamknąć stronę, postęp wróci po odświeżeniu.',
    )
    if (isActiveStatus(batch.status)) {
      setBatches((prev) => [...prev.filter((b) => b.id !== batch.id), batch])
    }
    void load().catch(() => {})
  }

  return (
    <div>
      <PriceListsTabs />
      <div className="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold">Cenniki z plików — źródła opisów</h1>
          <p className="max-w-3xl text-xs text-slate-500">
            Przy każdym cenniku z pliku możesz wskazać strony, z których program pobiera opisy kart — w kolejności
            ważności — albo wybrać je z listy Administracja → „Strony wyszukiwarka”. {PRICE_LIST_SITES_PRIORITY_NOTE}{' '}
            Karty z opisem z B2B nie są tu ruszane.
          </p>
        </div>
        <input
          className="w-64 rounded border border-slate-300 px-2 py-1.5 text-xs"
          placeholder="Szukaj producenta lub wersji"
          value={query}
          onChange={(e) => setQuery(e.target.value)}
        />
      </div>

      {msg && <p className="mb-2 rounded bg-green-50 px-3 py-2 text-xs text-green-800">{msg}</p>}
      {err && <p className="mb-2 rounded bg-red-50 px-3 py-2 text-xs text-red-700">{err}</p>}

      <EnrichmentProgressBanner batches={batches} idleLabel="Startuję worker kolejki…" />

      <div className="rounded-xl bg-white p-4 shadow-sm">
        {loading && <p className="text-sm text-slate-500">Ładowanie…</p>}
        {!loading && lists.length === 0 && !err && (
          <p className="text-sm text-slate-500">Brak cenników z plików z kartami w katalogu.</p>
        )}
        {!loading && lists.length > 0 && (
          <div className="overflow-x-auto">
            <table className="w-full text-left text-xs">
              <thead>
                <tr className="border-b bg-slate-50 text-slate-700">
                  <th className="p-2 font-semibold">Producent</th>
                  <th className="p-2 font-semibold">Wersja</th>
                  <th className="p-2 text-right font-semibold">Kart</th>
                  <th className="p-2 font-semibold">Skąd są opisy</th>
                  <th className="p-2 font-semibold">Pewność opisów i zdjęcia</th>
                  <th className="p-2 font-semibold">Strony cennika</th>
                  <th className="p-2 font-semibold">Pobieranie opisów</th>
                  <th className="p-2" />
                </tr>
              </thead>
              <tbody>
                {visible.map((list) => {
                  const isOpen = Boolean(open[list.id])
                  return (
                    <Fragment key={list.id}>
                      <tr className={`border-b align-top ${isOpen ? 'bg-blue-50/40' : ''}`}>
                        <td className="p-2">
                          <Link
                            to={`/products?price_list=${list.id}&price_list_file=1&price_list_label=${encodeURIComponent(list.manufacturer)}`}
                            className="font-medium text-blue-700 hover:underline"
                            title={`Pokaż karty z cennika: ${list.manufacturer}`}
                          >
                            {list.manufacturer}
                          </Link>
                          {list.has_b2b_account && (
                            <span
                              className="ml-1.5 inline-block rounded bg-indigo-100 px-1.5 py-0.5 align-middle text-[10px] font-semibold text-indigo-800"
                              title="Ten producent ma też konto B2B. Karty z opisem z B2B nie są tu ruszane — ich opis uzupełnia konto B2B."
                            >
                              ma też konto B2B
                            </span>
                          )}
                        </td>
                        <td className="p-2 text-slate-700">{list.version}</td>
                        <td className="p-2 text-right tabular-nums">{n(list.cards)}</td>
                        <td className="min-w-[16rem] p-2">
                          <SourcesBar sources={list.sources} />
                          {list.stale > 0 && list.enrichment_sites_updated_at && (
                            <p
                              className="mt-1 text-amber-800"
                              title={`Opisy pobrane przed zmianą stron cennika (${formatDateTime(list.enrichment_sites_updated_at)})`}
                            >
                              Opisy sprzed zmiany stron: <b>{n(list.stale)}</b>
                            </p>
                          )}
                        </td>
                        <td className="min-w-[13rem] p-2">
                          <QualityCell list={list} canReview={canReview} />
                        </td>
                        <td className="p-2">
                          {list.enrichment_sites.length === 0 ? (
                            <span className="text-slate-400">nie ustawiono</span>
                          ) : (
                            <>
                              <span className="font-medium">
                                {list.enrichment_sites.length}{' '}
                                {plural(list.enrichment_sites.length, 'strona', 'strony', 'stron')}
                              </span>
                              <span className="block text-slate-500">{MODE_SHORT[list.enrichment_sites_mode]}</span>
                            </>
                          )}
                        </td>
                        <td className="p-2">
                          <DownloadState list={list} />
                        </td>
                        <td className="p-2 text-right">
                          <button
                            type="button"
                            className="whitespace-nowrap rounded-full border border-blue-300 px-3 py-1 text-xs font-medium text-blue-700 hover:bg-blue-50"
                            aria-expanded={isOpen}
                            onClick={() => setOpen((prev) => ({ ...prev, [list.id]: !prev[list.id] }))}
                          >
                            Źródła opisów {isOpen ? '▴' : '▾'}
                          </button>
                        </td>
                      </tr>
                      {isOpen && (
                        <tr className="border-b bg-slate-50/60">
                          <td colSpan={8} className="p-3">
                            <SourcesPanel
                              key={`${list.id}:${list.enrichment_sites_mode}:${list.enrichment_sites.join('\n')}`}
                              list={list}
                              canEdit={canEdit}
                              canManageSearchSites={canManageSearchSites}
                              canSeeB2b={canSeeB2b}
                              searchSites={searchSites}
                              searchSitesErr={searchSitesErr}
                              onNeedSearchSites={loadSearchSites}
                              onSaved={(res) => onSaved(list, res)}
                              onReenrich={() => {
                                setMsg('')
                                setReenrichList(list)
                              }}
                            />
                          </td>
                        </tr>
                      )}
                    </Fragment>
                  )
                })}
                {visible.length === 0 && (
                  <tr>
                    <td colSpan={8} className="p-3 text-slate-500">
                      Żaden cennik nie pasuje do „{query.trim()}”.
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {reenrichList && (
        <ReenrichModal
          list={reenrichList}
          onClose={() => setReenrichList(null)}
          onQueued={(batch, count) => onQueued(reenrichList, batch, count)}
        />
      )}
    </div>
  )
}

function SourcesBar({ sources }: { sources: FilePriceListSources }) {
  const total = SOURCE_SEGMENTS.reduce((sum, s) => sum + (sources[s.key] ?? 0), 0)
  if (total === 0) return <span className="text-slate-400">—</span>

  return (
    <div>
      <div className="flex h-2 overflow-hidden rounded bg-slate-100" aria-hidden="true">
        {SOURCE_SEGMENTS.map((s) =>
          sources[s.key] > 0 ? (
            <div key={s.key} className={s.color} style={{ width: `${(sources[s.key] / total) * 100}%` }} />
          ) : null,
        )}
      </div>
      <p className="mt-1 flex flex-wrap gap-x-2.5 gap-y-0.5 text-[11px] text-slate-600">
        {SOURCE_SEGMENTS.map((s) =>
          sources[s.key] > 0 ? (
            <span key={s.key} className="inline-flex items-center gap-1 whitespace-nowrap">
              <span className={`inline-block h-2 w-2 rounded-sm ${s.color}`} />
              {s.label} <b className="tabular-nums">{n(sources[s.key])}</b>
            </span>
          ) : null,
        )}
      </p>
    </div>
  )
}

/**
 * Pewność opisów cennika: karty z opisem według werdyktu tożsamości strony źródłowej, karty czekające na przegląd
 * (link do zakładki „Do przeglądu” z filtrem cennika) i karty ze zdjęciem.
 */
function QualityCell({ list, canReview }: { list: FilePriceList; canReview: boolean }) {
  const identity = list.identity
  const toReview = (
    <>
      do przeglądu: <b className="tabular-nums">{n(list.to_review)}</b>
    </>
  )

  return (
    <div className="space-y-0.5 text-[11px] text-slate-600">
      {list.described > 0 ? (
        <p className="flex flex-wrap gap-x-2 gap-y-0.5">
          <span
            className="whitespace-nowrap"
            title="Na stronie, z której pobrano opis, jest kod wyrobu (SKU, EAN albo kod producenta) albo stronę wskazał człowiek."
          >
            potwierdzone kodem <b className="tabular-nums text-emerald-700">{n(identity.hard)}</b>
          </span>
          <span
            className="whitespace-nowrap"
            title="Na stronie zgadzają się nazwa i producent, ale nie ma kodu wyrobu — to może być inny wariant."
          >
            niepewne <b className="tabular-nums text-amber-700">{n(identity.soft)}</b>
          </span>
          <span
            className="whitespace-nowrap"
            title="Program nie potwierdził, że strona dotyczy tego wyrobu (ani kod, ani nazwa z producentem)."
          >
            bez potwierdzenia <b className="tabular-nums text-red-700">{n(identity.none)}</b>
          </span>
          {identity.unknown > 0 && (
            <span
              className="whitespace-nowrap text-slate-400"
              title="Opisy pobrane, zanim program zaczął sprawdzać stronę źródłową, oraz opisy z B2B."
            >
              niesprawdzone {n(identity.unknown)}
            </span>
          )}
        </p>
      ) : (
        <p className="text-slate-400">brak opisów</p>
      )}
      {list.to_review > 0 &&
        (canReview ? (
          <p>
            <Link
              to={`/price-lists/review?price_list_id=${list.id}`}
              className="font-medium text-amber-800 hover:underline"
              title="Otwórz listę „Do przeglądu” z kartami tego cennika"
            >
              {toReview} →
            </Link>
          </p>
        ) : (
          <p className="text-amber-800">{toReview}</p>
        ))}
      <p>
        ze zdjęciem: <b className="tabular-nums">{n(list.with_image)}</b> z {n(list.cards)}
      </p>
    </div>
  )
}

function DownloadState({ list }: { list: FilePriceList }) {
  const parts: ReactNode[] = []
  const batch = list.batch
  if (batch && isActiveStatus(batch.status)) {
    parts.push(
      <p key="batch" className="font-medium text-blue-700">
        {batch.status === 'queued' ? 'Partia w kolejce' : 'Trwa pobieranie'}: {n(batch.done + batch.failed)} z{' '}
        {n(batch.total)}
        {batch.failed > 0 ? ` (nieudane ${n(batch.failed)})` : ''}
      </p>,
    )
  }
  if (list.queued + list.running > 0) {
    parts.push(
      <p key="queue" className="text-blue-700">
        w kolejce {n(list.queued)} · w trakcie {n(list.running)}
      </p>,
    )
  }
  if (list.failed > 0) {
    parts.push(
      <p key="failed" className="text-red-700">
        nieudane {n(list.failed)}
      </p>,
    )
  }
  if (list.manual > 0) {
    parts.push(
      <p key="manual" className="text-amber-800" title="Program nie znalazł strony wyrobu — opis trzeba uzupełnić ręcznie.">
        do ręcznego opisu {n(list.manual)}
      </p>,
    )
  }
  if (parts.length === 0) return <span className="text-slate-400">—</span>

  return <div className="space-y-0.5">{parts}</div>
}

type SourcesPanelProps = {
  list: FilePriceList
  canEdit: boolean
  canManageSearchSites: boolean
  canSeeB2b: boolean
  searchSites: SearchSiteOption[] | null
  searchSitesErr: string
  onNeedSearchSites: () => void
  onSaved: (res: PriceListSourcesUpdateResponse) => void
  onReenrich: () => void
}

function SourcesPanel({
  list,
  canEdit,
  canManageSearchSites,
  canSeeB2b,
  searchSites,
  searchSitesErr,
  onNeedSearchSites,
  onSaved,
  onReenrich,
}: SourcesPanelProps) {
  const [draft, setDraft] = useState(() => list.enrichment_sites.join('\n'))
  const [mode, setMode] = useState<EnrichmentSitesMode>(list.enrichment_sites_mode)
  const [saving, setSaving] = useState(false)
  const [errors, setErrors] = useState<SaveErrors>({ sites: [], mode: [], general: '' })
  const [pickerOpen, setPickerOpen] = useState(false)

  const parsed = useMemo(() => parseSiteLines(draft), [draft])
  const tooMany = parsed.length > ENRICHMENT_SITES_MAX
  const dirty =
    parsed.map(siteKey).join('\n') !== list.enrichment_sites.map(siteKey).join('\n') ||
    mode !== list.enrichment_sites_mode

  async function save() {
    setSaving(true)
    setErrors({ sites: [], mode: [], general: '' })
    try {
      const body: PriceListSourcesUpdate = {
        enrichment_sites: parsed.length > 0 ? parsed : null,
        enrichment_sites_mode: mode,
      }
      const res = await api<PriceListSourcesUpdateResponse>(`/price-lists/${list.id}`, {
        method: 'PATCH',
        body: JSON.stringify(body),
      })
      onSaved(res)
    } catch (ex) {
      setErrors(saveErrorsFrom(ex))
    } finally {
      setSaving(false)
    }
  }

  const notIndexed = list.hosts.filter((h) => h.indexed_pages === 0)

  return (
    <div className="grid gap-4 lg:grid-cols-2">
      <div className="space-y-3">
        <p className="rounded bg-sky-50 px-3 py-2 text-sky-900">{PRICE_LIST_SITES_PRIORITY_NOTE}</p>
        {list.has_b2b_account && (
          <p className="rounded bg-indigo-50 px-3 py-2 text-indigo-900">
            Ten producent ma też konto B2B. Karty z opisem z B2B nie są tu ruszane — ich opis uzupełniają „Strony z
            opisami” przy koncie (zakładka{' '}
            {canSeeB2b ? (
              <Link className="underline" to="/price-lists/b2b">
                B2B
              </Link>
            ) : (
              'B2B'
            )}
            ).
          </p>
        )}

        {canEdit ? (
          <>
            <label className="block">
              <span className="font-medium text-slate-700">Strony cennika</span>{' '}
              <span className={tooMany ? 'font-semibold text-red-700' : 'text-slate-400'}>
                ({parsed.length} z {ENRICHMENT_SITES_MAX})
              </span>
              <textarea
                rows={6}
                className={`mt-1 w-full rounded border px-2 py-1.5 font-mono text-xs ${
                  errors.sites.length > 0 || tooMany ? 'border-red-400' : 'border-slate-300'
                }`}
                value={draft}
                onChange={(e) => {
                  setDraft(e.target.value)
                  if (errors.sites.length > 0) setErrors((prev) => ({ ...prev, sites: [] }))
                }}
                placeholder={'sklepbhp.pl\nhurtowniabhp.pl'}
                disabled={saving}
              />
              <span className="mt-0.5 block text-slate-500">
                Jedna strona w wierszu (adres albo domena). Kolejność to ważność — strona wyżej wygrywa, gdy wyrób jest
                na kilku. Najwyżej {ENRICHMENT_SITES_MAX} stron; przy pustym polu opisy są pobierane jak dotąd.
              </span>
              {tooMany && (
                <span className="mt-0.5 block text-red-700">
                  Za dużo stron — usuń {parsed.length - ENRICHMENT_SITES_MAX}.
                </span>
              )}
              {errors.sites.map((e) => (
                <span key={e} className="mt-0.5 block text-red-700">
                  {e}
                </span>
              ))}
            </label>
            <button
              type="button"
              className="rounded border border-slate-300 bg-white px-3 py-1.5 text-xs hover:bg-slate-50"
              onClick={() => {
                onNeedSearchSites()
                setPickerOpen(true)
              }}
              disabled={saving}
            >
              Wybierz z listy „Strony wyszukiwarka”…
            </button>

            <fieldset className="space-y-1.5">
              <legend className="mb-1 font-medium text-slate-700">Gdzie szukać opisu, gdy wyrób nie ma karty producenta</legend>
              {(['first', 'only'] as const).map((m) => (
                <label key={m} className="flex items-start gap-2">
                  <input
                    type="radio"
                    name={`mode-${list.id}`}
                    className="mt-0.5"
                    checked={mode === m}
                    onChange={() => {
                      setMode(m)
                      if (errors.mode.length > 0) setErrors((prev) => ({ ...prev, mode: [] }))
                    }}
                    disabled={saving}
                  />
                  <span>
                    {MODE_LABEL[m]}
                    {m === 'only' && (
                      <span className="block text-slate-500">
                        Gdy wyrobu nie ma na stronach cennika, program nie szuka dalej — karta trafia do ręcznego
                        opisu.
                      </span>
                    )}
                  </span>
                </label>
              ))}
              {parsed.length === 0 && (
                <p className="text-slate-500">Bez stron cennika tryb nie ma znaczenia — opisy są pobierane jak dotąd.</p>
              )}
              {errors.mode.map((e) => (
                <p key={e} className="text-red-700">
                  {e}
                </p>
              ))}
            </fieldset>

            {errors.general && <p className="rounded bg-red-50 px-3 py-2 text-red-700">{errors.general}</p>}
            <div className="flex flex-wrap gap-2">
              <button
                type="button"
                className="rounded bg-blue-600 px-3 py-1.5 text-xs text-white hover:bg-blue-700 disabled:opacity-50"
                disabled={!dirty || saving || tooMany}
                onClick={() => void save()}
              >
                {saving ? 'Zapisuję…' : 'Zapisz'}
              </button>
              {dirty && (
                <button
                  type="button"
                  className="rounded border border-slate-300 bg-white px-3 py-1.5 text-xs"
                  disabled={saving}
                  onClick={() => {
                    setDraft(list.enrichment_sites.join('\n'))
                    setMode(list.enrichment_sites_mode)
                    setErrors({ sites: [], mode: [], general: '' })
                  }}
                >
                  Cofnij zmiany
                </button>
              )}
              <button
                type="button"
                className="rounded-full border border-blue-300 px-3 py-1 text-xs font-medium text-blue-700 hover:bg-blue-50 disabled:opacity-50"
                disabled={list.cards === 0 || listIsDownloading(list) || dirty}
                title={
                  listIsDownloading(list)
                    ? 'Pobieranie opisów tego cennika jeszcze trwa'
                    : dirty
                      ? 'Najpierw zapisz zmiany stron — pobieranie używa zapisanych stron'
                      : undefined
                }
                onClick={onReenrich}
              >
                Pobierz opisy ponownie…
              </button>
            </div>
          </>
        ) : (
          <div className="space-y-1">
            <p className="font-medium text-slate-700">Strony cennika</p>
            {list.enrichment_sites.length === 0 ? (
              <p className="text-slate-500">Nie ustawiono — opisy są pobierane jak dotąd.</p>
            ) : (
              <>
                <ol className="list-decimal pl-5">
                  {list.enrichment_sites.map((s) => (
                    <li key={s}>
                      <a className="text-blue-700 underline" href={siteHref(s)} target="_blank" rel="noopener noreferrer">
                        {s}
                      </a>
                    </li>
                  ))}
                </ol>
                <p className="text-slate-600">Tryb: {MODE_LABEL[list.enrichment_sites_mode]}</p>
              </>
            )}
            <p className="text-slate-400">Zmiany zapisuje osoba z uprawnieniem do importu cenników.</p>
          </div>
        )}
      </div>

      <div className="space-y-3">
        {list.hosts.length > 0 && (
          <div>
            <p className="mb-1 font-medium text-slate-700">
              Zapisane strony cennika
              {list.enrichment_sites_updated_at && (
                <span className="font-normal text-slate-500">
                  {' '}
                  · zmienione {formatDateTime(list.enrichment_sites_updated_at)}
                </span>
              )}
            </p>
            {dirty && canEdit && (
              <p className="mb-1 text-slate-500">Lista pokazuje zapisany stan — po zapisie liczby odświeżą się.</p>
            )}
            <table className="w-full bg-white text-left text-xs">
              <thead>
                <tr className="border-b bg-slate-50 text-slate-700">
                  <th className="p-1.5 font-semibold">Pozycja</th>
                  <th className="p-1.5 font-semibold">Strona</th>
                  <th className="p-1.5 text-right font-semibold">Stron w indeksie</th>
                  <th className="p-1.5 text-right font-semibold">Opisanych kart z tej strony</th>
                  <th className="p-1.5 font-semibold">Na liście „Strony wyszukiwarka”</th>
                </tr>
              </thead>
              <tbody>
                {list.hosts.map((h) => (
                  <tr key={h.host} className="border-b align-top">
                    <td className="p-1.5 tabular-nums">{h.position + 1}</td>
                    <td className="p-1.5">
                      <a className="text-blue-700 underline" href={siteHref(h.host)} target="_blank" rel="noopener noreferrer">
                        {h.host}
                      </a>
                      {h.indexed_pages === 0 && <span className="block text-amber-800">{NOT_INDEXED_NOTE}</span>}
                    </td>
                    <td className="p-1.5 text-right tabular-nums">{n(h.indexed_pages)}</td>
                    <td className="p-1.5 text-right tabular-nums">{n(h.described_cards)}</td>
                    <td className="p-1.5">{h.on_search_sites ? 'tak' : <span className="text-slate-500">nie</span>}</td>
                  </tr>
                ))}
              </tbody>
            </table>
            {notIndexed.length > 0 && (
              <p className="mt-1 text-amber-800">
                Strony spoza indeksu ({notIndexed.map((h) => h.host).join(', ')}) są szukane przez wyszukiwarkę
                internetową — wolniej i z ryzykiem pominięcia wyrobu. Dodaj je w Administracja →{' '}
                {canManageSearchSites ? (
                  <Link className="underline" to="/admin/strony-wyszukiwarka">
                    Strony wyszukiwarka
                  </Link>
                ) : (
                  'Strony wyszukiwarka'
                )}
                , żeby program znał ich mapę.
              </p>
            )}
          </div>
        )}

        {canEdit && <SiteCheck list={list} dirty={dirty} />}
      </div>

      {pickerOpen && (
        <SearchSitesPicker
          manufacturer={list.manufacturer}
          currentText={draft}
          sites={searchSites}
          error={searchSitesErr}
          onRetry={onNeedSearchSites}
          onAdd={(hosts) => {
            setDraft((prev) => appendSites(prev, hosts))
            setPickerOpen(false)
          }}
          onClose={() => setPickerOpen(false)}
        />
      )}
    </div>
  )
}

type ProductOption = { id: number; sku: string; name: string }

/** „Sprawdź na karcie”: które zapisane strony cennika mają w lokalnym indeksie stronę wybranej karty. */
function SiteCheck({ list, dirty }: { list: FilePriceList; dirty: boolean }) {
  const [q, setQ] = useState('')
  const [options, setOptions] = useState<ProductOption[]>([])
  const [searching, setSearching] = useState(false)
  const [checking, setChecking] = useState(false)
  const [result, setResult] = useState<SiteCheckResponse | null>(null)
  const [err, setErr] = useState('')
  const seq = useRef(0)
  /** Opis wybranej karty wpisany w pole — nie jest nowym wyszukiwaniem. */
  const [picked, setPicked] = useState<string | null>(null)
  const noSites = list.enrichment_sites.length === 0

  useEffect(() => {
    const term = q.trim()
    if (term.length < 2 || q === picked) {
      seq.current++
      setOptions([])
      setSearching(false)
      return
    }
    const mySeq = ++seq.current
    setSearching(true)
    const timer = window.setTimeout(() => {
      const qs = new URLSearchParams({ price_list: String(list.id), price_list_file: '1', q: term, per_page: '10' })
      api<{ data: ProductOption[] }>(`/products?${qs.toString()}`)
        .then((res) => {
          if (mySeq !== seq.current) return
          setOptions(Array.isArray(res.data) ? res.data : [])
        })
        .catch((ex) => {
          if (mySeq !== seq.current) return
          setOptions([])
          setErr(ex instanceof Error ? ex.message : 'Nie udało się wyszukać kart')
        })
        .finally(() => {
          if (mySeq === seq.current) setSearching(false)
        })
    }, 350)

    return () => window.clearTimeout(timer)
  }, [q, picked, list.id])

  async function check(product: ProductOption) {
    setChecking(true)
    setErr('')
    setResult(null)
    setOptions([])
    const label = `${product.sku} — ${product.name}`
    setPicked(label)
    setQ(label)
    seq.current++
    try {
      setResult(
        await api<SiteCheckResponse>(`/price-lists/${list.id}/site-check`, {
          method: 'POST',
          body: JSON.stringify({ product_id: product.id }),
        }),
      )
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Nie udało się sprawdzić karty')
    } finally {
      setChecking(false)
    }
  }

  return (
    <div className="rounded border border-slate-200 bg-white p-3">
      <p className="font-medium text-slate-700">Sprawdź na karcie</p>
      <p className="mb-2 text-slate-500">
        Pokazuje, na których zapisanych stronach cennika jest strona wybranej karty. Sprawdza tylko lokalny indeks stron
        — bez wyszukiwarki internetowej i bez AI.
        {dirty && ' Używa zapisanych stron, nie zmian w polu obok.'}
      </p>
      {noSites ? (
        <p className="text-slate-500">Najpierw zapisz strony cennika.</p>
      ) : (
        <>
          <div className="relative">
            <input
              className="w-full rounded border border-slate-300 px-2 py-1.5 text-xs"
              placeholder="Kod (SKU) albo nazwa karty z tego cennika"
              value={q}
              onChange={(e) => {
                setQ(e.target.value)
                setErr('')
                setResult(null)
              }}
              disabled={checking}
            />
            {(options.length > 0 || (searching && q.trim().length >= 2)) && (
              <ul className="absolute z-10 mt-1 max-h-64 w-full overflow-auto rounded border border-slate-200 bg-white shadow-lg">
                {searching && options.length === 0 && <li className="px-2 py-1.5 text-slate-500">Szukam…</li>}
                {options.map((p) => (
                  <li key={p.id}>
                    <button
                      type="button"
                      className="block w-full px-2 py-1.5 text-left hover:bg-blue-50"
                      onClick={() => void check(p)}
                    >
                      <span className="font-mono text-slate-700">{p.sku}</span> — {p.name}
                    </button>
                  </li>
                ))}
              </ul>
            )}
            {!searching && q.trim().length >= 2 && q !== picked && options.length === 0 && !err && (
              <p className="mt-1 text-slate-500">Brak kart tego cennika pasujących do wpisu.</p>
            )}
          </div>
          {checking && <p className="mt-2 text-slate-500">Sprawdzam…</p>}
          {err && <p className="mt-2 text-red-700">{err}</p>}
          {result && (
            <div className="mt-2">
              <p className="mb-1 text-slate-700">
                <Link className="font-mono text-blue-700 hover:underline" to={`/products/${result.product.id}`}>
                  {result.product.sku}
                </Link>{' '}
                — {result.product.name}
              </p>
              {result.hits.length === 0 ? (
                <p className="text-amber-800">Brak stron tego wyrobu w indeksie wybranych stron.</p>
              ) : (
                <table className="w-full text-left text-xs">
                  <thead>
                    <tr className="border-b bg-slate-50 text-slate-700">
                      <th className="p-1.5 font-semibold">Pozycja</th>
                      <th className="p-1.5 font-semibold">Strona wyrobu</th>
                      <th className="p-1.5 font-semibold">Ma kod karty</th>
                    </tr>
                  </thead>
                  <tbody>
                    {result.hits.map((h) => (
                      <tr key={h.url} className="border-b align-top">
                        <td className="p-1.5 tabular-nums">{h.position === null ? '—' : h.position + 1}</td>
                        <td className="p-1.5">
                          <a
                            className="break-all text-blue-700 underline"
                            href={h.url}
                            target="_blank"
                            rel="noopener noreferrer"
                          >
                            {h.title?.trim() || h.url}
                          </a>
                          <span className="block text-slate-500">{h.host}</span>
                        </td>
                        <td className="p-1.5">
                          {h.coded ? (
                            <span className="text-emerald-700">tak</span>
                          ) : (
                            <span
                              className="text-amber-800"
                              title="Na stronie nie ma kodu karty — sama nie wystarczy, żeby opis wziąć ze stron cennika (zabezpieczenie przed opisem innego wariantu)."
                            >
                              nie
                            </span>
                          )}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              )}
            </div>
          )}
        </>
      )}
    </div>
  )
}

type PickerProps = {
  manufacturer: string
  currentText: string
  sites: SearchSiteOption[] | null
  error: string
  onRetry: () => void
  onAdd: (hosts: string[]) => void
  onClose: () => void
}

/** Okno wyboru stron z listy Administracja → „Strony wyszukiwarka”; strony producenta cennika na górze. */
function SearchSitesPicker({ manufacturer, currentText, sites, error, onRetry, onAdd, onClose }: PickerProps) {
  const [filter, setFilter] = useState('')
  const [selected, setSelected] = useState<Record<string, boolean>>({})
  const [anchor, setAnchor] = useState<number | null>(null)

  const present = useMemo(() => new Set(parseSiteLines(currentText).map(siteKey)), [currentText])
  const ownManufacturer = manufacturer.trim().toLowerCase()

  const rows = useMemo(() => {
    if (!sites) return []
    const q = filter.trim().toLowerCase()
    const matches = sites.filter(
      (s) => q === '' || s.host.toLowerCase().includes(q) || s.manufacturers.some((m) => m.toLowerCase().includes(q)),
    )
    const own = (s: SearchSiteOption) => s.manufacturers.some((m) => m.trim().toLowerCase() === ownManufacturer)

    return [...matches.filter(own), ...matches.filter((s) => !own(s))]
  }, [sites, filter, ownManufacturer])

  const orderedHosts = useMemo(() => rows.map((s) => s.host), [rows])
  const chosen = useMemo(
    () => (sites ?? []).map((s) => s.host).filter((h) => selected[h] && !present.has(siteKey(h))),
    [sites, selected, present],
  )
  const room = Math.max(0, ENRICHMENT_SITES_MAX - present.size)

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
      role="dialog"
      aria-modal="true"
      aria-labelledby="search-sites-picker-title"
      onClick={onClose}
    >
      <div
        className="flex max-h-[90vh] w-full max-w-3xl flex-col rounded-xl bg-white p-4 text-xs shadow-lg"
        onClick={(e) => e.stopPropagation()}
      >
        <p id="search-sites-picker-title" className="text-sm font-semibold text-slate-800">
          Wybierz strony z listy „Strony wyszukiwarka” — {manufacturer}
        </p>
        <p className="mt-1 text-slate-500">
          Zaznaczone strony dopiszą się na koniec pola (bez powtórzeń); kolejność możesz potem zmienić w polu. Shift +
          kliknięcie zaznacza zakres. Strony przypisane do producenta {manufacturer} są na górze.
        </p>
        <input
          className="mt-2 w-full rounded border border-slate-300 px-2 py-1.5"
          placeholder="Filtruj po domenie albo producencie"
          value={filter}
          onChange={(e) => {
            setFilter(e.target.value)
            setAnchor(null)
          }}
          autoFocus
        />
        <div className="mt-2 min-h-[10rem] flex-1 overflow-auto rounded border border-slate-200">
          {sites === null && !error && <p className="p-3 text-slate-500">Ładowanie listy stron…</p>}
          {error && (
            <p className="p-3 text-red-700">
              {error}{' '}
              <button type="button" className="underline" onClick={onRetry}>
                Spróbuj ponownie
              </button>
            </p>
          )}
          {sites !== null && (
            <table className="w-full text-left">
              <thead className="sticky top-0 bg-slate-50">
                <tr className="border-b text-slate-700">
                  <th className="w-8 p-1.5" />
                  <th className="p-1.5 font-semibold">Domena</th>
                  <th className="p-1.5 text-right font-semibold">Stron w indeksie</th>
                  <th className="p-1.5 font-semibold">Producent</th>
                  <th className="p-1.5 text-right font-semibold">Ranga</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((s, index) => {
                  const already = present.has(siteKey(s.host))
                  return (
                    <tr key={s.host} className={`border-b ${already ? 'text-slate-400' : 'hover:bg-blue-50/50'}`}>
                      <td className="select-none p-1.5">
                        <input
                          type="checkbox"
                          checked={already || Boolean(selected[s.host])}
                          disabled={already}
                          aria-label={`Zaznacz ${s.host}`}
                          title={already ? 'Już w polu stron cennika' : 'Shift + kliknięcie zaznacza wszystkie od ostatnio klikniętej'}
                          onChange={() => {
                            /* obsługa w onClick — potrzebny shiftKey */
                          }}
                          onClick={(e) => {
                            const next = applyCheckboxRange(orderedHosts, selected, anchor, index, e.shiftKey)
                            setSelected(next.selected)
                            setAnchor(next.anchorIndex)
                          }}
                        />
                      </td>
                      <td className="p-1.5">
                        {s.host}
                        {already && <span className="ml-1 text-[10px]">(już na liście)</span>}
                      </td>
                      <td className="p-1.5 text-right tabular-nums">{n(s.links)}</td>
                      <td className="p-1.5">{s.manufacturers.length > 0 ? s.manufacturers.join(', ') : '—'}</td>
                      <td className="p-1.5 text-right tabular-nums">{s.priority ?? '—'}</td>
                    </tr>
                  )
                })}
                {rows.length === 0 && (
                  <tr>
                    <td colSpan={5} className="p-3 text-slate-500">
                      Brak stron pasujących do filtra.
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          )}
        </div>
        {chosen.length > room && (
          <p className="mt-2 text-amber-800">
            Zaznaczono {chosen.length}, a w polu zostało miejsca na {room} (najwyżej {ENRICHMENT_SITES_MAX} stron) —
            przed zapisem trzeba będzie usunąć nadmiar.
          </p>
        )}
        <div className="mt-3 flex justify-end gap-2">
          <button type="button" className="rounded border border-slate-300 px-3 py-1.5" onClick={onClose}>
            Anuluj
          </button>
          <button
            type="button"
            className="rounded bg-blue-600 px-3 py-1.5 text-white disabled:opacity-50"
            disabled={chosen.length === 0}
            onClick={() => onAdd(chosen)}
          >
            Dodaj zaznaczone ({chosen.length})
          </button>
        </div>
      </div>
    </div>
  )
}

type ReenrichProps = {
  list: FilePriceList
  onClose: () => void
  onQueued: (batch: EnrichmentBatch, count: number) => void
}

/** „Pobierz opisy ponownie”: filtry → podgląd liczby kart (apply:false) → potwierdzenie → zlecenie (apply:true). */
function ReenrichModal({ list, onClose, onQueued }: ReenrichProps) {
  const [onlyNotFromSites, setOnlyNotFromSites] = useState(list.enrichment_sites.length > 0)
  const [skipManufacturer, setSkipManufacturer] = useState(true)
  const [useDate, setUseDate] = useState(Boolean(list.enrichment_sites_updated_at))
  const [before, setBefore] = useState(() => toLocalDateTimeInput(list.enrichment_sites_updated_at))
  const [preview, setPreview] = useState<EnrichPreviewResponse | null>(null)
  const [ack, setAck] = useState(false)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  const dateMissing = useDate && before.trim() === ''

  function filters(): ReenrichFilters {
    const f: ReenrichFilters = { only_not_from_sites: onlyNotFromSites, skip_manufacturer: skipManufacturer }
    if (useDate && before.trim() !== '') {
      // Pole daty to czas lokalny bez strefy, a serwer liczy w UTC — wysyłamy chwilę ze strefą. Nieruszona domyślna
      // wartość = dokładna chwila zmiany stron (pole ucina sekundy).
      const untouched = list.enrichment_sites_updated_at !== null
        && before.trim() === toLocalDateTimeInput(list.enrichment_sites_updated_at)
      const local = new Date(before.trim())
      f.enriched_before = untouched
        ? (list.enrichment_sites_updated_at as string)
        : Number.isNaN(local.getTime()) ? before.trim() : local.toISOString()
    }
    return f
  }

  /** Każda zmiana filtrów unieważnia podgląd — zlecenie idzie dopiero po świeżym podglądzie. */
  function changed<T>(setter: (v: T) => void) {
    return (v: T) => {
      setter(v)
      setPreview(null)
      setAck(false)
      setErr('')
    }
  }

  async function runPreview() {
    setBusy(true)
    setErr('')
    try {
      setPreview(
        await api<EnrichPreviewResponse>(`/price-lists/${list.id}/enrich`, {
          method: 'POST',
          body: JSON.stringify({ force: true, ...filters(), apply: false }),
        }),
      )
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Nie udało się policzyć kart')
    } finally {
      setBusy(false)
    }
  }

  async function apply() {
    setBusy(true)
    setErr('')
    try {
      const res = await api<{ batch: EnrichmentBatch; product_ids?: number[] }>(`/price-lists/${list.id}/enrich`, {
        method: 'POST',
        body: JSON.stringify({ force: true, ...filters(), apply: true }),
      })
      onQueued(res.batch, res.product_ids?.length ?? res.batch.total)
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Nie udało się zlecić pobierania')
      setBusy(false)
    }
  }

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
      role="dialog"
      aria-modal="true"
      aria-labelledby="reenrich-title"
      onClick={() => {
        if (!busy) onClose()
      }}
    >
      <div className="w-full max-w-lg rounded-xl bg-white p-4 text-xs shadow-lg" onClick={(e) => e.stopPropagation()}>
        <p id="reenrich-title" className="text-sm font-semibold text-slate-800">
          Pobierz opisy ponownie — {list.manufacturer} / {list.version}
        </p>
        <p className="mt-2 rounded bg-amber-50 px-3 py-2 text-amber-900">
          Ponowne pobranie zastępuje obecny opis karty. Gdy program nie znajdzie strony wyrobu, karta może trafić do
          ręcznego opisu. Karty z opisem z B2B są pomijane. Operacja używa wyszukiwarki i AI — <b>generuje koszty</b>.
        </p>
        {list.enrichment_sites.length === 0 && (
          <p className="mt-2 text-slate-600">
            Ten cennik nie ma zapisanych stron — opisy zostaną pobrane jak dotąd (producent, lista „Strony
            wyszukiwarka”, reszta internetu).
          </p>
        )}

        <div className="mt-3 space-y-2">
          <label className="flex items-start gap-2">
            <input
              type="checkbox"
              className="mt-0.5"
              checked={onlyNotFromSites}
              onChange={(e) => changed(setOnlyNotFromSites)(e.target.checked)}
              disabled={busy || list.enrichment_sites.length === 0}
            />
            <span className={list.enrichment_sites.length === 0 ? 'text-slate-400' : undefined}>
              Tylko karty z opisem spoza stron cennika
            </span>
          </label>
          <label className="flex items-start gap-2">
            <input
              type="checkbox"
              className="mt-0.5"
              checked={skipManufacturer}
              onChange={(e) => changed(setSkipManufacturer)(e.target.checked)}
              disabled={busy}
            />
            <span>
              Pomiń karty opisane przez producenta
              <span className="block text-slate-500">Karta producenta i tak ma pierwszeństwo przed stronami cennika.</span>
            </span>
          </label>
          <div className="flex flex-wrap items-center gap-2">
            <label className="flex items-center gap-2">
              <input
                type="checkbox"
                checked={useDate}
                onChange={(e) => changed(setUseDate)(e.target.checked)}
                disabled={busy}
              />
              <span>Tylko opisy pobrane przed</span>
            </label>
            <input
              type="datetime-local"
              className="rounded border border-slate-300 px-2 py-1"
              value={before}
              onChange={(e) => changed(setBefore)(e.target.value)}
              disabled={busy || !useDate}
            />
          </div>
          {list.enrichment_sites_updated_at && (
            <p className="text-slate-500">
              Strony cennika zmieniono {formatDateTime(list.enrichment_sites_updated_at)} — domyślna data to ta chwila.
            </p>
          )}
        </div>

        {preview && (
          <div className="mt-3 rounded bg-slate-50 px-3 py-2 text-slate-700">
            <p>
              Pasuje <b>{n(preview.matched)}</b> {plural(preview.matched, 'karta', 'karty', 'kart')}, zostanie zleconych{' '}
              <b>{n(preview.will_queue)}</b> (limit partii {n(preview.limit)}).
            </p>
            {preview.skipped_b2b > 0 && <p>Pominięte z opisem z B2B: {n(preview.skipped_b2b)}.</p>}
            {preview.matched > preview.will_queue && preview.will_queue > 0 && (
              <p className="text-slate-500">Resztę zlecisz kolejnym uruchomieniem po zakończeniu tej partii.</p>
            )}
          </div>
        )}
        {preview && preview.will_queue > 0 && (
          <label className="mt-3 flex items-start gap-2 text-slate-700">
            <input
              type="checkbox"
              className="mt-0.5"
              checked={ack}
              onChange={(e) => setAck(e.target.checked)}
              disabled={busy}
            />
            <span>Rozumiem, że opisy tych kart zostaną zastąpione, a pobieranie generuje koszty.</span>
          </label>
        )}
        {err && <p className="mt-3 rounded bg-red-50 px-3 py-2 text-red-700">{err}</p>}

        <div className="mt-4 flex justify-end gap-2">
          <button type="button" className="rounded border border-slate-300 px-3 py-1.5" onClick={onClose} disabled={busy}>
            Anuluj
          </button>
          {!preview ? (
            <button
              type="button"
              className="rounded bg-blue-600 px-3 py-1.5 text-white disabled:opacity-50"
              disabled={busy || dateMissing}
              onClick={() => void runPreview()}
            >
              {busy ? 'Liczę…' : 'Policz karty'}
            </button>
          ) : (
            <button
              type="button"
              className="rounded bg-blue-600 px-3 py-1.5 text-white disabled:opacity-50"
              disabled={busy || !ack || preview.will_queue === 0}
              onClick={() => void apply()}
            >
              {busy ? 'Zlecam…' : `Pobierz (${n(preview.will_queue)})`}
            </button>
          )}
        </div>
      </div>
    </div>
  )
}
