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

/**
 * Dokumenty przekazane kreatorowi z okna szczegółów ogłoszenia (Ogłoszenia → „Załóż przetarg z pozycjami”), do odczytu
 * po jednym ścieżką dokumentów kreatora (podgląd przed dodaniem): noticeDocuments — dokumenty z e-Zamówień (kreator
 * pobiera je przez serwer: POST /tenders/{id}/documents/from-notice), files — pliki przeciągnięte przez człowieka.
 * Trzymane tylko w pamięci tej karty przeglądarki — po odświeżeniu strony pliki trzeba dodać ponownie (kreator to mówi).
 */
export type TenderWizardHandoff = {
  noticeNumber: string
  procedureUrl: string | null
  noticeDocuments: { id: string; name: string }[]
  files: File[]
  /** odczytać pozycje z treści ogłoszenia (POST /tenders/{id}/documents/from-notice-text), gdy nie ma dokumentów */
  noticeText?: boolean
  /** kreator zaczął już sam odczyt pierwszego dokumentu — po powrocie na stronę przetargu nie robi tego drugi raz */
  started?: boolean
}

const handoffs = new Map<string, TenderWizardHandoff>()

export function isEmptyHandoff(handoff: TenderWizardHandoff): boolean {
  return handoff.files.length === 0 && handoff.noticeDocuments.length === 0 && !handoff.noticeText
}

export function setTenderWizardHandoff(tenderId: number | string, handoff: TenderWizardHandoff): void {
  if (isEmptyHandoff(handoff)) handoffs.delete(String(tenderId))
  else handoffs.set(String(tenderId), handoff)
}

/** Odczyt bez usuwania (inicjalizator stanu Reacta w trybie ścisłym wołany jest dwa razy). */
export function getTenderWizardHandoff(tenderId: number | string): TenderWizardHandoff | null {
  return handoffs.get(String(tenderId)) ?? null
}
