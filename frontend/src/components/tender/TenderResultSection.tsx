/**
 * Pulpit przetargu › Po terminie › Wynik przetargu (makieta, ekran 5): wynik każdej części zamówienia,
 * zwycięzca, ceny, powód przegranej, oferty innych firm i dane z Biuletynu.
 *
 * ZAŚLEPKA kroku 0 — pełny ekran dopisuje strumień A (fetchTenderResult / saveTenderResult z lib/api).
 */
export type TenderResultSectionProps = {
  tenderId: number
  /** opiekun albo tenders.edit_offer z dostępem do przetargu */
  canEdit: boolean
  /** po zapisie wyniku — np. odświeżenie znacznika wyniku w nagłówku przetargu */
  onChanged?: () => void
}

export function TenderResultSection(props: TenderResultSectionProps) {
  return (
    <section className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm" data-tender-id={props.tenderId}>
      <h2 className="text-sm font-semibold">Wynik przetargu</h2>
      <p className="mt-1 text-xs text-slate-500">Ekran w przygotowaniu.</p>
    </section>
  )
}
