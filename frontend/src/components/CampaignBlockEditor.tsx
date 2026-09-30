import { useEffect, useRef, useState } from 'react'
import { BTN_SM, INPUT } from './CampaignsUi'
import { errorText } from '../lib/campaignFormat'
import {
  BRAND_COLORS,
  BRAND_COLOR_LABEL,
  CAMPAIGN_BLOCK_LABEL,
  CAMPAIGN_LAYOUT_HINT,
  CAMPAIGN_LAYOUT_LABEL,
  LAYOUTS_WITH_DESCRIPTION,
  DEFAULT_BRAND_COLOR,
  MAX_CAMPAIGN_BLOCKS,
  campaignAssetUrl,
  uploadCampaignAsset,
  type CampaignBlock,
  type CampaignBlockType,
  type CampaignLayout,
} from '../lib/campaigns'

/**
 * Elementy maila kampanii i szablonu: karty elementów z polami, kolejność ↑ ↓, usuwanie, dodawanie i kolor.
 * Produkty są zawsze dokładnie raz (bez ×), logo i stopka najwyżej raz. Obrazki idą na serwer od razu po
 * wybraniu pliku, w elemencie zostaje tylko identyfikator obrazka.
 */

const ADD_ORDER: CampaignBlockType[] = ['header', 'heading', 'text', 'image', 'button', 'footer']
const LAYOUTS = Object.keys(CAMPAIGN_LAYOUT_LABEL) as CampaignLayout[]
const MAX_UPLOAD_BYTES = 5 * 1024 * 1024
const IMAGE_ACCEPT = 'image/jpeg,image/png,image/gif,image/webp'

function emptyBlock(type: CampaignBlockType): CampaignBlock {
  switch (type) {
    case 'header':
      return { type, logo: null }
    case 'heading':
    case 'text':
    case 'footer':
      return { type, text: '' }
    case 'image':
      return { type, asset: null, alt: '', url: '' }
    case 'products':
      return { type, layout: 'grid3' }
    case 'button':
      return { type, label: '', url: '' }
  }
}

/** Adres linku: https://… (przycisk także mailto:…), bez spacji — jak walidacja serwera. */
function urlProblem(url: string, allowMailto: boolean): string {
  const u = url.trim()
  if (u === '') return ''
  const ok = allowMailto ? /^(https:\/\/|mailto:)\S+$/i.test(u) : /^https:\/\/\S+$/i.test(u)
  if (ok) return ''
  return allowMailto
    ? 'Adres musi zaczynać się od https:// albo mailto: i nie może mieć spacji.'
    : 'Adres musi zaczynać się od https:// i nie może mieć spacji.'
}

