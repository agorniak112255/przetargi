import { plural } from './plural'

/** Odpowiedź DELETE /products/{id} i POST /products/delete. */
export type DeleteProductsResult = {
  message: string
  deleted: number
  product_ids_deleted: number[]
  /** Usuwanie z listy konta dostawcy: karty, od których odpięto tylko to konto (karta zostaje). */
  detached?: number
  product_ids_detached?: number[]
  /** Karty, których nie odpięto (konto jedynym producentem, zmiana ceny karty w przetargu) — powód w message. */
  refused?: { id: number; sku: string; reason: string }[]
  /** Ile pozycji ze źródeł (cennik z pliku, B2B) będzie pomijanych przy kolejnych importach. */
  positions_excluded: number
}

/** Dopisek o pominiętych pozycjach; null, gdy opcji „Pomijaj przy kolejnych importach” nie zaznaczono. */
export function skipImportNote(result: DeleteProductsResult, skipImport: boolean): string | null {
  if (!skipImport) return null
  const n = result.positions_excluded
  if (n > 0) {
    return `Kolejne importy pominą ${n} ${plural(n, 'pozycję', 'pozycje', 'pozycji')} ze źródeł (cennik z pliku, B2B).`
  }
  if (result.deleted === 0) {
    // usuwanie z listy dostawcy: same odpięcia (albo odmowy — powód w message)
    return (result.detached ?? 0) > 0 ? 'Odpięte pozycje nie miały kodów, po których można je pomijać.' : null
  }
  return result.deleted === 1
    ? 'Ta karta nie miała pozycji z cennika z pliku ani z B2B, więc nie ma czego pomijać.'
    : 'Usunięte karty nie miały pozycji z cennika z pliku ani z B2B, więc nie ma czego pomijać.'
}

/** Pełny komunikat po usunięciu: tekst serwera + informacja o pomijaniu. */
export function deleteResultText(result: DeleteProductsResult, skipImport: boolean): string {
  const note = skipImportNote(result, skipImport)
  return note ? `${result.message} ${note}` : result.message
}
