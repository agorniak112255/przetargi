import type { InquiryTone } from '../types/inquiry'

/**
 * Szablony listu do klienta — te same nazwy na stronie zakładania zapytania,
 * na stronie odpowiedzi i w dodatku do Thunderbirda. Treść pozycji buduje
 * backend (`ClientInquiryService`), tu jest tylko nazwa i krótkie wyjaśnienie,
 * co klient w danym szablonie zobaczy.
 */
export const toneOptions: { id: InquiryTone; label: string; hint: string }[] = [
  {
    id: 'handlowy',
    label: 'Handlowy (pełna specyfikacja)',
    hint: 'Nazwa z katalogu, SKU i producent, normy i cena.',
  },
  {
    id: 'bez_sku',
    label: 'Bez SKU (proste opisy)',
    hint: 'Jedno zdanie opisu bez marki i modelu, normy i cena. Bez opisu w karcie pozycję opisują słowa klienta.',
  },
  {
    id: 'formal',
    label: 'Oficjalny (długie opisy)',
    hint: 'Nazwa, akapit opisu z karty wyrobu, normy i cena — bez SKU.',
  },
]

export const toneLabel: Record<InquiryTone, string> = {
  handlowy: 'Handlowy (pełna specyfikacja)',
  bez_sku: 'Bez SKU (proste opisy)',
  formal: 'Oficjalny (długie opisy)',
}

export function toneHint(tone: InquiryTone): string {
  return toneOptions.find((opt) => opt.id === tone)?.hint ?? ''
}
