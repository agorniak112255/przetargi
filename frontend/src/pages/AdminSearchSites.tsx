import { useEffect, useMemo, useRef, useState, type FormEvent } from 'react'
import { api } from '../lib/api'

type SearchSite = {
  host: string
  links: number
  sources: string[]
  source_label: string
  last_seen_at: string | null
  last_attempt_at: string | null
  empty_reason: string | null
  added_at: string | null
  is_config_skip_listed: boolean
  skip_overridden: boolean
}

type SitesResponse = {
  sites: SearchSite[]
  total: number
  with_links: number
  links: number
}

type CatalogPageRow = {
  id: number
  url: string
  title: string | null
  last_seen_at: string | null
}

type PagesResponse = {
  host: string
  links: number
  data: CatalogPageRow[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
}

type CheckProgress = {
  host: string
  status: 'idle' | 'queued' | 'running' | 'done' | 'failed'
  started_at: string | null
  finished_at: string | null
  lines: { at: string; text: string }[]
}

type SortKey = 'host' | 'links' | 'source_label' | 'last_seen_at'

const WATCH_KEY = 'catalog-check-host'

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

function formatClock(iso: string): string {
  try {
    return new Date(iso).toLocaleTimeString('pl-PL')
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
  const [reindexHost, setReindexHost] = useState('')
  const [deleteHost, setDeleteHost] = useState('')
  const [skipToggleHost, setSkipToggleHost] = useState('')
  const [pagesHost, setPagesHost] = useState<SearchSite | null>(null)
  const [watchHost, setWatchHost] = useState(() => sessionStorage.getItem(WATCH_KEY) ?? '')
  const [watchTick, setWatchTick] = useState(0)
  const [check, setCheck] = useState<CheckProgress | null>(null)

  async function load(silent = false) {
    if (!silent) {
      setBusy(true)
    }
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
      if (!silent) {
        setBusy(false)
      }
    }
  }

  useEffect(() => {
    void load()
  }, [])

  function watch(host: string) {
    setCheck(null)
    setWatchHost(host)
    setWatchTick((n) => n + 1)
    sessionStorage.setItem(WATCH_KEY, host)
  }

  function stopWatch() {
    setWatchHost('')
    setCheck(null)
    sessionStorage.removeItem(WATCH_KEY)
  }

  useEffect(() => {
    if (watchHost === '') {
      return
    }
    let cancelled = false
    let timer = 0
    async function pull() {
      try {
        const data = await api<CheckProgress>(
          `/admin/catalog-search-sites/${encodeURIComponent(watchHost)}/progress`,
        )
        if (cancelled) {
          return
        }
        setCheck(data)
        if (data.status === 'queued' || data.status === 'running') {
          timer = window.setTimeout(() => void pull(), 1500)
        } else if (data.status === 'done' || data.status === 'failed') {
          void load(true)
        }
      } catch {
        if (!cancelled) {
          setCheck(null)
          timer = window.setTimeout(() => void pull(), 2000)
        }
      }
    }
    void pull()
    return () => {
      cancelled = true
      if (timer) {
        window.clearTimeout(timer)
      }
    }
  }, [watchHost, watchTick])

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
      : rows.filter(
          (r) =>
            r.host.includes(needle) ||
            r.source_label.toLowerCase().includes(needle) ||
            (r.empty_reason ?? '').toLowerCase().includes(needle),
        )
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
      watch(created.host)
      setMsg(`Dodano ${created.host} — indeksowanie w tle.`)
      await load()
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Nie udało się dodać strony')
    } finally {
      setBusy(false)
    }
  }

  async function onDelete(host: string) {
    if (!window.confirm(`Usunąć ${host} z indeksu i z tej listy?`)) {
      return
    }
    setDeleteHost(host)
    setErr('')
    setMsg('')
    try {
      const res = await api<{ message: string }>(
        `/admin/catalog-search-sites/${encodeURIComponent(host)}`,
        { method: 'DELETE' },
      )
      setMsg(res.message)
      if (pagesHost?.host === host) {
        setPagesHost(null)
      }
      await load()
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Nie udało się usunąć strony')
    } finally {
      setDeleteHost('')
    }
  }

  async function onToggleSkip(host: string, action: 'unskip' | 'reskip') {
    setSkipToggleHost(host)
    setErr('')
    setMsg('')
    try {
      const res = await api<{ message: string }>(
        `/admin/catalog-search-sites/${encodeURIComponent(host)}/${action}`,
        { method: 'POST' },
      )
      setMsg(res.message)
      if (action === 'unskip') {
        watch(host)
      }
      await load()
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Nie udało się zmienić blokady')
    } finally {
      setSkipToggleHost('')
    }
  }

  async function onReindex(host: string) {
    setReindexHost(host)
    setErr('')
    setMsg('')
    try {
      const res = await api<{ message: string }>(
        `/admin/catalog-search-sites/${encodeURIComponent(host)}/reindex`,
        { method: 'POST' },
      )
      setMsg(res.message)
      watch(host)
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Nie udało się zlecić sprawdzenia')
    } finally {
      setReindexHost('')
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
      {watchHost !== '' && (
        <CheckLogPanel host={watchHost} check={check} onClose={stopWatch} />
      )}
      <div className="overflow-hidden rounded-2xl border border-slate-200 bg-gradient-to-br from-sky-50 via-white to-emerald-50 p-5 shadow-sm">
        <p className="text-[11px] font-semibold uppercase tracking-wide text-sky-700">Wyszukiwarka katalogu</p>
        <h2 className="mt-1 text-lg font-semibold text-slate-900">Strony w indeksie</h2>
        <p className="mt-1 max-w-2xl text-[12px] text-slate-600">
          Domeny, z których zbieramy karty produktów (sitemap). Zero linków to zwykle brak sitemapy,
          WAF, CDN albo wpis z konfiguracji, którego jeszcze nie udało się zaindeksować.
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
                <th className="p-3 text-right">Akcje</th>
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
                    {row.empty_reason && (
                      <p className="mt-0.5 max-w-md text-[11px] text-amber-800">{row.empty_reason}</p>
                    )}
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
                  <td className="p-3 text-slate-500">
                    <div>{formatWhen(row.last_seen_at)}</div>
                    {row.last_attempt_at && (
                      <div className="text-[10px] text-slate-400">
                        sprawdzano {formatWhen(row.last_attempt_at)}
                      </div>
                    )}
                  </td>
                  <td className="p-3 text-right">
                    <div className="flex flex-wrap justify-end gap-1">
                      <button
                        type="button"
                        onClick={() => setPagesHost(row)}
                        className="rounded-lg border border-slate-300 px-2 py-1 text-[11px] font-semibold text-slate-700 hover:bg-slate-50"
                      >
                        Karty
                      </button>
                      {row.is_config_skip_listed && (
                        <button
                          type="button"
                          disabled={skipToggleHost === row.host}
                          onClick={() => void onToggleSkip(row.host, row.skip_overridden ? 'reskip' : 'unskip')}
                          className={`rounded-lg px-2 py-1 text-[11px] font-semibold disabled:opacity-50 ${
                            row.skip_overridden
                              ? 'border border-amber-300 text-amber-800 hover:bg-amber-50'
                              : 'bg-emerald-600 text-white hover:bg-emerald-700'
                          }`}
                        >
                          {skipToggleHost === row.host
                            ? '…'
                            : row.skip_overridden
                              ? 'Zablokuj ponownie'
                              : 'Odblokuj'}
                        </button>
                      )}
                      <button
                        type="button"
                        disabled={reindexHost === row.host}
                        onClick={() => void onReindex(row.host)}
                        className="rounded-lg bg-sky-600 px-2 py-1 text-[11px] font-semibold text-white hover:bg-sky-700 disabled:opacity-50"
                      >
                        {reindexHost === row.host ? 'Zlecam…' : 'Sprawdź'}
                      </button>
                      <button
                        type="button"
                        disabled={deleteHost === row.host}
                        onClick={() => void onDelete(row.host)}
                        className="rounded-lg border border-rose-300 px-2 py-1 text-[11px] font-semibold text-rose-700 hover:bg-rose-50 disabled:opacity-50"
                      >
                        {deleteHost === row.host ? 'Usuwam…' : 'Usuń'}
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
              {visible.length === 0 && (
                <tr>
                  <td colSpan={5} className="p-8 text-center text-slate-400">
                    {busy ? 'Ładowanie…' : 'Brak stron dla tego filtra.'}
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </div>

      {pagesHost && (
        <CatalogPagesModal
          site={pagesHost}
          onClose={() => setPagesHost(null)}
          onReindex={() => void onReindex(pagesHost.host)}
          onDelete={() => void onDelete(pagesHost.host)}
          reindexing={reindexHost === pagesHost.host}
          deleting={deleteHost === pagesHost.host}
        />
      )}
    </div>
  )
}

function CatalogPagesModal({
  site,
  onClose,
  onReindex,
  onDelete,
  reindexing,
  deleting,
}: {
  site: SearchSite
  onClose: () => void
  onReindex: () => void
  onDelete: () => void
  reindexing: boolean
  deleting: boolean
}) {
  const [q, setQ] = useState('')
  const [page, setPage] = useState(1)
  const [rows, setRows] = useState<CatalogPageRow[]>([])
  const [meta, setMeta] = useState({ current_page: 1, last_page: 1, per_page: 40, total: 0 })
  const [err, setErr] = useState('')
  const [busy, setBusy] = useState(false)

  async function load(nextPage = page, nextQ = q) {
    setBusy(true)
    setErr('')
    try {
      const params = new URLSearchParams()
      params.set('page', String(nextPage))
      params.set('per_page', '40')
      if (nextQ.trim() !== '') {
        params.set('q', nextQ.trim())
      }
      const data = await api<PagesResponse>(
        `/admin/catalog-search-sites/${encodeURIComponent(site.host)}/pages?${params}`,
      )
      setRows(data.data)
      setMeta(data.meta)
      setPage(data.meta.current_page)
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Błąd wczytywania kart')
    } finally {
      setBusy(false)
    }
  }

  useEffect(() => {
    void load(1, q)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [site.host])

  useEffect(() => {
    function onKey(e: KeyboardEvent) {
      if (e.key === 'Escape') {
        onClose()
      }
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [onClose])

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
      role="dialog"
      aria-modal="true"
      onClick={onClose}
    >
      <div
        className="flex max-h-[88vh] w-full max-w-4xl flex-col overflow-hidden rounded-xl bg-white shadow-lg"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="flex items-start justify-between gap-3 border-b border-slate-200 px-4 py-3">
          <div>
            <p className="text-sm font-semibold text-slate-900">Karty {site.host}</p>
            <p className="text-xs text-slate-500">
              {site.links.toLocaleString('pl-PL')} adresów w indeksie
              {site.empty_reason ? ` · ${site.empty_reason}` : ''}
            </p>
          </div>
          <div className="flex gap-2">
            <button
              type="button"
              disabled={reindexing}
              onClick={onReindex}
              className="rounded-lg bg-sky-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-sky-700 disabled:opacity-50"
            >
              {reindexing ? 'Zlecam…' : 'Sprawdź ponownie'}
            </button>
            <button
              type="button"
              disabled={deleting}
              onClick={onDelete}
              className="rounded-lg border border-rose-300 px-3 py-1.5 text-xs font-semibold text-rose-700 hover:bg-rose-50 disabled:opacity-50"
            >
              {deleting ? 'Usuwam…' : 'Usuń'}
            </button>
            <button
              type="button"
              onClick={onClose}
              className="rounded border border-slate-300 px-2 py-1 text-xs"
            >
              Zamknij
            </button>
          </div>
        </div>

        <form
          className="flex gap-2 border-b border-slate-100 px-4 py-2"
          onSubmit={(e) => {
            e.preventDefault()
            void load(1, q)
          }}
        >
          <input
            value={q}
            onChange={(e) => setQ(e.target.value)}
            placeholder="Szukaj w adresie lub tytule…"
            className="min-w-0 flex-1 rounded-lg border border-slate-300 px-3 py-1.5 text-sm outline-none focus:border-sky-400"
          />
          <button
            type="submit"
            className="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50"
          >
            Szukaj
          </button>
        </form>

        {err && (
          <p className="mx-4 mt-2 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-[12px] text-rose-800">
            {err}
          </p>
        )}

        <div className="min-h-0 flex-1 overflow-auto">
          <table className="w-full text-left text-[13px]">
            <thead>
              <tr className="sticky top-0 border-b bg-slate-50 text-[11px] uppercase tracking-wide text-slate-500">
                <th className="p-3">Adres</th>
                <th className="p-3">Tytuł</th>
                <th className="p-3">W indeksie</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((row) => (
                <tr key={row.id} className="border-b last:border-0 hover:bg-slate-50">
                  <td className="p-3">
                    <a
                      href={row.url}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="break-all text-sky-800 hover:underline"
                    >
                      {row.url}
                    </a>
                  </td>
                  <td className="p-3 text-slate-600">{row.title ?? '—'}</td>
                  <td className="whitespace-nowrap p-3 text-slate-500">{formatWhen(row.last_seen_at)}</td>
                </tr>
              ))}
              {rows.length === 0 && (
                <tr>
                  <td colSpan={3} className="p-8 text-center text-slate-400">
                    {busy ? 'Ładowanie…' : 'Brak kart dla tej domeny.'}
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>

        <div className="flex items-center justify-between border-t border-slate-100 px-4 py-2 text-[12px] text-slate-500">
          <span>
            {meta.total.toLocaleString('pl-PL')} wyników · strona {meta.current_page} / {meta.last_page}
          </span>
          <div className="flex gap-1">
            <button
              type="button"
              disabled={busy || page <= 1}
              onClick={() => void load(page - 1, q)}
              className="rounded border border-slate-300 px-2 py-1 disabled:opacity-40"
            >
              Wstecz
            </button>
            <button
              type="button"
              disabled={busy || page >= meta.last_page}
              onClick={() => void load(page + 1, q)}
              className="rounded border border-slate-300 px-2 py-1 disabled:opacity-40"
            >
              Dalej
            </button>
          </div>
        </div>
      </div>
    </div>
  )
}

function CheckLogPanel({
  host,
  check,
  onClose,
}: {
  host: string
  check: CheckProgress | null
  onClose: () => void
}) {
  const logRef = useRef<HTMLDivElement>(null)
  const status = check?.status ?? 'queued'
  const label =
    status === 'running'
      ? 'W toku'
      : status === 'queued'
        ? 'W kolejce'
        : status === 'done'
          ? 'Gotowe'
          : status === 'failed'
            ? 'Błąd'
            : 'Czekam…'
  const tone =
    status === 'failed'
      ? 'border-rose-200 bg-rose-50'
      : status === 'done'
        ? 'border-emerald-200 bg-emerald-50'
        : 'border-sky-200 bg-sky-50'

  useEffect(() => {
    const box = logRef.current
    if (box) {
      box.scrollTop = box.scrollHeight
    }
  }, [check?.lines.length])

  return (
    <div className={`fixed top-3 right-3 left-3 z-30 rounded-2xl border p-4 shadow-lg md:left-64 ${tone}`}>
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div>
          <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-600">
            Sprawdzanie domeny
          </p>
          <h3 className="text-sm font-semibold text-slate-900">
            {host}
            <span className="ml-2 text-[11px] font-medium text-slate-600">{label}</span>
          </h3>
        </div>
        <button
          type="button"
          onClick={onClose}
          className="rounded-lg border border-slate-300 bg-white px-2 py-1 text-[11px] font-semibold text-slate-700 hover:bg-slate-50"
        >
          Zamknij
        </button>
      </div>
      <div
        ref={logRef}
        className="mt-3 max-h-48 overflow-auto rounded-lg border border-white/70 bg-white/80 px-3 py-2 font-mono text-[11px] leading-5 text-slate-800"
      >
        {(check?.lines ?? []).length === 0 ? (
          <p className="text-slate-500">Czekam na pierwsze linie logu…</p>
        ) : (
          (check?.lines ?? []).map((line, i) => (
            <p key={`${line.at}-${i}`}>
              <span className="mr-2 text-slate-400">{formatClock(line.at)}</span>
              {line.text}
            </p>
          ))
        )}
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
