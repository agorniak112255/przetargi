import { useState } from 'react'

/** Sortowanie tabel po kliknięciu w nagłówek (nagłówek: SortTh w components/CampaignsUi). */

export type SortDir = 'asc' | 'desc'
/** null = kolejność z serwera (np. pozycje kampanii jak w mailu). */
export type TableSort<K extends string> = { key: K; dir: SortDir } | null

/** Klik w nagłówek: ta sama kolumna odwraca kierunek, nowa startuje od domyślnego (liczby i daty malejąco). */
export function useTableSort<K extends string>(descFirst: readonly K[], initial: TableSort<K> = null) {
  const [sort, setSort] = useState<TableSort<K>>(initial)
  function toggle(key: K) {
    setSort((prev) =>
      prev?.key === key ? { key, dir: prev.dir === 'asc' ? 'desc' : 'asc' } : { key, dir: descFirst.includes(key) ? 'desc' : 'asc' },
    )
  }
  return [sort, toggle] as const
}

/** Sortuje kopię wierszy; puste wartości (null, '') zawsze na końcu, teksty po polsku, przy remisie kolejność bez zmian. */
export function sortRows<T, K extends string>(
  rows: readonly T[],
  sort: TableSort<K>,
  value: (row: T, key: K) => string | number | null | undefined,
): T[] {
  if (!sort) return [...rows]
  const mul = sort.dir === 'asc' ? 1 : -1
  return rows
    .map((row, idx) => ({ row, idx, v: value(row, sort.key) }))
    .sort((a, b) => {
      const ae = a.v == null || a.v === '' || Number.isNaN(a.v)
      const be = b.v == null || b.v === '' || Number.isNaN(b.v)
      if (ae || be) return ae === be ? a.idx - b.idx : ae ? 1 : -1
      const cmp =
        typeof a.v === 'number' && typeof b.v === 'number'
          ? a.v - b.v
          : String(a.v).localeCompare(String(b.v), 'pl', { numeric: true, sensitivity: 'base' })
      return cmp === 0 ? a.idx - b.idx : cmp * mul
    })
    .map((x) => x.row)
}

/** Data ISO jako liczba do sortRows (brak daty = pusta wartość). */
export function sortDate(iso: string | null | undefined): number | null {
  return iso ? Date.parse(iso) : null
}
