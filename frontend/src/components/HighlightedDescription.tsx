import { highlightSegments, type HighlightKind } from '../lib/descriptionHighlight'

const KIND_CLASS: Record<HighlightKind, string> = {
  text: '',
  query: 'rounded-sm bg-amber-200 px-0.5 text-amber-950',
  find: 'rounded-sm bg-sky-200 px-0.5 text-sky-950',
  both: 'rounded-sm bg-orange-300 px-0.5 font-medium text-orange-950',
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
          return <span key={i}>{seg.text}</span>
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
