import { useEffect, useRef, useState, type ReactNode } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { BusyLabel, useBusySeconds } from '../components/Busy'
import { InquiryContactChip, InquiryContactModal } from '../components/InquiryContact'
import { ProductAiMatchModal, type AiMatchPick } from '../components/ProductAiMatchModal'
import { OrderQuantityBadge } from '../components/OrderQuantityBadge'
import { ProductVerifyModal } from '../components/ProductVerifyModal'
import { useAuth } from '../auth'
import { api, type OrderQuantity } from '../lib/api'
import { toneHint, toneOptions } from '../lib/inquiryTone'
import type {
  InquiryAnswer,
  InquiryCard,
  InquiryConfidence,
  InquiryDuplicateRef,
  InquiryFlag,
  InquiryItem,
  InquiryPayload,
  InquiryPriceMode,
  InquiryTerms,
  InquiryTone,
} from '../types/inquiry'

type Draft = { subject: string; body: string }
/** Ręczne szukanie przy pozycji: „Szukaj” po nazwie/kodzie, „Szukaj AI” po opisie. */
type SearchMode = 'catalog' | 'ai'
/** Otwarte okno szukania: dla której pozycji i w którym trybie. */
type SearchFor = { itemId: string; mode: SearchMode; query: string }
type Answers = Record<string, InquiryAnswer>
/** Warunki w polach formularza — pusty string zamiast null, bo tak działa `<input>`. */
type TermsDraft = Record<keyof InquiryTerms, string>

const PLN = new Intl.NumberFormat('pl-PL', { style: 'currency', currency: 'PLN' })

/** Ile razy i co ile pytamy serwer, czy dodatek podjął już list (łącznie ok. 40 s). */
const WATCH_TRIES = 20
const WATCH_EVERY_MS = 2000

/** Data wysłania maila źródłowego; nieczytelną wartość pokazujemy bez zmian. */
function mailDate(value: string): string {
  const d = new Date(value)
  return Number.isNaN(d.getTime())
    ? value
    : d.toLocaleString('pl-PL', { dateStyle: 'short', timeStyle: 'short' })
}

/**
 * Zapasowe kopiowanie HTML: list wstawiony poza ekranem, zaznaczony i skopiowany jak zaznaczenie
 * na stronie — schowek dostaje wtedy wersję z formatowaniem. HTML pochodzi z naszego serwera.
 */
function copyHtmlBySelection(html: string): boolean {
  const holder = document.createElement('div')
  holder.innerHTML = html
  holder.setAttribute('aria-hidden', 'true')
  holder.style.position = 'fixed'
  holder.style.left = '-10000px'
  holder.style.top = '0'
  document.body.appendChild(holder)
  const selection = window.getSelection()
  const range = document.createRange()
  range.selectNodeContents(holder)
  selection?.removeAllRanges()
  selection?.addRange(range)
  let ok = false
  try {
    ok = document.execCommand('copy')
  } catch {
    ok = false
  }
  selection?.removeAllRanges()
  holder.remove()
  return ok
}

function priceByMode(
  p: { catalog_pln: number | null; offer_pln: number | null },
  mode: InquiryPriceMode,
): string | null {
  const v = mode === 'catalog' ? p.catalog_pln : mode === 'catalog_margin' ? p.offer_pln : null
  return v == null ? null : PLN.format(v)
}

/** Klucz jednostki do porównania („szt.”, „sztuk” → „szt”); brak jednostki pozycji zapytania = sztuki. */
function unitKey(unit: string | null | undefined): string {
  const u = (unit ?? '').trim().toLowerCase().replace(/[.\s]/g, '')
  if (u === '' || u.startsWith('szt')) return 'szt'
  if (u.startsWith('par')) return 'par'
  if (u.startsWith('op')) return 'op'
  if (u.startsWith('kpl') || u.startsWith('komplet')) return 'kpl'
  return u
}

/**
 * Ilość pozycji zapytania do podpowiedzi „→ zamówisz N” przy warunku zamawiania. null = ilość nieznana albo
 * klient liczy w innej jednostce niż sklep (np. „op.” wobec „szt.”) — wtedy sam znaczek, bez zgadywania.
 */
function qtyForOrderCondition(item: InquiryItem, oq: OrderQuantity | null | undefined): number | null {
  // jednostka warunku nieznana (np. Delta Plus) — nie wiadomo, czy klient liczy w tym samym; sam znaczek
  if (!oq || !(oq.unit ?? '').trim()) return null
  const m = /^\s*(\d+(?:[.,]\d+)?)\s*(.*)$/u.exec(item.qty ?? '')
  if (!m) return null
  if (unitKey(item.unit || m[2]) !== unitKey(oq.unit)) return null
  const qty = Number(m[1].replace(',', '.'))
  return Number.isFinite(qty) && qty > 0 ? qty : null
}

const confidenceBadge: Record<InquiryConfidence, { label: string; cls: string }> = {
  high: { label: 'pewne', cls: 'bg-emerald-100 text-emerald-800' },
  medium: { label: 'sprawdź', cls: 'bg-amber-100 text-amber-800' },
  none: { label: 'brak w katalogu', cls: 'bg-red-100 text-red-800' },
}

// Model nie ocenił kart — o katalogu nic nie wiemy, więc nie wolno pisać „brak w katalogu”.
const modelFailedBadge = { label: 'model nie odpowiedział', cls: 'bg-slate-200 text-slate-800' }

const flagLabel: Record<InquiryFlag, string> = {
  low_score: 'niski wynik dopasowania',
  ambiguous: 'kilku podobnych kandydatów',
  no_price: 'brak ceny',
  card_default: 'pytanie AI z domyślną odpowiedzią',
  qty_unknown: 'brak ilości w mailu',
  requirement_unconfirmed: 'karta nie potwierdza warunku z zapytania',
  requirement_note: 'warunek szczególny do przeczytania',
  product_from_subject: 'wyrób wzięty z tematu maila',
  model_failed: 'wyszukaj ponownie przyciskiem „Szukaj AI”',
  requirement_conflict: 'możliwa sprzeczność w wierszu klienta',
  brand_not_in_catalog: 'zamiennik innej marki',
}

const priceModeOptions: { id: InquiryPriceMode; label: string }[] = [
  { id: 'none', label: 'Bez cen' },
  { id: 'catalog', label: 'Cena katalogowa' },
  { id: 'catalog_margin', label: 'Zakup + marża' },
]

/**
 * Warunki, o które klient pyta wprost w mailu. Podpowiedzi pokazują format
 * odpowiedzi, a nie przykładowe zobowiązanie — handlowiec wpisuje swoje.
 */
