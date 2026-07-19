import { useCallback, useEffect, useRef, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useAuth } from '../auth'
import { ProductAiMatchModal } from '../components/ProductAiMatchModal'
import { ProductPreviewModal } from '../components/ProductPreviewModal'
import { ProductSearchSelect } from '../components/ProductSearchSelect'
import { api, downloadFile, type Product, type Substitute, type Tender } from '../lib/api'

type Item = {
  id: number
  line_no: number
  requirement: string
  ai_match_percent: number | null
  quantity: number
  offer_price: string | null
  margin_percent: string | null
  status: string
  main_product: Product | null
  main_product_id?: number | null
}

function itemProductId(item: Item): string {
  const id = item.main_product_id ?? item.main_product?.id
  return id != null ? String(id) : ''
}

type ItemDraft = {
  main_product_id: number | null
  quantity: number
  offer_price: number | null
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
  selected: boolean
}
type PreviewCondition = { category: string | null; content: string; selected: boolean }

type Detail = {
  tender: Tender & {
    items: Item[]
    conditions?: Condition[]
    documents?: DocMeta[]
    title: string
    status_histories?: History[]
  }
  substitutes_by_main: Record<string, Substitute[]>
  can_edit: boolean
  next_statuses: string[]
}

const tabs = ['pozycje', 'warunki', 'dokumenty', 'zamienniki', 'oferta', 'workflow'] as const

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

  const load = useCallback(async () => {
    const d = await api<Detail>(`/tenders/${id}`)
    setData(d)
  }, [id])

  useEffect(() => {
    void load()
    void api<{ data: Product[] }>('/products?per_page=100').then((p) => setProducts(p.data ?? []))
  }, [load])

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
      setMsg('Zapisano pozycję.')
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
      setMsg(`Zapisano całość: ${res.updated} pozycji.`)
    } catch (e) {
      setErr(e instanceof Error ? e.message : 'Błąd zapisu całości')
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
        .map(({ sku, name, requirement, quantity, offer_price, currency }) => ({
          sku: sku ?? null,
          name: name ?? requirement,
          requirement,
          quantity,
          offer_price: offer_price ?? null,
          currency: currency ?? null,
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
    setBusy(true)
    try {
      await api(`/tenders/${id}/transition`, {
        method: 'POST',
        body: JSON.stringify({ status }),
      })
      await load()
      setMsg(`Status: ${statusLabel[status] ?? status}`)
    } catch (e) {
      setErr(e instanceof Error ? e.message : 'Błąd statusu')
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

  async function runMatch(onlyEmpty: boolean) {
    setErr('')
    setMsg('')
    setBusy(true)
    try {
      const res = await api<{ matched: number; skipped: number; avg_score: number }>(
        `/tenders/${id}/match`,
        {
          method: 'POST',
          body: JSON.stringify({ only_empty: onlyEmpty }),
        },
      )
      await load()
      setMsg(
        `Dopasowanie: ${res.matched} pozycji (pominięte ${res.skipped}), średni wynik ${res.avg_score}%.`,
      )
      setTab('pozycje')
    } catch (e) {
      setErr(e instanceof Error ? e.message : 'Błąd dopasowania')
    } finally {
      setBusy(false)
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

  const { tender, substitutes_by_main, can_edit, next_statuses } = data
  const canApproveSub = Boolean(user?.permissions?.includes('substitutes.approve'))

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
          {tender.client?.name} · <strong>{statusLabel[tender.status] ?? tender.status}</strong> · AI{' '}
          {tender.ai_percent}% · marża {tender.margin_percent}% ·{' '}
          {can_edit ? 'edycja włączona' : 'tylko podgląd'}
        </p>
        <div className="flex flex-wrap gap-1">
          {can_edit && (
            <>
              <button
                type="button"
                disabled={busy}
                onClick={() => void runMatch(true)}
                title="Tylko pozycje bez produktu — zapisanych nie rusza"
                className="rounded bg-violet-600 px-2 py-1.5 text-[11px] text-white disabled:opacity-50"
              >
                Dopasuj AI (puste)
              </button>
            </>
          )}
          <button
            type="button"
            disabled={busy}
            onClick={() => void exportOffer('excel')}
            className="rounded bg-emerald-600 px-2 py-1.5 text-[11px] text-white disabled:opacity-50"
          >
            Excel
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

      <div className="mb-3 flex flex-wrap gap-1 border-b border-slate-200 pb-2">
        {tabs.map((t) => (
          <button
            key={t}
            type="button"
            onClick={() => setTab(t)}
            className={`rounded-t px-3 py-2 text-xs capitalize ${
              tab === t ? 'bg-sky-100 font-semibold text-blue-700' : 'bg-slate-100 text-slate-600'
            }`}
          >
            {t}
          </button>
        ))}
      </div>

      {tab === 'pozycje' && (
        <div className="space-y-3">
          {can_edit && (
            <div className="flex justify-end">
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
          <div className="overflow-x-auto rounded-xl bg-white p-4 shadow-sm">
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
                {tender.items.map((item) => (
                  <ItemRow
                    key={item.id}
                    item={item}
                    products={products}
                    canEdit={can_edit}
                    busy={busy}
                    onSave={saveItem}
                    onDraftChange={registerItemDraft}
                  />
                ))}
              </tbody>
            </table>
          </div>
          {can_edit && (
            <div className="flex justify-end">
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
                              <th className="p-1.5">Nazwa</th>
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
                                <td className="p-1.5">{it.name || it.requirement}</td>
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
                    Poz. {item.line_no} · {item.main_product!.name} ({item.main_product!.sku})
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
                    <td className="p-2">{item.main_product?.sku}</td>
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

      {tab === 'workflow' && (
        <div className="space-y-4">
          <div className="rounded-xl bg-white p-4 shadow-sm">
            <h2 className="mb-2 text-sm font-semibold">Zmiana statusu</h2>
            <p className="mb-3 text-xs text-slate-500">
              Teraz: <strong>{statusLabel[tender.status] ?? tender.status}</strong>
            </p>
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
                  </tr>
                ))}
                {(tender.status_histories ?? []).length === 0 && (
                  <tr>
                    <td colSpan={3} className="p-3 text-slate-400">
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
  item,
  products,
  canEdit,
  busy,
  onSave,
  onDraftChange,
}: {
  item: Item
  products: Product[]
  canEdit: boolean
  busy: boolean
  onSave: (id: number, patch: Record<string, unknown>) => Promise<void>
  onDraftChange: (itemId: number, draft: ItemDraft) => void
}) {
  const [productId, setProductId] = useState<string>(itemProductId(item))
  const [picked, setPicked] = useState<{ id: number; sku: string; name: string } | null>(
    item.main_product
      ? { id: item.main_product.id, sku: item.main_product.sku, name: item.main_product.name }
      : null,
  )
  const [qty, setQty] = useState(String(item.quantity))
  const [price, setPrice] = useState(item.offer_price ?? '')
  const [matchHint, setMatchHint] = useState('')
  const [previewId, setPreviewId] = useState<number | null>(null)
  const [aiModalOpen, setAiModalOpen] = useState(false)

  useEffect(() => {
    setProductId(itemProductId(item))
    setPicked(
      item.main_product
        ? { id: item.main_product.id, sku: item.main_product.sku, name: item.main_product.name }
        : null,
    )
    setQty(String(item.quantity))
    setPrice(item.offer_price ?? '')
  }, [item])

  useEffect(() => {
    onDraftChange(item.id, {
      main_product_id: productId ? Number(productId) : null,
      quantity: Number(qty) || 1,
      offer_price: price === '' ? null : Number(String(price).replace(',', '.')),
    })
  }, [item.id, productId, qty, price, onDraftChange])

  const selectedProduct =
    picked && String(picked.id) === productId
      ? picked
      : item.main_product && String(item.main_product.id) === productId
        ? item.main_product
        : null

  const hasSavedProduct = Boolean(selectedProduct || productId)

  return (
    <tr className="border-b align-top">
      <td className="p-2">{item.line_no}</td>
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
                setPicked({ id: p.id, sku: p.sku, name: p.name })
                setMatchHint(`AI: ${p.sku} (${p.score}%)`)
                setAiModalOpen(false)
              }}
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
                  <span className="block truncate text-[10px] text-slate-600">
                    {item.main_product.name}
                  </span>
                  {item.ai_match_percent != null && (
                    <span className="mt-0.5 block text-[10px] text-violet-700">
                      Dopasowanie AI: {item.ai_match_percent}%
                    </span>
                  )}
                </span>
              </button>
            ) : (
              '—'
            )}
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
      <td className="p-2">
        {canEdit ? (
          <input
            className="w-20 rounded border border-slate-300 px-1 py-1"
            value={price}
            onChange={(e) => setPrice(e.target.value)}
          />
        ) : (
          (item.offer_price ?? '—')
        )}
      </td>
      <td className="p-2">{item.margin_percent ?? '—'}%</td>
      <td className="p-2">
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
              })
            }
          >
            Zapisz
          </button>
        )}
      </td>
    </tr>
  )
}
