import { useEffect, useMemo, useState } from 'react'
import type { FormEvent } from 'react'
import { ApiError, api } from '../lib/api'

type EntryKind = 'producer' | 'brand' | 'exclusion'

type DictionaryEntry = {
  id: number
  term: string
  term_key: string
  kind: EntryKind
  manufacturer: string | null
  detect_in_query: boolean
  note: string | null
  updated_at: string | null
}

type CatalogProducer = {
  name: string
  key: string
  cards_count: number
  entry_id: number | null
  detect_in_query: boolean
}

type Payload = {
  entries: DictionaryEntry[]
  producers: CatalogProducer[]
  kinds: Record<string, string>
}

type Draft = {
  term: string
  kind: EntryKind
  manufacturer: string
  detect_in_query: boolean
  note: string
}

type SortKey = 'name' | 'cards' | 'detect'

/**
 * Wiersz słownika: zapisany wpis, producent z katalogu albo jedno i drugie — słowo ma najwyżej jeden wpis
 * (term_key jest unikalny), więc producent z katalogu i wpis o tym samym słowie to zawsze jeden wiersz.
 */
type Row = {
  key: string
  term: string
  kind: EntryKind
  entry: DictionaryEntry | null
  producer: CatalogProducer | null
  /** null przy wykluczeniu — ono nigdy nie jest rozpoznawane */
  detect: boolean | null
}

const KIND_BADGE: Record<EntryKind, string> = {
  producer: 'bg-slate-100 text-slate-700',
  brand: 'bg-violet-50 text-violet-700',
  exclusion: 'bg-amber-100 text-amber-800',
}

function emptyDraft(): Draft {
  return { term: '', kind: 'brand', manufacturer: '', detect_in_query: true, note: '' }
}

function draftFrom(entry: DictionaryEntry): Draft {
  return {
    term: entry.term,
    kind: entry.kind,
    manufacturer: entry.manufacturer ?? '',
    detect_in_query: entry.detect_in_query,
    note: entry.note ?? '',
  }
}

/** Ciało POST/PATCH: producent tylko przy marce, flaga rozpoznawania nie dotyczy wykluczeń. */
function payloadFrom(draft: Draft): Record<string, unknown> {
  const body: Record<string, unknown> = {
    term: draft.term.trim(),
    kind: draft.kind,
    manufacturer: draft.kind === 'brand' ? draft.manufacturer || null : null,
    note: draft.note.trim() || null,
  }
  if (draft.kind !== 'exclusion') body.detect_in_query = draft.detect_in_query
  return body
}

/**
 * PATCH wysyła tylko zmienione pola — np. sama zmiana notatki marki nie wysyła producenta,
 * więc nie blokuje się, gdy producent zniknął z katalogu.
 */
function patchFrom(entry: DictionaryEntry, draft: Draft): Record<string, unknown> {
  const full = payloadFrom(draft)
  const original: Record<string, unknown> = {
    term: entry.term,
    kind: entry.kind,
    manufacturer: entry.kind === 'brand' ? entry.manufacturer : null,
    detect_in_query: entry.detect_in_query,
    note: entry.note,
  }
  return Object.fromEntries(Object.entries(full).filter(([field, value]) => value !== original[field]))
}

/** Przy 422 Laravel podaje w `message` tylko pierwszy błąd („… (and 1 more error)”) — pokazujemy wszystkie. */
function errorText(ex: unknown, fallback: string): string {
  if (ex instanceof ApiError && ex.status === 422) {
    const errors = ex.body.errors as Record<string, string[]> | undefined
    const list = errors ? Object.values(errors).flat() : []
    if (list.length > 0) return list.join(' ')
  }
  return ex instanceof Error ? ex.message : fallback
}

function localDraftError(draft: Draft): string {
  if (draft.term.trim() === '') return 'Podaj słowo.'
  if (draft.kind === 'brand' && draft.manufacturer === '') return 'Marka musi mieć wskazanego producenta.'
  return ''
}

