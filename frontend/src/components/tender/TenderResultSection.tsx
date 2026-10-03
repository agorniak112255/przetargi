import { useCallback, useEffect, useId, useMemo, useRef, useState, type KeyboardEvent } from 'react'
import {
  ApiError,
  checkTenderBzp,
  deleteTenderLot,
  fetchTenderResult,
  saveTenderResult,
  searchCompetitors,
  type BzpNoticeRef,
  type Competitor,
  type CompetitorInput,
  type LossReason,
  type LotOutcome,
  type TenderLot,
  type TenderLotUpdate,
  type TenderResultResponse,
} from '../../lib/api'
import {
  LOSS_REASONS,
  LOSS_REASON_LABEL,
  LOT_FIELD_LABEL,
  LOT_OUTCOMES,
  LOT_OUTCOME_CLASS,
  LOT_OUTCOME_LABEL,
  RESULT_STATUS_CLASS,
  amountToInput,
  formatMoney,
  formatVat,
  grossPreview,
  parseAmountInput,
  resultStatusLabel,
} from '../../lib/tenderResult'

/**
 * Pulpit przetargu › Po terminie › Wynik przetargu (makieta, ekran 5): wynik każdej części zamówienia,
 * zwycięzca, ceny, powód przegranej, oferty innych firm i dane z Biuletynu Zamówień Publicznych.
 *
 * Formularz trzyma wersję roboczą każdej części; „Zapisz wynik” wysyła tylko zmienione części i w nich tylko
 * pola różne od zapisanych (pominięte pole serwer zostawia bez zmian — np. surowy NIP zwycięzcy z Biuletynu nie
 * ginie przy zapisie notatki). Część 1 bez zapisu (wirtualna) idzie razem z każdą nową częścią, żeby nie zniknęła.
 */
export type TenderResultSectionProps = {
  tenderId: number
  /**
   * uprawnienie tenders.edit_offer (dostęp do przetargu sprawdza serwer — can_edit w odpowiedzi);
   * opiekun przetargu bez tego uprawnienia wyniku nie zmienia
   */
  canEdit: boolean
  /** po zapisie wyniku — np. odświeżenie znacznika wyniku w nagłówku przetargu */
  onChanged?: () => void
  /** czy w formularzu są niezapisane zmiany — rodzic pyta przed przejściem do innej sekcji i przed zamknięciem strony */
  onDirtyChange?: (dirty: boolean) => void
  /** wartość oferty netto z wyceny — podpowiedź „nasza cena” przy przetargu z jedną częścią */
  offerValueNet?: string | null
}

/** Firma wybrana ze słownika albo wpisana jako nowa. */
type CompanyDraft = { kind: 'existing'; competitor: Competitor } | { kind: 'new'; name: string; nip: string }

type OfferDraft = { key: string; company: CompanyDraft | null; price: string }

type LotDraft = {
  key: string
  id: number | null
  lot_no: string
  name: string
  our_net: string
  our_vat_rate: string
  outcome: LotOutcome | ''
  winner: CompanyDraft | null
  winner_price: string
  currency: string
  offers_count: string
  lowest_price: string
  highest_price: string
  loss_reason: LossReason | ''
  note: string
  offers: OfferDraft[]
}

let draftSeq = 0
function nextKey(prefix: string): string {
  draftSeq += 1
  return `${prefix}:${draftSeq}`
}

function toDraft(lot: TenderLot): LotDraft {
  return {
    key: lot.id != null ? `id:${lot.id}` : nextKey('new'),
    id: lot.id,
    lot_no: String(lot.lot_no),
    name: lot.name ?? '',
    our_net: amountToInput(lot.our_net),
    our_vat_rate: lot.our_vat_rate != null ? formatVat(lot.our_vat_rate) : '',
    outcome: lot.outcome ?? '',
    winner: lot.winner ? { kind: 'existing', competitor: lot.winner } : null,
    winner_price: amountToInput(lot.winner_price),
    currency: lot.currency || 'PLN',
    offers_count: lot.offers_count != null ? String(lot.offers_count) : '',
    lowest_price: amountToInput(lot.lowest_price),
    highest_price: amountToInput(lot.highest_price),
    loss_reason: lot.loss_reason ?? '',
    note: lot.note ?? '',
    offers: lot.offers.map((o) => ({
      key: `offer:${o.id}`,
      company: { kind: 'existing', competitor: o.competitor },
      price: amountToInput(o.price),
    })),
  }
}

function emptyDraft(lotNo: number): LotDraft {
  return {
    key: nextKey('new'),
    id: null,
    lot_no: String(lotNo),
    name: '',
    our_net: '',
    our_vat_rate: '',
    outcome: '',
    winner: null,
    winner_price: '',
    currency: 'PLN',
    offers_count: '',
    lowest_price: '',
    highest_price: '',
    loss_reason: '',
    note: '',
    offers: [],
  }
}

/** Porównanie wersji roboczej z zapisaną bez kluczy React. */
function draftSignature(d: LotDraft): string {
  const company = (c: CompanyDraft | null) =>
    c == null ? null : c.kind === 'existing' ? `#${c.competitor.id}` : `+${c.name.trim()}|${c.nip.trim()}`
  return JSON.stringify({
    ...d,
    key: undefined,
    winner: company(d.winner),
    offers: d.offers.map((o) => [company(o.company), o.price.trim()]),
  })
}

function companyInput(c: CompanyDraft): CompetitorInput {
  return c.kind === 'existing' ? { competitor_id: c.competitor.id } : { name: c.name.trim(), nip: c.nip.trim() || null }
}

function companyName(c: CompanyDraft | null): string {
  if (!c) return ''
  return c.kind === 'existing' ? c.competitor.name : c.name.trim()
}

/**
 * Wersja robocza → treść żądania; tekst błędu, gdy czegoś nie da się odczytać (bez zgadywania).
 * Z `before` (stan zapisany) wysyłane są tylko pola, które się zmieniły; id i numer części zawsze.
 */
