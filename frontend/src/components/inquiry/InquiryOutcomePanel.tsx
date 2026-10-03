import { useEffect, useId, useRef, useState, type KeyboardEvent } from 'react'
import {
  api,
  linkInquiryClient,
  saveInquiryOutcome,
  type InquiryClientLink,
  type InquiryClientLinkSource,
  type InquiryOrderHint,
  type InquiryOutcome,
  type InquiryOutcomeFields,
  type InquiryOutcomeReason,
  type InquiryOutcomeView,
} from '../../lib/api'
import { fmtPrice, shortDate } from '../../lib/reports'

/** Zapytanie w zakresie potrzebnym panelowi wyniku (GET /inquiries/{id} — pola etapu 4). */
export type InquiryOutcomePanelInquiry = { id: number; replied_at: string | null } & InquiryOutcomeFields

const OUTCOME_LABEL: Record<InquiryOutcome, string> = {
  ordered: 'Zamówił',
  partial: 'Zamówił część',
  not_ordered: 'Nie zamówił',
  unknown: 'Nie wiadomo',
}

const OUTCOMES: InquiryOutcome[] = ['ordered', 'partial', 'not_ordered', 'unknown']

const REASON_LABEL: Record<InquiryOutcomeReason, string> = {
  price: 'Cena',
  lead_time: 'Termin dostawy',
  bought_elsewhere: 'Kupił gdzie indziej',
  no_response: 'Klient nie odpowiedział',
}

const REASONS: InquiryOutcomeReason[] = ['price', 'lead_time', 'bought_elsewhere', 'no_response']

/** Reguła powiązania z klientem słowami (InquiryClientLinker::RULES). */
const LINK_RULE: Record<InquiryClientLinkSource, string> = {
  manual: 'wybrane przez handlowca',
  email: 'ten sam adres e-mail co w ERP XL',
  nip: 'NIP z maila',
}

const withReason = (o: InquiryOutcome | null): boolean => o === 'partial' || o === 'not_ordered'
const isOrdered = (o: InquiryOutcome | null): boolean => o === 'ordered' || o === 'partial'

function moment(iso: string | null): string {
  if (!iso) return ''
  const d = new Date(iso)
  return Number.isNaN(d.getTime()) ? iso : d.toLocaleString('pl-PL', { dateStyle: 'short', timeStyle: 'short' })
}

function zl(value: string | null | undefined): string {
  const n = Number(value)
  return Number.isFinite(n) ? fmtPrice(n, 'PLN') : '—'
}

function errorMessage(ex: unknown, fallback: string): string {
  return ex instanceof Error && ex.message ? ex.message : fallback
}

/**
 * Ochrona niezapisanego wyniku: zamknięcie albo odświeżenie karty (beforeunload) i kliknięty link do innej strony
 * aplikacji pytają przed wyjściem (BrowserRouter nie ma useBlocker — nasłuch w fazie przechwytywania działa przed
 * <Link>). Wzór jak wynik przetargu w TenderDetail.
 */
