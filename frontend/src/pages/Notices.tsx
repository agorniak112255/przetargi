import { useCallback, useEffect, useRef, useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { useAuth } from '../auth'
import { NoticeDetailsPanel, type NoticeDocumentSelection } from '../components/notice/NoticeDetailsPanel'
import {
  ApiError,
  can,
  createTenderFromNotice,
  fetchNotices,
  skipNotice,
  unskipNotice,
  type CreateTenderFromNoticeResult,
  type NoticeClientCandidate,
  type NoticeListSource,
  type NoticeRow,
  type NoticesResponse,
  type NoticeTab,
} from '../lib/api'
import { errorText, fmtDate, fmtDateTime } from '../lib/campaignFormat'
import { plural } from '../lib/plural'
import { setTenderWizardActive, setTenderWizardHandoff } from '../lib/tenderWizard'

const TABS: { key: NoticeTab; label: string }[] = [
  { key: 'new', label: 'Nowe' },
  { key: 'created', label: 'Założone jako przetarg' },
  { key: 'skipped', label: 'Pominięte' },
]

const SOURCE_LABEL: Record<NoticeListSource, string> = {
  bzp: 'Biuletyn Zamówień Publicznych',
}

/** Tytuł przetargu z przedmiotu zamówienia — backend przycina go do 255 znaków. */
const TITLE_LIMIT = 255

function tabFromParam(value: string | null): NoticeTab {
  return value === 'created' || value === 'skipped' ? value : 'new'
}

function externalLink(href: string, label: string) {
  return (
    <a href={href} target="_blank" rel="noopener noreferrer" className="whitespace-nowrap text-blue-700 hover:underline">
      {label} ↗
    </a>
  )
}

/**
 * Ogłoszenia o zamówieniu z Biuletynu Zamówień Publicznych (bzp:fetch codziennie o 6:30) z kodami rodzaju zamówienia
 * (CPV) na odzież, obuwie i środki ochrony. Zakładki: „Nowe” (bez decyzji i bez przetargu), „Założone jako przetarg”,
 * „Pominięte” (decyzja wspólna dla zespołu). Filtry żyją w adresie strony; spóźnione odpowiedzi są przerywane.
 */
export function Notices() {
  const { user } = useAuth()
  const navigate = useNavigate()
  const [params, setParams] = useSearchParams()
  const canCreate = can(user, 'tenders.create')

  const tab = tabFromParam(params.get('tab'))
  const category = params.get('category') ?? ''
  const province = params.get('province') ?? ''
  const q = params.get('q') ?? ''
  const past = params.get('past') === '1'
  const page = Math.max(1, Number(params.get('page')) || 1)

  const [list, setList] = useState<NoticesResponse | null>(null)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const [reloadKey, setReloadKey] = useState(0)
  const [rowBusy, setRowBusy] = useState<number | null>(null)
  const [actionErr, setActionErr] = useState('')
  const [flash, setFlash] = useState<{ text: string; undo: NoticeRow | null } | null>(null)
  /** okno „Załóż przetarg”; selection — dokumenty wybrane w szczegółach ogłoszenia (null — z listy, bez dokumentów) */
  const [confirm, setConfirm] = useState<{ row: NoticeRow; selection: NoticeDocumentSelection | null } | null>(null)
  const [detailsRow, setDetailsRow] = useState<NoticeRow | null>(null)

  const setFilters = useCallback(
    (patch: Record<string, string | null>) => {
      setParams(
        (prev) => {
          const next = new URLSearchParams(prev)
          for (const [key, value] of Object.entries(patch)) {
            if (value === null || value === '') next.delete(key)
            else next.set(key, value)
          }
          return next
        },
        { replace: true },
      )
    },
    [setParams],
  )

  // Szukanie z opóźnieniem: pole ma własny stan, do adresu trafia po ~300 ms.
  const [qDraft, setQDraft] = useState(q)
  const pushedQ = useRef(q)
  useEffect(() => {
    if (q !== pushedQ.current) {
      pushedQ.current = q
      setQDraft(q)
    }
  }, [q])
  useEffect(() => {
    if (qDraft === q) return
    const t = window.setTimeout(() => {
      pushedQ.current = qDraft
      setFilters({ q: qDraft.trim() || null, page: null })
    }, 300)
    return () => window.clearTimeout(t)
  }, [qDraft, q, setFilters])

  useEffect(() => {
    const controller = new AbortController()
    setBusy(true)
    setErr('')
    fetchNotices(
      {
        tab,
        category: category || undefined,
        province: province || undefined,
        q: q || undefined,
        past: tab === 'new' && past,
        page,
      },
      controller.signal,
    )
      .then((res) => {
        if (!controller.signal.aborted) setList(res)
      })
      .catch((ex: unknown) => {
        if (controller.signal.aborted) return
        setErr(errorText(ex, 'Nie udało się wczytać ogłoszeń.'))
      })
      .finally(() => {
        if (!controller.signal.aborted) setBusy(false)
      })
    return () => controller.abort()
  }, [tab, category, province, q, past, page, reloadKey])

  // Po pominięciu ostatniego ogłoszenia na ostatniej stronie ta strona przestaje istnieć — cofnij na ostatnią.
  useEffect(() => {
    if (list && list.data.length === 0 && page > list.meta.last_page && list.meta.last_page >= 1) {
      setFilters({ page: list.meta.last_page > 1 ? String(list.meta.last_page) : null })
    }
  }, [list, page, setFilters])

  async function toggleSkip(row: NoticeRow, skip: boolean): Promise<boolean> {
    setRowBusy(row.id)
    setActionErr('')
    setFlash(null)
    try {
      await (skip ? skipNotice(row.id) : unskipNotice(row.id))
      setFlash(
        skip
          ? { text: `Pominięto ogłoszenie ${row.notice_number} — jest teraz w zakładce „Pominięte” u całego zespołu.`, undo: row }
          : { text: `Przywrócono ogłoszenie ${row.notice_number}.`, undo: null },
      )
      setReloadKey((k) => k + 1)
      return true
    } catch (ex) {
      setActionErr(errorText(ex, skip ? 'Nie udało się pominąć ogłoszenia.' : 'Nie udało się przywrócić ogłoszenia.'))
      return false
    } finally {
      setRowBusy(null)
    }
  }

  const rows = list?.data ?? []
  const meta = list?.meta
  const lastPage = Math.max(1, meta?.last_page ?? 1)
  const total = meta?.total ?? 0
  const filtersActive = Boolean(category || province || q || past)

  function emptyText(): string {
    if (list && list.fetched_at === null) return 'Aplikacja nie pobrała jeszcze żadnych ogłoszeń z Biuletynu Zamówień Publicznych.'
    if (filtersActive) return 'Brak ogłoszeń dla tych filtrów.'
    if (tab === 'created') return 'Z żadnego ogłoszenia nie założono jeszcze przetargu.'
    if (tab === 'skipped') return 'Nikt nie pominął jeszcze żadnego ogłoszenia.'
    return 'Brak nowych ogłoszeń z terminem składania w przyszłości.'
  }

  return (
    <div>
      <div className="app-page-head mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="app-page-title text-xl font-semibold">Ogłoszenia</h1>
          <p className="text-xs text-slate-500">
            Ogłoszenia o zamówieniu z kodami rodzaju zamówienia (CPV) na odzież, obuwie i środki ochrony. Biuletyn
            Zamówień Publicznych jest sprawdzany codziennie o 6:30.
            {list?.fetched_at ? ` Ostatnie pobranie z Biuletynu: ${fmtDateTime(list.fetched_at)}.` : ''}
          </p>
        </div>
      </div>

      <div className="app-card mb-4 rounded-xl bg-white p-4 text-xs shadow-sm" title={list?.source_note || undefined}>
        <div className="grid gap-2 sm:grid-cols-3">
          <div className="rounded-lg border border-slate-200 p-2.5">
            <div className="font-semibold text-slate-900">Biuletyn Zamówień Publicznych</div>
            <div className="text-slate-500">pobierany — ogłoszenia krajowe od 130 000 zł</div>
          </div>
          <div className="rounded-lg border border-slate-200 p-2.5">
            <div className="font-semibold text-slate-900">Dziennik Urzędowy Unii Europejskiej (TED)</div>
            <div className="text-slate-500">nie jest pobierany</div>
          </div>
          <div className="rounded-lg bg-slate-50 p-2.5">
            <div className="font-semibold text-slate-600">Poza zasięgiem</div>
            <div className="text-slate-500">zamówienia poniżej 130 000 zł — nie mają wspólnego źródła</div>
          </div>
        </div>
      </div>

      {flash && (
        <div className="mb-2 flex flex-wrap items-center gap-2 rounded bg-green-50 px-3 py-2 text-xs text-green-800" role="status">
          <span>{flash.text}</span>
          {flash.undo && (
            <button
              type="button"
              className="font-medium underline"
              disabled={rowBusy !== null}
              onClick={() => flash.undo && void toggleSkip(flash.undo, false)}
            >
              Cofnij
            </button>
          )}
        </div>
      )}
      {actionErr && (
        <p className="mb-2 rounded bg-red-50 px-3 py-2 text-xs text-red-700" role="alert">
          {actionErr}
        </p>
      )}

      <div className="app-card rounded-xl bg-white p-4 shadow-sm">
        <div className="mb-3 flex flex-wrap items-center gap-1.5 text-xs" role="group" aria-label="Rodzaj zamówienia">
          <span className="mr-1 text-slate-500">Rodzaj zamówienia:</span>
          {[{ key: '', label: 'Wszystkie' }, ...(list?.categories ?? [])].map((c) => {
            const on = c.key === category
            return (
              <button
                key={c.key || 'all'}
                type="button"
                aria-pressed={on}
                onClick={() => setFilters({ category: c.key || null, page: null })}
                className={`app-chip rounded-full border px-2.5 py-1 ${
                  on
                    ? 'app-chip--active border-blue-600 bg-blue-600 text-white'
                    : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50'
                }`}
              >
                {c.label}
              </button>
            )
          })}
        </div>

        <div className="mb-3 flex flex-wrap items-end gap-2">
          <label className="block text-xs">
            Szukaj
            <input
              type="search"
              className="mt-1 block w-72 max-w-full rounded border border-slate-300 px-2 py-1.5 text-sm"
              value={qDraft}
              onChange={(e) => setQDraft(e.target.value)}
              placeholder="przedmiot, zamawiający, miasto, numer ogłoszenia…"
            />
          </label>
          <label className="block text-xs">
            Województwo
            <select
              className="mt-1 block rounded border border-slate-300 px-2 py-1.5 text-sm"
              value={province}
              onChange={(e) => setFilters({ province: e.target.value || null, page: null })}
            >
              <option value="">Wszystkie województwa</option>
              {(list?.provinces ?? []).map((p) => (
                <option key={p.code} value={p.code}>
                  {p.name}
                </option>
              ))}
            </select>
          </label>
          {tab === 'new' && (
            <label className="mb-1.5 inline-flex items-center gap-1.5 text-xs">
              <input
                type="checkbox"
                checked={past}
                onChange={(e) => setFilters({ past: e.target.checked ? '1' : null, page: null })}
              />
              Pokaż też ogłoszenia po terminie składania
            </label>
          )}
          {filtersActive && (
            <button
              type="button"
              onClick={() => {
                setQDraft('')
                pushedQ.current = ''
                setFilters({ category: null, province: null, q: null, past: null, page: null })
              }}
              className="rounded border border-slate-300 px-2.5 py-1.5 text-xs text-slate-700 hover:bg-slate-50"
            >
              Wyczyść filtry
            </button>
          )}
        </div>

        <nav className="app-tabs mb-3 flex flex-wrap gap-1 border-b border-slate-200" aria-label="Ogłoszenia">
          {TABS.map((t) => {
            const on = t.key === tab
            const count = list?.counts[t.key]
            return (
              <button
                key={t.key}
                type="button"
                aria-current={on ? 'page' : undefined}
                onClick={() => {
                  if (on) return
                  setFlash(null)
                  // „pokaż po terminie” dotyczy tylko zakładki „Nowe”
                  setFilters({ tab: t.key === 'new' ? null : t.key, page: null, past: null })
                }}
                className={`app-tab -mb-px border-b-2 px-3 py-2 text-sm ${
                  on ? 'app-tab--active border-blue-600 font-semibold text-blue-700' : 'border-transparent text-slate-600 hover:text-slate-900'
                }`}
              >
                {t.label}
                {count !== undefined && <span className="app-tab-count ml-1.5 text-[11px] text-slate-500">{count.toLocaleString('pl-PL')}</span>}
              </button>
            )
          })}
        </nav>

        {err && (
          <p className="mb-3 rounded bg-red-50 px-3 py-2 text-xs text-red-700" role="alert">
            {err}{' '}
            <button type="button" className="font-medium underline" onClick={() => setReloadKey((k) => k + 1)}>
              Spróbuj ponownie
            </button>
          </p>
        )}

        {rows.length === 0 && !busy ? (
          !err && <p className="py-4 text-xs text-slate-500">{emptyText()}</p>
        ) : (
          <div className={`-mx-4 overflow-x-auto sm:mx-0 ${busy ? 'opacity-60' : ''}`} aria-busy={busy}>
            <table className="app-table w-full min-w-[64rem] text-left text-xs">
              <thead>
                <tr className="border-b bg-slate-50 text-[11px] tracking-wide text-slate-500 uppercase">
                  <th className="p-2 font-medium">Zamawiający i przedmiot</th>
                  <th className="p-2 font-medium">Termin składania</th>
                  <th className="p-2 font-medium">Czego dotyczy</th>
                  <th className="p-2 font-medium">Wartość z ogłoszenia</th>
                  <th className="p-2 font-medium">Dokumenty</th>
                  <th className="p-2" />
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => (
                  <NoticeTableRow
                    key={row.id}
                    row={row}
                    tab={tab}
                    canCreate={canCreate}
                    busy={rowBusy === row.id}
                    onCreate={() => setConfirm({ row, selection: null })}
                    onDetails={() => setDetailsRow(row)}
                    onSkip={(skip) => void toggleSkip(row, skip)}
                  />
                ))}
              </tbody>
            </table>
          </div>
        )}

        <div className="mt-3 flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-3">
          <p className="text-xs text-slate-500">
            {busy && !list
              ? 'Wczytuję…'
              : `Strona ${meta?.page ?? page} z ${lastPage} · ${total.toLocaleString('pl-PL')} ${plural(total, 'ogłoszenie', 'ogłoszenia', 'ogłoszeń')}`}
          </p>
          <nav className="flex items-center gap-1" aria-label="Strony listy">
            <button
              type="button"
              disabled={page <= 1 || busy}
              onClick={() => setFilters({ page: page - 1 > 1 ? String(page - 1) : null })}
              className="rounded border border-slate-300 px-2.5 py-1.5 text-xs disabled:opacity-40"
            >
              ← Poprzednia
            </button>
            <button
              type="button"
              disabled={page >= lastPage || busy}
              onClick={() => setFilters({ page: String(Math.min(lastPage, page + 1)) })}
              className="rounded border border-slate-300 px-2.5 py-1.5 text-xs disabled:opacity-40"
            >
              Następna →
            </button>
          </nav>
        </div>

        <p className="mt-3 rounded bg-slate-50 px-3 py-2 text-xs text-slate-600">
          „Szczegóły” (albo kliknięcie przedmiotu zamówienia) pokazuje treść ogłoszenia, części z opisami i dokumenty
          postępowania. „Załóż przetarg” otwiera kreator z wypełnionym tytułem, zamawiającym, terminem składania z
          godziną i numerem ogłoszenia.
          {can(user, 'tenders.import')
            ? ' Gdy ogłoszenie wymienia towary i ilości, kreator odczyta je z jego treści; pełną listę pozycji mają zwykle dokumenty — w szczegółach wybierz je z listy e-Zamówień albo dodaj pliki pobrane ze strony postępowania. Odczytane pozycje kreator pokaże do sprawdzenia.'
            : ' Odczyt pozycji z ogłoszenia i dokumentów w kreatorze wymaga uprawnienia „Dodawanie dokumentów” — poproś o nie administratora.'} „Pomiń” chowa ogłoszenie z zakładki „Nowe” u całego zespołu.
        </p>
      </div>

      {detailsRow && (
        <NoticeDetailsPanel
          row={detailsRow}
          canCreate={canCreate}
          canImport={can(user, 'tenders.import')}
          inactive={confirm !== null}
          busy={rowBusy === detailsRow.id}
          onClose={() => setDetailsRow(null)}
          onCreate={(row, selection) => setConfirm({ row, selection })}
          onSkip={(row, skip) => {
            void toggleSkip(row, skip).then((ok) => {
              if (ok) setDetailsRow(null)
            })
          }}
        />
      )}

      {confirm && (
        <CreateTenderDialog
          row={confirm.row}
          selection={confirm.selection}
          canImport={can(user, 'tenders.import')}
          onClose={() => setConfirm(null)}
          onCreated={(id, res) => {
            const chosen = confirm.selection?.documents ?? []
            // serwer zwraca dokumenty potwierdzone jako należące do postępowania — kreator pobierze tylko te
            const confirmed = res.document_ids ? chosen.filter((d) => res.document_ids?.includes(d.id)) : chosen
            setTenderWizardHandoff(id, {
              noticeNumber: confirm.row.notice_number,
              procedureUrl: confirm.row.procedure_url,
              noticeDocuments: confirmed,
              files: confirm.selection?.files ?? [],
              // bez dokumentów kreator odczyta towary z treści ogłoszenia (przy dokumentach — przyciskiem)
              noticeText: true,
            })
            setTenderWizardActive(id, true)
            navigate(`/tenders/${id}`)
          }}
          onConflict={() => setReloadKey((k) => k + 1)}
          onOpenExisting={(id) => {
            const chosen = (confirm.selection?.documents.length ?? 0) + (confirm.selection?.files.length ?? 0)
            if (
              chosen > 0 &&
              !window.confirm(
                'Wybrane dokumenty nie zostaną przeniesione do istniejącego przetargu — dodasz je tam w zakładce „Dokumenty”. Przejść do przetargu?',
              )
            )
              return
            navigate(`/tenders/${id}`)
          }}
        />
      )}
    </div>
  )
}

