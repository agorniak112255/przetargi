import { useEffect, useState } from 'react'
import { api } from '../lib/api'

/** Pole karty dostawcy, do którego przykładamy wzorzec reguły. */
type MatchField = 'catalog_no' | 'category' | 'name'
type MatchType = 'prefix' | 'equals' | 'contains' | 'any'

type Rule = {
  id: number | null
  name: string
  match_field: MatchField
  match_type: MatchType
  pattern: string
  discount_percent: number | string
  last_matched_count: number
  last_matched_at: string | null
}

type Props = {
  account: { id: number; username: string; connector_label: string | null }
  canManage: boolean
  onClose: () => void
}

const FIELD_LABEL: Record<MatchField, string> = {
  catalog_no: 'nr katalogowy',
  category: 'kategoria',
  name: 'nazwa',
}

const TYPE_LABEL: Record<MatchType, string> = {
  prefix: 'zaczyna się od',
  equals: 'jest równe',
  contains: 'zawiera',
  any: 'wszystko (łapanka)',
}

const EMPTY_RULE: Rule = {
  id: null,
  name: '',
  match_field: 'catalog_no',
  match_type: 'prefix',
  pattern: '',
  discount_percent: 0,
  last_matched_count: 0,
  last_matched_at: null,
}

export function B2bDiscountRulesModal({ account, canManage, onClose }: Props) {
  const [rules, setRules] = useState<Rule[]>([])
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const [msg, setMsg] = useState('')

  useEffect(() => {
    let alive = true
    api<{ rules: Rule[] }>(`/b2b-accounts/${account.id}/discount-rules`)
      .then((r) => {
        if (alive) setRules(r.rules)
      })
      .catch((e: unknown) => {
        if (alive) setErr(e instanceof Error ? e.message : 'Nie udało się wczytać rabatów.')
      })
      .finally(() => {
        if (alive) setLoading(false)
      })
    return () => {
      alive = false
    }
  }, [account.id])

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose()
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [onClose])

  const patch = (index: number, changes: Partial<Rule>) => {
    setRules(rules.map((r, i) => (i === index ? { ...r, ...changes } : r)))
    setMsg('')
  }

  const move = (index: number, by: number) => {
    const to = index + by
    if (to < 0 || to >= rules.length) return
    const next = [...rules]
    ;[next[index], next[to]] = [next[to], next[index]]
    setRules(next)
    setMsg('')
  }

  const onSave = async () => {
    setBusy(true)
    setErr('')
    setMsg('')
    try {
      const payload = rules.map((r) => ({
        name: r.name,
        match_field: r.match_field,
        match_type: r.match_type,
        pattern: r.match_type === 'any' ? '' : r.pattern,
        discount_percent: Number(r.discount_percent) || 0,
      }))
      const res = await api<{ rules: Rule[] }>(`/b2b-accounts/${account.id}/discount-rules`, {
        method: 'PUT',
        body: JSON.stringify({ rules: payload }),
      })
      setRules(res.rules)
      setMsg('Rabaty zapisane. Obowiązują od następnego pobrania cennika.')
    } catch (e: unknown) {
      setErr(e instanceof Error ? e.message : 'Nie udało się zapisać rabatów.')
    } finally {
      setBusy(false)
    }
  }

  const hasCatchAll = rules.some((r) => r.match_type === 'any')

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
      role="dialog"
      aria-modal="true"
      onClick={onClose}
    >
      <div
        className="flex max-h-[88vh] w-full max-w-4xl flex-col overflow-hidden rounded-xl bg-white shadow-lg"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="flex items-start justify-between gap-3 border-b border-slate-200 px-4 py-3">
          <div className="min-w-0">
            <p className="text-sm font-semibold text-slate-900">
              Rabaty na grupy asortymentowe — {account.connector_label ?? 'importer B2B'}
            </p>
            <p className="truncate text-xs text-slate-500">
              Konto: {account.username} · reguły sprawdzane od góry, pierwsza pasująca wygrywa
            </p>
          </div>
          <button
            type="button"
            onClick={onClose}
            aria-label="Zamknij"
            className="rounded px-2 py-0.5 text-lg leading-none text-slate-500 hover:bg-slate-100 hover:text-slate-900"
          >
            ×
          </button>
        </div>

        <div className="min-h-0 flex-1 overflow-auto px-4 py-3 text-xs">
          {err && <p className="mb-2 rounded bg-red-50 px-3 py-2 text-red-700">{err}</p>}
          {msg && <p className="mb-2 rounded bg-green-50 px-3 py-2 text-green-800">{msg}</p>}

          {loading ? (
            <p className="text-slate-500">Wczytywanie…</p>
          ) : (
            <>
              <p className="mb-3 rounded bg-slate-50 px-3 py-2 text-slate-600">
                Witryna podaje tylko cenę katalogową. Cena zakupu = cena ze strony minus rabat z tej listy.
                Karta, do której nie pasuje żadna reguła, <b>nie zostanie zapisana</b> — znajdziesz ją wśród
                pominiętych w logu pobierania.
                {!hasCatchAll && rules.length > 0 && (
                  <>
                    {' '}
                    Nie masz reguły „wszystko” na końcu listy, więc nowe grupy produktów u dostawcy będą
                    pomijane, dopóki ich tu nie dopiszesz.
                  </>
                )}
              </p>

              <table className="w-full table-fixed border-separate border-spacing-y-1">
                <thead className="text-left text-[11px] uppercase tracking-wide text-slate-500">
                  <tr>
                    <th className="w-8" />
                    <th className="px-2">Grupa</th>
                    <th className="w-28 px-2">Pole</th>
                    <th className="w-32 px-2">Dopasowanie</th>
                    <th className="w-40 px-2">Wzorzec</th>
                    <th className="w-20 px-2 text-right">Upust %</th>
                    <th className="w-24 px-2 text-right">Kart</th>
                    <th className="w-16" />
                  </tr>
                </thead>
                <tbody>
                  {rules.map((rule, index) => (
                    <tr key={rule.id ?? `new-${index}`} className="bg-slate-50">
                      <td className="px-1 text-center align-middle text-slate-400">{index + 1}</td>
                      <td className="px-2 py-1">
                        <input
                          className="w-full rounded border border-slate-300 px-2 py-1"
                          value={rule.name}
                          disabled={!canManage}
                          onChange={(e) => patch(index, { name: e.target.value })}
                        />
                      </td>
                      <td className="px-2 py-1">
                        <select
                          className="w-full rounded border border-slate-300 px-1 py-1"
                          value={rule.match_field}
                          disabled={!canManage}
                          onChange={(e) => patch(index, { match_field: e.target.value as MatchField })}
                        >
                          {(Object.keys(FIELD_LABEL) as MatchField[]).map((f) => (
                            <option key={f} value={f}>
                              {FIELD_LABEL[f]}
                            </option>
                          ))}
                        </select>
                      </td>
                      <td className="px-2 py-1">
                        <select
                          className="w-full rounded border border-slate-300 px-1 py-1"
                          value={rule.match_type}
                          disabled={!canManage}
                          onChange={(e) => patch(index, { match_type: e.target.value as MatchType })}
                        >
                          {(Object.keys(TYPE_LABEL) as MatchType[]).map((t) => (
                            <option key={t} value={t}>
                              {TYPE_LABEL[t]}
                            </option>
                          ))}
                        </select>
                      </td>
                      <td className="px-2 py-1">
                        <input
                          className="w-full rounded border border-slate-300 px-2 py-1 disabled:bg-slate-100"
                          value={rule.match_type === 'any' ? '' : rule.pattern}
                          disabled={!canManage || rule.match_type === 'any'}
                          placeholder={rule.match_type === 'any' ? 'nie dotyczy' : 'np. BW'}
                          onChange={(e) => patch(index, { pattern: e.target.value })}
                        />
                      </td>
                      <td className="px-2 py-1">
                        <input
                          type="number"
                          min={0}
                          max={100}
                          step="0.01"
                          className="w-full rounded border border-slate-300 px-2 py-1 text-right"
                          value={rule.discount_percent}
                          disabled={!canManage}
                          onChange={(e) => patch(index, { discount_percent: e.target.value })}
                        />
                      </td>
                      <td
                        className="px-2 py-1 text-right text-slate-600"
                        title={
                          rule.last_matched_at
                            ? `ostatnie pobranie: ${new Date(rule.last_matched_at).toLocaleString('pl-PL')}`
                            : 'reguła nie brała jeszcze udziału w pobieraniu'
                        }
                      >
                        {rule.last_matched_at === null ? (
                          <span className="text-slate-400">—</span>
                        ) : rule.last_matched_count === 0 ? (
                          <span className="text-amber-700">0</span>
                        ) : (
                          rule.last_matched_count
                        )}
                      </td>
                      <td className="px-1 py-1 text-right">
                        {canManage && (
                          <span className="whitespace-nowrap">
                            <button
                              type="button"
                              className="px-1 text-slate-500 hover:text-slate-900 disabled:text-slate-300"
                              disabled={index === 0}
                              title="W górę"
                              onClick={() => move(index, -1)}
                            >
                              ↑
                            </button>
                            <button
                              type="button"
                              className="px-1 text-slate-500 hover:text-slate-900 disabled:text-slate-300"
                              disabled={index === rules.length - 1}
                              title="W dół"
                              onClick={() => move(index, 1)}
                            >
                              ↓
                            </button>
                            <button
                              type="button"
                              className="px-1 text-red-600 hover:text-red-800"
                              title="Usuń regułę"
                              onClick={() => {
                                setRules(rules.filter((_, i) => i !== index))
                                setMsg('')
                              }}
                            >
                              ×
                            </button>
                          </span>
                        )}
                      </td>
                    </tr>
                  ))}
                  {rules.length === 0 && (
                    <tr>
                      <td colSpan={8} className="px-2 py-3 text-slate-500">
                        Brak reguł. Dopóki ich nie dodasz, pobieranie cennika pominie wszystkie karty.
                      </td>
                    </tr>
                  )}
                </tbody>
              </table>
            </>
          )}
        </div>

        <div className="flex items-center justify-between gap-3 border-t border-slate-200 px-4 py-3">
          {canManage ? (
            <button
              type="button"
              className="rounded border border-slate-300 px-3 py-1.5 text-xs hover:bg-slate-50"
              onClick={() => {
                setRules([...rules, { ...EMPTY_RULE }])
                setMsg('')
              }}
            >
              Dodaj regułę
            </button>
          ) : (
            <span className="text-xs text-slate-500">Podgląd — brak uprawnień do zmiany rabatów.</span>
          )}
          <div className="flex gap-2">
            <button
              type="button"
              className="rounded border border-slate-300 px-3 py-1.5 text-xs hover:bg-slate-50"
              onClick={onClose}
            >
              Zamknij
            </button>
            {canManage && (
              <button
                type="button"
                className="rounded bg-blue-700 px-3 py-1.5 text-xs text-white hover:bg-blue-800 disabled:bg-slate-400"
                disabled={busy || loading}
                onClick={() => void onSave()}
              >
                {busy ? 'Zapisywanie…' : 'Zapisz rabaty'}
              </button>
            )}
          </div>
        </div>
      </div>
    </div>
  )
}
