import { useCallback, useEffect, useRef, useState, type ReactNode } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useAuth } from '../auth'
import {
  BTN,
  BTN_PRIMARY,
  BTN_SM,
  CampaignStatusChip,
  Chip,
  ConfirmDialog,
  DropBar,
  ErrorBar,
  INPUT,
  Modal,
  Pager,
  SortTh,
  StageTwo,
} from '../components/CampaignsUi'
import { CampaignBlockEditor, LiveMailPreview } from '../components/CampaignBlockEditor'
import { ProductSearchSelect } from '../components/ProductSearchSelect'
import { ProductVerifyModal } from '../components/ProductVerifyModal'
import { CampaignSuggestionsModal } from '../components/CampaignSuggestionsModal'
import { XlCustomersModal } from '../components/XlCustomersModal'
import { ListContactsModal, type ListChoice } from '../components/ListContactsModal'
import { CampaignRecipientsConfirm } from '../components/CampaignRecipientsConfirm'
import { can } from '../lib/api'
import {
  errorText,
  fmtDate,
  fmtDateTime,
  fmtInt,
  fmtQty,
  moneyInputValue,
  parseMoney,
} from '../lib/campaignFormat'
import {
  BRAND_COLORS,
  BRAND_COLOR_LABEL,
  CAMPAIGN_LAYOUT_LABEL,
  DEFAULT_BRAND_COLOR,
  ITEM_LINK_LABEL_MAX,
  LAYOUTS_WITH_DESCRIPTION,
  addCampaignItems,
  addCampaignRecipients,
  applyTemplate,
  checkCampaignReplies,
  campaignAudience,
  campaignPreview,
  previewDraft,
  createTemplate,
  listTemplates,
  standardCampaignBlocks,
  campaignRecipients,
  cancelCampaign,
  deleteCampaign,
  duplicateCampaign,
  formatPln,
  getCampaign,
  listMailingLists,
  removeCampaignItem,
  scheduleCampaign,
  sendCampaign,
  unscheduleCampaign,
  sendCampaignTest,
  updateCampaign,
  updateCampaignItem,
  type AudiencePreview,
  type Campaign,
  type CampaignAudience,
  type CampaignBlock,
  type CampaignItem,
  type CampaignItemLink,
  type CampaignItemPatch,
  type CampaignPatch,
  type CampaignPreview,
  type CampaignRecipientRow,
  type CampaignRecipientSort,
  type CampaignRecipientStatus,
  type CampaignTotals,
  type CampaignClicks,
  type CampaignReplies,
  type CampaignSales,
  type CampaignSalesBuyer,
  type CampaignTemplate,
  type CampaignXlMode,
  type MailingList,
  type PageMeta,
} from '../lib/campaigns'
import { plural } from '../lib/plural'
import { sortDate, sortRows, useTableSort } from '../lib/tableSort'
import { useSerialAutosave } from '../lib/useSerialAutosave'

/**
 * Kampania /kampanie/:id. Projekt (draft): kreator w 5 krokach — produkty, odbiorcy, treść, podgląd i test, wysyłka;
 * wszystko zapisuje się samo (pola tekstowe z opóźnieniem, ceny po wyjściu z pola). Po wysyłce: tylko odczyt,
 * postęp wysyłki, odbiorcy i wynik (stan pozycji przy wysyłce i po 7 / 30 dniach).
 */

/** Jak config('campaigns.max_items') — serwer i tak odrzuci nadmiar komunikatem 422. */
const MAX_ITEMS = 12
const CONTENT_SAVE_MS = 600
const REFRESH_SENDING_MS = 10_000

type Step = 1 | 2 | 3 | 4 | 5

const XL_MODES: { value: CampaignXlMode | null; label: string; hint: string }[] = [
  { value: null, label: 'Bez klientów z ERP XL', hint: 'tylko wybrane grupy' },
  { value: 'items', label: 'Kupowali te towary', hint: 'którąkolwiek pozycję kampanii, z faktur i paragonów XL' },
  { value: 'group', label: 'Kupowali z tej grupy', hint: 'towary z tą samą literą grupy co pozycje (A odzież, B obuwie, S sprzęt…)' },
  { value: 'mine', label: 'Moi klienci', hint: 'kontrahenci, którym najczęściej wystawiasz faktury w XL' },
]

const RECIPIENT_STATUS_LABEL: Record<string, string> = {
  pending: 'czeka',
  sending: 'w wysyłce',
  sent: 'wysłany',
  failed: 'błąd',
  skipped: 'pominięty',
}

type Content = {
  subject: string
  preheader: string
  valid_until: string
  /** Elementy maila — do serwera zawsze cała tablica. */
  blocks: CampaignBlock[]
  brand_color: string | null
}

function contentOf(c: Campaign): Content {
  return {
    subject: c.subject ?? '',
    preheader: c.preheader ?? '',
    valid_until: c.valid_until ? c.valid_until.slice(0, 10) : '',
    blocks: c.blocks ?? standardCampaignBlocks(),
    brand_color: c.brand_color ?? null,
  }
}

function contentPatch(patch: Partial<Content>): CampaignPatch {
  const out: CampaignPatch = {}
  if (patch.subject !== undefined) out.subject = patch.subject
  if (patch.preheader !== undefined) out.preheader = patch.preheader.trim() === '' ? null : patch.preheader
  if (patch.valid_until !== undefined) out.valid_until = patch.valid_until === '' ? null : patch.valid_until
  if (patch.blocks !== undefined) out.blocks = patch.blocks
  if (patch.brand_color !== undefined) out.brand_color = patch.brand_color
  return out
}

/**
 * Zeszło ze stanu (0–100) z 30 dni, a gdy brak — z 7 dni; null, gdy nie ma danych. Stan wyższy niż przy wysyłce
 * (dostawa) to nie „ujemna sprzedaż” — rose = true, bez procentu.
 */
function itemDrop(item: CampaignItem): { percent: number; days: 7 | 30; rose: boolean } | null {
  const start = item.snapshot?.stock
  if (start == null || start <= 0) return null
  const after = item.stock_after_30d ?? item.stock_after_7d
  if (after == null) return null
  const days = item.stock_after_30d != null ? 30 : 7
  if (after > start) return { percent: 0, days, rose: true }
  return { percent: Math.min(100, ((start - after) / start) * 100), days, rose: false }
}

type ItemSortKey = 'name' | 'price' | 'stock' | 'after_7d' | 'after_30d' | 'drop' | 'bought' | 'clicks'
const ITEM_SORT_DESC: ItemSortKey[] = ['price', 'stock', 'after_7d', 'after_30d', 'drop', 'bought', 'clicks']
const RECIPIENT_SORT_DESC: CampaignRecipientSort[] = ['sent_at', 'clicks', 'replied_at', 'notes']

/** Wartość komórki tabeli „Wynik” do sortowania — to samo, co widać w kolumnie (stan wzrósł = poniżej 0%). */
function itemSortValue(i: CampaignItem, k: ItemSortKey, campaign: Campaign): string | number | null {
  switch (k) {
    case 'name':
      return i.snapshot?.name ?? i.name
    case 'price':
      return i.snapshot?.price ?? i.promo_price_net
    case 'stock':
      return i.snapshot?.stock ?? null
    case 'after_7d':
      return i.stock_after_7d
    case 'after_30d':
      return i.stock_after_30d
    case 'drop': {
      const drop = itemDrop(i)
      return drop ? (drop.rose ? -1 : drop.percent) : null
    }
    case 'bought': {
      const row = campaign.sales?.items.find((r) => r.erp_item_id === i.erp_item_id)
      return row ? row.value_recipients : null
    }
    case 'clicks': {
      const row = campaign.clicks?.items.find((r) => r.campaign_item_id === i.id)
      return row ? row.product + row.offer + (row.link ?? 0) : null
    }
  }
}

export function CampaignEditor() {
  const { id } = useParams()
  // Inna kampania (np. po „Duplikuj”) = świeży stan kreatora, bez szkiców poprzedniej.
  return <CampaignEditorPage key={id} campaignId={Number(id)} />
}

function CampaignEditorPage({ campaignId }: { campaignId: number }) {
  const navigate = useNavigate()
  const { user } = useAuth()

  const [campaign, setCampaign] = useState<Campaign | null>(null)
  const [loadErr, setLoadErr] = useState('')
  const [err, setErr] = useState('')
  const [saving, setSaving] = useState(0)
  const [savedAt, setSavedAt] = useState<Date | null>(null)
  const [nameDraft, setNameDraft] = useState('')
  const [dialog, setDialog] = useState<'delete' | 'cancel' | null>(null)
  const [dialogBusy, setDialogBusy] = useState(false)
  const [dialogErr, setDialogErr] = useState('')
  const [duplicating, setDuplicating] = useState(false)
  // Kolejność odpowiedzi: stara odpowiedź (np. wolny PATCH ceny) nie nadpisuje nowszej.
  const mutationSeq = useRef(0)

  const load = useCallback(async () => {
    if (!Number.isFinite(campaignId) || campaignId <= 0) {
      setLoadErr('Zły numer kampanii.')
      return
    }
    try {
      const c = await getCampaign(campaignId)
      setCampaign(c)
      setNameDraft(c.name)
      setLoadErr('')
    } catch (ex) {
      setLoadErr(errorText(ex, 'Nie udało się wczytać kampanii.'))
    }
  }, [campaignId])

  useEffect(() => {
    void load()
  }, [load])

  /** Każda zmiana kampanii zwraca pełną kampanię — podmieniamy stan, jeśli to najnowsza odpowiedź. */
  const mutate = useCallback(async (run: () => Promise<Campaign>, fallback: string): Promise<Campaign | null> => {
    const my = ++mutationSeq.current
    setSaving((n) => n + 1)
    try {
      const c = await run()
      if (my === mutationSeq.current) setCampaign(c)
      setSavedAt(new Date())
      return c
    } catch (ex) {
      setErr(errorText(ex, fallback))
      return null
    } finally {
      setSaving((n) => n - 1)
    }
  }, [])

  async function saveName() {
    if (!campaign) return
    const name = nameDraft.trim()
    if (!name || name === campaign.name) {
      setNameDraft(campaign.name)
      return
    }
    const res = await mutate(() => updateCampaign(campaign.id, { name }), 'Nie udało się zmienić nazwy.')
    if (!res) setNameDraft(campaign.name)
  }

  async function duplicate() {
    if (!campaign) return
    setDuplicating(true)
    setErr('')
    try {
      const c = await duplicateCampaign(campaign.id)
      navigate(`/kampanie/${c.id}`)
    } catch (ex) {
      setErr(errorText(ex, 'Nie udało się zduplikować kampanii.'))
    } finally {
      setDuplicating(false)
    }
  }

  async function confirmDialog() {
    if (!campaign || !dialog) return
    setDialogBusy(true)
    setDialogErr('')
    try {
      if (dialog === 'delete') {
        await deleteCampaign(campaign.id)
        navigate('/kampanie')
        return
      }
      setCampaign(await cancelCampaign(campaign.id))
      setDialog(null)
    } catch (ex) {
      setDialogErr(errorText(ex, dialog === 'delete' ? 'Nie udało się usunąć kampanii.' : 'Nie udało się anulować wysyłki.'))
    } finally {
      setDialogBusy(false)
    }
  }

  if (!campaign) {
    return (
      <div>
        <Link to="/kampanie" className="app-back text-xs text-blue-600 hover:underline">
          ← Kampanie
        </Link>
        {loadErr ? <div className="mt-3"><ErrorBar message={loadErr} /></div> : <p className="mt-3 text-sm text-slate-500">Ładowanie…</p>}
      </div>
    )
  }

  // zaplanowana pokazuje kreator tylko do odczytu, z paskiem „cofnij planowanie”
  const isDraft = campaign.status === 'draft' || campaign.status === 'scheduled'
  const editable = campaign.status === 'draft' && campaign.can_edit
  const isAuthor = user?.id === campaign.author.id
  const canCancel = campaign.status === 'sending' && (isAuthor || can(user, 'campaigns.manage'))
  // sam podgląd (campaigns.view): bez duplikowania, sprawdzania skrzynki i dopisywania
  const canManage = campaign.can_manage ?? true

  return (
    <div>
      <Link to="/kampanie" className="app-back text-xs text-blue-600 hover:underline">
        ← Kampanie
      </Link>
      <div className="app-page-head mb-4 mt-1 flex flex-wrap items-end justify-between gap-3">
        <div className="min-w-0 flex-1">
          {editable ? (
            <input
              aria-label="Nazwa kampanii"
              maxLength={200}
              className="app-page-title block w-full max-w-2xl rounded border border-transparent bg-transparent px-1 text-xl font-semibold hover:border-slate-300 focus:border-slate-400"
              value={nameDraft}
              onChange={(e) => setNameDraft(e.target.value)}
              onBlur={() => void saveName()}
              onKeyDown={(e) => {
                if (e.key === 'Enter') e.currentTarget.blur()
              }}
            />
          ) : (
            <h1 className="app-page-title text-xl font-semibold">{campaign.name}</h1>
          )}
          <p className="mt-1 flex flex-wrap items-center gap-2 text-xs text-slate-600">
            <CampaignStatusChip status={campaign.status} sent={campaign.totals?.sent} total={campaign.totals?.recipients} />
            <span className="app-code font-mono">{campaign.code}</span>
            <span>
              utworzona {fmtDate(campaign.created_at)} przez {campaign.author.name}
            </span>
            {editable && (
              <span className="text-slate-500">
                · {saving > 0 ? 'zapisuję…' : savedAt ? `zapisano ${savedAt.toLocaleTimeString('pl-PL', { hour: '2-digit', minute: '2-digit' })}` : 'zapisuje się sama'}
              </span>
            )}
          </p>
        </div>
        <div className="flex flex-wrap gap-2">
          {canManage && can(user, 'campaigns.use') && (
            <button type="button" className={BTN} disabled={duplicating} onClick={() => void duplicate()}>
              Duplikuj
            </button>
          )}
          {(editable || campaign.can_delete) && (
            <button
              type="button"
              className={`${BTN} text-red-700`}
              onClick={() => {
                setDialogErr('')
                setDialog('delete')
              }}
            >
              {editable ? 'Usuń projekt' : 'Usuń kampanię'}
            </button>
          )}
          {canCancel && (
            <button
              type="button"
              className={`${BTN} text-red-700`}
              onClick={() => {
                setDialogErr('')
                setDialog('cancel')
              }}
            >
              Anuluj wysyłkę
            </button>
          )}
        </div>
      </div>

      <ErrorBar message={err} onClose={() => setErr('')} />

      {campaign.status === 'scheduled' && (
        <ScheduledBanner
          campaign={campaign}
          canUnschedule={isAuthor || can(user, 'campaigns.manage')}
          onChanged={(c) => {
            setErr('')
            setCampaign(c)
          }}
          onError={setErr}
        />
      )}

      {isDraft ? (
        <DraftWizard
          campaign={campaign}
          editable={editable}
          isAuthor={isAuthor}
          mutate={mutate}
          onError={setErr}
          onSent={(c) => {
            setErr('')
            setCampaign(c)
          }}
        />
      ) : (
        <SentView
          campaign={campaign}
          canManage={canManage}
          mutate={mutate}
          onReload={load}
          onChanged={(c) => {
            setErr('')
            setCampaign(c)
          }}
        />
      )}

      {dialog === 'delete' && (
        <ConfirmDialog
          title={editable ? 'Usunąć projekt kampanii?' : 'Usunąć wysłaną kampanię?'}
          danger
          confirmLabel={editable ? 'Usuń projekt' : 'Usuń kampanię'}
          confirmWord="Tak"
          busy={dialogBusy}
          error={dialogErr}
          onClose={() => setDialog(null)}
          onConfirm={() => void confirmDialog()}
          message={
            editable ? (
              <p>
                <b>{campaign.name}</b> — {campaign.items.length} {plural(campaign.items.length, 'pozycja', 'pozycje', 'pozycji')}.
                Projekt zniknie z listy kampanii.
              </p>
            ) : (
              <div className="space-y-2">
                <p>
                  <b>{campaign.code} {campaign.name}</b> zniknie z listy razem z odbiorcami, kliknięciami, odpowiedziami
                  klientów i wynikami sprzedaży. Tego nie da się cofnąć.
                </p>
                <p className="text-slate-600">
                  Maile, które wyszły, zostają u klientów. Wypisy z mailingu zostają — te adresy dalej nie dostaną kampanii.
                </p>
              </div>
            )
          }
        />
      )}
      {dialog === 'cancel' && (
        <ConfirmDialog
          title="Anulować wysyłkę?"
          danger
          confirmLabel="Anuluj wysyłkę"
          busy={dialogBusy}
          error={dialogErr}
          onClose={() => setDialog(null)}
          onConfirm={() => void confirmDialog()}
          message={
            campaign.sent_at ? (
              // kampania już raz zakończona, trwa wysyłka do dopisanych — anuluje się tylko dopisanie
              <>
                <p>
                  Maile, które już wyszły, zostają u klientów. Dopisani odbiorcy, którzy jeszcze czekają, <b>nie dostaną</b>{' '}
                  tej kampanii.
                </p>
                <p className="text-xs text-slate-600">
                  Kampania wróci do stanu „Wysłana”. Zatrzymanych adresów nie da się dopisać do niej drugi raz.
                </p>
              </>
            ) : (
              <>
                <p>
                  Maile, które już wyszły, zostają u klientów. Pozostali odbiorcy <b>nie dostaną</b> tej kampanii.
                </p>
                <p className="text-xs text-slate-600">Anulowanej kampanii nie da się wznowić — można ją zduplikować.</p>
              </>
            )
          }
        />
      )}
    </div>
  )
}

