import { useEffect, useMemo, useState } from 'react'
import { api } from '../lib/api'

/** Producent, którego karty ściąga konto B2B, ze znacznikami: czy ten cennik ustala cenę / opis jego wyrobów. */
type ManufacturerRow = {
  manufacturer: string
  key: string
  /** Liczba różnych kart tego producenta powiązanych z kontem; 0 = sama zapisana reguła. */
  cards: number
  take_price: boolean
  take_description: boolean
  has_rule: boolean
  /** Skąd jest cennik producenta tej marki (np. „cennik producenta: konto Bolle (#4)”); null = brak. */
  own_source: string | null
}

type RulesResponse = {
  /** false = łącznik treści albo z wersjami — w oknie tylko znacznik „opis”. */
  uses_price_rules: boolean
  sync_running: boolean
  manufacturers: ManufacturerRow[]
  /** Po zapisie: karty, na których zmieniła się cena obowiązująca. */
  recomputed?: number
  /** Po zapisie: karty, które mają cenę tylko z wyłączonych źródeł — zostaje ostatnia cena. */
  frozen?: number
}

type Flags = { take_price: boolean; take_description: boolean }
type FlagField = keyof Flags

type Props = {
  account: { id: number; username: string; connector_label: string | null }
  canManage: boolean
  onClose: () => void
}

/** „1 karcie”, „3 kartach” — liczba w miejscowniku. */
function cardsLocative(n: number): string {
  return `${n.toLocaleString('pl-PL')} ${n === 1 ? 'karcie' : 'kartach'}`
}

/** „1 karta ma”, „3 karty mają”, „5 kart ma” — mianownik z orzeczeniem. */
function cardsHave(n: number): string {
  const tens = n % 100
  const units = n % 10
  const few = units >= 2 && units <= 4 && (tens < 12 || tens > 14)
  const noun = n === 1 ? 'karta ma' : few ? 'karty mają' : 'kart ma'
  return `${n.toLocaleString('pl-PL')} ${noun}`
}

function sameFlags(a: Flags, b: Flags): boolean {
  return a.take_price === b.take_price && a.take_description === b.take_description
}

