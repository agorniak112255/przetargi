import { useCallback, useEffect, useRef, useState } from 'react'
import { BTN, BTN_PRIMARY, BTN_SM, Chip, ConfirmDialog, ErrorBar, INPUT, Modal } from '../components/CampaignsUi'
import { errorText, fmtDateTime, fmtInt, fmtQty } from '../lib/campaignFormat'
import {
  INSPECTION_INTERVALS,
  XL_TYPE_GOODS,
  acceptInspectionSuggestions,
  createInspectionPositions,
  deleteInspectionPosition,
  intervalLabel,
  listInspectionPositions,
  listInspectionSuggestions,
  rejectInspectionSuggestions,
  searchInspectionCatalog,
  updateInspectionPosition,
  xlTypeLabel,
  type InspectionCatalogItem,
  type InspectionCatalogType,
  type InspectionPositionPatch,
  type InspectionPositionRow,
  type InspectionSuggestionCandidate,
  type InspectionSuggestionGroup,
} from '../lib/inspections'
import { plural } from '../lib/plural'
import { InspectionsTabs } from './Inspections'

/**
 * Pozycje przeglądów (/przeglady/pozycje, uprawnienie inspections.manage): towary i usługi z ERP XL, dla których
 * system liczy terminy u klientów. Interwał wybiera człowiek z listy (1–24 mies.) — system go nie wymyśla. Towar może
 * mieć usługę, która go odnawia (sprzedaż usługi po zakupie zamyka termin zakupu). Podpowiedzi z wzorca to propozycje
 * po podobnej nazwie — każdą zatwierdza człowiek.
 */

const SEARCH_DEBOUNCE_MS = 300
const MAX_ADD = 100
const CATALOG_TYPES: { value: InspectionCatalogType; label: string }[] = [
  { value: 'all', label: 'usługi i towary' },
  { value: 'service', label: 'tylko usługi' },
  { value: 'goods', label: 'tylko towary' },
]

function IntervalSelect({
  value,
  onChange,
  disabled,
  allowEmpty,
  label,
}: {
  value: number | null
  onChange: (months: number) => void
  disabled?: boolean
  /** Brak domyślnego interwału — człowiek musi go wybrać. */
  allowEmpty?: boolean
  label: string
}) {
  return (
    <select
      className={INPUT}
      aria-label={label}
      value={value ?? ''}
      disabled={disabled}
      onChange={(e) => {
        if (e.target.value !== '') onChange(Number(e.target.value))
      }}
    >
      {allowEmpty && <option value="">wybierz…</option>}
      {INSPECTION_INTERVALS.map((m) => (
        <option key={m} value={m}>
          {intervalLabel(m)}
        </option>
      ))}
    </select>
  )
}

