import type { DescriptionLayoutBlock, Product } from '../lib/api'
import { countFindHits } from '../lib/descriptionHighlight'
import {
  EMPHASIS_CLASS,
  layoutPlainText,
  layoutSections,
  type LayoutSection,
} from '../lib/descriptionLayout'
import { HighlightedDescription } from './HighlightedDescription'

type Props = {
  product: Product
  blocks?: DescriptionLayoutBlock[]
  queryTokens?: string[]
  findPhrase?: string
  activeFindIndex?: number
  compact?: boolean
}

function blockClass(emphasis: string, compact: boolean): string {
  const extra = EMPHASIS_CLASS[emphasis] ?? ''
  return `${compact ? 'mb-2' : 'mb-3'} ${extra}`.trim()
}

function titleClass(compact: boolean): string {
  return compact ? 'mb-1 text-[11px] font-semibold text-slate-700' : 'mb-1 text-xs font-semibold text-slate-700'
}

export function DescriptionLayoutView({
  product,
  blocks,
  queryTokens = [],
  findPhrase = '',
  activeFindIndex = 0,
  compact = false,
}: Props) {
  const sections = layoutSections(product, blocks)
  if (sections.length === 0) {
    return <p className="text-sm text-slate-500">Brak opisu w karcie.</p>
  }

  let findOffset = 0

  return (
    <div>
      {sections.map((section) => {
        const node = (
          <SectionView
            key={section.id}
            section={section}
            queryTokens={queryTokens}
            findPhrase={findPhrase}
            activeFindIndex={activeFindIndex}
            findIndexOffset={findOffset}
            compact={compact}
          />
        )
        findOffset += sectionHitCount(section, findPhrase)
        return node
      })}
    </div>
  )
}

export function descriptionSearchText(product: Product, blocks?: DescriptionLayoutBlock[]): string {
  return layoutPlainText(layoutSections(product, blocks))
}

function sectionHitCount(section: LayoutSection, findPhrase: string): number {
  if (section.kind === 'prose') return countFindHits(section.text, findPhrase)
  if (section.kind === 'attributes') {
    return section.extra ? countFindHits(`Normy: ${section.extra}`, findPhrase) : 0
  }
  if (section.kind === 'list') {
    return section.items.reduce((sum, item) => sum + countFindHits(item, findPhrase), 0)
  }
  return 0
}

function SectionView({
  section,
  queryTokens,
  findPhrase,
  activeFindIndex,
  findIndexOffset,
  compact,
}: {
  section: LayoutSection
  queryTokens: string[]
  findPhrase: string
  activeFindIndex: number
  findIndexOffset: number
  compact: boolean
}) {
  const wrap = blockClass(section.emphasis, compact)
  const highlight = {
    queryTokens,
    findPhrase,
    activeFindIndex,
    findIndexOffset,
  }

  if (section.kind === 'prose') {
    return (
      <div className={wrap}>
        <HighlightedDescription
          text={section.text}
          className={compact ? 'whitespace-pre-wrap text-sm text-slate-700' : undefined}
          {...highlight}
        />
      </div>
    )
  }

  if (section.kind === 'attributes') {
    return (
      <div className={wrap || 'mb-3 rounded border border-slate-100 bg-slate-50 px-3 py-2'}>
        <p className={titleClass(compact)}>{section.title}</p>
        <dl className="grid grid-cols-2 gap-x-3 gap-y-1 text-[11px] text-slate-600 sm:grid-cols-3">
          {section.pairs.map((pair) => (
            <div key={pair.label}>
              <dt className="text-slate-400">{pair.label}</dt>
              <dd className="font-medium text-slate-800">{pair.value}</dd>
            </div>
          ))}
        </dl>
        {section.extra ? (
          <HighlightedDescription
            text={`Normy: ${section.extra}`}
            className="mt-1 text-[11px] text-slate-600"
            {...highlight}
          />
        ) : null}
      </div>
    )
  }

  if (section.kind === 'documents') {
    return (
      <div className={wrap}>
        <p className={titleClass(compact)}>{section.title}</p>
        <ul className="space-y-1 text-xs text-slate-600">
          {section.items.map((doc) => (
            <li key={doc.url + doc.title}>
              <a href={doc.url} target="_blank" rel="noreferrer" className="text-blue-600 hover:underline">
                {doc.title}
              </a>
              <span className="ml-1 text-slate-400">({doc.meta})</span>
            </li>
          ))}
        </ul>
      </div>
    )
  }

  if (section.kind === 'sources') {
    return (
      <div className={wrap}>
        <p className="text-[11px] text-slate-400">
          {section.title}:{' '}
          {section.items.map((url, i) => (
            <span key={url}>
              {i > 0 ? ' · ' : ''}
              <a className="text-blue-600 hover:underline" href={url} target="_blank" rel="noreferrer">
                {url.replace(/^https?:\/\//, '').slice(0, 40)}
              </a>
            </span>
          ))}
        </p>
      </div>
    )
  }

  return (
    <div className={wrap}>
      <p className={titleClass(compact)}>{section.title}</p>
      <ul className="list-disc pl-5 text-xs text-slate-600">
        {section.items.map((item, i) => {
          const itemOffset =
            findIndexOffset +
            section.items.slice(0, i).reduce((sum, prev) => sum + countFindHits(prev, findPhrase), 0)
          return (
            <li key={`${section.id}-${i}`}>
              <HighlightedDescription
                text={item}
                className="whitespace-pre-wrap text-xs leading-relaxed text-slate-600"
                queryTokens={queryTokens}
                findPhrase={findPhrase}
                activeFindIndex={activeFindIndex}
                findIndexOffset={itemOffset}
              />
            </li>
          )
        })}
      </ul>
    </div>
  )
}
