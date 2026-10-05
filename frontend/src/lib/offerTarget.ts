import { useEffect, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { getOffer } from './offers'

/** Oferta, do której dobiera się produkty na liście Produktów albo Zapasów (?oferta=ID z ekranu oferty). */
export type OfferTarget = { id: number; code: string; subject: string; itemsCount: number; maxItems: number }

/** Czyta ?oferta=ID i wczytuje ofertę (oferta jest zawsze edytowalna — także po wysyłce). */
export function useOfferTarget(): { target: OfferTarget | null; offerId: number | null; error: string } {
  const [params] = useSearchParams()
  const raw = Number(params.get('oferta'))
  const offerId = Number.isInteger(raw) && raw > 0 ? raw : null
  const [target, setTarget] = useState<OfferTarget | null>(null)
  const [error, setError] = useState('')

  useEffect(() => {
    if (offerId === null) {
      setTarget(null)
      setError('')
      return
    }
    let alive = true
    getOffer(offerId)
      .then((o) => {
        if (!alive) return
        setTarget({
          id: o.id,
          code: o.code ?? `#${o.id}`,
          subject: o.subject,
          itemsCount: o.items.length,
          maxItems: o.limits.max_items,
        })
        setError('')
      })
      .catch((ex) => {
        if (alive) setError(ex instanceof Error ? ex.message : 'Nie udało się wczytać oferty.')
      })
    return () => {
      alive = false
    }
  }, [offerId])

  return { target, offerId, error }
}
