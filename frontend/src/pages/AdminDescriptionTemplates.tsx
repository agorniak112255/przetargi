import { useEffect, useMemo, useState } from 'react'
import { api } from '../lib/api'
import { DescriptionLayoutView } from '../components/DescriptionLayoutView'
import {
  BLOCK_LABELS,
  EMPHASIS_LABELS,
  samplePreviewProduct,
} from '../lib/descriptionLayout'

type LayoutBlock = {
  id: string
  visible: boolean
  emphasis: string
}

type Layout = {
  inherit_card: boolean
  inherit_export: boolean
  card: LayoutBlock[]
  export: LayoutBlock[]
}

type Template = {
  kategoria_bhp: string
  label: string
  instructions: string
  default_instructions: string
  is_customized: boolean
  is_fallback: boolean
  is_visual_default: boolean
  layout: Layout
  resolved_layout: { card: LayoutBlock[]; export: LayoutBlock[] }
  is_layout_customized: boolean
  updated_at: string | null
}

type Listing = {
  templates: Template[]
}

type Tab = 'instructions' | 'card' | 'export'

function preview(text: string): string {
  const line = text.replace(/\s+/g, ' ').trim()
  return line.length > 140 ? `${line.slice(0, 137)}…` : line
}

function cloneLayout(layout: Layout): Layout {
  return {
    inherit_card: layout.inherit_card,
    inherit_export: layout.inherit_export,
    card: layout.card.map((b) => ({ ...b })),
    export: layout.export.map((b) => ({ ...b })),
  }
}

function sameLayout(a: Layout, b: Layout): boolean {
  return JSON.stringify(a) === JSON.stringify(b)
}

function moveBlock(list: LayoutBlock[], from: number, to: number): LayoutBlock[] {
  if (to < 0 || to >= list.length || from === to) return list
  const next = [...list]
  const [item] = next.splice(from, 1)
  next.splice(to, 0, item)
  return next
}

