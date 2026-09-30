import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../auth'
import {
  BTN,
  BTN_PRIMARY,
  BTN_SM,
  CampaignsTabs,
  Chip,
  ConfirmDialog,
  ErrorBar,
  INPUT,
} from '../components/CampaignsUi'
import { errorText, fmtDate, fmtInt } from '../lib/campaignFormat'
import { can } from '../lib/api'
import { createMailingList, deleteMailingList, listMailingLists, type MailingList } from '../lib/campaigns'
import { plural } from '../lib/plural'

/**
 * Zakładka „Grupy odbiorców” strony Kampanie: własne i wspólne grupy adresów z podstawą wysyłki.
 * Kontakty i import — w szczegółach grupy (/kampanie/grupy/:id).
 */
export function MailingLists() {
  const { user } = useAuth()
  const manage = can(user, 'campaigns.manage')
  const [lists, setLists] = useState<MailingList[] | null>(null)
  const [err, setErr] = useState('')
  const [formOpen, setFormOpen] = useState(false)
  const [name, setName] = useState('')
  const [shared, setShared] = useState(false)
  const [saving, setSaving] = useState(false)
  const [toDelete, setToDelete] = useState<MailingList | null>(null)
  const [deleting, setDeleting] = useState(false)
  const [deleteErr, setDeleteErr] = useState('')

  const load = useCallback(async () => {
    try {
      const res = await listMailingLists()
      setLists(res.data)
      setErr('')
    } catch (ex) {
      setErr(errorText(ex, 'Nie udało się wczytać grup.'))
    }
  }, [])

  useEffect(() => {
    void load()
  }, [load])

  async function create(e: FormEvent) {
    e.preventDefault()
    if (!name.trim()) return
    setSaving(true)
    setErr('')
    try {
      await createMailingList({ name: name.trim(), is_shared: manage ? shared : false })
      setName('')
      setShared(false)
      setFormOpen(false)
      await load()
    } catch (ex) {
      setErr(errorText(ex, 'Nie udało się założyć grupy.'))
    } finally {
      setSaving(false)
    }
  }

  async function confirmDelete() {
    if (!toDelete) return
    setDeleting(true)
    setDeleteErr('')
    try {
      await deleteMailingList(toDelete.id)
      setToDelete(null)
      await load()
    } catch (ex) {
      setDeleteErr(errorText(ex, 'Nie udało się usunąć grupy.'))
    } finally {
      setDeleting(false)
    }
  }

  return (
    <>
      <div className="app-page-head mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="app-page-title text-xl font-semibold">Grupy odbiorców</h1>
          <p className="mt-1 max-w-3xl text-xs text-slate-600">
            Każdy adres ma zapisaną podstawę wysyłki: stały klient albo zgoda (skąd, kiedy i kto ją wpisał). Bez
            podstawy adres nie dostanie kampanii.
          </p>
        </div>
        <button type="button" className={BTN_PRIMARY} onClick={() => setFormOpen((v) => !v)}>
          + Nowa grupa
        </button>
      </div>

      <CampaignsTabs active="groups" />

      <ErrorBar message={err} onClose={() => setErr('')} />

      {formOpen && (
        <form onSubmit={create} className="mb-4 flex flex-wrap items-end gap-3 rounded-xl bg-white p-4 text-xs shadow-sm">
          <label className="flex min-w-[16rem] flex-1 flex-col gap-1 text-slate-600">
            Nazwa grupy
            <input
              autoFocus
              required
              maxLength={150}
              className={INPUT}
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder="np. Stali klienci – przemysł Podkarpacie"
            />
          </label>
          {manage && (
            <label className="flex items-center gap-1.5 pb-1 text-slate-700" title="Wspólną grupę widzą i wybierają wszyscy handlowcy">
              <input type="checkbox" checked={shared} onChange={(e) => setShared(e.target.checked)} />
              Wspólna dla wszystkich handlowców
            </label>
          )}
          <button type="submit" className={BTN_PRIMARY} disabled={saving || !name.trim()}>
            {saving ? 'Zapisuję…' : 'Załóż grupę'}
          </button>
          <button type="button" className={BTN} onClick={() => setFormOpen(false)}>
            Anuluj
          </button>
        </form>
      )}

      <div className="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_300px]">
        <div className="overflow-x-auto rounded-xl bg-white p-4 shadow-sm">
          <table className="w-full text-left text-xs">
            <thead>
              <tr className="border-b bg-slate-50">
                <th className="p-2">Grupa</th>
                <th className="p-2 text-right">Adresy</th>
                <th className="p-2">Podstawa wysyłki</th>
                <th className="p-2">Zmieniona</th>
                <th className="p-2" />
              </tr>
            </thead>
            <tbody>
              {(lists ?? []).map((l) => (
                <tr key={l.id} className="border-b align-top">
                  <td className="min-w-[14rem] p-2">
                    <Link to={`/kampanie/grupy/${l.id}`} className="font-medium text-slate-900 hover:text-blue-700 hover:underline">
                      {l.name}
                    </Link>
                    <div className="text-[11px] text-slate-500">
                      {l.is_shared
                        ? `wspólna, prowadzi ${l.owner.name}`
                        : l.owner.id === user?.id
                          ? 'moja'
                          : `prowadzi ${l.owner.name}`}
                    </div>
                  </td>
                  <td className="p-2 text-right tabular-nums">{fmtInt(l.contacts_count)}</td>
                  <td className="p-2">
                    <span className="flex flex-wrap gap-1">
                      {l.basis_counts.customer > 0 && <Chip tone="green">stały klient {fmtInt(l.basis_counts.customer)}</Chip>}
                      {l.basis_counts.consent > 0 && <Chip tone="green">zgoda {fmtInt(l.basis_counts.consent)}</Chip>}
                      {l.contacts_count === 0 && <span className="text-slate-400">pusta</span>}
                    </span>
                  </td>
                  <td className="whitespace-nowrap p-2 tabular-nums text-slate-600">{fmtDate(l.updated_at)}</td>
                  <td className="whitespace-nowrap p-2 text-right">
                    <span className="inline-flex gap-1">
                      <Link to={`/kampanie/grupy/${l.id}`} className={BTN_SM}>
                        {l.can_edit ? 'Otwórz / dodaj adresy' : 'Otwórz'}
                      </Link>
                      {l.can_edit && (
                        <button
                          type="button"
                          className={`${BTN_SM} text-red-700`}
                          onClick={() => {
                            setDeleteErr('')
                            setToDelete(l)
                          }}
                        >
                          Usuń
                        </button>
                      )}
                    </span>
                  </td>
                </tr>
              ))}
              {lists !== null && lists.length === 0 && (
                <tr>
                  <td colSpan={5} className="p-8 text-center text-slate-500">
                    Nie masz jeszcze grup. Załóż grupę i wklej do niej adresy klientów.
                  </td>
                </tr>
              )}
              {lists === null && !err && (
                <tr>
                  <td colSpan={5} className="p-8 text-center text-slate-500">
                    Ładowanie…
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
        <aside className="rounded-xl bg-white p-4 text-xs text-slate-600 shadow-sm">
          <h2 className="app-card-title mb-2 text-sm font-semibold text-slate-900">Dodawanie adresów</h2>
          <p>Adresy wklejasz w grupie. Przy imporcie wybierasz podstawę dla całej paczki:</p>
          <ul className="my-2 space-y-1.5">
            <li>
              <span className="font-bold text-emerald-700">✓</span> <b className="text-slate-800">Stały klient</b>: kupuje u
              nas (większość adresów).
            </li>
            <li>
              <span className="font-bold text-emerald-700">✓</span> <b className="text-slate-800">Zgoda</b>: skąd (formularz,
              umowa, targi z podpisem) i kiedy.
            </li>
          </ul>
          <p className="mb-2">Zapytanie klienta z Thunderbirda nie jest zgodą na mailing, więc te adresy nie dodają się same.</p>
          <p className="mb-2">
            Klientów z ERP XL (kupowali te towary, moi klienci) wybierasz w kampanii, w kroku „Odbiorcy” — nie trzeba ich
            wklejać do grupy.
          </p>
          <p>Adres powtórzony w kilku grupach dostanie jeden mail. Wypisany adres jest pomijany we wszystkich kampaniach wszystkich handlowców.</p>
        </aside>
      </div>

      {toDelete && (
        <ConfirmDialog
          title="Usunąć grupę?"
          danger
          confirmLabel="Usuń grupę"
          busy={deleting}
          error={deleteErr}
          onClose={() => setToDelete(null)}
          onConfirm={() => void confirmDelete()}
          message={
            <>
              <p>
                <b>{toDelete.name}</b> — {fmtInt(toDelete.contacts_count)}{' '}
                {plural(toDelete.contacts_count, 'adres', 'adresy', 'adresów')}.
              </p>
              <p className="text-xs text-slate-600">
                Adresy, które są też w innych grupach, tam zostaną. Wysłanych kampanii to nie zmienia.
              </p>
            </>
          }
        />
      )}
    </>
  )
}