function cardsLabel(n: number): string {
  const lastTwo = n % 100
  const last = n % 10
  if (n === 1) return '1 karta'
  if (last >= 2 && last <= 4 && (lastTwo < 12 || lastTwo > 14)) return `${n} karty`
  return `${n} kart`
}

function SortMark({ active, dir }: { active: boolean; dir: 'asc' | 'desc' }) {
  if (!active) return <span className="ml-1 text-slate-300">↕</span>
  return <span className="ml-1 text-sky-600">{dir === 'asc' ? '↑' : '↓'}</span>
}

function ManufacturerSelect({
  value,
  onChange,
  options,
  className,
}: {
  value: string
  onChange: (value: string) => void
  options: CatalogProducer[]
  className: string
}) {
  const known = options.some((p) => p.name === value)
  return (
    <select className={className} value={value} onChange={(e) => onChange(e.target.value)}>
      <option value="">— wybierz producenta —</option>
      {value !== '' && !known && <option value={value}>{value} (brak w katalogu)</option>}
      {options.map((p) => (
        <option key={p.key} value={p.name}>
          {p.name} ({p.cards_count})
        </option>
      ))}
    </select>
  )
}

export function AdminDictionaries() {
  const [entries, setEntries] = useState<DictionaryEntry[]>([])
  const [producers, setProducers] = useState<CatalogProducer[]>([])
  const [kinds, setKinds] = useState<Record<string, string>>({})
  const [loading, setLoading] = useState(true)
  const [err, setErr] = useState('')
  const [msg, setMsg] = useState('')

  const [form, setForm] = useState<Draft>(emptyDraft)
  const [formErr, setFormErr] = useState('')
  const [adding, setAdding] = useState(false)

  const [q, setQ] = useState('')
  const [kindFilter, setKindFilter] = useState('')
  const [stateFilter, setStateFilter] = useState<'' | 'on' | 'off'>('')
  const [sortKey, setSortKey] = useState<SortKey>('name')
  const [sortDir, setSortDir] = useState<'asc' | 'desc'>('asc')

  const [editingId, setEditingId] = useState<number | null>(null)
  const [editDraft, setEditDraft] = useState<Draft>(emptyDraft)
  const [rowErr, setRowErr] = useState('')
  const [busyKey, setBusyKey] = useState<string | null>(null)

  async function load() {
    const data = await api<Payload>('/admin/brand-dictionary')
    setEntries(data.entries ?? [])
    setProducers(data.producers ?? [])
    setKinds(data.kinds ?? {})
  }

  useEffect(() => {
    void load()
      .catch((e: Error) => setErr(e.message))
      .finally(() => setLoading(false))
  }, [])

  const kindLabel = (kind: string) => kinds[kind] ?? kind

  const producersByName = useMemo(
    () => [...producers].sort((a, b) => a.name.localeCompare(b.name, 'pl')),
    [producers],
  )

  const cardsByName = useMemo(() => new Map(producers.map((p) => [p.name, p.cards_count])), [producers])

  const rows = useMemo<Row[]>(() => {
    const catalog = new Map(producers.map((p) => [p.key, p]))
    const out: Row[] = entries.map((entry) => {
      const producer = catalog.get(entry.term_key) ?? null
      catalog.delete(entry.term_key)
      // Przy producencie z katalogu bierzemy stan policzony przez wyszukiwarkę (to, co dzieje się z zapytaniem).
      const detect =
        entry.kind === 'exclusion'
          ? null
          : entry.kind === 'producer' && producer
            ? producer.detect_in_query
            : entry.detect_in_query
      return { key: entry.term_key, term: entry.term, kind: entry.kind, entry, producer, detect }
    })
    for (const producer of catalog.values()) {
      out.push({
        key: producer.key,
        term: producer.name,
        kind: 'producer',
        entry: null,
        producer,
        detect: producer.detect_in_query,
      })
    }
    return out
  }, [entries, producers])

  const visibleRows = useMemo(() => {
    const needle = q.trim().toLowerCase()
    const list = rows.filter((r) => {
      if (kindFilter && r.kind !== kindFilter) return false
      if (stateFilter === 'on' && r.detect !== true) return false
      if (stateFilter === 'off' && r.detect !== false) return false
      if (!needle) return true
      return `${r.term} ${r.entry?.manufacturer ?? ''} ${r.entry?.note ?? ''}`.toLowerCase().includes(needle)
    })
    const byName = (a: Row, b: Row) => a.term.localeCompare(b.term, 'pl')
    list.sort((a, b) => {
      let cmp = 0
      if (sortKey === 'cards') cmp = (a.producer?.cards_count ?? -1) - (b.producer?.cards_count ?? -1)
      else if (sortKey === 'detect') cmp = (a.detect === null ? -1 : Number(a.detect)) - (b.detect === null ? -1 : Number(b.detect))
      if (cmp === 0) cmp = byName(a, b)
      return sortDir === 'asc' ? cmp : -cmp
    })
    return list
  }, [rows, q, kindFilter, stateFilter, sortKey, sortDir])

  const detectedCount = rows.filter((r) => r.detect === true).length

  function toggleSort(key: SortKey) {
    if (sortKey === key) {
      setSortDir((d) => (d === 'asc' ? 'desc' : 'asc'))
      return
    }
    setSortKey(key)
    setSortDir('asc')
  }

  async function add(e: FormEvent) {
    e.preventDefault()
    setMsg('')
    const local = localDraftError(form)
    if (local) {
      setFormErr(local)
      return
    }
    setAdding(true)
    setFormErr('')
    try {
      const res = await api<{ entry: DictionaryEntry }>('/admin/brand-dictionary', {
        method: 'POST',
        body: JSON.stringify(payloadFrom(form)),
      })
      setForm((prev) => ({ ...emptyDraft(), kind: prev.kind }))
      setMsg(`Dodano „${res.entry?.term ?? form.term.trim()}”.`)
      await load()
    } catch (ex) {
      setFormErr(errorText(ex, 'Nie udało się dodać wpisu'))
    } finally {
      setAdding(false)
    }
  }

  function startEdit(entry: DictionaryEntry) {
    setEditingId(entry.id)
    setEditDraft(draftFrom(entry))
    setRowErr('')
    setMsg('')
  }

  async function saveEdit(row: Row, entry: DictionaryEntry) {
    const local = localDraftError(editDraft)
    if (local) {
      setRowErr(local)
      return
    }
    const body = patchFrom(entry, editDraft)
    if (Object.keys(body).length === 0) {
      setEditingId(null)
      setRowErr('')
      return
    }
    setBusyKey(row.key)
    setRowErr('')
    setMsg('')
    try {
      await api<{ entry: DictionaryEntry }>(`/admin/brand-dictionary/${entry.id}`, {
        method: 'PATCH',
        body: JSON.stringify(body),
      })
      setEditingId(null)
      setMsg(`Zapisano „${editDraft.term.trim()}”.`)
      await load()
    } catch (ex) {
      setRowErr(errorText(ex, 'Nie udało się zapisać wpisu'))
    } finally {
      setBusyKey(null)
    }
  }

  async function remove(row: Row, entry: DictionaryEntry) {
    if (!window.confirm(`Usunąć wpis „${entry.term}” (${kindLabel(entry.kind)})?`)) return
    setBusyKey(row.key)
    setErr('')
    setMsg('')
    try {
      await api<unknown>(`/admin/brand-dictionary/${entry.id}`, { method: 'DELETE' })
      if (editingId === entry.id) setEditingId(null)
      setMsg(`Usunięto „${entry.term}”.`)
      await load()
    } catch (ex) {
      setErr(errorText(ex, 'Nie udało się usunąć wpisu'))
    } finally {
      setBusyKey(null)
    }
  }

  async function toggle(row: Row, detect: boolean) {
    setBusyKey(row.key)
    setErr('')
    setMsg('')
    try {
      // Zawsze zapisujemy decyzję wprost, także przy włączaniu. Brak wpisu nie znaczy „tak”: domyślnie
      // rozpoznawani są tylko producenci z konfiguracji domen, więc producent spoza niej (BHP, JSP, ARDON)
      // bez wpisu nie byłby rozpoznawany, a producent z konfiguracji bez wpisu „Nie” wróciłby do „Tak”.
      if (row.entry) {
        await api<{ entry: DictionaryEntry }>(`/admin/brand-dictionary/${row.entry.id}`, {
          method: 'PATCH',
          body: JSON.stringify({ detect_in_query: detect }),
        })
      } else {
        await api<{ entry: DictionaryEntry }>('/admin/brand-dictionary', {
          method: 'POST',
          body: JSON.stringify({ term: row.term, kind: 'producer', manufacturer: null, detect_in_query: detect, note: null }),
        })
      }
      setMsg(`${detect ? 'Włączono' : 'Wyłączono'} rozpoznawanie „${row.term}”.`)
      await load()
    } catch (ex) {
      setErr(errorText(ex, 'Nie udało się zmienić ustawienia'))
    } finally {
      setBusyKey(null)
    }
  }

  const sortHeaders: { key: SortKey; label: string; className: string }[] = [
    { key: 'name', label: 'Słowo', className: 'p-2' },
    { key: 'cards', label: 'Kart w katalogu', className: 'p-2 text-right' },
    { key: 'detect', label: 'Rozpoznawaj w zapytaniu', className: 'p-2' },
  ]

  const input = 'rounded-lg border border-slate-300 px-2 py-1.5 text-xs'
  const cellInput = 'w-full rounded border border-slate-300 px-2 py-1 text-xs'
  const smallBtn = 'rounded-lg border border-slate-300 px-2.5 py-1 text-xs hover:bg-slate-50 disabled:opacity-50'

  function sortableTh(h: (typeof sortHeaders)[number]) {
    return (
      <th key={h.key} className={h.className}>
        <button
          type="button"
          onClick={() => toggleSort(h.key)}
          className="inline-flex items-center font-semibold uppercase tracking-wide hover:text-sky-700"
        >
          {h.label}
          <SortMark active={sortKey === h.key} dir={sortDir} />
        </button>
      </th>
    )
  }

  return (
    <div className="space-y-4">
      <div className="overflow-hidden rounded-2xl border border-slate-200 bg-gradient-to-br from-violet-50 via-white to-sky-50 p-5 shadow-sm">
        <p className="text-[11px] font-semibold uppercase tracking-wide text-violet-700">Wyszukiwarka wyrobów</p>
        <h2 className="mt-1 text-lg font-semibold text-slate-900">Słowniki</h2>
        <p className="mt-1 max-w-3xl text-[12px] text-slate-600">
          Słowniki uczą wyszukiwarkę, które słowa w zapytaniu klienta są marką lub producentem, a które nie —
          od tego zależy, czy wynik zostanie zawężony do kart jednego producenta.
        </p>
      </div>

      {err && <p className="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-[12px] text-rose-800">{err}</p>}
      {msg && (
        <p className="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-[12px] text-emerald-800">{msg}</p>
      )}

      <section className="space-y-3">
        <div>
          <h3 className="text-sm font-semibold text-slate-900">Producenci, marki i wykluczenia</h3>
          <p className="mt-0.5 max-w-3xl text-[12px] text-slate-600">
            Lista obejmuje wszystkich producentów z katalogu oraz dodane marki i wykluczenia. Marka wskazuje
            producenta, pod którym są jej karty (np. Peltor → 3M). Producent z wyłączonym rozpoznawaniem nie zawęża
            wyniku. „Domyślnie” oznacza producenta z listy wbudowanej w program, bez wpisu w słowniku. Wykluczenie
            to słowo, które nigdy nie jest marką (np. kask).
          </p>
        </div>

        <form
          onSubmit={(e) => void add(e)}
          className="space-y-2 rounded-2xl border border-slate-200 bg-white p-3 shadow-sm"
        >
          <div className="flex flex-wrap items-end gap-2">
            <label className="flex flex-col gap-0.5 text-[11px] text-slate-500">
              Słowo
              <input
                className={`${input} w-44`}
                maxLength={120}
                value={form.term}
                onChange={(e) => setForm({ ...form, term: e.target.value })}
                placeholder="np. Peltor"
              />
            </label>
            <label className="flex flex-col gap-0.5 text-[11px] text-slate-500">
              Rodzaj
              <select
                className={input}
                value={form.kind}
                onChange={(e) => setForm({ ...form, kind: e.target.value as EntryKind })}
              >
                {Object.entries(kinds).map(([id, label]) => (
                  <option key={id} value={id}>
                    {label}
                  </option>
                ))}
              </select>
            </label>
            {form.kind === 'brand' && (
              <label className="flex flex-col gap-0.5 text-[11px] text-slate-500">
                Producent
                <ManufacturerSelect
                  className={`${input} w-56`}
                  value={form.manufacturer}
                  onChange={(v) => setForm({ ...form, manufacturer: v })}
                  options={producersByName}
                />
              </label>
            )}
            {form.kind !== 'exclusion' && (
              <label className="flex items-center gap-1.5 pb-1.5 text-xs text-slate-700">
                <input
                  type="checkbox"
                  checked={form.detect_in_query}
                  onChange={(e) => setForm({ ...form, detect_in_query: e.target.checked })}
                />
                Rozpoznawaj w zapytaniu
              </label>
            )}
            <label className="flex min-w-[12rem] flex-1 flex-col gap-0.5 text-[11px] text-slate-500">
              Notatka
              <input
                className={input}
                maxLength={200}
                value={form.note}
                onChange={(e) => setForm({ ...form, note: e.target.value })}
                placeholder="opcjonalnie"
              />
            </label>
            <button
              type="submit"
              disabled={adding || loading}
              className="rounded-lg bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white disabled:opacity-50"
            >
              {adding ? 'Dodaję…' : 'Dodaj'}
            </button>
          </div>
          {formErr && (
            <p className="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-[12px] text-rose-800">{formErr}</p>
          )}
        </form>

        <div className="rounded-2xl border border-slate-200 bg-white shadow-sm">
          <div className="flex flex-wrap items-center gap-2 border-b border-slate-200 p-3">
            <h4 className="mr-2 text-[13px] font-semibold text-slate-900">Słownik</h4>
            <select className={input} value={kindFilter} onChange={(e) => setKindFilter(e.target.value)}>
              <option value="">Wszystkie rodzaje</option>
              {Object.entries(kinds).map(([id, label]) => (
                <option key={id} value={id}>
                  {label}
                </option>
              ))}
            </select>
            <select
              className={input}
              value={stateFilter}
              onChange={(e) => setStateFilter(e.target.value as '' | 'on' | 'off')}
            >
              <option value="">Rozpoznawane i wyłączone</option>
              <option value="on">Tylko rozpoznawane</option>
              <option value="off">Tylko wyłączone</option>
            </select>
            <input
              className={`${input} min-w-[12rem] flex-1`}
              placeholder="Filtruj po słowie, producencie, notatce…"
              value={q}
              onChange={(e) => setQ(e.target.value)}
            />
            <span className="text-[11px] text-slate-500">
              {visibleRows.length} z {rows.length} · rozpoznawanych: {detectedCount}
            </span>
          </div>
          <div className="max-h-[40rem] overflow-auto">
            <table className="w-full min-w-[60rem] text-left text-[13px]">
              <thead>
                <tr className="sticky top-0 z-10 border-b bg-slate-50 text-[11px] uppercase tracking-wide text-slate-500">
                  {sortableTh(sortHeaders[0])}
                  <th className="p-2">Rodzaj</th>
                  <th className="p-2">Producent</th>
                  {sortableTh(sortHeaders[1])}
                  {sortableTh(sortHeaders[2])}
                  <th className="p-2">Notatka</th>
                  <th className="p-2" />
                </tr>
              </thead>
              <tbody>
                {visibleRows.map((row) => {
                  const entry = row.entry
                  const editing = entry !== null && editingId === entry.id
                  const busy = busyKey === row.key
                  const d = editDraft
                  const kind = editing ? d.kind : row.kind
                  // Wpis producenta z katalogu nie ma „Usuń”: stan zmienia przycisk, a usunięcie wpisu po cichu
                  // przywróciłoby ustawienie domyślne (np. wyłączony producent z konfiguracji znów byłby „Tak”).
                  const removable = entry !== null && !(entry.kind === 'producer' && row.producer !== null)
                  const brandCards = row.kind === 'brand' && entry?.manufacturer ? cardsByName.get(entry.manufacturer) : undefined
                  return [
                    <tr key={row.key} className="border-b align-top last:border-b-0">
                      <td className="p-2">
                        {editing ? (
                          <input
                            className={`${cellInput} min-w-[9rem]`}
                            maxLength={120}
                            value={d.term}
                            onChange={(e) => setEditDraft({ ...d, term: e.target.value })}
                          />
                        ) : (
                          <span className="font-medium text-slate-900">{row.term}</span>
                        )}
                      </td>
                      <td className="p-2">
                        {editing ? (
                          <select
                            className={`${cellInput} min-w-[8rem]`}
                            value={d.kind}
                            onChange={(e) => setEditDraft({ ...d, kind: e.target.value as EntryKind })}
                          >
                            {Object.entries(kinds).map(([id, label]) => (
                              <option key={id} value={id}>
                                {label}
                              </option>
                            ))}
                          </select>
                        ) : (
                          <span
                            className={`rounded px-1.5 py-0.5 text-[11px] font-medium ${KIND_BADGE[row.kind] ?? KIND_BADGE.producer}`}
                          >
                            {kindLabel(row.kind)}
                          </span>
                        )}
                      </td>
                      <td className="p-2">
                        {editing ? (
                          d.kind === 'brand' ? (
                            <ManufacturerSelect
                              className={`${cellInput} min-w-[10rem]`}
                              value={d.manufacturer}
                              onChange={(v) => setEditDraft({ ...d, manufacturer: v })}
                              options={producersByName}
                            />
                          ) : (
                            <span className="text-slate-500">—</span>
                          )
                        ) : row.kind === 'brand' && entry?.manufacturer ? (
                          <span className="text-slate-700">
                            {entry.manufacturer}
                            {brandCards !== undefined && (
                              <span className="ml-1.5 text-[11px] text-slate-500">{cardsLabel(brandCards)}</span>
                            )}
                          </span>
                        ) : (
                          <span className="text-slate-500">—</span>
                        )}
                      </td>
                      <td className="p-2 text-right tabular-nums text-slate-700">
                        {row.producer ? row.producer.cards_count : <span className="text-slate-400">—</span>}
                      </td>
                      <td className="p-2">
                        {kind === 'exclusion' ? (
                          <span className="text-slate-500">—</span>
                        ) : editing ? (
                          <label className="flex items-center gap-1.5 text-xs text-slate-700">
                            <input
                              type="checkbox"
                              checked={d.detect_in_query}
                              onChange={(e) => setEditDraft({ ...d, detect_in_query: e.target.checked })}
                            />
                            {d.detect_in_query ? 'Tak' : 'Nie'}
                          </label>
                        ) : row.detect ? (
                          <span className="text-slate-700">
                            Tak
                            {entry === null && (
                              <span
                                className="ml-1.5 text-[11px] text-slate-500"
                                title="Producent z listy wbudowanej w program — rozpoznawany bez wpisu w słowniku."
                              >
                                domyślnie
                              </span>
                            )}
                          </span>
                        ) : (
                          <span className="font-medium text-amber-800">Nie</span>
                        )}
                      </td>
                      <td className="p-2">
                        {editing ? (
                          <input
                            className={`${cellInput} min-w-[10rem] text-slate-600`}
                            maxLength={200}
                            value={d.note}
                            onChange={(e) => setEditDraft({ ...d, note: e.target.value })}
                            placeholder="opcjonalnie"
                          />
                        ) : (
                          <span className="text-[12px] text-slate-600">{entry?.note}</span>
                        )}
                      </td>
                      <td className="whitespace-nowrap p-2 text-right">
                        {editing && entry ? (
                          <>
                            <button
                              type="button"
                              disabled={busy}
                              onClick={() => void saveEdit(row, entry)}
                              className="rounded-lg bg-blue-600 px-2.5 py-1 text-xs font-semibold text-white disabled:opacity-50"
                            >
                              {busy ? 'Zapisuję…' : 'Zapisz'}
                            </button>
                            <button
                              type="button"
                              disabled={busy}
                              onClick={() => {
                                setEditingId(null)
                                setRowErr('')
                              }}
                              className={`ml-1 ${smallBtn}`}
                            >
                              Anuluj
                            </button>
                          </>
                        ) : (
                          <>
                            {row.detect !== null && (
                              <button
                                type="button"
                                disabled={busyKey !== null}
                                onClick={() => void toggle(row, !row.detect)}
                                className={`w-[4.75rem] ${
                                  row.detect
                                    ? smallBtn
                                    : 'rounded-lg bg-blue-600 px-2.5 py-1 text-xs font-semibold text-white disabled:opacity-50'
                                }`}
                              >
                                {busy ? 'Zapisuję…' : row.detect ? 'Wyłącz' : 'Włącz'}
                              </button>
                            )}
                            {entry && (
                              <button
                                type="button"
                                disabled={busyKey !== null}
                                onClick={() => startEdit(entry)}
                                className={`ml-1 ${smallBtn}`}
                              >
                                Edytuj
                              </button>
                            )}
                            {entry && removable && (
                              <button
                                type="button"
                                disabled={busyKey !== null}
                                onClick={() => void remove(row, entry)}
                                className="ml-2 text-xs text-red-700 underline disabled:opacity-50"
                              >
                                Usuń
                              </button>
                            )}
                          </>
                        )}
                      </td>
                    </tr>,
                    editing && rowErr ? (
                      <tr key={`${row.key}-err`} className="border-b">
                        <td colSpan={7} className="px-2 pb-2">
                          <p className="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-[12px] text-rose-800">
                            {rowErr}
                          </p>
                        </td>
                      </tr>
                    ) : null,
                  ]
                })}
              </tbody>
            </table>
            {visibleRows.length === 0 && (
              <p className="px-4 py-6 text-center text-xs text-slate-500">
                {loading ? 'Ładowanie…' : rows.length === 0 ? 'Słownik jest pusty.' : 'Brak pozycji dla tego filtra.'}
              </p>
            )}
          </div>
        </div>
        <p className="max-w-3xl text-[12px] text-slate-600">
          Nie włączaj rozpoznawania nazw, które są zwykłymi słowami branżowymi (np. BHP) — rozpoznanie takiego
          słowa w zapytaniu zawęziłoby wynik do kilku kart tego producenta. Liczba kart podpowiada, czy nazwa
          to prawdziwa marka.
        </p>
      </section>
    </div>
  )
}
