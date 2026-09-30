import { useEffect, useRef, useState, type ReactNode } from 'react'
import { useNavigate } from 'react-router-dom'
import { addCampaignItems, createCampaign, listCampaigns, type Campaign, type CampaignListRow } from '../lib/campaigns'
import { plural } from '../lib/plural'
import { formatDate } from '../lib/priceChange'

/** Najwięcej pozycji w jednej kampanii (backend: config campaigns.max_items — tam decyzja ostateczna). */
export const CAMPAIGN_MAX_ITEMS = 12
/** Tyle ostatnich projektów użytkownika pokazuje menu. */
const RECENT_DRAFTS = 5

function positions(n: number): string {
  return `${n} ${plural(n, 'pozycja', 'pozycje', 'pozycji')}`
}

/**
 * „Dodaj do kampanii ▾”: nowa kampania z zaznaczonych albo dopisanie do jednego z ostatnich projektów
 * (kampanie w statusie draft użytkownika). Po sukcesie otwiera kreator /kampanie/:id. Wspólne dla Zapasów
 * (towary XL — erpItemIds) i Produktów (karty — productIds).
 */
export function AddToCampaignMenu({
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
  const [drafts, setDrafts] = useState<CampaignListRow[] | null>(null)
  const [draftsErr, setDraftsErr] = useState('')
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const boxRef = useRef<HTMLDivElement>(null)
  const seq = useRef(0)

  const count = erpItemIds.length + productIds.length
  const tooMany = count > CAMPAIGN_MAX_ITEMS

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

  async function loadDrafts() {
    const my = ++seq.current
    setDraftsErr('')
    try {
      const res = await listCampaigns({ scope: 'mine', status: 'draft' })
      if (my === seq.current) setDrafts(res.data.slice(0, RECENT_DRAFTS))
    } catch (ex) {
      if (my === seq.current) setDraftsErr(ex instanceof Error ? ex.message : 'Nie udało się wczytać projektów')
    }
  }

  function toggle() {
    if (open) {
      setOpen(false)
      return
    }
    setErr('')
    setOpen(true)
    // Za każdym otwarciem świeża lista — projekt mógł dostać pozycje w innej karcie przeglądarki.
    void loadDrafts()
  }

  async function run(action: () => Promise<Campaign>) {
    setBusy(true)
    setErr('')
    try {
      const campaign = await action()
      setOpen(false)
      navigate(`/kampanie/${campaign.id}`)
    } catch (ex) {
      // Np. 422 „kampania ma już 12 pozycji” albo „kampania została już wysłana” — komunikat z serwera.
      setErr(ex instanceof Error ? ex.message : 'Nie udało się dodać do kampanii')
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
        title={
          tooMany
            ? `Kampania mieści najwyżej ${CAMPAIGN_MAX_ITEMS} pozycji — zaznaczono ${count}`
            : 'Nowa kampania z zaznaczonych albo dopisanie do Twojego projektu'
        }
      >
        Dodaj do kampanii{showCount && count > 0 ? ` (${count})` : ''} ▾
      </button>
      {open && (
        <div
          role="menu"
          className={`app-popover absolute right-0 z-40 w-80 rounded-xl border border-slate-200 bg-white p-2 text-left text-xs text-slate-800 shadow-xl ${
            placement === 'up' ? 'bottom-full mb-2' : 'top-full mt-2'
          }`}
        >
          {tooMany ? (
            <p className="rounded-lg bg-amber-50 px-2.5 py-2 text-amber-900">
              Kampania mieści najwyżej {CAMPAIGN_MAX_ITEMS} pozycji, a zaznaczono {count}. Odznacz{' '}
              {count - CAMPAIGN_MAX_ITEMS} — klient i tak nie obejrzy więcej w jednym mailu.
            </p>
          ) : (
            <>
              {err && <p className="mb-1 rounded-lg bg-red-50 px-2.5 py-2 text-red-700">{err}</p>}
              <MenuButton
                disabled={busy}
                title="Nowa kampania z zaznaczonych"
                note={`${positions(count)} · kreator zacznie od produktów`}
                onClick={() => void run(() => createCampaign(body))}
              />
              <div className="my-1 border-t border-slate-100" />
              {drafts === null && !draftsErr && <p className="px-2.5 py-2 text-slate-500">Wczytuję Twoje projekty…</p>}
              {draftsErr && <p className="px-2.5 py-2 text-red-700">Nie udało się wczytać projektów: {draftsErr}</p>}
              {drafts !== null && drafts.length === 0 && (
                <p className="px-2.5 py-2 text-slate-500">Nie masz jeszcze projektów kampanii.</p>
              )}
              {drafts?.map((d) => {
                const room = CAMPAIGN_MAX_ITEMS - d.items_count
                return (
                  <MenuButton
                    key={d.id}
                    disabled={busy || room <= 0}
                    title={`Do projektu „${d.name}”`}
                    note={
                      <>
                        {d.code ? `${d.code} · ` : ''}
                        {positions(d.items_count)} · utworzony {formatDate(d.created_at)}
                        {room <= 0 ? (
                          <span className="block text-amber-800">Projekt jest pełny ({CAMPAIGN_MAX_ITEMS} pozycji).</span>
                        ) : count > room ? (
                          <span className="block text-amber-800">
                            Zmieści się jeszcze {positions(room)} (pozycje już dodane są pomijane).
                          </span>
                        ) : null}
                      </>
                    }
                    onClick={() => void run(() => addCampaignItems(d.id, body))}
                  />
                )
              })}
              {busy && <p className="px-2.5 pt-1 text-slate-500">Zapisuję…</p>}
            </>
          )}
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
