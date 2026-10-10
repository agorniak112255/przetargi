import { useEffect, useState, type ReactNode } from 'react'
import { ApiError } from '../lib/api'
import { plural } from '../lib/plural'
import { formatDateTime } from '../lib/priceChange'
import {
  importPriceListFile,
  labelOf,
  MATCH_KIND_LABEL,
  PREVIEW_ACTION_LABEL,
  previewPriceListFile,
  SOURCE_KIND_LABEL,
  type FileView,
  type IntakeImportResult,
  type IntakeView,
  type PreviewSources,
  type PreviewView,
} from '../lib/priceListIntake'
import { CandidatesList } from './PriceListSourcePinsModal'

/** Skąd karty wezmą opis po imporcie (podział źródeł z podglądu mapy). */
const PREVIEW_SOURCES: { key: keyof PreviewSources; label: string; hint: string; color: string }[] = [
  {
    key: 'manufacturer',
    label: 'strona producenta',
    hint: 'Importer przypiął kartę do strony wyrobu u producenta.',
    color: 'bg-violet-500',
  },
  {
    key: 'supplier',
    label: 'strona dostawcy',
    hint: 'Strona dostawcy z listy cennika — producent nie ma wyrobu albo ma za krótki opis.',
    color: 'bg-emerald-500',
  },
  {
    key: 'shop',
    label: 'strona sklepu',
    hint: 'Producent nie ma wyrobu — strona sklepu ze zdjęciem i opisem.',
    color: 'bg-sky-400',
  },
  {
    key: 'human_url',
    label: 'adres wskazany ręcznie',
    hint: 'Karta ma adres wskazany przez człowieka — wygrywa z mapą importera.',
    color: 'bg-indigo-400',
  },
  {
    key: 'b2b_description',
    label: 'opis z B2B',
    hint: 'Karta ma opis z konta B2B — cennik z pliku go nie rusza.',
    color: 'bg-blue-300',
  },
  {
    key: 'unresolved',
    label: 'bez strony',
    hint: 'Importer nie znalazł strony — karta nie dostanie opisu z internetu i trafi do „Do przeglądu”.',
    color: 'bg-amber-400',
  },
  {
    key: 'not_checked',
    label: 'niesprawdzone w podglądzie',
    hint: 'Podgląd sprawdza tylko część wierszy (limit) albo tylko strony już zapisane w indeksie.',
    color: 'bg-slate-300',
  },
]

function n(value: number | null | undefined): string {
  return (value ?? 0).toLocaleString('pl-PL')
}

function price(value: number | string | null): string {
  if (value === null || value === '') return '—'
  const v = typeof value === 'number' ? value : Number(String(value).replace(',', '.'))
  return Number.isFinite(v) ? `${v.toLocaleString('pl-PL', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} zł` : String(value)
}

function percentChange(oldValue: number | string | null, newValue: number | string | null): string {
  const o = Number(String(oldValue ?? '').replace(',', '.'))
  const nv = Number(String(newValue ?? '').replace(',', '.'))
  if (!Number.isFinite(o) || !Number.isFinite(nv) || o === 0) return ''
  const p = ((nv - o) / o) * 100

  return `${p > 0 ? '+' : ''}${p.toLocaleString('pl-PL', { maximumFractionDigits: 1 })}%`
}

function Section({
  title,
  count,
  shown,
  children,
}: {
  title: string
  count?: number
  /** Ile pozycji jest na liście (serwer obcina listy) — mniej niż count = dopisek „pokazano pierwsze N”. */
  shown?: number
  children: ReactNode
}) {
  return (
    <details className="rounded border border-slate-200 bg-white" open={count === undefined || count > 0}>
      <summary className="cursor-pointer select-none px-2.5 py-1.5 font-medium text-slate-700">
        {title}
        {count !== undefined && <span className="ml-1 font-normal text-slate-500">({n(count)})</span>}
        {count !== undefined && shown !== undefined && shown < count && (
          <span className="ml-1 font-normal text-slate-500">— pokazano pierwsze {n(shown)}</span>
        )}
      </summary>
      <div className="max-h-64 overflow-auto border-t border-slate-100 px-2.5 py-1.5">{children}</div>
    </details>
  )
}

type Props = {
  intake: IntakeView
  file: FileView
  onClose: () => void
  onImported: (result: IntakeImportResult, describe: boolean) => void
  /** Import nie wyszedł (błąd, 409 „import już trwa”) — stan pliku mógł się zmienić, lista do odświeżenia. */
  onImportFailed: () => void
}

