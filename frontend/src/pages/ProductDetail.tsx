import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { api, type Product, type Substitute } from '../lib/api'

type Detail = Product & { substitutes: Substitute[] }

export function ProductDetail() {
  const { id } = useParams()
  const [p, setP] = useState<Detail | null>(null)

  useEffect(() => {
    void api<Detail>(`/products/${id}`).then(setP)
  }, [id])

  if (!p) return <p className="text-sm text-slate-500">Ładowanie…</p>

  return (
    <div>
      <Link to="/products" className="text-xs text-blue-600 hover:underline">
        ← Produkty
      </Link>
      <h1 className="mt-2 text-xl font-semibold">{p.name}</h1>
      <p className="mb-4 text-sm text-slate-500">
        {p.sku} · {p.manufacturer} · {p.norms ?? 'bez normy'}
      </p>
      <div className="mb-4 grid gap-3 sm:grid-cols-3">
        <div className="rounded-xl bg-white p-4 shadow-sm text-sm">
          Cena kat. netto: <b>{p.catalog_price_net} zł</b>
        </div>
        <div className="rounded-xl bg-white p-4 shadow-sm text-sm">
          Zakup: <b>{p.purchase_price} zł</b>
        </div>
        <div className="rounded-xl bg-white p-4 shadow-sm text-sm">
          Stan: <b>{p.stock}</b>
        </div>
      </div>
      <h2 className="mb-2 text-sm font-semibold">Zamienniki dla tego produktu głównego</h2>
      <div className="rounded-xl bg-white p-4 shadow-sm">
        <table className="w-full text-left text-xs">
          <thead>
            <tr className="border-b bg-slate-50">
              <th className="p-2">Kod</th>
              <th className="p-2">Nazwa</th>
              <th className="p-2">Typ</th>
              <th className="p-2">AI</th>
              <th className="p-2">Status</th>
            </tr>
          </thead>
          <tbody>
            {p.substitutes.map((s) => (
              <tr key={s.id} className="border-b">
                <td className="p-2">{s.substitute_product?.sku}</td>
                <td className="p-2">{s.substitute_product?.name}</td>
                <td className="p-2">{s.type}</td>
                <td className="p-2">{s.match_percent}%</td>
                <td className="p-2">{s.approval_status}</td>
              </tr>
            ))}
            {p.substitutes.length === 0 && (
              <tr>
                <td colSpan={5} className="p-3 text-slate-400">
                  Brak zamienników dla tego głównego.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  )
}
