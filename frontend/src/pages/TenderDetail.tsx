import { useCallback, useEffect, useRef, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useAuth } from '../auth'
import { ItemBattlecard, type BattlecardProduct } from '../components/ItemBattlecard'
import { ProductAiMatchModal } from '../components/ProductAiMatchModal'
import { ProductPreviewModal } from '../components/ProductPreviewModal'
import { ProductSearchSelect } from '../components/ProductSearchSelect'
import { api, downloadFile, type Product, type Substitute, type Tender } from '../lib/api'
import { productDisplayName } from '../lib/productLabel'

type MatchReason = { code: string; label: string; points: number; url?: string }

function ExternalHintLink({ reason }: { reason: MatchReason }) {
  const href = reason.url
  if (!href) {
    return <span>{reason.label}</span>
  }
  const badge =
    reason.code === 'custom_offer' ? 'Własna propozycja — nie z katalogu' : 'Link zewnętrzny — nie z katalogu'
  const title = reason.label
    .replace(/^Link zewnętrzny \(nie z katalogu SUPON\):\s*/u, '')
    .replace(/^Własna propozycja \(nie z katalogu SUPON\):\s*/u, '')
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
                  title: r.label.replace(/^Link zewnętrzny \(nie z katalogu SUPON\):\s*/u, ''),
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
  margin_percent: string | null
  status: string
  main_product: Product | null
  main_product_id?: number | null
  custom_name?: string | null
  custom_url?: string | null
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

function itemProductId(item: Item): string {
  const id = item.main_product_id ?? item.main_product?.id
  return id != null ? String(id) : ''
}

type ItemDraft = {
  main_product_id: number | null
  quantity: number
  offer_price: number | null
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

type Condition = {
  id: number
  category: string | null
  content: string
  sort_order: number
  source: string
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

const tabs = [
  'pozycje',
  'warunki',
  'dokumenty',
  'zamienniki',
  'oferta',
  'komentarze',
  'zaproszenia',
  'historia',
  'workflow',
] as const

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
  avg_score: number
  changes: MatchChange[]
  at: string
}

type MatchProgress = {
  status: string
  done: number
  total: number
  line_no: number | null
  requirement: string | null
  started_at: number | null
}

function matchReportStorageKey(tenderId: string): string {
  return `tender-match-report-${tenderId}`
}

const MATCH_BATCH_SIZE = 1
const MATCH_CONCURRENCY_DEFAULT = 4
const MATCH_CONCURRENCY_MAX = 8

function clampMatchConcurrency(value: number | undefined): number {
  const n = Number(value)
  if (!Number.isFinite(n)) {
    return MATCH_CONCURRENCY_DEFAULT
  }
  return Math.max(1, Math.min(MATCH_CONCURRENCY_MAX, Math.round(n)))
}

async function mapPool<T>(
  items: T[],
  concurrency: number,
  worker: (item: T) => Promise<void>,
): Promise<void> {
  let next = 0
  const run = async () => {
    while (next < items.length) {
      const i = next
      next += 1
      await worker(items[i])
    }
  }
  await Promise.all(Array.from({ length: Math.min(concurrency, items.length) }, () => run()))
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
        return true
      }
      return i.ai_match_percent < minScore
    })
    .map((i) => i.id)
}

