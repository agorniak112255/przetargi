import type { ReactNode } from 'react'

export function splitSiwzRequirement(requirement: string): { name: string; description: string | null } {
  const sep = ' · '
  const idx = requirement.indexOf(sep)
  if (idx === -1) {
    return { name: requirement, description: null }
  }
  const name = requirement.slice(0, idx).trim()
  const description = requirement.slice(idx + sep.length).trim()
  if (description === '' || description.toLowerCase() === name.toLowerCase()) {
    return { name, description: null }
  }
  return { name, description }
}

const SIWZ_HEADING =
  /(?=(?:PODESZWA|CHOLEWKA|ESD|NORMA|NORMY|TYP(?:\s*\/\s*PRODUCENT(?:\s+REFERENCYJNY)?)?|WYMAGANIA(?:\s+OGÓLNE)?|PÓŁBUTY|TRZEWIKI|KALOSZE|INDEKS)\s*:)/iu

function siwzSections(text: string): { heading: string | null; body: string }[] {
  const chunks = text
    .split(SIWZ_HEADING)
    .map((part) => part.trim())
    .filter((part) => part !== '')
  if (chunks.length <= 1) {
    return [{ heading: null, body: text.trim() }]
  }
  return chunks.map((chunk) => {
    const colon = chunk.indexOf(':')
    if (colon <= 0 || colon > 48) {
      return { heading: null, body: chunk }
    }
    return { heading: chunk.slice(0, colon).trim(), body: chunk.slice(colon + 1).trim() }
  })
}

function SiwzSectionBody({ text }: { text: string }) {
  const lines = text
    .split(/\n/)
    .map((line) => line.trim())
    .filter((line) => line !== '')
  const listCount = lines.filter((line) => /^[•\-*]\s+/.test(line)).length
  if (listCount >= 2) {
    return (
      <ul className="list-disc space-y-0.5 pl-4 text-slate-700">
        {lines.map((line, i) => (
          <li key={`${line}-${i}`}>{line.replace(/^[•\-*]\s+/, '')}</li>
        ))}
      </ul>
    )
  }
  return <div className="whitespace-pre-wrap text-slate-700">{text}</div>
}

export function SiwzDescriptionBody({ text }: { text: string }) {
  const sections = siwzSections(text)
  if (sections.length === 1 && sections[0].heading == null) {
    return <SiwzSectionBody text={text} />
  }
  return (
    <div className="space-y-2">
      {sections.map((section, i) => (
        <div key={`${section.heading ?? 'opis'}-${i}`}>
          {section.heading ? (
            <div className="text-[10px] font-bold uppercase tracking-wide text-slate-900">
              {section.heading}:
            </div>
          ) : null}
          <SiwzSectionBody text={section.body} />
        </div>
      ))}
    </div>
  )
}

export function SiwzRequirementBlock({
  name,
  description,
  className = '',
}: {
  name: string
  description?: string | null
  className?: string
}) {
  return (
    <div className={className}>
      <div className="font-medium text-slate-800">{name}</div>
      {description ? (
        <div className="mt-1 max-h-32 overflow-y-auto whitespace-pre-wrap rounded border border-slate-300 bg-slate-50 px-1.5 py-1 text-[10px] leading-snug text-slate-600">
          {description}
        </div>
      ) : null}
    </div>
  )
}

export function SiwzItemTile({
  lineNo,
  name,
  description,
  badges,
}: {
  lineNo: number
  name: string
  description?: string | null
  badges?: ReactNode
}) {
  return (
    <section className="flex min-h-full min-w-0 flex-col overflow-hidden rounded-lg border border-slate-700">
      <header className="bg-slate-800 px-3 py-2 text-white">
        <div className="text-[10px] font-semibold uppercase tracking-wide text-slate-300">SIWZ</div>
        <div className="mt-0.5 flex flex-wrap items-center gap-1.5">
          <span className="text-lg font-bold leading-none">{lineNo}</span>
          <span className="inline-block h-2 w-2 rounded-full bg-amber-400" />
          {badges}
        </div>
        <h3 className="mt-1.5 text-sm font-bold uppercase leading-snug">{name}</h3>
      </header>
      <div className="max-h-72 flex-1 overflow-y-auto bg-white px-3 py-2 text-[11px] leading-snug">
        {description ? (
          <SiwzDescriptionBody text={description} />
        ) : (
          <p className="text-slate-400">Brak opisu w SIWZ.</p>
        )}
      </div>
    </section>
  )
}
