import { type ReactNode, useCallback, useEffect, useRef, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useAuth } from '../auth'
import {
  BTN,
  BTN_PRIMARY,
  BTN_SM,
  Chip,
  ConfirmDialog,
  ErrorBar,
  INPUT,
  Modal,
  type ChipTone,
} from '../components/CampaignsUi'
import { CardPickerModal } from '../components/CardPickerModal'
import { api, ApiError, can } from '../lib/api'
import { errorText, fmtDate, fmtDateTime, fmtQty, moneyInputValue, parseMoney } from '../lib/campaignFormat'
import {
  CAMPAIGN_LAYOUT_HINT,
  CAMPAIGN_LAYOUT_LABEL,
  LAYOUTS_WITH_DESCRIPTION,
  formatPln,
  type CampaignLayout,
} from '../lib/campaigns'
import { copyRichHtml } from '../lib/clipboard'
import {
  addOfferItems,
  deleteOffer,
  getOffer,
  markOfferCopied,
  offerPreview,
  offerSentMail,
  parseEmails,
  removeOfferItem,
  sendOffer,
  updateOffer,
  updateOfferItem,
  type Offer,
  type OfferItem,
  type OfferItemPatch,
  type OfferPatch,
  type OfferPreview,
  type OfferRecipientStatus,
  type OfferSend,
  type OfferSendResult,
  type OfferSentMail,
} from '../lib/offers'
import { plural } from '../lib/plural'
import { useSerialAutosave } from '../lib/useSerialAutosave'

/**
 * Oferta /oferty/:id — jedna strona: produkty z ceną netto, treść maila, podgląd, wysyłka (osobny mail do każdego
 * adresu ze skrzynki „Moja poczta”) albo kopia do wklejenia w Thunderbirdzie, historia wysyłek. Oferta jest zawsze
 * edytowalna; każda wysyłka zapisuje na serwerze dokładnie to, co dostał klient.
 */

const CONTENT_SAVE_MS = 600
/** Po zmianie oferty podgląd odświeża się sam po chwili bez zmian (zapis treści idzie po CONTENT_SAVE_MS). */
const PREVIEW_REFRESH_MS = 900
const LAYOUTS = Object.keys(CAMPAIGN_LAYOUT_LABEL) as CampaignLayout[]
/** Wstępne sprawdzenie adresu w przeglądarce — dokładnie sprawdza serwer. */
const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
/** Jak przy ofercie z karty (ClientOfferModal): ok. 40 s czekania, aż dodatek Thunderbirda podejmie prośbę. */
const WATCH_TRIES = 20
const WATCH_EVERY_MS = 2000
const ADDON_STATUS_EVERY_MS = 20000
const EMPTY_EMAILS = 'Wpisz co najmniej jeden adres.'

const RECIPIENT_STATUS: Record<OfferRecipientStatus, { label: string; tone: ChipTone }> = {
  sent: { label: 'wysłano', tone: 'green' },
  failed: { label: 'błąd', tone: 'red' },
  skipped: { label: 'pominięto', tone: 'slate' },
}

type Content = { subject: string; intro: string; layout: CampaignLayout; valid_until: string }

function contentOf(o: Offer): Content {
  return { subject: o.subject, intro: o.intro ?? '', layout: o.layout, valid_until: o.valid_until ?? '' }
}

function contentPatch(patch: Partial<Content>): OfferPatch {
  const out: OfferPatch = {}
  if (patch.subject !== undefined) out.subject = patch.subject
  if (patch.intro !== undefined) out.intro = patch.intro.trim() === '' ? null : patch.intro
  if (patch.layout !== undefined) out.layout = patch.layout
  if (patch.valid_until !== undefined) out.valid_until = patch.valid_until === '' ? null : patch.valid_until
  return out
}

/**
 * Komunikat błędu z serwera. Przy 422 wszystkie powody z `errors` (np. `items` — pozycje bez ceny, `emails` — adresy
 * wypisane z mailingu), bo `message` Laravela pokazuje tylko pierwszy z dopiskiem „i jeszcze N”.
 */
function apiErrorText(ex: unknown, fallback: string): string {
  // limit żądań (np. wysyłki: 5 na minutę) — serwer odpowiada po angielsku „Too Many Attempts.”
  if (ex instanceof ApiError && ex.status === 429) return 'Za dużo prób w krótkim czasie — odczekaj minutę i spróbuj ponownie.'
  if (ex instanceof ApiError && ex.body.errors && typeof ex.body.errors === 'object') {
    const lines = Object.values(ex.body.errors as Record<string, unknown>)
      .flat()
      .filter((v): v is string => typeof v === 'string' && v.trim() !== '')
    if (lines.length > 0) return lines.join(' ')
  }
  return errorText(ex, fallback)
}

export function OfferEditor() {
  const { id } = useParams()
  // inna oferta = świeży stan strony, bez szkiców poprzedniej
  return <OfferEditorPage key={id} offerId={Number(id)} />
}

function OfferEditorPage({ offerId }: { offerId: number }) {
  const [offer, setOffer] = useState<Offer | null>(null)
  const [loadErr, setLoadErr] = useState('')

  useEffect(() => {
    if (!Number.isFinite(offerId) || offerId <= 0) {
      setLoadErr('Zły numer oferty.')
      return
    }
    let cancelled = false
    getOffer(offerId)
      .then((o) => {
        if (!cancelled) setOffer(o)
      })
      .catch((ex: unknown) => {
        if (!cancelled) setLoadErr(errorText(ex, 'Nie udało się wczytać oferty.'))
      })
    return () => {
      cancelled = true
    }
  }, [offerId])

  if (!offer) {
    return (
      <div>
        <Link to="/oferty" className="app-back text-xs text-blue-600 hover:underline">
          ← Oferty
        </Link>
        {loadErr ? (
          <div className="mt-3">
            <ErrorBar message={loadErr} />
          </div>
        ) : (
          <p className="mt-3 text-sm text-slate-500">Ładowanie…</p>
        )}
      </div>
    )
  }
  return <Editor initial={offer} />
}

type Mutate = (run: () => Promise<Offer>, fallback: string) => Promise<Offer | null>

