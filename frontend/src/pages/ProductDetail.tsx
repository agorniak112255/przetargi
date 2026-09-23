import { Fragment, useCallback, useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useAuth } from '../auth'
import { DescriptionLayoutView } from '../components/DescriptionLayoutView'
import { CrossRefPanel } from '../components/CrossRefPanel'
import { PrestaSearchModal, type PrestaSearchResult } from '../components/PrestaSearchModal'
import { PrestaKitBadge, ProductKitModal } from '../components/ProductKitModal'
import { PriceStep } from '../components/ProductPriceChange'
import { ProductVariantsTable } from '../components/ProductVariantsTable'
import { ShopFieldsTables } from '../components/ShopFieldsTables'
import { SupplierSpecialBadge } from '../components/SupplierSpecialBadge'
import { SupplierSpecialPanel } from '../components/SupplierSpecialPanel'
import { SUPPLIER_SPECIAL_INFERENCE_NOTE, supplierSpecialSummary } from '../lib/supplierSpecial'
import {
  api,
  B2B_DESCRIPTION_OVERWRITE_CONFIRM,
  can,
  type EnrichmentBatch,
  type PrestaExportResult,
  type Product,
  type ProductAccessory,
  type ProductKitSuggestions,
  type ProductPriceHistoryRow,
  type ProductSourcePrice,
  type Substitute,
} from '../lib/api'
import {
  currencyLabel,
  formatDateTime,
  formatPct,
  formatPrice,
  priceChangeSummary,
  variantCountLabel,
} from '../lib/priceChange'

type Detail = Product & { substitutes: Substitute[] }

/**
 * Cennik bazowy dostawcy pod ceną konta B2B: rabat standardowy, faktyczny i ocena ceny specjalnej B2B.
 * Ocena to wniosek z porównania (dostawca potwierdza ceny specjalne mailem) — stąd zawsze pochodzenie ceny bazowej.
 */
function BasePriceNote({ slot }: { slot: ProductSourcePrice }) {
  const cur = currencyLabel(slot.currency)
  const special = slot.supplier_special ?? null
  const hasStandard = slot.standard_discount_percent != null && slot.standard_discount_percent !== ''
  return (
    <div className="rounded bg-slate-50 px-2 py-1.5 text-[11px] text-slate-600">
      <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
        <span>
          Cennik bazowy:{' '}
          <b className="tabular-nums text-slate-800">
            {formatPrice(slot.base_price_net)} {cur}
          </b>
          {slot.base_price_category && <> ({slot.base_price_category})</>}
          {slot.base_price_code && <span className="text-slate-500"> · kod {slot.base_price_code}</span>}
        </span>
        {hasStandard ? (
          <>
            <span>
              Rabat standardowy: <b className="tabular-nums">{formatPct(Number(slot.standard_discount_percent), false)}</b>
            </span>
            {special && (
              <>
                <span>
                  Rabat faktyczny:{' '}
                  <b className="tabular-nums">{formatPct(special.actual_discount_percent, false)}</b>
                </span>
                <span>
                  Cena standardowa:{' '}
                  <b className="tabular-nums">
                    {formatPrice(special.standard_price)} {cur}
                  </b>
                </span>
              </>
            )}
          </>
        ) : (
          <span className="text-amber-800">
            Brak rabatu standardowego dla kategorii {slot.base_price_category ? `„${slot.base_price_category}”` : '(bez arkusza)'} —
            ustaw w{' '}
            <Link to="/price-lists/b2b" className="underline hover:text-amber-900">
              Cenniki B2B → Rabaty
            </Link>
          </span>
        )}
      </div>
      {special?.status === 'special' && (
        <div className="mt-1 flex flex-wrap items-center gap-2" title={supplierSpecialSummary(special, slot.currency)}>
          <SupplierSpecialBadge special={special} currency={slot.currency} />
          <b className="tabular-nums text-emerald-800">
            taniej o {formatPrice(special.saving_net)} {cur} od ceny standardowej
          </b>
          <span className="text-slate-500">{SUPPLIER_SPECIAL_INFERENCE_NOTE}</span>
        </div>
      )}
      {special?.status === 'worse_than_standard' && (
        <p className="mt-1 text-amber-800">
          Cena konta powyżej ceny standardowej o {formatPrice(Math.abs(special.saving_net))} {cur} — sprawdź arkusz
          cennika bazowego i rabat standardowy.
        </p>
      )}
      {slot.base_price_source && <p className="mt-1 text-[10px] text-slate-500">Źródło: {slot.base_price_source}</p>}
    </div>
  )
}

/** Nagłówki kolumn cenników bywają skrótowe („ochrony”, „rozm.”) — na karcie nazywamy je po ludzku. */
const PRICE_LIST_ATTR_LABELS: Record<string, string> = {
  klasa_ochrony: 'Klasa ochrony',
  normy: 'Normy',
  rozmiar: 'Rozmiar',
  kolor: 'Kolor',
  material: 'Materiał',
}

const STATUS_LABEL: Record<string, string> = {
  none: 'Brak danych',
  queued: 'W kolejce',
  running: 'Pobieranie…',
  done: 'Gotowe',
  failed: 'Błąd',
  manual: 'Ręcznie',
}

const TRACE_LABEL: Record<string, string> = {
  start: 'start',
  ident: 'tożsamość',
  catalog: 'indeks',
  query: 'zapytanie',
  drop: 'odrzucono',
  err: 'błąd',
  search: 'szukanie',
  fetch: 'pobranie',
  page: 'strona',
  desc: 'opis',
  fail: 'koniec',
}

