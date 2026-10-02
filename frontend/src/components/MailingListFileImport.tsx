import { useCallback, useEffect, useRef, useState } from 'react'
import { BTN, BTN_PRIMARY, ErrorBar, INPUT, Modal } from './CampaignsUi'
import { errorText, fmtInt } from '../lib/campaignFormat'
import {
  importMailingListFile,
  previewMailingListFile,
  type ContactBasis,
  type ContactFilePreview,
  type ContactImportField,
  type ImportResult,
} from '../lib/campaigns'
import { plural } from '../lib/plural'

/**
 * Import adresów z pliku (CSV, Excel): użytkownik przypisuje kolumny pliku do pól kontaktu. Serwer podpowiada
 * przypisanie z nagłówków; plik jest wysyłany drugi raz przy imporcie (nie zostaje na serwerze).
 */

const FIELD_OPTIONS: { value: ContactImportField; label: string }[] = [
  { value: 'email', label: 'Adres e-mail' },
  { value: 'first_name', label: 'Imię' },
  { value: 'last_name', label: 'Nazwisko' },
  { value: 'name', label: 'Imię i nazwisko (razem)' },
  { value: 'company', label: 'Firma' },
  { value: 'consent', label: 'Zgoda (1 / tak)' },
]

export function MailingListFileImport({
  listId,
  file,
  initialBasis,
  initialNote,
  onClose,
  onDone,
}: {
  listId: number
  file: File
  initialBasis: ContactBasis
  initialNote: string
  onClose: () => void
  onDone: (result: ImportResult) => void
}) {
  const [sheet, setSheet] = useState(0)
  const [preview, setPreview] = useState<ContactFilePreview | null>(null)
  const [loading, setLoading] = useState(false)
  const [err, setErr] = useState('')
  const [hasHeader, setHasHeader] = useState(true)
  const [columns, setColumns] = useState<(ContactImportField | null)[]>([])
  const [basis, setBasis] = useState<ContactBasis>(initialBasis)
  const [basisNote, setBasisNote] = useState(initialNote.trim() || `plik: ${file.name}`)
  const [importing, setImporting] = useState(false)
  const seq = useRef(0)

  const load = useCallback(
    async (index: number) => {
      const my = ++seq.current
      setLoading(true)
      setErr('')
      try {
        const res = await previewMailingListFile(listId, file, index)
        if (my !== seq.current) return
        setPreview(res)
        setHasHeader(res.has_header)
        setColumns(res.suggested)
      } catch (ex) {
        if (my === seq.current) {
          setPreview(null)
          setErr(errorText(ex, 'Nie udało się odczytać pliku.'))
        }
      } finally {
        if (my === seq.current) setLoading(false)
      }
    },
    [listId, file],
  )

  useEffect(() => {
    void load(sheet)
  }, [load, sheet])

  /** Pole może mieć tylko jedną kolumnę — wybór zdejmuje je z poprzedniej. */
  function assign(index: number, field: ContactImportField | null) {
    setColumns((prev) => prev.map((f, i) => (i === index ? field : field !== null && f === field ? null : f)))
  }

  const dataRows = preview ? Math.max(0, preview.total_rows - (hasHeader ? 1 : 0)) : 0
  const emailColumn = columns.indexOf('email')
  const tooMany = preview !== null && dataRows > preview.max_rows
  const consentMapped = columns.includes('consent')

  async function runImport() {
    if (!preview) return
    if (emailColumn < 0) {
      setErr('Wskaż kolumnę z adresem e-mail.')
      return
    }
    if (basis === 'consent' && !basisNote.trim()) {
      setErr('Przy podstawie „Zgoda” wpisz, skąd jest zgoda (np. newsletter sklepu internetowego).')
      return
    }
    const mapping: Partial<Record<ContactImportField, number>> = {}
    columns.forEach((f, i) => {
      if (f) mapping[f] = i
    })
    setImporting(true)
    setErr('')
    try {
      onDone(
        await importMailingListFile(listId, {
          file,
          sheet,
          has_header: hasHeader,
          mapping,
          basis,
          basis_note: basisNote.trim() || undefined,
        }),
      )
    } catch (ex) {
      setErr(errorText(ex, 'Nie udało się zaimportować pliku.'))
    } finally {
      setImporting(false)
    }
  }

  const header = preview && hasHeader ? preview.rows[0] : null
  const sample = preview ? preview.rows.slice(hasHeader ? 1 : 0) : []

  return (
    <Modal
      title={`Import z pliku: ${file.name}`}
      wide="full"
      busy={importing}
      onClose={onClose}
      footer={
        <>
          <button type="button" className={BTN} onClick={onClose} disabled={importing}>
            Anuluj
          </button>
          <button
            type="button"
            className={BTN_PRIMARY}
            onClick={() => void runImport()}
            disabled={importing || loading || !preview || emailColumn < 0 || tooMany}
          >
            {importing ? 'Importuję…' : `Importuj ${fmtInt(dataRows)} ${plural(dataRows, 'wiersz', 'wiersze', 'wierszy')}`}
          </button>
        </>
      }
    >
      <div className="space-y-3 text-xs">
        <ErrorBar message={err} onClose={() => setErr('')} />

        {preview && (
          <div className="flex flex-wrap items-center gap-x-4 gap-y-2 text-slate-700">
            {preview.sheets.length > 1 && (
              <label className="inline-flex items-center gap-1.5">
                Arkusz
                <select className={INPUT} value={sheet} onChange={(e) => setSheet(Number(e.target.value))} disabled={loading}>
                  {preview.sheets.map((name, i) => (
                    <option key={i} value={i}>
                      {name || `Arkusz ${i + 1}`}
                    </option>
                  ))}
                </select>
              </label>
            )}
            <label className="inline-flex items-center gap-1.5">
              <input type="checkbox" checked={hasHeader} onChange={(e) => setHasHeader(e.target.checked)} />
              pierwszy wiersz to nagłówki kolumn
            </label>
            <span className="text-slate-500">
              Wierszy z danymi: {fmtInt(dataRows)}
              {loading ? ' · wczytywanie…' : ''}
            </span>
          </div>
        )}
        {tooMany && preview && (
          <p className="rounded bg-amber-50 px-2 py-1.5 text-amber-900">
            Najwyżej {fmtInt(preview.max_rows)} wierszy w jednym imporcie — podziel plik.
          </p>
        )}

        {preview && (
          <>
            <p className="text-slate-600">
              Nad każdą kolumną wybierz, co zawiera. Wymagany jest tylko <b>adres e-mail</b>; kolumny „Pomiń” nie są
              zapisywane. Komórka z kilkoma adresami daje kilka kontaktów.
            </p>
            <div className="overflow-x-auto rounded border border-slate-200">
              <table className="w-full text-left text-xs">
                <thead>
                  <tr className="border-b bg-slate-50">
                    {columns.map((field, i) => (
                      <th key={i} className={`min-w-[150px] p-2 align-top ${field ? 'bg-blue-50' : ''}`}>
                        <select
                          aria-label={`Kolumna ${i + 1}`}
                          className={`${INPUT} w-full ${field ? 'border-blue-400 font-medium text-blue-900' : 'text-slate-500'}`}
                          value={field ?? ''}
                          onChange={(e) => assign(i, (e.target.value || null) as ContactImportField | null)}
                        >
                          <option value="">Pomiń</option>
                          {FIELD_OPTIONS.map((o) => (
                            <option key={o.value} value={o.value}>
                              {o.label}
                            </option>
                          ))}
                        </select>
                        {header && <div className="mt-1 truncate font-semibold text-slate-800" title={header[i]}>{header[i] || '—'}</div>}
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {sample.map((row, r) => (
                    <tr key={r} className="border-b">
                      {row.map((cell, i) => (
                        <td key={i} className={`max-w-[260px] truncate p-2 ${columns[i] ? 'text-slate-900' : 'text-slate-400'}`} title={cell}>
                          {cell}
                        </td>
                      ))}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <p className="text-[11px] text-slate-500">
              Podgląd pierwszych wierszy. Gdy jest kolumna „Imię i nazwisko (razem)”, a w wierszu jest pusta — bierzemy
              Imię + Nazwisko. Istniejącym adresom uzupełniamy tylko puste dane.
            </p>
          </>
        )}

        {preview && (
          <fieldset className="rounded border border-slate-200 p-3">
            <legend className="px-1 text-slate-600">Podstawa wysyłki dla całego importu</legend>
            <div className="flex flex-wrap gap-x-6 gap-y-1">
              <label className="inline-flex items-center gap-2">
                <input type="radio" name="file-basis" checked={basis === 'customer'} onChange={() => setBasis('customer')} />
                <span>
                  <b className="text-slate-800">Stały klient</b> (kupuje u nas)
                </span>
              </label>
              <label className="inline-flex items-center gap-2">
                <input type="radio" name="file-basis" checked={basis === 'consent'} onChange={() => setBasis('consent')} />
                <span>
                  <b className="text-slate-800">Zgoda</b> na informacje handlowe
                </span>
              </label>
            </div>
            <label className="mt-2 block max-w-xl text-slate-600">
              {basis === 'consent' ? 'Skąd zgoda (wymagane)' : 'Uwaga (opcjonalnie)'}
              <input
                className={`${INPUT} mt-1 block w-full`}
                maxLength={255}
                value={basisNote}
                onChange={(e) => setBasisNote(e.target.value)}
              />
            </label>
            <p className="mt-1 text-[11px] text-slate-500">
              {consentMapped
                ? 'Kolumna zgody: wchodzą tylko wiersze z wartością 1 / tak — pozostałe (także puste) są pomijane.'
                : 'Bez kolumny zgody ta podstawa dotyczy wszystkich wierszy pliku.'}
            </p>
          </fieldset>
        )}

        {!preview && loading && <p className="text-slate-500">Wczytywanie pliku…</p>}
      </div>
    </Modal>
  )
}
