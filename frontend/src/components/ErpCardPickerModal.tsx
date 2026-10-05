import { useCallback, useMemo } from 'react'
import { type ErpAdminItem } from '../lib/api'
import { queryHighlightTokens } from '../lib/descriptionHighlight'
import { erpQty, erpUnitLabel } from '../lib/erpStock'
import { formatDate } from '../lib/priceChange'
import { CardPickerModal, type CardPickOption } from './CardPickerModal'

const LINK_NOTE: Record<string, string> = {
  confirmed: 'obecna karta (potwierdzona)',
  auto: 'połączona automatycznie',
  suggested: 'propozycja automatu',
}

/** Słowa z nazwy towaru XL w kolejności z nazwy (queryHighlightTokens układa je po długości) + kod z nazwy XL. */
function nameWords(item: ErpAdminItem): string[] {
  const lower = item.name.toLocaleLowerCase('pl')
  const words = queryHighlightTokens(item.name).sort(
    (a, b) => lower.indexOf(a.toLocaleLowerCase('pl')) - lower.indexOf(b.toLocaleLowerCase('pl')),
  )
  const code = item.match_value?.trim()
  if (code && !words.some((w) => w.toLocaleLowerCase('pl') === code.toLocaleLowerCase('pl'))) words.push(code)
  return words
}

/**
 * Okno ręcznego łączenia towaru ERP XL z kartą (ekran „Powiązania z ERP XL”) — wspólny CardPickerModal z nagłówkiem
 * towaru XL. Chipy ze słowami nazwy XL pokazują, ile kart trafia każde słowo osobno (cała nazwa XL jako jeden ciąg nie
 * trafia żadnej karty). Przy pustej frazie lista kart, które towar już ma, i propozycji automatu.
 */
export function ErpCardPickerModal({
  item,
  onClose,
  onLink,
  error,
}: {
  item: ErpAdminItem
  onClose: () => void
  onLink: (productId: number, sku: string) => Promise<boolean>
  error: string
}) {
  const words = useMemo(() => nameWords(item), [item])
  const confirmed = item.links.find((l) => l.status === 'confirmed')
  const rejectedIds = useMemo(
    () => new Set(item.links.filter((l) => l.status === 'rejected' && l.product).map((l) => l.product!.id)),
    [item.links],
  )
  // pusta fraza: karty, które towar już ma albo które zaproponował automat
  const presetOptions = useMemo<CardPickOption[]>(
    () =>
      item.links
        .filter((l) => l.product && l.status !== 'rejected')
        .map((l) => ({
          key: `l${l.id}`,
          pick: { id: l.product!.id, sku: l.product!.sku, name: l.product!.name, manufacturer: l.product!.manufacturer },
          product: null,
          note: LINK_NOTE[l.status] ?? null,
        })),
    [item.links],
  )
  const noteFor = useCallback(
    (productId: number) => {
      const link = item.links.find((l) => l.product?.id === productId && l.status !== 'rejected')
      return link ? (LINK_NOTE[link.status] ?? null) : null
    },
    [item.links],
  )

  return (
    <CardPickerModal
      title="Połącz towar XL z kartą"
      header={
        <>
          <p className="mt-0.5 text-sm">
            <span className="font-mono font-semibold text-slate-900">{item.code}</span>
            <span className="text-slate-400"> — </span>
            <span className="font-medium text-slate-900">{item.name}</span>
          </p>
          {item.name1 && <p className="font-mono text-[11px] text-slate-500">Nazwa1: {item.name1}</p>}
          <p className="mt-1 flex flex-wrap gap-x-4 gap-y-0.5 text-[11px] text-slate-600">
            <span>
              Stan HANDEL:{' '}
              <span className="font-medium text-slate-800">
                {erpQty(item.stock_trade)}
                {erpUnitLabel(item.unit)}
              </span>
            </span>
            {item.last_purchase_at && (
              <span>
                Ostatni zakup: {formatDate(item.last_purchase_at)}
                {item.last_supplier && <> · {item.last_supplier}</>}
              </span>
            )}
            {item.last_sale_at && <span>Ostatnia sprzedaż: {formatDate(item.last_sale_at)}</span>}
            {item.match_value && (
              <span>
                Kod z nazwy XL: <span className="font-mono text-slate-800">{item.match_value}</span>
              </span>
            )}
          </p>
        </>
      }
      words={words}
      wordsLabel="Słowa z nazwy XL:"
      presetOptions={presetOptions}
      presetHint="Karty tego towaru i propozycje automatu. Wpisz frazę albo kliknij słowo z nazwy XL, żeby szukać w katalogu."
      emptyHint="Wpisz co najmniej 2 znaki albo kliknij słowo z nazwy XL."
      noteFor={noteFor}
      badgesFor={(productId) =>
        rejectedIds.has(productId) ? (
          <span
            className="rounded bg-amber-50 px-1.5 text-[10px] text-amber-800"
            title="Ktoś odłączył albo odrzucił tę kartę dla tego towaru"
          >
            odrzucona wcześniej
          </span>
        ) : null
      }
      notice={
        confirmed && (
          <p className="rounded bg-amber-50 px-2 py-1 text-amber-800">
            Ten towar ma już potwierdzoną kartę {confirmed.product?.sku ?? `#${confirmed.id}`}.
          </p>
        )
      }
      error={error}
      placeholder="Wpisz SKU, model, producenta albo kod XL — każde kolejne słowo zawęża listę"
      ariaLabel="Szukaj karty po SKU, nazwie, producencie albo kodzie XL"
      footerHint="Połączenie zapisze się jako potwierdzone ręcznie. ↑ ↓ wybór · dwuklik = pełny podgląd · Ctrl+Enter = połącz"
      submitLabel={(pick, busy) => (busy ? 'Łączę…' : pick ? `Połącz z ${pick.sku}` : 'Wybierz kartę z listy')}
      previewQuery={item.name}
      onClose={onClose}
      onPick={onLink}
    />
  )
}
