import { findBrandOffsets, highlightSegments, type HighlightKind } from '../lib/descriptionHighlight'
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

export function HighlightedDescription({
  text,
  queryTokens,
  findPhrase,
  activeFindIndex,
  findIndexOffset = 0,
  className = 'whitespace-pre-wrap text-[15px] leading-relaxed text-slate-800',
  brand = null,
}: Props) {
  const segs = highlightSegments(text, queryTokens, findPhrase)

  return (
    <p className={className}>
      {segs.map((seg, i) => {
        if (seg.kind === 'text') {
          return <span key={i}>{withBrandMarks(seg.text, brand)}</span>
        }
        const findIndex = seg.findIndex == null ? undefined : seg.findIndex + findIndexOffset
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
      })}
    </p>
  )
}
