import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import type { ProductKitSuggestion, ProductKitSuggestions } from '../lib/api'

type Props = {
  open: boolean
  busy: boolean
  error: string
  data: ProductKitSuggestions | null
  onClose: () => void
  onAdd: (ids: number[]) => void
}

export function ProductKitModal({ open, busy, error, data, onClose, onAdd }: Props) {
  const [selected, setSelected] = useState<Record<number, boolean>>({})
  const [preview, setPreview] = useState<string | null>(null)

  useEffect(() => {
    if (!open) return
    setSelected({})
    setPreview(null)
  }, [open, data])

  useEffect(() => {
    if (!open) return
    function onKey(e: KeyboardEvent) {
      if (e.key === 'Escape' && !busy) {
        if (preview) setPreview(null)
        else onClose()
      }
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [open, busy, onClose, preview])

  if (!open) return null

  const rows = data?.suggestions ?? []
  const ids = rows.filter((row) => selected[row.id]).map((row) => row.id)

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/55 p-4"
      role="dialog"
      aria-modal="true"
      aria-labelledby="kit-suggest-title"
      onClick={() => {
        if (!busy) onClose()
      }}
    >
      <div
        className="relative flex max-h-[90vh] w-full max-w-3xl flex-col overflow-hidden rounded-2xl bg-white shadow-xl"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="bg-gradient-to-r from-teal-700 to-emerald-600 px-5 py-4 text-white">
          <p id="kit-suggest-title" className="text-base font-semibold">
            Dopasuj warianty
          </p>
          <p className="mt-1 text-xs text-emerald-100">
            {data?.family_label
              ? `Propozycje do zestawu (${data.family_label}). Zaznacz te, które pasują.`
              : 'Zaznacz pozycje, które chcesz dodać do zestawu.'}
          </p>
        </div>

        {busy && (
          <div className="absolute inset-0 z-10 flex flex-col items-center justify-center bg-white/90">
            <span className="inline-block h-8 w-8 animate-spin rounded-full border-4 border-teal-600 border-t-transparent" />
            <p className="mt-3 text-sm font-semibold text-teal-900">Dobieram zestaw…</p>
            <p className="mt-1 text-xs text-slate-500">Model przegląda katalog</p>
          </div>
        )}

        <div className="min-h-0 flex-1 overflow-y-auto px-5 py-4">
          {error && <p className="mb-3 rounded bg-red-50 px-3 py-2 text-xs text-red-700">{error}</p>}
          {!busy && !error && rows.length === 0 && (
            <p className="text-sm text-slate-500">Brak propozycji w katalogu dla tego produktu.</p>
          )}
          <ul className="space-y-2">
            {rows.map((row) => (
              <KitRow
                key={row.id}
                row={row}
                checked={Boolean(selected[row.id])}
                onToggle={() => setSelected((cur) => ({ ...cur, [row.id]: !cur[row.id] }))}
                onPreview={(url) => setPreview(url)}
              />
            ))}
          </ul>
        </div>

        <div className="flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 px-5 py-3">
          <button
            type="button"
            className="rounded border border-slate-300 px-3 py-2 text-xs hover:bg-slate-50"
            onClick={onClose}
            disabled={busy}
          >
            Anuluj
          </button>
          <button
            type="button"
            className="rounded bg-teal-700 px-3 py-2 text-xs font-semibold text-white disabled:opacity-50"
            disabled={busy || ids.length === 0}
            onClick={() => onAdd(ids)}
          >
            Dodaj do zestawu{ids.length > 0 ? ` (${ids.length})` : ''}
          </button>
        </div>
      </div>

      {preview && (
        <button
          type="button"
          className="absolute inset-0 z-20 flex items-center justify-center bg-slate-950/70 p-6"
          onClick={(e) => {
            e.stopPropagation()
            setPreview(null)
          }}
        >
          <img src={preview} alt="" className="max-h-full max-w-full rounded-lg shadow-2xl" />
        </button>
      )}
    </div>
  )
}

export function PrestaKitBadge({
  inPresta,
  url,
  prestaId,
}: {
  inPresta: boolean
  url?: string | null
  prestaId?: number | null
}) {
  const label = inPresta ? 'Presta' : 'brak w Presta'
  const title = inPresta
    ? `Jest w Preście${prestaId ? ` #${prestaId}` : ''} — przy eksporcie zostanie podpięty`
    : 'Brak w Preście — przy eksporcie tego produktu wariant też zostanie wysłany'
  const cls = inPresta
    ? 'bg-emerald-100 text-emerald-800'
    : 'bg-red-100 text-red-800'
  if (inPresta && url) {
    return (
      <a
        href={url}
        target="_blank"
        rel="noreferrer"
        title={title}
        className={`rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide ${cls}`}
      >
        {label}
      </a>
    )
  }
  return (
    <span title={title} className={`rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide ${cls}`}>
      {label}
    </span>
  )
}

function KitRow({
  row,
  checked,
  onToggle,
  onPreview,
}: {
  row: ProductKitSuggestion
  checked: boolean
  onToggle: () => void
  onPreview: (url: string) => void
}) {
  return (
    <li className="flex items-start gap-3 rounded-xl border border-slate-200 p-3">
      <input
        type="checkbox"
        className="mt-2"
        checked={checked}
        onChange={onToggle}
        aria-label={`Zaznacz ${row.name ?? row.sku ?? 'produkt'}`}
      />
      {row.image_url ? (
        <button
          type="button"
          className="h-16 w-16 shrink-0 overflow-hidden rounded-lg bg-slate-100"
          onClick={() => onPreview(row.image_url!)}
          title="Podgląd zdjęcia"
        >
          <img src={row.image_url} alt="" className="h-full w-full object-contain" />
        </button>
      ) : (
        <div className="flex h-16 w-16 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-[10px] text-slate-400">
          brak zdjęcia
        </div>
      )}
      <div className="min-w-0 flex-1">
        <div className="flex flex-wrap items-baseline gap-x-2">
          <Link
            to={`/products/${row.id}`}
            target="_blank"
            rel="noreferrer"
            className="font-medium text-blue-700 hover:underline"
          >
            {row.name || row.sku || 'Produkt'}
          </Link>
          {row.role ? (
            <span className="rounded bg-teal-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-teal-800">
              {row.role}
            </span>
          ) : null}
          <PrestaKitBadge inPresta={Boolean(row.in_presta)} url={row.presta_url} prestaId={row.presta_id} />
        </div>
        <p className="mt-0.5 text-xs text-slate-600">
          {row.short_description || [row.manufacturer, row.sku].filter(Boolean).join(' · ') || '—'}
        </p>
        {row.reason ? <p className="mt-1 text-[11px] text-slate-500">{row.reason}</p> : null}
      </div>
    </li>
  )
}