const termFields: { key: keyof InquiryTerms; label: string; placeholder: string }[] = [
  { key: 'lead_time', label: 'Termin realizacji', placeholder: 'np. 3 dni robocze od zamówienia' },
  { key: 'delivery', label: 'Koszt dostawy', placeholder: 'np. 25 zł netto, kurier' },
  { key: 'payment', label: 'Płatność', placeholder: 'np. przelew 30 dni' },
  { key: 'validity', label: 'Ważność oferty', placeholder: 'np. 14 dni' },
]

/** Limit z kontraktu API — dłuższego warunku serwer i tak nie przyjmie. */
const TERM_MAX = 200

function who(entry: InquiryDuplicateRef): string {
  return entry.user?.name ?? 'inna osoba'
}

/**
 * Ten list jest kopią cudzego zapytania — pasek nad listem, żeby nie napisać
 * drugiej odpowiedzi na ten sam mail.
 */
function DuplicateOfBar({ origin }: { origin: InquiryDuplicateRef }) {
  return (
    <p className="rounded border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900">
      <span className="font-medium">
        To kopia zapytania #{origin.id}, które prowadzi {who(origin)}
        {origin.created_at ? ` (od ${mailDate(origin.created_at)})` : ''}.
      </span>{' '}
      {origin.replied_at && (
        <span className="font-semibold">
          Odpowiedź do klienta poszła stamtąd {mailDate(origin.replied_at)} — uzgodnij, zanim wyślesz
          swoją.{' '}
        </span>
      )}
      <Link to={`/inquiries/${origin.id}`} className="text-blue-700 underline">
        Otwórz tamto zapytanie
      </Link>
    </p>
  )
}

/**
 * Ten sam mail prowadzą też inni. Najważniejsze jest to, czy ktoś już odpowiedział
 * klientowi — wtedy pasek jest czerwony, bo grozi wysłaniem drugiej oferty.
 */
function DuplicatesBar({ list }: { list: InquiryDuplicateRef[] }) {
  const replied = list.filter((d) => d.replied_at)
  const danger = replied.length > 0
  return (
    <div
      className={`rounded border px-3 py-2 text-sm ${
        danger ? 'border-red-300 bg-red-50 text-red-900' : 'border-amber-300 bg-amber-50 text-amber-900'
      }`}
    >
      <p className="font-medium">Ten sam mail obsługują też inne osoby.</p>
      {danger && (
        <p className="mt-0.5 font-semibold">
          Odpowiedź do klienta już poszła ({replied.map(who).join(', ')}) — nie wysyłaj drugiej oferty
          bez uzgodnienia.
        </p>
      )}
      <ul className="mt-1 space-y-0.5 text-xs">
        {list.map((d) => (
          <li key={d.id}>
            <span className="font-medium">{who(d)}</span>
            {d.created_at ? ` · od ${mailDate(d.created_at)}` : ''}
            {' · '}
            {d.replied_at ? (
              <span className="font-semibold">
                odpowiedź wysłana {mailDate(d.replied_at)}
              </span>
            ) : (
              'jeszcze bez odpowiedzi'
            )}
            {' · '}
            <Link to={`/inquiries/${d.id}`} className="text-blue-700 underline">
              zapytanie #{d.id}
            </Link>
          </li>
        ))}
      </ul>
    </div>
  )
}

function Chip({
  active,
  disabled,
  onClick,
  children,
}: {
  active: boolean
  disabled: boolean
  onClick: () => void
  children: ReactNode
}) {
  return (
    <button
      type="button"
      disabled={disabled}
      onClick={onClick}
      className={`max-w-full rounded-full border px-2.5 py-1 text-left text-xs leading-snug ${
        active
          ? 'border-blue-600 bg-blue-600 text-white'
          : 'border-slate-300 bg-white text-slate-700 hover:border-blue-300'
      } disabled:opacity-50`}
    >
      {children}
    </button>
  )
}

function CardChips({
  card,
  answers,
  busy,
  customDraft,
  onCustomDraft,
  onAnswer,
}: {
  card: InquiryCard
  answers: Answers
  busy: boolean
  customDraft: string
  onCustomDraft: (value: string) => void
  onAnswer: (key: string, answer: InquiryAnswer) => void
}) {
  const current = answers[card.id]
  return (
    <div>
      <p className="text-xs font-semibold text-slate-700">{card.title}</p>
      {card.prompt && <p className="text-[11px] text-slate-500">{card.prompt}</p>}
      <div className="mt-1.5 flex flex-wrap gap-1.5">
        {card.options.map((opt) => (
          <Chip
            key={opt.id}
            active={current?.option_id === opt.id}
            disabled={busy}
            onClick={() => onAnswer(card.id, { option_id: opt.id, custom: current?.custom ?? null })}
          >
            {opt.label}
          </Chip>
        ))}
      </div>
      {card.allow_custom && (
        <input
          className="mt-1.5 w-full rounded border border-slate-300 px-2 py-1 text-xs"
          disabled={busy}
          placeholder="Własna uwaga do tego pytania"
          value={customDraft}
          onChange={(e) => onCustomDraft(e.target.value)}
          onBlur={() => {
            if (customDraft === (current?.custom ?? '')) return
            onAnswer(card.id, {
              option_id: current?.option_id ?? card.options[0]?.id ?? '',
              custom: customDraft.trim() || null,
            })
          }}
        />
      )}
    </div>
  )
}