function Editor({ initial }: { initial: Offer }) {
  const navigate = useNavigate()
  const [offer, setOffer] = useState<Offer>(initial)
  const [err, setErr] = useState('')
  const [saving, setSaving] = useState(0)
  const [savedAt, setSavedAt] = useState<Date | null>(null)
  const [deleteOpen, setDeleteOpen] = useState(false)
  const [deleteBusy, setDeleteBusy] = useState(false)
  const [deleteErr, setDeleteErr] = useState('')
  // Kolejność odpowiedzi: stara odpowiedź (np. wolny zapis ceny) nie nadpisuje nowszej.
  const mutationSeq = useRef(0)
  const offerId = initial.id

  // Licznik zmian oferty — podgląd wczytany przy innym liczniku jest nieaktualny (kopiowanie czeka na świeży).
  const tickRef = useRef(0)
  const [tick, setTick] = useState(0)
  const bump = useCallback(() => {
    tickRef.current += 1
    setTick(tickRef.current)
  }, [])

  /** Każda zmiana oferty zwraca pełną ofertę — podmieniamy stan, jeśli to najnowsza odpowiedź. */
  const mutate: Mutate = useCallback(
    async (run, fallback) => {
      const my = ++mutationSeq.current
      setSaving((n) => n + 1)
      // podgląd jest nieaktualny od chwili wysłania zmiany, nie od odpowiedzi — inaczej „Kopiuj” zaraz po zmianie
      // ceny skopiowałby stary mail
      bump()
      try {
        const o = await run()
        if (my === mutationSeq.current) setOffer(o)
        setSavedAt(new Date())
        bump()
        return o
      } catch (ex) {
        setErr(apiErrorText(ex, fallback))
        return null
      } finally {
        setSaving((n) => n - 1)
      }
    },
    [bump],
  )

  // Treść: szkic w polach, zapis po CONTENT_SAVE_MS bez pisania, przy wyjściu z pola i przed podglądem/wysyłką.
  const [content, setContent] = useState<Content>(() => contentOf(initial))
  // Pola, których zapis się nie udał — dołączane do następnego zapisu; wysyłka i kopiowanie czekają na ich zapis
  // (autozapis zdejmuje zmiany z kolejki przed wysłaniem, więc bez tego nieudany temat przepadłby po cichu).
  const failedContent = useRef<Partial<Content>>({})
  const [contentFailed, setContentFailed] = useState(false)
  const saveContent = useCallback(
    async (patch: Partial<Content>) => {
      const full = { ...failedContent.current, ...patch }
      if (Object.keys(full).length === 0) return null
      const o = await mutate(() => updateOffer(offerId, contentPatch(full)), 'Nie udało się zapisać treści.')
      failedContent.current = o === null ? full : {}
      setContentFailed(o === null)
      return o
    },
    [mutate, offerId],
  )
  const autosave = useSerialAutosave<Content>(saveContent, CONTENT_SAVE_MS)
  const flush = autosave.flush
  const enqueue = autosave.enqueue
  /** Zapisuje czekającą treść (także po wcześniejszym błędzie); false = treść nie jest zapisana na serwerze. */
  const ensureSaved = useCallback(async (): Promise<boolean> => {
    await flush()
    if (Object.keys(failedContent.current).length > 0) await enqueue(() => saveContent({}))
    return Object.keys(failedContent.current).length === 0
  }, [flush, enqueue, saveContent])

  function editContent(patch: Partial<Content>, immediate = false) {
    setContent((c) => ({ ...c, ...patch }))
    autosave.edit(patch, immediate)
    bump()
  }

  // Podgląd: HTML z serwera (ten sam renderer co wysyłka), odświeżany sam po zmianach.
  const [preview, setPreview] = useState<{ data: OfferPreview; tick: number } | null>(null)
  const [previewLoading, setPreviewLoading] = useState(false)
  const [previewErr, setPreviewErr] = useState('')
  const previewSeq = useRef(0)
  const previewTickRef = useRef(-1)

  const refreshPreview = useCallback(async () => {
    const my = ++previewSeq.current
    setPreviewLoading(true)
    try {
      await flush()
      const at = tickRef.current
      const data = await offerPreview(offerId)
      if (my !== previewSeq.current) return
      previewTickRef.current = at
      setPreview({ data, tick: at })
      setPreviewErr('')
    } catch (ex) {
      if (my === previewSeq.current) setPreviewErr(apiErrorText(ex, 'Nie udało się przygotować podglądu.'))
    } finally {
      if (my === previewSeq.current) setPreviewLoading(false)
    }
  }, [flush, offerId])

  useEffect(() => {
    const t = window.setTimeout(
      () => {
        if (previewTickRef.current !== tickRef.current) void refreshPreview()
      },
      previewTickRef.current < 0 ? 0 : PREVIEW_REFRESH_MS,
    )
    return () => window.clearTimeout(t)
  }, [tick, refreshPreview])

  const previewFresh = preview !== null && preview.tick === tick && !previewLoading

  async function confirmDelete() {
    setDeleteBusy(true)
    setDeleteErr('')
    try {
      autosave.discard(['subject', 'intro', 'layout', 'valid_until'])
      await deleteOffer(offerId)
      navigate('/oferty')
    } catch (ex) {
      setDeleteErr(apiErrorText(ex, 'Nie udało się usunąć oferty.'))
      setDeleteBusy(false)
    }
  }

  const title = content.subject.trim() || 'Oferta bez tematu'

  return (
    <div>
      <Link to="/oferty" className="app-back text-xs text-blue-600 hover:underline">
        ← Oferty
      </Link>
      <div className="app-page-head mb-4 mt-1 flex flex-wrap items-end justify-between gap-3">
        <div className="min-w-0 flex-1">
          <h1 className="app-page-title truncate text-xl font-semibold">{title}</h1>
          <p className="mt-1 flex flex-wrap items-center gap-2 text-xs text-slate-600">
            {offer.code && <span className="app-code font-mono">{offer.code}</span>}
            <span>
              {offer.items.length} {plural(offer.items.length, 'pozycja', 'pozycje', 'pozycji')}
            </span>
            {offer.last_sent_at && <span>· ostatnia wysyłka {fmtDateTime(offer.last_sent_at)}</span>}
            {offer.last_copied_at && <span>· skopiowana {fmtDateTime(offer.last_copied_at)}</span>}
            <span className="text-slate-500">
              ·{' '}
              {saving > 0
                ? 'zapisuję…'
                : savedAt
                  ? `zapisano ${savedAt.toLocaleTimeString('pl-PL', { hour: '2-digit', minute: '2-digit' })}`
                  : 'zapisuje się sama'}
            </span>
          </p>
        </div>
        <button
          type="button"
          className={`${BTN} text-red-700`}
          onClick={() => {
            setDeleteErr('')
            setDeleteOpen(true)
          }}
        >
          Usuń ofertę
        </button>
      </div>

      <ErrorBar message={err} onClose={() => setErr('')} />

      <ItemsSection offer={offer} layout={content.layout} mutate={mutate} onError={setErr} />

      <div className="mt-4 grid items-start gap-4 xl:grid-cols-[minmax(0,1fr)_minmax(0,700px)]">
        <div className="space-y-4">
          <ContentSection content={content} onEdit={editContent} onBlur={() => void flush()} />
          <SendSection
            offer={offer}
            subject={content.subject}
            preview={preview?.data ?? null}
            previewFresh={previewFresh}
            saving={saving > 0}
            contentFailed={contentFailed}
            ensureSaved={ensureSaved}
            onSent={(o) => {
              // z wyniku wysyłki tylko jej pola — zmiana ceny zapisana w trakcie wysyłki zostaje na ekranie
              setOffer((cur) => ({ ...cur, sends: o.sends, last_sent_at: o.last_sent_at }))
            }}
            onCopied={() => setOffer((o) => ({ ...o, last_copied_at: new Date().toISOString() }))}
          />
          <HistorySection offer={offer} />
        </div>
        <PreviewSection
          preview={preview?.data ?? null}
          fresh={previewFresh}
          loading={previewLoading}
          error={previewErr}
          onRefresh={() => void refreshPreview()}
          missingPrices={
            <MissingPrices
              items={offer.items}
              onPatch={(item, price) =>
                mutate(() => updateOfferItem(offerId, item.id, { price_net: price }), 'Nie udało się zapisać pozycji.')
              }
              onInvalid={setErr}
            />
          }
        />
      </div>

      {deleteOpen && (
        <ConfirmDialog
          title="Usunąć ofertę?"
          danger
          confirmLabel="Usuń ofertę"
          busy={deleteBusy}
          error={deleteErr}
          onClose={() => setDeleteOpen(false)}
          onConfirm={() => void confirmDelete()}
          message={
            <>
              <p>
                <b>
                  {offer.code ? `${offer.code} ` : ''}
                  {title}
                </b>{' '}
                zniknie z listy ofert razem z historią wysyłek. Tego nie da się cofnąć.
              </p>
              {offer.sends.length > 0 && <p className="text-xs text-slate-600">Maile, które już wyszły, zostają u klientów.</p>}
            </>
          }
        />
      )}
    </div>
  )
}

/* ---------- Produkty ---------- */

const ITEMS_SECTION_ID = 'offer-items'

