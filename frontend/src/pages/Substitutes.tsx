import { useEffect, useMemo, useState, type FormEvent } from 'react'
import { useAuth } from '../auth'
import { api, can, type Product, type Substitute } from '../lib/api'

const TYPES = [
  { value: 'preferowany', label: 'Preferowany' },
  { value: 'tanszy', label: 'Tańszy' },
  { value: 'premium', label: 'Premium' },
  { value: 'awaryjny', label: 'Awaryjny' },
] as const

const STATUSES = [
  { value: '', label: 'Wszystkie statusy' },
  { value: 'oczekuje', label: 'Oczekuje' },
  { value: 'zatwierdzony', label: 'Zatwierdzony' },
  { value: 'odrzucony', label: 'Odrzucony' },
] as const

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
})

type ProductsPage = { data: Product[] }

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

function statusClass(status: string): string {
  if (status === 'zatwierdzony') return 'bg-green-100 text-green-800'
  if (status === 'odrzucony') return 'bg-red-100 text-red-800'
  return 'bg-amber-100 text-amber-800'
}

export function Substitutes() {
  const { user } = useAuth()
  const canManage = can(user, 'substitutes.manage')
  const canApprove = can(user, 'substitutes.approve')

  const [rows, setRows] = useState<Substitute[]>([])
  const [loading, setLoading] = useState(false)
  const [q, setQ] = useState('')
  const [debouncedQ, setDebouncedQ] = useState('')
  const [status, setStatus] = useState('')
  const [type, setType] = useState('')
  const [formOpen, setFormOpen] = useState(false)
  const [editingId, setEditingId] = useState<number | null>(null)
  const [form, setForm] = useState<FormState>(emptyForm)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const [msg, setMsg] = useState('')

  useEffect(() => {
    const t = window.setTimeout(() => setDebouncedQ(q.trim()), 300)
    return () => window.clearTimeout(t)
  }, [q])

  async function load() {
    setLoading(true)
    setErr('')
    try {
      const params = new URLSearchParams()
      if (debouncedQ) params.set('q', debouncedQ)
      if (status) params.set('approval_status', status)
      if (type) params.set('type', type)
      const qs = params.toString()
      setRows(await api<Substitute[]>(`/substitutes${qs ? `?${qs}` : ''}`))
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Błąd ładowania')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    void load()
  }, [debouncedQ, status, type])

  const groups = useMemo(() => {
    const map = new Map<number, { main: Substitute['main_product']; items: Substitute[] }>()
    for (const s of rows) {
      const id = s.main_product?.id ?? s.main_product_id
      if (!id) continue
      if (!map.has(id)) map.set(id, { main: s.main_product, items: [] })
      map.get(id)!.items.push(s)
    }
    return [...map.values()]
  }, [rows])

  function openCreate() {
    setEditingId(null)
    setForm(emptyForm())
    setFormOpen(true)
    setMsg('')
    setErr('')
  }

  function openEdit(s: Substitute) {
    setEditingId(s.id)
    setForm({
      main_product_id: s.main_product?.id ?? s.main_product_id ?? null,
      substitute_product_id: s.substitute_product?.id ?? s.substitute_product_id ?? null,
      main_label: s.main_product
        ? `${s.main_product.sku} · ${s.main_product.name}`
        : '',
      sub_label: s.substitute_product
        ? `${s.substitute_product.sku} · ${s.substitute_product.name}`
        : '',
      type: s.type,
      match_percent: s.match_percent,
      norms_ok: s.norms_ok ?? true,
      certs_ok: s.certs_ok ?? true,
      reason: s.reason ?? '',
    })
    setFormOpen(true)
    setMsg('')
    setErr('')
  }

  async function onSave(e: FormEvent) {
    e.preventDefault()
    if (!form.main_product_id || !form.substitute_product_id) {
      setErr('Wybierz produkt główny i zamiennik.')
      return
    }
    setBusy(true)
    setErr('')
    setMsg('')
    const body = {
      main_product_id: form.main_product_id,
      substitute_product_id: form.substitute_product_id,
      type: form.type,
      match_percent: form.match_percent,
      norms_ok: form.norms_ok,
      certs_ok: form.certs_ok,
      reason: form.reason || null,
    }
    try {
      if (editingId) {
        await api(`/substitutes/${editingId}`, { method: 'PATCH', body: JSON.stringify(body) })
        setMsg('Zamiennik zaktualizowany (status wrócił do „oczekuje”).')
      } else {
        await api('/substitutes', { method: 'POST', body: JSON.stringify(body) })
        setMsg('Zamiennik dodany.')
      }
      setFormOpen(false)
      setEditingId(null)
      setForm(emptyForm())
      await load()
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Błąd zapisu')
    } finally {
      setBusy(false)
    }
  }

  async function onDelete(id: number) {
    if (!window.confirm('Usunąć ten zamiennik?')) return
    setBusy(true)
    setErr('')
    try {
      await api(`/substitutes/${id}`, { method: 'DELETE' })
      setMsg('Usunięto.')
      await load()
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Błąd usuwania')
    } finally {
      setBusy(false)
    }
  }

  async function onApprove(id: number, approval_status: string) {
    setBusy(true)
    setErr('')
    try {
      await api(`/substitutes/${id}/approve`, {
        method: 'PATCH',
        body: JSON.stringify({ approval_status }),
      })
      setMsg(approval_status === 'zatwierdzony' ? 'Zatwierdzono.' : 'Odrzucono.')
      await load()
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Błąd akceptacji')
    } finally {
      setBusy(false)
    }
  }

  const typeLabel = (v: string) => TYPES.find((t) => t.value === v)?.label ?? v

  return (
    <div>
      <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold">Zamienniki</h1>
          <p className="mt-1 text-xs text-slate-500">
            Relacja produkt główny → zamienniki · {rows.length} pozycji
            {loading ? ' · ładowanie…' : ''}
          </p>
        </div>
        {canManage && (
          <button
            type="button"
            onClick={openCreate}
            className="rounded bg-blue-600 px-3 py-2 text-xs text-white hover:bg-blue-700"
          >
            + Dodaj zamiennik
          </button>
        )}
      </div>

      <div className="mb-4 flex flex-wrap gap-2 rounded-xl border border-slate-200 bg-white p-3">
        <input
          className="min-w-[200px] flex-1 rounded border border-slate-300 px-2 py-1.5 text-xs"
          placeholder="Szukaj SKU / nazwy…"
          value={q}
          onChange={(e) => setQ(e.target.value)}
        />
        <select
          className="rounded border border-slate-300 px-2 py-1.5 text-xs"
          value={status}
          onChange={(e) => setStatus(e.target.value)}
        >
          {STATUSES.map((s) => (
            <option key={s.value || 'all'} value={s.value}>
              {s.label}
            </option>
          ))}
        </select>
        <select
          className="rounded border border-slate-300 px-2 py-1.5 text-xs"
          value={type}
          onChange={(e) => setType(e.target.value)}
        >
          <option value="">Wszystkie typy</option>
          {TYPES.map((t) => (
            <option key={t.value} value={t.value}>
              {t.label}
            </option>
          ))}
        </select>
      </div>

      {msg && <p className="mb-2 rounded bg-green-50 px-3 py-2 text-xs text-green-800">{msg}</p>}
      {err && <p className="mb-2 rounded bg-red-50 px-3 py-2 text-xs text-red-700">{err}</p>}

      {formOpen && canManage && (
        <form onSubmit={onSave} className="mb-4 rounded-xl bg-white p-4 shadow-sm text-sm">
          <h2 className="mb-3 font-semibold">
            {editingId ? 'Edycja zamiennika' : 'Nowy zamiennik'}
          </h2>
          <div className="grid gap-3 sm:grid-cols-2">
            <ProductSearch
              label="Produkt główny *"
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
                {TYPES.map((t) => (
                  <option key={t.value} value={t.value}>
                    {t.label}
                  </option>
                ))}
              </select>
            </label>
            <label className="block text-xs">
              Zgodność AI (%) *
              <input
                type="number"
                min={0}
                max={100}
                required
                className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5"
                value={form.match_percent}
                onChange={(e) =>
                  setForm((f) => ({ ...f, match_percent: Number(e.target.value) }))
                }
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
            <button
              type="submit"
              disabled={busy}
              className="rounded bg-blue-600 px-3 py-2 text-xs text-white disabled:opacity-50"
            >
              {editingId ? 'Zapisz zmiany' : 'Dodaj'}
            </button>
            <button
              type="button"
              className="rounded border border-slate-300 px-3 py-2 text-xs"
              onClick={() => {
                setFormOpen(false)
                setEditingId(null)
              }}
            >
              Anuluj
            </button>
          </div>
        </form>
      )}

      <div className="space-y-3">
        {groups.map((g) => (
          <div key={g.main?.id} className="overflow-hidden rounded-xl border border-slate-200 bg-white">
            <div className="border-b bg-slate-50 px-4 py-3 text-sm font-semibold">
              <span className="mr-1 rounded bg-slate-800 px-1.5 py-0.5 text-[10px] text-white">
                PRODUKT GŁÓWNY
              </span>
              {g.main?.name} · {g.main?.sku}
            </div>
            <table className="w-full text-left text-xs">
              <thead>
                <tr className="border-b bg-slate-50/80">
                  <th className="p-2">Zamiennik</th>
                  <th className="p-2">Typ</th>
                  <th className="p-2">AI</th>
                  <th className="p-2">Normy / Cert.</th>
                  <th className="p-2">Powód</th>
                  <th className="p-2">Status</th>
                  <th className="p-2">Akcje</th>
                </tr>
              </thead>
              <tbody>
                {g.items.map((s) => (
                  <tr key={s.id} className="border-b">
                    <td className="p-2">
                      {s.substitute_product?.name} ({s.substitute_product?.sku})
                    </td>
                    <td className="p-2">{typeLabel(s.type)}</td>
                    <td className="p-2">{s.match_percent}%</td>
                    <td className="p-2">
                      {s.norms_ok === false ? '✗' : '✓'} / {s.certs_ok === false ? '✗' : '✓'}
                    </td>
                    <td className="max-w-[200px] truncate p-2" title={s.reason ?? ''}>
                      {s.reason ?? '—'}
                    </td>
                    <td className="p-2">
                      <span className={`rounded px-1.5 py-0.5 text-[10px] ${statusClass(s.approval_status)}`}>
                        {s.approval_status}
                      </span>
                      {s.approver?.name && (
                        <span className="mt-0.5 block text-[10px] text-slate-400">
                          {s.approver.name}
                        </span>
                      )}
                    </td>
                    <td className="p-2">
                      <div className="flex flex-wrap gap-1">
                        {canManage && (
                          <>
                            <button
                              type="button"
                              disabled={busy}
                              className="rounded border border-slate-300 px-2 py-1 text-[10px]"
                              onClick={() => openEdit(s)}
                            >
                              Edytuj
                            </button>
                            <button
                              type="button"
                              disabled={busy}
                              className="rounded border border-red-200 px-2 py-1 text-[10px] text-red-700"
                              onClick={() => void onDelete(s.id)}
                            >
                              Usuń
                            </button>
                          </>
                        )}
                        {canApprove && s.approval_status === 'oczekuje' && (
                          <>
                            <button
                              type="button"
                              disabled={busy}
                              className="rounded bg-green-600 px-2 py-1 text-[10px] text-white"
                              onClick={() => void onApprove(s.id, 'zatwierdzony')}
                            >
                              OK
                            </button>
                            <button
                              type="button"
                              disabled={busy}
                              className="rounded bg-red-600 px-2 py-1 text-[10px] text-white"
                              onClick={() => void onApprove(s.id, 'odrzucony')}
                            >
                              Nie
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
        ))}
        {!loading && groups.length === 0 && (
          <p className="rounded-xl border border-dashed border-slate-300 bg-white p-6 text-center text-xs text-slate-500">
            Brak zamienników dla wybranych filtrów.
          </p>
        )}
      </div>
    </div>
  )
}
