import { useEffect, useRef, type ReactNode } from 'react'
import type { InquiryContact } from '../types/inquiry'

/**
 * Kontakt ze stopki maila: kafelek w wierszu listy i okno ze szczegółami.
 * Pokazujemy wyłącznie to, co backend wyciągnął ze stopki — puste pola pomijamy,
 * niczego nie uzupełniamy ani nie formatujemy „na ładniej”.
 */

/** Czy jest cokolwiek do pokazania — sam pusty obiekt nie zasługuje na kafelek. */
function hasContact(contact: InquiryContact | null | undefined): contact is InquiryContact {
  if (!contact) return false
  return Boolean(
    contact.person ||
      contact.company ||
      contact.address ||
      contact.website ||
      contact.raw ||
      (contact.emails?.length ?? 0) > 0 ||
      (contact.phones?.length ?? 0) > 0,
  )
}

/** Krótki podpis na kafelku — pierwsza sensowna dana, bez sklejania. */
function contactLabel(contact: InquiryContact): string {
  return (
    contact.person ||
    contact.company ||
    contact.emails?.[0] ||
    contact.phones?.[0] ||
    'Kontakt'
  )
}

/** Numer w linku `tel:` bez spacji i myślników; na ekranie zostaje zapis z maila. */
function telHref(phone: string): string {
  const cleaned = phone.replace(/[^\d+]/g, '')
  return `tel:${cleaned}`
}

/** Stopki podają adresy bez schematu („www.firma.pl”) — link i tak musi go mieć. */
function websiteHref(website: string): string {
  return /^https?:\/\//i.test(website) ? website : `https://${website.replace(/^\/+/, '')}`
}

export function InquiryContactChip({
  contact,
  onOpen,
  title = 'Pokaż kontakt ze stopki maila',
}: {
  contact: InquiryContact | null | undefined
  onOpen: () => void
  title?: string
}) {
  if (!hasContact(contact)) return null
  return (
    <button
      type="button"
      onClick={onOpen}
      title={title}
      className="max-w-[16rem] truncate rounded-full border border-slate-300 bg-white px-2 py-0.5 text-[11px] font-medium text-slate-700 hover:border-blue-300 hover:text-blue-700"
    >
      {contactLabel(contact)}
    </button>
  )
}

function Row({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="grid grid-cols-[6.5rem_1fr] gap-x-2 gap-y-0.5 py-1">
      <span className="text-[11px] uppercase tracking-wide text-slate-500">{label}</span>
      <div className="text-xs text-slate-800">{children}</div>
    </div>
  )
}

const FOCUSABLE =
  'a[href], button:not([disabled]), summary, input, select, textarea, [tabindex]:not([tabindex="-1"])'

export function InquiryContactModal({
  contact,
  subtitle,
  onClose,
}: {
  /** `null` = okno zamknięte. */
  contact: InquiryContact | null
  subtitle?: string | null
  onClose: () => void
}) {
  const boxRef = useRef<HTMLDivElement>(null)
  const open = hasContact(contact)

  useEffect(() => {
    if (!open) return
    const previous = document.activeElement as HTMLElement | null
    boxRef.current?.focus()

    function onKey(e: KeyboardEvent) {
      if (e.key === 'Escape') {
        // Okno może stać nad innym (np. weryfikacją karty) — Escape zamyka tylko to wierzchnie.
        e.stopPropagation()
        onClose()
        return
      }
      if (e.key !== 'Tab' || !boxRef.current) return
      const items = [...boxRef.current.querySelectorAll<HTMLElement>(FOCUSABLE)].filter(
        (el) => el.offsetParent !== null,
      )
      if (items.length === 0) {
        e.preventDefault()
        boxRef.current.focus()
        return
      }
      const first = items[0]
      const last = items[items.length - 1]
      const active = document.activeElement
      if (!e.shiftKey && (active === last || active === boxRef.current)) {
        e.preventDefault()
        first.focus()
      } else if (e.shiftKey && (active === first || active === boxRef.current)) {
        e.preventDefault()
        last.focus()
      }
    }

    window.addEventListener('keydown', onKey, true)
    return () => {
      window.removeEventListener('keydown', onKey, true)
      previous?.focus?.()
    }
  }, [open, onClose])

  if (!contact || !open) return null

  const emails = contact.emails ?? []
  const phones = contact.phones ?? []

  return (
    <div
      className="fixed inset-0 z-[80] flex items-center justify-center bg-slate-950/50 p-4"
      onClick={(e) => {
        e.stopPropagation()
        onClose()
      }}
    >
      <div
        ref={boxRef}
        role="dialog"
        aria-modal="true"
        aria-labelledby="inquiry-contact-title"
        tabIndex={-1}
        className="flex max-h-[80vh] w-full max-w-md flex-col overflow-hidden rounded-lg bg-white shadow-xl outline-none"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="border-b border-slate-100 px-4 py-3">
          <p id="inquiry-contact-title" className="text-sm font-semibold text-slate-800">
            Kontakt ze stopki maila
          </p>
          {subtitle && <p className="mt-0.5 truncate text-xs text-slate-500">{subtitle}</p>}
        </div>

        <div className="min-h-0 flex-1 divide-y divide-slate-100 overflow-y-auto px-4 py-2">
          {contact.person && <Row label="Osoba">{contact.person}</Row>}
          {contact.company && <Row label="Firma">{contact.company}</Row>}
          {phones.length > 0 && (
            <Row label="Telefon">
              <ul className="space-y-0.5">
                {phones.map((phone) => (
                  <li key={phone}>
                    <a className="text-blue-600 hover:underline" href={telHref(phone)}>
                      {phone}
                    </a>
                  </li>
                ))}
              </ul>
            </Row>
          )}
          {emails.length > 0 && (
            <Row label="E-mail">
              <ul className="space-y-0.5">
                {emails.map((email) => (
                  <li key={email}>
                    <a className="break-all text-blue-600 hover:underline" href={`mailto:${email}`}>
                      {email}
                    </a>
                  </li>
                ))}
              </ul>
            </Row>
          )}
          {contact.address && <Row label="Adres">{contact.address}</Row>}
          {contact.website && (
            <Row label="Strona">
              <a
                className="break-all text-blue-600 hover:underline"
                href={websiteHref(contact.website)}
                target="_blank"
                rel="noreferrer"
              >
                {contact.website}
              </a>
            </Row>
          )}
          {contact.raw && (
            <details className="py-2">
              <summary className="cursor-pointer text-[11px] uppercase tracking-wide text-slate-500">
                Stopka w oryginale
              </summary>
              <pre className="mt-1.5 whitespace-pre-wrap break-words font-sans text-xs text-slate-600">
                {contact.raw}
              </pre>
            </details>
          )}
        </div>

        <div className="flex justify-end border-t border-slate-100 px-4 py-2.5">
          <button
            type="button"
            onClick={onClose}
            className="rounded border border-slate-300 px-3 py-1.5 text-xs text-slate-700 hover:bg-slate-50"
          >
            Zamknij
          </button>
        </div>
      </div>
    </div>
  )
}
