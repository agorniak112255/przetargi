import { useEffect, useRef, useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../auth'
import {
  api,
  can,
  type Product,
  type Substitute,
  type SubstituteBoardGroup,
  type SubstituteBoardPage,
  type SubstituteCardLite,
  type SubstituteLite,
  type SubstituteSummary,
  type SubstitutesByMain,
} from '../lib/api'
import { formatPln } from '../lib/campaigns'
import { Pager } from '../components/CampaignsUi'
import {
  ManufacturerBadge,
  SourceTag,
  StaleBadge,
  StatusPill,
  SubstituteMatrix,
  SubstitutePriceTag,
  SubstituteThumb,
  TypeTag,
  type SubstituteDecision,
} from '../components/SubstituteMatrix'
import {
  SUBSTITUTE_SOURCES,
  SUBSTITUTE_STATUSES,
  SUBSTITUTE_TYPES,
  paramSummaryText,
  plural,
} from '../lib/substitutes'

type FormState = {
  main_product_id: number | null
  substitute_product_id: number | null
  main_label: string
  sub_label: string
  type: string
  match_percent: number
  norms_ok: boolean
  certs_ok: boolean
  reason: string
  /** edycja propozycji automatu: bez pól „Zgodność AI”, norm i certyfikatów (dowody są w evidence) */
  auto: boolean
}

const emptyForm = (): FormState => ({
  main_product_id: null,
  substitute_product_id: null,
  main_label: '',
  sub_label: '',
  type: 'preferowany',
  match_percent: 80,
  norms_ok: true,
  certs_ok: true,
  reason: '',
  auto: false,
})

type ProductsPage = { data: Product[] }

type Filters = { family: string; manufacturer: string; status: string; type: string; source: string }

const emptyFilters: Filters = { family: '', manufacturer: '', status: '', type: '', source: '' }

type Detail = { mainCard: SubstituteCardLite | null; rows: Substitute[]; loading: boolean; error: string }

function ProductSearch({
  label,
  valueLabel,
  excludeId,
  onPick,
}: {
  label: string
  valueLabel: string
  excludeId?: number | null
  onPick: (p: Product) => void
}) {
  const [q, setQ] = useState('')
  const [hits, setHits] = useState<Product[]>([])
  const [open, setOpen] = useState(false)

  useEffect(() => {
    if (q.trim().length < 2) {
      setHits([])
      return
    }
    const t = window.setTimeout(() => {
      const params = new URLSearchParams({ q: q.trim(), per_page: '20' })
      void api<ProductsPage>(`/products?${params}`)
        .then((page) => {
          setHits(page.data.filter((p) => p.id !== excludeId))
          setOpen(true)
        })
        .catch(() => setHits([]))
    }, 250)
    return () => window.clearTimeout(t)
  }, [q, excludeId])

  return (
    <label className="relative block text-xs">
      {label}
      <input
        className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5"
        value={open || q ? q : valueLabel}
        onChange={(e) => {
          setQ(e.target.value)
          setOpen(true)
        }}
        onFocus={() => {
          if (valueLabel) setQ('')
          setOpen(true)
        }}
        onBlur={() => window.setTimeout(() => setOpen(false), 150)}
        placeholder="Szukaj SKU / nazwy (min. 2 znaki)"
      />
      {valueLabel && !open && !q && (
        <p className="mt-0.5 truncate text-[10px] text-slate-500">{valueLabel}</p>
      )}
      {open && hits.length > 0 && (
        <ul className="absolute z-20 mt-1 max-h-48 w-full overflow-auto rounded border border-slate-200 bg-white shadow-lg">
          {hits.map((p) => (
            <li key={p.id}>
              <button
                type="button"
                className="w-full px-2 py-1.5 text-left hover:bg-slate-50"
                onMouseDown={(e) => e.preventDefault()}
                onClick={() => {
                  onPick(p)
                  setQ('')
                  setOpen(false)
                }}
              >
                <span className="font-medium">{p.sku}</span>
                <span className="text-slate-500"> · {p.name}</span>
              </button>
            </li>
          ))}
        </ul>
      )}
    </label>
  )
}

/** Licznik w pasku nad listą — kliknięcie ustawia filtr statusu. */
function SummaryKpi({
  label,
  value,
  hint,
  tone,
  active,
  title,
  onClick,
}: {
  label: string
  value: number | null
  hint?: string
  tone?: 'alert'
  active: boolean
  title: string
  onClick: () => void
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-pressed={active}
      title={title}
      data-tone={tone}
      className={`app-kpi min-w-0 rounded-xl border bg-white px-2.5 py-2 text-left shadow-sm sm:px-3 transition-colors hover:border-slate-400 ${
        active ? 'border-slate-800 ring-1 ring-slate-800' : 'border-slate-200'
      }`}
    >
      <b
        className={`app-kpi-value block text-lg font-semibold sm:text-xl tabular-nums ${tone === 'alert' ? 'text-amber-800' : 'text-slate-800'}`}
      >
        {value == null ? '…' : value.toLocaleString('pl-PL')}
      </b>
      <span className="app-kpi-label block text-xs text-slate-500">{label}</span>
      {(hint || active) && (
        <span className="app-kpi-link hidden text-[11px] text-slate-500 sm:block">{active ? '✓ filtr włączony' : hint}</span>
      )}
    </button>
  )
}

/** Kompaktowa karta zamiennika w rzędzie pod kartą główną. */
function SubstituteChip({ sub, focused, onOpen }: { sub: SubstituteLite; focused: boolean; onOpen: () => void }) {
  const accent =
    sub.approval_status === 'zatwierdzony'
      ? 'border-l-emerald-500'
      : sub.approval_status === 'odrzucony'
        ? 'border-l-rose-300'
        : 'border-l-amber-400'
  const summary = paramSummaryText(sub.summary)
  return (
    <button
      type="button"
      onClick={onOpen}
      className={`flex w-56 shrink-0 flex-col rounded-lg border border-l-[3px] bg-white p-2 text-left transition-colors hover:bg-slate-50 ${accent} ${
        focused ? 'border-slate-800 ring-1 ring-slate-800' : 'border-slate-200'
      } ${sub.approval_status === 'odrzucony' ? 'opacity-70' : ''}`}
      title="Porównaj z kartą główną"
    >
      <span className="flex min-w-0 gap-2">
        <SubstituteThumb url={sub.product.thumb_url} size="sm" />
        <span className="min-w-0">
          <ManufacturerBadge name={sub.product.manufacturer} />
          <span className="mt-0.5 line-clamp-2 break-words text-[11px] font-medium leading-snug text-slate-800" title={sub.product.name}>
            {sub.product.name}
          </span>
        </span>
      </span>
      <span className="mt-1.5 flex items-center justify-between gap-1">
        <SubstitutePriceTag price={sub.price} compact />
        <StatusPill status={sub.approval_status} />
      </span>
      <span className="mt-1 flex flex-wrap items-center gap-1">
        <TypeTag type={sub.type} />
        {sub.stale && <StaleBadge />}
        <SourceTag source={sub.source} />
      </span>
      <span className="mt-1 text-[10.5px] leading-snug text-slate-500">
        {summary || (sub.source === 'automat' ? 'bez dowodów automatu' : 'wpisany ręcznie')}
      </span>
    </button>
  )
}

export function Substitutes() {
  const { user } = useAuth()
  const canManage = can(user, 'substitutes.manage')
  const canApprove = can(user, 'substitutes.approve')

  const [summary, setSummary] = useState<SubstituteSummary | null>(null)
  const [board, setBoard] = useState<SubstituteBoardPage | null>(null)
  const [loading, setLoading] = useState(false)
  const [q, setQ] = useState('')
  const [debouncedQ, setDebouncedQ] = useState('')
  const [filters, setFilters] = useState<Filters>(emptyFilters)
  const [page, setPage] = useState(1)
  const [expanded, setExpanded] = useState<{ mainId: number; focusId: number | null } | null>(null)
  const [detail, setDetail] = useState<Detail | null>(null)
  const [showAllInDetail, setShowAllInDetail] = useState(false)
  const [formOpen, setFormOpen] = useState(false)
  const [editingId, setEditingId] = useState<number | null>(null)
  const [editingMainId, setEditingMainId] = useState<number | null>(null)
  const [form, setForm] = useState<FormState>(emptyForm)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const [msg, setMsg] = useState('')
  const boardReq = useRef(0)
  const detailReq = useRef(0)
  const formRef = useRef<HTMLFormElement | null>(null)

  useEffect(() => {
    const t = window.setTimeout(() => {
      setDebouncedQ(q.trim())
      setPage(1)
    }, 300)
    return () => window.clearTimeout(t)
  }, [q])

  async function loadSummary() {
    try {
      setSummary(await api<SubstituteSummary>('/substitutes/summary'))
    } catch {
      // licznik to dodatek — lista działa bez niego
    }
  }

  async function loadBoard() {
    const req = ++boardReq.current
    setLoading(true)
    setErr('')
    try {
      const params = new URLSearchParams()
      if (debouncedQ) params.set('q', debouncedQ)
      for (const [k, v] of Object.entries(filters)) if (v) params.set(k, v)
      if (page > 1) params.set('page', String(page))
      const qs = params.toString()
      const res = await api<SubstituteBoardPage>(`/substitutes/board${qs ? `?${qs}` : ''}`)
      if (req === boardReq.current) setBoard(res)
    } catch (ex) {
      if (req === boardReq.current) setErr(ex instanceof Error ? ex.message : 'Błąd ładowania')
    } finally {
      if (req === boardReq.current) setLoading(false)
    }
  }

  async function loadDetail(mainId: number) {
    const req = ++detailReq.current
    setDetail((d) => ({ mainCard: d?.mainCard ?? null, rows: d?.rows ?? [], loading: true, error: '' }))
    try {
      const res = await api<SubstitutesByMain>(`/products/${mainId}/substitutes`)
      if (req !== detailReq.current) return
      setDetail({ mainCard: res.main_card ?? null, rows: res.substitutes.filter(Boolean), loading: false, error: '' })
    } catch (ex) {
      if (req !== detailReq.current) return
      setDetail({ mainCard: null, rows: [], loading: false, error: ex instanceof Error ? ex.message : 'Błąd ładowania' })
    }
  }

  useEffect(() => {
    void loadSummary()
  }, [])

  useEffect(() => {
    void loadBoard()
    // loadBoard czyta filtry z bieżącego renderu; zależności to dokładnie jego wejścia.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debouncedQ, filters, page])

  function setFilter(key: keyof Filters, value: string) {
    setFilters((f) => ({ ...f, [key]: value }))
    setPage(1)
  }

  function goPage(p: number) {
    setPage(p)
    window.scrollTo({ top: 0 })
  }

  function resetFilters() {
    setQ('')
    setDebouncedQ('')
    setFilters(emptyFilters)
    setPage(1)
  }

  function toggleGroup(mainId: number, focusId: number | null = null) {
    if (expanded?.mainId === mainId && (focusId === null || focusId === expanded.focusId)) {
      setExpanded(null)
      return
    }
    setExpanded({ mainId, focusId })
    if (expanded?.mainId !== mainId) {
      setShowAllInDetail(false)
      setDetail(null)
      void loadDetail(mainId)
    }
  }

  /** Nadpisuje wiersz w tablicy i w macierzy po decyzji — grupa nie znika z ekranu przy włączonym filtrze. */
  function patchRow(id: number, patch: Partial<SubstituteLite>) {
    setBoard((b) =>
      b
        ? {
            ...b,
            data: b.data.map((g) => ({
              ...g,
              substitutes: g.substitutes.map((s) => (s.id === id ? { ...s, ...patch } : s)),
            })),
          }
        : b,
    )
    setDetail((d) =>
      d
        ? {
            ...d,
            rows: d.rows.map((r) =>
              r.id === id
                ? {
                    ...r,
                    approval_status: patch.approval_status ?? r.approval_status,
                    decision_note: patch.decision_note !== undefined ? patch.decision_note : r.decision_note,
                    approver: patch.approver !== undefined ? patch.approver : r.approver,
                  }
                : r,
            ),
          }
        : d,
    )
  }

  function dropRow(id: number) {
    setBoard((b) =>
      b
        ? {
            ...b,
            data: b.data
              .map((g) => ({ ...g, substitutes: g.substitutes.filter((s) => s.id !== id) }))
              .filter((g) => g.substitutes.length > 0),
          }
        : b,
    )
    setDetail((d) => (d ? { ...d, rows: d.rows.filter((r) => r.id !== id) } : d))
  }

  async function onDecide(row: Substitute, approval_status: SubstituteDecision, note: string) {
    setBusy(true)
    setErr('')
    setMsg('')
    try {
      const body: { approval_status: SubstituteDecision; note?: string } = { approval_status }
      if (note.trim() && approval_status !== 'oczekuje') body.note = note.trim()
      const res = await api<Substitute>(`/substitutes/${row.id}/approve`, { method: 'PATCH', body: JSON.stringify(body) })
      patchRow(row.id, {
        approval_status: res.approval_status ?? approval_status,
        decision_note: res.decision_note ?? (approval_status === 'oczekuje' ? null : body.note ?? null),
        approver: res.approver ?? null,
      })
      setMsg(
        approval_status === 'zatwierdzony'
          ? 'Zatwierdzono zamiennik.'
          : approval_status === 'odrzucony'
            ? 'Odrzucono zamiennik.'
            : 'Decyzja cofnięta — zamiennik znów czeka.',
      )
      void loadSummary()
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Błąd zapisu decyzji')
    } finally {
      setBusy(false)
    }
  }

  async function onDelete(row: Substitute) {
    if (!window.confirm('Usunąć ten ręcznie dodany zamiennik?')) return
    setBusy(true)
    setErr('')
    setMsg('')
    try {
      const res = await api<{ ok: boolean; rejected?: boolean }>(`/substitutes/${row.id}`, { method: 'DELETE' })
      if (res.rejected) {
        // propozycja automatu nie znika — backend oznacza ją jako odrzuconą
        patchRow(row.id, { approval_status: 'odrzucony' })
        setMsg('Propozycja automatu oznaczona jako odrzucona.')
      } else {
        dropRow(row.id)
        setMsg('Usunięto.')
      }
      void loadSummary()
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Błąd usuwania')
    } finally {
      setBusy(false)
    }
  }

  function openCreate() {
    setEditingId(null)
    setEditingMainId(null)
    setForm(emptyForm())
    setFormOpen(true)
    setMsg('')
    setErr('')
  }

  function openEdit(row: Substitute, mainCard: SubstituteCardLite | null) {
    const sub = row.card ?? row.substitute_product
    setEditingId(row.id)
    setEditingMainId(mainCard?.id ?? row.main_product_id ?? null)
    setForm({
      main_product_id: mainCard?.id ?? row.main_product_id ?? null,
      substitute_product_id: sub?.id ?? row.substitute_product_id ?? null,
      main_label: mainCard ? `${mainCard.sku} · ${mainCard.name}` : '',
      sub_label: sub ? `${sub.sku} · ${sub.name}` : '',
      type: row.type,
      match_percent: row.match_percent,
      norms_ok: row.norms_ok ?? true,
      certs_ok: row.certs_ok ?? true,
      reason: row.reason ?? '',
      auto: row.source === 'automat',
    })
    setFormOpen(true)
    setMsg('')
    setErr('')
    window.setTimeout(() => formRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' }), 0)
  }

  function closeForm() {
    setFormOpen(false)
    setEditingId(null)
    setEditingMainId(null)
    setForm(emptyForm())
  }

  async function onSave(e: FormEvent) {
    e.preventDefault()
    if (!form.main_product_id || !form.substitute_product_id) {
      setErr('Wybierz kartę główną i zamiennik.')
      return
    }
    setBusy(true)
    setErr('')
    setMsg('')
    const body: Record<string, unknown> = {
      main_product_id: form.main_product_id,
      substitute_product_id: form.substitute_product_id,
      type: form.type,
      reason: form.reason || null,
    }
    if (!form.auto) {
      body.match_percent = form.match_percent
      body.norms_ok = form.norms_ok
      body.certs_ok = form.certs_ok
    }
    try {
      if (editingId) {
        await api(`/substitutes/${editingId}`, { method: 'PATCH', body: JSON.stringify(body) })
        setMsg('Zamiennik zaktualizowany (po zmianie treści wraca do decyzji).')
      } else {
        await api('/substitutes', { method: 'POST', body: JSON.stringify(body) })
        setMsg('Zamiennik dodany.')
      }
      const reloadMain = editingMainId
      closeForm()
      await loadBoard()
      void loadSummary()
      if (expanded && (expanded.mainId === reloadMain || expanded.mainId === form.main_product_id)) void loadDetail(expanded.mainId)
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Błąd zapisu')
    } finally {
      setBusy(false)
    }
  }

  const t = summary?.totals
  const anyFilter = Boolean(debouncedQ || Object.values(filters).some(Boolean))
  const statusKpi = (status: string) => ({
    active: filters.status === status,
    onClick: () => setFilter('status', filters.status === status ? '' : status),
  })

  function renderGroup(g: SubstituteBoardGroup) {
    const open = expanded?.mainId === g.main.id
    const pending = g.substitutes.filter((s) => s.approval_status === 'oczekuje').length
    const groupIds = new Set(g.substitutes.map((s) => s.id))
    const detailRows = open && detail ? detail.rows : []
    const visibleRows = showAllInDetail ? detailRows : detailRows.filter((r) => groupIds.has(r.id))
    const hiddenCount = detailRows.length - detailRows.filter((r) => groupIds.has(r.id)).length

    return (
      <section key={g.main.id} className={`rounded-xl border bg-white shadow-sm ${open ? 'border-slate-400' : 'border-slate-200'}`}>
        <div className="flex flex-col gap-3 p-3 lg:flex-row">
          <div
            className="flex min-w-0 cursor-pointer gap-3 lg:w-[22rem] lg:shrink-0"
            onClick={() => toggleGroup(g.main.id)}
            title={open ? 'Zwiń porównanie' : 'Pokaż porównanie parametrów'}
          >
            <SubstituteThumb url={g.main.thumb_url} size="lg" />
            <div className="min-w-0 flex-1">
              <div className="flex flex-wrap items-center gap-1.5">
                <ManufacturerBadge name={g.main.manufacturer} main />
                {g.main.family_label && (
                  <span className="rounded-full bg-slate-100 px-2 py-px text-[10px] text-slate-600">{g.main.family_label}</span>
                )}
              </div>
              <Link
                to={`/products/${g.main.id}`}
                target="_blank"
                rel="noopener"
                onClick={(e) => e.stopPropagation()}
                className="mt-0.5 line-clamp-2 break-words text-sm font-semibold leading-snug text-slate-900 hover:underline"
                title={g.main.name}
              >
                {g.main.name}
              </Link>
              <p className="mt-0.5 flex flex-wrap items-baseline gap-x-2 text-[11px] text-slate-500">
                <span className="font-mono">{g.main.sku}</span>
                <span className="font-semibold tabular-nums text-slate-800">
                  {g.main.price_pln != null ? formatPln(g.main.price_pln) : <span className="font-normal text-slate-400">brak ceny</span>}
                </span>
              </p>
              {g.chips.length > 0 && (
                <div className="mt-1.5 flex flex-wrap gap-1">
                  {g.chips.map((c) => (
                    <span key={c} className="rounded border border-slate-200 bg-slate-50 px-1.5 py-px text-[10px] text-slate-700">
                      {c}
                    </span>
                  ))}
                </div>
              )}
              <div className="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1">
                <button
                  type="button"
                  onClick={(e) => {
                    e.stopPropagation()
                    toggleGroup(g.main.id)
                  }}
                  className="whitespace-nowrap rounded-md border border-slate-300 px-2 py-0.5 text-[11px] font-medium text-slate-700 hover:bg-slate-50"
                  aria-expanded={open}
                >
                  {open ? '▴ Zwiń porównanie' : '▾ Porównaj parametry'}
                </button>
                <span className="text-[11px] text-slate-500">
                  {g.substitutes.length} {plural(g.substitutes.length, 'zamiennik', 'zamienniki', 'zamienników')}
                  {pending > 0 && <b className="font-medium text-amber-800">, {pending} do decyzji</b>}
                </span>
              </div>
            </div>
          </div>
          <div className="-mx-1 flex min-w-0 flex-1 gap-2 overflow-x-auto px-1 pb-1 lg:flex-wrap lg:content-start lg:overflow-visible">
            {g.substitutes.map((s) => (
              <SubstituteChip
                key={s.id}
                sub={s}
                focused={open && expanded?.focusId === s.id}
                onOpen={() => toggleGroup(g.main.id, s.id)}
              />
            ))}
          </div>
        </div>

        {open && (
          <div className="border-t border-slate-200 p-3">
            {detail?.error ? (
              <p className="rounded bg-red-50 px-3 py-2 text-xs text-red-700">{detail.error}</p>
            ) : !detail || (detail.loading && detail.rows.length === 0) ? (
              <p className="text-xs text-slate-500">Ładowanie porównania…</p>
            ) : (
              <>
                {hiddenCount > 0 && (
                  <p className="mb-2 text-[11px] text-slate-500">
                    {showAllInDetail ? 'Pokazane wszystkie zamienniki tej karty.' : `${hiddenCount} ${plural(hiddenCount, 'zamiennik', 'zamienniki', 'zamienników')} tej karty poza filtrem.`}{' '}
                    <button type="button" className="underline hover:text-slate-800" onClick={() => setShowAllInDetail((v) => !v)}>
                      {showAllInDetail ? 'Tylko z filtra' : 'Pokaż wszystkie'}
                    </button>
                  </p>
                )}
                <SubstituteMatrix
                  mainCard={detail.mainCard ?? g.main}
                  rows={visibleRows}
                  focusId={expanded?.focusId}
                  canApprove={canApprove}
                  canManage={canManage}
                  busy={busy}
                  onDecide={(row, status, note) => void onDecide(row, status, note)}
                  onEdit={(row) => openEdit(row, detail.mainCard ?? g.main)}
                  onDelete={(row) => void onDelete(row)}
                />
              </>
            )}
          </div>
        )}
      </section>
    )
  }

  return (
    <div>
      <div className="app-page-head mb-3 flex flex-wrap items-end justify-between gap-3">
        <div className="max-w-3xl">
          <h1 className="app-page-title text-xl font-semibold">Zamienniki</h1>
          <p className="mt-1 text-xs text-slate-500">
            Automat proponuje karty innych producentów, które spełniają każdy parametr ochronny karty głównej — z cytatem
            źródła przy każdej wartości. Decyzję o zamienniku zawsze podejmuje człowiek.
          </p>
        </div>
        {canManage && !formOpen && (
          <button
            type="button"
            onClick={openCreate}
            className="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50"
          >
            + Dodaj zamiennik ręcznie
          </button>
        )}
      </div>

      <div className="app-kpis mb-3 grid grid-cols-3 gap-2 xl:grid-cols-6">
        <SummaryKpi
          label="Karty główne"
          value={t?.mains ?? null}
          hint="pokaż wszystko"
          active={false}
          title="Wyczyść wszystkie filtry"
          onClick={resetFilters}
        />
        <SummaryKpi
          label="Zamienniki"
          value={t?.rows ?? null}
          hint={t ? `${t.auto} automat · ${t.manual} ręcznych` : undefined}
          active={false}
          title="Wszystkie statusy"
          onClick={() => setFilter('status', '')}
        />
        <SummaryKpi label="Do decyzji" value={t?.pending ?? null} tone="alert" title="Pokaż czekające na decyzję" {...statusKpi('oczekuje')} />
        <SummaryKpi label="Zatwierdzone" value={t?.approved ?? null} title="Pokaż zatwierdzone" {...statusKpi('zatwierdzony')} />
        <SummaryKpi label="Odrzucone" value={t?.rejected ?? null} title="Pokaż odrzucone" {...statusKpi('odrzucony')} />
        {t && t.stale > 0 && (
          <SummaryKpi
            label="Nieaktualne"
            value={t.stale}
            tone="alert"
            hint="wśród zatwierdzonych"
            title="Zatwierdzone pary, których automat już nie potwierdza — na liście z plakietką „nieaktualne”"
            active={false}
            onClick={() => setFilter('status', 'zatwierdzony')}
          />
        )}
      </div>

      <div className="mb-3 grid grid-cols-2 items-center gap-2 rounded-xl border border-slate-200 bg-white p-2.5 sm:flex sm:flex-wrap">
        <input
          className="col-span-2 min-w-[12rem] flex-1 rounded-md border border-slate-300 px-2 py-1.5 text-xs"
          placeholder="Szukaj SKU, nazwy lub producenta…"
          value={q}
          onChange={(e) => setQ(e.target.value)}
        />
        <select
          className="min-w-0 rounded-md border border-slate-300 px-2 py-1.5 text-xs sm:max-w-[12rem]"
          value={filters.family}
          onChange={(e) => setFilter('family', e.target.value)}
          aria-label="Grupa"
        >
          <option value="">Wszystkie grupy</option>
          {(summary?.families ?? []).map((f) =>
            f.key ? (
              <option key={f.key} value={f.key}>
                {f.label} ({f.mains})
              </option>
            ) : (
              <option key="__none" value="__none" disabled>
                {f.label} ({f.mains})
              </option>
            ),
          )}
        </select>
        <select
          className="min-w-0 rounded-md border border-slate-300 px-2 py-1.5 text-xs sm:max-w-[12rem]"
          value={filters.manufacturer}
          onChange={(e) => setFilter('manufacturer', e.target.value)}
          aria-label="Producent karty głównej"
        >
          <option value="">Producent karty głównej</option>
          {(summary?.manufacturers ?? []).map((m) => (
            <option key={m.name} value={m.name}>
              {m.name} ({m.mains})
            </option>
          ))}
        </select>
        <select
          className="min-w-0 rounded-md border border-slate-300 px-2 py-1.5 text-xs"
          value={filters.status}
          onChange={(e) => setFilter('status', e.target.value)}
          aria-label="Status"
        >
          <option value="">Wszystkie statusy</option>
          {SUBSTITUTE_STATUSES.map((s) => (
            <option key={s.value} value={s.value}>
              {s.label}
            </option>
          ))}
        </select>
        <select
          className="min-w-0 rounded-md border border-slate-300 px-2 py-1.5 text-xs"
          value={filters.type}
          onChange={(e) => setFilter('type', e.target.value)}
          aria-label="Typ"
        >
          <option value="">Wszystkie typy</option>
          {SUBSTITUTE_TYPES.map((x) => (
            <option key={x.value} value={x.value}>
              {x.label}
            </option>
          ))}
        </select>
        <select
          className="min-w-0 rounded-md border border-slate-300 px-2 py-1.5 text-xs"
          value={filters.source}
          onChange={(e) => setFilter('source', e.target.value)}
          aria-label="Źródło"
        >
          <option value="">Automat i ręczne</option>
          {SUBSTITUTE_SOURCES.map((x) => (
            <option key={x.value} value={x.value}>
              {x.label}
            </option>
          ))}
        </select>
        {anyFilter && (
          <button type="button" onClick={resetFilters} className="px-1 text-xs text-slate-500 underline hover:text-slate-800">
            Wyczyść
          </button>
        )}
      </div>

      {msg && <p className="mb-2 rounded bg-green-50 px-3 py-2 text-xs text-green-800">{msg}</p>}
      {err && <p className="mb-2 rounded bg-red-50 px-3 py-2 text-xs text-red-700">{err}</p>}

      {formOpen && canManage && (
        <form ref={formRef} onSubmit={onSave} className="mb-4 rounded-xl border border-slate-200 bg-white p-4 text-sm shadow-sm">
          <h2 className="app-card-title mb-3 font-semibold">
            {editingId ? (form.auto ? 'Edycja propozycji automatu' : 'Edycja zamiennika') : 'Nowy zamiennik (ręcznie)'}
          </h2>
          {form.auto && (
            <p className="mb-3 rounded bg-amber-50 px-3 py-2 text-xs text-amber-800">
              Po zmianie treści wiersz staje się ręczny i wraca do decyzji. Dowody automatu zostają do wglądu.
            </p>
          )}
          <div className="grid gap-3 sm:grid-cols-2">
            <ProductSearch
              label="Karta główna *"
              valueLabel={form.main_label}
              excludeId={form.substitute_product_id}
              onPick={(p) =>
                setForm((f) => ({
                  ...f,
                  main_product_id: p.id,
                  main_label: `${p.sku} · ${p.name}`,
                }))
              }
            />
            <ProductSearch
              label="Zamiennik *"
              valueLabel={form.sub_label}
              excludeId={form.main_product_id}
              onPick={(p) =>
                setForm((f) => ({
                  ...f,
                  substitute_product_id: p.id,
                  sub_label: `${p.sku} · ${p.name}`,
                }))
              }
            />
            <label className="block text-xs">
              Typ *
              <select
                className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5"
                value={form.type}
                onChange={(e) => setForm((f) => ({ ...f, type: e.target.value }))}
              >
                {SUBSTITUTE_TYPES.map((x) => (
                  <option key={x.value} value={x.value}>
                    {x.label}
                  </option>
                ))}
              </select>
            </label>
            {!form.auto && (
              <>
                <label className="block text-xs">
                  Zgodność AI (%) *
                  <input
                    type="number"
                    min={0}
                    max={100}
                    required
                    className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5"
                    value={form.match_percent}
                    onChange={(e) => setForm((f) => ({ ...f, match_percent: Number(e.target.value) }))}
                  />
                </label>
                <label className="flex items-center gap-2 text-xs sm:col-span-1">
                  <input
                    type="checkbox"
                    checked={form.norms_ok}
                    onChange={(e) => setForm((f) => ({ ...f, norms_ok: e.target.checked }))}
                  />
                  Zgodność norm
                </label>
                <label className="flex items-center gap-2 text-xs sm:col-span-1">
                  <input
                    type="checkbox"
                    checked={form.certs_ok}
                    onChange={(e) => setForm((f) => ({ ...f, certs_ok: e.target.checked }))}
                  />
                  Zgodność certyfikatów
                </label>
              </>
            )}
            <label className="block text-xs sm:col-span-2">
              Uzasadnienie
              <textarea
                rows={2}
                className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5"
                value={form.reason}
                onChange={(e) => setForm((f) => ({ ...f, reason: e.target.value }))}
                placeholder="Dlaczego ten zamiennik?"
              />
            </label>
          </div>
          <div className="mt-3 flex gap-2">
            <button type="submit" disabled={busy} className="rounded bg-blue-600 px-3 py-2 text-xs text-white disabled:opacity-50">
              {editingId ? 'Zapisz zmiany' : 'Dodaj'}
            </button>
            <button type="button" className="rounded border border-slate-300 px-3 py-2 text-xs" onClick={closeForm}>
              Anuluj
            </button>
          </div>
        </form>
      )}

      <div className="mb-2 flex flex-wrap items-center justify-between gap-2 text-xs text-slate-500">
        <span>
          {board
            ? `${board.total.toLocaleString('pl-PL')} ${plural(board.total, 'karta główna', 'karty główne', 'kart głównych')}${anyFilter ? ' (z filtrem)' : ''}`
            : ''}
          {loading ? ' · ładowanie…' : ''}
        </span>
        <Pager meta={board} disabled={loading} onPage={goPage} />
      </div>

      <div className={`space-y-3 transition-opacity ${loading && board ? 'opacity-60' : ''}`}>
        {board?.data.map(renderGroup)}
        {!loading && board && board.data.length === 0 && (
          <p className="rounded-xl border border-dashed border-slate-300 bg-white p-6 text-center text-xs text-slate-500">
            Brak zamienników dla wybranych filtrów.
          </p>
        )}
      </div>

      <Pager meta={board} disabled={loading} onPage={goPage} />
    </div>
  )
}
