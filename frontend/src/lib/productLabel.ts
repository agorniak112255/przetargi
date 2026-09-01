/** Słabe nazwy z cenników EN (np. Uncoated, KW Palm Coated). */
const WEAK_EXACT =
  /^(uncoated|coated|liner|glove|gloves|nitrile|latex|foam|pu|pvc|nylon|hppe|cut|ultra|dry|wet|winter|summer|nitrile foam|pu coated|latex coated)$/i

const WEAK_TOKEN =
  /\b(uncoated|coated|liner|gloves?|nitrile|latex|foam|palm|micro-?foam|hppe|kw)\b/i

type Named = {
  name?: string | null
  description?: string | null
  manufacturer?: string | null
}

export function isWeakProductName(name: string, description?: string | null): boolean {
  const n = name.trim()
  if (n === '') return true
  if (WEAK_EXACT.test(n)) return true

  const desc = (description ?? '').trim()
  if (desc.length < 20) return false

  const hasPlDesc = /[ąćęłńóśźż]/i.test(desc)
  const hasPlName = /[ąćęłńóśźż]/i.test(n)
  if (hasPlName || !hasPlDesc) return false

  // pojedyncze angielskie słowo (Uncoated)
  if (/^[a-z][a-z\-]*$/i.test(n) && n.length <= 20) return true

  // złożenia EN z cennika: „KW Palm Coated”, „KW 3/4 Coated - Yellow”
  if (WEAK_TOKEN.test(n) && /^[a-z0-9][a-z0-9\s\-\/_.%°]*$/i.test(n) && n.length <= 48) {
    return true
  }

  return false
}

function polishShortFromDescription(desc: string, maxLen: number): string {
  const typ = desc.match(/(?:^|\n)\s*-\s*Typ:\s*(.+)/i)?.[1]?.trim()
  if (typ) {
    const t = typ.replace(/\s+/g, ' ')
    return t.length > maxLen ? `${t.slice(0, maxLen - 1).trimEnd()}…` : t
  }

  const firstLine = desc.split(/\n+/)[0]?.trim() ?? ''
  const beforeTo = firstLine.match(/^(.+?)\s+to\s+/i)?.[1]?.trim()
  const beforeZ = firstLine.match(/^(Rękawic\w+[^.]*?)(?:\s+z\s+)/i)?.[1]?.trim()
  let out = (beforeTo || beforeZ || firstLine.split(/(?<=[.!?])\s+/)[0] || firstLine).replace(
    /\s+/g,
    ' ',
  )
  // usuń zbędny (SKU …) z nagłówka
  out = out.replace(/\s*\(SKU\s+[^)]+\)\s*/gi, ' ').replace(/\s+/g, ' ').trim()
  if (out.length > maxLen) {
    out = `${out.slice(0, maxLen - 1).trimEnd()}…`
  }
  return out
}

/** Nazwa do UI: preferuj polski opis, gdy `name` z cennika jest angielskim skrótem. */
export function productDisplayName(p: Named, maxLen = 72): string {
  const name = (p.name ?? '').trim()
  const desc = (p.description ?? '').trim()

  if (!isWeakProductName(name, desc)) {
    return name || (p.manufacturer ?? '').trim() || '—'
  }

  return polishShortFromDescription(desc, maxLen) || name || '—'
}

export function productSelectLabel(p: { sku: string } & Named): string {
  return `${p.sku} · ${productDisplayName(p, 56)}`
}

/** Współczynnik narzutu z procentu marży przetargu (18 → 1.18). */
export function offerMarkupFactor(percent: number | null | undefined, fallback = 1.18): number {
  const p = Number(percent)
  if (!Number.isFinite(p)) return fallback
  const factor = 1 + p / 100
  return factor > 0 ? factor : fallback
}

/** Sugerowana cena oferty = zakup × narzut (+18% domyślnie, jak config/pricing.php). */
export function suggestedOfferPrice(
  purchase: number | null | undefined,
  markup: number | null | undefined = 1.18,
): number | null {
  if (purchase == null || Number.isNaN(Number(purchase)) || Number(purchase) <= 0) return null
  const factor = markup == null || Number.isNaN(Number(markup)) || Number(markup) <= 0 ? 1.18 : Number(markup)
  return Math.round(Number(purchase) * factor * 100) / 100
}
