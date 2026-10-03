import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../../auth'
import {
  canAny,
  deleteClientNote,
  fetchClientTimeline,
  updateClientNote,
  type ClientCard,
  type ClientTimelineType,
  type TimelineEvent,
} from '../../lib/api'
import { errorText, fmtDate, fmtInt } from '../../lib/campaignFormat'
import { formatPln } from '../../lib/campaigns'
import { plural } from '../../lib/plural'
import { RESULT_STATUS_CLASS, RESULT_STATUS_LABEL } from '../../lib/tenderResult'
import { tenderStatusLabel } from '../../lib/tenderStatus'
import { NavIcon, type NavIconName } from '../NavIcon'
import { ClientNoteForm } from './ClientNoteForm'
import { DOCUMENT_KIND_LABEL, LINK_RULE_LABEL, OUTCOME_LABEL, elapsedText } from './clientText'

type NoteEvent = Extract<TimelineEvent, { type: 'note' }>

const TABS: { key: ClientTimelineType; label: string; section?: keyof ClientCard['sections'] }[] = [
  { key: 'all', label: 'Wszystko' },
  { key: 'invoices', label: 'Faktury' },
  { key: 'inquiries', label: 'Zapytania', section: 'inquiries' },
  { key: 'tenders', label: 'Przetargi', section: 'tenders' },
  { key: 'campaigns', label: 'Kampanie', section: 'campaigns' },
  { key: 'notes', label: 'Notatki' },
]

const SOURCE_LABEL: Record<string, string> = {
  invoices: 'faktur i paragonów',
  inquiries: 'zapytań',
  tenders: 'przetargów',
  campaigns: 'kampanii',
  notes: 'notatek',
}

const ICON: Record<TimelineEvent['type'], { name: NavIconName; className: string }> = {
  note: { name: 'chat', className: 'bg-slate-100 text-slate-600' },
  invoice: { name: 'inventory', className: 'bg-emerald-50 text-emerald-700' },
  inquiry: { name: 'inquiries', className: 'bg-blue-50 text-blue-700' },
  tender: { name: 'tenders', className: 'bg-amber-50 text-amber-700' },
  campaign: { name: 'campaigns', className: 'bg-blue-50 text-blue-700' },
}

/**
 * „Historia współpracy” na karcie klienta (ekran 12): oś czasu z filtrami. Zakładki sekcji bez uprawnienia nie ma
 * (serwer i tak ich nie poda). Notatkę zmienia i usuwa autor albo osoba z clients.manage (can_edit z serwera).
 * Nowa notatka z formularza obok trafia na górę listy bez przeładowania (niezapisana edycja innej notatki zostaje).
 */
