import { useCallback, useEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { SectionNote } from '../../components/ReportKit'
import { errorText } from '../../lib/campaignFormat'
import { plural } from '../../lib/plural'
import { fetchSalesTargets, fmtDec, groupInt, longDate, NBSP, saveSalesTargets, type SalesTargetsReport, type Tone } from '../../lib/reports'

/**
 * Raport „Cele handlowców” (ekran 11 makiety) — GET/PUT /reports/targets; wymaga reports.view
 * i reports.targets.manage (sprawdza też serwer). Miesięczny cel osoby i realizacja: sprzedaż netto z faktur
 * i paragonów w ERP XL (nocny odczyt) klientów przypisanych do handlowca według dzisiejszego opiekuna.
 *
 * Wybór miesiąca obejmuje także następny miesiąc — cele ustala się z wyprzedzeniem (sprzedaży i realizacji wtedy
 * jeszcze nie ma). Edycja celów w tabeli („Ustaw cele”): zapis wysyła tylko zmienione kwoty, puste pole usuwa cel. Niezapisane zmiany
 * chroni pytanie przy zmianie miesiąca, przejściu linkiem na inną stronę, zamknięciu karty i (przez onDirtyChange)
 * przy zmianie zakładki raportów.
 */

type Row = SalesTargetsReport['rows'][number]

const BAR_FILL: Record<Tone, string> = {
  neutral: 'bg-blue-600',
  good: 'bg-teal-600',
  warn: 'bg-amber-500',
  bad: 'bg-rose-700',
}

/** Kwota z serwera („420000.00”) w pełnych złotych: „420 000 zł”. */
/** „3 października 2026, 05:41” — moment odczytu pełnymi słowami (bez skrótu miesiąca). */
function readStamp(iso: string): string {
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return iso
  return `${longDate(iso)}, ${d.toLocaleTimeString('pl-PL', { hour: '2-digit', minute: '2-digit' })}`
}

function zl(value: string | null): string {
  if (value == null) return '—'
  const n = Number(value)
  return Number.isFinite(n) ? `${groupInt(n)}${NBSP}zł` : value
}

function pct(p: number | null): string {
  return p == null ? '—' : `${fmtDec(p)}%`
}

/**
 * Ton realizacji: miesiąc zamknięty — od 100% dobrze, od 90% uwaga, niżej źle; miesiąc w toku — dobrze, gdy
 * realizacja dotrzymuje tempa dni roboczych (np. po 11 z 22 dni co najmniej 50%), inaczej uwaga.
 */
function tone(p: number | null, d: SalesTargetsReport): Tone {
  if (p == null) return 'neutral'
  if (d.closed || !d.workdays) return p >= 100 ? 'good' : p >= 90 ? 'warn' : 'bad'
  const pace = d.workdays.total > 0 ? (d.workdays.elapsed / d.workdays.total) * 100 : 0
  return p >= pace ? 'good' : 'warn'
}

/**
 * Wpis w polu celu → kwota (null = pole puste, usuwa cel); NaN — niepoprawna (także zero: cel musi być większy od
 * zera, jak sprawdza serwer). Przyjmuje „420 000”, „420000,50”, „420 000 zł”.
 */
function parseAmount(text: string): number | null {
  const clean = text.replace(/[\s ]/g, '').replace(/zł$/i, '').replace(',', '.')
  if (clean === '') return null
  if (!/^\d{1,12}(\.\d{1,2})?$/.test(clean)) return Number.NaN
  const n = Number(clean)
  return n > 0 ? n : Number.NaN
}

/** Kwota celu w polu edycji: „420000” albo „380000,50”. */
function amountText(value: string | null): string {
  if (value == null) return ''
  const n = Number(value)
  if (!Number.isFinite(n)) return value
  return Number.isInteger(n) ? String(n) : n.toFixed(2).replace('.', ',')
}

function sameAmount(a: number | null, b: string | null): boolean {
  if (a == null || b == null) return a == null && b == null
  return Math.round(a * 100) === Math.round(Number(b) * 100)
}

export function ReportTargets({ onDirtyChange }: { onDirtyChange?: (dirty: boolean) => void }) {
  const [month, setMonth] = useState<string | null>(null)
  const [data, setData] = useState<SalesTargetsReport | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [editing, setEditing] = useState(false)
  const [drafts, setDrafts] = useState<Record<number, string>>({})
  const [saving, setSaving] = useState(false)
  const [saveError, setSaveError] = useState('')
  const [saved, setSaved] = useState('')
  const seq = useRef(0)

  const load = useCallback(async (m: string | null) => {
    const my = ++seq.current
    setLoading(true)
    setError('')
    try {
      const res = await fetchSalesTargets(m ?? undefined)
      if (my === seq.current) setData(res)
    } catch (ex) {
      if (my === seq.current) setError(errorText(ex, 'Nie udało się wczytać celów handlowców.'))
    } finally {
      if (my === seq.current) setLoading(false)
    }
  }, [])

  useEffect(() => {
    void load(month)
  }, [load, month])

  // zmienione pola (porównanie z celem z serwera) — tylko je wysyła zapis
  const changed = editing && data ? data.rows.filter((r) => r.user_id in drafts && !sameAmount(parseAmount(drafts[r.user_id]), r.target)) : []
  const invalid = changed.filter((r) => Number.isNaN(parseAmount(drafts[r.user_id]) ?? 0))
  const dirty = changed.length > 0
  const dirtyRef = useRef(dirty)
  dirtyRef.current = dirty

  const onDirtyChangeRef = useRef(onDirtyChange)
  onDirtyChangeRef.current = onDirtyChange
  useEffect(() => {
    onDirtyChangeRef.current?.(dirty)
  }, [dirty])
  useEffect(() => () => onDirtyChangeRef.current?.(false), [])

  // zamknięcie albo odświeżenie karty z niezapisanymi celami — pytanie przeglądarki
  useEffect(() => {
    if (!dirty) return
    const warn = (e: BeforeUnloadEvent) => {
      if (!dirtyRef.current) return
      e.preventDefault()
      e.returnValue = ''
    }
    window.addEventListener('beforeunload', warn)
    return () => window.removeEventListener('beforeunload', warn)
  }, [dirty])

  // link do innej strony aplikacji (menu, dashboard) z niezapisanymi celami — pytanie przed przejściem
  // (BrowserRouter nie ma useBlocker; nasłuch w fazie przechwytywania działa przed <Link>)
  useEffect(() => {
    if (!dirty) return
    const onClick = (e: MouseEvent) => {
      if (e.defaultPrevented || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return
      const anchor = e.target instanceof Element ? e.target.closest('a[href]') : null
      if (!(anchor instanceof HTMLAnchorElement)) return
      if ((anchor.target && anchor.target !== '_self') || anchor.hasAttribute('download')) return
      const url = new URL(anchor.href, window.location.href)
      if (url.origin !== window.location.origin || url.pathname === window.location.pathname) return
      if (!dirtyRef.current) return
      if (window.confirm('Cele mają niezapisane zmiany. Przejść dalej bez zapisu? Zmiany przepadną.')) {
        dirtyRef.current = false
        return
      }
      e.preventDefault()
      e.stopPropagation()
    }
    document.addEventListener('click', onClick, true)
    return () => document.removeEventListener('click', onClick, true)
  }, [dirty])

  function mayDiscard(): boolean {
    return !dirtyRef.current || window.confirm('Cele mają niezapisane zmiany. Odrzucić je?')
  }

  function changeMonth(next: string) {
    if (!mayDiscard()) return
    setEditing(false)
    setDrafts({})
    setSaveError('')
    setSaved('')
    setMonth(next)
  }

  function startEditing() {
    if (!data) return
    setDrafts(Object.fromEntries(data.rows.map((r) => [r.user_id, amountText(r.target)])))
    setSaveError('')
    setSaved('')
    setEditing(true)
  }

  function cancelEditing() {
    if (!mayDiscard()) return
    setEditing(false)
    setDrafts({})
    setSaveError('')
  }

  async function save() {
    if (!data || changed.length === 0 || invalid.length > 0) return
    const shownMonth = data.month
    setSaving(true)
    setSaveError('')
    setSaved('')
    try {
      const res = await saveSalesTargets(
        shownMonth,
        changed.map((r) => ({ user_id: r.user_id, amount: parseAmount(drafts[r.user_id]) })),
      )
      // nowsze wczytanie (inny miesiąc) nie jest nadpisywane odpowiedzią zapisu
      seq.current++
      setData(res)
      setLoading(false)
      setEditing(false)
      setDrafts({})
      setSaved(`Zapisano ${changed.length} ${plural(changed.length, 'cel', 'cele', 'celów')}.`)
    } catch (ex) {
      setSaveError(errorText(ex, 'Nie udało się zapisać celów.'))
    } finally {
      setSaving(false)
    }
  }

  const months = data?.months ?? []
  const selected = data?.month ?? month ?? ''

  return (
    <div aria-busy={loading}>
      <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
        <div className="flex flex-wrap items-center gap-2">
          <label className="flex items-center gap-2 text-xs text-slate-600">
            Miesiąc
            <select
              className="rounded-md border border-slate-300 bg-white px-2 py-1.5 text-sm text-slate-900"
              value={selected}
              disabled={months.length === 0 || saving}
              onChange={(e) => changeMonth(e.target.value)}
            >
              {months.map((m) => (
                <option key={m.key} value={m.key}>
                  {m.label}
                </option>
              ))}
            </select>
          </label>
          {!editing ? (
            <button
              type="button"
              disabled={!data || loading}
              onClick={startEditing}
              className="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50"
            >
              Ustaw cele
            </button>
          ) : (
            <>
              <button
                type="button"
                disabled={saving || changed.length === 0 || invalid.length > 0}
                onClick={() => void save()}
                className="rounded-md bg-blue-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-blue-700 disabled:opacity-50"
              >
                {saving ? 'Zapisywanie…' : changed.length > 0 ? `Zapisz cele (${changed.length})` : 'Zapisz cele'}
              </button>
              <button
                type="button"
                disabled={saving}
                onClick={cancelEditing}
                className="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs text-slate-700 hover:bg-slate-50 disabled:opacity-50"
              >
                Anuluj
              </button>
            </>
          )}
          {saved && <span className="text-xs text-emerald-700">{saved}</span>}
          {saveError && (
            <span className="text-xs text-red-700" role="alert">
              {saveError}
            </span>
          )}
        </div>
        {data && (
          <span className="text-xs text-slate-500">
            {data.data_until
              ? `Faktury i paragony z ERP XL według odczytu z ${readStamp(data.data_until)}`
              : 'Nie ma jeszcze nocnego odczytu faktur i paragonów z ERP XL'}
          </span>
        )}
      </div>

      {error && (
        <div className="mb-3 flex items-center justify-between gap-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-800" role="alert">
          <span>{error}</span>
          <button type="button" onClick={() => void load(month)} className="shrink-0 font-medium underline">
            Spróbuj ponownie
          </button>
        </div>
      )}
      {!data && loading && (
        <div role="status" aria-label="Wczytywanie raportu" className="h-64 animate-pulse rounded-xl bg-white shadow-sm" />
      )}
      {data && (
        <div className={`transition-opacity ${loading ? 'opacity-50' : ''}`}>
          <TargetsCard
            d={data}
            editing={editing}
            drafts={drafts}
            onDraft={(userId, text) => setDrafts((prev) => ({ ...prev, [userId]: text }))}
            invalidIds={new Set(invalid.map((r) => r.user_id))}
            disabled={saving}
          />
        </div>
      )}
    </div>
  )
}

function TargetsCard({
  d,
  editing,
  drafts,
  onDraft,
  invalidIds,
  disabled,
}: {
  d: SalesTargetsReport
  editing: boolean
  drafts: Record<number, string>
  onDraft: (userId: number, text: string) => void
  invalidIds: Set<number>
  disabled: boolean
}) {
  const label = d.months.find((m) => m.key === d.month)?.label ?? d.month
  const state = d.closed
    ? 'zamknięty miesiąc'
    : d.upcoming
      ? 'miesiąc jeszcze się nie zaczął'
      : d.workdays
      ? `miesiąc w toku: ${d.workdays.elapsed} z ${d.workdays.total} ${plural(d.workdays.total, 'dnia roboczego', 'dni roboczych', 'dni roboczych')}`
      : 'miesiąc w toku'
  const totalTone = tone(d.total.percent, d)

  return (
    <section className="app-card app-report-card min-w-0 rounded-xl bg-white p-4 shadow-sm">
      <header className="mb-3 flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
        <div className="min-w-0">
          <h2 className="app-card-title text-sm font-semibold text-slate-900">
            {label} · {state}
          </h2>
          <p className="mt-0.5 max-w-3xl text-xs text-slate-500">
            Sprzedaż netto z faktur i paragonów w ERP XL, po korektach, klientów przypisanych do handlowca (opiekun z karty w ERP
            XL albo opiekun w aplikacji).
          </p>
        </div>
        {d.total.percent != null && (
          <span
            className={`rounded-full px-2.5 py-1 text-xs font-semibold ${
              totalTone === 'good' ? 'bg-teal-50 text-teal-800' : totalTone === 'bad' ? 'bg-rose-50 text-rose-800' : 'bg-amber-50 text-amber-800'
            }`}
            title="Sprzedaż osób, które mają cel, wobec sumy ich celów"
          >
            Osoby z celem: {pct(d.total.percent)} sumy celów
          </span>
        )}
      </header>

      {d.rows.length === 0 && d.unassigned.clients_bought === 0 && Number(d.unassigned.sales) === 0 ? (
        <SectionNote>
          W tym miesiącu nie ma ani celów, ani sprzedaży do pokazania. Handlowcy pojawiają się tu, gdy mają rolę „handlowiec”, cel
          albo przypisanego pracownika ERP XL.
        </SectionNote>
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full min-w-[640px] text-left text-sm">
            <thead>
              <tr className="border-b border-slate-200 text-xs text-slate-500">
                <th className="py-2 pr-3 font-medium">Handlowiec</th>
                <th className="py-2 pr-3 text-right font-medium">Cel</th>
                <th className="py-2 pr-3 text-right font-medium">Sprzedaż</th>
                <th className="w-[28%] py-2 pr-3 font-medium">Realizacja</th>
                <th className="py-2 pr-3 text-right font-medium">Klienci, którzy kupili</th>
                <th className="py-2 text-right font-medium">w tym nowi</th>
              </tr>
            </thead>
            <tbody>
              {d.rows.map((r) => (
                <TargetRow
                  key={r.user_id}
                  r={r}
                  d={d}
                  editing={editing}
                  draft={drafts[r.user_id] ?? ''}
                  onDraft={(text) => onDraft(r.user_id, text)}
                  invalid={invalidIds.has(r.user_id)}
                  disabled={disabled}
                />
              ))}
              <tr className="border-b border-slate-100 text-slate-500">
                <td className="py-2 pr-3">Klienci bez opiekuna</td>
                <td className="py-2 pr-3 text-right">—</td>
                <td className="py-2 pr-3 text-right tabular-nums">{zl(d.unassigned.sales)}</td>
                <td className="py-2 pr-3" />
                <td className="py-2 pr-3 text-right tabular-nums">{groupInt(d.unassigned.clients_bought)}</td>
                <td className="py-2 text-right tabular-nums">{groupInt(d.unassigned.new_clients)}</td>
              </tr>
            </tbody>
            <tfoot>
              <tr className="font-semibold text-slate-900">
                <td className="py-2 pr-3">Razem handlowcy</td>
                <td className="py-2 pr-3 text-right tabular-nums">{zl(d.total.target)}</td>
                <td className="py-2 pr-3 text-right tabular-nums">{zl(d.total.sales)}</td>
                <td className="py-2 pr-3 text-xs font-normal text-slate-500">
                  {d.total.percent != null ? `osoby z celem: ${pct(d.total.percent)}` : ''}
                </td>
                <td className="py-2 pr-3" />
                <td className="py-2" />
              </tr>
            </tfoot>
          </table>
        </div>
      )}

      <div className="mt-3 space-y-1 text-xs text-slate-500">
        <p>
          Wiersz „Klienci bez opiekuna” pokazuje sprzedaż, której nikt nie ma w celu. Warto przypisać tych klientów: opiekuna
          w aplikacji ustawisz w zakładce <Link to="/clients" className="app-link text-blue-700 hover:underline">Klienci</Link>,
          a pracownika ERP XL do konta w Administracji → Użytkownicy.
        </p>
        <p>{d.rule}</p>
        {d.upcoming && (
          <p>Ten miesiąc jeszcze się nie zaczął: cele można ustawić z wyprzedzeniem, sprzedaż i realizacja pojawią się od jego pierwszego dnia.</p>
        )}
        {!d.closed && !d.upcoming && (
          <p>Miesiąc w toku: sprzedaż obejmuje dokumenty do ostatniego nocnego odczytu z ERP XL, nie z dzisiejszego dnia.</p>
        )}
        <p>Dni robocze to dni od poniedziałku do piątku — święta nie są odliczane.</p>
      </div>
    </section>
  )
}

function TargetRow({
  r,
  d,
  editing,
  draft,
  onDraft,
  invalid,
  disabled,
}: {
  r: Row
  d: SalesTargetsReport
  editing: boolean
  draft: string
  onDraft: (text: string) => void
  invalid: boolean
  disabled: boolean
}) {
  const t = tone(r.percent, d)
  const width = r.percent == null ? 0 : Math.max(1.5, Math.min(100, r.percent))
  const inputId = `target-${r.user_id}`
  return (
    <tr className="border-b border-slate-100 align-middle">
      <td className="py-2 pr-3">
        <span className="text-slate-900">{r.name}</span>
        {!r.has_employee && (
          <span
            className="ml-1.5 text-[11px] text-slate-500"
            title="Klienci z opiekunem w ERP XL liczą się tej osobie dopiero po przypisaniu pracownika ERP XL do jej konta (Administracja → Użytkownicy). Liczą się klienci, którym ustawiono ją jako opiekuna w aplikacji."
          >
            · bez pracownika ERP XL
          </span>
        )}
      </td>
      <td className="py-2 pr-3 text-right tabular-nums">
        {editing ? (
          <span className="inline-flex flex-col items-end">
            <label htmlFor={inputId} className="sr-only">
              Cel: {r.name} (zł netto)
            </label>
            <input
              id={inputId}
              inputMode="decimal"
              autoComplete="off"
              placeholder="bez celu"
              value={draft}
              disabled={disabled}
              aria-invalid={invalid || undefined}
              onChange={(e) => onDraft(e.target.value)}
              className={`w-32 rounded border px-2 py-1 text-right text-sm ${invalid ? 'border-red-500' : 'border-slate-300'}`}
            />
            {invalid && <span className="mt-0.5 text-[11px] text-red-700">Wpisz kwotę w złotych albo zostaw puste</span>}
          </span>
        ) : (
          zl(r.target)
        )}
      </td>
      <td
        className="py-2 pr-3 text-right tabular-nums"
        title={`Z opiekuna w ERP XL: ${zl(r.by_source.xl)} · z opiekuna w aplikacji: ${zl(r.by_source.app)}`}
      >
        {zl(r.sales)}
      </td>
      <td className="py-2 pr-3">
        {r.percent != null ? (
          <span className="flex items-center gap-2">
            <span className="h-2 flex-1 overflow-hidden rounded-full bg-slate-100" aria-hidden>
              <span className={`block h-full rounded-full ${BAR_FILL[t]}`} style={{ width: `${width}%` }} />
            </span>
            <span className="w-14 text-right tabular-nums text-slate-900">{pct(r.percent)}</span>
          </span>
        ) : (
          <span className="text-xs text-slate-500">{d.upcoming && r.target != null ? 'od początku miesiąca' : 'bez celu'}</span>
        )}
      </td>
      <td className="py-2 pr-3 text-right tabular-nums">{groupInt(r.clients_bought)}</td>
      <td className="py-2 text-right tabular-nums">{groupInt(r.new_clients)}</td>
    </tr>
  )
}