export function InspectionPositions() {
  const [rows, setRows] = useState<InspectionPositionRow[] | null>(null)
  const [err, setErr] = useState('')
  const [busyId, setBusyId] = useState<number | null>(null)
  const [addOpen, setAddOpen] = useState(false)
  const [renewFor, setRenewFor] = useState<InspectionPositionRow | null>(null)
  const [toDelete, setToDelete] = useState<InspectionPositionRow | null>(null)
  const [deleteBusy, setDeleteBusy] = useState(false)
  const [deleteErr, setDeleteErr] = useState('')
  const [suggestionsKey, setSuggestionsKey] = useState(0)
  const seq = useRef(0)

  const load = useCallback(async () => {
    const my = ++seq.current
    try {
      const res = await listInspectionPositions()
      if (my !== seq.current) return
      setRows(res.data)
    } catch (ex) {
      if (my === seq.current) setErr(errorText(ex, 'Nie udało się wczytać pozycji przeglądów.'))
    }
  }, [])

  useEffect(() => {
    void load()
  }, [load])

  async function patch(row: InspectionPositionRow, p: InspectionPositionPatch) {
    setBusyId(row.id)
    setErr('')
    try {
      await updateInspectionPosition(row.id, p)
      await load()
    } catch (ex) {
      setErr(errorText(ex, 'Nie udało się zapisać pozycji.'))
    } finally {
      setBusyId(null)
    }
  }

  async function confirmDelete() {
    if (!toDelete) return
    setDeleteBusy(true)
    setDeleteErr('')
    try {
      await deleteInspectionPosition(toDelete.id)
      setToDelete(null)
      await load()
      // usunięta pozycja może znowu pojawić się w podpowiedziach
      setSuggestionsKey((k) => k + 1)
    } catch (ex) {
      setDeleteErr(errorText(ex, 'Nie udało się usunąć pozycji.'))
    } finally {
      setDeleteBusy(false)
    }
  }

  const defined = new Set((rows ?? []).map((r) => r.xl_gid))

  return (
    <div>
      <InspectionsTabs />
      <div className="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold">Pozycje przeglądów</h1>
          <p className="mt-1 max-w-3xl text-xs text-slate-600">
            Towary i usługi z ERP XL, dla których system liczy terminy przeglądów u klientów. Interwał wybierasz Ty —
            system go nie wymyśla i nie podaje przepisów. Termin u klienta = ostatnia sprzedaż tej pozycji + interwał.
          </p>
        </div>
        <button type="button" className={BTN_PRIMARY} onClick={() => setAddOpen(true)}>
          + Dodaj z ERP XL
        </button>
      </div>

      <ErrorBar message={err} onClose={() => setErr('')} />

      <div className="overflow-x-auto rounded-xl bg-white p-4 shadow-sm">
        <div className="mb-2 flex flex-wrap items-baseline justify-between gap-2">
          <h2 className="text-sm font-semibold text-slate-900">Zdefiniowane pozycje</h2>
          <span className="text-xs text-slate-500">{rows ? `Łącznie ${fmtInt(rows.length)}` : 'ładowanie…'}</span>
        </div>
        <p className="mb-2 text-xs text-slate-500">
          Zmiana interwału, usługi odnawiającej albo wyłączenie pozycji od razu przelicza terminy u klientów.
        </p>
        <table className="w-full text-left text-xs">
          <thead>
            <tr className="border-b bg-slate-50 text-slate-700">
              <th className="p-2">Pozycja z ERP XL</th>
              <th className="p-2">Interwał przeglądu</th>
              <th className="p-2" title="Tylko dla towaru: sprzedaż tej usługi po zakupie urządzenia zamyka termin zakupu">
                Usługa odnawiająca
              </th>
              <th className="p-2">Uwaga</th>
              <th className="p-2 text-center">Aktywna</th>
              <th className="whitespace-nowrap p-2 text-right">Klienci z terminem</th>
              <th className="p-2">Skąd i kto</th>
              <th className="p-2" />
            </tr>
          </thead>
          <tbody>
            {(rows ?? []).map((r) => (
              <tr key={r.id} className={`border-b align-top ${r.active ? '' : 'text-slate-500'}`}>
                <td className="min-w-[16rem] p-2">
                  <div className="font-medium text-slate-900">{r.name}</div>
                  <div className="text-[11px] text-slate-500">
                    <span className="font-mono">{r.code}</span> · {xlTypeLabel(r.xl_type)}
                    {r.unit ? ` · ${r.unit}` : ''}
                  </div>
                  {r.xl_type === XL_TYPE_GOODS && !r.history_loaded && (
                    <div className="mt-0.5">
                      <Chip tone="amber" title="Historia sprzedaży towaru od 2019 roku zostanie odczytana z ERP XL przy najbliższym odczycie nocnym">
                        historia sprzedaży czeka na odczyt z ERP XL
                      </Chip>
                    </div>
                  )}
                </td>
                <td className="whitespace-nowrap p-2">
                  <IntervalSelect
                    value={r.interval_months}
                    label={`Interwał przeglądu: ${r.name}`}
                    disabled={busyId === r.id}
                    onChange={(m) => {
                      if (m !== r.interval_months) void patch(r, { interval_months: m })
                    }}
                  />
                </td>
                <td className="min-w-[12rem] p-2">
                  {r.xl_type === XL_TYPE_GOODS ? (
                    <>
                      {r.renewed_by ? (
                        <div className="text-slate-800">
                          {r.renewed_by.name} <span className="font-mono text-[11px] text-slate-500">{r.renewed_by.code}</span>
                        </div>
                      ) : (
                        <div className="text-slate-400">brak</div>
                      )}
                      <span className="inline-flex gap-1">
                        <button type="button" className={BTN_SM} disabled={busyId === r.id} onClick={() => setRenewFor(r)}>
                          {r.renewed_by ? 'Zmień' : 'Wybierz usługę'}
                        </button>
                        {r.renewed_by && (
                          <button
                            type="button"
                            className={BTN_SM}
                            disabled={busyId === r.id}
                            onClick={() => void patch(r, { renewed_by_xl_gid: null })}
                          >
                            Usuń
                          </button>
                        )}
                      </span>
                    </>
                  ) : (
                    <span className="text-slate-400" title="Usługa sama jest przeglądem — nie potrzebuje usługi odnawiającej">
                      nie dotyczy usługi
                    </span>
                  )}
                </td>
                <td className="min-w-[12rem] p-2">
                  <NoteField
                    value={r.note}
                    label={`Uwaga: ${r.name}`}
                    disabled={busyId === r.id}
                    onCommit={(note) => void patch(r, { note })}
                  />
                </td>
                <td className="p-2 text-center">
                  <input
                    type="checkbox"
                    checked={r.active}
                    disabled={busyId === r.id}
                    aria-label={`Aktywna: ${r.name}`}
                    title="Wyłączona pozycja nie daje terminów na liście (definicja zostaje)"
                    onChange={(e) => void patch(r, { active: e.target.checked })}
                  />
                </td>
                <td className="whitespace-nowrap p-2 text-right tabular-nums">
                  <div className="text-slate-800">{fmtInt(r.customers_due)}</div>
                  {r.customers_overdue > 0 && <div className="text-[11px] text-red-700">zaległych: {fmtInt(r.customers_overdue)}</div>}
                </td>
                <td className="p-2 text-[11px] text-slate-600">
                  <div>
                    {r.source === 'suggestion'
                      ? `z podpowiedzi${r.pattern ? ` (wzór: ${r.pattern.name})` : ''}`
                      : 'dodana ręcznie'}
                  </div>
                  <div>
                    {r.created_by_name ? `dodał ${r.created_by_name}` : ''}
                    {r.updated_by_name ? `${r.created_by_name ? ', ' : ''}zmienił ${r.updated_by_name}` : ''}
                  </div>
                  <div className="text-slate-500">{fmtDateTime(r.updated_at)}</div>
                </td>
                <td className="p-2 text-right">
                  <button
                    type="button"
                    className={`${BTN_SM} text-red-700`}
                    onClick={() => {
                      setDeleteErr('')
                      setToDelete(r)
                    }}
                  >
                    Usuń
                  </button>
                </td>
              </tr>
            ))}
            {rows && rows.length === 0 && (
              <tr>
                <td colSpan={8} className="p-8 text-center text-slate-500">
                  Nie ma jeszcze pozycji przeglądów. Kliknij „+ Dodaj z ERP XL”, wyszukaj usługi przeglądów (np. „przegląd
                  gaśnicy”) i wybierz interwał.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>

      <SuggestionsSection key={suggestionsKey} onAccepted={() => void load()} />

      {addOpen && (
        <AddFromCatalogModal
          defined={defined}
          onClose={() => setAddOpen(false)}
          onAdded={() => {
            setAddOpen(false)
            void load()
            setSuggestionsKey((k) => k + 1)
          }}
        />
      )}
      {renewFor && (
        <ServicePickerModal
          position={renewFor}
          onClose={() => setRenewFor(null)}
          onPick={(svc) => {
            const r = renewFor
            setRenewFor(null)
            void patch(r, { renewed_by_xl_gid: svc.xl_gid })
          }}
        />
      )}
      {toDelete && (
        <ConfirmDialog
          title="Usunąć pozycję przeglądu?"
          danger
          confirmLabel="Usuń pozycję"
          busy={deleteBusy}
          error={deleteErr}
          onClose={() => setToDelete(null)}
          onConfirm={() => void confirmDelete()}
          message={
            <>
              <p>
                <b>{toDelete.name}</b> ({toDelete.code}) przestanie być pozycją przeglądu. Znikną jej terminy u{' '}
                {fmtInt(toDelete.customers_due)} {plural(toDelete.customers_due, 'klienta', 'klientów', 'klientów')}.
              </p>
              <p className="text-xs text-slate-600">
                Faktury z ERP XL zostają — po ponownym dodaniu pozycji terminy policzą się od nowa. Jeśli chcesz tylko
                wstrzymać terminy, odznacz „Aktywna”.
              </p>
            </>
          }
        />
      )}
    </div>
  )
}

/** Uwaga przy pozycji (tylko dla pracowników): szkic lokalnie, zapis po wyjściu z pola. */
function NoteField({
  value,
  label,
  disabled,
  onCommit,
}: {
  value: string | null
  label: string
  disabled: boolean
  onCommit: (note: string | null) => void
}) {
  const [draft, setDraft] = useState(value ?? '')
  const [focused, setFocused] = useState(false)
  const [lastValue, setLastValue] = useState(value)
  if (value !== lastValue && !focused) {
    setLastValue(value)
    setDraft(value ?? '')
  }
  return (
    <input
      type="text"
      className={`${INPUT} w-full`}
      aria-label={label}
      placeholder="np. podstawa interwału"
      maxLength={500}
      value={draft}
      disabled={disabled}
      onFocus={() => setFocused(true)}
      onChange={(e) => setDraft(e.target.value)}
      onKeyDown={(e) => {
        if (e.key === 'Enter') e.currentTarget.blur()
      }}
      onBlur={() => {
        setFocused(false)
        const next = draft.trim() === '' ? null : draft.trim()
        if (next !== value) onCommit(next)
      }}
    />
  )
}

/** Wyszukiwarka katalogu ERP XL (usługi i towary) z opóźnieniem; najwyżej 50 wyników z serwera. */
function useCatalogSearch(type: InspectionCatalogType) {
  const [q, setQ] = useState('')
  const [items, setItems] = useState<InspectionCatalogItem[]>([])
  const [loading, setLoading] = useState(false)
  const [err, setErr] = useState('')
  const seq = useRef(0)

  useEffect(() => {
    const query = q.trim()
    const my = ++seq.current
    if (query.length < 2) {
      setItems([])
      setLoading(false)
      return
    }
    const t = window.setTimeout(() => {
      setLoading(true)
      searchInspectionCatalog(query, type)
        .then((res) => {
          if (my !== seq.current) return
          setItems(res.data)
          setErr('')
        })
        .catch((ex: unknown) => {
          if (my === seq.current) setErr(errorText(ex, 'Nie udało się przeszukać katalogu ERP XL.'))
        })
        .finally(() => {
          if (my === seq.current) setLoading(false)
        })
    }, SEARCH_DEBOUNCE_MS)
    return () => window.clearTimeout(t)
  }, [q, type])

  return { q, setQ, items, loading, err }
}

function UsageCell({ item }: { item: { xl_type: number; customers_24m: number; quantity_24m: number; unit: string | null } }) {
  return (
    <td
      className="whitespace-nowrap p-2 text-right tabular-nums"
      title={
        item.xl_type === XL_TYPE_GOODS
          ? 'Towar: klienci z zakupem w ostatnich 24 miesiącach i suma sztuk z ich kartoteki zakupów w ERP XL'
          : 'Usługa: klienci i sztuki z faktur i WZ w ERP XL z ostatnich 24 miesięcy'
      }
    >
      <div className="text-slate-800">
        {fmtInt(item.customers_24m)} {plural(item.customers_24m, 'klient', 'klientów', 'klientów')}
      </div>
      <div className="text-[11px] text-slate-500">{fmtQty(item.quantity_24m, item.unit)}</div>
    </td>
  )
}

function AddFromCatalogModal({
  defined,
  onClose,
  onAdded,
}: {
  defined: Set<number>
  onClose: () => void
  onAdded: () => void
}) {
  const [type, setType] = useState<InspectionCatalogType>('service')
  const search = useCatalogSearch(type)
  const [picked, setPicked] = useState<Map<number, InspectionCatalogItem>>(() => new Map())
  const [interval, setCommonInterval] = useState<number | null>(null)
  const [note, setNote] = useState('')
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  function toggle(item: InspectionCatalogItem) {
    setPicked((cur) => {
      const next = new Map(cur)
      if (next.has(item.xl_gid)) next.delete(item.xl_gid)
      else next.set(item.xl_gid, item)
      return next
    })
  }

  async function submit() {
    if (interval === null) {
      setErr('Wybierz interwał przeglądu.')
      return
    }
    setBusy(true)
    setErr('')
    try {
      await createInspectionPositions(
        [...picked.values()].map((i) => ({
          xl_gid: i.xl_gid,
          interval_months: interval,
          renewed_by_xl_gid: null,
          note: note.trim() === '' ? null : note.trim(),
        })),
      )
      onAdded()
    } catch (ex) {
      setErr(errorText(ex, 'Nie udało się dodać pozycji.'))
      setBusy(false)
    }
  }

  const ready = picked.size > 0 && picked.size <= MAX_ADD && interval !== null
  return (
    <Modal
      title="Dodaj pozycje przeglądów z ERP XL"
      wide="full"
      busy={busy}
      onClose={onClose}
      footer={
        <>
          <span className="mr-auto text-xs text-slate-600">
            Zaznaczono {fmtInt(picked.size)} {plural(picked.size, 'pozycję', 'pozycje', 'pozycji')}
            {picked.size > MAX_ADD && <span className="text-red-700"> — najwyżej {MAX_ADD} naraz</span>}
          </span>
          <label className="flex items-center gap-1.5 text-xs text-slate-700">
            Wspólny interwał
            <IntervalSelect value={interval} allowEmpty label="Wspólny interwał przeglądu" onChange={setCommonInterval} />
          </label>
          <input
            type="text"
            className={`${INPUT} w-56`}
            placeholder="uwaga, np. podstawa interwału"
            aria-label="Uwaga do dodawanych pozycji"
            maxLength={500}
            value={note}
            onChange={(e) => setNote(e.target.value)}
          />
          <button type="button" className={BTN} disabled={busy} onClick={onClose}>
            Anuluj
          </button>
          <button type="button" className={BTN_PRIMARY} disabled={busy || !ready} onClick={() => void submit()}>
            {busy ? 'Dodaję…' : `Dodaj ${picked.size > 0 ? fmtInt(picked.size) : ''}`.trim()}
          </button>
        </>
      }
    >
      <div className="space-y-2 text-xs">
        <p className="text-slate-600">
          Wyszukaj usługi przeglądów (np. „przegląd gaśnicy”) albo urządzenia (towary), zaznacz kilka naraz i wybierz
          wspólny interwał. Liczby obok pokazują, ilu klientów kupiło pozycję w ostatnich 24 miesiącach — pomagają ocenić,
          czy warto ją śledzić. Usługę odnawiającą towar ustawisz potem na liście.
        </p>
        <div className="flex flex-wrap items-end gap-2">
          <label className="flex min-w-[18rem] flex-1 flex-col gap-0.5 text-[11px] text-slate-500">
            Szukaj w ERP XL
            <input
              type="search"
              autoFocus
              className={INPUT}
              placeholder="kod albo nazwa — każde słowo zawęża listę"
              value={search.q}
              onChange={(e) => search.setQ(e.target.value)}
            />
          </label>
          <label className="flex flex-col gap-0.5 text-[11px] text-slate-500">
            Rodzaj
            <select className={INPUT} value={type} onChange={(e) => setType(e.target.value as InspectionCatalogType)}>
              {CATALOG_TYPES.map((t) => (
                <option key={t.value} value={t.value}>
                  {t.label}
                </option>
              ))}
            </select>
          </label>
          <span className="pb-1 text-slate-500">{search.loading ? 'szukam…' : ''}</span>
        </div>
        <ErrorBar message={search.err || err} />
        <table className="w-full text-left">
          <thead>
            <tr className="border-b bg-slate-50 text-slate-700">
              <th className="w-8 p-2" />
              <th className="p-2">Pozycja z ERP XL</th>
              <th className="p-2">Rodzaj</th>
              <th className="whitespace-nowrap p-2 text-right">Ostatnie 24 miesiące</th>
            </tr>
          </thead>
          <tbody>
            {search.items.map((item) => {
              const already = item.position_id !== null || defined.has(item.xl_gid)
              return (
                <tr
                  key={item.xl_gid}
                  className={`border-b align-top ${already ? 'text-slate-400' : 'cursor-pointer hover:bg-sky-50'}`}
                  onClick={() => {
                    if (!already) toggle(item)
                  }}
                >
                  <td className="p-2">
                    <input
                      type="checkbox"
                      checked={picked.has(item.xl_gid)}
                      disabled={already}
                      aria-label={`Zaznacz ${item.code}`}
                      onClick={(e) => e.stopPropagation()}
                      onChange={() => toggle(item)}
                    />
                  </td>
                  <td className="p-2">
                    <div className={already ? '' : 'text-slate-900'}>{item.name}</div>
                    <span className="font-mono text-[11px]">{item.code}</span>
                    {already && <span className="ml-1.5 text-[11px]">— już jest pozycją przeglądu</span>}
                  </td>
                  <td className="p-2">{xlTypeLabel(item.xl_type)}</td>
                  <UsageCell item={item} />
                </tr>
              )
            })}
            {search.items.length === 0 && (
              <tr>
                <td colSpan={4} className="p-6 text-center text-slate-500">
                  {search.q.trim().length < 2
                    ? 'Wpisz co najmniej 2 znaki.'
                    : search.loading
                      ? 'Szukam…'
                      : 'Nic nie znaleziono — spróbuj innego słowa albo rodzaju.'}
                </td>
              </tr>
            )}
          </tbody>
        </table>
        {picked.size > 0 && (
          <div className="rounded border border-slate-200 bg-slate-50 px-2 py-1.5">
            <p className="font-medium text-slate-700">Zaznaczone (zostają przy zmianie wyszukiwania):</p>
            <ul className="mt-1 flex flex-wrap gap-1">
              {[...picked.values()].map((i) => (
                <li key={i.xl_gid}>
                  <button
                    type="button"
                    className={BTN_SM}
                    title="Kliknij, żeby odznaczyć"
                    onClick={() => toggle(i)}
                  >
                    {i.code} ×
                  </button>
                </li>
              ))}
            </ul>
          </div>
        )}
      </div>
    </Modal>
  )
}

/** Wybór usługi odnawiającej towar — tylko usługi z ERP XL. */
function ServicePickerModal({
  position,
  onClose,
  onPick,
}: {
  position: InspectionPositionRow
  onClose: () => void
  onPick: (svc: InspectionCatalogItem) => void
}) {
  const search = useCatalogSearch('service')
  return (
    <Modal title={`Usługa odnawiająca: ${position.name}`} wide onClose={onClose}>
      <div className="space-y-2 text-xs">
        <p className="text-slate-600">
          Wybierz usługę przeglądu tego urządzenia. Gdy klient kupi tę usługę po zakupie urządzenia, termin liczony od
          zakupu się zamyka (przegląd został zrobiony u nas).
        </p>
        <input
          type="search"
          autoFocus
          className={`${INPUT} w-full`}
          placeholder="kod albo nazwa usługi, np. przegląd gaśnicy GP-6"
          value={search.q}
          onChange={(e) => search.setQ(e.target.value)}
        />
        <ErrorBar message={search.err} />
        <table className="w-full text-left">
          <tbody>
            {search.items.map((item) => (
              <tr key={item.xl_gid} className="border-b align-top">
                <td className="p-2">
                  <div className="text-slate-900">{item.name}</div>
                  <span className="font-mono text-[11px] text-slate-500">{item.code}</span>
                </td>
                <UsageCell item={item} />
                <td className="p-2 text-right">
                  <button type="button" className={BTN_SM} onClick={() => onPick(item)}>
                    Wybierz
                  </button>
                </td>
              </tr>
            ))}
            {search.items.length === 0 && (
              <tr>
                <td className="p-4 text-center text-slate-500">
                  {search.q.trim().length < 2 ? 'Wpisz co najmniej 2 znaki.' : search.loading ? 'Szukam…' : 'Nic nie znaleziono.'}
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </Modal>
  )
}

/* ---------- Podpowiedzi z wzorca ---------- */

const candidateKey = (patternId: number, xlGid: number) => `${patternId}:${xlGid}`

function SuggestionsSection({ onAccepted }: { onAccepted: () => void }) {
  const [groups, setGroups] = useState<InspectionSuggestionGroup[] | null>(null)
  const [err, setErr] = useState('')
  const [busy, setBusy] = useState(false)
  const [checked, setChecked] = useState<Set<string>>(() => new Set())
  /** Interwał zmieniony przez człowieka przed zatwierdzeniem; brak wpisu = interwał wzorca. */
  const [intervals, setIntervals] = useState<Map<string, number>>(() => new Map())

  const load = useCallback(async () => {
    try {
      const res = await listInspectionSuggestions()
      setGroups(res.data)
      setErr('')
    } catch (ex) {
      setErr(errorText(ex, 'Nie udało się wczytać podpowiedzi.'))
    }
  }, [])

  useEffect(() => {
    void load()
  }, [load])

  function intervalOf(g: InspectionSuggestionGroup, c: InspectionSuggestionCandidate): number {
    return intervals.get(candidateKey(g.pattern.id, c.xl_gid)) ?? c.interval_months
  }

  async function accept(g: InspectionSuggestionGroup, list: InspectionSuggestionCandidate[]) {
    if (list.length === 0) return
    setBusy(true)
    setErr('')
    try {
      await acceptInspectionSuggestions(
        g.pattern.id,
        list.map((c) => ({
          xl_gid: c.xl_gid,
          interval_months: intervalOf(g, c),
          renewed_by_xl_gid: c.renewed_by?.xl_gid ?? null,
        })),
      )
      clearChecked(g, list)
      await load()
      onAccepted()
    } catch (ex) {
      setErr(errorText(ex, 'Nie udało się zatwierdzić podpowiedzi.'))
    } finally {
      setBusy(false)
    }
  }

  async function reject(g: InspectionSuggestionGroup, list: InspectionSuggestionCandidate[]) {
    if (list.length === 0) return
    setBusy(true)
    setErr('')
    try {
      await rejectInspectionSuggestions(
        g.pattern.id,
        list.map((c) => c.xl_gid),
      )
      clearChecked(g, list)
      await load()
    } catch (ex) {
      setErr(errorText(ex, 'Nie udało się odrzucić podpowiedzi.'))
    } finally {
      setBusy(false)
    }
  }

  function clearChecked(g: InspectionSuggestionGroup, list: InspectionSuggestionCandidate[]) {
    setChecked((cur) => {
      const next = new Set(cur)
      for (const c of list) next.delete(candidateKey(g.pattern.id, c.xl_gid))
      return next
    })
  }

  function toggle(key: string) {
    setChecked((cur) => {
      const next = new Set(cur)
      if (next.has(key)) next.delete(key)
      else next.add(key)
      return next
    })
  }

  return (
    <div className="mt-4 rounded-xl bg-white p-4 shadow-sm">
      <h2 className="text-sm font-semibold text-slate-900">Podpowiedzi</h2>
      <p className="mt-0.5 max-w-3xl text-xs text-slate-600">
        Pozycje z ERP XL o nazwie podobnej do już zdefiniowanej (ten sam rodzaj, te same słowa, inny model, np. GP-4 obok
        GP-6). <b className="font-medium text-amber-800">To propozycja na podstawie podobnej nazwy — sprawdź przed
        zatwierdzeniem.</b> Interwał i usługa odnawiająca są skopiowane ze wzoru; odrzucona podpowiedź nie wróci.
      </p>
      <ErrorBar message={err} onClose={() => setErr('')} />
      {groups === null ? (
        !err && <p className="mt-2 text-xs text-slate-500">Ładowanie…</p>
      ) : groups.length === 0 ? (
        <p className="mt-2 text-xs text-slate-500">Brak podpowiedzi — wszystkie podobne pozycje są już zdefiniowane albo odrzucone.</p>
      ) : (
        <div className="mt-3 space-y-4">
          {groups.map((g) => {
            const keys = g.candidates.map((c) => candidateKey(g.pattern.id, c.xl_gid))
            const sel = g.candidates.filter((c) => checked.has(candidateKey(g.pattern.id, c.xl_gid)))
            const allChecked = keys.length > 0 && keys.every((k) => checked.has(k))
            return (
              <section key={g.pattern.id} className="rounded border border-slate-200">
                <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 bg-slate-50 px-3 py-2 text-xs">
                  <span>
                    Wzór: <b className="font-medium text-slate-900">{g.pattern.name}</b>{' '}
                    <span className="text-slate-500">({intervalLabel(g.pattern.interval_months)})</span> ·{' '}
                    {g.candidates.length} {plural(g.candidates.length, 'propozycja', 'propozycje', 'propozycji')}
                  </span>
                  <span className="inline-flex gap-1.5">
                    <button type="button" className={BTN_SM} disabled={busy || sel.length === 0} onClick={() => void accept(g, sel)}>
                      Zatwierdź zaznaczone ({sel.length})
                    </button>
                    <button type="button" className={BTN_SM} disabled={busy || sel.length === 0} onClick={() => void reject(g, sel)}>
                      Odrzuć zaznaczone ({sel.length})
                    </button>
                  </span>
                </div>
                <table className="w-full text-left text-xs">
                  <thead>
                    <tr className="border-b text-slate-700">
                      <th className="w-8 p-2">
                        <input
                          type="checkbox"
                          checked={allChecked}
                          aria-label={`Zaznacz wszystkie propozycje wzoru ${g.pattern.name}`}
                          onChange={() =>
                            setChecked((cur) => {
                              const next = new Set(cur)
                              for (const k of keys) {
                                if (allChecked) next.delete(k)
                                else next.add(k)
                              }
                              return next
                            })
                          }
                        />
                      </th>
                      <th className="p-2">Propozycja z ERP XL</th>
                      <th className="p-2">Interwał</th>
                      <th className="p-2">Usługa odnawiająca</th>
                      <th className="whitespace-nowrap p-2 text-right">Ostatnie 24 miesiące</th>
                      <th className="p-2" />
                    </tr>
                  </thead>
                  <tbody>
                    {g.candidates.map((c) => {
                      const key = candidateKey(g.pattern.id, c.xl_gid)
                      return (
                        <tr key={key} className="border-b align-top last:border-b-0">
                          <td className="p-2">
                            <input
                              type="checkbox"
                              checked={checked.has(key)}
                              aria-label={`Zaznacz ${c.code}`}
                              onChange={() => toggle(key)}
                            />
                          </td>
                          <td className="min-w-[16rem] p-2">
                            <div className="text-slate-900">{c.name}</div>
                            <div className="text-[11px] text-slate-500">
                              <span className="font-mono">{c.code}</span> · {xlTypeLabel(c.xl_type)} · model {c.token}
                            </div>
                          </td>
                          <td className="whitespace-nowrap p-2">
                            <IntervalSelect
                              value={intervalOf(g, c)}
                              label={`Interwał: ${c.name}`}
                              disabled={busy}
                              onChange={(m) =>
                                setIntervals((cur) => {
                                  const next = new Map(cur)
                                  next.set(key, m)
                                  return next
                                })
                              }
                            />
                          </td>
                          <td className="p-2">
                            {c.xl_type === XL_TYPE_GOODS ? (
                              c.renewed_by ? (
                                <span title="Propozycja po numerze modelu — sprawdź">
                                  {c.renewed_by.name}{' '}
                                  <span className="font-mono text-[11px] text-slate-500">{c.renewed_by.code}</span>
                                </span>
                              ) : (
                                <span className="text-slate-400">brak propozycji</span>
                              )
                            ) : (
                              <span className="text-slate-400">nie dotyczy usługi</span>
                            )}
                          </td>
                          <UsageCell item={c} />
                          <td className="whitespace-nowrap p-2 text-right">
                            <span className="inline-flex gap-1">
                              <button type="button" className={BTN_SM} disabled={busy} onClick={() => void accept(g, [c])}>
                                Zatwierdź
                              </button>
                              <button type="button" className={BTN_SM} disabled={busy} onClick={() => void reject(g, [c])}>
                                Odrzuć
                              </button>
                            </span>
                          </td>
                        </tr>
                      )
                    })}
                  </tbody>
                </table>
              </section>
            )
          })}
        </div>
      )}
    </div>
  )
}
