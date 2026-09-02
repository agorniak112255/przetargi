import { useEffect, useMemo, useState, type FormEvent } from 'react'
import { api } from '../lib/api'

type SearchSite = {
  host: string
  links: number
  sources: string[]
  source_label: string
  last_seen_at: string | null
  last_attempt_at: string | null
  added_at: string | null
}

type SitesResponse = {
  sites: SearchSite[]
  total: number
  with_links: number
  links: number
}

type SortKey = 'host' | 'links' | 'source_label' | 'last_seen_at'

const SOURCE_BADGE: Record<string, string> = {
  manual: 'bg-emerald-100 text-emerald-800',
  config: 'bg-sky-100 text-sky-800',
  producent: 'bg-violet-100 text-violet-800',
  indeks: 'bg-amber-100 text-amber-900',
}

const SOURCE_NAME: Record<string, string> = {
  manual: 'Ręcznie',
  config: 'Konfiguracja',
  producent: 'Producent',
  indeks: 'Indeks',
}

function formatWhen(iso: string | null): string {
  if (!iso) return '—'
  try {
    return new Date(iso).toLocaleString('pl-PL')
  } catch {
    return iso
  }
}

function SortMark({ active, dir }: { active: boolean; dir: 'asc' | 'desc' }) {
  if (!active) return <span className="ml-1 text-slate-300">↕</span>
  return <span className="ml-1 text-sky-600">{dir === 'asc' ? '↑' : '↓'}</span>
}