export function ProductDetail() {
  const { id } = useParams()
  const navigate = useNavigate()
  const { user } = useAuth()
  const canEnrich = can(user, 'price_lists.import')
  const canExportPresta = can(user, 'presta.export')
  const canDelete = can(user, 'products.delete')
  const canDeleteImages = can(user, 'products.images.delete')
  const [p, setP] = useState<Detail | null>(null)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const [batch, setBatch] = useState<EnrichmentBatch | null>(null)
  const [imageModalUrl, setImageModalUrl] = useState<string | null>(null)
  const [prestaOpen, setPrestaOpen] = useState(false)
  const [prestaBusy, setPrestaBusy] = useState(false)
  const [prestaErr, setPrestaErr] = useState('')
  const [prestaItems, setPrestaItems] = useState<PrestaSearchResult[]>([])
  const [exportBusy, setExportBusy] = useState(false)
  const [exportMsg, setExportMsg] = useState('')
  const [deleteBusy, setDeleteBusy] = useState(false)
  const [imageDeleteId, setImageDeleteId] = useState<number | null>(null)
  const [priceHistory, setPriceHistory] = useState<ProductPriceHistoryRow[]>([])
  const [categoryOptions, setCategoryOptions] = useState<{ value: string; label: string }[]>([])
  const [categoryBusy, setCategoryBusy] = useState(false)
  const [specsRows, setSpecsRows] = useState<{ label: string; value: string }[]>([])
  const [specsBusy, setSpecsBusy] = useState(false)
  const [specsMsg, setSpecsMsg] = useState('')
  const [categoryMsg, setCategoryMsg] = useState('')
  const [shopUrlDraft, setShopUrlDraft] = useState('')
  const [shopUrlBusy, setShopUrlBusy] = useState(false)
  const [shopUrlMsg, setShopUrlMsg] = useState('')
  const [kitOpen, setKitOpen] = useState(false)
  const [kitBusy, setKitBusy] = useState(false)
  const [kitErr, setKitErr] = useState('')
  const [kitData, setKitData] = useState<ProductKitSuggestions | null>(null)
  const [kitSelected, setKitSelected] = useState<Record<number, boolean>>({})
  const [kitActionBusy, setKitActionBusy] = useState(false)

  const load = useCallback(async () => {
    if (!id) return
    setP(await api<Detail>(`/products/${id}`))
    const hist = await api<{ data: typeof priceHistory }>(`/products/${id}/price-history`)
    setPriceHistory(hist.data ?? [])
  }, [id])

  useEffect(() => {
    void load()
  }, [load])

  useEffect(() => {
    void api<{ data: { value: string; label: string }[] }>('/products/categories')
      .then((res) => setCategoryOptions(res.data ?? []))
      .catch(() => setCategoryOptions([]))
  }, [])

  useEffect(() => {
    setShopUrlDraft(p?.shop_source_url ?? '')
  }, [p?.id, p?.shop_source_url])

  useEffect(() => {
    setSpecsRows(p?.manual_specs ?? [])
    setSpecsMsg('')
  }, [p?.id, p?.manual_specs])

  async function saveManualSpecs(rows: { label: string; value: string }[]) {
    if (!id || !p) return
    const clean = rows
      .map((r) => ({ label: r.label.trim(), value: r.value.trim() }))
      .filter((r) => r.label !== '' || r.value !== '')
    if (clean.some((r) => r.label === '' || r.value === '')) {
      setErr('Każdy wiersz ma nazwę parametru i wartość — inaczej nie wiadomo, co zapisujemy.')
      return
    }
    setSpecsBusy(true)
    setSpecsMsg('')
    setErr('')
    try {
      const res = await api<{ manual_specs: { label: string; value: string }[] | null }>(
        `/products/${id}/manual-specs`,
        { method: 'PATCH', body: JSON.stringify({ specs: clean }) },
      )
      setP({ ...p, manual_specs: res.manual_specs })
      setSpecsRows(res.manual_specs ?? [])
      setSpecsMsg('Zapisano')
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Nie udało się zapisać parametrów')
    } finally {
      setSpecsBusy(false)
    }
  }

  async function saveCategory(next: string) {
    if (!id || !p) return
    const current = p.category ?? ''
    if (next === current) return
    setCategoryBusy(true)
    setCategoryMsg('')
    setErr('')
    try {
      const res = await api<{ category: string | null }>(`/products/${id}/category`, {
        method: 'PATCH',
        body: JSON.stringify({ category: next === '' ? null : next }),
      })
      setP({ ...p, category: res.category })
      setCategoryMsg('Zapisano')
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Nie udało się zapisać grupy')
    } finally {
      setCategoryBusy(false)
    }
  }

  useEffect(() => {
    if (!batch || batch.status === 'done' || batch.status === 'failed') return
    const t = window.setInterval(() => {
      void api<EnrichmentBatch>(`/product-enrichment-batches/${batch.id}`).then((b) => {
        setBatch(b)
        if (b.status === 'done' || b.status === 'failed') {
          void load()
        }
      })
    }, 2000)
    return () => window.clearInterval(t)
  }, [batch, load])

  useEffect(() => {
    if (!imageModalUrl) return
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setImageModalUrl(null)
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [imageModalUrl])

  async function persistShopSource(next: string): Promise<boolean> {
    if (!id || !p) return false
    const trimmed = next.trim()
    const current = (p.shop_source_url ?? '').trim()
    if (trimmed === current) {
      setShopUrlMsg(trimmed === '' ? '' : 'Zapisano')
      return true
    }
    setShopUrlBusy(true)
    setShopUrlMsg('')
    setErr('')
    try {
      const res = await api<{ shop_source_url: string | null }>(`/products/${id}/shop-source`, {
        method: 'PATCH',
        body: JSON.stringify({ shop_source_url: trimmed === '' ? null : trimmed }),
      })
      setP({ ...p, shop_source_url: res.shop_source_url })
      setShopUrlDraft(res.shop_source_url ?? '')
      setShopUrlMsg(res.shop_source_url ? 'Zapisano — kliknij Pobierz, żeby zaciągnąć kartę' : 'Usunięto link')
      return true
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Nie udało się zapisać linku do sklepu')
      return false
    } finally {
      setShopUrlBusy(false)
    }
  }

  async function enrich(force = false) {
    if (!id) return
    if (canEnrich && shopUrlDraft.trim() !== (p?.shop_source_url ?? '').trim()) {
      const saved = await persistShopSource(shopUrlDraft)
      if (!saved) return
    }
    const overwriteB2b = Boolean(p?.description_from_b2b)
    if (overwriteB2b && !window.confirm(B2B_DESCRIPTION_OVERWRITE_CONFIRM)) return
    setBusy(true)
    setErr('')
    try {
      const res = await api<{ batch: EnrichmentBatch; product?: Detail; images_count?: number }>(
        `/products/${id}/enrich`,
        {
          method: 'POST',
          body: JSON.stringify({ force, overwrite_b2b_description: overwriteB2b }),
        },
      )
      setBatch(res.batch)
      if (res.product) {
        setP(res.product)
      } else {
        await load()
      }
      if ((res.images_count ?? res.product?.images?.length ?? 0) === 0) {
        setErr('Opis pobrany, ale nie udało się zapisać zdjęcia. Spróbuj ponownie za chwilę.')
      }
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Błąd enrichmentu')
    } finally {
      setBusy(false)
    }
  }

  async function searchPresta() {
    if (!id) return
    setPrestaBusy(true)
    setPrestaErr('')
    setPrestaOpen(true)
    try {
      const res = await api<PrestaSearchResult>(`/products/${id}/presta-search`, {
        method: 'POST',
        body: '{}',
      })
      setPrestaItems([res])
    } catch (ex) {
      setPrestaItems([])
      setPrestaErr(ex instanceof Error ? ex.message : 'Błąd wyszukiwania w Preście')
    } finally {
      setPrestaBusy(false)
    }
  }

  async function exportPresta() {
    if (!id) return
    const already = Boolean(p?.presta_export?.presta_id)
    const ok = window.confirm(
      already
        ? 'Produkt jest już w Preście. Zaktualizować opis, zdjęcia, rozmiary i termin „Na zamówienie”?'
        : 'Wysłać ten produkt do Presty? Wejdą opis, zdjęcia, rozmiary z opakowania i termin „Na zamówienie”.',
    )
    if (!ok) return
    setExportBusy(true)
    setErr('')
    setExportMsg('')
    try {
      const res = await api<PrestaExportResult>(`/products/${id}/presta-export`, {
        method: 'POST',
        body: JSON.stringify({ force: already }),
      })
      const missing =
        res.sizes_missing.length > 0 ? ` · brak atrybutów: ${res.sizes_missing.join(', ')}` : ''
      setExportMsg(
        res.action === 'exists'
          ? `Już w Preście (#${res.presta_id}).`
          : `Wysłano do Presty (#${res.presta_id}, ${res.action}` +
              (res.sizes.length > 0 ? `, rozmiary ${res.sizes.join('/')}` : '') +
              (res.images > 0 ? `, zdjęcia ${res.images}` : '') +
              `)${missing}`,
      )
      await load()
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Błąd wysyłki do Presty')
    } finally {
      setExportBusy(false)
    }
  }

  async function deleteProduct() {
    if (!id || !p) return
    const ok = window.confirm(
      `Usunąć ${p.sku} ${p.name} z katalogu?\n\nTej operacji nie można cofnąć.`,
    )
    if (!ok) return
    setDeleteBusy(true)
    setErr('')
    try {
      await api(`/products/${id}`, { method: 'DELETE' })
      navigate('/products')
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Błąd usuwania produktu')
      setDeleteBusy(false)
    }
  }

  async function deleteImage(imageId: number) {
    if (!id || !p) return
    const ok = window.confirm(
      'Usunąć to zdjęcie z karty?\n\nSynchronizacja z dostawcą i wzbogacanie nie dodadzą go ponownie.',
    )
    if (!ok) return
    setImageDeleteId(imageId)
    setErr('')
    try {
      const res = await api<{ images: NonNullable<Detail['images']> }>(`/products/${id}/images/${imageId}`, {
        method: 'DELETE',
      })
      setP((prev) => (prev ? { ...prev, images: res.images } : prev))
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Nie udało się usunąć zdjęcia')
    } finally {
      setImageDeleteId(null)
    }
  }

  async function openKitSuggest() {
    if (!id) return
    setKitOpen(true)
    setKitBusy(true)
    setKitErr('')
    setKitData(null)
    try {
      setKitData(await api<ProductKitSuggestions>(`/products/${id}/kit-suggestions`, { method: 'POST', body: '{}' }))
    } catch (ex) {
      setKitErr(ex instanceof Error ? ex.message : 'Nie udało się dobrać zestawu')
    } finally {
      setKitBusy(false)
    }
  }

  async function addToKit(ids: number[]) {
    if (!id || !p || ids.length === 0) return
    setKitActionBusy(true)
    setErr('')
    try {
      const res = await api<{ accessories: ProductAccessory[] }>(`/products/${id}/kit`, {
        method: 'POST',
        body: JSON.stringify({ related_product_ids: ids }),
      })
      setP({ ...p, accessories: res.accessories })
      setKitOpen(false)
    } catch (ex) {
      setKitErr(ex instanceof Error ? ex.message : 'Nie udało się dodać do zestawu')
    } finally {
      setKitActionBusy(false)
    }
  }

  async function removeFromKit(all: boolean) {
    if (!id || !p) return
    const ids = Object.entries(kitSelected)
      .filter(([, on]) => on)
      .map(([rowId]) => Number(rowId))
    if (!all && ids.length === 0) return
    setKitActionBusy(true)
    setErr('')
    try {
      const res = await api<{ accessories: ProductAccessory[] }>(`/products/${id}/kit`, {
        method: 'DELETE',
        body: JSON.stringify(all ? { all: true } : { accessory_ids: ids }),
      })
      setP({ ...p, accessories: res.accessories })
      setKitSelected({})
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Nie udało się usunąć z zestawu')
    } finally {
      setKitActionBusy(false)
    }
  }

  if (!p) return <p className="text-sm text-slate-500">Ładowanie…</p>

  const status = p.enrichment_status ?? 'none'
  const currency = p.currency?.trim() || 'PLN'
  const variants = p.variants ?? null
  const variantsBlockExport = variants !== null && variants.active_count > 0

  return (
    <div>
      <Link to="/products" className="text-xs text-blue-600 hover:underline">
        ← Produkty
      </Link>
      <div className="mt-2 flex flex-wrap items-start justify-between gap-3">
        <div>
          <div className="flex flex-wrap items-center gap-2">
            <h1 className="text-xl font-semibold">{p.name}</h1>
            <button
              type="button"
              className="rounded border border-slate-300 px-2 py-1 text-xs hover:bg-slate-50"
              title="Kopiuje nazwę, firmę i SKU, otwiera Google"
              onClick={() => {
                const q = [p.name, p.manufacturer, p.sku]
                  .map((s) => s?.trim())
                  .filter(Boolean)
                  .join(' ')
                void navigator.clipboard.writeText(q).catch(() => undefined)
                window.open(
                  `https://www.google.com/search?q=${encodeURIComponent(q)}`,
                  '_blank',
                  'noopener,noreferrer',
                )
              }}
            >
              Szukaj w Google
            </button>
          </div>
          <p className="mb-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-slate-500">
            <span>{p.sku}</span>
            {p.model_name?.trim() ? (
              <span className="font-semibold text-slate-700" title="Nazwa modelu z cennika">
                {p.model_name.trim()}
              </span>
            ) : null}
            {p.manufacturer?.trim() ? (
              <span className="rounded-md bg-teal-600 px-2 py-0.5 text-xs font-bold uppercase tracking-wide text-white">
                {p.manufacturer.trim()}
              </span>
            ) : null}
            <span>{p.norms ?? 'bez normy'}</span>
          </p>
          <div className="mb-2 flex max-w-xl flex-wrap items-center gap-2">
            <label htmlFor="product-group" className="shrink-0 text-xs font-semibold text-slate-600">
              Grupa
            </label>
            <select
              id="product-group"
              className="min-w-[16rem] flex-1 rounded border border-slate-300 bg-white px-2 py-1.5 text-xs"
              value={p.category ?? ''}
              disabled={categoryBusy}
              onChange={(e) => void saveCategory(e.target.value)}
            >
              <option value="">— brak —</option>
              {p.category
                && !categoryOptions.some((o) => o.value === p.category)
                && <option value={p.category}>{p.category}</option>}
              {categoryOptions.map((o) => (
                <option key={o.value} value={o.value}>
                  {o.label}
                </option>
              ))}
            </select>
            <span className="text-xs text-slate-500">
              {categoryBusy ? 'Zapisuję…' : categoryMsg}
            </span>
          </div>
          {canEnrich && (
            <form
              className="mb-2 flex max-w-xl flex-wrap items-center gap-2"
              onSubmit={(e) => {
                e.preventDefault()
                void persistShopSource(shopUrlDraft)
              }}
            >
              <label htmlFor="product-shop-url" className="shrink-0 text-xs font-semibold text-slate-600">
                Link do sklepu
              </label>
              <input
                id="product-shop-url"
                type="url"
                inputMode="url"
                placeholder="https://…"
                className="min-w-[16rem] flex-1 rounded border border-slate-300 bg-white px-2 py-1.5 text-xs"
                value={shopUrlDraft}
                disabled={shopUrlBusy || busy}
                onChange={(e) => {
                  setShopUrlDraft(e.target.value)
                  setShopUrlMsg('')
                }}
              />
              <button
                type="submit"
                disabled={shopUrlBusy || busy}
                className="rounded border border-slate-300 bg-white px-2 py-1.5 text-xs hover:bg-slate-50 disabled:opacity-50"
              >
                {shopUrlBusy ? 'Zapisuję…' : 'Zapisz'}
              </button>
              <span className="text-xs text-slate-500">{shopUrlMsg}</span>
            </form>
          )}
          <p className="text-xs text-slate-500">
            Opis/zdjęcia: <b>{status === 'none' && p.description_from_b2b ? 'Z B2B (opis ze sklepu dostawcy)' : (STATUS_LABEL[status] ?? status)}</b>
            {p.enriched_at ? ` · ${new Date(p.enriched_at).toLocaleString('pl-PL')}` : ''}
          </p>
        </div>
        <div className="flex flex-wrap gap-2">
          <a
            href="#cross-ref"
            className="rounded border border-emerald-600 px-3 py-2 text-xs font-semibold text-emerald-800 hover:bg-emerald-50"
          >
            Cross-ref po SKU
          </a>
          {p.substitutes?.[0]?.substitute_product_id && (
            <Link
              to={`/products/compare?a=${p.id}&b=${p.substitutes[0].substitute_product_id}`}
              className="rounded border border-slate-300 px-3 py-2 text-xs hover:bg-slate-50"
            >
              Porównaj z zamiennikiem
            </Link>
          )}
          {canEnrich && (
            <button
              type="button"
              disabled={busy || status === 'queued'}
              onClick={() =>
                void enrich(
                  status === 'done'
                    || status === 'failed'
                    || status === 'running'
                    || status === 'manual',
                )
              }
              className="rounded bg-blue-600 px-3 py-2 text-xs text-white disabled:opacity-50"
            >
              {busy
                ? 'Startuję…'
                : status === 'running'
                  ? 'Odblokuj i pobierz ponownie'
                  : status === 'done'
                    ? 'Pobierz ponownie'
                    : 'Pobierz opis i zdjęcia'}
            </button>
          )}
          {canEnrich && (
            <button
              type="button"
              disabled={prestaBusy}
              onClick={() => void searchPresta()}
              className="rounded bg-emerald-700 px-3 py-2 text-xs text-white disabled:opacity-50"
            >
              {prestaBusy ? 'Szukam…' : 'Wyszukaj w Presta'}
            </button>
          )}
          {canExportPresta && (
            <button
              type="button"
              disabled={exportBusy || variantsBlockExport}
              onClick={() => void exportPresta()}
              className="rounded bg-violet-700 px-3 py-2 text-xs text-white disabled:opacity-50"
              title={
                variantsBlockExport
                  ? 'Karta ma wersje z cenami (np. formaty znaku) — eksport do Presty nie jest obsługiwany.'
                  : 'Wysyła kartę do sklepu: opis, rozmiary, termin na zamówienie'
              }
            >
              {exportBusy
                ? 'Wysyłam…'
                : p.presta_export?.presta_id
                  ? 'Aktualizuj w Preście'
                  : 'Wyślij do Presty'}
            </button>
          )}
          {canDelete && (
            <button
              type="button"
              disabled={deleteBusy}
              onClick={() => void deleteProduct()}
              className="rounded border border-red-300 px-3 py-2 text-xs text-red-700 hover:bg-red-50 disabled:opacity-50"
              title="Usuwa tę pozycję z katalogu"
            >
              {deleteBusy ? 'Usuwam…' : 'Usuń produkt'}
            </button>
          )}
          <button
            type="button"
            disabled={kitBusy}
            onClick={() => void openKitSuggest()}
            className="rounded bg-teal-700 px-3 py-2 text-xs text-white disabled:opacity-50"
            title="AI dobiera filtry, mocowania i inne pozycje do zestawu"
          >
            Dopasuj warianty
          </button>
        </div>
      </div>

      <div id="cross-ref" className="mt-3">
        <CrossRefPanel initialCode={p.sku} />
      </div>

      {exportMsg && <p className="mt-2 rounded bg-green-50 px-3 py-2 text-xs text-green-800">{exportMsg}</p>}
      {err && <p className="mt-2 rounded bg-red-50 px-3 py-2 text-xs text-red-700">{err}</p>}
      {p.presta_export?.url && (
        <p className="mt-2 text-xs text-slate-500">
          W sklepie:{' '}
          <a href={p.presta_export.url} target="_blank" rel="noreferrer" className="text-violet-700 underline">
            karta Presta #{p.presta_export.presta_id}
          </a>
        </p>
      )}
      {p.enrichment_error && (status === 'failed' || status === 'manual') && (
        <div className="mt-2 rounded bg-red-50 px-3 py-2 text-xs text-red-700">
          <p>{p.enrichment_error}</p>
          {p.enrichment_trace?.steps && p.enrichment_trace.steps.length > 0 && (
            <details className="mt-2">
              <summary className="cursor-pointer font-medium text-red-800">
                Przebieg wyszukiwania ({p.enrichment_trace.steps.length} kroków)
              </summary>
              <ol className="mt-2 list-decimal space-y-1 pl-4 text-red-800">
                {p.enrichment_trace.steps.map((step, i) => (
                  <li key={`${step.t}-${i}`}>
                    <span className="font-medium">{TRACE_LABEL[step.t] ?? step.t}</span>
                    {': '}
                    {step.m}
                    {step.url && (
                      <>
                        {' '}
                        <a href={step.url} target="_blank" rel="noreferrer" className="underline break-all">
                          {step.url}
                        </a>
                      </>
                    )}
                    {step.urls && step.urls.length > 0 && (
                      <ul className="mt-0.5 list-disc pl-4">
                        {step.urls.map((url, j) => (
                          <li key={url}>
                            <a href={url} target="_blank" rel="noreferrer" className="underline break-all">
                              {url}
                            </a>
                            {step.why?.[j] ? <span className="text-red-600"> — {step.why[j]}</span> : null}
                          </li>
                        ))}
                      </ul>
                    )}
                  </li>
                ))}
              </ol>
            </details>
          )}
        </div>
      )}
      {batch && (batch.status === 'queued' || batch.status === 'running') && (
        <p className="mt-2 text-xs text-slate-500">
          Postęp: {batch.done + batch.failed}/{batch.total} ({batch.progress_percent}%)
        </p>
      )}

      {variants !== null ? (
        <>
          <div className="mt-4 rounded-xl bg-white p-4 shadow-sm text-sm">
            Cena konta netto:{' '}
            {variants.active_count === 0 ? (
              <b>brak — wszystkie wersje wycofane ({variants.count})</b>
            ) : variants.min_price !== null && variants.max_price !== null ? (
              <>
                <b>
                  {variants.min_price === variants.max_price
                    ? formatPrice(variants.min_price)
                    : `od ${formatPrice(variants.min_price)} do ${formatPrice(variants.max_price)}`}{' '}
                  {currencyLabel(variants.currency)} netto
                </b>
                {' · '}
                {variantCountLabel(variants.active_count)}
              </>
            ) : (
              <>
                <b>{variantCountLabel(variants.active_count)}</b>
                <span className="text-slate-500"> — brak cen do porównania (różne waluty albo wersje bez ceny)</span>
              </>
            )}
            {variants.source_label && <span className="text-slate-500"> (ceny konta {variants.source_label})</span>}
          </div>
          <p className="mt-2 rounded border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">
            Ceny są w wersjach — auto-oferta w przetargu nie przyjmie ceny tej karty; wpisz cenę wybranej wersji ręcznie.
          </p>
        </>
      ) : (
        <div className="mt-4 grid gap-3 sm:grid-cols-3">
          <div className="rounded-xl bg-white p-4 shadow-sm text-sm">
            Cena kat. netto: <b>{p.catalog_price_net} {currency}</b>
          </div>
          <div className="rounded-xl bg-white p-4 shadow-sm text-sm">
            Zakup: <b>{p.purchase_price} {currency}</b>
          </div>
          <div className="rounded-xl bg-white p-4 shadow-sm text-sm">
            Upust:{' '}
            <b>{p.discount_percent != null && p.discount_percent !== '' ? `${p.discount_percent}%` : '—'}</b>
          </div>
          <SupplierSpecialPanel product={p} className="sm:col-span-3" />
        </div>
      )}
      {(p.source_prices?.length ?? 0) > 0 && (
        <div className="mt-3 rounded-xl bg-white p-4 shadow-sm">
          <h2 className="mb-2 text-sm font-semibold">Ceny ze źródeł</h2>
          <div className="overflow-x-auto">
            <table className="w-full text-left text-xs">
              <thead>
                <tr className="border-b bg-slate-50">
                  <th className="p-2">Źródło</th>
                  <th className="p-2 text-right">Katalogowa</th>
                  <th className="p-2 text-right">Zakup</th>
                  <th className="p-2 text-right">Rabat</th>
                  <th className="p-2">Waluta</th>
                  <th className="p-2">Dostępność</th>
                  <th className="p-2">Sprawdzono</th>
                  <th className="p-2">Status</th>
                </tr>
              </thead>
              <tbody>
                {p.source_prices!.map((s) => (
                  <Fragment key={s.source_key}>
                    <tr className={s.base_price_net != null ? '' : 'border-b'}>
                      <td className="p-2" title={s.source_key}>
                        {s.source_label}
                        {s.migrated && <span className="ml-1 text-[11px] text-slate-400">(z historii cen)</span>}
                      </td>
                      <td className="whitespace-nowrap p-2 text-right tabular-nums">{formatPrice(s.catalog_price_net)}</td>
                      <td className="whitespace-nowrap p-2 text-right tabular-nums">{formatPrice(s.purchase_price)}</td>
                      <td className="whitespace-nowrap p-2 text-right tabular-nums">
                        {s.discount_percent !== null && s.discount_percent !== ''
                          ? formatPct(Number(s.discount_percent), false)
                          : '—'}
                      </td>
                      <td className="p-2">{s.currency ?? '—'}</td>
                      <td className="p-2">{s.availability ?? '—'}</td>
                      <td className="whitespace-nowrap p-2 tabular-nums">
                        {s.checked_at ? formatDateTime(s.checked_at) : '—'}
                      </td>
                      <td className="p-2">
                        {s.is_effective && (
                          <span className="rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-medium text-emerald-800">
                            obowiązuje
                          </span>
                        )}
                      </td>
                    </tr>
                    {s.base_price_net != null && (
                      <tr className="border-b">
                        <td colSpan={8} className="px-2 pb-2 pt-0">
                          <BasePriceNote slot={s} />
                        </td>
                      </tr>
                    )}
                  </Fragment>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}
      {variants === null && p.variant_summary && (
        <p className="mt-2 text-xs text-slate-600">
          Rozmiary / kody: <span className="text-slate-800">{p.variant_summary}</span>
        </p>
      )}
      <p className="mb-4 mt-2 text-xs text-slate-600">
        {p.last_price_change ? (
          <span title={priceChangeSummary(p.last_price_change, currency)}>
            Ostatnia zmiana ceny: <b>{formatDateTime(p.last_price_change.at)}</b> · {p.last_price_change.source_label}
            {' · zakup '}
            <PriceStep
              oldValue={p.last_price_change.purchase_old}
              newValue={p.last_price_change.purchase_new}
              pct={p.last_price_change.purchase_pct}
              currency={currency}
            />
            {p.last_price_change.catalog_old !== p.last_price_change.catalog_new && (
              <>
                {' · katalog '}
                <PriceStep
                  oldValue={p.last_price_change.catalog_old}
                  newValue={p.last_price_change.catalog_new}
                  pct={p.last_price_change.catalog_pct}
                  currency={currency}
                />
              </>
            )}
          </span>
        ) : (
          <span className="text-slate-400">
            {priceHistory.length > 0 ? 'Cena bez zmian od dodania.' : 'Brak historii cen.'}
          </span>
        )}
      </p>

      {variants !== null && <ProductVariantsTable key={p.id} productId={p.id} variants={variants} />}

      <div className="mb-4 rounded-xl bg-white p-4 shadow-sm">
        <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
          <div>
            <h2 className="text-sm font-semibold">Warianty i akcesoria</h2>
            <p className="mt-0.5 text-[11px] text-slate-500">
              Zielony kafelek = już w Preście. Czerwony = brak — przy eksporcie tego produktu wariant też pójdzie do sklepu.
            </p>
          </div>
          <div className="flex flex-wrap gap-2">
            <button
              type="button"
              className="rounded border border-slate-300 px-2 py-1 text-xs hover:bg-slate-50 disabled:opacity-50"
              disabled={kitActionBusy || Object.values(kitSelected).every((on) => !on)}
              onClick={() => void removeFromKit(false)}
            >
              Usuń zaznaczone
            </button>
            <button
              type="button"
              className="rounded border border-red-200 px-2 py-1 text-xs text-red-700 hover:bg-red-50 disabled:opacity-50"
              disabled={kitActionBusy || (p.accessories?.length ?? 0) === 0}
              onClick={() => void removeFromKit(true)}
            >
              Usuń wszystkie
            </button>
          </div>
        </div>
        {(p.accessories?.length ?? 0) === 0 ? (
          <p className="text-xs text-slate-500">Brak pozycji w zestawie. Kliknij „Dopasuj warianty”, żeby dobrać je z katalogu.</p>
        ) : (
          <ul className="space-y-2 text-xs">
            {p.accessories!.map((row) => (
              <li key={row.id} className="flex items-start gap-3 border-b border-slate-100 py-2 last:border-0">
                <input
                  type="checkbox"
                  className="mt-1"
                  checked={Boolean(kitSelected[row.id])}
                  onChange={() => setKitSelected((cur) => ({ ...cur, [row.id]: !cur[row.id] }))}
                  aria-label={`Zaznacz ${row.name ?? row.sku ?? 'pozycję'}`}
                />
                {row.image_url ? (
                  <img src={row.image_url} alt="" className="h-12 w-12 rounded object-contain bg-slate-50" />
                ) : (
                  <div className="h-12 w-12 rounded bg-slate-100" />
                )}
                <div className="min-w-0 flex-1">
                  {row.related_product_id ? (
                    <Link to={`/products/${row.related_product_id}`} className="font-medium text-blue-700 hover:underline">
                      {row.name || row.sku || 'Produkt'}
                    </Link>
                  ) : (
                    <span className="font-medium">{row.name || row.sku || 'Nieznany'}</span>
                  )}
                  <span className="ml-2 align-middle">
                    <PrestaKitBadge inPresta={Boolean(row.in_presta)} url={row.presta_url} prestaId={row.presta_id} />
                  </span>
                  <p className="text-slate-600">
                    {row.short_description || [row.sku, row.manufacturer].filter(Boolean).join(' · ') || '—'}
                  </p>
                  <p className="text-slate-400">
                    {row.source === 'presta' ? 'z Presty' : row.source === 'manual' ? 'ręcznie' : 'z karty'}
                    {row.matched ? '' : ' · bez karty w katalogu'}
                  </p>
                </div>
              </li>
            ))}
          </ul>
        )}
      </div>

      {(p.special_prices?.length ?? 0) > 0 && (
        <div className="mb-4 rounded-xl bg-white p-4 shadow-sm">
          <h2 className="mb-2 text-sm font-semibold">Ceny specjalne (kontrakty klientów)</h2>
          <table className="w-full text-left text-xs">
            <thead>
              <tr className="border-b bg-slate-50">
                <th className="p-2">Klient</th>
                <th className="p-2">Cena</th>
                <th className="p-2">Od</th>
                <th className="p-2">Kontrakt</th>
              </tr>
            </thead>
            <tbody>
              {p.special_prices!.map((s) => (
                <tr key={s.id} className="border-b">
                  <td className="p-2">{s.client_name}</td>
                  <td className="p-2">
                    {s.price} {s.currency}
                  </td>
                  <td className="p-2">{s.valid_from ?? '—'}</td>
                  <td className="p-2">{s.contract_ref ?? '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {priceHistory.length > 0 && (
        <div className="mb-4 rounded-xl bg-white p-4 shadow-sm">
          <h2 className="mb-2 text-sm font-semibold">Historia cen</h2>
          <div className="overflow-x-auto">
            <table className="w-full text-left text-xs">
              <thead>
                <tr className="border-b bg-slate-50">
                  <th className="p-2">Data</th>
                  <th className="p-2">Źródło</th>
                  <th className="p-2">Zakup</th>
                  <th className="p-2">Katalog</th>
                </tr>
              </thead>
              <tbody>
                {priceHistory.map((h, i) => {
                  const first = i === priceHistory.length - 1 && h.purchase_old === null && h.catalog_old === null
                  return (
                    <tr key={h.id} className="border-b">
                      <td className="whitespace-nowrap p-2">{formatDateTime(h.created_at)}</td>
                      <td className="p-2" title={h.source ?? undefined}>
                        {h.source_label}
                        {first && <span className="ml-1 text-slate-400">(dodanie ceny)</span>}
                      </td>
                      <td className="whitespace-nowrap p-2">
                        <PriceStep oldValue={h.purchase_old} newValue={h.purchase_price} pct={h.purchase_pct} currency={currency} />
                      </td>
                      <td className="whitespace-nowrap p-2">
                        <PriceStep oldValue={h.catalog_old} newValue={h.catalog_price_net} pct={h.catalog_pct} currency={currency} />
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        </div>
      )}

      <div className="mb-4 rounded-xl bg-white p-4 shadow-sm">
        <h2 className="mb-2 text-sm font-semibold">Parametry wpisane ręcznie</h2>
        <p className="mb-3 text-xs text-slate-600">
          Jedyne dane karty, których nie rusza żadna automatyka: ani import cennika, ani pobieranie
          z witryny dostawcy, ani wzbogacanie. Tu wpisz to, czego nie ma w opisie producenta, a jest
          potrzebne przy wymaganiach przetargu. Trafiają do wyszukiwania, do porównania z wymaganiem
          (z podpisem „wpisane ręcznie”) i do opisu wysyłanego do sklepu.
        </p>
        <table className="w-full text-left text-xs">
          <tbody>
            {specsRows.map((row, i) => (
              <tr key={i} className="border-b last:border-0">
                <td className="w-48 p-1">
                  <input
                    value={row.label}
                    disabled={specsBusy}
                    placeholder="Parametr, np. Tłumienie"
                    onChange={(e) =>
                      setSpecsRows(specsRows.map((r, j) => (i === j ? { ...r, label: e.target.value } : r)))
                    }
                    className="w-full rounded border border-slate-300 px-2 py-1 text-xs"
                  />
                </td>
                <td className="p-1">
                  <input
                    value={row.value}
                    disabled={specsBusy}
                    placeholder="Wartość, np. SNR 32 dB"
                    onChange={(e) =>
                      setSpecsRows(specsRows.map((r, j) => (i === j ? { ...r, value: e.target.value } : r)))
                    }
                    className="w-full rounded border border-slate-300 px-2 py-1 text-xs"
                  />
                </td>
                <td className="w-10 p-1 text-right">
                  <button
                    type="button"
                    disabled={specsBusy}
                    onClick={() => setSpecsRows(specsRows.filter((_, j) => i !== j))}
                    title="Usuń wiersz"
                    className="rounded px-2 py-1 text-xs text-slate-500 hover:bg-slate-100 disabled:opacity-50"
                  >
                    ×
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        <div className="mt-2 flex items-center gap-2">
          <button
            type="button"
            disabled={specsBusy}
            onClick={() => setSpecsRows([...specsRows, { label: '', value: '' }])}
            className="rounded-lg border border-slate-300 px-2 py-1 text-[11px] font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-50"
          >
            Dodaj wiersz
          </button>
          <button
            type="button"
            disabled={specsBusy}
            onClick={() => void saveManualSpecs(specsRows)}
            className="rounded-lg bg-slate-900 px-3 py-1 text-[11px] font-semibold text-white hover:bg-slate-800 disabled:opacity-50"
          >
            {specsBusy ? 'Zapisuję…' : 'Zapisz parametry'}
          </button>
          {specsMsg && <span className="text-[11px] text-emerald-700">{specsMsg}</span>}
        </div>
      </div>

      {p.price_list_attributes && Object.keys(p.price_list_attributes).length > 0 && (
        <div className="mb-4 rounded-xl bg-white p-4 shadow-sm">
          <h2 className="mb-2 text-sm font-semibold">Parametry z cennika dostawcy</h2>
          <p className="mb-3 text-xs text-slate-600">
            Wartości przepisane dosłownie z kolumn cennika. To dokument producenta z datą obowiązywania,
            więc przy dopasowaniu biorą górę nad tym, co wyczytano ze stron.
          </p>
          <table className="w-full text-left text-xs">
            <tbody>
              {Object.entries(p.price_list_attributes).map(([key, value]) => (
                <tr key={key} className="border-b last:border-0">
                  <th className="w-48 p-2 text-left font-medium text-slate-600">
                    {PRICE_LIST_ATTR_LABELS[key] ?? key}
                  </th>
                  <td className="p-2 text-slate-800">{value}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {(p.shop_fields?.length ?? 0) > 0 && (
        <div className="mb-4 rounded-xl bg-white p-4 shadow-sm">
          <h2 className="mb-2 text-sm font-semibold">Dane ze sklepu dostawcy</h2>
          <p className="mb-3 text-xs text-slate-600">
            Wiersze skopiowane z karty produktu u dostawcy. To nie jest opis wyrobu — karta bez opisu nadal na niego czeka.
          </p>
          <ShopFieldsTables sources={p.shop_fields!} />
        </div>
      )}

      {(p.description ||
        (p.images && p.images.length > 0) ||
        (p.documents && p.documents.length > 0) ||
        p.enrichment_payload) && (
        <div className="mb-4 min-w-0 overflow-x-hidden rounded-xl bg-white p-4 shadow-sm">
          <h2 className="mb-2 text-sm font-semibold">Opis i zdjęcia</h2>
          {p.images && p.images.length > 0 ? (
            <div className="mb-3 flex flex-wrap gap-2">
              {p.images.map((img) => (
                <div key={img.id} className="relative">
                <button
                  type="button"
                  onClick={() => setImageModalUrl(img.url)}
                  className="rounded border border-slate-200 bg-slate-50 p-0 hover:border-blue-400"
                  title="Powiększ"
                >
                  <img
                    src={img.thumb_url || img.url}
                    alt={p.name}
                    className="h-32 w-32 object-contain"
                    onError={(e) => {
                      const el = e.currentTarget
                      const next =
                        img.url && el.src !== img.url
                          ? img.url
                          : img.source_url && el.src !== img.source_url
                            ? img.source_url
                            : null
                      if (next) {
                        el.src = next
                        return
                      }
                      el.style.display = 'none'
                      setErr(`Nie można wyświetlić zdjęcia. Użyj „Pobierz ponownie”.`)
                    }}
                  />
                </button>
                {canDeleteImages && (
                  <button
                    type="button"
                    onClick={() => void deleteImage(img.id)}
                    disabled={imageDeleteId !== null}
                    className="absolute right-1 top-1 rounded bg-white/90 px-1.5 text-xs font-semibold text-red-700 shadow hover:bg-red-50 disabled:opacity-50"
                    title="Usuń zdjęcie z karty"
                    aria-label="Usuń zdjęcie z karty"
                  >
                    {imageDeleteId === img.id ? '…' : '×'}
                  </button>
                )}
                </div>
              ))}
            </div>
          ) : p.enrichment_status === 'done' ? (
            <p className="mb-3 text-xs text-amber-700">Brak zapisanego zdjęcia — użyj „Pobierz ponownie”.</p>
          ) : null}
          <DescriptionLayoutView product={p} compact />
        </div>
      )}

      {imageModalUrl && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4"
          role="dialog"
          aria-modal="true"
          onClick={() => setImageModalUrl(null)}
        >
          <div
            className="relative max-h-[90vh] max-w-[90vw]"
            onClick={(e) => e.stopPropagation()}
          >
            <button
              type="button"
              onClick={() => setImageModalUrl(null)}
              className="absolute -right-2 -top-2 rounded bg-white px-2 py-1 text-xs shadow"
            >
              Zamknij
            </button>
            <img
              src={imageModalUrl}
              alt={p.name}
              className="max-h-[85vh] max-w-[90vw] rounded bg-white object-contain"
            />
          </div>
        </div>
      )}

      <h2 className="mb-2 text-sm font-semibold">Zamienniki dla tego produktu głównego</h2>
      <div className="rounded-xl bg-white p-4 shadow-sm">
        <table className="w-full text-left text-xs">
          <thead>
            <tr className="border-b bg-slate-50">
              <th className="p-2">Kod</th>
              <th className="p-2">Nazwa</th>
              <th className="p-2">Typ</th>
              <th className="p-2">AI</th>
              <th className="p-2">Status</th>
            </tr>
          </thead>
          <tbody>
            {(p.substitutes ?? []).map((s) => (
              <tr key={s.id} className="border-b">
                <td className="p-2">{s.substitute_product?.sku}</td>
                <td className="p-2">{s.substitute_product?.name}</td>
                <td className="p-2">{s.type}</td>
                <td className="p-2">{s.match_percent}%</td>
                <td className="p-2">
                  <span>{s.approval_status}</span>
                  {s.substitute_product_id && (
                    <Link
                      to={`/products/compare?a=${p.id}&b=${s.substitute_product_id}`}
                      className="ml-2 text-[10px] font-semibold text-emerald-800 hover:underline"
                    >
                      Porównaj
                    </Link>
                  )}
                </td>
              </tr>
            ))}
            {(p.substitutes ?? []).length === 0 && (
              <tr>
                <td colSpan={5} className="p-3 text-slate-400">
                  Brak zamienników dla tego głównego.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
      <PrestaSearchModal
        open={prestaOpen}
        items={prestaItems}
        loading={prestaBusy}
        error={prestaErr}
        onClose={() => setPrestaOpen(false)}
        onApplied={() => {
          void load()
        }}
      />
      <ProductKitModal
        open={kitOpen}
        busy={kitBusy || kitActionBusy}
        error={kitErr}
        data={kitData}
        onClose={() => setKitOpen(false)}
        onAdd={(ids) => void addToKit(ids)}
      />
    </div>
  )
}
