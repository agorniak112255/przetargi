import type { InquiryOutcomeFields, InquiryOutcomeView } from '../../lib/api'

/** Zapytanie w zakresie potrzebnym panelowi wyniku (GET /inquiries/{id} — pola etapu 4). */
export type InquiryOutcomePanelInquiry = { id: number; replied_at: string | null } & InquiryOutcomeFields

/**
 * „Jak się skończyło zapytanie” (ekran 10 makiety) — po wysłaniu odpowiedzi: wynik, powód, podpowiedź z ERP XL
 * (szara, z regułą) i „Potwierdź ten dokument” (saveInquiryOutcome w lib/api).
 *
 * ZAŚLEPKA (krok 0) — nic nie pokazuje; panel dopisuje strumień C.
 */
export function InquiryOutcomePanel({ inquiry, onChanged }: { inquiry: InquiryOutcomePanelInquiry; onChanged: (outcome: InquiryOutcomeView) => void }) {
  void inquiry
  void onChanged
  return null
}