function ItemRow({
  item,
  index,
  priceMode,
  answers,
  busy,
  customDrafts,
  onCustomDraft,
  onAnswer,
  onPreview,
  onSearch,
  manualDraft,
  onManualDraft,
  onManualPriceBlur,
}: {
  item: InquiryItem
  index: number
  priceMode: InquiryPriceMode
  answers: Answers
  busy: boolean
  customDrafts: Record<string, string>
  onCustomDraft: (cardId: string, value: string) => void
  onAnswer: (key: string, answer: InquiryAnswer) => void
  onPreview: (productId: number, query: string) => void
  onSearch: (mode: SearchMode) => void
  manualDraft: string
  onManualDraft: (value: string) => void
  onManualPriceBlur: () => void
}) {
  const badge = item.flags.includes('model_failed') ? modelFailedBadge : confidenceBadge[item.confidence]
  const chosenId = item.chosen.startsWith('p:') ? Number(item.chosen.slice(2)) : null
  const chosen = chosenId != null ? item.candidates.find((c) => c.id === chosenId) ?? null : null
  const computedPrice = chosen ? priceByMode(chosen, priceMode) : null
  const chosenPrice = item.manual_price != null && priceMode !== 'none' ? PLN.format(item.manual_price) : computedPrice
  const subAnswer = item.substitute_key ? answers[item.substitute_key]?.option_id ?? 'no' : null
  const meta = [item.qty, item.unit].filter(Boolean).join(' ')

  return (
    <div className="border-t border-slate-100 pt-3 first:border-t-0 first:pt-0">
      <div className="flex flex-wrap items-center gap-2">
        <span className="inline-flex h-6 min-w-6 items-center justify-center rounded bg-slate-800 px-1.5 text-xs font-semibold text-white">
          {index + 1}
        </span>
        {meta && <span className="text-xs font-medium text-slate-800">{meta}</span>}
        {item.size && <span className="text-xs text-slate-600">rozm. {item.size}</span>}
        <span className={`rounded-full px-2 py-0.5 text-[11px] font-medium ${badge.cls}`}>{badge.label}</span>
        {item.flags.length > 0 && (
          <span className="text-[11px] text-slate-500">
            {item.flags.map((f) => flagLabel[f]).join(' · ')}
          </span>
        )}
      </div>

      {item.quote && (
        <blockquote className="mt-2 border-l-4 border-amber-400 bg-amber-50 px-2.5 py-1.5 text-xs leading-relaxed text-slate-800">
          {item.quote}
        </blockquote>
      )}

      {/* Wniosek modelu, nie fakt z maila — dlatego „możliwa” i prośba o wyjaśnienie z klientem. */}
      {item.conflict && (
        <p className="mt-1.5 rounded border border-orange-300 bg-orange-50 px-2.5 py-1.5 text-xs text-orange-900">
          <span className="font-semibold">Możliwa sprzeczność w zapytaniu</span> (ocena AI — wyjaśnij z klientem):{' '}
          {item.conflict}
        </p>
      )}

      {item.brand_not_in_catalog && (
        <p className="mt-1.5 rounded border border-orange-300 bg-orange-50 px-2.5 py-1.5 text-xs text-orange-900">
          <span className="font-semibold">Marki „{item.brand_not_in_catalog}” nie ma w katalogu</span> — kandydaci poniżej
          to zamienniki innej marki. Do listu wejdzie dopiero ten, który wybierzesz.
        </p>
      )}

      {/* Warunki szczególne klienta: wprost, z werdyktem karty. Karta bez
          potwierdzenia nie wchodzi do listu, więc handlowiec musi wiedzieć,
          czego brakuje i gdzie sam ma to sprawdzić. */}
      {item.requirements.length > 0 && (
        <ul className="mt-2 space-y-1">
          {item.requirements.map((req) => (
            <li key={req.text} className="text-[11px] leading-relaxed">
              <span className="font-semibold text-slate-800">Warunek z zapytania:</span>{' '}
              <span className="text-slate-800">{req.text}</span>{' '}
              {!req.checkable ? (
                <span className="text-slate-500">— sprawdź sam, tego nie sprawdzi żadna reguła</span>
              ) : req.ok === true ? (
                <span className="text-emerald-700">— potwierdzony w karcie</span>
              ) : (
                <span className="text-red-700">— karta tego nie potwierdza</span>
              )}
            </li>
          ))}
        </ul>
      )}

      <div className="mt-2 flex items-start justify-between gap-2 text-xs">
        <div className="min-w-0">
          {chosen ? (
            <>
              <p className="text-slate-800">
                <span className="font-semibold">{chosen.sku}</span> · {chosen.name}
                {chosen.manufacturer ? ` · ${chosen.manufacturer}` : ''}
                {chosenPrice ? ` · ${chosenPrice}` : priceMode !== 'none' ? ' · cena do potwierdzenia' : ''}
                {item.manual_price != null && priceMode !== 'none' && ' (ręcznie)'}
              </p>
              <OrderQuantityBadge
                oq={chosen.order_quantity}
                qty={qtyForOrderCondition(item, chosen.order_quantity)}
                block
                className="mt-0.5"
              />
              {chosen.reason && <p className="text-[11px] text-slate-500">{chosen.reason}</p>}
              {/* Cena z negocjacji albo promocji: wchodzi do tekstu i do tabeli listu,
                  więc nie trzeba poprawiać treści ręcznie (to kasowało tabelę). */}
              {priceMode !== 'none' && (
                <label className="mt-1.5 flex flex-wrap items-center gap-1.5 text-[11px] text-slate-600">
                  Cena ręczna
                  <input
                    className="w-24 rounded border border-slate-300 px-2 py-0.5 text-xs text-slate-800"
                    disabled={busy}
                    inputMode="decimal"
                    placeholder={computedPrice ?? 'np. 159,00'}
                    value={manualDraft}
                    onChange={(e) => onManualDraft(e.target.value)}
                    onBlur={onManualPriceBlur}
                  />
                  zł netto
                  <span className="text-slate-400">
                    {item.manual_price != null && computedPrice
                      ? `wyliczona: ${computedPrice} · puste pole = cena wyliczona`
                      : 'puste pole = cena wyliczona'}
                  </span>
                </label>
              )}
            </>
          ) : chosenId != null ? (
            <p className="text-slate-800">Towar #{chosenId} (spoza listy kandydatów)</p>
          ) : (
            <p className="text-slate-600">W liście: sprawdzimy i wrócimy z propozycją — bez SKU.</p>
          )}
        </div>
        {/* Model podsunął nie to, czego szukał klient — handlowiec szuka sam:
            po nazwie/kodzie albo opisem przez AI. Wybrany wyrób wchodzi do listu. */}
        <div className="flex shrink-0 gap-1.5">
          <button
            type="button"
            disabled={busy}
            onClick={() => onSearch('catalog')}
            title="Szukanie po nazwie i kodzie w katalogu"
            className="rounded-full border border-sky-300 bg-white px-2 py-0.5 text-[11px] font-medium text-sky-800 hover:bg-sky-50 disabled:opacity-50"
          >
            Szukaj
          </button>
          <button
            type="button"
            disabled={busy}
            onClick={() => onSearch('ai')}
            title="Szukanie AI po opisie wymagania"
            className="rounded-full border border-violet-300 bg-white px-2 py-0.5 text-[11px] font-medium text-violet-800 hover:bg-violet-50 disabled:opacity-50"
          >
            Szukaj AI
          </button>
        </div>
      </div>

      <div className="mt-2">
        <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Alternatywy</p>
        <div className="mt-1 flex flex-wrap items-center gap-1.5">
          {item.candidates.map((c) => (
            <span key={c.id} className="inline-flex items-center gap-1">
              <Chip
                active={item.chosen === `p:${c.id}`}
                disabled={busy}
                onClick={() => onAnswer(item.answer_key, { option_id: `p:${c.id}` })}
              >
                {c.sku} · {c.name} · {c.source === 'manual' ? 'ręcznie' : c.source === 'link' ? 'z linku' : `${c.score}%`}
              </Chip>
              <OrderQuantityBadge oq={c.order_quantity} />
              <button
                type="button"
                disabled={busy}
                onClick={() => onPreview(c.id, item.quote ?? '')}
                className="rounded-full border border-violet-300 bg-white px-2 py-0.5 text-[11px] font-medium text-violet-800 hover:bg-violet-50 disabled:opacity-50"
              >
                Opis
              </button>
            </span>
          ))}
          <Chip
            active={item.chosen === 'check'}
            disabled={busy}
            onClick={() => onAnswer(item.answer_key, { option_id: 'check' })}
          >
            Sprawdzimy i wrócimy
          </Chip>
        </div>
      </div>

      {item.substitute_key && (
        <div className="mt-2">
          <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Zamienniki</p>
          <div className="mt-1 flex flex-wrap gap-1.5">
            <Chip
              active={subAnswer === 'no'}
              disabled={busy}
              onClick={() => onAnswer(item.substitute_key!, { option_id: 'no' })}
            >
              Tylko wskazany
            </Chip>
            {item.substitutes.map((s) => (
              <Chip
                key={s.id}
                active={subAnswer === `p:${s.id}`}
                disabled={busy}
                onClick={() => onAnswer(item.substitute_key!, { option_id: `p:${s.id}` })}
              >
                Zamiennik: {s.sku} · {s.name}
              </Chip>
            ))}
          </div>
        </div>
      )}

      {item.cards.length > 0 && (
        <div className="mt-2 space-y-2">
          {item.cards.map((card) => (
            <CardChips
              key={card.id}
              card={card}
              answers={answers}
              busy={busy}
              customDraft={customDrafts[card.id] ?? ''}
              onCustomDraft={(v) => onCustomDraft(card.id, v)}
              onAnswer={onAnswer}
            />
          ))}
        </div>
      )}
    </div>
  )
}