export function ClientTimeline({
  clientId,
  card,
  addedNote,
}: {
  clientId: number
  card: ClientCard
  /** notatka dopisana formularzem obok — wstawiana na górę osi */
  addedNote: NoteEvent | null
}) {
  const { user } = useAuth()
  const [type, setType] = useState<ClientTimelineType>('all')
  const [events, setEvents] = useState<TimelineEvent[] | null>(null)
  const [truncated, setTruncated] = useState<Record<string, boolean>>({})
  const [error, setError] = useState('')
  const [attempt, setAttempt] = useState(0)
  const [editingId, setEditingId] = useState<number | null>(null)

  useEffect(() => {
    const ctrl = new AbortController()
    setEvents(null)
    setError('')
    fetchClientTimeline(clientId, type, ctrl.signal)
      .then((res) => {
        setEvents(res.data)
        setTruncated(res.truncated)
      })
      .catch((ex: unknown) => {
        if (ctrl.signal.aborted) return
        setError(errorText(ex, 'Nie udało się wczytać historii współpracy.'))
      })
    return () => ctrl.abort()
  }, [clientId, type, attempt])

  useEffect(() => {
    if (!addedNote || (type !== 'all' && type !== 'notes')) return
    setEvents((list) => (list && !list.some((e) => e.type === 'note' && e.id === addedNote.id) ? [addedNote, ...list] : list))
  }, [addedNote, type])

  function changeTab(next: ClientTimelineType) {
    if (next === type) return
    if (editingId !== null && !window.confirm('Edytowana notatka nie jest zapisana. Przejść do innej zakładki bez zapisu?')) return
    setEditingId(null)
    setType(next)
  }

  async function saveNote(note: NoteEvent, input: Parameters<typeof updateClientNote>[2]) {
    const saved = await updateClientNote(clientId, note.id, input)
    setEvents((list) => (list ? list.map((e) => (e.type === 'note' && e.id === note.id ? saved : e)) : list))
    setEditingId(null)
  }

  async function removeNote(note: NoteEvent) {
    if (!window.confirm('Usunąć tę notatkę? Tej operacji nie można cofnąć.')) return
    try {
      await deleteClientNote(clientId, note.id)
      setEvents((list) => (list ? list.filter((e) => !(e.type === 'note' && e.id === note.id)) : list))
    } catch (ex) {
      window.alert(errorText(ex, 'Nie udało się usunąć notatki.'))
    }
  }

  const tabs = TABS.filter((t) => !t.section || card.sections[t.section])
  const cut = Object.entries(truncated).filter(([, v]) => v).map(([k]) => SOURCE_LABEL[k] ?? k)
  const showInvoiceNotice = (type === 'all' || type === 'invoices') && card.documents_synced_at === null && card.client.xl_gid != null
  const canOpenCampaign = canAny(user, ['campaigns.use', 'campaigns.view'])

  return (
    <section className="app-card min-w-0 rounded-xl bg-white p-4 shadow-sm" style={{ flex: '2 1 460px' }}>
      <h2 className="app-card-title mb-2 text-sm font-semibold text-slate-900">Historia współpracy</h2>
      <div className="mb-3 flex flex-wrap gap-1 text-xs" role="group" aria-label="Rodzaj wpisów">
        {tabs.map((t) => (
          <button
            key={t.key}
            type="button"
            aria-pressed={type === t.key}
            onClick={() => changeTab(t.key)}
            className={`rounded-full border px-3 py-1 ${type === t.key ? 'border-blue-600 bg-blue-600 text-white' : 'border-slate-300 hover:bg-slate-50'}`}
          >
            {t.label}
          </button>
        ))}
      </div>
      <p className="mb-2 text-[11px] text-slate-500">
        Ostatnie 24 miesiące, najnowsze na górze. Zapytania tylko powiązane z klientem na pewno (ten sam adres e-mail co w ERP XL, NIP z maila
        albo wybór handlowca) — bez zgadywania po domenie adresu.
      </p>
      {showInvoiceNotice && (
        <p className="mb-2 rounded bg-amber-50 px-2 py-1.5 text-xs text-amber-800">Faktury pojawią się po nocnym odczycie z ERP XL.</p>
      )}
      {card.client.xl_gid == null && (type === 'all' || type === 'invoices') && (
        <p className="mb-2 rounded bg-slate-50 px-2 py-1.5 text-xs text-slate-600">
          Klient dopisany ręcznie, bez powiązania z kontrahentem w ERP XL — faktur i paragonów nie ma skąd pokazać.
        </p>
      )}
      {error ? (
        <p className="rounded bg-red-50 px-2 py-1.5 text-xs text-red-700">
          {error}{' '}
          <button type="button" onClick={() => setAttempt((n) => n + 1)} className="underline">
            Spróbuj ponownie
          </button>
        </p>
      ) : events === null ? (
        <p className="text-xs text-slate-500">Wczytuję…</p>
      ) : events.length === 0 ? (
        <p className="text-xs text-slate-500">Brak wpisów w tym widoku.</p>
      ) : (
        <ol className="space-y-3">
          {events.map((e) => (
            <li key={`${e.type}-${e.id}`} className="grid grid-cols-[5.5rem_1.75rem_minmax(0,1fr)] gap-2 text-xs">
              <span className="pt-1 text-slate-500 tabular-nums">{fmtDate(e.date)}</span>
              <span className={`flex h-7 w-7 items-center justify-center rounded-full ${ICON[e.type].className}`} aria-hidden>
                <NavIcon name={ICON[e.type].name} className="h-4 w-4" />
              </span>
              <div className="min-w-0 pt-1">
                {e.type === 'note' ? (
                  editingId === e.id ? (
                    <ClientNoteForm
                      compact
                      initial={{ body: e.body, remind_on: e.remind_on }}
                      submitLabel="Zapisz zmiany"
                      onSubmit={(input) => saveNote(e, input)}
                      onCancel={() => setEditingId(null)}
                    />
                  ) : (
                    <NoteItem note={e} onEdit={() => setEditingId(e.id)} onDelete={() => void removeNote(e)} editingOther={editingId !== null} />
                  )
                ) : (
                  <EventBody event={e} canOpenCampaign={canOpenCampaign} />
                )}
              </div>
            </li>
          ))}
        </ol>
      )}
      {cut.length > 0 && (
        <p className="mt-3 text-[11px] text-slate-500">Pokazano po 200 najnowszych wpisów — starsze pominięte dla: {cut.join(', ')}.</p>
      )}
    </section>
  )
}

