import { useEffect, useMemo, useState } from 'react'
import { api } from '../lib/api'

type CatalogSlangEntry = {
  category: string
  terms: string[]
  phrases: string[]
  note: string
  keywords: string[]
  tags: string[]
}

type Payload = {
  entries: CatalogSlangEntry[]
  defaults: CatalogSlangEntry[]
  categories: Record<string, string>
}

type SortKey = 'category' | 'terms' | 'phrases'

function csv(list: string[] | undefined): string {
  return (list ?? []).join(', ')
}

function parseCsv(value: string): string[] {
  return value
    .split(/[,;]+/)
    .map((s) => s.trim())
    .filter(Boolean)
}

function hay(row: CatalogSlangEntry): string {
  return `${row.terms.join(' ')} ${row.phrases.join(' ')} ${row.keywords.join(' ')} ${row.tags.join(' ')} ${row.note}`.toLowerCase()
}

function emptyRow(category: string): CatalogSlangEntry {
  return {
    category,
    terms: [],
    phrases: [],
    note: '',
    keywords: [],
    tags: [],
  }
}

function SortMark({ active, dir }: { active: boolean; dir: 'asc' | 'desc' }) {
  if (!active) return <span className="ml-1 text-slate-300">↕</span>
  return <span className="ml-1 text-sky-600">{dir === 'asc' ? '↑' : '↓'}</span>
}

