import { useCallback, useEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import {
  ApiError,
  manualMerge,
  manualMergeConflictPreview,
  manualMergePreview,
  type ManualMergeCard,
  type ManualMergeMoves,
  type ManualMergePreview,
  type ManualMergeResult,
} from '../lib/api'
import { currencyLabel, formatPrice } from '../lib/priceChange'

type Props = {
  /** Zaznaczone karty (2–10) — okno montowane dopiero po kliknięciu „Połącz zaznaczone”. */
  productIds: number[]
  onClose: () => void
  /** Po udanym połączeniu; cards = karty z podglądu, na którym zatwierdzono (SKU do komunikatu). */
  onMerged: (result: ManualMergeResult, cards: ManualMergeCard[]) => void
}

const NOTE_MAX = 500

/** 409 z połączenia: serwer policzył podgląd od nowa i jest inny niż ten, który człowiek oglądał. */
const PLAN_CHANGED_TEXT = 'Dane zmieniły się od podglądu — sprawdź i zatwierdź ponownie.'

/** Polska liczba mnoga: 1 → one, 2–4 (bez 12–14) → few, reszta → many. */
function plural(n: number, one: string, few: string, many: string): string {
  const mod10 = n % 10
  const mod100 = n % 100
  if (n === 1) return one
  return mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14) ? few : many
}

function priceText(price: string | null, currency: string | null): string {
  if (price === null || price === '') return '—'
  return `${formatPrice(price)} ${currencyLabel(currency)}`
}

/** „ceny z 2 źródeł, 3 zdjęcia, …” — tylko niezerowe pozycje, w kolejności ważnej dla handlowca. */
function movesText(moves: ManualMergeMoves): string[] {
  const out: string[] = []
  const add = (n: number, text: string) => {
    if (n > 0) out.push(text)
  }
  add(moves.source_prices, `ceny z ${moves.source_prices} ${plural(moves.source_prices, 'źródła', 'źródeł', 'źródeł')}`)
  add(
    moves.size_rows,
    `${moves.size_rows} ${plural(moves.size_rows, 'rozmiar', 'rozmiary', 'rozmiarów')} z cenami i historią cen`,
  )
  add(
    moves.b2b_links,
    `${moves.b2b_links} ${plural(moves.b2b_links, 'powiązanie', 'powiązania', 'powiązań')} z kontami B2B`,
  )
  add(
    moves.tender_items,
    `${moves.tender_items} ${plural(moves.tender_items, 'pozycja', 'pozycje', 'pozycji')} przetargów`,
  )
  add(moves.images, `${moves.images} ${plural(moves.images, 'zdjęcie', 'zdjęcia', 'zdjęć')}`)
  add(
    moves.identifiers,
    `${moves.identifiers} ${plural(moves.identifiers, 'identyfikator', 'identyfikatory', 'identyfikatorów')} (EAN, kody)`,
  )
  add(
    moves.accessories,
    `${moves.accessories} ${plural(moves.accessories, 'akcesorium', 'akcesoria', 'akcesoriów')}`,
  )
  return out
}

/**
 * „Połącz zaznaczone” z listy produktów: podgląd z serwera (która karta zostaje, blokady, ostrzeżenia, co przejdzie),
 * wybór karty, która zostaje (każda zmiana = nowy podgląd), potwierdzenie innej marki, notatka i połączenie z
 * plan_hash oglądanego podglądu. 409 = serwer ma inny podgląd — pokazujemy go w miejsce starego.
 */