function formatMatchEta(seconds: number): string {
  if (seconds < 60) {
    return `ok. ${seconds} s`
  }
  return `ok. ${Math.ceil(seconds / 60)} min`
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

const statusLabel: Record<string, string> = {
  draft: 'Szkic',
  wycena: 'Wycena',
  akceptacja_km: 'Akceptacja kierownika',
  akceptacja_dyrektor: 'Akceptacja dyrektora',
  zatwierdzona: 'Zatwierdzona',
  exported: 'Wyeksportowana',
  odrzucony: 'Odrzucony',
  archiwum: 'Archiwum',
}

const actionLabel: Record<string, string> = {
  created: 'Utworzono przetarg',
  updated: 'Zmieniono dane przetargu',
  status_changed: 'Zmiana statusu',
  item_updated: 'Zmiana pozycji',
  item_bulk_updated: 'Zapis zbiorczy pozycji',
  comment_added: 'Dodano komentarz',
  invitation_added: 'Zaproszono użytkownika',
  invitation_removed: 'Usunięto zaproszenie',
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
  const before = meta.before as Record<string, unknown> | undefined
  const after = meta.after as Record<string, unknown> | undefined
  if (before && after) {
    const parts: string[] = []
    for (const key of ['offer_price', 'quantity', 'main_product_id', 'ai_match_percent'] as const) {
      if (!sameVal(before[key], after[key])) {
        const labels: Record<string, string> = {
          offer_price: 'cena',
          quantity: 'ilość',
          main_product_id: 'produkt',
          ai_match_percent: 'AI %',
        }
        parts.push(`${labels[key] ?? key}: ${String(before[key] ?? '—')} → ${String(after[key] ?? '—')}`)
      }
    }
    return parts.length > 0 ? parts.join('; ') : 'zapis bez zmiany wartości'
  }
  if (typeof meta.from === 'string' && typeof meta.to === 'string') {
    return `${meta.from} → ${meta.to}${meta.note ? ` (${String(meta.note)})` : ''}`
  }
  if (typeof meta.user_name === 'string') {
    return `${meta.user_name}${meta.user_email ? ` <${String(meta.user_email)}>` : ''}`
  }
  return '—'
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

export function TenderDetail() {
  const { id } = useParams()
  const { user } = useAuth()
  const [data, setData] = useState<Detail | null>(null)
  const [tab, setTab] = useState<(typeof tabs)[number]>('pozycje')
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
  const [docStatus, setDocStatus] = useState('')
  const itemDraftsRef = useRef<Map<number, ItemDraft>>(new Map())
  const [coverageFilter, setCoverageFilter] = useState<CoverageFilter>(null)
  const [itemQuery, setItemQuery] = useState('')
  const [matchBusy, setMatchBusy] = useState(false)
  const [matchElapsed, setMatchElapsed] = useState(0)
  const [matchProgress, setMatchProgress] = useState<MatchProgress | null>(null)
  const matchStartedAtRef = useRef(0)
  const matchDoneRef = useRef(0)
  const [matchReport, setMatchReport] = useState<MatchReport | null>(null)
  const [showAiChanges, setShowAiChanges] = useState(false)
  const [transitionNote, setTransitionNote] = useState('')
  const [activities, setActivities] = useState<ActivityRow[]>([])
  const [comments, setComments] = useState<CommentRow[]>([])
  const [commentBody, setCommentBody] = useState('')
  const [commentItemId, setCommentItemId] = useState('')
  const [deadlineEdit, setDeadlineEdit] = useState('')
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

  const load = useCallback(async () => {
    const d = await api<Detail>(`/tenders/${id}`)
    setData(d)
    setDeadlineEdit(d.tender.deadline ? d.tender.deadline.slice(0, 10) : '')
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
      setErr(e instanceof Error ? e.message : 'Nie udało się wczytać historii/komentarzy')
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
    if (!matchBusy || !id) {
      return
    }
    let cancelled = false
    const started = matchStartedAtRef.current
    const pull = async () => {
      try {
        const p = await api<MatchProgress>(`/tenders/${id}/match/progress`)
        if (cancelled) {
          return
        }
        if (p.started_at != null && p.started_at < started - 5) {
          return
        }
        setMatchProgress((prev) => ({
          status: 'running',
          done: matchDoneRef.current,
          total: prev?.total ?? p.total,
          line_no: p.line_no,
          requirement: p.requirement,
          started_at: started,
        }))
      } catch {
        /* postęp jest pomocniczy */
      }
    }
    void pull()
    const poll = window.setInterval(() => void pull(), 1000)
    const refresh = window.setInterval(() => {
      void load()
    }, 8000)
    return () => {
      cancelled = true
      window.clearInterval(poll)
      window.clearInterval(refresh)
    }
  }, [matchBusy, id, load])

  useEffect(() => {
    if (tab === 'zaproszenia') void loadDirectory(inviteQ)
  }, [tab, inviteQ, loadDirectory])

  const registerItemDraft = useCallback((itemId: number, draft: ItemDraft) => {
    itemDraftsRef.current.set(itemId, draft)
  }, [])

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
      setMsg('Zapisano pozycję — wpis w zakładce Historia.')
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
      setMsg(`Zapisano całość: ${res.updated} pozycji — zobacz zakładkę Historia.`)
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
        setMsg('Brak tańszych zamienników (≥3% po upuście) na pozycjach.')
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
      docMode === 'simple' ? 'odczyt tekstu' : docMode === 'ai' ? 'analiza AI' : 'analiza AI + archiwum'
    setDocStatus(`Wybrano: ${file.name} — trwa ${modeLabel}…`)
    try {
      const targets: string[] = []
      if (docTargets.items) targets.push('items')
      if (docTargets.conditions) targets.push('conditions')
      if (targets.length === 0) throw new Error('Zaznacz pozycje i/lub warunki.')
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
        `Gotowe: ${file.name} — ${res.items_count} pozycji, ${res.conditions_count} warunków. Sprawdź numer/nazwę/cenę i zaciągnij.`,
      )
      setMsg('Podgląd gotowy — nic nie trafiło jeszcze do pozycji. Zatwierdź poniżej.')
      // nie przeładowuj listy w trakcie podglądu (archiwum tylko w trybie pełnym)
      if (docMode === 'full') await load()
      requestAnimationFrame(() => {
        document.getElementById('doc-preview')?.scrollIntoView({ behavior: 'smooth', block: 'start' })
      })
    } catch (e) {
      setDocStatus(`Błąd przy pliku ${file.name}.`)
      setErr(e instanceof Error ? e.message : 'Błąd analizy dokumentu')
    } finally {
      setBusy(false)
    }
  }

  async function openDocumentPreview(docId: number) {
    setErr('')
    setBusy(true)
    setDocStatus('Ładowanie podglądu z archiwum…')
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
        setDocStatus('Brak zapisanej analizy — użyj „Analizuj ponownie”.')
        return
      }
      setDocPreview({
        document_id: res.id,
        extracted_text: res.extracted_text ?? '',
        items,
        conditions,
      })
      setDocStatus(
        `Podgląd z archiwum: ${items.length} pozycji, ${conditions.length} warunków — zatwierdź, aby zaciągnąć.`,
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
          `Zaciągnięto do przetargu: ${res.items_created} pozycji, ${res.conditions_created} warunków.`,
        )
        setMsg(`Zaciągnięto: ${res.items_created} pozycji, ${res.conditions_created} warunków.`)
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
        `Zaciągnięto do przetargu: ${res.items_created} pozycji, ${res.conditions_created} warunków.`,
      )
      setMsg(`Zaciągnięto: ${res.items_created} pozycji, ${res.conditions_created} warunków.`)
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
      setMsg('Ponowna analiza gotowa — zatwierdź zaznaczone.')
    } catch (e) {
      setErr(e instanceof Error ? e.message : 'Błąd ponownej analizy')
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

  async function addCondition() {
    const content = newCondition.trim()
    if (!content) return
    setBusy(true)
    try {
      await api(`/tenders/${id}/conditions`, {
        method: 'POST',
        body: JSON.stringify({ content }),
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

  async function transition(status: string) {
    setErr('')
    setMsg('')
    const needsNote = status === 'odrzucony' || status === 'wycena'
    if (needsNote && data?.tender.status.startsWith('akceptacja') && transitionNote.trim().length < 5) {
      setErr('Wymagana notatka (min. 5 znaków) przy odrzuceniu lub cofnięciu z akceptacji.')
      return
    }
    if (status === 'odrzucony' && transitionNote.trim().length < 5) {
      setErr('Wymagana notatka (min. 5 znaków) przy odrzuceniu.')
      return
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
      setMsg(`Status: ${statusLabel[status] ?? status}`)
    } catch (e) {
      setErr(e instanceof Error ? e.message : 'Błąd statusu')
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
        body: JSON.stringify({ deadline: deadlineEdit || null }),
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
        }),
      })
      setCommentBody('')
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
      const res = await api<{ email_sent: boolean }>(`/tenders/${id}/invitations`, {
        method: 'POST',
        body: JSON.stringify({
          user_id: Number(inviteUserId),
          note: inviteNote.trim() || null,
        }),
      })
      setInviteUserId('')
      setInviteNote('')
      await loadMeta()
      setMsg(
        res.email_sent
          ? 'Zaproszono użytkownika i wysłano e-mail.'
          : 'Zaproszono użytkownika (e-mail nie wyszedł — sprawdź SMTP).',
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
      setErr(e instanceof Error ? e.message : 'Błąd akceptacji')
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
    matchStartedAtRef.current = Math.floor(Date.now() / 1000)
    matchDoneRef.current = 0
    const targets = matchTargetIds(
      data?.tender.items ?? [],
      onlyEmpty,
      itemIds,
      data?.coverage?.thresholds.min_match_score ?? 65,
    )
    const estimated = targets.length
    setMatchProgress({
      status: 'running',
      done: 0,
      total: estimated,
      line_no: null,
      requirement: null,
      started_at: matchStartedAtRef.current,
    })
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
      changes: [],
    }
    const scoreParts: number[] = []
    const chunks: number[][] = []
    for (let i = 0; i < targets.length; i += MATCH_BATCH_SIZE) {
      chunks.push(targets.slice(i, i + MATCH_BATCH_SIZE))
    }
    const errors: string[] = []
    const concurrency = clampMatchConcurrency(data?.coverage?.thresholds.match_concurrency)
    try {
      await mapPool(chunks, concurrency, async (chunk) => {
        try {
          const res = await api<MatchApiRes>(`/tenders/${id}/match`, {
            method: 'POST',
            body: JSON.stringify({
              only_empty: onlyEmpty,
              item_ids: chunk,
              progress_total: estimated,
            }),
          })
          merged.matched += res.matched
          merged.skipped += res.skipped
          merged.processed = (merged.processed ?? 0) + (res.processed ?? res.matched + res.skipped)
          merged.changed = (merged.changed ?? 0) + (res.changed ?? 0)
          merged.unchanged = (merged.unchanged ?? 0) + (res.unchanged ?? 0)
          merged.cleared = (merged.cleared ?? 0) + (res.cleared ?? 0)
          merged.skipped_custom = (merged.skipped_custom ?? 0) + (res.skipped_custom ?? 0)
          merged.no_match = (merged.no_match ?? 0) + (res.no_match ?? 0)
          merged.changes = [...(merged.changes ?? []), ...(res.changes ?? [])]
          if (res.matched > 0) {
            scoreParts.push(res.avg_score)
          }
        } catch (e) {
          errors.push(e instanceof Error ? e.message : 'Błąd dopasowania')
        }
        matchDoneRef.current += chunk.length
        setMatchProgress({
          status: matchDoneRef.current >= estimated ? 'done' : 'running',
          done: Math.min(matchDoneRef.current, estimated),
          total: estimated,
          line_no: null,
          requirement: null,
          started_at: matchStartedAtRef.current,
        })
      })
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
        avg_score: merged.avg_score,
        changes: merged.changes ?? [],
        at: new Date().toISOString(),
      }
      setMatchReport(report)
      if (id) {
        sessionStorage.setItem(matchReportStorageKey(id), JSON.stringify(report))
      }
      if (report.changed > 0) {
        setShowAiChanges(true)
      }
      setMsg(
        report.changed > 0
          ? `Dopasowanie zakończone: zmieniono ${report.changed} z ${report.processed} pozycji.`
          : `Dopasowanie zakończone: brak zmian w ofercie (${report.processed} przerobionych, ${report.unchanged} bez zmiany, ${report.skipped_custom} własnych, ${report.no_match} bez produktu).`,
      )
      setTab('pozycje')
      if (errors.length > 0) {
        setErr(`Część paczek nie przeszła (${errors.length}): ${errors[0]}`)
      }
    } catch (e) {
      setErr(e instanceof Error ? e.message : 'Błąd dopasowania')
    } finally {
      setBusy(false)
      setMatchBusy(false)
    }
  }

  async function exportOffer(kind: 'excel' | 'pdf' | 'docx') {
    setErr('')
    setBusy(true)
    try {
      const ext = kind === 'excel' ? 'xlsx' : kind
      await downloadFile(`/tenders/${id}/export/${kind}`, `oferta.${ext}`)
      setMsg(
        kind === 'excel' ? 'Pobrano Excel.' : kind === 'pdf' ? 'Pobrano PDF.' : 'Pobrano uzupełniony formularz DOCX.',
      )
    } catch (e) {
      setErr(e instanceof Error ? e.message : 'Błąd eksportu')
    } finally {
      setBusy(false)
    }
  }

  if (!data) return <p className="text-sm text-slate-500">Ładowanie…</p>

  const { tender, substitutes_by_main, can_edit, next_statuses, coverage } = data
  const canApproveSub = Boolean(user?.permissions?.includes('substitutes.approve'))
  const canComment = Boolean(user?.permissions?.includes('tenders.comment'))
  const canInvite = Boolean(user?.permissions?.includes('tenders.invite'))
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

  return (
    <div>
      <Link to="/tenders" className="text-xs text-blue-600 hover:underline">
        ← Lista przetargów
      </Link>
      <h1 className="mt-2 text-xl font-semibold">
        {tender.number} · {tender.title}
      </h1>
      <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
        <p className="text-xs text-slate-500">
          {tender.client?.name} · opiekun {tender.owner?.name ?? '—'} ·{' '}
          <strong>{statusLabel[tender.status] ?? tender.status}</strong> · AI {tender.ai_percent}% · marża{' '}
          {tender.margin_percent}% · {can_edit ? 'edycja włączona' : 'tylko podgląd'}
        </p>
        <div className="flex flex-wrap gap-1">
          {can_edit && (
            <>
              <button
                type="button"
                disabled={busy}
                onClick={() =>
                  void runMatch(true, listNarrowed ? filteredItems.map((i) => i.id) : undefined)
                }
                title={
                  listNarrowed
                    ? `Tylko puste spośród ${filteredItems.length} pozycji z filtra`
                    : 'Tylko pozycje bez produktu — zapisanych nie rusza'
                }
                className="rounded bg-violet-600 px-2 py-1.5 text-[11px] text-white disabled:opacity-50"
              >
                Dopasuj AI (puste)
              </button>
              <button
                type="button"
                disabled={busy}
                onClick={() => {
                  if (listNarrowed && filteredItems.length === 0) {
                    setErr('Brak pozycji w filtrze.')
                    return
                  }
                  const confirmMsg = listNarrowed
                    ? `Ponownie przeszukać ${filteredItems.length} pozycji z filtra? Własne propozycje zostaną zachowane.`
                    : 'Ponownie przeszukać wszystkie pozycje (także te z produktem z katalogu)? Własne propozycje zostaną zachowane.'
                  if (!window.confirm(confirmMsg)) {
                    return
                  }
                  void runMatch(false, listNarrowed ? filteredItems.map((i) => i.id) : undefined)
                }}
                title={
                  listNarrowed
                    ? `Ponowne dopasowanie ${filteredItems.length} pozycji z filtra`
                    : 'Ponowne dopasowanie całej oferty — nadpisze produkty z katalogu'
                }
                className="rounded bg-violet-800 px-2 py-1.5 text-[11px] text-white disabled:opacity-50"
              >
                Dopasuj AI ({listNarrowed ? `filtr ${filteredItems.length}` : 'wszystkie'})
              </button>
              <button
                type="button"
                disabled={busy}
                onClick={() => void previewCheaperSubstitutes()}
                title="Podgląd i zastosowanie najtańszych zamienników z battlecard (≥3% taniej po upuście)"
                className="rounded bg-amber-500 px-2 py-1.5 text-[11px] font-semibold text-white disabled:opacity-50"
              >
                Zastosuj tańsze zamienniki
              </button>
            </>
          )}
          <button
            type="button"
            disabled={busy}
            onClick={() => void exportOffer('excel')}
            title="Pobierz ofertę do pliku Excel"
            className="rounded bg-emerald-600 px-2 py-1.5 text-[11px] text-white disabled:opacity-50"
          >
            Eksport Excel
          </button>
          <button
            type="button"
            disabled={busy}
            onClick={() => void exportOffer('pdf')}
            className="rounded bg-emerald-800 px-2 py-1.5 text-[11px] text-white disabled:opacity-50"
          >
            PDF
          </button>
          <button
            type="button"
            disabled={busy}
            onClick={() => void exportOffer('docx')}
            title="Wypełnia wgrany formularz ofertowy DOCX cenami z oferty"
            className="rounded bg-sky-700 px-2 py-1.5 text-[11px] text-white disabled:opacity-50"
          >
            DOCX
          </button>
        </div>
      </div>

      {msg && <p className="mb-2 rounded bg-green-50 px-3 py-2 text-xs text-green-800">{msg}</p>}
      {err && <p className="mb-2 rounded bg-red-50 px-3 py-2 text-xs text-red-700">{err}</p>}
      {matchBusy && (
        <div className="fixed inset-0 z-40 flex items-center justify-center bg-slate-900/55 p-4">
          <div className="w-full max-w-md rounded-xl bg-white p-4 text-sm shadow-xl">
            <p className="font-semibold text-slate-900">Trwa dopasowanie AI…</p>
            <p className="mt-1 text-xs text-slate-600">
              Nie odświeżaj strony. Lecą {clampMatchConcurrency(coverage?.thresholds.match_concurrency)}{' '}
              wyszukiwania naraz — przy ~80 pustych to zwykle kilka minut.
            </p>
            {(() => {
              const total = Math.max(matchProgress?.total ?? 0, 0)
              const done = Math.min(matchProgress?.done ?? 0, total || (matchProgress?.done ?? 0))
              const pct = total > 0 ? Math.round((done / total) * 100) : 0
              const eta =
                done > 0 && total > done
                  ? formatMatchEta(Math.round((matchElapsed * (total - done)) / done))
                  : null
              return (
                <>
                  <p className="mt-3 font-mono text-2xl font-semibold text-violet-800">
                    {done} / {total || '…'}
                  </p>
                  <p className="text-xs text-slate-500">sprawdzonych pozycji</p>
                  <div className="mt-2 h-2 overflow-hidden rounded-full bg-slate-200">
                    <div
                      className="h-full rounded-full bg-violet-600 transition-all"
                      style={{ width: `${pct}%` }}
                    />
                  </div>
                  {matchProgress?.line_no != null && (
                    <p className="mt-2 truncate text-xs text-slate-600">
                      Ostatnia: poz. {matchProgress.line_no}
                      {matchProgress.requirement ? ` · ${matchProgress.requirement}` : ''}
                    </p>
                  )}
                  <p className="mt-2 font-mono text-sm text-violet-800">
                    {matchElapsed} s{eta ? ` · zostało ${eta}` : ''}
                  </p>
                </>
              )
            })()}
          </div>
        </div>
      )}
      {matchReport && !matchBusy && (
        <div className="mb-3 rounded-xl border border-violet-200 bg-violet-50 p-3 text-xs text-violet-950">
          <div className="flex flex-wrap items-start justify-between gap-2">
            <div>
              <strong>Ostatnie dopasowanie AI</strong>
              <span className="ml-2 text-violet-800/70">
                {new Date(matchReport.at).toLocaleString('pl-PL')}
              </span>
              <p className="mt-1">
                Przerobiono {matchReport.processed} · zmieniono {matchReport.changed} · bez zmiany{' '}
                {matchReport.unchanged} · zdjęto produkt {matchReport.cleared} · własne pominięte{' '}
                {matchReport.skipped_custom} · bez produktu {matchReport.no_match}
              </p>
            </div>
            <div className="flex flex-wrap gap-1">
              {matchReport.changed > 0 && (
                <button
                  type="button"
                  onClick={() => {
                    setShowAiChanges((v) => !v)
                    setTab('pozycje')
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
            <ul className="mt-2 max-h-32 space-y-0.5 overflow-y-auto font-mono text-[11px]">
              {matchReport.changes.slice(0, 80).map((c) => (
                <li key={c.id}>
                  Poz. {c.line_no}: {c.from_sku ?? '—'} → {c.to_sku ?? 'brak'}
                  {c.action === 'cleared' ? ' (zdjęto)' : ''}
                </li>
              ))}
            </ul>
          )}
        </div>
      )}

      {coverage && (
        <div
          className={`mb-3 rounded-xl border p-3 text-xs ${
            coverage.ready ? 'border-emerald-200 bg-emerald-50' : 'border-amber-200 bg-amber-50'
          }`}
        >
          <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
            <strong>
              Pokrycie oferty:{' '}
              {coverage.ready ? 'gotowa do akceptacji' : 'wymaga uzupełnienia'}
            </strong>
            <span className="text-slate-600">
              {coverage.with_product}/{coverage.total} z produktem
            </span>
          </div>
          <div className="flex flex-wrap gap-1.5">
            {(
              [
                ['without_product', `Bez produktu (${coverage.without_product})`],
                ['without_price', `Bez ceny (${coverage.without_price})`],
                ['weak_match', `Słabe AI (${coverage.weak_match})`],
                ['low_margin', `Niska marża (${coverage.low_margin})`],
              ] as const
            ).map(([key, label]) => (
              <button
                key={key}
                type="button"
                disabled={coverage[key] === 0}
                onClick={() => {
                  setCoverageFilter((f) => (f === key ? null : key))
                  setTab('pozycje')
                }}
                className={`rounded px-2 py-1 ${
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
                Zamienniki oczekujące: {coverage.substitutes_pending}
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

      <div className="mb-3 flex flex-wrap items-end gap-2 rounded-xl bg-white p-3 text-xs shadow-sm">
        <label>
          Termin składania
          <input
            type="date"
            className="mt-1 block rounded border border-slate-300 px-2 py-1"
            value={deadlineEdit}
            onChange={(e) => setDeadlineEdit(e.target.value)}
          />
        </label>
        <button
          type="button"
          disabled={busy}
          onClick={() => void saveDeadline()}
          className="rounded bg-slate-700 px-3 py-1.5 text-white disabled:opacity-50"
        >
          Zapisz termin
        </button>
        {tender.deadline &&
          new Date(tender.deadline) <= new Date(Date.now() + 7 * 86400000) &&
          new Date(tender.deadline) >= new Date(new Date().toDateString()) && (
            <span className="rounded bg-red-100 px-2 py-1 font-medium text-red-700">
              Deadline w ciągu 7 dni
            </span>
          )}
      </div>

      <div className="mb-3 flex flex-wrap gap-1 border-b border-slate-200 pb-2">
        {tabs.map((t) => {
          const badge =
            t === 'historia'
              ? activities.length
              : t === 'komentarze'
                ? comments.length
                : t === 'zaproszenia'
                  ? invitations.length
                  : null
          return (
            <button
              key={t}
              type="button"
              onClick={() => setTab(t)}
              className={`rounded-t px-3 py-2 text-xs capitalize ${
                tab === t ? 'bg-sky-100 font-semibold text-blue-700' : 'bg-slate-100 text-slate-600'
              }`}
            >
              {t}
              {badge != null && badge > 0 ? ` (${badge})` : ''}
            </button>
          )
        })}
      </div>

      {tab === 'pozycje' && (
        <div className="space-y-3">
          {can_edit && (
            <div className="flex flex-wrap items-center justify-end gap-2">
              <button
                type="button"
                disabled={busy}
                onClick={() => void previewCheaperSubstitutes()}
                title="Na pozycjach z tańszym zamiennikiem (≥3% po upuście) — podgląd, potem zbiorcza zamiana"
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
                    Poz. {c.line_no}: {c.from_sku ?? '—'} → {c.to_sku} (−{c.save_percent}% · zakup{' '}
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
                placeholder="Szukaj w SIWZ / produkcie głównym…"
                className="min-w-[240px] flex-1 rounded border border-slate-300 px-2 py-1.5 text-xs"
              />
              <span className="text-[11px] text-slate-500">
                {listNarrowed
                  ? `${filteredItems.length} / ${tender.items.length} pozycji`
                  : `${tender.items.length} pozycji`}
              </span>
            </div>
            <table className="w-full text-left text-xs">
              <thead>
                <tr className="border-b bg-slate-50">
                  <th className="p-2">Lp</th>
                  <th className="p-2">SIWZ</th>
                  <th className="p-2">Produkt główny</th>
                  <th className="p-2">Ilość</th>
                  <th className="p-2">Cena oferty</th>
                  <th className="p-2">Marża</th>
                  <th className="p-2"></th>
                </tr>
              </thead>
              <tbody>
                {filteredItems.map((item) => (
                  <ItemRow
                    key={item.id}
                    tenderId={Number(id)}
                    item={item}
                    products={products}
                    canEdit={can_edit}
                    canComment={canComment}
                    busy={busy}
                    changedByAi={aiChangedIds.has(item.id)}
                    comments={comments.filter((c) => c.tender_item_id === item.id)}
                    itemActivities={activities.filter(
                      (a) => a.item?.id === item.id && activityHasRealChange(a),
                    )}
                    onSave={saveItem}
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
                  <tr>
                    <td colSpan={7} className="p-3 text-slate-400">
                      Brak pozycji dla wybranego filtra.
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
          {can_edit && (
            <div className="flex flex-wrap items-center justify-end gap-2">
              <button
                type="button"
                disabled={busy}
                onClick={() => void previewCheaperSubstitutes()}
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
        </div>
      )}

      {tab === 'warunki' && (
        <div className="space-y-3 rounded-xl bg-white p-4 shadow-sm">
          <p className="text-xs text-slate-500">
            Warunki udziału / IT z dokumentów SIWZ (terminy, certyfikaty, wymagania systemowe).
          </p>
          {can_edit && (
            <div className="flex gap-2">
              <input
                className="flex-1 rounded border border-slate-300 px-2 py-1.5 text-xs"
                placeholder="Nowy warunek…"
                value={newCondition}
                onChange={(e) => setNewCondition(e.target.value)}
              />
              <button
                type="button"
                disabled={busy || !newCondition.trim()}
                onClick={() => void addCondition()}
                className="rounded bg-blue-600 px-3 py-1.5 text-xs text-white disabled:opacity-50"
              >
                Dodaj
              </button>
            </div>
          )}
          <table className="w-full text-left text-xs">
            <thead>
              <tr className="border-b bg-slate-50">
                <th className="p-2">Kategoria</th>
                <th className="p-2">Treść</th>
                <th className="p-2">Źródło</th>
                <th className="p-2"></th>
              </tr>
            </thead>
            <tbody>
              {(tender.conditions ?? []).map((c) => (
                <tr key={c.id} className="border-b align-top">
                  <td className="p-2 text-slate-500">{c.category ?? '—'}</td>
                  <td className="p-2">{c.content}</td>
                  <td className="p-2 text-slate-400">{c.source}</td>
                  <td className="p-2">
                    {can_edit && (
                      <button
                        type="button"
                        disabled={busy}
                        className="text-[10px] text-red-600"
                        onClick={() => void deleteCondition(c.id)}
                      >
                        Usuń
                      </button>
                    )}
                  </td>
                </tr>
              ))}
              {(tender.conditions ?? []).length === 0 && (
                <tr>
                  <td colSpan={4} className="p-3 text-slate-400">
                    Brak warunków — zaimportuj z zakładki Dokumenty.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      )}

      {tab === 'dokumenty' && (
        <div className="space-y-4">
          <div className="rounded-xl bg-white p-4 shadow-sm">
            <h2 className="mb-2 text-sm font-semibold">Import dokumentu SIWZ</h2>
            <p className="mb-3 text-xs text-slate-500">
              PDF, Excel (xlsx/xls/csv), Word (doc/docx) → pozycje i/lub warunki. Word jest zapisywany jako szablon oferty (przycisk DOCX).
            </p>
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
                  ['simple', 'Prosty (tekst)'],
                  ['ai', 'AI (podgląd)'],
                  ['full', 'Pełny (AI + archiwum)'],
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
            {!can_edit && (
              <p className="mb-2 text-xs text-amber-700">Import tylko w statusie szkic/wycena.</p>
            )}
            <div className="flex flex-wrap items-center gap-3">
              <label
                className={`inline-flex cursor-pointer items-center rounded bg-blue-600 px-3 py-2 text-xs font-medium text-white hover:bg-blue-700 ${
                  !can_edit || busy ? 'pointer-events-none opacity-50' : ''
                }`}
              >
                {busy && docStatus.startsWith('Wybrano') ? 'Przetwarzanie…' : 'Przeglądaj…'}
                <input
                  type="file"
                  className="sr-only"
                  accept=".pdf,.xlsx,.xls,.csv,.doc,.docx"
                  disabled={!can_edit || busy}
                  onChange={(e) => {
                    const f = e.target.files?.[0]
                    if (f) void analyzeDocument(f)
                    e.target.value = ''
                  }}
                />
              </label>
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
                    Co zostanie zaimportowane
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
                    disabled={busy || !can_edit}
                    onClick={() => void commitDocument()}
                    className="rounded bg-emerald-600 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-700 disabled:opacity-50"
                  >
                    Zaciągnij zaznaczone do przetargu
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
                  Odznacz
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
                      Pozycje SIWZ ({docPreview.items.length})
                    </h4>
                    {docPreview.items.length === 0 ? (
                      <p className="text-xs text-slate-400">Brak pozycji w wyniku analizy.</p>
                    ) : (
                      <div className="max-h-72 overflow-auto rounded border border-slate-200 bg-white">
                        <table className="w-full text-left text-xs">
                          <thead className="sticky top-0 bg-slate-50">
                            <tr className="border-b">
                              <th className="p-1.5 w-8"></th>
                              <th className="p-1.5">Numer</th>
                              <th className="p-1.5">Nazwa / opis</th>
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
                                  <span className="block">{it.name || it.requirement}</span>
                                  {it.description ? (
                                    <span className="mt-0.5 block text-[11px] text-slate-500">{it.description}</span>
                                  ) : null}
                                </td>
                                <td className="p-1.5 max-w-[200px] text-[11px] text-slate-700">
                                  {it.norms ?? '—'}
                                </td>
                                <td className="p-1.5 text-right whitespace-nowrap">
                                  {it.offer_price != null
                                    ? `${Number(it.offer_price).toFixed(2)}${it.currency ? ` ${it.currency}` : ''}`
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
                      <p className="text-xs text-slate-400">Brak warunków w wyniku analizy.</p>
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
                        <span className="ml-1 text-[10px] text-emerald-600">plik</span>
                      ) : null}
                    </td>
                    <td className="p-2">{d.mode}</td>
                    <td className="p-2">{new Date(d.created_at).toLocaleString('pl-PL')}</td>
                    <td className="p-2 space-x-2">
                      <button
                        type="button"
                        disabled={busy}
                        className="text-[10px] text-emerald-700"
                        onClick={() => void openDocumentPreview(d.id)}
                      >
                        Podgląd
                      </button>
                      {can_edit && (
                        <>
                          <button
                            type="button"
                            disabled={busy}
                            className="text-[10px] text-blue-600"
                            onClick={() => void reanalyzeDoc(d.id)}
                          >
                            Analizuj ponownie
                          </button>
                          <button
                            type="button"
                            disabled={busy}
                            className="text-[10px] text-red-600"
                            onClick={() => void deleteDoc(d.id)}
                          >
                            Usuń
                          </button>
                        </>
                      )}
                    </td>
                  </tr>
                ))}
                {(tender.documents ?? []).length === 0 && (
                  <tr>
                    <td colSpan={4} className="p-3 text-slate-400">
                      Brak wgranych dokumentów.
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {tab === 'zamienniki' && (
        <div className="space-y-3">
          <p className="rounded-lg border-l-4 border-blue-500 bg-slate-100 p-3 text-xs text-slate-600">
            Zamienniki przypięte do produktu głównego. Akceptacja: kierownik.
          </p>
          {tender.items
            .filter((i) => i.main_product)
            .map((item) => {
              const subs = substitutes_by_main[String(item.main_product!.id)] ?? []
              if (subs.length === 0) return null
              return (
                <div key={item.id} className="overflow-hidden rounded-xl border border-slate-200 bg-white">
                  <div className="border-b bg-slate-50 px-4 py-3 text-xs font-semibold">
                    Poz. {item.line_no} · {productDisplayName(item.main_product!)} (
                    {item.main_product!.sku})
                  </div>
                  <table className="w-full text-left text-xs">
                    <thead>
                      <tr className="border-b bg-slate-50/80">
                        <th className="p-2">Zamiennik</th>
                        <th className="p-2">Typ</th>
                        <th className="p-2">AI</th>
                        <th className="p-2">Status</th>
                        <th className="p-2">Akcja</th>
                      </tr>
                    </thead>
                    <tbody>
                      {subs.map((s) => (
                        <tr key={s.id} className="border-b">
                          <td className="p-2">
                            {s.substitute_product?.name} ({s.substitute_product?.sku})
                          </td>
                          <td className="p-2">{s.type}</td>
                          <td className="p-2">{s.match_percent}%</td>
                          <td className="p-2">{s.approval_status}</td>
                          <td className="p-2">
                            {canApproveSub && (
                              <div className="flex gap-1">
                                <button
                                  type="button"
                                  disabled={busy}
                                  className="rounded bg-green-600 px-2 py-1 text-[10px] text-white"
                                  onClick={() => void approveSub(s.id, 'zatwierdzony')}
                                >
                                  OK
                                </button>
                                <button
                                  type="button"
                                  disabled={busy}
                                  className="rounded bg-red-600 px-2 py-1 text-[10px] text-white"
                                  onClick={() => void approveSub(s.id, 'odrzucony')}
                                >
                                  Nie
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
                <th className="p-2">Kod</th>
                <th className="p-2">Oferta</th>
                <th className="p-2">Marża</th>
                <th className="p-2">Wartość linii</th>
              </tr>
            </thead>
            <tbody>
              {tender.items.map((item) => {
                const line =
                  item.offer_price != null ? Number(item.offer_price) * item.quantity : null
                return (
                  <tr key={item.id} className="border-b">
                    <td className="p-2">{item.main_product?.sku ?? item.custom_name ?? '—'}</td>
                    <td className="p-2">{item.offer_price ?? '—'}</td>
                    <td className="p-2">{item.margin_percent ?? '—'}%</td>
                    <td className="p-2">
                      {line != null ? `${line.toLocaleString('pl-PL')} zł` : '—'}
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
          <p className="mt-3 text-sm">
            Suma:{' '}
            <strong>
              {tender.offer_value_net
                ? `${Number(tender.offer_value_net).toLocaleString('pl-PL')} zł`
                : '—'}
            </strong>{' '}
            · marża {tender.margin_percent}%
          </p>
        </div>
      )}

      {tab === 'komentarze' && (
        <div className="space-y-3 rounded-xl bg-white p-4 shadow-sm text-xs">
          <h2 className="text-sm font-semibold">Komentarze</h2>
          {canComment ? (
            <div className="flex flex-wrap items-end gap-2 border-b border-slate-100 pb-3">
              <label className="flex-1 min-w-[200px]">
                Treść
                <textarea
                  className="mt-1 w-full rounded border border-slate-300 px-2 py-1"
                  rows={2}
                  value={commentBody}
                  onChange={(e) => setCommentBody(e.target.value)}
                />
              </label>
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
                      Lp {it.line_no}
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
            <p className="text-slate-400">Brak uprawnienia do komentowania.</p>
          )}
          <ul className="space-y-2">
            {comments.map((c) => (
              <li key={c.id} className="rounded border border-slate-100 bg-slate-50 p-2">
                <div className="mb-1 text-[11px] text-slate-500">
                  {c.user?.name} · {new Date(c.created_at).toLocaleString('pl-PL')}
                  {c.item ? ` · poz. ${c.item.line_no}` : ''}
                </div>
                <p className="whitespace-pre-wrap">{c.body}</p>
              </li>
            ))}
            {comments.length === 0 && <li className="text-slate-400">Brak komentarzy.</li>}
          </ul>
        </div>
      )}

      {tab === 'historia' && (
        <div className="rounded-xl bg-white p-4 shadow-sm text-xs">
          <h2 className="mb-3 text-sm font-semibold">Historia zmian</h2>
          <table className="w-full text-left">
            <thead>
              <tr className="border-b bg-slate-50">
                <th className="p-2">Kiedy</th>
                <th className="p-2">Kto</th>
                <th className="p-2">Akcja</th>
                <th className="p-2">Szczegóły</th>
              </tr>
            </thead>
            <tbody>
              {activities.map((a) => (
                <tr key={a.id} className="border-b align-top">
                  <td className="p-2 whitespace-nowrap">
                    {new Date(a.created_at).toLocaleString('pl-PL')}
                  </td>
                  <td className="p-2">{a.user?.name ?? '—'}</td>
                  <td className="p-2">
                    {actionLabel[a.action] ?? a.action}
                    {a.item ? ` (lp ${a.item.line_no})` : ''}
                  </td>
                  <td className="p-2 text-[11px] text-slate-600">
                    {formatActivityMeta(a.meta)}
                  </td>
                </tr>
              ))}
              {activities.length === 0 && (
                <tr>
                  <td colSpan={4} className="p-3 text-slate-400">
                    Brak wpisów audytu. Zmień cenę/produkt i kliknij Zapisz przy pozycji.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      )}

      {tab === 'zaproszenia' && (
        <div className="space-y-3 rounded-xl bg-white p-4 shadow-sm text-xs">
          <h2 className="text-sm font-semibold">Zaproszeni do przetargu</h2>
          <p className="text-slate-500">
            Zaproszone osoby mają dostęp do tego przetargu jak opiekun (w ramach własnych uprawnień roli).
          </p>

          {canInvite && (
            <div className="grid gap-2 rounded-lg border border-slate-200 bg-slate-50 p-3 sm:grid-cols-[1fr_1fr_auto]">
              <label>
                Szukaj użytkownika
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
                  placeholder="np. Proszę o wycenę sekcji rękawic"
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
                      <span className="text-amber-700">brak</span>
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
      )}

      {tab === 'workflow' && (
        <div className="space-y-4">
          <div className="rounded-xl bg-white p-4 shadow-sm">
            <h2 className="mb-2 text-sm font-semibold">Zmiana statusu</h2>
            <p className="mb-3 text-xs text-slate-500">
              Teraz: <strong>{statusLabel[tender.status] ?? tender.status}</strong>
            </p>
            <label className="mb-3 block text-xs">
              Notatka (wymagana przy odrzuceniu / cofnięciu z akceptacji)
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
                  → {statusLabel[s] ?? s}
                </button>
              ))}
              {next_statuses.length === 0 && (
                <span className="text-xs text-slate-400">Brak dostępnych przejść dla Twojej roli.</span>
              )}
            </div>
            <p className="mt-3 text-[11px] text-slate-400">
              Zalogowany: {user?.name} ({user?.role}). Przejścia statusów zależą od uprawnień roli.
            </p>
          </div>
          <div className="rounded-xl bg-white p-4 shadow-sm">
            <h2 className="mb-2 text-sm font-semibold">Historia statusów</h2>
            <table className="w-full text-left text-xs">
              <thead>
                <tr className="border-b bg-slate-50">
                  <th className="p-2">Kiedy</th>
                  <th className="p-2">Kto</th>
                  <th className="p-2">Z → Do</th>
                  <th className="p-2">Notatka</th>
                </tr>
              </thead>
              <tbody>
                {(tender.status_histories ?? []).map((h) => (
                  <tr key={h.id} className="border-b">
                    <td className="p-2">{new Date(h.created_at).toLocaleString('pl-PL')}</td>
                    <td className="p-2">{h.user?.name}</td>
                    <td className="p-2">
                      {h.from_status ?? '—'} → {h.to_status}
                    </td>
                    <td className="p-2">{h.note ?? '—'}</td>
                  </tr>
                ))}
                {(tender.status_histories ?? []).length === 0 && (
                  <tr>
                    <td colSpan={4} className="p-3 text-slate-400">
                      Brak historii.
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </div>
  )
}

function ItemRow({
  tenderId,
  item,
  products,
  canEdit,
  canComment,
  busy,
  changedByAi,
  comments,
  itemActivities,
  onSave,
  onDraftChange,
  onComment,
}: {
  tenderId: number
  item: Item
  products: Product[]
  canEdit: boolean
  canComment: boolean
  busy: boolean
  changedByAi?: boolean
  comments: CommentRow[]
  itemActivities: ActivityRow[]
  onSave: (id: number, patch: Record<string, unknown>) => Promise<void>
  onDraftChange: (itemId: number, draft: ItemDraft) => void
  onComment: (itemId: number, body: string) => Promise<void>
}) {
  const [productId, setProductId] = useState<string>(itemProductId(item))
  const [picked, setPicked] = useState<{
    id: number
    sku: string
    name: string
    description?: string | null
  } | null>(
    item.main_product
      ? {
          id: item.main_product.id,
          sku: item.main_product.sku,
          name: item.main_product.name,
          description: item.main_product.description,
        }
      : null,
  )
  const [qty, setQty] = useState(String(item.quantity))
  const [price, setPrice] = useState(item.offer_price ?? '')
  const [customName, setCustomName] = useState(item.custom_name ?? '')
  const [customUrl, setCustomUrl] = useState(item.custom_url ?? '')
  const [matchHint, setMatchHint] = useState('')
  const [previewId, setPreviewId] = useState<number | null>(null)
  const [aiModalOpen, setAiModalOpen] = useState(false)
  const [pendingAiScore, setPendingAiScore] = useState<number | null>(null)
  const [commentText, setCommentText] = useState('')
  const [showComment, setShowComment] = useState(false)
  const [showPriceHistory, setShowPriceHistory] = useState(false)
  const hasChanges = itemActivities.length > 0

  useEffect(() => {
    setProductId(itemProductId(item))
    setPicked(
      item.main_product
        ? {
            id: item.main_product.id,
            sku: item.main_product.sku,
            name: item.main_product.name,
            description: item.main_product.description,
          }
        : null,
    )
    setQty(String(item.quantity))
    setPrice(item.offer_price ?? '')
    setCustomName(item.custom_name ?? '')
    setCustomUrl(item.custom_url ?? '')
    setPendingAiScore(null)
  }, [item])

  useEffect(() => {
    onDraftChange(item.id, {
      main_product_id: productId ? Number(productId) : null,
      quantity: Number(qty) || 1,
      offer_price: price === '' ? null : Number(String(price).replace(',', '.')),
      custom_name: customName.trim() || null,
      custom_url: customUrl.trim() || null,
    })
  }, [item.id, productId, qty, price, customName, customUrl, onDraftChange])

  const selectedProduct =
    picked && String(picked.id) === productId
      ? picked
      : item.main_product && String(item.main_product.id) === productId
        ? item.main_product
        : null

  const hasSavedProduct = Boolean(selectedProduct || productId)

  return (
    <>
    <tr
      className={`${showComment ? 'align-top' : 'border-b align-top'} ${
        changedByAi ? 'bg-violet-50 ring-1 ring-inset ring-violet-200' : ''
      }`}
    >
      <td className="p-2">
        <span className="inline-flex items-center gap-1">
          {item.line_no}
          {changedByAi && (
            <span className="rounded bg-violet-700 px-1 py-px text-[9px] font-bold uppercase text-white">
              AI
            </span>
          )}
          {hasChanges && (
            <span
              title={`${itemActivities.length} zmian — kliknij cenę / hist.`}
              className="inline-block h-2 w-2 rounded-full bg-amber-500"
            />
          )}
        </span>
      </td>
      <td className="p-2 max-w-[200px] text-[11px]">{item.requirement}</td>
      <td className="p-2">
        {canEdit ? (
          <div className="flex flex-col gap-1">
            <div className="flex items-start gap-1">
              <ProductSearchSelect
                products={products}
                value={productId}
                selectedProduct={selectedProduct}
                disabled={busy}
                onChange={(id, product) => {
                  setProductId(id)
                  setPicked(product ?? null)
                  setMatchHint('')
                  setPendingAiScore(null)
                  if (id) {
                    setCustomName('')
                    setCustomUrl('')
                  }
                }}
                hint={
                  matchHint ||
                  (item.ai_match_percent != null
                    ? hasSavedProduct
                      ? `Dopasowanie AI: ${item.ai_match_percent}%`
                      : `Wynik AI: ${item.ai_match_percent}%`
                    : undefined)
                }
              />
              <button
                type="button"
                title="Otwórz wyszukiwanie AI (własne zapytanie → top 5)"
                disabled={busy}
                onClick={() => setAiModalOpen(true)}
                className="shrink-0 rounded bg-violet-600 px-2 py-1 text-[10px] text-white hover:bg-violet-700 disabled:opacity-50"
              >
                AI
              </button>
            </div>
            <ProductAiMatchModal
              open={aiModalOpen}
              initialQuery={item.requirement}
              onClose={() => setAiModalOpen(false)}
              onSelect={(p) => {
                setProductId(String(p.id))
                setPicked({
                  id: p.id,
                  sku: p.sku,
                  name: p.name,
                  description: p.description,
                })
                setMatchHint(`AI: ${p.sku} (${p.score}%)`)
                setPendingAiScore(p.score)
                setCustomName('')
                setCustomUrl('')
                setAiModalOpen(false)
              }}
              onAddExternal={(hint) => {
                setCustomName(hint.title)
                setCustomUrl(hint.url)
                setProductId('')
                setPicked(null)
                setPendingAiScore(null)
                setAiModalOpen(false)
                void onSave(item.id, {
                  main_product_id: null,
                  custom_name: hint.title,
                  custom_url: hint.url,
                  quantity: Number(qty) || 1,
                  offer_price: price === '' ? null : Number(String(price).replace(',', '.')),
                  match_source: 'custom',
                  status: 'matched',
                })
              }}
            />
            {!hasSavedProduct && (
              <ExternalHints
                reasons={item.ai_match_reasons}
                onAddToOffer={(hint) => {
                  setCustomName(hint.title)
                  setCustomUrl(hint.url)
                  setProductId('')
                  setPicked(null)
                  void onSave(item.id, {
                    main_product_id: null,
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
            <details className="max-w-[280px] rounded border border-amber-200 bg-amber-50/70 px-2 py-1">
              <summary className="cursor-pointer text-[10px] font-semibold text-amber-950">
                Własna propozycja{customName ? `: ${customName}` : ''}
              </summary>
              <div className="mt-1 space-y-1">
                <input
                  className="w-full rounded border border-amber-200 px-1.5 py-1 text-[11px]"
                  placeholder="Nazwa do oferty"
                  disabled={busy}
                  value={customName}
                  onChange={(e) => setCustomName(e.target.value)}
                />
                <input
                  className="w-full rounded border border-amber-200 px-1.5 py-1 text-[11px]"
                  placeholder="Link (opcjonalnie)"
                  disabled={busy}
                  value={customUrl}
                  onChange={(e) => setCustomUrl(e.target.value)}
                />
                <p className="text-[10px] text-amber-900">Cenę wpisz w kolumnie Cena i zapisz.</p>
              </div>
            </details>
            {hasSavedProduct && (
              <details
                open
                className="max-w-[280px] rounded border border-violet-200 bg-violet-50 px-2 py-1 text-[10px] text-violet-900"
              >
                <summary className="cursor-pointer font-semibold">
                  Uzasadnienie dopasowania
                  {item.ai_match_percent != null ? ` (${item.ai_match_percent}%)` : ''}
                </summary>
                {(item.ai_match_reasons?.length ?? 0) > 0 ? (
                  <ul className="mt-1 list-disc pl-4">
                    {item.ai_match_reasons!.map((r, i) => (
                      <li key={`${r.code}-${i}`}>
                        {r.code === 'external_link' || r.code === 'custom_offer' ? (
                          <ExternalHintLink reason={r} />
                        ) : (
                          <>
                            {r.label}
                            {r.points > 0 ? ` (+${r.points})` : ''}
                          </>
                        )}
                      </li>
                    ))}
                  </ul>
                ) : (
                  <p className="mt-1 text-slate-500">Brak zapisanych powodów — odśwież stronę.</p>
                )}
              </details>
            )}
            <ItemBattlecard
              tenderId={tenderId}
              itemId={item.id}
              enabled={Boolean(item.main_product_id ?? item.main_product?.id)}
              canSelectSubstitute
              selectedProductId={item.main_product_id ?? item.main_product?.id ?? null}
              onSelectSubstitute={(p: BattlecardProduct) =>
                onSave(item.id, {
                  main_product_id: p.product_id,
                  quantity: Number(qty) || 1,
                  ai_match_percent: p.match_percent,
                  match_source: 'battlecard',
                  ai_match_reasons: [
                    {
                      code: 'battlecard',
                      label: `Wybrano ${p.sku} z battlecard`,
                      points: p.match_percent,
                    },
                  ],
                })
              }
            />
          </div>
        ) : (
          <span>
            {item.main_product ? (
              <button
                type="button"
                className="flex w-full max-w-[280px] items-start gap-1.5 rounded-md border border-sky-200 bg-sky-50 px-2 py-1.5 text-left shadow-sm transition hover:border-sky-400 hover:bg-sky-100"
                onClick={() => setPreviewId(item.main_product!.id)}
              >
                <span className="mt-0.5 inline-block h-1.5 w-1.5 shrink-0 rounded-full bg-sky-500" />
                <span className="min-w-0">
                  <span className="block truncate text-[11px] font-medium text-sky-900">
                    {item.main_product.sku}
                  </span>
                  <span
                    className="block truncate text-[10px] text-slate-600"
                    title={item.main_product.name}
                  >
                    {productDisplayName(item.main_product)}
                  </span>
                  {item.ai_match_percent != null && (
                    <span className="mt-0.5 block text-[10px] text-violet-700">
                      Dopasowanie AI: {item.ai_match_percent}%
                    </span>
                  )}
                </span>
              </button>
            ) : item.custom_name ? (
              <div className="max-w-[280px] rounded border border-amber-300 bg-amber-50 px-2 py-1.5">
                <span className="rounded bg-amber-200 px-1 py-px text-[9px] font-bold uppercase tracking-wide text-amber-950">
                  Własna propozycja — nie z katalogu
                </span>
                <span className="mt-1 block text-[11px] font-medium text-amber-950">{item.custom_name}</span>
                {item.custom_url && (
                  <a
                    href={item.custom_url}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="mt-0.5 block truncate text-[10px] text-amber-900 underline"
                  >
                    {item.custom_url}
                  </a>
                )}
              </div>
            ) : (
              <ExternalHints reasons={item.ai_match_reasons} />
            )}
            {(item.ai_match_reasons?.length ?? 0) > 0 && item.main_product && (
              <ul className="mt-1 max-w-[280px] list-disc pl-4 text-[10px] text-slate-600">
                {item.ai_match_reasons!.slice(0, 4).map((r, i) => (
                  <li key={`${r.code}-${i}`}>{r.label}</li>
                ))}
              </ul>
            )}
            <div className="mt-1">
              <ItemBattlecard
                tenderId={tenderId}
                itemId={item.id}
                enabled={Boolean(item.main_product)}
              />
            </div>
            <ProductPreviewModal productId={previewId} onClose={() => setPreviewId(null)} />
          </span>
        )}
      </td>
      <td className="p-2">
        {canEdit ? (
          <input
            className="w-16 rounded border border-slate-300 px-1 py-1"
            value={qty}
            onChange={(e) => setQty(e.target.value)}
          />
        ) : (
          item.quantity
        )}
      </td>
      <td className="p-2 align-top">
        <div className="flex w-max max-w-[16rem] flex-col gap-1">
          <button
            type="button"
            title={hasChanges ? 'Kliknij — historia zmian ceny' : 'Brak zmian ceny'}
            onClick={() => setShowPriceHistory((v) => !v)}
            className={`flex min-w-[5.5rem] items-center gap-1 rounded border px-1.5 py-1 text-left text-xs ${
              hasChanges
                ? 'border-amber-400 bg-amber-50 text-amber-950 hover:bg-amber-100'
                : 'border-transparent hover:border-slate-200 hover:bg-slate-50'
            }`}
          >
            {canEdit ? (
              <input
                className="w-16 border-0 bg-transparent p-0 outline-none"
                value={price}
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
          {showPriceHistory && (
            <div className="rounded-lg border border-amber-200 bg-amber-50/90 p-2 shadow-sm">
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
      </td>
      <td
        className={`p-2 ${
          item.margin_percent != null && Number(item.margin_percent) < 0
            ? 'font-semibold text-red-700'
            : ''
        }`}
        title={
          item.margin_percent != null && Number(item.margin_percent) < 0
            ? 'Ujemna marża — cena oferty poniżej zakupu (po upuście). Proponowany narzut: +18%.'
            : 'Marża = (oferta − zakup) / oferta'
        }
      >
        {item.margin_percent ?? '—'}%
        {item.margin_percent != null && Number(item.margin_percent) < 0 ? (
          <span className="mt-0.5 block text-[9px] font-normal">ujemna!</span>
        ) : null}
      </td>
      <td className="p-2">
        <div className="flex flex-col gap-1">
          {canEdit && (
            <button
              type="button"
              disabled={busy}
              className="rounded bg-blue-600 px-2 py-1 text-[10px] text-white disabled:opacity-50"
              onClick={() =>
                void onSave(item.id, {
                  main_product_id: productId ? Number(productId) : null,
                  quantity: Number(qty) || 1,
                  offer_price: price === '' ? null : Number(price.replace(',', '.')),
                  custom_name: customName.trim() || null,
                  custom_url: customUrl.trim() || null,
                  ...(pendingAiScore != null
                    ? {
                        ai_match_percent: pendingAiScore,
                        match_source: 'ai',
                        ai_match_reasons: [
                          {
                            code: 'ai',
                            label: 'Wybór z wyszukiwania AI',
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
              className="rounded border border-slate-300 px-2 py-1 text-[10px] text-slate-700 hover:bg-slate-50"
              onClick={() => setShowComment((v) => !v)}
            >
              Komentarz{comments.length > 0 ? ` (${comments.length})` : ''}
            </button>
          )}
        </div>
      </td>
    </tr>
    {canComment && showComment && (
      <tr className="border-b bg-slate-50/80">
        <td colSpan={7} className="px-3 py-2 text-[11px]">
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
        </td>
      </tr>
    )}
    </>
  )
}
