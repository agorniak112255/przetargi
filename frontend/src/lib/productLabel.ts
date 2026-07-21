/** Słabe nazwy z cenników EN (np. Uncoated) — zamiast nich pokazujemy skrót opisu PL. */
const WEAK_NAME =
  /^(uncoated|coated|liner|glove|gloves|nitrile|latex|foam|pu|pvc|nylon|hppe|cut|ultra|dry|wet|winter|summer|nitrile foam|pu coated|latex coated)$/i

type Named = {
  name?: string | null
  description?: string | null
  manufacturer?: string | null
}

export function isWeakProductName(name: string, description?: string | null): boolean {
  const n = name.trim()
  if (n === '') return true
  if (WEAK_NAME.test(n)) return true

  const desc = (description ?? '').trim()
  if (desc.length < 20) return false

  const hasPlDesc = /[ąćęłńóśźż]/i.test(desc)
  const hasPlName = /[ąćęłńóśźż]/i.test(n)
  if (hasPlName || !hasPlDesc) return false

  // pojedyncze angielskie słowo z cennika (np. Uncoated)
  return /^[a-z][a-z\-]*$/i.test(n) && n.length <= 20
}

function polishShortFromDescription(desc: string, maxLen: number): string {
  const typ = desc.match(/(?:^|\n)\s*-\s*Typ:\s*(.+)/i)?.[1]?.trim()
  if (typ) {
    const t = typ.replace(/\s+/g, ' ')
    return t.length > maxLen ? `${t.slice(0, maxLen - 1).trimEnd()}…` : t
  }

  const firstLine = desc.split(/\n+/)[0]?.trim() ?? ''
  // „Rękawica … 58-917 to …” → część przed „to”
  const beforeTo = firstLine.match(/^(.+?)\s+to\s+/i)?.[1]?.trim()
  let out = (beforeTo || firstLine.split(/(?<=[.!?])\s+/)[0] || firstLine).replace(/\s+/g, ' ')
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
