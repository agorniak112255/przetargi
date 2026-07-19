import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api, type Tender } from '../lib/api'

type Dash = {
  my_tenders: number
  offer_value_net: number
  avg_margin_percent: number
  products_count: number
  substitutes_pending: number
  pending_my_approval: number
  deadline_soon: number
  recent_tenders: Tender[]
}

export function Dashboard() {
  const [data, setData] = useState<Dash | null>(null)

  useEffect(() => {
    void api<Dash>('/dashboard').then(setData)
  }, [])

  if (!data) return <p className="text-sm text-slate-500">Ładowanie…</p>

  const kpis = [
    { label: 'Moje przetargi', value: String(data.my_tenders), to: '/tenders?filter=mine' },
    { label: 'Wartość ofert', value: `${data.offer_value_net.toLocaleString('pl-PL')} zł` },
    { label: 'Śr. marża', value: `${data.avg_margin_percent}%` },
    { label: 'Do mojej akceptacji', value: String(data.pending_my_approval), to: '/tenders' },
    { label: 'Deadline < 7 dni', value: String(data.deadline_soon), to: '/tenders?filter=deadline_soon' },
    { label: 'Zamienniki do akcept.', value: String(data.substitutes_pending), to: '/substitutes' },
  ]

  return (
    <div>
      <h1 className="mb-4 text-xl font-semibold">Dashboard</h1>
      <div className="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        {kpis.map((k) => (
          <div key={k.label} className="rounded-xl bg-white p-4 text-center shadow-sm">
            <b className="block text-2xl text-blue-600">{k.value}</b>
            <span className="text-xs text-slate-500">{k.label}</span>
            {'to' in k && k.to && (
              <Link to={k.to} className="mt-1 block text-[11px] text-blue-600 hover:underline">
                Zobacz
              </Link>
            )}
          </div>
        ))}
      </div>
      <div className="rounded-xl bg-white p-4 shadow-sm">
        <h2 className="mb-3 text-sm font-semibold">Ostatnie przetargi</h2>
        <table className="w-full text-left text-xs">
          <thead>
            <tr className="border-b bg-slate-50">
              <th className="p-2">Numer</th>
              <th className="p-2">Klient</th>
              <th className="p-2">Status</th>
              <th className="p-2">Termin</th>
              <th className="p-2">AI %</th>
              <th className="p-2"></th>
            </tr>
          </thead>
          <tbody>
            {data.recent_tenders.map((t) => (
              <tr key={t.id} className="border-b">
                <td className="p-2">{t.number}</td>
                <td className="p-2">{t.client?.name}</td>
                <td className="p-2">{t.status}</td>
                <td className="p-2">
                  {t.deadline ?? '—'}
                  {t.deadline &&
                    new Date(t.deadline) <= new Date(Date.now() + 7 * 86400000) &&
                    new Date(t.deadline) >= new Date(new Date().toDateString()) && (
                      <span className="ml-1 text-red-600">!</span>
                    )}
                </td>
                <td className="p-2">{t.ai_percent}%</td>
                <td className="p-2">
                  <Link className="text-blue-600 hover:underline" to={`/tenders/${t.id}`}>
                    Otwórz
                  </Link>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}
