import { useEffect, useState } from 'react'
import { api } from '../lib/api'
import { plural } from '../lib/plural'
import { skipImportNote, type DeleteProductsResult } from '../lib/productDeletion'

type Props = {
  /** Karty do usunięcia — okno montowane dopiero po kliknięciu „Usuń”. */
  productIds: number[]
  /** Przy jednej karcie: SKU i nazwa do treści pytania. */
  card?: { sku: string; name: string } | null
  /**
   * Lista filtrowana po koncie dostawcy (Cenniki → karty konta B2B): karta połączona z innym źródłem traci tylko
   * pozycje tego konta, karta tylko z tego konta jest usuwana w całości.
   */
  b2bAccount?: { id: number; label: string } | null
  /** Anulowanie (przed usunięciem). */
  onClose: () => void
  /**
   * Po udanym usunięciu. Bez pomijania — od razu; z pomijaniem — gdy człowiek zamknie okno z wynikiem
   * (ile pozycji pominięto albo że karta nie miała czego pomijać).
   */
  onDeleted: (result: DeleteProductsResult, skipImport: boolean) => void
}

/**
 * Potwierdzenie usunięcia kart z katalogu z opcją „Pomijaj przy kolejnych importach”. Okno samo wysyła
 * żądanie; po usunięciu z pomijaniem pokazuje wynik (liczbę pominiętych pozycji), zanim wróci do strony.
 */
