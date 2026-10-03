import { useEffect, useState, type CSSProperties, type FormEvent } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { useAuth } from '../auth'
import { api, can, type Tender } from '../lib/api'
import { tenderStatusLabel } from '../lib/tenderStatus'
import { setTenderWizardActive } from '../lib/tenderWizard'
import { formatDeadline } from '../lib/tenderDeadline'
import { RESULT_STATUS_CLASS, resultStatusLabel } from '../lib/tenderResult'

type Client = { id: number; name: string }

/** Termin w ciągu 7 dni i nie w przeszłości. */
function isDeadlineSoon(deadline: string | null): boolean {
  return (
    !!deadline &&
    new Date(deadline) <= new Date(Date.now() + 7 * 86400000) &&
    new Date(deadline) >= new Date(new Date().toDateString())
  )
}

export function Tenders() {
  const { user } = useAuth()
  const navigate = useNavigate()
  const [params, setParams] = useSearchParams()
  const filter = params.get('filter') ?? ''
  const [rows, setRows] = useState<Tender[]>([])
  const [clients, setClients] = useState<Client[]>([])
  const [open, setOpen] = useState(false)
  const [title, setTitle] = useState('')
  const [clientId, setClientId] = useState('')
  const [deadline, setDeadline] = useState('')
  const [deadlineTime, setDeadlineTime] = useState('')
  const [noticeNumber, setNoticeNumber] = useState('')
  const [margin, setMargin] = useState('18')
  const [err, setErr] = useState('')
  const [busy, setBusy] = useState(false)
  const seeAll = can(user, 'tenders.view_all')
  const canDeleteTender = can(user, 'tenders.delete')

  async function load() {
    const qs = filter ? `?filter=${encodeURIComponent(filter)}` : ''
    const [t, c] = await Promise.all([
      api<Tender[]>(`/tenders${qs}`),
      api<Client[]>('/clients'),
    ])
    setRows(t)
    setClients(c)
    if (!clientId && c[0]) setClientId(String(c[0].id))
  }

  useEffect(() => {
    void load()
  }, [filter])

  async function onCreate(e: FormEvent) {
    e.preventDefault()
    setBusy(true)
    setErr('')
    try {
      const t = await api<Tender>('/tenders', {
        method: 'POST',
        body: JSON.stringify({
          title,
          client_id: Number(clientId),
          deadline: deadline || null,
          // godzina tylko razem z datą
          deadline_time: deadline ? deadlineTime || null : null,
          notice_number: noticeNumber.trim() || null,
          target_margin_percent: Number(String(margin).replace(',', '.')) || 18,
        }),
      })
      setOpen(false)
      setTitle('')
      setDeadlineTime('')
      setNoticeNumber('')
      setMargin('18')
      setTenderWizardActive(t.id, true)
      navigate(`/tenders/${t.id}`)
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Nie udało się utworzyć przetargu.')
    } finally {
      setBusy(false)
    }
  }

  async function deleteTender(t: Tender) {
    if (!window.confirm(`Usunąć przetarg ${t.number}? Tej operacji nie można cofnąć.`)) return
    setBusy(true)
    setErr('')
    try {
      await api(`/tenders/${t.id}`, { method: 'DELETE' })
      await load()
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Nie udało się usunąć przetargu.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div>
      <div className="app-page-head mb-4 flex flex-wrap items-center justify-between gap-3">
        <h1 className="app-page-title text-xl font-semibold">Przetargi</h1>
        <div className="flex flex-wrap items-center gap-2">
          <select
            className="rounded border border-slate-300 px-2 py-1.5 text-xs"
            value={filter}
            onChange={(e) => {
              const v = e.target.value
              if (v) setParams({ filter: v })
              else setParams({})
            }}
          >
            <option value="">{seeAll ? 'Wszystkie przetargi' : 'Moje i zaproszenia'}</option>
            <option value="mine">Tylko te, których jestem opiekunem</option>
            <option value="invited">Tylko zaproszenia</option>
            <option value="deadline_soon">Termin za mniej niż 7 dni</option>
            <option value="no_result">Po terminie, bez wpisanego wyniku</option>
            <option value="no_deadline_time">W toku, bez godziny składania</option>
            <option value="no_notice">W toku, bez numeru ogłoszenia</option>
          </select>
          <button
            type="button"
            onClick={() => setOpen((v) => !v)}
            className="rounded bg-blue-600 px-3 py-2 text-xs text-white hover:bg-blue-700"
          >
            + Nowy przetarg
          </button>
        </div>
      </div>

      {open && (
        <form onSubmit={onCreate} className="mb-4 rounded-xl bg-white p-4 shadow-sm text-sm">
          <h2 className="mb-3 font-semibold">Nowy przetarg</h2>
          {err && <p className="mb-2 text-xs text-red-600">{err}</p>}
          <div className="grid gap-3 sm:grid-cols-3">
            <label className="block text-xs">
              Tytuł
              <input
                required
                className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5"
                value={title}
                onChange={(e) => setTitle(e.target.value)}
                placeholder="np. Pakiet rękawic na drugi kwartał"
              />
            </label>
            <label className="block text-xs">
              Zamawiający
              <select
                required
                className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5"
                value={clientId}
                onChange={(e) => setClientId(e.target.value)}
              >
                {clients.map((c) => (
                  <option key={c.id} value={c.id}>
                    {c.name}
                  </option>
                ))}
              </select>
            </label>
            <label className="block text-xs">
              Termin składania
              <input
                type="date"
                className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5"
                value={deadline}
                onChange={(e) => setDeadline(e.target.value)}
              />
            </label>
            <label className="block text-xs" title="Godzina w czasie polskim, do której trzeba złożyć ofertę">
              Godzina składania
              <input
                type="time"
                disabled={!deadline}
                className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5 disabled:bg-slate-50"
                value={deadlineTime}
                onChange={(e) => setDeadlineTime(e.target.value)}
              />
            </label>
            <label
              className="block text-xs"
              title="Biuletyn Zamówień Publicznych (np. 2026/BZP 00431178/01) albo Dziennik Urzędowy Unii Europejskiej (TED, np. 606345-2026). Przy numerze z Biuletynu aplikacja sama znajdzie wynik przetargu."
            >
              Numer ogłoszenia (nieobowiązkowy)
              <input
                className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5"
                value={noticeNumber}
                onChange={(e) => setNoticeNumber(e.target.value)}
                placeholder="np. 2026/BZP 00431178/01"
              />
            </label>
            <label
              className="block text-xs"
              title="Narzut doliczany do ceny zakupu. Przy 18% produkt kupiony za 100 zł ma w ofercie 118 zł (marża około 15%). Zmiana narzutu przelicza proporcjonalnie wszystkie ceny w ofercie, także poprawione ręcznie."
            >
              Narzut na cenę zakupu, %
              <input
                required
                type="number"
                min={0}
                max={500}
                step={0.1}
                className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5"
                value={margin}
                onChange={(e) => setMargin(e.target.value)}
              />
            </label>
          </div>
          <p className="mt-1 text-[11px] text-slate-500">
            Narzut doliczany do ceny zakupu. Przy 18% produkt kupiony za 100 zł ma w ofercie 118 zł (marża
            około 15%). Domyślnie 18%. Zmiana narzutu przelicza proporcjonalnie wszystkie ceny w ofercie, także
            poprawione ręcznie.
          </p>
          <button
            type="submit"
            disabled={busy}
            className="mt-3 rounded bg-blue-600 px-3 py-2 text-xs text-white disabled:opacity-50"
          >
            Utwórz przetarg
          </button>
        </form>
      )}

      {err && !open ? <p className="mb-2 text-xs text-red-600">{err}</p> : null}

      <div className="app-card rounded-xl bg-white p-4 shadow-sm">
        <table className="app-table w-full text-left text-xs">
          <thead>
            <tr className="border-b bg-slate-50">
              <th className="p-2">Numer</th>
              <th className="p-2">Zamawiający</th>
              <th className="p-2">Termin składania</th>
              <th className="p-2">Wartość oferty netto</th>
              <th className="p-2">Pozycje</th>
              <th className="p-2">Status</th>
              <th className="p-2">Wynik</th>
              <th
                className="p-2"
                title="Szacunek, jak bardzo produkty pasują do opisu zamawiającego — średnia z pozycji ocenionych automatycznie; produkty wybrane ręcznie nie mają oceny. Nie zastępuje sprawdzenia karty produktu."
              >
                Dopasowanie
              </th>
              <th className="p-2">Opiekun przetargu</th>
              {canDeleteTender ? <th className="p-2"></th> : null}
            </tr>
          </thead>
          <tbody>
            {rows.map((t) => {
              const soon = isDeadlineSoon(t.deadline)
              return (
                <tr key={t.id} className="border-b hover:bg-slate-50">
                  <td className="p-2">
                    <Link className="app-code font-medium text-blue-600 hover:underline" to={`/tenders/${t.id}`}>
                      {t.number}
                    </Link>
                  </td>
                  <td className="p-2">{t.client?.name}</td>
                  <td className="p-2">
                    <span className="app-deadline" data-soon={soon ? 'true' : undefined}>
                      {formatDeadline(t.deadline, t.deadline_time) || '—'}
                      {soon && (
                        <span className="app-deadline-flag ml-1 font-semibold text-red-600" title="Termin składania za mniej niż 7 dni">
                          !
                        </span>
                      )}
                    </span>
                  </td>
                  <td className="app-num p-2">
                    {t.offer_value_net
                      ? `${Number(t.offer_value_net).toLocaleString('pl-PL')} zł`
                      : '—'}
                  </td>
                  <td className="app-num p-2">{t.items_count ?? 0}</td>
                  <td className="p-2">
                    <span className="app-status" data-status={t.status}>
                      {tenderStatusLabel(t.status)}
                    </span>
                  </td>
                  <td className="p-2">
                    {t.result_status ? (
                      <span className={`whitespace-nowrap rounded border px-1.5 py-0.5 ${RESULT_STATUS_CLASS[t.result_status]}`}>
                        {resultStatusLabel(t.result_status)}
                      </span>
                    ) : (
                      <span className="text-slate-400">—</span>
                    )}
                  </td>
                  <td className="p-2">
                    <span className="app-ai" style={{ '--ai': `${t.ai_percent}%` } as CSSProperties}>
                      {t.ai_percent}%
                    </span>
                  </td>
                  <td className="p-2">{t.owner?.name}</td>
                  {canDeleteTender ? (
                    <td className="p-2 text-right">
                      <button
                        type="button"
                        disabled={busy}
                        className="rounded bg-red-600 px-2 py-1 text-[10px] font-semibold text-white hover:bg-red-700 disabled:opacity-50"
                        onClick={() => void deleteTender(t)}
                      >
                        Usuń
                      </button>
                    </td>
                  ) : null}
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>
    </div>
  )
}
