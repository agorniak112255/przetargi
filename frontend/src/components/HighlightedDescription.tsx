import { highlightSegments, type HighlightKind } from '../lib/descriptionHighlight'
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
  if (!m || m[3] === '') {
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
}

export function HighlightedDescription({
  text,
  queryTokens,
  findPhrase,
  activeFindIndex,
}: Props) {
  const segs = highlightSegments(text, queryTokens, findPhrase)

  return (
    <p className="whitespace-pre-wrap text-[15px] leading-relaxed text-slate-800">
      {segs.map((seg, i) => {
        if (seg.kind === 'text') {
          return <span key={i}>{withBoldSpecLabel(seg.text)}</span>
        }
        const isActive = seg.findIndex === activeFindIndex
        return (
          <mark
            key={i}
            data-find-hit={seg.findIndex}
            className={`${KIND_CLASS[seg.kind]} ${isActive ? 'bg-sky-400 text-sky-950 ring-2 ring-sky-600' : ''}`}
          >
            {seg.text}
          </mark>
        )
      })}
    </p>
  )
}
