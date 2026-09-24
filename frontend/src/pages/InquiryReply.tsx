import { Fragment, useEffect, useRef, useState, type ReactNode } from 'react'
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
  InquiryOmittedItem,
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

// Kolumna statusu pozycji: im większa uwaga potrzebna, tym mocniejsza barwa (pewne jasne, brak pełna czerwień).
const confidenceBadge: Record<InquiryConfidence, { label: string; cls: string }> = {
  high: { label: 'pewne', cls: 'bg-emerald-100 text-emerald-800' },
  medium: { label: 'sprawdź', cls: 'bg-amber-400 text-amber-950' },
  none: { label: 'brak w katalogu', cls: 'bg-red-700 text-white' },
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

/** Barwa adnotacji: czerwień = sprzeczność albo niepotwierdzony warunek, bursztyn = do sprawdzenia, fiolet = pytanie AI. */
const flagTone: Record<InquiryFlag, { cls: string; dot: string }> = {
  low_score: { cls: 'border-slate-300 bg-slate-100 text-slate-700', dot: 'bg-slate-500' },
  ambiguous: { cls: 'border-amber-300 bg-amber-50 text-amber-800', dot: 'bg-amber-500' },
  no_price: { cls: 'border-amber-300 bg-amber-50 text-amber-800', dot: 'bg-amber-500' },
  card_default: { cls: 'border-violet-200 bg-violet-50 text-violet-800', dot: 'bg-violet-500' },
  qty_unknown: { cls: 'border-amber-300 bg-amber-50 text-amber-800', dot: 'bg-amber-500' },
  requirement_unconfirmed: { cls: 'border-red-300 bg-red-50 text-red-800', dot: 'bg-red-600' },
  requirement_note: { cls: 'border-amber-300 bg-amber-50 text-amber-800', dot: 'bg-amber-500' },
  product_from_subject: { cls: 'border-sky-200 bg-sky-50 text-sky-800', dot: 'bg-sky-500' },
  model_failed: { cls: 'border-slate-300 bg-slate-100 text-slate-700', dot: 'bg-slate-500' },
  requirement_conflict: { cls: 'border-red-500 bg-red-50 font-semibold text-red-800', dot: 'bg-red-600' },
  brand_not_in_catalog: { cls: 'border-orange-300 bg-orange-50 text-orange-800', dot: 'bg-orange-500' },
}

/** Pasmo wyniku alternatywy; 80 = próg pewnego dopasowania (CONFIDENT_SCORE w ClientInquiryService). */
function scoreTone(score: number): string {
  if (score >= 80) return 'bg-emerald-100 text-emerald-800'
  if (score >= 60) return 'bg-amber-100 text-amber-800'
  return 'bg-red-100 text-red-800'
}

/** Układ wiersza pozycji: status | co napisał klient | nasza propozycja (od 42rem szerokości listy). */
const itemGrid = '@2xl:grid-cols-[6.5rem_minmax(0,1fr)_minmax(0,1.3fr)]'

function StatusIcon({ kind }: { kind: InquiryConfidence | 'model_failed' }) {
  if (kind === 'model_failed') return null
  return (
    <svg
      viewBox="0 0 24 24"
      className="h-4 w-4 shrink-0"
      fill="none"
      stroke="currentColor"
      strokeWidth={2.5}
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
    >
      {kind === 'high' && <polyline points="20 6 9 17 4 12" />}
      {kind === 'medium' && (
        <>
          <circle cx="11" cy="11" r="7" />
          <line x1="21" y1="21" x2="16.65" y2="16.65" />
        </>
      )}
      {kind === 'none' && (
        <>
          <circle cx="12" cy="12" r="9" />
          <line x1="15" y1="9" x2="9" y2="15" />
          <line x1="9" y1="9" x2="15" y2="15" />
        </>
      )}
    </svg>
  )
}

/** Kółko wyboru przy alternatywie — jedna pozycja listu, jak przycisk opcji. */
function RadioDot({ on }: { on: boolean }) {
  return (
    <span
      className={`h-3.5 w-3.5 shrink-0 rounded-full bg-white ${on ? 'border-4 border-blue-600' : 'border-2 border-slate-400'}`}
    />
  )
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
 * Wiersze maila, których nie ma w pozycjach — ponad limit pozycji na zapytanie albo
 * wiersze, których pokrycia nie jesteśmy pewni. Nie wchodzą do listu, więc bez tego
 * paska oferta byłaby po cichu niepełna.
 */
function OmittedItemsBar({ list, limit }: { list: InquiryOmittedItem[]; limit?: number }) {
  return (
    <div className="rounded border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900">
      <p className="font-medium">
        Wiersze z maila poza pozycjami ({list.length}) — nie ma ich w liście. Sprawdź je i dopisz
        brakujące ręcznie.
      </p>
      {limit ? <p className="text-xs">Analiza obejmuje najwyżej {limit} pozycji jednego zapytania.</p> : null}
      <ul className="mt-1 list-disc space-y-0.5 pl-4 text-xs">
        {list.map((row, i) => (
          <li key={i}>
            {row.quote}
            {row.size && !row.quote.includes(row.size) ? ` · rozm. ${row.size}` : ''}
          </li>
        ))}
      </ul>
    </div>
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
  const failed = item.flags.includes('model_failed')
  const badge = failed ? modelFailedBadge : confidenceBadge[item.confidence]
  const chosenId = item.chosen.startsWith('p:') ? Number(item.chosen.slice(2)) : null
  const chosen = chosenId != null ? item.candidates.find((c) => c.id === chosenId) ?? null : null
  const computedPrice = chosen ? priceByMode(chosen, priceMode) : null
  const chosenPrice = item.manual_price != null && priceMode !== 'none' ? PLN.format(item.manual_price) : computedPrice
  const subAnswer = item.substitute_key ? answers[item.substitute_key]?.option_id ?? 'no' : null
  const meta = [item.qty, item.unit].filter(Boolean).join(' ')
  const checking = item.chosen === 'check'
  const inLetter = chosen
    ? chosen.sku
    : chosenId != null
      ? `towar #${chosenId} (spoza listy kandydatów)`
      : 'sprawdzimy i wrócimy — bez SKU'
  // Zebra: sąsiednie pozycje mają inne tło, żeby nie mylić, do której należy alternatywa albo pytanie.
  const zebra = index % 2 === 0 ? 'bg-white' : 'bg-slate-100'
  const optionCls = (on: boolean) =>
    `flex w-full min-w-0 items-center gap-2 rounded-lg border px-2 py-1.5 text-left text-xs leading-snug disabled:opacity-50 ${
      on ? 'border-blue-600 bg-blue-50' : 'border-slate-300 bg-white hover:border-blue-300'
    }`

  return (
    <div
      className={`grid grid-cols-[5.5rem_minmax(0,1fr)] overflow-hidden rounded-xl border border-slate-300 shadow-sm ${itemGrid} ${zebra}`}
    >
      <div className={`row-span-2 flex flex-col gap-1.5 p-3 @2xl:row-span-1 ${badge.cls}`}>
        <div className="flex items-center gap-1.5">
          <span className="text-2xl font-extrabold leading-none">{index + 1}</span>
          <StatusIcon kind={failed ? 'model_failed' : item.confidence} />
        </div>
        <p className="text-[11px] font-extrabold uppercase leading-tight tracking-wide">{badge.label}</p>
        {meta && <p className="text-xs font-semibold">{meta}</p>}
        {item.size && <p className="text-xs">rozm. {item.size}</p>}
      </div>

      <div className="min-w-0 space-y-2 p-3">
        <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-500 @2xl:hidden">Klient napisał</p>
        {item.quote && (
          <blockquote className="rounded border border-amber-200 bg-amber-50 px-2.5 py-1.5 text-[13px] leading-relaxed text-slate-800">
            {item.quote}
          </blockquote>
        )}

        {item.flags.length > 0 && (
          <div className="flex flex-wrap gap-1">
            {item.flags.map((f) => (
              <span
                key={f}
                className={`inline-flex items-center gap-1.5 rounded border px-1.5 py-0.5 text-[11px] ${flagTone[f].cls}`}
              >
                <span className={`h-1.5 w-1.5 shrink-0 rounded-full ${flagTone[f].dot}`} />
                {flagLabel[f]}
              </span>
            ))}
          </div>
        )}

        {/* Wniosek modelu, nie fakt z maila — dlatego dopisek o ocenie AI i prośba o wyjaśnienie z klientem. */}
        {item.conflict && (
          <div className="flex items-start gap-2 rounded-lg border-2 border-red-500 bg-red-50 px-2.5 py-2 text-xs leading-relaxed text-red-900">
            <svg
              viewBox="0 0 24 24"
              className="mt-px h-4 w-4 shrink-0"
              fill="none"
              stroke="currentColor"
              strokeWidth={2.2}
              strokeLinecap="round"
              strokeLinejoin="round"
              aria-hidden="true"
            >
              <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />
              <line x1="12" y1="9" x2="12" y2="13" />
              <line x1="12" y1="17" x2="12.01" y2="17" />
            </svg>
            <div>
              <p className="font-bold">Sprzeczność w zapytaniu — wyjaśnij z klientem</p>
              <p>{item.conflict}</p>
              <p className="mt-0.5 text-[11px] text-red-700">Ocena AI, nie fakt z maila.</p>
            </div>
          </div>
        )}

        {item.brand_not_in_catalog && (
          <p className="rounded-lg border-2 border-orange-400 bg-orange-50 px-2.5 py-1.5 text-xs leading-relaxed text-orange-900">
            <span className="font-semibold">Marki „{item.brand_not_in_catalog}” nie ma w katalogu.</span> Kandydaci to
            zamienniki innej marki — do listu wejdzie dopiero ten, który wybierzesz.
          </p>
        )}

        {/* Warunki szczególne klienta: wprost, z werdyktem karty. Karta bez
            potwierdzenia nie wchodzi do listu, więc handlowiec musi wiedzieć,
            czego brakuje i gdzie sam ma to sprawdzić. */}
        {item.requirements.length > 0 && (
          <ul className="space-y-1">
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

        {item.cards.map((card) => (
          <div key={card.id} className="rounded-lg border border-violet-200 bg-white p-2.5">
            <span className="mb-1 inline-block rounded-full bg-violet-50 px-2 py-0.5 text-[10px] font-semibold text-violet-800">
              {answers[card.id] ? 'Pytanie AI' : 'Pytanie AI · bez odpowiedzi'}
            </span>
            <CardChips
              card={card}
              answers={answers}
              busy={busy}
              customDraft={customDrafts[card.id] ?? ''}
              onCustomDraft={(v) => onCustomDraft(card.id, v)}
              onAnswer={onAnswer}
            />
          </div>
        ))}
      </div>

      <div className="col-start-2 min-w-0 space-y-2 border-t border-slate-200 p-3 @2xl:col-start-auto @2xl:border-l @2xl:border-t-0">
        <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-500 @2xl:hidden">Nasza propozycja</p>
        {/* Model podsunął nie to, czego szukał klient — handlowiec szuka sam:
            po nazwie/kodzie albo opisem przez AI. Wybrany wyrób wchodzi do listu. */}
        <div className="flex items-center justify-between gap-2">
          <p className="min-w-0 text-xs text-slate-800">
            <span className="text-slate-500">W liście:</span> <span className="font-semibold">{inLetter}</span>
          </p>
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

        {/* Jedna siatka na całą listę: kolumna znaczków i „Opis” ma wspólną szerokość, więc wiersze wyboru są równe. */}
        <div className="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-1.5 gap-y-1">
          {item.candidates.map((c) => {
            const on = item.chosen === `p:${c.id}`
            const label = c.source === 'manual' ? 'ręcznie' : c.source === 'link' ? 'z linku' : `${c.score}%`
            const tone = c.source === 'manual' || c.source === 'link' ? 'bg-slate-200 text-slate-800' : scoreTone(c.score)
            return (
              <Fragment key={c.id}>
                <button
                  type="button"
                  disabled={busy}
                  aria-pressed={on}
                  onClick={() => onAnswer(item.answer_key, { option_id: `p:${c.id}` })}
                  className={optionCls(on)}
                >
                  <RadioDot on={on} />
                  <span className="min-w-0 flex-1 text-slate-800">
                    <span className="font-semibold">{c.sku}</span> · {c.name}
                    {c.manufacturer && <span className="text-slate-500"> · {c.manufacturer}</span>}
                    {/* Warunki zamawiania pod nazwą — obok poszerzały kolumnę przycisków i ściskały nazwę do słowa w linii.
                        Wybrana alternatywa pokazuje je niżej, razem z ilością do zamówienia. */}
                    {!on && <OrderQuantityBadge oq={c.order_quantity} block className="mt-1" />}
                  </span>
                  <span className={`shrink-0 rounded-full px-1.5 py-0.5 text-[11px] font-bold ${tone}`}>{label}</span>
                </button>
                <button
                  type="button"
                  disabled={busy}
                  onClick={() => onPreview(c.id, item.quote ?? '')}
                  className="rounded-full border border-violet-300 bg-white px-2 py-0.5 text-[11px] font-medium text-violet-800 hover:bg-violet-50 disabled:opacity-50"
                >
                  Opis
                </button>
                {on && (
                  <div className="col-span-2 space-y-1 pb-1 pl-7 text-[11px] text-slate-600">
                    {(chosenPrice || priceMode !== 'none') && (
                      <p className="text-xs text-slate-800">
                        Cena: {chosenPrice ?? 'do potwierdzenia'}
                        {item.manual_price != null && priceMode !== 'none' && ' (ręcznie)'}
                      </p>
                    )}
                    <OrderQuantityBadge oq={c.order_quantity} qty={qtyForOrderCondition(item, c.order_quantity)} block />
                    {c.reason && <p>{c.reason}</p>}
                    {/* Cena z negocjacji albo promocji: wchodzi do tekstu i do tabeli listu,
                        więc nie trzeba poprawiać treści ręcznie (to kasowało tabelę). */}
                    {priceMode !== 'none' && (
                      <label className="flex flex-wrap items-center gap-1.5">
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
                  </div>
                )}
              </Fragment>
            )
          })}
          <button
            type="button"
            disabled={busy}
            aria-pressed={checking}
            onClick={() => onAnswer(item.answer_key, { option_id: 'check' })}
            className={optionCls(checking)}
          >
            <RadioDot on={checking} />
            <span className="text-slate-800">Sprawdzimy i wrócimy — bez SKU</span>
          </button>
          <span />
        </div>

        {item.substitute_key && (
          <div>
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
      </div>
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

/**
 * Okno na prawie cały ekran: po lewej zapytanie klienta, po prawej list tak, jak zobaczy go klient
 * (z tabelą), albo pole do ręcznej poprawki treści. Ręczna poprawka zapisana na serwerze kasuje
 * tabelę — do maila idzie wtedy sam tekst.
 */
function ReplyPreviewModal({
  mail,
  subject,
  body,
  html,
  locked,
  saving,
  msg,
  err,
  editHint,
  onSubject,
  onBody,
  onSave,
  onThunderbird,
  onCopyWithTable,
  onCopyBody,
  onClose,
}: {
  /** Mail klienta dokładnie tak, jak przyszedł. */
  mail: { subject: string | null; from: string; sentAt: string | null; body: string }
  subject: string
  body: string
  html: string | null
  locked: boolean
  saving: boolean
  msg: string
  err: string
  editHint: string
  onSubject: (value: string) => void
  onBody: (value: string) => void
  onSave: () => void
  /** Tylko dla zapytania z Thunderbirda — odpowiedź idzie na ten sam mail. */
  onThunderbird?: () => void
  onCopyWithTable: () => void
  onCopyBody: () => void
  onClose: () => void
}) {
  const [editing, setEditing] = useState(false)
  const showText = editing || !html
  const boxRef = useRef<HTMLDivElement>(null)
  // Zaznaczanie tekstu w polu treści kończone poza oknem to „kliknięcie” w tło — nie zamyka okna.
  const downOnBackdrop = useRef(false)

  useEffect(() => {
    const previous = document.activeElement as HTMLElement | null
    boxRef.current?.focus()
    return () => previous?.focus?.()
  }, [])

  useEffect(() => {
    // Nasłuch w fazie capture, jak w innych oknach: Escape zamyka tylko to wierzchnie.
    function onKey(e: KeyboardEvent) {
      if (e.key === 'Escape') {
        e.stopPropagation()
        onClose()
      }
    }
    window.addEventListener('keydown', onKey, true)
    return () => window.removeEventListener('keydown', onKey, true)
  }, [onClose])

  return (
    <div
      className="fixed inset-0 z-[80] flex items-center justify-center bg-slate-950/50 p-3"
      onMouseDown={(e) => {
        downOnBackdrop.current = e.target === e.currentTarget
      }}
      onClick={(e) => {
        if (e.target === e.currentTarget && downOnBackdrop.current) onClose()
      }}
    >
      <div
        ref={boxRef}
        role="dialog"
        aria-modal="true"
        aria-labelledby="reply-preview-title"
        tabIndex={-1}
        className="flex h-[94vh] w-full max-w-[1600px] flex-col overflow-hidden rounded-lg bg-white shadow-xl outline-none"
      >
        <div className="flex items-center justify-between gap-2 border-b border-slate-100 px-4 py-3">
          <p id="reply-preview-title" className="text-sm font-semibold text-slate-800">
            Podgląd odpowiedzi
          </p>
          <div className="flex items-center gap-2">
            {saving && <span className="text-[11px] text-slate-400">Zapisuję…</span>}
            <button
              type="button"
              onClick={onClose}
              aria-label="Zamknij podgląd"
              className="rounded px-2 py-0.5 text-sm text-slate-500 hover:bg-slate-100"
            >
              ✕
            </button>
          </div>
        </div>

        {/* Na szerokim ekranie dwie kolumny przewijane osobno; na wąskim jedna pod drugą. */}
        <div className="min-h-0 flex-1 overflow-y-auto lg:grid lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)] lg:overflow-hidden">
          <section className="max-h-[35vh] overflow-y-auto border-b border-slate-100 bg-slate-50 px-4 py-3 text-xs lg:max-h-none lg:min-h-0 lg:border-r lg:border-b-0">
            <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Zapytanie klienta</p>
            {mail.subject && <p className="mt-2 text-sm font-medium text-slate-800">{mail.subject}</p>}
            {(mail.from || mail.sentAt) && (
              <p className="mt-1 text-[11px] text-slate-500">
                {[mail.from && `Od: ${mail.from}`, mail.sentAt && `Mail z ${mailDate(mail.sentAt)}`]
                  .filter(Boolean)
                  .join(' · ')}
              </p>
            )}
            <pre className="mt-2 whitespace-pre-wrap break-words font-sans text-slate-700">{mail.body}</pre>
          </section>

          <section className="flex flex-col gap-3 px-4 py-3 lg:min-h-0 lg:overflow-y-auto">
            <label className="block text-xs font-medium text-slate-600">
              Temat
              <input
                className="mt-1 w-full rounded border border-slate-300 px-2 py-1.5 text-sm"
                value={subject}
                disabled={locked}
                onChange={(e) => onSubject(e.target.value)}
                onBlur={onSave}
              />
            </label>
            {showText ? (
              <label className="flex flex-1 flex-col text-xs font-medium text-slate-600">
                Treść
                <textarea
                  className="mt-1 min-h-[420px] w-full flex-1 rounded border border-slate-300 px-2 py-1.5 text-sm leading-relaxed"
                  value={body}
                  disabled={locked}
                  onChange={(e) => onBody(e.target.value)}
                  onBlur={onSave}
                />
              </label>
            ) : (
              <div>
                <p className="text-xs font-medium text-slate-600">List tak, jak zobaczy go klient</p>
                {/* Treść z naszego serwera, zbudowana z danych zapytania — bez znaczników od klienta.
                    List ma 60% szerokości okna poczty; w podglądzie wypełnia swoją kolumnę, bo ta jest
                    węższa od okna poczty i przy 60% zostawał pusty pas. Do schowka idzie bez zmian. */}
                <div
                  className="mt-1 overflow-x-auto rounded border border-slate-200 bg-white p-3 [&>div]:w-full!"
                  dangerouslySetInnerHTML={{ __html: html ?? '' }}
                />
              </div>
            )}
            <p className="text-[11px] text-slate-400">
              {showText
                ? editHint
                : 'Do maila pójdzie tabela: po lewej pozycja z zapytania, po prawej nasza propozycja.'}
            </p>
            {editing && html && (
              <p className="text-[11px] text-amber-800">
                Zapisana poprawka treści usuwa tabelę — do maila pójdzie sam tekst. Tabela wróci po zmianie
                wyboru produktu lub cen.
              </p>
            )}
            {!html && (
              <p className="text-[11px] text-slate-400">
                Po ręcznej poprawce treści wysyłamy sam tekst, bez tabeli. Tabela wróci po zmianie wyboru
                produktu lub cen.
              </p>
            )}
          </section>
        </div>

        <div className="border-t border-slate-100 px-4 py-3">
          {msg && <p className="mb-2 rounded bg-green-50 px-3 py-2 text-xs text-green-800">{msg}</p>}
          {err && <p className="mb-2 rounded bg-red-50 px-3 py-2 text-xs text-red-700">{err}</p>}
          <div className="flex flex-wrap items-center gap-2">
            {onThunderbird && (
              <button
                type="button"
                disabled={saving}
                onClick={onThunderbird}
                className="rounded bg-blue-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-blue-700 disabled:opacity-50"
              >
                Zapisz i wyślij w Thunderbirdzie
              </button>
            )}
            {html && (
              <button
                type="button"
                disabled={saving}
                onClick={onCopyWithTable}
                title="Wkleja się do Outlooka, Gmaila i innej poczty razem z tabelą"
                className={
                  onThunderbird
                    ? 'rounded border border-slate-300 px-3 py-1.5 text-xs hover:bg-slate-50 disabled:opacity-50'
                    : 'rounded bg-blue-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-blue-700 disabled:opacity-50'
                }
              >
                Kopiuj z tabelą (HTML)
              </button>
            )}
            <button
              type="button"
              disabled={saving}
              onClick={onCopyBody}
              className="rounded border border-slate-300 px-3 py-1.5 text-xs hover:bg-slate-50 disabled:opacity-50"
            >
              Kopiuj treść
            </button>
            {html && !locked && (
              <button
                type="button"
                onClick={() => setEditing((on) => !on)}
                className="rounded border border-slate-300 px-3 py-1.5 text-xs hover:bg-slate-50"
              >
                {editing ? 'Pokaż list z tabelą' : 'Popraw treść ręcznie'}
              </button>
            )}
            <button
              type="button"
              onClick={onClose}
              className="rounded border border-slate-300 px-3 py-1.5 text-xs hover:bg-slate-50"
            >
              Zamknij
            </button>
          </div>
        </div>
      </div>
    </div>
  )
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
  const [replyOpen, setReplyOpen] = useState(false)
  const composeSec = useBusySeconds(composeBusy)

  // Treść zapisana na serwerze (PATCH) — do wykrywania niezapisanych edycji.
  // Numer otwartego zapytania dla pilnowania Thunderbirda: po przejściu na inne
  // zapytanie stary nasłuch nie ma prawa nadpisać komunikatu na ekranie.
  const inquiryRef = useRef<number | null>(null)
  // Numer bieżącego pilnowania Thunderbirda — „Anuluj wysyłkę” i nowa prośba unieważniają stare.
  const watchRef = useRef(0)
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

  /**
   * Samo oznaczenie — bez kopiowania. List kopiuje się osobnym przyciskiem (z tabelą albo
   * sama treść); kopia przy oznaczaniu nadpisywała schowek z listem z tabelą.
   */
  async function markSent() {
    if (!inquiry) return
    setMsg('')
    try {
      await saveEdits()
    } catch {
      return
    }
    try {
      const res = await api<InquiryPayload>(`/inquiries/${inquiry.id}/replied`, {
        method: 'POST',
        body: JSON.stringify({ replied: true }),
      })
      setInquiry(res)
      setMsg('Zapytanie oznaczone jako wysłane.')
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Nie udało się oznaczyć jako wysłane.')
    }
  }

  /**
   * Przeglądarka nie sięgnie do poczty na komputerze, więc zostawiamy prośbę
   * na serwerze — dodatek do Thunderbirda podejmuje ją w ciągu kilku sekund
   * i otwiera okno odpowiedzi na tym samym mailu. true = list przekazany
   * (okno podglądu może się wtedy zamknąć).
   */
  async function sendViaThunderbird(): Promise<boolean> {
    if (!inquiry) return false
    setMsg('')
    try {
      await saveEdits()
    } catch {
      return false
    }
    try {
      const res = await api<InquiryPayload>(`/inquiries/${inquiry.id}/queue-reply`, {
        method: 'POST',
        body: JSON.stringify({ queued: true }),
      })
      setInquiry(res)
      setMsg('Zapisano. Thunderbird otworzy okno odpowiedzi w ciągu kilku sekund.')
      void watchThunderbird(inquiry.id)
      return true
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Nie udało się przekazać listu do Thunderbirda.')
      return false
    }
  }

  /**
   * Czy Thunderbird odebrał list. Handlowiec zostaje w przeglądarce i nie widzi
   * okna odpowiedzi — zwłaszcza gdy Thunderbird stoi zminimalizowany. Prośbę
   * kasuje dodatek w chwili podjęcia, więc jej zniknięcie jest potwierdzeniem.
   */
  async function watchThunderbird(inquiryId: number) {
    const ticket = ++watchRef.current
    // inne zapytanie na ekranie albo ręczne anulowanie (nowy numer) — przestajemy pilnować;
    // po anulowaniu prośba też znika, ale to nie znaczy, że Thunderbird ją podjął
    const stale = () => inquiryRef.current !== inquiryId || watchRef.current !== ticket
    for (let i = 0; i < WATCH_TRIES; i += 1) {
      await new Promise((done) => setTimeout(done, WATCH_EVERY_MS))
      let row: InquiryPayload
      try {
        row = await api<InquiryPayload>(`/inquiries/${inquiryId}`)
      } catch {
        return
      }
      if (stale()) return
      if (row.send_requested_at === null) {
        setInquiry(row)
        setMsg('Thunderbird otworzył okno odpowiedzi. Maila wysyłasz stamtąd.')

        return
      }
    }
    if (!stale()) {
      setMsg('Thunderbird jeszcze nie odebrał listu — sprawdź, czy jest uruchomiony i zalogowany w dodatku.')
    }
  }

  async function cancelThunderbird() {
    if (!inquiry) return
    watchRef.current += 1
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
  // Wiersze maila poza pozycjami; pole dochodzi po stronie API, więc czytamy ostrożnie.
  const omitted = Array.isArray(inquiry.omitted_items) ? inquiry.omitted_items : []
  // attention_count liczy też pominięte wiersze — „X z {total} pozycji” dotyczy samych pozycji
  const itemAttention = Math.max(0, inquiry.attention_count - omitted.length)
  const banner = inquiry.replied_at
    ? {
        cls: 'bg-emerald-50 text-emerald-800',
        text: `Wysłano ${new Date(inquiry.replied_at).toLocaleString('pl-PL')}`,
      }
    : inquiry.attention_count > 0
      ? {
          cls: 'bg-amber-50 text-amber-800',
          text: [
            itemAttention > 0 ? `${itemAttention} z ${total} pozycji wymaga sprawdzenia` : '',
            omitted.length > 0 ? `wiersze z maila poza pozycjami: ${omitted.length}` : '',
          ]
            .filter(Boolean)
            .join(' · '),
        }
      : {
          cls: 'bg-emerald-50 text-emerald-800',
          text: 'Wszystkie pozycje pewne — list gotowy do skopiowania',
        }

  return (
    <div className="app-inquiry-reply space-y-4">
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
        {/* Jedyne wyjście do listy — kafelek, żeby nie ginął w nagłówku (pasek przycisków go nie powtarza). */}
        <Link
          to="/inquiries"
          className="rounded-xl bg-white px-4 py-2.5 text-sm font-semibold text-blue-700 shadow-sm ring-1 ring-slate-200 hover:bg-blue-50"
        >
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
      {omitted.length > 0 && <OmittedItemsBar list={omitted} limit={inquiry.omitted_limit} />}

      {/* Przyciski listu zostają u góry ekranu przy przewijaniu pozycji (reguła .app-inquiry-reply w index.css). */}
      <div className="sticky top-0 z-30 rounded-xl bg-white px-4 py-3 shadow-sm ring-1 ring-slate-200">
        <div className="flex flex-wrap items-center gap-2">
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
          <button
            type="button"
            onClick={() => setReplyOpen(true)}
            className="rounded border border-slate-300 px-3 py-1.5 text-xs font-medium hover:bg-slate-50"
          >
            Podgląd odpowiedzi
          </button>
          {!readOnly && (
            <button
              type="button"
              disabled={busy || saving}
              onClick={() => void markSent()}
              className={
                inquiry.source_message_id
                  ? 'rounded border border-slate-300 px-3 py-1.5 text-xs hover:bg-slate-50 disabled:opacity-50'
                  : 'rounded bg-blue-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-blue-700 disabled:opacity-50'
              }
            >
              Oznacz, że wysłano
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
          {busy && (
            <span className="text-xs text-violet-800">
              <BusyLabel label="Piszę list" seconds={composeSec} />
            </span>
          )}
        </div>
        {/* Komunikaty w pasku — widać je także przy pozycji na dole listy. */}
        {!readOnly && inquiry.send_requested_at && (
          <p className="mt-2 text-[11px] text-slate-500">
            List czeka na Thunderbirda — otworzy okno odpowiedzi w ciągu kilku sekund. Maila wysyłasz sam, z
            Thunderbirda.
          </p>
        )}
        {msg && <p className="mt-2 rounded bg-green-50 px-3 py-2 text-xs text-green-800">{msg}</p>}
        {err && <p className="mt-2 rounded bg-red-50 px-3 py-2 text-xs text-red-700">{err}</p>}
      </div>

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
          <div className="@container space-y-3">
            <div
              className={`hidden text-[11px] font-semibold uppercase tracking-wide text-slate-500 @2xl:grid ${itemGrid}`}
            >
              <span className="px-3">Poz. · status</span>
              <span className="px-3">Klient napisał</span>
              <span className="px-3">Nasza propozycja</span>
            </div>
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

      <div className="grid items-start gap-4 lg:grid-cols-2">
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

        <div className="space-y-4">
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

          <details className="rounded-xl bg-white p-4 text-xs shadow-sm">
            <summary className="cursor-pointer font-semibold text-slate-800">Zapytanie klienta</summary>
            {inquiry.source_subject && (
              <p className="mt-2 font-medium text-slate-700">{inquiry.source_subject}</p>
            )}
            <pre className="mt-2 whitespace-pre-wrap font-sans text-slate-600">{inquiry.source_body}</pre>
          </details>
        </div>
      </div>

      {replyOpen && (
        <ReplyPreviewModal
          mail={{
            subject: inquiry.source_subject,
            from: sender,
            sentAt: inquiry.source_sent_at,
            body: inquiry.source_body,
          }}
          subject={subject}
          body={body}
          html={inquiry.reply_html}
          locked={locked}
          saving={saving}
          msg={msg}
          err={err}
          editHint={
            readOnly
              ? 'Tylko podgląd — treść listu możesz skopiować, ale zmienić go może wyłącznie autor.'
              : inquiry.source_message_id
                ? 'Edycje zapisują się po opuszczeniu pola. Thunderbird otworzy odpowiedź na ten mail — wysyłasz ją sam, po sprawdzeniu.'
                : 'Edycje zapisują się po opuszczeniu pola. System nie wysyła maila — wklej treść do swojej poczty.'
          }
          onSubject={setSubject}
          onBody={setBody}
          onSave={() => void saveEdits().catch(() => undefined)}
          onThunderbird={
            !readOnly && inquiry.source_message_id
              ? () =>
                  void sendViaThunderbird().then((sent) => {
                    if (sent) setReplyOpen(false)
                  })
              : undefined
          }
          onCopyWithTable={() => void copyWithTable()}
          onCopyBody={() => void copyBody()}
          onClose={() => {
            // pole zamknięte Escape'em nie dostaje blur — niezapisana poprawka szłaby w niepamięć
            void saveEdits().catch(() => undefined)
            setReplyOpen(false)
          }}
        />
      )}

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
