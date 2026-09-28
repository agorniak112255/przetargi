import { plural } from './plural'

/** Odpowiedź DELETE /products/{id} i POST /products/delete. */
export type DeleteProductsResult = {
  message: string
  deleted: number
  product_ids_deleted: number[]
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
  return result.deleted === 1
    ? 'Ta karta nie miała pozycji z cennika z pliku ani z B2B, więc nie ma czego pomijać.'
    : 'Usunięte karty nie miały pozycji z cennika z pliku ani z B2B, więc nie ma czego pomijać.'
}

/** Pełny komunikat po usunięciu: tekst serwera + informacja o pomijaniu. */
export function deleteResultText(result: DeleteProductsResult, skipImport: boolean): string {
  const note = skipImportNote(result, skipImport)
  return note ? `${result.message} ${note}` : result.message
}
