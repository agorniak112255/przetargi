const STOP = new Set([
  'do', 'pracy', 'z', 'na', 'oraz', 'dla', 'the', 'and', 'with', 'od', 'przy',
  'bez', 'jak', 'lub', 'czy', 'jest', 'sie', 'się', 'pod', 'nad', 'typ',
  'rodzaju', 'przed', 'formie', 'celu', 'produkt', 'lub', 'albo', 'czyli',
])

export type HighlightKind = 'text' | 'query' | 'find' | 'both'

export type HighlightSeg = {
  text: string
  kind: HighlightKind
  findIndex?: number
}

export function queryHighlightTokens(query: string): string[] {
  const parts = query.split(/[\s,;|/·•+]+/u)
  const out: string[] = []
  const seen = new Set<string>()
  for (const raw of parts) {
    const token = raw.replace(/^[^\p{L}\p{N}]+|[^\p{L}\p{N}]+$/gu, '').trim()
    if (token.length < 3) continue
    const key = token.toLocaleLowerCase('pl')
    if (STOP.has(key) || seen.has(key)) continue
    seen.add(key)
    out.push(token)
  }
  return out.sort((a, b) => b.length - a.length)
}

export function findAllOffsets(hay: string, needle: string): Array<[number, number]> {
  const n = needle.trim()
  if (n.length < 2) return []
  const h = hay.toLocaleLowerCase('pl')
  const q = n.toLocaleLowerCase('pl')
  const out: Array<[number, number]> = []
  let i = 0
  while (i <= h.length - q.length) {
    const p = h.indexOf(q, i)
    if (p < 0) break
    out.push([p, p + q.length])
    i = p + q.length
  }
  return out
}

export function highlightSegments(
  text: string,
  queryTokens: string[],
  findPhrase: string,
): HighlightSeg[] {
  if (text === '') return []
  const queryRanges = queryTokens.flatMap((t) => findAllOffsets(text, t))
  const findRanges = findAllOffsets(text, findPhrase)
  const cuts = new Set<number>([0, text.length])
  for (const [a, b] of queryRanges) {
    cuts.add(a)
    cuts.add(b)
  }
  for (const [a, b] of findRanges) {
    cuts.add(a)
    cuts.add(b)
  }
  const points = [...cuts].sort((a, b) => a - b)
  const segs: HighlightSeg[] = []
  for (let i = 0; i < points.length - 1; i++) {
    const start = points[i]
    const end = points[i + 1]
    if (end <= start) continue
    const inQuery = queryRanges.some(([a, b]) => start >= a && end <= b)
    const findAt = findRanges.findIndex(([a, b]) => start >= a && end <= b)
    const kind: HighlightKind = inQuery && findAt >= 0
      ? 'both'
      : findAt >= 0
        ? 'find'
        : inQuery
          ? 'query'
          : 'text'
    segs.push({
      text: text.slice(start, end),
      kind,
      findIndex: findAt >= 0 ? findAt : undefined,
    })
  }
  return segs
}

export function descriptionProse(text: string | null | undefined): string {
  if (!text) return ''
  const cut = text.search(/\n\n(?:Specyfikacja|Cechy|Materiały|Normy|Certyfikaty|Zastosowanie)\s*:/)
  let body = cut >= 0 ? text.slice(0, cut).trim() : text.trim()
  body = body.replace(/([^\n])\s+(\d{1,2})\)\s+/g, '$1\n$2) ')
  return body
}
