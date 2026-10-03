import type { TenderCalendarFilter } from '../../lib/api'

/**
 * Kalendarz terminów składania ofert (widok miesiąca, ekran 4 makiety) — GET /tenders/calendar (fetchTenderCalendar
 * w lib/api); filter jak na liście przetargów.
 *
 * ZAŚLEPKA (krok 0) — widok miesiąca dopisuje strumień A.
 */
export function TenderCalendar({ filter }: { filter: TenderCalendarFilter }) {
  void filter
  return <p className="text-sm text-slate-500">Kalendarz terminów jeszcze nie jest gotowy.</p>
}
