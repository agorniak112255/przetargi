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
  StageTwo,
} from '../components/CampaignsUi'
import { ProductSearchSelect } from '../components/ProductSearchSelect'
import { XlCustomersModal } from '../components/XlCustomersModal'
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
  campaignAudience,
  campaignPreview,
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
  type CampaignItem,
  type CampaignItemPatch,
  type CampaignLayout,
  type CampaignPatch,
  type CampaignPreview,
  type CampaignRecipientRow,
  type CampaignRecipientStatus,
  type CampaignTotals,
  type CampaignSales,
  type CampaignXlMode,
  type MailingList,
  type PageMeta,
} from '../lib/campaigns'
import { plural } from '../lib/plural'

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

const LAYOUTS: { value: CampaignLayout; label: string; cols: number }[] = [
  { value: 'grid3', label: 'Siatka po 3', cols: 3 },
  { value: 'grid2', label: 'Siatka po 2', cols: 2 },
  { value: 'list', label: 'Lista z opisem', cols: 1 },
]

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
  heading: string
  intro: string
  valid_until: string
  layout: CampaignLayout
}

function contentOf(c: Campaign): Content {
  return {
    subject: c.subject ?? '',
    preheader: c.preheader ?? '',
    heading: c.heading ?? '',
    intro: c.intro ?? '',
    valid_until: c.valid_until ? c.valid_until.slice(0, 10) : '',
    layout: c.layout,
  }
}

