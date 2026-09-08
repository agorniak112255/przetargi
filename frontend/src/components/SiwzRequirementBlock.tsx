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
