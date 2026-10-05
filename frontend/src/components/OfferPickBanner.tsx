import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'
import type { OfferTarget } from '../lib/offerTarget'

/** Pasek nad listą: do której oferty dobierasz produkty, ile miejsc zostało, powrót do oferty (jak CampaignPickBanner). */
export function OfferPickBanner({
  target,
  offerId,
  error,
  what,
  action,
}: {
  target: OfferTarget | null
  offerId: number | null
  error: string
  /** „towar” (Zapasy) albo „karty” (Produkty). */
  what: string
  /** Przycisk „Dodaj do OF-…” obok powrotu — pasek jest przypięty u góry listy. */
  action?: ReactNode
}) {
  if (offerId === null) return null
  const free = target ? Math.max(0, target.maxItems - target.itemsCount) : 0

  return (
    <div className="app-callout flex flex-wrap items-center justify-between gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-950">
      {error ? (
        <span>{error}</span>
      ) : target ? (
        <span>
          Dobierasz {what} do oferty <b>{target.code}</b>
          {target.subject.trim() !== '' && <> „{target.subject.trim()}”</>} — {target.itemsCount} z {target.maxItems}{' '}
          pozycji{free > 0 ? `, zostało ${free}` : ', oferta jest pełna'}. Zaznacz i kliknij „Dodaj do {target.code}”.
        </span>
      ) : (
        <span>Wczytuję ofertę…</span>
      )}
      <span className="flex flex-wrap items-center gap-2">
        {action}
        <Link
          to={`/oferty/${offerId}`}
          className="rounded border border-emerald-300 bg-white px-3 py-1.5 text-xs font-medium text-emerald-900 hover:bg-emerald-100"
        >
          ← Wróć do oferty
        </Link>
      </span>
    </div>
  )
}
