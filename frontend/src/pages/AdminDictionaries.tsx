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

type ProducerSortKey = 'name' | 'cards_count' | 'detect'

/** Wiersz tabeli wpisów: zapisany wpis albo producent rozpoznawany domyślnie (z konfiguracji programu, bez wpisu). */
type EntryRow = { type: 'entry'; entry: DictionaryEntry } | { type: 'default'; producer: CatalogProducer }

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

/**
 * Wyłączony producent z katalogu nie stoi w tabeli wpisów — widać go w tabeli producentów z odznaczoną kratką.
 * Wpis z „Nie” zostaje w bazie, bo bez niego producent z konfiguracji programu wróciłby do „Tak”.
 */
function hiddenInEntries(kind: EntryKind, termKey: string, detect: boolean, catalogKeys: Map<string, number>): boolean {
  return kind === 'producer' && !detect && catalogKeys.has(termKey)
}

const HIDDEN_NOTE = ' Wyłączony producent jest widoczny tylko w tabeli „Producenci z katalogu”.'

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

  const [entryQ, setEntryQ] = useState('')
  const [entryKind, setEntryKind] = useState('')
  const [editingId, setEditingId] = useState<number | null>(null)
  const [editDraft, setEditDraft] = useState<Draft>(emptyDraft)
  const [rowErr, setRowErr] = useState('')
  const [rowBusy, setRowBusy] = useState<number | null>(null)

  const [producerQ, setProducerQ] = useState('')
  const [sortKey, setSortKey] = useState<ProducerSortKey>('cards_count')
  const [sortDir, setSortDir] = useState<'asc' | 'desc'>('asc')
  const [producerBusy, setProducerBusy] = useState<string | null>(null)

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

  const cardsByKey = useMemo(() => new Map(producers.map((p) => [p.key, p.cards_count])), [producers])

  const entryKeys = useMemo(() => new Set(entries.map((e) => e.term_key)), [entries])

  const entryRows = useMemo<EntryRow[]>(() => {
    const rows: EntryRow[] = entries
      .filter((e) => !hiddenInEntries(e.kind, e.term_key, e.detect_in_query, cardsByKey))
      .map((entry) => ({ type: 'entry', entry }))
    for (const producer of producers) {
      if (producer.detect_in_query && !entryKeys.has(producer.key)) rows.push({ type: 'default', producer })
    }
    const term = (r: EntryRow) => (r.type === 'entry' ? r.entry.term : r.producer.name)
    return rows.sort((a, b) => term(a).localeCompare(term(b), 'pl'))
  }, [entries, producers, entryKeys, cardsByKey])

  const visibleEntries = useMemo(() => {
    const needle = entryQ.trim().toLowerCase()
    return entryRows.filter((r) => {
      const kind = r.type === 'entry' ? r.entry.kind : 'producer'
      if (entryKind && kind !== entryKind) return false
      if (!needle) return true
      const text =
        r.type === 'entry'
          ? `${r.entry.term} ${r.entry.manufacturer ?? ''} ${r.entry.note ?? ''}`
          : `${r.producer.name} domyślnie`
      return text.toLowerCase().includes(needle)
    })
  }, [entryRows, entryQ, entryKind])

  const visibleProducers = useMemo(() => {
    const needle = producerQ.trim().toLowerCase()
    const list = needle ? producers.filter((p) => p.name.toLowerCase().includes(needle)) : [...producers]
    // Sortowanie stabilne: przy remisie zostaje kolejność z API.
    list.sort((a, b) => {
      let cmp: number
      if (sortKey === 'name') cmp = a.name.localeCompare(b.name, 'pl')
      else if (sortKey === 'cards_count') cmp = a.cards_count - b.cards_count
      else cmp = Number(a.detect_in_query) - Number(b.detect_in_query)
      return sortDir === 'asc' ? cmp : -cmp
    })
    return list
  }, [producers, producerQ, sortKey, sortDir])

  const disabledProducers = producers.filter((p) => !p.detect_in_query).length

  function toggleSort(key: ProducerSortKey) {
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
      const hidden = res.entry && hiddenInEntries(res.entry.kind, res.entry.term_key, res.entry.detect_in_query, cardsByKey)
      setMsg(`Dodano „${res.entry?.term ?? form.term.trim()}”.${hidden ? HIDDEN_NOTE : ''}`)
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

  async function saveEdit(entry: DictionaryEntry) {
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
    setRowBusy(entry.id)
    setRowErr('')
    setMsg('')
    try {
      const res = await api<{ entry: DictionaryEntry }>(`/admin/brand-dictionary/${entry.id}`, {
        method: 'PATCH',
        body: JSON.stringify(body),
      })
      setEditingId(null)
      const hidden = res.entry && hiddenInEntries(res.entry.kind, res.entry.term_key, res.entry.detect_in_query, cardsByKey)
      setMsg(`Zapisano „${editDraft.term.trim()}”.${hidden ? HIDDEN_NOTE : ''}`)
      await load()
    } catch (ex) {
      setRowErr(errorText(ex, 'Nie udało się zapisać wpisu'))
    } finally {
      setRowBusy(null)
    }
  }

  async function remove(entry: DictionaryEntry) {
    if (!window.confirm(`Usunąć wpis „${entry.term}” (${kindLabel(entry.kind)})?`)) return
    setRowBusy(entry.id)
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
      setRowBusy(null)
    }
  }

  async function toggleProducer(p: CatalogProducer, detect: boolean) {
    setProducerBusy(p.key)
    setErr('')
    setMsg('')
    try {
      // Zawsze zapisujemy decyzję wprost, także przy włączaniu. Usunięcie wpisu nie przywraca „tak”:
      // domyślnie rozpoznawani są tylko producenci z konfiguracji domen, więc producent spoza niej
      // (BHP, JSP, ARDON) po usunięciu wpisu dalej nie byłby rozpoznawany.
      if (p.entry_id !== null) {
        await api<{ entry: DictionaryEntry }>(`/admin/brand-dictionary/${p.entry_id}`, {
          method: 'PATCH',
          body: JSON.stringify({ detect_in_query: detect }),
        })
      } else {
        await api<{ entry: DictionaryEntry }>('/admin/brand-dictionary', {
          method: 'POST',
          body: JSON.stringify({ term: p.name, kind: 'producer', manufacturer: null, detect_in_query: detect, note: null }),
        })
      }
      setMsg(`${detect ? 'Włączono' : 'Wyłączono'} rozpoznawanie producenta „${p.name}”.`)
      await load()
    } catch (ex) {
      setErr(errorText(ex, 'Nie udało się zmienić ustawienia producenta'))
    } finally {
      setProducerBusy(null)
    }
  }

  const producerHeaders: { key: ProducerSortKey; label: string; className: string }[] = [
    { key: 'name', label: 'Nazwa', className: 'p-2' },
    { key: 'cards_count', label: 'Kart w katalogu', className: 'p-2 text-right' },
    { key: 'detect', label: 'Rozpoznawaj w zapytaniu', className: 'p-2' },
  ]

  const input = 'rounded-lg border border-slate-300 px-2 py-1.5 text-xs'
  const cellInput = 'w-full rounded border border-slate-300 px-2 py-1 text-xs'

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
            Marka wskazuje producenta, pod którym są jej karty (np. Peltor → 3M). Producent z wyłączonym
            rozpoznawaniem nie zawęża wyniku i nie stoi w tabeli wpisów — widać go w tabeli „Producenci z katalogu”
            z odznaczoną kratką. „Domyślnie” oznacza producenta z listy wbudowanej w program. Wykluczenie to słowo,
            które nigdy nie jest marką (np. kask).
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
            <h4 className="mr-2 text-[13px] font-semibold text-slate-900">Wpisy słownika</h4>
            <select className={input} value={entryKind} onChange={(e) => setEntryKind(e.target.value)}>
              <option value="">Wszystkie rodzaje</option>
              {Object.entries(kinds).map(([id, label]) => (
                <option key={id} value={id}>
                  {label}
                </option>
              ))}
            </select>
            <input
              className={`${input} min-w-[12rem] flex-1`}
              placeholder="Filtruj po słowie, producencie, notatce…"
              value={entryQ}
              onChange={(e) => setEntryQ(e.target.value)}
            />
            <span className="text-[11px] text-slate-500">
              {visibleEntries.length} z {entryRows.length}
            </span>
          </div>
          <div className="overflow-x-auto">
            <table className="w-full min-w-[56rem] text-left text-[13px]">
              <thead>
                <tr className="border-b bg-slate-50 text-[11px] uppercase tracking-wide text-slate-500">
                  <th className="p-2">Słowo</th>
                  <th className="p-2">Rodzaj</th>
                  <th className="p-2">Producent</th>
                  <th className="p-2">Rozpoznawaj w zapytaniu</th>
                  <th className="p-2">Notatka</th>
                  <th className="p-2" />
                </tr>
              </thead>
              <tbody>
                {visibleEntries.map((row) => {
                  if (row.type === 'default') {
                    const p = row.producer
                    const busy = producerBusy === p.key
                    return (
                      <tr key={`default-${p.key}`} className="border-b align-top last:border-b-0">
                        <td className="p-2">
                          <span className="font-medium text-slate-900">{p.name}</span>
                          <span className="ml-1.5 text-[11px] text-slate-500">{cardsLabel(p.cards_count)}</span>
                        </td>
                        <td className="p-2">
                          <span className={`rounded px-1.5 py-0.5 text-[11px] font-medium ${KIND_BADGE.producer}`}>
                            {kindLabel('producer')}
                          </span>
                        </td>
                        <td className="p-2">
                          <span className="text-slate-500">—</span>
                        </td>
                        <td className="p-2">
                          <span className="text-slate-700">Tak</span>
                          <span
                            className="ml-1.5 text-[11px] text-slate-500"
                            title="Producent z listy wbudowanej w program — rozpoznawany bez wpisu w słowniku."
                          >
                            domyślnie
                          </span>
                        </td>
                        <td className="p-2" />
                        <td className="whitespace-nowrap p-2 text-right">
                          <button
                            type="button"
                            disabled={producerBusy !== null}
                            onClick={() => void toggleProducer(p, false)}
                            className="rounded-lg border border-slate-300 px-2.5 py-1 text-xs hover:bg-slate-50 disabled:opacity-50"
                          >
                            {busy ? 'Zapisuję…' : 'Wyłącz'}
                          </button>
                        </td>
                      </tr>
                    )
                  }
                  const entry = row.entry
                  const editing = editingId === entry.id
                  const busy = rowBusy === entry.id
                  const d = editDraft
                  const producerCards = entry.kind === 'producer' ? cardsByKey.get(entry.term_key) : undefined
                  return [
                    <tr key={entry.id} className="border-b align-top last:border-b-0">
                      <td className="p-2">
                        {editing ? (
                          <input
                            className={`${cellInput} min-w-[9rem]`}
                            maxLength={120}
                            value={d.term}
                            onChange={(e) => setEditDraft({ ...d, term: e.target.value })}
                          />
                        ) : (
                          <>
                            <span className="font-medium text-slate-900">{entry.term}</span>
                            {producerCards !== undefined && (
                              <span className="ml-1.5 text-[11px] text-slate-500">{cardsLabel(producerCards)}</span>
                            )}
                          </>
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
                            className={`rounded px-1.5 py-0.5 text-[11px] font-medium ${KIND_BADGE[entry.kind] ?? KIND_BADGE.producer}`}
                          >
                            {kindLabel(entry.kind)}
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
                        ) : (
                          <span className={entry.manufacturer ? 'text-slate-700' : 'text-slate-500'}>
                            {entry.kind === 'brand' ? entry.manufacturer || '—' : '—'}
                          </span>
                        )}
                      </td>
                      <td className="p-2">
                        {(editing ? d.kind : entry.kind) === 'exclusion' ? (
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
                        ) : entry.detect_in_query ? (
                          <span className="text-slate-700">Tak</span>
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
                          <span className="text-[12px] text-slate-600">{entry.note}</span>
                        )}
                      </td>
                      <td className="whitespace-nowrap p-2 text-right">
                        {editing ? (
                          <>
                            <button
                              type="button"
                              disabled={busy}
                              onClick={() => void saveEdit(entry)}
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
                              className="ml-1 rounded-lg border border-slate-300 px-2.5 py-1 text-xs hover:bg-slate-50 disabled:opacity-50"
                            >
                              Anuluj
                            </button>
                          </>
                        ) : (
                          <>
                            <button
                              type="button"
                              disabled={busy}
                              onClick={() => startEdit(entry)}
                              className="rounded-lg border border-slate-300 px-2.5 py-1 text-xs hover:bg-slate-50 disabled:opacity-50"
                            >
                              Edytuj
                            </button>
                            <button
                              type="button"
                              disabled={busy}
                              onClick={() => void remove(entry)}
                              className="ml-2 text-xs text-red-700 underline disabled:opacity-50"
                            >
                              Usuń
                            </button>
                          </>
                        )}
                      </td>
                    </tr>,
                    editing && rowErr ? (
                      <tr key={`${entry.id}-err`} className="border-b">
                        <td colSpan={6} className="px-2 pb-2">
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
            {visibleEntries.length === 0 && (
              <p className="px-4 py-6 text-center text-xs text-slate-500">
                {loading ? 'Ładowanie…' : entryRows.length === 0 ? 'Słownik jest pusty.' : 'Brak wpisów dla tego filtra.'}
              </p>
            )}
          </div>
        </div>

        <div className="rounded-2xl border border-slate-200 bg-white shadow-sm">
          <div className="flex flex-wrap items-center gap-2 border-b border-slate-200 p-3">
            <h4 className="mr-2 text-[13px] font-semibold text-slate-900">Producenci z katalogu</h4>
            <input
              className={`${input} min-w-[12rem] flex-1`}
              placeholder="Filtruj producentów…"
              value={producerQ}
              onChange={(e) => setProducerQ(e.target.value)}
            />
            <span className="text-[11px] text-slate-500">
              {visibleProducers.length} z {producers.length} · wyłączonych: {disabledProducers}
            </span>
          </div>
          <div className="max-h-[36rem] overflow-auto">
            <table className="w-full min-w-[32rem] text-left text-[13px]">
              <thead>
                <tr className="sticky top-0 border-b bg-slate-50 text-[11px] uppercase tracking-wide text-slate-500">
                  {producerHeaders.map((h) => (
                    <th key={h.key} className={h.className}>
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
                </tr>
              </thead>
              <tbody>
                {visibleProducers.map((p) => {
                  const entry = p.entry_id !== null ? entries.find((e) => e.id === p.entry_id) : undefined
                  // Wpis innego rodzaju (marka, wykluczenie) zmienia się w tabeli wpisów, nie przełącznikiem.
                  const foreignEntry = entry !== undefined && entry.kind !== 'producer'
                  const busy = producerBusy === p.key
                  // „Tak” bez żadnego wpisu o tym słowie pochodzi z listy producentów w konfiguracji programu.
                  const byDefault = p.detect_in_query && !entryKeys.has(p.key)
                  return (
                    <tr key={p.key} className="border-b last:border-b-0">
                      <td className="p-2 font-medium text-slate-900">{p.name}</td>
                      <td className="p-2 text-right tabular-nums text-slate-700">{p.cards_count}</td>
                      <td className="p-2">
                        <label
                          className="inline-flex items-center gap-1.5 text-xs text-slate-700"
                          title={foreignEntry ? `Słowo ma wpis „${kindLabel(entry.kind)}” — zmień go w tabeli wpisów.` : undefined}
                        >
                          <input
                            type="checkbox"
                            checked={p.detect_in_query}
                            disabled={busy || foreignEntry || producerBusy !== null}
                            onChange={(e) => void toggleProducer(p, e.target.checked)}
                          />
                          {busy ? (
                            'Zapisuję…'
                          ) : p.detect_in_query ? (
                            'Tak'
                          ) : (
                            <span className="font-medium text-amber-800">Nie</span>
                          )}
                          {foreignEntry && (
                            <span className="text-[11px] text-slate-500">({kindLabel(entry.kind).toLowerCase()})</span>
                          )}
                          {byDefault && !busy && (
                            <span
                              className="text-[11px] text-slate-500"
                              title="Producent z listy wbudowanej w program — nie ma wpisu w słowniku. Odznaczenie zapisze wpis z „Nie”."
                            >
                              domyślnie
                            </span>
                          )}
                        </label>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
            {visibleProducers.length === 0 && (
              <p className="px-4 py-6 text-center text-xs text-slate-500">
                {loading ? 'Ładowanie…' : producers.length === 0 ? 'Brak producentów w katalogu.' : 'Brak producentów dla tego filtra.'}
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
