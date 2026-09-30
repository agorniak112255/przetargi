import { useEffect, useRef, useState } from 'react'
import {
  campaignXlCustomers,
  type CampaignXlMode,
  type PageMeta,
  type XlCustomerRow,
  type XlCustomerSort,
} from '../lib/campaigns'
import { errorText, fmtDate, fmtInt } from '../lib/campaignFormat'
import { plural } from '../lib/plural'
import { BTN, BTN_PRIMARY, INPUT, Modal, Pager } from './CampaignsUi'

export type XlChoice = { mode: CampaignXlMode; months: 12 | 24; only_mine: boolean; customer_ids: number[] | null }

const MODE_LABEL: Record<CampaignXlMode, string> = {
  items: 'kupowali te towary',
  group: 'kupowali z tej grupy',
  mine: 'moi klienci',
}

const DEFAULT_DIR: Record<XlCustomerSort, 'asc' | 'desc'> = {
  documents: 'desc',
  last_sale: 'desc',
  acronym: 'asc',
  name: 'asc',
  city: 'asc',
}

/**
 * „Pokaż / wybierz”: klienci XL z jednej kategorii z adresami e-mail — szukanie, sortowanie, zaznaczanie. Zapis:
 * wszyscy zaznaczeni = cała kategoria (customer_ids: null, dojdą też nowi klienci z nocnego odczytu XL), inaczej lista.
 */
