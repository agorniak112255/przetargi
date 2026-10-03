import { useCallback, useEffect, useRef, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useAuth } from '../auth'
import { ApiError, can, createClientNote, fetchClientCard, type ClientCard, type TimelineEvent } from '../lib/api'
import { errorText, fmtDate, fmtDateTime, fmtInt, fmtQty } from '../lib/campaignFormat'
import { formatPln } from '../lib/campaigns'
import { plural } from '../lib/plural'
import { KpiRow, type Kpi } from '../components/ReportKit'
import { ClientFields } from '../components/client/ClientFields'
import { ClientNoteForm } from '../components/client/ClientNoteForm'
import { ClientOwnerPicker } from '../components/client/ClientOwnerPicker'
import { ClientTimeline } from '../components/client/ClientTimeline'
import { nipText, polishToday } from '../components/client/clientText'

type NoteEvent = Extract<TimelineEvent, { type: 'note' }>

/**
 * Karta klienta z historią współpracy (/clients/:id, ekran 12 makiety): opiekun w ERP XL i opiekun w aplikacji
 * osobno, kafelki (zakupy netto w roku, ostatni zakup, ostatnie 12 miesięcy), oś czasu, notatki z przypomnieniem
 * i „Najczęściej kupuje (24 miesiące)”. Dane z ERP XL to nocna kopia — strona nie pyta XL na żywo.
 * Zaległych płatności nie ma: aplikacja nie ma prawa odczytu rozrachunków w ERP XL.
 */
