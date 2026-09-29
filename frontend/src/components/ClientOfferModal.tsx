import { useEffect, useMemo, useRef, useState } from 'react'
import { useAuth } from '../auth'
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
import { formatPrice } from '../lib/priceChange'
import { offerMarkupFactor, productDisplayName, purchaseForOffer, suggestedOfferPrice } from '../lib/productLabel'
import { SourcePricesRanked } from './SourcePricesRanked'

type Props = {
  open: boolean
  onClose: () => void
  product: Product | null
}

const TEMPLATE_KEY = 'supon_offer_template'
/** Domyślna marża konta w bazie (users.default_margin_percent) — gdy /me jej nie podał. */
const DEFAULT_MARGIN_PERCENT = 18

function storedTemplate(): OfferTemplateId {
  try {
    const v = localStorage.getItem(TEMPLATE_KEY)
    return OFFER_TEMPLATES.some((t) => t.id === v) ? (v as OfferTemplateId) : 'classic'
  } catch {
    return 'classic'
  }
}

/**
 * Oferta dla klienta z karty: trzy układy do skopiowania jednym przyciskiem i wklejenia w e-mail. Handlowiec wpisuje tylko cenę —
 * reszta pochodzi z karty (bez cen zakupu, dostawców i danych z ich sklepów). Na górze, tylko w panelu, ceny zakupu
 * ze źródeł karty i propozycja: zakup obowiązujący × (1 + marża konta) — ten sam wzór co odpowiedź na zapytanie.
 */
export function ClientOfferModal({ open, onClose, product }: Props) {
  const { user } = useAuth()
  // Cena trzymana z kartą, dla której ją wpisano — inna karta zaczyna od swojej propozycji.
  const [priceDraft, setPriceDraft] = useState<{ productId: number; text: string } | null>(null)
  const [template, setTemplate] = useState<OfferTemplateId>(storedTemplate)
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null)
  const priceRef = useRef<HTMLInputElement>(null)
  // Pliki PDF odznaczone przez handlowca (adresy) — trzymane z kartą; inna karta zaczyna od wszystkich.
  const [skippedDocs, setSkippedDocs] = useState<{ productId: number; urls: string[] } | null>(null)

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

  // Po otwarciu kwota zaznaczona — wpisanie własnej od razu zastępuje propozycję.
  const productId = product?.id
  useEffect(() => {
    if (!open || productId == null) return
    priceRef.current?.focus()
    priceRef.current?.select()
  }, [open, productId])

  const cardData = useMemo(() => (product ? buildOfferData(product, window.location.href) : null), [product])
  const skipped = useMemo(
    () => (product && skippedDocs?.productId === product.id ? skippedDocs.urls : []),
    [product, skippedDocs],
  )
  const data = useMemo(
    () => (cardData ? { ...cardData, documents: cardData.documents.filter((d) => !skipped.includes(d.url)) } : null),
    [cardData, skipped],
  )
  const marginPercent = user?.default_margin_percent ?? DEFAULT_MARGIN_PERCENT
  const purchase = purchaseForOffer(product)
  const proposal = suggestedOfferPrice(purchase, offerMarkupFactor(marginPercent))
  const proposalText = proposal != null ? formatPrice(proposal) : ''
  // Bez wpisu handlowca oferta startuje z propozycji; wyczyszczone pole to świadomie oferta bez ceny.
  const priceText = product && priceDraft?.productId === product.id ? priceDraft.text : proposalText
  const price = parseOfferPrice(priceText)
  const priceInvalid = price != null && Number.isNaN(price)
  const offerPrice = price == null || priceInvalid ? null : price
  const date = useMemo(() => new Date(), [])
  const html = useMemo(
    () => (data ? renderOfferHtml(template, data, offerPrice, date) : ''),
    [data, template, offerPrice, date],
  )

  if (!open || !product || !data || !cardData) return null

  function toggleDoc(url: string, include: boolean) {
    if (!product) return
    const next = include ? skipped.filter((u) => u !== url) : [...skipped, url]
    setSkippedDocs({ productId: product.id, urls: next })
    setMsg(null)
  }

  function pickTemplate(id: OfferTemplateId) {
    setTemplate(id)
    setMsg(null)
    try {
      localStorage.setItem(TEMPLATE_KEY, id)
    } catch {
      // wybór układu to tylko wygoda — bez pamięci zostaje domyślny
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

        <div className="grid min-h-0 flex-1 grid-cols-1 lg:grid-cols-[20rem_1fr]">
          <div className="flex flex-col gap-4 overflow-y-auto border-b border-slate-200 bg-slate-50 p-4 lg:border-b-0 lg:border-r">
            {/* Podpowiedź tylko dla handlowca — ceny zakupu nie trafiają do oferty. */}
            <div className="space-y-2">
              <SourcePricesRanked product={product} />
              <div className="rounded border border-violet-200 bg-violet-50 px-2.5 py-2 text-xs text-slate-700">
                {proposal != null && purchase != null ? (
                  <>
                    <div className="flex items-center justify-between gap-2">
                      <span>
                        Proponowana cena: <b className="text-sm tabular-nums text-slate-900">{formatPrice(proposal)} zł</b>
                      </span>
                      {priceText !== proposalText && (
                        <button
                          type="button"
                          onClick={() => {
                            setPriceDraft({ productId: product.id, text: proposalText })
                            setMsg(null)
                          }}
                          className="shrink-0 rounded-md bg-violet-700 px-2 py-1 text-[11px] font-semibold text-white hover:bg-violet-800"
                        >
                          Przywróć
                        </button>
                      )}
                    </div>
                    <p className="mt-0.5 text-[11px] text-slate-500">
                      zakup {formatPrice(purchase)} zł + marża {formatPrice(marginPercent).replace(/,00$/, '')}% z Twojego konta
                    </p>
                  </>
                ) : (
                  <p className="text-[11px] text-slate-500">
                    Brak ceny zakupu w zł na karcie — propozycji z marżą nie da się policzyć.
                  </p>
                )}
              </div>
            </div>
            <label className="block">
              <span className="text-xs font-semibold text-slate-700">Cena netto dla klienta (zł)</span>
              <input
                type="text"
                inputMode="decimal"
                ref={priceRef}
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

            <div>
              <p className="mb-1.5 text-xs font-semibold text-slate-700">Pliki PDF w ofercie</p>
              {cardData.documents.length > 0 ? (
                <div className="flex flex-col gap-1 rounded-lg border border-slate-200 bg-white px-3 py-2">
                  {cardData.documents.map((d) => (
                    <label key={d.url} className="flex cursor-pointer items-start gap-2 text-xs text-slate-700">
                      <input
                        type="checkbox"
                        checked={!skipped.includes(d.url)}
                        onChange={(e) => toggleDoc(d.url, e.target.checked)}
                        className="mt-0.5 accent-violet-700"
                      />
                      <span className="min-w-0">
                        <span className="font-medium text-slate-900">{d.kind}</span>
                        <span className="block truncate text-[11px] text-slate-500" title={d.title}>
                          {d.title}
                        </span>
                      </span>
                    </label>
                  ))}
                </div>
              ) : (
                <p className="text-[11px] text-slate-500">Karta nie ma plików PDF.</p>
              )}
            </div>

            <div className="flex flex-col gap-1.5">
              <button
                type="button"
                disabled={priceInvalid}
                onClick={() => void copyForMail()}
                className="rounded-md bg-violet-700 px-3 py-2 text-sm font-semibold text-white hover:bg-violet-800 disabled:opacity-50"
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