function contentPatch(patch: Partial<Content>): CampaignPatch {
  const out: CampaignPatch = {}
  if (patch.subject !== undefined) out.subject = patch.subject
  if (patch.preheader !== undefined) out.preheader = patch.preheader.trim() === '' ? null : patch.preheader
  if (patch.heading !== undefined) out.heading = patch.heading.trim() === '' ? null : patch.heading
  if (patch.intro !== undefined) out.intro = patch.intro.trim() === '' ? null : patch.intro
  if (patch.valid_until !== undefined) out.valid_until = patch.valid_until === '' ? null : patch.valid_until
  if (patch.layout !== undefined) out.layout = patch.layout
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
      setDialogErr(errorText(ex, dialog === 'delete' ? 'Nie udało się usunąć projektu.' : 'Nie udało się anulować wysyłki.'))
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
          <button type="button" className={BTN} disabled={duplicating} onClick={() => void duplicate()}>
            Duplikuj
          </button>
          {editable && (
            <button
              type="button"
              className={`${BTN} text-red-700`}
              onClick={() => {
                setDialogErr('')
                setDialog('delete')
              }}
            >
              Usuń projekt
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
        <SentView campaign={campaign} onReload={load} />
      )}

      {dialog === 'delete' && (
        <ConfirmDialog
          title="Usunąć projekt kampanii?"
          danger
          confirmLabel="Usuń projekt"
          busy={dialogBusy}
          error={dialogErr}
          onClose={() => setDialog(null)}
          onConfirm={() => void confirmDialog()}
          message={
            <p>
              <b>{campaign.name}</b> — {campaign.items.length} {plural(campaign.items.length, 'pozycja', 'pozycje', 'pozycji')}.
              Projekt zniknie z listy kampanii.
            </p>
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
            <>
              <p>
                Maile, które już wyszły, zostają u klientów. Pozostali odbiorcy <b>nie dostaną</b> tej kampanii.
              </p>
              <p className="text-xs text-slate-600">Anulowanej kampanii nie da się wznowić — można ją zduplikować.</p>
            </>
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
  const [content, setContent] = useState<Content>(() => contentOf(campaign))
  const pending = useRef<Partial<Content>>({})
  const timer = useRef<number | null>(null)
  const campaignId = campaign.id

  const flush = useCallback(async () => {
    if (timer.current !== null) {
      window.clearTimeout(timer.current)
      timer.current = null
    }
    const patch = pending.current
    if (Object.keys(patch).length === 0) return
    pending.current = {}
    await mutate(() => updateCampaign(campaignId, contentPatch(patch)), 'Nie udało się zapisać treści.')
  }, [mutate, campaignId])

  // Wyjście ze strony w trakcie pisania — niezapisana treść idzie od razu.
  const flushRef = useRef(flush)
  useEffect(() => {
    flushRef.current = flush
  }, [flush])
  useEffect(() => () => void flushRef.current(), [])

  function editContent(patch: Partial<Content>, immediate = false) {
    setContent((c) => ({ ...c, ...patch }))
    pending.current = { ...pending.current, ...patch }
    if (timer.current !== null) window.clearTimeout(timer.current)
    if (immediate) void flush()
    else timer.current = window.setTimeout(() => void flush(), CONTENT_SAVE_MS)
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
  const layoutLabel = LAYOUTS.find((l) => l.value === content.layout)?.label ?? content.layout

  const steps: { n: Step; title: string; hint: string; done: boolean }[] = [
    { n: 1, title: 'Produkty', hint: `${items.length} ${plural(items.length, 'pozycja', 'pozycje', 'pozycji')}`, done: items.length > 0 },
    {
      n: 2,
      title: 'Odbiorcy',
      hint: !hasAudienceChoice ? 'nie wybrano' : audience ? `${fmtInt(audience.final)} ${plural(audience.final, 'odbiorca', 'odbiorcy', 'odbiorców')}` : 'wybrano',
      done: (audience?.final ?? 0) > 0,
    },
    { n: 3, title: 'Treść', hint: content.subject.trim() ? `układ: ${layoutLabel.toLowerCase()}` : 'brak tematu', done: content.subject.trim() !== '' },
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
        <ContentStep content={content} editable={editable} onEdit={editContent} onBlur={() => void flush()} onNext={() => void goTo(4)} />
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
  const items = campaign.items
  const full = items.length >= MAX_ITEMS

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
  onRemove,
  onPickCard,
  onInvalid,
}: {
  item: CampaignItem
  editable: boolean
  margin: number | undefined
  onPatch: (patch: CampaignItemPatch) => Promise<Campaign | null>
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
}: {
  campaign: Campaign
  editable: boolean
  mutate: Mutate
  audience: AudiencePreview | null
  audienceLoading: boolean
  audienceErr: string
  onNext: () => void
}) {
  const { user } = useAuth()
  const [lists, setLists] = useState<MailingList[] | null>(null)
  const [listsErr, setListsErr] = useState('')
  // Wybór pokazywany od razu (kolejne kliknięcia budują się na nim), zapis PATCH w tle.
  const [draft, setDraft] = useState<CampaignAudience>(campaign.audience)
  const [lastServer, setLastServer] = useState(campaign.audience)
  const [xlPicker, setXlPicker] = useState<CampaignXlMode | null>(null)
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

  function toggleList(id: number, on: boolean) {
    const ids = on ? [...new Set([...draft.list_ids, id])] : draft.list_ids.filter((x) => x !== id)
    save({ ...draft, list_ids: ids })
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
                </span>
                <span className="flex items-center gap-2">
                  <Chip tone={l.contacts_count > 0 ? 'green' : 'red'}>{fmtInt(l.contacts_count)}</Chip>
                  <Link
                    to={`/kampanie/grupy/${l.id}`}
                    className={BTN_SM}
                    title="Otwiera adresy tej grupy (wybór odbiorców kampanii zapisuje się sam)"
                    onClick={(e) => e.stopPropagation()}
                  >
                    Pokaż
                  </Link>
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
          Dalej: treść →
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
  content,
  editable,
  onEdit,
  onBlur,
  onNext,
}: {
  content: Content
  editable: boolean
  onEdit: (patch: Partial<Content>, immediate?: boolean) => void
  onBlur: () => void
  onNext: () => void
}) {
  const field = `${INPUT} mt-1 block w-full text-sm`
  return (
    <div className="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_300px]">
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
        <label className="block font-medium text-slate-700">
          Nagłówek w mailu
          <input
            className={field}
            maxLength={200}
            disabled={!editable}
            value={content.heading}
            onChange={(e) => onEdit({ heading: e.target.value })}
            onBlur={onBlur}
            placeholder="np. Końcówki serii w cenach wyprzedażowych"
          />
        </label>
        <label className="block font-medium text-slate-700">
          Tekst
          <textarea
            className={`${field} min-h-28 resize-y`}
            disabled={!editable}
            value={content.intro}
            onChange={(e) => onEdit({ intro: e.target.value })}
            onBlur={onBlur}
            placeholder={'Dzień dobry,\nmamy na magazynie końcówki serii…'}
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
        <div>
          <div className="mb-1.5 font-medium text-slate-700">Układ</div>
          <div className="grid gap-2 sm:grid-cols-3">
            {LAYOUTS.map((l) => {
              const on = content.layout === l.value
              return (
                <button
                  key={l.value}
                  type="button"
                  aria-pressed={on}
                  disabled={!editable}
                  onClick={() => onEdit({ layout: l.value }, true)}
                  className={`grid gap-1.5 rounded-lg border bg-white p-2 text-left ${
                    on ? 'border-blue-600 ring-1 ring-blue-600' : 'border-slate-300 hover:border-slate-400'
                  }`}
                >
                  <span className="grid gap-1 rounded bg-slate-100 p-1.5" aria-hidden>
                    <i className="block h-1.5 w-3/5 rounded-sm bg-slate-300" />
                    <i className="block h-1.5 rounded-sm bg-slate-300" />
                    <span className="grid gap-1" style={{ gridTemplateColumns: `repeat(${l.cols}, minmax(0, 1fr))` }}>
                      {Array.from({ length: l.cols === 1 ? 2 : l.cols }, (_, i) => (
                        <i key={i} className="block h-4 rounded-sm bg-slate-300" />
                      ))}
                    </span>
                  </span>
                  <span className="text-xs font-medium text-slate-800">{l.label}</span>
                </button>
              )
            })}
          </div>
          <p className="mt-1.5 flex items-center gap-1.5 text-[11px] text-slate-500">
            Szablon firmowy z logo i stopką. Własne szablony handlowca <StageTwo />
          </p>
        </div>
      </div>
      <aside className="rounded-xl bg-white p-4 shadow-sm">
        <h2 className="app-card-title mb-2 text-sm font-semibold text-slate-900">Stałe elementy</h2>
        <ul className="space-y-1.5 text-xs text-slate-600">
          <Check ok>Nazwa i hasło firmy w nagłówku, stopka firmy</Check>
          <Check ok>Twój podpis z „Moja poczta”</Check>
          <Check ok>„Ceny netto ważne do … lub do wyczerpania zapasów”</Check>
          <Check ok>Stan pozycji z dniem odczytu z XL</Check>
          <Check ok>Link „Wypisz mnie” w stopce i w nagłówku maila</Check>
        </ul>
        <button type="button" className={`${BTN_PRIMARY} mt-3 w-full`} onClick={onNext}>
          Dalej: podgląd →
        </button>
      </aside>
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
  const [confirm, setConfirm] = useState(false)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const [scheduleAt, setScheduleAt] = useState(defaultScheduleValue)
  const [scheduling, setScheduling] = useState(false)
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

  async function schedule() {
    const at = new Date(scheduleAt)
    if (Number.isNaN(at.getTime())) {
      setErr('Wybierz dzień i godzinę wysyłki.')
      return
    }
    setScheduling(true)
    setErr('')
    try {
      await flush()
      onSent(await scheduleCampaign(campaign.id, at.toISOString()))
    } catch (ex) {
      setErr(errorText(ex, 'Nie udało się zaplanować wysyłki.'))
    } finally {
      setScheduling(false)
    }
  }

  async function send() {
    setBusy(true)
    setErr('')
    try {
      await flush()
      const c = await sendCampaign(campaign.id)
      setConfirm(false)
      onSent(c)
    } catch (ex) {
      setErr(errorText(ex, 'Nie udało się rozpocząć wysyłki.'))
    } finally {
      setBusy(false)
    }
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
            disabled={blockers.length > 0 || busy}
            title={blockers.length > 0 ? `Nie można wysłać: ${blockers.join(', ')}` : undefined}
            onClick={() => {
              setErr('')
              setConfirm(true)
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
            disabled={blockers.length > 0 || scheduling || !scheduleAt}
            title={
              blockers.length > 0
                ? `Nie można zaplanować: ${blockers.join(', ')}`
                : 'Kampania wystartuje sama o tej godzinie; odbiorców i stany policzymy w chwili startu'
            }
            onClick={() => void schedule()}
          >
            {scheduling ? 'Planuję…' : 'Zaplanuj'}
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
        <ConfirmDialog
          title="Wysłać kampanię teraz?"
          confirmLabel={`Wyślij do ${fmtInt(final)} ${plural(final, 'odbiorcy', 'odbiorców', 'odbiorców')}`}
          busy={busy}
          error={err}
          onClose={() => setConfirm(false)}
          onConfirm={() => void send()}
          message={
            <>
              <p>
                <b>{campaign.name}</b> pójdzie do <b>{fmtInt(final)}</b> {plural(final, 'odbiorcy', 'odbiorców', 'odbiorców')} z
                Twojej skrzynki.
              </p>
              <p className="text-xs text-slate-600">
                Tego nie da się cofnąć — można tylko zatrzymać wysyłkę do tych, którzy jeszcze nie dostali maila. Stan
                pozycji zapiszemy teraz, żeby policzyć, ile zeszło po 7 i 30 dniach.
              </p>
            </>
          }
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

function SentView({ campaign, onReload }: { campaign: Campaign; onReload: () => Promise<void> }) {
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
      const res = await campaignRecipients(campaign.id, { status: filter, page })
      if (my !== seq.current) return
      setRows(res.data)
      setMeta(res.meta)
      setErr('')
    } catch (ex) {
      if (my === seq.current) setErr(errorText(ex, 'Nie udało się wczytać odbiorców.'))
    } finally {
      if (my === seq.current) setLoading(false)
    }
  }, [campaign.id, filter, page])

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
          label={campaign.sent_at ? 'Zakończona' : 'Rozpoczęta'}
          value={fmtDateTime(campaign.sent_at ?? campaign.sending_started_at)}
          small
        />
      </div>

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
                <th className="p-2">Pozycja</th>
                <th className="p-2 text-right">Cena w mailu</th>
                <th className="p-2 text-right">Przy wysyłce</th>
                <th className="p-2 text-right">Po 7 dniach</th>
                <th className="p-2 text-right">Po 30 dniach</th>
                <th className="p-2">Zeszło</th>
                <th className="p-2 text-right">Kupili odbiorcy kampanii</th>
              </tr>
            </thead>
            <tbody>
              {campaign.items.map((i) => {
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
              <th className="p-2">Adres</th>
              <th className="p-2">Źródło</th>
              <th className="p-2">Status</th>
              <th className="p-2">Wysłano</th>
              <th className="p-2">Uwagi</th>
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
                <td className="whitespace-nowrap p-2 tabular-nums text-slate-600">{r.sent_at ? fmtDateTime(r.sent_at) : '—'}</td>
                <td className="max-w-[24rem] p-2 text-[11px]">
                  {r.error && <span className="break-words text-red-700">{r.error}</span>}
                  {r.unsubscribed_at && <span className="block text-slate-600">wypisał się {fmtDate(r.unsubscribed_at)}</span>}
                </td>
              </tr>
            ))}
            {rows !== null && rows.length === 0 && (
              <tr>
                <td colSpan={5} className="p-6 text-center text-slate-500">
                  Brak odbiorców w tym widoku.
                </td>
              </tr>
            )}
          </tbody>
        </table>
        <Pager meta={meta} disabled={loading} onPage={setPage} />
      </div>

      <div className="rounded-xl bg-white p-4 shadow-sm">
        <button type="button" className={BTN} onClick={() => void togglePreview()}>
          {previewOpen ? 'Ukryj treść maila' : 'Pokaż treść maila'}
        </button>
        {previewOpen && (
          <div className="mt-3">
            {previewErr ? <ErrorBar message={previewErr} /> : preview ? <MailPreview preview={preview} /> : <p className="text-xs text-slate-500">Ładowanie…</p>}
          </div>
        )}
      </div>
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

/**
 * „Kupili odbiorcy kampanii”: kto z odbiorców kupił pozycje kampanii w okresie od wysyłki (faktury i paragony XL,
 * odczyt nocny), a dla porównania ile kupili pozostali klienci. Zakup po mailu nie dowodzi, że kupili dzięki kampanii.
 */
function CampaignSalesPanel({ sales }: { sales: CampaignSales }) {
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
                <th className="p-2">Data</th>
                <th className="p-2">Klient</th>
                <th className="p-2">Mail poszedł na</th>
                <th className="p-2">Towar</th>
                <th className="p-2 text-right">Ilość</th>
                <th className="p-2 text-right">Netto</th>
                <th className="p-2">Dokument</th>
              </tr>
            </thead>
            <tbody>
              {sales.buyers.map((b, idx) => (
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
