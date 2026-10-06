import { useCallback, useEffect, useRef, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useAuth } from '../auth'
import { BTN_PRIMARY, BTN_SM, Chip, ConfirmDialog, ErrorBar } from '../components/CampaignsUi'
import { can } from '../lib/api'
import { errorText, fmtDateTime, fmtInt } from '../lib/campaignFormat'
import { createOffer, deleteOffer, listOffers, type OfferListRow } from '../lib/offers'
import { plural } from '../lib/plural'

/**
 * Oferty: lista własnych ofert dla klientów (wybrane produkty z ceną) — wysyłka ze skrzynki „Moja poczta” albo
 * nowa wiadomość w Thunderbirdzie (dodatek). Oferta jest zawsze edytowalna; historia wysyłek jest w ofercie.
 * Oferty przeglądu (kind = inspection) przygotowuje moduł Przeglądy — tu są na tej samej liście, ze znacznikiem.
 */
export function Offers() {
  const navigate = useNavigate()
  const { user } = useAuth()
  // nowa oferta produktowa tylko z „Oferty dla klientów”; oferty przeglądu powstają w module Przeglądy
  const canProducts = can(user, 'offers.use')
  const canInspections = can(user, 'inspections.offer')
  const [rows, setRows] = useState<OfferListRow[]>([])
  const [loading, setLoading] = useState(true)
  const [err, setErr] = useState('')
  const [busy, setBusy] = useState(false)
  const [toDelete, setToDelete] = useState<OfferListRow | null>(null)
  const [deleteBusy, setDeleteBusy] = useState(false)
  const [deleteErr, setDeleteErr] = useState('')
  const seq = useRef(0)

  const load = useCallback(async () => {
    const my = ++seq.current
    setLoading(true)
    try {
      const res = await listOffers()
      if (my !== seq.current) return
      setRows(res.data)
      setErr('')
    } catch (ex) {
      if (my === seq.current) setErr(errorText(ex, 'Nie udało się wczytać ofert.'))
    } finally {
      if (my === seq.current) setLoading(false)
    }
  }, [])

  useEffect(() => {
    void load()
  }, [load])

  async function newOffer() {
    setBusy(true)
    setErr('')
    try {
      const o = await createOffer()
      navigate(`/oferty/${o.id}`)
    } catch (ex) {
      setErr(errorText(ex, 'Nie udało się założyć oferty.'))
      setBusy(false)
    }
  }

  async function confirmDelete() {
    if (!toDelete) return
    setDeleteBusy(true)
    setDeleteErr('')
    try {
      await deleteOffer(toDelete.id)
      setRows((r) => r.filter((x) => x.id !== toDelete.id))
      setToDelete(null)
    } catch (ex) {
      setDeleteErr(errorText(ex, 'Nie udało się usunąć oferty.'))
    } finally {
      setDeleteBusy(false)
    }
  }


  return (
    <>
      <div className="app-page-head mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="app-page-title text-xl font-semibold">Oferty</h1>
          <p className="mt-1 max-w-3xl text-xs text-slate-600">
            Twoje oferty dla klientów: wybrane produkty z ceną netto, wysłane ze skrzynki „Moja poczta” (osobny mail do
            każdego adresu) albo otwarte jako nowa wiadomość w Thunderbirdzie.
            {canInspections && (
              <>
                {' '}
                Oferty ze znacznikiem „Przegląd” przygotowujesz w module{' '}
                <Link to="/przeglady" className="text-blue-600 hover:underline">
                  Przeglądy
                </Link>{' '}
                — to przypomnienie dla jednego klienta, co i kiedy wymaga przeglądu, bez cen.
              </>
            )}
          </p>
        </div>
        {canProducts && (
          <button type="button" className={BTN_PRIMARY} disabled={busy} onClick={() => void newOffer()}>
            + Nowa oferta
          </button>
        )}
      </div>

      <ErrorBar message={err} onClose={() => setErr('')} />

      <div className="overflow-x-auto rounded-xl bg-white p-4 shadow-sm">
        <div className="mb-2 text-right text-xs text-slate-500">
          {loading ? 'ładowanie…' : `Łącznie ${fmtInt(rows.length)}`}
        </div>
        <table className="w-full text-left text-xs">
          <thead>
            <tr className="border-b bg-slate-50">
              <th className="p-2">Oferta</th>
              <th className="p-2 text-right">Pozycje</th>
              <th className="p-2 text-right" title="Adresy, do których mail wyszedł (wszystkie wysyłki razem)">
                Wysłano do
              </th>
              <th className="p-2">Ostatnia wysyłka</th>
              <th className="p-2">Zmieniona</th>
              <th className="p-2" />
            </tr>
          </thead>
          <tbody>
            {rows.map((r) => (
              <tr key={r.id} className="border-b align-top even:bg-slate-100">
                <td className="min-w-[14rem] p-2">
                  <Link to={`/oferty/${r.id}`} className="font-medium text-slate-900 hover:text-blue-700 hover:underline">
                    {r.subject.trim() || <span className="italic text-slate-500">bez tematu</span>}
                  </Link>
                  <div className="mt-0.5 flex flex-wrap items-center gap-1.5">
                    {r.code && <span className="app-code font-mono text-[11px] text-slate-500">{r.code}</span>}
                    {r.kind === 'inspection' && (
                      <Chip tone="blue" title="Oferta przeglądu z modułu Przeglądy — bez cen">
                        Przegląd
                      </Chip>
                    )}
                    {r.customer_name && <span className="text-[11px] text-slate-600">dla {r.customer_name}</span>}
                  </div>
                </td>
                <td
                  className="whitespace-nowrap p-2 text-right tabular-nums"
                  title={r.kind === 'inspection' ? 'Wiersze przeglądu w ofercie' : 'Produkty w ofercie'}
                >
                  {fmtInt(r.items_count)}
                </td>
                <td className="whitespace-nowrap p-2 text-right tabular-nums">
                  {r.recipients_count > 0
                    ? `${fmtInt(r.recipients_count)} ${plural(r.recipients_count, 'adresu', 'adresów', 'adresów')}`
                    : '—'}
                </td>
                <td className="whitespace-nowrap p-2 tabular-nums text-slate-700">{fmtDateTime(r.last_sent_at)}</td>
                <td className="whitespace-nowrap p-2 tabular-nums text-slate-700">{fmtDateTime(r.updated_at)}</td>
                <td className="whitespace-nowrap p-2 text-right">
                  <span className="inline-flex gap-1">
                    <Link to={`/oferty/${r.id}`} className={BTN_SM}>
                      Otwórz
                    </Link>
                    <button
                      type="button"
                      className={`${BTN_SM} text-red-700`}
                      onClick={() => {
                        setDeleteErr('')
                        setToDelete(r)
                      }}
                    >
                      Usuń
                    </button>
                  </span>
                </td>
              </tr>
            ))}
            {rows.length === 0 && (
              <tr>
                <td colSpan={6} className="p-8 text-center text-slate-500">
                  {loading ? (
                    'Ładowanie…'
                  ) : (
                    canProducts ? (
                      <>
                        Nie ma jeszcze ofert. Kliknij „+ Nowa oferta” — produkty dodasz w ofercie wyszukiwarką albo
                        z listy Produktów i Zapasów.
                      </>
                    ) : (
                      <>Nie ma jeszcze ofert. Oferty przeglądu przygotujesz w module Przeglądy przyciskiem „Przygotuj oferty”.</>
                    )
                  )}
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>

      {toDelete && (
        <ConfirmDialog
          title="Usunąć ofertę?"
          danger
          confirmLabel="Usuń ofertę"
          busy={deleteBusy}
          error={deleteErr}
          onClose={() => setToDelete(null)}
          onConfirm={() => void confirmDelete()}
          message={
            <>
              <p>
                <b>
                  {toDelete.code ? `${toDelete.code} ` : ''}
                  {toDelete.subject.trim() || 'bez tematu'}
                </b>
                {toDelete.customer_name ? ` dla ${toDelete.customer_name}` : ''} — {toDelete.items_count}{' '}
                {plural(toDelete.items_count, 'pozycja', 'pozycje', 'pozycji')}. Oferta zniknie
                z listy razem z historią wysyłek. Tego nie da się cofnąć.
              </p>
              {toDelete.recipients_count > 0 && (
                <p className="text-xs text-slate-600">Maile, które już wyszły, zostają u klientów.</p>
              )}
            </>
          }
        />
      )}
    </>
  )
}
