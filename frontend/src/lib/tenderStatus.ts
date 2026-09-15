/** Nazwy statusów przetargu w interfejsie (klucze jak w backend TenderWorkflowService). */
export const TENDER_STATUS_LABEL: Record<string, string> = {
  draft: 'Szkic',
  wycena: 'Wycena',
  akceptacja_km: 'Akceptacja kierownika',
  akceptacja_dyrektor: 'Akceptacja dyrektora',
  zatwierdzona: 'Zatwierdzona',
  exported: 'Wyeksportowana',
  odrzucony: 'Odrzucony',
  archiwum: 'Archiwum',
}

/** Kolejne etapy głównej ścieżki przetargu. „odrzucony” i „archiwum” są poza ścieżką. */
export const TENDER_STATUS_FLOW = [
  'draft',
  'wycena',
  'akceptacja_km',
  'akceptacja_dyrektor',
  'zatwierdzona',
  'exported',
] as const

export function tenderStatusLabel(status: string): string {
  return TENDER_STATUS_LABEL[status] ?? status
}
