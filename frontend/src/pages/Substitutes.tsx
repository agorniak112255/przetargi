import { useEffect, useMemo, useState } from 'react'
import { api, type Substitute } from '../lib/api'

export function Substitutes() {
  const [rows, setRows] = useState<Substitute[]>([])

  useEffect(() => {
    void api<Substitute[]>('/substitutes').then(setRows)
  }, [])

  const groups = useMemo(() => {
    const map = new Map<number, { main: Substitute['main_product']; items: Substitute[] }>()
    for (const s of rows) {
      const id = s.main_product?.id
      if (!id) continue
      if (!map.has(id)) map.set(id, { main: s.main_product, items: [] })
      map.get(id)!.items.push(s)
    }
    return [...map.values()]
  }, [rows])

  return (
    <div>
      <h1 className="mb-2 text-xl font-semibold">Zamienniki</h1>
      <p className="mb-4 rounded-lg border-l-4 border-blue-500 bg-slate-100 p-3 text-xs text-slate-600">
        Relacja: <strong>produkt główny → lista zamienników</strong> (co zamienia ten kod).
      </p>
      <div className="space-y-3">
        {groups.map((g) => (
          <div key={g.main?.id} className="overflow-hidden rounded-xl border border-slate-200 bg-white">
            <div className="border-b bg-slate-50 px-4 py-3 text-sm font-semibold">
              <span className="mr-1 rounded bg-slate-800 px-1.5 py-0.5 text-[10px] text-white">
                PRODUKT GŁÓWNY
              </span>
              {g.main?.name} · {g.main?.sku}
            </div>
            <table className="w-full text-left text-xs">
              <thead>
                <tr className="border-b bg-slate-50/80">
                  <th className="p-2">Zamiennik</th>
                  <th className="p-2">Typ</th>
                  <th className="p-2">AI</th>
                  <th className="p-2">Powód</th>
                  <th className="p-2">Status</th>
                </tr>
              </thead>
              <tbody>
                {g.items.map((s) => (
                  <tr key={s.id} className="border-b">
                    <td className="p-2">
                      {s.substitute_product?.name} ({s.substitute_product?.sku})
                    </td>
                    <td className="p-2">{s.type}</td>
                    <td className="p-2">{s.match_percent}%</td>
                    <td className="p-2">{s.reason}</td>
                    <td className="p-2">{s.approval_status}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ))}
      </div>
    </div>
  )
}
