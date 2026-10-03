import { useEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import {
  ApiError,
  fetchNoticeDetails,
  fetchNoticeItems,
  type NoticeDetails,
  type NoticeDocument,
  type NoticeDocumentKind,
  type NoticeItem,
  type NoticeItemsResponse,
  type NoticeRow,
} from '../../lib/api'
import { errorText, fmtDate } from '../../lib/campaignFormat'
import { plural } from '../../lib/plural'
import {
  DOCUMENT_ACCEPT,
  expandDocumentFiles,
  isReadableDocument,
  lotNumberOf,
  sortDocumentsForReading,
  type SkippedDocument,
} from '../../lib/zipDocuments'

const KIND_LABEL: Record<NoticeDocumentKind, string> = {
  description: 'opis przedmiotu zamówienia',
  form: 'formularz cenowy albo ofertowy',
  swz: 'specyfikacja warunków zamówienia (SWZ)',
  other: 'inny dokument',
}

/** Rodzaje zaznaczane na starcie — z nich kreator odczyta pozycje i ceny. */
const DEFAULT_KINDS: NoticeDocumentKind[] = ['description', 'form']

/** Sekcje rozwinięte na starcie: przedmiot zamówienia, terminy, wadium. */
const OPEN_SECTION = /przedmiot|termin|wadium/i

/** Ocena serwera (importable, suggested), a gdy jej brak — ta sama reguła po stronie przeglądarki. */
function importable(d: NoticeDocument): boolean {
  return d.importable ?? isReadableDocument(d.file_name)
}

function suggested(d: NoticeDocument): boolean {
  return d.suggested ?? (importable(d) && DEFAULT_KINDS.includes(d.kind))
}

function megabytes(bytes: number): string {
  return `${(bytes / 1024 / 1024).toLocaleString('pl-PL', { maximumFractionDigits: 1 })} MB`
}

/**
 * Strona postępowania na platformazakupowa.pl otwarta od razu na liście załączników (znacznik sekcji
 * #allAttachmentsTable na stronie /transakcja/{numer}). Pliki pobiera człowiek w swojej przeglądarce — regulamin
 * platformy zabrania pobierania załączników przez automat; „Pobierz wszystkie załączniki” działa po zalogowaniu.
 */
function attachmentsUrl(procedureUrl: string | null): string | null {
  if (!procedureUrl) return null
  try {
    const url = new URL(procedureUrl)
    if (!/(^|\.)platformazakupowa\.pl$/i.test(url.hostname) || !/^\/transakcja\/\d+\/?$/.test(url.pathname)) return null
    url.hash = 'allAttachmentsTable'
    return url.toString()
  } catch {
    return null
  }
}

function externalLink(href: string, label: string) {
  return (
    <a href={href} target="_blank" rel="noopener noreferrer" className="whitespace-nowrap text-blue-700 hover:underline">
      {label} ↗
    </a>
  )
}

/** Ilość tylko z cytatu ogłoszenia (fakt); bez niej — „ilość nie podana”. Różnych jednostek nie sumujemy. */
function quantityLabel(item: NoticeItem): string {
  if (item.quantity == null) return 'ilość nie podana'
  return `${item.quantity.toLocaleString('pl-PL')}${item.unit ? ` ${item.unit}` : ''}`
}

/** „04.10, 14:35” — chwila odczytu modelem. */
function readAtLabel(iso: string | null): string | null {
  if (!iso) return null
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return null
  return d.toLocaleString('pl-PL', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })
}

type ItemGroup = { lotNo: number | null; name: string | null; bhp: boolean | null; items: NoticeItem[] }

/** Towary w częściach ogłoszenia (kolejność części rosnąco, towary bez części na końcu). */
function groupByLot(res: NoticeItemsResponse, items: NoticeItem[]): ItemGroup[] {
  const groups = new Map<number | null, ItemGroup>()
  for (const item of items) {
    let group = groups.get(item.lot_no)
    if (!group) {
      const lot = item.lot_no == null ? undefined : res.lots.find((l) => l.lot_no === item.lot_no)
      group = { lotNo: item.lot_no, name: lot?.name ?? null, bhp: lot?.bhp ?? null, items: [] }
      groups.set(item.lot_no, group)
    }
    group.items.push(item)
  }
  return [...groups.values()].sort((a, b) => (a.lotNo ?? Number.MAX_SAFE_INTEGER) - (b.lotNo ?? Number.MAX_SAFE_INTEGER))
}

function AssortmentRow({ item }: { item: NoticeItem }) {
  return (
    <li
      className={`rounded border border-slate-200 px-2 py-1.5 ${item.quote_found ? '' : 'opacity-60'}`}
      title={`Z ogłoszenia: „${item.quote}”`}
    >
      <div className="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
        <span className="font-medium text-slate-900">{item.name}</span>
        <span className={item.quantity == null ? 'text-slate-500' : 'text-slate-900'}>· {quantityLabel(item)}</span>
        <span
          className={`inline-block rounded px-1.5 text-[11px] ${
            item.bhp ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600'
          }`}
        >
          {item.bhp ? 'możliwe BHP (ocena modelu)' : 'poza BHP (ocena modelu)'}
        </span>
        {!item.quote_found && (
          <span className="text-[11px] text-amber-800">
            nie znaleziono w treści ogłoszenia — tylko propozycja modelu
          </span>
        )}
      </div>
      {item.spec && (
        <p className="mt-0.5 line-clamp-2 text-[11px] text-slate-500" title={item.spec}>
          {item.spec}
        </p>
      )}
    </li>
  )
}

