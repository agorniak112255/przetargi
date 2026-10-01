import { useEffect, useRef, useState } from 'react'
import { ApiError } from '../lib/api'
import {
  campaignAudienceRecipients,
  type AudienceRecipients,
  type AudienceSkipReason,
} from '../lib/campaigns'
import { errorText, fmtDateTime, fmtInt } from '../lib/campaignFormat'
import { plural } from '../lib/plural'
import { BTN, BTN_PRIMARY, Chip, INPUT, Modal, Pager } from './CampaignsUi'

type View = 'send' | 'skipped'

const REASON_LABEL: Record<AudienceSkipReason, string> = {
  suppressed: 'wypisany z mailingu',
  capped: 'dostał niedawno inną kampanię',
  generic: 'adres faktury@ / księgowość@',
  invalid: 'niepoprawny adres',
}

const SKIP_ORDER: AudienceSkipReason[] = ['suppressed', 'capped', 'generic', 'invalid']

/**
 * Okno przed „Wyślij teraz” i „Zaplanuj”: dokładna lista adresów, do których pójdzie mail, i pominiętych z powodem.
 * Przy wysyłce odcisk pokazanej listy (checksum) idzie do serwera — gdy lista zmieniła się w międzyczasie, serwer
 * odmawia (422), a okno wczytuje ją od nowa.
 */
