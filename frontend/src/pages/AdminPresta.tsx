import { useEffect, useState, type FormEvent } from 'react'
import { useAuth } from '../auth'
import { PrestaCategoryMapTab } from '../components/PrestaCategoryMapTab'
import { PrestaSearchModal, type PrestaSearchResult } from '../components/PrestaSearchModal'
import { api, can, type Product } from '../lib/api'

type PrestaSettings = {
  enabled: boolean
  host: string
  port: number
  database: string
  username: string
  prefix: string
  id_lang: number
  shop_url: string
  id_category_default?: number
  delivery_label?: string
  has_password: boolean
  has_webservice_key?: boolean
  source: string
}

type ProductPage = { data: Product[] }

export function AdminPresta() {
  const { user } = useAuth()
  const canSearch = can(user, 'price_lists.import')
  const [err, setErr] = useState('')
  const [msg, setMsg] = useState('')
  const [busy, setBusy] = useState(false)
  const [enabled, setEnabled] = useState(false)
  const [host, setHost] = useState('')
  const [port, setPort] = useState('3306')
  const [database, setDatabase] = useState('')
  const [username, setUsername] = useState('')
  const [password, setPassword] = useState('')
  const [hasPassword, setHasPassword] = useState(false)
  const [prefix, setPrefix] = useState('ps_')
  const [idLang, setIdLang] = useState('1')
  const [shopUrl, setShopUrl] = useState('https://supon.rzeszow.pl')
  const [webserviceKey, setWebserviceKey] = useState('')
  const [hasWebserviceKey, setHasWebserviceKey] = useState(false)
  const [idCategoryDefault, setIdCategoryDefault] = useState('2')
  const [deliveryLabel, setDeliveryLabel] = useState('Na zamówienie')
  const [source, setSource] = useState('')
  const [catalogQ, setCatalogQ] = useState('')
  const [catalogRows, setCatalogRows] = useState<Product[]>([])
  const [catalogLoading, setCatalogLoading] = useState(false)
  const [picked, setPicked] = useState<Record<number, boolean>>({})
  const [prestaOpen, setPrestaOpen] = useState(false)
  const [prestaBusy, setPrestaBusy] = useState(false)
  const [prestaErr, setPrestaErr] = useState('')
  const [prestaItems, setPrestaItems] = useState<PrestaSearchResult[]>([])
  const [tab, setTab] = useState<'polaczenie' | 'kategorie'>('polaczenie')

  async function load() {
    const data = await api<PrestaSettings>('/admin/presta-settings')
    setEnabled(Boolean(data.enabled))
    setHost(data.host ?? '')
    setPort(String(data.port || 3306))
    setDatabase(data.database ?? '')
    setUsername(data.username ?? '')
    setHasPassword(Boolean(data.has_password))
    setPrefix(data.prefix || 'ps_')
    setIdLang(String(data.id_lang || 1))
    setShopUrl(data.shop_url || 'https://supon.rzeszow.pl')
    setHasWebserviceKey(Boolean(data.has_webservice_key))
    setIdCategoryDefault(String(data.id_category_default || 2))
    setDeliveryLabel(data.delivery_label || 'Na zamówienie')
    setSource(data.source)
  }

  useEffect(() => {
    void load().catch((e: Error) => setErr(e.message))
  }, [])

  async function onSave(e: FormEvent) {
    e.preventDefault()
    setBusy(true)
    setErr('')
    setMsg('')
    try {
      const body: Record<string, unknown> = {
        enabled,
        host: host.trim() || null,
        port: Number(port),
        database: database.trim() || null,
        username: username.trim() || null,
        prefix: prefix.trim() || 'ps_',
        id_lang: Number(idLang),
        shop_url: shopUrl.trim() || null,
        id_category_default: Number(idCategoryDefault) || 2,
        delivery_label: deliveryLabel.trim() || 'Na zamówienie',
      }
      if (password.trim()) body.password = password.trim()
      if (webserviceKey.trim()) body.webservice_key = webserviceKey.trim()
      await api('/admin/presta-settings', {
        method: 'PUT',
        body: JSON.stringify(body),
      })
      setPassword('')
      setWebserviceKey('')
      setMsg('Zapisano ustawienia sklepu.')
      await load()
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Błąd zapisu')
    } finally {
      setBusy(false)
    }
  }

  async function onTest() {
    setBusy(true)
    setErr('')
    setMsg('')
    try {
      const res = await api<{ ok: boolean; message: string }>('/admin/presta-settings/test', {
        method: 'POST',
        body: '{}',
      })
      setMsg(res.message)
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Błąd testu')
    } finally {
      setBusy(false)
    }
  }

  useEffect(() => {
    if (!canSearch) return
    const t = window.setTimeout(() => {
      const q = catalogQ.trim()
      if (q.length < 2) {
        setCatalogRows([])
        return
      }
      setCatalogLoading(true)
      void api<ProductPage>(`/products?q=${encodeURIComponent(q)}&per_page=30&sort=name&dir=asc`)
        .then((res) => setCatalogRows(res.data ?? []))
        .catch(() => setCatalogRows([]))
        .finally(() => setCatalogLoading(false))
    }, 300)
    return () => window.clearTimeout(t)
  }, [catalogQ, canSearch])

  const pickedIds = Object.keys(picked)
    .map(Number)
    .filter((id) => picked[id])

  async function searchPresta() {
    if (pickedIds.length === 0) return
    setPrestaBusy(true)
    setPrestaErr('')
    setPrestaOpen(true)
    try {
      if (pickedIds.length === 1) {
        const res = await api<PrestaSearchResult>(`/products/${pickedIds[0]}/presta-search`, {
          method: 'POST',
          body: '{}',
        })
        setPrestaItems([res])
        return
      }
      const res = await api<{ items: PrestaSearchResult[] }>('/products/presta-search', {
        method: 'POST',
        body: JSON.stringify({ product_ids: pickedIds.slice(0, 80) }),
      })
      setPrestaItems(res.items ?? [])
    } catch (ex) {
      setPrestaItems([])
      setPrestaErr(ex instanceof Error ? ex.message : 'Błąd wyszukiwania w Preście')
    } finally {
      setPrestaBusy(false)
    }
  }

  return (
    <div>
      {err && <p className="mb-2 text-sm text-red-600">{err}</p>}
      {msg && <p className="mb-2 text-sm text-green-700">{msg}</p>}

      <div className="mb-3 flex gap-1 text-xs">
        <button
          type="button"
          className={`rounded px-3 py-1.5 ${tab === 'polaczenie' ? 'bg-slate-800 text-white' : 'bg-white text-slate-700 shadow-sm'}`}
          onClick={() => setTab('polaczenie')}
        >
          Połączenie
        </button>
        <button
          type="button"
          className={`rounded px-3 py-1.5 ${tab === 'kategorie' ? 'bg-slate-800 text-white' : 'bg-white text-slate-700 shadow-sm'}`}
          onClick={() => setTab('kategorie')}
        >
          Kategorie
        </button>
      </div>

      {tab === 'kategorie' && <PrestaCategoryMapTab />}

      {tab === 'polaczenie' && (
        <>
      <form onSubmit={(e) => void onSave(e)} className="mb-4 grid max-w-3xl gap-3 rounded-xl bg-white p-4 shadow-sm sm:grid-cols-2">
        <div className="sm:col-span-2">
          <h2 className="text-sm font-semibold">Sklep PrestaShop</h2>
          <p className="mt-1 text-xs text-slate-500">
            Baza (SELECT) do wyszukiwania opisów. Klucz Webservice jest potrzebny, żeby wysyłać
            produkty do sklepu (rozmiary + termin na zamówienie). Ceny i stany ze sklepu nie są
            zapisywane przy imporcie
            {source ? ` · źródło: ${source}` : ''}.
          </p>
        </div>

        <label className="flex items-center gap-2 text-xs sm:col-span-2">
          <input type="checkbox" checked={enabled} onChange={(e) => setEnabled(e.target.checked)} />
          Włącz połączenie
        </label>

        <label className="text-xs">
          Host MySQL
          <input
            className="mt-1 w-full rounded border px-2 py-1.5 text-sm"
            value={host}
            onChange={(e) => setHost(e.target.value)}
            placeholder="127.0.0.1 albo vpn.supon.rzeszow.pl"
          />
        </label>
        <label className="text-xs">
          Port
          <input
            className="mt-1 w-full rounded border px-2 py-1.5 text-sm"
            value={port}
            onChange={(e) => setPort(e.target.value)}
          />
        </label>
        <label className="text-xs">
          Baza
          <input
            className="mt-1 w-full rounded border px-2 py-1.5 text-sm"
            value={database}
            onChange={(e) => setDatabase(e.target.value)}
            placeholder="supon_presta"
          />
        </label>
        <label className="text-xs">
          Użytkownik (SELECT)
          <input
            className="mt-1 w-full rounded border px-2 py-1.5 text-sm"
            value={username}
            onChange={(e) => setUsername(e.target.value)}
            placeholder="rag_readonly"
          />
        </label>
        <label className="text-xs sm:col-span-2">
          Hasło {hasPassword ? '(zapisane, wpisz aby zmienić)' : ''}
          <input
            type="password"
            className="mt-1 w-full rounded border px-2 py-1.5 text-sm"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            autoComplete="new-password"
          />
        </label>
        <label className="text-xs">
          Prefiks tabel
          <input
            className="mt-1 w-full rounded border px-2 py-1.5 text-sm"
            value={prefix}
            onChange={(e) => setPrefix(e.target.value)}
          />
        </label>
        <label className="text-xs">
          id_lang (PL = 1)
          <input
            className="mt-1 w-full rounded border px-2 py-1.5 text-sm"
            value={idLang}
            onChange={(e) => setIdLang(e.target.value)}
          />
        </label>
        <label className="text-xs sm:col-span-2">
          URL sklepu
          <input
            className="mt-1 w-full rounded border px-2 py-1.5 text-sm"
            value={shopUrl}
            onChange={(e) => setShopUrl(e.target.value)}
          />
        </label>
        <label className="text-xs sm:col-span-2">
          Klucz Webservice (zapis do sklepu)
          <input
            type="password"
            className="mt-1 w-full rounded border px-2 py-1.5 text-sm"
            value={webserviceKey}
            onChange={(e) => setWebserviceKey(e.target.value)}
            autoComplete="new-password"
            placeholder={hasWebserviceKey ? 'zapisany, wpisz aby zmienić' : 'klucz z Presta → Parametry zaawansowane → Webservice'}
          />
        </label>
        <label className="text-xs">
          id kategorii domyślnej
          <input
            className="mt-1 w-full rounded border px-2 py-1.5 text-sm"
            value={idCategoryDefault}
            onChange={(e) => setIdCategoryDefault(e.target.value)}
          />
        </label>
        <label className="text-xs">
          Termin dostawy
          <input
            className="mt-1 w-full rounded border px-2 py-1.5 text-sm"
            value={deliveryLabel}
            onChange={(e) => setDeliveryLabel(e.target.value)}
          />
        </label>

        <div className="flex gap-2 sm:col-span-2">
          <button
            type="submit"
            disabled={busy}
            className="rounded bg-slate-800 px-3 py-1.5 text-xs text-white disabled:opacity-50"
          >
            Zapisz
          </button>
          <button
            type="button"
            disabled={busy}
            onClick={() => void onTest()}
            className="rounded border border-slate-300 px-3 py-1.5 text-xs disabled:opacity-50"
          >
            Test połączenia
          </button>
        </div>
      </form>

      {canSearch && (
        <div id="presta-search" className="max-w-3xl rounded-xl bg-white p-4 shadow-sm">
          <h2 className="text-sm font-semibold">Wyszukaj w Presta</h2>
          <p className="mt-1 text-xs text-slate-500">
            Znajdź produkt w katalogu, zaznacz i szukaj odpowiednika w sklepie (SKU/EAN, potem nazwa).
          </p>
          <input
            className="mt-3 w-full rounded border border-slate-300 px-3 py-2 text-sm"
            placeholder="Szukaj kod, nazwa, producent…"
            value={catalogQ}
            onChange={(e) => setCatalogQ(e.target.value)}
          />
          <div className="mt-3 max-h-64 overflow-auto rounded border border-slate-100">
            <table className="w-full text-left text-xs">
              <thead>
                <tr className="border-b bg-slate-50">
                  <th className="p-2 w-8" />
                  <th className="p-2">SKU</th>
                  <th className="p-2">Nazwa</th>
                  <th className="p-2">Producent</th>
                </tr>
              </thead>
              <tbody>
                {catalogRows.map((p) => (
                  <tr key={p.id} className="border-b">
                    <td className="p-2">
                      <input
                        type="checkbox"
                        checked={Boolean(picked[p.id])}
                        onChange={() =>
                          setPicked((prev) => ({ ...prev, [p.id]: !prev[p.id] }))
                        }
                      />
                    </td>
                    <td className="p-2 font-mono">{p.sku}</td>
                    <td className="p-2">{p.name}</td>
                    <td className="p-2">{p.manufacturer}</td>
                  </tr>
                ))}
                {catalogQ.trim().length >= 2 && !catalogLoading && catalogRows.length === 0 && (
                  <tr>
                    <td colSpan={4} className="p-3 text-slate-400">
                      Brak produktów.
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
          <button
            type="button"
            disabled={prestaBusy || pickedIds.length === 0}
            onClick={() => void searchPresta()}
            className="mt-3 rounded bg-emerald-700 px-3 py-2 text-xs text-white disabled:opacity-50"
          >
            {prestaBusy ? 'Szukam…' : `Wyszukaj w Presta${pickedIds.length > 0 ? ` (${pickedIds.length})` : ''}`}
          </button>
          <PrestaSearchModal
            open={prestaOpen}
            items={prestaItems}
            loading={prestaBusy}
            error={prestaErr}
            onClose={() => setPrestaOpen(false)}
            onApplied={() => setMsg('Zastosowano dane ze sklepu.')}
          />
        </div>
      )}
        </>
      )}
    </div>
  )
}
