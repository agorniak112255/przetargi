import { unzipSync, type UnzipFileInfo } from 'fflate'

/**
 * Dokumenty przetargu z komputera, także w paczce ZIP (na platformie postępowania: „Pobierz wszystkie załączniki”).
 * Paczka jest rozpakowywana w przeglądarce, a każdy dokument idzie dalej tą samą ścieżką co plik przeciągnięty
 * pojedynczo (POST /tenders/{id}/documents/analyze: te same rodzaje i limit 51200 kB). Platform nie odpytujemy —
 * paczkę pobiera człowiek (regulamin platformazakupowa.pl zabrania automatycznego pobierania załączników).
 */
export const DOCUMENT_EXTENSIONS = ['pdf', 'xlsx', 'xls', 'csv', 'doc', 'docx'] as const
export const DOCUMENT_MAX_BYTES = 50 * 1024 * 1024
/** pola wyboru pliku: dokumenty i paczki ZIP */
export const DOCUMENT_ACCEPT = [...DOCUMENT_EXTENSIONS, 'zip'].map((x) => `.${x}`).join(',')

/** Ochrona przed „bombą ZIP” i przypadkowo wybraną paczką z całym dyskiem. */
const ZIP_MAX_BYTES = 200 * 1024 * 1024
const ZIP_MAX_TOTAL_UNPACKED = 300 * 1024 * 1024
const ZIP_MAX_DOCUMENTS = 60
/** paczka w paczce (np. „Załączniki do SWZ.zip” w „Wszystkie załączniki.zip”) — jeden poziom głębiej */
const ZIP_MAX_DEPTH = 2

export type SkippedDocument = { name: string; reason: string }

export function extensionOf(fileName: string): string {
  const m = /\.([a-z0-9]+)$/i.exec(fileName.trim())
  return m ? m[1].toLowerCase() : ''
}

export function isReadableDocument(fileName: string): boolean {
  return (DOCUMENT_EXTENSIONS as readonly string[]).includes(extensionOf(fileName))
}

function megabytes(bytes: number): string {
  return `${(bytes / 1024 / 1024).toLocaleString('pl-PL', { maximumFractionDigits: 1 })} MB`
}

/**
 * Kolejność odczytu: najpierw dokumenty z listą pozycji. Ta sama reguła nazw co EzamowieniaDocuments::kindOf
 * (formularz cenowy/ofertowy, opis przedmiotu zamówienia, SWZ, reszta); wyjaśnienia i zmiany na końcu.
 */
export function documentReadOrder(fileName: string): number {
  const text = fileName.toLowerCase().replace(/[_]+/g, ' ')
  if (/^\s*(?:wyjaśnieni|zmian|modyfikacj|odpowied|pytani|informacja z otwarcia|zawiadomieni|unieważnieni|ogłoszenie o zmianie|sprostowani)/u.test(text)) return 4
  if (/formularz\w*\s+(?:cenow|ofert|asortyment)|(?<![\p{L}\d])kosztorys/u.test(text)) return 0
  if (/opis\w*\s+przedmiotu\s+zam|(?<![\p{L}\d])opz(?![\p{L}\d])|specyfikacj\w*\s+techniczn|szczegółow\w*\s+opis/u.test(text)) return 1
  if (/(?<![\p{L}\d])(?:swz|siwz)(?![\p{L}\d])|specyfikacj\w*\s+warunków\s+zamówienia/u.test(text)) return 2
  return 3
}

/**
 * Numer pakietu/części z nazwy pliku — ta sama reguła co NoticeBhpLots::lotNumberOf: „Pakiet nr 3”, „Część II”,
 * „cz. 4”, „Zadanie nr 5”; sam „Załącznik nr 2” to nie numer części.
 */