function NoteItem({ note, onEdit, onDelete, editingOther }: { note: NoteEvent; onEdit: () => void; onDelete: () => void; editingOther: boolean }) {
  return (
    <>
      <p>
        <strong>Notatka</strong>
        {note.author ? <span className="text-slate-600"> · {note.author.name}</span> : <span className="text-slate-500"> · konto usunięte</span>}
        {note.remind_on && <span className="text-slate-500"> · przypomnienie {fmtDate(note.remind_on)}</span>}
      </p>
      <p className="whitespace-pre-wrap break-words text-slate-800">{note.body}</p>
      {note.can_edit && !editingOther && (
        <p className="mt-0.5 flex gap-3">
          <button type="button" onClick={onEdit} className="text-blue-700 hover:underline">
            Zmień
          </button>
          <button type="button" onClick={onDelete} className="text-red-700 hover:underline">
            Usuń
          </button>
        </p>
      )}
    </>
  )
}

function EventBody({ event, canOpenCampaign }: { event: Exclude<TimelineEvent, NoteEvent>; canOpenCampaign: boolean }) {
  switch (event.type) {
    case 'invoice': {
      const value = Number(event.net_value)
      return (
        <>
          <p>
            <strong>
              {DOCUMENT_KIND_LABEL[event.kind] ?? 'Dokument'} {event.document_number}
            </strong>
            <span className={value < 0 ? 'text-red-700' : 'text-slate-600'}> · {formatPln(value)} netto</span>
          </p>
          {event.confirmed_inquiry && (
            <p className="text-slate-500">
              Potwierdzone przez handlowca jako zamówienie z{' '}
              {event.confirmed_inquiry.can_open ? (
                <Link to={`/inquiries/${event.confirmed_inquiry.id}`} className="text-blue-700 hover:underline">
                  zapytania z {fmtDate(event.confirmed_inquiry.date)}
                </Link>
              ) : (
                // cudze zapytanie bez uprawnienia do otwierania cudzych — sama data, bez linku
                <>zapytania z {fmtDate(event.confirmed_inquiry.date)}</>
              )}
            </p>
          )}
        </>
      )
    }
    case 'inquiry': {
      const title = event.subject ?? 'bez tematu'
      const replied = event.replied_at ? elapsedText(event.date, event.replied_at) : null
      return (
        <>
          <p>
            <strong>Zapytanie</strong> ·{' '}
            {event.can_open ? (
              <Link to={`/inquiries/${event.id}`} className="text-blue-700 hover:underline">
                {title}
              </Link>
            ) : (
              title
            )}
            {event.items_count > 0 && (
              <span className="text-slate-600">
                {' '}
                · {event.items_count} {plural(event.items_count, 'pozycja', 'pozycje', 'pozycji')}
              </span>
            )}
          </p>
          <p className="text-slate-500">
            {event.replied_at ? `Odpowiedź ${replied ? `po ${replied}` : `z ${fmtDate(event.replied_at)}`}` : 'Bez odpowiedzi'}
            {event.outcome ? ` · ${OUTCOME_LABEL[event.outcome]} (wpisał handlowiec)` : ''} · {event.user.name} · powiązanie: {LINK_RULE_LABEL[event.link_source]}
          </p>
        </>
      )
    }
    case 'tender':
      return (
        <p>
          <strong>Przetarg</strong>{' '}
          <Link to={event.url} className="text-blue-700 hover:underline">
            {event.number}
          </Link>
          <span className="text-slate-600"> · {event.title}</span>
          <span className="text-slate-500"> · {tenderStatusLabel(event.status)}</span>
          {event.result_status && (
            <span className={`ml-1 rounded border px-1.5 py-0.5 text-[11px] ${RESULT_STATUS_CLASS[event.result_status]}`}>
              {RESULT_STATUS_LABEL[event.result_status]}
            </span>
          )}
        </p>
      )
    case 'campaign':
      return (
        <>
          <p>
            <strong>Kampania</strong>{' '}
            {canOpenCampaign ? (
              <Link to={`/kampanie/${event.id}`} className="text-blue-700 hover:underline">
                „{event.name}”
              </Link>
            ) : (
              <>„{event.name}”</>
            )}
          </p>
          <p className="text-slate-500">
            {event.clicks > 0 ? `kliknął ${fmtInt(event.clicks)} ${plural(event.clicks, 'raz', 'razy', 'razy')}` : 'bez kliknięć'} ·{' '}
            {event.replied ? 'odpowiedział' : 'nie odpowiedział'} · wysłał {event.user.name}
          </p>
        </>
      )
  }
}
