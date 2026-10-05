import { useEffect, useRef, useState, type ReactNode } from 'react'
import { useNavigate } from 'react-router-dom'
import { addOfferItems, createOffer, listOffers, type Offer, type OfferListRow } from '../lib/offers'
import { plural } from '../lib/plural'
import { formatDate } from '../lib/priceChange'

/** Tyle ostatnio zmienianych ofert użytkownika pokazuje menu. */
const RECENT_OFFERS = 5

function positions(n: number): string {
  return `${n} ${plural(n, 'pozycja', 'pozycje', 'pozycji')}`
}

/**
 * „Dodaj do oferty ▾”: nowa oferta z zaznaczonych albo dopisanie do jednej z ostatnio zmienianych ofert (oferta jest
 * zawsze edytowalna, także po wysyłce). Po sukcesie otwiera ofertę /oferty/:id. Wspólne dla Zapasów (towary XL —
 * erpItemIds) i Produktów (karty — productIds). Limit pozycji zna dopiero serwer — jego komunikat (422) pokazujemy.
 */
export function AddToOfferMenu({
  erpItemIds = [],
  productIds = [],
  placement = 'down',
  buttonClassName,
  showCount = false,
}: {
  erpItemIds?: number[]
  productIds?: number[]
  /** Menu nad przyciskiem (pasek na dole ekranu) albo pod nim (pasek narzędzi). */
  placement?: 'up' | 'down'
  buttonClassName: string
  /** Liczba zaznaczonych w nawiasie na przycisku — jak pozostałe akcje zbiorcze Produktów. */
  showCount?: boolean
}) {
  const navigate = useNavigate()
  const [open, setOpen] = useState(false)
  const [offers, setOffers] = useState<OfferListRow[] | null>(null)
  const [offersErr, setOffersErr] = useState('')
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const boxRef = useRef<HTMLDivElement>(null)
  const seq = useRef(0)

  const count = erpItemIds.length + productIds.length

  useEffect(() => {
    if (!open) return
    function onDoc(e: MouseEvent) {
      if (!boxRef.current?.contains(e.target as Node)) setOpen(false)
    }
    function onKey(e: KeyboardEvent) {
      if (e.key === 'Escape') setOpen(false)
    }
    document.addEventListener('mousedown', onDoc)
    document.addEventListener('keydown', onKey)
    return () => {
      document.removeEventListener('mousedown', onDoc)
      document.removeEventListener('keydown', onKey)
    }
  }, [open])

  async function loadOffers() {
    const my = ++seq.current
    setOffersErr('')
    try {
      const res = await listOffers()
      const recent = [...res.data]
        .sort((a, b) => Date.parse(b.updated_at) - Date.parse(a.updated_at))
        .slice(0, RECENT_OFFERS)
      if (my === seq.current) setOffers(recent)
    } catch (ex) {
      if (my === seq.current) setOffersErr(ex instanceof Error ? ex.message : 'Nie udało się wczytać ofert')
    }
  }

  function toggle() {
    if (open) {
      setOpen(false)
      return
    }
    setErr('')
    setOpen(true)
    // Za każdym otwarciem świeża lista — oferta mogła się zmienić w innej karcie przeglądarki.
    void loadOffers()
  }

  async function run(action: () => Promise<Offer>) {
    setBusy(true)
    setErr('')
    try {
      const offer = await action()
      setOpen(false)
      navigate(`/oferty/${offer.id}`)
    } catch (ex) {
      // Np. 422 „oferta mieści najwyżej 30 pozycji” — komunikat z serwera.
      setErr(ex instanceof Error ? ex.message : 'Nie udało się dodać do oferty')
    } finally {
      setBusy(false)
    }
  }

  const body = {
    ...(erpItemIds.length > 0 ? { erp_item_ids: erpItemIds } : {}),
    ...(productIds.length > 0 ? { product_ids: productIds } : {}),
  }

  return (
    <div className="relative" ref={boxRef}>
      <button
        type="button"
        disabled={count === 0}
        aria-haspopup="menu"
        aria-expanded={open}
        onClick={toggle}
        className={buttonClassName}
        title="Nowa oferta dla klienta z zaznaczonych albo dopisanie do Twojej oferty"
      >
        Dodaj do oferty{showCount && count > 0 ? ` (${count})` : ''} ▾
      </button>
      {open && (
        <div
          role="menu"
          className={`app-popover absolute right-0 z-40 w-80 rounded-xl border border-slate-200 bg-white p-2 text-left text-xs text-slate-800 shadow-xl ${
            placement === 'up' ? 'bottom-full mb-2' : 'top-full mt-2'
          }`}
        >
          {err && <p className="mb-1 rounded-lg bg-red-50 px-2.5 py-2 text-red-700">{err}</p>}
          <MenuButton
            disabled={busy}
            title="Nowa oferta z zaznaczonych"
            note={`${positions(count)} · cena: koszt zakupu + Twoja domyślna marża`}
            onClick={() => void run(() => createOffer(body))}
          />
          <div className="my-1 border-t border-slate-100" />
          {offers === null && !offersErr && <p className="px-2.5 py-2 text-slate-500">Wczytuję Twoje oferty…</p>}
          {offersErr && <p className="px-2.5 py-2 text-red-700">Nie udało się wczytać ofert: {offersErr}</p>}
          {offers !== null && offers.length === 0 && (
            <p className="px-2.5 py-2 text-slate-500">Nie masz jeszcze ofert.</p>
          )}
          {offers?.map((o) => (
            <MenuButton
              key={o.id}
              disabled={busy}
              title={`Do oferty „${o.subject.trim() || 'bez tematu'}”`}
              note={
                <>
                  {o.code ? `${o.code} · ` : ''}
                  {positions(o.items_count)} · zmieniona {formatDate(o.updated_at)}
                  {o.last_sent_at ? ` · wysłana ${formatDate(o.last_sent_at)}` : ''}
                </>
              }
              onClick={() => void run(() => addOfferItems(o.id, body))}
            />
          ))}
          {offers !== null && offers.length > 0 && (
            <p className="px-2.5 pt-1 text-[11px] text-slate-500">Pozycje już dodane do oferty są pomijane.</p>
          )}
          {busy && <p className="px-2.5 pt-1 text-slate-500">Zapisuję…</p>}
        </div>
      )}
    </div>
  )
}

function MenuButton({
  title,
  note,
  disabled,
  onClick,
}: {
  title: string
  note: ReactNode
  disabled: boolean
  onClick: () => void
}) {
  return (
    <button
      type="button"
      role="menuitem"
      disabled={disabled}
      onClick={onClick}
      className="app-menu-item block w-full rounded-lg px-2.5 py-2 text-left hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50"
    >
      <span className="block font-semibold">{title}</span>
      <span className="block text-[11px] text-slate-500">{note}</span>
    </button>
  )
}