export function lotNumberOf(fileName: string): number | null {
  const text = fileName
    .split(/(\s+)/)
    .map((w) => (/^[IVX]{1,5}[.,:;)]*$/.test(w) ? w : w.toLowerCase()))
    .join('')
  const m = /(?<!\p{L})(?:pakiet\p{L}*|częś[ćc]\p{L}*|czesc\p{L}*|cz\.|zadani\p{L}*)[\s_]*(?:nr\.?|numer)?[\s_]*(\d{1,3}|[IVX]{1,5})(?![\p{L}\d])/u.exec(text)
  if (!m) return null
  if (/^\d+$/.test(m[1])) return Number(m[1])
  const values: Record<string, number> = { I: 1, V: 5, X: 10 }
  let total = 0
  for (let i = 0; i < m[1].length; i++) {
    const v = values[m[1][i]] ?? 0
    const next = values[m[1][i + 1]] ?? 0
    total += v < next ? -v : v
  }
  return total
}

export function sortDocumentsForReading(files: File[]): File[] {
  return files
    .map((f, i) => ({ f, i, order: documentReadOrder(f.name) }))
    .sort((a, b) => a.order - b.order || a.i - b.i)
    .map((x) => x.f)
}

/** Polskie litery w kodowaniu CP852 (paczki z „Wyślij do › Folder skompresowany” w polskim Windows bez flagi UTF-8). */
const CP852_POLISH: Record<number, string> = {
  0xa5: 'ą', 0x86: 'ć', 0xa9: 'ę', 0x88: 'ł', 0xe4: 'ń', 0xa2: 'ó', 0x98: 'ś', 0xab: 'ź', 0xbe: 'ż',
  0xa4: 'Ą', 0x8f: 'Ć', 0xa8: 'Ę', 0x9d: 'Ł', 0xe3: 'Ń', 0xe0: 'Ó', 0x97: 'Ś', 0x8d: 'Ź', 0xbd: 'Ż',
}

/**
 * Nazwa wpisu: fflate czyta nazwy bez flagi UTF-8 jako Latin-1. Gdy bajty są poprawnym UTF-8 (program pakujący nie
 * ustawił flagi) — UTF-8; inaczej polskie litery z CP852. Nazwy z flagą UTF-8 nie mają znaków > 0xFF i zostają.
 */
export function decodeEntryName(name: string): string {
  if (!/[\u0080-ÿ]/.test(name) || /[Ā-￿]/.test(name)) return name
  const bytes = Uint8Array.from(name, (c) => c.charCodeAt(0))
  try {
    return new TextDecoder('utf-8', { fatal: true }).decode(bytes)
  } catch {
    return Array.from(bytes, (b) => (b < 0x80 ? String.fromCharCode(b) : (CP852_POLISH[b] ?? String.fromCharCode(b)))).join('')
  }
}

function baseName(path: string): string {
  const parts = path.split(/[\\/]/).filter(Boolean)
  return parts[parts.length - 1] ?? path
}

function parentName(path: string): string {
  const parts = path.split(/[\\/]/).filter(Boolean)
  return parts.length > 1 ? parts[parts.length - 2] : ''
}

function mimeOf(ext: string): string {
  return (
    {
      pdf: 'application/pdf',
      csv: 'text/csv',
      xls: 'application/vnd.ms-excel',
      xlsx: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
      doc: 'application/msword',
      docx: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    }[ext] ?? 'application/octet-stream'
  )
}

type Budget = { unpacked: number; documents: number }