export function AdminDescriptionTemplates() {
  const [rows, setRows] = useState<Template[]>([])
  const [selected, setSelected] = useState<string>('domyslny')
  const [draft, setDraft] = useState('')
  const [layout, setLayout] = useState<Layout | null>(null)
  const [tab, setTab] = useState<Tab>('card')
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const [msg, setMsg] = useState('')
  const [dragId, setDragId] = useState<string | null>(null)

  const current = useMemo(
    () => rows.find((row) => row.kategoria_bhp === selected) ?? null,
    [rows, selected],
  )

  const dirty =
    current !== null &&
    layout !== null &&
    (draft !== current.instructions || !sameLayout(layout, current.layout))

  async function load(keepKey?: string) {
    const data = await api<Listing>('/admin/enrichment-description-templates')
    const list = data.templates ?? []
    setRows(list)
    const key = keepKey && list.some((row) => row.kategoria_bhp === keepKey)
      ? keepKey
      : (list[0]?.kategoria_bhp ?? 'domyslny')
    setSelected(key)
    const row = list.find((item) => item.kategoria_bhp === key)
    setDraft(row?.instructions ?? '')
    setLayout(row ? cloneLayout(row.layout) : null)
    setTab(row?.is_visual_default ? 'card' : 'instructions')
  }

  useEffect(() => {
    void load().catch((e: Error) => setErr(e.message))
  }, [])

  function pick(key: string) {
    if (dirty && !window.confirm('Masz niezapisane zmiany. Odrzucić je?')) {
      return
    }
    const row = rows.find((item) => item.kategoria_bhp === key)
    setSelected(key)
    setDraft(row?.instructions ?? '')
    setLayout(row ? cloneLayout(row.layout) : null)
    setTab(row?.is_visual_default ? 'card' : 'instructions')
    setMsg('')
    setErr('')
  }

  async function save() {
    if (!current || !layout) return
    setBusy(true)
    setErr('')
    setMsg('')
    try {
      const saved = await api<Template>(
        `/admin/enrichment-description-templates/${encodeURIComponent(current.kategoria_bhp)}`,
        {
          method: 'PUT',
          body: JSON.stringify(
            current.is_visual_default ? { layout } : { instructions: draft, layout },
          ),
        },
      )
      setRows((prev) => prev.map((row) => (row.kategoria_bhp === saved.kategoria_bhp ? saved : row)))
      setDraft(saved.instructions)
      setLayout(cloneLayout(saved.layout))
      setMsg('Zapisano szablon.')
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Nie udało się zapisać')
    } finally {
      setBusy(false)
    }
  }

  async function restore() {
    if (!current) return
    if (!window.confirm('Przywrócić domyślny układ i instrukcje tego szablonu?')) return
    setBusy(true)
    setErr('')
    setMsg('')
    try {
      const saved = await api<Template>(
        `/admin/enrichment-description-templates/${encodeURIComponent(current.kategoria_bhp)}/restore`,
        { method: 'POST', body: '{}' },
      )
      setRows((prev) => prev.map((row) => (row.kategoria_bhp === saved.kategoria_bhp ? saved : row)))
      setDraft(saved.instructions)
      setLayout(cloneLayout(saved.layout))
      setMsg('Przywrócono zestaw startowy.')
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Nie udało się przywrócić')
    } finally {
      setBusy(false)
    }
  }

  const surface = tab === 'export' ? 'export' : 'card'
  const inheritKey = surface === 'export' ? 'inherit_export' : 'inherit_card'
  const inheriting = layout ? layout[inheritKey] && !current?.is_visual_default : false
  const editorBlocks = layout
    ? inheriting
      ? current?.resolved_layout[surface] ?? layout[surface]
      : layout[surface]
    : []

  function patchBlocks(next: LayoutBlock[]) {
    if (!layout || inheriting) return
    setLayout({ ...layout, [surface]: next })
    setMsg('')
  }

  function customizeSurface() {
    if (!layout || !current) return
    setLayout({
      ...layout,
      [inheritKey]: false,
      [surface]: current.resolved_layout[surface].map((b) => ({ ...b })),
    })
  }

  function inheritSurface() {
    if (!layout) return
    setLayout({ ...layout, [inheritKey]: true })
  }

  const previewProduct = useMemo(() => {
    const sample = samplePreviewProduct()
    const card = layout
      ? layout.inherit_card && current && !current.is_visual_default
        ? current.resolved_layout.card
        : layout.card
      : current?.resolved_layout.card
    const exp = layout
      ? layout.inherit_export && current && !current.is_visual_default
        ? current.resolved_layout.export
        : layout.export
      : current?.resolved_layout.export
    return {
      ...sample,
      description_layout: {
        kategoria_bhp: current?.kategoria_bhp ?? 'rekawice',
        label: current?.label ?? 'Rękawice',
        card: card ?? sample.description_layout?.card ?? [],
        export: exp ?? sample.description_layout?.export ?? [],
      },
    }
  }, [current, layout])

  return (
    <div className="space-y-4">
      <div className="overflow-hidden rounded-2xl border border-slate-200 bg-gradient-to-br from-sky-50 via-white to-violet-50 p-5 shadow-sm">
        <p className="text-[11px] font-semibold uppercase tracking-wide text-sky-700">Pobieranie opisów</p>
        <h2 className="mt-1 text-lg font-semibold text-slate-900">Szablony opisów</h2>
        <p className="mt-1 max-w-3xl text-[12px] text-slate-600">
          U góry jest <b>domyślny układ</b> — taki, jaki system ma dziś na karcie i w eksporcie do
          Presty. Rodziny BHP dziedziczą ten układ, dopóki go nie zmienisz. Instrukcje AI nadal
          mówią modelowi, <i>co zbierać</i> przy ściąganiu; układ mówi, <i>jak to pokazać</i>.
          Przy pobieraniu rękawic startuje szablon <b>Rękawice</b> (instrukcje + ewentualny własny
          układ). Gdy rodzina nie wyjdzie — instrukcje „inne” i układ domyślny.
        </p>
      </div>

      {err && (
        <p className="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-[12px] text-rose-800">{err}</p>
      )}
      {msg && (
        <p className="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-[12px] text-emerald-800">{msg}</p>
      )}

      <div className="grid gap-4 lg:grid-cols-[minmax(16rem,22rem)_1fr]">
        <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
          <table className="w-full text-left text-[13px]">
            <thead>
              <tr className="border-b bg-slate-50 text-[11px] uppercase tracking-wide text-slate-500">
                <th className="p-2">Szablon</th>
                <th className="p-2">Status</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((row) => {
                const active = row.kategoria_bhp === selected
                return (
                  <tr
                    key={row.kategoria_bhp}
                    className={`cursor-pointer border-b last:border-b-0 ${
                      active ? 'bg-sky-50' : 'hover:bg-slate-50'
                    }`}
                    onClick={() => pick(row.kategoria_bhp)}
                  >
                    <td className="p-2 align-top">
                      <div className="font-medium text-slate-800">{row.label}</div>
                      <div className="mt-0.5 text-[11px] text-slate-500">
                        {row.is_visual_default
                          ? 'Obecny układ karty i eksportu'
                          : preview(row.instructions)}
                      </div>
                    </td>
                    <td className="p-2 align-top whitespace-nowrap">
                      {row.is_visual_default && (
                        <span className="mr-1 rounded bg-violet-100 px-1.5 py-0.5 text-[10px] font-medium text-violet-800">
                          układ systemu
                        </span>
                      )}
                      {row.is_fallback && (
                        <span className="mr-1 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-medium text-slate-600">
                          fallback AI
                        </span>
                      )}
                      {row.is_layout_customized && (
                        <span className="mr-1 rounded bg-sky-100 px-1.5 py-0.5 text-[10px] font-medium text-sky-800">
                          układ własny
                        </span>
                      )}
                      {!row.is_visual_default &&
                        (row.is_customized ? (
                          <span className="rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-medium text-amber-800">
                            instrukcje
                          </span>
                        ) : (
                          <span className="rounded bg-emerald-50 px-1.5 py-0.5 text-[10px] font-medium text-emerald-800">
                            AI domyślne
                          </span>
                        ))}
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>

        <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
          {current && layout ? (
            <>
              <div className="mb-3 flex flex-wrap items-start justify-between gap-2">
                <div>
                  <h3 className="text-sm font-semibold text-slate-900">{current.label}</h3>
                  <p className="text-[11px] text-slate-500">
                    {current.is_visual_default
                      ? 'klucz: domyslny · używany, gdy rodzina nie ma własnego układu'
                      : `klucz: ${current.kategoria_bhp}${current.is_fallback ? ' · instrukcje, gdy rodzina nie jest znana' : ''}`}
                  </p>
                </div>
                <div className="flex flex-wrap gap-2">
                  <button
                    type="button"
                    disabled={busy || (!current.is_customized && !current.is_layout_customized)}
                    onClick={() => void restore()}
                    className="rounded-lg border border-slate-300 px-3 py-1.5 text-xs hover:bg-slate-50 disabled:opacity-50"
                  >
                    Przywróć domyślne
                  </button>
                  <button
                    type="button"
                    disabled={busy || !dirty}
                    onClick={() => void save()}
                    className="rounded-lg bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white disabled:opacity-50"
                  >
                    {busy ? 'Zapisuję…' : 'Zapisz'}
                  </button>
                </div>
              </div>

              <div className="mb-3 flex flex-wrap gap-1 rounded-lg bg-slate-100 p-1">
                {(
                  [
                    ...(!current.is_visual_default
                      ? ([['instructions', 'Instrukcje AI']] as const)
                      : []),
                    ['card', 'Układ karty'],
                    ['export', 'Układ eksportu'],
                  ] as const
                ).map(([id, label]) => (
                  <button
                    key={id}
                    type="button"
                    onClick={() => setTab(id)}
                    className={`rounded-md px-3 py-1.5 text-xs font-medium ${
                      tab === id ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900'
                    }`}
                  >
                    {label}
                  </button>
                ))}
              </div>

              {tab === 'instructions' && !current.is_visual_default ? (
                <>
                  <textarea
                    className="min-h-[18rem] w-full rounded-xl border border-slate-300 p-3 font-mono text-[12px] leading-relaxed"
                    value={draft}
                    onChange={(e) => {
                      setDraft(e.target.value)
                      setMsg('')
                    }}
                  />
                  <p className="mt-2 text-[11px] text-slate-500">
                    Te zdania idą do modelu przy ściąganiu opisu. Układ bloków ustawiasz w sąsiednich
                    zakładkach — działa od razu na karcie i przy następnym eksporcie do Presty.
                  </p>
                </>
              ) : (
                <div className="grid gap-4 xl:grid-cols-[minmax(18rem,22rem)_1fr]">
                  <div>
                    {!current.is_visual_default && (
                      <div className="mb-2 flex flex-wrap items-center gap-2">
                        {inheriting ? (
                          <button
                            type="button"
                            onClick={customizeSurface}
                            className="rounded-lg border border-violet-300 bg-violet-50 px-2.5 py-1 text-[11px] font-medium text-violet-800"
                          >
                            Dostosuj układ {tab === 'export' ? 'eksportu' : 'karty'}
                          </button>
                        ) : (
                          <button
                            type="button"
                            onClick={inheritSurface}
                            className="rounded-lg border border-slate-300 px-2.5 py-1 text-[11px] hover:bg-slate-50"
                          >
                            Dziedzicz z domyślnego
                          </button>
                        )}
                        <span className="text-[11px] text-slate-500">
                          {inheriting ? 'Teraz jak układ systemu' : 'Własny układ tej rodziny'}
                        </span>
                      </div>
                    )}
                    <ul className="space-y-1.5">
                      {editorBlocks.map((block, index) => (
                        <li
                          key={block.id}
                          draggable={!inheriting}
                          onDragStart={() => setDragId(block.id)}
                          onDragEnd={() => setDragId(null)}
                          onDragOver={(e) => e.preventDefault()}
                          onDrop={() => {
                            if (!dragId) return
                            const from = editorBlocks.findIndex((b) => b.id === dragId)
                            patchBlocks(moveBlock(editorBlocks, from, index))
                            setDragId(null)
                          }}
                          className={`rounded-lg border px-2 py-1.5 ${
                            block.visible ? 'border-slate-200 bg-white' : 'border-dashed border-slate-200 bg-slate-50'
                          } ${dragId === block.id ? 'opacity-60' : ''}`}
                        >
                          <div className="flex items-center gap-2">
                            <span className="cursor-grab text-slate-400" title="Przeciągnij">
                              ::
                            </span>
                            <label className="flex min-w-0 flex-1 items-center gap-2 text-[12px]">
                              <input
                                type="checkbox"
                                checked={block.visible}
                                disabled={inheriting}
                                onChange={(e) =>
                                  patchBlocks(
                                    editorBlocks.map((b) =>
                                      b.id === block.id ? { ...b, visible: e.target.checked } : b,
                                    ),
                                  )
                                }
                              />
                              <span className={`truncate ${block.visible ? 'font-medium text-slate-800' : 'text-slate-400'}`}>
                                {BLOCK_LABELS[block.id] ?? block.id}
                              </span>
                            </label>
                            <button
                              type="button"
                              disabled={inheriting || index === 0}
                              onClick={() => patchBlocks(moveBlock(editorBlocks, index, index - 1))}
                              className="rounded border border-slate-200 px-1 text-[10px] disabled:opacity-30"
                            >
                              ↑
                            </button>
                            <button
                              type="button"
                              disabled={inheriting || index === editorBlocks.length - 1}
                              onClick={() => patchBlocks(moveBlock(editorBlocks, index, index + 1))}
                              className="rounded border border-slate-200 px-1 text-[10px] disabled:opacity-30"
                            >
                              ↓
                            </button>
                          </div>
                          <select
                            className="mt-1 w-full rounded border border-slate-200 bg-white px-1.5 py-0.5 text-[11px]"
                            value={block.emphasis}
                            disabled={inheriting || !block.visible}
                            onChange={(e) =>
                              patchBlocks(
                                editorBlocks.map((b) =>
                                  b.id === block.id ? { ...b, emphasis: e.target.value } : b,
                                ),
                              )
                            }
                          >
                            {Object.entries(EMPHASIS_LABELS).map(([value, label]) => (
                              <option key={value} value={value}>
                                {label}
                              </option>
                            ))}
                          </select>
                        </li>
                      ))}
                    </ul>
                  </div>
                  <div className="rounded-xl border border-slate-200 bg-slate-50 p-3">
                    <p className="mb-2 text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                      Podgląd {tab === 'export' ? 'eksportu (Presta)' : 'karty weryfikacji'}
                    </p>
                    <div className="rounded-lg border border-slate-200 bg-white p-3">
                      <DescriptionLayoutView
                        product={previewProduct}
                        blocks={tab === 'export' ? previewProduct.description_layout?.export : undefined}
                        compact
                      />
                    </div>
                  </div>
                </div>
              )}
            </>
          ) : (
            <p className="text-sm text-slate-500">Ładowanie szablonów…</p>
          )}
        </div>
      </div>
    </div>
  )
}
