import { useEffect, useState, type CSSProperties } from 'react'
import { Link } from 'react-router-dom'
import { api, type Tender } from '../lib/api'
import { tenderStatusLabel } from '../lib/tenderStatus'

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

/** Termin w ciągu 7 dni i nie w przeszłości. */
function isDeadlineSoon(deadline: string | null): boolean {
  return (
    !!deadline &&
    new Date(deadline) <= new Date(Date.now() + 7 * 86400000) &&
    new Date(deadline) >= new Date(new Date().toDateString())
  )
}

export function Dashboard() {
  const [data, setData] = useState<Dash | null>(null)

  useEffect(() => {
    void api<Dash>('/dashboard').then(setData)
  }, [])

  if (!data) return <p className="text-sm text-slate-500">Ładowanie…</p>

  const kpis = [
    { id: 'mine', label: 'Moje przetargi', value: String(data.my_tenders), to: '/tenders?filter=mine' },
    { id: 'value', label: 'Wartość ofert', value: `${data.offer_value_net.toLocaleString('pl-PL')} zł` },
    { id: 'margin', label: 'Śr. marża', value: `${data.avg_margin_percent}%` },
    {
      id: 'approval',
      label: 'Do mojej akceptacji',
      value: String(data.pending_my_approval),
      to: '/tenders',
      tone: data.pending_my_approval > 0 ? 'attention' : undefined,
    },
    {
      id: 'deadline',
      label: 'Deadline < 7 dni',
      value: String(data.deadline_soon),
      to: '/tenders?filter=deadline_soon',
      tone: data.deadline_soon > 0 ? 'alert' : undefined,
    },
    { id: 'substitutes', label: 'Zamienniki do akcept.', value: String(data.substitutes_pending), to: '/substitutes' },
  ]

  return (
    <div>
      <h1 className="app-page-title mb-4 text-xl font-semibold">Dashboard</h1>
      <div className="app-kpis mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        {kpis.map((k) => (
          <div
            key={k.id}
            className="app-kpi rounded-xl bg-white p-4 text-center shadow-sm"
            data-kpi={k.id}
            data-tone={k.tone}
          >
            <b className="app-kpi-value block text-2xl text-blue-600">{k.value}</b>
            <span className="app-kpi-label text-xs text-slate-500">{k.label}</span>
            {'to' in k && k.to && (
              <Link to={k.to} className="app-kpi-link mt-1 block text-[11px] text-blue-600 hover:underline">
                Zobacz
              </Link>
            )}
          </div>
        ))}
      </div>
      <div className="app-card rounded-xl bg-white p-4 shadow-sm">
        <h2 className="app-card-title mb-3 text-sm font-semibold">Ostatnie przetargi</h2>
        <table className="app-table w-full text-left text-xs">
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
            {data.recent_tenders.map((t) => {
              const soon = isDeadlineSoon(t.deadline)
              return (
                <tr key={t.id} className="border-b">
                  <td className="p-2">
                    <span className="app-code">{t.number}</span>
                  </td>
                  <td className="p-2">{t.client?.name}</td>
                  <td className="p-2">
                    <span className="app-status" data-status={t.status}>
                      {tenderStatusLabel(t.status)}
                    </span>
                  </td>
                  <td className="p-2">
                    <span className="app-deadline" data-soon={soon ? 'true' : undefined}>
                      {t.deadline ?? '—'}
                      {soon && <span className="app-deadline-flag ml-1 text-red-600">!</span>}
                    </span>
                  </td>
                  <td className="p-2">
                    <span className="app-ai" style={{ '--ai': `${t.ai_percent}%` } as CSSProperties}>
                      {t.ai_percent}%
                    </span>
                  </td>
                  <td className="p-2">
                    <Link className="app-link text-blue-600 hover:underline" to={`/tenders/${t.id}`}>
                      Otwórz
                    </Link>
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>
    </div>
  )
}
