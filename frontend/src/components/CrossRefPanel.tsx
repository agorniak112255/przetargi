import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../lib/api'
import {
  CrossRefFilterModal,
  type CrossRefAppliedFilter,
  type CrossRefFilterGroup,
} from './CrossRefFilterModal'
import { ProductPreviewModal } from './ProductPreviewModal'

type CrossRefProduct = {
  product_id: number
  sku: string
  name: string
  manufacturer: string
  catalog_price_net: number | null
  match_percent: number
  cross_brand: boolean
  image_url?: string | null
  has_description?: boolean
  matched_filters?: CrossRefAppliedFilter[]
  attributes?: {
    material?: string | null
    klasa_ochrony?: string | null
    poziomy_en388?: string | null
    typ_wyrobu?: string | null
    przeznaczenie?: string | null
  } | null
}

type CrossRefResponse = {
  code: string
  seed: CrossRefProduct | null
  matches: CrossRefProduct[]
  total: number
  applied_filters?: CrossRefAppliedFilter[]
}

type CrossRefOptionsResponse = {
  code: string
  seed: CrossRefProduct | null
  groups: CrossRefFilterGroup[]
}

function fmtPrice(v: number | null): string {
  if (v == null) return '—'
  return v.toLocaleString('pl-PL', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function compareUrl(ids: number[]): string {
  const params = new URLSearchParams()
  ids.forEach((id) => params.append('ids[]', String(id)))
  return `/products/compare?${params.toString()}`
}

function defaultMust(groups: CrossRefFilterGroup[]): string[] {
  return groups.flatMap((g) => g.items.filter((i) => i.default).map((i) => i.id))
}

export function CrossRefPanel({
  initialCode = '',
  autoRun = false,
}: {
  initialCode?: string
  autoRun?: boolean
}) {
  const [code, setCode] = useState(initialCode)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const [result, setResult] = useState<CrossRefResponse | null>(null)
  const [selectedIds, setSelectedIds] = useState<number[]>([])
  const [modalOpen, setModalOpen] = useState(false)
  const [groups, setGroups] = useState<CrossRefFilterGroup[]>([])
  const [must, setMust] = useState<string[]>([])
  const [optionsBusy, setOptionsBusy] = useState(false)
  const [optionsErr, setOptionsErr] = useState('')
  const [seedLabel, setSeedLabel] = useState('')
  const [previewId, setPreviewId] = useState<number | null>(null)
  const [imageModal, setImageModal] = useState<{ name: string; url: string } | null>(null)

  async function run(override?: string, filterIds: string[] = []) {
    const q = (override ?? code).trim()
    if (q.length < 2) {
      setErr('Podaj kod / SKU (min. 2 znaki)')
      return
    }
    setBusy(true)
    setErr('')
    try {
      const qs = new URLSearchParams({ code: q, limit: '12' })
      for (const id of filterIds) qs.append('must[]', id)
      const res = await api<CrossRefResponse>(`/products/cross-ref?${qs.toString()}`)
      setResult(res)
      setMust(filterIds)
      setSelectedIds(res.seed ? [res.seed.product_id] : [])
      setModalOpen(false)
    } catch (e: unknown) {
      setResult(null)
      setErr(e instanceof Error ? e.message : 'Błąd cross-ref')
    } finally {
      setBusy(false)
    }
  }

  async function openRefine() {
    const q = code.trim()
    if (q.length < 2) {
      setErr('Podaj kod / SKU (min. 2 znaki)')
      return
    }
    setModalOpen(true)
    setOptionsBusy(true)
    setOptionsErr('')
    try {
      const res = await api<CrossRefOptionsResponse>(
        `/products/cross-ref/options?code=${encodeURIComponent(q)}`,
      )
      setGroups(res.groups)
      setSeedLabel(
        res.seed ? `${res.seed.manufacturer} · ${res.seed.name}`.replace(/^ · /, '') : '',
      )
      if (must.length === 0) {
        setMust(defaultMust(res.groups))
      } else {
        const known = new Set(res.groups.flatMap((g) => g.items.map((i) => i.id)))
        const kept = must.filter((id) => known.has(id))
        setMust(kept.length > 0 ? kept : defaultMust(res.groups))
      }
      if (!res.seed) {
        setOptionsErr(`Nie znaleziono produktu „${q}”.`)
      }
    } catch (e: unknown) {
      setGroups([])
      setOptionsErr(e instanceof Error ? e.message : 'Nie udało się pobrać filtrów')
    } finally {
      setOptionsBusy(false)
    }
  }

  useEffect(() => {
    if (initialCode && initialCode !== code) {
      setCode(initialCode)
    }
    if (autoRun && initialCode.trim().length >= 2) {
      void run(initialCode, [])
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps -- only on mount / initialCode from URL
  }, [initialCode, autoRun])

  useEffect(() => {
    if (!imageModal) return
    function onKey(e: KeyboardEvent) {
      if (e.key === 'Escape') setImageModal(null)
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [imageModal])

  const seedId = result?.seed?.product_id
  const applied = result?.applied_filters ?? []
  const selectedProducts = result
    ? [result.seed, ...result.matches].filter(
        (product): product is CrossRefProduct =>
          product !== null && selectedIds.includes(product.product_id),
      )
    : []

  function toggleProduct(productId: number) {
    if (productId === seedId) return
    setSelectedIds((current) => {
      if (current.includes(productId)) return current.filter((id) => id !== productId)
      if (current.length >= 5) return current
      return [...current, productId]
    })
  }

  return (
    <div className="mb-4 rounded-xl border border-emerald-200 bg-emerald-50/40 p-3 shadow-sm">
      <label className="mb-1 block text-xs font-medium text-slate-700">
        Cross-ref po kodzie / SKU — ten sam wyrób (typ, materiał, klasa, przeznaczenie, normy)
      </label>
      <div className="flex flex-wrap items-center gap-2">
        <input
          className="w-full max-w-xs rounded border border-slate-300 bg-white px-3 py-2 text-sm"
          placeholder="np. RNITZ, XG27B, 30-202"
          value={code}
          onChange={(e) => setCode(e.target.value)}
          onKeyDown={(e) => {
            if (e.key === 'Enter') {
              e.preventDefault()
              void run()
            }
          }}
        />
        <button
          type="button"
          disabled={busy}
          onClick={() => void run()}
          className="rounded bg-emerald-700 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-800 disabled:opacity-50"
        >
          {busy ? 'Szukam…' : 'Znajdź zamienniki'}
        </button>
        <button
          type="button"
          disabled={busy}
          onClick={() => void openRefine()}
          className="rounded border border-emerald-700 bg-white px-3 py-2 text-xs font-semibold text-emerald-800 hover:bg-emerald-50 disabled:opacity-50"
        >
          Doprecyzuj
        </button>
      </div>
      {err && <p className="mt-2 text-xs text-rose-700">{err}</p>}
      {result && (
        <div className="mt-3 space-y-2">
          {result.seed ? (
            <p className="flex flex-wrap items-center gap-2 text-xs text-slate-600">
              <span>
                Baza:{' '}
                <Link to={`/products/${result.seed.product_id}`} className="font-medium text-blue-700 hover:underline">
                  {result.seed.sku}
                </Link>
                {' · '}
                {result.seed.manufacturer} · {result.seed.name}
              </span>
              {result.seed.has_description && (
                <button
                  type="button"
                  onClick={() => setPreviewId(result.seed!.product_id)}
                  className="rounded border border-green-300 bg-green-50 px-2 py-0.5 text-[11px] text-green-800 hover:bg-green-100"
                >
                  Opis
                </button>
              )}
            </p>
          ) : (
            <p className="text-xs text-amber-800">
              Brak dokładnego SKU „{result.code}” — wyniki na podstawie podobieństwa tekstu.
            </p>
          )}
          {applied.length > 0 && (
            <div className="flex flex-wrap items-center gap-1.5">
              <span className="text-[10px] font-semibold uppercase tracking-wide text-slate-500">
                Musi mieć
              </span>
              {applied.map((f) => (
                <span
                  key={f.id}
                  className="rounded-full border border-emerald-300 bg-white px-2 py-0.5 text-[10px] text-emerald-900"
                >
                  {f.label}
                </span>
              ))}
              <button
                type="button"
                className="text-[11px] text-emerald-800 hover:underline"
                onClick={() => void openRefine()}
              >
                Zmień
              </button>
              <button
                type="button"
                className="text-[11px] text-slate-600 hover:underline"
                onClick={() => void run(code, [])}
              >
                Szukaj wszystko
              </button>
            </div>
          )}
          <div className="rounded-lg border border-emerald-200 bg-white p-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <div>
                <p className="text-xs font-semibold text-slate-700">
                  Wybrane do porównania: {selectedIds.length}/5
                </p>
                <p className="text-[10px] text-slate-500">
                  Zaznacz od 2 do 5 produktów. Produkt bazowy jest dodany automatycznie.
                </p>
              </div>
              {selectedIds.length >= 2 ? (
                <Link
                  to={compareUrl(selectedIds)}
                  className="rounded bg-emerald-700 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-800"
                >
                  Porównaj zaznaczone ({selectedIds.length})
                </Link>
              ) : (
                <span className="cursor-not-allowed rounded bg-slate-200 px-3 py-2 text-xs font-semibold text-slate-500">
                  Wybierz jeszcze produkt
                </span>
              )}
            </div>
            {selectedProducts.length > 0 && (
              <div className="mt-2 flex flex-wrap gap-1.5">
                {selectedProducts.map((product) => (
                  <span
                    key={product.product_id}
                    className="inline-flex items-center gap-1 rounded-full border border-emerald-200 bg-emerald-50 px-2 py-1 text-[10px] text-emerald-900"
                  >
                    <b>{product.sku}</b>
                    <span className="max-w-40 truncate">{product.manufacturer}</span>
                    {product.product_id === seedId ? (
                      <span className="text-emerald-600">baza</span>
                    ) : (
                      <button
                        type="button"
                        aria-label={`Usuń ${product.sku} z porównania`}
                        className="ml-0.5 font-bold text-rose-600 hover:text-rose-800"
                        onClick={() => toggleProduct(product.product_id)}
                      >
                        ×
                      </button>
                    )}
                  </span>
                ))}
              </div>
            )}
          </div>
          {result.matches.length === 0 ? (
            <p className="text-xs text-slate-500">
              {applied.length > 0
                ? 'Brak zamienników spełniających zaznaczone warunki. Odznacz część filtrów.'
                : 'Brak ekwiwalentów powyżej progu podobieństwa.'}
            </p>
          ) : (
            <div className="overflow-x-auto rounded-lg border border-emerald-100 bg-white">
              <table className="w-full text-left text-xs">
                <thead>
                  <tr className="border-b bg-slate-50">
                    <th className="p-2 text-center">Wybierz</th>
                    <th className="p-2">%</th>
                    <th className="p-2">Zdjęcie</th>
                    <th className="p-2">SKU</th>
                    <th className="p-2">Producent</th>
                    <th className="p-2">Nazwa</th>
                    <th className="p-2">Cena</th>
                    <th className="p-2">Opis</th>
                    <th className="p-2">{applied.length > 0 ? 'Spełnia / atrybuty' : 'Atrybuty'}</th>
                  </tr>
                </thead>
                <tbody>
                  {result.matches.map((m) => {
                    const checked = selectedIds.includes(m.product_id)
                    const disabled = !checked && selectedIds.length >= 5
                    const hits = m.matched_filters ?? applied
                    return (
                      <tr
                        key={m.product_id}
                        className={`border-b align-top ${checked ? 'bg-emerald-50/70' : ''}`}
                      >
                        <td className="p-2 text-center">
                          <input
                            type="checkbox"
                            checked={checked}
                            disabled={disabled}
                            aria-label={`Wybierz ${m.sku} do porównania`}
                            onChange={() => toggleProduct(m.product_id)}
                            className="h-4 w-4 rounded border-slate-300 text-emerald-700"
                          />
                        </td>
                        <td className="p-2 font-semibold text-violet-700">{m.match_percent}%</td>
                        <td className="p-2">
                          {m.image_url ? (
                            <button
                              type="button"
                              onClick={() => setImageModal({ name: m.name, url: m.image_url! })}
                              className="block overflow-hidden rounded border border-slate-200 bg-slate-50"
                              title="Pokaż pełne zdjęcie"
                            >
                              <img src={m.image_url} alt="" className="h-10 w-10 object-cover" />
                            </button>
                          ) : (
                            <span className="text-slate-400">—</span>
                          )}
                        </td>
                        <td className="p-2">
                          <Link to={`/products/${m.product_id}`} className="text-blue-600 hover:underline">
                            {m.sku}
                          </Link>
                          {m.cross_brand && (
                            <span className="ml-1 rounded bg-amber-100 px-1 text-[9px] text-amber-800">
                              inna marka
                            </span>
                          )}
                        </td>
                        <td className="p-2">{m.manufacturer}</td>
                        <td className="p-2 max-w-[14rem] truncate" title={m.name}>
                          {m.name}
                        </td>
                        <td className="p-2 whitespace-nowrap">{fmtPrice(m.catalog_price_net)}</td>
                        <td className="p-2">
                          {m.has_description ? (
                            <button
                              type="button"
                              onClick={() => setPreviewId(m.product_id)}
                              className="rounded border border-green-300 bg-green-50 px-2 py-1 text-[11px] text-green-800 hover:bg-green-100"
                            >
                              Opis
                            </button>
                          ) : (
                            <span className="text-slate-400">—</span>
                          )}
                        </td>
                        <td className="p-2 text-[10px] text-slate-500">
                          {hits.length > 0 && (
                            <div className="mb-1 flex flex-wrap gap-1">
                              {hits.map((f) => (
                                <span
                                  key={f.id}
                                  className="rounded bg-emerald-100 px-1.5 py-0.5 text-[10px] text-emerald-900"
                                >
                                  {f.label}
                                </span>
                              ))}
                            </div>
                          )}
                          {[
                            m.attributes?.typ_wyrobu,
                            m.attributes?.material,
                            m.attributes?.klasa_ochrony,
                            m.attributes?.przeznaczenie,
                            m.attributes?.poziomy_en388,
                          ]
                            .filter(Boolean)
                            .join(' · ') || (hits.length > 0 ? '' : '—')}
                        </td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>
          )}
        </div>
      )}
      <CrossRefFilterModal
        open={modalOpen}
        code={code.trim()}
        seedLabel={seedLabel}
        groups={groups}
        selected={must}
        loading={optionsBusy}
        error={optionsErr}
        onChange={setMust}
        onSearchAll={() => void run(code, [])}
        onSearchMust={(ids) => void run(code, ids)}
        onClose={() => setModalOpen(false)}
      />
      <ProductPreviewModal
        productId={previewId}
        query={[result?.seed?.sku, result?.seed?.name, result?.code].filter(Boolean).join(' ')}
        onClose={() => setPreviewId(null)}
      />
      {imageModal && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4"
          role="dialog"
          aria-modal="true"
          onClick={() => setImageModal(null)}
        >
          <div className="relative max-h-[90vh] max-w-[90vw]" onClick={(e) => e.stopPropagation()}>
            <button
              type="button"
              onClick={() => setImageModal(null)}
              className="absolute -right-2 -top-2 rounded bg-white px-2 py-1 text-xs shadow"
            >
              Zamknij
            </button>
            <img
              src={imageModal.url}
              alt={imageModal.name}
              className="max-h-[85vh] max-w-[90vw] rounded bg-white object-contain"
            />
          </div>
        </div>
      )}
    </div>
  )
}