export function ClientDetail() {
  const { id } = useParams()
  const clientId = Number(id)
  const { user } = useAuth()
  const [card, setCard] = useState<ClientCard | null>(null)
  const [error, setError] = useState('')
  const [notFound, setNotFound] = useState(false)
  const [addedNote, setAddedNote] = useState<NoteEvent | null>(null)
  const [showFields, setShowFields] = useState(false)
  const requestRef = useRef(0)

  // odświeżenie po zmianie opiekuna — w tle, bez chowania formularzy (niezapisana notatka zostaje)
  const loadCard = useCallback(
    async (signal?: AbortSignal) => {
      const request = ++requestRef.current
      try {
        const data = await fetchClientCard(clientId, signal)
        if (request !== requestRef.current) return
        setCard(data)
        setError('')
      } catch (ex) {
        if (signal?.aborted || request !== requestRef.current) return
        if (ex instanceof ApiError && ex.status === 404) setNotFound(true)
        else setError(errorText(ex, 'Nie udało się wczytać karty klienta.'))
      }
    },
    [clientId],
  )

  useEffect(() => {
    if (!Number.isInteger(clientId) || clientId <= 0) {
      setNotFound(true)
      return
    }
    const ctrl = new AbortController()
    setCard(null)
    setNotFound(false)
    setError('')
    setAddedNote(null)
    void loadCard(ctrl.signal)
    return () => ctrl.abort()
  }, [clientId, loadCard])

  if (notFound) {
    return (
      <div>
        <BackLink />
        <p className="text-sm text-slate-600">Nie ma takiego klienta.</p>
      </div>
    )
  }
  if (!card) {
    return (
      <div>
        <BackLink />
        {error ? (
          <p className="rounded bg-red-50 px-3 py-2 text-xs text-red-700">
            {error}{' '}
            <button type="button" onClick={() => void loadCard()} className="underline">
              Spróbuj ponownie
            </button>
          </p>
        ) : (
          <p className="text-sm text-slate-500">Wczytuję kartę klienta…</p>
        )}
      </div>
    )
  }

  const c = card.client
  const hasXl = c.xl_gid != null
  const synced = card.documents_synced_at !== null
  const ownerOptions = card.owner_options ?? []

  return (
    <div>
      <BackLink />
      <header className="mb-4">
        <h1 className="app-page-title text-xl font-semibold">{c.name}</h1>
        <p className="mt-1 text-xs text-slate-600">
          {[nipText(c) ? `NIP ${nipText(c)}` : null, c.city].filter(Boolean).join(' · ')}
          {c.xl_archived && <span className="ml-2 rounded bg-amber-100 px-1 text-[10px] text-amber-800">archiwalny w ERP XL</span>}
          {!hasXl && <span className="ml-2 text-slate-500">klient dopisany ręcznie (bez powiązania z ERP XL)</span>}
        </p>
        <dl className="mt-1 flex flex-wrap gap-x-5 gap-y-1 text-xs">
          <div>
            <dt className="inline text-slate-500">Opiekun w ERP XL: </dt>
            <dd className="inline">
              {card.xl_manager?.name ?? card.xl_manager?.email ?? <span className="text-slate-500">brak</span>}
              {card.xl_manager?.user && card.xl_manager.user.name !== card.xl_manager.name && (
                <span className="text-slate-500"> (konto: {card.xl_manager.user.name})</span>
              )}
            </dd>
          </div>
          <div>
            <dt className="inline text-slate-500">Opiekun w aplikacji: </dt>
            <dd className="inline">
              <ClientOwnerPicker
                clientId={clientId}
                owner={card.app_owner}
                canManage={card.can_manage}
                loadOptions={() => Promise.resolve(ownerOptions)}
                onSaved={(saved) => {
                  const owner = saved.owner ?? null
                  setCard((prev) => (prev ? { ...prev, app_owner: owner, client: { ...prev.client, owner_id: saved.owner_id, owner } } : prev))
                  // przypisanie do celów mogło się zmienić (opiekun w aplikacji liczy się, gdy opiekun z ERP XL nie ma konta)
                  void loadCard()
                }}
              />
            </dd>
          </div>
          <div>
            <dt className="inline text-slate-500">Sprzedaż liczy się do celów: </dt>
            <dd className="inline">
              {card.assignment.user ? (
                <>
                  {card.assignment.user.name}{' '}
                  <span className="text-slate-500">
                    ({card.assignment.source === 'xl' ? 'opiekun z ERP XL przypisany do konta' : 'opiekun w aplikacji'})
                  </span>
                </>
              ) : (
                <span className="text-slate-500">nikogo — klient bez opiekuna przypisanego do konta</span>
              )}
            </dd>
          </div>
        </dl>
        <button type="button" onClick={() => setShowFields((v) => !v)} aria-expanded={showFields} className="mt-2 text-xs text-blue-700 hover:underline">
          {showFields ? '▾ Ukryj dane klienta i osoby kontaktowe' : '▸ Dane klienta i osoby kontaktowe'}
        </button>
        {showFields && (
          <div className="mt-2 overflow-hidden rounded-xl border border-slate-200">
            <ClientFields c={c} owner={card.app_owner?.name ?? null} />
          </div>
        )}
      </header>

      {error && <p className="mb-3 rounded bg-red-50 px-3 py-2 text-xs text-red-700">{error}</p>}
      <KpiRow items={tiles(card, hasXl, synced)} />

      <div className="flex flex-wrap items-start gap-4">
        <ClientTimeline clientId={clientId} card={card} addedNote={addedNote} />
        <aside className="app-card min-w-0 space-y-4 rounded-xl bg-white p-4 shadow-sm" style={{ flex: '1 1 260px' }}>
          <section>
            <h2 className="app-card-title mb-2 text-sm font-semibold text-slate-900">Dodaj notatkę</h2>
            <ClientNoteForm
              submitLabel="Zapisz notatkę"
              onSubmit={async (input) => {
                const saved = await createClientNote(clientId, { body: input.body ?? '', remind_on: input.remind_on ?? null })
                if (saved.type === 'note') setAddedNote(saved)
              }}
            />
            <p className="mt-2 text-[11px] text-slate-500">Notatki widzą wszyscy, którzy mają dostęp do kart klientów.</p>
          </section>
          <section>
            <h3 className="mb-1 text-sm font-semibold text-slate-900">Najczęściej kupuje (24 miesiące)</h3>
            {!hasXl ? (
              <p className="text-xs text-slate-500">Klient spoza ERP XL — brak danych o zakupach.</p>
            ) : card.top_items.length === 0 ? (
              <p className="text-xs text-slate-500">Brak zakupów w ERP XL z ostatnich 24 miesięcy (albo nocny odczyt jeszcze ich nie przyniósł).</p>
            ) : (
              <ul className="divide-y divide-slate-100 text-xs">
                {card.top_items.map((item) => (
                  <li key={item.erp_item_id} className="flex gap-2 py-1.5">
                    <div className="min-w-0 grow">
                      <p className="break-words">{item.name}</p>
                      <p className="text-[11px] text-slate-500">
                        <span className="app-code">{item.code}</span> · {fmtInt(item.documents)} {plural(item.documents, 'dokument', 'dokumenty', 'dokumentów')}
                        {item.last_sale_at ? ` · ostatnio ${fmtDate(item.last_sale_at)}` : ''}
                        {item.product &&
                          (can(user, 'products.view') ? (
                            <>
                              {' · '}
                              <Link to={`/products/${item.product.id}`} className="text-blue-700 hover:underline">
                                karta w katalogu
                              </Link>
                            </>
                          ) : null)}
                      </p>
                    </div>
                    <span className="shrink-0 text-right text-slate-600 tabular-nums">{fmtQty(Number(item.quantity), item.unit)}</span>
                  </li>
                ))}
              </ul>
            )}
            {hasXl && <p className="mt-1 text-[11px] text-slate-500">Faktury i paragony z ERP XL, według liczby dokumentów; odczyt co noc.</p>}
          </section>
        </aside>
      </div>
    </div>
  )
}

