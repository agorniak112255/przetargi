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

/** price = reguły dają cenę zakupu (protekt.pl); standard = rabat standardowy do wykrywania ceny specjalnej B2B (UVEX). */
type RulesMode = 'price' | 'standard'

/** Arkusz cennika bazowego widziany na kartach konta — podpowiedź wzorca, żeby nie zgadywać nazwy. */
type BaseCategory = { name: string; product_count: number }

/** Rabat standardowy podany przez dostawcę — propozycja dla konta bez reguł. */
type DefaultRule = Pick<Rule, 'name' | 'match_field' | 'match_type' | 'pattern' | 'discount_percent'>

type RulesResponse = {
  rules: Rule[]
  mode?: RulesMode
  categories?: BaseCategory[]
  defaults?: DefaultRule[]
  recomputed?: number
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

/** W trybie standard kategoria karty to nazwa arkusza cennika bazowego. */
function fieldLabel(field: MatchField, mode: RulesMode): string {
  return mode === 'standard' && field === 'category' ? 'arkusz cennika bazowego' : FIELD_LABEL[field]
}

/** „1 karcie”, „3 kartach” — liczba w miejscowniku. */
function cardsLocative(n: number): string {
  return `${n.toLocaleString('pl-PL')} ${n === 1 ? 'karcie' : 'kartach'}`
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
  const [mode, setMode] = useState<RulesMode>('price')
  const [categories, setCategories] = useState<BaseCategory[]>([])
  const [defaults, setDefaults] = useState<DefaultRule[]>([])
  /** Pobieranie arkuszy z aktualnego cennika dostawcy (logowanie + plik, kilka sekund). */
  const [sheetsState, setSheetsState] = useState<'idle' | 'loading' | 'done'>('idle')
  const [sheetsNote, setSheetsNote] = useState('')
  const standard = mode === 'standard'
  const datalistId = `b2b-base-categories-${account.id}`

  const fillDefaults = (list: DefaultRule[]) => {
    setRules(list.map((d) => ({ ...EMPTY_RULE, ...d })))
    setMsg('Wstawiono rabaty standardowe podane przez dostawcę — sprawdź i kliknij „Zapisz rabaty”.')
  }

  useEffect(() => {
    let alive = true
    api<RulesResponse>(`/b2b-accounts/${account.id}/discount-rules`)
      .then((r) => {
        if (!alive) return
        setRules(r.rules)
        setMode(r.mode ?? 'price')
        setCategories(r.categories ?? [])
        setDefaults(r.defaults ?? [])
        // konto bez reguł dostaje propozycję dostawcy w formularzu — zapis dopiero po kliknięciu
        if (r.mode === 'standard' && r.rules.length === 0 && (r.defaults ?? []).length > 0 && canManage) {
          setRules((r.defaults ?? []).map((d) => ({ ...EMPTY_RULE, ...d })))
          setMsg('Wstawiono rabaty standardowe podane przez dostawcę — sprawdź i kliknij „Zapisz rabaty”.')
        }
        if (r.mode === 'standard' && canManage) {
          setSheetsState('loading')
          api<{ categories: BaseCategory[] }>(`/b2b-accounts/${account.id}/discount-rules/base-categories`, {
            method: 'POST',
          })
            .then((s) => {
              if (!alive) return
              setCategories(s.categories)
              setSheetsNote('')
            })
            .catch((e: unknown) => {
              if (alive) {
                setSheetsNote(
                  `Nie udało się pobrać arkuszy z cennika dostawcy: ${e instanceof Error ? e.message : 'błąd'}`,
                )
              }
            })
            .finally(() => {
              if (alive) setSheetsState('done')
            })
        }
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
  }, [account.id, canManage])

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
      const res = await api<RulesResponse>(`/b2b-accounts/${account.id}/discount-rules`, {
        method: 'PUT',
        body: JSON.stringify({ rules: payload }),
      })
      setRules(res.rules)
      if (res.categories) setCategories(res.categories)
      if ((res.mode ?? mode) === 'standard') {
        setMsg(
          res.recomputed !== undefined
            ? `Rabaty standardowe zapisane. Przeliczono ocenę cen — rabat standardowy zmienił się na ${cardsLocative(res.recomputed)}.`
            : 'Rabaty standardowe zapisane.',
        )
      } else {
        setMsg('Rabaty zapisane. Obowiązują od następnego pobrania cennika.')
      }
    } catch (e: unknown) {
      setErr(e instanceof Error ? e.message : 'Nie udało się zapisać rabatów.')
    } finally {
      setBusy(false)
    }
  }

  const hasCatchAll = rules.some((r) => r.match_type === 'any')

  /** Reguła „jest równe” po arkuszu, którego nie ma w znanym cenniku — literówka; zapis i tak odrzuci serwer. */
  const unknownSheet = (r: Rule) =>
    standard &&
    categories.length > 0 &&
    r.match_field === 'category' &&
    r.match_type === 'equals' &&
    r.pattern.trim() !== '' &&
    !categories.some((c) => c.name.trim().toLowerCase() === r.pattern.trim().toLowerCase())

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
              {standard ? 'Rabaty standardowe dostawcy' : 'Rabaty na grupy asortymentowe'} —{' '}
              {account.connector_label ?? 'importer B2B'}
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
              {standard ? (
                <div className="mb-3 space-y-1 rounded bg-slate-50 px-3 py-2 text-slate-600">
                  <p>
                    Reguły ustalają <b>rabat standardowy</b> dla kategorii cennika bazowego dostawcy. Kategoria to
                    nazwa arkusza w pliku cennika bazowego (np. „Hełmy”) — wybierz ją z podpowiedzi, bo reguła
                    „jest równe” musi mieć pełną nazwę arkusza (wielkość liter nie ma znaczenia).
                  </p>
                  <p>
                    Cena konta niższa niż <b>cennik bazowy × (1 − rabat standardowy)</b> ={' '}
                    <b className="text-emerald-800">cena specjalna B2B</b>. To wniosek z porównania — potwierdzenie
                    ceny specjalnej dostawca wysyła mailem. Cena zakupu karty zostaje ceną konta, reguły jej nie
                    zmieniają. Karta bez pasującej reguły jest zapisywana, tylko bez oceny ceny.
                  </p>
                </div>
              ) : (
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
              )}

              {standard && sheetsState === 'loading' && (
                <p className="mb-2 text-[11px] text-slate-500">Pobieram nazwy arkuszy z cennika dostawcy…</p>
              )}
              {standard && sheetsNote && <p className="mb-2 text-[11px] text-amber-700">{sheetsNote}</p>}
              {standard && canManage && defaults.length > 0 && rules.length > 0 && (
                <p className="mb-2 text-[11px] text-slate-500">
                  <button
                    type="button"
                    className="text-blue-700 underline hover:text-blue-900"
                    onClick={() => fillDefaults(defaults)}
                  >
                    Wstaw rabaty standardowe dostawcy
                  </button>{' '}
                  (zastępuje listę w formularzu; zapis dopiero przyciskiem „Zapisz rabaty”)
                </p>
              )}
              {standard && categories.length > 0 && (
                <div className="mb-3">
                  <p className="mb-1 text-[11px] text-slate-500">
                    Arkusze cennika bazowego (liczba kart po ostatniej synchronizacji)
                    {canManage ? ' — kliknij, aby dodać regułę „jest równe”:' : ':'}
                  </p>
                  <div className="flex flex-wrap gap-1">
                    {categories.map((c) => {
                      const covered = rules.some(
                        (r) =>
                          r.match_field === 'category' &&
                          r.match_type === 'equals' &&
                          r.pattern.trim().toLowerCase() === c.name.trim().toLowerCase(),
                      )
                      return (
                        <button
                          key={c.name}
                          type="button"
                          disabled={!canManage || covered}
                          title={covered ? 'Reguła dla tego arkusza już jest na liście' : undefined}
                          className="rounded border border-slate-300 bg-white px-2 py-0.5 text-[11px] text-slate-700 hover:bg-slate-100 disabled:cursor-default disabled:opacity-50"
                          onClick={() => {
                            setRules([
                              ...rules,
                              {
                                ...EMPTY_RULE,
                                name: c.name,
                                match_field: 'category',
                                match_type: 'equals',
                                pattern: c.name,
                              },
                            ])
                            setMsg('')
                          }}
                        >
                          {c.name} <span className="text-slate-400">· {c.product_count}</span>
                        </button>
                      )
                    })}
                  </div>
                  <datalist id={datalistId}>
                    {categories.map((c) => (
                      <option key={c.name} value={c.name}>
                        {`kart: ${c.product_count}`}
                      </option>
                    ))}
                  </datalist>
                </div>
              )}

              <table className="w-full table-fixed border-separate border-spacing-y-1">
                <thead className="text-left text-[11px] uppercase tracking-wide text-slate-500">
                  <tr>
                    <th className="w-8" />
                    <th className="px-2">Grupa</th>
                    <th className={`${standard ? 'w-44' : 'w-28'} px-2`}>Pole</th>
                    <th className="w-32 px-2">Dopasowanie</th>
                    <th className="w-40 px-2">Wzorzec</th>
                    <th
                      className={`${standard ? 'w-24' : 'w-20'} px-2 text-right`}
                      title={standard ? 'Rabat standardowy od ceny z cennika bazowego' : undefined}
                    >
                      {standard ? 'Rabat std. %' : 'Upust %'}
                    </th>
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
                              {fieldLabel(f, mode)}
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
                          className={`w-full rounded border px-2 py-1 disabled:bg-slate-100 ${
                            unknownSheet(rule) ? 'border-red-400 bg-red-50' : 'border-slate-300'
                          }`}
                          title={unknownSheet(rule) ? 'Nie ma takiego arkusza w cenniku bazowym — wybierz z podpowiedzi' : undefined}
                          value={rule.match_type === 'any' ? '' : rule.pattern}
                          disabled={!canManage || rule.match_type === 'any'}
                          list={standard && rule.match_field === 'category' ? datalistId : undefined}
                          placeholder={
                            rule.match_type === 'any'
                              ? 'nie dotyczy'
                              : standard && rule.match_field === 'category'
                                ? 'nazwa arkusza'
                                : 'np. BW'
                          }
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
                        {standard
                          ? 'Brak reguł. Dopóki ich nie dodasz, karty tego konta nie mają oceny ceny specjalnej B2B.'
                          : 'Brak reguł. Dopóki ich nie dodasz, pobieranie cennika pominie wszystkie karty.'}
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
                setRules([
                  ...rules,
                  standard ? { ...EMPTY_RULE, match_field: 'category', match_type: 'equals' } : { ...EMPTY_RULE },
                ])
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
