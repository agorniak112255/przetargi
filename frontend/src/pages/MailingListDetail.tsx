import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react'
import { Link, useParams } from 'react-router-dom'
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
  Pager,
} from '../components/CampaignsUi'
import { errorText, fmtDate, fmtInt } from '../lib/campaignFormat'
import { can } from '../lib/api'
import {
  importMailingListContacts,
  listMailingLists,
  mailingListContacts,
  removeMailingListContact,
  updateMailingList,
  type ContactBasis,
  type ImportResult,
  type MailingList,
  type MailingListContact,
  type PageMeta,
} from '../lib/campaigns'
import { plural } from '../lib/plural'

/** Szczegóły grupy odbiorców: kontakty (szukaj, stronicowanie, usuwanie) i import wklejonych adresów. */

const BASIS_LABEL: Record<ContactBasis, string> = {
  customer: 'stały klient',
  consent: 'zgoda',
}

const SEARCH_DEBOUNCE_MS = 300

export function MailingListDetail() {
  const { id } = useParams()
  const listId = Number(id)
  const { user } = useAuth()
  const manage = can(user, 'campaigns.manage')

  const [list, setList] = useState<MailingList | null>(null)
  const [listErr, setListErr] = useState('')
  const [err, setErr] = useState('')

  const [searchInput, setSearchInput] = useState('')
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [contacts, setContacts] = useState<MailingListContact[] | null>(null)
  const [meta, setMeta] = useState<PageMeta | null>(null)
  const [loading, setLoading] = useState(false)
  const seq = useRef(0)

  const [nameDraft, setNameDraft] = useState('')

  const [text, setText] = useState('')
  const [basis, setBasis] = useState<ContactBasis>('customer')
  const [basisNote, setBasisNote] = useState('')
  const [importing, setImporting] = useState(false)
  const [importResult, setImportResult] = useState<ImportResult | null>(null)
  // jeden odbiorca — osobne pola zamiast wklejania
  const [oneEmail, setOneEmail] = useState('')
  const [oneName, setOneName] = useState('')
  const [oneCompany, setOneCompany] = useState('')
  const [formMsg, setFormMsg] = useState('')

  const [toRemove, setToRemove] = useState<MailingListContact | null>(null)
  const [removing, setRemoving] = useState(false)
  const [removeErr, setRemoveErr] = useState('')

  // Brak osobnego GET jednej grupy — bierzemy ją z listy grup (widoczne tylko własne i wspólne).
  const loadList = useCallback(async () => {
    try {
      const res = await listMailingLists()
      const found = res.data.find((l) => l.id === listId) ?? null
      setList(found)
      if (found) setNameDraft(found.name)
      setListErr(found ? '' : 'Nie ma takiej grupy albo nie masz do niej dostępu.')
    } catch (ex) {
      setListErr(errorText(ex, 'Nie udało się wczytać grupy.'))
    }
  }, [listId])

  useEffect(() => {
    void loadList()
  }, [loadList])

  useEffect(() => {
    const t = window.setTimeout(() => {
      setSearch(searchInput.trim())
      setPage(1)
    }, SEARCH_DEBOUNCE_MS)
    return () => window.clearTimeout(t)
  }, [searchInput])

  const loadContacts = useCallback(async () => {
    if (!Number.isFinite(listId) || listId <= 0) return
    const my = ++seq.current
    setLoading(true)
    try {
      const res = await mailingListContacts(listId, { search, page })
      if (my !== seq.current) return
      setContacts(res.data)
      setMeta(res.meta)
    } catch (ex) {
      if (my === seq.current) setErr(errorText(ex, 'Nie udało się wczytać adresów.'))
    } finally {
      if (my === seq.current) setLoading(false)
    }
  }, [listId, search, page])

  useEffect(() => {
    void loadContacts()
  }, [loadContacts])

  async function saveName() {
    if (!list || !list.can_edit) return
    const name = nameDraft.trim()
    if (!name || name === list.name) {
      setNameDraft(list.name)
      return
    }
    try {
      setList(await updateMailingList(list.id, { name }))
    } catch (ex) {
      setErr(errorText(ex, 'Nie udało się zmienić nazwy.'))
      setNameDraft(list.name)
    }
  }

  async function toggleShared(value: boolean) {
    if (!list) return
    try {
      setList(await updateMailingList(list.id, { is_shared: value }))
    } catch (ex) {
      setErr(errorText(ex, 'Nie udało się zmienić rodzaju grupy.'))
    }
  }

  /** Wspólny zapis: pojedynczy odbiorca i wklejona paczka idą tym samym importem (podstawa wysyłki dla wszystkich). */
  async function importText(payload: string): Promise<boolean> {
    if (basis === 'consent' && !basisNote.trim()) {
      setFormMsg('Przy podstawie „Zgoda” wpisz, skąd jest zgoda (np. formularz www 2025).')
      return false
    }
    setImporting(true)
    setErr('')
    setFormMsg('')
    setImportResult(null)
    try {
      const res = await importMailingListContacts(listId, {
        text: payload,
        basis,
        basis_note: basisNote.trim() || undefined,
      })
      setImportResult(res)
      await Promise.all([loadContacts(), loadList()])
      return true
    } catch (ex) {
      setErr(errorText(ex, 'Nie udało się dodać adresów.'))
      return false
    } finally {
      setImporting(false)
    }
  }

  async function runImport(e: FormEvent) {
    e.preventDefault()
    if (!text.trim()) {
      setFormMsg('Wklej albo wpisz co najmniej jeden adres e-mail (jeden w wierszu).')
      return
    }
    if (await importText(text)) setText('')
  }

  async function addOne(e: FormEvent) {
    e.preventDefault()
    const email = oneEmail.trim()
    if (!email) {
      setFormMsg('Wpisz adres e-mail odbiorcy.')
      return
    }
    // separatory importu (; , tabulator) w nazwie rozbiłyby wiersz na kolumny
    const clean = (v: string) => v.replace(/[;,\t]+/g, ' ').trim()
    if (await importText([email, clean(oneName), clean(oneCompany)].join(';'))) {
      setOneEmail('')
      setOneName('')
      setOneCompany('')
    }
  }

  async function confirmRemove() {
    if (!toRemove) return
    setRemoving(true)
    setRemoveErr('')
    try {
      await removeMailingListContact(listId, toRemove.id)
      setToRemove(null)
      await Promise.all([loadContacts(), loadList()])
    } catch (ex) {
      setRemoveErr(errorText(ex, 'Nie udało się usunąć adresu z grupy.'))
    } finally {
      setRemoving(false)
    }
  }

  const canEdit = Boolean(list?.can_edit)
  const lines = text.split(/\r?\n/).filter((l) => l.trim() !== '').length

  return (
    <div>
      <div className="app-page-head mb-4 flex flex-wrap items-end justify-between gap-3">
        <div className="min-w-0">
          <Link to="/kampanie?tab=grupy" className="app-back text-xs text-blue-600 hover:underline">
            ← Grupy odbiorców
          </Link>
          {canEdit ? (
            <input
              aria-label="Nazwa grupy"
              maxLength={150}
              className="app-page-title mt-1 block w-full max-w-xl rounded border border-transparent bg-transparent px-1 text-xl font-semibold hover:border-slate-300 focus:border-slate-400"
              value={nameDraft}
              onChange={(e) => setNameDraft(e.target.value)}
              onBlur={() => void saveName()}
              onKeyDown={(e) => {
                if (e.key === 'Enter') e.currentTarget.blur()
              }}
            />
          ) : (
            <h1 className="app-page-title mt-1 text-xl font-semibold">{list?.name ?? 'Grupa odbiorców'}</h1>
          )}
          {list && (
            <p className="mt-1 flex flex-wrap items-center gap-2 text-xs text-slate-600">
              <span>
                {list.is_shared ? `Wspólna, prowadzi ${list.owner.name}` : list.owner.id === user?.id ? 'Moja grupa' : `Prowadzi ${list.owner.name}`}
              </span>
              <span>·</span>
              <span>
                {fmtInt(list.contacts_count)} {plural(list.contacts_count, 'adres', 'adresy', 'adresów')}
              </span>
              {list.basis_counts.customer > 0 && <Chip tone="green">stały klient {fmtInt(list.basis_counts.customer)}</Chip>}
              {list.basis_counts.consent > 0 && <Chip tone="green">zgoda {fmtInt(list.basis_counts.consent)}</Chip>}
              {manage && canEdit && (
                <label className="ml-2 inline-flex items-center gap-1.5">
                  <input type="checkbox" checked={list.is_shared} onChange={(e) => void toggleShared(e.target.checked)} />
                  wspólna dla wszystkich handlowców
                </label>
              )}
            </p>
          )}
        </div>
      </div>

      <CampaignsTabs active="groups" />

      <ErrorBar message={listErr} />
      <ErrorBar message={err} onClose={() => setErr('')} />

      <div className="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_340px]">
        <div className="overflow-x-auto rounded-xl bg-white p-4 shadow-sm">
          <div className="mb-2 flex flex-wrap items-center justify-between gap-2 text-xs">
            <input
              type="search"
              className={`${INPUT} w-72`}
              placeholder="Szukaj: adres, nazwisko, firma"
              value={searchInput}
              onChange={(e) => setSearchInput(e.target.value)}
            />
            <span className="text-slate-500">
              {meta ? `Łącznie ${fmtInt(meta.total)}` : ''}
              {loading ? ' · ładowanie…' : ''}
            </span>
          </div>
          <table className="w-full text-left text-xs">
            <thead>
              <tr className="border-b bg-slate-50">
                <th className="p-2">Adres</th>
                <th className="p-2">Osoba / firma</th>
                <th className="p-2">Podstawa wysyłki</th>
                <th className="p-2">Dodany</th>
                {canEdit && <th className="p-2" />}
              </tr>
            </thead>
            <tbody>
              {(contacts ?? []).map((c) => (
                <tr key={c.id} className="border-b align-top">
                  <td className="p-2">
                    <span className="font-mono text-slate-900">{c.email}</span>
                    {c.suppressed && (
                      <div className="mt-0.5">
                        <Chip tone="red" title="Adres jest na liście wypisanych — nie dostanie kampanii">
                          wypisany
                        </Chip>
                      </div>
                    )}
                  </td>
                  <td className="p-2 text-slate-700">
                    {c.name || <span className="text-slate-400">—</span>}
                    {c.company && <div className="text-[11px] text-slate-500">{c.company}</div>}
                  </td>
                  <td className="p-2">
                    <Chip tone="green">{BASIS_LABEL[c.basis] ?? c.basis}</Chip>
                    {c.basis_note && <div className="mt-0.5 text-[11px] text-slate-500">{c.basis_note}</div>}
                  </td>
                  <td className="whitespace-nowrap p-2 tabular-nums text-slate-600">{fmtDate(c.added_at)}</td>
                  {canEdit && (
                    <td className="p-2 text-right">
                      <button
                        type="button"
                        className={`${BTN_SM} text-red-700`}
                        onClick={() => {
                          setRemoveErr('')
                          setToRemove(c)
                        }}
                      >
                        Usuń z grupy
                      </button>
                    </td>
                  )}
                </tr>
              ))}
              {contacts !== null && contacts.length === 0 && (
                <tr>
                  <td colSpan={canEdit ? 5 : 4} className="p-8 text-center text-slate-500">
                    {search ? 'Brak adresów pasujących do wyszukiwania.' : 'Grupa jest pusta. Wklej adresy obok.'}
                  </td>
                </tr>
              )}
            </tbody>
          </table>
          <Pager meta={meta} disabled={loading} onPage={setPage} />
        </div>

        <aside className="rounded-xl bg-white p-4 text-xs shadow-sm">
          <h2 className="app-card-title mb-2 text-sm font-semibold text-slate-900">Dodaj adresy</h2>
          {!canEdit ? (
            <p className="text-slate-600">
              {list ? 'To wspólna grupa — adresy dodaje administrator.' : 'Ładowanie…'}
            </p>
          ) : (
            <div className="space-y-4">
              <fieldset>
                <legend className="mb-1 text-slate-600">Podstawa wysyłki</legend>
                <label className="flex items-start gap-2 py-0.5">
                  <input type="radio" name="basis" checked={basis === 'customer'} onChange={() => setBasis('customer')} />
                  <span>
                    <b className="text-slate-800">Stały klient</b> (kupuje u nas)
                  </span>
                </label>
                <label className="flex items-start gap-2 py-0.5">
                  <input type="radio" name="basis" checked={basis === 'consent'} onChange={() => setBasis('consent')} />
                  <span>
                    <b className="text-slate-800">Zgoda</b> na informacje handlowe
                  </span>
                </label>
                <label className="mt-1 block text-slate-600">
                  {basis === 'consent' ? 'Skąd zgoda (wymagane)' : 'Uwaga (opcjonalnie)'}
                  <input
                    className={`${INPUT} mt-1 block w-full`}
                    maxLength={255}
                    value={basisNote}
                    onChange={(e) => setBasisNote(e.target.value)}
                    placeholder={basis === 'consent' ? 'np. formularz www 2025, targi Kielce 2026 z podpisem' : 'np. klienci z Podkarpacia'}
                  />
                </label>
              </fieldset>

              <form onSubmit={addOne} className="space-y-2 rounded border border-slate-200 p-3">
                <p className="font-semibold text-slate-900">Jeden odbiorca</p>
                <label className="block text-slate-600">
                  Adres e-mail
                  <input
                    type="email"
                    className={`${INPUT} mt-1 block w-full`}
                    value={oneEmail}
                    onChange={(e) => setOneEmail(e.target.value)}
                    autoComplete="off"
                  />
                </label>
                <label className="block text-slate-600">
                  Imię i nazwisko (opcjonalnie)
                  <input className={`${INPUT} mt-1 block w-full`} maxLength={200} value={oneName} onChange={(e) => setOneName(e.target.value)} />
                </label>
                <label className="block text-slate-600">
                  Firma (opcjonalnie)
                  <input className={`${INPUT} mt-1 block w-full`} maxLength={250} value={oneCompany} onChange={(e) => setOneCompany(e.target.value)} />
                </label>
                <button type="submit" className={`${BTN_PRIMARY} w-full`} disabled={importing}>
                  {importing ? 'Dodaję…' : 'Dodaj odbiorcę'}
                </button>
              </form>

              <form onSubmit={runImport} className="space-y-2 rounded border border-slate-200 p-3">
                <p className="font-semibold text-slate-900">Wiele adresów naraz</p>
                <label className="block text-slate-600">
                  Wklej adresy — jeden w wierszu; można z kolumnami z Excela
                  <textarea
                    className={`${INPUT} mt-1 block h-32 w-full font-mono`}
                    value={text}
                    onChange={(e) => setText(e.target.value)}
                  />
                  <span className="mt-0.5 block text-[11px] text-slate-500">
                    Przykład wiersza: <span className="font-mono">jan.kowalski@firma.pl;Jan Kowalski;Firma Sp. z o.o.</span> —
                    albo sam adres. Separator: średnik, przecinek albo tabulator.
                    {lines > 0 && ` Wierszy: ${fmtInt(lines)}.`}
                  </span>
                </label>
                <button type="submit" className={`${BTN_PRIMARY} w-full`} disabled={importing}>
                  {importing ? 'Dodaję…' : lines > 0 ? `Dodaj ${fmtInt(lines)} ${plural(lines, 'adres', 'adresy', 'adresów')}` : 'Dodaj adresy'}
                </button>
              </form>

              {formMsg && <p className="rounded bg-amber-50 px-2 py-1.5 text-amber-900">{formMsg}</p>}
            </div>
          )}

          {importResult && (
            <div className="mt-3 rounded border border-slate-200 bg-slate-50 p-3 text-slate-700" role="status">
              <p className="font-medium text-slate-900">Wynik importu</p>
              <ul className="mt-1 space-y-0.5">
                <li>Dodane: {fmtInt(importResult.added)}</li>
                <li>Już były w grupie: {fmtInt(importResult.already)}</li>
                {importResult.suppressed > 0 && (
                  <li className="text-amber-800">
                    Na liście wypisanych: {fmtInt(importResult.suppressed)} — są w grupie, ale nie dostaną kampanii
                  </li>
                )}
                <li className={importResult.invalid.length > 0 ? 'text-red-700' : ''}>
                  Niepoprawne: {fmtInt(importResult.invalid.length)}
                </li>
              </ul>
              {importResult.invalid.length > 0 && (
                <details className="mt-1">
                  <summary className="cursor-pointer text-[11px] text-slate-600">Pokaż niepoprawne wiersze</summary>
                  <ul className="mt-1 max-h-32 overflow-y-auto font-mono text-[11px] text-red-700">
                    {importResult.invalid.map((v, i) => (
                      <li key={i} className="break-all">
                        {v}
                      </li>
                    ))}
                  </ul>
                  {importResult.invalid.length >= 50 && (
                    <p className="text-[11px] text-slate-500">Pokazano pierwsze 50.</p>
                  )}
                </details>
              )}
              <button type="button" className={`${BTN} mt-2`} onClick={() => setImportResult(null)}>
                Zamknij wynik
              </button>
            </div>
          )}
        </aside>
      </div>

      {toRemove && (
        <ConfirmDialog
          title="Usunąć adres z grupy?"
          confirmLabel="Usuń z grupy"
          danger
          busy={removing}
          error={removeErr}
          onClose={() => setToRemove(null)}
          onConfirm={() => void confirmRemove()}
          message={
            <>
              <p className="font-mono">{toRemove.email}</p>
              <p className="text-xs text-slate-600">
                Adres zniknie tylko z tej grupy. Żeby nigdy nie dostał kampanii, trzeba go dodać do listy wypisanych.
              </p>
            </>
          }
        />
      )}
    </div>
  )
}
