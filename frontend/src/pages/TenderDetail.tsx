import { useCallback, useEffect, useRef, useState, type ReactNode } from 'react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { useAuth } from '../auth'
import { CheaperSourceNote } from '../components/CheaperSourceNote'
import { OrderQuantityBadge } from '../components/OrderQuantityBadge'
import { ItemBattlecard, type BattlecardProduct } from '../components/ItemBattlecard'
import { ProductAiMatchModal } from '../components/ProductAiMatchModal'
import { ProductVerifyModal } from '../components/ProductVerifyModal'
import { ProductSearchSelect } from '../components/ProductSearchSelect'
import { clampAiConcurrency, mapPool } from '../lib/aiConcurrency'
import {
  api,
  can,
  downloadFile,
  type Product,
  type ProductActiveVariant,
  type Substitute,
  type Tender,
  type TenderInvitationCreateResponse,
  type TenderResultStatus,
} from '../lib/api'
import { currencyLabel, formatPrice } from '../lib/priceChange'
import { offerMarkupFactor, productDisplayName, productThumbUrl, purchaseForOffer, suggestedOfferPrice } from '../lib/productLabel'
import { isDualRequirement } from '../lib/productAiSearch'
import { SiwzItemTile, SiwzRequirementBlock, splitSiwzRequirement } from '../components/SiwzRequirementBlock'
import { CardConflictsModal } from '../components/CardConflictsModal'
import type { TenderConflicts } from '../components/RequirementCheckList'
import { conflictsLabel, useRequirementCheck } from '../lib/useRequirementCheck'
import { TENDER_STATUS_LABEL } from '../lib/tenderStatus'
import { ShareToChatButton } from '../components/ShareToChatButton'
import { StatusFlow } from '../components/StatusFlow'
import { isTenderWizardActive, setTenderWizardActive } from '../lib/tenderWizard'
import { TenderResultSection } from '../components/tender/TenderResultSection'
import { MentionTextarea } from '../components/tender/MentionTextarea'
import { deadlineTimeLabel, formatDeadline } from '../lib/tenderDeadline'
import { LOT_FIELD_LABEL, NO_RESULT_CLASS, RESULT_STATUS_CLASS, resultStatusLabel } from '../lib/tenderResult'

type MatchReason = { code: string; label: string; points: number; url?: string }

/**
 * Nazwa produktu z powodu dopasowania bez przedrostka backendu (ProductMatchService, TenderItemController).
 * Stare wpisy w bazie mają wcześniejsze brzmienia przedrostków — obcinamy wszystkie.
 */
const EXTERNAL_HINT_PREFIX =
  /^(?:Link zewnętrzny \(nie z katalogu SUPON\)|Własna propozycja \(nie z katalogu SUPON\)|Podpowiedź \/ zamiennik \(nie z katalogu\)|Podpowiedź z internetu \(spoza katalogu\)):\s*/u

function externalHintTitle(label: string): string {
  return label.replace(EXTERNAL_HINT_PREFIX, '')
}

function ExternalHintLink({ reason }: { reason: MatchReason }) {
  const href = reason.url
  if (!href) {
    return <span>{reason.label}</span>
  }
  const badge =
    reason.code === 'custom_offer' ? 'Wpisany ręcznie — spoza katalogu' : 'Znaleziony w internecie — spoza katalogu'
  const title = externalHintTitle(reason.label)
  return (
    <a
      href={href}
      target="_blank"
      rel="noopener noreferrer"
      className="inline-flex flex-col gap-0.5 font-semibold text-amber-900 underline decoration-amber-400"
    >
      <span className="rounded bg-amber-200 px-1 py-px text-[9px] font-bold uppercase tracking-wide text-amber-950">
        {badge}
      </span>
      <span>{title}</span>
    </a>
  )
}

function isExternalOfferItem(item: Item, productId = ''): boolean {
  if (productId || item.main_product_id || item.main_product) {
    return false
  }
  const src = item.match_source ?? ''
  return (
    (item.custom_name ?? '').trim() !== '' ||
    src === 'external' ||
    src === 'custom'
  )
}

function isBrandSubstituteItem(item: Item): boolean {
  const src = item.match_source ?? ''
  if (src === 'ai_substitute') {
    return true
  }
  return (item.ai_match_reasons ?? []).some((r) => r.code === 'brand_substitute')
}

function ExternalOfferBanner({
  name,
  url,
}: {
  name: string
  url?: string | null
}) {
  return (
    <div className="max-w-[280px] rounded-md border-2 border-orange-500 bg-orange-100 px-2 py-1.5">
      <span className="inline-block rounded bg-orange-600 px-1.5 py-px text-[9px] font-bold uppercase tracking-wide text-white">
        Produkt spoza katalogu
      </span>
      <p className="mt-1 text-[11px] font-semibold text-orange-950">{name}</p>
      {url ? (
        <a
          href={url}
          target="_blank"
          rel="noopener noreferrer"
          className="mt-0.5 block truncate text-[10px] font-medium text-orange-800 underline"
        >
          {url}
        </a>
      ) : null}
    </div>
  )
}

function ExternalHints({
  reasons,
  onAddToOffer,
}: {
  reasons?: MatchReason[] | null
  onAddToOffer?: (hint: { url: string; title: string }) => void
}) {
  const links = (reasons ?? []).filter((r) => r.code === 'external_link' || r.code === 'custom_offer')
  if (links.length === 0) {
    return <span>—</span>
  }
  return (
    <div className="max-w-[280px] rounded border border-amber-300 bg-amber-50 px-2 py-1.5">
      {links.map((r, i) => (
        <div key={`${r.url ?? r.label}-${i}`} className="space-y-1">
          <ExternalHintLink reason={r} />
          {onAddToOffer && r.code === 'external_link' && r.url && (
            <button
              type="button"
              onClick={() =>
                onAddToOffer({
                  url: r.url!,
                  title: externalHintTitle(r.label),
                })
              }
              className="rounded bg-amber-700 px-2 py-0.5 text-[10px] font-medium text-white hover:bg-amber-800"
            >
              Dodaj do oferty
            </button>
          )}
        </div>
      ))}
    </div>
  )
}

type Item = {
  id: number
  line_no: number
  requirement: string
  ai_match_percent: number | null
  ai_match_reasons?: MatchReason[] | null
  match_source?: string | null
  quantity: number
  offer_price: string | null
  companion_offer_price?: string | null
  margin_percent: string | null
  status: string
  main_product: Product | null
  main_product_id?: number | null
  /** Wariant karty w ofercie (kolor, rozmiar, kod); etykieta i kod zapisane w chwili wyboru. */
  main_variant_id?: number | null
  main_variant_label?: string | null
  main_variant_sku?: string | null
  /** „auto” — wskazany przez dopasowanie z wymagania, „manual” — wybrany przez handlowca. */
  main_variant_source?: 'auto' | 'manual' | null
  main_variant?: ProductActiveVariant | null
  companion_product?: Product | null
  companion_product_id?: number | null
  custom_name?: string | null
  custom_url?: string | null
  updated_at?: string | null
}

type Coverage = {
  total: number
  with_product: number
  without_product: number
  without_price: number
  weak_match: number
  low_margin: number
  substitutes_pending: number
  ready: boolean
  blockers: string[]
  item_ids: {
    without_product: number[]
    without_price: number[]
    weak_match: number[]
    low_margin: number[]
  }
  thresholds: { min_match_score: number; min_margin_percent: number; match_concurrency?: number }
}

type ActivityRow = {
  id: number
  action: string
  meta?: Record<string, unknown> | null
  created_at: string
  user?: { name: string } | null
  item?: { id: number; line_no: number } | null
}

type CommentRow = {
  id: number
  body: string
  created_at: string
  user?: { name: string; role?: string } | null
  item?: { id: number; line_no: number } | null
  tender_item_id?: number | null
  /** osoby wspomniane przez „@” (powiadomione) */
  mentioned_users?: { id: number; name: string }[]
}

type InvitationRow = {
  id: number
  note: string | null
  email_sent_at: string | null
  created_at: string | null
  user?: { id: number; name: string; email: string; role?: string } | null
  inviter?: { id: number; name: string; email: string; role?: string } | null
}

type DirectoryUser = { id: number; name: string; email: string; role: string }

/** Sprzeczności kart zapisanych w pozycjach; błąd = brak oznaczeń (to tylko podpowiedź). */
async function fetchTenderConflicts(tenderId: string): Promise<TenderConflicts['items']> {
  try {
    const res = await api<TenderConflicts>(`/tenders/${tenderId}/conflicts`)
    return res.items && typeof res.items === 'object' ? res.items : {}
  } catch {
    return {}
  }
}

function itemProductId(item: Item): string {
  const id = item.main_product_id ?? item.main_product?.id
  return id != null ? String(id) : ''
}

type PickedProduct = {
  id: number
  sku: string
  name: string
  description?: string | null
  purchase_price?: string | number | null
  purchase_price_pln?: number | null
  currency?: string | null
  images?: Product['images']
}

function pickedFromProduct(
  p: {
    id: number
    sku: string
    name: string
    description?: string | null
    purchase_price?: string | number | null
    purchase_price_pln?: number | null
    currency?: string | null
    images?: Product['images']
  } | null | undefined,
): PickedProduct | null {
  if (!p) return null
  return {
    id: p.id,
    sku: p.sku,
    name: p.name,
    description: p.description,
    purchase_price: p.purchase_price,
    purchase_price_pln: p.purchase_price_pln,
    currency: p.currency,
    images: p.images,
  }
}

type ItemDraft = {
  main_product_id: number | null
  companion_product_id: number | null
  quantity: number
  offer_price: number | null
  companion_offer_price: number | null
  custom_name: string | null
  custom_url: string | null
}

type History = {
  id: number
  from_status: string | null
  to_status: string
  note: string | null
  created_at: string
  user?: { name: string }
}

type ConditionStatus = 'spelniamy' | 'nie_spelniamy'

type Condition = {
  id: number
  category: string | null
  content: string
  sort_order: number
  source: string
  tender_document_id?: number | null
  /** null = jeszcze nie sprawdzony („do sprawdzenia”) */
  status?: ConditionStatus | null
  status_at?: string | null
  status_user?: { id: number; name: string } | null
}

type DocMeta = {
  id: number
  original_name: string
  extension: string
  size_bytes: number
  mode: string
  targets: string[] | null
  has_file: boolean
  created_at: string
  uploader?: { name: string } | null
}

type PreviewItem = {
  sku?: string | null
  name?: string
  requirement: string
  quantity: number
  offer_price?: number | null
  currency?: string | null
  norms?: string | null
  description?: string | null
  selected: boolean
}
type PreviewCondition = { category: string | null; content: string; selected: boolean }

type Detail = {
  tender: Tender & {
    items: Item[]
    conditions?: Condition[]
    documents?: DocMeta[]
    title: string
    owner_id?: number | null
    status_histories?: History[]
  }
  substitutes_by_main: Record<string, Substitute[]>
  can_edit: boolean
  next_statuses: string[]
  coverage?: Coverage
}

type TenderTab =
  | 'podsumowanie'
  | 'dokumenty'
  | 'warunki'
  | 'pozycje'
  | 'zamienniki'
  | 'oferta'
  | 'wynik'
  | 'komentarze'
  | 'zaproszenia'
  | 'historia'

/** Menu boczne pełnego widoku przetargu (po kreatorze). Zmiana statusu jest w sekcji Historia i statusy. */
const TAB_GROUPS: Array<{ label: string; tabs: Array<{ key: TenderTab; label: string }> }> = [
  { label: 'Przegląd', tabs: [{ key: 'podsumowanie', label: 'Podsumowanie' }] },
  {
    label: 'Przygotowanie',
    tabs: [
      { key: 'dokumenty', label: 'Dokumenty' },
      { key: 'warunki', label: 'Warunki' },
    ],
  },
  {
    label: 'Wycena',
    tabs: [
      { key: 'pozycje', label: 'Pozycje' },
      { key: 'zamienniki', label: 'Zamienniki' },
      { key: 'oferta', label: 'Oferta' },
    ],
  },
  { label: 'Po terminie', tabs: [{ key: 'wynik', label: 'Wynik przetargu' }] },
  {
    label: 'Zespół',
    tabs: [
      { key: 'komentarze', label: 'Komentarze' },
      { key: 'zaproszenia', label: 'Zaproszenia' },
      { key: 'historia', label: 'Historia i statusy' },
    ],
  },
]

/**
 * Sekcje, które można otworzyć adresem „?tab=…” (linki z powiadomień: „/tenders/12?tab=wynik”, „?tab=komentarze”).
 * Taki adres pokazuje pełny widok przetargu także wtedy, gdy przetarg jest jeszcze w kreatorze.
 */
const URL_TABS: TenderTab[] = ['wynik', 'komentarze']

function urlTabOf(value: string | null): TenderTab | null {
  return value && (URL_TABS as string[]).includes(value) ? (value as TenderTab) : null
}

/** Jedno zdanie pod nagłówkiem sekcji pełnego widoku. Dokumenty, Warunki i Komentarze mają opis we własnej sekcji. */
const SECTION_INTRO: Partial<Record<TenderTab, string>> = {
  podsumowanie: 'Najważniejsze liczby oferty i lista rzeczy, które trzeba jeszcze uzupełnić.',
  pozycje: 'Produkty, o które prosi zamawiający. Do każdej pozycji dobieramy nasz produkt i cenę.',
  zamienniki:
    'Inne produkty, które spełniają te same wymagania — często tańsze. Zatwierdza je osoba z uprawnieniem (zwykle kierownik).',
  oferta: 'Zestawienie cen, które trafi do oferty.',
  historia: 'Kto i kiedy co zmienił oraz na jakim etapie jest przetarg.',
}

const NARZUT_HINT =
  'Narzut doliczany do ceny zakupu. Przy 18% produkt kupiony za 100 zł ma w ofercie 118 zł (marża około 15%). ' +
  'Zmiana narzutu przelicza proporcjonalnie wszystkie ceny w ofercie, także poprawione ręcznie.'

const MARGIN_HINT = 'Ile procent ceny w ofercie zostaje po odjęciu aktualnej ceny zakupu.'

const MATCH_AVERAGE_HINT =
  'Szacunek, jak bardzo produkty pasują do opisu zamawiającego — średnia z pozycji ocenionych automatycznie; ' +
  'produkty wybrane ręcznie nie mają oceny. Nie zastępuje sprawdzenia karty produktu.'

/** Tryby odczytu dokumentu (wartości `mode` jak w API). */
const DOC_MODE_LABEL: Record<string, string> = {
  simple: 'Tylko tekst',
  ai: 'Odczyt z podglądem',
  full: 'Odczyt z podglądem i zapis pliku',
}

/** Skąd jest warunek (`source` w tender_conditions). */
const CONDITION_SOURCE_LABEL: Record<string, string> = {
  manual: 'dodany ręcznie',
  document: 'z dokumentacji przetargu',
}

/** Kategorie warunków (odczyt dokumentu: termin|dostawa|gwarancja|certyfikat|platnosc|it|inne). Kolejność = kolejność grup. */
const CONDITION_CATEGORY_LABEL: Record<string, string> = {
  termin: 'Terminy',
  dostawa: 'Dostawa',
  gwarancja: 'Gwarancja',
  certyfikat: 'Certyfikaty i normy',
  platnosc: 'Płatności',
  it: 'Faktury i systemy elektroniczne',
  inne: 'Inne',
}

function conditionCategoryLabel(category: string | null): string {
  // „a|b|c” = przepisana lista wariantów ze schematu odczytu (wpisy sprzed 02.10.2026), nie kategoria
  if (!category || category.includes('|')) return 'Bez kategorii'
  const known = CONDITION_CATEGORY_LABEL[category.toLowerCase()]
  return known ?? category.charAt(0).toUpperCase() + category.slice(1)
}

const CONDITION_STATUS_OPTIONS: Array<{ value: ConditionStatus | null; label: string; active: string }> = [
  { value: 'spelniamy', label: 'Spełniamy', active: 'border-emerald-600 bg-emerald-600 text-white' },
  { value: null, label: 'Do sprawdzenia', active: 'border-amber-500 bg-amber-500 text-white' },
  { value: 'nie_spelniamy', label: 'Nie spełniamy', active: 'border-red-600 bg-red-600 text-white' },
]

/** Typ zamiennika (enum `type` w product_substitutes). */
const SUBSTITUTE_TYPE_LABEL: Record<string, string> = {
  preferowany: 'Preferowany',
  tanszy: 'Tańszy',
  premium: 'Droższy, wyższej klasy',
  awaryjny: 'Awaryjny',
}

/** Decyzja o zamienniku (`approval_status`; wartości wysyłane do API bez zmian). */
const SUBSTITUTE_STATUS_LABEL: Record<string, string> = {
  oczekuje: 'Do zatwierdzenia',
  zatwierdzony: 'Zatwierdzony',
  odrzucony: 'Odrzucony',
}

/** Dni do terminu liczone w kalendarzu lokalnym (0 = dziś, ujemne = po terminie); null bez daty. */
function daysUntil(day: string): number | null {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(day)
  if (!m) return null
  const target = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]))
  const now = new Date()
  const today = new Date(now.getFullYear(), now.getMonth(), now.getDate())
  return Math.round((target.getTime() - today.getTime()) / 86400000)
}

/** Rozwijane menu akcji w nagłówku (Eksport, ⋯). Zamyka się po wyborze, kliknięciu obok i Esc. */
function ActionMenu({
  label,
  ariaLabel,
  disabled,
  items,
}: {
  label: ReactNode
  ariaLabel?: string
  disabled?: boolean
  items: Array<{ label: string; hint?: string; danger?: boolean; onSelect: () => void }>
}) {
  const [open, setOpen] = useState(false)
  const ref = useRef<HTMLDivElement | null>(null)

  useEffect(() => {
    if (!open) return
    const onDown = (e: MouseEvent) => {
      if (!ref.current?.contains(e.target as Node)) setOpen(false)
    }
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setOpen(false)
    }
    document.addEventListener('mousedown', onDown)
    document.addEventListener('keydown', onKey)
    return () => {
      document.removeEventListener('mousedown', onDown)
      document.removeEventListener('keydown', onKey)
    }
  }, [open])

  return (
    <div ref={ref} className="app-menu relative">
      <button
        type="button"
        disabled={disabled}
        aria-haspopup="menu"
        aria-expanded={open}
        aria-label={ariaLabel}
        onClick={() => setOpen((v) => !v)}
        className="app-menu-button rounded border border-slate-300 bg-white px-2 py-1.5 text-[11px] font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-50"
      >
        {label}
      </button>
      {open && (
        <div
          role="menu"
          className="app-menu-list absolute right-0 z-30 mt-1 w-64 rounded-lg border border-slate-200 bg-white p-1 shadow-lg"
        >
          {items.map((it) => (
            <button
              key={it.label}
              type="button"
              role="menuitem"
              onClick={() => {
                setOpen(false)
                it.onSelect()
              }}
              className={`block w-full rounded px-2 py-1.5 text-left text-xs hover:bg-slate-100 ${
                it.danger ? 'text-red-700' : 'text-slate-700'
              }`}
            >
              <span className="font-semibold">{it.label}</span>
              {it.hint && <span className="block text-[11px] text-slate-500">{it.hint}</span>}
            </button>
          ))}
        </div>
      )}
    </div>
  )
}

type CoverageFilter = keyof Coverage['item_ids'] | null

type MatchChange = {
  id: number
  line_no: number
  action: 'changed' | 'cleared' | 'unchanged' | 'skipped_custom' | 'no_match'
  from_sku: string | null
  to_sku: string | null
}

type MatchReport = {
  processed: number
  changed: number
  unchanged: number
  cleared: number
  skipped_custom: number
  no_match: number
  /** pozycje, dla których model nie odpowiedział — czekają albo zostały z poprzednią kartą (≤ 70%) */
  model_unavailable?: number
  /** wszystkie pozycje, przy których zapytanie do modelu padło (limit tempa, timeout, błąd API) */
  model_failed?: number
  /** tryb „tylko puste”: pozycje z produktem ≥ progu lub własne — nie wysłane do modelu */
  left_as_is?: number
  /** pozycje, których żądanie przeglądarka zerwała po limicie czasu — serwer liczy je dalej, liczby wyżej ich nie obejmują */
  finished_in_background?: number
  avg_score: number
  changes: MatchChange[]
  at: string
}

type MatchProgress = {
  done: number
  total: number
  /** pozycje (id), na które przeglądarka czeka w tej chwili — najwyżej „Ile zapytań AI naraz” */
  inFlight: number[]
}

function matchReportStorageKey(tenderId: string): string {
  return `tender-match-report-${tenderId}`
}

function matchTargetIds(
  items: Item[],
  onlyEmpty: boolean,
  itemIds: number[] | undefined,
  minScore: number,
): number[] {
  const scoped = itemIds ? items.filter((i) => itemIds.includes(i.id)) : items
  if (!onlyEmpty) {
    return scoped.map((i) => i.id)
  }
  return scoped
    .filter((i) => {
      if ((i.custom_name ?? '').trim() !== '') {
        return false
      }
      if (i.main_product_id == null && i.main_product == null) {
        return true
      }
      if (i.ai_match_percent == null) {
        return false
      }
      return i.ai_match_percent < minScore
    })
    .map((i) => i.id)
}

/**
 * Limit czasu jednej pozycji: zrozumienie, ocena kart i ewentualne przepisanie zapytania idą po kolei, a każde
 * zapytanie do modelu ma własny limit i ponowienia przy przeciążeniu.
 */
const MATCH_ITEM_ABORT_MS = 900_000

/**
 * Każda pozycja w toku zajmuje proces PHP na cały czas pracy modelu, a serwer ma ich 40 (pm.max_children, 25.09.2026)
 * dla wszystkich użytkowników i dodatku Thunderbirda — wyższe „Ile zapytań AI naraz” nie zajmie reszty.
 */
const MATCH_MAX_PARALLEL_ITEMS = 24

function matchParallelItems(concurrency: number | undefined): number {
  return Math.min(clampAiConcurrency(concurrency), MATCH_MAX_PARALLEL_ITEMS)
}

const ULID_ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ'

/** Numer przebiegu (ULID) — wszystkie pozycje jednego kliknięcia to jeden przebieg w Statystykach AI. */
function newMatchRunId(): string {
  let time = Date.now()
  let out = ''
  for (let i = 0; i < 10; i += 1) {
    out = ULID_ALPHABET[time % 32] + out
    time = Math.floor(time / 32)
  }
  const random = new Uint8Array(16)
  crypto.getRandomValues(random)
  for (const byte of random) {
    out += ULID_ALPHABET[byte % 32]
  }
  return out
}

function formatMatchEta(seconds: number): string {
  if (seconds < 60) {
    return `około ${seconds} ${seconds === 1 ? 'sekundy' : 'sekund'}`
  }
  const minutes = Math.ceil(seconds / 60)
  return `około ${minutes} ${minutes === 1 ? 'minuty' : 'minut'}`
}

function loadMatchReport(tenderId: string): MatchReport | null {
  try {
    const raw = sessionStorage.getItem(matchReportStorageKey(tenderId))
    if (!raw) {
      return null
    }
    return JSON.parse(raw) as MatchReport
  } catch {
    return null
  }
}

function foldSearch(s: string): string {
  return s
    .toLowerCase()
    .replace(/ą/g, 'a')
    .replace(/ć/g, 'c')
    .replace(/ę/g, 'e')
    .replace(/ł/g, 'l')
    .replace(/ń/g, 'n')
    .replace(/ó/g, 'o')
    .replace(/ś/g, 's')
    .replace(/ź/g, 'z')
    .replace(/ż/g, 'z')
}

function itemMatchesQuery(item: Item, query: string): boolean {
  const tokens = foldSearch(query).split(/\s+/).filter(Boolean)
  if (tokens.length === 0) {
    return true
  }
  const p = item.main_product
  const hay = foldSearch(
    [item.requirement, item.custom_name, p?.sku, p?.name, p?.manufacturer, p ? productDisplayName(p) : '']
      .filter(Boolean)
      .join(' '),
  )
  return tokens.every((t) => hay.includes(t))
}

const actionLabel: Record<string, string> = {
  created: 'Utworzono przetarg',
  updated: 'Zmieniono dane przetargu',
  status_changed: 'Zmiana statusu',
  item_updated: 'Zmiana pozycji',
  item_bulk_updated: 'Zapisano kilka pozycji naraz',
  comment_added: 'Dodano komentarz',
  invitation_added: 'Zaproszono osobę do przetargu',
  invitation_removed: 'Usunięto zaproszenie',
  result_updated: 'Zmieniono wynik przetargu',
}

