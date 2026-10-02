/**
 * Kreator zakładania przetargu (4 kroki na stronie przetargu).
 *
 * Włącza go „Nowy przetarg” na liście albo „Otwórz kreator” na Podsumowaniu; wyłącza ostatni krok
 * („Rozpocznij wycenę” albo „Zakończ kreator”) i „Zamknij kreator”. Nie wynika ze statusu, bo backend sam
 * przestawia Szkic na Wycenę po imporcie SIWZ i dopasowaniu AI — kreator zamykałby się w połowie.
 * Stan jest w przeglądarce osoby, która zakłada przetarg.
 */
function key(tenderId: number | string): string {
  return `tender-wizard:${tenderId}`
}

export function isTenderWizardActive(tenderId: number | string): boolean {
  try {
    return localStorage.getItem(key(tenderId)) === '1'
  } catch {
    return false
  }
}

export function setTenderWizardActive(tenderId: number | string, active: boolean): void {
  try {
    if (active) localStorage.setItem(key(tenderId), '1')
    else localStorage.removeItem(key(tenderId))
  } catch {
    // brak dostępu do localStorage — kreator działa do odświeżenia strony
  }
}