function unpackZip(data: Uint8Array, zipName: string, depth: number, budget: Budget, out: File[], skipped: SkippedDocument[]) {
  const ignored = (name: string) => name.endsWith('/') || /(^|\/)(__MACOSX|\.)/.test(name) || /(^|\/)Thumbs\.db$/i.test(name)
  let entries: Record<string, Uint8Array>
  try {
    entries = unzipSync(data, {
      filter: (info: UnzipFileInfo) => {
        if (ignored(info.name)) return false
        const name = decodeEntryName(info.name)
        const ext = extensionOf(name)
        const label = `${zipName} › ${baseName(name)}`
        if (ext === 'zip') {
          if (depth >= ZIP_MAX_DEPTH) {
            skipped.push({ name: label, reason: 'paczka w paczce w paczce — rozpakuj ją ręcznie' })
            return false
          }
          if (info.originalSize > ZIP_MAX_BYTES) {
            skipped.push({ name: label, reason: `paczka ma ${megabytes(info.originalSize)}, a limit to 200 MB` })
            return false
          }
        } else if (!isReadableDocument(name)) {
          skipped.push({ name: label, reason: 'tego rodzaju pliku kreator nie odczyta (tylko PDF, Excel, CSV albo Word)' })
          return false
        } else if (info.originalSize > DOCUMENT_MAX_BYTES) {
          skipped.push({ name: label, reason: `plik ma ${megabytes(info.originalSize)}, a limit to 50 MB` })
          return false
        }
        if (budget.unpacked + info.originalSize > ZIP_MAX_TOTAL_UNPACKED || budget.documents >= ZIP_MAX_DOCUMENTS) {
          skipped.push({ name: label, reason: 'paczka jest za duża — najwyżej 60 dokumentów i 300 MB po rozpakowaniu' })
          return false
        }
        budget.unpacked += info.originalSize
        if (ext !== 'zip') budget.documents += 1
        return true
      },
    })
  } catch {
    skipped.push({ name: zipName, reason: 'nie udało się rozpakować paczki (uszkodzona albo zaszyfrowana hasłem)' })
    return
  }

  const used = new Set(out.map((f) => f.name.toLowerCase()))
  for (const [rawName, bytes] of Object.entries(entries)) {
    const name = decodeEntryName(rawName)
    const ext = extensionOf(name)
    if (ext === 'zip') {
      unpackZip(bytes, baseName(name), depth + 1, budget, out, skipped)
      continue
    }
    // ta sama nazwa w dwóch folderach paczki — z nazwą folderu
    let fileName = baseName(name)
    if (used.has(fileName.toLowerCase()) && parentName(name) !== '') fileName = `${parentName(name)} - ${fileName}`
    used.add(fileName.toLowerCase())
    // kopia do własnego ArrayBuffer (typ BlobPart nie przyjmuje widoku na współdzielony bufor)
    out.push(new File([bytes.slice()], fileName, { type: mimeOf(ext) }))
  }
}

/**
 * Pliki wybrane przez człowieka → dokumenty do odczytu: paczki ZIP rozpakowane, pliki nieobsługiwane i za duże pominięte
 * (z powodem), kolejność odczytu według nazwy (formularz cenowy, opis przedmiotu zamówienia, SWZ, reszta).
 */
export async function expandDocumentFiles(list: File[]): Promise<{ files: File[]; skipped: SkippedDocument[] }> {
  const files: File[] = []
  const skipped: SkippedDocument[] = []
  for (const f of list) {
    const ext = extensionOf(f.name)
    if (ext === 'zip') {
      if (f.size > ZIP_MAX_BYTES) {
        skipped.push({ name: f.name, reason: `paczka ma ${megabytes(f.size)}, a limit to 200 MB` })
        continue
      }
      const before = files.length
      unpackZip(new Uint8Array(await f.arrayBuffer()), f.name, 1, { unpacked: 0, documents: 0 }, files, skipped)
      if (files.length === before && !skipped.some((s) => s.name === f.name)) {
        skipped.push({ name: f.name, reason: 'w paczce nie ma dokumentów PDF, Excel, CSV ani Word' })
      }
    } else if (!isReadableDocument(f.name)) {
      skipped.push({ name: f.name, reason: 'tego rodzaju pliku kreator nie odczyta (tylko PDF, Excel, CSV, Word albo paczka ZIP)' })
    } else if (f.size > DOCUMENT_MAX_BYTES) {
      skipped.push({ name: f.name, reason: `plik ma ${megabytes(f.size)}, a limit to 50 MB` })
    } else {
      files.push(f)
    }
  }
  return { files: sortDocumentsForReading(files), skipped }
}