/** Model rusza dopiero, gdy panel zostaje otwarty tyle czasu — przeglądanie listy nie zajmuje miejsc na odczyt. */
const MODEL_DELAY_MS = 1000

/** Zajęty model (503) — jedno ponowienie po tym czasie, potem komunikat. */
const BUSY_RETRY_MS = 5000

type AssortmentPhase = 'cache' | 'wait' | 'model' | 'busy-retry' | 'done'

/**
 * Podsumowanie asortymentu z treści ogłoszenia (GET /notices/{id}/items): towary z ilościami i cechami przepisanymi
 * z ogłoszenia. Najpierw wynik zapamiętany (cached_only — od razu, bez modelu); gdy go nie ma, a użytkownik może
 * uruchomić model — odczyt modelem po MODEL_DELAY_MS otwartego panelu (do pół minuty; metadane i dokumenty są od razu).
 * Komponent zakładany z key = id ogłoszenia: zmiana ogłoszenia zaczyna od zera, zamknięcie przerywa zapytanie i timery.
 */
function NoticeAssortment({ noticeId, canRefresh }: { noticeId: number; canRefresh: boolean }) {
  const [data, setData] = useState<NoticeItemsResponse | null>(null)
  const [err, setErr] = useState<{ text: string; permanent: boolean } | null>(null)
  const [phase, setPhase] = useState<AssortmentPhase>('cache')
  const [seconds, setSeconds] = useState(0)
  const [request, setRequest] = useState({ key: 0, refresh: false })
  const headingRef = useRef<HTMLHeadingElement | null>(null)
  /** po „Spróbuj ponownie” / „Odczytaj ponownie” przycisk znika — fokus wraca na nagłówek sekcji */
  const focusAfterRef = useRef(false)
  const loading = phase !== 'done'

  useEffect(() => {
    const controller = new AbortController()
    const { signal } = controller
    const openedAt = Date.now()
    let timer: number | undefined
    let tick: number | undefined
    setErr(null)

    const fail = (ex: unknown) => {
      if (signal.aborted) return
      // ogłoszenie bez opisu przedmiotu — stan trwały, ponowienie nic nie zmieni
      const permanent = ex instanceof ApiError && ex.status === 422 && ex.body.reason === 'no_description'
      setErr({ text: errorText(ex, 'Nie udało się odczytać asortymentu z ogłoszenia.'), permanent })
      setPhase('done')
    }
    const runModel = (attempt: number) => {
      const started = Date.now()
      setPhase('model')
      setSeconds(0)
      tick = window.setInterval(() => setSeconds(Math.floor((Date.now() - started) / 1000)), 1000)
      fetchNoticeItems(noticeId, { signal, refresh: request.refresh })
        .then((res) => {
          if (signal.aborted) return
          setData(res)
          setPhase('done')
        })
        .catch((ex: unknown) => {
          if (signal.aborted) return
          if (ex instanceof ApiError && ex.status === 503 && attempt === 0) {
            setPhase('busy-retry')
            timer = window.setTimeout(() => runModel(1), BUSY_RETRY_MS)
            return
          }
          fail(ex)
        })
        .finally(() => window.clearInterval(tick))
    }

    if (request.refresh) {
      runModel(0)
    } else {
      setPhase('cache')
      fetchNoticeItems(noticeId, { signal, cachedOnly: true })
        .then((res) => {
          if (signal.aborted) return
          if (res.items !== null || !canRefresh) {
            setData(res)
            setPhase('done')
            return
          }
          setPhase('wait')
          timer = window.setTimeout(() => runModel(0), Math.max(0, MODEL_DELAY_MS - (Date.now() - openedAt)))
        })
        .catch(fail)
    }
    return () => {
      controller.abort()
      window.clearTimeout(timer)
      window.clearInterval(tick)
    }
  }, [noticeId, request, canRefresh])

  useEffect(() => {
    if (phase === 'done' && focusAfterRef.current) {
      focusAfterRef.current = false
      headingRef.current?.focus()
    }
  }, [phase])

  function again(refresh: boolean) {
    focusAfterRef.current = true
    setRequest((r) => ({ key: r.key + 1, refresh }))
  }

  const items = data?.items ?? null
  const groups = data && items ? groupByLot(data, items) : []
  const grouped = groups.length > 1 || (data?.lots.length ?? 0) > 1
  const bhpCount = items?.filter((i) => i.bhp).length ?? 0
  const readAt = readAtLabel(data?.read_at ?? null)
  const statusText =
    phase === 'busy-retry'
      ? 'Model zajęty — ponowię odczyt za kilka sekund…'
      : phase === 'model'
        ? request.refresh
          ? 'Odczytuję asortyment ponownie…'
          : 'Odczytuję asortyment z treści ogłoszenia…'
        : 'Wczytuję asortyment…'

  return (
    <section aria-labelledby="notice-assortment-title" aria-busy={loading}>
      <h3
        id="notice-assortment-title"
        ref={headingRef}
        tabIndex={-1}
        className="mb-1 text-sm font-semibold text-slate-900 focus:outline-none"
      >
        Asortyment z ogłoszenia
      </h3>
      {loading ? (
        <p className="text-slate-500">
          {/* czytnik ekranu dostaje stały tekst, nie licznik co sekundę */}
          <span role="status">{statusText}</span>
          {phase === 'model' && <span aria-hidden="true"> {seconds} s (zwykle do 30 s)</span>}
        </p>
      ) : err?.permanent ? (
        <p className="text-slate-600">{err.text}</p>
      ) : err ? (
        <p className="rounded bg-red-50 px-3 py-2 text-red-700" role="alert">
          {err.text}{' '}
          <button type="button" className="font-medium underline" onClick={() => again(false)}>
            Spróbuj ponownie
          </button>
        </p>
      ) : items === null ? (
        <p className="text-slate-600">{data?.note ?? 'Asortymentu z tego ogłoszenia nikt jeszcze nie odczytał.'}</p>
      ) : items.length === 0 ? (
        <p className="text-slate-600">Ogłoszenie nie wymienia towarów z ilościami — są w dokumentach postępowania.</p>
      ) : (
        <>
          <p className="mb-1 text-slate-600">
            {items.length} {plural(items.length, 'towar', 'towary', 'towarów')}
            {bhpCount > 0 && `, w tym ${bhpCount} możliwe BHP (ocena modelu)`}. Ilości, jednostki i cechy tylko
            przepisane z ogłoszenia — gdy nie stoją przy towarze w treści ogłoszenia, ich nie podajemy.
          </p>
          {grouped ? (
            <div className="space-y-1">
              {groups.map((g) => (
                <details
                  key={g.lotNo ?? 'none'}
                  open={g.bhp !== false || g.items.some((i) => i.bhp)}
                  className="rounded border border-slate-200 px-2 py-1"
                >
                  <summary className="cursor-pointer font-medium text-slate-900">
                    {g.lotNo != null ? `Część ${g.lotNo}` : 'Bez wskazanej części'}
                    {g.name ? <span className="font-normal text-slate-600"> · {g.name}</span> : null}
                    {g.bhp !== null && (
                      <span
                        title="ocena aplikacji z kodów rodzaju zamówienia (CPV) i opisu części"
                        className={`ml-2 inline-block rounded px-1.5 text-[11px] font-normal ${
                          g.bhp ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600'
                        }`}
                      >
                        {g.bhp ? 'możliwe towary BHP (ocena aplikacji)' : 'bez towarów BHP (ocena aplikacji)'}
                      </span>
                    )}
                    <span className="ml-2 font-normal text-slate-500">
                      {g.items.length} {plural(g.items.length, 'towar', 'towary', 'towarów')}
                    </span>
                  </summary>
                  <ul className="mt-1 space-y-1 pb-1">
                    {g.items.map((item, i) => (
                      <AssortmentRow key={`${item.name}-${i}`} item={item} />
                    ))}
                  </ul>
                </details>
              ))}
            </div>
          ) : (
            <ul className="space-y-1">
              {items.map((item, i) => (
                <AssortmentRow key={`${item.name}-${i}`} item={item} />
              ))}
            </ul>
          )}
        </>
      )}
      {!loading && !err && data !== null && (readAt || items !== null || canRefresh) && (
        <p className="mt-1 flex flex-wrap items-baseline gap-x-2 text-[11px] text-slate-500">
          {readAt && <span>odczytano {readAt}</span>}
          {data.source === 'lots' && items && items.length > 0 && (
            <span>odczyt z opisów części (skróconych) — lista może być niepełna</span>
          )}
          {items !== null && (
            <span>Odczyt robi model; cechy są tylko przepisane z ogłoszenia, ocena BHP to wniosek modelu.</span>
          )}
          {canRefresh && (
            <button
              type="button"
              className="font-medium text-blue-700 hover:underline"
              onClick={() => again(true)}
            >
              Odczytaj ponownie
            </button>
          )}
        </p>
      )}
    </section>
  )
}