function toUpdate(d: LotDraft, before?: LotDraft): { update: TenderLotUpdate } | { error: string } {
  const full = buildUpdate(d)
  if ('error' in full || !before) return full
  const prev = buildUpdate(before)
  if ('error' in prev) return full
  const update: TenderLotUpdate = { id: full.update.id, lot_no: full.update.lot_no }
  const target = update as Record<string, unknown>
  const next = full.update as Record<string, unknown>
  const old = prev.update as Record<string, unknown>
  for (const key of Object.keys(next)) {
    if (key === 'id' || key === 'lot_no') continue
    if (JSON.stringify(next[key]) !== JSON.stringify(old[key])) target[key] = next[key]
  }
  return { update }
}

function buildUpdate(d: LotDraft): { update: TenderLotUpdate } | { error: string } {
  const lotNo = Number(d.lot_no)
  const label = `Część ${d.lot_no || '?'}`
  if (!Number.isInteger(lotNo) || lotNo < 1 || lotNo > 1000) {
    return { error: `${label}: numer części to liczba od 1 do 1000.` }
  }
  const amounts: Record<'our_net' | 'winner_price' | 'lowest_price' | 'highest_price', string | null> = {
    our_net: null,
    winner_price: null,
    lowest_price: null,
    highest_price: null,
  }
  for (const field of ['our_net', 'winner_price', 'lowest_price', 'highest_price'] as const) {
    const parsed = parseAmountInput(d[field])
    if (parsed === undefined) {
      return { error: `${label}: pole „${LOT_FIELD_LABEL[field]}” — wpisz kwotę, np. 1234,50.` }
    }
    amounts[field] = parsed
  }
  const vat = parseAmountInput(d.our_vat_rate)
  if (vat === undefined || (vat !== null && Number(vat) > 100)) {
    return { error: `${label}: stawka VAT to liczba od 0 do 100.` }
  }
  const offersCount = d.offers_count.trim()
  if (offersCount !== '' && !/^\d{1,5}$/.test(offersCount)) {
    return { error: `${label}: liczba ofert to liczba całkowita.` }
  }
  if (d.winner?.kind === 'new' && d.winner.name.trim() === '' && d.winner.nip.trim() === '') {
    return { error: `${label}: wpisz nazwę zwycięzcy albo wybierz firmę z listy.` }
  }
  const offers: NonNullable<TenderLotUpdate['offers']> = []
  for (const o of d.offers) {
    if (!o.company || companyName(o.company) === '') {
      return { error: `${label}: w cenach pozostałych firm wybierz firmę albo usuń pusty wiersz.` }
    }
    const price = parseAmountInput(o.price)
    if (!price) {
      return { error: `${label}: wpisz cenę firmy „${companyName(o.company)}”, np. 62900,00.` }
    }
    offers.push({ ...companyInput(o.company), price, currency: d.currency })
  }
  return {
    update: {
      id: d.id,
      lot_no: lotNo,
      name: d.name.trim() || null,
      our_net: amounts.our_net,
      our_vat_rate: vat,
      outcome: d.outcome || null,
      winner: d.winner ? companyInput(d.winner) : null,
      winner_price: amounts.winner_price,
      currency: d.currency,
      offers_count: offersCount === '' ? null : Number(offersCount),
      lowest_price: amounts.lowest_price,
      highest_price: amounts.highest_price,
      loss_reason: d.outcome === 'lost' ? d.loss_reason || null : null,
      note: d.note.trim() || null,
      offers,
    },
  }
}

/** Różnica naszej ceny brutto do ceny (dodatnia = byliśmy drożsi), procent od naszej ceny. */
function gapTo(ourGross: string | null, price: string | null, currency: string): { amount: number; percent: number } | null {
  if (!ourGross || !price || currency !== 'PLN') return null
  const our = Math.round(Number(ourGross) * 100)
  const other = Math.round(Number(price) * 100)
  if (!Number.isFinite(our) || !Number.isFinite(other) || our === 0) return null
  return { amount: (our - other) / 100, percent: Math.round(((our - other) * 1000) / our) / 10 }
}

function errorText(e: unknown, fallback: string): string {
  if (e instanceof ApiError) {
    const errors = e.body.errors as Record<string, string[]> | undefined
    if (errors) return Object.values(errors).flat().join(' ')
    return e.message
  }
  return e instanceof Error ? e.message : fallback
}

function formatDate(iso: string | null | undefined, withTime = false): string {
  if (!iso) return ''
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return ''
  return withTime ? d.toLocaleString('pl-PL', { dateStyle: 'short', timeStyle: 'short' }) : d.toLocaleDateString('pl-PL')
}

function percentLabel(p: number): string {
  return `${Math.abs(p).toLocaleString('pl-PL', { maximumFractionDigits: 1 })}%`
}

const inputClass = 'mt-1 block w-full rounded border border-slate-300 px-2 py-1 disabled:bg-slate-50'

function FromBulletin({ show }: { show: boolean }) {
  if (!show) return null
  return (
    <span
      className="ml-1 rounded border border-sky-200 bg-sky-50 px-1 text-[10px] font-medium text-sky-800"
      title="Wartość z ogłoszenia o wyniku w Biuletynie Zamówień Publicznych"
    >
      z Biuletynu
    </span>
  )
}