/** Pozycja trafia do maila i nie ma ceny — ta sama reguła co blokada wysyłki (SendSection). */
function lacksPrice(item: OfferItem): boolean {
  return item.price_net == null && (item.erp_item_id != null || item.product_id != null)
}

function ItemsSection({
  offer,
  layout,
  mutate,
  onError,
}: {
  offer: Offer
  layout: CampaignLayout
  mutate: Mutate
  onError: (message: string) => void
}) {
  const { user } = useAuth()
  const [pickerOpen, setPickerOpen] = useState(false)
  const [pickerNotice, setPickerNotice] = useState('')
  const [pickerErr, setPickerErr] = useState('')
  const items = offer.items
  const max = offer.limits.max_items
  const full = items.length >= max
  const canSearch = can(user, 'products.view')
  const canInventory = can(user, 'inventory.view') || can(user, 'campaigns.use')
  const margin = user?.default_margin_percent
  const layoutShowsDescription = LAYOUTS_WITH_DESCRIPTION.includes(layout)

  const patchItem = (item: OfferItem, patch: OfferItemPatch) =>
    mutate(() => updateOfferItem(offer.id, item.id, patch), 'Nie udało się zapisać pozycji.')

  const inOffer = new Set(items.map((i) => i.product_id).filter((id): id is number => id !== null))

  function openPicker() {
    setPickerNotice('')
    setPickerErr('')
    setPickerOpen(true)
  }

  /** Okno zostaje otwarte — można dodać kilka produktów pod rząd. */
  async function addProduct(productId: number, sku: string): Promise<boolean> {
    setPickerErr('')
    setPickerNotice('')
    const before = items.length
    const o = await mutate(() => addOfferItems(offer.id, { product_ids: [productId] }), 'Nie udało się dodać produktu.')
    if (o === null) {
      setPickerErr('Nie udało się dodać produktu — spróbuj ponownie.')
      return false
    }
    // serwer pomija kartę, której towar XL już jest w ofercie
    setPickerNotice(
      o.items.length > before
        ? `Dodano ${sku} do oferty (${o.items.length} z ${o.limits.max_items}). Możesz dodać kolejny produkt albo zamknąć okno.`
        : `${sku} jest już w ofercie (jako ten sam towar z XL) — nic nie dodano.`,
    )
    return true
  }

  const noPrice = items.filter((i) => i.warnings.no_price && (i.erp_item_id != null || i.product_id != null)).length
  const belowCost = items.filter((i) => i.warnings.below_cost).length
  const noImage = items.filter((i) => i.warnings.no_image).length

  return (
    <div id={ITEMS_SECTION_ID} className="scroll-mt-4 rounded-xl bg-white shadow-sm">
      <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-4 py-2.5">
        <div className="flex flex-wrap items-baseline gap-2">
          <h2 className="app-card-title text-sm font-semibold text-slate-900">Produkty w ofercie</h2>
          <span className="text-xs tabular-nums text-slate-500">
            {items.length} z {max}
          </span>
          {noPrice > 0 && <Chip tone="red">bez ceny: {noPrice}</Chip>}
          {belowCost > 0 && <Chip tone="amber">poniżej kosztu: {belowCost}</Chip>}
          {noImage > 0 && <Chip tone="amber">bez zdjęcia: {noImage}</Chip>}
        </div>
        <div className="flex flex-wrap items-center gap-2 text-xs text-slate-600">
          {canSearch && (
            <button type="button" className={BTN_PRIMARY} disabled={full} onClick={openPicker}>
              + Dodaj produkt
            </button>
          )}
          {items.length > 0 && (canSearch || canInventory) && (
            <>
              <span>albo zaznacz w</span>
              {canSearch && (
                <Link to={`/products?oferta=${offer.id}`} className={`${BTN_SM} inline-block`}>
                  Produktach
                </Link>
              )}
              {canInventory && (
                <Link to={`/zapasy?oferta=${offer.id}`} className={`${BTN_SM} inline-block`}>
                  Zapasach
                </Link>
              )}
            </>
          )}
          {!canSearch && (
            <span className="text-slate-500">Wyszukiwanie kart wymaga uprawnienia „Produkty — podgląd”.</span>
          )}
        </div>
      </div>
      <div className="overflow-x-auto">
        <table className="w-full text-left text-xs">
          <thead>
            <tr className="border-b bg-slate-50">
              <th className="p-2">Produkt</th>
              <th className="p-2 text-right">Stan</th>
              <th className="p-2 text-right">Koszt zakupu</th>
              <th className="p-2 text-right">Cena netto w ofercie</th>
              <th className="p-2 text-center">Kolejność</th>
              <th className="p-2" />
            </tr>
          </thead>
          <tbody>
            {items.map((item, idx) => (
              <ItemRow
                key={item.id}
                item={item}
                margin={margin}
                layoutShowsDescription={layoutShowsDescription}
                onPatch={(p) => patchItem(item, p)}
                onMove={
                  // pozycja = miejsce na liście liczone od 1 (jak przesuwanie pozycji kampanii)
                  (dir) => void patchItem(item, { position: idx + 1 + dir })
                }
                canUp={idx > 0}
                canDown={idx < items.length - 1}
                onRemove={() =>
                  void mutate(() => removeOfferItem(offer.id, item.id), 'Nie udało się usunąć pozycji.')
                }
                onInvalid={onError}
              />
            ))}
            {items.length === 0 && (
              <tr>
                <td colSpan={6} className="p-6">
                  <div className="mx-auto max-w-xl text-center">
                    <p className="text-sm font-medium text-slate-700">Oferta nie ma jeszcze produktów — dodaj je:</p>
                    <div className="mt-3 flex flex-wrap justify-center gap-2">
                      {canSearch && (
                        <button type="button" className={BTN_PRIMARY} onClick={openPicker}>
                          Wyszukaj kartę produktu
                        </button>
                      )}
                      {canSearch && (
                        <Link to={`/products?oferta=${offer.id}`} className={`${BTN} inline-block`}>
                          Wybierz w Produktach
                        </Link>
                      )}
                      {canInventory && (
                        <Link to={`/zapasy?oferta=${offer.id}`} className={`${BTN} inline-block`}>
                          Wybierz w Zapasach
                        </Link>
                      )}
                    </div>
                    <p className="mt-3 text-[11px] text-slate-500">
                      „Wyszukaj kartę produktu” otwiera okno z wyszukiwarką, podglądem karty i opisem. W Produktach
                      i Zapasach zaznacz pozycje kwadracikami po lewej i kliknij „Dodaj do {offer.code ?? 'oferty'}” na
                      belce u góry listy.
                    </p>
                  </div>
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
      <p className="px-4 py-3 text-[11px] text-slate-500">
        Koszt zakupu i cena sugerowana są widoczne tylko tutaj — klient widzi wyłącznie cenę netto w ofercie. Sugerowana =
        koszt zakupu (towar z XL: średni koszt partii; karta: cena zakupu w zł) plus domyślna marża Twojego konta. Wysłać
        i skopiować ofertę można dopiero, gdy każda pozycja ma cenę. Najwyżej {max} pozycji.
        {full && <b className="font-medium text-amber-800"> Oferta ma już najwięcej pozycji.</b>}
      </p>
      {pickerOpen && (
        <CardPickerModal
          title={`Dodaj produkt do oferty ${offer.code ?? ''}`.trim()}
          header={
            <p className="mt-0.5 text-sm text-slate-800">
              <span className="font-medium">{offer.subject.trim() || 'Oferta bez tematu'}</span>
              <span className="text-slate-500">
                {' '}
                · {items.length} z {max} pozycji
              </span>
            </p>
          }
          emptyHint="Wpisz co najmniej 2 znaki — nazwę, kod, model albo producenta. Każde kolejne słowo zawęża listę."
          badgesFor={(id) =>
            inOffer.has(id) ? (
              <span className="rounded bg-emerald-50 px-1.5 text-[10px] text-emerald-800">już w ofercie</span>
            ) : null
          }
          canSubmit={(pick) => !inOffer.has(pick.id) && !full}
          notice={pickerNotice && <p className="rounded bg-emerald-50 px-2 py-1 text-emerald-800">{pickerNotice}</p>}
          error={pickerErr || (full ? `Oferta ma już ${max} pozycji — więcej się nie zmieści.` : '')}
          placeholder="Wpisz nazwę, kod, model albo producenta — każde kolejne słowo zawęża listę"
          ariaLabel="Szukaj karty produktu po nazwie, kodzie, modelu albo producencie"
          footerHint="Cena w ofercie = koszt zakupu + Twoja marża (zmienisz ją w tabeli). Okno zostaje otwarte — możesz dodać kilka produktów. ↑ ↓ wybór · dwuklik = pełny podgląd · Ctrl+Enter = dodaj"
          submitLabel={(pick, busy) =>
            busy ? 'Dodaję…' : pick ? (inOffer.has(pick.id) ? 'Już w ofercie' : `Dodaj ${pick.sku} do oferty`) : 'Wybierz kartę z listy'
          }
          previewQuery=""
          onClose={() => setPickerOpen(false)}
          onPick={addProduct}
        />
      )}
    </div>
  )
}

function Thumb({ url }: { url: string | null }) {
  return url ? (
    <img src={url} alt="" className="h-10 w-10 shrink-0 rounded border border-slate-200 bg-white object-contain" />
  ) : (
    <span className="grid h-10 w-10 shrink-0 place-items-center rounded border border-dashed border-slate-300 text-center text-[9px] leading-tight text-slate-400">
      brak
      <br />
      zdjęcia
    </span>
  )
}

function ItemRow({
  item,
  margin,
  layoutShowsDescription,
  onPatch,
  onMove,
  canUp,
  canDown,
  onRemove,
  onInvalid,
}: {
  item: OfferItem
  margin: number | undefined
  layoutShowsDescription: boolean
  onPatch: (patch: OfferItemPatch) => Promise<Offer | null>
  onMove: (dir: -1 | 1) => void
  canUp: boolean
  canDown: boolean
  onRemove: () => void
  onInvalid: (message: string) => void
}) {
  const w = item.warnings
  const gone = item.erp_item_id == null && item.product_id == null && item.card == null
  return (
    <tr className="border-b align-top">
      <td className="min-w-[20rem] p-2">
        <div className="flex gap-2.5">
          <Thumb url={item.image_url ?? item.card?.thumb_url ?? null} />
          <div className="min-w-0">
            <b className="block font-medium text-slate-900">{item.name || <span className="italic text-slate-500">bez nazwy</span>}</b>
            <span className="text-[11px] text-slate-500">
              Kod produktu: <span className="font-mono">{item.code || '—'}</span>
            </span>
            {item.card && (
              <span className="ml-2 text-[11px] text-slate-500">
                Karta:{' '}
                <Link to={`/products/${item.card.id}`} className="font-mono text-blue-600 hover:underline">
                  {item.card.sku}
                </Link>
              </span>
            )}
            <div className="mt-1 flex flex-wrap gap-1">
              {w.no_price && <Chip tone="red">brak ceny</Chip>}
              {w.below_cost && (
                <Chip tone="amber">
                  cena poniżej kosztu zakupu{item.unit_cost != null ? ` (${formatPln(item.unit_cost)})` : ''}
                </Chip>
              )}
              {w.no_image && <Chip tone="amber">bez zdjęcia</Chip>}
              {gone && (
                <Chip tone="red" title="Towaru nie ma już w XL ani karty w katalogu">
                  brak towaru i karty
                </Chip>
              )}
            </div>
            <ItemTextLine
              label="Uwaga w mailu"
              hint="krótka linia pod nazwą, np. rozmiary albo termin dostawy"
              value={item.note}
              fallback={null}
              emptyText="brak"
              ariaName={item.name}
              onCommit={(next) => onPatch({ note: next })}
            />
            <ItemTextLine
              label="Opis w mailu"
              hint={layoutShowsDescription ? undefined : 'obecny układ go nie pokazuje'}
              value={item.description}
              fallback={item.card_excerpt}
              emptyText={item.card ? 'brak — karta nie ma opisu' : 'brak — pozycja nie ma karty'}
              ariaName={item.name}
              onCommit={(next) => onPatch({ description: next })}
            />
          </div>
        </div>
      </td>
      <td className="whitespace-nowrap p-2 text-right tabular-nums">
        {item.stock != null ? (
          <span className="font-medium text-slate-800">{fmtQty(item.stock, item.unit)}</span>
        ) : (
          <span className="text-slate-400" title="Pozycja bez towaru w XL — stanu nie znamy">
            —
          </span>
        )}
      </td>
      <td className="whitespace-nowrap p-2 text-right tabular-nums text-slate-700">
        {item.unit_cost != null ? formatPln(item.unit_cost) : <span className="text-slate-400">—</span>}
      </td>
      <td className="whitespace-nowrap p-2 text-right">
        <MoneyInput
          value={item.price_net}
          label={`Cena netto w ofercie: ${item.name}`}
          onCommit={(v) => onPatch({ price_net: v }).then(Boolean)}
          onInvalid={() => onInvalid('Cena netto: wpisz kwotę, np. 89,00.')}
        />
        {item.suggested_price != null ? (
          <div className="mt-0.5 text-[10px] text-slate-500">
            sugerowana {formatPln(item.suggested_price)}
            {margin != null ? ` (koszt + ${margin.toLocaleString('pl-PL')}%)` : ''}
            {item.price_net !== item.suggested_price && (
              <button
                type="button"
                className="ml-1 text-blue-600 hover:underline"
                onClick={() => void onPatch({ price_net: item.suggested_price })}
              >
                wstaw sugerowaną
              </button>
            )}
          </div>
        ) : (
          <div className="mt-0.5 text-[10px] text-slate-500">brak kosztu — bez ceny sugerowanej</div>
        )}
      </td>
      <td className="whitespace-nowrap p-2 text-center">
        <span className="inline-flex gap-1">
          <button
            type="button"
            className={BTN_SM}
            disabled={!canUp}
            aria-label={`W górę: ${item.name}`}
            title="Przesuń w górę"
            onClick={() => onMove(-1)}
          >
            ↑
          </button>
          <button
            type="button"
            className={BTN_SM}
            disabled={!canDown}
            aria-label={`W dół: ${item.name}`}
            title="Przesuń w dół"
            onClick={() => onMove(1)}
          >
            ↓
          </button>
        </span>
      </td>
      <td className="p-2 text-right">
        <button type="button" className={BTN_SM} aria-label={`Usuń z oferty: ${item.name}`} title="Usuń z oferty" onClick={onRemove}>
          ×
        </button>
      </td>
    </tr>
  )
}

/**
 * Krótki tekst przy pozycji (uwaga, opis): zwinięty pokazuje, co pójdzie w mailu, „wpisz/popraw” otwiera pole.
 * Zapis po wyjściu z pola; pusty tekst = null (opis wraca wtedy do wycinka z karty).
 */
function ItemTextLine({
  label,
  hint,
  value,
  fallback,
  emptyText,
  ariaName,
  onCommit,
}: {
  label: string
  hint?: string
  value: string | null
  /** Tekst, który idzie w mailu przy pustym polu (wycinek opisu karty); null = nic. */
  fallback: string | null
  emptyText: string
  ariaName: string
  onCommit: (next: string | null) => Promise<Offer | null>
}) {
  const [draft, setDraft] = useState(value ?? '')
  const [open, setOpen] = useState(false)
  const [lastValue, setLastValue] = useState(value)
  if (value !== lastValue && !open) {
    setLastValue(value)
    setDraft(value ?? '')
  }

  function commit() {
    const next = draft.trim() === '' ? null : draft.trim()
    setOpen(false)
    // otwarte „popraw” bez zmian wstawia wycinek z karty — zapis zamroziłby go jako własny opis pozycji
    if (value === null && next !== null && next === fallback?.trim()) return
    if (next !== value) void onCommit(next)
  }

  const inMail = value ?? fallback
  return (
    <div className="mt-1.5 max-w-[34rem] text-[11px]">
      <div className="flex flex-wrap items-baseline gap-x-1.5 text-slate-600">
        <span className="font-medium text-slate-700">{label}:</span>
        {hint && <span className="text-slate-400">({hint})</span>}
        {!open && (
          <>
            <span className={inMail ? 'text-slate-700' : 'text-slate-400'}>
              {inMail ?? emptyText}
              {value === null && fallback !== null && <span className="text-slate-400"> (z karty)</span>}
            </span>
            <button
              type="button"
              className="text-blue-600 hover:underline"
              onClick={() => {
                setDraft(value ?? fallback ?? '')
                setOpen(true)
              }}
            >
              {inMail ? 'popraw' : 'wpisz'}
            </button>
          </>
        )}
      </div>
      {open && (
        <>
          <textarea
            className="mt-1 w-full rounded border border-slate-300 px-2 py-1 text-xs"
            rows={2}
            maxLength={300}
            autoFocus
            value={draft}
            placeholder={fallback ?? ''}
            aria-label={`${label}: ${ariaName}`}
            onChange={(e) => setDraft(e.target.value)}
            onBlur={commit}
            onKeyDown={(e) => {
              if (e.key === 'Escape') {
                setDraft(value ?? '')
                setOpen(false)
              }
            }}
          />
          <div className="flex flex-wrap gap-x-2 text-slate-500">
            <span>{draft.length}/300 · zapis po wyjściu z pola</span>
            {fallback !== null && (
              <button
                type="button"
                className="text-blue-600 hover:underline"
                // mousedown przed blur pola — inaczej zapisałby się wpisany tekst
                onMouseDown={(e) => {
                  e.preventDefault()
                  setDraft('')
                  setOpen(false)
                  if (value !== null) void onCommit(null)
                }}
              >
                wróć do opisu z karty
              </button>
            )}
          </div>
        </>
      )}
    </div>
  )
}

/** Pole kwoty: szkic lokalnie, zapis po wyjściu z pola (albo Enter); niepoprawny wpis wraca do zapisanej wartości. */
function MoneyInput({
  value,
  label,
  onCommit,
  onInvalid,
}: {
  value: number | null
  label: string
  /** false = serwer nie przyjął — pole wraca do zapisanej wartości. */
  onCommit: (value: number | null) => Promise<boolean>
  onInvalid: () => void
}) {
  const [draft, setDraft] = useState(moneyInputValue(value))
  const [focused, setFocused] = useState(false)
  const [lastValue, setLastValue] = useState(value)
  // Escape: blur() woła onBlur od razu, a jego domknięcie ma jeszcze wpisany szkic — flaga każe tylko cofnąć
  const cancelRef = useRef(false)
  // nowa wartość z serwera (np. „wstaw sugerowaną”) — pole idzie za nią, o ile człowiek właśnie w nim nie pisze
  if (value !== lastValue && !focused) {
    setLastValue(value)
    setDraft(moneyInputValue(value))
  }

  return (
    <span className="inline-flex items-center gap-1">
      <input
        type="text"
        inputMode="decimal"
        aria-label={label}
        placeholder="—"
        className={`${INPUT} w-24 text-right tabular-nums ${value == null ? 'ring-1 ring-red-300' : ''}`}
        value={draft}
        onFocus={() => setFocused(true)}
        onChange={(e) => setDraft(e.target.value)}
        onKeyDown={(e) => {
          if (e.key === 'Enter') e.currentTarget.blur()
          if (e.key === 'Escape') {
            cancelRef.current = true
            e.currentTarget.blur()
          }
        }}
        onBlur={() => {
          setFocused(false)
          if (cancelRef.current) {
            cancelRef.current = false
            setDraft(moneyInputValue(value))
            return
          }
          const parsed = parseMoney(draft)
          if (parsed === undefined) {
            setDraft(moneyInputValue(value))
            onInvalid()
            return
          }
          setDraft(moneyInputValue(parsed))
          if (parsed === value) return
          void onCommit(parsed).then((ok) => {
            if (!ok) setDraft(moneyInputValue(value))
          })
        }}
      />
      <span className="text-slate-500">zł</span>
    </span>
  )
}

/* ---------- Treść ---------- */

function ContentSection({
  content,
  onEdit,
  onBlur,
}: {
  content: Content
  onEdit: (patch: Partial<Content>, immediate?: boolean) => void
  onBlur: () => void
}) {
  const field = `${INPUT} mt-1 block w-full text-sm`
  return (
    <div className="space-y-3 rounded-xl bg-white p-4 text-xs shadow-sm">
      <h2 className="app-card-title text-sm font-semibold text-slate-900">Treść maila</h2>
      <label className="block font-medium text-slate-700">
        Temat wiadomości <span className="font-normal text-slate-500">— wymagany do wysyłki</span>
        <input
          className={field}
          maxLength={200}
          value={content.subject}
          onChange={(e) => onEdit({ subject: e.target.value.replace(/[\r\n]+/g, ' ') })}
          onBlur={onBlur}
          placeholder="np. Oferta: rękawice i półbuty S3 dla Państwa firmy"
        />
      </label>
      <label className="block font-medium text-slate-700">
        Wstęp <span className="font-normal text-slate-500">— tekst nad produktami (puste = bez wstępu)</span>
        <textarea
          className={field}
          rows={5}
          maxLength={5000}
          value={content.intro}
          onChange={(e) => onEdit({ intro: e.target.value })}
          onBlur={onBlur}
          placeholder="np. Dzień dobry, w nawiązaniu do rozmowy przesyłam ofertę na…"
        />
        <span className="mt-0.5 block font-normal text-slate-500">{content.intro.length}/5000</span>
      </label>
      <div className="grid gap-3 sm:grid-cols-2">
        <label className="block font-medium text-slate-700">
          Układ produktów
          <select
            className={field}
            value={content.layout}
            onChange={(e) => onEdit({ layout: e.target.value as CampaignLayout }, true)}
          >
            {LAYOUTS.map((l) => (
              <option key={l} value={l}>
                {CAMPAIGN_LAYOUT_LABEL[l]}
              </option>
            ))}
          </select>
          <span className="mt-0.5 block font-normal text-slate-500">{CAMPAIGN_LAYOUT_HINT[content.layout]}</span>
        </label>
        <label className="block font-medium text-slate-700">
          Oferta ważna do
          <input
            type="date"
            className={field}
            value={content.valid_until}
            onChange={(e) => onEdit({ valid_until: e.target.value }, true)}
          />
          <span className="mt-0.5 block font-normal text-slate-500">
            W mailu: „Ceny netto.{content.valid_until ? ` Oferta ważna do ${fmtDate(content.valid_until)}` : ''}”
          </span>
          {content.valid_until !== '' && content.valid_until < localToday() && (
            <span className="mt-0.5 block font-normal text-red-700">
              Ta data już minęła — z nią oferty nie da się wysłać ani skopiować.
            </span>
          )}
        </label>
      </div>
    </div>
  )
}

/* ---------- Podgląd ---------- */

function PreviewSection({
  preview,
  fresh,
  loading,
  error,
  onRefresh,
  missingPrices,
}: {
  preview: OfferPreview | null
  fresh: boolean
  loading: boolean
  error: string
  onRefresh: () => void
  /** Pola cen pozycji bez ceny — przy podglądzie, bo tu widać, że w mailu brakuje ceny. */
  missingPrices: ReactNode
}) {
  return (
    <div className="rounded-xl bg-white p-4 text-xs shadow-sm">
      <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
        <h2 className="app-card-title text-sm font-semibold text-slate-900">Podgląd maila</h2>
        <span className="flex items-center gap-2 text-slate-500">
          {loading ? 'odświeżam…' : preview && !fresh ? 'odświeży się za chwilę' : ''}
          <button type="button" className={BTN_SM} disabled={loading} onClick={onRefresh}>
            Odśwież podgląd
          </button>
        </span>
      </div>
      {error && <ErrorBar message={error} />}
      {missingPrices}
      {preview && (
        <div className="mb-2 space-y-1.5">
          {preview.from === '' && (
            <p className="rounded border border-amber-200 bg-amber-50 px-2 py-1.5 text-amber-900">
              Nie masz skrzynki nadawcy — wysyłka nie zadziała. Ustaw skrzynkę w{' '}
              <Link to="/account" className="font-medium underline">
                Moje konto → Moja poczta
              </Link>
              . Kopiowanie do Thunderbirda działa bez niej.
            </p>
          )}
          {preview.public_url_missing && (
            <p className="rounded border border-amber-200 bg-amber-50 px-2 py-1.5 text-amber-900">
              Brak publicznego adresu aplikacji — w mailu nie będzie zdjęć ani baneru, a wysyłka jest zablokowana. Ustawia
              go administrator.
            </p>
          )}
        </div>
      )}
      {preview ? (
        <div className="rounded-xl bg-slate-100 p-3">
          <div className="mx-auto mb-2 grid max-w-[680px] gap-0.5 text-slate-600">
            <span>
              <b className="text-slate-800">Od:</b>{' '}
              {preview.from || <span className="text-amber-800">brak skrzynki nadawcy (Moja poczta)</span>}
            </span>
            <span>
              <b className="text-slate-800">Temat:</b> {preview.subject || <span className="text-amber-800">brak tematu</span>}
            </span>
          </div>
          {/* sandbox="" — bez skryptów, formularzy i dostępu do strony; HTML maila tylko do obejrzenia. */}
          <iframe
            title="Podgląd oferty"
            sandbox=""
            srcDoc={preview.html}
            className={`mx-auto block h-[900px] w-full max-w-[680px] rounded border border-slate-200 bg-white ${fresh ? '' : 'opacity-70'}`}
          />
        </div>
      ) : (
        !error && <p className="text-slate-500">Przygotowuję podgląd…</p>
      )}
    </div>
  )
}

/**
 * Pozycje bez ceny z polem kwoty przy podglądzie maila — pole ceny w tabeli „Produkty w ofercie” jest wysoko nad
 * podglądem i łatwo go nie zauważyć (zgłoszenie użytkownika 05.10.2026). Zapis taki sam jak w tabeli; pozycja znika
 * z listy, gdy dostanie cenę. Wszystkie ceny (także już wpisane) zmienia się w tabeli.
 */
function MissingPrices({
  items,
  onPatch,
  onInvalid,
}: {
  items: OfferItem[]
  onPatch: (item: OfferItem, price: number | null) => Promise<Offer | null>
  onInvalid: (message: string) => void
}) {
  const missing = items.filter(lacksPrice)
  const showTable = () => document.getElementById(ITEMS_SECTION_ID)?.scrollIntoView({ behavior: 'smooth', block: 'start' })
  return (
    <div className="mb-2 rounded border border-slate-200 bg-slate-50 px-2 py-1.5 text-slate-700">
      {missing.length > 0 && (
        <>
          <p className="font-medium text-red-800">
            {missing.length} {plural(missing.length, 'pozycja nie ma', 'pozycje nie mają', 'pozycji nie ma')} ceny — wpisz
            cenę netto, zanim wyślesz albo skopiujesz ofertę:
          </p>
          <ul className="mt-1.5 space-y-1.5">
            {missing.map((item) => (
              <li key={item.id} className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
                <span className="min-w-0 flex-1">
                  <span className="text-slate-900">{item.name || <span className="italic text-slate-500">bez nazwy</span>}</span>
                  {item.code && <span className="ml-1.5 font-mono text-[11px] text-slate-500">{item.code}</span>}
                </span>
                <span className="inline-flex items-center gap-2">
                  {item.suggested_price != null && (
                    <button
                      type="button"
                      className="text-[11px] text-blue-600 hover:underline"
                      onClick={() => void onPatch(item, item.suggested_price)}
                    >
                      wstaw sugerowaną {formatPln(item.suggested_price)}
                    </button>
                  )}
                  <MoneyInput
                    value={item.price_net}
                    label={`Cena netto w ofercie: ${item.name}`}
                    onCommit={(v) => onPatch(item, v).then(Boolean)}
                    onInvalid={() => onInvalid('Cena netto: wpisz kwotę, np. 89,00.')}
                  />
                </span>
              </li>
            ))}
          </ul>
        </>
      )}
      <p className={missing.length > 0 ? 'mt-1.5 text-[11px] text-slate-500' : 'text-[11px] text-slate-500'}>
        {missing.length > 0 ? 'Pozostałe ceny' : 'Ceny pozycji'} zmienisz w tabeli{' '}
        <button type="button" className="text-blue-600 hover:underline" onClick={showTable}>
          „Produkty w ofercie” ↑
        </button>{' '}
        — kolumna „Cena netto w ofercie”.
      </p>
    </div>
  )
}

/* ---------- Wysyłka ---------- */

const SAVE_FAILED = 'Nie udało się zapisać treści oferty — kliknij „Zapisz ponownie”.'

function localToday(): string {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

function SendSection({
  offer,
  subject,
  preview,
  previewFresh,
  saving,
  contentFailed,
  ensureSaved,
  onSent,
  onCopied,
}: {
  offer: Offer
  subject: string
  preview: OfferPreview | null
  previewFresh: boolean
  /** Trwa zapis zmiany (cena, opis, treść) — kopia albo wysyłka mogłyby wziąć stan sprzed niej. */
  saving: boolean
  /** Ostatni zapis treści się nie udał — serwer ma starszy temat albo wstęp niż pola na ekranie. */
  contentFailed: boolean
  ensureSaved: () => Promise<boolean>
  onSent: (o: Offer) => void
  onCopied: () => void
}) {
  const { user } = useAuth()
  const [raw, setRaw] = useState('')
  const [confirmOpen, setConfirmOpen] = useState(false)
  const [sending, setSending] = useState(false)
  const [sendErr, setSendErr] = useState('')
  const [results, setResults] = useState<OfferSendResult[] | null>(null)
  const [copyMsg, setCopyMsg] = useState<{ ok: boolean; text: string } | null>(null)

  const emails = parseEmails(raw)
  const invalid = emails.filter((e) => !EMAIL_RE.test(e))
  const max = offer.limits.max_recipients
  // data z serwera (zapisana); porównanie napisów RRRR-MM-DD z dzisiejszą datą lokalną
  const validUntilPast = offer.valid_until !== null && offer.valid_until < localToday()
  // pozycje bez towaru i bez karty (usunięte dane) serwer pomija w mailu — nie blokują wysyłki
  const noPrice = offer.items.filter((i) => i.price_net == null && (i.erp_item_id != null || i.product_id != null)).length
  // pozycje w stanie strony są zawsze świeże; lista z podglądu liczy się tylko, gdy podgląd jest aktualny
  const missingPrices = noPrice > 0 || (previewFresh && (preview?.missing_prices.length ?? 0) > 0)

  // Powód, dla którego wysyłka jest zablokowana (pierwszy z listy) — pokazany pod przyciskiem.
  const sendBlock = (() => {
    if (offer.items.length === 0) return 'Dodaj produkty do oferty.'
    // najpierw nieudany zapis — dalsze powody (np. data) liczone są ze stanu serwera, który może być starszy
    if (contentFailed) return SAVE_FAILED
    if (saving) return 'Poczekaj, aż zmiany się zapiszą.'
    if (missingPrices) return 'Uzupełnij cenę przy każdej pozycji.'
    if (validUntilPast) return 'Data „Oferta ważna do” już minęła — zmień ją albo wyczyść pole.'
    if (subject.trim() === '') return 'Wpisz temat wiadomości.'
    if (preview?.from === '') return 'Ustaw skrzynkę nadawcy w Moje konto → Moja poczta.'
    if (preview?.public_url_missing) return 'Brak publicznego adresu aplikacji — wysyłka zablokowana.'
    if (emails.length === 0) return EMPTY_EMAILS
    if (emails.length > max) return `Najwyżej ${max} ${plural(max, 'adres', 'adresy', 'adresów')} naraz.`
    if (invalid.length > 0) return `Popraw ${plural(invalid.length, 'adres', 'adresy', 'adresy')}: ${invalid.join(', ')}`
    return ''
  })()

  const copyBlock = (() => {
    if (offer.items.length === 0) return 'Dodaj produkty do oferty.'
    if (contentFailed) return SAVE_FAILED
    if (saving) return 'Poczekaj, aż zmiany się zapiszą.'
    if (missingPrices) return 'Uzupełnij cenę przy każdej pozycji.'
    if (validUntilPast) return 'Data „Oferta ważna do” już minęła — zmień ją albo wyczyść pole.'
    if (!preview || !previewFresh) return 'Poczekaj, aż podgląd się odświeży.'
    return ''
  })()

  const [retrying, setRetrying] = useState(false)
  async function retrySave() {
    setRetrying(true)
    try {
      await ensureSaved()
    } finally {
      setRetrying(false)
    }
  }

  async function send() {
    setSending(true)
    setSendErr('')
    try {
      if (!(await ensureSaved())) {
        setSendErr('Nie udało się zapisać treści oferty — nic nie wysłano. Spróbuj ponownie za chwilę.')
        return
      }
      const res = await sendOffer(offer.id, emails)
      onSent(res.offer)
      setResults(res.results)
      // w polu zostają adresy, do których mail nie wyszedł — do poprawy i ponownej wysyłki
      setRaw(
        res.results
          .filter((r) => r.status !== 'sent')
          .map((r) => r.email)
          .join('\n'),
      )
    } catch (ex) {
      setSendErr(apiErrorText(ex, 'Nie udało się wysłać oferty.'))
    } finally {
      setSending(false)
      setConfirmOpen(false)
    }
  }

  /** Kopiuje HTML już wczytanego podglądu — bez czekania przed zapisem do schowka (schowek wymaga świeżego kliknięcia). */
  function copy() {
    if (copyBlock || !preview) return
    setCopyMsg(null)
    void copyRichHtml(preview.html, preview.text).then((ok) => {
      if (ok) {
        setCopyMsg({ ok: true, text: 'Skopiowano ofertę — wklej ją w treść nowej wiadomości w Thunderbirdzie (Ctrl+V).' })
        void markOfferCopied(offer.id)
          .then(onCopied)
          .catch(() => {
            // znacznik „skopiowana” to tylko informacja na liście — kopia w schowku i tak jest
          })
      } else {
        setCopyMsg({ ok: false, text: 'Nie udało się skopiować do schowka.' })
      }
    })
  }

  const sentCount = results?.filter((r) => r.status === 'sent').length ?? 0

  return (
    <div className="space-y-3 rounded-xl bg-white p-4 text-xs shadow-sm">
      <h2 className="app-card-title text-sm font-semibold text-slate-900">Wysyłka</h2>
      <label className="block font-medium text-slate-700">
        Adresy e-mail klientów{' '}
        <span className="font-normal text-slate-500">— oddziel przecinkiem, średnikiem albo nową linią</span>
        <textarea
          className={`${INPUT} mt-1 block w-full font-mono text-sm`}
          rows={3}
          value={raw}
          disabled={sending}
          onChange={(e) => {
            setRaw(e.target.value)
            setSendErr('')
          }}
          placeholder="np. zakupy@firma.pl, jan.kowalski@firma.pl"
        />
      </label>
      <p className={`tabular-nums ${emails.length > max ? 'text-red-700' : 'text-slate-500'}`}>
        {emails.length} {plural(emails.length, 'adres', 'adresy', 'adresów')} (najwyżej {max}). Każdy adres dostaje osobny
        mail — klienci nie widzą siebie nawzajem. Kopia z listą adresów trafi do Twojej skrzynki.
      </p>
      <div className="flex flex-wrap items-center gap-2">
        <button
          type="button"
          className={BTN_PRIMARY}
          disabled={sending || sendBlock !== ''}
          onClick={() => {
            setSendErr('')
            setConfirmOpen(true)
          }}
        >
          {sending
            ? 'Wysyłam…'
            : `Wyślij do ${emails.length} ${plural(emails.length, 'adresu', 'adresów', 'adresów')}`}
        </button>
        <button
          type="button"
          className={BTN}
          disabled={copyBlock !== ''}
          title="Kopiuje gotowy wygląd z podglądu — do wklejenia w treść nowej wiadomości"
          onClick={copy}
        >
          Kopiuj do wklejenia w Thunderbirdzie
        </button>
        {can(user, 'inquiries.use') && (
          <ThunderbirdButton
            preview={preview}
            subject={(preview?.subject || subject).trim() || `Oferta ${offer.code ?? ''}`.trim()}
            blocked={copyBlock !== ''}
          />
        )}
      </div>
      {sendBlock && emails.length > 0 && <p className="text-amber-800">{sendBlock}</p>}
      {sendBlock && emails.length === 0 && sendBlock !== EMPTY_EMAILS && <p className="text-slate-500">Wysyłka: {sendBlock}</p>}
      {copyBlock && copyBlock !== sendBlock && <p className="text-slate-500">Kopiowanie: {copyBlock}</p>}
      {contentFailed && (
        <button type="button" className={BTN} disabled={retrying} onClick={() => void retrySave()}>
          {retrying ? 'Zapisuję…' : 'Zapisz ponownie'}
        </button>
      )}
      {copyMsg && (
        <p className={copyMsg.ok ? 'text-emerald-700' : 'text-red-700'} role="status">
          {copyMsg.text}
        </p>
      )}
      {sendErr && <ErrorBar message={sendErr} onClose={() => setSendErr('')} />}
      {results && (
        <div className="rounded border border-slate-200">
          <p className="border-b border-slate-200 bg-slate-50 px-3 py-1.5 font-medium text-slate-700">
            Wynik wysyłki: wysłano {sentCount} z {results.length}
            {sentCount < results.length ? ' — adresy bez wysyłki zostały w polu powyżej' : ''}
          </p>
          <RecipientList rows={results} />
        </div>
      )}

      {confirmOpen && (
        <ConfirmDialog
          title="Wysłać ofertę?"
          confirmLabel={`Wyślij do ${emails.length} ${plural(emails.length, 'adresu', 'adresów', 'adresów')}`}
          busy={sending}
          onClose={() => setConfirmOpen(false)}
          onConfirm={() => void send()}
          message={
            <>
              <p>
                <b>{subject.trim()}</b> — {offer.items.length}{' '}
                {plural(offer.items.length, 'pozycja', 'pozycje', 'pozycji')}. Każdy adres dostanie osobny mail z Twojej
                skrzynki{preview?.from ? ` (${preview.from})` : ''}:
              </p>
              <ul className="max-h-48 list-disc overflow-y-auto pl-5 font-mono text-xs">
                {emails.map((e) => (
                  <li key={e}>{e}</li>
                ))}
              </ul>
              <p className="text-xs text-slate-600">
                Wysłanego maila nie da się cofnąć. Wysyłka może potrwać do 2 minut — nie zamykaj strony.
              </p>
            </>
          }
        />
      )}
    </div>
  )
}

function RecipientList({ rows }: { rows: { email: string; status: OfferRecipientStatus; error: string | null }[] }) {
  return (
    <ul className="divide-y divide-slate-100">
      {rows.map((r, i) => {
        const s = RECIPIENT_STATUS[r.status] ?? { label: r.status, tone: 'slate' as ChipTone }
        return (
          <li key={`${r.email}-${i}`} className="flex flex-wrap items-baseline gap-x-2 px-3 py-1">
            <Chip tone={s.tone}>{s.label}</Chip>
            <span className="font-mono">{r.email}</span>
            {r.error && <span className="text-slate-600">— {r.error}</span>}
          </li>
        )
      })}
    </ul>
  )
}

/**
 * „Otwórz w Thunderbirdzie” (wzór z ClientOfferModal): prośba na serwerze, dodatek podejmuje ją przy pytaniu o
 * kolejkę; pilnujemy podjęcia, bo okno Thunderbirda otwiera się poza przeglądarką. Przycisk tylko, gdy dodatek
 * odezwał się niedawno. Treść = HTML już wczytanego podglądu.
 */
function ThunderbirdButton({ preview, subject, blocked }: { preview: OfferPreview | null; subject: string; blocked: boolean }) {
  const [addonReady, setAddonReady] = useState(false)
  const [busy, setBusy] = useState(false)
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null)
  // numer bieżącego pilnowania prośby — odmontowanie i nowa prośba unieważniają stare
  const watchRef = useRef(0)

  useEffect(() => {
    let cancelled = false
    const check = () =>
      void api<{ addon_ready: boolean }>('/offers/compose/status')
        .then((res) => {
          if (!cancelled) setAddonReady(res.addon_ready)
        })
        // bez odpowiedzi zostaje samo kopiowanie — przycisk Thunderbirda to dodatek, nie warunek
        .catch(() => {
          if (!cancelled) setAddonReady(false)
        })
    check()
    const timer = window.setInterval(check, ADDON_STATUS_EVERY_MS)
    const watch = watchRef
    return () => {
      cancelled = true
      window.clearInterval(timer)
      watch.current += 1
    }
  }, [])

  async function open() {
    if (blocked || !preview) return
    const ticket = ++watchRef.current
    setBusy(true)
    setMsg(null)
    let id: number
    try {
      const res = await api<{ id: number }>('/offers/compose', {
        method: 'POST',
        body: JSON.stringify({ subject: subject.slice(0, 255), body_html: preview.html, body_text: preview.text }),
      })
      id = res.id
    } catch (ex) {
      if (watchRef.current === ticket) {
        setBusy(false)
        setMsg({ ok: false, text: apiErrorText(ex, 'Nie udało się przekazać oferty do Thunderbirda.') })
      }
      return
    }
    if (watchRef.current !== ticket) return
    setMsg({ ok: true, text: 'Przekazano. Thunderbird otworzy nowego maila z ofertą w ciągu kilku sekund.' })
    for (let i = 0; i < WATCH_TRIES; i += 1) {
      await new Promise((done) => setTimeout(done, WATCH_EVERY_MS))
      if (watchRef.current !== ticket) return
      try {
        const row = await api<{ claimed_at: string | null }>(`/offers/compose/${id}`)
        if (watchRef.current !== ticket) return
        if (row.claimed_at) {
          setBusy(false)
          setMsg({ ok: true, text: 'Thunderbird otworzył nowego maila z ofertą — wpisz adresata i wyślij stamtąd.' })
          return
        }
      } catch {
        // chwilowy błąd sieci — pytamy dalej do końca czasu
      }
    }
    if (watchRef.current !== ticket) return
    setBusy(false)
    setMsg({
      ok: false,
      text: 'Thunderbird jeszcze nie odebrał oferty — sprawdź, czy jest uruchomiony. Możesz też skopiować ofertę i wkleić ją ręcznie.',
    })
  }

  if (!addonReady && !msg) return null
  return (
    <>
      {addonReady && (
        <button
          type="button"
          className={BTN}
          disabled={blocked || busy}
          title="Otwiera nowego maila z tą ofertą w Thunderbirdzie — adresata wpisujesz sam"
          onClick={() => void open()}
        >
          {busy ? 'Czekam na Thunderbirda…' : 'Otwórz w Thunderbirdzie'}
        </button>
      )}
      {msg && (
        <p className={`w-full ${msg.ok ? 'text-emerald-700' : 'text-red-700'}`} role="status">
          {msg.text}
        </p>
      )}
    </>
  )
}

/* ---------- Historia wysyłek ---------- */

function HistorySection({ offer }: { offer: Offer }) {
  const [shown, setShown] = useState<OfferSend | null>(null)
  return (
    <div className="rounded-xl bg-white text-xs shadow-sm">
      <h2 className="app-card-title border-b border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-900">
        Historia wysyłek
      </h2>
      {offer.sends.length === 0 ? (
        <p className="px-4 py-3 text-slate-500">Oferta nie była jeszcze wysyłana.</p>
      ) : (
        <ul className="divide-y divide-slate-200">
          {offer.sends.map((s) => {
            const sent = s.recipients.filter((r) => r.status === 'sent').length
            return (
              <li key={s.id} className="px-4 py-2">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <span className="text-slate-700">
                    <b className="font-medium tabular-nums text-slate-900">{fmtDateTime(s.created_at)}</b> · wysłano {sent} z{' '}
                    {s.recipients.length}
                  </span>
                  <button type="button" className={BTN_SM} onClick={() => setShown(s)}>
                    Pokaż wysłaną
                  </button>
                </div>
                <RecipientList rows={s.recipients} />
              </li>
            )
          })}
        </ul>
      )}
      <p className="px-4 py-2 text-[11px] text-slate-500">
        „Pokaż wysłaną” otwiera dokładnie ten mail, który dostali klienci — także gdy oferta zmieniła się później.
      </p>
      {shown && <SentMailModal offerId={offer.id} send={shown} onClose={() => setShown(null)} />}
    </div>
  )
}

function SentMailModal({ offerId, send, onClose }: { offerId: number; send: OfferSend; onClose: () => void }) {
  const [mail, setMail] = useState<OfferSentMail | null>(null)
  const [err, setErr] = useState('')

  useEffect(() => {
    let cancelled = false
    offerSentMail(offerId, send.id)
      .then((m) => {
        if (!cancelled) setMail(m)
      })
      .catch((ex: unknown) => {
        if (!cancelled) setErr(apiErrorText(ex, 'Nie udało się wczytać wysłanego maila.'))
      })
    return () => {
      cancelled = true
    }
  }, [offerId, send.id])

  return (
    <Modal title={`Wysłana oferta — ${fmtDateTime(send.created_at)}`} wide onClose={onClose}>
      {err && <ErrorBar message={err} />}
      {mail ? (
        <div className="text-xs">
          <p className="mb-2 text-slate-600">
            <b className="text-slate-800">Temat:</b> {mail.subject}
          </p>
          <iframe
            title="Wysłana oferta"
            sandbox=""
            srcDoc={mail.html}
            className="mx-auto block h-[70vh] w-full max-w-[680px] rounded border border-slate-200 bg-white"
          />
        </div>
      ) : (
        !err && <p className="text-xs text-slate-500">Wczytuję…</p>
      )}
    </Modal>
  )
}
