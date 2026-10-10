import { Fragment, useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../auth'
import { EnrichmentProgressBanner } from '../components/EnrichmentProgressBanner'
import { PriceListImportPreviewModal } from '../components/PriceListImportPreviewModal'
import { PriceListIntakeForm, SearchSitesPicker } from '../components/PriceListIntakeForm'
import { PriceListSourcePinsModal } from '../components/PriceListSourcePinsModal'
import { PriceListsTabs } from '../components/PriceListsTabs'
import { ApiError, api, can, canAny, parseActiveEnrichment, type EnrichmentBatch } from '../lib/api'
import { plural } from '../lib/plural'
import { formatDateTime } from '../lib/priceChange'
import {
  countOf,
  downloadPriceListFile,
  fetchImporters,
  fetchPriceListFiles,
  FILE_STATUS_LABEL,
  formatBytes,
  INTAKE_FILE_ACCEPT,
  INTAKE_FILE_MAX_BYTES,
  INTAKE_STATUS_LABEL,
  INTAKE_STATUS_TONE,
  intakeOf,
  setImporter,
  uploadPriceListFile,
  type FilePriceListRow,
  type FileView,
  type ImporterOption,
  type IntakeImportResult,
  type IntakeView,
} from '../lib/priceListIntake'
import {
  appendSites,
  ENRICHMENT_SITES_MAX,
  MODEL_NOTE,
  parseSiteLines,
  siteHref,
  siteKey,
  toLocalDateTimeInput,
  type EnrichmentSitesMode,
  type EnrichPreviewResponse,
  type EnrichQueuedResponse,
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

function listIsDownloading(list: FilePriceListRow): boolean {
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
  // wybór importera cennika: tylko administrator (PATCH /price-lists/{id}/importer: price_lists.import + admin.access)
  const isAdmin = canEdit && can(user, 'admin.access')

  const [lists, setLists] = useState<FilePriceListRow[]>([])
  const [loading, setLoading] = useState(true)
  const [err, setErr] = useState('')
  const [msg, setMsg] = useState('')
  const [query, setQuery] = useState('')
  const [open, setOpen] = useState<Record<number, boolean>>({})
  const [batches, setBatches] = useState<EnrichmentBatch[]>([])
  const [reenrichList, setReenrichList] = useState<FilePriceListRow | null>(null)

  /** Formularz cennika nowym sposobem: 'new' = dodawanie, IntakeView = ustawienia istniejącego. */
  const [intakeForm, setIntakeForm] = useState<'new' | IntakeView | null>(null)
  const [previewFor, setPreviewFor] = useState<{ intake: IntakeView; file: FileView } | null>(null)
  const [pinsFor, setPinsFor] = useState<IntakeView | null>(null)
  const [filesOpen, setFilesOpen] = useState<Record<number, boolean>>({})
  const [uploadingId, setUploadingId] = useState<number | null>(null)
  const [importerBusyId, setImporterBusyId] = useState<number | null>(null)
  const [importers, setImporters] = useState<ImporterOption[] | null>(null)
  const importersRequested = useRef(false)
  /** Cennik wskazany po zapisie albo przy „cennik już istnieje” — podświetlony wiersz. */
  const [highlightId, setHighlightId] = useState<number | null>(null)
  const [catalogManufacturers, setCatalogManufacturers] = useState<string[]>([])
  const catalogManufacturersRequested = useRef(false)

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

  function onSaved(list: FilePriceListRow, res: PriceListSourcesUpdateResponse) {
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

  function onQueued(list: FilePriceListRow, batch: EnrichmentBatch, count: number, models: number | null) {
    setReenrichList(null)
    setErr('')
    // liczba modeli tylko, gdy przyszła i różni się od liczby kart (marka z grupowaniem) — inaczej nic nie wnosi
    const modelsNote =
      models !== null && models !== count
        ? `To ${n(models)} ${plural(models, 'model', 'modele', 'modeli')} — opis jest pobierany raz na model i przepisywany pozostałym kartom modelu. `
        : ''
    setMsg(
      `Zlecono ponowne pobranie opisów dla ${n(count)} ${plural(count, 'karty', 'kart', 'kart')} z „${list.manufacturer} / ${list.version}”. ` +
        modelsNote +
        'Pobieranie trwa w tle — możesz zamknąć stronę, postęp wróci po odświeżeniu.',
    )
    if (isActiveStatus(batch.status)) {
      setBatches((prev) => [...prev.filter((b) => b.id !== batch.id), batch])
    }
    void load().catch(() => {})
  }

  const loadImporters = useCallback(() => {
    if (importersRequested.current) return
    importersRequested.current = true
    fetchImporters()
      .then((res) => setImporters(Array.isArray(res.importers) ? res.importers : []))
      .catch((ex) => {
        importersRequested.current = false
        setErr(ex instanceof Error ? ex.message : 'Nie udało się wczytać listy importerów')
      })
  }, [])

  const hasIntake = lists.some((l) => intakeOf(l) !== null)
  useEffect(() => {
    if (isAdmin && hasIntake) loadImporters()
  }, [isAdmin, hasIntake, loadImporters])

  const canSeeCatalog = can(user, 'products.view')
  function openIntakeForm(value: 'new' | IntakeView) {
    setMsg('')
    setErr('')
    loadSearchSites()
    if (canSeeCatalog && !catalogManufacturersRequested.current) {
      catalogManufacturersRequested.current = true
      api<{ data: string[] }>('/products/manufacturers')
        .then((res) => setCatalogManufacturers(Array.isArray(res.data) ? res.data : []))
        .catch(() => {
          /* podpowiedzi z katalogu są dodatkiem — zostają cenniki i lista stron */
        })
    }
    setIntakeForm(value)
  }

  /** Podpowiedzi producenta: cenniki z tej zakładki, producenci z listy „Strony wyszukiwarka” i z katalogu kart. */
  const manufacturerOptions = useMemo(() => {
    const seen = new Map<string, string>()
    const add = (name: string) => {
      const t = name.trim()
      if (t !== '' && !seen.has(t.toLowerCase())) seen.set(t.toLowerCase(), t)
    }
    lists.forEach((l) => add(l.manufacturer))
    ;(searchSites ?? []).forEach((s) => s.manufacturers.forEach(add))
    catalogManufacturers.forEach(add)

    return [...seen.values()].sort((a, b) => a.localeCompare(b, 'pl', { sensitivity: 'base' }))
  }, [lists, searchSites, catalogManufacturers])

  function onIntakeSaved(intake: IntakeView, kind: 'created' | 'updated' | 'switched') {
    const label = `„${intake.manufacturer} / ${intake.version}”`
    setIntakeForm(null)
    setErr('')
    setQuery('')
    setHighlightId(intake.id)
    setMsg(
      kind === 'updated'
        ? `Zapisano ustawienia cennika ${label}.`
        : `${kind === 'created' ? `Założono cennik ${label}.` : `Cennik ${label} przyjmuje się teraz nowym sposobem.`} ` +
            'Teraz dodaj plik cennika — przycisk „Dodaj plik” przy cenniku. Importer przygotuje programista.',
    )
    void load().catch((ex) => setErr(ex instanceof Error ? ex.message : 'Błąd odświeżania listy'))
  }

  function onShowList(priceListId: number) {
    const list = lists.find((l) => l.id === priceListId)
    setIntakeForm(null)
    setQuery(list?.manufacturer ?? '')
    setHighlightId(priceListId)
  }

  async function uploadFile(list: FilePriceListRow, file: File) {
    setMsg('')
    setErr('')
    if (file.size > INTAKE_FILE_MAX_BYTES) {
      setErr(`Plik „${file.name}” ma ${formatBytes(file.size)} — najwyżej 100 MB.`)
      return
    }
    setUploadingId(list.id)
    try {
      const res = await uploadPriceListFile(list.id, file)
      const same = res.duplicate === true
      const status = res.intake?.status
      const next =
        status === 'awaiting_importer'
          ? ' Programista przygotuje importer — wtedy stan zmieni się na „Gotowy do importu”.'
          : status === 'importer_missing'
            ? ' Importer przypisany do cennika nie jest wdrożony — daj znać programiście.'
            : status === 'ready'
              ? ' Otwórz „Podgląd importu”, sprawdź liczby i zaimportuj.'
              : ''
      setMsg(
        (same
          ? `Ten plik jest już dodany („${res.file.original_name}”, cennik „${list.manufacturer}”) — nic się nie zmieniło.`
          : `Dodano plik „${res.file.original_name}” do cennika „${list.manufacturer} / ${list.version}”.`) + next,
      )
      setHighlightId(list.id)
      await load()
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Nie udało się dodać pliku')
    } finally {
      setUploadingId(null)
    }
  }

  async function changeImporter(list: FilePriceListRow, key: string) {
    setMsg('')
    setErr('')
    setImporterBusyId(list.id)
    try {
      await setImporter(list.id, key === '' ? null : key)
      const label = importers?.find((i) => i.key === key)?.label
      setMsg(
        key === ''
          ? `Odpięto importer od cennika „${list.manufacturer}”.`
          : `Cennik „${list.manufacturer}” używa importera „${label ?? key}”.`,
      )
      await load()
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Nie udało się zmienić importera')
    } finally {
      setImporterBusyId(null)
    }
  }

  function onImported(intake: IntakeView, result: IntakeImportResult, describe: boolean) {
    setPreviewFor(null)
    setErr('')
    const notes = countOf(result.errors)
    setMsg(
      `Zaimportowano „${intake.manufacturer} / ${intake.version}”: nowe karty ${n(result.created ?? 0)}, ` +
        `zaktualizowane ${n(result.updated ?? 0)}, pominięte ${n(result.skipped ?? 0)}, zmiany cen ${n(countOf(result.price_changes))}` +
        (notes > 0 ? `, uwagi ${n(notes)}` : '') +
        '. Importer przypisuje kartom strony w tle' +
        (describe ? ' — potem ruszy pobieranie opisów, każda karta tylko ze swojej strony' : '') +
        '. Karty bez strony pokażą się przy cenniku i w „Do przeglądu”.',
    )
    setHighlightId(intake.id)
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
            Karty z opisem z B2B nie są tu ruszane. Nowy cennik dodajesz przyciskiem „+ Dodaj cennik z pliku”: cennik →
            plik → importer, który przypisze każdej karcie stronę wyrobu.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <input
            className="w-64 rounded border border-slate-300 px-2 py-1.5 text-xs"
            placeholder="Szukaj producenta lub wersji"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
          />
          {canEdit && (
            <button
              type="button"
              className="shrink-0 rounded bg-blue-600 px-3 py-2 text-xs text-white hover:bg-blue-700"
              onClick={() => openIntakeForm('new')}
            >
              + Dodaj cennik z pliku
            </button>
          )}
        </div>
      </div>

      {msg && <p className="mb-2 rounded bg-green-50 px-3 py-2 text-xs text-green-800">{msg}</p>}
      {err && <p className="mb-2 rounded bg-red-50 px-3 py-2 text-xs text-red-700">{err}</p>}

      <EnrichmentProgressBanner batches={batches} idleLabel="Startuję worker kolejki…" />

      <div className="rounded-xl bg-white p-4 shadow-sm">
        {loading && <p className="text-sm text-slate-500">Ładowanie…</p>}
        {!loading && lists.length === 0 && !err && (
          <p className="text-sm text-slate-500">
            Brak cenników z plików z kartami w katalogu.
            {canEdit && ' Nowy cennik dodasz przyciskiem „+ Dodaj cennik z pliku”.'}
          </p>
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
                  const intake = intakeOf(list)
                  const rowTone = list.id === highlightId ? 'bg-amber-50' : isOpen ? 'bg-blue-50/40' : ''
                  return (
                    <Fragment key={list.id}>
                      <tr className={`align-top ${intake ? '' : 'border-b'} ${rowTone}`}>
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
                        <td className="p-2 text-right tabular-nums">
                          {n(list.cards)}
                          {list.models < list.cards && (
                            <p
                              className="mt-0.5 cursor-help whitespace-nowrap text-[11px] text-slate-500"
                              title={MODEL_NOTE}
                            >
                              modeli: {n(list.models)}
                            </p>
                          )}
                        </td>
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
                      {intake && (
                        <tr className={`border-b ${rowTone}`}>
                          <td colSpan={8} className="px-2 pb-2">
                            <IntakeBar
                              list={list}
                              intake={intake}
                              canEdit={canEdit}
                              isAdmin={isAdmin}
                              importers={importers}
                              uploading={uploadingId === list.id}
                              importerBusy={importerBusyId === list.id}
                              filesOpen={Boolean(filesOpen[list.id])}
                              onUpload={(file) => void uploadFile(list, file)}
                              onImporterChange={(key) => void changeImporter(list, key)}
                              onPreview={(file) => {
                                setMsg('')
                                setErr('')
                                setPreviewFor({ intake, file })
                              }}
                              onPins={() => setPinsFor(intake)}
                              onSettings={() => openIntakeForm(intake)}
                              onToggleFiles={() => setFilesOpen((prev) => ({ ...prev, [list.id]: !prev[list.id] }))}
                            />
                          </td>
                        </tr>
                      )}
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
                              onOpenSettings={intake && canEdit ? () => openIntakeForm(intake) : null}
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

      {intakeForm !== null && (
        <PriceListIntakeForm
          // inny cennik = nowy formularz (stan pól z useState)
          key={intakeForm === 'new' ? 'new' : intakeForm.id}
          initial={intakeForm === 'new' ? null : intakeForm}
          manufacturerOptions={manufacturerOptions}
          searchSites={searchSites}
          searchSitesErr={searchSitesErr}
          onNeedSearchSites={loadSearchSites}
          findList={(id) => lists.find((l) => l.id === id)}
          onShowList={onShowList}
          onSwitchExisting={(intake) => openIntakeForm(intake)}
          canAssignOtherBrands={canManageSearchSites}
          onSaved={onIntakeSaved}
          onClose={() => setIntakeForm(null)}
        />
      )}

      {previewFor && (
        <PriceListImportPreviewModal
          intake={previewFor.intake}
          file={previewFor.file}
          onClose={() => setPreviewFor(null)}
          onImported={(result, describe) => onImported(previewFor.intake, result, describe)}
          onImportFailed={() => void load().catch(() => {})}
        />
      )}

      {pinsFor && <PriceListSourcePinsModal intake={pinsFor} canReview={canReview} onClose={() => setPinsFor(null)} />}

      {reenrichList && (
        <ReenrichModal
          list={reenrichList}
          onClose={() => setReenrichList(null)}
          onQueued={(batch, count, models) => onQueued(reenrichList, batch, count, models)}
        />
      )}
    </div>
  )
}

const INTAKE_PREVIEW_STATUSES = new Set(['ready', 'imported', 'failed'])

type IntakeBarProps = {
  list: FilePriceListRow
  intake: IntakeView
  canEdit: boolean
  isAdmin: boolean
  importers: ImporterOption[] | null
  uploading: boolean
  importerBusy: boolean
  filesOpen: boolean
  onUpload: (file: File) => void
  onImporterChange: (key: string) => void
  onPreview: (file: FileView) => void
  onPins: () => void
  onSettings: () => void
  onToggleFiles: () => void
}

/**
 * Pasek cennika nowym sposobem (cennik → plik → importer): stan przyjęcia, importer (administrator wybiera), ostatni
 * plik, strony kart z mapy importera i akcje — „Dodaj plik”, „Podgląd importu”, „Karty bez strony”, „Ustawienia”, pliki.
 */
function IntakeBar({
  list,
  intake,
  canEdit,
  isAdmin,
  importers,
  uploading,
  importerBusy,
  filesOpen,
  onUpload,
  onImporterChange,
  onPreview,
  onPins,
  onSettings,
  onToggleFiles,
}: IntakeBarProps) {
  const fileInput = useRef<HTMLInputElement>(null)
  const latest = intake.latest_file
  const status = intake.status
  const statusText =
    status === 'failed' ? `Błąd: ${latest?.error?.trim() || 'import się nie udał'}` : INTAKE_STATUS_LABEL[status] ?? status
  const canPreview = INTAKE_PREVIEW_STATUSES.has(status) && latest !== null
  const pins = intake.pins ?? { pinned: 0, unresolved: 0, total: 0 }
  const btn =
    'whitespace-nowrap rounded-full border border-blue-300 px-3 py-1 text-xs font-medium text-blue-700 hover:bg-blue-50 disabled:opacity-50'
  // „Dodaj plik” wypełniony, gdy to następny krok (cennik czeka na plik)
  const primaryBtn =
    'whitespace-nowrap rounded-full bg-blue-600 px-3 py-1 text-xs font-medium text-white hover:bg-blue-700 disabled:opacity-50'

  // importery pasujące do producenta cennika na górze listy
  const importerOptions = useMemo(() => {
    const all = importers ?? []
    const fits = (o: ImporterOption) => o.manufacturer_keys.includes(intake.manufacturer_key)
    return [...all.filter(fits), ...all.filter((o) => !fits(o))]
  }, [importers, intake.manufacturer_key])
  const currentMissing =
    intake.importer_key !== null && intake.importer_key !== '' && importers !== null
      ? !importers.some((o) => o.key === intake.importer_key)
      : false

  return (
    <div className="rounded border border-slate-200 bg-white px-2.5 py-2">
      <div className="flex flex-wrap items-center gap-x-3 gap-y-1.5">
        <span
          className={`inline-block max-w-md truncate rounded px-1.5 py-0.5 font-semibold ${INTAKE_STATUS_TONE[status] ?? ''}`}
          title={statusText}
        >
          {statusText}
        </span>

        <span className="text-slate-600">
          Importer:{' '}
          {isAdmin ? (
            <select
              className="rounded border border-slate-300 bg-white px-1.5 py-0.5 text-xs text-slate-800"
              value={intake.importer_key ?? ''}
              disabled={importerBusy || importers === null}
              onChange={(e) => onImporterChange(e.target.value)}
              aria-label={`Importer cennika ${list.manufacturer}`}
            >
              <option value="">— brak —</option>
              {currentMissing && intake.importer_key && (
                <option value={intake.importer_key}>{intake.importer_key} (nie jest wdrożony)</option>
              )}
              {importerOptions.map((o) => (
                <option key={o.key} value={o.key}>
                  {o.label} (wersja {o.version})
                  {o.manufacturer_keys.includes(intake.manufacturer_key) ? '' : ' — inny producent'}
                </option>
              ))}
            </select>
          ) : intake.importer_label || intake.importer_key ? (
            <b className="text-slate-800">{intake.importer_label ?? intake.importer_key}</b>
          ) : (
            <span className="text-slate-400">jeszcze nie ma</span>
          )}
        </span>

        <span className="text-slate-600">
          Plik:{' '}
          {latest ? (
            <>
              <b className="text-slate-800">{latest.original_name}</b>
              {latest.created_at && <span className="text-slate-500"> · {formatDateTime(latest.created_at)}</span>}
            </>
          ) : (
            <span className="text-slate-400">nie dodano</span>
          )}
        </span>

        {pins.total > 0 && (
          <span className="text-slate-600" title="Karty, którym importer przypisał stronę wyrobu">
            Karty ze stroną: <b className="tabular-nums text-emerald-700">{n(pins.pinned)}</b> z {n(pins.total)}
          </span>
        )}
        {pins.unresolved > 0 && (
          <button
            type="button"
            className="whitespace-nowrap rounded-full border border-amber-400 px-3 py-1 text-xs font-medium text-amber-800 hover:bg-amber-50"
            onClick={onPins}
            title="Karty, którym importer nie przypisał strony — nie dostaną opisu z internetu, adres wskazuje się ręcznie"
          >
            Karty bez strony ({n(pins.unresolved)})
          </button>
        )}
        {(pins.human_url ?? 0) > 0 && (
          <span className="text-slate-600" title="Karty z adresem wskazanym przez człowieka — wygrywa z mapą importera">
            adres wskazany ręcznie: <b className="tabular-nums">{n(pins.human_url ?? 0)}</b>
          </span>
        )}
        {pins.unresolved === 0 && pins.total > 0 && (
          <button type="button" className="text-blue-700 underline hover:text-blue-900" onClick={onPins}>
            Strony kart
          </button>
        )}

        <span className="ml-auto flex flex-wrap gap-1.5">
          {canEdit && (
            <>
              <input
                ref={fileInput}
                type="file"
                accept={INTAKE_FILE_ACCEPT}
                className="hidden"
                onChange={(e) => {
                  const file = e.target.files?.[0]
                  e.target.value = ''
                  if (file) onUpload(file)
                }}
              />
              <button
                type="button"
                className={status === 'awaiting_file' ? primaryBtn : btn}
                disabled={uploading}
                onClick={() => fileInput.current?.click()}
                title="Plik cennika: Excel (xlsx, xls), CSV albo PDF, najwyżej 100 MB"
              >
                {uploading ? 'Wysyłam plik…' : 'Dodaj plik'}
              </button>
              <button
                type="button"
                className={btn}
                disabled={!canPreview}
                onClick={() => latest && onPreview(latest)}
                title={
                  canPreview
                    ? 'Co import zmieni — bez zapisu; „Importuj” jest w oknie podglądu'
                    : status === 'awaiting_file'
                      ? 'Najpierw dodaj plik'
                      : 'Podgląd będzie dostępny, gdy cennik ma wdrożony importer'
                }
              >
                Podgląd importu
              </button>
              <button type="button" className={btn} onClick={onSettings}>
                Ustawienia
              </button>
            </>
          )}
          <button type="button" className={btn} onClick={onToggleFiles} aria-expanded={filesOpen}>
            Pliki {filesOpen ? '▴' : '▾'}
          </button>
        </span>
      </div>

      {intake.importer_notes && intake.importer_notes.trim() !== '' && (
        <p className="mt-1 truncate text-slate-500" title={intake.importer_notes}>
          Uwagi dla programisty: {intake.importer_notes}
        </p>
      )}

      {filesOpen && (
        <IntakeFiles
          priceListId={list.id}
          reloadKey={`${latest?.id ?? 0}:${latest?.status ?? ''}:${intake.status}`}
        />
      )}
    </div>
  )
}

/** Pliki cennika nowym sposobem (najnowszy pierwszy): nazwa, data, kto, stan, wersja importera, pobranie. */
function IntakeFiles({ priceListId, reloadKey }: { priceListId: number; reloadKey: string }) {
  const [files, setFiles] = useState<FileView[] | null>(null)
  const [err, setErr] = useState('')
  const [downloadErr, setDownloadErr] = useState('')

  useEffect(() => {
    let cancelled = false
    setErr('')
    fetchPriceListFiles(priceListId)
      .then((res) => {
        if (!cancelled) setFiles(Array.isArray(res.files) ? res.files : [])
      })
      .catch((ex) => {
        if (!cancelled) setErr(ex instanceof Error ? ex.message : 'Nie udało się wczytać plików')
      })

    return () => {
      cancelled = true
    }
  }, [priceListId, reloadKey])

  if (err) return <p className="mt-2 text-red-700">{err}</p>
  if (files === null) return <p className="mt-2 text-slate-500">Ładowanie plików…</p>
  if (files.length === 0) return <p className="mt-2 text-slate-500">Cennik nie ma jeszcze plików.</p>

  return (
    <div className="mt-2">
      <table className="w-full text-left text-xs">
        <thead>
          <tr className="border-b bg-slate-50 text-slate-700">
            <th className="p-1.5 font-semibold">Plik</th>
            <th className="p-1.5 font-semibold">Dodany</th>
            <th className="p-1.5 text-right font-semibold">Rozmiar</th>
            <th className="p-1.5 font-semibold">Stan</th>
            <th className="p-1.5 font-semibold">Zaimportowany</th>
            <th className="p-1.5" />
          </tr>
        </thead>
        <tbody>
          {files.map((f) => (
            <tr key={f.id} className={`border-b align-top ${f.status === 'superseded' ? 'text-slate-400' : ''}`}>
              <td className="p-1.5">{f.original_name}</td>
              <td className="p-1.5">
                {f.created_at ? formatDateTime(f.created_at) : '—'}
                {f.uploaded_by_name && <span className="block text-slate-500">{f.uploaded_by_name}</span>}
              </td>
              <td className="p-1.5 text-right tabular-nums">{formatBytes(f.size)}</td>
              <td className="p-1.5">
                <span className={f.status === 'failed' ? 'text-red-700' : f.status === 'imported' ? 'text-emerald-700' : ''}>
                  {FILE_STATUS_LABEL[f.status] ?? f.status}
                </span>
                {f.error && <span className="block text-red-700">{f.error}</span>}
              </td>
              <td className="p-1.5">
                {f.imported_at ? formatDateTime(f.imported_at) : '—'}
                {f.importer_key && (
                  <span className="block text-slate-500">
                    importer {f.importer_key}
                    {f.importer_version !== null ? `, wersja ${f.importer_version}` : ''}
                  </span>
                )}
              </td>
              <td className="p-1.5 text-right">
                <button
                  type="button"
                  className="text-blue-700 underline hover:text-blue-900"
                  onClick={() => {
                    setDownloadErr('')
                    downloadPriceListFile(priceListId, f).catch((ex) =>
                      setDownloadErr(ex instanceof Error ? ex.message : 'Nie udało się pobrać pliku'),
                    )
                  }}
                >
                  Pobierz
                </button>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
      {downloadErr && <p className="mt-1 text-red-700">{downloadErr}</p>}
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
function QualityCell({ list, canReview }: { list: FilePriceListRow; canReview: boolean }) {
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

function DownloadState({ list }: { list: FilePriceListRow }) {
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
  list: FilePriceListRow
  canEdit: boolean
  canManageSearchSites: boolean
  canSeeB2b: boolean
  searchSites: SearchSiteOption[] | null
  searchSitesErr: string
  onNeedSearchSites: () => void
  onSaved: (res: PriceListSourcesUpdateResponse) => void
  onReenrich: () => void
  /** Cennik nowym sposobem: strony i tryb zmienia się w formularzu „Ustawienia” (null = brak uprawnień). */
  onOpenSettings: (() => void) | null
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
  onOpenSettings,
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

        {canEdit && !intakeOf(list) ? (
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
                <p className="text-slate-600">
                  Tryb: {intakeOf(list) ? MODE_SHORT[list.enrichment_sites_mode] : MODE_LABEL[list.enrichment_sites_mode]}
                </p>
              </>
            )}
            {intakeOf(list) ? (
              <>
                <p className="text-slate-500">
                  Ten cennik przyjmuje się nowym sposobem — strony dostawców i tryb zmieniasz w „Ustawienia” przy
                  cenniku.
                </p>
                {canEdit && (
                  <div className="flex flex-wrap gap-2 pt-1">
                    {onOpenSettings && (
                      <button
                        type="button"
                        className="rounded border border-slate-300 bg-white px-3 py-1.5 text-xs hover:bg-slate-50"
                        onClick={onOpenSettings}
                      >
                        Ustawienia
                      </button>
                    )}
                    <button
                      type="button"
                      className="rounded-full border border-blue-300 px-3 py-1 text-xs font-medium text-blue-700 hover:bg-blue-50 disabled:opacity-50"
                      disabled={list.cards === 0 || listIsDownloading(list)}
                      title={listIsDownloading(list) ? 'Pobieranie opisów tego cennika jeszcze trwa' : undefined}
                      onClick={onReenrich}
                    >
                      Pobierz opisy ponownie…
                    </button>
                  </div>
                )}
              </>
            ) : (
              <p className="text-slate-400">Zmiany zapisuje osoba z uprawnieniem do importu cenników.</p>
            )}
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
function SiteCheck({ list, dirty }: { list: FilePriceListRow; dirty: boolean }) {
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

type ReenrichProps = {
  list: FilePriceListRow
  onClose: () => void
  /** models = liczba modeli w partii z odpowiedzi (null, gdy kolejka ich nie liczy). */
  onQueued: (batch: EnrichmentBatch, count: number, models: number | null) => void
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
      const res = await api<EnrichQueuedResponse>(`/price-lists/${list.id}/enrich`, {
        method: 'POST',
        body: JSON.stringify({ force: true, ...filters(), apply: true }),
      })
      onQueued(
        res.batch,
        res.product_ids?.length ?? res.batch.total,
        typeof res.models_queued === 'number' ? res.models_queued : null,
      )
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
            {typeof preview.will_queue_models === 'number' && preview.will_queue_models !== preview.will_queue && (
              <p className="cursor-help" title={MODEL_NOTE}>
                To <b>{n(preview.will_queue_models)}</b>{' '}
                {plural(preview.will_queue_models, 'model', 'modele', 'modeli')} — opis jest pobierany raz na model (ten
                sam wyrób w różnych wymiarach i kolorach) i przepisywany pozostałym kartom modelu bez osobnego pobierania.
              </p>
            )}
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