/** Wybór firmy: podpowiedzi ze słownika konkurentów albo nowa firma (nazwa + NIP). */
function CompanyPicker({
  value,
  onChange,
  disabled,
  label,
}: {
  value: CompanyDraft | null
  onChange: (value: CompanyDraft | null) => void
  disabled?: boolean
  label: string
}) {
  const listId = useId()
  const [query, setQueryState] = useState('')
  /** wpisany tekst bez czekania na render — wyjście z pola tuż po wyborze nie może dopisać starego tekstu */
  const queryRef = useRef('')
  const setQuery = (text: string) => {
    queryRef.current = text
    setQueryState(text)
  }
  const [hits, setHits] = useState<Competitor[]>([])
  const [open, setOpen] = useState(false)
  const [active, setActive] = useState(0)
  const [searchErr, setSearchErr] = useState('')

  useEffect(() => {
    if (!open || value) return
    let cancelled = false
    const timer = window.setTimeout(() => {
      searchCompetitors(query.trim())
        .then((rows) => {
          if (!cancelled) {
            setHits(rows)
            // nowa lista = podświetlenie od pierwszej pozycji, nie na firmie, której osoba nie widziała
            setActive(0)
            setSearchErr('')
          }
        })
        .catch(() => {
          if (!cancelled) setSearchErr('Nie udało się pobrać listy firm.')
        })
    }, 250)
    return () => {
      cancelled = true
      window.clearTimeout(timer)
    }
  }, [query, open, value])

  if (value?.kind === 'existing') {
    return (
      <div className="mt-1 flex flex-wrap items-center gap-2">
        <span className="font-medium">{value.competitor.name}</span>
        {value.competitor.nip && <span className="text-slate-500">NIP {value.competitor.nip}</span>}
        {!disabled && (
          <button type="button" className="text-blue-700 underline" onClick={() => onChange(null)}>
            Zmień
          </button>
        )}
      </div>
    )
  }

  if (value?.kind === 'new') {
    return (
      <div className="mt-1 space-y-1">
        <div className="flex flex-wrap gap-1">
          <input
            aria-label={`${label}: nazwa nowej firmy`}
            className="min-w-[180px] flex-1 rounded border border-slate-300 px-2 py-1 disabled:bg-slate-50"
            value={value.name}
            disabled={disabled}
            placeholder="Nazwa firmy"
            onChange={(e) => onChange({ ...value, name: e.target.value })}
          />
          <input
            aria-label={`${label}: NIP nowej firmy`}
            className="w-36 rounded border border-slate-300 px-2 py-1 disabled:bg-slate-50"
            value={value.nip}
            disabled={disabled}
            placeholder="NIP (jeśli znany)"
            onChange={(e) => onChange({ ...value, nip: e.target.value })}
          />
        </div>
        {!disabled && <p className="text-slate-500">Nowa firma — trafi na listę firm po zapisie wyniku. Firma o tej samej nazwie albo tym samym NIP-ie nie powstanie drugi raz.</p>}
        {!disabled && (
          <button type="button" className="text-blue-700 underline" onClick={() => onChange(null)}>
            Wybierz z listy firm
          </button>
        )}
      </div>
    )
  }

  const typed = query.trim()
  // opcje listy: firmy ze słownika, a na końcu „+ Nowa firma”, gdy coś wpisano
  const options: Array<{ kind: 'existing'; competitor: Competitor } | { kind: 'new' }> = [
    ...hits.map((competitor) => ({ kind: 'existing' as const, competitor })),
    ...(typed !== '' ? [{ kind: 'new' as const }] : []),
  ]
  const activeIndex = options.length > 0 ? Math.min(active, options.length - 1) : -1
  const expanded = open && !disabled

  function close() {
    setOpen(false)
    setActive(0)
  }

  function pick(option: (typeof options)[number]) {
    onChange(option.kind === 'existing' ? { kind: 'existing', competitor: option.competitor } : { kind: 'new', name: typed, nip: '' })
    setQuery('')
    close()
  }

  /**
   * Wyjście z pola (Tab, kliknięcie obok) z wpisanym tekstem bez wyboru: ta sama nazwa albo NIP co jedna firma
   * z podpowiedzi = ta firma, inaczej nowa firma (widać ją wtedy jako „nowa firma” z polem NIP) — tekst nie ginie.
   */
  function commitTyped() {
    const typed = queryRef.current.trim()
    if (typed === '') return
    const folded = typed.toLocaleLowerCase('pl-PL')
    const digits = typed.replace(/\D/g, '')
    const same = hits.filter(
      (c) => c.name.trim().toLocaleLowerCase('pl-PL') === folded || (digits.length === 10 && c.nip === digits),
    )
    onChange(same.length === 1 ? { kind: 'existing', competitor: same[0] } : { kind: 'new', name: typed, nip: '' })
    setQuery('')
  }

  function onKeyDown(e: KeyboardEvent<HTMLInputElement>) {
    if (e.key === 'Escape') {
      if (expanded) {
        e.preventDefault()
        close()
      }
      return
    }
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault()
      if (!expanded) {
        setOpen(true)
        return
      }
      if (options.length === 0) return
      const step = e.key === 'ArrowDown' ? 1 : -1
      setActive((activeIndex + step + options.length) % options.length)
      return
    }
    if (e.key === 'Enter') {
      // Enter nie wysyła formularza i nie klika niczego obok — tylko wybiera z listy
      e.preventDefault()
      if (expanded && activeIndex >= 0) pick(options[activeIndex])
    }
  }

  return (
    <div
      className="relative mt-1"
      onBlur={(e) => {
        // lista zamyka się dopiero, gdy fokus wychodzi poza całe pole wyboru (nie przy przejściu na listę)
        if (e.relatedTarget instanceof Node && e.currentTarget.contains(e.relatedTarget)) return
        close()
        commitTyped()
      }}
    >
      <input
        aria-label={label}
        role="combobox"
        aria-autocomplete="list"
        aria-expanded={expanded}
        aria-controls={expanded ? listId : undefined}
        aria-activedescendant={expanded && activeIndex >= 0 ? `${listId}-${activeIndex}` : undefined}
        className="block w-full rounded border border-slate-300 px-2 py-1 disabled:bg-slate-50"
        value={query}
        disabled={disabled}
        placeholder={disabled ? '—' : 'Szukaj po nazwie albo NIP'}
        onFocus={() => setOpen(true)}
        onKeyDown={onKeyDown}
        onChange={(e) => {
          setQuery(e.target.value)
          setActive(0)
          setOpen(true)
        }}
      />
      {expanded && (
        <div
          tabIndex={-1}
          // kliknięcie w listę (także w pasek przewijania) nie zabiera fokusu z pola
          onMouseDown={(e) => e.preventDefault()}
          className="absolute z-20 mt-1 max-h-64 w-full overflow-auto rounded border border-slate-200 bg-white shadow-lg"
        >
          {searchErr && <p className="px-2 py-1 text-red-700">{searchErr}</p>}
          {hits.length === 0 && !searchErr && (
            <p className="px-2 py-1 text-slate-500">{typed ? 'Brak takiej firmy na liście.' : 'Lista firm jest pusta.'}</p>
          )}
          <ul id={listId} role="listbox" aria-label={`${label}: podpowiedzi`}>
            {options.map((option, i) => {
              const isActive = i === activeIndex
              return (
                <li
                  key={option.kind === 'existing' ? option.competitor.id : 'new'}
                  id={`${listId}-${i}`}
                  role="option"
                  aria-selected={isActive}
                  className={`cursor-pointer px-2 py-1.5 ${option.kind === 'new' ? 'border-t border-slate-100 font-medium text-blue-700' : ''} ${
                    isActive ? 'bg-slate-100' : 'hover:bg-slate-50'
                  }`}
                  onMouseEnter={() => setActive(i)}
                  onClick={() => pick(option)}
                >
                  {option.kind === 'existing' ? (
                    <>
                      {option.competitor.name}
                      {option.competitor.nip && <span className="ml-1 text-slate-500">NIP {option.competitor.nip}</span>}
                    </>
                  ) : (
                    <>+ Nowa firma: „{typed}”</>
                  )}
                </li>
              )
            })}
          </ul>
        </div>
      )}
    </div>
  )
}

