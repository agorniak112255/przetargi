import { useCallback, useEffect, useRef, useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { useAuth } from '../auth'
import {
  BTN,
  BTN_PRIMARY,
  BTN_SM,
  CampaignStatusChip,
  CampaignsTabs,
  DropBar,
  ErrorBar,
  Pager,
} from '../components/CampaignsUi'
import { campaignsTabFromParam, errorText, fmtDateTime, fmtInt } from '../lib/campaignFormat'
import { can } from '../lib/api'
import {
  CAMPAIGN_STATUS_LABEL,
  createCampaign,
  duplicateCampaign,
  formatPln,
  listCampaigns,
  type CampaignListRow,
  type CampaignStatus,
  type PageMeta,
} from '../lib/campaigns'
import { plural } from '../lib/plural'
import { CampaignTemplates } from './CampaignTemplates'
import { EmailSuppressions } from './EmailSuppressions'
import { MailingLists } from './MailingLists'

/**
 * Kampanie: lista kampanii (Moje / Wszystkie), a w zakładkach szablony maili, grupy odbiorców i lista wypisanych.
 * Zakładka w adresie (?tab=), żeby link i „wstecz” wracały tam, gdzie był handlowiec.
 */

const REFRESH_SENDING_MS = 15_000
const STATUSES: CampaignStatus[] = ['draft', 'scheduled', 'sending', 'sent', 'cancelled']

export function Campaigns() {
  const [params] = useSearchParams()
  const { user } = useAuth()
  let tab = campaignsTabFromParam(params.get('tab'))
  if (tab === 'all' && !can(user, 'campaigns.manage')) tab = 'mine'

  return (
    <div>
      {tab === 'templates' ? (
        <CampaignTemplates />
      ) : tab === 'groups' ? (
        <MailingLists />
      ) : tab === 'suppressed' ? (
        <EmailSuppressions />
      ) : (
        <CampaignList key={tab} scope={tab === 'all' ? 'all' : 'mine'} />
      )}
    </div>
  )
}

function CampaignList({ scope }: { scope: 'mine' | 'all' }) {
  const navigate = useNavigate()
  const [status, setStatus] = useState<CampaignStatus | ''>('')
  const [page, setPage] = useState(1)
  const [rows, setRows] = useState<CampaignListRow[]>([])
  const [meta, setMeta] = useState<PageMeta | null>(null)
  const [loading, setLoading] = useState(true)
  const [err, setErr] = useState('')
  const [busy, setBusy] = useState(false)
  const seq = useRef(0)

  const load = useCallback(
    async (quiet = false) => {
      const my = ++seq.current
      if (!quiet) setLoading(true)
      try {
        const res = await listCampaigns({ scope, status, page })
        if (my !== seq.current) return
        setRows(res.data)
        setMeta(res.meta)
        setErr('')
      } catch (ex) {
        if (my === seq.current) setErr(errorText(ex, 'Nie udało się wczytać kampanii.'))
      } finally {
        if (my === seq.current) setLoading(false)
      }
    },
    [scope, status, page],
  )

  useEffect(() => {
    void load()
  }, [load])

  // Trwa wysyłka — lista odświeża się sama (postęp x/y), tylko przy widocznej karcie.
  const anySending = rows.some((r) => r.status === 'sending')
  useEffect(() => {
    if (!anySending) return
    const t = window.setInterval(() => {
      if (document.visibilityState === 'visible') void load(true)
    }, REFRESH_SENDING_MS)
    return () => window.clearInterval(t)
  }, [anySending, load])

  async function newEmpty() {
    setBusy(true)
    setErr('')
    try {
      const c = await createCampaign({})
      navigate(`/kampanie/${c.id}`)
    } catch (ex) {
      setErr(errorText(ex, 'Nie udało się założyć kampanii.'))
      setBusy(false)
    }
  }

  async function duplicate(id: number) {
    setBusy(true)
    setErr('')
    try {
      const c = await duplicateCampaign(id)
      navigate(`/kampanie/${c.id}`)
    } catch (ex) {
      setErr(errorText(ex, 'Nie udało się zduplikować kampanii.'))
      setBusy(false)
    }
  }

  // Kafle liczone z wierszy tej strony listy — API nie podaje sum dla całej listy.
  const sentRows = rows.filter((r) => r.status === 'sent')
  const drafts = rows.filter((r) => r.status === 'draft').length
  const sending = rows.filter((r) => r.status === 'sending').length
  const mailsSent = rows.reduce((s, r) => s + (r.sent ?? 0), 0)
  const mailsFailed = rows.reduce((s, r) => s + (r.failed ?? 0), 0)
  const drops = rows.map((r) => r.result?.drop_percent).filter((v): v is number => v != null)
  const avgDrop = drops.length > 0 ? drops.reduce((s, v) => s + v, 0) / drops.length : null
  const salesRows = rows.filter((r) => r.sales != null)
  const salesValue = salesRows.reduce((s, r) => s + (r.sales?.net_value ?? 0), 0)
  const salesCustomers = salesRows.reduce((s, r) => s + (r.sales?.customers ?? 0), 0)
  const pageNote = meta && meta.last_page > 1 ? 'na tej stronie listy' : 'na liście'

  return (
    <>
      <div className="app-page-head mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="app-page-title text-xl font-semibold">Kampanie</h1>
          <p className="mt-1 max-w-3xl text-xs text-slate-600">
            {scope === 'all' ? 'Kampanie wszystkich handlowców.' : 'Twoje kampanie.'} Wynik to przede wszystkim to, ile
            towaru zeszło z magazynu po wysyłce.
          </p>
        </div>
        <div className="flex flex-wrap gap-2">
          <button type="button" className={BTN} disabled={busy} onClick={() => void newEmpty()}>
            + Pusta kampania
          </button>
          <Link to="/zapasy" className={BTN_PRIMARY}>
            + Nowa kampania z Zapasów
          </Link>
        </div>
      </div>

      <CampaignsTabs active={scope} />

      <ErrorBar message={err} onClose={() => setErr('')} />

      <div className="app-kpis mb-4 grid gap-2 sm:grid-cols-3 xl:grid-cols-6">
        <Kpi value={fmtInt(sentRows.length)} label={`Kampanie wysłane ${pageNote}`} />
        <Kpi value={fmtInt(sending)} label="W trakcie wysyłki" tone={sending > 0 ? 'attention' : undefined} />
        <Kpi value={fmtInt(drafts)} label="Projekty (niewysłane)" />
        <Kpi
          value={fmtInt(mailsSent)}
          label={`Maile wysłane, ${fmtInt(mailsFailed)} ${plural(mailsFailed, 'błąd', 'błędy', 'błędów')}`}
          tone={mailsFailed > 0 ? 'alert' : undefined}
        />
        <Kpi
          value={avgDrop != null ? `${Math.round(avgDrop) > 0 ? '−' : ''}${Math.round(avgDrop)}%` : '—'}
          label={
            avgDrop != null
              ? `Średnio zeszło ze stanu (${drops.length} ${plural(drops.length, 'kampania', 'kampanie', 'kampanii')})`
              : 'Zeszło ze stanu — liczymy 7 i 30 dni po wysyłce'
          }
        />
        <Kpi
          value={salesRows.length > 0 ? formatPln(salesValue) : '—'}
          label={
            salesRows.length > 0
              ? `Kupili odbiorcy: ${fmtInt(salesCustomers)} ${plural(salesCustomers, 'firma', 'firmy', 'firm')} (netto, 30 dni po wysyłce)`
              : 'Kupili odbiorcy — z faktur XL po wysyłce'
          }
        />
      </div>

      <div className="overflow-x-auto rounded-xl bg-white p-4 shadow-sm">
        <div className="mb-2 flex flex-wrap items-center justify-between gap-2 text-xs">
          <label className="inline-flex items-center gap-1.5 text-slate-600">
            Status
            <select
              className="rounded border border-slate-300 bg-white px-1.5 py-1 text-xs"
              value={status}
              onChange={(e) => {
                setStatus(e.target.value as CampaignStatus | '')
                setPage(1)
              }}
            >
              <option value="">wszystkie</option>
              {STATUSES.map((s) => (
                <option key={s} value={s}>
                  {CAMPAIGN_STATUS_LABEL[s]}
                </option>
              ))}
            </select>
          </label>
          <span className="text-slate-500">
            {meta ? `Łącznie ${fmtInt(meta.total)}` : ''}
            {loading ? ' · ładowanie…' : ''}
            {anySending ? ' · odświeża się co 15 s' : ''}
          </span>
        </div>
        <table className="w-full text-left text-xs">
          <thead>
            <tr className="border-b bg-slate-50">
              <th className="p-2">Kampania</th>
              {scope === 'all' && <th className="p-2">Autor</th>}
              <th className="p-2">Status</th>
              <th className="p-2">Wysyłka</th>
              <th className="p-2 text-right">Odbiorcy</th>
              <th className="p-2 text-right">Błędy</th>
              <th className="p-2">Zeszło z magazynu</th>
              <th className="p-2 text-right">Kupili odbiorcy</th>
              <th className="p-2" />
            </tr>
          </thead>
          <tbody>
            {rows.map((r) => (
              <CampaignRow key={r.id} row={r} showAuthor={scope === 'all'} busy={busy} onDuplicate={duplicate} />
            ))}
            {rows.length === 0 && (
              <tr>
                <td colSpan={scope === 'all' ? 8 : 7} className="p-8 text-center text-slate-500">
                  {loading ? (
                    'Ładowanie…'
                  ) : status ? (
                    'Brak kampanii o tym statusie.'
                  ) : (
                    <>
                      Nie ma jeszcze kampanii. Zaznacz towar w{' '}
                      <Link to="/zapasy" className="text-blue-600 hover:underline">
                        Zapasach
                      </Link>{' '}
                      i wybierz „Dodaj do kampanii” albo załóż pustą kampanię.
                    </>
                  )}
                </td>
              </tr>
            )}
          </tbody>
        </table>
        <Pager meta={meta} disabled={loading} onPage={setPage} />
        <p className="mt-2 text-[11px] text-slate-500">
          Kafle nad tabelą liczone są z kampanii {pageNote}. „Zeszło z magazynu” = spadek stanu pozycji kampanii
          (magazyny handlowe) od wysyłki: po 30 dniach, a do tego czasu po 7 dniach. Spadek stanu nie dowodzi, że
          towar sprzedała kampania.
        </p>
      </div>
    </>
  )
}

function Kpi({ value, label, tone }: { value: string; label: string; tone?: 'alert' | 'attention' }) {
  return (
    <div className="app-kpi rounded-xl bg-white px-3 py-2 shadow-sm" data-tone={tone}>
      <b
        className={`app-kpi-value block text-xl font-semibold tabular-nums ${
          tone === 'alert' ? 'text-amber-800' : tone === 'attention' ? 'text-blue-700' : 'text-slate-800'
        }`}
      >
        {value}
      </b>
      <span className="app-kpi-label text-xs text-slate-500">{label}</span>
    </div>
  )
}

function CampaignRow({
  row,
  showAuthor,
  busy,
  onDuplicate,
}: {
  row: CampaignListRow
  showAuthor: boolean
  busy: boolean
  onDuplicate: (id: number) => void
}) {
  const when = row.sent_at ?? row.sending_started_at
  const result = row.result
  return (
    <tr className="border-b align-top">
      <td className="min-w-[14rem] p-2">
        <Link to={`/kampanie/${row.id}`} className="font-medium text-slate-900 hover:text-blue-700 hover:underline">
          {row.name}
        </Link>
        <div className="text-[11px] text-slate-500">
          <span className="app-code font-mono">{row.code}</span>
          {' · '}
          {row.items_count} {plural(row.items_count, 'pozycja', 'pozycje', 'pozycji')}
          {row.stock_value != null && ` · ${formatPln(row.stock_value)} zapasu`}
        </div>
      </td>
      {showAuthor && <td className="whitespace-nowrap p-2 text-slate-700">{row.author.name}</td>}
      <td className="whitespace-nowrap p-2">
        <CampaignStatusChip status={row.status} sent={row.sent} total={row.recipients_total} />
      </td>
      <td className="whitespace-nowrap p-2 tabular-nums text-slate-700">
        {row.status === 'scheduled' && row.scheduled_at ? (
          <span className="text-blue-700" title="Wyśle się sama o tej godzinie">
            zaplanowana na {fmtDateTime(row.scheduled_at)}
          </span>
        ) : when ? (
          fmtDateTime(when)
        ) : (
          '—'
        )}
      </td>
      <td className="p-2 text-right tabular-nums">
        {row.status === 'draft' || row.status === 'scheduled' ? '—' : fmtInt(row.recipients_total)}
        {(row.clicked ?? 0) > 0 && <span className="block text-[10px] text-blue-700">kliknęło {fmtInt(row.clicked)}</span>}
        {(row.replies ?? 0) > 0 && <span className="block text-[10px] text-emerald-700">odpowiedzi {fmtInt(row.replies)}</span>}
      </td>
      <td className={`p-2 text-right tabular-nums ${row.failed > 0 ? 'font-medium text-red-700' : ''}`}>
        {row.status === 'draft' ? '—' : fmtInt(row.failed)}
      </td>
      <td className="whitespace-nowrap p-2">
        {result?.drop_percent != null ? (
          <span
            title={`Stan przy wysyłce: ${fmtInt(result.stock_at_send)} · po 7 dniach: ${fmtInt(result.stock_after_7d)} · po 30 dniach: ${fmtInt(result.stock_after_30d)}`}
          >
            <DropBar percent={result.drop_percent} suffix={result.stock_after_30d != null ? ' (30 dni)' : ' (7 dni)'} />
          </span>
        ) : row.status === 'sent' || row.status === 'sending' ? (
          <span className="text-slate-500">liczymy 7 dni po wysyłce</span>
        ) : (
          <span className="text-slate-400">—</span>
        )}
      </td>
      <td className="whitespace-nowrap p-2 text-right tabular-nums">
        {row.sales ? (
          <span title={row.sales.complete ? 'Okres 30 dni zamknięty' : 'Okres 30 dni od wysyłki trwa'}>
            {row.sales.customers > 0 ? (
              <>
                <b className="font-semibold text-emerald-700">{formatPln(row.sales.net_value)}</b>
                <span className="block text-[10px] text-slate-500">
                  {fmtInt(row.sales.customers)} {plural(row.sales.customers, 'firma', 'firmy', 'firm')}
                </span>
              </>
            ) : (
              <span className="text-slate-500">nikt{row.sales.complete ? '' : ' (na razie)'}</span>
            )}
          </span>
        ) : (
          <span className="text-slate-400">—</span>
        )}
      </td>
      <td className="whitespace-nowrap p-2 text-right">
        <span className="inline-flex gap-1">
          <Link to={`/kampanie/${row.id}`} className={BTN_SM}>
            {row.status === 'draft' ? 'Edytuj' : 'Otwórz'}
          </Link>
          <button type="button" className={BTN_SM} disabled={busy} onClick={() => onDuplicate(row.id)}>
            Duplikuj
          </button>
        </span>
      </td>
    </tr>
  )
}
