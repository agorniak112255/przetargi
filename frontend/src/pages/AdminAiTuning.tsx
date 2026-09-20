import { useEffect, useState, type FormEvent } from 'react'
import { api } from '../lib/api'

type Payload = {
  catalog_search_limit: number
  default: number
  min: number
  max: number
  match_apply_score: number
  match_substitute_score: number
  match_min_score: number
  match_allow_catalog_rows: boolean
  match_defaults: {
    apply: number
    substitute: number
    min: number
    score_min: number
    score_max: number
  }
}

type Meta = Pick<Payload, 'default' | 'min' | 'max'> & { match: Payload['match_defaults'] }

const META_FALLBACK: Meta = {
  default: 40,
  min: 1,
  max: 80,
  match: { apply: 40, substitute: 55, min: 65, score_min: 1, score_max: 99 },
}

export function AdminAiTuning() {
  const [limit, setLimit] = useState('40')
  const [applyScore, setApplyScore] = useState('40')
  const [substituteScore, setSubstituteScore] = useState('55')
  const [minScore, setMinScore] = useState('65')
  const [allowCatalog, setAllowCatalog] = useState(false)
  const [meta, setMeta] = useState<Meta>(META_FALLBACK)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const [msg, setMsg] = useState('')

  function fill(data: Payload) {
    setLimit(String(data.catalog_search_limit))
    setApplyScore(String(data.match_apply_score))
    setSubstituteScore(String(data.match_substitute_score))
    setMinScore(String(data.match_min_score))
    setAllowCatalog(data.match_allow_catalog_rows)
    setMeta({
      default: data.default,
      min: data.min,
      max: data.max,
      match: data.match_defaults ?? META_FALLBACK.match,
    })
  }

  async function load() {
    setErr('')
    fill(await api<Payload>('/admin/ai-tuning'))
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
        body: JSON.stringify({
          catalog_search_limit: Number(limit),
          match_apply_score: Number(applyScore),
          match_substitute_score: Number(substituteScore),
          match_min_score: Number(minScore),
          match_allow_catalog_rows: allowCatalog,
        }),
      })
      fill(data)
      setMsg('Zapisano ustawienia wyszukiwania i dopasowania.')
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Nie udało się zapisać')
    } finally {
      setBusy(false)
    }
  }

  function resetMatchDefaults() {
    setApplyScore(String(meta.match.apply))
    setSubstituteScore(String(meta.match.substitute))
    setMinScore(String(meta.match.min))
    setAllowCatalog(false)
  }

  const scoreRange = { min: meta.match.score_min, max: meta.match.score_max }

  return (
    <div className="space-y-4">
      <div className="overflow-hidden rounded-2xl border border-slate-200 bg-gradient-to-br from-violet-50 via-white to-sky-50 p-5 shadow-sm">
        <p className="text-[11px] font-semibold uppercase tracking-wide text-violet-700">
          Wyszukiwarka SIWZ
        </p>
        <h2 className="mt-1 text-lg font-semibold text-slate-900">Strojenie AI</h2>
        <p className="mt-1 max-w-3xl text-[12px] text-slate-600">
          Limit dotyczy „Szukaj w katalogu” na Produktach i w modalu przetargu — ile
          produktów model rankuje i zwraca. Progi niżej decydują, kiedy dopasowanie
          trafia automatycznie do pozycji oferty w przetargu.
        </p>
      </div>

      {err && <p className="text-sm text-red-600">{err}</p>}
      {msg && <p className="text-sm text-green-700">{msg}</p>}

      <form onSubmit={(e) => void onSave(e)} className="grid max-w-3xl gap-4">
        <div className="grid gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
          <p className="text-xs font-semibold text-slate-900">Wyszukiwanie w katalogu</p>
          <label className="max-w-xs text-xs font-medium text-slate-700">
            Limit wyników
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
        </div>

        <div className="grid gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
          <div className="flex items-start justify-between gap-3">
            <div>
              <p className="text-xs font-semibold text-slate-900">
                Progi dopasowania w przetargach
              </p>
              <p className="mt-1 text-[11px] text-slate-500">
                Niżej ustawione progi = więcej pozycji wypełnionych automatycznie, ale
                więcej pomyłek. Wyżej = więcej pustych pozycji do ręcznego uzupełnienia.
              </p>
            </div>
            <button
              type="button"
              onClick={resetMatchDefaults}
              className="shrink-0 rounded-lg border border-slate-300 px-2 py-1 text-[11px] font-medium text-slate-700 hover:bg-slate-50"
            >
              Przywróć domyślne
            </button>
          </div>

          <div className="grid gap-3 sm:grid-cols-3">
            <label className="text-xs font-medium text-slate-700">
              Zapis dopasowania
              <input
                type="number"
                min={scoreRange.min}
                max={scoreRange.max}
                required
                className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                value={applyScore}
                onChange={(e) => setApplyScore(e.target.value)}
              />
              <span className="mt-1 block text-[11px] font-normal text-slate-500">
                Od tego wyniku produkt trafia do pozycji oferty. Domyślnie {meta.match.apply}.
              </span>
            </label>
            <label className="text-xs font-medium text-slate-700">
              Zapis zamiennika
              <input
                type="number"
                min={scoreRange.min}
                max={scoreRange.max}
                required
                className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                value={substituteScore}
                onChange={(e) => setSubstituteScore(e.target.value)}
              />
              <span className="mt-1 block text-[11px] font-normal text-slate-500">
                Inna marka/model niż w SIWZ. Nie niżej niż próg zapisu. Domyślnie{' '}
                {meta.match.substitute}.
              </span>
            </label>
            <label className="text-xs font-medium text-slate-700">
              Minimum propozycji
              <input
                type="number"
                min={scoreRange.min}
                max={scoreRange.max}
                required
                className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                value={minScore}
                onChange={(e) => setMinScore(e.target.value)}
              />
              <span className="mt-1 block text-[11px] font-normal text-slate-500">
                Poniżej tego wyniku produkt nie jest w ogóle proponowany. Domyślnie{' '}
                {meta.match.min}.
              </span>
            </label>
          </div>

          <label className="flex items-start gap-2 rounded-lg bg-slate-50 p-3 text-xs text-slate-700">
            <input
              type="checkbox"
              className="mt-0.5"
              checked={allowCatalog}
              onChange={(e) => setAllowCatalog(e.target.checked)}
            />
            <span>
              <b>Wstawiaj też karty z zapasowej listy katalogowej</b>
              <span className="mt-0.5 block text-[11px] text-slate-500">
                Gdy model nie wskaże żadnej karty, pozycja dostaje produkt „tego samego
                rodzaju z katalogu”. Wypełnia więcej pozycji, ale bez oceny modelu —
                domyślnie wyłączone.
              </span>
            </span>
          </label>
        </div>

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