function customDraftsFrom(p: InquiryPayload): Record<string, string> {
  const out: Record<string, string> = {}
  const cards = [...p.items.flatMap((i) => i.cards), ...p.global_cards]
  for (const card of cards) {
    if (card.allow_custom) out[card.id] = p.answers[card.id]?.custom ?? ''
  }
  return out
}

/** Cena ręczna w polskim zapisie („159,00”); pozycja bez niej = puste pole. */
function manualPriceText(value: number | null | undefined): string {
  return value == null ? '' : value.toFixed(2).replace('.', ',')
}

function manualDraftsFrom(p: InquiryPayload): Record<string, string> {
  const out: Record<string, string> = {}
  for (const item of p.items) out[item.id] = manualPriceText(item.manual_price)
  return out
}

/** Warunki z serwera do pól; pole dochodzi po stronie API, więc czytamy ostrożnie. */
function termsDraftFrom(p: InquiryPayload): TermsDraft {
  const saved: Partial<InquiryTerms> = p.terms ?? {}
  return {
    lead_time: saved.lead_time ?? '',
    delivery: saved.delivery ?? '',
    payment: saved.payment ?? '',
    validity: saved.validity ?? '',
  }
}

/** Pola do kontraktu API: puste pole = null, czyli warunek skasowany. */
function termsPayload(draft: TermsDraft): InquiryTerms {
  return {
    lead_time: draft.lead_time.trim() || null,
    delivery: draft.delivery.trim() || null,
    payment: draft.payment.trim() || null,
    validity: draft.validity.trim() || null,
  }
}

