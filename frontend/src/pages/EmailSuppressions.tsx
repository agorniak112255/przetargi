import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../auth'
import {
  BTN_PRIMARY,
  BTN_SM,
  CampaignsTabs,
  Chip,
  ConfirmDialog,
  ErrorBar,
  INPUT,
  Pager,
} from '../components/CampaignsUi'
import { errorText, fmtDateTime, fmtInt } from '../lib/campaignFormat'
import { can } from '../lib/api'
import {
  addSuppression,
  deleteSuppression,
  listSuppressions,
  type EmailSuppression,
  type PageMeta,
} from '../lib/campaigns'

/** Zakładka „Wypisani”: adresy, które nie dostaną żadnej kampanii. Dodaje i usuwa tylko campaigns.manage. */

const REASON_LABEL: Record<EmailSuppression['reason'], string> = {
  unsubscribe: 'wypisał się',
  bounce: 'adres nie działa',
  manual: 'dodany ręcznie',
}

const SEARCH_DEBOUNCE_MS = 300

export function EmailSuppressions() {
  const { user } = useAuth()
  const manage = can(user, 'campaigns.manage')
  const [searchInput, setSearchInput] = useState('')
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [rows, setRows] = useState<EmailSuppression[] | null>(null)
  const [meta, setMeta] = useState<PageMeta | null>(null)
  const [loading, setLoading] = useState(false)
  const [err, setErr] = useState('')
  const [email, setEmail] = useState('')
  const [note, setNote] = useState('')
  const [adding, setAdding] = useState(false)
  const [toDelete, setToDelete] = useState<EmailSuppression | null>(null)
  const [deleting, setDeleting] = useState(false)
  const [deleteErr, setDeleteErr] = useState('')
  const seq = useRef(0)

  useEffect(() => {
    const t = window.setTimeout(() => {
      setSearch(searchInput.trim())
      setPage(1)
    }, SEARCH_DEBOUNCE_MS)
    return () => window.clearTimeout(t)
  }, [searchInput])

  const load = useCallback(async () => {
    const my = ++seq.current
    setLoading(true)
    try {
      const res = await listSuppressions({ search, page })
      if (my !== seq.current) return
      setRows(res.data)
      setMeta(res.meta)
      setErr('')
    } catch (ex) {
      if (my === seq.current) setErr(errorText(ex, 'Nie udało się wczytać listy wypisanych.'))
    } finally {
      if (my === seq.current) setLoading(false)
    }
  }, [search, page])

  useEffect(() => {
    void load()
  }, [load])

  async function add(e: FormEvent) {
    e.preventDefault()
    if (!email.trim()) return
    setAdding(true)
    setErr('')
    try {
      await addSuppression({ email: email.trim(), note: note.trim() || undefined })
      setEmail('')
      setNote('')
      await load()
    } catch (ex) {
      setErr(errorText(ex, 'Nie udało się dodać adresu.'))
    } finally {
      setAdding(false)
    }
  }

  async function confirmDelete() {
    if (!toDelete) return
    setDeleting(true)
    setDeleteErr('')
    try {
      await deleteSuppression(toDelete.id)
      setToDelete(null)
      await load()
    } catch (ex) {
      setDeleteErr(errorText(ex, 'Nie udało się usunąć adresu z listy.'))
    } finally {
      setDeleting(false)
    }
  }

  return (
    <>
      <div className="app-page-head mb-4">
        <h1 className="app-page-title text-xl font-semibold">Wypisani z mailingu</h1>
        <p className="mt-1 max-w-3xl text-xs text-slate-600">
          Te adresy nie dostaną żadnej kampanii — od żadnego handlowca. Adres trafia tu sam, gdy klient kliknie „Wypisz mnie”
          w mailu.
          {!manage && ' Dodać albo usunąć adres może administrator.'}
        </p>
      </div>

      <CampaignsTabs active="suppressed" />

      <ErrorBar message={err} onClose={() => setErr('')} />

      {manage && (
        <form onSubmit={add} className="mb-4 flex flex-wrap items-end gap-3 rounded-xl bg-white p-4 text-xs shadow-sm">
          <label className="flex min-w-[16rem] flex-col gap-1 text-slate-600">
            Adres e-mail do wykluczenia
            <input type="email" required className={INPUT} value={email} onChange={(e) => setEmail(e.target.value)} />
          </label>
          <label className="flex min-w-[16rem] flex-1 flex-col gap-1 text-slate-600">
            Powód (opcjonalnie)
            <input
              maxLength={255}
              className={INPUT}
              value={note}
              onChange={(e) => setNote(e.target.value)}
              placeholder="np. prośba telefoniczna 30.09"
            />
          </label>
          <button type="submit" className={BTN_PRIMARY} disabled={adding || !email.trim()}>
            {adding ? 'Dodaję…' : 'Dodaj do wypisanych'}
          </button>
        </form>
      )}

      <div className="overflow-x-auto rounded-xl bg-white p-4 shadow-sm">
        <div className="mb-2 flex flex-wrap items-center justify-between gap-2 text-xs">
          <input
            type="search"
            className={`${INPUT} w-72`}
            placeholder="Szukaj adresu"
            value={searchInput}
            onChange={(e) => setSearchInput(e.target.value)}
          />
          <span className="text-slate-500">
            {meta ? `Łącznie ${fmtInt(meta.total)}` : ''}
            {loading ? ' · ładowanie…' : ''}
          </span>
        </div>
        <table className="w-full text-left text-xs">
          <thead>
            <tr className="border-b bg-slate-50">
              <th className="p-2">Adres</th>
              <th className="p-2">Powód</th>
              <th className="p-2">Kampania</th>
              <th className="p-2">Od kiedy</th>
              {manage && <th className="p-2" />}
            </tr>
          </thead>
          <tbody>
            {(rows ?? []).map((s) => (
              <tr key={s.id} className="border-b align-top">
                <td className="p-2 font-mono text-slate-900">{s.email}</td>
                <td className="p-2">
                  <Chip tone={s.reason === 'bounce' ? 'amber' : 'slate'}>{REASON_LABEL[s.reason] ?? s.reason}</Chip>
                  {s.note && <div className="mt-0.5 text-[11px] text-slate-500">{s.note}</div>}
                </td>
                <td className="p-2">
                  {s.campaign ? (
                    <Link to={`/kampanie/${s.campaign.id}`} className="app-code font-mono text-blue-600 hover:underline">
                      {s.campaign.code}
                    </Link>
                  ) : (
                    <span className="text-slate-400">—</span>
                  )}
                </td>
                <td className="whitespace-nowrap p-2 tabular-nums text-slate-600">{fmtDateTime(s.created_at)}</td>
                {manage && (
                  <td className="p-2 text-right">
                    <button
                      type="button"
                      className={`${BTN_SM} text-red-700`}
                      onClick={() => {
                        setDeleteErr('')
                        setToDelete(s)
                      }}
                    >
                      Usuń z listy
                    </button>
                  </td>
                )}
              </tr>
            ))}
            {rows !== null && rows.length === 0 && (
              <tr>
                <td colSpan={manage ? 5 : 4} className="p-8 text-center text-slate-500">
                  {search ? 'Brak adresów pasujących do wyszukiwania.' : 'Nikt się jeszcze nie wypisał.'}
                </td>
              </tr>
            )}
          </tbody>
        </table>
        <Pager meta={meta} disabled={loading} onPage={setPage} />
      </div>

      {toDelete && (
        <ConfirmDialog
          title="Usunąć adres z listy wypisanych?"
          confirmLabel="Usuń z listy"
          danger
          busy={deleting}
          error={deleteErr}
          onClose={() => setToDelete(null)}
          onConfirm={() => void confirmDelete()}
          message={
            <>
              <p className="font-mono">{toDelete.email}</p>
              {toDelete.reason === 'unsubscribe' && (
                <p className="rounded border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">
                  Ten klient sam się wypisał. Usuń go z listy tylko wtedy, gdy wyraźnie poprosił o ponowne maile.
                </p>
              )}
              <p className="text-xs text-slate-600">Po usunięciu adres znów może dostawać kampanie.</p>
            </>
          }
        />
      )}
    </>
  )
}