function useLeaveGuard(dirty: boolean, message: string) {
  const dirtyRef = useRef(dirty)
  useEffect(() => {
    dirtyRef.current = dirty
  }, [dirty])
  useEffect(() => {
    if (!dirty) return
    const warn = (e: BeforeUnloadEvent) => {
      if (!dirtyRef.current) return
      e.preventDefault()
      e.returnValue = ''
    }
    const onClick = (e: MouseEvent) => {
      if (e.defaultPrevented || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return
      const anchor = e.target instanceof Element ? e.target.closest('a[href]') : null
      if (!(anchor instanceof HTMLAnchorElement)) return
      if ((anchor.target && anchor.target !== '_self') || anchor.hasAttribute('download')) return
      const url = new URL(anchor.href, window.location.href)
      if (url.origin !== window.location.origin || url.pathname === window.location.pathname) return
      if (!dirtyRef.current) return
      if (window.confirm(message)) {
        dirtyRef.current = false
        return
      }
      e.preventDefault()
      e.stopPropagation()
    }
    window.addEventListener('beforeunload', warn)
    document.addEventListener('click', onClick, true)
    return () => {
      window.removeEventListener('beforeunload', warn)
      document.removeEventListener('click', onClick, true)
    }
  }, [dirty, message])
}

/** Jedna podpowiedź z ERP XL — szara, jako wniosek, z liczbą towarów bez powiązania. */
function HintBox({
  hint,
  chosen,
  inOutcome,
  canEdit,
  onConfirm,
}: {
  hint: InquiryOrderHint
  chosen: boolean
  inOutcome: boolean
  canEdit: boolean
  onConfirm: () => void
}) {
  const unlinked = Math.max(0, hint.offered_items - hint.linked_items)
  return (
    <div className={`rounded-lg border px-3 py-2.5 text-xs ${chosen || inOutcome ? 'border-emerald-300 bg-emerald-50' : 'border-slate-200 bg-slate-50'}`}>
      <p className="font-semibold text-slate-700">Podpowiedź z ERP XL</p>
      <p className="mt-0.5 text-slate-600">
        {shortDate(hint.issued_at)} wystawiono temu klientowi dokument <b className="text-slate-800">{hint.document_number}</b> na {hint.matched_items} z{' '}
        {hint.offered_items} zaoferowanych towarów, {zl(hint.document_net)} netto (w tym towary z oferty: {zl(hint.matched_net)}). Możliwe, że to
        zamówienie z tej oferty. Sprawdź i potwierdź.
      </p>
      {unlinked > 0 && (
        <p className="mt-1 text-[11px] text-slate-500">
          {unlinked} z {hint.offered_items} towarów oferty nie ma powiązania karty z towarem w ERP XL — ich zakupu tu nie widać.
        </p>
      )}
      {inOutcome && !chosen && <p className="mt-1 text-[11px] font-medium text-emerald-800">Ten dokument jest zapisany w wyniku — potwierdził go handlowiec.</p>}
      {canEdit && !inOutcome && (
        <div className="mt-2">
          {chosen ? (
            <span className="text-[11px] font-medium text-emerald-800">Ten dokument zapisze się w wyniku po kliknięciu „Zapisz”.</span>
          ) : (
            <button type="button" onClick={onConfirm} className="rounded border border-slate-300 bg-white px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50">
              Potwierdź ten dokument
            </button>
          )}
        </div>
      )}
    </div>
  )
}

/**
 * „Jak się skończyło zapytanie” (makieta: ekran „Zapytanie: jak się skończyło”) — po wysłaniu odpowiedzi: wynik
 * i powód wpisuje autor zapytania. Podpowiedź z ERP XL jest szara, z regułą; „Potwierdź ten dokument” tylko wskazuje
 * dokument do zapisania razem z wynikiem — niczego nie zapisuje bez kliknięcia „Zapisz”. Bez podpowiedzi — powód.
 */
export function InquiryOutcomePanel({ inquiry, onChanged }: { inquiry: InquiryOutcomePanelInquiry; onChanged: (outcome: InquiryOutcomeView) => void }) {
  const saved = inquiry.outcome
  const hints = inquiry.order_hints
  const canEdit = saved.can_edit
  const groupId = useId()
  const [outcome, setOutcome] = useState<InquiryOutcome | null>(saved.outcome)
  const [reason, setReason] = useState<InquiryOutcomeReason | null>(saved.reason)
  // undefined — dokument bez zmian (klucz hint_id nie idzie); null — zdjąć dokument z wyniku; liczba — podpowiedź
  const [hintId, setHintId] = useState<number | null | undefined>(undefined)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const [msg, setMsg] = useState('')
  const requestRef = useRef(0)

  const effectiveReason = withReason(outcome) ? reason : null
  const dirty = outcome !== saved.outcome || effectiveReason !== (saved.reason ?? null) || hintId !== undefined
  useLeaveGuard(dirty, 'Wynik zapytania ma niezapisane zmiany. Opuścić stronę bez zapisu? Zmiany przepadną.')

  // dokument, który zostanie w wyniku: wskazana podpowiedź, zapisany dokument albo nic
  const chosenHint = typeof hintId === 'number' ? (hints.hints.find((h) => h.id === hintId) ?? null) : null
  const keepsSavedDocument = hintId === undefined && isOrdered(outcome) && saved.document !== null

  function confirmHint(hint: InquiryOrderHint) {
    setHintId(hint.id)
    // dokument ma sens tylko przy zakupie — „zamówił” zaznaczamy, gdy nic o zakupie nie wybrano (człowiek może zmienić)
    if (!isOrdered(outcome)) setOutcome('ordered')
    setMsg('')
  }

  async function save(next: { outcome: InquiryOutcome | null; reason: InquiryOutcomeReason | null; hint_id?: number | null }) {
    const ticket = ++requestRef.current
    setBusy(true)
    setErr('')
    setMsg('')
    try {
      const view = await saveInquiryOutcome(inquiry.id, next)
      if (ticket !== requestRef.current) return
      setOutcome(view.outcome)
      setReason(view.reason)
      setHintId(undefined)
      setMsg('Zapisano.')
      onChanged(view)
    } catch (ex) {
      if (ticket !== requestRef.current) return
      setErr(errorMessage(ex, 'Nie udało się zapisać wyniku.'))
    } finally {
      if (ticket === requestRef.current) setBusy(false)
    }
  }

  function submit() {
    const body: { outcome: InquiryOutcome | null; reason: InquiryOutcomeReason | null; hint_id?: number | null } = { outcome, reason: effectiveReason }
    if (hintId !== undefined) body.hint_id = isOrdered(outcome) ? hintId : null
    void save(body)
  }

  function clearOutcome() {
    if (!window.confirm('Wyczyścić wynik zapytania? Zapytanie wróci do „wysłane, wynik niewpisany”.')) return
    void save({ outcome: null, reason: null, hint_id: null })
  }

  const savedSummary = saved.outcome ? (
    <p className="text-xs text-slate-600">
      Zapisano: <b className="text-slate-800">{OUTCOME_LABEL[saved.outcome]}</b>
      {saved.reason && <> · powód: {REASON_LABEL[saved.reason].toLocaleLowerCase('pl-PL')}</>}
      {saved.by && <> · {saved.by.name}</>}
      {saved.at && <>, {moment(saved.at)}</>}
      {saved.document && (
        <>
          <br />
          Dokument z ERP XL: {saved.document.number} z {shortDate(saved.document.date)}, {zl(saved.document.net_value)} netto
        </>
      )}
    </p>
  ) : (
    <p className="text-xs text-slate-500">Wyniku jeszcze nie wpisano.</p>
  )

  return (
    <section className="rounded-xl bg-white p-4 shadow-sm" aria-labelledby={`${groupId}-title`}>
      <h2 id={`${groupId}-title`} className="text-sm font-semibold">
        Jak się skończyło
      </h2>
      <p className="mt-0.5 text-[11px] text-slate-500">Wynik wpisuje osoba, która prowadzi zapytanie. Aplikacja niczego tu nie wpisuje sama.</p>

      <div className="mt-3 space-y-2">
        {hints.status === 'ok' && hints.hints.length > 0
          ? hints.hints.map((h) => (
              <HintBox
                key={h.id}
                hint={h}
                chosen={chosenHint?.id === h.id}
                inOutcome={hintId === undefined && saved.document?.number === h.document_number}
                canEdit={canEdit}
                onConfirm={() => confirmHint(h)}
              />
            ))
          : hints.status === 'ok' &&
            hints.computed_at && (
              <p className="rounded-lg border border-dashed border-slate-300 px-3 py-2 text-xs text-slate-500">
                Brak podpowiedzi z ERP XL: nocne sprawdzenie ({moment(hints.computed_at)}) nie znalazło faktury ani paragonu tego klienta
                z towarami tej oferty.
              </p>
            )}
        <p className="text-[11px] text-slate-500">{hints.rule}</p>
      </div>

      <div className="mt-3">{savedSummary}</div>

      {canEdit && (
        <div className="mt-3 space-y-3 border-t border-slate-100 pt-3">
          <fieldset>
            <legend className="text-xs font-medium text-slate-700">Wynik</legend>
            <div className="mt-1.5 flex flex-wrap gap-2">
              {OUTCOMES.map((o) => (
                <label
                  key={o}
                  className={`flex cursor-pointer items-center gap-1.5 rounded-md border px-2.5 py-1.5 text-xs ${
                    outcome === o ? 'border-blue-600 bg-blue-50 text-blue-800' : 'border-slate-300 text-slate-700 hover:bg-slate-50'
                  }`}
                >
                  <input type="radio" name={`${groupId}-outcome`} value={o} checked={outcome === o} disabled={busy} onChange={() => setOutcome(o)} />
                  {OUTCOME_LABEL[o]}
                </label>
              ))}
            </div>
          </fieldset>

          {withReason(outcome) && (
            <label className="block text-xs text-slate-700">
              {outcome === 'partial' ? 'Dlaczego nie całość' : 'Dlaczego nie zamówił'}
              <select
                className="mt-1 block rounded border border-slate-300 px-2 py-1.5 text-sm"
                value={reason ?? ''}
                disabled={busy}
                onChange={(e) => setReason(e.target.value === '' ? null : (e.target.value as InquiryOutcomeReason))}
              >
                <option value="">— nie podano —</option>
                {REASONS.map((r) => (
                  <option key={r} value={r}>
                    {REASON_LABEL[r]}
                  </option>
                ))}
              </select>
            </label>
          )}

          {isOrdered(outcome) && (chosenHint || keepsSavedDocument) && (
            <p className="text-xs text-slate-600">
              Dokument w wyniku: {chosenHint ? chosenHint.document_number : saved.document?.number}{' '}
              <button type="button" disabled={busy} onClick={() => setHintId(null)} className="ml-1 text-blue-700 underline disabled:opacity-50">
                bez dokumentu
              </button>
            </p>
          )}
          {hintId === null && saved.document && isOrdered(outcome) && (
            <p className="text-xs text-amber-800">Po zapisie dokument {saved.document.number} zniknie z wyniku.</p>
          )}

          <div className="flex flex-wrap items-center justify-end gap-2">
            {busy && <span className="text-[11px] text-slate-400">Zapisuję…</span>}
            {msg && !dirty && <span className="text-[11px] text-emerald-700">{msg}</span>}
            {saved.outcome && (
              <button type="button" disabled={busy} onClick={clearOutcome} className="rounded border border-slate-300 px-3 py-1.5 text-xs text-slate-700 hover:bg-slate-50 disabled:opacity-50">
                Wyczyść wynik
              </button>
            )}
            <button
              type="button"
              disabled={busy || !dirty || outcome === null}
              onClick={submit}
              className="rounded bg-emerald-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-emerald-700 disabled:opacity-50"
            >
              Zapisz
            </button>
          </div>
          {err && (
            <p className="rounded bg-red-50 px-3 py-2 text-xs text-red-700" role="alert">
              {err}
            </p>
          )}
        </div>
      )}
    </section>
  )
}

type ClientOption = { id: number; name: string; nip: string | null; city: string | null }

/**
 * Powiązanie zapytania z klientem z zakładki Klienci — pokazuje regułę słowami; autor może wybrać klienta (lista
 * z podpowiedziami) albo zostawić zapytanie świadomie bez klienta. Wybór handlowca jest ręczny: nocne powiązania
 * automatyczne (ten sam adres e-mail, NIP z maila) już go nie zmienią.
 */
export function InquiryClientLinkBox({
  inquiryId,
  link,
  canEdit,
  canPick,
  onChanged,
}: {
  inquiryId: number
  link: InquiryClientLink | null
  canEdit: boolean
  canPick: boolean
  onChanged: (link: InquiryClientLink | null) => void
}) {
  const [picking, setPicking] = useState(false)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  async function save(clientId: number | null) {
    setBusy(true)
    setErr('')
    try {
      const res = await linkInquiryClient(inquiryId, clientId)
      setPicking(false)
      onChanged(res.client_link)
    } catch (ex) {
      setErr(errorMessage(ex, 'Nie udało się zapisać klienta zapytania.'))
    } finally {
      setBusy(false)
    }
  }

  function withoutClient() {
    if (!window.confirm('Zostawić zapytanie bez klienta? Nocne powiązanie po adresie e-mail albo NIP-ie już go nie zmieni.')) return
    void save(null)
  }

  return (
    <div className="mt-1 text-[11px] text-slate-500">
      <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
        {link ? (
          <span>
            Klient: <b className="font-medium text-slate-700">{link.client.name}</b> <span className="text-slate-400">({LINK_RULE[link.source]})</span>
          </span>
        ) : (
          <span>Zapytanie nie jest powiązane z klientem z zakładki Klienci.</span>
        )}
        {canEdit && !picking && canPick && (
          <button type="button" disabled={busy} onClick={() => setPicking(true)} className="text-blue-700 underline disabled:opacity-50">
            {link ? 'Zmień klienta' : 'Powiąż z klientem'}
          </button>
        )}
        {canEdit && !picking && link && (
          <button type="button" disabled={busy} onClick={withoutClient} className="text-blue-700 underline disabled:opacity-50">
            Bez klienta
          </button>
        )}
      </div>
      {!link && (
        <p className="mt-0.5">Wiążemy tylko pewnie: ten sam adres e-mail co w ERP XL, NIP z maila albo wybór handlowca — nigdy po samej domenie.</p>
      )}
      {picking && <ClientPicker disabled={busy} onPick={(c) => void save(c.id)} onCancel={() => setPicking(false)} />}
      {err && (
        <p className="mt-1 rounded bg-red-50 px-2 py-1 text-xs text-red-700" role="alert">
          {err}
        </p>
      )}
    </div>
  )
}

/** Wybór klienta z listy z podpowiedziami: strzałki, Enter, Escape; wpisany tekst bez wyboru zostaje w polu. */
function ClientPicker({ disabled, onPick, onCancel }: { disabled: boolean; onPick: (client: ClientOption) => void; onCancel: () => void }) {
  const listId = useId()
  const [clients, setClients] = useState<ClientOption[] | null>(null)
  const [loadErr, setLoadErr] = useState('')
  const [query, setQuery] = useState('')
  const [open, setOpen] = useState(true)
  const [active, setActive] = useState(0)
  const [reload, setReload] = useState(0)
  const inputRef = useRef<HTMLInputElement>(null)

  useEffect(() => {
    let cancelled = false
    setLoadErr('')
    api<ClientOption[]>('/clients')
      .then((rows) => {
        if (!cancelled) setClients(rows.map((c) => ({ id: c.id, name: c.name, nip: c.nip ?? null, city: c.city ?? null })))
      })
      .catch((ex) => {
        if (!cancelled) setLoadErr(errorMessage(ex, 'Nie udało się wczytać listy klientów.'))
      })
    return () => {
      cancelled = true
    }
  }, [reload])

  useEffect(() => {
    inputRef.current?.focus()
  }, [])

  const typed = query.trim().toLocaleLowerCase('pl-PL')
  const digits = query.replace(/\D/g, '')
  const hits = (clients ?? [])
    .filter((c) =>
      typed === ''
        ? true
        : c.name.toLocaleLowerCase('pl-PL').includes(typed) ||
          (digits.length >= 3 && (c.nip ?? '').replace(/\D/g, '').includes(digits)) ||
          (c.city ?? '').toLocaleLowerCase('pl-PL').includes(typed),
    )
    .slice(0, 30)
  const activeIndex = hits.length > 0 ? Math.min(active, hits.length - 1) : -1
  const expanded = open && !disabled && clients !== null

  function onKeyDown(e: KeyboardEvent<HTMLInputElement>) {
    if (e.key === 'Escape') {
      e.preventDefault()
      if (expanded) setOpen(false)
      else onCancel()
      return
    }
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault()
      if (!expanded) {
        setOpen(true)
        return
      }
      if (hits.length === 0) return
      const step = e.key === 'ArrowDown' ? 1 : -1
      setActive((activeIndex + step + hits.length) % hits.length)
      return
    }
    if (e.key === 'Enter') {
      e.preventDefault()
      if (expanded && activeIndex >= 0) onPick(hits[activeIndex])
    }
  }

  return (
    <div
      className="relative mt-1 max-w-md"
      onBlur={(e) => {
        // lista zamyka się dopiero, gdy fokus wychodzi poza całe pole wyboru; wpisany tekst zostaje w polu
        if (e.relatedTarget instanceof Node && e.currentTarget.contains(e.relatedTarget)) return
        setOpen(false)
      }}
    >
      <div className="flex items-center gap-2">
        <input
          ref={inputRef}
          aria-label="Klient zapytania"
          role="combobox"
          aria-autocomplete="list"
          aria-expanded={expanded}
          aria-controls={expanded ? listId : undefined}
          aria-activedescendant={expanded && activeIndex >= 0 ? `${listId}-${activeIndex}` : undefined}
          className="block w-full rounded border border-slate-300 px-2 py-1 text-xs text-slate-800"
          value={query}
          disabled={disabled}
          placeholder={clients === null && !loadErr ? 'Wczytuję klientów…' : 'Szukaj po nazwie, NIP-ie albo mieście'}
          onFocus={() => setOpen(true)}
          onKeyDown={onKeyDown}
          onChange={(e) => {
            setQuery(e.target.value)
            setActive(0)
            setOpen(true)
          }}
        />
        <button type="button" onClick={onCancel} className="shrink-0 rounded border border-slate-300 px-2 py-1 text-xs text-slate-700 hover:bg-slate-50">
          Anuluj
        </button>
      </div>
      {!open && query.trim() !== '' && <p className="mt-0.5 text-[11px] text-amber-800">Wpisany tekst nie jest zapisany — wybierz klienta z listy.</p>}
      {loadErr && (
        <p className="mt-1 text-xs text-red-700" role="alert">
          {loadErr}{' '}
          <button type="button" className="underline" onClick={() => setReload((n) => n + 1)}>
            Spróbuj ponownie
          </button>
        </p>
      )}
      {expanded && (
        <div
          tabIndex={-1}
          onMouseDown={(e) => e.preventDefault()}
          className="absolute z-20 mt-1 max-h-64 w-full overflow-auto rounded border border-slate-200 bg-white text-xs shadow-lg"
        >
          {hits.length === 0 && <p className="px-2 py-1 text-slate-500">{typed ? 'Brak takiego klienta.' : 'Lista klientów jest pusta.'}</p>}
          <ul id={listId} role="listbox" aria-label="Klienci">
            {hits.map((c, i) => (
              <li
                key={c.id}
                id={`${listId}-${i}`}
                role="option"
                aria-selected={i === activeIndex}
                className={`cursor-pointer px-2 py-1.5 ${i === activeIndex ? 'bg-slate-100' : 'hover:bg-slate-50'}`}
                onMouseEnter={() => setActive(i)}
                onClick={() => onPick(c)}
              >
                <span className="text-slate-800">{c.name}</span>
                {(c.nip || c.city) && <span className="ml-1 text-slate-500">{[c.nip ? `NIP ${c.nip}` : null, c.city].filter(Boolean).join(' · ')}</span>}
              </li>
            ))}
          </ul>
        </div>
      )}
    </div>
  )
}
