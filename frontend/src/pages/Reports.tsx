import { useEffect, useState } from 'react'
import { api, downloadFile } from '../lib/api'

type RowStatus = {
  status: string
  count: number
  offer_value_net: number
  avg_margin: number | null
}

type RowOwner = {
  owner_id: number
  owner_name: string
  count: number
  offer_value_net: number
  avg_margin: number | null
}

export function Reports() {
  const [byStatus, setByStatus] = useState<RowStatus[]>([])
  const [byOwner, setByOwner] = useState<RowOwner[]>([])
  const [err, setErr] = useState('')

  useEffect(() => {
    void api<{ by_status: RowStatus[]; by_owner: RowOwner[] }>('/reports/summary')
      .then((r) => {
        setByStatus(r.by_status)
        setByOwner(r.by_owner)
      })
      .catch((e) => setErr(e instanceof Error ? e.message : 'Błąd raportu'))
  }, [])

  return (
    <div>
      <div className="mb-4 flex items-center justify-between gap-3">
        <h1 className="text-xl font-semibold">Raporty</h1>
        <button
          type="button"
          className="rounded bg-emerald-600 px-3 py-2 text-xs text-white"
          onClick={() => void downloadFile('/reports/csv', 'raport-przetargi.csv')}
        >
          Eksport CSV
        </button>
      </div>
      {err && <p className="mb-2 text-xs text-red-600">{err}</p>}

      <div className="mb-4 rounded-xl bg-white p-4 shadow-sm">
        <h2 className="mb-2 text-sm font-semibold">Pipeline wg statusu</h2>
        <table className="w-full text-left text-xs">
          <thead>
            <tr className="border-b bg-slate-50">
              <th className="p-2">Status</th>
              <th className="p-2">Liczba</th>
              <th className="p-2">Wartość</th>
              <th className="p-2">Śr. marża</th>
            </tr>
          </thead>
          <tbody>
            {byStatus.map((r) => (
              <tr key={r.status} className="border-b">
                <td className="p-2">{r.status}</td>
                <td className="p-2">{r.count}</td>
                <td className="p-2">{r.offer_value_net.toLocaleString('pl-PL')} zł</td>
                <td className="p-2">{r.avg_margin ?? '—'}%</td>
              </tr>
            ))}
            {byStatus.length === 0 && (
              <tr>
                <td colSpan={4} className="p-3 text-slate-400">
                  Brak danych.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>

      <div className="rounded-xl bg-white p-4 shadow-sm">
        <h2 className="mb-2 text-sm font-semibold">Wg opiekuna</h2>
        <table className="w-full text-left text-xs">
          <thead>
            <tr className="border-b bg-slate-50">
              <th className="p-2">Opiekun</th>
              <th className="p-2">Liczba</th>
              <th className="p-2">Wartość</th>
              <th className="p-2">Śr. marża</th>
            </tr>
          </thead>
          <tbody>
            {byOwner.map((r) => (
              <tr key={r.owner_id} className="border-b">
                <td className="p-2">{r.owner_name}</td>
                <td className="p-2">{r.count}</td>
                <td className="p-2">{r.offer_value_net.toLocaleString('pl-PL')} zł</td>
                <td className="p-2">{r.avg_margin ?? '—'}%</td>
              </tr>
            ))}
            {byOwner.length === 0 && (
              <tr>
                <td colSpan={4} className="p-3 text-slate-400">
                  Brak danych.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  )
}