export function DeleteProductsDialog({ productIds, card, b2bAccount, onClose, onDeleted }: Props) {
  // z listy dostawcy bez pomijania pozycje wracają przy najbliższej synchronizacji — domyślnie zaznaczone
  const [skipImport, setSkipImport] = useState(Boolean(b2bAccount))
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const [done, setDone] = useState<DeleteProductsResult | null>(null)

  const single = productIds.length === 1

  function close() {
    if (busy) return
    if (done) onDeleted(done, true)
    else onClose()
  }

  useEffect(() => {
    function onKey(e: KeyboardEvent) {
      if (e.key !== 'Escape' || busy) return
      if (done) onDeleted(done, true)
      else onClose()
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [busy, done, onClose, onDeleted])

  async function submit() {
    if (busy || done || productIds.length === 0) return
    setBusy(true)
    setErr('')
    try {
      const scope = b2bAccount ? { b2b_account: b2bAccount.id } : {}
      const res = await api<DeleteProductsResult>(
        single ? `/products/${productIds[0]}` : '/products/delete',
        single
          ? { method: 'DELETE', body: JSON.stringify({ skip_import: skipImport, ...scope }) }
          : { method: 'POST', body: JSON.stringify({ product_ids: productIds, skip_import: skipImport, ...scope }) },
      )
      if (skipImport) {
        setDone(res)
      } else {
        onDeleted(res, false)
      }
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Błąd usuwania produktu')
    } finally {
      setBusy(false)
    }
  }

  const n = productIds.length
  const title = b2bAccount
    ? single
      ? `Usunąć kartę z cennika ${b2bAccount.label}?`
      : `Usunąć ${n} ${plural(n, 'kartę', 'karty', 'kart')} z cennika ${b2bAccount.label}?`
    : single
      ? 'Usunąć kartę z katalogu?'
      : `Usunąć ${n} ${plural(n, 'kartę', 'karty', 'kart')} z katalogu?`
  const note = done ? skipImportNote(done, true) : null

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
      role="dialog"
      aria-modal="true"
      aria-labelledby="delete-products-title"
      onClick={close}
    >
      <div
        className="flex w-full max-w-lg flex-col overflow-hidden rounded-xl bg-white shadow-lg"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="flex items-start justify-between gap-3 border-b border-slate-200 px-4 py-3">
          <p id="delete-products-title" className="text-sm font-semibold text-slate-900">
            {done ? (done.deleted === 0 && (done.detached ?? 0) > 0 ? 'Odpięto' : 'Usunięto') : title}
          </p>
          <button
            type="button"
            onClick={close}
            disabled={busy}
            aria-label="Zamknij"
            className="rounded px-2 py-0.5 text-lg leading-none text-slate-500 hover:bg-slate-100 hover:text-slate-900 disabled:opacity-50"
          >
            ×
          </button>
        </div>

        <div className="space-y-3 px-4 py-3 text-sm">
          {done ? (
            <>
              <p className="text-slate-800">{done.message}</p>
              {note && (
                <p
                  className={`rounded px-3 py-2 text-xs ${
                    done.positions_excluded > 0
                      ? 'bg-green-50 text-green-800'
                      : 'border border-amber-200 bg-amber-50 font-medium text-amber-900'
                  }`}
                >
                  {note}
                </p>
              )}
            </>
          ) : (
            <>
              {single && card ? (
                <p className="text-slate-800">
                  <span className="font-mono text-xs text-slate-600">{card.sku}</span>{' '}
                  <span className="font-medium">{card.name}</span>
                </p>
              ) : (
                <p className="text-slate-800">
                  Zaznaczone: {n} {plural(n, 'karta', 'karty', 'kart')}.
                </p>
              )}
              {b2bAccount && (
                <p className="rounded border border-indigo-200 bg-indigo-50 px-3 py-2 text-xs text-indigo-900">
                  Karta połączona z innym źródłem (np. cennikiem producenta) zostaje — odpinane są tylko pozycje{' '}
                  {b2bAccount.label}: powiązanie, cena i tabelka sklepu tego dostawcy. Karta tylko z {b2bAccount.label}{' '}
                  jest usuwana w całości.
                </p>
              )}
              <p className="font-medium text-red-700">Tej operacji nie można cofnąć.</p>
              <label className="flex cursor-pointer items-start gap-2 rounded border border-slate-200 bg-slate-50 px-3 py-2">
                <input
                  type="checkbox"
                  className="mt-0.5"
                  checked={skipImport}
                  disabled={busy}
                  onChange={(e) => setSkipImport(e.target.checked)}
                />
                <span>
                  <span className="text-slate-800">
                    {b2bAccount
                      ? `Pomijaj przy kolejnych synchronizacjach ${b2bAccount.label}`
                      : 'Pomijaj przy kolejnych importach (cennik z pliku, B2B)'}
                  </span>
                  <span className="mt-0.5 block text-xs text-slate-500">
                    {b2bAccount
                      ? `Blokowane są tylko pozycje ${b2bAccount.label} — inne źródła karty działają dalej.`
                      : `Ponowny import nie założy ${single ? 'tej karty' : 'tych kart'} od nowa.`}{' '}
                    Przywrócić można w Cenniki → Usunięte z pominięciem.
                  </span>
                </span>
              </label>
              {b2bAccount && !skipImport && (
                <p className="rounded border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">
                  Bez pomijania pozycje {b2bAccount.label} wrócą przy najbliższej synchronizacji — na tę samą kartę albo
                  jako nowa karta.
                </p>
              )}
              {err && <p className="rounded bg-red-50 px-3 py-2 text-xs text-red-700">{err}</p>}
            </>
          )}
        </div>

        <div className="flex flex-wrap items-center justify-end gap-2 border-t border-slate-200 px-4 py-3">
          {done ? (
            <button
              type="button"
              onClick={close}
              className="rounded bg-blue-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-blue-700"
            >
              Zamknij
            </button>
          ) : (
            <>
              <button
                type="button"
                onClick={close}
                disabled={busy}
                className="rounded border border-slate-300 px-3 py-1.5 text-xs hover:bg-slate-50 disabled:opacity-50"
              >
                Anuluj
              </button>
              <button
                type="button"
                onClick={() => void submit()}
                disabled={busy}
                className="rounded bg-red-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-red-700 disabled:opacity-40"
              >
                {busy ? 'Usuwam…' : 'Usuń'}
              </button>
            </>
          )}
        </div>
      </div>
    </div>
  )
}
