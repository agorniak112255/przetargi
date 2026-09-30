import { useEffect, useState } from 'react'
import { errorText, fmtDate } from '../lib/campaignFormat'
import { campaignSuggestions, formatPln, type CampaignSuggestion } from '../lib/campaigns'
import { BTN, BTN_PRIMARY, ErrorBar, Modal } from './CampaignsUi'

/**
 * „Zaproponuj pozycje”: ranking zalegającego towaru (serwer, bez AI) z powodami przy każdej pozycji; zaznaczasz
 * i dodajesz do projektu. Zaznaczyć można tyle, ile zostało wolnych miejsc w kampanii.
 */
export function CampaignSuggestionsModal({
  campaignId,
  onClose,
  onAdd,
}: {
  campaignId: number
  onClose: () => void
  /** Dodanie zaznaczonych; true = dodane (okno się zamyka). */
  onAdd: (erpItemIds: number[]) => Promise<boolean>
}) {
  const [mine, setMine] = useState(false)
  const [rows, setRows] = useState<CampaignSuggestion[] | null>(null)
  const [free, setFree] = useState(0)
  const [mineAvailable, setMineAvailable] = useState(true)
  const [minMonths, setMinMonths] = useState(6)
  const [selected, setSelected] = useState<Set<number>>(new Set())
  const [err, setErr] = useState('')
  const [adding, setAdding] = useState(false)

  useEffect(() => {
    let alive = true
    setRows(null)
    setErr('')
    campaignSuggestions(campaignId, mine)
      .then((res) => {
        if (!alive) return
        setRows(res.data)
        setFree(res.free)
        setMineAvailable(res.mine_available)
        setMinMonths(res.min_months)
        setSelected(new Set())
      })
      .catch((ex: unknown) => {
        if (alive) setErr(errorText(ex, 'Nie udało się przygotować propozycji.'))
      })
    return () => {
      alive = false
    }
  }, [campaignId, mine])

  function toggle(id: number) {
    setSelected((prev) => {
      const next = new Set(prev)
      if (next.has(id)) next.delete(id)
      else if (next.size < free) next.add(id)
      return next
    })
  }

  async function add() {
    setAdding(true)
    const ok = await onAdd([...selected])
    setAdding(false)
    if (ok) onClose()
  }

  return (
    <Modal
      title="Zaproponuj pozycje do kampanii"
      wide
      busy={adding}
      onClose={onClose}
      footer={
        <>
          <span className="mr-auto text-xs text-slate-500">
            Zaznaczono {selected.size} z {free} wolnych miejsc
          </span>
          <button type="button" className={BTN} disabled={adding} onClick={onClose}>
            Anuluj
          </button>
          <button type="button" className={BTN_PRIMARY} disabled={adding || selected.size === 0} onClick={() => void add()}>
            {adding ? 'Dodaję…' : `Dodaj zaznaczone (${selected.size})`}
          </button>
        </>
      }
    >
      <div className="space-y-3 text-xs">
        <p className="text-slate-600">
          Towar na stanie handlowym, który nie sprzedaje się od co najmniej {minMonths} miesięcy (jak w Zapasach), bez
          pozycji z innych aktywnych kampanii. Punkty: wartość zapasu, czas zalegania, ilu klientów z e-mailem go kupowało
          i czy karta ma zdjęcie oraz opis — przy każdej pozycji podane powody.
        </p>
        <label className={`inline-flex items-center gap-1.5 ${mineAvailable ? 'text-slate-700' : 'text-slate-400'}`}>
          <input type="checkbox" checked={mine} disabled={!mineAvailable} onChange={(e) => setMine(e.target.checked)} />
          tylko towar kupowany przez moich klientów (opiekun w ERP XL)
          {!mineAvailable && <span>— konto nie jest przypisane do operatora XL</span>}
        </label>
        <ErrorBar message={err} onClose={() => setErr('')} />
        {free === 0 && rows !== null && (
          <p className="rounded bg-amber-50 px-3 py-2 text-amber-900">Kampania ma już komplet pozycji — usuń którąś, żeby dodać nową.</p>
        )}
        {rows === null && !err ? (
          <p className="text-slate-500">Liczę propozycje…</p>
        ) : rows !== null && rows.length === 0 ? (
          <p className="text-slate-500">Brak zalegającego towaru do zaproponowania.</p>
        ) : (
          rows !== null && (
            <div className="max-h-[60vh] overflow-y-auto">
              <table className="w-full text-left">
                <thead className="sticky top-0 bg-white">
                  <tr className="border-b bg-slate-50">
                    <th className="w-8 p-2" />
                    <th className="p-2">Towar</th>
                    <th className="p-2 text-right">Zapas</th>
                    <th className="p-2 text-right">Punkty</th>
                    <th className="p-2">Dlaczego</th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map((r) => {
                    const checked = selected.has(r.erp_item_id)
                    return (
                      <tr
                        key={r.erp_item_id}
                        className={`cursor-pointer border-b align-top even:bg-slate-50 ${checked ? 'bg-blue-50' : ''}`}
                        onClick={() => toggle(r.erp_item_id)}
                      >
                        <td className="p-2">
                          <input
                            type="checkbox"
                            aria-label={`Zaznacz: ${r.name}`}
                            checked={checked}
                            disabled={!checked && selected.size >= free}
                            onChange={() => toggle(r.erp_item_id)}
                            onClick={(e) => e.stopPropagation()}
                          />
                        </td>
                        <td className="min-w-[14rem] p-2">
                          <div className="flex gap-2">
                            {r.card?.thumb_url ? (
                              <img src={r.card.thumb_url} alt="" className="h-10 w-10 shrink-0 rounded object-contain" />
                            ) : (
                              <div className="h-10 w-10 shrink-0 rounded bg-slate-100" />
                            )}
                            <div className="min-w-0">
                              <b className="block font-medium text-slate-900">{r.name}</b>
                              <span className="font-mono text-[11px] text-slate-500">{r.code}</span>
                              {r.card && <span className="ml-1.5 text-[11px] text-slate-500">karta {r.card.sku}</span>}
                            </div>
                          </div>
                        </td>
                        <td className="whitespace-nowrap p-2 text-right tabular-nums">
                          {r.stock_value != null ? formatPln(r.stock_value) : '—'}
                          <div className="text-[10px] text-slate-500">
                            {r.stock.toLocaleString('pl-PL')} {r.unit}
                            {r.last_sale_at ? ` · ost. sprzedaż ${fmtDate(r.last_sale_at)}` : ''}
                          </div>
                        </td>
                        <td className="p-2 text-right text-sm font-semibold tabular-nums text-blue-700">{r.score}</td>
                        <td className="p-2 text-[11px] text-slate-600">
                          <ul className="list-disc pl-4">
                            {r.reasons.map((reason) => (
                              <li key={reason}>{reason}</li>
                            ))}
                          </ul>
                        </td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>
          )
        )}
      </div>
    </Modal>
  )
}
