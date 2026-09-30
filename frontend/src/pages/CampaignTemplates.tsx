import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useAuth } from '../auth'
import { BTN, BTN_PRIMARY, BTN_SM, CampaignsTabs, Chip, ConfirmDialog, ErrorBar, INPUT } from '../components/CampaignsUi'
import { can } from '../lib/api'
import { errorText, fmtDate } from '../lib/campaignFormat'
import {
  BRAND_COLOR_LABEL,
  CAMPAIGN_BLOCK_LABEL,
  DEFAULT_BRAND_COLOR,
  createTemplate,
  deleteTemplate,
  listTemplates,
  standardCampaignBlocks,
  type CampaignTemplate,
} from '../lib/campaigns'

/**
 * Zakładka „Moje szablony” strony Kampanie: własne szablony maila i wspólne (prowadzi administrator).
 * Edycja elementów — na stronie szablonu (/kampanie/szablony/:id). Szablon wybiera się w kampanii, w kroku „Treść”.
 */
export function CampaignTemplates() {
  const { user } = useAuth()
  const navigate = useNavigate()
  const manage = can(user, 'campaigns.manage')
  const [rows, setRows] = useState<CampaignTemplate[] | null>(null)
  const [err, setErr] = useState('')
  const [formOpen, setFormOpen] = useState(false)
  const [name, setName] = useState('')
  const [shared, setShared] = useState(false)
  const [busy, setBusy] = useState(false)
  const [toDelete, setToDelete] = useState<CampaignTemplate | null>(null)
  const [deleting, setDeleting] = useState(false)
  const [deleteErr, setDeleteErr] = useState('')

  const load = useCallback(async () => {
    try {
      const res = await listTemplates()
      setRows(res.data)
      setErr('')
    } catch (ex) {
      setErr(errorText(ex, 'Nie udało się wczytać szablonów.'))
    }
  }, [])

  useEffect(() => {
    void load()
  }, [load])

  async function create(e: FormEvent) {
    e.preventDefault()
    if (!name.trim()) return
    setBusy(true)
    setErr('')
    try {
      const t = await createTemplate({
        name: name.trim(),
        blocks: standardCampaignBlocks(),
        brand_color: null,
        is_shared: manage ? shared : false,
      })
      navigate(`/kampanie/szablony/${t.id}`)
    } catch (ex) {
      setErr(errorText(ex, 'Nie udało się założyć szablonu.'))
      setBusy(false)
    }
  }

  async function copy(t: CampaignTemplate) {
    setBusy(true)
    setErr('')
    try {
      const c = await createTemplate({
        name: `${t.name} (kopia)`.slice(0, 150),
        blocks: t.blocks,
        brand_color: t.brand_color,
      })
      navigate(`/kampanie/szablony/${c.id}`)
    } catch (ex) {
      setErr(errorText(ex, 'Nie udało się zrobić kopii szablonu.'))
      setBusy(false)
    }
  }

  async function confirmDelete() {
    if (!toDelete) return
    setDeleting(true)
    setDeleteErr('')
    try {
      await deleteTemplate(toDelete.id)
      setToDelete(null)
      await load()
    } catch (ex) {
      setDeleteErr(errorText(ex, 'Nie udało się usunąć szablonu.'))
    } finally {
      setDeleting(false)
    }
  }

  return (
    <>
      <div className="app-page-head mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="app-page-title text-xl font-semibold">Szablony maili</h1>
          <p className="mt-1 max-w-3xl text-xs text-slate-600">
            Układ maila kampanii: logo, nagłówek, tekst, grafiki, produkty, przycisk i stopka. Szablon wybierasz w
            kampanii, w kroku „Treść” — jego elementy kopiują się do kampanii i tam można je dalej zmieniać.
          </p>
        </div>
        <button type="button" className={BTN_PRIMARY} onClick={() => setFormOpen((v) => !v)}>
          + Nowy szablon
        </button>
      </div>

      <CampaignsTabs active="templates" />

      <ErrorBar message={err} onClose={() => setErr('')} />

      {formOpen && (
        <form onSubmit={create} className="mb-4 flex flex-wrap items-end gap-3 rounded-xl bg-white p-4 text-xs shadow-sm">
          <label className="flex min-w-[16rem] flex-1 flex-col gap-1 text-slate-600">
            Nazwa szablonu
            <input
              autoFocus
              required
              maxLength={150}
              className={INPUT}
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder="np. Wyprzedaż obuwia – z banerem"
            />
          </label>
          {manage && (
            <label className="flex items-center gap-1.5 pb-1 text-slate-700" title="Wspólny szablon widzą i wybierają wszyscy handlowcy">
              <input type="checkbox" checked={shared} onChange={(e) => setShared(e.target.checked)} />
              Wspólny dla wszystkich handlowców
            </label>
          )}
          <button type="submit" className={BTN_PRIMARY} disabled={busy || !name.trim()}>
            {busy ? 'Zakładam…' : 'Załóż szablon'}
          </button>
          <button type="button" className={BTN} onClick={() => setFormOpen(false)}>
            Anuluj
          </button>
        </form>
      )}

      <div className="overflow-x-auto rounded-xl bg-white p-4 shadow-sm">
        <table className="w-full text-left text-xs">
          <thead>
            <tr className="border-b bg-slate-50">
              <th className="p-2">Szablon</th>
              <th className="p-2">Elementy</th>
              <th className="p-2">Kolor</th>
              <th className="p-2">Zmieniony</th>
              <th className="p-2" />
            </tr>
          </thead>
          <tbody>
            {(rows ?? []).map((t) => {
              const color = t.brand_color ?? DEFAULT_BRAND_COLOR
              return (
                <tr key={t.id} className="border-b align-top">
                  <td className="min-w-[14rem] p-2">
                    <Link to={`/kampanie/szablony/${t.id}`} className="font-medium text-slate-900 hover:text-blue-700 hover:underline">
                      {t.name}
                    </Link>
                    <div className="mt-0.5 flex flex-wrap items-center gap-1.5 text-[11px] text-slate-500">
                      {t.is_shared && <Chip tone="blue">wspólny</Chip>}
                      <span>
                        {t.owner == null
                          ? 'autor usunięty'
                          : t.owner.id === user?.id
                            ? 'mój'
                            : `autor: ${t.owner.name}`}
                      </span>
                    </div>
                  </td>
                  <td className="p-2 text-slate-700">{t.blocks.map((b) => CAMPAIGN_BLOCK_LABEL[b.type] ?? b.type).join(' · ')}</td>
                  <td className="whitespace-nowrap p-2 text-slate-700">
                    <span className="inline-flex items-center gap-1.5">
                      <span className="inline-block h-3 w-3 rounded-full border border-slate-300" style={{ backgroundColor: color }} aria-hidden />
                      {BRAND_COLOR_LABEL[color] ?? color}
                    </span>
                  </td>
                  <td className="whitespace-nowrap p-2 tabular-nums text-slate-600">{fmtDate(t.updated_at)}</td>
                  <td className="whitespace-nowrap p-2 text-right">
                    <span className="inline-flex gap-1">
                      <Link to={`/kampanie/szablony/${t.id}`} className={BTN_SM}>
                        {t.can_edit ? 'Edytuj' : 'Otwórz'}
                      </Link>
                      <button type="button" className={BTN_SM} disabled={busy} onClick={() => void copy(t)}>
                        Zrób kopię
                      </button>
                      {t.can_edit && (
                        <button
                          type="button"
                          className={`${BTN_SM} text-red-700`}
                          onClick={() => {
                            setDeleteErr('')
                            setToDelete(t)
                          }}
                        >
                          Usuń
                        </button>
                      )}
                    </span>
                  </td>
                </tr>
              )
            })}
            {rows !== null && rows.length === 0 && (
              <tr>
                <td colSpan={5} className="p-8 text-center text-slate-500">
                  Nie masz jeszcze szablonów. Kampanie używają układu „Standard SUPON” — załóż szablon albo w kampanii
                  wybierz „Zapisz jako mój szablon”.
                </td>
              </tr>
            )}
            {rows === null && !err && (
              <tr>
                <td colSpan={5} className="p-8 text-center text-slate-500">
                  Ładowanie…
                </td>
              </tr>
            )}
          </tbody>
        </table>
        <p className="mt-2 text-[11px] text-slate-500">
          Zawsze w mailu, niezależnie od szablonu: podpis z „Moja poczta”, „Ceny netto ważne do …” nad produktami i link
          „Wypisz mnie z mailingu”. Wspólne szablony prowadzi administrator — możesz zrobić ich kopię i zmienić po swojemu.
        </p>
      </div>

      {toDelete && (
        <ConfirmDialog
          title="Usunąć szablon?"
          danger
          confirmLabel="Usuń szablon"
          busy={deleting}
          error={deleteErr}
          onClose={() => setToDelete(null)}
          onConfirm={() => void confirmDelete()}
          message={
            <>
              <p>
                <b>{toDelete.name}</b>
                {toDelete.is_shared ? ' — szablon wspólny, zniknie u wszystkich handlowców.' : '.'}
              </p>
              <p className="text-xs text-slate-600">Kampanie, w których go użyto, zachowują swoją treść.</p>
            </>
          }
        />
      )}
    </>
  )
}