export function ManualCardMergeModal({ productIds, onClose, onMerged }: Props) {
  const [preview, setPreview] = useState<ManualMergePreview | null>(null)
  const [loading, setLoading] = useState(false)
  /** Karta kliknięta w „Zostaje”, zanim przyjdzie jej podgląd (radio reaguje od razu). */
  const [requestedKeep, setRequestedKeep] = useState<number | null>(null)
  const [loadErr, setLoadErr] = useState('')
  const [submitErr, setSubmitErr] = useState('')
  const [notice, setNotice] = useState('')
  const [busy, setBusy] = useState(false)
  const [note, setNote] = useState('')
  /** Hash podglądu, przy którym zaznaczono „Wiem, łączę mimo innej marki” — po zmianie podglądu do ponowienia. */
  const [brandConfirmHash, setBrandConfirmHash] = useState<string | null>(null)
  const requestSeq = useRef(0)

  const load = useCallback(
    async (keepId: number | null) => {
      const seq = ++requestSeq.current
      setLoading(true)
      setRequestedKeep(keepId)
      setLoadErr('')
      try {
        const res = await manualMergePreview(productIds, keepId)
        if (seq !== requestSeq.current) return
        setPreview(res)
      } catch (ex) {
        if (seq !== requestSeq.current) return
        setLoadErr(ex instanceof Error ? ex.message : 'Nie udało się wczytać podglądu połączenia')
      } finally {
        if (seq === requestSeq.current) {
          setLoading(false)
          setRequestedKeep(null)
        }
      }
    },
    [productIds],
  )

  useEffect(() => {
    void load(null)
  }, [load])

  useEffect(() => {
    function onKey(e: KeyboardEvent) {
      if (e.key === 'Escape' && !busy) onClose()
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [busy, onClose])

  const cards = preview?.cards ?? []
  const keepId = loading && requestedKeep !== null ? requestedKeep : (preview?.keep_product_id ?? null)
  const keep = cards.find((c) => c.id === preview?.keep_product_id) ?? null
  const drops = keep ? cards.filter((c) => c.id !== keep.id) : []
  const needsConfirm = (preview?.warnings ?? []).some((w) => w.requires_confirm)
  const brandConfirmed = preview !== null && brandConfirmHash === preview.plan_hash
  const staleConfirm = brandConfirmHash !== null && !brandConfirmed
  const blockers = preview?.blockers ?? []
  const ready = preview !== null && preview.can_merge && blockers.length === 0 && keep !== null && drops.length > 0
  const canSubmit = ready && !loading && !busy && (!needsConfirm || brandConfirmed)
  const moves = preview ? movesText(preview.moves) : []

  async function submit() {
    if (!preview || !keep || !canSubmit) return
    const n = cards.length
    const ok = window.confirm(
      `Połączyć ${n} ${plural(n, 'kartę', 'karty', 'kart')} w kartę ${keep.sku} (#${keep.id})?\n\n` +
        `${drops.length === 1 ? 'Zniknie karta' : 'Znikną karty'} ${drops.map((c) => c.sku).join(', ')} — ` +
        `ich ceny, powiązania i zdjęcia przejdą do karty ${keep.sku}. Przed zmianą zapisuje się kopia zapasowa.`,
    )
    if (!ok) return
    setBusy(true)
    setSubmitErr('')
    setNotice('')
    try {
      const trimmed = note.trim()
      const res = await manualMerge({
        product_ids: productIds,
        keep_product_id: keep.id,
        plan_hash: preview.plan_hash,
        confirm_brand: needsConfirm && brandConfirmed,
        note: trimmed !== '' ? trimmed : null,
      })
      onMerged(res, cards)
    } catch (ex) {
      const fresh = manualMergeConflictPreview(ex)
      if (fresh) {
        // odpowiedź z serwera wygrywa z podglądem w drodze (np. po szybkiej zmianie radia)
        requestSeq.current++
        setLoading(false)
        setRequestedKeep(null)
        setPreview(fresh)
        setNotice(PLAN_CHANGED_TEXT)
      } else if (ex instanceof ApiError && ex.status === 409) {
        setNotice(PLAN_CHANGED_TEXT)
        void load(keep.id)
      } else {
        setSubmitErr(ex instanceof Error ? ex.message : 'Nie udało się połączyć kart')
        // 422: blokada, której podgląd jeszcze nie pokazywał — świeży podgląd pokaże ją w tabeli
        if (ex instanceof ApiError && ex.status === 422) void load(keep.id)
      }
    } finally {
      setBusy(false)
    }
  }

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
      role="dialog"
      aria-modal="true"
      aria-labelledby="manual-merge-title"
      onClick={() => {
        if (!busy) onClose()
      }}
    >
      <div
        className="flex max-h-[90vh] w-full max-w-6xl flex-col overflow-hidden rounded-xl bg-white shadow-lg"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="flex items-start justify-between gap-3 border-b border-slate-200 px-4 py-3">
          <div className="min-w-0">
            <p id="manual-merge-title" className="text-sm font-semibold text-slate-900">
              Połącz karty ręcznie ({productIds.length})
            </p>
            <p className="text-xs text-slate-500">
              Wybierz kartę, która zostaje. Pozostałe znikną — ich ceny, powiązania i zdjęcia przejdą do niej. Nic się
              nie zmieni, dopóki nie klikniesz „Połącz”.
            </p>
          </div>
          <button
            type="button"
            onClick={onClose}
            disabled={busy}
            aria-label="Zamknij"
            className="rounded px-2 py-0.5 text-lg leading-none text-slate-500 hover:bg-slate-100 hover:text-slate-900 disabled:opacity-50"
          >
            ×
          </button>
        </div>

        <div className="min-h-0 flex-1 space-y-3 overflow-auto px-4 py-3 text-xs">
          {notice && (
            <p className="rounded border border-amber-200 bg-amber-50 px-3 py-2 font-medium text-amber-900">{notice}</p>
          )}
          {loadErr && (
            <p className="rounded bg-red-50 px-3 py-2 text-red-700">
              {loadErr}
              {preview ? ' Pokazuję ostatnio pobrany podgląd.' : ''}
            </p>
          )}
          {preview === null && !loadErr && <p className="text-slate-500">Wczytuję podgląd połączenia…</p>}

          {preview !== null && (
            <>
              {(preview.suggestion_reason || preview.keep_locked) && (
                <div className="rounded bg-slate-50 px-3 py-2 text-slate-700">
                  {preview.suggestion_reason && (
                    <p>
                      <span className="font-medium text-slate-800">Podpowiedź:</span> {preview.suggestion_reason}
                    </p>
                  )}
                  {preview.keep_locked && preview.suggested_keep_id !== null && (
                    <p className="mt-0.5 text-slate-600">
                      Zostać może tylko karta producenta #{preview.suggested_keep_id} — pozostałych nie da się wybrać.
                    </p>
                  )}
                </div>
              )}

              <div className="overflow-x-auto">
                <table className={`w-full text-left text-xs ${loading ? 'opacity-60' : ''}`}>
                  <thead>
                    <tr className="border-b bg-slate-50">
                      <th className="w-16 p-2">Zostaje</th>
                      <th className="p-2">Karta</th>
                      <th className="p-2">Właściciel</th>
                      <th className="p-2">Ceny</th>
                      <th className="p-2">Opis</th>
                      <th className="p-2 text-right">Zdjęcia</th>
                      <th className="p-2 text-right">Rozmiary</th>
                      <th className="p-2 text-right" title="Pozycje przetargów wskazujące kartę">
                        Przetargi
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    {cards.map((c) => {
                      const checked = c.id === keepId
                      const locked = preview.keep_locked && c.id !== preview.suggested_keep_id
                      return (
                        <tr key={c.id} className={`border-b align-top ${checked ? 'bg-blue-50/40' : ''}`}>
                          <td className="p-2">
                            <label
                              className={`flex items-center gap-1 ${locked ? 'cursor-not-allowed' : 'cursor-pointer'}`}
                              title={
                                locked
                                  ? `Zostać może tylko karta producenta #${preview.suggested_keep_id}`
                                  : 'Ta karta zostaje, pozostałe przejdą do niej'
                              }
                            >
                              <input
                                type="radio"
                                name="manual-merge-keep"
                                checked={checked}
                                disabled={busy || loading || locked}
                                onChange={() => {
                                  setNotice('')
                                  setSubmitErr('')
                                  void load(c.id)
                                }}
                              />
                              {checked ? (
                                <span className="text-[11px] font-medium text-blue-700">zostaje</span>
                              ) : keepId !== null ? (
                                <span className="text-[11px] text-slate-500">zniknie</span>
                              ) : null}
                            </label>
                          </td>
                          <td className="min-w-[16rem] max-w-[26rem] p-2">
                            <p className="flex flex-wrap items-baseline gap-x-2">
                              <Link
                                to={`/products/${c.id}`}
                                target="_blank"
                                rel="noopener"
                                className="font-mono text-[11px] text-blue-600 hover:underline"
                                title="Otwórz kartę w nowej karcie przeglądarki"
                              >
                                #{c.id}
                              </Link>
                              <span className="font-mono text-[11px] text-slate-700">{c.sku}</span>
                              {c.presta && (
                                <span
                                  className="rounded bg-emerald-100 px-1 text-[10px] font-medium text-emerald-800"
                                  title="Karta jest powiązana z produktem w Preście"
                                >
                                  Presta
                                </span>
                              )}
                            </p>
                            <p className="line-clamp-2 break-words text-slate-800" title={c.name}>
                              {c.name}
                            </p>
                            <p className="text-[11px] text-slate-500">{c.manufacturer ?? '—'}</p>
                          </td>
                          <td className="p-2">
                            {c.is_owner && (
                              <span className="inline-block rounded bg-emerald-100 px-1.5 py-px text-[11px] font-medium text-emerald-800">
                                karta producenta
                              </span>
                            )}
                            {c.owner_label ? (
                              <p className="mt-0.5 text-[11px] text-slate-600">{c.owner_label}</p>
                            ) : (
                              !c.is_owner && <span className="text-slate-400">—</span>
                            )}
                          </td>
                          <td className="p-2">
                            <p className="whitespace-nowrap text-slate-600">
                              Karta: <b className="tabular-nums">{priceText(c.purchase_price, c.currency)}</b>
                            </p>
                            {c.sources.length > 0 && (
                              <ul className="mt-0.5 space-y-px text-[11px] text-slate-600">
                                {c.sources.map((s) => (
                                  <li key={s.source_key}>
                                    {s.label}: <span className="tabular-nums">{priceText(s.purchase_price, s.currency)}</span>
                                  </li>
                                ))}
                              </ul>
                            )}
                          </td>
                          <td className="p-2">
                            {c.has_description ? (
                              <span className="text-emerald-700">tak</span>
                            ) : (
                              <span className="text-slate-400">nie</span>
                            )}
                          </td>
                          <td className="p-2 text-right tabular-nums">{countCell(c.images)}</td>
                          <td className="p-2 text-right tabular-nums">{countCell(c.size_rows)}</td>
                          <td className="p-2 text-right tabular-nums">{countCell(c.tender_items)}</td>
                        </tr>
                      )
                    })}
                  </tbody>
                </table>
                {loading && <p className="mt-1 text-[11px] text-slate-500">Liczę podgląd od nowa…</p>}
              </div>

              {blockers.length > 0 && (
                <div className="rounded border border-red-200 bg-red-50 px-3 py-2 text-red-800">
                  <p className="font-medium">Tych kart nie da się teraz połączyć:</p>
                  <ul className="mt-0.5 list-disc space-y-px pl-5">
                    {blockers.map((b, i) => (
                      <li key={i}>{b}</li>
                    ))}
                  </ul>
                </div>
              )}

              {preview.warnings.length > 0 && (
                <div className="rounded border border-amber-200 bg-amber-50 px-3 py-2 text-amber-900">
                  <p className="font-medium">Sprawdź przed połączeniem:</p>
                  <ul className="mt-0.5 list-disc space-y-px pl-5">
                    {preview.warnings.map((w, i) => (
                      <li key={`${w.code}:${i}`}>{w.text}</li>
                    ))}
                  </ul>
                  {needsConfirm && (
                    <label className="mt-1.5 flex items-start gap-2 font-medium text-amber-900">
                      <input
                        type="checkbox"
                        className="mt-0.5"
                        checked={brandConfirmed}
                        disabled={busy || loading}
                        onChange={(e) => setBrandConfirmHash(e.target.checked ? preview.plan_hash : null)}
                      />
                      <span>Wiem, łączę mimo innej marki.</span>
                    </label>
                  )}
                  {needsConfirm && staleConfirm && (
                    <p className="mt-0.5 text-[11px] text-amber-800">
                      Podgląd zmienił się od zaznaczenia — sprawdź go i potwierdź jeszcze raz.
                    </p>
                  )}
                </div>
              )}

              {keep && drops.length > 0 && blockers.length === 0 && (
                <div className="rounded border border-slate-200 bg-white px-3 py-2 text-slate-700">
                  <p className="font-medium text-slate-800">Co się stanie</p>
                  <ul className="mt-0.5 list-disc space-y-px pl-5">
                    <li>
                      Zostaje karta <b className="font-mono">{keep.sku}</b> (#{keep.id}).
                    </li>
                    <li>
                      {drops.length === 1 ? 'Zniknie karta' : 'Znikną karty'}{' '}
                      <b className="font-mono">{drops.map((c) => c.sku).join(', ')}</b>
                      {moves.length > 0 ? (
                        <>
                          {' '}
                          — do karty {keep.sku} przejdą: {moves.join(', ')}.
                        </>
                      ) : (
                        <> — nie mają cen, powiązań ani zdjęć do przeniesienia.</>
                      )}
                    </li>
                    {preview.moves.b2b_links > 0 && (
                      <li>Kolejne pobrania cenników B2B tych pozycji trafią do karty {keep.sku}.</li>
                    )}
                    <li>Przed zmianą zapisuje się kopia zapasowa kart.</li>
                  </ul>
                </div>
              )}

              <div>
                <label className="font-medium text-slate-800" htmlFor="manual-merge-note">
                  Notatka (niewymagana)
                </label>
                <textarea
                  id="manual-merge-note"
                  value={note}
                  maxLength={NOTE_MAX}
                  rows={2}
                  disabled={busy}
                  onChange={(e) => setNote(e.target.value)}
                  placeholder="np. ten sam wyrób — sprawdzone na stronie producenta"
                  className="mt-1 block w-full rounded border border-slate-300 bg-white px-2 py-1 text-xs"
                />
                <p className="mt-0.5 text-right text-[11px] tabular-nums text-slate-500">
                  {note.length}/{NOTE_MAX}
                </p>
              </div>
            </>
          )}
        </div>

        <div className="flex flex-wrap items-center justify-end gap-2 border-t border-slate-200 px-4 py-3">
          {submitErr ? (
            <p className="mr-auto text-xs text-red-700">{submitErr}</p>
          ) : (
            preview !== null &&
            !busy &&
            !loading &&
            !canSubmit && (
              <p className="mr-auto text-xs text-slate-500">
                {blockers.length > 0
                  ? 'Połączenie zablokowane — powody w czerwonej ramce.'
                  : keep === null
                    ? 'Wybierz kartę, która zostaje.'
                    : ready
                      ? 'Zaznacz potwierdzenie innej marki, żeby połączyć.'
                      : 'Tych kart nie da się teraz połączyć.'}
              </p>
            )
          )}
          <button
            type="button"
            onClick={onClose}
            disabled={busy}
            className="rounded border border-slate-300 px-3 py-1.5 text-xs hover:bg-slate-50 disabled:opacity-50"
          >
            Anuluj
          </button>
          <button
            type="button"
            disabled={!canSubmit}
            onClick={() => void submit()}
            className="rounded bg-blue-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-blue-700 disabled:opacity-40"
          >
            {busy ? 'Łączę…' : 'Połącz'}
          </button>
        </div>
      </div>
    </div>
  )
}

function countCell(n: number) {
  return n > 0 ? n.toLocaleString('pl-PL') : <span className="text-slate-400">0</span>
}