export function CampaignRecipientsConfirm({
  campaignId,
  campaignName,
  mode,
  scheduleAt,
  flush,
  onConfirm,
  onClose,
}: {
  campaignId: number
  campaignName: string
  mode: 'send' | 'schedule'
  /** Tylko mode 'schedule': wybrany dzień i godzina startu. */
  scheduleAt?: Date
  /** Zapis treści w toku — przed pobraniem listy. */
  flush: () => Promise<void>
  /** Rzuca przy błędzie (komunikat pokazuje okno); po sukcesie okno zamyka rodzic. */
  onConfirm: (checksum: string) => Promise<void>
  onClose: () => void
}) {
  const [view, setView] = useState<View>('send')
  const [search, setSearch] = useState('')
  const [debounced, setDebounced] = useState('')
  const [page, setPage] = useState(1)
  const [reloadKey, setReloadKey] = useState(0)
  // odpowiedź razem z widokiem, którego dotyczy (kolumny tabeli zależą od widoku odpowiedzi, nie od wybranej zakładki)
  const [shown, setShown] = useState<{ view: View; res: AudienceRecipients } | null>(null)
  const [loading, setLoading] = useState(true)
  const [loadErr, setLoadErr] = useState('')
  const [err, setErr] = useState('')
  const [notice, setNotice] = useState('')
  const [busy, setBusy] = useState(false)
  const seq = useRef(0)

  useEffect(() => {
    const t = window.setTimeout(() => setDebounced(search.trim()), 350)
    return () => window.clearTimeout(t)
  }, [search])

  useEffect(() => {
    const my = ++seq.current
    setLoading(true)
    void (async () => {
      try {
        // treść i pozycje w toku zapisu — lista ma odpowiadać temu, co zobaczy serwer przy wysyłce
        await flush()
        const res = await campaignAudienceRecipients(campaignId, { view, search: debounced, page, per_page: 50 })
        if (my !== seq.current) return
        setShown({ view, res })
        setLoadErr('')
      } catch (ex) {
        if (my === seq.current) setLoadErr(errorText(ex, 'Nie udało się wczytać listy odbiorców.'))
      } finally {
        if (my === seq.current) setLoading(false)
      }
    })()
  }, [campaignId, view, debounced, page, reloadKey, flush])

  const res = shown?.res ?? null
  const summary = res?.summary ?? null
  const skippedTotal = summary ? SKIP_ORDER.reduce((s, k) => s + (summary.skipped[k] ?? 0), 0) : 0
  const total = summary?.total ?? 0
  const canConfirm = !busy && !loading && !loadErr && res !== null && total > 0

  function switchView(v: View) {
    if (v === view) return
    setView(v)
    setPage(1)
  }

  async function confirm() {
    if (!res || !canConfirm) return
    setBusy(true)
    setErr('')
    setNotice('')
    try {
      await onConfirm(res.checksum)
    } catch (ex) {
      const errors = ex instanceof ApiError ? (ex.body.errors as Record<string, string[]> | undefined) : undefined
      const changed = ex instanceof ApiError && ex.status === 422 ? errors?.recipients_checksum : undefined
      if (changed) {
        // lista zmieniła się od podglądu: pokazujemy ją od nowa, wysyłka dopiero po ponownym potwierdzeniu
        setNotice(changed.join(' ') || 'Lista odbiorców zmieniła się od podglądu — sprawdź ją jeszcze raz.')
        setView('send')
        setSearch('')
        setDebounced('')
        setPage(1)
        setReloadKey((k) => k + 1)
      } else {
        setErr(errorText(ex, mode === 'send' ? 'Nie udało się rozpocząć wysyłki.' : 'Nie udało się zaplanować wysyłki.'))
      }
    } finally {
      setBusy(false)
    }
  }

  const title =
    mode === 'send'
      ? 'Wysłać kampanię teraz?'
      : `Zaplanować wysyłkę na ${scheduleAt ? fmtDateTime(scheduleAt.toISOString()) : '—'}?`
  const confirmLabel =
    mode === 'send'
      ? busy
        ? 'Wysyłam…'
        : `Wyślij do ${fmtInt(total)} ${plural(total, 'odbiorcy', 'odbiorców', 'odbiorców')}`
      : busy
        ? 'Planuję…'
        : 'Zaplanuj'
  const shownView = shown?.view ?? view
  const rows = res?.data ?? []

  const tab = (v: View, label: string, n: number | null) => (
    <button
      type="button"
      aria-pressed={view === v}
      onClick={() => switchView(v)}
      className={`-mb-px border-b-2 px-3 py-1.5 ${
        view === v ? 'border-blue-600 font-semibold text-blue-700' : 'border-transparent text-slate-600 hover:text-slate-900'
      }`}
    >
      {label}
      {n !== null && <span className="ml-1 tabular-nums">({fmtInt(n)})</span>}
    </button>
  )

  return (
    <Modal
      title={title}
      wide
      busy={busy}
      onClose={onClose}
      footer={
        <>
          <button type="button" className={BTN} onClick={onClose} disabled={busy}>
            Anuluj
          </button>
          <button
            type="button"
            className={BTN_PRIMARY}
            disabled={!canConfirm}
            title={!loading && res !== null && total === 0 ? 'Nikt nie dostanie maila — sprawdź krok Odbiorcy' : undefined}
            onClick={() => void confirm()}
          >
            {confirmLabel}
          </button>
        </>
      }
    >
      <div className="space-y-3 text-xs">
        <div className="flex flex-wrap items-center gap-x-6 gap-y-2 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3">
          <div>
            <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Wyślemy do</p>
            <p className={`text-3xl font-semibold leading-tight tabular-nums ${total > 0 ? 'text-emerald-700' : 'text-red-700'}`}>
              {summary ? fmtInt(total) : '…'}
            </p>
            <p className="text-slate-600">{plural(total, 'odbiorcy', 'odbiorców', 'odbiorców')}</p>
          </div>
          <div className="min-w-0 flex-1 space-y-1 text-slate-700">
            <p>
              <b className="text-slate-900">{campaignName}</b>{' '}
              {mode === 'send' ? 'pójdzie z Twojej skrzynki.' : 'wystartuje sama z Twojej skrzynki o wybranej godzinie.'}
            </p>
            {summary && (
              <p className="tabular-nums text-slate-600">
                z grup <b className="text-slate-800">{fmtInt(summary.from_lists)}</b> · z ERP XL{' '}
                <b className="text-slate-800">{fmtInt(summary.from_xl)}</b> · powtórzone{' '}
                <b className="text-slate-800">{summary.duplicates > 0 ? `−${fmtInt(summary.duplicates)}` : '0'}</b>
                {skippedTotal > 0 && (
                  <>
                    {' '}
                    · pominięci <b className="text-slate-800">−{fmtInt(skippedTotal)}</b>
                  </>
                )}
              </p>
            )}
            {summary && skippedTotal > 0 && (
              <p className="text-[11px] text-slate-500">
                {SKIP_ORDER.filter((k) => (summary.skipped[k] ?? 0) > 0)
                  .map((k) => `${REASON_LABEL[k]}: ${fmtInt(summary.skipped[k])}`)
                  .join(' · ')}
              </p>
            )}
          </div>
        </div>

        {(res?.warnings ?? []).length > 0 && (
          <ul className="space-y-1 rounded border border-amber-200 bg-amber-50 px-2 py-1.5 text-[11px] text-amber-900">
            {(res?.warnings ?? []).map((w) => (
              <li key={w}>{w}</li>
            ))}
          </ul>
        )}
        {notice && (
          <p className="rounded border border-amber-300 bg-amber-50 px-2 py-1.5 font-medium text-amber-900" role="alert">
            {notice}
          </p>
        )}
        {loadErr && <p className="rounded bg-red-50 px-2 py-1.5 text-red-700">{loadErr}</p>}

        <div className="flex flex-wrap items-end justify-between gap-2 border-b border-slate-200">
          <div className="flex gap-1" role="group" aria-label="Lista odbiorców">
            {tab('send', 'Do wysyłki', summary ? total : null)}
            {tab('skipped', 'Pominięci', summary ? skippedTotal : null)}
          </div>
          <input
            type="search"
            value={search}
            onChange={(e) => {
              setSearch(e.target.value)
              setPage(1)
            }}
            placeholder="Szukaj: adres, osoba, grupa, akronim XL…"
            className={`${INPUT} mb-1.5 w-64`}
            aria-label="Szukaj odbiorcy"
          />
        </div>

        <div className={`max-h-[45vh] overflow-auto rounded border border-slate-200 ${loading ? 'opacity-60' : ''}`}>
          <table className="w-full text-left">
            <thead className="sticky top-0 z-10 bg-slate-50 text-[11px] text-slate-600">
              <tr>
                <th className="px-2 py-2 font-semibold">E-mail</th>
                <th className="px-2 py-2 font-semibold">Osoba / firma</th>
                <th className="px-2 py-2 font-semibold">Skąd</th>
                {shownView === 'skipped' && <th className="px-2 py-2 font-semibold">Powód</th>}
              </tr>
            </thead>
            <tbody>
              {rows.map((r, i) => (
                <tr key={`${r.email}|${r.source}|${i}`} className="border-t border-slate-100">
                  <td className={`px-2 py-1.5 align-top font-mono text-[11px] ${shownView === 'skipped' ? 'text-slate-500' : 'text-slate-800'}`}>
                    {r.email}
                  </td>
                  <td className="px-2 py-1.5 align-top text-slate-700">{r.name ?? <span className="text-slate-400">—</span>}</td>
                  <td className="px-2 py-1.5 align-top">
                    {r.source === 'xl' ? <Chip tone="blue">XL: {r.origin}</Chip> : <Chip tone="green">grupa: {r.origin}</Chip>}
                  </td>
                  {shownView === 'skipped' && (
                    <td className="px-2 py-1.5 align-top text-slate-700">{r.reason ? REASON_LABEL[r.reason] ?? r.reason : '—'}</td>
                  )}
                </tr>
              ))}
              {!loading && !loadErr && res !== null && rows.length === 0 && (
                <tr>
                  <td colSpan={shownView === 'skipped' ? 4 : 3} className="px-2 py-6 text-center text-slate-500">
                    {debounced
                      ? 'Nikt nie pasuje do szukanej frazy.'
                      : shownView === 'skipped'
                        ? 'Nikogo nie pominięto.'
                        : 'Nikt nie dostanie maila — sprawdź krok Odbiorcy.'}
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
        <div className="flex flex-wrap items-center justify-between gap-2">
          <span className="text-[11px] text-slate-500">
            {res ? `${fmtInt(res.meta.total)} ${plural(res.meta.total, 'adres', 'adresy', 'adresów')} na liście` : ''}
            {loading ? ' · ładowanie…' : ''}
          </span>
          <Pager meta={res?.meta} disabled={loading} onPage={setPage} />
        </div>

        {mode === 'send' ? (
          <p className="text-slate-600">
            Tego nie da się cofnąć — można tylko zatrzymać wysyłkę do tych, którzy jeszcze nie dostali maila. Stan pozycji
            zapiszemy teraz, żeby policzyć, ile zeszło po 7 i 30 dniach.
          </p>
        ) : (
          <p className="rounded border border-sky-200 bg-sky-50 px-2 py-1.5 text-sky-900">
            Ręczny wybór adresów zostaje, ale całe grupy i kategorie XL policzymy jeszcze raz w chwili startu — mogą dojść
            nowi klienci albo odpaść wypisani.
          </p>
        )}
        {err && <p className="rounded bg-red-50 px-3 py-2 text-red-700">{err}</p>}
      </div>
    </Modal>
  )
}