/** Szczegóły wpisu „Zmieniono wynik przetargu” (meta z TenderResultService). */
function formatResultMeta(meta: Record<string, unknown>): string {
  const parts: string[] = []
  for (const raw of Array.isArray(meta.lots) ? meta.lots : []) {
    if (!raw || typeof raw !== 'object') continue
    const lot = raw as {
      lot_no?: number
      created?: boolean
      deleted?: boolean
      fields?: string[]
      offers_changed?: boolean
      previous_lot_no?: number
    }
    const label = `część ${lot.lot_no ?? '?'}`
    if (lot.deleted) {
      parts.push(`usunięto ${label}`)
      continue
    }
    const changes = (Array.isArray(lot.fields) ? lot.fields : []).map((f) => LOT_FIELD_LABEL[f] ?? f)
    if (lot.offers_changed) changes.push('ceny innych firm')
    if (lot.previous_lot_no != null) changes.push(`numer części (było ${lot.previous_lot_no})`)
    parts.push(`${lot.created ? 'nowa ' : ''}${label}${changes.length > 0 ? `: ${changes.join(', ')}` : ''}`)
  }
  const before = (meta.result_status_before ?? null) as TenderResultStatus | null
  const after = (meta.result_status_after ?? null) as TenderResultStatus | null
  if (before !== after) {
    parts.push(`wynik przetargu: ${resultStatusLabel(before)} → ${resultStatusLabel(after)}`)
  }
  return parts.length > 0 ? parts.join('; ') : '—'
}

function sameVal(a: unknown, b: unknown): boolean {
  if (a === b) return true
  if (a == null && b == null) return true
  if (typeof a === 'number' || typeof b === 'number') {
    return Number(a) === Number(b)
  }
  return String(a ?? '') === String(b ?? '')
}

function formatActivityMeta(meta: Record<string, unknown> | null | undefined): string {
  if (!meta) return '—'
  if (Array.isArray(meta.lots)) return formatResultMeta(meta)
  const before = meta.before as Record<string, unknown> | undefined
  const after = meta.after as Record<string, unknown> | undefined
  if (before && after) {
    const parts: string[] = []
    for (const key of [
      'offer_price',
      'companion_offer_price',
      'quantity',
      'main_product_id',
      'companion_product_id',
      'ai_match_percent',
      'deadline',
      'deadline_time',
      'notice_number',
    ] as const) {
      if (!sameVal(before[key], after[key])) {
        const labels: Record<string, string> = {
          offer_price: 'cena w ofercie',
          companion_offer_price: 'cena drugiego produktu kompletu',
          quantity: 'ilość',
          main_product_id: 'produkt',
          companion_product_id: 'drugi produkt kompletu',
          ai_match_percent: 'ocena dopasowania, %',
          deadline: 'termin składania',
          deadline_time: 'godzina składania',
          notice_number: 'numer ogłoszenia',
        }
        parts.push(`${labels[key] ?? key}: ${String(before[key] ?? '—')} → ${String(after[key] ?? '—')}`)
      }
    }
    return parts.length > 0 ? parts.join('; ') : 'zapis bez zmiany wartości'
  }
  if (typeof meta.from === 'string' && typeof meta.to === 'string') {
    return `${TENDER_STATUS_LABEL[meta.from] ?? meta.from} → ${TENDER_STATUS_LABEL[meta.to] ?? meta.to}${meta.note ? ` (${String(meta.note)})` : ''}`
  }
  if (typeof meta.user_name === 'string') {
    return `${meta.user_name}${meta.user_email ? ` <${String(meta.user_email)}>` : ''}`
  }
  return '—'
}

/** Autor wpisu historii; wynik zapisany przez nocne pobieranie z Biuletynu nie ma osoby. */
function activityAuthor(a: ActivityRow): string {
  if (a.user?.name) return a.user.name
  return a.meta?.source === 'bzp' ? 'Biuletyn Zamówień Publicznych' : '—'
}

function activityHasRealChange(a: ActivityRow): boolean {
  const meta = a.meta
  if (!meta) return false
  const before = meta.before as Record<string, unknown> | undefined
  const after = meta.after as Record<string, unknown> | undefined
  if (!before || !after) {
    return (
      a.action === 'status_changed' ||
      a.action === 'comment_added' ||
      a.action === 'invitation_added' ||
      a.action === 'invitation_removed'
    )
  }
  return (['offer_price', 'quantity', 'main_product_id', 'ai_match_percent'] as const).some(
    (k) => !sameVal(before[k], after[k]),
  )
}

/**
 * Klucz = id: przejście z przetargu A do B bez opuszczania strony (np. z powiadomienia) montuje widok od nowa —
 * bez kroku kreatora, filtrów i podglądu importu z poprzedniego przetargu.
 */
export function TenderDetail() {
  const { id } = useParams()
  return <TenderDetailView key={id} />
}

