import { useEffect, useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { InquiryClarifyModal, type InquiryAnswer, type InquiryCard } from '../components/InquiryClarifyModal'
import { useAuth } from '../auth'
import { api, appHref, can } from '../lib/api'

type ClientRow = { id: number; name: string }

type InquiryListItem = {
  id: number
  source_subject: string | null
  reply_subject: string | null
  client: { id: number; name: string } | null
  created_at: string | null
  has_reply: boolean
}

export type InquiryPayload = {
  id: number
  client_id: number | null
  client: { id: number; name: string } | null
  tone: 'formal' | 'handlowy'
  source_subject: string | null
  source_body: string
  questions: string[]
  matches: {
    query: string
    products: {
      id: number
      sku: string
      name: string
      manufacturer: string
      norms: string
      catalog_price_net: string | null
      currency: string
      stock: number | null
      score: number
    }[]
  }[]
  cards: InquiryCard[]
  answers: Record<string, InquiryAnswer>
  extra_note: string | null
  reply_subject: string | null
  reply_body: string | null
}

function openReplyWindow(id: number): void {
  const href = appHref(`/inquiries/${id}`)
  const win = window.open(href, 'inquiry-reply')
  if (!win) {
    window.location.assign(href)
  }
}

export function Inquiries() {
  const { user } = useAuth()
  const [clients, setClients] = useState<ClientRow[]>([])
  const [recent, setRecent] = useState<InquiryListItem[]>([])
  const [body, setBody] = useState('')
  const [subject, setSubject] = useState('')
  const [clientId, setClientId] = useState('')
  const [tone, setTone] = useState<'formal' | 'handlowy'>('formal')
  const [busy, setBusy] = useState(false)
  const [composeBusy, setComposeBusy] = useState(false)
  const [err, setErr] = useState('')
  const [inquiry, setInquiry] = useState<InquiryPayload | null>(null)
  const [modalOpen, setModalOpen] = useState(false)

  async function loadRecent() {
    setRecent(await api<InquiryListItem[]>('/inquiries'))
  }

  useEffect(() => {
    void loadRecent()
    if (can(user, 'clients.view')) {
      void api<ClientRow[]>('/clients').then((rows) =>
        setClients(rows.map((c) => ({ id: c.id, name: c.name }))),
      )
    }
  }, [user])

  async function composeAndOpen(
    current: InquiryPayload,
    answers: Record<string, InquiryAnswer>,
    extraNote: string,
  ) {
    setComposeBusy(true)
    setErr('')
    try {
      const done = await api<InquiryPayload>(`/inquiries/${current.id}/compose`, {
        method: 'POST',
        body: JSON.stringify({ answers, extra_note: extraNote || null }),
      })
      setInquiry(done)
      setModalOpen(false)
      openReplyWindow(done.id)
      await loadRecent()
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Błąd pisania odpowiedzi')
    } finally {
      setComposeBusy(false)
    }
  }

  async function onPrepare(e: FormEvent) {
    e.preventDefault()
    setBusy(true)
    setErr('')
    setInquiry(null)
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
      setInquiry(created)
      if (created.cards.length > 0) {
        setModalOpen(true)
      } else {
        await composeAndOpen(created, {}, '')
      }
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Błąd analizy')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div>
      <h1 className="mb-1 text-xl font-semibold">Zapytania</h1>
      <p className="mb-4 text-sm text-slate-500">
        Wklej mail klienta. System dopyta tylko o niuanse, potem otworzy okno z listem do skopiowania.
      </p>

      {err && <p className="mb-3 rounded bg-red-50 px-3 py-2 text-xs text-red-700">{err}</p>}

      <form onSubmit={(e) => void onPrepare(e)} className="mb-6 rounded-xl bg-white p-4 shadow-sm">
        <div className="grid gap-3 lg:grid-cols-[1fr_16rem]">
          <label className="block text-xs">
            Treść maila *
            <textarea
              required
              minLength={20}
              className="mt-1 min-h-[220px] w-full rounded border border-slate-300 px-2 py-1.5 text-sm"
              value={body}
              disabled={busy || composeBusy}
              onChange={(e) => setBody(e.target.value)}
              placeholder="Wklej całą treść zapytania od klienta…"
            />
          </label>
          <div className="space-y-3">
            <label className="block text-xs">
              Temat (opcjonalnie)
              <input
                className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5 text-sm"
                value={subject}
                disabled={busy || composeBusy}
                onChange={(e) => setSubject(e.target.value)}
              />
            </label>
            {can(user, 'clients.view') && (
              <label className="block text-xs">
                Klient
                <select
                  className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5 text-sm"
                  value={clientId}
                  disabled={busy || composeBusy}
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
                disabled={busy || composeBusy}
                onChange={(e) => setTone(e.target.value as 'formal' | 'handlowy')}
              >
                <option value="formal">Formalny</option>
                <option value="handlowy">Handlowy</option>
              </select>
            </label>
            <button
              type="submit"
              disabled={busy || composeBusy || body.trim().length < 20}
              className="w-full rounded bg-blue-600 px-3 py-2 text-xs font-medium text-white hover:bg-blue-700 disabled:opacity-50"
            >
              {busy ? 'Analizuję…' : 'Przygotuj odpowiedź'}
            </button>
          </div>
        </div>
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
                <th className="p-2" />
              </tr>
            </thead>
            <tbody>
              {recent.map((row) => (
                <tr key={row.id} className="border-b">
                  <td className="p-2">{row.reply_subject || row.source_subject || `Zapytanie #${row.id}`}</td>
                  <td className="p-2">{row.client?.name ?? '—'}</td>
                  <td className="p-2">
                    {row.created_at ? new Date(row.created_at).toLocaleString('pl-PL') : '—'}
                  </td>
                  <td className="p-2 text-right">
                    <Link className="text-blue-600 hover:underline" to={`/inquiries/${row.id}`}>
                      {row.has_reply ? 'Otwórz' : 'Dokończ'}
                    </Link>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <InquiryClarifyModal
        open={modalOpen}
        cards={inquiry?.cards ?? []}
        busy={composeBusy}
        error={err}
        onClose={() => {
          if (!composeBusy) setModalOpen(false)
        }}
        onSubmit={(answers, extraNote) => {
          if (inquiry) void composeAndOpen(inquiry, answers, extraNote)
        }}
      />
    </div>
  )
}
