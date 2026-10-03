import { useEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import {
  fetchNoticeDetails,
  type NoticeDetails,
  type NoticeDocument,
  type NoticeDocumentKind,
  type NoticeRow,
} from '../../lib/api'
import { errorText, fmtDate } from '../../lib/campaignFormat'
import { plural } from '../../lib/plural'

/** Te same rodzaje i limit co krok „Dokumenty” kreatora (POST /tenders/{id}/documents/analyze: max 51200 kB). */
const NOTICE_FILE_EXTENSIONS = ['pdf', 'xlsx', 'xls', 'csv', 'doc', 'docx'] as const
const NOTICE_FILE_MAX_BYTES = 50 * 1024 * 1024

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

function extensionOf(fileName: string): string {
  const m = /\.([a-z0-9]+)$/i.exec(fileName.trim())
  return m ? m[1].toLowerCase() : ''
}

function readable(fileName: string): boolean {
  return (NOTICE_FILE_EXTENSIONS as readonly string[]).includes(extensionOf(fileName))
}

/** Ocena serwera (importable, suggested), a gdy jej brak — ta sama reguła po stronie przeglądarki. */
function importable(d: NoticeDocument): boolean {
  return d.importable ?? readable(d.file_name)
}

function suggested(d: NoticeDocument): boolean {
  return d.suggested ?? (importable(d) && DEFAULT_KINDS.includes(d.kind))
}

function megabytes(bytes: number): string {
  return `${(bytes / 1024 / 1024).toLocaleString('pl-PL', { maximumFractionDigits: 1 })} MB`
}

function externalLink(href: string, label: string) {
  return (
    <a href={href} target="_blank" rel="noopener noreferrer" className="whitespace-nowrap text-blue-700 hover:underline">
      {label} ↗
    </a>
  )
}

export type NoticeDocumentSelection = {
  /** zaznaczone dokumenty z listy e-Zamówień (id i nazwa do potwierdzenia) */
  documents: { id: string; name: string }[]
  /** pliki z komputera — kreator odczyta je po założeniu przetargu */
  files: File[]
}

/**
 * Szczegóły ogłoszenia (panel z prawej): nagłówek, części z opisami, sekcje ogłoszenia słowo w słowo, dokumenty
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
  const [rejected, setRejected] = useState<{ name: string; reason: string }[]>([])
  const [dragOver, setDragOver] = useState(false)
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

  function addFiles(list: FileList | null) {
    if (!list || list.length === 0) return
    const ok: File[] = []
    const bad: { name: string; reason: string }[] = []
    for (const f of Array.from(list)) {
      if (!readable(f.name)) bad.push({ name: f.name, reason: 'tego rodzaju pliku kreator nie odczyta (tylko PDF, Excel, CSV albo Word)' })
      else if (f.size > NOTICE_FILE_MAX_BYTES) bad.push({ name: f.name, reason: `plik ma ${megabytes(f.size)}, a limit to 50 MB` })
      else ok.push(f)
    }
    setRejected(bad)
    setFiles((prev) => [...prev, ...ok.filter((f) => !prev.some((p) => p.name === f.name && p.size === f.size))])
  }

  const org = row.organization
  const place = [org.city, org.province_name].filter(Boolean).join(' · ')
  const docs = details?.documents
  const ezItems: NoticeDocument[] = docs?.available ? docs.items : []
  const mayCreate = canCreate && !row.tender && !row.skipped
  const mayAddDocs = mayCreate && canImport && details?.can_import_documents !== false
  const chosenDocs = mayAddDocs ? ezItems.filter((d) => selected.has(d.id) && importable(d)) : []
  const chosenFiles = mayAddDocs ? files : []
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
                        przedmiotu zamówienia albo formularz cenowy lub ofertowy — rodzaj to podpowiedź z nazwy, sprawdź
                        go.
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
                                {!ok && (
                                  <span className="block text-[11px] text-amber-800">
                                    Kreator nie odczyta pliku tego rodzaju — jeśli to archiwum, pobierz je ze strony
                                    postępowania, rozpakuj i dodaj pliki niżej.
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
                  docs && <p className="mb-1 text-slate-600">{docs.note}</p>
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
                        if (!busy) addFiles(e.dataTransfer.files)
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
                      <span className="text-slate-500">PDF, Excel, CSV albo Word, do 50 MB na plik</span>
                      <span className="mt-1 inline-flex items-center rounded bg-blue-600 px-3 py-1.5 font-medium text-white">
                        Wybierz pliki z komputera
                      </span>
                      <input
                        type="file"
                        multiple
                        className="sr-only"
                        accept={NOTICE_FILE_EXTENSIONS.map((x) => `.${x}`).join(',')}
                        disabled={busy}
                        onChange={(e) => {
                          addFiles(e.target.files)
                          e.target.value = ''
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
                            <span className="min-w-0 break-all">
                              {f.name} <span className="text-slate-500">· {megabytes(f.size)}</span>
                            </span>
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
                      udostępniają ich listę publicznie. Inne platformy (na przykład platformazakupowa.pl) nie pozwalają na
                      pobieranie plików przez automat.
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
                  ? 'Bez dokumentów kreator odczyta towary z treści ogłoszenia — zobaczysz je jako podgląd do zatwierdzenia.'
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