export function CampaignBlockEditor({
  blocks,
  brandColor,
  onChange,
  disabled,
  onUploadingChange,
}: {
  blocks: CampaignBlock[]
  brandColor: string | null
  onChange: (blocks: CampaignBlock[], brandColor: string | null) => void
  disabled?: boolean
  /** Wgrywanie obrazka w toku — rodzic blokuje wtedy podmianę całej listy (np. „Zastosuj” szablon). */
  onUploadingChange?: (busy: boolean) => void
}) {
  const [uploading, setUploading] = useState<number | null>(null)
  useEffect(() => {
    onUploadingChange?.(uploading !== null)
  }, [uploading, onUploadingChange])
  const [uploadErr, setUploadErr] = useState<{ index: number; text: string } | null>(null)
  const fileInput = useRef<HTMLInputElement | null>(null)
  const uploadFor = useRef<number | null>(null)
  // Wynik wgrywania trafia do aktualnych elementów (w trakcie wgrywania mogły się zmienić pola innych elementów).
  const latest = useRef({ blocks, brandColor, onChange })
  useEffect(() => {
    latest.current = { blocks, brandColor, onChange }
  }, [blocks, brandColor, onChange])

  const locked = Boolean(disabled) || uploading !== null
  const color = brandColor ?? DEFAULT_BRAND_COLOR
  const has = (t: CampaignBlockType) => blocks.some((b) => b.type === t)
  const full = blocks.length >= MAX_CAMPAIGN_BLOCKS
  const addable = ADD_ORDER.filter((t) => !((t === 'header' || t === 'footer') && has(t)))

  function setBlocks(next: CampaignBlock[]) {
    onChange(next, brandColor)
  }

  function update(index: number, block: CampaignBlock) {
    setBlocks(blocks.map((b, i) => (i === index ? block : b)))
  }

  function move(index: number, dir: -1 | 1) {
    const j = index + dir
    if (j < 0 || j >= blocks.length) return
    const next = [...blocks]
    ;[next[index], next[j]] = [next[j], next[index]]
    setBlocks(next)
  }

  function remove(index: number) {
    if (blocks[index]?.type === 'products') return
    setBlocks(blocks.filter((_, i) => i !== index))
  }

  /** Logo na górę, stopka na koniec, reszta przed stopką (albo na koniec, gdy stopki nie ma). */
  function add(type: CampaignBlockType) {
    if (full) return
    const block = emptyBlock(type)
    if (type === 'header') {
      setBlocks([block, ...blocks])
      return
    }
    const footerAt = blocks.findIndex((b) => b.type === 'footer')
    if (type === 'footer' || footerAt < 0) {
      setBlocks([...blocks, block])
      return
    }
    setBlocks([...blocks.slice(0, footerAt), block, ...blocks.slice(footerAt)])
  }

  function pickFile(index: number) {
    uploadFor.current = index
    setUploadErr(null)
    fileInput.current?.click()
  }

  async function upload(file: File) {
    const index = uploadFor.current
    uploadFor.current = null
    if (index === null) return
    const type = blocks[index]?.type
    if (type !== 'header' && type !== 'image') return
    if (file.size > MAX_UPLOAD_BYTES) {
      setUploadErr({ index, text: 'Plik jest większy niż 5 MB.' })
      return
    }
    setUploading(index)
    try {
      const asset = await uploadCampaignAsset(file)
      // przesuwanie i usuwanie elementów jest zablokowane w trakcie wgrywania — indeks się nie zmienił
      const cur = latest.current
      const b = cur.blocks[index]
      if (b?.type === 'header') {
        cur.onChange(cur.blocks.map((x, i) => (i === index ? { ...b, logo: asset.uuid } : x)), cur.brandColor)
      } else if (b?.type === 'image') {
        cur.onChange(cur.blocks.map((x, i) => (i === index ? { ...b, asset: asset.uuid } : x)), cur.brandColor)
      }
    } catch (ex) {
      setUploadErr({ index, text: errorText(ex, 'Nie udało się wgrać obrazka.') })
    } finally {
      setUploading(null)
    }
  }

  const field = `${INPUT} mt-1 block w-full text-sm`

  function imageField(index: number, uuid: string | null, onRemove: () => void, emptyNote: string) {
    const busy = uploading === index
    return (
      <div className="flex flex-wrap items-center gap-3">
        {uuid ? (
          <img
            src={campaignAssetUrl(uuid)}
            alt=""
            className="h-16 max-w-[220px] rounded border border-slate-200 bg-slate-50 object-contain"
          />
        ) : (
          <span className="rounded border border-dashed border-slate-300 px-3 py-4 text-[11px] text-slate-500">{emptyNote}</span>
        )}
        {!disabled && (
          <span className="inline-flex flex-wrap gap-1">
            <button type="button" className={BTN_SM} disabled={locked} onClick={() => pickFile(index)}>
              {busy ? 'Wgrywam…' : uuid ? 'Zmień obrazek' : 'Wgraj obrazek'}
            </button>
            {uuid && (
              <button type="button" className={`${BTN_SM} text-red-700`} disabled={locked} onClick={onRemove}>
                Usuń obrazek
              </button>
            )}
          </span>
        )}
        {uploadErr?.index === index && <span className="w-full text-[11px] text-red-700">{uploadErr.text}</span>}
      </div>
    )
  }

  function body(block: CampaignBlock, index: number) {
    switch (block.type) {
      case 'header':
        return (
          <>
            {imageField(index, block.logo, () => update(index, { ...block, logo: null }), 'bez logo — nazwa i hasło firmy')}
            <p className="text-[11px] text-slate-500">JPG, PNG, GIF albo WEBP do 5 MB. W mailu logo ma najwyżej 60 px wysokości.</p>
          </>
        )
      case 'heading':
        return (
          <input
            aria-label="Nagłówek"
            className={field}
            maxLength={200}
            disabled={disabled}
            value={block.text}
            onChange={(e) => update(index, { ...block, text: e.target.value.replace(/[\r\n]+/g, ' ') })}
            placeholder="np. Końcówki serii w cenach wyprzedażowych"
          />
        )
      case 'text':
        return (
          <textarea
            aria-label="Tekst"
            className={`${field} min-h-24 resize-y`}
            maxLength={5000}
            disabled={disabled}
            value={block.text}
            onChange={(e) => update(index, { ...block, text: e.target.value })}
            placeholder={'Dzień dobry,\nmamy na magazynie końcówki serii…'}
          />
        )
      case 'image': {
        const problem = urlProblem(block.url, false)
        return (
          <>
            {imageField(index, block.asset, () => update(index, { ...block, asset: null }), 'brak obrazka')}
            {!block.asset && !disabled && (
              <p className="text-[11px] text-amber-800">Wgraj obrazek — bez niego kampanii nie da się wysłać.</p>
            )}
            <label className="block text-slate-600">
              Opis obrazka <span className="text-slate-500">(gdy klient ma wyłączone obrazki)</span>
              <input
                className={field}
                maxLength={200}
                disabled={disabled}
                value={block.alt}
                onChange={(e) => update(index, { ...block, alt: e.target.value.replace(/[\r\n]+/g, ' ') })}
                placeholder="np. Nowa kolekcja obuwia S3"
              />
            </label>
            <label className="block text-slate-600">
              Link po kliknięciu <span className="text-slate-500">(opcjonalnie)</span>
              <input
                className={field}
                maxLength={500}
                disabled={disabled}
                value={block.url}
                onChange={(e) => update(index, { ...block, url: e.target.value.replace(/[\r\n]+/g, '') })}
                placeholder="https://…"
              />
            </label>
            {problem && <p className="text-[11px] text-red-700">{problem}</p>}
          </>
        )
      }
      case 'products':
        return (
          <>
            <label className="inline-flex items-center gap-1.5 text-slate-600">
              Układ
              <select
                className={INPUT}
                disabled={disabled}
                value={block.layout}
                onChange={(e) => update(index, { ...block, layout: e.target.value as CampaignLayout })}
              >
                {LAYOUTS.map((l) => (
                  <option key={l} value={l}>
                    {CAMPAIGN_LAYOUT_LABEL[l]}
                  </option>
                ))}
              </select>
            </label>
            <p className="text-[11px] text-slate-500">
              {CAMPAIGN_LAYOUT_HINT[block.layout]} Nad pozycjami zawsze „Ceny netto ważne do …”.
              {LAYOUTS_WITH_DESCRIPTION.includes(block.layout) &&
                ' Opis bierzemy z karty produktu; zmienisz go przy pozycji w kroku „Produkty”.'}
            </p>
          </>
        )
      case 'button': {
        const problem = urlProblem(block.url, true)
        return (
          <>
            <div className="grid gap-2 sm:grid-cols-[minmax(0,14rem)_minmax(0,1fr)]">
              <label className="block text-slate-600">
                Napis
                <input
                  className={field}
                  maxLength={60}
                  disabled={disabled}
                  value={block.label}
                  onChange={(e) => update(index, { ...block, label: e.target.value.replace(/[\r\n]+/g, ' ') })}
                  placeholder="np. Zobacz katalog"
                />
              </label>
              <label className="block text-slate-600">
                Adres
                <input
                  className={field}
                  maxLength={500}
                  disabled={disabled}
                  value={block.url}
                  onChange={(e) => update(index, { ...block, url: e.target.value.replace(/[\r\n]+/g, '') })}
                  placeholder="https://… albo mailto:…"
                />
              </label>
            </div>
            {problem ? (
              <p className="text-[11px] text-red-700">{problem}</p>
            ) : (
              (!block.label.trim() || !block.url.trim()) &&
              !disabled && <p className="text-[11px] text-amber-800">Podaj napis i adres — bez nich kampanii nie da się wysłać.</p>
            )}
          </>
        )
      }
      case 'footer':
        return (
          <>
            <textarea
              aria-label="Stopka"
              className={`${field} min-h-16 resize-y`}
              maxLength={1000}
              disabled={disabled}
              value={block.text}
              onChange={(e) => update(index, { ...block, text: e.target.value })}
              placeholder="np. dane firmy, godziny pracy, telefon do biura"
            />
            <p className="text-[11px] text-slate-500">Link „Wypisz mnie z mailingu” jest w mailu zawsze, także bez stopki.</p>
          </>
        )
    }
  }

  return (
    <div className="space-y-2 text-xs">
      <div className="flex flex-wrap items-center gap-2">
        <span className="font-medium text-slate-700">Kolor przycisków i akcentów</span>
        <span className="inline-flex gap-1.5" role="radiogroup" aria-label="Kolor maila">
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
                disabled={disabled}
                onClick={() => onChange(blocks, c)}
                className={`h-6 w-6 rounded-full border-2 disabled:cursor-default ${
                  on ? 'border-slate-900 ring-2 ring-slate-300' : 'border-white ring-1 ring-slate-300'
                }`}
                style={{ backgroundColor: c }}
              />
            )
          })}
        </span>
        <span className="text-slate-500">{BRAND_COLOR_LABEL[color] ?? color}</span>
      </div>

      <input
        ref={fileInput}
        type="file"
        accept={IMAGE_ACCEPT}
        className="hidden"
        onChange={(e) => {
          const file = e.target.files?.[0]
          e.target.value = ''
          if (file) void upload(file)
          else uploadFor.current = null
        }}
      />

      <ol className="space-y-2">
        {blocks.map((block, index) => (
          <li key={index} className="rounded-lg border border-slate-200 bg-white">
            <div className="flex items-center justify-between gap-2 border-b border-slate-100 bg-slate-50 px-3 py-1.5">
              <b className="text-xs font-semibold text-slate-800">
                <span className="tabular-nums text-slate-500">{index + 1}.</span> {CAMPAIGN_BLOCK_LABEL[block.type] ?? block.type}
              </b>
              {!disabled && (
                <span className="inline-flex gap-1">
                  <button
                    type="button"
                    className={BTN_SM}
                    disabled={locked || index === 0}
                    onClick={() => move(index, -1)}
                    aria-label={`Przesuń wyżej: ${CAMPAIGN_BLOCK_LABEL[block.type]}`}
                    title="Wyżej"
                  >
                    ↑
                  </button>
                  <button
                    type="button"
                    className={BTN_SM}
                    disabled={locked || index === blocks.length - 1}
                    onClick={() => move(index, 1)}
                    aria-label={`Przesuń niżej: ${CAMPAIGN_BLOCK_LABEL[block.type]}`}
                    title="Niżej"
                  >
                    ↓
                  </button>
                  {block.type !== 'products' && (
                    <button
                      type="button"
                      className={`${BTN_SM} text-red-700`}
                      disabled={locked}
                      onClick={() => remove(index)}
                      aria-label={`Usuń element: ${CAMPAIGN_BLOCK_LABEL[block.type]}`}
                      title="Usuń element"
                    >
                      ×
                    </button>
                  )}
                </span>
              )}
            </div>
            <div className="space-y-2 px-3 py-2">{body(block, index)}</div>
          </li>
        ))}
      </ol>

      {!disabled && (
        <div className="flex flex-wrap items-center gap-1.5 pt-1">
          <span className="font-medium text-slate-700">Dodaj element:</span>
          {full ? (
            <span className="text-slate-500">najwyżej {MAX_CAMPAIGN_BLOCKS} elementów</span>
          ) : (
            addable.map((t) => (
              <button key={t} type="button" className={BTN_SM} disabled={locked} onClick={() => add(t)}>
                + {CAMPAIGN_BLOCK_LABEL[t]}
              </button>
            ))
          )}
        </div>
      )}
    </div>
  )
}

