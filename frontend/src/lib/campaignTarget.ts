import { useEffect, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { getCampaign } from './campaigns'

/** Kampania, do której dobiera się towar na liście Produktów albo Zapasów (?kampania=ID z kreatora). */
export type CampaignTarget = { id: number; code: string; name: string; itemsCount: number }

/**
 * Czyta ?kampania=ID i wczytuje projekt. Wysłanej kampanii (nie draft) nie da się uzupełnić — wtedy target = null
 * i komunikat w pasku.
 */
export function useCampaignTarget(): { target: CampaignTarget | null; campaignId: number | null; error: string } {
  const [params] = useSearchParams()
  const raw = Number(params.get('kampania'))
  const campaignId = Number.isInteger(raw) && raw > 0 ? raw : null
  const [target, setTarget] = useState<CampaignTarget | null>(null)
  const [error, setError] = useState('')

  useEffect(() => {
    if (campaignId === null) {
      setTarget(null)
      setError('')
      return
    }
    let alive = true
    getCampaign(campaignId)
      .then((c) => {
        if (!alive) return
        if (c.status !== 'draft') {
          setTarget(null)
          setError(`Kampania ${c.code} została już wysłana — nie można do niej dodawać towaru.`)
          return
        }
        setTarget({ id: c.id, code: c.code, name: c.name, itemsCount: c.items.length })
        setError('')
      })
      .catch((ex) => {
        if (alive) setError(ex instanceof Error ? ex.message : 'Nie udało się wczytać kampanii.')
      })
    return () => {
      alive = false
    }
  }, [campaignId])

  return { target, campaignId, error }
}
