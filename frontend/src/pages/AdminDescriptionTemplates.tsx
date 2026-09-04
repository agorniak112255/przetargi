import { useEffect, useMemo, useState } from 'react'
import { api } from '../lib/api'

type Template = {
  kategoria_bhp: string
  label: string
  instructions: string
  default_instructions: string
  is_customized: boolean
  is_fallback: boolean
  updated_at: string | null
}

type Listing = {
  templates: Template[]
}

function preview(text: string): string {
  const line = text.replace(/\s+/g, ' ').trim()
  return line.length > 140 ? `${line.slice(0, 137)}…` : line
}

export function AdminDescriptionTemplates() {
  const [rows, setRows] = useState<Template[]>([])
  const [selected, setSelected] = useState<string>('rekawice')
  const [draft, setDraft] = useState('')
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const [msg, setMsg] = useState('')

  const current = useMemo(
    () => rows.find((row) => row.kategoria_bhp === selected) ?? null,
    [rows, selected],
  )

  const dirty = current !== null && draft !== current.instructions

  async function load(keepKey?: string) {
    const data = await api<Listing>('/admin/enrichment-description-templates')
    const list = data.templates ?? []
    setRows(list)
    const key = keepKey && list.some((row) => row.kategoria_bhp === keepKey)
      ? keepKey
      : (list[0]?.kategoria_bhp ?? 'inne')
    setSelected(key)
    const row = list.find((item) => item.kategoria_bhp === key)
    setDraft(row?.instructions ?? '')
  }

  useEffect(() => {
    void load().catch((e: Error) => setErr(e.message))
  }, [])

  function pick(key: string) {
    if (dirty && !window.confirm('Masz niezapisane zmiany. Odrzucić je?')) {
      return
    }
    const row = rows.find((item) => item.kategoria_bhp === key)
    setSelected(key)
    setDraft(row?.instructions ?? '')
    setMsg('')
    setErr('')
  }

  async function save() {
    if (!current) return
    setBusy(true)
    setErr('')
    setMsg('')
    try {
      const saved = await api<Template>(
        `/admin/enrichment-description-templates/${encodeURIComponent(current.kategoria_bhp)}`,
        { method: 'PUT', body: JSON.stringify({ instructions: draft }) },
      )
      setRows((prev) => prev.map((row) => (row.kategoria_bhp === saved.kategoria_bhp ? saved : row)))
      setDraft(saved.instructions)
      setMsg('Zapisano szablon.')
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Nie udało się zapisać')
    } finally {
      setBusy(false)
    }
  }

  async function restore() {
    if (!current) return
    if (!window.confirm('Przywrócić domyślne instrukcje tej rodziny?')) return
    setBusy(true)
    setErr('')
    setMsg('')
    try {
      const saved = await api<Template>(
        `/admin/enrichment-description-templates/${encodeURIComponent(current.kategoria_bhp)}/restore`,
        { method: 'POST', body: '{}' },
      )
      setRows((prev) => prev.map((row) => (row.kategoria_bhp === saved.kategoria_bhp ? saved : row)))
      setDraft(saved.instructions)
      setMsg('Przywrócono zestaw startowy.')
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Nie udało się przywrócić')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="space-y-4">
      <div className="overflow-hidden rounded-2xl border border-slate-200 bg-gradient-to-br from-sky-50 via-white to-violet-50 p-5 shadow-sm">
        <p className="text-[11px] font-semibold uppercase tracking-wide text-sky-700">Pobieranie opisów</p>
        <h2 className="mt-1 text-lg font-semibold text-slate-900">Szablony opisów</h2>
        <p className="mt-1 max-w-3xl text-[12px] text-slate-600">
          Instrukcje dla modelu przy zbieraniu opisu z internetu — jedna na rodzinę BHP, nie na
          ścieżkę Presty. Przy wyszukiwaniu rodzina jest wykrywana z nazwy, SKU i kategorii
          produktu; kategorie sklepu zostają bez zmian. Gdy nie da się rozpoznać, używany jest
          szablon domyślny.
        </p>
      </div>

      {err && (
        <p className="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-[12px] text-rose-800">{err}</p>
      )}
      {msg && (
        <p className="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-[12px] text-emerald-800">{msg}</p>
      )}

      <div className="grid gap-4 lg:grid-cols-[minmax(16rem,22rem)_1fr]">
        <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
          <table className="w-full text-left text-[13px]">
            <thead>
              <tr className="border-b bg-slate-50 text-[11px] uppercase tracking-wide text-slate-500">
                <th className="p-2">Rodzina</th>
                <th className="p-2">Status</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((row) => {
                const active = row.kategoria_bhp === selected
                return (
                  <tr
                    key={row.kategoria_bhp}
                    className={`cursor-pointer border-b last:border-b-0 ${
                      active ? 'bg-sky-50' : 'hover:bg-slate-50'
                    }`}
                    onClick={() => pick(row.kategoria_bhp)}
                  >
                    <td className="p-2 align-top">
                      <div className="font-medium text-slate-800">{row.label}</div>
                      <div className="mt-0.5 text-[11px] text-slate-500">{preview(row.instructions)}</div>
                    </td>
                    <td className="p-2 align-top whitespace-nowrap">
                      {row.is_fallback && (
                        <span className="mr-1 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-medium text-slate-600">
                          fallback
                        </span>
                      )}
                      {row.is_customized ? (
                        <span className="rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-medium text-amber-800">
                          zmieniony
                        </span>
                      ) : (
                        <span className="rounded bg-emerald-50 px-1.5 py-0.5 text-[10px] font-medium text-emerald-800">
                          domyślny
                        </span>
                      )}
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>

        <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
          {current ? (
            <>
              <div className="mb-3 flex flex-wrap items-start justify-between gap-2">
                <div>
                  <h3 className="text-sm font-semibold text-slate-900">{current.label}</h3>
                  <p className="text-[11px] text-slate-500">
                    klucz: {current.kategoria_bhp}
                    {current.is_fallback ? ' · używany, gdy rodzina nie jest znana' : ''}
                  </p>
                </div>
                <div className="flex flex-wrap gap-2">
                  <button
                    type="button"
                    disabled={busy || !current.is_customized}
                    onClick={() => void restore()}
                    className="rounded-lg border border-slate-300 px-3 py-1.5 text-xs hover:bg-slate-50 disabled:opacity-50"
                  >
                    Przywróć domyślne
                  </button>
                  <button
                    type="button"
                    disabled={busy || !dirty}
                    onClick={() => void save()}
                    className="rounded-lg bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white disabled:opacity-50"
                  >
                    {busy ? 'Zapisuję…' : 'Zapisz'}
                  </button>
                </div>
              </div>
              <textarea
                className="min-h-[22rem] w-full rounded-xl border border-slate-300 p-3 font-mono text-[12px] leading-relaxed"
                value={draft}
                onChange={(e) => {
                  setDraft(e.target.value)
                  setMsg('')
                }}
              />
              <p className="mt-2 text-[11px] text-slate-500">
                Schema JSON odpowiedzi modelu jest wspólna — tu edytujesz tylko instrukcje rodziny
                (jakie normy, materiały i rozmiary zbierać).
              </p>
            </>
          ) : (
            <p className="text-sm text-slate-500">Ładowanie szablonów…</p>
          )}
        </div>
      </div>
    </div>
  )
}
