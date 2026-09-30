import { useCallback, useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useAuth } from '../auth'
import { CampaignBlockEditor, LiveMailPreview } from '../components/CampaignBlockEditor'
import { BTN, CampaignsTabs, Chip, ErrorBar } from '../components/CampaignsUi'
import { can } from '../lib/api'
import { errorText } from '../lib/campaignFormat'
import {
  createTemplate,
  listTemplates,
  previewTemplate,
  updateTemplate,
  type CampaignBlock,
  type CampaignTemplate,
} from '../lib/campaigns'
import { useSerialAutosave } from '../lib/useSerialAutosave'

/**
 * Szablon maila /kampanie/szablony/:id: nazwa, (administrator) wspólny, elementy maila i kolor, podgląd z
 * przykładowymi produktami. Zapisuje się sam (jak treść kampanii). Cudzy wspólny szablon — tylko do odczytu.
 */

const SAVE_MS = 600

type Draft = { name: string; blocks: CampaignBlock[]; brand_color: string | null }

export function CampaignTemplateEditor() {
  const { id } = useParams()
  // Inny szablon (np. po „Zrób kopię”) = świeży stan edytora, bez szkicu poprzedniego.
  return <TemplateEditorPage key={id} templateId={Number(id)} />
}

function TemplateEditorPage({ templateId }: { templateId: number }) {
  const { user } = useAuth()
  const navigate = useNavigate()
  const manage = can(user, 'campaigns.manage')
  const [tpl, setTpl] = useState<CampaignTemplate | null>(null)
  const [draft, setDraft] = useState<Draft | null>(null)
  const [loadErr, setLoadErr] = useState('')
  const [err, setErr] = useState('')
  const [saving, setSaving] = useState(0)
  const [savedAt, setSavedAt] = useState<Date | null>(null)
  const [copying, setCopying] = useState(false)

  // Brak osobnego GET jednego szablonu — bierzemy go z listy (widoczne tylko własne i wspólne).
  useEffect(() => {
    if (!Number.isFinite(templateId) || templateId <= 0) {
      setLoadErr('Zły numer szablonu.')
      return
    }
    let alive = true
    listTemplates()
      .then((res) => {
        if (!alive) return
        const found = res.data.find((t) => t.id === templateId) ?? null
        setTpl(found)
        if (found) setDraft({ name: found.name, blocks: found.blocks, brand_color: found.brand_color })
        setLoadErr(found ? '' : 'Nie ma takiego szablonu albo nie masz do niego dostępu.')
      })
      .catch((ex: unknown) => {
        if (alive) setLoadErr(errorText(ex, 'Nie udało się wczytać szablonu.'))
      })
    return () => {
      alive = false
    }
  }, [templateId])

  const save = useCallback(
    async (patch: Partial<Draft>) => {
      const body: Parameters<typeof updateTemplate>[1] = {}
      if (patch.name !== undefined) body.name = patch.name
      if (patch.blocks !== undefined) body.blocks = patch.blocks
      if (patch.brand_color !== undefined) body.brand_color = patch.brand_color
      setSaving((n) => n + 1)
      try {
        // Odpowiedź odświeża uprawnienia i datę; szkic w polach zostaje (mógł się zmienić w trakcie zapisu).
        setTpl(await updateTemplate(templateId, body))
        setSavedAt(new Date())
      } catch (ex) {
        setErr(errorText(ex, 'Nie udało się zapisać szablonu.'))
      } finally {
        setSaving((n) => n - 1)
      }
    },
    [templateId],
  )
  const autosave = useSerialAutosave<Draft>(save, SAVE_MS)

  function edit(patch: Partial<Draft>, immediate = false) {
    setDraft((d) => (d ? { ...d, ...patch } : d))
    autosave.edit(patch, immediate)
  }

  async function toggleShared(value: boolean) {
    await autosave.flush()
    try {
      setTpl(await autosave.enqueue(() => updateTemplate(templateId, { is_shared: value })))
    } catch (ex) {
      setErr(errorText(ex, 'Nie udało się zmienić rodzaju szablonu.'))
    }
  }

  async function copy() {
    if (!draft) return
    setCopying(true)
    setErr('')
    try {
      await autosave.flush()
      const c = await createTemplate({
        name: `${draft.name} (kopia)`.slice(0, 150),
        blocks: draft.blocks,
        brand_color: draft.brand_color,
      })
      navigate(`/kampanie/szablony/${c.id}`)
    } catch (ex) {
      setErr(errorText(ex, 'Nie udało się zrobić kopii szablonu.'))
      setCopying(false)
    }
  }

  const canEdit = Boolean(tpl?.can_edit)

  return (
    <div>
      <div className="app-page-head mb-4 flex flex-wrap items-end justify-between gap-3">
        <div className="min-w-0 flex-1">
          <Link to="/kampanie?tab=szablony" className="app-back text-xs text-blue-600 hover:underline">
            ← Moje szablony
          </Link>
          {canEdit && draft ? (
            <input
              aria-label="Nazwa szablonu"
              maxLength={150}
              className="app-page-title mt-1 block w-full max-w-xl rounded border border-transparent bg-transparent px-1 text-xl font-semibold hover:border-slate-300 focus:border-slate-400"
              value={draft.name}
              onChange={(e) => setDraft({ ...draft, name: e.target.value })}
              onBlur={() => {
                const name = draft.name.trim()
                if (!tpl || !name) {
                  if (tpl) setDraft({ ...draft, name: tpl.name })
                  return
                }
                if (name !== tpl.name) edit({ name }, true)
              }}
              onKeyDown={(e) => {
                if (e.key === 'Enter') e.currentTarget.blur()
              }}
            />
          ) : (
            <h1 className="app-page-title mt-1 text-xl font-semibold">{tpl?.name ?? 'Szablon maila'}</h1>
          )}
          {tpl && (
            <p className="mt-1 flex flex-wrap items-center gap-2 text-xs text-slate-600">
              {tpl.is_shared && <Chip tone="blue">wspólny</Chip>}
              <span>
                {tpl.owner == null ? 'autor usunięty' : tpl.owner.id === user?.id ? 'Mój szablon' : `Autor: ${tpl.owner.name}`}
              </span>
              {canEdit && (
                <span className="text-slate-500">
                  ·{' '}
                  {saving > 0
                    ? 'zapisuję…'
                    : savedAt
                      ? `zapisano ${savedAt.toLocaleTimeString('pl-PL', { hour: '2-digit', minute: '2-digit' })}`
                      : 'zapisuje się sam'}
                </span>
              )}
              {manage && canEdit && (
                <label className="ml-2 inline-flex items-center gap-1.5">
                  <input type="checkbox" checked={tpl.is_shared} onChange={(e) => void toggleShared(e.target.checked)} />
                  wspólny dla wszystkich handlowców
                </label>
              )}
            </p>
          )}
        </div>
        {tpl && (
          <button type="button" className={BTN} disabled={copying} onClick={() => void copy()}>
            {copying ? 'Kopiuję…' : 'Zrób kopię'}
          </button>
        )}
      </div>

      <CampaignsTabs active="templates" />

      <ErrorBar message={loadErr} />
      <ErrorBar message={err} onClose={() => setErr('')} />

      {tpl && draft && (
        <>
          {!canEdit && (
            <p className="mb-3 rounded border border-slate-200 bg-white px-3 py-2 text-xs text-slate-600">
              Tylko do odczytu — wspólne szablony prowadzi administrator. Zrób kopię, żeby zmienić go po swojemu.
            </p>
          )}
          <div className="grid items-start gap-4 xl:grid-cols-[minmax(0,1fr)_minmax(0,432px)]">
            <div className="rounded-xl bg-white p-4 shadow-sm">
              <CampaignBlockEditor
                blocks={draft.blocks}
                brandColor={draft.brand_color}
                disabled={!canEdit}
                onChange={(blocks, brand_color) => edit({ blocks, brand_color })}
              />
            </div>
            <LiveMailPreview
              blocks={draft.blocks}
              brandColor={draft.brand_color}
              load={previewTemplate}
              note="przykładowe produkty"
            />
          </div>
        </>
      )}
    </div>
  )
}
