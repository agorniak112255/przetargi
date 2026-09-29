import { useEffect, useMemo, useState } from 'react'
import type { Product } from '../lib/api'
import { copyRichHtml } from '../lib/clipboard'
import {
  buildOfferData,
  OFFER_TEMPLATES,
  offerPreviewDocument,
  parseOfferPrice,
  renderOfferHtml,
  renderOfferText,
  type OfferTemplateId,
} from '../lib/clientOffer'
import { productDisplayName } from '../lib/productLabel'

type Props = {
  open: boolean
  onClose: () => void
  product: Product | null
}

const TEMPLATE_KEY = 'supon_offer_template'

function storedTemplate(): OfferTemplateId {
  try {
    const v = localStorage.getItem(TEMPLATE_KEY)
    return OFFER_TEMPLATES.some((t) => t.id === v) ? (v as OfferTemplateId) : 'classic'
  } catch {
    return 'classic'
  }
}

/**
 * Oferta dla klienta z karty: trzy układy HTML do skopiowania jednym przyciskiem. Handlowiec wpisuje tylko cenę —
 * reszta pochodzi z karty (bez cen zakupu, dostawców i danych z ich sklepów).
 */
export function ClientOfferModal({ open, onClose, product }: Props) {
  // Cena trzymana z kartą, dla której ją wpisano — inna karta zaczyna od pustego pola.
  const [priceDraft, setPriceDraft] = useState<{ productId: number; text: string } | null>(null)
  const [template, setTemplate] = useState<OfferTemplateId>(storedTemplate)
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null)

  useEffect(() => {
    if (!open) return
    // Nasłuch na window w fazie capture rusza przed nasłuchem okna weryfikacji (document) — Escape zamyka tylko ofertę.
    function onKey(e: KeyboardEvent) {
      if (e.key === 'Escape') {
        e.stopPropagation()
        onClose()
      }
    }
    window.addEventListener('keydown', onKey, true)
    return () => window.removeEventListener('keydown', onKey, true)
  }, [open, onClose])

  const data = useMemo(() => (product ? buildOfferData(product, window.location.href) : null), [product])
  const priceText = product && priceDraft?.productId === product.id ? priceDraft.text : ''
  const price = parseOfferPrice(priceText)
  const priceInvalid = price != null && Number.isNaN(price)
  const offerPrice = price == null || priceInvalid ? null : price
  const date = useMemo(() => new Date(), [])
  const html = useMemo(
    () => (data ? renderOfferHtml(template, data, offerPrice, date) : ''),
    [data, template, offerPrice, date],
  )

  if (!open || !product || !data) return null

  function pickTemplate(id: OfferTemplateId) {
    setTemplate(id)
    setMsg(null)
    try {
      localStorage.setItem(TEMPLATE_KEY, id)
    } catch {
      // wybór układu to tylko wygoda — bez pamięci zostaje domyślny
    }
  }

  async function copyCode() {
    if (priceInvalid) return
    try {
      await navigator.clipboard.writeText(html)
      setMsg({ ok: true, text: 'Skopiowano kod HTML oferty.' })
    } catch {
      setMsg({ ok: false, text: 'Nie udało się skopiować do schowka.' })
    }
  }

  async function copyForMail() {
    if (priceInvalid || !data) return
    const ok = await copyRichHtml(html, renderOfferText(data, offerPrice, date))
    setMsg(
      ok
        ? { ok: true, text: 'Skopiowano gotową ofertę — wklej ją w treść wiadomości (Ctrl+V).' }
        : { ok: false, text: 'Nie udało się skopiować do schowka.' },
    )
  }

  const missing = [
    data.images.length === 0 ? 'zdjęć' : '',
    data.paragraphs.length === 0 ? 'opisu' : '',
    data.params.length + data.notes.length === 0 ? 'parametrów' : '',
  ].filter(Boolean)

  return (
    <div
      className="fixed inset-0 z-[80] flex items-center justify-center bg-slate-950/60 p-3 sm:p-6"
      role="dialog"
      aria-modal="true"
      aria-labelledby="client-offer-title"
      // Klik w tło nie może dojść do tła okna pod spodem — zamknąłby oba.
      onClick={(e) => {
        e.stopPropagation()
        onClose()
      }}
    >
      <div
        className="flex h-[94vh] w-full max-w-6xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="flex items-start justify-between gap-3 border-b border-slate-200 px-5 py-3">
          <div className="min-w-0">
            <p className="text-xs font-medium uppercase tracking-wide text-violet-700">Oferta dla klienta</p>
            <h2 id="client-offer-title" className="truncate text-base font-semibold text-slate-900">
              {productDisplayName(product, 160)}
            </h2>
          </div>
          <button
            type="button"
            onClick={onClose}
            className="shrink-0 rounded-md border border-slate-300 px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50"
          >
            Zamknij
          </button>
        </div>

        <div className="grid min-h-0 flex-1 grid-cols-1 lg:grid-cols-[18rem_1fr]">
          <div className="flex flex-col gap-4 overflow-y-auto border-b border-slate-200 bg-slate-50 p-4 lg:border-b-0 lg:border-r">
            <label className="block">
              <span className="text-xs font-semibold text-slate-700">Cena netto dla klienta (zł)</span>
              <input
                type="text"
                inputMode="decimal"
                autoFocus
                value={priceText}
                onChange={(e) => {
                  setPriceDraft({ productId: product.id, text: e.target.value })
                  setMsg(null)
                }}
                placeholder="np. 29,90"
                className={`mt-1 w-full rounded-md border bg-white px-2.5 py-2 text-base font-semibold tabular-nums focus:outline-none focus:ring-2 ${
                  priceInvalid
                    ? 'border-rose-400 focus:ring-rose-200'
                    : 'border-slate-300 focus:border-violet-400 focus:ring-violet-200'
                }`}
              />
              <span className={`mt-1 block text-[11px] ${priceInvalid ? 'text-rose-700' : 'text-slate-500'}`}>
                {priceInvalid
                  ? 'Wpisz kwotę większą od zera, np. 29,90.'
                  : 'Puste pole = oferta bez ceny.'}
              </span>
            </label>

            <div>
              <p className="mb-1.5 text-xs font-semibold text-slate-700">Wygląd oferty</p>
              <div className="flex flex-col gap-1.5">
                {OFFER_TEMPLATES.map((t) => (
                  <button
                    key={t.id}
                    type="button"
                    onClick={() => pickTemplate(t.id)}
                    aria-pressed={t.id === template}
                    className={`rounded-lg border px-3 py-2 text-left ${
                      t.id === template
                        ? 'border-violet-500 bg-violet-50 ring-2 ring-violet-200'
                        : 'border-slate-200 bg-white hover:bg-slate-100'
                    }`}
                  >
                    <span className="block text-sm font-semibold text-slate-900">{t.label}</span>
                    <span className="block text-[11px] leading-snug text-slate-500">{t.hint}</span>
                  </button>
                ))}
              </div>
            </div>

            <div className="flex flex-col gap-1.5">
              <button
                type="button"
                disabled={priceInvalid}
                onClick={() => void copyCode()}
                className="rounded-md bg-violet-700 px-3 py-2 text-sm font-semibold text-white hover:bg-violet-800 disabled:opacity-50"
              >
                Kopiuj kod HTML
              </button>
              <button
                type="button"
                disabled={priceInvalid}
                onClick={() => void copyForMail()}
                className="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-medium text-slate-700 hover:bg-slate-100 disabled:opacity-50"
                title="Kopiuje gotowy wygląd — do wklejenia wprost w treść e-maila"
              >
                Kopiuj do wklejenia w e-mail
              </button>
              {msg && (
                <p className={`text-xs ${msg.ok ? 'text-emerald-700' : 'text-rose-700'}`} role="status">
                  {msg.text}
                </p>
              )}
            </div>

            <div className="mt-auto space-y-1 text-[11px] leading-snug text-slate-500">
              {missing.length > 0 && (
                <p className="rounded-md border border-amber-200 bg-amber-50 px-2 py-1.5 text-amber-900">
                  Karta nie ma {missing.join(', ')} — oferta pokaże tylko to, co jest w karcie.
                </p>
              )}
              <p>Dane z karty. Bez cen zakupu, dostawców i danych z ich sklepów.</p>
            </div>
          </div>

          <div className="min-h-0 bg-slate-100 p-3">
            <iframe
              title="Podgląd oferty"
              srcDoc={offerPreviewDocument(html)}
              // bez skryptów i formularzy — sam podgląd
              sandbox="allow-popups allow-popups-to-escape-sandbox"
              className="h-full w-full rounded-lg border border-slate-200 bg-white"
            />
          </div>
        </div>
      </div>
    </div>
  )
}