export function AdminCatalogSlang() {
  const [rows, setRows] = useState<CatalogSlangEntry[]>([])
  const [defaults, setDefaults] = useState<CatalogSlangEntry[]>([])
  const [categories, setCategories] = useState<Record<string, string>>({})
  const [cat, setCat] = useState('')
  const [q, setQ] = useState('')
  const [sortKey, setSortKey] = useState<SortKey>('category')
  const [sortDir, setSortDir] = useState<'asc' | 'desc'>('asc')
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const [msg, setMsg] = useState('')

  async function load() {
    setErr('')
    const data = await api<Payload>('/admin/catalog-slang')
    setRows(
      (data.entries ?? []).map((row) => ({
        ...row,
        keywords: row.keywords ?? [],
        tags: row.tags ?? [],
      })),
    )
    setDefaults(
      (data.defaults ?? []).map((row) => ({
        ...row,
        keywords: row.keywords ?? [],
        tags: row.tags ?? [],
      })),
    )
    setCategories(data.categories ?? {})
  }

  useEffect(() => {
    void load().catch((e: Error) => setErr(e.message))
  }, [])

  const visible = useMemo(() => {
    const needle = q.trim().toLowerCase()
    let list = rows.map((row, index) => ({ row, index }))
    if (cat) list = list.filter((item) => item.row.category === cat)
    if (needle) list = list.filter((item) => hay(item.row).includes(needle))
    list.sort((a, b) => {
      const av =
        sortKey === 'category'
          ? (categories[a.row.category] ?? a.row.category)
          : csv(a.row[sortKey]).toLowerCase()
      const bv =
        sortKey === 'category'
          ? (categories[b.row.category] ?? b.row.category)
          : csv(b.row[sortKey]).toLowerCase()
      const cmp = av.localeCompare(bv, 'pl')
      return sortDir === 'asc' ? cmp : -cmp
    })
    return list
  }, [rows, cat, q, sortKey, sortDir, categories])

  function patch(index: number, next: CatalogSlangEntry) {
    setRows((prev) => prev.map((row, i) => (i === index ? next : row)))
    setMsg('')
  }

  function toggleSort(key: SortKey) {
    if (sortKey === key) {
      setSortDir((d) => (d === 'asc' ? 'desc' : 'asc'))
      return
    }
    setSortKey(key)
    setSortDir('asc')
  }

  async function save() {
    setBusy(true)
    setErr('')
    setMsg('')
    try {
      const catalog_slang = rows
        .filter((row) => row.terms.some((t) => t.trim() !== '') && row.phrases.some((p) => p.trim() !== ''))
        .map((row) => ({
          category: row.category,
          terms: row.terms,
          phrases: row.phrases,
          note: row.note,
          keywords: row.keywords ?? [],
          tags: row.tags ?? [],
        }))
      const data = await api<Payload>('/admin/catalog-slang', {
        method: 'PUT',
        body: JSON.stringify({ catalog_slang }),
      })
      setRows(
        (data.entries ?? []).map((row) => ({
          ...row,
          keywords: row.keywords ?? [],
          tags: row.tags ?? [],
        })),
      )
      setMsg(`Zapisano ${data.entries?.length ?? 0} wpisów.`)
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Nie udało się zapisać słownika')
    } finally {
      setBusy(false)
    }
  }

  const headers: { key: SortKey; label: string }[] = [
    { key: 'category', label: 'Kategoria' },
    { key: 'terms', label: 'Żargon' },
    { key: 'phrases', label: 'Frazy z cennika' },
  ]

  return (
    <div className="space-y-4">
      <div className="overflow-hidden rounded-2xl border border-slate-200 bg-gradient-to-br from-violet-50 via-white to-sky-50 p-5 shadow-sm">
        <p className="text-[11px] font-semibold uppercase tracking-wide text-violet-700">Wyszukiwarka SIWZ</p>
        <h2 className="mt-1 text-lg font-semibold text-slate-900">Słownik żargonu</h2>
        <p className="mt-1 max-w-3xl text-[12px] text-slate-600">
          Trafienie w żargon zastępuje zapytanie: szukamy po notatce i frazach z cennika
          (np. wampirki → „Proste dzianinowe powlekane, ochrona przed cieczą”), w ramach
          kategorii — żeby „pianki” przy rękawicach nie szukały zatyczek do uszu.
        </p>
      </div>

      <div className="flex flex-wrap items-center gap-2 rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">
        <select
          className="rounded-lg border border-slate-300 px-2 py-1.5 text-xs"
          value={cat}
          onChange={(e) => setCat(e.target.value)}
        >
          <option value="">Wszystkie kategorie</option>
          {Object.entries(categories).map(([id, label]) => (
            <option key={id} value={id}>
              {label}
            </option>
          ))}
        </select>
        <input
          className="min-w-[12rem] flex-1 rounded-lg border border-slate-300 px-2 py-1.5 text-xs"
          placeholder="Filtruj żargon…"
          value={q}
          onChange={(e) => setQ(e.target.value)}
        />
        <button
          type="button"
          className="rounded-lg border border-slate-300 px-3 py-1.5 text-xs hover:bg-slate-50"
          onClick={() => setRows((prev) => [...prev, emptyRow(cat || 'rece')])}
        >
          Dodaj wpis
        </button>
        <button
          type="button"
          className="rounded-lg border border-slate-300 px-3 py-1.5 text-xs hover:bg-slate-50"
          onClick={() => {
            setRows(defaults)
            setMsg('Przywrócono zestaw startowy — kliknij Zapisz, żeby utrwalić.')
          }}
        >
          Przywróć zestaw startowy
        </button>
        <button
          type="button"
          disabled={busy}
          onClick={() => void save()}
          className="rounded-lg bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white disabled:opacity-50"
        >
          {busy ? 'Zapisuję…' : 'Zapisz'}
        </button>
        <span className="text-[11px] text-slate-500">
          {visible.length} z {rows.length}
        </span>
      </div>

      {err && (
        <p className="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-[12px] text-rose-800">{err}</p>
      )}
      {msg && (
        <p className="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-[12px] text-emerald-800">{msg}</p>
      )}

      <div className="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
        <table className="w-full min-w-[64rem] text-left text-[13px]">
          <thead>
            <tr className="border-b bg-slate-50 text-[11px] uppercase tracking-wide text-slate-500">
              {headers.map((h) => (
                <th key={h.key} className="p-2">
                  <button
                    type="button"
                    onClick={() => toggleSort(h.key)}
                    className="inline-flex items-center font-semibold hover:text-sky-700"
                  >
                    {h.label}
                    <SortMark active={sortKey === h.key} dir={sortDir} />
                  </button>
                </th>
              ))}
              <th className="p-2">Słowa kluczowe</th>
              <th className="p-2">Notatka</th>
              <th className="p-2" />
            </tr>
          </thead>
          <tbody>
            {visible.map(({ row, index }) => (
              <tr key={`${row.category}-${index}`} className="border-b align-top last:border-b-0">
                <td className="p-2">
                  <select
                    className="w-full min-w-[8rem] rounded border border-slate-300 px-1 py-1 text-xs"
                    value={row.category}
                    onChange={(e) => patch(index, { ...row, category: e.target.value })}
                  >
                    {Object.entries(categories).map(([id, label]) => (
                      <option key={id} value={id}>
                        {label}
                      </option>
                    ))}
                  </select>
                </td>
                <td className="p-2">
                  <input
                    className="w-full min-w-[10rem] rounded border border-slate-300 px-2 py-1 text-xs"
                    value={csv(row.terms)}
                    onChange={(e) => patch(index, { ...row, terms: parseCsv(e.target.value) })}
                    placeholder="wampirki, wampiry"
                  />
                </td>
                <td className="p-2">
                  <input
                    className="w-full min-w-[12rem] rounded border border-slate-300 px-2 py-1 text-xs"
                    value={csv(row.phrases)}
                    onChange={(e) => patch(index, { ...row, phrases: parseCsv(e.target.value) })}
                    placeholder="rękawice powlekane"
                  />
                </td>
                <td className="p-2">
                  <input
                    className="w-full min-w-[9rem] rounded border border-slate-300 px-2 py-1 text-xs"
                    value={csv(row.keywords)}
                    onChange={(e) => patch(index, { ...row, keywords: parseCsv(e.target.value) })}
                    placeholder="po przecinku"
                  />
                </td>
                <td className="p-2">
                  <input
                    className="w-full min-w-[8rem] rounded border border-slate-300 px-2 py-1 text-xs text-slate-600"
                    value={row.note}
                    onChange={(e) => patch(index, { ...row, note: e.target.value })}
                    placeholder="opcjonalnie"
                  />
                </td>
                <td className="p-2">
                  <button
                    type="button"
                    className="text-xs text-red-700 underline"
                    onClick={() => setRows((prev) => prev.filter((_, i) => i !== index))}
                  >
                    Usuń
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        {visible.length === 0 && (
          <p className="px-4 py-6 text-center text-xs text-slate-500">Brak wpisów dla tego filtra.</p>
        )}
      </div>
    </div>
  )
}