export function B2bManufacturerRulesModal({ account, canManage, onClose }: Props) {
  const [rows, setRows] = useState<ManufacturerRow[]>([])
  /** Niezapisane zmiany znaczników po kluczu producenta; wiersz zgodny z serwerem nie ma tu wpisu. */
  const [edits, setEdits] = useState<Record<string, Flags>>({})
  const [usesPriceRules, setUsesPriceRules] = useState(true)
  const [syncRunning, setSyncRunning] = useState(false)
  const [filter, setFilter] = useState('')
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const [msg, setMsg] = useState('')

  const applyResponse = (r: RulesResponse) => {
    setRows(r.manufacturers)
    setUsesPriceRules(r.uses_price_rules)
    setSyncRunning(r.sync_running)
    setEdits({})
  }

  useEffect(() => {
    let alive = true
    api<RulesResponse>(`/b2b-accounts/${account.id}/manufacturers`)
      .then((r) => {
        if (alive) applyResponse(r)
      })
      .catch((e: unknown) => {
        if (alive) setErr(e instanceof Error ? e.message : 'Nie udało się wczytać producentów.')
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

  const flagsOf = (row: ManufacturerRow): Flags =>
    edits[row.key] ?? { take_price: row.take_price, take_description: row.take_description }

  const visible = useMemo(() => {
    const q = filter.trim().toLocaleLowerCase('pl-PL')
    return q === '' ? rows : rows.filter((r) => r.manufacturer.toLocaleLowerCase('pl-PL').includes(q))
  }, [rows, filter])

  /** Ustawia znacznik na podanych wierszach; powrót do stanu z serwera usuwa wpis ze zmian. */
  const setFlag = (targets: ManufacturerRow[], field: FlagField, value: boolean) => {
    setEdits((prev) => {
      const next = { ...prev }
      for (const row of targets) {
        const original: Flags = { take_price: row.take_price, take_description: row.take_description }
        const flags: Flags = { ...(prev[row.key] ?? original), [field]: value }
        if (sameFlags(flags, original)) delete next[row.key]
        else next[row.key] = flags
      }
      return next
    })
    setMsg('')
  }

  const changedRows = rows.filter((r) => edits[r.key] !== undefined)
  const priceOff = rows.filter((r) => !flagsOf(r).take_price).length
  const descriptionOff = rows.filter((r) => !flagsOf(r).take_description).length

  const onSave = async () => {
    if (changedRows.length === 0) return
    setBusy(true)
    setErr('')
    setMsg('')
    try {
      const payload = changedRows.map((r) => {
        const flags = flagsOf(r)
        return {
          manufacturer: r.manufacturer,
          // łącznik bez reguł ceny: serwer i tak zapisze true — wysyłamy to samo, żeby nie udawać wyłączenia
          take_price: usesPriceRules ? flags.take_price : true,
          take_description: flags.take_description,
        }
      })
      const res = await api<RulesResponse>(`/b2b-accounts/${account.id}/manufacturers`, {
        method: 'PUT',
        body: JSON.stringify({ rules: payload }),
      })
      applyResponse(res)
      const parts = ['Zapisano.']
      if (res.uses_price_rules) parts.push(`Cena zmieniła się na ${cardsLocative(res.recomputed ?? 0)}.`)
      if ((res.frozen ?? 0) > 0) {
        parts.push(`${cardsHave(res.frozen ?? 0)} cenę tylko z wyłączonych źródeł — zostaje ostatnia cena.`)
      }
      if (res.sync_running) {
        parts.push('Trwa pobieranie — zmiany znaczników opisu zadziałają od następnego pobrania.')
      }
      setMsg(parts.join(' '))
    } catch (e: unknown) {
      setErr(e instanceof Error ? e.message : 'Nie udało się zapisać znaczników producentów.')
    } finally {
      setBusy(false)
    }
  }

  /** „wszystkie / żadne” pod nagłówkiem kolumny — dotyczy tylko wierszy widocznych po filtrze. */
  const bulkLinks = (field: FlagField) =>
    canManage &&
    visible.length > 0 && (
      <span className="mt-0.5 block whitespace-nowrap text-[10px] font-normal normal-case tracking-normal">
        <button
          type="button"
          className="text-blue-700 underline hover:text-blue-900"
          title={filter.trim() ? 'Zaznacz u widocznych po filtrze' : 'Zaznacz u wszystkich'}
          onClick={() => setFlag(visible, field, true)}
        >
          wszystkie
        </button>
        {' / '}
        <button
          type="button"
          className="text-blue-700 underline hover:text-blue-900"
          title={filter.trim() ? 'Odznacz u widocznych po filtrze' : 'Odznacz u wszystkich'}
          onClick={() => setFlag(visible, field, false)}
        >
          żadne
        </button>
      </span>
    )

  const colCount = usesPriceRules ? 5 : 4
  // border-separate: przy border-collapse dolna linia przyklejonego nagłówka znika podczas przewijania
  const th = 'sticky top-0 z-10 border-b border-slate-200 bg-white px-2 py-1.5 align-top'
  const td = 'border-b border-slate-100 px-2 py-1'

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
              Producenci — {account.connector_label ?? 'importer B2B'}
            </p>
            <p className="truncate text-xs text-slate-500">
              Konto: {account.username} · producenci, których karty pobiera to konto
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

        <div className="flex min-h-0 flex-1 flex-col px-4 py-3 text-xs">
          {err && <p className="mb-2 shrink-0 rounded bg-red-50 px-3 py-2 text-red-700">{err}</p>}
          {msg && <p className="mb-2 shrink-0 rounded bg-green-50 px-3 py-2 text-green-800">{msg}</p>}

          {loading ? (
            <p className="text-slate-500">Wczytywanie…</p>
          ) : rows.length === 0 ? (
            !err && <p className="text-slate-500">To konto nie pobrało jeszcze produktów.</p>
          ) : (
            <>
              <div className="mb-3 shrink-0 space-y-1 rounded bg-slate-50 px-3 py-2 text-slate-600">
                <p>
                  Odznacz {usesPriceRules ? 'cenę albo opis' : 'opis'}, jeśli ten cennik nie ma{' '}
                  {usesPriceRules ? 'ich' : 'go'} ustalać dla danego producenta. Cennik producenta (jego konto B2B
                  albo plik) zawsze ma pierwszeństwo przed dystrybutorem.
                </p>
                {!usesPriceRules && (
                  <p>Ten importer nie ustala cen kart — można wyłączyć tylko opis.</p>
                )}
                {syncRunning && (
                  <p className="text-amber-700">
                    Trwa pobieranie — zmiany znaczników opisu zadziałają od następnego pobrania.
                  </p>
                )}
              </div>

              <div className="mb-2 flex shrink-0 flex-wrap items-center gap-x-4 gap-y-1">
                <input
                  type="search"
                  className="w-64 rounded border border-slate-300 px-2 py-1"
                  placeholder="Szukaj producenta…"
                  value={filter}
                  onChange={(e) => setFilter(e.target.value)}
                  aria-label="Filtr producentów"
                />
                <span className="text-slate-500">
                  {filter.trim()
                    ? `Pokazano ${visible.length.toLocaleString('pl-PL')} z ${rows.length.toLocaleString('pl-PL')} producentów`
                    : `Producentów: ${rows.length.toLocaleString('pl-PL')}`}
                  {usesPriceRules && priceOff > 0 && ` · bez ceny: ${priceOff.toLocaleString('pl-PL')}`}
                  {descriptionOff > 0 && ` · bez opisu: ${descriptionOff.toLocaleString('pl-PL')}`}
                </span>
              </div>

              <div className="min-h-0 flex-1 overflow-auto rounded border border-slate-200">
                <table className="w-full table-fixed border-separate border-spacing-0">
                  <thead className="text-left text-[11px] uppercase tracking-wide text-slate-500">
                    <tr>
                      <th className={th}>Producent</th>
                      <th className={`${th} w-20 text-right`} title="Liczba kart tego producenta pobranych przez konto">
                        Kart
                      </th>
                      {usesPriceRules && (
                        <th className={`${th} w-24 text-center`} title="Ten cennik ustala cenę wyrobów producenta">
                          Cena
                          {bulkLinks('take_price')}
                        </th>
                      )}
                      <th className={`${th} w-24 text-center`} title="Ten cennik ustala opis wyrobów producenta">
                        Opis
                        {bulkLinks('take_description')}
                      </th>
                      <th className={`${th} w-72`}>Uwagi</th>
                    </tr>
                  </thead>
                  <tbody>
                    {visible.map((row) => {
                      const flags = flagsOf(row)
                      const changed = edits[row.key] !== undefined
                      return (
                        <tr
                          key={row.key}
                          className={changed ? 'bg-amber-50' : 'hover:bg-slate-50'}
                        >
                          <td className={`${td} truncate text-slate-900`} title={row.manufacturer}>
                            {row.manufacturer}
                            {changed && <span className="ml-1 text-[10px] text-amber-700">(zmiana)</span>}
                          </td>
                          <td
                            className={`${td} text-right tabular-nums text-slate-600`}
                            title={row.cards === 0 ? 'Brak kart po ostatnim pobraniu — zostaje zapisana reguła' : undefined}
                          >
                            {row.cards === 0 ? (
                              <span className="text-slate-400">0</span>
                            ) : (
                              row.cards.toLocaleString('pl-PL')
                            )}
                          </td>
                          {usesPriceRules && (
                            <td className={`${td} text-center`}>
                              <input
                                type="checkbox"
                                checked={flags.take_price}
                                disabled={!canManage || busy}
                                aria-label={`Cena z tego cennika: ${row.manufacturer}`}
                                onChange={(e) => setFlag([row], 'take_price', e.target.checked)}
                              />
                            </td>
                          )}
                          <td className={`${td} text-center`}>
                            <input
                              type="checkbox"
                              checked={flags.take_description}
                              disabled={!canManage || busy}
                              aria-label={`Opis z tego cennika: ${row.manufacturer}`}
                              onChange={(e) => setFlag([row], 'take_description', e.target.checked)}
                            />
                          </td>
                          <td className={`${td} truncate text-slate-500`} title={row.own_source ?? undefined}>
                            {row.own_source ?? ''}
                          </td>
                        </tr>
                      )
                    })}
                    {visible.length === 0 && (
                      <tr>
                        <td colSpan={colCount} className="px-2 py-3 text-slate-500">
                          Żaden producent nie pasuje do filtra.
                        </td>
                      </tr>
                    )}
                  </tbody>
                </table>
              </div>
            </>
          )}
        </div>

        <div className="flex items-center justify-between gap-3 border-t border-slate-200 px-4 py-3">
          {canManage ? (
            <span className="text-xs text-slate-500">
              {changedRows.length > 0
                ? `Niezapisane zmiany: ${changedRows.length.toLocaleString('pl-PL')}`
                : 'Brak niezapisanych zmian.'}
            </span>
          ) : (
            <span className="text-xs text-slate-500">Podgląd — brak uprawnień do zmiany znaczników.</span>
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
                disabled={busy || loading || changedRows.length === 0}
                onClick={() => void onSave()}
              >
                {busy ? 'Zapisywanie…' : 'Zapisz'}
              </button>
            )}
          </div>
        </div>
      </div>
    </div>
  )
}
