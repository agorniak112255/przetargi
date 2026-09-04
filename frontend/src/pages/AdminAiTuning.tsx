import { useEffect, useState, type FormEvent } from 'react'
import { api } from '../lib/api'

type Payload = {
  catalog_search_limit: number
  default: number
  min: number
  max: number
}

export function AdminAiTuning() {
  const [limit, setLimit] = useState('40')
  const [meta, setMeta] = useState<Pick<Payload, 'default' | 'min' | 'max'>>({
    default: 40,
    min: 1,
    max: 80,
  })
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const [msg, setMsg] = useState('')

  async function load() {
    setErr('')
    const data = await api<Payload>('/admin/ai-tuning')
    setLimit(String(data.catalog_search_limit))
    setMeta({ default: data.default, min: data.min, max: data.max })
  }

  useEffect(() => {
    void load().catch((e: Error) => setErr(e.message))
  }, [])

  async function onSave(e: FormEvent) {
    e.preventDefault()
    setBusy(true)
    setErr('')
    setMsg('')
    try {
      const data = await api<Payload>('/admin/ai-tuning', {
        method: 'PUT',
        body: JSON.stringify({ catalog_search_limit: Number(limit) }),
      })
      setLimit(String(data.catalog_search_limit))
      setMsg(`Zapisano limit: ${data.catalog_search_limit}.`)
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Nie udało się zapisać')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="space-y-4">
      <div className="overflow-hidden rounded-2xl border border-slate-200 bg-gradient-to-br from-violet-50 via-white to-sky-50 p-5 shadow-sm">
        <p className="text-[11px] font-semibold uppercase tracking-wide text-violet-700">
          Wyszukiwarka SIWZ
        </p>
        <h2 className="mt-1 text-lg font-semibold text-slate-900">Strojenie AI</h2>
        <p className="mt-1 max-w-3xl text-[12px] text-slate-600">
          Limit dotyczy „Szukaj w katalogu” na Produktach i w modalu przetargu — ile
          produktów model rankuje i zwraca.
        </p>
      </div>

      {err && <p className="text-sm text-red-600">{err}</p>}
      {msg && <p className="text-sm text-green-700">{msg}</p>}

      <form
        onSubmit={(e) => void onSave(e)}
        className="grid max-w-xl gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm"
      >
        <label className="text-xs font-medium text-slate-700">
          Limit wyników wyszukiwania w katalogu
          <input
            type="number"
            min={meta.min}
            max={meta.max}
            required
            className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
            value={limit}
            onChange={(e) => setLimit(e.target.value)}
          />
        </label>
        <p className="text-[11px] text-slate-500">
          Domyślnie {meta.default}. Zakres {meta.min}–{meta.max}.
        </p>
        <div>
          <button
            type="submit"
            disabled={busy}
            className="rounded-lg bg-violet-600 px-3 py-2 text-xs font-semibold text-white hover:bg-violet-700 disabled:opacity-50"
          >
            {busy ? 'Zapisuję…' : 'Zapisz'}
          </button>
        </div>
      </form>
    </div>
  )
}