function NoticeLink({ notice, label }: { notice: BzpNoticeRef; label: string }) {
  return (
    <span>
      {label}: <strong>{notice.notice_number}</strong>
      {notice.published_at ? ` z ${formatDate(notice.published_at)}` : ''}
      {notice.url && (
        <>
          {' '}
          <a href={notice.url} target="_blank" rel="noopener noreferrer" className="text-blue-700 underline">
            otwórz w Biuletynie
          </a>
        </>
      )}
    </span>
  )
}

export function TenderResultSection({ tenderId, canEdit, onChanged, onDirtyChange, offerValueNet }: TenderResultSectionProps) {
  const [data, setData] = useState<TenderResultResponse | null>(null)
  const [drafts, setDrafts] = useState<LotDraft[]>([])
  /** stan zapisany każdej części (klucz jak w drafts) — do porównań i do wysyłania tylko zmian */
  const [saved, setSaved] = useState<Record<string, LotDraft>>({})
  const [selectedKey, setSelectedKey] = useState('')
  const [busy, setBusy] = useState(false)
  const [loadErr, setLoadErr] = useState('')
  const [err, setErr] = useState('')
  const [msg, setMsg] = useState('')
  const [bzpMessage, setBzpMessage] = useState('')
  const selectedLotNo = useRef<string | null>(null)

  const apply = useCallback((res: TenderResultResponse) => {
    const next = res.lots.map(toDraft)
    setData(res)
    setDrafts(next)
    setSaved(Object.fromEntries(next.map((d) => [d.key, d])))
    const keep = next.find((d) => d.lot_no === selectedLotNo.current) ?? next[0]
    setSelectedKey(keep?.key ?? '')
  }, [])

  useEffect(() => {
    let cancelled = false
    fetchTenderResult(tenderId)
      .then((res) => {
        if (!cancelled) apply(res)
      })
      .catch((e) => {
        if (!cancelled) setLoadErr(errorText(e, 'Nie udało się wczytać wyniku przetargu.'))
      })
    return () => {
      cancelled = true
    }
  }, [tenderId, apply])

  const editable = canEdit && Boolean(data?.can_edit)
  // część jeszcze niezapisana (także wirtualna z odpowiedzi) jest „zmieniona” dopiero po wpisaniu czegoś
  const dirtyKeys = useMemo(
    () =>
      drafts
        .filter((d) => {
          const before = saved[d.key]
          return before === undefined || draftSignature(before) !== draftSignature(d)
        })
        .map((d) => d.key),
    [drafts, saved],
  )
  const dirty = dirtyKeys.length > 0 || drafts.length !== Object.keys(saved).length

  // rodzic (pulpit przetargu) ostrzega przed utratą zmian; po odmontowaniu nic nie jest niezapisane
  const onDirtyChangeRef = useRef(onDirtyChange)
  onDirtyChangeRef.current = onDirtyChange
  useEffect(() => {
    onDirtyChangeRef.current?.(dirty)
  }, [dirty])
  useEffect(() => () => onDirtyChangeRef.current?.(false), [])

  const savedLots = useMemo(() => new Map((data?.lots ?? []).map((l) => [l.id != null ? `id:${l.id}` : '', l])), [data])
  const selected = drafts.find((d) => d.key === selectedKey) ?? drafts[0] ?? null
  const selectedSaved = selected ? savedLots.get(selected.key) ?? null : null
  // część wirtualna z odpowiedzi nie ma klucza „id:…” — jej dane z Biuletynu są puste
  const bzpFields = new Set(selectedSaved?.bzp_fields ?? [])

  function update(key: string, patch: Partial<LotDraft>) {
    setMsg('')
    setDrafts((list) => list.map((d) => (d.key === key ? { ...d, ...patch } : d)))
  }

  function select(d: LotDraft) {
    selectedLotNo.current = d.lot_no
    setSelectedKey(d.key)
  }

  function addLot() {
    const maxNo = drafts.reduce((m, d) => Math.max(m, Number(d.lot_no) || 0), 0)
    const draft = emptyDraft(maxNo + 1)
    setDrafts((list) => [...list, draft])
    select(draft)
  }

  async function save() {
    setErr('')
    setMsg('')
    const lots: TenderLotUpdate[] = []
    // nowa część obok niezapisanej części 1 z odpowiedzi: bez niej przetarg miałby tylko nową część
    const savingNewLot = drafts.some((d) => d.id == null && saved[d.key] === undefined)
    for (const d of drafts) {
      const virtual = d.id == null && saved[d.key] !== undefined
      if (!dirtyKeys.includes(d.key) && !(virtual && savingNewLot)) continue
      const res = toUpdate(d, saved[d.key])
      if ('error' in res) {
        setErr(res.error)
        return
      }
      lots.push(res.update)
    }
    if (lots.length === 0) return
    setBusy(true)
    try {
      apply(await saveTenderResult(tenderId, lots))
      setMsg('Zapisano wynik przetargu.')
      setBzpMessage('')
      onChanged?.()
    } catch (e) {
      setErr(errorText(e, 'Nie udało się zapisać wyniku.'))
    } finally {
      setBusy(false)
    }
  }

  function discard() {
    if (!data) return
    setErr('')
    setMsg('')
    apply(data)
  }

  async function removeLot(d: LotDraft) {
    if (d.id == null) {
      setDrafts((list) => list.filter((x) => x.key !== d.key))
      setSaved((s) => {
        const next = { ...s }
        delete next[d.key]
        return next
      })
      setSelectedKey('')
      return
    }
    if (!window.confirm(`Usunąć część ${d.lot_no} razem z wynikiem i cenami innych firm?`)) return
    setBusy(true)
    setErr('')
    try {
      await deleteTenderLot(tenderId, d.id)
      apply(await fetchTenderResult(tenderId))
      setMsg(`Usunięto część ${d.lot_no}.`)
      onChanged?.()
    } catch (e) {
      setErr(errorText(e, 'Nie udało się usunąć części.'))
    } finally {
      setBusy(false)
    }
  }

  async function checkBulletin() {
    if (dirty && !window.confirm('Masz niezapisane zmiany — sprawdzenie w Biuletynie wczyta wynik od nowa. Kontynuować?')) {
      return
    }
    setBusy(true)
    setErr('')
    setMsg('')
    try {
      const res = await checkTenderBzp(tenderId)
      apply(res)
      setBzpMessage(res.bzp_message ?? 'Sprawdzono ogłoszenia w Biuletynie Zamówień Publicznych.')
      onChanged?.()
    } catch (e) {
      setErr(errorText(e, 'Nie udało się sprawdzić Biuletynu.'))
    } finally {
      setBusy(false)
    }
  }

  if (loadErr) {
    return <p className="rounded-xl bg-white p-4 text-xs text-red-700 shadow-sm">{loadErr}</p>
  }
  if (!data) {
    return <p className="rounded-xl bg-white p-4 text-xs text-slate-500 shadow-sm">Wczytywanie wyniku przetargu…</p>
  }

  const resultNotice = data.bzp.result_notice
  const contractNotice = data.bzp.contract_notice
  const conflicts = data.lots.filter((l) => l.bzp_conflict)

  const selGross = selected ? grossPreview(selected.our_net, selected.our_vat_rate) : null
  const selWinnerPrice = selected ? parseAmountInput(selected.winner_price) ?? null : null
  const selGap = selected ? gapTo(selGross, selWinnerPrice, selected.currency) : null
  const canFillOffer =
    editable && drafts.length === 1 && selected != null && selected.our_net === '' && offerValueNet != null && Number(offerValueNet) > 0

  return (
    <div className="space-y-3 text-xs">
      {/* Biuletyn Zamówień Publicznych */}
      <div className="flex flex-wrap items-start justify-between gap-2 rounded-xl bg-white p-4 shadow-sm">
        <div className="min-w-0 flex-1 space-y-1">
          <div className="flex flex-wrap items-center gap-2">
            <h2 className="text-sm font-semibold">Wynik przetargu</h2>
            <span
              className={`rounded border px-2 py-0.5 font-medium ${
                data.result_status ? RESULT_STATUS_CLASS[data.result_status] : 'border-slate-200 bg-white text-slate-500'
              }`}
            >
              {data.result_status ? `Wynik: ${resultStatusLabel(data.result_status)}` : 'Wynik nie jest jeszcze wpisany'}
            </span>
          </div>
          <p className="text-slate-500">
            Wynik wpisuje się osobno dla każdej części zamówienia, bo jedną część można wygrać, a inną przegrać.
          </p>
          {resultNotice ? (
            <p className="text-slate-700">
              <NoticeLink notice={resultNotice} label="Ogłoszenie o wyniku postępowania w Biuletynie Zamówień Publicznych" />
              <br />
              <span className="text-slate-500">
                Pola oznaczone „z Biuletynu” aplikacja wypełniła z tego ogłoszenia. Wartości wpisane ręcznie nie są
                nadpisywane.
              </span>
            </p>
          ) : data.notice_source === 'bzp' ? (
            <p className="text-slate-600">
              Ogłoszenia o wyniku nie ma jeszcze wśród ogłoszeń pobranych z Biuletynu Zamówień Publicznych
              (numer ogłoszenia o zamówieniu: {data.notice_number}). Aplikacja pobiera ogłoszenia codziennie rano.
              {data.bzp.checked_at ? ` Ostatnie sprawdzenie: ${formatDate(data.bzp.checked_at, true)}.` : ''}
            </p>
          ) : data.notice_source === 'ted' ? (
            <p className="text-slate-600">
              Numer ogłoszenia ({data.notice_number}) jest z Dziennika Urzędowego Unii Europejskiej (TED). Wyników
              z TED aplikacja nie pobiera — wpisz je ręcznie.
            </p>
          ) : (
            <p className="text-slate-600">
              Przetarg nie ma numeru ogłoszenia. Wpisz go w podsumowaniu przetargu, żeby aplikacja mogła sama znaleźć
              wynik w Biuletynie Zamówień Publicznych.
            </p>
          )}
          {contractNotice && (
            <p className="text-slate-500">
              <NoticeLink notice={contractNotice} label="Ogłoszenie o zamówieniu" />
            </p>
          )}
          {bzpMessage && <p className="rounded bg-sky-50 px-2 py-1 text-sky-800">{bzpMessage}</p>}
        </div>
        {editable && data.notice_source === 'bzp' && (
          <button
            type="button"
            disabled={busy}
            onClick={() => void checkBulletin()}
            className="rounded border border-slate-300 bg-white px-3 py-1.5 font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-50"
          >
            Sprawdź w Biuletynie Zamówień Publicznych
          </button>
        )}
      </div>

      {conflicts.length > 0 && (
        <div className="rounded-xl border border-amber-200 bg-amber-50 p-3 text-amber-800">
          <strong>Biuletyn podaje coś innego niż wpis w aplikacji.</strong> Wpisane ręcznie wartości zostały bez zmian
          — sprawdź je:
          <ul className="mt-1 list-disc pl-5">
            {conflicts.map((l) => (
              <li key={l.lot_no}>
                Część {l.lot_no}: {l.bzp_conflict}
              </li>
            ))}
          </ul>
        </div>
      )}

      {msg && <p className="rounded bg-green-50 px-3 py-2 text-green-800">{msg}</p>}
      {err && <p className="rounded bg-red-50 px-3 py-2 text-red-700">{err}</p>}

      {/* Tabela części */}
      <div className="overflow-x-auto rounded-xl bg-white p-4 shadow-sm">
        <table className="w-full text-left">
          <thead>
            <tr className="border-b bg-slate-50">
              <th className="p-2">Część zamówienia</th>
              <th className="p-2 text-right">Nasza oferta</th>
              <th className="p-2">Wynik</th>
              <th className="p-2">Wygrała firma</th>
              <th className="p-2 text-right">Cena zwycięzcy</th>
              <th className="p-2 text-right">Ofert</th>
              <th className="p-2">Powód przegranej</th>
            </tr>
          </thead>
          <tbody>
            {drafts.map((d) => {
              const gross = grossPreview(d.our_net, d.our_vat_rate)
              const net = parseAmountInput(d.our_net)
              const isSelected = selected?.key === d.key
              const winnerLabel = companyName(d.winner) || (d.outcome === 'won' ? 'nasza firma' : '')
              const savedLot = savedLots.get(d.key)
              return (
                <tr
                  key={d.key}
                  onClick={() => select(d)}
                  className={`cursor-pointer border-b align-top ${isSelected ? 'bg-sky-50' : 'hover:bg-slate-50'}`}
                >
                  <td className="p-2">
                    {/* przycisk: część wybiera się też klawiaturą (Tab, Enter albo spacja) */}
                    <button
                      type="button"
                      aria-current={isSelected ? 'true' : undefined}
                      title="Pokaż i edytuj tę część"
                      className="text-left font-bold text-slate-900 underline-offset-2 hover:underline"
                      onClick={(e) => {
                        e.stopPropagation()
                        select(d)
                      }}
                    >
                      Część {d.lot_no || '?'}
                      {d.name.trim() ? ` · ${d.name.trim()}` : ''}
                    </button>
                    {dirtyKeys.includes(d.key) && <span className="ml-1 text-amber-800">(niezapisana zmiana)</span>}
                    {savedLot?.bzp_conflict && (
                      <span className="ml-1 font-bold text-amber-800" title={savedLot.bzp_conflict}>
                        !
                      </span>
                    )}
                  </td>
                  <td className="p-2 text-right">
                    {net ? `${formatMoney(net, 'PLN')} netto` : <span className="text-slate-400">—</span>}
                    {gross && (
                      <div className="text-slate-500">
                        {formatMoney(gross, 'PLN')} brutto (VAT {d.our_vat_rate}%)
                      </div>
                    )}
                  </td>
                  <td className="p-2">
                    {d.outcome ? (
                      <span className={`rounded border px-1.5 py-0.5 ${LOT_OUTCOME_CLASS[d.outcome]}`}>
                        {LOT_OUTCOME_LABEL[d.outcome]}
                      </span>
                    ) : (
                      <span className="text-slate-400">bez wyniku</span>
                    )}
                  </td>
                  <td className="p-2">{winnerLabel || <span className="text-slate-400">—</span>}</td>
                  <td className="p-2 text-right">
                    {parseAmountInput(d.winner_price) ? (
                      formatMoney(parseAmountInput(d.winner_price), d.currency)
                    ) : (
                      <span className="text-slate-400">—</span>
                    )}
                  </td>
                  <td className="p-2 text-right">{d.offers_count || <span className="text-slate-400">—</span>}</td>
                  <td className="p-2">
                    {d.outcome === 'lost' && d.loss_reason ? (
                      LOSS_REASON_LABEL[d.loss_reason]
                    ) : (
                      <span className="text-slate-400">—</span>
                    )}
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
        {editable && (
          <button type="button" disabled={busy} onClick={addLot} className="mt-2 text-blue-700 underline disabled:opacity-50">
            + Dodaj część zamówienia
          </button>
        )}
      </div>

      {/* Edycja wybranej części */}
      {selected && (
        <div className="space-y-3 rounded-xl bg-white p-4 shadow-sm">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <h2 className="text-sm font-semibold">
              Część {selected.lot_no || '?'}
              {selected.name.trim() ? ` · ${selected.name.trim()}` : ''}
            </h2>
            {editable && (drafts.length > 1 || selected.id != null) && (
              <button
                type="button"
                disabled={busy || (selected.id != null && dirty)}
                title={selected.id != null && dirty ? 'Najpierw zapisz albo odrzuć zmiany' : undefined}
                onClick={() => void removeLot(selected)}
                className="rounded border border-red-200 bg-white px-2 py-1 text-red-700 hover:bg-red-50 disabled:opacity-50"
              >
                Usuń część
              </button>
            )}
          </div>

          {selectedSaved?.bzp_conflict && (
            <p className="rounded border border-amber-200 bg-amber-50 px-2 py-1 text-amber-800">
              Biuletyn podaje co innego: {selectedSaved.bzp_conflict}
            </p>
          )}

          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <label>
              Numer części
              <input
                className={inputClass}
                inputMode="numeric"
                value={selected.lot_no}
                disabled={!editable || busy}
                onChange={(e) => update(selected.key, { lot_no: e.target.value })}
              />
            </label>
            <label className="sm:col-span-1 lg:col-span-3">
              Nazwa części
              <FromBulletin show={bzpFields.has('name')} />
              <input
                className={inputClass}
                value={selected.name}
                disabled={!editable || busy}
                placeholder="np. Odzież robocza"
                onChange={(e) => update(selected.key, { name: e.target.value })}
              />
            </label>
            <label>
              Nasza cena netto, zł
              <input
                className={inputClass}
                inputMode="decimal"
                value={selected.our_net}
                disabled={!editable || busy}
                placeholder="np. 74310,00"
                onChange={(e) => update(selected.key, { our_net: e.target.value })}
              />
              {canFillOffer && (
                <button
                  type="button"
                  className="mt-1 text-blue-700 underline"
                  onClick={() => update(selected.key, { our_net: amountToInput(Number(offerValueNet).toFixed(2)) })}
                >
                  Wstaw wartość oferty z wyceny: {formatMoney(offerValueNet, 'PLN')}
                </button>
              )}
            </label>
            <label>
              Stawka VAT, %
              <input
                className={inputClass}
                inputMode="decimal"
                value={selected.our_vat_rate}
                disabled={!editable || busy}
                placeholder="np. 23"
                onChange={(e) => update(selected.key, { our_vat_rate: e.target.value })}
              />
            </label>
            <div>
              Nasza cena brutto
              <p className="mt-1 py-1 font-semibold">
                {selGross ? formatMoney(selGross, 'PLN') : <span className="font-normal text-slate-400">wpisz cenę netto i stawkę VAT</span>}
              </p>
            </div>
            <div className="sm:col-span-2">
              Wynik
              <FromBulletin show={bzpFields.has('outcome')} />
              <div className="mt-1 flex flex-wrap gap-1" role="group" aria-label="Wynik części">
                {LOT_OUTCOMES.map((o) => {
                  const on = selected.outcome === o
                  return (
                    <button
                      key={o}
                      type="button"
                      aria-pressed={on}
                      disabled={!editable || busy}
                      onClick={() =>
                        update(selected.key, {
                          outcome: on ? '' : o,
                          loss_reason: !on && o === 'lost' ? selected.loss_reason : '',
                        })
                      }
                      className={`rounded border px-2 py-1 disabled:opacity-60 ${
                        on ? LOT_OUTCOME_CLASS[o] + ' font-semibold' : 'border-slate-300 bg-white text-slate-600 hover:bg-slate-50'
                      }`}
                    >
                      {LOT_OUTCOME_LABEL[o]}
                    </button>
                  )
                })}
              </div>
            </div>
            <div className="sm:col-span-2">
              Wygrała firma
              <FromBulletin show={bzpFields.has('winner')} />
              <CompanyPicker
                label="Wygrała firma"
                value={selected.winner}
                disabled={!editable || busy}
                onChange={(winner) => update(selected.key, { winner })}
              />
              {selectedSaved?.winner_national_id_raw && (
                <p className="mt-1 text-amber-800">
                  NIP ze źródła „{selectedSaved.winner_national_id_raw}” nie jest poprawnym NIP-em — zapisany bez zmian.
                </p>
              )}
              {selected.outcome === 'won' && !selected.winner && (
                <p className="mt-1 text-slate-500">Przy wygranej puste pole oznacza naszą firmę.</p>
              )}
            </div>
            <label>
              Cena zwycięzcy ({selected.currency === 'PLN' ? 'zł' : selected.currency})
              <FromBulletin show={bzpFields.has('winner_price')} />
              <input
                className={inputClass}
                inputMode="decimal"
                value={selected.winner_price}
                disabled={!editable || busy}
                placeholder="jak w ogłoszeniu"
                onChange={(e) => update(selected.key, { winner_price: e.target.value })}
              />
            </label>
            <label>
              Liczba ofert
              <FromBulletin show={bzpFields.has('offers_count')} />
              <input
                className={inputClass}
                inputMode="numeric"
                value={selected.offers_count}
                disabled={!editable || busy}
                onChange={(e) => update(selected.key, { offers_count: e.target.value })}
              />
            </label>
            <label>
              Najniższa cena
              <FromBulletin show={bzpFields.has('lowest_price')} />
              <input
                className={inputClass}
                inputMode="decimal"
                value={selected.lowest_price}
                disabled={!editable || busy}
                onChange={(e) => update(selected.key, { lowest_price: e.target.value })}
              />
            </label>
            <label>
              Najwyższa cena
              <FromBulletin show={bzpFields.has('highest_price')} />
              <input
                className={inputClass}
                inputMode="decimal"
                value={selected.highest_price}
                disabled={!editable || busy}
                onChange={(e) => update(selected.key, { highest_price: e.target.value })}
              />
            </label>
            <label>
              Powód przegranej
              <select
                className={inputClass}
                value={selected.loss_reason}
                disabled={!editable || busy || selected.outcome !== 'lost'}
                title={selected.outcome !== 'lost' ? 'Powód wpisuje się tylko przy przegranej' : undefined}
                onChange={(e) => update(selected.key, { loss_reason: e.target.value as LossReason | '' })}
              >
                <option value="">{selected.outcome === 'lost' ? 'Wybierz powód' : '—'}</option>
                {LOSS_REASONS.map((r) => (
                  <option key={r} value={r}>
                    {LOSS_REASON_LABEL[r]}
                  </option>
                ))}
              </select>
            </label>
          </div>

          {selGap && selected.outcome === 'lost' && (
            <p className="rounded bg-slate-50 px-3 py-2 text-slate-700">
              {selGap.amount > 0 ? (
                <>
                  Byliśmy drożsi o {formatMoney(selGap.amount, 'PLN')} brutto. Zwycięzca był tańszy od nas o{' '}
                  <strong>{percentLabel(selGap.percent)}</strong>.
                </>
              ) : selGap.amount < 0 ? (
                <>
                  Byliśmy tańsi od zwycięzcy o {formatMoney(-selGap.amount, 'PLN')} brutto (
                  <strong>{percentLabel(selGap.percent)}</strong>) — przegrana z innego powodu niż cena?
                </>
              ) : (
                <>Nasza cena brutto była taka sama jak cena zwycięzcy.</>
              )}{' '}
              <span className="text-slate-500">
                Cenę zwycięzcy z ogłoszenia przyjmujemy jako cenę z podatkiem VAT.
              </span>
            </p>
          )}

          <label className="block">
            Notatka
            <textarea
              className={inputClass}
              rows={3}
              value={selected.note}
              disabled={!editable || busy}
              placeholder="np. Zwycięzca dał tańsze obuwie innego producenta, z tą samą normą."
              onChange={(e) => update(selected.key, { note: e.target.value })}
            />
          </label>

          {selectedSaved?.decided_at && (
            <p className="text-slate-500">
              Ostatnia zmiana wyniku tej części: {selectedSaved.decided_by?.name ?? '—'},{' '}
              {formatDate(selectedSaved.decided_at, true)}
            </p>
          )}
        </div>
      )}

      {/* Ceny pozostałych firm */}
      {selected && (
        <div className="space-y-2 rounded-xl bg-white p-4 shadow-sm">
          <div className="flex flex-wrap items-start justify-between gap-2">
            <div>
              <h2 className="text-sm font-semibold">Ceny pozostałych firm (część {selected.lot_no || '?'}) — nieobowiązkowe</h2>
              <p className="text-slate-500">
                Z informacji z otwarcia ofert na stronie postępowania. Ogłoszenie w Biuletynie podaje tylko zwycięzcę
                oraz najniższą i najwyższą cenę. Firmę wybierasz z listy, żeby ta sama firma nie liczyła się pod
                dwiema nazwami.
              </p>
            </div>
            {editable && (
              <button
                type="button"
                disabled={busy}
                onClick={() =>
                  update(selected.key, {
                    offers: [...selected.offers, { key: nextKey('offer'), company: null, price: '' }],
                  })
                }
                className="rounded border border-slate-300 bg-white px-2 py-1 font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-50"
              >
                + Dodaj firmę
              </button>
            )}
          </div>
          {selected.offers.length === 0 && !selGross ? (
            <p className="text-slate-400">Nie wpisano cen innych firm.</p>
          ) : (
            <table className="w-full text-left">
              <thead>
                <tr className="border-b bg-slate-50">
                  <th className="p-2">Firma</th>
                  <th className="p-2 text-right">Cena brutto</th>
                  <th className="p-2 text-right">Różnica do naszej</th>
                  {editable && <th className="p-2" />}
                </tr>
              </thead>
              <tbody>
                {selected.offers.map((o) => {
                  const price = parseAmountInput(o.price) ?? null
                  const gap = gapTo(selGross, price, selected.currency)
                  const isWinner =
                    o.company?.kind === 'existing' &&
                    selected.winner?.kind === 'existing' &&
                    o.company.competitor.id === selected.winner.competitor.id
                  return (
                    <tr key={o.key} className="border-b align-top">
                      <td className="p-2">
                        <CompanyPicker
                          label="Firma"
                          value={o.company}
                          disabled={!editable || busy}
                          onChange={(company) =>
                            update(selected.key, {
                              offers: selected.offers.map((x) => (x.key === o.key ? { ...x, company } : x)),
                            })
                          }
                        />
                        {isWinner && (
                          <span className="mt-1 inline-block rounded border border-emerald-200 bg-emerald-50 px-1.5 text-emerald-800">
                            zwycięzca
                          </span>
                        )}
                      </td>
                      <td className="p-2 text-right">
                        <input
                          aria-label="Cena brutto"
                          className="w-32 rounded border border-slate-300 px-2 py-1 text-right disabled:bg-slate-50"
                          inputMode="decimal"
                          value={o.price}
                          disabled={!editable || busy}
                          onChange={(e) =>
                            update(selected.key, {
                              offers: selected.offers.map((x) => (x.key === o.key ? { ...x, price: e.target.value } : x)),
                            })
                          }
                        />
                      </td>
                      <td className="p-2 text-right">
                        {gap == null ? (
                          <span className="text-slate-400">—</span>
                        ) : gap.amount > 0 ? (
                          <span className="text-red-700">taniej o {percentLabel(gap.percent)}</span>
                        ) : gap.amount < 0 ? (
                          <span className="text-emerald-700">drożej o {percentLabel(gap.percent)}</span>
                        ) : (
                          'tyle samo'
                        )}
                      </td>
                      {editable && (
                        <td className="p-2 text-right">
                          <button
                            type="button"
                            disabled={busy}
                            className="text-red-700 underline disabled:opacity-50"
                            onClick={() =>
                              update(selected.key, { offers: selected.offers.filter((x) => x.key !== o.key) })
                            }
                          >
                            Usuń
                          </button>
                        </td>
                      )}
                    </tr>
                  )
                })}
                {selGross && (
                  <tr className="border-b">
                    <td className="p-2">Nasza firma (my)</td>
                    <td className="p-2 text-right">{formatMoney(selGross, 'PLN')}</td>
                    <td className="p-2 text-right text-slate-400">—</td>
                    {editable && <td className="p-2" />}
                  </tr>
                )}
              </tbody>
            </table>
          )}
        </div>
      )}

      {editable && (
        <div className="flex flex-wrap items-center justify-end gap-2">
          {dirty && <span className="text-amber-800">Masz niezapisane zmiany.</span>}
          {dirty && (
            <button
              type="button"
              disabled={busy}
              onClick={discard}
              className="rounded border border-slate-300 bg-white px-3 py-2 font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-50"
            >
              Odrzuć zmiany
            </button>
          )}
          <button
            type="button"
            disabled={busy || !dirty}
            onClick={() => void save()}
            className="rounded bg-emerald-600 px-4 py-2 font-semibold text-white hover:bg-emerald-700 disabled:opacity-50"
          >
            Zapisz wynik
          </button>
        </div>
      )}
      {!editable && (
        <p className="text-slate-500">Wynik może zmieniać osoba z uprawnieniem do edycji oferty, która ma dostęp do przetargu.</p>
      )}
    </div>
  )
}