function NoticeTableRow({
  row,
  tab,
  canCreate,
  busy,
  onCreate,
  onDetails,
  onSkip,
}: {
  row: NoticeRow
  tab: NoticeTab
  canCreate: boolean
  busy: boolean
  onCreate: () => void
  onDetails: () => void
  onSkip: (skip: boolean) => void
}) {
  const org = row.organization
  const place = [org.city, org.province_name].filter(Boolean).join(' · ')
  return (
    <tr className="border-b border-slate-100 align-top hover:bg-slate-50">
      <td className="max-w-[30rem] p-2">
        <div className="font-semibold text-slate-900">{org.name ?? 'Zamawiający nie podany'}</div>
        {place && <div className="text-[11px] text-slate-500">{place}</div>}
        <button
          type="button"
          onClick={onDetails}
          className="mt-1 block text-left text-slate-800 hover:text-blue-700 hover:underline"
          title="Pokaż szczegóły ogłoszenia"
        >
          {row.order_object ?? 'Przedmiot zamówienia nie podany'}
        </button>
        <div className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-[11px] text-slate-500">
          <span className="rounded-full bg-slate-100 px-1.5 py-0.5 text-slate-600">{SOURCE_LABEL[row.source] ?? row.source}</span>
          <span className="app-code">{row.notice_number}</span>
          {row.lots_count > 1 && <span>{`${row.lots_count} ${plural(row.lots_count, 'część', 'części', 'części')}`}</span>}
          {row.published_at && <span>opublikowano {fmtDate(row.published_at)}</span>}
        </div>
      </td>
      <td className="whitespace-nowrap p-2">
        {row.deadline_local ?? <span className="text-slate-400">nie podano</span>}
        {row.past && (
          <div className="mt-1">
            <span className="rounded bg-amber-50 px-1.5 py-0.5 text-[11px] text-amber-800">po terminie</span>
          </div>
        )}
      </td>
      <td className="p-2" title={row.cpv_codes.length ? `Kody rodzaju zamówienia (CPV): ${row.cpv_codes.join(', ')}` : undefined}>
        {row.categories.length > 0 ? (
          <div className="flex flex-wrap gap-1">
            {row.categories.map((c) => (
              <span key={c} className="rounded-full bg-slate-100 px-2 py-0.5 text-slate-700">
                {c}
              </span>
            ))}
          </div>
        ) : (
          <span className="text-slate-400">inny rodzaj</span>
        )}
      </td>
      <td className="whitespace-nowrap p-2">
        {/* serwer podaje gotowy tekst z ogłoszenia („1 365 178,85 PLN”) — bez przeliczania */}
        {row.total_value ?? <span className="text-slate-400">nie podano</span>}
      </td>
      <td className="p-2">
        {row.procedure_url || row.notice_url ? (
          <div className="space-y-1">
            {row.procedure_url && <div>{externalLink(row.procedure_url, 'strona postępowania')}</div>}
            {row.notice_url && <div>{externalLink(row.notice_url, 'ogłoszenie w Biuletynie')}</div>}
          </div>
        ) : (
          <span className="text-slate-400">—</span>
        )}
      </td>
      <td className="p-2">
        <div className="flex flex-col items-end gap-1.5">
          {row.tender &&
            (row.tender.can_open ? (
              <Link to={`/tenders/${row.tender.id}`} className="app-code whitespace-nowrap font-medium text-blue-700 hover:underline">
                Przetarg {row.tender.number}
              </Link>
            ) : (
              <span className="whitespace-nowrap text-slate-600" title="Nie masz dostępu do tego przetargu">
                Przetarg {row.tender.number}
              </span>
            ))}
          {row.skipped && (
            <span className="text-right text-[11px] text-slate-500">
              pominięte{row.skipped.by ? ` przez: ${row.skipped.by.name}` : ''}
              {row.skipped.at ? `, ${fmtDateTime(row.skipped.at)}` : ''}
            </span>
          )}
          <div className="flex flex-wrap justify-end gap-1.5">
            <button
              type="button"
              onClick={onDetails}
              className="whitespace-nowrap rounded border border-slate-300 bg-white px-2.5 py-1 font-medium text-slate-700 hover:bg-slate-50"
            >
              Szczegóły
            </button>
            {canCreate && !row.tender && tab === 'new' && (
              <button
                type="button"
                disabled={busy}
                onClick={onCreate}
                className="whitespace-nowrap rounded bg-blue-600 px-2.5 py-1 font-medium text-white hover:bg-blue-700 disabled:opacity-50"
              >
                Załóż przetarg
              </button>
            )}
            {row.skipped ? (
              <button
                type="button"
                disabled={busy}
                onClick={() => onSkip(false)}
                className="whitespace-nowrap rounded border border-slate-300 bg-white px-2.5 py-1 font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50"
              >
                Przywróć
              </button>
            ) : (
              tab === 'new' && (
                <button
                  type="button"
                  disabled={busy}
                  onClick={() => onSkip(true)}
                  className="whitespace-nowrap rounded border border-slate-300 bg-white px-2.5 py-1 font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50"
                >
                  Pomiń
                </button>
              )
            )}
          </div>
        </div>
      </td>
    </tr>
  )
}