/**
 * „Podgląd importu”: POST preview (bez zapisów) → liczby wierszy, zmiany cen, podział źródeł opisu, karty bez strony,
 * próbki; „Importuj” z „Pobierz opisy po imporcie” → POST import.
 */
export function PriceListImportPreviewModal({ intake, file, onClose, onImported, onImportFailed }: Props) {
  const [preview, setPreview] = useState<PreviewView | null>(null)
  const [loading, setLoading] = useState(true)
  const [loadErr, setLoadErr] = useState('')
  const [describe, setDescribe] = useState(true)
  const [importing, setImporting] = useState(false)
  const [importErr, setImportErr] = useState('')
  const [attempt, setAttempt] = useState(0)

  useEffect(() => {
    let cancelled = false
    setLoading(true)
    setLoadErr('')
    previewPriceListFile(intake.id, file.id)
      .then((res) => {
        if (!cancelled) setPreview(res)
      })
      .catch((ex) => {
        if (cancelled) return
        if (ex instanceof ApiError && ex.status === 409) {
          setLoadErr(ex.message || 'Ten cennik nie ma jeszcze importera — przygotuje go programista.')
        } else if (ex instanceof ApiError && ex.status === 422) {
          setLoadErr(
            `${ex.message} Plik ma inny układ niż ten, pod który napisano importer — przekaż go programiście (uwagi w ustawieniach cennika).`,
          )
        } else {
          setLoadErr(ex instanceof Error ? ex.message : 'Nie udało się przygotować podglądu')
        }
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })

    return () => {
      cancelled = true
    }
  }, [intake.id, file.id, attempt])

  async function runImport() {
    setImporting(true)
    setImportErr('')
    try {
      const res = await importPriceListFile(intake.id, file.id, describe)
      onImported(res, describe)
    } catch (ex) {
      setImportErr(
        ex instanceof ApiError && ex.status === 409
          ? ex.message || 'Import tego cennika już trwa — poczekaj, aż się skończy.'
          : ex instanceof Error
            ? ex.message
            : 'Nie udało się zaimportować pliku',
      )
      setImporting(false)
      onImportFailed()
    }
  }

  const sourcesTotal = preview ? PREVIEW_SOURCES.reduce((sum, s) => sum + (preview.sources?.[s.key] ?? 0), 0) : 0
  const willWrite = preview ? (preview.rows?.create ?? 0) + (preview.rows?.update ?? 0) : 0
  // pełne liczby z serwera; listy w podglądzie są obcięte
  const priceChangesTotal = preview ? (preview.price_changes_total ?? preview.price_changes?.length ?? 0) : 0
  const skippedTotal = preview ? (preview.skipped_total ?? preview.skipped?.length ?? 0) : 0
  const unresolvedTotal = preview ? (preview.unresolved_total ?? preview.unresolved?.length ?? 0) : 0

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
      role="dialog"
      aria-modal="true"
      aria-labelledby="import-preview-title"
      onClick={() => {
        if (!importing) onClose()
      }}
    >
      <div
        className="flex max-h-[92vh] w-full max-w-5xl flex-col rounded-xl bg-white p-4 text-xs shadow-lg"
        onClick={(e) => e.stopPropagation()}
      >
        <p id="import-preview-title" className="text-sm font-semibold text-slate-800">
          Podgląd importu — {intake.manufacturer} / {intake.version}
        </p>
        <p className="mt-0.5 text-slate-500">
          Plik: <b className="text-slate-700">{file.original_name}</b>
          {file.created_at && <> · dodany {formatDateTime(file.created_at)}</>}
          {file.uploaded_by_name && <> · {file.uploaded_by_name}</>}
          {preview?.importer && (
            <>
              {' '}
              · importer <b className="text-slate-700">{intake.importer_label ?? preview.importer.key}</b> (wersja{' '}
              {preview.importer.version})
            </>
          )}
        </p>
        <p className="mt-1 text-slate-500">Podgląd niczego nie zapisuje — karty i ceny zmienią się dopiero po „Importuj”.</p>

        <div className="mt-3 min-h-0 flex-1 space-y-2 overflow-auto pr-1">
          {loading && <p className="text-slate-500">Czytam plik i sprawdzam strony kart…</p>}
          {loadErr && (
            <p className="rounded bg-red-50 px-3 py-2 text-red-700">
              {loadErr}{' '}
              <button type="button" className="underline" onClick={() => setAttempt((a) => a + 1)}>
                Spróbuj ponownie
              </button>
            </p>
          )}
          {preview && (
            <>
              <div className="flex flex-wrap gap-x-5 gap-y-1 rounded bg-slate-50 px-3 py-2 text-slate-700">
                <span>
                  Wierszy w pliku: <b className="tabular-nums">{n(preview.rows_total)}</b>
                </span>
                <span>
                  nowe karty: <b className="tabular-nums text-emerald-700">{n(preview.rows?.create)}</b>
                </span>
                <span>
                  aktualizacja: <b className="tabular-nums text-blue-700">{n(preview.rows?.update)}</b>
                </span>
                <span>
                  pominięte: <b className="tabular-nums">{n(preview.rows?.skip)}</b>
                </span>
                <span title="Wiersze, których import nie wpuści — np. wykluczone z importu albo innego producenta.">
                  zablokowane: <b className="tabular-nums text-red-700">{n(preview.rows?.blocked)}</b>
                </span>
                <span>
                  zmiany cen: <b className="tabular-nums">{n(priceChangesTotal)}</b>
                </span>
              </div>

              {(preview.notes?.length ?? 0) > 0 && (
                <ul className="list-disc space-y-0.5 rounded bg-amber-50 py-2 pl-7 pr-3 text-amber-900">
                  {preview.notes.map((note, i) => (
                    <li key={i}>{note}</li>
                  ))}
                </ul>
              )}

              <div className="rounded border border-slate-200 px-2.5 py-2">
                <p className="mb-1 font-medium text-slate-700">Skąd karty wezmą opis</p>
                {sourcesTotal === 0 ? (
                  <p className="text-slate-400">—</p>
                ) : (
                  <>
                    <div className="flex h-2 overflow-hidden rounded bg-slate-100" aria-hidden="true">
                      {PREVIEW_SOURCES.map((s) =>
                        (preview.sources?.[s.key] ?? 0) > 0 ? (
                          <div
                            key={s.key}
                            className={s.color}
                            style={{ width: `${((preview.sources[s.key] ?? 0) / sourcesTotal) * 100}%` }}
                          />
                        ) : null,
                      )}
                    </div>
                    <p className="mt-1 flex flex-wrap gap-x-3 gap-y-0.5 text-[11px] text-slate-600">
                      {PREVIEW_SOURCES.map((s) =>
                        (preview.sources?.[s.key] ?? 0) > 0 ? (
                          <span key={s.key} className="inline-flex cursor-help items-center gap-1 whitespace-nowrap" title={s.hint}>
                            <span className={`inline-block h-2 w-2 rounded-sm ${s.color}`} />
                            {s.label} <b className="tabular-nums">{n(preview.sources[s.key])}</b>
                          </span>
                        ) : null,
                      )}
                    </p>
                  </>
                )}
              </div>

              <Section
                title="Karty bez strony — trafią do „Do przeglądu”"
                count={unresolvedTotal}
                shown={preview.unresolved?.length ?? 0}
              >
                <table className="w-full text-left">
                  <thead>
                    <tr className="border-b text-slate-600">
                      <th className="p-1 font-semibold">Kod</th>
                      <th className="p-1 font-semibold">Nazwa</th>
                      <th className="p-1 font-semibold">Powód</th>
                      <th className="p-1 font-semibold">Strony do sprawdzenia</th>
                    </tr>
                  </thead>
                  <tbody>
                    {(preview.unresolved ?? []).map((row, i) => (
                      <tr key={`${row.sku}-${i}`} className="border-b align-top">
                        <td className="p-1 font-mono">{row.sku}</td>
                        <td className="p-1">{row.name ?? '—'}</td>
                        <td className="p-1 text-amber-800">{row.reason ?? '—'}</td>
                        <td className="p-1">
                          <CandidatesList candidates={row.candidates} />
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </Section>

              <Section title="Zmiany cen" count={priceChangesTotal} shown={preview.price_changes?.length ?? 0}>
                <table className="w-full text-left">
                  <thead>
                    <tr className="border-b text-slate-600">
                      <th className="p-1 font-semibold">Kod</th>
                      <th className="p-1 font-semibold">Nazwa</th>
                      <th className="p-1 text-right font-semibold">Było</th>
                      <th className="p-1 text-right font-semibold">Będzie</th>
                      <th className="p-1 text-right font-semibold">Zmiana</th>
                    </tr>
                  </thead>
                  <tbody>
                    {(preview.price_changes ?? []).map((row, i) => (
                      <tr key={`${row.sku}-${i}`} className="border-b">
                        <td className="p-1 font-mono">{row.sku}</td>
                        <td className="p-1">{row.name ?? '—'}</td>
                        <td className="p-1 text-right tabular-nums">{price(row.old)}</td>
                        <td className="p-1 text-right tabular-nums">{price(row.new)}</td>
                        <td className="p-1 text-right tabular-nums">{percentChange(row.old, row.new)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </Section>

              <Section title="Pominięte wiersze" count={skippedTotal} shown={preview.skipped?.length ?? 0}>
                <table className="w-full text-left">
                  <thead>
                    <tr className="border-b text-slate-600">
                      <th className="p-1 font-semibold">Wiersz</th>
                      <th className="p-1 font-semibold">Kod</th>
                      <th className="p-1 font-semibold">Powód</th>
                    </tr>
                  </thead>
                  <tbody>
                    {(preview.skipped ?? []).map((row, i) => (
                      <tr key={i} className="border-b align-top">
                        <td className="p-1 tabular-nums">{row.ref ?? '—'}</td>
                        <td className="p-1 font-mono">{row.sku ?? '—'}</td>
                        <td className="p-1">{row.reason}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </Section>

              <Section title="Próbka wierszy i ich stron" count={preview.samples?.length ?? 0}>
                <table className="w-full text-left">
                  <thead>
                    <tr className="border-b text-slate-600">
                      <th className="p-1 font-semibold">Kod</th>
                      <th className="p-1 font-semibold">Nazwa</th>
                      <th className="p-1 font-semibold">Import</th>
                      <th className="p-1 font-semibold">Strona</th>
                      <th className="p-1 font-semibold">Dopasowanie po</th>
                    </tr>
                  </thead>
                  <tbody>
                    {(preview.samples ?? []).map((row, i) => (
                      <tr key={`${row.sku}-${i}`} className="border-b align-top">
                        <td className="p-1 font-mono">{row.sku}</td>
                        <td className="p-1">{row.name ?? '—'}</td>
                        <td className="p-1">{labelOf(PREVIEW_ACTION_LABEL, row.action)}</td>
                        <td className="p-1">
                          {row.url ? (
                            <a className="break-all text-blue-700 underline" href={row.url} target="_blank" rel="noopener noreferrer">
                              {row.url}
                            </a>
                          ) : (
                            <span className="text-amber-800">bez strony</span>
                          )}
                          {row.source_kind && <span className="block text-slate-500">{labelOf(SOURCE_KIND_LABEL, row.source_kind)}</span>}
                        </td>
                        <td className="p-1">{labelOf(MATCH_KIND_LABEL, row.match_kind)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </Section>

              {(preview.not_in_preview?.length ?? 0) > 0 && (
                <div className="rounded bg-slate-50 px-3 py-2 text-slate-600">
                  <p className="font-medium text-slate-700">Podgląd nie sprawdza:</p>
                  <ul className="list-disc pl-5">
                    {preview.not_in_preview.map((item, i) => (
                      <li key={i}>{typeof item === 'string' ? item : JSON.stringify(item)}</li>
                    ))}
                  </ul>
                </div>
              )}
            </>
          )}
        </div>

        {preview && file.status === 'imported' && (
          <p className="mt-2 rounded bg-amber-50 px-3 py-2 text-amber-900">
            Ten plik był już zaimportowany{file.imported_at ? ` ${formatDateTime(file.imported_at)}` : ''} — import
            powtórzy go (np. po poprawce importera).
          </p>
        )}
        {importErr && <p className="mt-2 rounded bg-red-50 px-3 py-2 text-red-700">{importErr}</p>}

        <div className="mt-3 flex flex-wrap items-center justify-end gap-3">
          {preview && (
            <label className="mr-auto flex items-start gap-2 text-slate-700">
              <input
                type="checkbox"
                className="mt-0.5"
                checked={describe}
                onChange={(e) => setDescribe(e.target.checked)}
                disabled={importing}
              />
              <span>
                Pobierz opisy po imporcie
                <span className="block text-slate-500">
                  Dla nowych kart, kart ze zmienioną stroną i kart bez opisu — każda karta czyta tylko swoją stronę.
                </span>
              </span>
            </label>
          )}
          <button type="button" className="rounded border border-slate-300 px-3 py-1.5" onClick={onClose} disabled={importing}>
            {preview ? 'Anuluj' : 'Zamknij'}
          </button>
          {preview && (
            <button
              type="button"
              className="rounded bg-blue-600 px-3 py-1.5 text-white hover:bg-blue-700 disabled:opacity-50"
              disabled={importing}
              onClick={() => void runImport()}
            >
              {importing ? 'Importuję…' : `Importuj (${n(willWrite)} ${plural(willWrite, 'wiersz', 'wiersze', 'wierszy')})`}
            </button>
          )}
        </div>
      </div>
    </div>
  )
}
