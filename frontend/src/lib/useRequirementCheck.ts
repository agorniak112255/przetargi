import { useEffect, useState } from 'react'
import { api } from './api'
import type { RequirementCheck } from '../components/RequirementCheckList'

/**
 * Etykieta przycisku i plakietki: „Nie spełnia 3 · sprzeczna karta 1”. Niespełnione wymaganie
 * i karta przecząca sama sobie to różne problemy — jedno słowo „Sprzeczności” myliło handlowca
 * (AI „nie znalazło sprzeczności”, a przycisk pokazywał 3).
 */
export function conflictsLabel(requirement: number, cardFields: number): string {
  const parts: string[] = []
  if (requirement > 0) parts.push(`Nie spełnia ${requirement}`)
  if (cardFields > 0) parts.push(`${parts.length > 0 ? 'sprzeczna karta' : 'Sprzeczna karta'} ${cardFields}`)
  return parts.join(' · ')
}

/**
 * Porównanie parametrów wymagania z kartą (reguły, bez modelu) — wspólne dla listy parametrów
 * i przycisku „Sprzeczności”. Wynik trzymany z kluczem, dla którego powstał: po zmianie
 * produktu/zapytania stary przestaje obowiązywać bez resetu w efekcie i bez migania.
 */
export function useRequirementCheck(
  productId: number | null,
  query: string,
): { check: RequirementCheck | null; loading: boolean; error: boolean } {
  const key = `${productId}|${query}`
  const enabled = productId != null && query.trim().length >= 3
  const [result, setResult] = useState<{ key: string; check: RequirementCheck | null } | null>(null)

  useEffect(() => {
    if (!enabled || productId == null) return
    let cancelled = false
    const requestKey = `${productId}|${query}`
    void api<RequirementCheck>(`/products/${productId}/requirement-check`, {
      method: 'POST',
      body: JSON.stringify({ query }),
    })
      .then((check) => {
        if (!cancelled) setResult({ key: requestKey, check })
      })
      .catch(() => {
        if (!cancelled) setResult({ key: requestKey, check: null })
      })
    return () => {
      cancelled = true
    }
  }, [enabled, productId, query])

  const current = enabled && result?.key === key ? result : null
  return {
    check: current?.check ?? null,
    loading: enabled && current == null,
    error: current != null && current.check == null,
  }
}