export function XlCustomersModal({
  campaignId,
  initial,
  editable,
  onClose,
  onSave,
}: {
  campaignId: number
  initial: Omit<XlChoice, 'customer_ids'>
  editable: boolean
  onClose: () => void
  onSave: (choice: XlChoice) => Promise<boolean>
}) {
  const mode = initial.mode
  const [months, setMonths] = useState<12 | 24>(initial.months)
  const [onlyMine, setOnlyMine] = useState(mode !== 'mine' && initial.only_mine)
  const [search, setSearch] = useState('')
  const [debounced, setDebounced] = useState('')
  const [sort, setSort] = useState<XlCustomerSort>('documents')
  const [dir, setDir] = useState<'asc' | 'desc'>('desc')
  const [page, setPage] = useState(1)
  const [rows, setRows] = useState<XlCustomerRow[]>([])
  const [meta, setMeta] = useState<PageMeta | null>(null)
  const [allIds, setAllIds] = useState<number[] | null>(null)
  const [selected, setSelected] = useState<Set<number>>(new Set())
  const [warnings, setWarnings] = useState<string[]>([])
  const [loading, setLoading] = useState(false)
  const [err, setErr] = useState('')
  const [saving, setSaving] = useState(false)
  const seq = useRef(0)
  // kategoria (okres, „tylko moi”), dla której ustawiono zaznaczenie — po jej zmianie zaznaczenie liczy się od nowa
  const selectionFor = useRef('')

  useEffect(() => {
    const t = window.setTimeout(() => setDebounced(search.trim()), 350)
    return () => window.clearTimeout(t)
  }, [search])

  useEffect(() => {
    const my = ++seq.current
    setLoading(true)
    campaignXlCustomers(campaignId, { mode, months, only_mine: onlyMine, search: debounced, sort, dir, page, per_page: 50 })
      .then((res) => {
        if (my !== seq.current) return
        setRows(res.data)
        setMeta(res.meta)
        setWarnings(res.warnings)
        setErr('')
        const key = `${months}|${onlyMine}`
        if (selectionFor.current !== key) {
          // pierwsze wczytanie tej kategorii: zapisany wybór albo wszyscy
          selectionFor.current = key
          setAllIds(res.ids)
          setSelected(new Set(res.selected_ids ?? res.ids))
        }
      })
      .catch((ex: unknown) => {
        if (my === seq.current) setErr(errorText(ex, 'Nie udało się wczytać klientów z ERP XL.'))
      })
      .finally(() => {
        if (my === seq.current) setLoading(false)
      })
  }, [campaignId, mode, months, onlyMine, debounced, sort, dir, page])

  function sortBy(key: XlCustomerSort) {
    if (sort === key) setDir(dir === 'asc' ? 'desc' : 'asc')
    else {
      setSort(key)
      setDir(DEFAULT_DIR[key])
    }
    setPage(1)
  }

  function toggle(id: number, on: boolean) {
    setSelected((prev) => {
      const next = new Set(prev)
      if (on) next.add(id)
      else next.delete(id)
      return next
    })
  }

  const total = allIds?.length ?? 0
  const pageAllOn = rows.length > 0 && rows.every((r) => selected.has(r.id))

  async function save() {
    if (allIds === null) return
    setSaving(true)
    const all = allIds.every((id) => selected.has(id))
    const ok = await onSave({
      mode,
      months,
      only_mine: onlyMine,
      customer_ids: all ? null : allIds.filter((id) => selected.has(id)),
    })
    setSaving(false)
    if (ok) onClose()
  }

  const th = (key: XlCustomerSort, label: string, right = false) => (
    <th className={`px-2 py-2 ${right ? 'text-right' : ''}`}>
      <button type="button" onClick={() => sortBy(key)} className="inline-flex items-center gap-1 font-semibold hover:text-slate-900">
        {label}
        <span className="text-[10px] text-slate-400">{sort === key ? (dir === 'asc' ? '▲' : '▼') : '◇'}</span>
      </button>
    </th>
  )

  return (
    <Modal
      title={`Klienci z ERP XL: ${MODE_LABEL[mode]}`}
      wide="full"
      busy={saving}
      onClose={onClose}
      footer={
        <>
          <span className="mr-auto text-xs text-slate-600">
            Wybrano <b className="tabular-nums">{fmtInt(selected.size)}</b> z{' '}
            <b className="tabular-nums">{fmtInt(total)}</b> {plural(total, 'klienta', 'klientów', 'klientów')}
            {total > 0 && selected.size === total ? ' — cała kategoria (dojdą też nowi klienci z nocnego odczytu XL)' : ''}
          </span>
          <button type="button" className={BTN} onClick={onClose} disabled={saving}>
            {editable ? 'Anuluj' : 'Zamknij'}
          </button>
          {editable && (
            <button type="button" className={BTN_PRIMARY} disabled={saving || allIds === null} onClick={() => void save()}>
              {saving ? 'Zapisuję…' : `Zapisz wybór (${fmtInt(selected.size)})`}
            </button>
          )}
        </>
      }
    >
      <div className="flex h-full min-h-0 flex-col gap-3 text-xs">
        <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
          <input
            type="search"
            value={search}
            onChange={(e) => {
              setSearch(e.target.value)
              setPage(1)
            }}
            placeholder="Szukaj: akronim, nazwa, miasto, NIP, e-mail…"
            className={`${INPUT} w-72`}
            aria-label="Szukaj klienta"
          />
          <span className="inline-flex items-center gap-2">
            <span className="text-slate-600">Kupowali w ostatnich</span>
            <span className="inline-flex overflow-hidden rounded border border-slate-300" role="group">
              {([12, 24] as const).map((m, i) => (
                <button
                  key={m}
                  type="button"
                  aria-pressed={months === m}
                  onClick={() => {
                    setMonths(m)
                    setPage(1)
                  }}
                  className={`px-2.5 py-1 tabular-nums ${i > 0 ? 'border-l border-slate-300' : ''} ${
                    months === m ? 'bg-blue-600 font-semibold text-white' : 'bg-white text-slate-700 hover:bg-slate-50'
                  }`}
                >
                  {m} mies.
                </button>
              ))}
            </span>
          </span>
          {mode !== 'mine' && (
            <label className="inline-flex items-center gap-1.5 text-slate-700">
              <input
                type="checkbox"
                checked={onlyMine}
                onChange={(e) => {
                  setOnlyMine(e.target.checked)
                  setPage(1)
                }}
              />
              tylko moi klienci
            </label>
          )}
          {editable && (
            <span className="ml-auto inline-flex gap-2">
              <button type="button" className={BTN} disabled={allIds === null} onClick={() => setSelected(new Set(allIds ?? []))}>
                Zaznacz wszystkich ({fmtInt(total)})
              </button>
              <button type="button" className={BTN} onClick={() => setSelected(new Set())}>
                Odznacz wszystkich
              </button>
            </span>
          )}
        </div>
        {(months !== initial.months || onlyMine !== (mode !== 'mine' && initial.only_mine)) && (
          <p className="rounded border border-sky-200 bg-sky-50 px-2 py-1.5 text-[11px] text-sky-900">
            Zmieniono okres albo „tylko moi” — zapis wybierze też tę kategorię w kampanii.
          </p>
        )}
        {warnings.map((w) => (
          <p key={w} className="rounded border border-amber-200 bg-amber-50 px-2 py-1.5 text-[11px] text-amber-900">
            {w}
          </p>
        ))}
        {err && <p className="rounded bg-red-50 px-2 py-1.5 text-red-700">{err}</p>}

        <div className={`min-h-0 flex-1 overflow-auto rounded border border-slate-200 ${loading ? 'opacity-60' : ''}`}>
          <table className="w-full text-left">
            <thead className="sticky top-0 z-10 bg-slate-50 text-[11px] text-slate-600">
              <tr>
                <th className="w-8 px-2 py-2">
                  {editable && (
                    <input
                      type="checkbox"
                      aria-label="Zaznacz klientów na tej stronie"
                      checked={pageAllOn}
                      onChange={(e) => {
                        const on = e.target.checked
                        setSelected((prev) => {
                          const next = new Set(prev)
                          for (const r of rows) {
                            if (on) next.add(r.id)
                            else next.delete(r.id)
                          }
                          return next
                        })
                      }}
                    />
                  )}
                </th>
                {th('acronym', 'Akronim XL')}
                {th('name', 'Nazwa')}
                {th('city', 'Miasto')}
                <th className="px-2 py-2 font-semibold">E-mail</th>
                {th('last_sale', 'Ostatni zakup')}
                {th('documents', 'Faktury i paragony (24 mies.)', true)}
                {mode === 'items' && <th className="px-2 py-2 text-right font-semibold">Kupione pozycje kampanii</th>}
                <th className="px-2 py-2 font-semibold">Operator XL</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((r) => {
                const on = selected.has(r.id)
                return (
                  <tr key={r.id} className={`border-t border-slate-100 ${on ? 'bg-sky-50/60' : ''}`}>
                    <td className="px-2 py-1.5 align-top">
                      <input
                        type="checkbox"
                        aria-label={`Wybierz ${r.acronym}`}
                        checked={on}
                        disabled={!editable}
                        onChange={(e) => toggle(r.id, e.target.checked)}
                      />
                    </td>
                    <td className="px-2 py-1.5 align-top font-mono text-[11px] text-slate-800">{r.acronym}</td>
                    <td className="px-2 py-1.5 align-top text-slate-900">{r.name ?? '—'}</td>
                    <td className="px-2 py-1.5 align-top text-slate-600">{r.city ?? '—'}</td>
                    <td className="px-2 py-1.5 align-top">
                      {r.emails.map((e) => (
                        <span
                          key={e.email}
                          className={`block font-mono text-[11px] ${e.skipped ? 'text-slate-400 line-through' : 'text-slate-800'}`}
                          title={
                            e.skipped === 'suppressed'
                              ? 'Wypisany z mailingu — nie dostanie kampanii'
                              : e.skipped === 'generic'
                                ? 'Adres faktury@ / księgowość@ — pomijany'
                                : undefined
                          }
                        >
                          {e.email}
                          {e.skipped && (
                            <span className="ml-1 font-sans text-[10px] no-underline">
                              ({e.skipped === 'suppressed' ? 'wypisany' : 'faktury — pomijany'})
                            </span>
                          )}
                        </span>
                      ))}
                    </td>
                    <td className="px-2 py-1.5 align-top tabular-nums text-slate-700">{fmtDate(r.last_sale_at)}</td>
                    <td className="px-2 py-1.5 text-right align-top tabular-nums text-slate-700">{fmtInt(r.documents_24m)}</td>
                    {mode === 'items' && (
                      <td className="px-2 py-1.5 text-right align-top tabular-nums text-slate-700">{fmtInt(r.matched_items)}</td>
                    )}
                    <td className="px-2 py-1.5 align-top font-mono text-[11px] text-slate-600">{r.main_operator ?? '—'}</td>
                  </tr>
                )
              })}
              {!loading && rows.length === 0 && (
                <tr>
                  <td colSpan={9} className="px-2 py-6 text-center text-slate-500">
                    {debounced ? 'Nikt nie pasuje do szukanej frazy.' : 'W tej kategorii nie ma klientów z adresem e-mail.'}
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
        <div className="flex flex-wrap items-center justify-between gap-2">
          <span className="text-[11px] text-slate-500">
            {meta ? `${fmtInt(meta.total)} ${plural(meta.total, 'klient', 'klientów', 'klientów')} na liście` : ''}
            {loading ? ' · ładowanie…' : ''}
          </span>
          <Pager meta={meta} disabled={loading} onPage={setPage} />
        </div>
      </div>
    </Modal>
  )
}
