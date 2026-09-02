import { useEffect, useState } from 'react'
import { api } from '../lib/api'

type PrestaCat = {
  presta_id: number
  name: string
  path: string
  level_depth: number
  active: boolean
}

type MapRow = {
  local_category: string
  presta_id: number | null
  product_count: number
  presta_path: string | null
}

type Listing = {
  categories: PrestaCat[]
  maps: MapRow[]
  default_presta_id: number
  imported?: number
}

export function PrestaCategoryMapTab() {
  const [err, setErr] = useState('')
  const [msg, setMsg] = useState('')
  const [busy, setBusy] = useState(false)
  const [categories, setCategories] = useState<PrestaCat[]>([])
  const [maps, setMaps] = useState<MapRow[]>([])
  const [defaultId, setDefaultId] = useState(2)

  async function load() {
    const data = await api<Listing>('/admin/presta-categories')
    setCategories(data.categories)
    setMaps(data.maps)
    setDefaultId(data.default_presta_id)
  }

  useEffect(() => {
    void load().catch((e: Error) => setErr(e.message))
  }, [])

  async function onSync() {
    setBusy(true)
    setErr('')
    setMsg('')
    try {
      const data = await api<Listing>('/admin/presta-categories/sync', { method: 'POST', body: '{}' })
      setCategories(data.categories)
      setMaps(data.maps)
      setDefaultId(data.default_presta_id)
      setMsg(`Pobrano ${data.imported ?? data.categories.length} kategorii z Presty.`)
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Błąd pobierania kategorii')
    } finally {
      setBusy(false)
    }
  }

  function setMap(local: string, prestaId: number | null) {
    setMaps((prev) =>
      prev.map((row) =>
        row.local_category === local ? { ...row, presta_id: prestaId } : row,
      ),
    )
  }

  async function onSave() {
    setBusy(true)
    setErr('')
    setMsg('')
    try {
      const data = await api<Listing>('/admin/presta-categories/maps', {
        method: 'PUT',
        body: JSON.stringify({
          maps: maps.map((row) => ({
            local_category: row.local_category,
            presta_id: row.presta_id,
          })),
        }),
      })
      setMaps(data.maps)
      setMsg('Zapisano mapowanie kategorii.')
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Błąd zapisu mapowania')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="max-w-4xl rounded-xl bg-white p-4 shadow-sm">
      {err && <p className="mb-2 text-sm text-red-600">{err}</p>}
      {msg && <p className="mb-2 text-sm text-green-700">{msg}</p>}
      <h2 className="text-sm font-semibold">Mapowanie kategorii</h2>
      <p className="mt-1 text-xs text-slate-500">
        Pobierz drzewo z Presty i przypisz kategorię z Przetargów do konkretnej kategorii sklepu.
        Bez mapowania produkt idzie do kategorii domyślnej (#{defaultId}).
      </p>
      <div className="mt-3 flex gap-2">
        <button
          type="button"
          disabled={busy}
          onClick={() => void onSync()}
          className="rounded bg-emerald-700 px-3 py-1.5 text-xs text-white disabled:opacity-50"
        >
          {busy ? 'Pobieram…' : 'Pobierz kategorie z Presty'}
        </button>
        <button
          type="button"
          disabled={busy || maps.length === 0}
          onClick={() => void onSave()}
          className="rounded bg-slate-800 px-3 py-1.5 text-xs text-white disabled:opacity-50"
        >
          Zapisz mapowanie
        </button>
      </div>
      <div className="mt-3 max-h-[32rem] overflow-auto rounded border border-slate-100">
        <table className="w-full text-left text-xs">
          <thead>
            <tr className="border-b bg-slate-50">
              <th className="p-2">Kategoria w Przetargach</th>
              <th className="p-2 w-16">Szt.</th>
              <th className="p-2">Kategoria w Preście</th>
            </tr>
          </thead>
          <tbody>
            {maps.map((row) => (
              <tr key={row.local_category} className="border-b">
                <td className="p-2">{row.local_category}</td>
                <td className="p-2 text-slate-500">{row.product_count}</td>
                <td className="p-2">
                  <select
                    className="w-full rounded border border-slate-300 px-2 py-1"
                    value={row.presta_id ?? ''}
                    onChange={(e) =>
                      setMap(row.local_category, e.target.value === '' ? null : Number(e.target.value))
                    }
                  >
                    <option value="">Domyślna (#{defaultId})</option>
                    {categories.map((cat) => (
                      <option key={cat.presta_id} value={cat.presta_id}>
                        {cat.path}
                      </option>
                    ))}
                  </select>
                </td>
              </tr>
            ))}
            {maps.length === 0 && (
              <tr>
                <td colSpan={3} className="p-3 text-slate-400">
                  {categories.length === 0
                    ? 'Najpierw pobierz kategorie z Presty.'
                    : 'Brak kategorii w produktach do zmapowania.'}
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  )
}