const MATCHED_BY_TEXT: Record<'nip' | 'nip_name' | 'name', string> = {
  nip: ' (ten sam NIP).',
  nip_name: ' (ten sam NIP i ta sama nazwa).',
  name: ' (ta sama nazwa).',
}

function candidatesFrom(body: Record<string, unknown>): NoticeClientCandidate[] {
  const list = body.client_candidates
  if (!Array.isArray(list)) return []
  return list.filter(
    (c): c is NoticeClientCandidate =>
      typeof c === 'object' && c !== null && Number.isInteger((c as NoticeClientCandidate).id) && typeof (c as NoticeClientCandidate).name === 'string',
  )
}

/**
 * Potwierdzenie „Załóż przetarg”: co zostanie wypełnione i jak aplikacja dobierze zamawiającego. Podpowiedź pochodzi
 * z chwili wczytania listy; przy kilku pasujących klientach (z listy albo z odpowiedzi 422 serwera) zamawiającego
 * wybiera człowiek — bez wyboru przycisk „Załóż przetarg” jest nieaktywny.
 */
function CreateTenderDialog({
  row,
  selection,
  canImport,
  onClose,
  onCreated,
  onConflict,
  onOpenExisting,
}: {
  row: NoticeRow
  selection: NoticeDocumentSelection | null
  /** uprawnienie tenders.import — bez niego kreator nie odczyta pozycji z ogłoszenia ani dokumentów */
  canImport: boolean
  onClose: () => void
  onCreated: (tenderId: number, result: CreateTenderFromNoticeResult) => void
  onConflict: () => void
  onOpenExisting: (tenderId: number) => void
}) {
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const [existing, setExisting] = useState<{ id: number; number: string; canOpen: boolean } | null>(null)
  const [candidates, setCandidates] = useState<NoticeClientCandidate[]>(row.client_candidates ?? [])
  // kandydaci przyszli dopiero z odpowiedzi serwera (ktoś dopisał klienta po wczytaniu listy)
  const [candidatesFromServer, setCandidatesFromServer] = useState(false)
  const [chosenId, setChosenId] = useState<number | null>(null)
  const confirmRef = useRef<HTMLButtonElement | null>(null)
  const firstChoiceRef = useRef<HTMLInputElement | null>(null)
  const closeRef = useRef<HTMLButtonElement | null>(null)
  // Escape i kliknięcie obok okna zamykają je, ale nie w trakcie zakładania (odpowiedź serwera i tak przyjdzie).
  const dismissRef = useRef<() => void>(onClose)
  useEffect(() => {
    dismissRef.current = busy ? () => {} : onClose
  }, [busy, onClose])

  // Raz po otwarciu: fokus na wyborze klienta (gdy trzeba wybrać) albo na przycisku potwierdzenia; po zamknięciu
  // wraca tam, skąd okno otwarto.
  useEffect(() => {
    const opener = document.activeElement instanceof HTMLElement ? document.activeElement : null
    ;(firstChoiceRef.current ?? confirmRef.current)?.focus()
    function onKey(e: KeyboardEvent) {
      if (e.key === 'Escape' && !e.defaultPrevented) {
        e.preventDefault()
        dismissRef.current()
      }
    }
    window.addEventListener('keydown', onKey)
    return () => {
      window.removeEventListener('keydown', onKey)
      opener?.focus()
    }
  }, [])

  // Kandydaci z odpowiedzi serwera — fokus na pierwszym, żeby wybór był od razu pod klawiaturą.
  useEffect(() => {
    if (candidatesFromServer) firstChoiceRef.current?.focus()
  }, [candidatesFromServer])

  // Przetarg już jest, a nie masz do niego dostępu — przycisk potwierdzenia znika, fokus na „Zamknij”
  // (przy dostępie fokus bierze „Przejdź do istniejącego przetargu”).
  useEffect(() => {
    if (existing !== null && !existing.canOpen) closeRef.current?.focus()
  }, [existing])

  const needsChoice = candidates.length > 0 && chosenId === null

  async function confirm() {
    if (needsChoice) return
    setBusy(true)
    setErr('')
    try {
      const res = await createTenderFromNotice(row.id, {
        clientId: chosenId ?? undefined,
        documentIds: selection?.documents.map((d) => d.id),
      })
      onCreated(res.tender_id, res)
    } catch (ex) {
      if (ex instanceof ApiError && ex.status === 409 && Number.isInteger(Number(ex.body.tender_id)) && Number(ex.body.tender_id) > 0) {
        setExisting({
          id: Number(ex.body.tender_id),
          number: typeof ex.body.tender_number === 'string' ? ex.body.tender_number : '',
          canOpen: ex.body.can_open === true,
        })
        onConflict()
      } else if (ex instanceof ApiError && ex.status === 422 && candidatesFrom(ex.body).length > 0) {
        setCandidates(candidatesFrom(ex.body))
        setChosenId(null)
        setCandidatesFromServer(true)
      } else {
        setErr(errorText(ex, 'Nie udało się założyć przetargu.'))
      }
      setBusy(false)
    }
  }

  const title = row.order_object ?? ''
  const org = row.organization

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
      role="dialog"
      aria-modal="true"
      aria-labelledby="notice-tender-title"
      onClick={() => dismissRef.current()}
    >
      <div
        className="flex max-h-[90vh] w-full max-w-2xl flex-col overflow-hidden rounded-xl bg-white shadow-lg"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="border-b border-slate-200 px-4 py-3">
          <h2 id="notice-tender-title" className="text-sm font-semibold text-slate-900">
            Załóż przetarg z ogłoszenia
          </h2>
          <p className="text-xs text-slate-500">
            Przetarg powstanie jako szkic, a Ty zostaniesz jego opiekunem. Potem otworzy się kreator.
          </p>
        </div>

        <div className="min-h-0 flex-1 space-y-3 overflow-y-auto px-4 py-3 text-xs">
          {existing !== null ? (
            <p className="rounded bg-amber-50 px-3 py-2 text-amber-800" role="alert">
              Przetarg z tym postępowaniem już istnieje
              {existing.number ? <span className="app-code"> {existing.number}</span> : ''} — ktoś założył go w
              międzyczasie. Drugi nie został założony.
              {!existing.canOpen && ' Nie masz dostępu do tego przetargu — o zaproszenie poproś jego opiekuna.'}
            </p>
          ) : (
            <>
              <dl className="grid gap-x-3 gap-y-2 sm:grid-cols-[11rem_1fr]">
                <dt className="text-slate-500">Tytuł przetargu</dt>
                <dd className="text-slate-900">
                  {title ? (
                    <>
                      {title.length > TITLE_LIMIT ? `${title.slice(0, TITLE_LIMIT)}…` : title}
                      {title.length > TITLE_LIMIT && (
                        <span className="block text-slate-500">skrócony do {TITLE_LIMIT} znaków</span>
                      )}
                    </>
                  ) : (
                    <span className="text-slate-500">ogłoszenie nie podaje przedmiotu zamówienia</span>
                  )}
                </dd>
                <dt className="text-slate-500">Termin składania ofert</dt>
                <dd className="text-slate-900">
                  {row.deadline_local ? (
                    `${row.deadline_local} (czas polski)`
                  ) : (
                    <span className="text-slate-500">ogłoszenie nie podaje terminu — wpiszesz go w kreatorze</span>
                  )}
                </dd>
                <dt className="text-slate-500">Numer ogłoszenia</dt>
                <dd className="app-code text-slate-900">{row.notice_number}</dd>
                <dt className="text-slate-500">Zamawiający z ogłoszenia</dt>
                <dd className="text-slate-900">
                  {org.name ?? <span className="text-slate-500">nie podano</span>}
                  <span className="block text-slate-500">
                    {org.nip ? `NIP ${org.nip}` : 'ogłoszenie nie podaje NIP-u'}
                    {org.city ? ` · ${org.city}` : ''}
                  </span>
                </dd>
              </dl>
              <div className="rounded bg-slate-50 px-3 py-2 text-slate-700">
                <p className="font-medium text-slate-900">Zamawiający w przetargu</p>
                {candidates.length > 0 ? (
                  <fieldset className="mt-1">
                    <legend className="mb-1">
                      {candidatesFromServer
                        ? 'Od wczytania listy zmienili się klienci: do zamawiającego pasuje kilku. Wybierz, którego użyć:'
                        : 'W zakładce Klienci do zamawiającego pasuje kilku klientów. Wybierz, którego użyć:'}
                    </legend>
                    <div className="space-y-1">
                      {candidates.map((c, i) => (
                        <label
                          key={c.id}
                          className={`flex cursor-pointer items-start gap-2 rounded border px-2 py-1.5 ${
                            chosenId === c.id ? 'border-blue-600 bg-white' : 'border-slate-200 bg-white hover:border-slate-300'
                          }`}
                        >
                          <input
                            ref={i === 0 ? firstChoiceRef : undefined}
                            type="radio"
                            name="notice-tender-client"
                            className="mt-0.5"
                            value={c.id}
                            checked={chosenId === c.id}
                            disabled={busy}
                            onChange={() => setChosenId(c.id)}
                          />
                          <span>
                            <span className="font-medium text-slate-900">{c.name}</span>
                            <span className="block text-slate-500">
                              {c.nip ? `NIP ${c.nip}` : 'bez NIP-u'}
                              {c.city ? ` · ${c.city}` : ''}
                            </span>
                          </span>
                        </label>
                      ))}
                    </div>
                    <p className="mt-1 text-slate-500">
                      Aplikacja nie zgaduje i nie dopisuje nowego klienta, gdy pasuje kilku. Brakuje właściwego? Dopisz go
                      w zakładce Klienci i otwórz to okno ponownie.
                    </p>
                  </fieldset>
                ) : row.client_match ? (
                  <p className="mt-1">
                    Istniejący klient: <strong>{row.client_match.name}</strong>
                    {MATCHED_BY_TEXT[row.client_match.matched_by] ?? '.'}
                  </p>
                ) : (
                  <p className="mt-1">
                    W zakładce Klienci nie ma pasującego klienta — aplikacja dopisze nowego klienta z nazwą, NIP-em i
                    miastem z ogłoszenia.
                  </p>
                )}
                <p className="mt-1 text-slate-500">
                  Reguła: najpierw klient z tym samym NIP-em (gdy jest ich kilku — ten z tą samą nazwą), potem dokładnie
                  jeden z tą samą nazwą. Gdy pasuje kilku, wybierasz Ty. Stan sprawdzony przy wczytaniu listy — przy
                  zakładaniu aplikacja sprawdza go ponownie tą samą regułą.
                </p>
              </div>
              {selection && (selection.documents.length > 0 || selection.files.length > 0) ? (
                <div className="rounded bg-slate-50 px-3 py-2 text-slate-700">
                  <p className="font-medium text-slate-900">Dokumenty do odczytu</p>
                  {selection.documents.length > 0 && (
                    <p className="mt-1">
                      Z e-Zamówień ({selection.documents.length}): {selection.documents.map((d) => d.name).join('; ')}.
                      Kreator pobierze je po otwarciu, po jednym, i zapisze w archiwum dokumentów przetargu.
                    </p>
                  )}
                  {selection.files.length > 0 && (
                    <p className="mt-1">
                      Z komputera ({selection.files.length}): {selection.files.map((f) => f.name).join('; ')}. Kreator
                      odczyta je w kroku „Dokumenty” i zapisze w archiwum dokumentów przetargu (przy ustawieniu odczytu
                      „tylko tekst” w archiwum zostaje tylko plik Word).
                    </p>
                  )}
                  <p className="mt-1 text-slate-500">
                    Pierwszy dokument kreator odczyta sam, kolejne — przyciskiem przy dokumencie, gdy skończysz z
                    poprzednim podglądem. Odczytane pozycje i warunki są tylko podglądem — do przetargu trafią dopiero po
                    Twoim „Dodaj do przetargu”.
                  </p>
                </div>
              ) : (
                <p className="text-slate-600">
                  {canImport
                    ? 'Kreator odczyta towary z treści ogłoszenia, jeśli ogłoszenie je wymienia (podgląd do zatwierdzenia). Dokumenty dodasz w kreatorze (krok „Dokumenty”) albo wybierzesz je wcześniej w szczegółach ogłoszenia.'
                    : 'Kreator otworzy krok „Dokumenty”. Odczyt pozycji wymaga uprawnienia „Dodawanie dokumentów”.'}
                </p>
              )}
            </>
          )}
          {err && (
            <p className="rounded bg-red-50 px-3 py-2 text-red-700" role="alert">
              {err}
            </p>
          )}
        </div>

        <div className="flex flex-wrap items-center justify-end gap-2 border-t border-slate-200 px-4 py-3">
          {existing === null && needsChoice && <span className="mr-auto text-xs text-slate-500">Najpierw wybierz klienta.</span>}
          <button
            ref={closeRef}
            type="button"
            disabled={busy}
            onClick={onClose}
            className="rounded border border-slate-300 bg-white px-3 py-1.5 text-xs text-slate-700 hover:bg-slate-50 disabled:opacity-50"
          >
            {existing !== null ? 'Zamknij' : 'Anuluj'}
          </button>
          {existing !== null ? (
            existing.canOpen && (
              <button
                type="button"
                autoFocus
                onClick={() => onOpenExisting(existing.id)}
                className="rounded bg-blue-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-blue-700"
              >
                Przejdź do istniejącego przetargu
              </button>
            )
          ) : (
            <button
              ref={confirmRef}
              type="button"
              disabled={busy || needsChoice}
              onClick={() => void confirm()}
              className="rounded bg-blue-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-blue-700 disabled:opacity-50"
            >
              {busy ? 'Zakładam…' : 'Załóż przetarg'}
            </button>
          )}
        </div>
      </div>
    </div>
  )
}
