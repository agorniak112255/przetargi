/**
 * Termin składania ofert przetargu dla ludzi: „5.10.2026, 10:00”, bez godziny „5.10.2026”.
 *
 * Data terminu to dzień „na zegarze” w Polsce (API podaje „2026-10-05” albo „2026-10-05T00:00:00.000000Z”
 * z rzutowania daty) — bierzemy sam dzień kalendarza, bez przeliczania stref, żeby przeglądarka w innej strefie
 * nie przesunęła dnia. Godzina przychodzi jako „HH:MM” (czas polski); „HH:MM:SS” jest skracane.
 * Bez daty (albo z nieczytelną datą) zwraca pusty tekst — miejsce wywołania decyduje, co pokazać zamiast.
 */
export function formatDeadline(date: string | null | undefined, time?: string | null): string {
  const day = formatDeadlineDate(date)
  if (day === '') return ''
  const hm = deadlineTimeLabel(time)
  return hm ? `${day}, ${hm}` : day
}

/** Sam dzień terminu: „5.10.2026”; pusty tekst bez daty. */
export function formatDeadlineDate(date: string | null | undefined): string {
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(date ?? '')
  if (!m) return ''
  return `${Number(m[3])}.${Number(m[2])}.${m[1]}`
}

/** Godzina terminu „HH:MM” z „H:MM”, „HH:MM” albo „HH:MM:SS”; null, gdy jej nie ma. */
export function deadlineTimeLabel(time: string | null | undefined): string | null {
  const m = /^(\d{1,2}):(\d{2})/.exec(time ?? '')
  if (!m) return null
  return `${m[1].padStart(2, '0')}:${m[2]}`
}