export function AdminSearchSites() {
  const [rows, setRows] = useState<SearchSite[]>([])
  const [total, setTotal] = useState(0)
  const [withLinks, setWithLinks] = useState(0)
  const [links, setLinks] = useState(0)
  const [url, setUrl] = useState('')
  const [q, setQ] = useState('')
  const [sortKey, setSortKey] = useState<SortKey>('links')
  const [sortDir, setSortDir] = useState<'asc' | 'desc'>('desc')
  const [err, setErr] = useState('')
  const [msg, setMsg] = useState('')
  const [busy, setBusy] = useState(false)

  async function load() {
    setBusy(true)
    setErr('')
    try {
      const data = await api<SitesResponse>('/admin/catalog-search-sites')
      setRows(data.sites)
      setTotal(data.total)
      setWithLinks(data.with_links)
      setLinks(data.links)
    } catch (e) {
      setErr(e instanceof Error ? e.message : 'Błąd wczytywania stron')
    } finally {
      setBusy(false)
    }
  }

  useEffect(() => {
    void load()
  }, [])

  function toggleSort(key: SortKey) {
    if (sortKey === key) {
      setSortDir((d) => (d === 'asc' ? 'desc' : 'asc'))
      return
    }
    setSortKey(key)
    setSortDir(key === 'host' || key === 'source_label' ? 'asc' : 'desc')
  }

  const visible = useMemo(() => {
    const needle = q.trim().toLowerCase()
    const filtered = needle === ''
      ? rows
      : rows.filter((r) => r.host.includes(needle) || r.source_label.toLowerCase().includes(needle))
    const copy = [...filtered]
    copy.sort((a, b) => {
      let cmp = 0
      if (sortKey === 'links') {
        cmp = a.links - b.links
      } else if (sortKey === 'last_seen_at') {
        cmp = (a.last_seen_at ?? '').localeCompare(b.last_seen_at ?? '')
      } else {
        cmp = String(a[sortKey]).localeCompare(String(b[sortKey]), 'pl')
      }
      return sortDir === 'asc' ? cmp : -cmp
    })
    return copy
  }, [rows, q, sortKey, sortDir])

  async function onAdd(e: FormEvent) {
    e.preventDefault()
    setBusy(true)
    setErr('')
    setMsg('')
    try {
      const created = await api<SearchSite & { already?: boolean }>('/admin/catalog-search-sites', {
        method: 'POST',
        body: JSON.stringify({ url }),
      })
      setUrl('')
      setMsg(`Dodano ${created.host} — indeksowanie w tle.`)
      await load()
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Nie udało się dodać strony')
    } finally {
      setBusy(false)
    }
  }

  const headers: { key: SortKey; label: string; className?: string }[] = [
    { key: 'host', label: 'Domena' },
    { key: 'links', label: 'Linki', className: 'text-right' },
    { key: 'source_label', label: 'Źródło' },
    { key: 'last_seen_at', label: 'Ostatnio w indeksie' },
  ]

  return (
    <div className="space-y-4">
      <div className="overflow-hidden rounded-2xl border border-slate-200 bg-gradient-to-br from-sky-50 via-white to-emerald-50 p-5 shadow-sm">
        <p className="text-[11px] font-semibold uppercase tracking-wide text-sky-700">Wyszukiwarka katalogu</p>
        <h2 className="mt-1 text-lg font-semibold text-slate-900">Strony w indeksie</h2>
        <p className="mt-1 max-w-2xl text-[12px] text-slate-600">
          Domeny, z których zbieramy karty produktów (sitemap). Nowa strona trafia do indeksu i do
          wyszukiwania opisów — ten sam host (także z www) nie zostanie dodany drugi raz.
        </p>
        <div className="mt-4 grid gap-3 sm:grid-cols-3">
          <Stat label="Strony" value={total} hint="w konfiguracji i dodane ręcznie" />
          <Stat label="Z linkami" value={withLinks} hint="mają karty w indeksie" tone="ok" />
          <Stat label="Linki łącznie" value={links} hint="adresy kart produktu" tone="links" />
        </div>
      </div>

      <form
        onSubmit={(e) => void onAdd(e)}
        className="flex flex-col gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:flex-row sm:items-end"
      >
        <label className="min-w-0 flex-1 text-[12px] font-medium text-slate-700">
          Nowa strona
          <input
            value={url}
            onChange={(e) => setUrl(e.target.value)}
            placeholder="https://sklep-bhp.pl albo sklep-bhp.pl"
            className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none ring-sky-200 focus:border-sky-400 focus:ring-2"
          />
        </label>
        <button
          type="submit"
          disabled={busy || url.trim() === ''}
          className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50"
        >
          Dodaj i sprawdź
        </button>
      </form>

      {err && (
        <p className="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-[12px] text-rose-800">{err}</p>
      )}
      {msg && (
        <p className="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-[12px] text-emerald-800">{msg}</p>
      )}

      <div className="rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-4 py-3">
          <p className="text-[12px] text-slate-500">
            {visible.length} z {rows.length} stron
          </p>
          <input
            value={q}
            onChange={(e) => setQ(e.target.value)}
            placeholder="Filtruj domenę…"
            className="w-full max-w-xs rounded-lg border border-slate-300 px-3 py-1.5 text-sm outline-none focus:border-sky-400"
          />
        </div>
        <div className="overflow-x-auto">
          <table className="w-full text-left text-[13px]">
            <thead>
              <tr className="border-b bg-slate-50 text-[11px] uppercase tracking-wide text-slate-500">
                {headers.map((h) => (
                  <th key={h.key} className={`p-3 ${h.className ?? ''}`}>
                    <button
                      type="button"
                      onClick={() => toggleSort(h.key)}
                      className="inline-flex items-center font-semibold hover:text-sky-700"
                    >
                      {h.label}
                      <SortMark active={sortKey === h.key} dir={sortDir} />
                    </button>
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {visible.map((row) => (
                <tr
                  key={row.host}
                  className={`border-b last:border-0 ${row.links === 0 ? 'bg-amber-50/40' : 'hover:bg-slate-50'}`}
                >
                  <td className="p-3">
                    <a
                      href={`https://${row.host}`}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="font-medium text-sky-800 hover:underline"
                    >
                      {row.host}
                    </a>
                  </td>
                  <td className="p-3 text-right tabular-nums">
                    <span className={row.links > 0 ? 'font-semibold text-slate-800' : 'text-amber-700'}>
                      {row.links.toLocaleString('pl-PL')}
                    </span>
                  </td>
                  <td className="p-3">
                    <span className="flex flex-wrap gap-1">
                      {row.sources.map((s) => (
                        <span
                          key={s}
                          className={`rounded-full px-2 py-0.5 text-[10px] font-semibold ${SOURCE_BADGE[s] ?? 'bg-slate-100 text-slate-700'}`}
                        >
                          {SOURCE_NAME[s] ?? s}
                        </span>
                      ))}
                    </span>
                  </td>
                  <td className="p-3 text-slate-500">{formatWhen(row.last_seen_at)}</td>
                </tr>
              ))}
              {visible.length === 0 && (
                <tr>
                  <td colSpan={4} className="p-8 text-center text-slate-400">
                    {busy ? 'Ładowanie…' : 'Brak stron dla tego filtra.'}
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  )
}

function Stat({
  label,
  value,
  hint,
  tone,
}: {
  label: string
  value: number
  hint: string
  tone?: 'ok' | 'links'
}) {
  const ring =
    tone === 'ok'
      ? 'border-emerald-200 bg-white'
      : tone === 'links'
        ? 'border-sky-200 bg-white'
        : 'border-slate-200 bg-white'

  return (
    <div className={`rounded-xl border px-4 py-3 shadow-sm ${ring}`}>
      <div className="text-[11px] font-medium text-slate-500">{label}</div>
      <div className="mt-0.5 text-2xl font-semibold tabular-nums text-slate-900">
        {value.toLocaleString('pl-PL')}
      </div>
      <div className="text-[10px] text-slate-400">{hint}</div>
    </div>
  )
}
