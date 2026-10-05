import { useState } from 'react'
import { BRAND_COLOR_LABEL, BRAND_COLORS, DEFAULT_BRAND_COLOR, ITEM_LINK_LABEL_MAX, type CampaignItemLink } from '../lib/campaigns'
import { BTN, BTN_PRIMARY, BTN_SM, INPUT, Modal } from './CampaignsUi'

/** Jak CampaignBlocks::validUrl bez mailto: https://, host bez spacji, „@” i dwukropka. */
function validLinkUrl(url: string): boolean {
  return /^https:\/\/[^/?#@:\s]+(:\d{1,5})?([/?#]\S*)?$/i.test(url)
}

/**
 * Przycisk z linkiem przy produkcie w mailu (np. do sklepu): podgląd, „+ Dodaj link”, zmiana i usunięcie. Wspólne dla
 * kampanii (drugi przycisk pod „Zapytaj o ofertę”) i oferty (jedyny przycisk — oferta podaje cenę, nie pyta o nią).
 */
export function ItemLinkField({
  name,
  link,
  editable,
  brandColor,
  askLabel,
  onSave,
}: {
  /** Nazwa produktu — w oknie, przy którym produkcie pojawi się przycisk. */
  name: string
  link: CampaignItemLink | null
  editable: boolean
  brandColor: string
  /** Przycisk nad linkiem w mailu (kampania: „Zapytaj o ofertę”); null = link jest jedynym przyciskiem (oferta). */
  askLabel: string | null
  /** null = usuń przycisk; false = serwer nie przyjął (okno zostaje otwarte). */
  onSave: (link: CampaignItemLink | null) => Promise<boolean>
}) {
  const [open, setOpen] = useState(false)
  if (!link && !editable) return null
  return (
    <div className="mt-1.5 flex max-w-[34rem] flex-wrap items-center gap-x-1.5 gap-y-1 text-[11px]">
      {link ? (
        <>
          <span className="font-medium text-slate-700">Przycisk w mailu:</span>
          <span className="rounded px-2 py-0.5 font-bold text-white" style={{ backgroundColor: link.color }}>
            {link.label}
          </span>
          <a
            href={link.url}
            target="_blank"
            rel="noopener noreferrer"
            className="max-w-[16rem] truncate text-blue-600 hover:underline"
            title={link.url}
          >
            {link.url}
          </a>
          {editable && (
            <>
              <button type="button" className="text-blue-600 hover:underline" onClick={() => setOpen(true)}>
                zmień
              </button>
              <button type="button" className="text-red-700 hover:underline" onClick={() => void onSave(null)}>
                usuń
              </button>
            </>
          )}
        </>
      ) : (
        <button type="button" className={BTN_SM} onClick={() => setOpen(true)}>
          + Dodaj link
        </button>
      )}
      {open && (
        <ItemLinkModal
          name={name}
          link={link}
          brandColor={brandColor}
          askLabel={askLabel}
          onClose={() => setOpen(false)}
          onSave={async (next) => {
            if (await onSave(next)) setOpen(false)
          }}
        />
      )}
    </div>
  )
}

/** Okno „Dodaj link”: adres (np. do sklepu), nazwa przycisku i kolor; podgląd przycisków jak w mailu. */
function ItemLinkModal({
  name,
  link,
  brandColor,
  askLabel,
  onClose,
  onSave,
}: {
  name: string
  link: CampaignItemLink | null
  brandColor: string
  askLabel: string | null
  onClose: () => void
  onSave: (link: CampaignItemLink) => Promise<void>
}) {
  const [url, setUrl] = useState(link?.url ?? '')
  const [label, setLabel] = useState(link?.label ?? (askLabel === null ? 'Zobacz w sklepie' : ''))
  // przy „Zapytaj o ofertę” domyślnie inny kolor, żeby przyciski się odróżniały; bez niego — kolor maila
  const [color, setColor] = useState<string>(
    link?.color ?? (askLabel === null ? brandColor : (BRAND_COLORS.find((c) => c !== brandColor) ?? DEFAULT_BRAND_COLOR)),
  )
  const [busy, setBusy] = useState(false)
  const [touched, setTouched] = useState(false)

  const cleanUrl = url.trim()
  const cleanLabel = label.trim()
  const urlError =
    cleanUrl === '' ? 'Wpisz link.' : !validLinkUrl(cleanUrl) ? 'Link musi zaczynać się od https:// i nie może zawierać spacji.' : ''
  const labelError = cleanLabel === '' ? 'Wpisz nazwę przycisku.' : ''

  async function save() {
    setTouched(true)
    if (urlError || labelError) return
    setBusy(true)
    try {
      await onSave({ url: cleanUrl, label: cleanLabel, color })
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal
      title={link ? 'Zmień link przy produkcie' : 'Dodaj link przy produkcie'}
      busy={busy}
      onClose={onClose}
      footer={
        <>
          <button type="button" className={BTN} onClick={onClose} disabled={busy}>
            Anuluj
          </button>
          <button type="button" className={BTN_PRIMARY} disabled={busy} onClick={() => void save()}>
            {busy ? 'Zapisuję…' : 'Zapisz'}
          </button>
        </>
      }
    >
      <div className="space-y-3 text-xs">
        <p className="text-slate-600">
          {askLabel !== null ? (
            <>
              W mailu pod „{askLabel}” przy produkcie <b className="font-medium text-slate-800">{name}</b> pojawi się drugi
              przycisk prowadzący pod ten link.
            </>
          ) : (
            <>
              W mailu i w PDF przy produkcie <b className="font-medium text-slate-800">{name}</b> pojawi się przycisk
              prowadzący pod ten link, np. do produktu w sklepie.
            </>
          )}
        </p>
        <label className="block">
          <span className="mb-1 block font-medium text-slate-700">Link</span>
          <input
            className={`${INPUT} w-full`}
            type="url"
            inputMode="url"
            maxLength={500}
            placeholder="https://…"
            value={url}
            autoFocus
            onChange={(e) => setUrl(e.target.value)}
          />
          {touched && urlError && <span className="mt-0.5 block text-red-700">{urlError}</span>}
        </label>
        <label className="block">
          <span className="mb-1 block font-medium text-slate-700">Nazwa przycisku</span>
          <input
            className={`${INPUT} w-full`}
            maxLength={ITEM_LINK_LABEL_MAX}
            placeholder="np. Kup w sklepie"
            value={label}
            onChange={(e) => setLabel(e.target.value)}
          />
          <span className="mt-0.5 block text-slate-500">
            {label.length}/{ITEM_LINK_LABEL_MAX}
          </span>
          {touched && labelError && <span className="block text-red-700">{labelError}</span>}
        </label>
        <div>
          <span className="mb-1 block font-medium text-slate-700">Kolor przycisku</span>
          <div className="flex flex-wrap items-center gap-2" role="radiogroup" aria-label="Kolor przycisku">
            {BRAND_COLORS.map((c) => {
              const on = c === color
              return (
                <button
                  key={c}
                  type="button"
                  role="radio"
                  aria-checked={on}
                  aria-label={BRAND_COLOR_LABEL[c] ?? c}
                  title={BRAND_COLOR_LABEL[c] ?? c}
                  onClick={() => setColor(c)}
                  className={`h-6 w-6 rounded-full border-2 ${on ? 'border-slate-900 ring-2 ring-slate-300' : 'border-white ring-1 ring-slate-300'}`}
                  style={{ backgroundColor: c }}
                />
              )
            })}
            <span className="text-slate-500">{BRAND_COLOR_LABEL[color] ?? color}</span>
          </div>
        </div>
        <div>
          <span className="mb-1 block font-medium text-slate-700">Podgląd w mailu</span>
          <div className="w-48 space-y-1.5 rounded-md border border-slate-200 p-2.5">
            {askLabel !== null && (
              <div className="rounded py-1.5 text-center text-[12.5px] font-bold text-white" style={{ backgroundColor: brandColor }}>
                {askLabel}
              </div>
            )}
            <div
              className="break-words rounded px-1 py-1.5 text-center text-[12.5px] font-bold text-white"
              style={{ backgroundColor: color }}
            >
              {cleanLabel || 'Nazwa przycisku'}
            </div>
          </div>
        </div>
      </div>
    </Modal>
  )
}