function TenderDetailView() {
  const { id } = useParams()
  const navigate = useNavigate()
  const { user } = useAuth()
  const [searchParams, setSearchParams] = useSearchParams()
  const urlTab = urlTabOf(searchParams.get('tab'))
  const [data, setData] = useState<Detail | null>(null)
  const [tab, setTabState] = useState<TenderTab>(() => urlTab ?? 'podsumowanie')
  /** sekcja widoczna teraz (dla pytania o niezapisany wynik w obsłudze zdarzeń i adresu) */
  const tabNowRef = useRef(tab)
  useEffect(() => {
    tabNowRef.current = tab
  }, [tab])
  // „Wynik przetargu” ma własny formularz: przejście do innej sekcji go odmontowuje i gubi niezapisane zmiany
  const resultDirtyRef = useRef(false)
  const [resultDirty, setResultDirty] = useState(false)
  const onResultDirtyChange = useCallback((dirty: boolean) => {
    resultDirtyRef.current = dirty
    setResultDirty(dirty)
  }, [])
  /** false = zostajemy w sekcji „Wynik przetargu” (osoba nie chce stracić zmian) */
  const mayLeaveResult = useCallback(
    (next: TenderTab) =>
      next === 'wynik' ||
      tabNowRef.current !== 'wynik' ||
      !resultDirtyRef.current ||
      window.confirm('Wynik przetargu ma niezapisane zmiany. Przejść do innej sekcji bez zapisu? Zmiany przepadną.'),
    [],
  )
  const setTab = useCallback(
    (next: TenderTab) => {
      if (mayLeaveResult(next)) setTabState(next)
    },
    [mayLeaveResult],
  )
  // zamknięcie albo odświeżenie karty przeglądarki z niezapisanym wynikiem — pytanie przeglądarki
  useEffect(() => {
    if (!resultDirty) return
    const warn = (e: BeforeUnloadEvent) => {
      e.preventDefault()
      e.returnValue = ''
    }
    window.addEventListener('beforeunload', warn)
    return () => window.removeEventListener('beforeunload', warn)
  }, [resultDirty])
  // adres „?tab=…” (np. z powiadomienia) pokazuje pełny widok zamiast kreatora — bez wyłączania kreatora na stałe
  const [forcePulpit, setForcePulpit] = useState(() => urlTab !== null)
  const [dragOver, setDragOver] = useState(false)
  const [wizardActive, setWizardActiveState] = useState(() => (id ? isTenderWizardActive(id) : false))
  const [wizardStep, setWizardStep] = useState(0)
  const [products, setProducts] = useState<Product[]>([])
  const [msg, setMsg] = useState('')
  const [err, setErr] = useState('')
  const [busy, setBusy] = useState(false)
  const [docMode, setDocMode] = useState<'simple' | 'ai' | 'full'>('ai')
  const [docTargets, setDocTargets] = useState({ items: true, conditions: true })
  const [docPreview, setDocPreview] = useState<{
    document_id: number | null
    extracted_text: string
    mapping_notes?: string | null
    items: PreviewItem[]
    conditions: PreviewCondition[]
  } | null>(null)
  const [replaceItems, setReplaceItems] = useState(false)
  const [replaceConditions, setReplaceConditions] = useState(false)
  const [newCondition, setNewCondition] = useState('')
  const [newConditionCategory, setNewConditionCategory] = useState('inne')
  const [docStatus, setDocStatus] = useState('')
  const itemDraftsRef = useRef<Map<number, ItemDraft>>(new Map())
  const [coverageFilter, setCoverageFilter] = useState<CoverageFilter>(null)
  const [itemQuery, setItemQuery] = useState('')
  const [matchBusy, setMatchBusy] = useState(false)
  const [matchElapsed, setMatchElapsed] = useState(0)
  const [matchProgress, setMatchProgress] = useState<MatchProgress | null>(null)
  const [matchReport, setMatchReport] = useState<MatchReport | null>(null)
  const [showAiChanges, setShowAiChanges] = useState(false)
  const [focusItemId, setFocusItemId] = useState<number | null>(null)
  const [focusTick, setFocusTick] = useState(0)
  const [reportPreviewId, setReportPreviewId] = useState<number | null>(null)
  const [reportPreviewQuery, setReportPreviewQuery] = useState('')
  const [transitionNote, setTransitionNote] = useState('')
  const [activities, setActivities] = useState<ActivityRow[]>([])
  const [comments, setComments] = useState<CommentRow[]>([])
  const [commentBody, setCommentBody] = useState('')
  const [commentMentions, setCommentMentions] = useState<number[]>([])
  const [commentItemId, setCommentItemId] = useState('')
  const [deadlineEdit, setDeadlineEdit] = useState('')
  const [deadlineTimeEdit, setDeadlineTimeEdit] = useState('')
  const [noticeEdit, setNoticeEdit] = useState('')
  const [marginEdit, setMarginEdit] = useState('18')
  const [cheaperPreview, setCheaperPreview] = useState<{
    candidates: Array<{
      item_id: number
      line_no: number
      from_sku: string | null
      to_sku: string
      save_percent: number
      purchase_price: number
    }>
  } | null>(null)
  const cheaperPreviewRef = useRef<HTMLDivElement | null>(null)
  const [invitations, setInvitations] = useState<InvitationRow[]>([])
  const [directory, setDirectory] = useState<DirectoryUser[]>([])
  const [inviteUserId, setInviteUserId] = useState('')
  const [inviteNote, setInviteNote] = useState('')
  const [inviteQ, setInviteQ] = useState('')

  // can_edit z API zależy wyłącznie od statusu (Szkic, Wycena) — edycję oferty daje dopiero uprawnienie roli.
  const canEditOffer = Boolean(data?.can_edit) && can(user, 'tenders.edit_offer')
  // Kreator: włączony przy zakładaniu (lib/tenderWizard). Szkic i Wycena, bo import SIWZ sam zmienia Szkic na Wycenę.
  const wizardMode = wizardActive && canEditOffer && !forcePulpit
  const wizardEnteredRef = useRef(false)

  function setWizardActive(active: boolean) {
    setWizardActiveState(active)
    if (id) setTenderWizardActive(id, active)
  }

  // Nowy adres „?tab=…” na tej samej stronie (np. kliknięte powiadomienie) przełącza sekcję.
  useEffect(() => {
    if (!urlTab) return
    if (!mayLeaveResult(urlTab)) {
      // zostajemy przy wyniku — adres wraca do „?tab=wynik”, żeby zgadzał się z tym, co widać
      setSearchParams(
        (prev) => {
          const next = new URLSearchParams(prev)
          next.set('tab', 'wynik')
          return next
        },
        { replace: true },
      )
      return
    }
    setTabState(urlTab)
    setForcePulpit(true)
  }, [urlTab, mayLeaveResult, setSearchParams])

  // Sekcje z URL_TABS widać w adresie (da się go skopiować); po przejściu do innej sekcji „?tab=” znika.
  // Tylko po zmianie sekcji (nie po zmianie adresu) — inaczej nowy adres z powiadomienia i stara sekcja
  // nadpisywałyby się nawzajem.
  const tabRef = useRef(tab)
  useEffect(() => {
    if (tabRef.current === tab) return
    tabRef.current = tab
    const wanted = (URL_TABS as string[]).includes(tab) ? tab : null
    setSearchParams(
      (prev) => {
        if (prev.get('tab') === wanted) return prev
        const next = new URLSearchParams(prev)
        if (wanted) next.set('tab', wanted)
        else next.delete('tab')
        return next
      },
      { replace: true },
    )
  }, [tab, setSearchParams])

  // Krok startowy liczony przy każdym wejściu w kreator, nie po każdym load() (zapis terminu, import, dopasowanie AI).
  useEffect(() => {
    // przetarg poszedł dalej (akceptacja, archiwum…) — po cofnięciu do Wyceny kreator nie wraca sam
    if (data && wizardActive && !['draft', 'wycena'].includes(data.tender.status) && id) {
      setWizardActiveState(false)
      setTenderWizardActive(id, false)
    }
    if (!wizardMode || !data) {
      wizardEnteredRef.current = false
      return
    }
    if (wizardEnteredRef.current) return
    wizardEnteredRef.current = true
    const items = data.tender.items
    const withoutProduct = data.coverage?.without_product ?? items.filter((i) => !i.main_product).length
    setWizardStep(items.length === 0 ? 0 : withoutProduct > 0 ? 1 : 3)
  }, [wizardMode, wizardActive, data, id])

  const load = useCallback(async () => {
    const d = await api<Detail>(`/tenders/${id}`)
    setData(d)
    setDeadlineEdit(d.tender.deadline ? d.tender.deadline.slice(0, 10) : '')
    setDeadlineTimeEdit(deadlineTimeLabel(d.tender.deadline_time) ?? '')
    setNoticeEdit(d.tender.notice_number ?? '')
    setMarginEdit(
      d.tender.target_margin_percent != null && d.tender.target_margin_percent !== ''
        ? String(d.tender.target_margin_percent)
        : '18',
    )
    return d
  }, [id])

  /**
   * Po zapisie wyniku: tylko znacznik wyniku w nagłówku. Pełne load() nadpisałoby niezapisane pola Podsumowania
   * (termin, godzina, numer ogłoszenia, narzut) wartościami z serwera.
   */
  const refreshResultStatus = useCallback(async () => {
    try {
      const d = await api<Detail>(`/tenders/${id}`)
      setData((prev) => (prev ? { ...prev, tender: { ...prev.tender, result_status: d.tender.result_status } } : d))
    } catch {
      // znacznik odświeży się przy następnym wczytaniu przetargu
    }
  }, [id])

  const loadMeta = useCallback(async () => {
    if (!id) return
    try {
      const [act, com, inv] = await Promise.all([
        api<{ data: ActivityRow[] }>(`/tenders/${id}/activities?per_page=50`),
        api<{ data: CommentRow[] }>(`/tenders/${id}/comments`),
        api<{ data: InvitationRow[] }>(`/tenders/${id}/invitations`),
      ])
      setActivities(Array.isArray(act.data) ? act.data : [])
      setComments(Array.isArray(com.data) ? com.data : [])
      setInvitations(Array.isArray(inv.data) ? inv.data : [])
    } catch (e) {
      setErr(e instanceof Error ? e.message : 'Nie udało się wczytać historii, komentarzy i zaproszeń.')
    }
  }, [id])

  const loadDirectory = useCallback(async (q = '') => {
    if (!user?.permissions?.includes('tenders.invite')) return
    try {
      const qs = q ? `?q=${encodeURIComponent(q)}` : ''
      const res = await api<{ data: DirectoryUser[] }>(`/users/directory${qs}`)
      setDirectory(Array.isArray(res.data) ? res.data : [])
    } catch {
      setDirectory([])
    }
  }, [user])

  useEffect(() => {
    void load()
    void loadMeta()
    void api<{ data: Product[] }>('/products?per_page=100').then((p) => setProducts(p.data ?? []))
  }, [load, loadMeta])

  // Sprzeczności kart: ponownie po zmianie zapisanych produktów lub wymagań (też po „Dopasuj AI”).
  const [conflicts, setConflicts] = useState<TenderConflicts['items']>({})
  const conflictsKey =
    data?.tender.items
      .map((i) => `${i.id}:${i.main_product_id ?? i.main_product?.id ?? ''}:${i.requirement?.length ?? 0}`)
      .join('|') ?? ''

  useEffect(() => {
    if (!id || !conflictsKey) return
    let cancelled = false
    void fetchTenderConflicts(id).then((items) => {
      if (!cancelled) setConflicts(items)
    })
    return () => {
      cancelled = true
    }
  }, [id, conflictsKey])

  useEffect(() => {
    if (!id) {
      return
    }
    setMatchReport(loadMatchReport(id))
    setShowAiChanges(false)
  }, [id])

  useEffect(() => {
    if (!matchBusy) {
      setMatchElapsed(0)
      return
    }
    const started = Date.now()
    const timer = window.setInterval(() => {
      setMatchElapsed(Math.floor((Date.now() - started) / 1000))
    }, 1000)
    return () => window.clearInterval(timer)
  }, [matchBusy])

  useEffect(() => {
    if (tab === 'zaproszenia' || (wizardMode && wizardStep === 3)) void loadDirectory(inviteQ)
  }, [tab, wizardMode, wizardStep, inviteQ, loadDirectory])

  const registerItemDraft = useCallback((itemId: number, draft: ItemDraft) => {
    itemDraftsRef.current.set(itemId, draft)
  }, [])

  async function deleteItem(item: Item) {
    if (!window.confirm(`Usunąć pozycję ${item.line_no}?`)) return
    setErr('')
    setMsg('')
    setBusy(true)
    try {
      await api(`/tenders/${id}/items/${item.id}`, { method: 'DELETE' })
      await load()
      await loadMeta()
      setMsg(`Usunięto pozycję ${item.line_no}.`)
    } catch (e) {
      setErr(e instanceof Error ? e.message : 'Błąd usuwania pozycji')
    } finally {
      setBusy(false)
    }
  }

  async function deleteTender() {
    if (!data) return
    if (!window.confirm(`Usunąć przetarg ${data.tender.number}? Tej operacji nie można cofnąć.`)) return
    setErr('')
    setBusy(true)
    try {
      await api(`/tenders/${id}`, { method: 'DELETE' })
      navigate('/tenders')
    } catch (e) {
      setErr(e instanceof Error ? e.message : 'Błąd usuwania przetargu')
      setBusy(false)
    }
  }

  async function saveItem(itemId: number, patch: Record<string, unknown>) {
    setErr('')
    setMsg('')
    setBusy(true)
    try {
      await api(`/tenders/${id}/items/${itemId}`, {
        method: 'PATCH',
        body: JSON.stringify(patch),
      })
      await load()
      await loadMeta()
      setMsg('Zapisano pozycję — zmiana jest w sekcji „Historia i statusy”.')
    } catch (e) {
      setErr(e instanceof Error ? e.message : 'Błąd zapisu')
    } finally {
      setBusy(false)
    }
  }

  async function saveAllItems() {
    setErr('')
    setMsg('')
    setBusy(true)
    try {
      const items = [...itemDraftsRef.current.entries()].map(([itemId, draft]) => ({
        id: itemId,
        ...draft,
      }))
      if (items.length === 0) {
        setMsg('Brak pozycji do zapisania.')
        return
      }
      const res = await api<{ updated: number }>(`/tenders/${id}/items/bulk`, {
        method: 'POST',
        body: JSON.stringify({ items }),
      })
      await load()
      await loadMeta()
      setMsg(`Zapisano wszystkie zmiany: ${res.updated} pozycji — szczegóły w sekcji „Historia i statusy”.`)
    } catch (e) {
      setErr(e instanceof Error ? e.message : 'Błąd zapisu całości')
    } finally {
      setBusy(false)
    }
  }

  async function previewCheaperSubstitutes() {
    setErr('')
    setMsg('')
    setBusy(true)
    try {
      const res = await api<{
        candidates: Array<{
          item_id: number
          line_no: number
          from_sku: string | null
          to_sku: string
          save_percent: number
          purchase_price: number
        }>
        candidates_count: number
      }>(`/tenders/${id}/items/apply-cheaper-substitutes`, {
        method: 'POST',
        body: JSON.stringify({ dry_run: true, min_save_percent: 3 }),
      })
      if ((res.candidates_count ?? 0) === 0) {
        setMsg('Nie ma tańszych zamienników — żaden nie jest co najmniej 3% taniej (po upuście).')
        setCheaperPreview(null)
        return
      }
      setCheaperPreview({ candidates: res.candidates ?? [] })
      setTab('pozycje')
      requestAnimationFrame(() => {
        cheaperPreviewRef.current?.scrollIntoView({ behavior: 'smooth', block: 'nearest' })
      })
    } catch (e) {
      setErr(e instanceof Error ? e.message : 'Błąd podglądu zamienników')
    } finally {
      setBusy(false)
    }
  }

  async function applyCheaperSubstitutes() {
    setErr('')
    setMsg('')
    setBusy(true)
    try {
      const res = await api<{ applied_count: number }>(
        `/tenders/${id}/items/apply-cheaper-substitutes`,
        {
          method: 'POST',
          body: JSON.stringify({ dry_run: false, min_save_percent: 3 }),
        },
      )
      setCheaperPreview(null)
      await load()
      await loadMeta()
      setMsg(`Zastosowano tańsze zamienniki na ${res.applied_count} pozycjach.`)
    } catch (e) {
      setErr(e instanceof Error ? e.message : 'Błąd zastosowania zamienników')
    } finally {
      setBusy(false)
    }
  }

  async function analyzeDocument(file: File) {
    setErr('')
    setMsg('')
    setBusy(true)
    const modeLabel =
      docMode === 'simple'
        ? 'odczyt samego tekstu'
        : docMode === 'ai'
          ? 'odczyt z podglądem'
          : 'odczyt z podglądem i zapis pliku'
    setDocStatus(`Wybrano: ${file.name} — trwa ${modeLabel}…`)
    try {
      const targets: string[] = []
      if (docTargets.items) targets.push('items')
      if (docTargets.conditions) targets.push('conditions')
      if (targets.length === 0) throw new Error('Zaznacz, co odczytać z pliku: pozycje, warunki albo jedno i drugie.')
      const fd = new FormData()
      fd.append('file', file)
      fd.append('mode', docMode)
      targets.forEach((t) => fd.append('targets[]', t))
      const res = await api<{
        document_id: number | null
        extracted_text: string
        mapping_notes?: string | null
        items: PreviewItem[]
        conditions: PreviewCondition[]
        items_count: number
        conditions_count: number
      }>(`/tenders/${id}/documents/analyze`, { method: 'POST', body: fd })
      setDocPreview({
        document_id: res.document_id,
        extracted_text: res.extracted_text ?? '',
        mapping_notes: res.mapping_notes,
        items: (res.items ?? []).map((i) => ({ ...i, selected: i.selected !== false })),
        conditions: (res.conditions ?? []).map((c) => ({ ...c, selected: c.selected !== false })),
      })
      setDocStatus(
        `Gotowe: ${file.name} — ${res.items_count} pozycji, ${res.conditions_count} warunków. Sprawdź numer, nazwę i cenę, potem kliknij „Dodaj do przetargu”.`,
      )
      setMsg('Podgląd gotowy — nic nie zostało jeszcze dodane do przetargu. Sprawdź listę poniżej.')
      // nie przeładowuj listy w trakcie podglądu — chyba że plik trafił do archiwum: tryb pełny albo Word
      // (backend zapisuje Word jako formularz ofertowy także w trybie AI)
      if (docMode === 'full' || /\.docx?$/i.test(file.name)) await load()
      requestAnimationFrame(() => {
        document.getElementById('doc-preview')?.scrollIntoView({ behavior: 'smooth', block: 'start' })
      })
    } catch (e) {
      setDocStatus(`Błąd przy pliku ${file.name}.`)
      setErr(e instanceof Error ? e.message : 'Nie udało się odczytać pliku')
    } finally {
      setBusy(false)
    }
  }

  async function openDocumentPreview(docId: number) {
    setErr('')
    setBusy(true)
    setDocStatus('Wczytuję podgląd zapisanego pliku…')
    try {
      const res = await api<{
        id: number
        extracted_text: string | null
        analysis_json: {
          items?: PreviewItem[]
          conditions?: PreviewCondition[]
        } | null
      }>(`/tenders/${id}/documents/${docId}`)
      const items = (res.analysis_json?.items ?? []).map((i) => ({
        ...i,
        selected: i.selected !== false,
      }))
      const conditions = (res.analysis_json?.conditions ?? []).map((c) => ({
        ...c,
        selected: c.selected !== false,
      }))
      if (items.length === 0 && conditions.length === 0) {
        setDocStatus('Ten plik nie ma zapisanego odczytu — kliknij „Odczytaj ponownie”.')
        return
      }
      setDocPreview({
        document_id: res.id,
        extracted_text: res.extracted_text ?? '',
        items,
        conditions,
      })
      setDocStatus(
        `Podgląd zapisanego pliku: ${items.length} pozycji, ${conditions.length} warunków — sprawdź i kliknij „Dodaj do przetargu”.`,
      )
    } catch (e) {
      setErr(e instanceof Error ? e.message : 'Błąd podglądu')
      setDocStatus('Błąd podglądu.')
    } finally {
      setBusy(false)
    }
  }

  async function commitDocument() {
    if (!docPreview) return
    setErr('')
    setMsg('')
    setBusy(true)
    try {
      if (docMode === 'simple') {
        const res = await api<{ items_created: number; conditions_created: number }>(
          `/tenders/${id}/documents/commit`,
          {
            method: 'POST',
            body: JSON.stringify({
              document_id: docPreview.document_id,
              simple_text: docPreview.extracted_text,
              simple_as:
                docTargets.items && docTargets.conditions
                  ? 'both'
                  : docTargets.items
                    ? 'items'
                    : 'conditions',
              replace_items: replaceItems,
              replace_conditions: replaceConditions,
            }),
          },
        )
        await load()
        setDocPreview(null)
        setDocStatus(
          `Dodano do przetargu: ${res.items_created} pozycji, ${res.conditions_created} warunków.`,
        )
        setMsg(`Dodano do przetargu: ${res.items_created} pozycji, ${res.conditions_created} warunków.`)
        return
      }
      const items = docPreview.items
        .filter((i) => i.selected)
        .map(({ sku, name, requirement, quantity, offer_price, currency, norms, description }) => ({
          sku: sku ?? null,
          name: name ?? requirement,
          requirement,
          quantity,
          offer_price: offer_price ?? null,
          currency: currency ?? null,
          norms: norms ?? null,
          description: description ?? null,
        }))
      const conditions = docPreview.conditions
        .filter((c) => c.selected)
        .map(({ category, content }) => ({ category, content }))
      const res = await api<{ items_created: number; conditions_created: number }>(
        `/tenders/${id}/documents/commit`,
        {
          method: 'POST',
          body: JSON.stringify({
            document_id: docPreview.document_id,
            items,
            conditions,
            replace_items: replaceItems,
            replace_conditions: replaceConditions,
          }),
        },
      )
      await load()
      setDocPreview(null)
      setDocStatus(
        `Dodano do przetargu: ${res.items_created} pozycji, ${res.conditions_created} warunków.`,
      )
      setMsg(`Dodano do przetargu: ${res.items_created} pozycji, ${res.conditions_created} warunków.`)
      if (items.length) setTab('pozycje')
      else setTab('warunki')
    } catch (e) {
      setErr(e instanceof Error ? e.message : 'Błąd zapisu')
    } finally {
      setBusy(false)
    }
  }

  async function reanalyzeDoc(docId: number) {
    setErr('')
    setBusy(true)
    try {
      const targets: string[] = []
      if (docTargets.items) targets.push('items')
      if (docTargets.conditions) targets.push('conditions')
      const res = await api<{
        document_id: number
        extracted_text: string
        items: PreviewItem[]
        conditions: PreviewCondition[]
      }>(`/tenders/${id}/documents/${docId}/reanalyze`, {
        method: 'POST',
        body: JSON.stringify({ mode: docMode, targets }),
      })
      setDocPreview({
        document_id: res.document_id,
        extracted_text: res.extracted_text,
        items: (res.items ?? []).map((i) => ({ ...i, selected: true })),
        conditions: (res.conditions ?? []).map((c) => ({ ...c, selected: true })),
      })
      setTab('dokumenty')
      setMsg('Plik odczytany ponownie — sprawdź zaznaczone i dodaj do przetargu.')
    } catch (e) {
      setErr(e instanceof Error ? e.message : 'Nie udało się ponownie odczytać pliku')
    } finally {
      setBusy(false)
    }
  }

  async function deleteDoc(docId: number) {
    setBusy(true)
    try {
      await api(`/tenders/${id}/documents/${docId}`, { method: 'DELETE' })
      await load()
      setMsg('Usunięto dokument.')
    } catch (e) {
      setErr(e instanceof Error ? e.message : 'Błąd usuwania')
    } finally {
      setBusy(false)
    }
  }

  async function downloadDoc(doc: DocMeta) {
    if (!doc.has_file) return
    setErr('')
    setBusy(true)
    try {
      await downloadFile(`/tenders/${id}/documents/${doc.id}/download`, doc.original_name)
      setMsg(`Pobrano ${doc.original_name}.`)
    } catch (e) {
      setErr(e instanceof Error ? e.message : 'Błąd pobierania pliku')
    } finally {
      setBusy(false)
    }
  }

  async function addCondition() {
    const content = newCondition.trim()
    if (!content) return
    setBusy(true)
    try {
      await api(`/tenders/${id}/conditions`, {
        method: 'POST',
        body: JSON.stringify({ content, category: newConditionCategory }),
      })
      setNewCondition('')
      await load()
      setMsg('Dodano warunek.')
    } catch (e) {
      setErr(e instanceof Error ? e.message : 'Błąd zapisu warunku')
    } finally {
      setBusy(false)
    }
  }

  async function setConditionStatus(condId: number, status: ConditionStatus | null) {
    setBusy(true)
    setErr('')
    try {
      await api(`/tenders/${id}/conditions/${condId}`, {
        method: 'PATCH',
        body: JSON.stringify({ status }),
      })
      await load()
    } catch (e) {
      setErr(e instanceof Error ? e.message : 'Nie udało się zapisać stanu warunku')
    } finally {
      setBusy(false)
    }
  }

  async function deleteCondition(condId: number) {
    setBusy(true)
    try {
      await api(`/tenders/${id}/conditions/${condId}`, { method: 'DELETE' })
      await load()
    } catch (e) {
      setErr(e instanceof Error ? e.message : 'Błąd usuwania')
    } finally {
      setBusy(false)
    }
  }

  async function transition(status: string): Promise<boolean> {
    setErr('')
    setMsg('')
    const needsNote = status === 'odrzucony' || status === 'wycena'
    if (needsNote && data?.tender.status.startsWith('akceptacja') && transitionNote.trim().length < 5) {
      setErr('Przy odrzuceniu albo cofnięciu z akceptacji wpisz notatkę — co najmniej 5 znaków.')
      return false
    }
    if (status === 'odrzucony' && transitionNote.trim().length < 5) {
      setErr('Przy odrzuceniu wpisz notatkę — co najmniej 5 znaków.')
      return false
    }
    setBusy(true)
    try {
      await api(`/tenders/${id}/transition`, {
        method: 'POST',
        body: JSON.stringify({ status, note: transitionNote.trim() || null }),
      })
      setTransitionNote('')
      await load()
      await loadMeta()
      setMsg(`Status: ${TENDER_STATUS_LABEL[status] ?? status}`)
      return true
    } catch (e) {
      setErr(e instanceof Error ? e.message : 'Nie udało się zmienić statusu')
      return false
    } finally {
      setBusy(false)
    }
  }

  async function saveDeadline() {
    setBusy(true)
    setErr('')
    try {
      await api(`/tenders/${id}`, {
        method: 'PATCH',
        // bez daty nie ma godziny (serwer i tak ją czyści)
        body: JSON.stringify({
          deadline: deadlineEdit || null,
          deadline_time: deadlineEdit ? deadlineTimeEdit || null : null,
        }),
      })
      await load()
      await loadMeta()
      setMsg('Zapisano termin.')
    } catch (e) {
      setErr(e instanceof Error ? e.message : 'Błąd zapisu terminu')
    } finally {
      setBusy(false)
    }
  }

  async function saveNotice() {
    setBusy(true)
    setErr('')
    try {
      await api(`/tenders/${id}`, {
        method: 'PATCH',
        body: JSON.stringify({ notice_number: noticeEdit.trim() || null }),
      })
      await load()
      await loadMeta()
      setMsg('Zapisano numer ogłoszenia.')
    } catch (e) {
      setErr(e instanceof Error ? e.message : 'Nie udało się zapisać numeru ogłoszenia')
    } finally {
      setBusy(false)
    }
  }

  async function saveTargetMargin() {
    const next = Number(String(marginEdit).replace(',', '.'))
    if (!Number.isFinite(next) || next < 0 || next > 500) {
      setErr('Narzut musi być liczbą od 0 do 500.')
      return
    }
    setBusy(true)
    setErr('')
    try {
      await api(`/tenders/${id}`, {
        method: 'PATCH',
        body: JSON.stringify({ target_margin_percent: next }),
      })
      await load()
      await loadMeta()
      setMsg('Zapisano narzut — ceny w ofercie przeliczone.')
    } catch (e) {
      setErr(e instanceof Error ? e.message : 'Nie udało się zapisać narzutu')
    } finally {
      setBusy(false)
    }
  }

  async function addComment() {
    if (commentBody.trim().length < 2) return
    setBusy(true)
    setErr('')
    try {
      await api(`/tenders/${id}/comments`, {
        method: 'POST',
        body: JSON.stringify({
          body: commentBody.trim(),
          tender_item_id: commentItemId ? Number(commentItemId) : null,
          mentioned_user_ids: commentMentions,
        }),
      })
      setCommentBody('')
      setCommentMentions([])
      setCommentItemId('')
      await loadMeta()
      setMsg('Dodano komentarz.')
    } catch (e) {
      setErr(e instanceof Error ? e.message : 'Błąd komentarza')
    } finally {
      setBusy(false)
    }
  }

  async function sendInvite() {
    if (!inviteUserId) return
    setBusy(true)
    setErr('')
    setMsg('')
    try {
      const res = await api<TenderInvitationCreateResponse>(`/tenders/${id}/invitations`, {
        method: 'POST',
        body: JSON.stringify({
          user_id: Number(inviteUserId),
          note: inviteNote.trim() || null,
        }),
      })
      setInviteUserId('')
      setInviteNote('')
      await loadMeta()
      const emailStatus = res.email_status ?? (res.email_sent ? 'sent' : 'failed')
      setMsg(
        emailStatus === 'sent'
          ? 'Zaproszono osobę i wysłano jej e-mail.'
          : emailStatus === 'opted_out'
            ? 'Zaproszono osobę. E-maila nie wysłano, bo osoba wyłączyła powiadomienia e-mail o zaproszeniach — dostała powiadomienie w aplikacji.'
            : 'Zaproszono osobę, ale e-mail nie został wysłany — poproś administratora o sprawdzenie ustawień poczty.',
      )
    } catch (e) {
      setErr(e instanceof Error ? e.message : 'Błąd zaproszenia')
    } finally {
      setBusy(false)
    }
  }

  async function removeInvite(invitationId: number) {
    if (!window.confirm('Usunąć dostęp tej osoby do przetargu?')) return
    setBusy(true)
    setErr('')
    try {
      await api(`/tenders/${id}/invitations/${invitationId}`, { method: 'DELETE' })
      await loadMeta()
      setMsg('Usunięto zaproszenie.')
    } catch (e) {
      setErr(e instanceof Error ? e.message : 'Błąd usuwania zaproszenia')
    } finally {
      setBusy(false)
    }
  }

  async function approveSub(subId: number, approval_status: string) {
    setErr('')
    setBusy(true)
    try {
      await api(`/substitutes/${subId}/approve`, {
        method: 'PATCH',
        body: JSON.stringify({ approval_status }),
      })
      await load()
      setMsg('Zaktualizowano zamiennik.')
    } catch (e) {
      setErr(e instanceof Error ? e.message : 'Nie udało się zapisać decyzji o zamienniku')
    } finally {
      setBusy(false)
    }
  }

  async function runMatch(onlyEmpty: boolean, itemIds?: number[]) {
    setErr('')
    setMsg('')
    setBusy(true)
    setMatchBusy(true)
    setShowAiChanges(false)
    const scopedItems = itemIds
      ? (data?.tender.items ?? []).filter((i) => itemIds.includes(i.id))
      : (data?.tender.items ?? [])
    const targets = matchTargetIds(
      data?.tender.items ?? [],
      onlyEmpty,
      itemIds,
      data?.coverage?.thresholds.min_match_score ?? 65,
    )
    const estimated = targets.length
    const leftAsIs = Math.max(0, scopedItems.length - targets.length)
    let done = 0
    const inFlight = new Set<number>()
    const showProgress = () => setMatchProgress({ done, total: estimated, inFlight: [...inFlight] })
    showProgress()
    type MatchApiRes = {
      matched: number
      skipped: number
      avg_score: number
      processed?: number
      changed?: number
      unchanged?: number
      cleared?: number
      skipped_custom?: number
      no_match?: number
      model_unavailable?: number
      model_failed?: number
      changes?: MatchChange[]
    }
    const merged: MatchApiRes = {
      matched: 0,
      skipped: 0,
      avg_score: 0,
      processed: 0,
      changed: 0,
      unchanged: 0,
      cleared: 0,
      skipped_custom: 0,
      no_match: 0,
      model_unavailable: 0,
      model_failed: 0,
      changes: [],
    }
    const scoreParts: number[] = []
    const concurrency = matchParallelItems(data?.coverage?.thresholds.match_concurrency)
    const runId = newMatchRunId()
    const errors: string[] = []
    let finishedInBackground = 0
    try {
      // Przesuwane okno: każda pozycja to osobne żądanie, w toku najwyżej „Ile zapytań AI naraz”. Gdy jedna
      // pozycja się skończy, od razu rusza następna — paczki czekały na swoją najwolniejszą pozycję, a model
      // stał w tym czasie bez pracy (25.09.2026).
      await mapPool(targets, concurrency, async (itemId) => {
        inFlight.add(itemId)
        showProgress()
        const ac = new AbortController()
        const abortTimer = window.setTimeout(() => ac.abort(), MATCH_ITEM_ABORT_MS)
        try {
          const res = await api<MatchApiRes>(`/tenders/${id}/match`, {
            method: 'POST',
            body: JSON.stringify({ only_empty: onlyEmpty, item_ids: [itemId], run_id: runId }),
            signal: ac.signal,
          })
          merged.matched += res.matched
          merged.skipped += res.skipped
          merged.processed = (merged.processed ?? 0) + (res.processed ?? res.matched + res.skipped)
          merged.changed = (merged.changed ?? 0) + (res.changed ?? 0)
          merged.unchanged = (merged.unchanged ?? 0) + (res.unchanged ?? 0)
          merged.cleared = (merged.cleared ?? 0) + (res.cleared ?? 0)
          merged.skipped_custom = (merged.skipped_custom ?? 0) + (res.skipped_custom ?? 0)
          merged.no_match = (merged.no_match ?? 0) + (res.no_match ?? 0)
          merged.model_unavailable = (merged.model_unavailable ?? 0) + (res.model_unavailable ?? 0)
          merged.model_failed = (merged.model_failed ?? 0) + (res.model_failed ?? 0)
          merged.changes = [...(merged.changes ?? []), ...(res.changes ?? [])]
          if (res.matched > 0) {
            scoreParts.push(res.avg_score)
          }
        } catch (e) {
          const aborted =
            (e instanceof DOMException && e.name === 'AbortError') ||
            (e instanceof Error && /abort/i.test(e.message))
          if (aborted) {
            // serwer liczy pozycję dalej (ignore_user_abort) i zapisze wynik — nie ma go tylko w raporcie
            finishedInBackground += 1
            errors.push(
              `Pozycja jest sprawdzana dłużej niż ${Math.round(MATCH_ITEM_ABORT_MS / 60_000)} minut — serwer kończy ją w tle; odśwież stronę za kilka minut.`,
            )
          } else {
            errors.push(e instanceof Error ? e.message : 'Nie udało się dopasować produktów')
          }
        } finally {
          window.clearTimeout(abortTimer)
          inFlight.delete(itemId)
          done += 1
          showProgress()
        }
      })
      if (targets.length > 0) {
        try {
          // każde żądanie liczyło sumy przetargu po swojej pozycji, równolegle z innymi — końcowe przeliczenie z bazy
          await api(`/tenders/${id}/match/finish`, { method: 'POST' })
        } catch (e) {
          errors.push(e instanceof Error ? e.message : 'Nie udało się przeliczyć sum przetargu')
        }
      }
      merged.changes = [...(merged.changes ?? [])].sort((a, b) => a.line_no - b.line_no)
      merged.avg_score =
        scoreParts.length === 0
          ? 0
          : Math.round((scoreParts.reduce((a, b) => a + b, 0) / scoreParts.length) * 10) / 10
      await load()
      await loadMeta()
      const report: MatchReport = {
        processed: merged.processed ?? merged.matched + merged.skipped,
        changed: merged.changed ?? 0,
        unchanged: merged.unchanged ?? 0,
        cleared: merged.cleared ?? 0,
        skipped_custom: merged.skipped_custom ?? 0,
        no_match: merged.no_match ?? 0,
        model_unavailable: merged.model_unavailable ?? 0,
        model_failed: merged.model_failed ?? 0,
        left_as_is: onlyEmpty ? leftAsIs : 0,
        finished_in_background: finishedInBackground,
        avg_score: merged.avg_score,
        changes: merged.changes ?? [],
        at: new Date().toISOString(),
      }
      setMatchReport(report)
      if (id) {
        sessionStorage.setItem(matchReportStorageKey(id), JSON.stringify(report))
      }
      setCoverageFilter(null)
      setShowAiChanges(report.changed > 0)
      setMsg(
        report.changed > 0
          ? `Dopasowanie zmieniło ${report.changed} z ${report.processed} pozycji i od razu je zapisało (nie trzeba klikać „Zapisz”). Poniżej widać tylko zmienione pozycje.`
          : `Dopasowanie zakończone, oferta bez zmian (sprawdzono ${report.processed} pozycji: ${report.unchanged} bez zmiany, ${report.skipped_custom} wpisanych ręcznie, ${report.no_match} bez pasującego produktu).`,
      )
      setTab('pozycje')
      // Awaria dostawcy modelu wygląda na liście jak spadek jakości: karty zostają, ale procenty lecą
      // do 70%. Bez tego komunikatu trzeba było zaglądać do logów, żeby to odróżnić (15.09, HTTP 429).
      const modelFailed = report.model_failed ?? 0
      const modelWarning =
        modelFailed > 0
          ? `Nie udało się ocenić ${modelFailed} z ${report.processed} pozycji — uruchom dopasowanie ponownie. ` +
            'Te pozycje zostały z poprzednim produktem (ocena najwyżej 70%) albo czekają bez produktu. ' +
            'Jeśli to się powtarza, administrator może zmniejszyć liczbę zapytań naraz w Ustawieniach AI.'
          : ''
      const batchWarning = errors.length > 0 ? `Nie udało się dopasować części pozycji (${errors.length}): ${errors[0]}` : ''
      if (modelWarning !== '' || batchWarning !== '') {
        setErr([modelWarning, batchWarning].filter((part) => part !== '').join(' '))
      }
    } catch (e) {
      setErr(e instanceof Error ? e.message : 'Nie udało się dopasować produktów')
    } finally {
      setBusy(false)
      setMatchBusy(false)
    }
  }

  const itemsVisible = wizardMode ? wizardStep === 1 : tab === 'pozycje'
  useEffect(() => {
    if (!itemsVisible || focusItemId == null) {
      return
    }
    const timer = window.setTimeout(() => {
      document.getElementById(`tender-item-${focusItemId}`)?.scrollIntoView({
        behavior: 'smooth',
        block: 'center',
      })
    }, 80)
    return () => window.clearTimeout(timer)
  }, [itemsVisible, focusItemId, focusTick, showAiChanges, coverageFilter, itemQuery])

  async function exportOffer(kind: 'excel' | 'pdf' | 'docx') {
    setErr('')
    setBusy(true)
    try {
      const ext = kind === 'excel' ? 'xlsx' : kind
      await downloadFile(`/tenders/${id}/export/${kind}`, `oferta.${ext}`)
      setMsg(
        kind === 'excel'
          ? 'Pobrano ofertę w pliku Excel.'
          : kind === 'pdf'
            ? 'Pobrano ofertę w pliku PDF.'
            : 'Pobrano formularz ofertowy z cenami (plik Word).',
      )
    } catch (e) {
      setErr(e instanceof Error ? e.message : 'Nie udało się pobrać pliku oferty')
    } finally {
      setBusy(false)
    }
  }

  if (!data) return <p className="text-sm text-slate-500">Ładowanie…</p>

  const { tender, substitutes_by_main, can_edit, next_statuses, coverage } = data
  const canApproveSub = Boolean(user?.permissions?.includes('substitutes.approve'))
  const canComment = Boolean(user?.permissions?.includes('tenders.comment'))
  const canInvite = Boolean(user?.permissions?.includes('tenders.invite'))
  const canDeleteItems = can(user, 'tenders.delete_items')
  const canDeleteTender = can(user, 'tenders.delete')
  // termin, godzina i numer ogłoszenia: jak trasa PATCH /tenders/{id} (permission:tenders.create|tenders.edit_offer)
  const canEditTenderFields = can(user, 'tenders.create') || can(user, 'tenders.edit_offer')
  const aiChangedIds = new Set((matchReport?.changes ?? []).map((c) => c.id))
  const filteredItems = tender.items.filter((it) => {
    if (coverageFilter && coverage && !coverage.item_ids[coverageFilter].includes(it.id)) {
      return false
    }
    if (showAiChanges && !aiChangedIds.has(it.id)) {
      return false
    }
    return itemMatchesQuery(it, itemQuery)
  })
  const listNarrowed = itemQuery.trim() !== '' || coverageFilter != null
  const listFiltered = listNarrowed || showAiChanges

  function openMatchChange(change: MatchChange) {
    const item = tender.items.find((i) => i.id === change.id)
    setTab('pozycje')
    if (wizardMode) setWizardStep(1)
    setCoverageFilter(null)
    setItemQuery('')
    setShowAiChanges(true)
    setFocusItemId(change.id)
    setFocusTick((n) => n + 1)
    const productId =
      item?.main_product?.id ??
      item?.main_product_id ??
      products.find((p) => p.sku === change.to_sku)?.id ??
      null
    const canPreview = Boolean(productId) && change.action !== 'cleared' && change.action !== 'no_match'
    setReportPreviewId(canPreview ? productId : null)
    setReportPreviewQuery(item?.requirement ?? '')
    if (!productId && change.to_sku && change.action !== 'cleared' && change.action !== 'no_match') {
      void api<{ data: Product[] }>(`/products?q=${encodeURIComponent(change.to_sku)}&per_page=8`).then((res) => {
        const hit = (res.data ?? []).find((p) => p.sku === change.to_sku) ?? res.data?.[0]
        if (hit) {
          setReportPreviewId(hit.id)
        }
      })
    }
  }

  function matchChangeProductName(change: MatchChange): string | null {
    const item = tender.items.find((i) => i.id === change.id)
    if (item?.main_product && (!change.to_sku || item.main_product.sku === change.to_sku)) {
      return productDisplayName(item.main_product, 64)
    }
    const fromList = products.find((p) => p.sku === change.to_sku)
    return fromList ? productDisplayName(fromList, 64) : null
  }

  const canImport = can(user, 'tenders.import')
  const documents = tender.documents ?? []
  const conditions = tender.conditions ?? []
  // Formularz ofertowy = zapisany plik Word; bez niego eksport DOCX kończy się błędem (TenderDocxOfferFiller).
  const hasOfferForm = documents.some((d) => d.has_file && /\.docx?$/i.test(d.original_name))
  const withProduct = coverage?.with_product ?? tender.items.filter((i) => i.main_product).length
  const attentionCount = coverage
    ? new Set([
        ...coverage.item_ids.without_product,
        ...coverage.item_ids.without_price,
        ...coverage.item_ids.weak_match,
        ...coverage.item_ids.low_margin,
      ]).size
    : 0
  const savedDeadline = tender.deadline ? tender.deadline.slice(0, 10) : ''
  const savedDeadlineTime = deadlineTimeLabel(tender.deadline_time) ?? ''
  const deadlineDirty =
    deadlineEdit !== savedDeadline || (deadlineEdit ? deadlineTimeEdit : '') !== (deadlineEdit ? savedDeadlineTime : '')
  const deadlineDays = daysUntil(savedDeadline)
  /** „5.10.2026, 10:00” — termin składania z godziną, jeśli jest */
  const deadlineLabel = formatDeadline(savedDeadline, tender.deadline_time)
  const noticeDirty = noticeEdit.trim() !== (tender.notice_number ?? '')
  // wynik jest potrzebny, gdy dzień terminu minął (szkic i odrzucony przetarg nie mają wyniku)
  const resultMissing =
    !tender.result_status && deadlineDays != null && deadlineDays < 0 && !['draft', 'odrzucony'].includes(tender.status)
  const canStartPricing = next_statuses.includes('wycena')
  const isDraft = tender.status === 'draft'

  async function finishWizard() {
    // Szkic → Wycena tylko, gdy przetarg jest jeszcze szkicem (import i dopasowanie AI robią to same)
    if (isDraft && canStartPricing && !(await transition('wycena'))) return
    setTab('podsumowanie')
    setWizardActive(false)
  }
  const docModeLabel =
    docMode === 'simple' ? 'tylko tekst' : docMode === 'ai' ? 'odczyt z podglądem' : 'odczyt z podglądem i zapis pliku'
  const docTargetsLabel =
    [docTargets.items ? 'pozycje' : null, docTargets.conditions ? 'warunki' : null].filter(Boolean).join(' i ') ||
    'nic nie zaznaczono'

  function goToItems(filter: CoverageFilter) {
    setCoverageFilter(filter)
    setShowAiChanges(false)
    setItemQuery('')
    setTab('pozycje')
  }

  const matchOverlay = (
    <>
      {matchBusy && (
        <div className="fixed inset-0 z-40 flex items-center justify-center bg-slate-900/55 p-4">
          <div className="w-full max-w-md rounded-xl bg-white p-4 text-sm shadow-xl">
            <p className="font-semibold text-slate-900">Trwa dopasowywanie produktów…</p>
            <p className="mt-1 text-xs text-slate-600">
              Nie odświeżaj strony. Sprawdzamy naraz do{' '}
              {matchParallelItems(coverage?.thresholds.match_concurrency)} pozycji — gdy jedna się skończy, od razu
              zaczyna się następna.
            </p>
            {(() => {
              const total = Math.max(matchProgress?.total ?? 0, 0)
              const done = Math.min(matchProgress?.done ?? 0, total)
              const pct = total > 0 ? Math.round((done / total) * 100) : 0
              const inFlight = matchProgress?.inFlight ?? []
              const lineOf = new Map((data?.tender.items ?? []).map((i) => [i.id, i.line_no]))
              const inFlightLines = inFlight
                .map((itemId) => lineOf.get(itemId))
                .filter((line): line is number => line != null)
                .sort((a, b) => a - b)
              // wszystkie pozostałe pozycje są już w modelu — średnia z dotychczasowych nie mówi, ile to potrwa
              const onlyTailLeft = done < total && total - done <= inFlight.length
              const eta =
                done > 0 && total > done && !onlyTailLeft
                  ? formatMatchEta(Math.round((matchElapsed * (total - done)) / done))
                  : null
              return (
                <>
                  <p className="mt-3 font-mono text-2xl font-semibold text-violet-800">{pct}%</p>
                  <div className="mt-2 h-2 overflow-hidden rounded-full bg-slate-200">
                    <div
                      className={`h-full rounded-full bg-violet-600 transition-all${inFlight.length > 0 ? ' animate-pulse' : ''}`}
                      style={{ width: `${Math.max(pct, inFlight.length > 0 ? 2 : 0)}%` }}
                    />
                  </div>
                  <p className="mt-2 text-xs text-slate-600">
                    Gotowe: {done} z {total || '…'} · sprawdzane teraz: {inFlight.length}
                  </p>
                  {inFlightLines.length > 0 && (
                    <p className="mt-2 truncate text-xs text-slate-600">
                      Teraz sprawdzane pozycje: {inFlightLines.join(', ')}
                    </p>
                  )}
                  {onlyTailLeft && (
                    <p className="mt-2 text-xs text-amber-800">
                      Ostatnie pozycje są jeszcze sprawdzane — to bywa 2–4 minuty.
                    </p>
                  )}
                  <p className="mt-2 font-mono text-sm text-violet-800">
                    Upłynęło {Math.floor(matchElapsed / 60)}:{String(matchElapsed % 60).padStart(2, '0')}
                    {eta ? ` · zostało ${eta}` : ''}
                  </p>
                </>
              )
            })()}
          </div>
        </div>
      )}
    </>
  )

  const matchReportPanel = (
    <>
      {matchReport && !matchBusy && (
        <div className="mb-3 rounded-xl border border-violet-200 bg-violet-50 p-3 text-xs text-violet-950">
          <div className="flex flex-wrap items-start justify-between gap-2">
            <div>
              <strong>Ostatnie dopasowanie produktów</strong>
              <span className="ml-2 text-violet-800/70">
                {new Date(matchReport.at).toLocaleString('pl-PL')}
              </span>
              <p className="mt-1">
                Sprawdzono pozycji: {matchReport.processed} · zmieniono: {matchReport.changed} · bez zmiany:{' '}
                {matchReport.unchanged} · usunięto produkt: {matchReport.cleared} · pominięto wpisane ręcznie:{' '}
                {matchReport.skipped_custom} · bez pasującego produktu: {matchReport.no_match}
                {(matchReport.model_failed ?? matchReport.model_unavailable ?? 0) > 0 && (
                  <>
                    {' '}
                    ·{' '}
                    <strong>
                      nie udało się ocenić: {matchReport.model_failed ?? matchReport.model_unavailable}
                    </strong>{' '}
                    (te pozycje czekają albo zostały z poprzednim produktem, ocena najwyżej 70% — uruchom
                    dopasowanie ponownie)
                  </>
                )}
                {(matchReport.left_as_is ?? 0) > 0 && (
                  <>
                    {' '}
                    · pominięto: {matchReport.left_as_is} (dopasowanie tylko pustych pozycji — te mają już
                    dobrze oceniony produkt albo produkt wpisany ręcznie)
                  </>
                )}
              </p>
              {(matchReport.finished_in_background ?? 0) > 0 && (
                <p className="mt-1 text-amber-800">
                  Pozycje sprawdzane dłużej niż limit czasu: {matchReport.finished_in_background} — serwer kończy je
                  w tle i zapisze wynik w ofercie, ale liczby wyżej ich nie obejmują.
                </p>
              )}
              <p className="mt-1 text-violet-800/80">
                Dobrane produkty są zapisane od razu. Przyciski <strong>Zapisz</strong> i{' '}
                <strong>Zapisz całość</strong> służą tylko do ręcznych poprawek (cena, ilość).
              </p>
            </div>
            <div className="flex flex-wrap gap-1">
              {matchReport.changed > 0 && (
                <button
                  type="button"
                  onClick={() => {
                    setShowAiChanges((v) => {
                      const next = !v
                      if (next) setCoverageFilter(null)
                      return next
                    })
                    setTab('pozycje')
                    if (wizardMode) setWizardStep(1)
                  }}
                  className={`rounded px-2 py-1 ${
                    showAiChanges ? 'bg-violet-800 text-white' : 'bg-white text-violet-900'
                  }`}
                >
                  {showAiChanges ? 'Pokaż wszystkie' : `Tylko zmienione (${matchReport.changed})`}
                </button>
              )}
              <button
                type="button"
                className="rounded px-2 py-1 text-violet-800 underline"
                onClick={() => {
                  setMatchReport(null)
                  setShowAiChanges(false)
                  setFocusItemId(null)
                  setReportPreviewId(null)
                  if (id) {
                    sessionStorage.removeItem(matchReportStorageKey(id))
                  }
                }}
              >
                Ukryj
              </button>
            </div>
          </div>
          {matchReport.changes.length > 0 && (
            <ul className="mt-2 max-h-40 space-y-0.5 overflow-y-auto text-[11px]">
              {matchReport.changes.slice(0, 80).map((c) => {
                const name = matchChangeProductName(c)
                return (
                  <li key={c.id}>
                    <button
                      type="button"
                      onClick={() => openMatchChange(c)}
                      title="Pokaż pozycję i produkt zapisany przy dopasowaniu"
                      className="max-w-full text-left font-mono text-violet-900 underline decoration-violet-400 hover:text-violet-950"
                    >
                      Pozycja {c.line_no}: {c.from_sku ?? '—'} → {c.to_sku ?? 'brak'}
                      {c.action === 'cleared' ? ' (usunięto produkt)' : ''}
                      {name ? ` · ${name}` : ''}
                    </button>
                  </li>
                )
              })}
            </ul>
          )}
        </div>
      )}
    </>
  )

  const coverageBlock = (
    <>
      {coverage && (
        <div
          data-ready={coverage.ready ? 'true' : 'false'}
          className={`app-coverage mb-3 rounded-xl border p-3 text-xs ${
            coverage.ready ? 'border-emerald-200 bg-emerald-50' : 'border-amber-200 bg-amber-50'
          }`}
        >
          <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
            <strong>
              Stan oferty:{' '}
              {coverage.ready ? 'gotowa do akceptacji' : 'trzeba uzupełnić'}
            </strong>
            <span className="text-slate-600">
              {coverage.with_product} z {coverage.total} pozycji ma produkt
            </span>
          </div>
          <div className="flex flex-wrap gap-1.5">
            {(
              [
                ['without_product', `Bez produktu: ${coverage.without_product}`],
                ['without_price', `Bez ceny: ${coverage.without_price}`],
                ['weak_match', `Słabe dopasowanie: ${coverage.weak_match}`],
                [
                  'low_margin',
                  coverage.thresholds?.min_margin_percent != null
                    ? `Niska marża (poniżej ${coverage.thresholds.min_margin_percent}%): ${coverage.low_margin}`
                    : `Niska marża: ${coverage.low_margin}`,
                ],
              ] as const
            ).map(([key, label]) => (
              <button
                key={key}
                type="button"
                disabled={coverage[key] === 0}
                title={key === 'weak_match' ? 'Ocena poniżej progu albo brak oceny.' : undefined}
                onClick={() => {
                  setCoverageFilter((f) => (f === key ? null : key))
                  setTab('pozycje')
                }}
                className={`app-chip rounded px-2 py-1 ${coverageFilter === key ? 'app-chip--active ' : ''}${
                  coverageFilter === key
                    ? 'bg-slate-800 text-white'
                    : coverage[key] === 0
                      ? 'bg-white/60 text-slate-400'
                      : 'bg-white text-slate-700 hover:bg-slate-100'
                }`}
              >
                {label}
              </button>
            ))}
            {coverage.substitutes_pending > 0 && (
              <span className="rounded bg-violet-100 px-2 py-1 text-violet-800">
                Zamienniki do zatwierdzenia: {coverage.substitutes_pending}
              </span>
            )}
            {(coverageFilter || itemQuery.trim() !== '') && (
              <button
                type="button"
                className="rounded px-2 py-1 text-blue-700 underline"
                onClick={() => {
                  setCoverageFilter(null)
                  setItemQuery('')
                }}
              >
                Wyczyść filtr
              </button>
            )}
          </div>
        </div>
      )}
    </>
  )

  const itemsSection = (
    <div className="space-y-3">
      {coverageBlock}
      {can_edit && (
        <div className="app-actions app-actions--end flex flex-wrap items-center justify-end gap-2">
              <button
                type="button"
                disabled={busy}
                onClick={() =>
                  void runMatch(true, listNarrowed ? filteredItems.map((i) => i.id) : undefined)
                }
                title={
                  listNarrowed
                    ? `Tylko pozycje bez produktu albo ze słabym dopasowaniem spośród ${filteredItems.length} pozycji z filtra`
                    : 'Tylko pozycje bez produktu albo ze słabym dopasowaniem — pozostałych nie zmienia'
                }
                className="rounded bg-violet-600 px-4 py-2 text-xs font-semibold text-white hover:bg-violet-700 disabled:opacity-50"
              >
                Dopasuj produkty do pustych pozycji
              </button>
              <button
                type="button"
                disabled={busy}
                onClick={() => {
                  if (listNarrowed && filteredItems.length === 0) {
                    setErr('Filtr nie pokazuje żadnej pozycji.')
                    return
                  }
                  const confirmMsg = listNarrowed
                    ? `Dopasować od nowa ${filteredItems.length} pozycji z filtra? Produkty wpisane ręcznie zostaną. Linki znalezione w internecie zostaną zastąpione produktami z katalogu.`
                    : 'Dopasować od nowa wszystkie pozycje (także te, które mają już produkt z katalogu)? Produkty wpisane ręcznie zostaną. Linki znalezione w internecie zostaną zastąpione produktami z katalogu.'
                  if (!window.confirm(confirmMsg)) {
                    return
                  }
                  void runMatch(false, listNarrowed ? filteredItems.map((i) => i.id) : undefined)
                }}
                title={
                  listNarrowed
                    ? `Dopasuje od nowa ${filteredItems.length} pozycji widocznych w filtrze`
                    : 'Dopasuje od nowa wszystkie pozycje — może zmienić już wybrane produkty z katalogu'
                }
                className="rounded bg-violet-800 px-4 py-2 text-xs font-semibold text-white hover:bg-violet-900 disabled:opacity-50"
              >
                {listNarrowed
                  ? `Dopasuj od nowa: ${filteredItems.length} pozycji z filtra`
                  : 'Dopasuj od nowa: wszystkie pozycje'}
              </button>
              <button
                type="button"
                disabled={busy}
                onClick={() => void previewCheaperSubstitutes()}
                title="Pokaże zamienniki co najmniej 3% taniej (po upuście) i zapyta, czy je zastosować"
                className="rounded bg-amber-500 px-4 py-2 text-xs font-semibold text-white hover:bg-amber-600 disabled:opacity-50"
              >
                Zastosuj tańsze zamienniki
              </button>
              <button
                type="button"
                disabled={busy}
                onClick={() => void saveAllItems()}
                className="rounded bg-emerald-600 px-4 py-2 text-xs font-semibold text-white hover:bg-emerald-700 disabled:opacity-50"
              >
                Zapisz całość
              </button>
        </div>
      )}
          {cheaperPreview && (
            <div
              ref={cheaperPreviewRef}
              className="rounded-lg border-2 border-amber-400 bg-amber-50 p-3 text-xs text-slate-800 shadow-sm"
            >
              <p className="font-semibold text-amber-950">
                Zastosować tańsze zamienniki na {cheaperPreview.candidates.length} pozycjach?
              </p>
              <p className="mt-1 text-[11px] text-amber-900/80">
                To tylko podgląd — potwierdź poniżej, żeby zapisać zmiany w ofercie.
              </p>
              <ul className="mt-2 max-h-48 space-y-1 overflow-y-auto">
                {cheaperPreview.candidates.map((c) => (
                  <li key={c.item_id} className="font-mono text-[11px]">
                    Pozycja {c.line_no}: {c.from_sku ?? '—'} → {c.to_sku} (taniej o {c.save_percent}% · cena zakupu{' '}
                    {Number(c.purchase_price).toLocaleString('pl-PL', {
                      minimumFractionDigits: 2,
                      maximumFractionDigits: 2,
                    })}{' '}
                    zł)
                  </li>
                ))}
              </ul>
              <div className="mt-3 flex gap-2">
                <button
                  type="button"
                  disabled={busy}
                  onClick={() => setCheaperPreview(null)}
                  className="rounded border border-slate-300 bg-white px-3 py-1.5 text-[11px] disabled:opacity-50"
                >
                  Anuluj
                </button>
                <button
                  type="button"
                  disabled={busy}
                  onClick={() => void applyCheaperSubstitutes()}
                  className="rounded bg-amber-600 px-3 py-1.5 text-[11px] font-semibold text-white hover:bg-amber-700 disabled:opacity-50"
                >
                  {busy ? 'Zapisuję…' : 'Tak, zastosuj'}
                </button>
              </div>
            </div>
          )}
          <div className="overflow-x-auto rounded-xl bg-white p-4 shadow-sm">
            <div className="mb-3 flex flex-wrap items-center gap-2">
              <input
                type="search"
                value={itemQuery}
                onChange={(e) => setItemQuery(e.target.value)}
                placeholder="Szukaj w wymaganiach zamawiającego i wybranych produktach…"
                className="min-w-[240px] flex-1 rounded border border-slate-300 px-2 py-1.5 text-xs"
              />
              <span className="text-[11px] text-slate-500">
                {listFiltered
                  ? `${filteredItems.length} z ${tender.items.length} pozycji`
                  : `${tender.items.length} pozycji`}
              </span>
            </div>
            <div className="space-y-3 text-xs">
                {filteredItems.map((item) => (
                  <ItemRow
                    key={item.id}
                    tenderId={Number(id)}
                    targetMarginPercent={Number(tender.target_margin_percent ?? 18)}
                    item={item}
                    products={products}
                    canEdit={can_edit}
                    canComment={canComment}
                    canDelete={canDeleteItems}
                    busy={busy}
                    focused={focusItemId === item.id}
                    changedByAi={aiChangedIds.has(item.id)}
                    conflicts={conflicts[String(item.id)]}
                    comments={comments.filter((c) => c.tender_item_id === item.id)}
                    itemActivities={activities.filter(
                      (a) => a.item?.id === item.id && activityHasRealChange(a),
                    )}
                    onSave={saveItem}
                    onDelete={deleteItem}
                    onDraftChange={registerItemDraft}
                    onComment={async (itemId, body) => {
                      setBusy(true)
                      setErr('')
                      try {
                        await api(`/tenders/${id}/comments`, {
                          method: 'POST',
                          body: JSON.stringify({ body, tender_item_id: itemId }),
                        })
                        await loadMeta()
                        setMsg('Dodano komentarz przy pozycji.')
                      } catch (e) {
                        setErr(e instanceof Error ? e.message : 'Błąd komentarza')
                      } finally {
                        setBusy(false)
                      }
                    }}
                  />
                ))}
                {filteredItems.length === 0 && (
                  <p className="p-3 text-slate-400">Żadna pozycja nie pasuje do filtra.</p>
                )}
            </div>
          </div>
      {can_edit && (
        <div className="app-actions app-actions--end flex flex-wrap items-center justify-end gap-2">
              <button
                type="button"
                disabled={busy}
                onClick={() => void saveAllItems()}
                className="rounded bg-emerald-600 px-4 py-2 text-xs font-semibold text-white hover:bg-emerald-700 disabled:opacity-50"
              >
                Zapisz całość
              </button>
        </div>
      )}
    </div>
  )

  const conditionsChecked = conditions.filter((c) => c.status != null).length
  const conditionsToCheck = conditions.length - conditionsChecked
  const conditionsNotMet = conditions.filter((c) => c.status === 'nie_spelniamy').length
  const conditionGroups = (() => {
    const order = Object.keys(CONDITION_CATEGORY_LABEL)
    const groups = new Map<string, Condition[]>()
    for (const c of conditions) {
      const label = conditionCategoryLabel(c.category)
      groups.set(label, [...(groups.get(label) ?? []), c])
    }
    const rank = (label: string) => {
      const i = order.findIndex((k) => CONDITION_CATEGORY_LABEL[k] === label)
      return i < 0 ? order.length - 0.5 : i
    }
    return [...groups.entries()].sort((a, b) => rank(a[0]) - rank(b[0]))
  })()

  const conditionsSection = (
    <div className="space-y-3 rounded-xl bg-white p-4 text-xs shadow-sm">
      <div>
        <h2 className="text-sm font-semibold">Warunki</h2>
        <p className="text-slate-500">
          Wymagania zamawiającego poza samymi produktami: terminy dostaw, wymagane dokumenty i certyfikaty. Jeśli
          któregoś nie spełnimy, oferta może zostać odrzucona — zaznacz przy każdym, czy go spełniamy.
        </p>
      </div>
      {conditions.length > 0 && (
        <div className="flex flex-wrap gap-1.5">
          <span className="rounded bg-emerald-50 px-2 py-1 text-emerald-700">
            Spełniamy: {conditions.filter((c) => c.status === 'spelniamy').length}
          </span>
          <span className="rounded bg-amber-50 px-2 py-1 text-amber-800">Do sprawdzenia: {conditionsToCheck}</span>
          <span className="rounded bg-red-50 px-2 py-1 text-red-700">Nie spełniamy: {conditionsNotMet}</span>
        </div>
      )}
      {can_edit && (
        <div className="flex flex-wrap items-end gap-2">
          <label className="text-slate-600">
            Kategoria
            <select
              className="mt-1 block rounded border border-slate-300 px-2 py-1.5"
              value={newConditionCategory}
              onChange={(e) => setNewConditionCategory(e.target.value)}
            >
              {Object.entries(CONDITION_CATEGORY_LABEL).map(([key, label]) => (
                <option key={key} value={key}>
                  {label}
                </option>
              ))}
            </select>
          </label>
          <label className="min-w-[240px] flex-1 text-slate-600">
            Nowy warunek
            <input
              className="mt-1 block w-full rounded border border-slate-300 px-2 py-1.5"
              placeholder="Na przykład: dostawa w ciągu 5 dni roboczych od zamówienia"
              value={newCondition}
              onChange={(e) => setNewCondition(e.target.value)}
            />
          </label>
          <button
            type="button"
            disabled={busy || !newCondition.trim()}
            onClick={() => void addCondition()}
            className="rounded bg-blue-600 px-3 py-1.5 text-white disabled:opacity-50"
          >
            Dodaj warunek
          </button>
        </div>
      )}
      {conditions.length === 0 ? (
        <p className="rounded bg-slate-50 p-3 text-slate-500">
          Nie ma jeszcze warunków. Zostaną odczytane z dokumentacji przetargu (sekcja Dokumenty) albo dodaj je ręcznie
          powyżej.
        </p>
      ) : (
        conditionGroups.map(([group, rows]) => (
          <div key={group}>
            <h3 className="mb-1 font-semibold text-slate-700">
              {group} <span className="font-normal text-slate-500">· {rows.length}</span>
            </h3>
            <ul className="divide-y divide-slate-100 rounded-lg border border-slate-200">
              {rows.map((c) => {
                const doc = c.tender_document_id ? documents.find((d) => d.id === c.tender_document_id) : undefined
                const source = doc ? `z pliku ${doc.original_name}` : (CONDITION_SOURCE_LABEL[c.source] ?? c.source)
                return (
                  <li key={c.id} className="flex flex-wrap items-start gap-3 p-2">
                    <div className="min-w-[240px] flex-1">
                      <p className="whitespace-pre-wrap">{c.content}</p>
                      <p className="mt-0.5 text-[11px] text-slate-500">
                        {source}
                        {c.status && c.status_user
                          ? ` · zaznaczył(a) ${c.status_user.name}${
                              c.status_at ? `, ${new Date(c.status_at).toLocaleDateString('pl-PL')}` : ''
                            }`
                          : ''}
                      </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-1" role="group" aria-label="Czy spełniamy warunek">
                      {CONDITION_STATUS_OPTIONS.map((o) => {
                        const on = (c.status ?? null) === o.value
                        return (
                          <button
                            key={o.label}
                            type="button"
                            aria-pressed={on}
                            disabled={busy || !can_edit || on}
                            onClick={() => void setConditionStatus(c.id, o.value)}
                            className={`rounded border px-2 py-1 ${
                              on ? o.active : 'border-slate-300 bg-white text-slate-600 hover:bg-slate-50'
                            } ${!can_edit && !on ? 'opacity-50' : ''}`}
                          >
                            {o.label}
                          </button>
                        )
                      })}
                      {can_edit && (
                        <button
                          type="button"
                          disabled={busy}
                          className="ml-1 px-1 text-[11px] text-red-600"
                          onClick={() => void deleteCondition(c.id)}
                        >
                          Usuń
                        </button>
                      )}
                    </div>
                  </li>
                )
              })}
            </ul>
          </div>
        ))
      )}
    </div>
  )

  const documentsSection = (
    <div className="space-y-4">
      <div className="rounded-xl bg-white p-4 shadow-sm">
        <h2 className="mb-1 text-sm font-semibold">Dokumenty od zamawiającego</h2>
        <p className="text-xs text-slate-500">
          Pliki od zamawiającego: specyfikacja warunków zamówienia (SWZ, dawniej SIWZ) z listą produktów i warunkami
          oraz formularz ofertowy do wypełnienia cenami.
        </p>
        <p className="mb-3 text-xs text-slate-500">
          Ze specyfikacji odczytamy pozycje i warunki. Formularz ofertowy (plik Word) zapiszemy, żeby wypełnić go cenami
          w menu Eksport › Formularz ofertowy z cenami (Word).
        </p>
        {!canImport ? (
          <p className="rounded bg-amber-50 px-3 py-2 text-xs text-amber-800">
            Nie masz uprawnienia do dodawania dokumentów. Poproś osobę z działu przetargów o dodanie dokumentacji przetargu.
          </p>
        ) : !can_edit ? (
          <p className="rounded bg-amber-50 px-3 py-2 text-xs text-amber-800">
            Dokumenty można dodawać tylko w statusie Szkic albo Wycena.
          </p>
        ) : (
          <>
            <label
              onDragOver={(e) => {
                e.preventDefault()
                setDragOver(true)
              }}
              onDragLeave={() => setDragOver(false)}
              onDrop={(e) => {
                e.preventDefault()
                setDragOver(false)
                const f = e.dataTransfer.files?.[0]
                if (f && !busy) void analyzeDocument(f)
              }}
              className={`app-dropzone flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed px-4 py-8 text-center ${
                dragOver ? 'border-blue-600 bg-sky-50' : 'border-slate-300 bg-slate-50'
              } ${busy ? 'pointer-events-none opacity-60' : ''}`}
            >
              <svg
                width="28"
                height="28"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                strokeWidth="2"
                strokeLinecap="round"
                strokeLinejoin="round"
                className="text-blue-600"
                aria-hidden="true"
              >
                <path d="M12 16V4" />
                <path d="M7 9l5-5 5 5" />
                <path d="M4 16v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3" />
              </svg>
              <strong className="text-sm">Przeciągnij tu dokumentację przetargu albo formularz ofertowy</strong>
              <span className="text-xs text-slate-500">PDF, Excel albo Word</span>
              <span className="mt-1 inline-flex items-center rounded bg-blue-600 px-3 py-2 text-xs font-medium text-white">
                {busy && docStatus.startsWith('Wybrano') ? 'Odczytuję plik…' : 'Wybierz plik z komputera'}
              </span>
              <input
                type="file"
                className="sr-only"
                accept=".pdf,.xlsx,.xls,.csv,.doc,.docx"
                disabled={busy}
                onChange={(e) => {
                  const f = e.target.files?.[0]
                  if (f) void analyzeDocument(f)
                  e.target.value = ''
                }}
              />
            </label>
            <details className="mt-3 rounded-lg border border-slate-200 px-3 py-2 text-xs">
              <summary className="cursor-pointer font-semibold">
                Ustawienia odczytu{' '}
                <span className="font-normal text-slate-500">
                  · {docTargetsLabel}, {docModeLabel},{' '}
                  {replaceItems || replaceConditions ? 'zastępuje istniejące' : 'dopisuje do istniejących'}
                </span>
              </summary>
              <div className="mt-3">
            <div className="mb-3 flex flex-wrap gap-4 text-xs">
              <label className="flex items-center gap-1">
                <input
                  type="checkbox"
                  checked={docTargets.items}
                  onChange={(e) => setDocTargets((t) => ({ ...t, items: e.target.checked }))}
                />
                Pozycje
              </label>
              <label className="flex items-center gap-1">
                <input
                  type="checkbox"
                  checked={docTargets.conditions}
                  onChange={(e) => setDocTargets((t) => ({ ...t, conditions: e.target.checked }))}
                />
                Warunki
              </label>
            </div>
            <div className="mb-3 flex flex-wrap gap-3 text-xs">
              {(
                [
                  ['simple', DOC_MODE_LABEL.simple],
                  ['ai', DOC_MODE_LABEL.ai],
                  ['full', DOC_MODE_LABEL.full],
                ] as const
              ).map(([v, label]) => (
                <label key={v} className="flex items-center gap-1">
                  <input
                    type="radio"
                    name="docMode"
                    checked={docMode === v}
                    onChange={() => setDocMode(v)}
                  />
                  {label}
                </label>
              ))}
            </div>
            <div className="mb-3 flex flex-wrap gap-3 text-xs">
              <label className="flex items-center gap-1">
                <input
                  type="checkbox"
                  checked={replaceItems}
                  onChange={(e) => setReplaceItems(e.target.checked)}
                />
                Zastąp istniejące pozycje
              </label>
              <label className="flex items-center gap-1">
                <input
                  type="checkbox"
                  checked={replaceConditions}
                  onChange={(e) => setReplaceConditions(e.target.checked)}
                />
                Zastąp istniejące warunki
              </label>
            </div>
              </div>
            </details>
          </>
        )}
        <div className="mt-3 flex flex-wrap items-center gap-3">
              {docStatus && (
                <p
                  className={`text-xs ${
                    docStatus.startsWith('Błąd')
                      ? 'text-red-600'
                      : docStatus.startsWith('Gotowe')
                        ? 'text-emerald-700'
                        : 'text-sky-700'
                  }`}
                >
                  {busy && docStatus.startsWith('Wybrano') ? (
                    <span className="inline-flex items-center gap-2">
                      <span className="inline-block h-3 w-3 animate-spin rounded-full border-2 border-sky-600 border-t-transparent" />
                      {docStatus}
                    </span>
                  ) : (
                    docStatus
                  )}
                </p>
              )}
        </div>
      </div>

          {docPreview && (
            <div
              id="doc-preview"
              className="space-y-3 rounded-xl border-2 border-amber-400 bg-amber-50/40 p-4 shadow-sm"
            >
              <div className="flex flex-wrap items-center justify-between gap-2">
                <div>
                  <h3 className="text-sm font-semibold text-amber-950">
                    Co zostanie dodane do przetargu
                  </h3>
                  <p className="text-[11px] text-amber-900/80">
                    To tylko podgląd — pozycje trafią do przetargu dopiero po kliknięciu poniżej.
                  </p>
                </div>
                <div className="flex flex-wrap gap-2">
                  <button
                    type="button"
                    disabled={busy}
                    onClick={() => {
                      setDocPreview(null)
                      setDocStatus('')
                    }}
                    className="rounded border border-slate-300 bg-white px-2 py-1 text-xs"
                  >
                    Anuluj
                  </button>
                  <button
                    type="button"
                    disabled={busy || !can_edit || !canImport}
                    onClick={() => void commitDocument()}
                    className="rounded bg-emerald-600 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-700 disabled:opacity-50"
                  >
                    {docMode === 'simple'
                      ? 'Dodaj do przetargu'
                      : `Dodaj do przetargu: ${docPreview.items.filter((i) => i.selected).length} pozycji, ${
                          docPreview.conditions.filter((c) => c.selected).length
                        } warunków`}
                  </button>
                </div>
              </div>
              <p className="text-xs text-slate-600">
                Zaznaczono:{' '}
                <strong>{docPreview.items.filter((i) => i.selected).length}</strong> pozycji,{' '}
                <strong>{docPreview.conditions.filter((c) => c.selected).length}</strong> warunków
                <button
                  type="button"
                  className="ml-3 text-blue-600 underline"
                  onClick={() =>
                    setDocPreview((p) =>
                      p
                        ? {
                            ...p,
                            items: p.items.map((i) => ({ ...i, selected: true })),
                            conditions: p.conditions.map((c) => ({ ...c, selected: true })),
                          }
                        : p,
                    )
                  }
                >
                  Zaznacz wszystkie
                </button>
                <button
                  type="button"
                  className="ml-2 text-blue-600 underline"
                  onClick={() =>
                    setDocPreview((p) =>
                      p
                        ? {
                            ...p,
                            items: p.items.map((i) => ({ ...i, selected: false })),
                            conditions: p.conditions.map((c) => ({ ...c, selected: false })),
                          }
                        : p,
                    )
                  }
                >
                  Odznacz wszystkie
                </button>
              </p>
              {docMode === 'simple' ? (
                <textarea
                  className="h-48 w-full rounded border border-slate-300 bg-white p-2 text-xs"
                  value={docPreview.extracted_text}
                  onChange={(e) =>
                    setDocPreview((p) => (p ? { ...p, extracted_text: e.target.value } : p))
                  }
                />
              ) : (
                <>
                  {docPreview.mapping_notes && (
                    <p className="rounded bg-white/80 px-2 py-1 text-[11px] text-slate-600">
                      {docPreview.mapping_notes}
                    </p>
                  )}
                  <div>
                    <h4 className="mb-1 text-xs font-semibold">
                      Pozycje z dokumentacji przetargu ({docPreview.items.length})
                    </h4>
                    {docPreview.items.length === 0 ? (
                      <p className="text-xs text-slate-400">W pliku nie znaleziono pozycji.</p>
                    ) : (
                      <div className="max-h-72 overflow-auto rounded border border-slate-200 bg-white">
                        <table className="w-full text-left text-xs">
                          <thead className="sticky top-0 bg-slate-50">
                            <tr className="border-b">
                              <th className="p-1.5 w-8"></th>
                              <th className="p-1.5">Numer</th>
                              <th className="p-1.5">Nazwa i opis</th>
                              <th className="p-1.5">Normy</th>
                              <th className="p-1.5 text-right">Cena</th>
                              <th className="p-1.5 text-right">Ilość</th>
                            </tr>
                          </thead>
                          <tbody>
                            {docPreview.items.map((it, idx) => (
                              <tr key={idx} className="border-b border-slate-100">
                                <td className="p-1.5">
                                  <input
                                    type="checkbox"
                                    checked={it.selected}
                                    onChange={(e) =>
                                      setDocPreview((p) => {
                                        if (!p) return p
                                        const items = [...p.items]
                                        items[idx] = { ...items[idx], selected: e.target.checked }
                                        return { ...p, items }
                                      })
                                    }
                                  />
                                </td>
                                <td className="p-1.5 font-mono text-[11px] text-slate-700">
                                  {it.sku ?? '—'}
                                </td>
                                <td className="p-1.5 max-w-[360px]">
                                  <SiwzRequirementBlock
                                    name={it.name || splitSiwzRequirement(it.requirement).name}
                                    description={
                                      it.description || splitSiwzRequirement(it.requirement).description
                                    }
                                  />
                                </td>
                                <td className="p-1.5 max-w-[200px] text-[11px] text-slate-700">
                                  {it.norms ?? '—'}
                                </td>
                                <td className="p-1.5 text-right whitespace-nowrap">
                                  {it.offer_price != null
                                    ? `${Number(it.offer_price).toFixed(2)} ${currencyLabel(it.currency)}`
                                    : '—'}
                                </td>
                                <td className="p-1.5 text-right">{it.quantity}</td>
                              </tr>
                            ))}
                          </tbody>
                        </table>
                      </div>
                    )}
                  </div>
                  <div>
                    <h4 className="mb-1 text-xs font-semibold">
                      Warunki ({docPreview.conditions.length})
                    </h4>
                    {docPreview.conditions.length === 0 ? (
                      <p className="text-xs text-slate-400">W pliku nie znaleziono warunków.</p>
                    ) : (
                      <ul className="max-h-56 space-y-1 overflow-y-auto rounded border border-slate-200 bg-white p-2 text-xs">
                        {docPreview.conditions.map((c, idx) => (
                          <li key={idx} className="flex gap-2 border-b border-slate-100 py-1.5">
                            <input
                              type="checkbox"
                              checked={c.selected}
                              onChange={(e) =>
                                setDocPreview((p) => {
                                  if (!p) return p
                                  const conditions = [...p.conditions]
                                  conditions[idx] = {
                                    ...conditions[idx],
                                    selected: e.target.checked,
                                  }
                                  return { ...p, conditions }
                                })
                              }
                            />
                            <span className="w-20 shrink-0 text-slate-400">{c.category ?? '—'}</span>
                            <span className="flex-1">{c.content}</span>
                          </li>
                        ))}
                      </ul>
                    )}
                  </div>
                </>
              )}
            </div>
          )}

          {documents.length > 0 && (
          <div className="rounded-xl bg-white p-4 shadow-sm">
            <h2 className="mb-2 text-sm font-semibold">Archiwum dokumentów</h2>
            <table className="w-full text-left text-xs">
              <thead>
                <tr className="border-b bg-slate-50">
                  <th className="p-2">Plik</th>
                  <th className="p-2">Tryb</th>
                  <th className="p-2">Data</th>
                  <th className="p-2"></th>
                </tr>
              </thead>
              <tbody>
                {(tender.documents ?? []).map((d) => (
                  <tr key={d.id} className="border-b">
                    <td className="p-2">
                      {d.original_name}
                      {d.has_file ? (
                        <span className="ml-1 text-[10px] text-emerald-600">plik zapisany</span>
                      ) : null}
                    </td>
                    <td className="p-2">{DOC_MODE_LABEL[d.mode] ?? d.mode}</td>
                    <td className="p-2">{new Date(d.created_at).toLocaleString('pl-PL')}</td>
                    <td className="p-2">
                      <div className="flex flex-wrap gap-1">
                        <button
                          type="button"
                          disabled={busy}
                          className="rounded bg-emerald-600 px-2 py-1 text-[10px] font-semibold text-white hover:bg-emerald-700 disabled:opacity-50"
                          onClick={() => void openDocumentPreview(d.id)}
                        >
                          Podgląd
                        </button>
                        <button
                          type="button"
                          disabled={busy || !d.has_file}
                          title={d.has_file ? 'Pobierz plik na komputer' : 'Ten plik nie został zapisany'}
                          className="rounded bg-sky-600 px-2 py-1 text-[10px] font-semibold text-white hover:bg-sky-700 disabled:opacity-50"
                          onClick={() => void downloadDoc(d)}
                        >
                          Pobierz
                        </button>
                        {can_edit && canImport && (
                          <>
                            <button
                              type="button"
                              disabled={busy}
                              className="rounded bg-blue-600 px-2 py-1 text-[10px] font-semibold text-white hover:bg-blue-700 disabled:opacity-50"
                              onClick={() => void reanalyzeDoc(d.id)}
                            >
                              Odczytaj ponownie
                            </button>
                            <button
                              type="button"
                              disabled={busy}
                              className="rounded bg-red-600 px-2 py-1 text-[10px] font-semibold text-white hover:bg-red-700 disabled:opacity-50"
                              onClick={() => void deleteDoc(d.id)}
                            >
                              Usuń
                            </button>
                          </>
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          )}
    </div>
  )

  const inviteSection = (
        <div className="space-y-3 rounded-xl bg-white p-4 shadow-sm text-xs">
          <h2 className="text-sm font-semibold">Zaproszeni do przetargu</h2>
          <p className="text-xs text-slate-500">
            Osoby, które mają dostęp do przetargu i pomagają w wycenie. Widzą przetarg jak opiekun przetargu, w granicach
            uprawnień swojej roli.
          </p>

          {canInvite && (
            <div className="grid gap-2 rounded-lg border border-slate-200 bg-slate-50 p-3 sm:grid-cols-[1fr_1fr_auto]">
              <label>
                Szukaj osoby
                <input
                  className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5"
                  value={inviteQ}
                  onChange={(e) => setInviteQ(e.target.value)}
                  placeholder="Imię lub e-mail"
                />
              </label>
              <label>
                Osoba
                <select
                  className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5"
                  value={inviteUserId}
                  onChange={(e) => setInviteUserId(e.target.value)}
                >
                  <option value="">— wybierz —</option>
                  {directory
                    .filter(
                      (u) =>
                        u.id !== user?.id &&
                        u.id !== tender.owner_id &&
                        !invitations.some((inv) => inv.user?.id === u.id),
                    )
                    .map((u) => (
                      <option key={u.id} value={u.id}>
                        {u.name} · {u.email} ({u.role})
                      </option>
                    ))}
                </select>
              </label>
              <div className="flex items-end">
                <button
                  type="button"
                  disabled={busy || !inviteUserId}
                  onClick={() => void sendInvite()}
                  className="w-full rounded bg-blue-600 px-3 py-1.5 text-white disabled:opacity-50"
                >
                  Zaproś
                </button>
              </div>
              <label className="sm:col-span-3">
                Wiadomość (opcjonalnie, trafi do e-maila)
                <textarea
                  className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5"
                  rows={2}
                  value={inviteNote}
                  onChange={(e) => setInviteNote(e.target.value)}
                  placeholder="Na przykład: proszę o wycenę rękawic"
                />
              </label>
            </div>
          )}

          <table className="w-full text-left">
            <thead>
              <tr className="border-b bg-slate-50">
                <th className="p-2">Osoba</th>
                <th className="p-2">Zaprosił</th>
                <th className="p-2">Kiedy</th>
                <th className="p-2">E-mail</th>
                <th className="p-2">Notatka</th>
                {canInvite && <th className="p-2" />}
              </tr>
            </thead>
            <tbody>
              {invitations.map((inv) => (
                <tr key={inv.id} className="border-b align-top">
                  <td className="p-2">
                    <div className="font-medium">{inv.user?.name ?? '—'}</div>
                    <div className="text-slate-500">{inv.user?.email}</div>
                  </td>
                  <td className="p-2">{inv.inviter?.name ?? '—'}</td>
                  <td className="p-2 whitespace-nowrap">
                    {inv.created_at ? new Date(inv.created_at).toLocaleString('pl-PL') : '—'}
                  </td>
                  <td className="p-2">
                    {inv.email_sent_at ? (
                      <span className="text-emerald-700">wysłany</span>
                    ) : (
                      <span className="text-amber-700">nie wysłano</span>
                    )}
                  </td>
                  <td className="p-2 text-slate-600">{inv.note ?? '—'}</td>
                  {canInvite && (
                    <td className="p-2 text-right">
                      <button
                        type="button"
                        disabled={busy}
                        onClick={() => void removeInvite(inv.id)}
                        className="rounded bg-red-600 px-2 py-1 text-[10px] text-white disabled:opacity-50"
                      >
                        Usuń
                      </button>
                    </td>
                  )}
                </tr>
              ))}
              {invitations.length === 0 && (
                <tr>
                  <td colSpan={canInvite ? 6 : 5} className="p-3 text-slate-400">
                    Nikt jeszcze nie został zaproszony.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
  )

  const statusSection = (
        <div className="space-y-4">
          <div className="rounded-xl bg-white p-4 shadow-sm">
            <h2 className="mb-2 text-sm font-semibold">Zmiana statusu</h2>
            <p className="mb-3 text-xs text-slate-500">
              Teraz: <strong>{TENDER_STATUS_LABEL[tender.status] ?? tender.status}</strong>
            </p>
            <label className="mb-3 block text-xs">
              Notatka (wymagana przy odrzuceniu albo cofnięciu z akceptacji)
              <textarea
                className="mt-1 w-full rounded border border-slate-300 px-2 py-1"
                rows={2}
                value={transitionNote}
                onChange={(e) => setTransitionNote(e.target.value)}
                placeholder="Uzasadnienie decyzji…"
              />
            </label>
            <div className="flex flex-wrap gap-2">
              {next_statuses.map((s) => (
                <button
                  key={s}
                  type="button"
                  disabled={busy}
                  onClick={() => void transition(s)}
                  className="rounded bg-blue-600 px-3 py-2 text-xs text-white hover:bg-blue-700 disabled:opacity-50"
                >
                  Zmień na: {TENDER_STATUS_LABEL[s] ?? s}
                </button>
              ))}
              {next_statuses.length === 0 && (
                <span className="text-xs text-slate-400">Twoja rola nie pozwala teraz zmienić statusu.</span>
              )}
            </div>
            <p className="mt-3 text-[11px] text-slate-400">
              Zalogowano jako: {user?.name} ({user?.role}). Dostępne zmiany statusu zależą od uprawnień Twojej roli.
            </p>
          </div>
          <div className="rounded-xl bg-white p-4 shadow-sm">
            <h2 className="mb-2 text-sm font-semibold">Historia statusów</h2>
            <table className="w-full text-left text-xs">
              <thead>
                <tr className="border-b bg-slate-50">
                  <th className="p-2">Kiedy</th>
                  <th className="p-2">Kto</th>
                  <th className="p-2">Zmiana statusu</th>
                  <th className="p-2">Notatka</th>
                </tr>
              </thead>
              <tbody>
                {(tender.status_histories ?? []).map((h) => (
                  <tr key={h.id} className="border-b">
                    <td className="p-2">{new Date(h.created_at).toLocaleString('pl-PL')}</td>
                    <td className="p-2">{h.user?.name}</td>
                    <td className="p-2">
                      {h.from_status ? (TENDER_STATUS_LABEL[h.from_status] ?? h.from_status) : '—'} →{' '}
                      {TENDER_STATUS_LABEL[h.to_status] ?? h.to_status}
                    </td>
                    <td className="p-2">{h.note ?? '—'}</td>
                  </tr>
                ))}
                {(tender.status_histories ?? []).length === 0 && (
                  <tr>
                    <td colSpan={4} className="p-3 text-slate-400">
                      Brak zmian statusu.
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </div>
  )

  const actionsMenu = canDeleteTender ? (
    <ActionMenu
      label="⋯"
      ariaLabel="Więcej akcji"
      disabled={busy}
      items={[{ label: 'Usuń przetarg', hint: 'Tej operacji nie można cofnąć', danger: true, onSelect: () => void deleteTender() }]}
    />
  ) : null

  const wizardSteps = [
    {
      label: 'Dokumenty',
      hint: documents.length > 0 ? `Pliki: ${documents.length}` : 'dodaj dokumentację i formularz ofertowy',
      done: documents.length > 0 || tender.items.length > 0,
    },
    {
      label: 'Pozycje i produkty',
      hint: tender.items.length > 0 ? `${withProduct} z ${tender.items.length} pozycji ma produkt` : 'brak pozycji',
      description: 'Sprawdź listę pozycji i dopasuj do nich produkty z katalogu.',
      done: tender.items.length > 0 && withProduct === tender.items.length,
    },
    {
      label: 'Warunki',
      hint: conditions.length > 0 ? `Warunki: ${conditions.length}` : 'brak warunków',
      done: conditions.length > 0,
    },
    {
      label: 'Termin i narzut',
      hint: savedDeadline ? `termin ${deadlineLabel}` : 'bez terminu',
      description: 'Ustaw termin składania i narzut, zaproś osoby do pomocy i rozpocznij wycenę.',
      done: false,
    },
  ]

  const startChecklist: Array<[boolean, string]> = [
    [documents.length > 0, documents.length > 0 ? `Dokumenty: ${documents.length}` : 'Brak dokumentów'],
    [
      tender.items.length > 0 && withProduct === tender.items.length,
      tender.items.length > 0 ? `${withProduct} z ${tender.items.length} pozycji ma produkt` : 'Brak pozycji',
    ],
    [
      conditions.length > 0 && conditionsToCheck === 0 && conditionsNotMet === 0,
      conditions.length === 0
        ? 'Brak warunków'
        : conditionsNotMet > 0
          ? `Warunki: ${conditionsNotMet} z ${conditions.length} nie spełniamy`
          : `Warunki: sprawdzone ${conditionsChecked} z ${conditions.length}`,
    ],
    [Boolean(savedDeadline), savedDeadline ? `Termin składania: ${deadlineLabel}` : 'Brak terminu składania'],
    [
      hasOfferForm,
      hasOfferForm
        ? 'Formularz ofertowy (plik Word) dodany'
        : 'Brak formularza ofertowego (plik Word) — nie da się pobrać formularza ofertowego z cenami',
    ],
  ]

  const wizardView = (
    <>
      <div className="mb-3 flex flex-wrap items-start justify-between gap-2">
        <div className="min-w-0">
          <Link to="/tenders" className="app-back text-xs text-blue-600 hover:underline">
            ← Lista przetargów
          </Link>
          <h1 className="app-title mt-2 text-xl font-semibold">
            {tender.number} · {tender.title}
          </h1>
          <p className="app-meta text-xs text-slate-500">
            Zamawiający: {tender.client?.name ?? '—'} · opiekun przetargu: {tender.owner?.name ?? '—'} ·{' '}
            {deadlineLabel ? `termin składania ${deadlineLabel} · ` : ''}
            {tender.notice_number ? `ogłoszenie ${tender.notice_number} · ` : ''}
            <strong>Zakładanie przetargu</strong> · {TENDER_STATUS_LABEL[tender.status] ?? tender.status}
          </p>
        </div>
        <div className="app-actions flex flex-wrap items-center gap-2">
          <button
            type="button"
            onClick={() => {
              setTab('podsumowanie')
              setWizardActive(false)
            }}
            className="rounded px-2 py-1.5 text-[11px] text-blue-700 underline"
          >
            Zamknij kreator i pokaż pełny widok
          </button>
          <ShareToChatButton link={{ type: 'tender', id: tender.id }} />
          {actionsMenu}
        </div>
      </div>
      {msg && <p className="mb-2 rounded bg-green-50 px-3 py-2 text-xs text-green-800">{msg}</p>}
      {err && <p className="mb-2 rounded bg-red-50 px-3 py-2 text-xs text-red-700">{err}</p>}
      <nav aria-label="Kroki zakładania przetargu" className="app-wizard-steps mb-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
        {wizardSteps.map((s, i) => {
          const current = i === wizardStep
          return (
            <button
              key={s.label}
              type="button"
              onClick={() => setWizardStep(i)}
              aria-current={current ? 'step' : undefined}
              data-state={current ? 'now' : s.done ? 'done' : 'todo'}
              className={`app-wizard-step flex items-start gap-2 rounded-xl border bg-white p-3 text-left text-xs ${
                current ? 'border-blue-600 ring-1 ring-blue-600' : 'border-slate-200 hover:bg-slate-50'
              }`}
            >
              <span
                className={`inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-[11px] font-bold ${
                  current ? 'bg-blue-600 text-white' : s.done ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'
                }`}
              >
                {s.done && !current ? '✓' : i + 1}
              </span>
              <span className="min-w-0">
                <span className="block text-[10px] text-slate-500">Krok {i + 1} z 4</span>
                <span className="block font-semibold">{s.label}</span>
                <span className={`block ${s.done ? 'text-emerald-700' : 'text-slate-500'}`}>{s.hint}</span>
              </span>
            </button>
          )
        })}
      </nav>
      {wizardSteps[wizardStep].description && (
        <p className="mb-3 text-xs text-slate-500">{wizardSteps[wizardStep].description}</p>
      )}
      {matchOverlay}
      {wizardStep === 1 && matchReportPanel}
      {wizardStep === 0 && documentsSection}
      {wizardStep === 1 &&
        (tender.items.length > 0 ? (
          itemsSection
        ) : (
          <p className="rounded-xl bg-white p-4 text-xs text-slate-500 shadow-sm">
            Przetarg nie ma jeszcze pozycji. Pozycje zostaną odczytane z dokumentacji przetargu dodanej w kroku 1
            {canImport
              ? '.'
              : ' — dokumenty dodaje osoba z uprawnieniem do dodawania dokumentów (dział przetargów).'}
          </p>
        ))}
      {wizardStep === 2 && conditionsSection}
      {wizardStep === 3 && (
        <div className="grid gap-3 lg:grid-cols-[2fr_1fr]">
          <div className="space-y-3">
            <div className="space-y-3 rounded-xl bg-white p-4 text-xs shadow-sm">
              <h2 className="text-sm font-semibold">Termin i narzut</h2>
              <div className="flex flex-wrap items-end gap-2">
                <label>
                  Termin składania ofert
                  <input
                    type="date"
                    className="mt-1 block rounded border border-slate-300 px-2 py-1"
                    value={deadlineEdit}
                    onChange={(e) => setDeadlineEdit(e.target.value)}
                  />
                </label>
                <label title="Godzina w czasie polskim, do której trzeba złożyć ofertę">
                  Godzina
                  <input
                    type="time"
                    className="mt-1 block rounded border border-slate-300 px-2 py-1 disabled:bg-slate-50"
                    value={deadlineTimeEdit}
                    disabled={!deadlineEdit}
                    onChange={(e) => setDeadlineTimeEdit(e.target.value)}
                  />
                </label>
                <button
                  type="button"
                  disabled={busy || !deadlineDirty}
                  onClick={() => void saveDeadline()}
                  className="rounded bg-slate-700 px-3 py-1.5 text-white disabled:opacity-50"
                >
                  Zapisz termin
                </button>
              </div>
              <div className="flex flex-wrap items-end gap-2">
                <label className="min-w-[240px]">
                  Numer ogłoszenia
                  <input
                    className="mt-1 block w-full rounded border border-slate-300 px-2 py-1"
                    value={noticeEdit}
                    placeholder="np. 2026/BZP 00431178/01 albo 606345-2026"
                    onChange={(e) => setNoticeEdit(e.target.value)}
                  />
                </label>
                <button
                  type="button"
                  disabled={busy || !noticeDirty}
                  onClick={() => void saveNotice()}
                  className="rounded bg-slate-700 px-3 py-1.5 text-white disabled:opacity-50"
                >
                  Zapisz numer
                </button>
              </div>
              <p className="text-slate-500">
                Numer z Biuletynu Zamówień Publicznych pozwala aplikacji samej pobrać wynik przetargu.
              </p>
              <div className="flex flex-wrap items-end gap-2">
                <label>
                  Narzut na cenę zakupu, %
                  <input
                    type="number"
                    min={0}
                    max={500}
                    step={0.1}
                    disabled={busy}
                    className="mt-1 block w-24 rounded border border-slate-300 px-2 py-1"
                    value={marginEdit}
                    onChange={(e) => setMarginEdit(e.target.value)}
                    title={NARZUT_HINT}
                  />
                </label>
                <button
                  type="button"
                  disabled={busy}
                  onClick={() => void saveTargetMargin()}
                  className="rounded bg-violet-700 px-3 py-1.5 text-white disabled:opacity-50"
                  title="Przelicza proporcjonalnie wszystkie ceny w ofercie"
                >
                  Zapisz narzut
                </button>
              </div>
            </div>
            {canInvite && inviteSection}
          </div>
          <div className="space-y-2 rounded-xl bg-white p-4 text-xs shadow-sm">
            <h2 className="text-sm font-semibold">Przed rozpoczęciem wyceny</h2>
            <ul className="space-y-1.5">
              {startChecklist.map(([ok, label]) => (
                <li key={label} className="flex items-start gap-2">
                  <span
                    className={`inline-flex h-4 w-4 shrink-0 items-center justify-center rounded-full text-[10px] font-bold ${
                      ok ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-800'
                    }`}
                  >
                    {ok ? '✓' : '!'}
                  </span>
                  <span className={ok ? 'text-slate-700' : 'text-amber-800'}>{label}</span>
                </li>
              ))}
            </ul>
            <p className="text-slate-500">Braki nie blokują startu — uzupełnisz je w pełnym widoku przetargu.</p>
          </div>
        </div>
      )}
      <div className="mt-4 flex flex-wrap items-center justify-between gap-2">
        {wizardStep > 0 ? (
          <button
            type="button"
            onClick={() => setWizardStep(wizardStep - 1)}
            className="rounded border border-slate-300 bg-white px-4 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50"
          >
            ‹ Wstecz
          </button>
        ) : (
          <span />
        )}
        {wizardStep < 3 ? (
          <button
            type="button"
            onClick={() => setWizardStep(wizardStep + 1)}
            className="rounded bg-blue-600 px-4 py-2 text-xs font-semibold text-white hover:bg-blue-700"
          >
            Dalej: {wizardSteps[wizardStep + 1].label} ›
          </button>
        ) : (
          <span className="flex flex-wrap items-center gap-2">
            <span className="text-[11px] text-slate-500">
              {deadlineDirty
                ? 'Najpierw zapisz zmieniony termin lub godzinę.'
                : !isDraft
                  ? 'Przetarg ma już status Wycena — otworzy się pełny widok.'
                  : canStartPricing
                    ? 'Status zmieni się ze Szkicu na Wycenę.'
                    : 'Status zostaje Szkic — nie masz uprawnienia do zmiany na Wycenę.'}
            </span>
            <button
              type="button"
              disabled={busy || deadlineDirty}
              onClick={() => void finishWizard()}
              className="rounded bg-blue-600 px-4 py-2 text-xs font-semibold text-white hover:bg-blue-700 disabled:opacity-50"
            >
              {isDraft && canStartPricing ? 'Rozpocznij wycenę ›' : 'Zakończ kreator ›'}
            </button>
          </span>
        )}
      </div>
    </>
  )

  const missingRows: Array<{ label: string; action?: string; onClick?: () => void }> = []
  if (tender.items.length === 0) {
    missingRows.push({ label: 'Przetarg nie ma pozycji — dodaj dokumentację przetargu', action: 'Dokumenty', onClick: () => setTab('dokumenty') })
  }
  if (coverage) {
    const coverageRows: Array<[CoverageFilter & string, string]> = [
      ['without_product', 'Pozycje bez produktu'],
      ['without_price', 'Pozycje bez ceny'],
      ['weak_match', 'Słabe dopasowanie'],
      [
        'low_margin',
        coverage.thresholds?.min_margin_percent != null
          ? `Niska marża (poniżej ${coverage.thresholds.min_margin_percent}%)`
          : 'Niska marża',
      ],
    ]
    for (const [key, label] of coverageRows) {
      if (coverage[key] > 0) {
        missingRows.push({ label: `${label}: ${coverage[key]}`, action: 'Pokaż', onClick: () => goToItems(key) })
      }
    }
    if (coverage.substitutes_pending > 0) {
      missingRows.push({
        label: `Zamienniki do zatwierdzenia: ${coverage.substitutes_pending}`,
        action: 'Pokaż',
        onClick: () => setTab('zamienniki'),
      })
    }
  }
  if (conditionsNotMet > 0) {
    missingRows.push({ label: `Warunki, których nie spełniamy: ${conditionsNotMet}`, action: 'Warunki', onClick: () => setTab('warunki') })
  }
  if (conditionsToCheck > 0) {
    missingRows.push({ label: `Warunki do sprawdzenia: ${conditionsToCheck}`, action: 'Warunki', onClick: () => setTab('warunki') })
  }
  if (!savedDeadline) {
    missingRows.push({ label: 'Brak terminu składania — wpisz go wyżej' })
  }
  if (!hasOfferForm) {
    missingRows.push({
      label: 'Brak formularza ofertowego (plik Word) — bez niego nie pobierzesz formularza ofertowego z cenami',
      action: 'Dokumenty',
      onClick: () => setTab('dokumenty'),
    })
  }

  const summarySection = (
    <div className="space-y-3 text-xs">
      {tender.status.startsWith('akceptacja') && next_statuses.length > 0 && (
        <div className="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-amber-200 bg-amber-50 p-3">
          <span className="text-amber-800">
            Przetarg czeka na decyzję: <strong>{TENDER_STATUS_LABEL[tender.status] ?? tender.status}</strong>
          </span>
          <button
            type="button"
            onClick={() => setTab('historia')}
            className="rounded bg-blue-600 px-3 py-1.5 font-semibold text-white hover:bg-blue-700"
          >
            Przejdź do zmiany statusu
          </button>
        </div>
      )}
      {canEditOffer && (isDraft || tender.items.length === 0) && (
        <div className="flex flex-wrap items-center justify-between gap-2 rounded-xl bg-white p-3 shadow-sm">
          <span className="text-slate-600">
            Kreator prowadzi krok po kroku: dokumenty, pozycje, warunki, termin i narzut.
          </span>
          <button
            type="button"
            onClick={() => {
              setForcePulpit(false)
              setWizardActive(true)
            }}
            className="rounded border border-slate-300 bg-white px-3 py-1.5 font-semibold text-slate-700 hover:bg-slate-50"
          >
            Otwórz kreator
          </button>
        </div>
      )}
      <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <div className="rounded-xl bg-white p-3 shadow-sm">
          <div className="text-slate-500">Termin składania</div>
          <div className="mt-1 flex flex-wrap items-center gap-2">
            <strong className="text-base">{deadlineLabel || '—'}</strong>
            {deadlineDays != null && deadlineDays >= 0 && deadlineDays <= 7 && (
              <span className="rounded bg-red-100 px-2 py-0.5 font-medium text-red-700">
                {deadlineDays === 0 ? 'dziś' : deadlineDays === 1 ? 'jutro' : `za ${deadlineDays} dni`}
              </span>
            )}
            {deadlineDays != null && deadlineDays > 7 && <span className="text-slate-500">za {deadlineDays} dni</span>}
            {deadlineDays != null && deadlineDays < 0 && <span className="text-slate-500">po terminie</span>}
          </div>
          {/* zmiana terminu i numeru: te same uprawnienia co PATCH /tenders/{id} na serwerze */}
          {canEditTenderFields ? (
            <>
              <div className="mt-2 flex flex-wrap items-center gap-1">
                <input
                  type="date"
                  aria-label="Termin składania"
                  className="rounded border border-slate-300 px-2 py-1"
                  value={deadlineEdit}
                  onChange={(e) => setDeadlineEdit(e.target.value)}
                />
                <input
                  type="time"
                  aria-label="Godzina składania (czas polski)"
                  title="Godzina w czasie polskim, do której trzeba złożyć ofertę"
                  className="rounded border border-slate-300 px-2 py-1 disabled:bg-slate-50"
                  value={deadlineTimeEdit}
                  disabled={!deadlineEdit}
                  onChange={(e) => setDeadlineTimeEdit(e.target.value)}
                />
                <button
                  type="button"
                  disabled={busy || !deadlineDirty}
                  onClick={() => void saveDeadline()}
                  className="rounded bg-slate-700 px-2 py-1 text-white disabled:opacity-50"
                >
                  Zapisz
                </button>
              </div>
              <div className="mt-2 text-slate-500">Numer ogłoszenia</div>
              <div className="mt-1 flex flex-wrap items-center gap-1">
                <input
                  aria-label="Numer ogłoszenia"
                  className="min-w-0 flex-1 rounded border border-slate-300 px-2 py-1"
                  value={noticeEdit}
                  placeholder="2026/BZP 00431178/01"
                  title="Biuletyn Zamówień Publicznych (np. 2026/BZP 00431178/01) albo Dziennik Urzędowy Unii Europejskiej (TED, np. 606345-2026)"
                  onChange={(e) => setNoticeEdit(e.target.value)}
                />
                <button
                  type="button"
                  disabled={busy || !noticeDirty}
                  onClick={() => void saveNotice()}
                  className="rounded bg-slate-700 px-2 py-1 text-white disabled:opacity-50"
                >
                  Zapisz
                </button>
              </div>
            </>
          ) : (
            <>
              <div className="mt-2 text-slate-500">Numer ogłoszenia</div>
              <div className="mt-1">{tender.notice_number || '—'}</div>
            </>
          )}
        </div>
        <div className="rounded-xl bg-white p-3 shadow-sm">
          <div className="text-slate-500">Pozycje z produktem</div>
          <strong className="mt-1 block text-base">
            {withProduct} z {tender.items.length}
          </strong>
          <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-200">
            <div
              className={`h-full rounded-full ${coverage?.ready ? 'bg-emerald-600' : 'bg-amber-500'}`}
              style={{ width: `${tender.items.length ? Math.round((withProduct / tender.items.length) * 100) : 0}%` }}
            />
          </div>
        </div>
        <div className="rounded-xl bg-white p-3 shadow-sm">
          <div className="text-slate-500">Wartość oferty netto</div>
          <strong className="mt-1 block text-base">
            {tender.offer_value_net ? `${Number(tender.offer_value_net).toLocaleString('pl-PL')} zł` : '—'}
          </strong>
          <div className="mt-1 text-slate-500" title={MATCH_AVERAGE_HINT}>
            Średnia ocena dopasowania: {tender.ai_percent}%
          </div>
        </div>
        <div className="rounded-xl bg-white p-3 shadow-sm">
          <div className="text-slate-500" title={MARGIN_HINT}>
            Marża
          </div>
          <strong className="mt-1 block text-base">
            {tender.margin_percent != null ? `${tender.margin_percent}%` : '—'}
          </strong>
          <div className="mt-2 flex flex-wrap items-center gap-1">
            <label className="flex items-center gap-1 text-slate-500">
              Narzut, %
              <input
                type="number"
                min={0}
                max={500}
                step={0.1}
                disabled={!can_edit || busy}
                className="w-20 rounded border border-slate-300 px-2 py-1 disabled:bg-slate-50"
                value={marginEdit}
                onChange={(e) => setMarginEdit(e.target.value)}
                title={NARZUT_HINT}
              />
            </label>
            {can_edit && (
              <button
                type="button"
                disabled={busy}
                onClick={() => void saveTargetMargin()}
                className="rounded bg-violet-700 px-2 py-1 text-white disabled:opacity-50"
                title="Przelicza proporcjonalnie wszystkie ceny w ofercie"
              >
                Zapisz
              </button>
            )}
          </div>
        </div>
      </div>
      <div className="rounded-xl bg-white p-4 shadow-sm">
        <h2 className="mb-2 text-sm font-semibold">Czego jeszcze brakuje w ofercie</h2>
        {missingRows.length === 0 ? (
          <p className="text-emerald-700">✓ Niczego nie brakuje.</p>
        ) : (
          <ul className="divide-y divide-slate-100">
            {missingRows.map((r) => (
              <li key={r.label} className="flex flex-wrap items-center gap-2 py-2">
                <span className="inline-flex h-4 w-4 shrink-0 items-center justify-center rounded-full bg-amber-50 text-[10px] font-bold text-amber-800">
                  !
                </span>
                <span className="flex-1 text-amber-800">{r.label}</span>
                {r.action && r.onClick && (
                  <button
                    type="button"
                    onClick={r.onClick}
                    className="rounded border border-slate-300 bg-white px-2 py-1 font-semibold text-slate-700 hover:bg-slate-50"
                  >
                    {r.action}
                  </button>
                )}
              </li>
            ))}
          </ul>
        )}
      </div>
      <div className="grid gap-3 lg:grid-cols-2">
        <div className="rounded-xl bg-white p-4 shadow-sm">
          <div className="mb-2 flex items-center justify-between gap-2">
            <h2 className="text-sm font-semibold">Dokumenty</h2>
            <button type="button" onClick={() => setTab('dokumenty')} className="text-blue-700 underline">
              {canImport && can_edit ? 'Dodaj dokument' : 'Pokaż'}
            </button>
          </div>
          {documents.length === 0 ? (
            <p className="text-slate-400">Nie dodano jeszcze dokumentów.</p>
          ) : (
            <ul className="divide-y divide-slate-100">
              {documents.slice(0, 5).map((d) => (
                <li key={d.id} className="flex flex-wrap items-center gap-2 py-1.5">
                  <span className="min-w-0 flex-1 truncate">{d.original_name}</span>
                  <span className="text-slate-500">{new Date(d.created_at).toLocaleDateString('pl-PL')}</span>
                </li>
              ))}
            </ul>
          )}
        </div>
        <div className="rounded-xl bg-white p-4 shadow-sm">
          <div className="mb-2 flex items-center justify-between gap-2">
            <h2 className="text-sm font-semibold">Ostatnia aktywność</h2>
            <button type="button" onClick={() => setTab('historia')} className="text-blue-700 underline">
              Cała historia
            </button>
          </div>
          {activities.length === 0 ? (
            <p className="text-slate-400">Brak zmian.</p>
          ) : (
            <ul className="divide-y divide-slate-100">
              {activities.slice(0, 5).map((a) => (
                <li key={a.id} className="py-1.5">
                  <span className="text-slate-500">
                    {new Date(a.created_at).toLocaleString('pl-PL')} · {activityAuthor(a)}
                  </span>
                  <br />
                  {actionLabel[a.action] ?? a.action}
                  {a.item ? ` (pozycja ${a.item.line_no})` : ''}
                </li>
              ))}
            </ul>
          )}
        </div>
      </div>
    </div>
  )

  const tabBadge: Partial<Record<TenderTab, { count?: number; alert?: number }>> = {
    dokumenty: { count: documents.length },
    warunki: { count: conditions.length, alert: conditionsToCheck + conditionsNotMet },
    pozycje: { count: tender.items.length, alert: attentionCount },
    zamienniki: { alert: coverage?.substitutes_pending ?? 0 },
    wynik: { alert: resultMissing ? 1 : 0 },
    komentarze: { count: comments.length },
    zaproszenia: { count: invitations.length },
    historia: { count: activities.length },
  }

  const pulpitView = (
    <>
      <Link to="/tenders" className="app-back text-xs text-blue-600 hover:underline">
        ← Lista przetargów
      </Link>
      <h1 className="app-title mt-2 text-xl font-semibold">
        {tender.number} · {tender.title}
      </h1>
      <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
        <p className="app-meta text-xs text-slate-500">
          Zamawiający: {tender.client?.name ?? '—'} · opiekun przetargu: {tender.owner?.name ?? '—'} ·{' '}
          {deadlineLabel ? `termin składania ${deadlineLabel} · ` : ''}
          {tender.notice_number ? `ogłoszenie ${tender.notice_number} · ` : ''}
          <strong>{TENDER_STATUS_LABEL[tender.status] ?? tender.status}</strong> ·{' '}
          <span title={MATCH_AVERAGE_HINT}>średnia ocena dopasowania {tender.ai_percent}%</span> · narzut{' '}
          {tender.target_margin_percent ?? 18}% · <span title={MARGIN_HINT}>marża {tender.margin_percent ?? '—'}%</span> ·{' '}
          {can_edit ? 'edycja włączona' : 'tylko podgląd'}
        </p>
        <div className="app-actions flex flex-wrap items-center gap-1">
          {(tender.result_status || resultMissing) && (
            <button
              type="button"
              onClick={() => setTab('wynik')}
              title="Pokaż wynik przetargu"
              className={`rounded border px-2 py-1 text-xs font-medium ${
                tender.result_status ? RESULT_STATUS_CLASS[tender.result_status] : NO_RESULT_CLASS
              }`}
            >
              {tender.result_status ? `Wynik: ${resultStatusLabel(tender.result_status)}` : 'Wynik: nie wpisano'}
            </button>
          )}
          <ActionMenu
            label="Eksport ▾"
            disabled={busy}
            items={[
              { label: 'Oferta w pliku Excel', hint: 'Tabela z cenami do dalszej pracy', onSelect: () => void exportOffer('excel') },
              { label: 'Oferta w pliku PDF', hint: 'Do wydruku albo wysłania', onSelect: () => void exportOffer('pdf') },
              {
                label: 'Formularz ofertowy z cenami (Word)',
                hint: hasOfferForm
                  ? 'Wypełnia cenami z oferty formularz ofertowy dodany w sekcji Dokumenty'
                  : 'Najpierw dodaj formularz ofertowy (plik Word) w sekcji Dokumenty',
                onSelect: () => void exportOffer('docx'),
              },
            ]}
          />
          <ShareToChatButton link={{ type: 'tender', id: tender.id }} />
          {actionsMenu}
        </div>
      </div>
      <StatusFlow status={tender.status} />

      {msg && <p className="mb-2 rounded bg-green-50 px-3 py-2 text-xs text-green-800">{msg}</p>}
      {err && <p className="mb-2 rounded bg-red-50 px-3 py-2 text-xs text-red-700">{err}</p>}
      {matchOverlay}
      {matchReportPanel}

      <div className="flex flex-col gap-3 md:flex-row md:items-start">
        <nav
          aria-label="Sekcje przetargu"
          className="app-tabs app-tabs--side flex flex-wrap gap-1 rounded-xl bg-white p-2 shadow-sm md:w-52 md:shrink-0 md:flex-col md:flex-nowrap"
        >
          {TAB_GROUPS.map((g) => (
            <div key={g.label} className="contents md:block">
              <p className="app-tabs-group hidden px-2 pb-1 pt-2 text-[10px] font-bold uppercase tracking-wide text-slate-400 md:block">
                {g.label}
              </p>
              {g.tabs.map(({ key, label }) => {
                const badge = tabBadge[key]
                const active = tab === key
                return (
                  <button
                    key={key}
                    type="button"
                    onClick={() => setTab(key)}
                    aria-current={active ? 'page' : undefined}
                    className={`app-tab flex items-center gap-1 rounded px-2 py-1.5 text-left text-xs md:w-full ${
                      active ? 'app-tab--active bg-sky-100 font-semibold text-blue-700' : 'text-slate-600 hover:bg-slate-100'
                    }`}
                  >
                    <span className="flex-1">{label}</span>
                    {badge?.count != null && badge.count > 0 && (
                      <span className="app-tab-count text-[11px] text-slate-500">{badge.count}</span>
                    )}
                    {badge?.alert != null && badge.alert > 0 && (
                      <span
                        className="app-tab-alert rounded-full bg-amber-500 px-1.5 text-[10px] font-bold text-white"
                        title="Do zrobienia"
                      >
                        {badge.alert}
                      </span>
                    )}
                  </button>
                )
              })}
            </div>
          ))}
        </nav>

        <div className="min-w-0 flex-1">
          {SECTION_INTRO[tab] && (
            <div className="mb-3">
              <h2 className="text-sm font-semibold">
                {TAB_GROUPS.flatMap((g) => g.tabs).find((t) => t.key === tab)?.label}
              </h2>
              <p className="text-xs text-slate-500">{SECTION_INTRO[tab]}</p>
            </div>
          )}
          {tab === 'podsumowanie' && summarySection}
          {tab === 'pozycje' && itemsSection}
          {tab === 'warunki' && conditionsSection}
          {tab === 'dokumenty' && documentsSection}
          {tab === 'zamienniki' && (
        <div className="space-y-3">
          {tender.items
            .filter((i) => i.main_product)
            .map((item) => {
              const subs = substitutes_by_main[String(item.main_product!.id)] ?? []
              if (subs.length === 0) return null
              return (
                <div key={item.id} className="overflow-hidden rounded-xl border border-slate-200 bg-white">
                  <div className="border-b bg-slate-50 px-4 py-3 text-xs font-semibold">
                    Pozycja {item.line_no} · {productDisplayName(item.main_product!)} (
                    {item.main_product!.sku})
                  </div>
                  <table className="w-full text-left text-xs">
                    <thead>
                      <tr className="border-b bg-slate-50/80">
                        <th className="p-2">Zamiennik</th>
                        <th className="p-2">Typ</th>
                        <th className="p-2">Ocena dopasowania</th>
                        <th className="p-2">Status</th>
                        <th className="p-2">Decyzja</th>
                      </tr>
                    </thead>
                    <tbody>
                      {subs.map((s) => (
                        <tr key={s.id} className="border-b">
                          <td className="p-2">
                            {s.substitute_product?.name} ({s.substitute_product?.sku})
                          </td>
                          <td className="p-2">{SUBSTITUTE_TYPE_LABEL[s.type] ?? s.type}</td>
                          <td className="p-2">{s.match_percent}%</td>
                          <td className="p-2">{SUBSTITUTE_STATUS_LABEL[s.approval_status] ?? s.approval_status}</td>
                          <td className="p-2">
                            {canApproveSub && (
                              <div className="flex gap-1">
                                <button
                                  type="button"
                                  disabled={busy}
                                  className="rounded bg-green-600 px-2 py-1 text-[10px] text-white"
                                  onClick={() => void approveSub(s.id, 'zatwierdzony')}
                                >
                                  Zatwierdź
                                </button>
                                <button
                                  type="button"
                                  disabled={busy}
                                  className="rounded bg-red-600 px-2 py-1 text-[10px] text-white"
                                  onClick={() => void approveSub(s.id, 'odrzucony')}
                                >
                                  Odrzuć
                                </button>
                              </div>
                            )}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )
            })}
        </div>
          )}
          {tab === 'oferta' && (
        <div className="rounded-xl bg-white p-4 shadow-sm">
          <table className="w-full text-left text-xs">
            <thead>
              <tr className="border-b bg-slate-50">
                <th className="p-2">Kod produktu</th>
                <th className="p-2">Cena w ofercie</th>
                <th className="p-2" title={MARGIN_HINT}>
                  Marża
                </th>
                <th className="p-2">Wartość pozycji</th>
              </tr>
            </thead>
            <tbody>
              {tender.items.map((item) => {
                const line =
                  item.offer_price != null ? Number(item.offer_price) * item.quantity : null
                return (
                  <tr key={item.id} className="border-b">
                    <td className="p-2">
                      {isExternalOfferItem(item) ? (
                        <span className="inline-flex flex-col gap-0.5">
                          <span className="w-fit rounded bg-orange-600 px-1 py-px text-[9px] font-bold uppercase text-white">
                            Spoza katalogu
                          </span>
                          <span>{item.custom_name}</span>
                        </span>
                      ) : (
                        (item.main_product?.sku ?? item.custom_name ?? '—')
                      )}
                      <OrderQuantityBadge
                        oq={item.main_product?.order_quantity}
                        qty={item.quantity}
                        block
                        className="mt-0.5"
                      />
                    </td>
                    <td className="p-2">
                      {item.offer_price != null
                        ? `${Number(item.offer_price).toLocaleString('pl-PL', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} zł`
                        : '—'}
                    </td>
                    <td className="p-2">{item.margin_percent != null ? `${item.margin_percent}%` : '—'}</td>
                    <td className="p-2">
                      {line != null ? `${line.toLocaleString('pl-PL')} zł` : '—'}
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
          <p className="mt-3 text-sm">
            Wartość oferty netto:{' '}
            <strong>
              {tender.offer_value_net
                ? `${Number(tender.offer_value_net).toLocaleString('pl-PL')} zł`
                : '—'}
            </strong>{' '}
            · narzut {tender.target_margin_percent ?? 18}% · marża {tender.margin_percent ?? '—'}%
          </p>
        </div>
          )}
          {tab === 'komentarze' && (
        <div className="space-y-3 rounded-xl bg-white p-4 shadow-sm text-xs">
          <div>
            <h2 className="text-sm font-semibold">Komentarze</h2>
            <p className="text-xs text-slate-500">Notatki zespołu o przetargu albo o konkretnej pozycji.</p>
          </div>
          {canComment ? (
            <div className="flex flex-wrap items-end gap-2 border-b border-slate-100 pb-3">
              {/* div zamiast <label>: kliknięcie osoby na liście „@” nie może wracać do pola i otwierać listy znowu */}
              <div className="flex-1 min-w-[200px]">
                <span id="tender-comment-label">Treść komentarza</span>
                <MentionTextarea
                  tenderId={tender.id}
                  value={commentBody}
                  onChange={setCommentBody}
                  mentionedIds={commentMentions}
                  onMentionedChange={setCommentMentions}
                  disabled={busy}
                  placeholder="Wpisz @, żeby powiadomić osobę z zespołu"
                  rows={2}
                  labelledBy="tender-comment-label"
                />
              </div>
              <label>
                Pozycja (opcjonalnie)
                <select
                  className="mt-1 block rounded border border-slate-300 px-2 py-1"
                  value={commentItemId}
                  onChange={(e) => setCommentItemId(e.target.value)}
                >
                  <option value="">Cały przetarg</option>
                  {tender.items.map((it) => (
                    <option key={it.id} value={it.id}>
                      Pozycja {it.line_no}
                    </option>
                  ))}
                </select>
              </label>
              <button
                type="button"
                disabled={busy}
                onClick={() => void addComment()}
                className="rounded bg-blue-600 px-3 py-2 text-white disabled:opacity-50"
              >
                Dodaj
              </button>
            </div>
          ) : (
            <p className="text-slate-400">Nie masz uprawnienia do dodawania komentarzy.</p>
          )}
          <ul className="space-y-2">
            {comments.map((c) => (
              <li key={c.id} className="rounded border border-slate-100 bg-slate-50 p-2">
                <div className="mb-1 text-[11px] text-slate-500">
                  {c.user?.name} · {new Date(c.created_at).toLocaleString('pl-PL')}
                  {c.item ? ` · pozycja ${c.item.line_no}` : ''}
                </div>
                <p className="whitespace-pre-wrap">{c.body}</p>
                {c.mentioned_users && c.mentioned_users.length > 0 && (
                  <p className="mt-1 text-[11px] text-slate-500">
                    Powiadomiono: {c.mentioned_users.map((u) => u.name).join(', ')}
                  </p>
                )}
              </li>
            ))}
            {comments.length === 0 && <li className="text-slate-400">Brak komentarzy.</li>}
          </ul>
        </div>
          )}
          {tab === 'wynik' && (
            <TenderResultSection
              tenderId={tender.id}
              canEdit={can(user, 'tenders.edit_offer')}
              offerValueNet={tender.offer_value_net}
              onChanged={() => {
                void refreshResultStatus()
                void loadMeta()
              }}
              onDirtyChange={onResultDirtyChange}
            />
          )}
          {tab === 'zaproszenia' && inviteSection}
          {tab === 'historia' && (
            <div className="space-y-4">
              {statusSection}
        <div className="rounded-xl bg-white p-4 shadow-sm text-xs">
          <h2 className="mb-3 text-sm font-semibold">Historia zmian</h2>
          <table className="w-full text-left">
            <thead>
              <tr className="border-b bg-slate-50">
                <th className="p-2">Kiedy</th>
                <th className="p-2">Kto</th>
                <th className="p-2">Zmiana</th>
                <th className="p-2">Szczegóły</th>
              </tr>
            </thead>
            <tbody>
              {activities.map((a) => (
                <tr key={a.id} className="border-b align-top">
                  <td className="p-2 whitespace-nowrap">
                    {new Date(a.created_at).toLocaleString('pl-PL')}
                  </td>
                  <td className="p-2">{activityAuthor(a)}</td>
                  <td className="p-2">
                    {actionLabel[a.action] ?? a.action}
                    {a.item ? ` (pozycja ${a.item.line_no})` : ''}
                  </td>
                  <td className="p-2 text-[11px] text-slate-600">
                    {formatActivityMeta(a.meta)}
                  </td>
                </tr>
              ))}
              {activities.length === 0 && (
                <tr>
                  <td colSpan={4} className="p-3 text-slate-400">
                    Brak zmian. Tu pojawią się zmiany cen, produktów i statusu.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
            </div>
          )}
        </div>
      </div>
    </>
  )

  return (
    <div>
      {wizardMode ? wizardView : pulpitView}
      <ProductVerifyModal
        productId={reportPreviewId}
        query={reportPreviewQuery}
        onClose={() => {
          setReportPreviewId(null)
          setReportPreviewQuery('')
        }}
      />
    </div>
  )
}

/** „żółty 42 · ARMEN-9007-1010-42 · zakup 120,00 zł” — kod pomijany, gdy równy etykiecie; cena, gdy jest. */
function variantOptionLabel(v: ProductActiveVariant): string {
  const parts = [v.label]
  const sku = (v.sku ?? '').trim()
  if (sku !== '' && sku !== v.label.trim()) parts.push(sku)
  if (v.purchase_price != null && v.purchase_price !== '') {
    parts.push(`cena zakupu ${formatPrice(v.purchase_price)} ${currencyLabel(v.currency)}`)
  }
  return parts.join(' · ')
}

/**
 * Wariant karty w ofercie (kolor, rozmiar, kod). Lista tylko przy karcie z co najmniej dwoma aktywnymi wariantami;
 * wybór zapisuje się od razu (main_variant_id), a backend przelicza cenę oferty i marżę z ceny wariantu.
 * Bez prawa edycji — sama etykieta wybranego wariantu.
 */
function ItemVariantPicker({
  item,
  canEdit,
  busy,
  onSave,
}: {
  item: Item
  canEdit: boolean
  busy: boolean
  onSave: (id: number, patch: Record<string, unknown>) => Promise<void>
}) {
  const variants = item.main_product?.active_variants ?? []
  const selectedId = item.main_variant_id ?? null
  const savedLabel = [item.main_variant_label, item.main_variant_sku]
    .map((s) => (s ?? '').trim())
    .filter((s, i, all) => s !== '' && (i === 0 || s !== all[0]))
    .join(' · ')
  const autoHint = item.main_variant_source === 'auto' ? 'Wariant wskazany przez dopasowanie z wymagania' : undefined

  if (!canEdit || variants.length < 2) {
    if (savedLabel === '') return null
    return (
      <div className="mt-1 text-[10px] text-slate-500" title={autoHint}>
        Wariant: <span className="text-xs text-slate-800">{savedLabel}</span>
      </div>
    )
  }

  // wybrany wariant zniknął z listy dostawcy — zostaje widoczny zamiast udawać „—”
  const selectedMissing = selectedId != null && !variants.some((v) => v.id === selectedId)
  return (
    <label className="mt-1 flex items-center gap-1 text-[10px] text-slate-500" title={autoHint}>
      Wariant:
      <select
        className="min-w-0 max-w-full flex-1 rounded border border-slate-300 bg-white px-1 py-0.5 text-xs text-slate-800 disabled:opacity-50"
        value={selectedId ?? ''}
        disabled={busy}
        onChange={(e) =>
          void onSave(item.id, { main_variant_id: e.target.value === '' ? null : Number(e.target.value) })
        }
      >
        <option value="">—</option>
        {selectedMissing && (
          <option value={selectedId} disabled>
            {savedLabel || `#${selectedId}`} (niedostępny u dostawcy)
          </option>
        )}
        {variants.map((v) => (
          <option key={v.id} value={v.id}>
            {variantOptionLabel(v)}
          </option>
        ))}
      </select>
    </label>
  )
}

function ItemRow({
  tenderId,
  targetMarginPercent,
  item,
  products,
  canEdit,
  canComment,
  canDelete,
  busy,
  focused,
  changedByAi,
  conflicts,
  comments,
  itemActivities,
  onSave,
  onDelete,
  onDraftChange,
  onComment,
}: {
  tenderId: number
  targetMarginPercent: number
  item: Item
  products: Product[]
  canEdit: boolean
  canComment: boolean
  canDelete: boolean
  busy: boolean
  focused?: boolean
  changedByAi?: boolean
  conflicts?: TenderConflicts['items'][string]
  comments: CommentRow[]
  itemActivities: ActivityRow[]
  onSave: (id: number, patch: Record<string, unknown>) => Promise<void>
  onDelete: (item: Item) => Promise<void>
  onDraftChange: (itemId: number, draft: ItemDraft) => void
  onComment: (itemId: number, body: string) => Promise<void>
}) {
  const [productId, setProductId] = useState<string>(itemProductId(item))
  const [picked, setPicked] = useState<PickedProduct | null>(pickedFromProduct(item.main_product))
  const [companionId, setCompanionId] = useState<string>(
    item.companion_product_id != null
      ? String(item.companion_product_id)
      : item.companion_product
        ? String(item.companion_product.id)
        : '',
  )
  const [companionPicked, setCompanionPicked] = useState<PickedProduct | null>(
    pickedFromProduct(item.companion_product),
  )
  const [qty, setQty] = useState(String(item.quantity))
  const [price, setPrice] = useState(item.offer_price ?? '')
  const [companionPrice, setCompanionPrice] = useState(item.companion_offer_price ?? '')
  const [customName, setCustomName] = useState(item.custom_name ?? '')
  const [customUrl, setCustomUrl] = useState(item.custom_url ?? '')
  const [matchHint, setMatchHint] = useState('')
  const [previewId, setPreviewId] = useState<number | null>(null)
  /** fraza do podświetlenia w karcie po kliknięciu w oknie sprzeczności */
  const [verifyFind, setVerifyFind] = useState('')
  const [conflictsOpen, setConflictsOpen] = useState(false)
  const [aiModalOpen, setAiModalOpen] = useState(false)
  const [aiModalWeb, setAiModalWeb] = useState(false)
  const [aiModalCatalog, setAiModalCatalog] = useState(false)
  const [pendingAiScore, setPendingAiScore] = useState<number | null>(null)
  const [commentText, setCommentText] = useState('')
  const [showComment, setShowComment] = useState(false)
  const [showPriceHistory, setShowPriceHistory] = useState(false)
  const hasChanges = itemActivities.length > 0
  const savedProductId = item.main_product_id ?? item.main_product?.id ?? null
  // Pełne sprawdzenie pobierane dopiero po otwarciu okna sprzeczności.
  const { check: conflictsCheck, error: conflictsError } = useRequirementCheck(
    conflictsOpen ? savedProductId : null,
    item.requirement ?? '',
  )
  // Wynik dotyczy tylko zapisanego produktu — niezapisany wybór w edycji go nie ma.
  const showConflicts =
    conflicts != null &&
    conflicts.count > 0 &&
    savedProductId != null &&
    conflicts.product_id === savedProductId &&
    productId === String(savedProductId)

  useEffect(() => {
    setProductId(itemProductId(item))
    setPicked(pickedFromProduct(item.main_product))
    setCompanionId(
      item.companion_product_id != null
        ? String(item.companion_product_id)
        : item.companion_product
          ? String(item.companion_product.id)
          : '',
    )
    setCompanionPicked(pickedFromProduct(item.companion_product))
    setQty(String(item.quantity))
    setPrice(item.offer_price ?? '')
    setCompanionPrice(item.companion_offer_price ?? '')
    setCustomName(item.custom_name ?? '')
    setCustomUrl(item.custom_url ?? '')
    setPendingAiScore(null)
  }, [item])

  useEffect(() => {
    onDraftChange(item.id, {
      main_product_id: productId ? Number(productId) : null,
      companion_product_id: companionId ? Number(companionId) : null,
      quantity: Number(qty) || 1,
      offer_price: price === '' ? null : Number(String(price).replace(',', '.')),
      companion_offer_price: companionPrice === '' ? null : Number(String(companionPrice).replace(',', '.')),
      custom_name: customName.trim() || null,
      custom_url: customUrl.trim() || null,
    })
  }, [item.id, productId, companionId, qty, price, companionPrice, customName, customUrl, onDraftChange])

  const selectedProduct =
    picked && String(picked.id) === productId
      ? picked
      : item.main_product && String(item.main_product.id) === productId
        ? item.main_product
        : null

  const hasSavedProduct = Boolean(
    selectedProduct || productId || (item.custom_name ?? '').trim(),
  )
  const isExternal = isExternalOfferItem(item, productId)
  const isSubstitute = isBrandSubstituteItem(item)
  // Karta poniżej progu zapisu wpisana jako propozycja (backend: ProductMatchService::PROPOSAL) — nie jest pełnym
  // dopasowaniem, więc mówimy to wprost przy procencie i od razu pokazujemy, czego karta nie potwierdza.
  const isProposal = (item.ai_match_reasons ?? []).some((r) => r.code === 'proposal')
  const markup = offerMarkupFactor(targetMarginPercent)

  function applyCatalogPrice(purchase: string | number | null | undefined): number | null {
    const next = suggestedOfferPrice(purchase == null || purchase === '' ? null : Number(purchase), markup)
    if (next != null) setPrice(next.toFixed(2))
    return next
  }

  function applyCompanionCatalogPrice(purchase: string | number | null | undefined): number | null {
    const next = suggestedOfferPrice(purchase == null || purchase === '' ? null : Number(purchase), markup)
    if (next != null) setCompanionPrice(next.toFixed(2))
    return next
  }

  function catalogPurchase(): number | null {
    const fromList = products.find((p) => String(p.id) === productId)
    // wybrany wariant zapisanej karty ma własną cenę (karta = najniższa z wariantów)
    const variant =
      item.main_variant && item.main_product && String(item.main_product.id) === productId ? item.main_variant : null

    return (
      purchaseForOffer(variant) ??
      purchaseForOffer(fromList) ??
      purchaseForOffer(selectedProduct) ??
      purchaseForOffer(item.main_product)
    )
  }

  const siwz = splitSiwzRequirement(item.requirement)
  const allowCompanion = isDualRequirement(item.requirement) || Boolean(companionId)
  const companionSum =
    (price === '' ? 0 : Number(String(price).replace(',', '.')) || 0) +
    (companionPrice === '' ? 0 : Number(String(companionPrice).replace(',', '.')) || 0)

  function companionFields(): Record<string, unknown> {
    return {
      companion_product_id: companionId ? Number(companionId) : null,
      companion_offer_price: companionPrice === '' ? null : Number(String(companionPrice).replace(',', '.')),
    }
  }

  function applyTenderMarginToOffer(purchase?: string | number | null): void {
    const next = applyCatalogPrice(purchase ?? catalogPurchase())
    const companionNext = companionPicked
      ? applyCompanionCatalogPrice(purchaseForOffer(companionPicked))
      : companionPrice === ''
        ? null
        : Number(String(companionPrice).replace(',', '.'))
    if (next == null) return
    void onSave(item.id, {
      main_product_id: productId ? Number(productId) : null,
      quantity: Number(qty) || 1,
      offer_price: next,
      custom_name: customName.trim() || null,
      custom_url: customUrl.trim() || null,
      companion_product_id: companionId ? Number(companionId) : null,
      companion_offer_price: companionNext,
    })
  }

  return (
    <article
      id={`tender-item-${item.id}`}
      className={`app-item rounded-xl border p-2 ${
        focused
          ? 'border-violet-600 bg-violet-50 ring-2 ring-violet-600'
          : isExternal
            ? 'border-orange-400 bg-orange-50/70'
            : isSubstitute
              ? 'border-teal-300 bg-teal-50/70'
              : changedByAi
                ? 'border-violet-200 bg-violet-50/60'
                : 'border-slate-200 bg-slate-50'
      }`}
    >
      <div className="grid items-stretch gap-2 lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]">
        <SiwzItemTile
          lineNo={item.line_no}
          name={siwz.name}
          description={siwz.description}
          badges={
            <>
              {isExternal && (
                <span className="rounded bg-orange-600 px-1 py-px text-[9px] font-bold uppercase text-white">
                  Spoza katalogu
                </span>
              )}
              {changedByAi && !isExternal && !isSubstitute && (
                <span className="rounded bg-violet-700 px-1 py-px text-[9px] font-bold uppercase text-white">
                  Zmienione przy ostatnim dopasowaniu
                </span>
              )}
              {isSubstitute && !isExternal && (
                <span
                  title="Inna marka lub model niż w wymaganiu, ale spełnia wymaganie."
                  className="rounded bg-teal-700 px-1 py-px text-[9px] font-bold uppercase text-white"
                >
                  Zamiennik
                </span>
              )}
            </>
          }
        />
        <section className="app-item-offer flex min-h-full min-w-0 flex-col rounded-lg border border-sky-200 bg-sky-50/80 p-2">
          <div className="mb-1 text-[10px] font-semibold uppercase tracking-wide text-sky-900">Oferta</div>
        {canEdit ? (
          <div className="flex flex-col gap-1">
            <div className="flex flex-wrap items-start gap-1">
              <ProductSearchSelect
                products={products}
                value={productId}
                selectedProduct={selectedProduct}
                disabled={busy}
                showSelectedCard={false}
                className="min-w-0 flex-1"
                previewQuery={item.requirement ?? ''}
                applyMarginPercent={targetMarginPercent}
                applyMarginDisabled={busy || catalogPurchase() == null}
                onApplyMargin={() => applyTenderMarginToOffer()}
                onChange={(id, product) => {
                  setProductId(id)
                  setPicked(product ?? null)
                  setMatchHint('')
                  setPendingAiScore(null)
                  setCompanionId('')
                  setCompanionPicked(null)
                  setCompanionPrice('')
                  if (id) {
                    setCustomName('')
                    setCustomUrl('')
                    applyCatalogPrice(purchaseForOffer(product))
                  }
                }}
                hint={
                  matchHint ||
                  (item.ai_match_percent != null
                    ? hasSavedProduct
                      ? isProposal
                        ? `Propozycja do sprawdzenia — nie spełnia wszystkich warunków (ocena dopasowania: ${item.ai_match_percent}%), braki w sekcji „Dlaczego ten produkt”`
                        : `Ocena dopasowania: ${item.ai_match_percent}%`
                      : `Ostatnia ocena dopasowania: ${item.ai_match_percent}%`
                    : undefined)
                }
              />
              <button
                type="button"
                title="Szukaj w katalogu po nazwie albo kodzie produktu"
                disabled={busy}
                onClick={() => {
                  setAiModalWeb(false)
                  setAiModalCatalog(true)
                  setAiModalOpen(true)
                }}
                className="shrink-0 rounded bg-sky-600 px-2 py-1 text-[10px] text-white hover:bg-sky-700 disabled:opacity-50"
              >
                Szukaj po nazwie lub kodzie
              </button>
              <button
                type="button"
                title="Znajdzie w katalogu 5 produktów najlepiej pasujących do opisu pozycji"
                disabled={busy}
                onClick={() => {
                  setAiModalWeb(false)
                  setAiModalCatalog(false)
                  setAiModalOpen(true)
                }}
                className="shrink-0 rounded bg-violet-600 px-2 py-1 text-[10px] text-white hover:bg-violet-700 disabled:opacity-50"
              >
                Szukaj w katalogu po opisie
              </button>
              <button
                type="button"
                title="Szukaj produktu w internecie (nie w katalogu)"
                disabled={busy}
                onClick={() => {
                  setAiModalWeb(true)
                  setAiModalCatalog(false)
                  setAiModalOpen(true)
                }}
                className="shrink-0 rounded bg-red-600 px-2 py-1 text-[10px] text-white hover:bg-red-700 disabled:opacity-50"
              >
                Szukaj w internecie
              </button>
            </div>
            {companionPicked && (
              <div className="flex max-w-[280px] items-start justify-between gap-2 rounded border border-slate-200 bg-slate-50 px-2 py-1">
                <span className="min-w-0 text-[10px] text-slate-700">
                  Drugi produkt kompletu: <b>{companionPicked.sku}</b>
                  <span className="mt-0.5 block truncate text-slate-500" title={companionPicked.name}>
                    {companionPicked.name}
                  </span>
                </span>
                <button
                  type="button"
                  disabled={busy}
                  onClick={() => {
                    setCompanionId('')
                    setCompanionPicked(null)
                    setCompanionPrice('')
                    void onSave(item.id, {
                      companion_product_id: null,
                      companion_offer_price: null,
                    })
                  }}
                  className="shrink-0 text-[10px] text-red-700 hover:underline disabled:opacity-50"
                >
                  Usuń
                </button>
              </div>
            )}
            {allowCompanion && !companionPicked && (
              <p className="max-w-[280px] text-[10px] text-slate-500">
                Pozycja wymaga kompletu dwóch produktów — w wyszukiwaniu zaznacz oba i kliknij „Dodaj oba”.
              </p>
            )}
            <ProductAiMatchModal
              open={aiModalOpen}
              initialQuery={item.requirement}
              initialWeb={aiModalWeb}
              initialMode={aiModalCatalog ? 'catalog' : aiModalWeb ? 'web' : 'ai'}
              allowCompanion={allowCompanion}
              hasMainProduct={Boolean(productId)}
              onClose={() => {
                setAiModalOpen(false)
                setAiModalWeb(false)
                setAiModalCatalog(false)
              }}
              onSelect={(p) => {
                const fromCatalog = p.source === 'catalog'
                const nextPrice = applyCatalogPrice(purchaseForOffer(p))
                setProductId(String(p.id))
                setPicked({
                  id: p.id,
                  sku: p.sku,
                  name: p.name,
                  description: p.description,
                  purchase_price: p.purchase_price,
                  purchase_price_pln: p.purchase_price_pln,
                  currency: p.currency,
                })
                setCompanionId('')
                setCompanionPicked(null)
                setCompanionPrice('')
                setMatchHint(
                  fromCatalog
                    ? `Wybrano z katalogu: ${p.sku}`
                    : `Wybrano z wyszukiwania po opisie: ${p.sku} (ocena dopasowania ${p.score}%)`,
                )
                setPendingAiScore(fromCatalog ? null : p.score)
                setCustomName('')
                setCustomUrl('')
                setAiModalOpen(false)
                setAiModalCatalog(false)
                void onSave(item.id, {
                  main_product_id: p.id,
                  companion_product_id: null,
                  companion_offer_price: null,
                  custom_name: null,
                  custom_url: null,
                  quantity: Number(qty) || 1,
                  ...(nextPrice != null ? { offer_price: nextPrice } : {}),
                  ...(fromCatalog
                    ? {
                        ai_match_percent: null,
                        match_source: 'manual',
                        ai_match_reasons: [
                          {
                            code: 'catalog',
                            label: 'Wybór z wyszukiwania po nazwie lub kodzie produktu',
                            points: 100,
                          },
                        ],
                      }
                    : {
                        ai_match_percent: p.score,
                        match_source: 'ai',
                        ai_match_reasons: [
                          {
                            code: 'ai',
                            label: 'Wybór z wyszukiwania w katalogu po opisie',
                            points: p.score,
                          },
                        ],
                      }),
                })
              }}
              onSelectCompanion={(p) => {
                if (!productId || Number(productId) === p.id) return
                const nextCompanion = applyCompanionCatalogPrice(purchaseForOffer(p))
                setCompanionId(String(p.id))
                setCompanionPicked({
                  id: p.id,
                  sku: p.sku,
                  name: p.name,
                  description: p.description,
                  purchase_price: p.purchase_price,
                  purchase_price_pln: p.purchase_price_pln,
                  currency: p.currency,
                })
                setAiModalOpen(false)
                setAiModalCatalog(false)
                void onSave(item.id, {
                  main_product_id: Number(productId),
                  companion_product_id: p.id,
                  companion_offer_price: nextCompanion,
                  quantity: Number(qty) || 1,
                  offer_price: price === '' ? null : Number(String(price).replace(',', '.')),
                })
              }}
              onSelectPair={(main, companion) => {
                const fromCatalog = main.source === 'catalog' && companion.source === 'catalog'
                const nextPrice = applyCatalogPrice(purchaseForOffer(main))
                const nextCompanion = applyCompanionCatalogPrice(purchaseForOffer(companion))
                setProductId(String(main.id))
                setPicked({
                  id: main.id,
                  sku: main.sku,
                  name: main.name,
                  description: main.description,
                  purchase_price: main.purchase_price,
                  purchase_price_pln: main.purchase_price_pln,
                  currency: main.currency,
                })
                setCompanionId(String(companion.id))
                setCompanionPicked({
                  id: companion.id,
                  sku: companion.sku,
                  name: companion.name,
                  description: companion.description,
                  purchase_price: companion.purchase_price,
                  purchase_price_pln: companion.purchase_price_pln,
                  currency: companion.currency,
                })
                setMatchHint(
                  fromCatalog
                    ? `Wybrano z katalogu komplet: ${main.sku} + ${companion.sku}`
                    : `Wybrano z wyszukiwania po opisie komplet: ${main.sku} + ${companion.sku}`,
                )
                setPendingAiScore(fromCatalog ? null : Math.round((main.score + companion.score) / 2))
                setCustomName('')
                setCustomUrl('')
                setAiModalOpen(false)
                setAiModalCatalog(false)
                void onSave(item.id, {
                  main_product_id: main.id,
                  companion_product_id: companion.id,
                  companion_offer_price: nextCompanion,
                  custom_name: null,
                  custom_url: null,
                  quantity: Number(qty) || 1,
                  ...(nextPrice != null ? { offer_price: nextPrice } : {}),
                  ...(fromCatalog
                    ? {
                        ai_match_percent: null,
                        match_source: 'manual',
                        ai_match_reasons: [
                          {
                            code: 'catalog',
                            label: 'Wybór kompletu z wyszukiwania po nazwie lub kodzie produktu',
                            points: 100,
                          },
                        ],
                      }
                    : {
                        ai_match_percent: Math.round((main.score + companion.score) / 2),
                        match_source: 'ai',
                        ai_match_reasons: [
                          {
                            code: 'ai',
                            label: `Komplet z wyszukiwania w katalogu po opisie: ${main.sku} + ${companion.sku}`,
                            points: Math.round((main.score + companion.score) / 2),
                          },
                        ],
                      }),
                })
              }}
              onAddExternal={(hint) => {
                setCustomName(hint.title)
                setCustomUrl(hint.url)
                setProductId('')
                setPicked(null)
                setCompanionId('')
                setCompanionPicked(null)
                setCompanionPrice('')
                setPendingAiScore(null)
                setAiModalOpen(false)
                setAiModalCatalog(false)
                void onSave(item.id, {
                  main_product_id: null,
                  companion_product_id: null,
                  companion_offer_price: null,
                  custom_name: hint.title,
                  custom_url: hint.url,
                  quantity: Number(qty) || 1,
                  offer_price: price === '' ? null : Number(String(price).replace(',', '.')),
                  match_source: 'custom',
                  status: 'matched',
                })
              }}
            />
            {isExternal && (customName || item.custom_name) && (
              <ExternalOfferBanner
                name={customName || item.custom_name || ''}
                url={customUrl || item.custom_url}
              />
            )}
            {!isExternal &&
              (item.ai_match_reasons ?? []).some(
                (r) => r.code === 'external_link' || r.code === 'custom_offer',
              ) && (
                <ExternalHints
                  reasons={item.ai_match_reasons}
                  onAddToOffer={(hint) => {
                    setCustomName(hint.title)
                    setCustomUrl(hint.url)
                    setProductId('')
                    setPicked(null)
                    setCompanionId('')
                    setCompanionPicked(null)
                    setCompanionPrice('')
                    void onSave(item.id, {
                      main_product_id: null,
                      companion_product_id: null,
                      companion_offer_price: null,
                      custom_name: hint.title,
                      custom_url: hint.url,
                      quantity: Number(qty) || 1,
                      offer_price: price === '' ? null : Number(String(price).replace(',', '.')),
                      match_source: 'custom',
                      status: 'matched',
                    })
                  }}
                />
              )}
          </div>
        ) : isExternal && !item.main_product ? (
          <ExternalOfferBanner name={item.custom_name || customName || ''} url={item.custom_url || customUrl} />
        ) : !item.main_product ? (
          <ExternalHints reasons={item.ai_match_reasons} />
        ) : null}

        {(canEdit || selectedProduct || item.main_product || isExternal) && (
          <div className="app-item-product mt-2 flex min-w-0 overflow-hidden rounded-lg border border-slate-200 bg-white">
            <button
              type="button"
              className="shrink-0 self-stretch bg-slate-50 p-2"
              title={selectedProduct || item.main_product ? 'Pokaż kartę produktu' : undefined}
              onClick={() => {
                const id = selectedProduct?.id ?? item.main_product?.id
                if (id) setPreviewId(id)
              }}
            >
              {productThumbUrl(selectedProduct ?? item.main_product) ? (
                <img
                  src={productThumbUrl(selectedProduct ?? item.main_product) ?? ''}
                  alt=""
                  className="h-28 w-28 rounded border border-slate-200 bg-white object-contain"
                />
              ) : (
                <div className="flex h-28 w-28 items-center justify-center rounded border border-dashed border-slate-200 text-[10px] text-slate-400">
                  Brak zdjęcia
                </div>
              )}
            </button>
            <div className="min-w-0 flex-1 px-3 py-2">
              <p className="text-sm font-semibold leading-snug text-slate-900">
                {selectedProduct
                  ? `${selectedProduct.sku} ${productDisplayName(selectedProduct)}`
                  : item.main_product
                    ? `${item.main_product.sku} ${productDisplayName(item.main_product)}`
                    : customName || item.custom_name || 'Produkt spoza katalogu'}
              </p>
              <div className="mt-1.5 flex flex-wrap items-center gap-1.5">
                {hasSavedProduct && !isExternal && (
                  <span className="rounded bg-emerald-700 px-1.5 py-0.5 text-[9px] font-semibold text-white">
                    Wybrane w ofercie
                  </span>
                )}
                {showConflicts && (
                  <button
                    type="button"
                    className="rounded border border-rose-300 bg-rose-50 px-1.5 py-0.5 text-[9px] font-semibold text-rose-800 hover:bg-rose-100"
                    title={`${conflicts.requirement.length} niespełnionych wymagań · ${conflicts.card_fields.length} sprzecznych pól karty produktu — kliknij, żeby zobaczyć szczegóły`}
                    onClick={() => setConflictsOpen(true)}
                  >
                    ⚠ {conflictsLabel(conflicts.requirement.length, conflicts.card_fields.length)}
                  </button>
                )}
                {canEdit && catalogPurchase() != null && (
                  <button
                    type="button"
                    disabled={busy}
                    className="rounded border border-emerald-600 bg-white px-1.5 py-0.5 text-[9px] font-semibold text-emerald-800 hover:bg-emerald-50 disabled:opacity-40"
                    title={`Ustaw cenę w ofercie: cena zakupu plus narzut przetargu ${targetMarginPercent}%`}
                    onClick={() => applyTenderMarginToOffer()}
                  >
                    Dolicz narzut {targetMarginPercent}%
                  </button>
                )}
              </div>
              <div className="mt-2 flex flex-wrap items-end gap-3">
                <label className="text-[10px] text-slate-500">
                  Ilość
                  {canEdit ? (
                    <input
                      className="mt-0.5 block w-14 rounded border border-slate-300 bg-white px-1.5 py-1 text-xs"
                      value={qty}
                      onChange={(e) => setQty(e.target.value)}
                    />
                  ) : (
                    <span className="mt-0.5 block text-xs text-slate-800">{item.quantity}</span>
                  )}
                </label>
                <div>
                  <div className="text-[10px] text-slate-500">Cena netto w ofercie (zł)</div>
                  <button
                    type="button"
                    title={hasChanges ? 'Kliknij, żeby zobaczyć historię zmian ceny' : 'Cena netto w ofercie'}
                    onClick={() => setShowPriceHistory((v) => !v)}
                    className={`mt-0.5 flex min-w-[5.5rem] items-center gap-1 rounded border px-1.5 py-1 text-left text-xs ${
                      hasChanges
                        ? 'border-amber-400 bg-amber-50 text-amber-950 hover:bg-amber-100'
                        : 'border-slate-300 bg-white'
                    }`}
                  >
                    {canEdit ? (
                      <input
                        className="w-20 border-0 bg-transparent p-0 outline-none"
                        value={price}
                        title={companionPicked ? 'Cena pierwszego produktu kompletu' : 'Cena netto w ofercie'}
                        onClick={(e) => e.stopPropagation()}
                        onChange={(e) => setPrice(e.target.value)}
                      />
                    ) : (
                      <span>{item.offer_price ?? '—'}</span>
                    )}
                    {hasChanges && (
                      <span className="ml-auto text-[10px] font-semibold text-amber-700">●</span>
                    )}
                  </button>
                </div>
                <div
                  className={`${
                    item.margin_percent != null && Number(item.margin_percent) < 0
                      ? 'font-semibold text-red-700'
                      : ''
                  }`}
                  title={
                    item.margin_percent != null && Number(item.margin_percent) < 0
                      ? `Ujemna marża — cena w ofercie jest niższa niż cena zakupu (po upuście). Narzut przetargu: ${targetMarginPercent}%.`
                      : MARGIN_HINT
                  }
                >
                  <div className="text-[10px] font-normal text-slate-500">Marża</div>
                  <div className="mt-0.5 text-xs text-slate-800">
                    {item.margin_percent ?? '—'}%
                    {item.margin_percent != null && Number(item.margin_percent) < 0 ? (
                      <span className="ml-1 text-[9px] font-normal">ujemna!</span>
                    ) : null}
                  </div>
                </div>
              </div>
              {/* Tańsze źródło liczy backend dla zapisanej karty — przy niezapisanym wyborze w edycji go nie ma. */}
              {item.main_product != null && productId === String(item.main_product.id) && (
                <CheaperSourceNote cheaper={item.main_product.cheaper_source} className="mt-1" />
              )}
              {/* Warunek zamawiania też liczy backend dla zapisanej karty; ilości pozycji nie zmienia, tylko podpowiada.
                  Ilość jak w szkicu zapisu (Number(qty) || 1), żeby podpowiedź zgadzała się z tym, co pójdzie do zapisu. */}
              {item.main_product != null && productId === String(item.main_product.id) && (
                <OrderQuantityBadge
                  oq={item.main_product.order_quantity}
                  qty={canEdit ? Number(qty) || 1 : item.quantity}
                  block
                  className="mt-1"
                />
              )}
              {/* Wariant dotyczy zapisanej karty — przy niezapisanym wyborze innej karty w edycji go nie ma. */}
              {item.main_product != null && productId === String(item.main_product.id) && (
                <ItemVariantPicker item={item} canEdit={canEdit} busy={busy} onSave={onSave} />
              )}
              {companionPicked && (
                <div className="mt-1 text-[10px] text-slate-500">
                  Drugi produkt kompletu: {canEdit ? (
                    <input
                      className="ml-1 w-16 rounded border border-slate-300 px-1 py-0.5 text-xs"
                      value={companionPrice}
                      title="Cena drugiego produktu kompletu"
                      onChange={(e) => setCompanionPrice(e.target.value)}
                    />
                  ) : (
                    <span className="ml-1 text-xs">{item.companion_offer_price ?? '—'}</span>
                  )}
                  <span className="ml-2">
                    Razem: {Number.isFinite(companionSum) ? companionSum.toFixed(2) : '—'}
                  </span>
                </div>
              )}
              {showPriceHistory && (
                <div className="mt-2 rounded-lg border border-amber-200 bg-amber-50/90 p-2 shadow-sm">
                  <div className="mb-1 flex items-center justify-between gap-2">
                    <strong className="text-[11px]">Historia ceny</strong>
                    <button
                      type="button"
                      className="text-[10px] text-slate-500 hover:underline"
                      onClick={() => setShowPriceHistory(false)}
                    >
                      zamknij
                    </button>
                  </div>
                  {itemActivities.length === 0 ? (
                    <p className="text-[11px] text-slate-400">Brak zapisanych zmian.</p>
                  ) : (
                    <ul className="max-h-40 space-y-1 overflow-y-auto text-[11px]">
                      {itemActivities.map((a) => (
                        <li key={a.id} className="rounded border border-amber-100 bg-white px-2 py-1">
                          <div className="text-slate-500">
                            {new Date(a.created_at).toLocaleString('pl-PL')}
                            {a.user?.name ? ` · ${a.user.name}` : ''}
                          </div>
                          <div>{formatActivityMeta(a.meta)}</div>
                        </li>
                      ))}
                    </ul>
                  )}
                </div>
              )}
            </div>
            <div className="flex shrink-0 flex-col justify-center gap-1.5 border-l border-slate-200 px-2 py-2">
              {canEdit && (
                <button
                  type="button"
                  disabled={busy}
                  className="rounded bg-blue-600 px-3 py-1.5 text-[10px] text-white disabled:opacity-50"
                  onClick={() =>
                    void onSave(item.id, {
                      main_product_id: productId ? Number(productId) : null,
                      quantity: Number(qty) || 1,
                      offer_price: price === '' ? null : Number(price.replace(',', '.')),
                      custom_name: customName.trim() || null,
                      custom_url: customUrl.trim() || null,
                      ...companionFields(),
                      ...(pendingAiScore != null
                        ? {
                            ai_match_percent: pendingAiScore,
                            match_source: 'ai',
                            ai_match_reasons: [
                              {
                                code: 'ai',
                                label: 'Wybór z wyszukiwania w katalogu po opisie',
                                points: pendingAiScore,
                              },
                            ],
                          }
                        : {}),
                    })
                  }
                >
                  Zapisz
                </button>
              )}
              {canComment && (
                <button
                  type="button"
                  className="rounded border border-slate-300 px-3 py-1.5 text-[10px] text-slate-700 hover:bg-slate-50"
                  onClick={() => setShowComment((v) => !v)}
                >
                  Komentarz{comments.length > 0 ? ` (${comments.length})` : ''}
                </button>
              )}
              {canDelete && (
                <button
                  type="button"
                  disabled={busy}
                  className="rounded bg-red-700 px-3 py-1.5 text-[10px] font-semibold text-white hover:bg-red-800 disabled:opacity-50"
                  onClick={() => void onDelete(item)}
                >
                  Usuń
                </button>
              )}
            </div>
          </div>
        )}

        {canEdit && (
          <>
            <details className="mt-2 rounded border border-amber-200 bg-amber-50/70 px-2 py-1">
              <summary className="cursor-pointer text-[10px] font-semibold text-amber-950">
                Wpisz produkt ręcznie{customName ? `: ${customName}` : ''}
              </summary>
              <div className="mt-1 space-y-1">
                <input
                  className="w-full rounded border border-amber-200 px-1.5 py-1 text-[11px]"
                  placeholder="Nazwa produktu do oferty"
                  disabled={busy}
                  value={customName}
                  onChange={(e) => setCustomName(e.target.value)}
                />
                <input
                  className="w-full rounded border border-amber-200 px-1.5 py-1 text-[11px]"
                  placeholder="Link do produktu (opcjonalnie)"
                  disabled={busy}
                  value={customUrl}
                  onChange={(e) => setCustomUrl(e.target.value)}
                />
                <p className="text-[10px] text-amber-900">Cenę wpisz w polu „Cena netto w ofercie (zł)” i kliknij „Zapisz”.</p>
              </div>
            </details>
            {hasSavedProduct && !isExternal && (
              <details
                open={isProposal}
                className="mt-1 rounded border border-violet-200 bg-violet-50 px-2 py-1 text-[10px] text-violet-900"
              >
                <summary className="cursor-pointer font-semibold">
                  Dlaczego ten produkt
                  {item.ai_match_percent != null ? ` (ocena dopasowania ${item.ai_match_percent}%)` : ''}
                </summary>
                {(item.ai_match_reasons?.length ?? 0) > 0 ? (
                  <ul className="mt-1 list-disc pl-4">
                    {item.ai_match_reasons!.map((r, i) => (
                      <li key={`${r.code}-${i}`}>
                        {r.code === 'external_link' || r.code === 'custom_offer' ? (
                          <ExternalHintLink reason={r} />
                        ) : (
                          <span title={r.points > 0 ? `+${r.points} punktów do oceny` : undefined}>{r.label}</span>
                        )}
                      </li>
                    ))}
                  </ul>
                ) : (
                  <p className="mt-1 text-slate-500">Brak zapisanych powodów — odśwież stronę.</p>
                )}
              </details>
            )}
          </>
        )}
        <ProductVerifyModal
          productId={previewId}
          query={item.requirement ?? ''}
          initialFind={verifyFind || undefined}
          onClose={() => {
            setPreviewId(null)
            setVerifyFind('')
          }}
        />
        {savedProductId != null && (
          <CardConflictsModal
            open={conflictsOpen}
            onClose={() => setConflictsOpen(false)}
            productId={savedProductId}
            productName={
              item.main_product ? `${item.main_product.sku} ${productDisplayName(item.main_product)}` : undefined
            }
            check={conflictsCheck}
            loading={conflictsCheck == null && !conflictsError}
            onOpenVerify={() => {
              setConflictsOpen(false)
              setVerifyFind('')
              setPreviewId(savedProductId)
            }}
            onFind={(phrase) => {
              setConflictsOpen(false)
              setVerifyFind(phrase)
              setPreviewId(savedProductId)
            }}
          />
        )}
        </section>
      </div>
    {(item.main_product_id ?? item.main_product?.id) ? (
      <div className="app-item-subs mt-2">
          <ItemBattlecard
            tenderId={tenderId}
            itemId={item.id}
            refreshKey={item.updated_at ?? ''}
            markupPercent={targetMarginPercent}
            enabled
            canSelectSubstitute={canEdit}
            selectedProductId={item.main_product_id ?? item.main_product?.id ?? null}
            onApplySelectedOffer={
              canEdit
                ? (p: BattlecardProduct) => {
                    applyTenderMarginToOffer(p.purchase_price)
                  }
                : undefined
            }
            onSelectSubstitute={
              canEdit
                ? (p: BattlecardProduct) => {
                    applyCatalogPrice(p.purchase_price)
                    // wynik ze wspólnych słów to nie ocena dopasowania — nie zapisujemy go jako „Dopasowanie AI”
                    const percent = p.match_basis === 'words' ? null : p.match_percent
                    return onSave(item.id, {
                      main_product_id: p.product_id,
                      quantity: Number(qty) || 1,
                      ai_match_percent: percent,
                      match_source: 'battlecard',
                      ai_match_reasons: [
                        {
                          code: 'battlecard',
                          label: `Wybrano ${p.sku} z porównania zamienników`,
                          points: percent ?? 0,
                        },
                      ],
                    })
                  }
                : undefined
            }
          />
      </div>
    ) : null}
    {canComment && showComment && (
      <div className="mt-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-[11px]">
          {comments.length > 0 && (
            <ul className="mb-2 space-y-1">
              {comments.slice(0, 5).map((c) => (
                <li key={c.id} className="rounded border border-slate-200 bg-white px-2 py-1">
                  <span className="text-slate-500">
                    {c.user?.name} · {new Date(c.created_at).toLocaleString('pl-PL')}:{' '}
                  </span>
                  {c.body}
                </li>
              ))}
            </ul>
          )}
          <div className="flex flex-wrap items-end gap-2">
            <textarea
              className="min-w-[220px] flex-1 rounded border border-slate-300 px-2 py-1"
              rows={2}
              placeholder="Komentarz do tej pozycji…"
              value={commentText}
              onChange={(e) => setCommentText(e.target.value)}
            />
            <button
              type="button"
              disabled={busy || commentText.trim().length < 2}
              className="rounded bg-slate-800 px-3 py-1.5 text-white disabled:opacity-50"
              onClick={() => {
                const body = commentText.trim()
                if (body.length < 2) return
                void onComment(item.id, body).then(() => {
                  setCommentText('')
                  setShowComment(false)
                })
              }}
            >
              Dodaj komentarz
            </button>
          </div>
      </div>
    )}
    </article>
  )
}