export function InquiryReply() {
  const { id } = useParams()
  const navigate = useNavigate()
  const { user } = useAuth()
  const [inquiry, setInquiry] = useState<InquiryPayload | null>(null)
  const [subject, setSubject] = useState('')
  const [body, setBody] = useState('')
  const [err, setErr] = useState('')
  const [msg, setMsg] = useState('')
  const [loading, setLoading] = useState(true)
  const [composeBusy, setComposeBusy] = useState(false)
  const [saving, setSaving] = useState(false)
  const [marginDraft, setMarginDraft] = useState('')
  const [noteDraft, setNoteDraft] = useState('')
  const [termsDraft, setTermsDraft] = useState<TermsDraft>({
    lead_time: '',
    delivery: '',
    payment: '',
    validity: '',
  })
  const [customDrafts, setCustomDrafts] = useState<Record<string, string>>({})
  const [manualDrafts, setManualDrafts] = useState<Record<string, string>>({})
  const [checked, setChecked] = useState<Record<number, boolean>>({})
  const [previewId, setPreviewId] = useState<number | null>(null)
  const [previewQuery, setPreviewQuery] = useState('')
  const [searchFor, setSearchFor] = useState<SearchFor | null>(null)
  const [contactOpen, setContactOpen] = useState(false)
  const composeSec = useBusySeconds(composeBusy)

  // Treść zapisana na serwerze (PATCH) — do wykrywania niezapisanych edycji.
  // Numer otwartego zapytania dla pilnowania Thunderbirda: po przejściu na inne
  // zapytanie stary nasłuch nie ma prawa nadpisać komunikatu na ekranie.
  const inquiryRef = useRef<number | null>(null)
  const serverRef = useRef<Draft>({ subject: '', body: '' })
  // Treść ostatnio wygenerowana przez compose/wczytana — do ochrony ręcznych zmian przed regeneracją.
  const composedRef = useRef<Draft>({ subject: '', body: '' })
  const pendingSave = useRef<Promise<unknown> | null>(null)

  function resetDrafts(p: InquiryPayload) {
    setMarginDraft(String(p.price.margin))
    setNoteDraft(p.extra_note ?? '')
    setTermsDraft(termsDraftFrom(p))
    setCustomDrafts(customDraftsFrom(p))
    setManualDrafts(manualDraftsFrom(p))
  }

  function applyComposed(p: InquiryPayload) {
    const draft = { subject: p.reply_subject ?? '', body: p.reply_body ?? '' }
    inquiryRef.current = p.id
    serverRef.current = draft
    composedRef.current = draft
    setInquiry(p)
    setSubject(draft.subject)
    setBody(draft.body)
    resetDrafts(p)
  }

  useEffect(() => {
    if (!id) return
    setLoading(true)
    setErr('')
    setMsg('')
    api<InquiryPayload>(`/inquiries/${id}`)
      .then((row) => {
        applyComposed(row)
        setChecked({})
      })
      .catch((ex) => setErr(ex instanceof Error ? ex.message : 'Nie udało się wczytać'))
      .finally(() => setLoading(false))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [id])

  /** Zapisuje poprawki tematu i treści; zwraca zapytanie po zapisie albo null, gdy nie było czego zapisać. */
  async function saveEdits(): Promise<InquiryPayload | null> {
    if (!inquiry) return null
    const next: Draft = { subject, body }
    const prev = serverRef.current
    if (next.subject === prev.subject && next.body === prev.body) return null
    serverRef.current = next
    setSaving(true)
    const p = api<InquiryPayload>(`/inquiries/${inquiry.id}`, {
      method: 'PATCH',
      body: JSON.stringify({ reply_subject: next.subject, reply_body: next.body }),
    })
      .then((res) => {
        setInquiry(res)
        return res
      })
      .catch((ex: unknown) => {
        serverRef.current = prev
        setErr(ex instanceof Error ? ex.message : 'Nie udało się zapisać zmian')
        throw ex
      })
      .finally(() => setSaving(false))
    pendingSave.current = p
    return await p
  }

  /** Przepisanie listu kasuje ręczne poprawki treści — pytamy o zgodę raz, przed żądaniem. */
  function overwriteAllowed(): boolean {
    const edited = subject !== composedRef.current.subject || body !== composedRef.current.body

    return !edited || window.confirm('Nadpisać ręczne zmiany w treści listu?')
  }

  async function compose(partial: Answers, tone?: InquiryTone): Promise<boolean> {
    if (!inquiry || composeBusy) return false
    if (!overwriteAllowed()) {
      resetDrafts(inquiry)
      return false
    }
    setComposeBusy(true)
    setErr('')
    setMsg('')
    try {
      if (pendingSave.current) await pendingSave.current.catch(() => undefined)
      const done = await api<InquiryPayload>(`/inquiries/${inquiry.id}/compose`, {
        method: 'POST',
        body: JSON.stringify({
          answers: partial,
          extra_note: noteDraft.trim() || null,
          // warunki idą przy każdym przepisaniu listu, tak samo jak dopisek
          terms: termsPayload(termsDraft),
          // bez pola „tone” backend zostawia zapisany szablon
          ...(tone ? { tone } : {}),
        }),
      })
      applyComposed(done)
      setMsg('List przepisany.')
      return true
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Błąd pisania odpowiedzi')
      return false
    } finally {
      setComposeBusy(false)
    }
  }

  function onAnswer(key: string, answer: InquiryAnswer) {
    void compose({ [key]: answer })
  }

  /**
   * Wyrób wyszukany ręcznie wchodzi do pozycji: serwer dopisuje go do jej
   * alternatyw i od razu przepisuje list. Dopisek i warunki jadą z żądaniem,
   * żeby niezapisane pola nie przepadły przy przepisaniu.
   */
  async function pickProduct(itemId: string, productId: number): Promise<void> {
    if (!inquiry || composeBusy) return
    if (!overwriteAllowed()) return
    setComposeBusy(true)
    setErr('')
    setMsg('')
    try {
      if (pendingSave.current) await pendingSave.current.catch(() => undefined)
      const done = await api<InquiryPayload>(`/inquiries/${inquiry.id}/pick-product`, {
        method: 'POST',
        body: JSON.stringify({
          item_id: itemId,
          product_id: productId,
          extra_note: noteDraft.trim() || null,
          terms: termsPayload(termsDraft),
        }),
      })
      applyComposed(done)
      setMsg('Wyrób z wyszukiwania wstawiony do pozycji, list przepisany.')
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Nie udało się wstawić wyrobu do pozycji')
    } finally {
      setComposeBusy(false)
    }
  }

  function onTone(tone: InquiryTone) {
    if (!inquiry || tone === inquiry.tone) return
    void compose({}, tone)
  }

  function onPriceMode(mode: InquiryPriceMode) {
    if (!inquiry || mode === inquiry.price.mode) return
    const custom = mode === 'catalog_margin' ? marginDraft.trim() || String(inquiry.price.margin) : null
    void compose({ price: { option_id: mode, custom } })
  }

  function onMarginBlur() {
    if (!inquiry) return
    const value = marginDraft.trim()
    if (value === '' || value === String(inquiry.price.margin)) {
      setMarginDraft(String(inquiry.price.margin))
      return
    }
    // odrzuconą marżę cofamy do zapisanej, żeby w polu nie została liczba spoza oferty
    void compose({ price: { option_id: 'catalog_margin', custom: value } }).then((ok) => {
      if (!ok) setMarginDraft(String(inquiry.price.margin))
    })
  }

  /**
   * Cena ręczna należy do wyrobu wybranego w tej chwili — po zmianie wyrobu
   * serwer przestaje ją stosować. Nieprzyjętą kwotę cofamy do zapisanej.
   */
  function onManualPriceBlur(item: InquiryItem) {
    if (!inquiry || !item.chosen.startsWith('p:')) return
    const value = (manualDrafts[item.id] ?? '').trim()
    const saved = manualPriceText(item.manual_price)
    if (value === saved) return
    void compose({ [item.manual_price_key]: { option_id: item.chosen, custom: value || null } }).then((ok) => {
      if (!ok) setManualDrafts((d) => ({ ...d, [item.id]: saved }))
    })
  }

  function onNoteBlur() {
    if (!inquiry) return
    if (noteDraft.trim() === (inquiry.extra_note ?? '').trim()) return
    void compose({})
  }

  /** Przepisujemy list dopiero, gdy warunek naprawdę się zmienił — samo wejście w pole nic nie kosztuje. */
  function onTermsBlur(key: keyof InquiryTerms) {
    if (!inquiry) return
    if (termsDraft[key].trim() === termsDraftFrom(inquiry)[key].trim()) return
    void compose({})
  }

  async function copyText(text: string): Promise<boolean> {
    try {
      await navigator.clipboard.writeText(text)
      return true
    } catch {
      setErr('Nie udało się skopiować do schowka.')
      return false
    }
  }

  async function copyBody() {
    setMsg('')
    try {
      await saveEdits()
    } catch {
      return
    }
    if (await copyText(body)) setMsg('Skopiowano treść.')
  }

  /**
   * List z tabelą do dowolnej poczty: w schowku jest HTML i zwykły tekst naraz, więc Outlook czy Gmail
   * wklejają tabelę, a pole bez formatowania sam tekst. Tabela z chwili po zapisie — ręczna poprawka
   * treści ją kasuje (serwer nie odda tabeli mówiącej co innego niż zatwierdzony tekst).
   */
  async function copyWithTable() {
    if (!inquiry) return
    setMsg('')
    let saved: InquiryPayload | null
    try {
      saved = await saveEdits()
    } catch {
      return
    }
    const html = (saved ?? inquiry).reply_html
    if (!html) {
      setErr('Po ręcznej poprawce treści list nie ma tabeli — skopiuj samą treść.')
      return
    }
    if (await copyHtml(html, body)) setMsg('Skopiowano list z tabelą — wklej go w treść wiadomości (Ctrl+V).')
  }

  async function copyHtml(html: string, text: string): Promise<boolean> {
    try {
      await navigator.clipboard.write([
        new ClipboardItem({
          'text/html': new Blob([html], { type: 'text/html' }),
          'text/plain': new Blob([text], { type: 'text/plain' }),
        }),
      ])
      return true
    } catch {
      // Przeglądarka bez ClipboardItem (starszy Firefox): kopiujemy zaznaczenie wstawionego listu.
      if (copyHtmlBySelection(html)) return true
      setErr('Nie udało się skopiować do schowka.')
      return false
    }
  }

  async function copyAndMarkSent() {
    if (!inquiry) return
    setMsg('')
    try {
      await saveEdits()
    } catch {
      return
    }
    const text = [subject.trim(), '', body].filter(Boolean).join('\n')
    if (!(await copyText(text))) return
    try {
      const res = await api<InquiryPayload>(`/inquiries/${inquiry.id}/replied`, {
        method: 'POST',
        body: JSON.stringify({ replied: true }),
      })
      setInquiry(res)
      setMsg('Skopiowano temat i treść. Zapytanie oznaczone jako wysłane.')
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Skopiowano, ale nie udało się oznaczyć jako wysłane.')
    }
  }

  /**
   * Przeglądarka nie sięgnie do poczty na komputerze, więc zostawiamy prośbę
   * na serwerze — dodatek do Thunderbirda podejmuje ją w ciągu kilku sekund
   * i otwiera okno odpowiedzi na tym samym mailu.
   */
  async function sendViaThunderbird() {
    if (!inquiry) return
    setMsg('')
    try {
      await saveEdits()
    } catch {
      return
    }
    try {
      const res = await api<InquiryPayload>(`/inquiries/${inquiry.id}/queue-reply`, {
        method: 'POST',
        body: JSON.stringify({ queued: true }),
      })
      setInquiry(res)
      setMsg('Zapisano. Thunderbird otworzy okno odpowiedzi w ciągu kilku sekund.')
      void watchThunderbird(inquiry.id)
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Nie udało się przekazać listu do Thunderbirda.')
    }
  }

  /**
   * Czy Thunderbird odebrał list. Handlowiec zostaje w przeglądarce i nie widzi
   * okna odpowiedzi — zwłaszcza gdy Thunderbird stoi zminimalizowany. Prośbę
   * kasuje dodatek w chwili podjęcia, więc jej zniknięcie jest potwierdzeniem.
   */
  async function watchThunderbird(inquiryId: number) {
    for (let i = 0; i < WATCH_TRIES; i += 1) {
      await new Promise((done) => setTimeout(done, WATCH_EVERY_MS))
      let row: InquiryPayload
      try {
        row = await api<InquiryPayload>(`/inquiries/${inquiryId}`)
      } catch {
        return
      }
      // inne zapytanie na ekranie albo ręczne anulowanie — przestajemy pilnować
      if (inquiryRef.current !== inquiryId) return
      if (row.send_requested_at === null) {
        setInquiry(row)
        setMsg('Thunderbird otworzył okno odpowiedzi. Maila wysyłasz stamtąd.')

        return
      }
    }
    if (inquiryRef.current === inquiryId) {
      setMsg('Thunderbird jeszcze nie odebrał listu — sprawdź, czy jest uruchomiony i zalogowany w dodatku.')
    }
  }

  async function cancelThunderbird() {
    if (!inquiry) return
    setMsg('')
    try {
      const res = await api<InquiryPayload>(`/inquiries/${inquiry.id}/queue-reply`, {
        method: 'POST',
        body: JSON.stringify({ queued: false }),
      })
      setInquiry(res)
      setMsg('Prośba o wysyłkę anulowana.')
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Nie udało się anulować.')
    }
  }

  /** Kasuje wyłącznie autor zapytania; serwer i tak sprawdza to drugi raz. */
  async function removeInquiry() {
    if (!inquiry) return
    const label = inquiry.source_subject || inquiry.reply_subject || `#${inquiry.id}`
    const ok = window.confirm(
      `Usunąć zapytanie „${label}”?\n\n` +
        'Znika treść maila, dobrane pozycje i przygotowany list. Tego nie da się cofnąć.',
    )
    if (!ok) return

    setErr('')
    try {
      await api(`/inquiries/${inquiry.id}`, { method: 'DELETE' })
      navigate('/inquiries', { replace: true })
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Nie udało się usunąć zapytania.')
    }
  }

  async function unmarkSent() {
    if (!inquiry) return
    setMsg('')
    try {
      const res = await api<InquiryPayload>(`/inquiries/${inquiry.id}/replied`, {
        method: 'POST',
        body: JSON.stringify({ replied: false }),
      })
      setInquiry(res)
      setMsg('Oznaczenie cofnięte.')
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Nie udało się cofnąć oznaczenia')
    }
  }

  if (loading) return <p className="text-sm text-slate-500">Ładowanie…</p>
  if (!inquiry) return <p className="text-sm text-red-600">{err || 'Brak zapytania.'}</p>

  const total = inquiry.items.length
  const busy = composeBusy
  // Cudze zapytanie (uprawnienie „otwieranie cudzych”) jest tylko do podglądu —
  // serwer i tak odrzuci każdą zmianę spoza konta autora.
  const readOnly = inquiry.user?.id !== user?.id
  const locked = busy || readOnly
  // Inne zapytania z tego samego maila; pole dochodzi po stronie API, więc czytamy ostrożnie.
  const duplicates = Array.isArray(inquiry.duplicates) ? inquiry.duplicates : []
  // Nadawca dokładnie tak, jak przyszedł w mailu — bez sklejania brakujących kawałków.
  const sender = [inquiry.source_from_name, inquiry.source_from_email]
    .filter(Boolean)
    .join(inquiry.source_from_name && inquiry.source_from_email ? ' · ' : '')
  const banner = inquiry.replied_at
    ? {
        cls: 'bg-emerald-50 text-emerald-800',
        text: `Wysłano ${new Date(inquiry.replied_at).toLocaleString('pl-PL')}`,
      }
    : inquiry.attention_count > 0
      ? {
          cls: 'bg-amber-50 text-amber-800',
          text: `${inquiry.attention_count} z ${total} pozycji wymaga sprawdzenia`,
        }
      : {
          cls: 'bg-emerald-50 text-emerald-800',
          text: 'Wszystkie pozycje pewne — list gotowy do skopiowania',
        }

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold">Odpowiedź na zapytanie</h1>
          <p className="text-xs text-slate-500">
            {inquiry.client?.name ? `${inquiry.client.name} · ` : ''}
            {inquiry.source_subject || `Zapytanie #${inquiry.id}`}
          </p>
          <div className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-[11px] text-slate-500">
            {sender && <span>Od: {sender}</span>}
            {inquiry.source_sent_at && <span>Mail z {mailDate(inquiry.source_sent_at)}</span>}
            {inquiry.user?.name && <span>Prowadzi: {inquiry.user.name}</span>}
            <InquiryContactChip contact={inquiry.contact} onOpen={() => setContactOpen(true)} />
          </div>
        </div>
        <Link to="/inquiries" className="text-xs text-blue-600 hover:underline">
          ← Wróć do zapytań
        </Link>
      </div>

      {inquiry.duplicate_of && <DuplicateOfBar origin={inquiry.duplicate_of} />}
      {duplicates.length > 0 && <DuplicatesBar list={duplicates} />}

      {readOnly && (
        <p className="rounded border border-slate-300 bg-slate-50 px-3 py-2 text-sm text-slate-700">
          Podgląd zapytania, które prowadzi {inquiry.user?.name ?? 'inna osoba'}. Zmieniać list i wysyłać
          odpowiedź może tylko autor.
        </p>
      )}

      <p className={`rounded px-3 py-2 text-sm font-medium ${banner.cls}`}>{banner.text}</p>

      {msg && <p className="rounded bg-green-50 px-3 py-2 text-xs text-green-800">{msg}</p>}
      {err && <p className="rounded bg-red-50 px-3 py-2 text-xs text-red-700">{err}</p>}

      <div className="grid items-start gap-4 lg:grid-cols-2">
        <div className="space-y-4">
          <div className="rounded-xl bg-white p-4 shadow-sm">
            <label className="block text-xs font-medium text-slate-600">
              Temat
              <input
                className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5 text-sm"
                value={subject}
                disabled={locked}
                onChange={(e) => setSubject(e.target.value)}
                onBlur={() => void saveEdits().catch(() => undefined)}
              />
            </label>
            <label className="mt-3 block text-xs font-medium text-slate-600">
              Treść
              <textarea
                className="mt-1 min-h-[420px] w-full rounded border border-slate-300 px-2 py-1.5 text-sm leading-relaxed"
                value={body}
                disabled={locked}
                onChange={(e) => setBody(e.target.value)}
                onBlur={() => void saveEdits().catch(() => undefined)}
              />
            </label>
            <div className="mt-3 flex flex-wrap items-center gap-2">
              {!readOnly && inquiry.source_message_id && (
                <button
                  type="button"
                  disabled={busy || saving}
                  onClick={() => void sendViaThunderbird()}
                  className="rounded bg-blue-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-blue-700 disabled:opacity-50"
                >
                  Zapisz i wyślij w Thunderbirdzie
                </button>
              )}
              {!readOnly && (
                <button
                  type="button"
                  disabled={busy || saving}
                  onClick={() => void copyAndMarkSent()}
                  className={
                    inquiry.source_message_id
                      ? 'rounded border border-slate-300 px-3 py-1.5 text-xs hover:bg-slate-50 disabled:opacity-50'
                      : 'rounded bg-blue-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-blue-700 disabled:opacity-50'
                  }
                >
                  Kopiuj i oznacz jako wysłane
                </button>
              )}
              <button
                type="button"
                disabled={busy || saving}
                onClick={() => void copyBody()}
                className="rounded border border-slate-300 px-3 py-1.5 text-xs hover:bg-slate-50 disabled:opacity-50"
              >
                Kopiuj treść
              </button>
              {inquiry.reply_html && (
                <button
                  type="button"
                  disabled={busy || saving}
                  onClick={() => void copyWithTable()}
                  title="Wkleja się do Outlooka, Gmaila i innej poczty razem z tabelą"
                  className="rounded border border-slate-300 px-3 py-1.5 text-xs hover:bg-slate-50 disabled:opacity-50"
                >
                  Kopiuj z tabelą (HTML)
                </button>
              )}
              {!readOnly && inquiry.send_requested_at && (
                <button
                  type="button"
                  disabled={locked}
                  onClick={() => void cancelThunderbird()}
                  className="rounded border border-slate-300 px-3 py-1.5 text-xs hover:bg-slate-50 disabled:opacity-50"
                >
                  Anuluj wysyłkę
                </button>
              )}
              {!readOnly && inquiry.replied_at && (
                <button
                  type="button"
                  disabled={locked}
                  onClick={() => void unmarkSent()}
                  className="rounded border border-slate-300 px-3 py-1.5 text-xs hover:bg-slate-50 disabled:opacity-50"
                >
                  Cofnij oznaczenie
                </button>
              )}
              <Link
                to="/inquiries"
                className="rounded border border-slate-300 px-3 py-1.5 text-xs hover:bg-slate-50"
              >
                Wróć do zapytań
              </Link>
              {!readOnly && (
                <button
                  type="button"
                  disabled={busy || saving}
                  onClick={() => void removeInquiry()}
                  className="rounded border border-red-300 px-3 py-1.5 text-xs text-red-700 hover:bg-red-50 disabled:opacity-50"
                >
                  Usuń zapytanie
                </button>
              )}
              {saving && <span className="text-[11px] text-slate-400">Zapisuję…</span>}
            </div>
            <p className="mt-2 text-[11px] text-slate-400">
              {readOnly
                ? 'Tylko podgląd — treść listu możesz skopiować, ale zmienić go może wyłącznie autor.'
                : inquiry.send_requested_at
                  ? 'List czeka na Thunderbirda — otworzy okno odpowiedzi w ciągu kilku sekund. Maila wysyłasz sam, z Thunderbirda.'
                  : inquiry.source_message_id
                    ? 'Edycje zapisują się po opuszczeniu pola. Thunderbird otworzy odpowiedź na ten mail — wysyłasz ją sam, po sprawdzeniu.'
                    : 'Edycje zapisują się po opuszczeniu pola. System nie wysyła maila — wklej treść do swojej poczty.'}
            </p>
            <p className="mt-1 text-[11px] text-slate-400">
              {inquiry.reply_html
                ? 'Do maila pójdzie tabela: po lewej pozycja z zapytania, po prawej nasza propozycja. Podgląd niżej.'
                : 'Po ręcznej poprawce treści wysyłamy sam tekst, bez tabeli. Tabela wróci po zmianie wyboru produktu lub cen.'}
            </p>
            {inquiry.reply_html && (
              <details className="mt-2 rounded border border-slate-200 bg-slate-50 p-2">
                <summary className="cursor-pointer text-[11px] font-medium text-slate-600">
                  Podgląd tabeli, którą zobaczy klient
                </summary>
                {/* Treść z naszego serwera, zbudowana z danych zapytania — bez znaczników od klienta. */}
                <div
                  className="mt-2 overflow-x-auto rounded bg-white p-2"
                  dangerouslySetInnerHTML={{ __html: inquiry.reply_html }}
                />
              </details>
            )}
          </div>

          <details className="rounded-xl bg-white p-4 text-xs shadow-sm">
            <summary className="cursor-pointer font-semibold text-slate-800">Zapytanie klienta</summary>
            {inquiry.source_subject && (
              <p className="mt-2 font-medium text-slate-700">{inquiry.source_subject}</p>
            )}
            <pre className="mt-2 whitespace-pre-wrap font-sans text-slate-600">{inquiry.source_body}</pre>
          </details>
        </div>

        <div className="space-y-4">
          <div className="rounded-xl bg-white p-4 shadow-sm">
            <div className="mb-3 flex items-center justify-between gap-2">
              <h2 className="text-sm font-semibold">Pozycje</h2>
              {busy ? (
                <span className="text-xs text-violet-800">
                  <BusyLabel label="Piszę list" seconds={composeSec} />
                </span>
              ) : readOnly ? null : (
                <span className="text-[11px] text-slate-400">Kliknięcie alternatywy od razu przepisuje list.</span>
              )}
            </div>
            {inquiry.items.length === 0 ? (
              <p className="text-xs text-slate-500">Brak pozycji.</p>
            ) : (
              <div className="space-y-3">
                {inquiry.items.map((item, i) => (
                  <ItemRow
                    key={item.id}
                    item={item}
                    index={i}
                    priceMode={inquiry.price.mode}
                    answers={inquiry.answers}
                    busy={locked}
                    customDrafts={customDrafts}
                    onCustomDraft={(cardId, v) => setCustomDrafts((d) => ({ ...d, [cardId]: v }))}
                    onAnswer={onAnswer}
                    manualDraft={manualDrafts[item.id] ?? ''}
                    onManualDraft={(v) => setManualDrafts((d) => ({ ...d, [item.id]: v }))}
                    onManualPriceBlur={() => onManualPriceBlur(item)}
                    onPreview={(pid, q) => {
                      setPreviewId(pid)
                      setPreviewQuery(q.trim())
                    }}
                    onSearch={(mode) =>
                      setSearchFor({
                        itemId: item.id,
                        mode,
                        query: (item.query ?? item.quote ?? '').trim(),
                      })
                    }
                  />
                ))}
              </div>
            )}
          </div>

          <div className="rounded-xl bg-white p-4 shadow-sm">
            <h2 className="mb-2 text-sm font-semibold">Dla całej oferty</h2>
            <p className="text-xs font-semibold text-slate-700">Szablon listu</p>
            <div className="mt-1.5 flex flex-wrap items-center gap-1.5">
              {toneOptions.map((opt) => (
                <Chip
                  key={opt.id}
                  active={inquiry.tone === opt.id}
                  disabled={locked}
                  onClick={() => onTone(opt.id)}
                >
                  {opt.label}
                </Chip>
              ))}
            </div>
            <p className="mt-1 text-[11px] text-slate-500">{toneHint(inquiry.tone)}</p>
            <p className="mt-3 text-xs font-semibold text-slate-700">Ceny w liście</p>
            <div className="mt-1.5 flex flex-wrap items-center gap-1.5">
              {priceModeOptions.map((opt) => (
                <Chip
                  key={opt.id}
                  active={inquiry.price.mode === opt.id}
                  disabled={locked}
                  onClick={() => onPriceMode(opt.id)}
                >
                  {opt.label}
                </Chip>
              ))}
              {inquiry.price.mode === 'catalog_margin' && (
                <label className="ml-1 inline-flex items-center gap-1 text-xs text-slate-700">
                  Marża
                  <input
                    type="number"
                    min={0}
                    max={inquiry.price.margin_max}
                    step={0.5}
                    disabled={locked}
                    className="w-20 rounded border border-slate-300 px-2 py-1 text-xs"
                    value={marginDraft}
                    onChange={(e) => setMarginDraft(e.target.value)}
                    onBlur={onMarginBlur}
                  />
                  %
                </label>
              )}
            </div>
            {/* Klient pyta o te cztery rzeczy wprost w mailu; bez pól handlowiec
                musiał je dopisywać ręcznie na końcu listu. */}
            <p className="mt-3 text-xs font-semibold text-slate-700">Warunki oferty</p>
            <div className="mt-1.5 grid gap-2 sm:grid-cols-2">
              {termFields.map((field) => (
                <label key={field.key} className="block text-[11px] font-medium text-slate-600">
                  {field.label}
                  <input
                    className="mt-0.5 w-full rounded border border-slate-300 px-2 py-1 text-xs font-normal"
                    disabled={locked}
                    maxLength={TERM_MAX}
                    value={termsDraft[field.key]}
                    onChange={(e) => setTermsDraft((d) => ({ ...d, [field.key]: e.target.value }))}
                    onBlur={() => onTermsBlur(field.key)}
                    placeholder={field.placeholder}
                  />
                </label>
              ))}
            </div>
            <p className="mt-1 text-[11px] text-slate-500">
              Puste pola nie trafiają do listu. Sama liczba dostaje w liście jednostkę (7 → „7 dni”,
              20 → „20,00 zł netto”); tekst idzie tak, jak go wpiszesz.
            </p>
            {inquiry.global_cards.length > 0 && (
              <div className="mt-3 space-y-2 border-t border-slate-100 pt-3">
                {inquiry.global_cards.map((card) => (
                  <CardChips
                    key={card.id}
                    card={card}
                    answers={inquiry.answers}
                    busy={locked}
                    customDraft={customDrafts[card.id] ?? ''}
                    onCustomDraft={(v) => setCustomDrafts((d) => ({ ...d, [card.id]: v }))}
                    onAnswer={onAnswer}
                  />
                ))}
              </div>
            )}
            <label className="mt-3 block border-t border-slate-100 pt-3 text-xs font-semibold text-slate-700">
              Dopisek do listu (klient go zobaczy)
              <textarea
                className="mt-1 min-h-[56px] w-full rounded border border-slate-300 px-2 py-1 text-xs font-normal"
                disabled={locked}
                value={noteDraft}
                onChange={(e) => setNoteDraft(e.target.value)}
                onBlur={onNoteBlur}
                placeholder="np. uwaga do pozycji, informacja o dostępności…"
              />
            </label>
          </div>

          {inquiry.questions.length > 0 && (
            <div className="rounded-xl bg-white p-4 shadow-sm">
              <h2 className="mb-1 text-sm font-semibold">Klient pyta o…</h2>
              <p className="mb-2 text-[11px] text-slate-500">
                Lista kontrolna dla Ciebie — nie trafia do listu. Odpowiedz w mailu albo w dopisku.
              </p>
              <ul className="space-y-1">
                {inquiry.questions.map((q, i) => (
                  <li key={i}>
                    <label className="flex items-start gap-2 text-xs text-slate-700">
                      <input
                        type="checkbox"
                        className="mt-0.5"
                        checked={Boolean(checked[i])}
                        onChange={(e) => setChecked((c) => ({ ...c, [i]: e.target.checked }))}
                      />
                      <span className={checked[i] ? 'text-slate-400 line-through' : ''}>{q}</span>
                    </label>
                  </li>
                ))}
              </ul>
            </div>
          )}
        </div>
      </div>

      <InquiryContactModal
        contact={contactOpen ? inquiry.contact : null}
        subtitle={inquiry.source_subject || `Zapytanie #${inquiry.id}`}
        onClose={() => setContactOpen(false)}
      />

      <ProductVerifyModal
        productId={previewId}
        query={previewQuery}
        onClose={() => {
          setPreviewId(null)
          setPreviewQuery('')
        }}
      />

      <ProductAiMatchModal
        open={searchFor !== null}
        initialQuery={searchFor?.query ?? ''}
        initialMode={searchFor?.mode ?? 'catalog'}
        autoRunAi={searchFor?.mode === 'ai'}
        onClose={() => setSearchFor(null)}
        onSelect={(p: AiMatchPick) => {
          const target = searchFor
          setSearchFor(null)
          if (target) void pickProduct(target.itemId, p.id)
        }}
      />
    </div>
  )
}
