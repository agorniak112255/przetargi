import { useEffect, useState, type FormEvent, type KeyboardEvent } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { BusyLabel, useBusySeconds } from '../components/Busy'
import { useAuth } from '../auth'
import { api, can } from '../lib/api'
import type {
  InquiryListItem,
  InquiryPayload,
  InquiryPreferences,
  InquiryTone,
} from '../types/inquiry'

type ClientRow = { id: number; name: string }

const priceModeLabel: Record<InquiryPreferences['price_mode'], string> = {
  none: 'bez cen',
  catalog: 'cena katalogowa',
  catalog_margin: 'katalog + marża',
}

function StatusChip({ row }: { row: InquiryListItem }) {
  if (row.replied_at) {
    return (
      <span className="rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-medium text-emerald-800">
        Wysłano
      </span>
    )
  }
  if (row.attention_count > 0) {
    return (
      <span className="rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-medium text-amber-800">
        Do sprawdzenia ({row.attention_count})
      </span>
    )
  }
  return (
    <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-600">
      Szkic
    </span>
  )
}

export function Inquiries() {
  const { user } = useAuth()
  const navigate = useNavigate()
  const [clients, setClients] = useState<ClientRow[]>([])
  const [recent, setRecent] = useState<InquiryListItem[]>([])
  const [prefs, setPrefs] = useState<InquiryPreferences | null>(null)
  const [body, setBody] = useState('')
  const [subject, setSubject] = useState('')
  const [clientId, setClientId] = useState('')
  const [tone, setTone] = useState<InquiryTone>('formal')
  const [more, setMore] = useState(false)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const prepareSec = useBusySeconds(busy)

  useEffect(() => {
    void api<InquiryListItem[]>('/inquiries')
      .then(setRecent)
      .catch((ex) => setErr(ex instanceof Error ? ex.message : 'Nie udało się wczytać listy'))
    void api<InquiryPreferences>('/inquiries/preferences')
      .then((p) => {
        setPrefs(p)
        setTone(p.tone)
      })
      .catch(() => {
        // brak preferencji — zostają domyślne
      })
    if (can(user, 'clients.view')) {
      void api<ClientRow[]>('/clients').then((rows) =>
        setClients(rows.map((c) => ({ id: c.id, name: c.name }))),
      )
    }
  }, [user])

  const canSubmit = !busy && body.trim().length >= 20

  async function onPrepare(e?: FormEvent) {
    e?.preventDefault()
    if (!canSubmit) return
    setBusy(true)
    setErr('')
    try {
      const created = await api<InquiryPayload>('/inquiries', {
        method: 'POST',
        body: JSON.stringify({
          body,
          subject: subject.trim() || null,
          client_id: clientId ? Number(clientId) : null,
          tone,
        }),
      })
      navigate(`/inquiries/${created.id}`)
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Błąd analizy')
      setBusy(false)
    }
  }

  function onBodyKey(e: KeyboardEvent<HTMLTextAreaElement>) {
    if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
      e.preventDefault()
      void onPrepare()
    }
  }

  return (
    <div>
      <h1 className="mb-1 text-xl font-semibold">Zapytania</h1>
      <p className="mb-4 text-sm text-slate-500">
        Wklej mail klienta. Dostaniesz gotowy list i listę pozycji, które warto sprawdzić przed
        wysłaniem.
      </p>

      {err && <p className="mb-3 rounded bg-red-50 px-3 py-2 text-xs text-red-700">{err}</p>}

      <form onSubmit={(e) => void onPrepare(e)} className="mb-6 rounded-xl bg-white p-4 shadow-sm">
        <label className="block text-xs">
          Treść maila *
          <textarea
            required
            minLength={20}
            className="mt-1 min-h-[220px] w-full rounded border border-slate-300 px-2 py-1.5 text-sm"
            value={body}
            disabled={busy}
            onChange={(e) => setBody(e.target.value)}
            onKeyDown={onBodyKey}
            placeholder="Wklej całą treść zapytania od klienta…"
          />
        </label>

        <div className="mt-3 flex flex-wrap items-center gap-3">
          <button
            type="submit"
            disabled={!canSubmit}
            className={`rounded px-4 py-2 text-xs font-medium text-white ${
              busy ? 'cursor-wait bg-violet-600' : 'bg-blue-600 hover:bg-blue-700 disabled:opacity-50'
            }`}
          >
            {busy ? <BusyLabel label="Analizuję zapytanie" seconds={prepareSec} /> : 'Przygotuj odpowiedź'}
          </button>
          <span className="text-[11px] text-slate-400">Ctrl+Enter wysyła</span>
          <button
            type="button"
            disabled={busy}
            onClick={() => setMore((v) => !v)}
            className="text-xs text-blue-600 hover:underline disabled:opacity-50"
          >
            {more ? 'Mniej' : 'Więcej'}
          </button>
          {busy && (
            <span className="text-[11px] text-violet-800">Model liczy — poczekaj, nie odświeżaj strony.</span>
          )}
        </div>

        {more && (
          <div className="mt-3 grid gap-3 border-t border-slate-100 pt-3 sm:grid-cols-3">
            <label className="block text-xs">
              Temat (opcjonalnie)
              <input
                className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5 text-sm"
                value={subject}
                disabled={busy}
                onChange={(e) => setSubject(e.target.value)}
              />
            </label>
            {can(user, 'clients.view') && (
              <label className="block text-xs">
                Klient
                <select
                  className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5 text-sm"
                  value={clientId}
                  disabled={busy}
                  onChange={(e) => setClientId(e.target.value)}
                >
                  <option value="">— bez klienta —</option>
                  {clients.map((c) => (
                    <option key={c.id} value={c.id}>
                      {c.name}
                    </option>
                  ))}
                </select>
              </label>
            )}
            <label className="block text-xs">
              Ton
              <select
                className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5 text-sm"
                value={tone}
                disabled={busy}
                onChange={(e) => setTone(e.target.value as InquiryTone)}
              >
                <option value="formal">Formalny</option>
                <option value="handlowy">Handlowy</option>
              </select>
            </label>
            {prefs && (
              <p className="text-[11px] text-slate-500 sm:col-span-3">
                Ceny jak ostatnio: {priceModeLabel[prefs.price_mode]}
                {prefs.price_mode === 'catalog_margin' ? ` ${prefs.margin}%` : ''} — zmienisz na stronie
                odpowiedzi.
              </p>
            )}
          </div>
        )}
      </form>

      {recent.length > 0 && (
        <div className="rounded-xl bg-white p-4 shadow-sm">
          <h2 className="mb-2 text-sm font-semibold">Ostatnie</h2>
          <table className="w-full text-left text-xs">
            <thead>
              <tr className="border-b bg-slate-50">
                <th className="p-2">Temat</th>
                <th className="p-2">Klient</th>
                <th className="p-2">Data</th>
                <th className="p-2">Status</th>
                <th className="p-2" />
              </tr>
            </thead>
            <tbody>
              {recent.map((row) => (
                <tr key={row.id} className="border-b">
                  <td className="p-2">{row.reply_subject || row.source_subject || `Zapytanie #${row.id}`}</td>
                  <td className="p-2">{row.client?.name ?? '—'}</td>
                  <td className="p-2 whitespace-nowrap">
                    {row.created_at ? new Date(row.created_at).toLocaleString('pl-PL') : '—'}
                  </td>
                  <td className="p-2">
                    <StatusChip row={row} />
                  </td>
                  <td className="p-2 text-right">
                    <Link className="text-blue-600 hover:underline" to={`/inquiries/${row.id}`}>
                      Otwórz
                    </Link>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
