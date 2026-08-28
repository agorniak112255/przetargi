import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { BusyLabel, InquiryClarifyModal, useBusySeconds, type InquiryAnswer } from '../components/InquiryClarifyModal'
import { api, appHref } from '../lib/api'
import type { InquiryPayload } from './Inquiries'

export function InquiryReply() {
  const { id } = useParams()
  const [inquiry, setInquiry] = useState<InquiryPayload | null>(null)
  const [subject, setSubject] = useState('')
  const [body, setBody] = useState('')
  const [err, setErr] = useState('')
  const [msg, setMsg] = useState('')
  const [loading, setLoading] = useState(true)
  const [composeBusy, setComposeBusy] = useState(false)
  const [modalOpen, setModalOpen] = useState(false)
  const composeSec = useBusySeconds(composeBusy)

  useEffect(() => {
    if (!id) return
    setLoading(true)
    setErr('')
    api<InquiryPayload>(`/inquiries/${id}`)
      .then((row) => {
        setInquiry(row)
        setSubject(row.reply_subject ?? '')
        setBody(row.reply_body ?? '')
        if (!row.reply_body && row.cards.length > 0) {
          setModalOpen(true)
        }
      })
      .catch((ex) => setErr(ex instanceof Error ? ex.message : 'Nie udało się wczytać'))
      .finally(() => setLoading(false))
  }, [id])

  async function compose(answers: Record<string, InquiryAnswer>, extraNote: string) {
    if (!inquiry) return
    setComposeBusy(true)
    setErr('')
    try {
      const done = await api<InquiryPayload>(`/inquiries/${inquiry.id}/compose`, {
        method: 'POST',
        body: JSON.stringify({ answers, extra_note: extraNote || null }),
      })
      setInquiry(done)
      setSubject(done.reply_subject ?? '')
      setBody(done.reply_body ?? '')
      setModalOpen(false)
      setMsg('Odpowiedź zaktualizowana.')
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Błąd pisania odpowiedzi')
    } finally {
      setComposeBusy(false)
    }
  }

  async function copyAll() {
    const text = [subject.trim(), '', body].filter(Boolean).join('\n')
    await navigator.clipboard.writeText(text)
    setMsg('Skopiowano temat i treść.')
  }

  async function copyBody() {
    await navigator.clipboard.writeText(body)
    setMsg('Skopiowano treść.')
  }

  if (loading) return <p className="text-sm text-slate-500">Ładowanie…</p>
  if (!inquiry) return <p className="text-sm text-red-600">{err || 'Brak zapytania.'}</p>

  const sources = inquiry.matches.flatMap((g) => g.products)

  return (
    <div className="mx-auto max-w-3xl space-y-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold">Odpowiedź do skopiowania</h1>
          <p className="text-xs text-slate-500">
            {inquiry.client?.name ? `${inquiry.client.name} · ` : ''}
            edytuj jeśli trzeba, potem Kopiuj.
          </p>
          <Link
            to="/inquiries"
            className="mt-1 inline-block text-xs text-blue-600 hover:underline"
          >
            ← Powrót do zapytań
          </Link>
        </div>
        <div className="flex flex-wrap gap-2">
          <button
            type="button"
            disabled={composeBusy}
            onClick={() => setModalOpen(true)}
            className={`rounded px-3 py-1.5 text-xs ${
              composeBusy
                ? 'cursor-wait bg-violet-600 text-white'
                : 'border border-slate-300 hover:bg-slate-50'
            }`}
          >
            {composeBusy ? <BusyLabel label="Piszę" seconds={composeSec} /> : 'Doprecyzuj'}
          </button>
          <button
            type="button"
            onClick={() => void copyBody()}
            className="rounded border border-slate-300 px-3 py-1.5 text-xs hover:bg-slate-50"
          >
            Kopiuj treść
          </button>
          <button
            type="button"
            onClick={() => void copyAll()}
            className="rounded bg-blue-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-blue-700"
          >
            Kopiuj całość
          </button>
        </div>
      </div>

      {msg && <p className="rounded bg-green-50 px-3 py-2 text-xs text-green-800">{msg}</p>}
      {err && <p className="rounded bg-red-50 px-3 py-2 text-xs text-red-700">{err}</p>}

      <div className="rounded-xl bg-white p-4 shadow-sm">
        <label className="block text-xs font-medium text-slate-600">
          Temat
          <input
            className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5 text-sm"
            value={subject}
            onChange={(e) => setSubject(e.target.value)}
          />
        </label>
        <label className="mt-3 block text-xs font-medium text-slate-600">
          Treść
          <textarea
            className="mt-1 min-h-[280px] w-full rounded border border-slate-300 px-2 py-1.5 text-sm leading-relaxed"
            value={body}
            onChange={(e) => setBody(e.target.value)}
          />
        </label>
      </div>

      <details className="rounded-xl bg-white p-4 text-xs shadow-sm">
        <summary className="cursor-pointer font-semibold text-slate-800">Zapytanie klienta</summary>
        {inquiry.source_subject && (
          <p className="mt-2 font-medium text-slate-700">{inquiry.source_subject}</p>
        )}
        <pre className="mt-2 whitespace-pre-wrap font-sans text-slate-600">{inquiry.source_body}</pre>
      </details>

      {sources.length > 0 && (
        <div className="rounded-xl bg-white p-4 text-xs shadow-sm">
          <p className="mb-2 font-semibold text-slate-800">Źródła z katalogu</p>
          <ul className="space-y-1.5">
            {sources.map((p) => (
              <li key={p.id}>
                <a
                  className="text-blue-600 hover:underline"
                  href={appHref(`/products/${p.id}`)}
                  target="_blank"
                  rel="noreferrer"
                >
                  {p.sku} · {p.name}
                </a>
                <span className="text-slate-500">
                  {' '}
                  · {p.manufacturer || '—'}
                  {p.norms ? ` · ${p.norms}` : ''}
                  {p.catalog_price_net
                    ? ` · kat. ${p.catalog_price_net} ${p.currency}`
                    : ''}
                </span>
              </li>
            ))}
          </ul>
          <p className="mt-2 text-[11px] text-slate-400">Ceny zakupu nie są pokazywane ani wklejane do listu.</p>
        </div>
      )}

      <div className="flex flex-wrap gap-2">
        <Link
          to="/inquiries"
          className="rounded border border-slate-300 px-3 py-1.5 text-xs hover:bg-slate-50"
        >
          Powrót
        </Link>
        <button
          type="button"
          onClick={() => {
            if (window.opener) {
              window.close()
              return
            }
            window.location.assign(appHref('/inquiries'))
          }}
          className="rounded bg-slate-800 px-3 py-1.5 text-xs font-medium text-white hover:bg-slate-700"
        >
          Zamknij
        </button>
      </div>

      <InquiryClarifyModal
        open={modalOpen}
        cards={inquiry.cards}
        initialAnswers={inquiry.answers}
        initialNote={inquiry.extra_note ?? ''}
        busy={composeBusy}
        error={err}
        onClose={() => {
          if (!composeBusy) setModalOpen(false)
        }}
        onSubmit={(answers, extraNote) => void compose(answers, extraNote)}
      />
    </div>
  )
}