function BackLink() {
  return (
    <p className="mb-3 text-sm">
      <Link to="/clients" className="text-blue-700 hover:underline">
        ← Klienci
      </Link>
    </p>
  )
}

function tiles(card: ClientCard, hasXl: boolean, synced: boolean): Kpi[] {
  const { sales_year: sales, last_sale: last, last_12m: year } = card.tiles
  const pending = hasXl && !synced
  const afterRead = 'faktury pojawią się po nocnym odczycie z ERP XL'
  const docs = (n: number) => `${fmtInt(n)} ${plural(n, 'faktura lub paragon', 'faktury lub paragony', 'faktur lub paragonów')}`

  const salesTile: Kpi = {
    label: `Zakupy netto ${sales?.year ?? polishToday().slice(0, 4)}`,
    value: sales ? formatPln(Number(sales.net)) : '—',
    sub: !hasXl
      ? 'klient spoza ERP XL'
      : !sales
        ? 'brak zakupów w tym roku'
        : pending
          ? `${docs(sales.documents)} — z zakładki Klienci; ${afterRead}`
          : `${docs(sales.documents)}, po korektach; stan z ${fmtDateTime(card.documents_synced_at)}`,
  }

  const lastTile: Kpi = {
    label: 'Ostatni zakup',
    value: last ? fmtDate(last.date) : '—',
    sub: last
      ? (last.document_number ?? (pending ? `numer dokumentu — ${afterRead}` : undefined))
      : hasXl
        ? 'brak zakupów w ERP XL'
        : 'klient spoza ERP XL',
  }

  // zapytania i przetargi tylko z uprawnieniem do tych modułów (null — nie pokazujemy)
  const counts: string[] = []
  const names: string[] = []
  if (year.inquiries !== null) {
    counts.push(fmtInt(year.inquiries))
    names.push('zapytania')
  }
  if (year.tenders !== null) {
    counts.push(fmtInt(year.tenders))
    names.push('przetargi')
  }
  const details: string[] = []
  if (names.length > 0) details.push(names.join(' · '))
  if (year.ordered_inquiries) {
    details.push(`zamówił po ${fmtInt(year.ordered_inquiries)} ${plural(year.ordered_inquiries, 'zapytaniu', 'zapytaniach', 'zapytaniach')} (wpisane przez handlowca)`)
  }
  if (hasXl) details.push(synced ? docs(year.invoices) : afterRead)
  const yearTile: Kpi = {
    label: 'Ostatnie 12 miesięcy',
    value: counts.length > 0 ? counts.join(' · ') : synced && hasXl ? fmtInt(year.invoices) : '—',
    sub: details.join('; ') || undefined,
  }

  return [salesTile, lastTile, yearTile]
}
