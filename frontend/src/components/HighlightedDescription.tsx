import { countFindHits, findBrandOffsets, highlightSegments, type HighlightKind } from '../lib/descriptionHighlight'
import type { ReactNode } from 'react'

const KIND_CLASS: Record<HighlightKind, string> = {
  text: '',
  query: 'rounded-sm bg-amber-200 px-0.5 text-amber-950',
  find: 'rounded-sm bg-sky-200 px-0.5 font-bold text-sky-950',
  both: 'rounded-sm bg-orange-300 px-0.5 font-bold text-orange-950',
}

const SPEC_LABEL = /^((?:•\s*)?[^:\n]{2,48}:)(\s*)([\s\S]*)$/u

function boldOne(text: string): ReactNode {
  const m = text.match(SPEC_LABEL)
  if (!m) {
    return text
  }
  return (
    <>
      <strong>{m[1]}</strong>
      {m[2]}
      {m[3]}
    </>
  )
}

function withBoldSpecLabel(text: string): ReactNode {
  if (!text.includes('\n')) {
    return boldOne(text)
  }
  return text.split('\n').map((line, i) => (
    <span key={i}>
      {i > 0 ? '\n' : null}
      {boldOne(line)}
    </span>
  ))
}

type Props = {
  text: string
  queryTokens: string[]
  findPhrase: string
  activeFindIndex: number
  findIndexOffset?: number
  className?: string
  brand?: string | null
}

function withBrandMarks(text: string, brand?: string | null): ReactNode {
  const ranges = brand ? findBrandOffsets(text, brand) : []
  if (ranges.length === 0) {
    return withBoldSpecLabel(text)
  }
  const nodes: ReactNode[] = []
  let last = 0
  ranges.forEach(([start, end], i) => {
    if (start > last) {
      nodes.push(<span key={`t-${i}`}>{withBoldSpecLabel(text.slice(last, start))}</span>)
    }
    nodes.push(
      <span key={`b-${i}`} className="rounded-sm bg-teal-100 px-0.5 font-bold text-teal-900">
        {text.slice(start, end)}
      </span>,
    )
    last = end
  })
  if (last < text.length) {
    nodes.push(<span key="t-end">{withBoldSpecLabel(text.slice(last))}</span>)
  }
  return nodes
}

/** Wiersz tabeli w opisie: kolumny rozdzielone „|” (tak podaje je import B2B). */
const TABLE_ROW = /\s\|\s/

type DescriptionBlock = { kind: 'text'; text: string } | { kind: 'table'; rows: string[][] }

/** Opis na bloki: ciągi linii z „|” stają się tabelą, reszta zostaje tekstem. */
export function splitDescriptionBlocks(text: string): DescriptionBlock[] {
  const blocks: DescriptionBlock[] = []
  let textLines: string[] = []
  let rows: string[][] = []

  const flushText = () => {
    if (textLines.length > 0) {
      blocks.push({ kind: 'text', text: textLines.join('\n') })
      textLines = []
    }
  }
  const flushRows = () => {
    if (rows.length >= 2) {
      blocks.push({ kind: 'table', rows })
    } else if (rows.length === 1) {
      textLines.push(rows[0].join(' | '))
    }
    rows = []
  }

  for (const line of text.split('\n')) {
    if (TABLE_ROW.test(line)) {
      flushText()
      rows.push(line.split(TABLE_ROW).map((cell) => cell.trim()))
      continue
    }
    flushRows()
    textLines.push(line)
  }
  flushRows()
  flushText()

  return blocks
}

export function HighlightedDescription({
  text,
  queryTokens,
  findPhrase,
  activeFindIndex,
  findIndexOffset = 0,
  className = 'max-w-full whitespace-pre-wrap break-words [overflow-wrap:anywhere] text-[15px] leading-relaxed text-slate-800',
  brand = null,
}: Props) {
  const marks = (value: string, offset: number) =>
    highlightSegments(value, queryTokens, findPhrase).map((seg, i) => {
      if (seg.kind === 'text') {
        return <span key={i}>{withBrandMarks(seg.text, brand)}</span>
      }
      const findIndex = seg.findIndex == null ? undefined : seg.findIndex + offset
      const isActive = findIndex === activeFindIndex
      return (
        <mark
          key={i}
          data-find-hit={findIndex}
          className={`${KIND_CLASS[seg.kind]} ${isActive ? 'bg-sky-400 text-sky-950 ring-2 ring-sky-600' : ''}`}
        >
          {seg.text}
        </mark>
      )
    })

  const blocks = splitDescriptionBlocks(text)
  if (blocks.length === 1 && blocks[0].kind === 'text') {
    return <p className={className}>{marks(text, findIndexOffset)}</p>
  }

  // Numeracja trafień wyszukiwania biegnie przez cały opis, więc każdy blok dostaje przesunięcie
  // równe liczbie trafień w blokach przed nim.
  let offset = findIndexOffset

  return (
    <div className={className}>
      {blocks.map((block, index) => {
        if (block.kind === 'text') {
          const at = offset
          offset += countFindHits(block.text, findPhrase)

          return <p key={index}>{marks(block.text, at)}</p>
        }

        const [head, ...body] = block.rows

        return (
          <table key={index} className="my-1 w-full table-auto border-collapse text-[13px]">
            <thead>
              <tr>
                {head.map((cell, i) => {
                  const at = offset
                  offset += countFindHits(cell, findPhrase)

                  return (
                    <th
                      key={i}
                      className="border border-slate-200 bg-slate-50 px-2 py-1 text-left font-semibold text-slate-700"
                    >
                      {marks(cell, at)}
                    </th>
                  )
                })}
              </tr>
            </thead>
            <tbody>
              {body.map((row, r) => (
                <tr key={r}>
                  {row.map((cell, i) => {
                    const at = offset
                    offset += countFindHits(cell, findPhrase)

                    return (
                      <td key={i} className="border border-slate-200 px-2 py-1 align-top text-slate-700">
                        {marks(cell, at)}
                      </td>
                    )
                  })}
                </tr>
              ))}
            </tbody>
          </table>
        )
      })}
    </div>
  )
}
