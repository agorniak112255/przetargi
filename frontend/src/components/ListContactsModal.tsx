import { useEffect, useRef, useState } from 'react'
import { campaignListContacts, type ListContactRow, type PageMeta } from '../lib/campaigns'
import { errorText, fmtDate, fmtInt } from '../lib/campaignFormat'
import { plural } from '../lib/plural'
import { BTN, BTN_PRIMARY, Chip, INPUT, Modal, Pager } from './CampaignsUi'

/** Wybór z grupy: include false = nic nie zaznaczono (grupa wypada z kampanii), excluded = odznaczone adresy. */
export type ListChoice = { include: boolean; excluded: number[] }

const BASIS_LABEL: Record<ListContactRow['basis'], string> = {
  customer: 'stały klient',
  consent: 'zgoda',
}

/**
 * „Pokaż / wybierz” przy grupie odbiorców (jak okno klientów ERP XL): adresy grupy z szukaniem i zaznaczaniem.
 * Zapis: odznaczone = wszystkie adresy grupy − zaznaczone (wszyscy zaznaczeni = cała grupa, dojdą też adresy dopisane
 * później); nic nie zaznaczono = grupa wypada z kampanii.
 */
export function ListContactsModal({
  campaignId,
  listId,
  listName,
  initialExcluded,
  editable,
  onClose,
  onSave,
}: {
  campaignId: number
  listId: number
  listName: string
  /** Odznaczenia z bieżącego wyboru w kroku Odbiorcy (null = brak wpisu — bierzemy zapisane z serwera). */
  initialExcluded: number[] | null
  editable: boolean
  onClose: () => void
  onSave: (choice: ListChoice) => Promise<boolean>
}) {
  const [search, setSearch] = useState('')
  const [debounced, setDebounced] = useState('')
  const [page, setPage] = useState(1)
  const [rows, setRows] = useState<ListContactRow[]>([])
  const [meta, setMeta] = useState<PageMeta | null>(null)
  const [name, setName] = useState(listName)
  const [allIds, setAllIds] = useState<number[] | null>(null)
  const [selected, setSelected] = useState<Set<number>>(new Set())
  const [loading, setLoading] = useState(false)
  const [err, setErr] = useState('')
  const [saving, setSaving] = useState(false)
  const seq = useRef(0)
  // zaznaczenie ustawiamy raz, z pierwszej odpowiedzi (kolejne strony i szukanie go nie ruszają)
  const initialized = useRef(false)
  const initialExcludedRef = useRef(initialExcluded)

  useEffect(() => {
    const t = window.setTimeout(() => setDebounced(search.trim()), 350)
    return () => window.clearTimeout(t)
  }, [search])

  useEffect(() => {
    const my = ++seq.current
    setLoading(true)
    campaignListContacts(campaignId, { list_id: listId, search: debounced, page, per_page: 50 })
      .then((res) => {
        if (my !== seq.current) return
        setRows(res.data)
        setMeta(res.meta)
        setName(res.list.name)
        setErr('')
        if (!initialized.current) {
          initialized.current = true
          const excluded = new Set(initialExcludedRef.current ?? res.excluded_ids)
          setAllIds(res.ids)
          setSelected(new Set(res.ids.filter((id) => !excluded.has(id))))
        }
      })
      .catch((ex: unknown) => {
        if (my === seq.current) setErr(errorText(ex, 'Nie udało się wczytać adresów grupy.'))
      })
      .finally(() => {
        if (my === seq.current) setLoading(false)
      })
  }, [campaignId, listId, debounced, page])

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
  const none = total > 0 && selected.size === 0

  async function save() {
    if (allIds === null) return
    setSaving(true)
    const ok = await onSave({ include: !none, excluded: none ? [] : allIds.filter((id) => !selected.has(id)) })
    setSaving(false)
    if (ok) onClose()
  }

  return (
    <Modal
      title={`Grupa odbiorców: ${name}`}
      wide="full"
      busy={saving}
      onClose={onClose}
      footer={
        <>
          <span className="mr-auto text-xs text-slate-600">
            Wybrano <b className="tabular-nums">{fmtInt(selected.size)}</b> z <b className="tabular-nums">{fmtInt(total)}</b>{' '}
            {plural(total, 'adresu', 'adresów', 'adresów')}
            {none ? (
              <span className="text-amber-800"> — nic nie wybrano: zapis odznaczy całą grupę w tej kampanii</span>
            ) : (
              <span className="text-slate-500"> · adresy dopisane później do grupy też dostaną kampanię</span>
            )}
          </span>
          <button type="button" className={BTN} onClick={onClose} disabled={saving}>
            {editable ? 'Anuluj' : 'Zamknij'}
          </button>
          {editable && (
            <button type="button" className={BTN_PRIMARY} disabled={saving || allIds === null} onClick={() => void save()}>
              {saving ? 'Zapisuję…' : none ? 'Odznacz grupę' : `Zapisz wybór (${fmtInt(selected.size)})`}
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
            placeholder="Szukaj: adres, osoba, firma…"
            className={`${INPUT} w-72`}
            aria-label="Szukaj adresu"
          />
          {editable && (
            <span className="ml-auto inline-flex gap-2">
              <button type="button" className={BTN} disabled={allIds === null || saving} onClick={() => setSelected(new Set(allIds ?? []))}>
                Zaznacz wszystkich ({fmtInt(total)})
              </button>
              <button type="button" className={BTN} disabled={allIds === null || saving} onClick={() => setSelected(new Set())}>
                Odznacz wszystkich
              </button>
            </span>
          )}
        </div>
        {err && <p className="rounded bg-red-50 px-2 py-1.5 text-red-700">{err}</p>}

        <div className={`min-h-0 flex-1 overflow-auto rounded border border-slate-200 ${loading ? 'opacity-60' : ''}`}>
          <table className="w-full text-left">
            <thead className="sticky top-0 z-10 bg-slate-50 text-[11px] text-slate-600">
              <tr>
                <th className="w-8 px-2 py-2">
                  {editable && (
                    <input
                      type="checkbox"
                      aria-label="Zaznacz adresy na tej stronie"
                      checked={pageAllOn}
                      disabled={allIds === null || saving}
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
                <th className="px-2 py-2 font-semibold">E-mail</th>
                <th className="px-2 py-2 font-semibold">Osoba</th>
                <th className="px-2 py-2 font-semibold">Firma</th>
                <th className="px-2 py-2 font-semibold">Podstawa wysyłki</th>
                <th className="px-2 py-2 font-semibold">Dodany</th>
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
                        aria-label={`Wybierz ${r.email}`}
                        checked={on}
                        disabled={!editable || allIds === null || saving}
                        onChange={(e) => toggle(r.id, e.target.checked)}
                      />
                    </td>
                    <td className="px-2 py-1.5 align-top">
                      <span
                        className={`font-mono text-[11px] ${r.skipped ? 'text-slate-400 line-through' : 'text-slate-800'}`}
                        title={r.skipped ? 'Wypisany z mailingu — nie dostanie kampanii' : undefined}
                      >
                        {r.email}
                      </span>
                      {r.skipped && <span className="ml-1 text-[10px] text-slate-500">(wypisany)</span>}
                      {!on && r.also_in.length > 0 && !r.skipped && (
                        <span className="mt-0.5 block text-[10px] text-amber-800">
                          dostanie i tak — jest też w {plural(r.also_in.length, 'grupie', 'grupach', 'grupach')}: {r.also_in.join(', ')}
                        </span>
                      )}
                    </td>
                    <td className="px-2 py-1.5 align-top text-slate-900">{r.name ?? '—'}</td>
                    <td className="px-2 py-1.5 align-top text-slate-600">{r.company ?? '—'}</td>
                    <td className="px-2 py-1.5 align-top">
                      <Chip tone="green">{BASIS_LABEL[r.basis] ?? r.basis}</Chip>
                      {r.basis_note && <span className="mt-0.5 block text-[11px] text-slate-500">{r.basis_note}</span>}
                    </td>
                    <td className="whitespace-nowrap px-2 py-1.5 align-top tabular-nums text-slate-600">{fmtDate(r.added_at)}</td>
                  </tr>
                )
              })}
              {!loading && !err && rows.length === 0 && (
                <tr>
                  <td colSpan={6} className="px-2 py-6 text-center text-slate-500">
                    {debounced ? 'Żaden adres nie pasuje do szukanej frazy.' : 'Grupa nie ma jeszcze adresów.'}
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
        <div className="flex flex-wrap items-center justify-between gap-2">
          <span className="text-[11px] text-slate-500">
            {meta ? `${fmtInt(meta.total)} ${plural(meta.total, 'adres', 'adresy', 'adresów')} na liście` : ''}
            {loading ? ' · ładowanie…' : ''}
          </span>
          <Pager meta={meta} disabled={loading} onPage={setPage} />
        </div>
      </div>
    </Modal>
  )
}
