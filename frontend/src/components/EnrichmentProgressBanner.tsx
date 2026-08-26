import {
  enrichmentPriceListHref,
  enrichmentProductHref,
  type EnrichmentBatch,
} from '../lib/api'

type Props = {
  batches: EnrichmentBatch[]
  idleLabel?: string
}

export function EnrichmentProgressBanner({ batches, idleLabel = 'W kolejce…' }: Props) {
  if (batches.length === 0) {
    return null
  }

  return (
    <div className="mb-3 rounded-xl border border-blue-200 bg-blue-50 px-4 py-3 text-xs text-blue-900">
      {batches.map((batch) => (
        <EnrichmentProgressItem key={batch.id} batch={batch} idleLabel={idleLabel} />
      ))}
    </div>
  )
}

function EnrichmentProgressItem({
  batch,
  idleLabel,
}: {
  batch: EnrichmentBatch
  idleLabel: string
}) {
  const priceHref = enrichmentPriceListHref(batch)
  const productHref = enrichmentProductHref(batch)
  const title = (
    <>
      Pobieranie opisów/zdjęć
      {batch.manufacturer ? ` — ${batch.manufacturer}` : ''} — {batch.done + batch.failed}/
      {batch.total} ({batch.progress_percent}%)
    </>
  )
  const nowLabel = batch.current_sku
    ? `Teraz: ${batch.current_sku}${batch.current_name ? ` — ${batch.current_name}` : ''}`
    : idleLabel

  return (
    <div className="mb-2 last:mb-0">
      {priceHref ? (
        <a
          href={priceHref}
          target="_blank"
          rel="noopener noreferrer"
          className="block rounded hover:bg-blue-100/70"
          title={`Cennik: ${batch.manufacturer}`}
        >
          <p className="font-semibold underline decoration-blue-300 underline-offset-2">{title}</p>
          {batch.manufacturer && (
            <p className="font-normal text-blue-800">Producent: {batch.manufacturer}</p>
          )}
          <div className="mt-1 h-2 overflow-hidden rounded bg-blue-100">
            <div
              className="h-full animate-pulse bg-blue-500 transition-all"
              style={{ width: `${Math.max(6, batch.progress_percent)}%` }}
            />
          </div>
        </a>
      ) : (
        <>
          <p className="font-semibold">{title}</p>
          <div className="mt-1 h-2 overflow-hidden rounded bg-blue-100">
            <div
              className="h-full animate-pulse bg-blue-500 transition-all"
              style={{ width: `${Math.max(6, batch.progress_percent)}%` }}
            />
          </div>
        </>
      )}
      {productHref && batch.current_sku ? (
        <a
          href={productHref}
          target="_blank"
          rel="noopener noreferrer"
          className="mt-1 block text-blue-800 underline decoration-blue-300 underline-offset-2 hover:text-blue-950"
          title={`Produkt ${batch.current_sku}`}
        >
          {nowLabel}
          {batch.message ? ` · ${batch.message}` : ''}
        </a>
      ) : (
        <p className="mt-1 text-blue-800">
          {nowLabel}
          {batch.message ? ` · ${batch.message}` : ''}
        </p>
      )}
    </div>
  )
}