/* ------------------------------------------------------------------------------------------------ */
/* Kreator projektu                                                                                  */
/* ------------------------------------------------------------------------------------------------ */

type Mutate = (run: () => Promise<Campaign>, fallback: string) => Promise<Campaign | null>

function DraftWizard({
  campaign,
  editable,
  isAuthor,
  mutate,
  onError,
  onSent,
}: {
  campaign: Campaign
  editable: boolean
  isAuthor: boolean
  mutate: Mutate
  onError: (message: string) => void
  onSent: (c: Campaign) => void
}) {
  const [step, setStep] = useState<Step>(1)
  const [audience, setAudience] = useState<AudiencePreview | null>(null)
  const [audienceLoading, setAudienceLoading] = useState(false)
  const [audienceErr, setAudienceErr] = useState('')
  const [testSentAt, setTestSentAt] = useState<Date | null>(null)
  const audienceSeq = useRef(0)

  // Treść: szkic w polach, zapis po CONTENT_SAVE_MS bez pisania, przy wyjściu z pola i przed podglądem/wysyłką.
  // Zapisy idą po kolei (nowy PATCH dopiero po odpowiedzi na poprzedni), wyjście ze strony zapisuje od razu.
  const [content, setContent] = useState<Content>(() => contentOf(campaign))
  const campaignId = campaign.id
  const saveContent = useCallback(
    (patch: Partial<Content>) => mutate(() => updateCampaign(campaignId, contentPatch(patch)), 'Nie udało się zapisać treści.'),
    [mutate, campaignId],
  )
  const autosave = useSerialAutosave<Content>(saveContent, CONTENT_SAVE_MS)
  const flush = autosave.flush

  function editContent(patch: Partial<Content>, immediate = false) {
    setContent((c) => ({ ...c, ...patch }))
    autosave.edit(patch, immediate)
  }

  /**
   * Szablon zastępuje elementy maila: najpierw zapis czekających zmian, potem (w tej samej kolejce) POST;
   * elementy w polach — z odpowiedzi serwera, żaden spóźniony PATCH starych elementów ich nie nadpisze.
   */
  async function applyTemplateToContent(templateId: number | null): Promise<boolean> {
    await flush()
    autosave.discard(['blocks', 'brand_color'])
    const c = await autosave.enqueue(() =>
      mutate(() => applyTemplate(campaignId, templateId), 'Nie udało się zastosować szablonu.'),
    )
    if (!c) return false
    autosave.discard(['blocks', 'brand_color'])
    setContent((prev) => ({ ...prev, blocks: c.blocks, brand_color: c.brand_color }))
    return true
  }

  const loadAudience = useCallback(async () => {
    const my = ++audienceSeq.current
    setAudienceLoading(true)
    try {
      const a = await campaignAudience(campaign.id)
      if (my !== audienceSeq.current) return
      setAudience(a)
      setAudienceErr('')
    } catch (ex) {
      if (my === audienceSeq.current) setAudienceErr(errorText(ex, 'Nie udało się policzyć odbiorców.'))
    } finally {
      if (my === audienceSeq.current) setAudienceLoading(false)
    }
  }, [campaign.id])

  // Liczba odbiorców zależy od odbiorców i od pozycji (tryb XL „kupowali te towary”) — przeliczamy po każdej zmianie.
  const audienceKey = JSON.stringify(campaign.audience) + campaign.items.map((i) => i.id).join(',')
  useEffect(() => {
    void loadAudience()
  }, [loadAudience, audienceKey])

  async function goTo(next: Step) {
    await flush()
    setStep(next)
  }

  const items = campaign.items
  const hasAudienceChoice = campaign.audience.list_ids.length > 0 || campaign.audience.xl.mode !== null
  const productsBlock = content.blocks.find((b) => b.type === 'products')
  const layoutLabel = productsBlock?.type === 'products' ? CAMPAIGN_LAYOUT_LABEL[productsBlock.layout] : ''

  const steps: { n: Step; title: string; hint: string; done: boolean }[] = [
    { n: 1, title: 'Produkty', hint: `${items.length} ${plural(items.length, 'pozycja', 'pozycje', 'pozycji')}`, done: items.length > 0 },
    {
      n: 2,
      title: 'Odbiorcy',
      hint: !hasAudienceChoice ? 'nie wybrano' : audience ? `${fmtInt(audience.final)} ${plural(audience.final, 'odbiorca', 'odbiorcy', 'odbiorców')}` : 'wybrano',
      done: (audience?.final ?? 0) > 0,
    },
    {
      n: 3,
      title: 'Treść',
      hint: !content.subject.trim() ? 'brak tematu' : campaign.template_name ? `szablon: ${campaign.template_name}` : `układ: ${layoutLabel.toLowerCase()}`,
      done: content.subject.trim() !== '',
    },
    { n: 4, title: 'Podgląd i test', hint: testSentAt ? 'test wysłany' : '–', done: testSentAt !== null },
    { n: 5, title: 'Wyślij', hint: '–', done: false },
  ]

  return (
    <>
      <nav className="mb-4 grid grid-cols-2 gap-1.5 sm:grid-cols-3 lg:grid-cols-5" aria-label="Kroki kampanii">
        {steps.map((s) => {
          const on = s.n === step
          return (
            <button
              key={s.n}
              type="button"
              aria-current={on ? 'step' : undefined}
              onClick={() => void goTo(s.n)}
              className={`grid gap-px rounded-lg border bg-white px-2.5 py-2 text-left shadow-sm ${
                on ? 'border-blue-600 ring-1 ring-blue-600' : 'border-slate-200 hover:border-slate-300'
              }`}
            >
              <b className={`text-[11px] font-semibold tracking-wide ${s.done ? 'text-emerald-700' : 'text-slate-400'}`}>
                KROK {s.n}
                {s.done ? ' ✓' : ''}
              </b>
              <span className="text-sm font-medium text-slate-900">{s.title}</span>
              <em className="truncate text-[11px] not-italic text-slate-500">{s.hint}</em>
            </button>
          )
        })}
      </nav>

      {step === 1 && (
        <ItemsStep campaign={campaign} editable={editable} mutate={mutate} onError={onError} onNext={() => void goTo(2)} />
      )}
      {step === 2 && (
        <AudienceStep
          campaign={campaign}
          editable={editable}
          mutate={mutate}
          audience={audience}
          audienceLoading={audienceLoading}
          audienceErr={audienceErr}
          onNext={() => void goTo(3)}
        />
      )}
      {step === 3 && (
        <ContentStep
          campaign={campaign}
          content={content}
          editable={editable}
          onEdit={editContent}
          onBlur={() => void flush()}
          onApplyTemplate={applyTemplateToContent}
          onNext={() => void goTo(4)}
        />
      )}
      {step === 4 && (
        <PreviewStep
          campaign={campaign}
          canTest={isAuthor}
          flush={flush}
          onTestSent={() => setTestSentAt(new Date())}
          onNext={() => void goTo(5)}
        />
      )}
      {step === 5 && (
        <SendStep
          campaign={campaign}
          content={content}
          audience={audience}
          audienceLoading={audienceLoading}
          isAuthor={isAuthor}
          testSentAt={testSentAt}
          flush={flush}
          onSent={onSent}
          onGoTo={(s) => void goTo(s)}
        />
      )}
    </>
  )
}

/* ---------- Krok 1: produkty ---------- */

