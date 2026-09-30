import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'
import type { CampaignTarget } from '../lib/campaignTarget'
import { CAMPAIGN_MAX_ITEMS } from './AddToCampaignMenu'

/** Pasek nad listą: do której kampanii dobierasz towar, ile miejsc zostało, powrót do kreatora. */
export function CampaignPickBanner({
  target,
  campaignId,
  error,
  what,
  action,
}: {
  target: CampaignTarget | null
  campaignId: number | null
  error: string
  /** „towar” (Zapasy) albo „karty” (Produkty). */
  what: string
  /** Przycisk „Dodaj do K-…” obok powrotu — pasek bywa przypięty u góry listy. */
  action?: ReactNode
}) {
  if (campaignId === null) return null
  const free = target ? Math.max(0, CAMPAIGN_MAX_ITEMS - target.itemsCount) : 0

  return (
    <div className="app-callout flex flex-wrap items-center justify-between gap-3 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-950">
      {error ? (
        <span>{error}</span>
      ) : target ? (
        <span>
          Dobierasz {what} do kampanii <b>{target.code}</b> „{target.name}” — {target.itemsCount} z {CAMPAIGN_MAX_ITEMS}{' '}
          pozycji{free > 0 ? `, zostało ${free}` : ', kampania jest pełna'}. Zaznacz i kliknij „Dodaj do {target.code}”.
        </span>
      ) : (
        <span>Wczytuję kampanię…</span>
      )}
      <span className="flex flex-wrap items-center gap-2">
        {action}
        <Link
          to={`/kampanie/${campaignId}`}
          className="rounded border border-sky-300 bg-white px-3 py-1.5 text-xs font-medium text-sky-900 hover:bg-sky-100"
        >
          ← Wróć do kampanii
        </Link>
      </span>
    </div>
  )
}