export type NoticeDocumentSelection = {
  /** zaznaczone dokumenty z listy e-Zamówień (id i nazwa do potwierdzenia) */
  documents: { id: string; name: string }[]
  /** pliki z komputera — kreator odczyta je po założeniu przetargu */
  files: File[]
}

/**
 * Szczegóły ogłoszenia (panel z prawej): nagłówek, podsumowanie asortymentu (NoticeAssortment), pod przyciskiem
 * „Pokaż pełną treść ogłoszenia” części z opisami i sekcje ogłoszenia słowo w słowo, dokumenty
 * (lista z e-Zamówień z polami wyboru albo strefa plików pobranych ręcznie z innej platformy) i akcje. Wybrane pliki
 * są chronione przed przypadkowym zamknięciem (pytanie przy zamknięciu i przy wyjściu ze strony). Escape zamyka panel,
 * chyba że nad nim jest otwarte okno zakładania przetargu (`inactive`).
 */
export function NoticeDetailsPanel({
  row: listRow,
  canCreate,
  canImport,
  inactive,
  busy,
  onClose,
  onCreate,
  onSkip,
}: {
  row: NoticeRow
  canCreate: boolean
  /** uprawnienie tenders.import — bez niego przetarg powstaje bez dokumentów (serwer odrzuca document_ids) */
  canImport: boolean
  inactive: boolean
  busy: boolean
  onClose: () => void
  onCreate: (row: NoticeRow, selection: NoticeDocumentSelection) => void
  onSkip: (row: NoticeRow, skip: boolean) => void
}) {
  const [details, setDetails] = useState<NoticeDetails | null>(null)
  const [loading, setLoading] = useState(true)
  const [err, setErr] = useState('')
  const [reloadKey, setReloadKey] = useState(0)
  const [selected, setSelected] = useState<Set<string>>(new Set())
  const [files, setFiles] = useState<File[]>([])
  /** pliki z pakietów bez towarów BHP — na start nie do odczytu (człowiek może je zaznaczyć) */
  const [excludedFiles, setExcludedFiles] = useState<Set<File>>(() => new Set())
  const [rejected, setRejected] = useState<SkippedDocument[]>([])
  const [unpacking, setUnpacking] = useState(false)
  const [dragOver, setDragOver] = useState(false)
  /** części z opisami i sekcje treści ogłoszenia — zwinięte, na górze podsumowanie asortymentu */
  const [showFull, setShowFull] = useState(false)
  const closeRef = useRef<HTMLButtonElement | null>(null)

  const row = details?.row ?? listRow
  const dirty = files.length > 0

  useEffect(() => {
    const controller = new AbortController()
    setLoading(true)
    setErr('')
    fetchNoticeDetails(listRow.id, controller.signal)
      .then((res) => {
        if (controller.signal.aborted) return
        setDetails(res)
        setSelected(
          new Set(
            res.documents.items.filter(suggested).map((d) => d.id),
          ),
        )
      })
      .catch((ex: unknown) => {
        if (!controller.signal.aborted) setErr(errorText(ex, 'Nie udało się wczytać szczegółów ogłoszenia.'))
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false)
      })
    return () => controller.abort()
  }, [listRow.id, reloadKey])

  // Zamknięcie z wybranymi plikami pyta o potwierdzenie (pliki są tylko w pamięci przeglądarki).
  const requestCloseRef = useRef<() => void>(onClose)
  useEffect(() => {
    requestCloseRef.current = () => {
      if (busy) return
      if (dirty && !window.confirm('Wybrane pliki nie zostały jeszcze przesłane. Zamknąć szczegóły i porzucić je?')) return
      onClose()
    }
  }, [busy, dirty, onClose])

  const inactiveRef = useRef(inactive)
  useEffect(() => {
    inactiveRef.current = inactive
  }, [inactive])

  // Fokus na „Zamknij” po otwarciu, po zamknięciu wraca tam, skąd panel otwarto; Escape zamyka (gdy nie ma okna nad nim).
  useEffect(() => {
    const opener = document.activeElement instanceof HTMLElement ? document.activeElement : null
    closeRef.current?.focus()
    function onKey(e: KeyboardEvent) {
      if (e.key !== 'Escape' || e.defaultPrevented || inactiveRef.current) return
      e.preventDefault()
      requestCloseRef.current()
    }
    window.addEventListener('keydown', onKey)
    return () => {
      window.removeEventListener('keydown', onKey)
      opener?.focus()
    }
  }, [])

  useEffect(() => {
    if (!dirty) return
    function onBeforeUnload(e: BeforeUnloadEvent) {
      e.preventDefault()
      e.returnValue = ''
    }
    window.addEventListener('beforeunload', onBeforeUnload)
    return () => window.removeEventListener('beforeunload', onBeforeUnload)
  }, [dirty])

  /**
   * Pakiet pliku z numeru w nazwie („Pakiet nr 3”): false — pakiet bez towarów BHP (ocena serwera w details.lots);
   * null — bez numeru, nieznany pakiet albo postępowanie bez części BHP do zawężenia.
   */
  function fileLotBhp(f: File): boolean | null {
    const lots = details?.lots ?? []
    if (lots.length < 2 || !lots.some((l) => l.bhp === true)) return null
    const no = lotNumberOf(f.name)
    const lot = no == null ? undefined : lots.find((l) => l.lot_no === no)
    return lot?.bhp ?? null
  }

  /** Pliki i paczki ZIP („Pobierz wszystkie załączniki” na platformie) — paczka rozpakowana w przeglądarce. */
  async function addFiles(list: FileList | null) {
    if (!list || list.length === 0) return
    const picked = Array.from(list)
    setUnpacking(true)
    try {
      const { files: ok, skipped } = await expandDocumentFiles(picked)
      setRejected(skipped)
      const nonBhp = ok.filter((f) => fileLotBhp(f) === false)
      if (nonBhp.length > 0) setExcludedFiles((prev) => new Set([...prev, ...nonBhp]))
      setFiles((prev) =>
        sortDocumentsForReading([...prev, ...ok.filter((f) => !prev.some((p) => p.name === f.name && p.size === f.size))]),
      )
    } finally {
      setUnpacking(false)
    }
  }

  const org = row.organization
  const place = [org.city, org.province_name].filter(Boolean).join(' · ')
  const docs = details?.documents
  const ezItems: NoticeDocument[] = docs?.available ? docs.items : []
  const mayCreate = canCreate && !row.tender && !row.skipped
  const mayAddDocs = mayCreate && canImport && details?.can_import_documents !== false
  const chosenDocs = mayAddDocs ? ezItems.filter((d) => selected.has(d.id) && importable(d)) : []
  const chosenFiles = mayAddDocs ? files.filter((f) => !excludedFiles.has(f)) : []
  const withDocuments = chosenDocs.length + chosenFiles.length > 0

  return (
    <div
      className="fixed inset-0 z-50 flex justify-end bg-black/40"
      role="dialog"
      aria-modal="true"
      aria-labelledby="notice-details-title"
      onClick={() => requestCloseRef.current()}
    >
      <div className="flex h-full w-full max-w-3xl flex-col bg-white shadow-xl" onClick={(e) => e.stopPropagation()}>
        <div className="flex items-start justify-between gap-3 border-b border-slate-200 px-4 py-3">
          <div className="min-w-0">
            <h2 id="notice-details-title" className="text-sm font-semibold text-slate-900">
              {org.name ?? 'Zamawiający nie podany'}
            </h2>
            <p className="text-xs text-slate-500">
              {[place, org.nip ? `NIP ${org.nip}` : null].filter(Boolean).join(' · ') || 'miejscowość nie podana'}
            </p>
          </div>
          <button
            ref={closeRef}
            type="button"
            disabled={busy}
            onClick={() => requestCloseRef.current()}
            className="shrink-0 rounded border border-slate-300 bg-white px-2.5 py-1 text-xs text-slate-700 hover:bg-slate-50 disabled:opacity-50"
          >
            Zamknij
          </button>
        </div>

        <div className="min-h-0 flex-1 space-y-4 overflow-y-auto px-4 py-3 text-xs">
          <div>
            <p className="text-sm text-slate-900">{row.order_object ?? 'Przedmiot zamówienia nie podany'}</p>
            <dl className="mt-2 grid gap-x-3 gap-y-1 sm:grid-cols-[12rem_1fr]">
              <dt className="text-slate-500">Termin składania ofert</dt>
              <dd className="text-slate-900">
                {row.deadline_local ? `${row.deadline_local} (czas polski)` : <span className="text-slate-500">nie podano</span>}
                {row.past && <span className="ml-2 rounded bg-amber-50 px-1.5 py-0.5 text-[11px] text-amber-800">po terminie</span>}
              </dd>
              <dt className="text-slate-500">Wartość z ogłoszenia</dt>
              <dd className="text-slate-900">{row.total_value ?? <span className="text-slate-500">nie podano</span>}</dd>
              <dt className="text-slate-500">Czego dotyczy</dt>
              <dd className="text-slate-900">
                {row.categories.length > 0 ? row.categories.join(', ') : <span className="text-slate-500">inny rodzaj</span>}
                {row.cpv_codes.length > 0 && (
                  <span className="block text-slate-500">kody rodzaju zamówienia (CPV): {row.cpv_codes.join(', ')}</span>
                )}
              </dd>
              <dt className="text-slate-500">Ogłoszenie</dt>
              <dd className="text-slate-900">
                <span className="app-code">{row.notice_number}</span>
                {row.published_at && <span className="text-slate-500"> · opublikowano {fmtDate(row.published_at)}</span>}
              </dd>
              {(row.procedure_url || row.notice_url) && (
                <>
                  <dt className="text-slate-500">Odnośniki</dt>
                  <dd className="flex flex-wrap gap-x-3 gap-y-1">
                    {row.procedure_url && externalLink(row.procedure_url, 'strona postępowania')}
                    {row.notice_url && externalLink(row.notice_url, 'ogłoszenie w Biuletynie')}
                  </dd>
                </>
              )}
            </dl>
            {row.tender && (
              <p className="mt-2 rounded bg-slate-50 px-3 py-2 text-slate-700">
                Z tego postępowania założono przetarg{' '}
                {row.tender.can_open ? (
                  <Link to={`/tenders/${row.tender.id}`} className="app-code font-medium text-blue-700 hover:underline">
                    {row.tender.number}
                  </Link>
                ) : (
                  <span className="app-code">{row.tender.number}</span>
                )}
                {!row.tender.can_open && ' — nie masz do niego dostępu'}.
              </p>
            )}
          </div>

          <NoticeAssortment key={listRow.id} noticeId={listRow.id} canRefresh={canCreate} />

          {err && (
            <p className="rounded bg-red-50 px-3 py-2 text-red-700" role="alert">
              {err}{' '}
              <button type="button" className="font-medium underline" onClick={() => setReloadKey((k) => k + 1)}>
                Spróbuj ponownie
              </button>
            </p>
          )}
          {loading && !details && <p className="text-slate-500">Wczytuję treść ogłoszenia…</p>}

          {details && (
            <>
              {(details.lots.length > 0 || details.sections.length > 0 || !details.html_available) && (
                <button
                  type="button"
                  aria-expanded={showFull}
                  aria-controls="notice-full-content"
                  onClick={() => setShowFull((v) => !v)}
                  className="rounded border border-slate-300 bg-white px-2.5 py-1 text-slate-700 hover:bg-slate-50"
                >
                  {showFull ? 'Zwiń pełną treść ogłoszenia' : 'Pokaż pełną treść ogłoszenia'}
                </button>
              )}

              {showFull && (
                <div id="notice-full-content" className="space-y-4">
                  {!details.html_available && (
                    <p className="rounded bg-amber-50 px-3 py-2 text-amber-900">
                      {details.html_note ||
                        'Pełna treść tego ogłoszenia nie jest już zapisana w aplikacji (aplikacja przechowuje ją 30 dni, gdy z ogłoszenia nie założono przetargu). Poniżej są tylko dane zapisane przy pobraniu: części zamówienia i ich opisy. Całe ogłoszenie przeczytasz w Biuletynie.'}
                    </p>
                  )}

                  {details.lots.length > 0 && (
                    <section aria-labelledby="notice-lots-title">
                      <h3 id="notice-lots-title" className="mb-1 text-sm font-semibold text-slate-900">
                        {details.lots.length > 1
                          ? `Części zamówienia (${details.lots.length})`
                          : 'Przedmiot zamówienia z ogłoszenia'}
                      </h3>
                      <ul className="space-y-2">
                        {details.lots.map((lot, i) => (
                          <li key={`${lot.lot_no ?? 'x'}-${i}`} className="rounded border border-slate-200 px-3 py-2">
                            <div className="font-medium text-slate-900">
                              {lot.lot_no !== null && details.lots.length > 1 ? `Część ${lot.lot_no}: ` : ''}
                              {lot.name ?? <span className="text-slate-500">nazwa nie podana</span>}
                              {details.lots.length > 1 && lot.bhp !== undefined && (
                                <span
                                  title={lot.bhp_reason ?? 'brak kodów CPV z listy BHP i środków ochrony w opisie części'}
                                  className={`ml-2 inline-block rounded px-1.5 text-[11px] font-normal ${
                                    lot.bhp ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600'
                                  }`}
                                >
                                  {lot.bhp ? 'towary BHP' : 'bez towarów BHP'}
                                </span>
                              )}
                            </div>
                            {lot.description ? (
                              <p className="mt-1 whitespace-pre-line text-slate-800">{lot.description}</p>
                            ) : (
                              <p className="mt-1 text-slate-500">Ogłoszenie nie podaje opisu tej części.</p>
                            )}
                            <div className="mt-1 flex flex-wrap gap-x-3 text-[11px] text-slate-500">
                              {lot.cpv_main && (
                                <span>
                                  kod rodzaju zamówienia (CPV): {lot.cpv_main}
                                  {lot.cpv_main_name ? ` — ${lot.cpv_main_name}` : ''}
                                </span>
                              )}
                              <span>wartość z ogłoszenia: {lot.estimated_value ?? 'nie podano'}</span>
                            </div>
                          </li>
                        ))}
                      </ul>
                    </section>
                  )}

                  {details.sections.length > 0 && (
                    <section aria-labelledby="notice-sections-title">
                      <h3 id="notice-sections-title" className="text-sm font-semibold text-slate-900">
                        Treść ogłoszenia
                      </h3>
                      <p className="mb-1 text-[11px] text-slate-500">
                        Tekst słowo w słowo z ogłoszenia w Biuletynie Zamówień Publicznych, w kolejności z ogłoszenia.
                      </p>
                      <div className="space-y-1">
                        {details.sections.map((s) => (
                          <details
                            key={s.key}
                            open={OPEN_SECTION.test(s.title) || OPEN_SECTION.test(s.key)}
                            className="rounded border border-slate-200 px-3 py-1.5"
                          >
                            <summary className="cursor-pointer font-medium text-slate-900">{s.title}</summary>
                            <p className="mt-1 whitespace-pre-line text-slate-800">{s.text}</p>
                          </details>
                        ))}
                      </div>
                    </section>
                  )}
                </div>
              )}

              <section aria-labelledby="notice-docs-title">
                <h3 id="notice-docs-title" className="mb-1 text-sm font-semibold text-slate-900">
                  Dokumenty postępowania
                </h3>
                {docs?.available && ezItems.length > 0 ? (
                  <>
                    {docs.note && <p className="mb-1 text-slate-600">{docs.note}</p>}
                    {mayAddDocs && (
                      <p className="mb-1 text-slate-600">
                        Zaznaczone dokumenty kreator pobierze z e-Zamówień po założeniu przetargu, po jednym, i zapisze w
                        archiwum dokumentów przetargu. Na start zaznaczone są te, które z nazwy wyglądają na opis
                        przedmiotu zamówienia albo formularz cenowy lub ofertowy — przy kilku pakietach tylko z pakietów
                        z towarami BHP. Rodzaj i pakiet to podpowiedź z nazwy, sprawdź je.
                      </p>
                    )}
                    <ul className="space-y-1">
                      {ezItems.map((d) => {
                        const ok = importable(d)
                        return (
                          <li key={d.id}>
                            <label
                              className={`flex items-start gap-2 rounded border px-2 py-1.5 ${
                                selected.has(d.id) ? 'border-blue-600' : 'border-slate-200'
                              } ${ok ? 'cursor-pointer' : 'opacity-70'}`}
                            >
                              <input
                                type="checkbox"
                                className="mt-0.5"
                                checked={selected.has(d.id)}
                                disabled={!ok || !mayAddDocs || busy}
                                onChange={(e) =>
                                  setSelected((prev) => {
                                    const next = new Set(prev)
                                    if (e.target.checked) next.add(d.id)
                                    else next.delete(d.id)
                                    return next
                                  })
                                }
                              />
                              <span className="min-w-0">
                                <span className="font-medium text-slate-900">{d.name}</span>
                                <span className="block break-all text-[11px] text-slate-500">
                                  {d.file_name}
                                  {d.published_at ? ` · opublikowano ${fmtDate(d.published_at)}` : ''}
                                  {` · rodzaj według nazwy: ${KIND_LABEL[d.kind] ?? KIND_LABEL.other}`}
                                </span>
                                {d.lot_no != null && d.lot_bhp === false && (
                                  <span className="block text-[11px] text-slate-600">
                                    Pakiet {d.lot_no} — bez towarów BHP, dlatego nie zaznaczony.
                                  </span>
                                )}
                                {!ok && (
                                  <span className="block text-[11px] text-amber-800">
                                    Kreator nie odczyta pliku tego rodzaju — jeśli to paczka ZIP, pobierz ją ze strony
                                    postępowania i przeciągnij niżej: aplikacja ją rozpakuje.
                                  </span>
                                )}
                              </span>
                            </label>
                          </li>
                        )
                      })}
                    </ul>
                  </>
                ) : (
                  docs && (
                    <>
                      <p className="mb-1 text-slate-600">{docs.note}</p>
                      {attachmentsUrl(row.procedure_url) && (
                        <p className="mb-1 text-slate-700">
                          <a
                            href={attachmentsUrl(row.procedure_url) ?? undefined}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="mr-2 inline-flex items-center rounded border border-blue-600 px-2 py-0.5 font-medium text-blue-700 hover:bg-blue-50"
                          >
                            Otwórz załączniki na platformie ↗
                          </a>
                          Tam zaloguj się, kliknij „Pobierz wszystkie załączniki” i przeciągnij pobraną paczkę ZIP niżej.
                        </p>
                      )}
                    </>
                  )
                )}

                {mayAddDocs ? (
                  <div className="mt-2">
                    <label
                      onDragOver={(e) => {
                        e.preventDefault()
                        setDragOver(true)
                      }}
                      onDragLeave={() => setDragOver(false)}
                      onDrop={(e) => {
                        e.preventDefault()
                        setDragOver(false)
                        if (!busy && !unpacking) void addFiles(e.dataTransfer.files)
                      }}
                      className={`app-dropzone flex cursor-pointer flex-col items-center justify-center gap-1 rounded-xl border-2 border-dashed px-4 py-5 text-center focus-within:ring-2 focus-within:ring-blue-500 ${
                        dragOver ? 'border-blue-600 bg-sky-50' : 'border-slate-300 bg-slate-50'
                      } ${busy ? 'pointer-events-none opacity-60' : ''}`}
                    >
                      <strong className="text-sm">
                        {docs?.available && ezItems.length > 0
                          ? 'Dodatkowe pliki z komputera (nieobowiązkowe)'
                          : 'Przeciągnij tu dokumenty pobrane ze strony postępowania'}
                      </strong>
                      <span className="text-slate-500">
                        PDF, Excel, CSV, Word albo paczka ZIP („Pobierz wszystkie załączniki” na stronie postępowania) —
                        do 50 MB na plik
                      </span>
                      <span className="mt-1 inline-flex items-center rounded bg-blue-600 px-3 py-1.5 font-medium text-white">
                        {unpacking ? 'Rozpakowuję paczkę…' : 'Wybierz pliki z komputera'}
                      </span>
                      <input
                        type="file"
                        multiple
                        className="sr-only"
                        accept={DOCUMENT_ACCEPT}
                        disabled={busy || unpacking}
                        onChange={(e) => {
                          const picked = e.target.files
                          void addFiles(picked).finally(() => {
                            e.target.value = ''
                          })
                        }}
                      />
                    </label>
                    {rejected.length > 0 && (
                      <ul className="mt-1 rounded bg-red-50 px-3 py-2 text-red-700" role="alert">
                        {rejected.map((r, i) => (
                          <li key={`${r.name}-${i}`}>
                            Pominięto {r.name}: {r.reason}.
                          </li>
                        ))}
                      </ul>
                    )}
                    {files.length > 0 && (
                      <ul className="mt-2 space-y-1">
                        {files.map((f) => (
                          <li
                            key={`${f.name}-${f.size}`}
                            className="flex items-center justify-between gap-2 rounded border border-slate-200 px-2 py-1"
                          >
                            <label className="flex min-w-0 cursor-pointer items-start gap-2 break-all">
                              <input
                                type="checkbox"
                                className="mt-0.5"
                                checked={!excludedFiles.has(f)}
                                disabled={busy}
                                onChange={(e) =>
                                  setExcludedFiles((prev) => {
                                    const next = new Set(prev)
                                    if (e.target.checked) next.delete(f)
                                    else next.add(f)
                                    return next
                                  })
                                }
                              />
                              <span>
                                {f.name} <span className="text-slate-500">· {megabytes(f.size)}</span>
                                {fileLotBhp(f) === false && (
                                  <span className="block text-[11px] text-slate-600">
                                    Pakiet {lotNumberOf(f.name)} — bez towarów BHP, dlatego nie zaznaczony.
                                  </span>
                                )}
                              </span>
                            </label>
                            <button
                              type="button"
                              disabled={busy}
                              onClick={() => setFiles((prev) => prev.filter((p) => p !== f))}
                              className="shrink-0 rounded border border-slate-300 px-2 py-0.5 text-slate-700 hover:bg-slate-50"
                            >
                              Usuń
                            </button>
                          </li>
                        ))}
                      </ul>
                    )}
                    <p className="mt-2 text-slate-500">
                      Dlaczego trzeba pobrać ręcznie: aplikacja pobiera dokumenty sama tylko z e-Zamówień, które
                      udostępniają ich listę publicznie. Inne platformy (na przykład platformazakupowa.pl) w regulaminie
                      zabraniają pobierania załączników przez automat — wystarczy jedno kliknięcie „Pobierz wszystkie
                      załączniki” na stronie postępowania i przeciągnięcie paczki ZIP tutaj.
                    </p>
                  </div>
                ) : (
                  !row.tender &&
                  !row.skipped && (
                    <p className="mt-1 text-slate-500">
                      {mayCreate
                        ? 'Dodawanie dokumentów do przetargu wymaga uprawnienia „Dodawanie dokumentów” — przetarg założysz bez nich, a dokumenty doda osoba z tym uprawnieniem.'
                        : 'Przetargi z dokumentami zakłada osoba z uprawnieniem do zakładania przetargów i dodawania dokumentów.'}
                    </p>
                  )
                )}
              </section>
            </>
          )}
        </div>

        <div className="flex flex-wrap items-center justify-end gap-2 border-t border-slate-200 px-4 py-3 text-xs">
          {mayCreate && (
            <span className="mr-auto text-slate-500">
              {withDocuments
                ? `${chosenDocs.length + chosenFiles.length} ${plural(chosenDocs.length + chosenFiles.length, 'dokument', 'dokumenty', 'dokumentów')} do odczytu. Pozycje i warunki zobaczysz w kreatorze jako podgląd — do przetargu trafią po Twoim zatwierdzeniu.`
                : mayAddDocs
                  ? 'Bez dokumentów kreator odczyta towary z treści ogłoszenia — towary BHP z ilością doda do przetargu sam, resztę pokaże w podglądzie.'
                  : 'Bez dokumentów kreator otworzy krok „Dokumenty”.'}
            </span>
          )}
          {!row.tender &&
            (row.skipped ? (
              <button
                type="button"
                disabled={busy}
                onClick={() => onSkip(row, false)}
                className="rounded border border-slate-300 bg-white px-3 py-1.5 text-slate-700 hover:bg-slate-50 disabled:opacity-50"
              >
                Przywróć
              </button>
            ) : (
              <button
                type="button"
                disabled={busy}
                onClick={() => {
                  if (dirty && !window.confirm('Wybrane pliki nie zostały przesłane. Pominąć ogłoszenie i porzucić je?')) return
                  onSkip(row, true)
                }}
                className="rounded border border-slate-300 bg-white px-3 py-1.5 text-slate-700 hover:bg-slate-50 disabled:opacity-50"
              >
                Pomiń
              </button>
            ))}
          {mayCreate && (
            <button
              type="button"
              disabled={busy || (loading && !details)}
              onClick={() =>
                onCreate(row, { documents: chosenDocs.map((d) => ({ id: d.id, name: d.name })), files: chosenFiles })
              }
              className="rounded bg-blue-600 px-3 py-1.5 font-medium text-white hover:bg-blue-700 disabled:opacity-50"
            >
              {withDocuments ? 'Załóż przetarg z pozycjami' : 'Załóż przetarg'}
            </button>
          )}
          {row.tender?.can_open && (
            <Link
              to={`/tenders/${row.tender.id}`}
              className="rounded bg-blue-600 px-3 py-1.5 font-medium text-white hover:bg-blue-700"
            >
              Przejdź do przetargu {row.tender.number}
            </Link>
          )}
        </div>
      </div>
    </div>
  )
}