function ItemsStep({
  campaign,
  editable,
  mutate,
  onError,
  onNext,
}: {
  campaign: Campaign
  editable: boolean
  mutate: Mutate
  onError: (message: string) => void
  onNext: () => void
}) {
  const { user } = useAuth()
  const navigate = useNavigate()
  const [cardFor, setCardFor] = useState<CampaignItem | null>(null)
  const [suggestOpen, setSuggestOpen] = useState(false)
  const items = campaign.items
  const full = items.length >= MAX_ITEMS
  // czy obecny układ produktów pokazuje krótki opis (podpowiedź przy polu „Opis w mailu”)
  const productsBlock = campaign.blocks.find((b) => b.type === 'products')
  const layout = productsBlock?.type === 'products' ? productsBlock.layout : null
  const layoutShowsDescription = layout !== null && LAYOUTS_WITH_DESCRIPTION.includes(layout)

  const patchItem = (item: CampaignItem, patch: CampaignItemPatch) =>
    mutate(() => updateCampaignItem(campaign.id, item.id, patch), 'Nie udało się zapisać pozycji.')

  const stockValue = items.reduce((s, i) => (i.stock != null && i.unit_cost != null ? s + i.stock * i.unit_cost : s), 0)
  const noCost = items.filter((i) => i.unit_cost == null).length
  const belowCost = items.filter((i) => i.warnings.below_cost).length
  const noImage = items.filter((i) => i.warnings.no_image).length
  const noStock = items.filter((i) => i.warnings.no_stock).length
  const inOther = items.filter((i) => i.warnings.other_campaigns.length > 0).length
  const margin = user?.id === campaign.author.id ? user?.default_margin_percent : undefined

  return (
    <div className="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_300px]">
      {suggestOpen && (
        <CampaignSuggestionsModal
          campaignId={campaign.id}
          onClose={() => setSuggestOpen(false)}
          onAdd={async (erpItemIds) =>
            (await mutate(() => addCampaignItems(campaign.id, { erp_item_ids: erpItemIds }), 'Nie udało się dodać pozycji.')) !== null
          }
        />
      )}
      <div className="rounded-xl bg-white shadow-sm">
        <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-4 py-2.5">
          <b className="text-sm text-slate-900">Produkty w kampanii</b>
          {editable && (
            <div className="flex flex-wrap gap-2">
              {/* pełne listy z filtrami i zdjęciami; pasek „Dodaj do K-…” wraca tu po dodaniu */}
              <button type="button" className={BTN} disabled={full} onClick={() => navigate(`/zapasy?kampania=${campaign.id}`)}>
                + z Zapasów
              </button>
              <button type="button" className={BTN} disabled={full} onClick={() => navigate(`/products?kampania=${campaign.id}`)}>
                + z Produktów
              </button>
              <button
                type="button"
                className={BTN}
                disabled={full}
                title="Ranking zalegającego towaru: wartość, czas zalegania, ilu klientów kupowało, gotowość karty"
                onClick={() => setSuggestOpen(true)}
              >
                Zaproponuj pozycje
              </button>
            </div>
          )}
        </div>
        <div className="overflow-x-auto">
          <table className="w-full text-left text-xs">
            <thead>
              <tr className="border-b bg-slate-50">
                <th className="p-2">Produkt</th>
                <th className="p-2 text-right">Dostępne</th>
                <th className="p-2 text-right">Koszt</th>
                <th className="p-2 text-right">Cena kampanii netto</th>
                <th className="p-2 text-right">Cena przed</th>
                {editable && <th className="p-2" />}
              </tr>
            </thead>
            <tbody>
              {items.map((item) => (
                <ItemRow
                  key={item.id}
                  item={item}
                  editable={editable}
                  margin={margin}
                  onPatch={(p) => patchItem(item, p)}
                  layoutShowsDescription={layoutShowsDescription}
                  brandColor={campaign.brand_color ?? DEFAULT_BRAND_COLOR}
                  onRemove={() => void mutate(() => removeCampaignItem(campaign.id, item.id), 'Nie udało się usunąć pozycji.')}
                  onPickCard={() => setCardFor(item)}
                  onInvalid={onError}
                />
              ))}
              {items.length === 0 && (
                <tr>
                  <td colSpan={editable ? 6 : 5} className="p-8 text-center text-slate-500">
                    Kampania nie ma jeszcze produktów. Dodaj towar z Zapasów (zalegający w magazynie) albo kartę z Produktów.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
        <p className="px-4 py-3 text-[11px] text-slate-500">
          „Dostępne” = stan wszystkich magazynów handlowych z nocnego odczytu ERP XL. W mailu klient zobaczy stan z dniem
          odczytu i „do wyczerpania zapasów”. Cenę przed wpisuj tylko, gdy naprawdę taka była (np. cennik) — nigdy
          wymyśloną; puste pole = w mailu bez przekreślonej ceny. Najwyżej {MAX_ITEMS} pozycji w kampanii.
        </p>
      </div>

      <aside className="rounded-xl bg-white p-4 shadow-sm">
        <h2 className="app-card-title mb-2 text-sm font-semibold text-slate-900">Kampania</h2>
        <dl className="grid grid-cols-[1fr_auto] gap-x-3 gap-y-1.5 text-xs">
          <dt className="text-slate-600">Pozycje</dt>
          <dd className="text-right font-medium tabular-nums">
            {items.length} z {MAX_ITEMS}
          </dd>
          <dt className="text-slate-600">Zapas w kampanii</dt>
          <dd className="text-right font-medium tabular-nums" title="Stan × koszt zakupu (partie z XL)">
            {items.length > 0 && noCost < items.length ? formatPln(stockValue) : '—'}
          </dd>
          {noCost > 0 && (
            <>
              <dt className="text-slate-500">— w tym bez kosztu</dt>
              <dd className="text-right tabular-nums text-slate-500">{noCost}</dd>
            </>
          )}
          <SummaryRow label="Poniżej kosztu" n={belowCost} warn />
          <SummaryRow label="Bez zdjęcia" n={noImage} warn />
          <SummaryRow label="Bez stanu" n={noStock} warn />
          <SummaryRow label="W innej kampanii" n={inOther} />
        </dl>
        <p className="mt-3 text-[11px] text-slate-500">
          Ostrzeżenia nie blokują wysyłki. Wyprzedaż poniżej kosztu bywa świadomą decyzją.
        </p>
        <button type="button" className={`${BTN_PRIMARY} mt-3 w-full`} onClick={onNext}>
          Dalej: odbiorcy →
        </button>
      </aside>

      {cardFor && (
        <ProductPicker
          title={`Karta dla: ${cardFor.name}`}
          confirmLabel="Użyj tej karty"
          note="Z karty bierzemy zdjęcie i nazwę do maila. Kod, stan i koszt zostają z towaru XL."
          onClose={() => setCardFor(null)}
          onPick={async (productId) => {
            const res = await patchItem(cardFor, { product_id: productId })
            if (res) setCardFor(null)
          }}
        />
      )}
    </div>
  )
}

function SummaryRow({ label, n, warn }: { label: string; n: number; warn?: boolean }) {
  return (
    <>
      <dt className="text-slate-600">{label}</dt>
      <dd className={`text-right font-medium tabular-nums ${warn && n > 0 ? 'text-amber-800' : ''}`}>
        {n === 0 ? '—' : `${n} ${plural(n, 'pozycja', 'pozycje', 'pozycji')}`}
      </dd>
    </>
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
  editable,
  margin,
  onPatch,
  layoutShowsDescription,
  brandColor,
  onRemove,
  onPickCard,
  onInvalid,
}: {
  item: CampaignItem
  editable: boolean
  margin: number | undefined
  onPatch: (patch: CampaignItemPatch) => Promise<Campaign | null>
  layoutShowsDescription: boolean
  /** Kolor „Zapytaj o ofertę” w mailu — do podglądu w oknie linku. */
  brandColor: string
  onRemove: () => void
  onPickCard: () => void
  onInvalid: (message: string) => void
}) {
  const w = item.warnings
  return (
    <tr className="border-b align-top">
      <td className="min-w-[18rem] p-2">
        <div className="flex gap-2.5">
          <Thumb url={item.image_url} />
          <div className="min-w-0">
            <b className="block font-medium text-slate-900">{item.name}</b>
            <span className="font-mono text-[11px] text-slate-500">{item.code}</span>
            <div className="mt-1 flex flex-wrap gap-1">
              {w.below_cost && (
                <Chip tone="amber">poniżej kosztu zakupu{item.unit_cost != null ? ` (${formatPln(item.unit_cost)})` : ''}</Chip>
              )}
              {w.no_stock && <Chip tone="red">brak na stanie</Chip>}
              {w.no_image && <Chip tone="amber">bez zdjęcia</Chip>}
              {item.erp_item_id == null && item.product_id == null && item.card == null && (
                <Chip tone="red" title="Towaru nie ma już w XL ani w katalogu">brak towaru i karty</Chip>
              )}
              {w.other_campaigns.map((o) => (
                <Link key={o.id} to={`/kampanie/${o.id}`} className="hover:underline">
                  <Chip tone="blue" title={`Kampania ${o.code}`}>
                    też w kampanii „{o.name}” ({o.author})
                  </Chip>
                </Link>
              ))}
            </div>
            <CardLine item={item} editable={editable} onPatch={onPatch} onPickCard={onPickCard} />
            <DescriptionField item={item} editable={editable} onPatch={onPatch} layoutShowsDescription={layoutShowsDescription} />
            <LinkField item={item} editable={editable} onPatch={onPatch} brandColor={brandColor} />
          </div>
        </div>
      </td>
      <td className="whitespace-nowrap p-2 text-right tabular-nums">
        <span className="font-medium text-slate-800">{fmtQty(item.stock, item.unit)}</span>
        {item.stock_synced_at && <div className="text-[10px] text-slate-500">stan z {fmtDate(item.stock_synced_at)}</div>}
      </td>
      <td className="whitespace-nowrap p-2 text-right tabular-nums text-slate-700">
        {item.unit_cost != null ? formatPln(item.unit_cost) : <span className="text-slate-400">—</span>}
      </td>
      <td className="whitespace-nowrap p-2 text-right">
        <MoneyInput
          value={item.promo_price_net}
          disabled={!editable}
          label={`Cena kampanii netto: ${item.name}`}
          onCommit={(v) => onPatch({ promo_price_net: v }).then(Boolean)}
          onInvalid={() => onInvalid('Cena kampanii: wpisz kwotę, np. 89,00.')}
        />
        {item.suggested_price != null && (
          <div className="mt-0.5 text-[10px] text-slate-500">
            sugerowana {formatPln(item.suggested_price)}
            {margin != null ? ` (koszt + ${margin.toLocaleString('pl-PL')}%)` : ''}
            {editable && item.promo_price_net !== item.suggested_price && (
              <button
                type="button"
                className="ml-1 text-blue-600 hover:underline"
                onClick={() => void onPatch({ promo_price_net: item.suggested_price })}
              >
                wstaw
              </button>
            )}
          </div>
        )}
        {item.promo_price_net == null && <div className="text-[10px] text-amber-800">bez ceny w mailu</div>}
      </td>
      <td className="whitespace-nowrap p-2 text-right">
        <MoneyInput
          value={item.price_before_net}
          disabled={!editable}
          label={`Cena przed: ${item.name}`}
          placeholder="—"
          onCommit={(v) => onPatch({ price_before_net: v }).then(Boolean)}
          onInvalid={() => onInvalid('Cena przed: wpisz kwotę, np. 149,00, albo zostaw puste.')}
        />
        <div className="mt-0.5 text-[10px] text-slate-500">{item.price_before_net == null ? 'nie pokazujemy' : 'przekreślona w mailu'}</div>
      </td>
      {editable && (
        <td className="p-2 text-right">
          <button type="button" className={BTN_SM} aria-label={`Usuń z kampanii: ${item.name}`} title="Usuń z kampanii" onClick={onRemove}>
            ×
          </button>
        </td>
      )}
    </tr>
  )
}

/** Drugi przycisk w mailu pod „Zapytaj o ofertę” (np. do sklepu): podgląd, „Dodaj link”, zmiana i usunięcie. */
function LinkField({
  item,
  editable,
  onPatch,
  brandColor,
}: {
  item: CampaignItem
  editable: boolean
  onPatch: (patch: CampaignItemPatch) => Promise<Campaign | null>
  brandColor: string
}) {
  const [open, setOpen] = useState(false)
  const link = item.link
  if (!link && !editable) return null
  return (
    <div className="mt-1.5 flex max-w-[34rem] flex-wrap items-center gap-x-1.5 gap-y-1 text-[11px]">
      {link ? (
        <>
          <span className="font-medium text-slate-700">Przycisk w mailu:</span>
          <span className="rounded px-2 py-0.5 font-bold text-white" style={{ backgroundColor: link.color }}>
            {link.label}
          </span>
          <a
            href={link.url}
            target="_blank"
            rel="noopener noreferrer"
            className="max-w-[16rem] truncate text-blue-600 hover:underline"
            title={link.url}
          >
            {link.url}
          </a>
          {editable && (
            <>
              <button type="button" className="text-blue-600 hover:underline" onClick={() => setOpen(true)}>
                zmień
              </button>
              <button
                type="button"
                className="text-red-700 hover:underline"
                onClick={() => void onPatch({ link_url: null, link_label: null, link_color: null })}
              >
                usuń
              </button>
            </>
          )}
        </>
      ) : (
        <button type="button" className={BTN_SM} onClick={() => setOpen(true)}>
          + Dodaj link
        </button>
      )}
      {open && (
        <ItemLinkModal
          item={item}
          brandColor={brandColor}
          onClose={() => setOpen(false)}
          onSave={async (next) => {
            if (await onPatch({ link_url: next.url, link_label: next.label, link_color: next.color })) setOpen(false)
          }}
        />
      )}
    </div>
  )
}

/** Jak CampaignBlocks::validUrl bez mailto: https://, host bez spacji, „@” i dwukropka. */
function validLinkUrl(url: string): boolean {
  return /^https:\/\/[^/?#@:\s]+(:\d{1,5})?([/?#]\S*)?$/i.test(url)
}

/** Okno „Dodaj link”: adres (np. do sklepu), nazwa przycisku i kolor; podgląd obu przycisków jak w mailu. */
function ItemLinkModal({
  item,
  brandColor,
  onClose,
  onSave,
}: {
  item: CampaignItem
  brandColor: string
  onClose: () => void
  onSave: (link: CampaignItemLink) => Promise<void>
}) {
  const [url, setUrl] = useState(item.link?.url ?? '')
  const [label, setLabel] = useState(item.link?.label ?? '')
  // domyślnie inny kolor niż „Zapytaj o ofertę”, żeby przyciski się odróżniały
  const [color, setColor] = useState<string>(item.link?.color ?? BRAND_COLORS.find((c) => c !== brandColor) ?? DEFAULT_BRAND_COLOR)
  const [busy, setBusy] = useState(false)
  const [touched, setTouched] = useState(false)

  const cleanUrl = url.trim()
  const cleanLabel = label.trim()
  const urlError =
    cleanUrl === '' ? 'Wpisz link.' : !validLinkUrl(cleanUrl) ? 'Link musi zaczynać się od https:// i nie może zawierać spacji.' : ''
  const labelError = cleanLabel === '' ? 'Wpisz nazwę przycisku.' : ''

  async function save() {
    setTouched(true)
    if (urlError || labelError) return
    setBusy(true)
    try {
      await onSave({ url: cleanUrl, label: cleanLabel, color })
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal
      title={item.link ? 'Zmień link przy produkcie' : 'Dodaj link przy produkcie'}
      busy={busy}
      onClose={onClose}
      footer={
        <>
          <button type="button" className={BTN} onClick={onClose} disabled={busy}>
            Anuluj
          </button>
          <button type="button" className={BTN_PRIMARY} disabled={busy} onClick={() => void save()}>
            {busy ? 'Zapisuję…' : 'Zapisz'}
          </button>
        </>
      }
    >
      <div className="space-y-3 text-xs">
        <p className="text-slate-600">
          W mailu pod „Zapytaj o ofertę” przy produkcie <b className="font-medium text-slate-800">{item.name}</b> pojawi się
          drugi przycisk prowadzący pod ten link.
        </p>
        <label className="block">
          <span className="mb-1 block font-medium text-slate-700">Link</span>
          <input
            className={`${INPUT} w-full`}
            type="url"
            inputMode="url"
            maxLength={500}
            placeholder="https://…"
            value={url}
            autoFocus
            onChange={(e) => setUrl(e.target.value)}
          />
          {touched && urlError && <span className="mt-0.5 block text-red-700">{urlError}</span>}
        </label>
        <label className="block">
          <span className="mb-1 block font-medium text-slate-700">Nazwa przycisku</span>
          <input
            className={`${INPUT} w-full`}
            maxLength={ITEM_LINK_LABEL_MAX}
            placeholder="np. Kup w sklepie"
            value={label}
            onChange={(e) => setLabel(e.target.value)}
          />
          <span className="mt-0.5 block text-slate-500">
            {label.length}/{ITEM_LINK_LABEL_MAX}
          </span>
          {touched && labelError && <span className="block text-red-700">{labelError}</span>}
        </label>
        <div>
          <span className="mb-1 block font-medium text-slate-700">Kolor przycisku</span>
          <div className="flex flex-wrap items-center gap-2" role="radiogroup" aria-label="Kolor przycisku">
            {BRAND_COLORS.map((c) => {
              const on = c === color
              return (
                <button
                  key={c}
                  type="button"
                  role="radio"
                  aria-checked={on}
                  aria-label={BRAND_COLOR_LABEL[c] ?? c}
                  title={BRAND_COLOR_LABEL[c] ?? c}
                  onClick={() => setColor(c)}
                  className={`h-6 w-6 rounded-full border-2 ${on ? 'border-slate-900 ring-2 ring-slate-300' : 'border-white ring-1 ring-slate-300'}`}
                  style={{ backgroundColor: c }}
                />
              )
            })}
            <span className="text-slate-500">{BRAND_COLOR_LABEL[color] ?? color}</span>
          </div>
        </div>
        <div>
          <span className="mb-1 block font-medium text-slate-700">Podgląd w mailu</span>
          <div className="w-48 space-y-1.5 rounded-md border border-slate-200 p-2.5">
            <div className="rounded py-1.5 text-center text-[12.5px] font-bold text-white" style={{ backgroundColor: brandColor }}>
              Zapytaj o ofertę
            </div>
            <div
              className="break-words rounded px-1 py-1.5 text-center text-[12.5px] font-bold text-white"
              style={{ backgroundColor: color }}
            >
              {cleanLabel || 'Nazwa przycisku'}
            </div>
          </div>
        </div>
      </div>
    </Modal>
  )
}

/**
 * Krótki opis w mailu (układy „z opisem”): pusty = wycinek opisu karty (pierwsze zdania, nic dopisanego).
 * Zapis po wyjściu z pola; pusty tekst przywraca opis z karty.
 */
function DescriptionField({
  item,
  editable,
  onPatch,
  layoutShowsDescription,
}: {
  item: CampaignItem
  editable: boolean
  onPatch: (patch: CampaignItemPatch) => Promise<Campaign | null>
  layoutShowsDescription: boolean
}) {
  const [value, setValue] = useState(item.description ?? '')
  const [open, setOpen] = useState(item.description !== null)
  useEffect(() => {
    setValue(item.description ?? '')
  }, [item.description])

  function commit() {
    const next = value.trim() === '' ? null : value.trim()
    if (next !== item.description) void onPatch({ description: next })
  }

  const inMail = item.description ?? item.card_excerpt
  return (
    <div className="mt-1.5 max-w-[34rem] text-[11px]">
      <div className="flex flex-wrap items-baseline gap-x-1.5 text-slate-600">
        <span className="font-medium text-slate-700">Opis w mailu:</span>
        {!layoutShowsDescription && <span className="text-slate-400">(obecny układ go nie pokazuje)</span>}
        {!open && (
          <>
            <span className={inMail ? 'text-slate-700' : 'text-slate-400'}>
              {inMail ?? (item.card ? 'brak — karta nie ma opisu' : 'brak — pozycja nie ma karty')}
              {item.description === null && item.card_excerpt !== null && <span className="text-slate-400"> (z karty)</span>}
            </span>
            {editable && (
              <button
                type="button"
                className="text-blue-600 hover:underline"
                onClick={() => {
                  setValue(item.description ?? item.card_excerpt ?? '')
                  setOpen(true)
                }}
              >
                {inMail ? 'popraw' : 'wpisz'}
              </button>
            )}
          </>
        )}
      </div>
      {open && (
        <>
          <textarea
            className="mt-1 w-full rounded border border-slate-300 px-2 py-1 text-xs"
            rows={2}
            maxLength={300}
            disabled={!editable}
            value={value}
            placeholder={item.card_excerpt ?? '1–2 zdania o produkcie'}
            aria-label={`Opis w mailu: ${item.name}`}
            onChange={(e) => setValue(e.target.value)}
            onBlur={commit}
          />
          <div className="flex flex-wrap gap-x-2 text-slate-500">
            <span>{value.length}/300 · zapis po wyjściu z pola</span>
            {editable && item.card_excerpt !== null && (
              <button
                type="button"
                className="text-blue-600 hover:underline"
                onClick={() => {
                  setValue('')
                  setOpen(false)
                  if (item.description !== null) void onPatch({ description: null })
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

function CardLine({
  item,
  editable,
  onPatch,
  onPickCard,
}: {
  item: CampaignItem
  editable: boolean
  onPatch: (patch: CampaignItemPatch) => Promise<Campaign | null>
  onPickCard: () => void
}) {
  if (item.card) {
    return (
      <div className="mt-1 flex flex-wrap items-center gap-x-1.5 gap-y-0.5 text-[11px] text-slate-600">
        <span>
          Karta:{' '}
          <Link to={`/products/${item.card.id}`} className="font-mono text-blue-600 hover:underline">
            {item.card.sku}
          </Link>
          {item.product_id != null ? ' (wybrana ręcznie)' : ' (główna karta towaru)'}
        </span>
        {editable && (
          <>
            <button type="button" className="text-blue-600 hover:underline" onClick={onPickCard}>
              inna karta
            </button>
            {item.product_id != null && item.erp_item_id != null && (
              <button type="button" className="text-blue-600 hover:underline" onClick={() => void onPatch({ product_id: null })}>
                wróć do głównej
              </button>
            )}
          </>
        )}
      </div>
    )
  }
  if (item.card_suggestion) {
    const s = item.card_suggestion
    return (
      <div className="mt-1 flex flex-wrap items-center gap-1.5 text-[11px] text-slate-600">
        <span>
          Propozycja karty: <b className="font-medium text-slate-800">{s.name}</b>{' '}
          <span className="font-mono">({s.sku})</span>
        </span>
        {editable && (
          <>
            <button
              type="button"
              className="rounded bg-blue-600 px-2 py-0.5 text-[11px] font-medium text-white hover:bg-blue-700"
              onClick={() => void onPatch({ product_id: s.product_id })}
            >
              Użyj tej karty
            </button>
            <button type="button" className={BTN_SM} onClick={onPickCard}>
              Inna karta
            </button>
          </>
        )}
      </div>
    )
  }
  return (
    <div className="mt-1 flex flex-wrap items-center gap-1.5 text-[11px] text-slate-500">
      <span>Bez karty — w mailu bez zdjęcia, nazwa z XL.</span>
      {editable && (
        <button type="button" className={BTN_SM} onClick={onPickCard}>
          Wybierz kartę
        </button>
      )}
    </div>
  )
}

/** Pole kwoty: szkic lokalnie, zapis po wyjściu z pola (albo Enter); niepoprawny wpis wraca do zapisanej wartości. */
function MoneyInput({
  value,
  disabled,
  label,
  placeholder,
  onCommit,
  onInvalid,
}: {
  value: number | null
  disabled: boolean
  label: string
  placeholder?: string
  /** false = serwer nie przyjął — pole wraca do zapisanej wartości. */
  onCommit: (value: number | null) => Promise<boolean>
  onInvalid: () => void
}) {
  const [draft, setDraft] = useState(moneyInputValue(value))
  const [focused, setFocused] = useState(false)
  const [lastValue, setLastValue] = useState(value)
  // Nowa wartość z serwera (np. „wstaw” sugerowaną) — pole idzie za nią, o ile człowiek właśnie w nim nie pisze.
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
        disabled={disabled}
        placeholder={placeholder}
        className={`${INPUT} w-24 text-right tabular-nums`}
        value={draft}
        onFocus={() => setFocused(true)}
        onChange={(e) => setDraft(e.target.value)}
        onKeyDown={(e) => {
          if (e.key === 'Enter') e.currentTarget.blur()
          if (e.key === 'Escape') {
            setDraft(moneyInputValue(value))
            e.currentTarget.blur()
          }
        }}
        onBlur={() => {
          setFocused(false)
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

/** Okno wyboru karty z katalogu (ProductSearchSelect): dodanie karty do kampanii albo zmiana karty pozycji. */
function ProductPicker({
  title,
  confirmLabel,
  note,
  onClose,
  onPick,
}: {
  title: string
  confirmLabel: string
  note: string
  onClose: () => void
  onPick: (productId: number) => Promise<void>
}) {
  const [value, setValue] = useState('')
  const [busy, setBusy] = useState(false)

  async function confirm() {
    const id = Number(value)
    if (!id) return
    setBusy(true)
    try {
      await onPick(id)
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal
      title={title}
      busy={busy}
      onClose={onClose}
      footer={
        <>
          <button type="button" className={BTN} onClick={onClose} disabled={busy}>
            Anuluj
          </button>
          <button type="button" className={BTN_PRIMARY} disabled={busy || !value} onClick={() => void confirm()}>
            {busy ? 'Zapisuję…' : confirmLabel}
          </button>
        </>
      }
    >
      <div className="min-h-[300px]">
        <p className="mb-2 text-xs text-slate-600">{note}</p>
        <ProductSearchSelect products={[]} value={value} onChange={(pid) => setValue(pid)} className="w-full max-w-full" />
      </div>
    </Modal>
  )
}

/* ---------- Krok 2: odbiorcy ---------- */

function AudienceStep({
  campaign,
  editable,
  mutate,
  audience,
  audienceLoading,
  audienceErr,
  onNext,
  nextLabel = 'Dalej: treść →',
}: {
  campaign: Campaign
  editable: boolean
  mutate: Mutate
  audience: AudiencePreview | null
  audienceLoading: boolean
  audienceErr: string
  onNext: () => void
  /** „Dopisz odbiorców” w wysłanej kampanii ma inny następny krok niż kreator. */
  nextLabel?: string
}) {
  const { user } = useAuth()
  const [lists, setLists] = useState<MailingList[] | null>(null)
  const [listsErr, setListsErr] = useState('')
  // Wybór pokazywany od razu (kolejne kliknięcia budują się na nim), zapis PATCH w tle.
  const [draft, setDraft] = useState<CampaignAudience>(campaign.audience)
  const [lastServer, setLastServer] = useState(campaign.audience)
  const [xlPicker, setXlPicker] = useState<CampaignXlMode | null>(null)
  const [listPicker, setListPicker] = useState<MailingList | null>(null)
  if (campaign.audience !== lastServer) {
    setLastServer(campaign.audience)
    setDraft(campaign.audience)
  }

  useEffect(() => {
    listMailingLists()
      .then((r) => setLists(r.data))
      .catch((ex: unknown) => setListsErr(errorText(ex, 'Nie udało się wczytać grup.')))
  }, [])

  function save(next: CampaignAudience) {
    setDraft(next)
    void mutate(() => updateCampaign(campaign.id, { audience: next }), 'Nie udało się zapisać odbiorców.').then((res) => {
      if (!res) setDraft(campaign.audience)
    })
  }

  // starsza odpowiedź bez pola = brak odznaczeń
  const exclusions = draft.list_exclusions ?? []
  const excludedIn = (listId: number) => exclusions.find((e) => e.list_id === listId)?.contact_ids ?? []

  /** Odznaczenie grupy usuwa też jej odznaczone adresy (ponowne zaznaczenie = cała grupa). */
  function toggleList(id: number, on: boolean) {
    const ids = on ? [...new Set([...draft.list_ids, id])] : draft.list_ids.filter((x) => x !== id)
    save({ ...draft, list_ids: ids, list_exclusions: on ? exclusions : exclusions.filter((e) => e.list_id !== id) })
  }

  /** Zapis z okna „Pokaż / wybierz” grupy: zaznacza grupę z odznaczeniami albo — nic nie wybrano — odznacza ją. */
  async function saveListChoice(id: number, choice: ListChoice): Promise<boolean> {
    const others = exclusions.filter((e) => e.list_id !== id)
    const next: CampaignAudience = choice.include
      ? {
          ...draft,
          list_ids: [...new Set([...draft.list_ids, id])],
          list_exclusions: choice.excluded.length > 0 ? [...others, { list_id: id, contact_ids: choice.excluded }] : others,
        }
      : { ...draft, list_ids: draft.list_ids.filter((x) => x !== id), list_exclusions: others }
    setDraft(next)
    const res = await mutate(() => updateCampaign(campaign.id, { audience: next }), 'Nie udało się zapisać wyboru adresów.')
    if (!res) setDraft(campaign.audience)
    return Boolean(res)
  }

  const xl = draft.xl
  const showMineHint = xl.mode !== null && (xl.mode === 'mine' || xl.only_mine)

  return (
    <div className="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_300px]">
      <div className="space-y-4">
        <div className="rounded-xl bg-white shadow-sm">
          <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-4 py-2.5">
            <b className="text-sm text-slate-900">Wybierz grupy odbiorców</b>
            <Link to="/kampanie?tab=grupy" className="text-xs text-blue-600 hover:underline">
              Zarządzaj grupami
            </Link>
          </div>
          {listsErr && <p className="px-4 py-2 text-xs text-red-700">{listsErr}</p>}
          {lists === null && !listsErr && <p className="px-4 py-3 text-xs text-slate-500">Ładowanie…</p>}
          {lists !== null && lists.length === 0 && (
            <p className="px-4 py-3 text-xs text-slate-500">
              Nie masz jeszcze grup.{' '}
              <Link to="/kampanie?tab=grupy" className="text-blue-600 hover:underline">
                Załóż grupę i wklej adresy
              </Link>{' '}
              albo wybierz klientów z ERP XL niżej.
            </p>
          )}
          {(lists ?? []).map((l) => {
            const checked = draft.list_ids.includes(l.id)
            const excluded = checked ? excludedIn(l.id).length : 0
            const who = l.is_shared ? `wspólna grupa (${l.owner.name})` : l.owner.id === user?.id ? 'moja grupa' : `grupa: ${l.owner.name}`
            return (
              <label
                key={l.id}
                className="grid cursor-pointer grid-cols-[22px_minmax(0,1fr)_auto] items-center gap-2.5 border-b border-slate-200 px-4 py-2.5 text-xs last:border-b-0 hover:bg-slate-50"
              >
                <input type="checkbox" checked={checked} disabled={!editable} onChange={(e) => toggleList(l.id, e.target.checked)} />
                <span>
                  <b className="font-medium text-slate-900">{l.name}</b>
                  <small className="block text-[11px] text-slate-500">
                    {who} · {fmtInt(l.contacts_count)} {plural(l.contacts_count, 'adres', 'adresy', 'adresów')} · podstawa: stały klient{' '}
                    {fmtInt(l.basis_counts.customer)}, zgoda {fmtInt(l.basis_counts.consent)}
                  </small>
                  {excluded > 0 && (
                    <small className="mt-0.5 block text-[11px] font-medium text-blue-700">
                      wybrano: {fmtInt(Math.max(0, l.contacts_count - excluded))} z {fmtInt(l.contacts_count)}{' '}
                      {plural(l.contacts_count, 'adresu', 'adresów', 'adresów')}
                    </small>
                  )}
                </span>
                <span className="flex items-center gap-2">
                  <Chip tone={l.contacts_count > 0 ? 'green' : 'red'}>{fmtInt(l.contacts_count)}</Chip>
                  <button
                    type="button"
                    className={BTN_SM}
                    title="Otwiera adresy tej grupy — możesz je przejrzeć, wyszukać i odznaczyć część"
                    onClick={(e) => {
                      e.preventDefault()
                      e.stopPropagation()
                      setListPicker(l)
                    }}
                  >
                    {editable ? 'Pokaż / wybierz' : 'Pokaż'}
                  </button>
                </span>
              </label>
            )
          })}
        </div>

        <div className="rounded-xl bg-white shadow-sm">
          <div className="border-b border-slate-200 px-4 py-2.5">
            <b className="text-sm text-slate-900">Klienci z ERP XL</b>
            <p className="text-[11px] text-slate-500">
              E-maile z kartotek kontrahentów XL (odczyt nocny). Podstawa wysyłki: stały klient. Adresy typu faktury@ i
              księgowość@ pomijamy.
            </p>
          </div>
          <div className="space-y-3 px-4 py-3 text-xs">
            <fieldset className="grid gap-1.5 sm:grid-cols-2">
              <legend className="sr-only">Którzy klienci z XL</legend>
              {XL_MODES.map((m) => (
                <label
                  key={m.value ?? 'none'}
                  className={`flex cursor-pointer items-start gap-2 rounded border px-2.5 py-2 ${
                    xl.mode === m.value ? 'border-blue-600 bg-sky-50' : 'border-slate-200 hover:bg-slate-50'
                  }`}
                >
                  <input
                    type="radio"
                    name="xl-mode"
                    className="mt-0.5"
                    disabled={!editable}
                    checked={xl.mode === m.value}
                    onChange={() =>
                      save({
                        ...draft,
                        xl: { ...xl, mode: m.value, only_mine: m.value === 'mine' || m.value === null ? false : xl.only_mine, customer_ids: null },
                      })
                    }
                  />
                  <span className="min-w-0 flex-1">
                    <b className="font-medium text-slate-900">{m.label}</b>
                    <small className="block text-[11px] text-slate-500">{m.hint}</small>
                    {m.value !== null && xl.mode === m.value && (xl.customer_ids ?? null) !== null && (
                      <small className="mt-0.5 block text-[11px] font-medium text-blue-700">
                        wybrano ręcznie: {fmtInt(xl.customer_ids?.length ?? 0)}{' '}
                        {plural(xl.customer_ids?.length ?? 0, 'klient', 'klientów', 'klientów')}
                      </small>
                    )}
                  </span>
                  {m.value !== null && (
                    <button
                      type="button"
                      className={`${BTN_SM} shrink-0 self-center`}
                      title="Otwiera listę klientów tej kategorii z adresami e-mail — możesz ich przejrzeć, wyszukać i zaznaczyć"
                      onClick={(e) => {
                        e.preventDefault()
                        e.stopPropagation()
                        setXlPicker(m.value)
                      }}
                    >
                      {editable ? 'Pokaż / wybierz' : 'Pokaż'}
                    </button>
                  )}
                </label>
              ))}
            </fieldset>
            <div className={`flex flex-wrap items-center gap-x-5 gap-y-2 ${xl.mode === null ? 'opacity-50' : ''}`}>
              <span className="inline-flex items-center gap-2">
                <span id="xl-months" className="text-slate-600">
                  Kupowali w ostatnich
                </span>
                <span className="inline-flex overflow-hidden rounded border border-slate-300" role="group" aria-labelledby="xl-months">
                  {([12, 24] as const).map((m, i) => (
                    <button
                      key={m}
                      type="button"
                      aria-pressed={xl.months === m}
                      disabled={!editable || xl.mode === null}
                      onClick={() => save({ ...draft, xl: { ...xl, months: m, customer_ids: null } })}
                      className={`px-2.5 py-1 tabular-nums ${i > 0 ? 'border-l border-slate-300' : ''} ${
                        xl.months === m ? 'bg-blue-600 font-semibold text-white' : 'bg-white text-slate-700 hover:bg-slate-50'
                      }`}
                    >
                      {m} mies.
                    </button>
                  ))}
                </span>
              </span>
              <label className="inline-flex items-center gap-1.5 text-slate-700" title="Tylko kontrahenci, którym najczęściej wystawiasz dokumenty w XL">
                <input
                  type="checkbox"
                  disabled={!editable || xl.mode === null || xl.mode === 'mine'}
                  checked={xl.mode === 'mine' || xl.only_mine}
                  onChange={(e) => save({ ...draft, xl: { ...xl, only_mine: e.target.checked, customer_ids: null } })}
                />
                tylko moi klienci
              </label>
            </div>
            {showMineHint && (
              <p className="text-[11px] text-slate-500">
                „Moi klienci” = kontrahenci, którym najwięcej faktur i paragonów wystawił Twój operator XL. Jeśli administrator
                nie powiązał Twojego konta z operatorem XL, z XL nie będzie nikogo (ostrzeżenie pokaże się w kroku 5).
              </p>
            )}
          </div>
        </div>
      </div>

      {listPicker !== null && (
        <ListContactsModal
          campaignId={campaign.id}
          listId={listPicker.id}
          listName={listPicker.name}
          initialExcluded={draft.list_ids.includes(listPicker.id) ? excludedIn(listPicker.id) : []}
          editable={editable}
          onClose={() => setListPicker(null)}
          onSave={(choice) => saveListChoice(listPicker.id, choice)}
        />
      )}

      {xlPicker !== null && (
        <XlCustomersModal
          campaignId={campaign.id}
          editable={editable}
          initial={{
            mode: xlPicker,
            months: xl.months,
            only_mine: xlPicker === xl.mode ? xl.only_mine : false,
          }}
          onClose={() => setXlPicker(null)}
          onSave={async (choice) => {
            const next = { ...draft, xl: choice }
            setDraft(next)
            const res = await mutate(() => updateCampaign(campaign.id, { audience: next }), 'Nie udało się zapisać wyboru klientów.')
            if (!res) setDraft(campaign.audience)
            return Boolean(res)
          }}
        />
      )}

      <aside className="rounded-xl bg-white p-4 shadow-sm">
        <h2 className="app-card-title mb-2 text-sm font-semibold text-slate-900">Kto dostanie maila</h2>
        {audienceErr && <p className="mb-2 rounded bg-red-50 px-2 py-1.5 text-xs text-red-700">{audienceErr}</p>}
        {audience ? (
          <AudienceSummary a={audience} loading={audienceLoading} />
        ) : (
          <p className="text-xs text-slate-500">{audienceLoading ? 'Liczę…' : '—'}</p>
        )}
        {audience?.without_mailbox && (
          <p className="mt-3 rounded border border-amber-200 bg-amber-50 px-2 py-1.5 text-[11px] text-amber-900">
            Nie ustawiono skrzynki nadawcy.{' '}
            <Link to="/account" className="font-medium underline">
              Moje konto → Moja poczta
            </Link>
          </p>
        )}
        <p className="mt-3 text-[11px] text-slate-500">
          Maile wychodzą z Twojej skrzynki („Moja poczta”), więc odpowiedzi klientów wrócą prosto do Ciebie.
        </p>
        <button type="button" className={`${BTN_PRIMARY} mt-3 w-full`} onClick={onNext}>
          {nextLabel}
        </button>
      </aside>
    </div>
  )
}

function AudienceSummary({ a, loading }: { a: AudiencePreview; loading: boolean }) {
  const minus = (n: number) => (n > 0 ? `−${fmtInt(n)}` : '0')
  return (
    <div className={loading ? 'opacity-60' : ''}>
      <dl className="grid grid-cols-[1fr_auto] gap-x-3 gap-y-1.5 text-xs">
        <dt className="text-slate-600">Adresy z grup</dt>
        <dd className="text-right tabular-nums">{fmtInt(a.lists.contacts)}</dd>
        <dt className="text-slate-600">
          Klienci z ERP XL{' '}
          <span className="text-slate-400">
            ({fmtInt(a.xl.customers)} {plural(a.xl.customers, 'firma', 'firmy', 'firm')})
          </span>
        </dt>
        <dd className="text-right tabular-nums">{fmtInt(a.xl.emails)}</dd>
        <dt className="text-slate-600">Powtórzone adresy</dt>
        <dd className="text-right tabular-nums">{minus(a.duplicates)}</dd>
        <dt className="text-slate-600">Niepoprawne adresy</dt>
        <dd className="text-right tabular-nums">{minus(a.invalid)}</dd>
        <dt className="text-slate-600">Faktury@, księgowość@ (z XL)</dt>
        <dd className="text-right tabular-nums">{minus(a.excluded_generic)}</dd>
        <dt className="text-slate-600">Wypisani z mailingu</dt>
        <dd className="text-right tabular-nums">{minus(a.suppressed)}</dd>
        <dt className="text-slate-600">Dostali niedawno inną kampanię</dt>
        <dd className="text-right tabular-nums">{minus(a.capped)}</dd>
        {(a.already ?? 0) > 0 && (
          <>
            <dt className="text-slate-600">Już dostali tę kampanię</dt>
            <dd className="text-right tabular-nums">{minus(a.already ?? 0)}</dd>
          </>
        )}
        <dt className="border-t border-slate-200 pt-1.5 font-semibold text-slate-900">Wyślemy do</dt>
        <dd className="border-t border-slate-200 pt-1.5 text-right text-base font-semibold tabular-nums text-emerald-700">
          {fmtInt(a.final)}
        </dd>
      </dl>
      {(a.warnings ?? []).length > 0 && (
        <ul className="mt-3 space-y-1 rounded border border-amber-200 bg-amber-50 px-2 py-1.5 text-[11px] text-amber-900">
          {(a.warnings ?? []).map((w) => (
            <li key={w}>{w}</li>
          ))}
        </ul>
      )}
      {a.sample.length > 0 && (
        <details className="mt-3 text-xs">
          <summary className="cursor-pointer text-slate-600">Przykładowe adresy ({a.sample.length})</summary>
          <ul className="mt-1 max-h-48 space-y-0.5 overflow-y-auto">
            {a.sample.map((s) => (
              <li key={s.email} className="truncate" title={s.name ?? undefined}>
                <span className="font-mono text-slate-800">{s.email}</span>{' '}
                <span className="text-[10px] text-slate-500">{s.source === 'xl' ? 'XL' : 'grupa'}</span>
              </li>
            ))}
          </ul>
        </details>
      )}
    </div>
  )
}

/* ---------- Krok 3: treść ---------- */

function ContentStep({
  campaign,
  content,
  editable,
  onEdit,
  onBlur,
  onApplyTemplate,
  onNext,
}: {
  campaign: Campaign
  content: Content
  editable: boolean
  onEdit: (patch: Partial<Content>, immediate?: boolean) => void
  onBlur: () => void
  onApplyTemplate: (templateId: number | null) => Promise<boolean>
  onNext: () => void
}) {
  const field = `${INPUT} mt-1 block w-full text-sm`
  const [templates, setTemplates] = useState<CampaignTemplate[] | null>(null)
  const [templatesErr, setTemplatesErr] = useState('')
  const [choice, setChoice] = useState(() => (campaign.template_id != null ? String(campaign.template_id) : ''))
  const [confirmApply, setConfirmApply] = useState(false)
  const [applying, setApplying] = useState(false)
  // w trakcie wgrywania obrazka lista elementów nie może się podmienić (wynik trafiłby w element szablonu)
  const [uploading, setUploading] = useState(false)
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null)
  const [saveAsOpen, setSaveAsOpen] = useState(false)
  const [saveAsName, setSaveAsName] = useState('')
  const [saveAsBusy, setSaveAsBusy] = useState(false)
  const [saveAsErr, setSaveAsErr] = useState('')

  const loadTemplates = useCallback(async () => {
    try {
      setTemplates((await listTemplates()).data)
      setTemplatesErr('')
    } catch (ex) {
      setTemplatesErr(errorText(ex, 'Nie udało się wczytać szablonów.'))
    }
  }, [])

  useEffect(() => {
    void loadTemplates()
  }, [loadTemplates])

  const shared = (templates ?? []).filter((t) => t.is_shared)
  const own = (templates ?? []).filter((t) => !t.is_shared)
  const choiceName = choice === '' ? 'Standard SUPON' : (templates?.find((t) => String(t.id) === choice)?.name ?? 'szablon')

  async function apply() {
    setApplying(true)
    setMsg(null)
    const ok = await onApplyTemplate(choice === '' ? null : Number(choice))
    setApplying(false)
    setConfirmApply(false)
    setMsg(
      ok
        ? { ok: true, text: `Zastosowano: ${choiceName}. Elementy możesz dalej zmieniać — kampania ma własną kopię.` }
        : { ok: false, text: 'Nie udało się zastosować szablonu — komunikat jest u góry strony.' },
    )
  }

  async function saveAsTemplate() {
    const name = saveAsName.trim()
    if (!name || saveAsBusy) return
    setSaveAsBusy(true)
    setSaveAsErr('')
    try {
      await createTemplate({ name, blocks: content.blocks, brand_color: content.brand_color })
      setSaveAsOpen(false)
      setMsg({ ok: true, text: `Zapisano szablon „${name}” — jest w zakładce Moje szablony.` })
      void loadTemplates()
    } catch (ex) {
      setSaveAsErr(errorText(ex, 'Nie udało się zapisać szablonu.'))
    } finally {
      setSaveAsBusy(false)
    }
  }

  return (
    <div className="grid items-start gap-4 xl:grid-cols-[minmax(0,1fr)_minmax(0,432px)]">
      <div className="space-y-4">
        <div className="space-y-3 rounded-xl bg-white p-4 text-xs shadow-sm">
          <label className="block font-medium text-slate-700">
            Temat wiadomości
            <input
              className={field}
              maxLength={200}
              disabled={!editable}
              value={content.subject}
              onChange={(e) => onEdit({ subject: e.target.value.replace(/[\r\n]+/g, ' ') })}
              onBlur={onBlur}
              placeholder="np. Wyprzedaż BHP: półbuty S3 od 89 zł – do wyczerpania"
            />
          </label>
          <label className="block font-medium text-slate-700">
            Zajawka w skrzynce <span className="font-normal text-slate-500">— krótki tekst widoczny obok tematu</span>
            <input
              className={field}
              maxLength={200}
              disabled={!editable}
              value={content.preheader}
              onChange={(e) => onEdit({ preheader: e.target.value.replace(/[\r\n]+/g, ' ') })}
              onBlur={onBlur}
              placeholder="np. Ceny ważne do 31.10 lub do wyczerpania stanu."
            />
          </label>
          <div className="grid gap-3 sm:grid-cols-2">
            <label className="block font-medium text-slate-700">
              Ważne do
              <input
                type="date"
                className={field}
                disabled={!editable}
                value={content.valid_until}
                onChange={(e) => onEdit({ valid_until: e.target.value }, true)}
              />
            </label>
            <div className="font-medium text-slate-700">
              Przycisk przy produkcie
              <p className="mt-1 rounded border border-slate-200 bg-slate-50 px-2 py-1.5 text-sm font-normal text-slate-700">
                Zapytaj o ofertę <span className="text-xs text-slate-500">(mail do Ciebie z kodem kampanii i towaru)</span>
              </p>
              <p className="mt-1 flex items-center gap-1.5 text-[11px] font-normal text-slate-400">
                „Zobacz w sklepie” <StageTwo />
              </p>
            </div>
          </div>
        </div>

        <div className="space-y-3 rounded-xl bg-white p-4 text-xs shadow-sm">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <b className="text-sm text-slate-900">Wygląd maila</b>
            <button
              type="button"
              className={BTN_SM}
              onClick={() => {
                setSaveAsName('')
                setSaveAsErr('')
                setSaveAsOpen(true)
              }}
            >
              Zapisz jako mój szablon
            </button>
          </div>
          {editable && (
            <div className="flex flex-wrap items-center gap-2">
              <label className="inline-flex items-center gap-1.5 font-medium text-slate-700">
                Szablon
                <select className={INPUT} value={choice} disabled={applying} onChange={(e) => setChoice(e.target.value)}>
                  <option value="">Standard SUPON</option>
                  {shared.length > 0 && (
                    <optgroup label="Wspólne">
                      {shared.map((t) => (
                        <option key={t.id} value={String(t.id)}>
                          {t.name}
                        </option>
                      ))}
                    </optgroup>
                  )}
                  {own.length > 0 && (
                    <optgroup label="Moje">
                      {own.map((t) => (
                        <option key={t.id} value={String(t.id)}>
                          {t.name}
                        </option>
                      ))}
                    </optgroup>
                  )}
                </select>
              </label>
              <button type="button" className={BTN} disabled={applying || uploading || templates === null} onClick={() => setConfirmApply(true)}>
                Zastosuj
              </button>
              <span className="text-slate-500">
                {campaign.template_name ? `ostatnio zastosowany: ${campaign.template_name} · ` : ''}
                <Link to="/kampanie?tab=szablony" className="text-blue-600 hover:underline">
                  Moje szablony
                </Link>
              </span>
            </div>
          )}
          {templatesErr && <p className="text-red-700">{templatesErr}</p>}
          {msg && (
            <p className={`rounded px-3 py-2 ${msg.ok ? 'bg-emerald-50 text-emerald-800' : 'bg-red-50 text-red-700'}`} role="status">
              {msg.text}
            </p>
          )}
          <CampaignBlockEditor
            blocks={content.blocks}
            brandColor={content.brand_color}
            disabled={!editable || applying}
            onChange={(blocks, brand_color) => onEdit({ blocks, brand_color })}
            onUploadingChange={setUploading}
          />
        </div>
      </div>

      <div className="space-y-3">
        <LiveMailPreview
          blocks={content.blocks}
          brandColor={content.brand_color}
          load={(body) => previewDraft(campaign.id, body)}
          note="z pozycjami kampanii"
        />
        <div className="rounded-xl bg-white p-4 shadow-sm">
          <h2 className="app-card-title mb-2 text-sm font-semibold text-slate-900">Zawsze w mailu</h2>
          <ul className="space-y-1.5 text-xs text-slate-600">
            <Check ok>Twój podpis z „Moja poczta” pod elementami maila</Check>
            <Check ok>„Ceny netto ważne do … lub do wyczerpania zapasów” nad produktami</Check>
            <Check ok>Stan pozycji z dniem odczytu z XL</Check>
            <Check ok>Link „Wypisz mnie” w stopce i w nagłówku maila</Check>
          </ul>
          <button type="button" className={`${BTN_PRIMARY} mt-3 w-full`} onClick={onNext}>
            Dalej: podgląd →
          </button>
        </div>
      </div>

      {confirmApply && (
        <ConfirmDialog
          title="Zastosować szablon?"
          confirmLabel="Zastosuj"
          busy={applying}
          onClose={() => setConfirmApply(false)}
          onConfirm={() => void apply()}
          message={
            <>
              <p>
                Zastąpić treść maila elementami szablonu <b>{choiceName}</b>?
              </p>
              <p className="text-xs text-slate-600">
                Obecne elementy (nagłówek, teksty, grafiki, przyciski, stopka) i kolor zostaną zastąpione. Temat, zajawka,
                termin ważności i produkty zostają bez zmian.
              </p>
            </>
          }
        />
      )}
      {saveAsOpen && (
        <Modal
          title="Zapisz jako mój szablon"
          busy={saveAsBusy}
          onClose={() => setSaveAsOpen(false)}
          footer={
            <>
              <button type="button" className={BTN} disabled={saveAsBusy} onClick={() => setSaveAsOpen(false)}>
                Anuluj
              </button>
              <button
                type="button"
                className={BTN_PRIMARY}
                disabled={saveAsBusy || !saveAsName.trim()}
                onClick={() => void saveAsTemplate()}
              >
                {saveAsBusy ? 'Zapisuję…' : 'Zapisz szablon'}
              </button>
            </>
          }
        >
          <form
            onSubmit={(e) => {
              e.preventDefault()
              void saveAsTemplate()
            }}
          >
            <label className="block text-xs font-medium text-slate-700">
              Nazwa szablonu
              <input
                autoFocus
                maxLength={150}
                className={`${INPUT} mt-1 block w-full text-sm`}
                value={saveAsName}
                onChange={(e) => setSaveAsName(e.target.value)}
                placeholder="np. Wyprzedaż obuwia – z banerem"
              />
            </label>
            <p className="mt-2 text-xs text-slate-600">
              Szablon dostanie obecne elementy maila i kolor. Produkty, temat i odbiorcy kampanii nie wchodzą do szablonu.
            </p>
            {saveAsErr && <p className="mt-2 rounded bg-red-50 px-3 py-2 text-xs text-red-700">{saveAsErr}</p>}
          </form>
        </Modal>
      )}
    </div>
  )
}

function Check({ ok, children }: { ok: boolean; children: ReactNode }) {
  return (
    <li className="flex items-baseline gap-2">
      <span className={`font-bold ${ok ? 'text-emerald-700' : 'text-amber-700'}`} aria-label={ok ? 'w porządku' : 'uwaga'}>
        {ok ? '✓' : '!'}
      </span>
      <span>{children}</span>
    </li>
  )
}

/* ---------- Krok 4: podgląd i test ---------- */

function MailPreview({ preview }: { preview: CampaignPreview }) {
  return (
    <div className="rounded-xl bg-slate-100 p-3">
      <div className="mx-auto mb-2 grid max-w-[680px] gap-0.5 text-xs text-slate-600">
        <span>
          <b className="text-slate-800">Od:</b>{' '}
          {preview.from ? `${preview.from.name} <${preview.from.address}>` : <span className="text-amber-800">brak skrzynki nadawcy (Moja poczta)</span>}
        </span>
        <span>
          <b className="text-slate-800">Temat:</b> {preview.subject || <span className="text-amber-800">brak tematu</span>}
        </span>
        {preview.preheader && (
          <span>
            <b className="text-slate-800">Zajawka:</b> {preview.preheader}
          </span>
        )}
      </div>
      {/* sandbox="" — bez skryptów, formularzy i dostępu do strony; HTML maila tylko do obejrzenia. */}
      <iframe
        title="Podgląd maila"
        sandbox=""
        srcDoc={preview.html}
        className="mx-auto block h-[900px] w-full max-w-[680px] rounded border border-slate-200 bg-white"
      />
    </div>
  )
}

function PreviewStep({
  campaign,
  canTest,
  flush,
  onTestSent,
  onNext,
}: {
  campaign: Campaign
  canTest: boolean
  flush: () => Promise<void>
  onTestSent: () => void
  onNext: () => void
}) {
  const [preview, setPreview] = useState<CampaignPreview | null>(null)
  const [loading, setLoading] = useState(false)
  const [err, setErr] = useState('')
  const [testTo, setTestTo] = useState('')
  const [testing, setTesting] = useState(false)
  const [testMsg, setTestMsg] = useState<{ ok: boolean; text: string } | null>(null)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      await flush()
      setPreview(await campaignPreview(campaign.id))
      setErr('')
    } catch (ex) {
      setErr(errorText(ex, 'Nie udało się przygotować podglądu.'))
    } finally {
      setLoading(false)
    }
  }, [campaign.id, flush])

  useEffect(() => {
    void load()
  }, [load])

  async function sendTest() {
    setTesting(true)
    setTestMsg(null)
    try {
      await flush()
      const res = await sendCampaignTest(campaign.id, testTo.trim() || undefined)
      setTestMsg({ ok: true, text: res.message || 'Test wysłany. Sprawdź skrzynkę za minutę, także folder spam.' })
      onTestSent()
    } catch (ex) {
      setTestMsg({ ok: false, text: errorText(ex, 'Nie udało się wysłać testu.') })
    } finally {
      setTesting(false)
    }
  }

  const defaultTo = preview?.from?.address ?? ''

  return (
    <div>
      <div className="mb-3 flex flex-wrap items-center justify-between gap-3 rounded-xl bg-white px-4 py-3 text-xs shadow-sm">
        <span className="text-slate-700">Podgląd to dokładnie ten HTML, który dostanie klient (link wypisu działa dopiero w prawdziwej wysyłce).</span>
        <div className="flex flex-wrap items-center gap-2">
          <button type="button" className={BTN} disabled={loading} onClick={() => void load()}>
            {loading ? 'Odświeżam…' : 'Odśwież podgląd'}
          </button>
          {canTest && (
            <>
              <input
                type="email"
                className={`${INPUT} w-56`}
                placeholder={defaultTo || 'adres testu'}
                aria-label="Adres testu (puste = Twoja skrzynka)"
                value={testTo}
                onChange={(e) => setTestTo(e.target.value)}
              />
              <button type="button" className={BTN} disabled={testing} onClick={() => void sendTest()}>
                {testing ? 'Wysyłam…' : `Wyślij test na ${testTo.trim() || defaultTo || 'moją skrzynkę'}`}
              </button>
            </>
          )}
          <button type="button" className={BTN_PRIMARY} onClick={onNext}>
            Dalej: wysyłka →
          </button>
        </div>
      </div>
      {testMsg && (
        <p
          className={`mb-3 rounded px-3 py-2 text-xs ${testMsg.ok ? 'bg-emerald-50 text-emerald-800' : 'bg-red-50 text-red-700'}`}
          role="status"
        >
          {testMsg.text}
        </p>
      )}
      {err && <ErrorBar message={err} />}
      {preview ? <MailPreview preview={preview} /> : !err && <p className="text-xs text-slate-500">Przygotowuję podgląd…</p>}
    </div>
  )
}

/* ---------- Krok 5: wysyłka ---------- */

function SendStep({
  campaign,
  content,
  audience,
  audienceLoading,
  isAuthor,
  testSentAt,
  flush,
  onSent,
  onGoTo,
}: {
  campaign: Campaign
  content: Content
  audience: AudiencePreview | null
  audienceLoading: boolean
  isAuthor: boolean
  testSentAt: Date | null
  flush: () => Promise<void>
  onSent: (c: Campaign) => void
  onGoTo: (s: Step) => void
}) {
  // okno z listą odbiorców przed wysyłką; at = godzina startu przy planowaniu
  const [confirm, setConfirm] = useState<{ mode: 'send' } | { mode: 'schedule'; at: Date } | null>(null)
  const [err, setErr] = useState('')
  const [scheduleAt, setScheduleAt] = useState(defaultScheduleValue)
  const scheduled = campaign.status === 'scheduled'

  const items = campaign.items
  const final = audience?.final ?? 0
  const noStock = items.filter((i) => i.warnings.no_stock)
  const blockers: string[] = []
  if (items.length === 0) blockers.push('brak produktów')
  if (!content.subject.trim()) blockers.push('brak tematu')
  if (!audience || final === 0) blockers.push('brak odbiorców')
  if (audience?.without_mailbox) blockers.push('brak skrzynki nadawcy')
  if (!isAuthor) blockers.push('wysłać może tylko autor kampanii (z własnej skrzynki)')

  function openSchedule() {
    const at = new Date(scheduleAt)
    if (Number.isNaN(at.getTime())) {
      setErr('Wybierz dzień i godzinę wysyłki.')
      return
    }
    setErr('')
    setConfirm({ mode: 'schedule', at })
  }

  /** Po potwierdzeniu w oknie z listą odbiorców; błąd (też 422 „lista się zmieniła”) pokazuje okno. */
  async function confirmed(checksum: string) {
    if (!confirm) return
    await flush()
    const c =
      confirm.mode === 'send'
        ? await sendCampaign(campaign.id, checksum)
        : await scheduleCampaign(campaign.id, confirm.at.toISOString())
    setConfirm(null)
    onSent(c)
  }

  const stepLink = (s: Step, label: string) => (
    <button type="button" className="ml-1 text-blue-600 hover:underline" onClick={() => onGoTo(s)}>
      {label}
    </button>
  )

  return (
    <div className="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_300px]">
      <div className="rounded-xl bg-white p-4 shadow-sm">
        <h2 className="mb-2.5 text-sm font-semibold text-slate-900">Sprawdzenie przed wysyłką</h2>
        <ul className="space-y-1.5 text-xs text-slate-700">
          {items.length === 0 ? (
            <Check ok={false}>Brak produktów {stepLink(1, 'dodaj')}</Check>
          ) : noStock.length === 0 ? (
            <Check ok>
              {items.length} {plural(items.length, 'produkt', 'produkty', 'produktów')}, wszystkie mają stan &gt; 0
            </Check>
          ) : (
            <Check ok={false}>
              {noStock.length} z {items.length} {plural(items.length, 'produktu', 'produktów', 'produktów')} bez stanu:{' '}
              {noStock.map((i) => i.name).join(', ')}
            </Check>
          )}
          {audienceLoading && !audience ? (
            <Check ok={false}>Liczę odbiorców…</Check>
          ) : final > 0 ? (
            <Check ok>
              {fmtInt(final)} {plural(final, 'odbiorca', 'odbiorcy', 'odbiorców')} z podstawą wysyłki; wypisani i powtórzeni pominięci
            </Check>
          ) : (
            <Check ok={false}>Brak odbiorców {stepLink(2, 'wybierz')}</Check>
          )}
          {content.subject.trim() ? (
            <Check ok>Temat: „{content.subject.trim()}”</Check>
          ) : (
            <Check ok={false}>Brak tematu {stepLink(3, 'uzupełnij')}</Check>
          )}
          {testSentAt ? (
            <Check ok>Test wysłany {testSentAt.toLocaleTimeString('pl-PL', { hour: '2-digit', minute: '2-digit' })}</Check>
          ) : (
            <Check ok={false}>Nie wysłano testu w tej sesji {stepLink(4, 'wyślij test')}</Check>
          )}
          {campaign.warnings.map((w, i) => (
            <Check key={`w${i}`} ok={false}>
              {w}
            </Check>
          ))}
          {items
            .filter((i) => i.warnings.below_cost)
            .map((i) => (
              <Check key={`c${i.id}`} ok={false}>
                {i.name} — cena poniżej kosztu zakupu
              </Check>
            ))}
          {items
            .filter((i) => i.warnings.no_image)
            .map((i) => (
              <Check key={`p${i.id}`} ok={false}>
                {i.name} — bez zdjęcia
              </Check>
            ))}
          {items
            .filter((i) => i.promo_price_net == null)
            .map((i) => (
              <Check key={`n${i.id}`} ok={false}>
                {i.name} — bez ceny kampanii
              </Check>
            ))}
          {items.flatMap((i) =>
            i.warnings.other_campaigns.map((o) => (
              <Check key={`o${i.id}-${o.id}`} ok={false}>
                {i.name} jest też w kampanii „{o.name}” ({o.author})
              </Check>
            )),
          )}
        </ul>

        {err && <p className="mt-3 rounded bg-red-50 px-3 py-2 text-xs text-red-700">{err}</p>}

        {scheduled ? (
          <p className="mt-4 rounded border border-blue-200 bg-blue-50 px-3 py-2 text-xs text-blue-900">
            Kampania jest zaplanowana na {fmtDateTime(campaign.scheduled_at ?? null)} — wyśle się sama. Żeby coś zmienić albo
            wysłać od razu, cofnij planowanie na pasku u góry.
          </p>
        ) : (
        <div className="mt-4 flex flex-wrap items-center gap-2.5">
          <button
            type="button"
            className={BTN_PRIMARY}
            disabled={blockers.length > 0 || confirm !== null}
            title={blockers.length > 0 ? `Nie można wysłać: ${blockers.join(', ')}` : 'Przed wysyłką pokażemy listę adresów, do których pójdzie mail'}
            onClick={() => {
              setErr('')
              setConfirm({ mode: 'send' })
            }}
          >
            Wyślij teraz do {fmtInt(final)} {plural(final, 'odbiorcy', 'odbiorców', 'odbiorców')}
          </button>
          <span className="text-[11px] text-slate-500">albo</span>
          <label className="inline-flex items-center gap-1.5 text-xs text-slate-700">
            zaplanuj na
            <input
              type="datetime-local"
              className={INPUT}
              value={scheduleAt}
              min={toLocalInputValue(new Date(Date.now() + 5 * 60_000))}
              onChange={(e) => setScheduleAt(e.target.value)}
              aria-label="Dzień i godzina wysyłki"
            />
          </label>
          <button
            type="button"
            className={BTN}
            disabled={blockers.length > 0 || confirm !== null || !scheduleAt}
            title={
              blockers.length > 0
                ? `Nie można zaplanować: ${blockers.join(', ')}`
                : 'Kampania wystartuje sama o tej godzinie; odbiorców i stany policzymy w chwili startu'
            }
            onClick={openSchedule}
          >
            Zaplanuj
          </button>
        </div>
        )}
        {!scheduled && blockers.length > 0 && (
          <p className="mt-2 text-[11px] text-amber-800">Nie można jeszcze wysłać: {blockers.join(', ')}.</p>
        )}
        <p className="mt-3 text-[11px] text-slate-500">
          Wysyłka idzie przez Twoją skrzynkę, partiami w tle (limit maili na godzinę z „Moja poczta”), więc możesz zamknąć
          przeglądarkę. Ostrzeżenia oznaczone „!” nie blokują wysyłki.
        </p>
      </div>
      <aside className="rounded-xl bg-white p-4 text-xs text-slate-600 shadow-sm">
        <h2 className="app-card-title mb-2 text-sm font-semibold text-slate-900">Status</h2>
        <p>
          Projekt → Zaplanowana (jeśli wybierzesz godzinę) → <b className="text-slate-800">Wysyłka</b> (sama się ustawi) →
          Wysłana. Zaplanowaną można cofnąć do projektu. Po wysyłce kampanii nie można edytować — można ją zduplikować.
        </p>
      </aside>

      {confirm && (
        <CampaignRecipientsConfirm
          campaignId={campaign.id}
          campaignName={campaign.name}
          mode={confirm.mode}
          scheduleAt={confirm.mode === 'schedule' ? confirm.at : undefined}
          flush={flush}
          onConfirm={confirmed}
          onClose={() => setConfirm(null)}
        />
      )}
    </div>
  )
}

/* ------------------------------------------------------------------------------------------------ */
/* Kampania wysłana / w wysyłce / anulowana — tylko odczyt                                           */
/* ------------------------------------------------------------------------------------------------ */

const RECIPIENT_FILTERS: { value: CampaignRecipientStatus | ''; label: string }[] = [
  { value: '', label: 'wszyscy' },
  { value: 'pending', label: 'czekają' },
  { value: 'sent', label: 'wysłani' },
  { value: 'failed', label: 'błędy' },
  { value: 'skipped', label: 'pominięci' },
]

function SentView({
  campaign,
  canManage,
  mutate,
  onReload,
  onChanged,
}: {
  campaign: Campaign
  /** Autor albo „Kampanie — wszystkie”; sam podgląd = tylko odczyt i test do siebie. */
  canManage: boolean
  mutate: Mutate
  onReload: () => Promise<void>
  onChanged: (c: Campaign) => void
}) {
  const [adding, setAdding] = useState(false)
  const [addedMsg, setAddedMsg] = useState('')
  const [testBusy, setTestBusy] = useState(false)
  const [testMsg, setTestMsg] = useState<{ ok: boolean; text: string } | null>(null)
  const [counts, setCounts] = useState<CampaignTotals | null>(null)
  const [filter, setFilter] = useState<CampaignRecipientStatus | ''>('')
  const [page, setPage] = useState(1)
  const [rows, setRows] = useState<CampaignRecipientRow[] | null>(null)
  const [meta, setMeta] = useState<PageMeta | null>(null)
  const [loading, setLoading] = useState(false)
  const [err, setErr] = useState('')
  const [preview, setPreview] = useState<CampaignPreview | null>(null)
  const [previewOpen, setPreviewOpen] = useState(false)
  const [previewErr, setPreviewErr] = useState('')
  const [tick, setTick] = useState(0)
  const [itemSort, toggleItemSort] = useTableSort<ItemSortKey>(ITEM_SORT_DESC)
  const [recipientSort, toggleRecipientSortRaw] = useTableSort<CampaignRecipientSort>(RECIPIENT_SORT_DESC)
  const seq = useRef(0)
  const sending = campaign.status === 'sending'
  const totalsJson = campaign.totals ? JSON.stringify(campaign.totals) : ''

  // Podczas wysyłki totals bywa puste (serwer liczy je na końcu) — liczymy z listy odbiorców po statusach.
  const loadCounts = useCallback(async () => {
    const totals = totalsJson ? (JSON.parse(totalsJson) as CampaignTotals) : null
    if (totals && !sending) {
      setCounts(totals)
      return
    }
    try {
      const [all, sent, failed, skipped] = await Promise.all([
        campaignRecipients(campaign.id),
        campaignRecipients(campaign.id, { status: 'sent' }),
        campaignRecipients(campaign.id, { status: 'failed' }),
        campaignRecipients(campaign.id, { status: 'skipped' }),
      ])
      setCounts({ recipients: all.meta.total, sent: sent.meta.total, failed: failed.meta.total, skipped: skipped.meta.total })
    } catch {
      if (totals) setCounts(totals)
    }
  }, [campaign.id, totalsJson, sending])

  const loadRows = useCallback(async () => {
    const my = ++seq.current
    setLoading(true)
    try {
      const res = await campaignRecipients(campaign.id, { status: filter, page, sort: recipientSort?.key, dir: recipientSort?.dir })
      if (my !== seq.current) return
      setRows(res.data)
      setMeta(res.meta)
      setErr('')
    } catch (ex) {
      if (my === seq.current) setErr(errorText(ex, 'Nie udało się wczytać odbiorców.'))
    } finally {
      if (my === seq.current) setLoading(false)
    }
  }, [campaign.id, filter, page, recipientSort])

  // lista stronicowana na serwerze — nowe sortowanie od pierwszej strony
  function toggleRecipientSort(k: CampaignRecipientSort) {
    toggleRecipientSortRaw(k)
    setPage(1)
  }

  useEffect(() => {
    void loadCounts()
  }, [loadCounts, tick])

  useEffect(() => {
    void loadRows()
  }, [loadRows, tick])

  // W trakcie wysyłki co 10 s: kampania (status, totals), liczniki i bieżąca strona odbiorców.
  useEffect(() => {
    if (!sending) return
    const t = window.setInterval(() => {
      if (document.visibilityState !== 'visible') return
      void onReload()
      setTick((n) => n + 1)
    }, REFRESH_SENDING_MS)
    return () => window.clearInterval(t)
  }, [sending, onReload])

  async function togglePreview() {
    if (previewOpen) {
      setPreviewOpen(false)
      return
    }
    setPreviewOpen(true)
    if (preview) return
    try {
      setPreview(await campaignPreview(campaign.id))
    } catch (ex) {
      setPreviewErr(errorText(ex, 'Nie udało się przygotować podglądu.'))
    }
  }

  async function sendTestToMe() {
    setTestBusy(true)
    setTestMsg(null)
    try {
      const res = await sendCampaignTest(campaign.id)
      setTestMsg({ ok: true, text: res.message })
    } catch (ex) {
      setTestMsg({ ok: false, text: errorText(ex, 'Nie udało się wysłać maila testowego.') })
    } finally {
      setTestBusy(false)
    }
  }

  // dopisani po starcie wysyłki (minuta zapasu na zapis odbiorców przy starcie)
  const startedMs = campaign.sending_started_at ? new Date(campaign.sending_started_at).getTime() : null
  const addedLater = (r: CampaignRecipientRow) =>
    startedMs !== null && r.created_at != null && new Date(r.created_at).getTime() - startedMs > 60_000

  const c = counts
  const done = c ? c.sent + c.failed + c.skipped : 0
  const waiting = c ? Math.max(0, c.recipients - done) : null
  const progress = c && c.recipients > 0 ? (done / c.recipients) * 100 : 0

  return (
    <div className="space-y-4">
      {campaign.warnings.length > 0 && (
        <div className="rounded border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">
          {campaign.warnings.map((w, i) => (
            <p key={i}>{w}</p>
          ))}
        </div>
      )}

      <div className="app-kpis grid gap-2 sm:grid-cols-3 xl:grid-cols-6">
        <SentKpi label="Odbiorcy" value={fmtInt(c?.recipients)} />
        <SentKpi label="Wysłane" value={fmtInt(c?.sent)} />
        <SentKpi label="Błędy" value={fmtInt(c?.failed)} tone={c && c.failed > 0 ? 'alert' : undefined} />
        <SentKpi label="Pominięte (wypisani w trakcie)" value={fmtInt(c?.skipped)} />
        <SentKpi
          label={campaign.status === 'cancelled' ? 'Nie wysłane (anulowana)' : 'Czeka na wysyłkę'}
          value={fmtInt(waiting)}
          tone={sending && waiting ? 'attention' : undefined}
        />
        <SentKpi
          label={campaign.status === 'sending' || !campaign.sent_at ? 'Rozpoczęta' : 'Zakończona'}
          value={fmtDateTime(campaign.status === 'sending' ? campaign.sending_started_at : (campaign.sent_at ?? campaign.sending_started_at))}
          small
        />
      </div>

      {campaign.can_add_recipients && !adding && (
        <div className="flex flex-wrap items-center justify-between gap-2 rounded-xl bg-white px-4 py-2.5 text-xs shadow-sm">
          <span className="text-slate-600">
            Chcesz wysłać tę kampanię jeszcze komuś? Dopisani dostaną ten sam mail, a wyniki zostaną w tej kampanii.
          </span>
          <button type="button" className={BTN} onClick={() => setAdding(true)}>
            + Dopisz odbiorców
          </button>
        </div>
      )}
      {adding && (
        <AddRecipientsPanel
          campaign={campaign}
          mutate={mutate}
          onClose={() => setAdding(false)}
          onAdded={(c, added) => {
            setAdding(false)
            setAddedMsg(`Dopisano ${fmtInt(added)} ${plural(added, 'odbiorcę', 'odbiorców', 'odbiorców')} — maile wychodzą w tle, według limitu skrzynki.`)
            onChanged(c)
            setTick((n) => n + 1)
          }}
        />
      )}
      {addedMsg && (
        <p className="rounded bg-emerald-50 px-3 py-2 text-xs text-emerald-800" role="status">
          {addedMsg}
        </p>
      )}

      {sending && (
        <div className="rounded-xl bg-white p-4 text-xs shadow-sm">
          <div className="mb-1.5 flex flex-wrap justify-between gap-2 text-slate-700">
            <span>
              Wysyłka w toku: {fmtInt(done)} z {fmtInt(c?.recipients)} — partiami w tle, według limitu skrzynki na godzinę.
            </span>
            <span className="text-slate-500">odświeża się co 10 s</span>
          </div>
          <div className="h-2 overflow-hidden rounded bg-slate-200" aria-hidden>
            <div className="h-full bg-emerald-600" style={{ width: `${progress}%` }} />
          </div>
        </div>
      )}
      {campaign.status === 'cancelled' && (
        <p className="rounded border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-800">
          Wysyłka anulowana. Maile, które wyszły przed anulowaniem, zostały u klientów.
        </p>
      )}

      <div className="rounded-xl bg-white shadow-sm">
        <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-4 py-2.5">
          <b className="text-sm text-slate-900">Wynik: ile zeszło z magazynu</b>
          <span className="text-[11px] text-slate-500">stan magazynów handlowych przy wysyłce i po 7 / 30 dniach</span>
        </div>
        <div className="overflow-x-auto">
          <table className="w-full text-left text-xs">
            <thead>
              <tr className="border-b bg-slate-50">
                <SortTh label="Pozycja" k="name" sort={itemSort} onSort={toggleItemSort} />
                <SortTh label="Cena w mailu" k="price" sort={itemSort} onSort={toggleItemSort} align="right" />
                <SortTh label="Przy wysyłce" k="stock" sort={itemSort} onSort={toggleItemSort} align="right" />
                <SortTh label="Po 7 dniach" k="after_7d" sort={itemSort} onSort={toggleItemSort} align="right" />
                <SortTh label="Po 30 dniach" k="after_30d" sort={itemSort} onSort={toggleItemSort} align="right" />
                <SortTh label="Zeszło" k="drop" sort={itemSort} onSort={toggleItemSort} />
                <SortTh label="Kupili odbiorcy kampanii" k="bought" sort={itemSort} onSort={toggleItemSort} align="right" />
                <SortTh label="Kliknięcia" k="clicks" sort={itemSort} onSort={toggleItemSort} align="right" />
              </tr>
            </thead>
            <tbody>
              {sortRows(campaign.items, itemSort, (i, k) => itemSortValue(i, k, campaign)).map((i) => {
                const drop = itemDrop(i)
                const snap = i.snapshot
                return (
                  <tr key={i.id} className="border-b align-top">
                    <td className="min-w-[16rem] p-2">
                      <span className="text-slate-900">{snap?.name ?? i.name}</span>{' '}
                      <span className="font-mono text-[11px] text-slate-500">{snap?.code ?? i.code}</span>
                    </td>
                    <td className="whitespace-nowrap p-2 text-right tabular-nums">{formatPln(snap?.price ?? i.promo_price_net)}</td>
                    <td className="whitespace-nowrap p-2 text-right tabular-nums">
                      {fmtQty(snap?.stock, i.unit)}
                      {snap?.stock_at && <div className="text-[10px] text-slate-500">stan z {fmtDate(snap.stock_at)}</div>}
                    </td>
                    <td className="whitespace-nowrap p-2 text-right tabular-nums">{fmtQty(i.stock_after_7d, i.unit)}</td>
                    <td className="whitespace-nowrap p-2 text-right tabular-nums">{fmtQty(i.stock_after_30d, i.unit)}</td>
                    <td className="whitespace-nowrap p-2">
                      {drop?.rose ? (
                        <span className="text-slate-600" title="Stan jest wyższy niż przy wysyłce — prawdopodobnie przyszła dostawa">
                          stan wzrósł ({drop.days} dni)
                        </span>
                      ) : drop ? (
                        <DropBar percent={drop.percent} suffix={` (${drop.days} dni)`} />
                      ) : (
                        <span className="text-slate-400">{campaign.status === 'cancelled' ? '—' : 'liczymy 7 dni po wysyłce'}</span>
                      )}
                    </td>
                    <td className="whitespace-nowrap p-2 text-right tabular-nums">
                      <ItemSalesCell sales={campaign.sales ?? null} erpItemId={i.erp_item_id} unit={i.unit} />
                    </td>
                    <td className="whitespace-nowrap p-2 text-right tabular-nums">
                      <ItemClicksCell clicks={campaign.clicks ?? null} itemId={i.id} />
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
        <p className="px-4 py-3 text-[11px] text-slate-500">
          Spadek stanu nie dowodzi, że towar sprzedała kampania (mógł zejść też inną drogą, a dostawa podnosi stan). Kto z
          odbiorców kupił — niżej, z faktur i paragonów ERP XL.
        </p>
      </div>

      {campaign.sales && <CampaignSalesPanel sales={campaign.sales} />}

      {campaign.clicks && <ClickedPanel campaign={campaign} clicks={campaign.clicks} tick={tick} />}

      {campaign.replies && (
        <RepliesPanel
          campaign={campaign}
          replies={campaign.replies}
          canCheck={canManage}
          onChecked={async () => {
            await onReload()
            setTick((n) => n + 1)
          }}
        />
      )}

      <div className="overflow-x-auto rounded-xl bg-white p-4 shadow-sm">
        <div className="mb-2 flex flex-wrap items-center justify-between gap-2 text-xs">
          <b className="text-sm text-slate-900">Odbiorcy</b>
          <span className="inline-flex flex-wrap items-center gap-1">
            {RECIPIENT_FILTERS.map((f) => (
              <button
                key={f.value || 'all'}
                type="button"
                aria-pressed={filter === f.value}
                onClick={() => {
                  setFilter(f.value)
                  setPage(1)
                }}
                className={`app-chip rounded border px-2 py-0.5 text-xs ${
                  filter === f.value ? 'app-chip--active border-blue-600 bg-blue-600 text-white' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50'
                }`}
              >
                {f.label}
              </button>
            ))}
            <span className="ml-2 text-slate-500">
              {meta ? `${fmtInt(meta.total)}` : ''}
              {loading ? ' · ładowanie…' : ''}
            </span>
          </span>
        </div>
        {err && <p className="mb-2 rounded bg-red-50 px-3 py-2 text-xs text-red-700">{err}</p>}
        <table className="w-full text-left text-xs">
          <thead>
            <tr className="border-b bg-slate-50">
              <SortTh label="Adres" k="email" sort={recipientSort} onSort={toggleRecipientSort} />
              <SortTh label="Źródło" k="source" sort={recipientSort} onSort={toggleRecipientSort} />
              <SortTh label="Status" k="status" sort={recipientSort} onSort={toggleRecipientSort} />
              <SortTh label="Wysłano" k="sent_at" sort={recipientSort} onSort={toggleRecipientSort} />
              <SortTh label="Kliknięcia" k="clicks" sort={recipientSort} onSort={toggleRecipientSort} align="right" />
              <SortTh label="Odpowiedź" k="replied_at" sort={recipientSort} onSort={toggleRecipientSort} />
              <SortTh label="Uwagi" k="notes" sort={recipientSort} onSort={toggleRecipientSort} />
            </tr>
          </thead>
          <tbody>
            {(rows ?? []).map((r) => (
              <tr key={r.id} className="border-b align-top">
                <td className="p-2">
                  <span className="font-mono text-slate-900">{r.email}</span>
                  {r.name && <div className="text-[11px] text-slate-500">{r.name}</div>}
                </td>
                <td className="p-2 text-slate-600">{r.source === 'xl' ? 'ERP XL' : 'grupa'}</td>
                <td className="p-2">
                  <Chip tone={r.status === 'sent' ? 'green' : r.status === 'failed' ? 'red' : r.status === 'skipped' ? 'slate' : 'amber'}>
                    {RECIPIENT_STATUS_LABEL[r.status] ?? r.status}
                  </Chip>
                </td>
                <td className="whitespace-nowrap p-2 tabular-nums text-slate-600">
                  {r.sent_at ? fmtDateTime(r.sent_at) : '—'}
                  {addedLater(r) && r.created_at && <div className="text-[10px] text-blue-700">dopisany {fmtDate(r.created_at)}</div>}
                </td>
                <td className="whitespace-nowrap p-2 text-right tabular-nums">
                  {r.clicks ? <b className="font-semibold text-blue-700">{fmtInt(r.clicks)}</b> : <span className="text-slate-400">—</span>}
                </td>
                <td className="whitespace-nowrap p-2 tabular-nums">
                  {r.replied_at ? (
                    <span className="font-medium text-emerald-700">{fmtDateTime(r.replied_at)}</span>
                  ) : (
                    <span className="text-slate-400">—</span>
                  )}
                </td>
                <td className="max-w-[24rem] p-2 text-[11px]">
                  {r.error && <span className="break-words text-red-700">{r.error}</span>}
                  {r.error && r.status === 'pending' && (
                    <span className="block text-slate-500">spróbujemy ponownie w kolejnej partii</span>
                  )}
                  {r.unsubscribed_at && <span className="block text-slate-600">wypisał się {fmtDate(r.unsubscribed_at)}</span>}
                </td>
              </tr>
            ))}
            {rows !== null && rows.length === 0 && (
              <tr>
                <td colSpan={7} className="p-6 text-center text-slate-500">
                  Brak odbiorców w tym widoku.
                </td>
              </tr>
            )}
          </tbody>
        </table>
        <Pager meta={meta} disabled={loading} onPage={setPage} />
      </div>

      <div className="rounded-xl bg-white p-4 shadow-sm">
        <div className="flex flex-wrap items-center gap-2">
          <button type="button" className={BTN} onClick={() => void togglePreview()}>
            {previewOpen ? 'Ukryj treść maila' : 'Pokaż treść maila'}
          </button>
          <button
            type="button"
            className={BTN}
            disabled={testBusy}
            title="Wysyła ten mail na Twój adres (oznaczony [TEST], kliknięcia się nie liczą)"
            onClick={() => void sendTestToMe()}
          >
            {testBusy ? 'Wysyłam…' : 'Wyślij test do mnie'}
          </button>
          {testMsg && (
            <span className={`text-xs ${testMsg.ok ? 'text-emerald-700' : 'text-red-700'}`} role="status">
              {testMsg.text}
            </span>
          )}
        </div>
        {previewOpen && (
          <div className="mt-3">
            {previewErr ? <ErrorBar message={previewErr} /> : preview ? <MailPreview preview={preview} /> : <p className="text-xs text-slate-500">Ładowanie…</p>}
          </div>
        )}
      </div>
    </div>
  )
}

/**
 * „Dopisz odbiorców” do wysłanej (albo wysyłanej) kampanii: ten sam wybór grup i klientów XL co w kreatorze (zapis od
 * razu), potem okno z listą tylko nowych adresów — obecni odbiorcy odpadają jako „już dostali tę kampanię”.
 */
function AddRecipientsPanel({
  campaign,
  mutate,
  onClose,
  onAdded,
}: {
  campaign: Campaign
  mutate: Mutate
  onClose: () => void
  onAdded: (c: Campaign, added: number) => void
}) {
  const [audience, setAudience] = useState<AudiencePreview | null>(null)
  const [loading, setLoading] = useState(false)
  const [err, setErr] = useState('')
  const [confirm, setConfirm] = useState(false)
  const seq = useRef(0)
  const audienceKey = JSON.stringify(campaign.audience)

  useEffect(() => {
    const my = ++seq.current
    setLoading(true)
    campaignAudience(campaign.id)
      .then((a) => {
        if (my !== seq.current) return
        setAudience(a)
        setErr('')
      })
      .catch((ex: unknown) => {
        if (my === seq.current) setErr(errorText(ex, 'Nie udało się policzyć odbiorców.'))
      })
      .finally(() => {
        if (my === seq.current) setLoading(false)
      })
  }, [campaign.id, audienceKey])

  const noop = useCallback(async () => {}, [])

  return (
    <div className="space-y-3 rounded-xl border border-blue-200 bg-sky-50/40 p-3">
      <div className="flex flex-wrap items-center justify-between gap-2 text-xs">
        <span className="text-slate-700">
          <b className="text-sm text-slate-900">Dopisz odbiorców</b> — zaznacz grupy albo klientów z ERP XL. Kto już dostał
          tę kampanię, nie dostanie jej drugi raz.
        </span>
        <button type="button" className={BTN_SM} onClick={onClose}>
          Zamknij
        </button>
      </div>
      <AudienceStep
        campaign={campaign}
        editable
        mutate={mutate}
        audience={audience}
        audienceLoading={loading}
        audienceErr={err}
        nextLabel="Dalej: sprawdź nowych odbiorców →"
        onNext={() => setConfirm(true)}
      />
      {confirm && (
        <CampaignRecipientsConfirm
          campaignId={campaign.id}
          campaignName={campaign.name}
          mode="add"
          flush={noop}
          onClose={() => setConfirm(false)}
          onConfirm={async (checksum) => {
            const res = await addCampaignRecipients(campaign.id, checksum)
            setConfirm(false)
            onAdded(res.campaign, res.added)
          }}
        />
      )}
    </div>
  )
}

function SentKpi({ label, value, tone, small }: { label: string; value: string; tone?: 'alert' | 'attention'; small?: boolean }) {
  return (
    <div className="app-kpi rounded-xl bg-white px-3 py-2 shadow-sm" data-tone={tone}>
      <b
        className={`app-kpi-value block font-semibold tabular-nums ${small ? 'text-sm' : 'text-xl'} ${
          tone === 'alert' ? 'text-amber-800' : tone === 'attention' ? 'text-blue-700' : 'text-slate-800'
        }`}
      >
        {value}
      </b>
      <span className="app-kpi-label text-xs text-slate-500">{label}</span>
    </div>
  )
}

/** Kolumna „Kupili odbiorcy kampanii” przy pozycji: ilość i wartość netto z faktur/paragonów odbiorców. */
function ItemSalesCell({ sales, erpItemId, unit }: { sales: CampaignSales | null; erpItemId: number | null; unit: string | null }) {
  if (!sales) return <span className="text-slate-400">—</span>
  const row = sales.items.find((r) => r.erp_item_id === erpItemId)
  if (!row) return <span className="text-slate-400" title="Pozycja bez towaru XL — sprzedaży nie da się śledzić">—</span>
  if (row.quantity_recipients <= 0) return <span className="text-slate-500">nikt (na razie)</span>
  return (
    <span>
      <b className="font-semibold text-emerald-700">{fmtQty(row.quantity_recipients, row.unit ?? unit)}</b>
      <span className="block text-[10px] text-slate-500">{formatPln(row.value_recipients)} netto</span>
    </span>
  )
}

type BuyerSortKey = keyof Pick<CampaignSalesBuyer, 'sold_at' | 'acronym' | 'email' | 'item_name' | 'quantity' | 'net_value' | 'document_number'>

/**
 * „Kupili odbiorcy kampanii”: kto z odbiorców kupił pozycje kampanii w okresie od wysyłki (faktury i paragony XL,
 * odczyt nocny), a dla porównania ile kupili pozostali klienci. Zakup po mailu nie dowodzi, że kupili dzięki kampanii.
 */
function CampaignSalesPanel({ sales }: { sales: CampaignSales }) {
  const [sort, toggleSort] = useTableSort<BuyerSortKey>(['sold_at', 'quantity', 'net_value'])
  const untracked = Math.max(0, sales.recipients_sent - sales.recipients_in_xl)
  return (
    <div className="rounded-xl bg-white shadow-sm">
      <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-4 py-2.5">
        <b className="text-sm text-slate-900">Kupili odbiorcy kampanii</b>
        <span className="text-[11px] text-slate-500">
          faktury i paragony z ERP XL od {fmtDate(sales.from)} do {fmtDate(sales.to)} ({sales.days} dni)
          {sales.complete ? ' · okres zamknięty' : ' · okres trwa'}
          {sales.synced_at ? ` · odczyt z XL ${fmtDateTime(sales.synced_at)}` : ' · pierwszy odczyt z XL w nocy'}
        </span>
      </div>
      <div className="grid gap-2 p-4 sm:grid-cols-3">
        <div className="rounded-lg bg-emerald-50 px-3 py-2">
          <b className="block text-lg font-semibold tabular-nums text-emerald-800">{formatPln(sales.recipients.net_value)}</b>
          <span className="text-xs text-emerald-900">
            netto — kupiło {fmtInt(sales.recipients.customers)}{' '}
            {plural(sales.recipients.customers, 'firma', 'firmy', 'firm')} z odbiorców
          </span>
        </div>
        <div className="rounded-lg bg-slate-50 px-3 py-2">
          <b className="block text-lg font-semibold tabular-nums text-slate-800">{formatPln(sales.others.net_value)}</b>
          <span className="text-xs text-slate-600">
            netto — pozostali klienci ({fmtInt(sales.others.customers)}), dla porównania
          </span>
        </div>
        <div className="rounded-lg bg-slate-50 px-3 py-2">
          <b className="block text-lg font-semibold tabular-nums text-slate-800">
            {fmtInt(sales.recipients_in_xl)} z {fmtInt(sales.recipients_sent)}
          </b>
          <span className="text-xs text-slate-600">
            odbiorców da się śledzić w XL
            {untracked > 0 ? ` — ${fmtInt(untracked)} ${plural(untracked, 'adresu', 'adresów', 'adresów')} nie ma na kartach kontrahentów` : ''}
          </span>
        </div>
      </div>
      {sales.buyers.length > 0 ? (
        <div className="overflow-x-auto border-t border-slate-200">
          <table className="w-full text-left text-xs">
            <thead>
              <tr className="border-b bg-slate-50">
                <SortTh label="Data" k="sold_at" sort={sort} onSort={toggleSort} />
                <SortTh label="Klient" k="acronym" sort={sort} onSort={toggleSort} />
                <SortTh label="Mail poszedł na" k="email" sort={sort} onSort={toggleSort} />
                <SortTh label="Towar" k="item_name" sort={sort} onSort={toggleSort} />
                <SortTh label="Ilość" k="quantity" sort={sort} onSort={toggleSort} align="right" />
                <SortTh label="Netto" k="net_value" sort={sort} onSort={toggleSort} align="right" />
                <SortTh label="Dokument" k="document_number" sort={sort} onSort={toggleSort} />
              </tr>
            </thead>
            <tbody>
              {sortRows(sales.buyers, sort, (b, k) => (k === 'sold_at' ? sortDate(b.sold_at) : b[k])).map((b, idx) => (
                <tr key={`${b.document_number}-${b.code}-${idx}`} className="border-b">
                  <td className="whitespace-nowrap p-2 tabular-nums">{fmtDate(b.sold_at)}</td>
                  <td className="p-2">
                    <span className="font-mono text-[11px] text-slate-800">{b.acronym}</span>
                    {b.name && <span className="block text-slate-600">{b.name}</span>}
                  </td>
                  <td className="p-2 font-mono text-[11px] text-slate-600">{b.email}</td>
                  <td className="p-2">
                    <span className="text-slate-900">{b.item_name}</span>{' '}
                    <span className="font-mono text-[11px] text-slate-500">{b.code}</span>
                  </td>
                  <td className="whitespace-nowrap p-2 text-right tabular-nums">{fmtQty(b.quantity, b.unit)}</td>
                  <td className="whitespace-nowrap p-2 text-right tabular-nums">{formatPln(b.net_value)}</td>
                  <td className="whitespace-nowrap p-2 font-mono text-[11px] text-slate-600">{b.document_number}</td>
                </tr>
              ))}
            </tbody>
          </table>
          {sales.buyers_truncated && <p className="px-4 py-2 text-[11px] text-slate-500">Pokazano pierwsze 200 pozycji.</p>}
        </div>
      ) : (
        <p className="border-t border-slate-200 px-4 py-3 text-xs text-slate-500">
          Nikt z odbiorców nie kupił jeszcze pozycji kampanii{sales.complete ? ' w tym okresie' : ''}.
        </p>
      )}
      <p className="px-4 py-3 text-[11px] text-slate-500">
        Zakup po wysyłce nie dowodzi, że klient kupił dzięki kampanii — porównaj z pozostałymi klientami. Liczymy klientów
        z ERP XL, do których mail wyszedł; adres z grupy liczy się, gdy jest na karcie kontrahenta w XL.
      </p>
    </div>
  )
}

/** Wartość pola datetime-local w czasie lokalnym przeglądarki (RRRR-MM-DDTHH:MM). */
function toLocalInputValue(d: Date): string {
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
}

/** Domyślnie jutro o 8:00 — maile rano czyta się najczęściej. */
function defaultScheduleValue(): string {
  const d = new Date()
  d.setDate(d.getDate() + 1)
  d.setHours(8, 0, 0, 0)
  return toLocalInputValue(d)
}

/** Pasek zaplanowanej kampanii: kiedy wystartuje i cofnięcie planowania (wraca do projektu). */
function ScheduledBanner({
  campaign,
  canUnschedule,
  onChanged,
  onError,
}: {
  campaign: Campaign
  canUnschedule: boolean
  onChanged: (c: Campaign) => void
  onError: (message: string) => void
}) {
  const [busy, setBusy] = useState(false)

  async function unschedule() {
    setBusy(true)
    try {
      onChanged(await unscheduleCampaign(campaign.id))
    } catch (ex) {
      onError(errorText(ex, 'Nie udało się cofnąć planowania.'))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-950">
      <span>
        Zaplanowana na <b>{fmtDateTime(campaign.scheduled_at ?? null)}</b> — wyśle się sama z Twojej skrzynki. Odbiorców i
        stany magazynu policzymy w chwili startu. Zmienić treść można po cofnięciu planowania.
      </span>
      {canUnschedule && (
        <button type="button" className={BTN} disabled={busy} onClick={() => void unschedule()}>
          {busy ? 'Cofam…' : 'Cofnij planowanie'}
        </button>
      )}
    </div>
  )
}

/** Kliknięcia ludzi przy pozycji: strona produktu i „Zapytaj o ofertę”. */
function ItemClicksCell({ clicks, itemId }: { clicks: CampaignClicks | null; itemId: number }) {
  const row = clicks?.items.find((r) => r.campaign_item_id === itemId)
  const link = row?.link ?? 0
  if (!row || row.offer + row.product + link === 0) return <span className="text-slate-400">—</span>
  return (
    <span title="Kliknięcia odbiorców (bez skanerów poczty)">
      <b className="font-semibold text-blue-700">{fmtInt(row.product + row.offer + link)}</b>
      <span className="block text-[10px] text-slate-500">
        produkt {fmtInt(row.product)} · zapytanie {fmtInt(row.offer)}
        {link > 0 ? ` · link ${fmtInt(link)}` : ''}
      </span>
    </span>
  )
}

/**
 * „Odpowiedzi klientów”: maile w skrzynce autora z kodem kampanii w temacie („Zapytaj o ofertę”) albo odpowiedzi na mail
 * kampanii. Z nagłówków (IMAP co 10 min) — treści nie czytamy, więc tu tylko kto, kiedy i o który towar pyta.
 */
function RepliesPanel({
  campaign,
  replies,
  canCheck,
  onChecked,
}: {
  campaign: Campaign
  replies: CampaignReplies
  /** „Sprawdź skrzynkę teraz” — autor albo „Kampanie — wszystkie” (nie sam podgląd). */
  canCheck: boolean
  onChecked: () => Promise<void>
}) {
  const [checking, setChecking] = useState(false)
  const [result, setResult] = useState<{ ok: boolean; text: string } | null>(null)
  const [previewId, setPreviewId] = useState<number | null>(null)
  const [sort, toggleSort] = useTableSort<'from_email' | 'received_at' | 'item_code' | 'subject'>(['received_at'])
  const sent = campaign.totals?.sent ?? 0
  const others = replies.list.filter((r) => r.recipient_email === null).length
  // item_code to kod z migawki wysłanego maila (snap_code) — po nim szukamy karty pozycji
  const cardByCode = new Map<string, number>()
  for (const item of campaign.items) {
    const code = (item.snapshot?.code ?? item.code).trim().toUpperCase()
    if (code !== '' && item.card && !cardByCode.has(code)) cardByCode.set(code, item.card.id)
  }
  const cardFor = (code: string | null) => (code ? cardByCode.get(code.trim().toUpperCase()) : undefined)

  async function checkNow() {
    setChecking(true)
    setResult(null)
    try {
      const res = await checkCampaignReplies(campaign.id)
      setResult({ ok: res.ok, text: res.message })
      await onChecked()
    } catch (ex) {
      setResult({ ok: false, text: errorText(ex, 'Nie udało się sprawdzić skrzynki.') })
    } finally {
      setChecking(false)
    }
  }

  return (
    <div className="rounded-xl bg-white shadow-sm">
      <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-4 py-2.5">
        <b className="text-sm text-slate-900">Odpowiedzi klientów</b>
        <span className="flex flex-wrap items-center gap-2 text-[11px] text-slate-500">
          <span>
            {fmtInt(replies.total)} {plural(replies.total, 'odpowiedź', 'odpowiedzi', 'odpowiedzi')} · odpowiedziało{' '}
            {fmtInt(replies.recipients)} z {fmtInt(sent)} {plural(sent, 'odbiorcy', 'odbiorców', 'odbiorców')}
            {replies.checked_at ? ` · skrzynka sprawdzona ${fmtDateTime(replies.checked_at)}` : ''}
          </span>
          {replies.enabled && canCheck && (
            <button
              type="button"
              className={BTN_SM}
              disabled={checking}
              title="Zwykle sprawdzamy co 10 minut — ten przycisk czyta nowe maile od razu"
              onClick={() => void checkNow()}
            >
              {checking ? 'Sprawdzam…' : 'Sprawdź skrzynkę teraz'}
            </button>
          )}
        </span>
      </div>
      {result && (
        <p
          className={`mx-4 mt-3 rounded px-3 py-2 text-xs ${result.ok ? 'bg-emerald-50 text-emerald-800' : 'bg-amber-50 text-amber-900'}`}
          role="status"
        >
          {result.text}
        </p>
      )}
      {!replies.enabled ? (
        <p className="px-4 py-3 text-xs text-slate-600">
          Liczenie odpowiedzi jest wyłączone w skrzynce autora kampanii (Moje konto → Moja poczta).
        </p>
      ) : (
        replies.error && (
          <p className="mx-4 mt-3 break-words rounded bg-red-50 px-3 py-2 text-xs text-red-700">
            Ostatni odczyt skrzynki nie udał się: {replies.error}
          </p>
        )
      )}
      {replies.list.length > 0 ? (
        <div className="overflow-x-auto">
          <table className="w-full text-left text-xs">
            <thead>
              <tr className="border-b bg-slate-50">
                <SortTh label="Od" k="from_email" sort={sort} onSort={toggleSort} />
                <SortTh label="Otrzymano" k="received_at" sort={sort} onSort={toggleSort} />
                <SortTh label="Towar" k="item_code" sort={sort} onSort={toggleSort} />
                <SortTh label="Temat" k="subject" sort={sort} onSort={toggleSort} />
              </tr>
            </thead>
            <tbody>
              {sortRows(replies.list, sort, (r, k) => (k === 'received_at' ? sortDate(r.received_at) : r[k])).map((r) => (
                <tr key={r.id} className="border-b align-top">
                  <td className="p-2">
                    <span className="font-mono text-slate-900">{r.from_email}</span>
                    {r.from_name && <span className="block text-[11px] text-slate-500">{r.from_name}</span>}
                    {r.recipient_email === null ? (
                      <span className="block text-[10px] text-amber-800">spoza listy odbiorców (np. przekazany mail)</span>
                    ) : (
                      r.recipient_email.toLowerCase() !== r.from_email && (
                        <span className="block text-[10px] text-slate-500">odbiorca kampanii: {r.recipient_email}</span>
                      )
                    )}
                  </td>
                  <td className="whitespace-nowrap p-2 tabular-nums text-slate-600">{fmtDateTime(r.received_at)}</td>
                  <td className="whitespace-nowrap p-2 font-mono text-slate-700">
                    {cardFor(r.item_code) !== undefined ? (
                      <button
                        type="button"
                        className="font-mono text-blue-600 hover:underline focus-visible:underline"
                        title={`${r.item_code} — kliknij, aby zobaczyć kartę produktu`}
                        onClick={() => setPreviewId(cardFor(r.item_code) ?? null)}
                      >
                        {r.item_code}
                      </button>
                    ) : (
                      (r.item_code ?? '—')
                    )}
                  </td>
                  <td className="max-w-[28rem] p-2">
                    <span className="break-words text-slate-800">{r.subject || '(bez tematu)'}</span>
                    <span className="block text-[10px] text-slate-500">
                      {r.matched_by === 'code' ? 'kod kampanii w temacie' : 'odpowiedź na mail kampanii'}
                    </span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : (
        replies.enabled && <p className="px-4 py-3 text-xs text-slate-500">Na razie brak odpowiedzi.</p>
      )}
      <p className="px-4 py-3 text-[11px] text-slate-500">
        Liczymy maile w skrzynce autora (wszystkie foldery poza Wysłanymi, Koszem, Spamem i Szkicami) z kodem{' '}
        {campaign.code} w temacie (przycisk „Zapytaj o ofertę”) i odpowiedzi na mail kampanii. Autoodpowiedzi („jestem na
        urlopie”) i zwrotki serwera pomijamy. Treści maili nie czytamy — odpowiadasz klientowi jak zwykle ze swojej poczty.
        {others > 0 ? ' Odpowiedź spoza listy odbiorców to zwykle ktoś, komu klient przekazał mail.' : ''}
      </p>
      <ProductVerifyModal productId={previewId} onClose={() => setPreviewId(null)} />
    </div>
  )
}

/**
 * „Kliknęli — do kogo zadzwonić”: odbiorcy, którzy kliknęli produkt albo „Zapytaj o ofertę”, od najczęściej klikających.
 * Kliknięcia skanerów poczty (zaraz po doręczeniu, automaty) nie liczą się.
 */
function ClickedPanel({ campaign, clicks, tick }: { campaign: Campaign; clicks: CampaignClicks; tick: number }) {
  const [rows, setRows] = useState<CampaignRecipientRow[] | null>(null)
  const [err, setErr] = useState('')
  // serwer zwraca najwyżej 100 klikających — sortuje on, żeby kolejność dotyczyła wszystkich
  const [sort, toggleSort] = useTableSort<'email' | 'clicks' | 'first_clicked_at'>(['clicks'], { key: 'clicks', dir: 'desc' })

  useEffect(() => {
    let alive = true
    campaignRecipients(campaign.id, { clicked: true, per_page: 100, sort: sort?.key, dir: sort?.dir })
      .then((res) => {
        if (alive) {
          setRows(res.data)
          setErr('')
        }
      })
      .catch((ex: unknown) => {
        if (alive) setErr(errorText(ex, 'Nie udało się wczytać klikających.'))
      })
    return () => {
      alive = false
    }
  }, [campaign.id, tick, sort])

  const sent = campaign.totals?.sent ?? 0
  return (
    <div className="rounded-xl bg-white shadow-sm">
      <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-4 py-2.5">
        <b className="text-sm text-slate-900">Kliknęli — do kogo zadzwonić</b>
        <span className="text-[11px] text-slate-500">
          kliknęło {fmtInt(clicks.recipients)} z {fmtInt(sent)} {plural(sent, 'odbiorcy', 'odbiorców', 'odbiorców')} ·{' '}
          {fmtInt(clicks.total)} {plural(clicks.total, 'kliknięcie', 'kliknięcia', 'kliknięć')}
          {clicks.bots > 0 ? ` · pominięte kliknięcia skanerów poczty: ${fmtInt(clicks.bots)}` : ''}
        </span>
      </div>
      {err && <p className="px-4 py-2 text-xs text-red-700">{err}</p>}
      {rows !== null && rows.length > 0 ? (
        <div className="overflow-x-auto">
          <table className="w-full text-left text-xs">
            <thead>
              <tr className="border-b bg-slate-50">
                <SortTh label="Adres" k="email" sort={sort} onSort={toggleSort} />
                <SortTh label="Kliknięcia" k="clicks" sort={sort} onSort={toggleSort} align="right" />
                <SortTh label="Pierwsze kliknięcie" k="first_clicked_at" sort={sort} onSort={toggleSort} />
              </tr>
            </thead>
            <tbody>
              {rows.map((r) => (
                <tr key={r.id} className="border-b">
                  <td className="p-2">
                    <span className="font-mono text-slate-900">{r.email}</span>
                    {r.name && <span className="block text-[11px] text-slate-500">{r.name}</span>}
                  </td>
                  <td className="p-2 text-right tabular-nums font-semibold text-blue-700">{fmtInt(r.clicks ?? 0)}</td>
                  <td className="whitespace-nowrap p-2 tabular-nums text-slate-600">{fmtDateTime(r.first_clicked_at ?? null)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : (
        rows !== null && <p className="px-4 py-3 text-xs text-slate-500">Nikt jeszcze nie kliknął linku w mailu.</p>
      )}
      <p className="px-4 py-3 text-[11px] text-slate-500">
        Liczymy wejścia na stronę produktu (zdjęcie, nazwa, „Zobacz produkt”). „Zapytaj o ofertę” otwiera od razu program
        pocztowy klienta, więc tego kliknięcia nie widać — mail z kodem kampanii w temacie liczymy niżej w „Odpowiedziach
        klientów”.
        Otwarć maila nie liczymy (programy pocztowe je zawyżają); kliknięcia w ciągu minuty od doręczenia i od automatów to
        skanery poczty.
      </p>
    </div>
  )
}