/**
 * Pomniejszony podgląd maila obok edytora: HTML z serwera w iframe bez skryptów (sandbox="").
 * Odświeża się `debounceMs` po ostatniej zmianie elementów lub koloru; starsza odpowiedź nie nadpisuje nowszej.
 */
export function LiveMailPreview({
  blocks,
  brandColor,
  load,
  note,
  debounceMs = 600,
}: {
  blocks: CampaignBlock[]
  brandColor: string | null
  load: (body: { blocks: CampaignBlock[]; brand_color: string | null }) => Promise<{ html: string }>
  note?: string
  debounceMs?: number
}) {
  const [html, setHtml] = useState<string | null>(null)
  const [loading, setLoading] = useState(false)
  const [err, setErr] = useState('')
  const seq = useRef(0)
  const loadRef = useRef(load)
  useEffect(() => {
    loadRef.current = load
  }, [load])

  const key = JSON.stringify([blocks, brandColor])
  useEffect(() => {
    const t = window.setTimeout(() => {
      const my = ++seq.current
      const body = JSON.parse(key) as [CampaignBlock[], string | null]
      setLoading(true)
      loadRef
        .current({ blocks: body[0], brand_color: body[1] })
        .then((res) => {
          if (my !== seq.current) return
          setHtml(res.html)
          setErr('')
        })
        .catch((ex: unknown) => {
          if (my === seq.current) setErr(errorText(ex, 'Nie udało się przygotować podglądu.'))
        })
        .finally(() => {
          if (my === seq.current) setLoading(false)
        })
    }, debounceMs)
    return () => window.clearTimeout(t)
  }, [key, debounceMs])

  // Mail ma 600 px szerokości — iframe 640 px pomniejszony do szerokości kolumny.
  const scale = 0.62
  return (
    <div className="overflow-x-auto rounded-xl bg-slate-100 p-3 text-xs">
      <div className="mb-2 flex items-center justify-between gap-2 text-slate-600">
        <b className="text-slate-800">Podgląd</b>
        <span className="text-slate-500">{loading ? 'odświeżam…' : note ?? ''}</span>
      </div>
      {err && <p className="mb-2 rounded bg-red-50 px-2 py-1.5 text-red-700">{err}</p>}
      {html !== null ? (
        <div className="relative mx-auto overflow-hidden rounded border border-slate-200 bg-white" style={{ width: 640 * scale, height: 1400 * scale }}>
          {/* sandbox="" — bez skryptów, formularzy i dostępu do strony; HTML maila tylko do obejrzenia. */}
          <iframe
            title="Podgląd maila"
            sandbox=""
            srcDoc={html}
            className="absolute left-0 top-0 origin-top-left border-0 bg-white"
            style={{ width: 640, height: 1400, transform: `scale(${scale})` }}
          />
        </div>
      ) : (
        !err && <p className="text-slate-500">Przygotowuję podgląd…</p>
      )}
    </div>
  )
}
