import { useEffect, useMemo, useState } from 'react'

export type CrossRefFilterItem = {
  id: string
  label: string
  default: boolean
}

export type CrossRefFilterGroup = {
  id: string
  label: string
  items: CrossRefFilterItem[]
}

export type CrossRefAppliedFilter = {
  id: string
  label: string
  group: string
  group_label: string
}

type Props = {
  open: boolean
  code: string
  seedLabel: string
  groups: CrossRefFilterGroup[]
  selected: string[]
  loading?: boolean
  error?: string
  onChange: (ids: string[]) => void
  onSearchAll: () => void
  onSearchMust: (ids: string[]) => void
  onClose: () => void
}

export function CrossRefFilterModal({
  open,
  code,
  seedLabel,
  groups,
  selected,
  loading = false,
  error = '',
  onChange,
  onSearchAll,
  onSearchMust,
  onClose,
}: Props) {
  const [draft, setDraft] = useState<string[]>(selected)

  useEffect(() => {
    if (open) setDraft(selected)
  }, [open, selected])

  useEffect(() => {
    if (!open) return
    function onKey(e: KeyboardEvent) {
      if (e.key === 'Escape') onClose()
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [open, onClose])

  const allIds = useMemo(() => groups.flatMap((g) => g.items.map((i) => i.id)), [groups])
  const allOn = allIds.length > 0 && allIds.every((id) => draft.includes(id))

  function toggle(id: string) {
    setDraft((cur) => (cur.includes(id) ? cur.filter((x) => x !== id) : [...cur, id]))
  }

  function toggleGroup(group: CrossRefFilterGroup) {
    const ids = group.items.map((i) => i.id)
    const every = ids.every((id) => draft.includes(id))
    setDraft((cur) => (every ? cur.filter((id) => !ids.includes(id)) : [...new Set([...cur, ...ids])]))
  }

  function selectDefaults() {
    setDraft(groups.flatMap((g) => g.items.filter((i) => i.default).map((i) => i.id)))
  }

  if (!open) return null

  return (
    <div
      className="fixed inset-0 z-50 flex items-start justify-center bg-black/50 p-4 pt-10"
      role="dialog"
      aria-modal="true"
      aria-labelledby="cross-ref-filter-title"
      onClick={onClose}
    >
      <div
        className="max-h-[88vh] w-full max-w-2xl overflow-auto rounded-xl bg-white p-4 shadow-xl"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="mb-3 flex items-start justify-between gap-3">
          <div>
            <h2 id="cross-ref-filter-title" className="text-sm font-semibold text-slate-800">
              Doprecyzuj zamienniki
            </h2>
            <p className="mt-0.5 text-xs text-slate-600">
              <b>{code}</b>
              {seedLabel ? ` · ${seedLabel}` : ''}
            </p>
            <p className="mt-1 text-[11px] text-slate-500">
              Zaznaczone cechy zamiennik <b>musi</b> mieć (łącznie). Puste = bez dodatkowego filtra.
            </p>
          </div>
          <button type="button" className="text-lg leading-none text-slate-400 hover:text-slate-700" onClick={onClose}>
            ×
          </button>
        </div>

        <button
          type="button"
          disabled={loading}
          onClick={onSearchAll}
          className="mb-3 w-full rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2.5 text-left hover:bg-emerald-100 disabled:opacity-50"
        >
          <span className="block text-sm font-semibold text-emerald-900">Szukaj wszystko</span>
          <span className="block text-[11px] text-emerald-800/80">
            Jak dotychczas — ten sam typ, materiał, klasa i normy, bez ręcznych zaznaczeń.
          </span>
        </button>

        {error && <p className="mb-2 text-xs text-rose-700">{error}</p>}
        {loading ? (
          <p className="py-6 text-center text-xs text-slate-500">Ładuję cechy produktu…</p>
        ) : groups.length === 0 ? (
          <p className="rounded border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">
            Brak twardych cech do odhaczenia. Użyj „Szukaj wszystko”.
          </p>
        ) : (
          <>
            <div className="mb-2 flex flex-wrap items-center gap-2">
              <button
                type="button"
                className="rounded border border-slate-200 px-2 py-1 text-[11px] text-slate-700 hover:bg-slate-50"
                onClick={() => setDraft(allOn ? [] : allIds)}
              >
                {allOn ? 'Wyczyść wszystko' : 'Zaznacz wszystko'}
              </button>
              <button
                type="button"
                className="rounded border border-slate-200 px-2 py-1 text-[11px] text-slate-700 hover:bg-slate-50"
                onClick={selectDefaults}
              >
                Typ + klasa + normy
              </button>
              <span className="text-[11px] text-slate-500">zaznaczono {draft.length}</span>
            </div>
            <div className="space-y-3">
              {groups.map((group) => {
                const ids = group.items.map((i) => i.id)
                const groupAll = ids.every((id) => draft.includes(id))
                return (
                  <section key={group.id} className="rounded-lg border border-slate-200">
                    <div className="flex items-center justify-between gap-2 border-b border-slate-100 bg-slate-50 px-3 py-1.5">
                      <h3 className="text-xs font-semibold text-slate-700">{group.label}</h3>
                      <button
                        type="button"
                        className="text-[11px] text-emerald-800 hover:underline"
                        onClick={() => toggleGroup(group)}
                      >
                        {groupAll ? 'Odznacz grupę' : 'Zaznacz grupę'}
                      </button>
                    </div>
                    <ul className="grid gap-1 p-2 sm:grid-cols-2">
                      {group.items.map((item) => {
                        const checked = draft.includes(item.id)
                        return (
                          <li key={item.id}>
                            <label className="flex cursor-pointer items-start gap-2 rounded px-1.5 py-1 text-xs text-slate-700 hover:bg-slate-50">
                              <input
                                type="checkbox"
                                className="mt-0.5 h-4 w-4 rounded border-slate-300 text-emerald-700"
                                checked={checked}
                                onChange={() => toggle(item.id)}
                              />
                              <span>
                                {item.label}
                                {item.default && (
                                  <span className="ml-1 text-[10px] text-slate-400">domyślne</span>
                                )}
                              </span>
                            </label>
                          </li>
                        )
                      })}
                    </ul>
                  </section>
                )
              })}
            </div>
          </>
        )}

        <div className="mt-4 flex flex-wrap justify-end gap-2">
          <button
            type="button"
            className="rounded border border-slate-200 px-3 py-2 text-xs text-slate-600 hover:bg-slate-50"
            onClick={onClose}
          >
            Anuluj
          </button>
          <button
            type="button"
            disabled={loading || draft.length === 0}
            className="rounded bg-emerald-700 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-800 disabled:opacity-50"
            onClick={() => {
              onChange(draft)
              onSearchMust(draft)
            }}
          >
            Szukaj z zaznaczeniem ({draft.length})
          </button>
        </div>
      </div>
    </div>
  )
}
