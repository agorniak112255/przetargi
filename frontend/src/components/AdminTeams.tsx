import { useEffect, useMemo, useState, type FormEvent } from 'react'
import { api } from '../lib/api'
import { plural } from '../lib/plural'
import { sortTeamsByName, type Team, type TeamPayload, type TeamUserOption, type TeamsResponse } from '../lib/teams'

/** Otwarty formularz zespołu: id null = nowy; members = id osoby → czy jest kierownikiem zespołu. */
type Draft = {
  id: number | null
  name: string
  members: Record<number, boolean>
}

/**
 * Zespoły w Administracji → Role: kierownik zespołu widzi w Raportach wynik kampanii członków swoich zespołów.
 * Niezależne od ról uprawnień — każdy ma jedną rolę, a do zespołów może należeć kilku.
 */
export function AdminTeams() {
  const [teams, setTeams] = useState<Team[]>([])
  const [users, setUsers] = useState<TeamUserOption[]>([])
  const [loaded, setLoaded] = useState(false)
  const [draft, setDraft] = useState<Draft | null>(null)
  const [filter, setFilter] = useState('')
  const [err, setErr] = useState('')
  const [msg, setMsg] = useState('')
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    let cancelled = false
    api<TeamsResponse>('/admin/teams')
      .then((data) => {
        if (cancelled) return
        setTeams(data.data)
        setUsers(data.users)
        setLoaded(true)
      })
      .catch((e: Error) => {
        if (!cancelled) setErr(e.message)
      })
    return () => {
      cancelled = true
    }
  }, [])

  function openNew() {
    setDraft({ id: null, name: '', members: {} })
    setFilter('')
    setErr('')
    setMsg('')
  }

  function openEdit(team: Team) {
    const members: Record<number, boolean> = {}
    for (const m of team.members) members[m.user_id] = m.is_leader
    setDraft({ id: team.id, name: team.name, members })
    setFilter('')
    setErr('')
    setMsg('')
  }

  function toggleMember(userId: number) {
    setDraft((prev) => {
      if (!prev) return prev
      const members = { ...prev.members }
      if (userId in members) delete members[userId]
      else members[userId] = false
      return { ...prev, members }
    })
  }

  function toggleLeader(userId: number) {
    setDraft((prev) => {
      if (!prev) return prev
      // zaznaczenie kierownika dopisuje osobę do zespołu
      return { ...prev, members: { ...prev.members, [userId]: !prev.members[userId] } }
    })
  }

  async function onSave(e: FormEvent) {
    e.preventDefault()
    if (!draft) return
    const payload: TeamPayload = {
      name: draft.name.trim(),
      members: Object.entries(draft.members).map(([id, isLeader]) => ({ user_id: Number(id), is_leader: isLeader })),
    }
    setBusy(true)
    setErr('')
    setMsg('')
    try {
      if (draft.id === null) {
        const res = await api<{ data: Team }>('/admin/teams', { method: 'POST', body: JSON.stringify(payload) })
        setTeams((prev) => sortTeamsByName([...prev, res.data]))
        setMsg(`Dodano zespół „${res.data.name}”.`)
      } else {
        const res = await api<{ data: Team }>(`/admin/teams/${draft.id}`, {
          method: 'PUT',
          body: JSON.stringify(payload),
        })
        setTeams((prev) => sortTeamsByName(prev.map((t) => (t.id === res.data.id ? res.data : t))))
        setMsg(`Zapisano zespół „${res.data.name}”.`)
      }
      setDraft(null)
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Błąd')
    } finally {
      setBusy(false)
    }
  }

  async function onDelete(team: Team) {
    if (
      !confirm(
        `Usunąć zespół „${team.name}”? Jego kierownicy przestaną widzieć w Raportach wynik kampanii członków tego zespołu.`,
      )
    )
      return
    setBusy(true)
    setErr('')
    setMsg('')
    try {
      await api(`/admin/teams/${team.id}`, { method: 'DELETE' })
      setTeams((prev) => prev.filter((t) => t.id !== team.id))
      if (draft?.id === team.id) setDraft(null)
      setMsg(`Usunięto zespół „${team.name}”.`)
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Błąd')
    } finally {
      setBusy(false)
    }
  }

  const visibleUsers = useMemo(() => {
    const q = filter.trim().toLocaleLowerCase('pl')
    if (q === '') return users
    return users.filter((u) => u.name.toLocaleLowerCase('pl').includes(q))
  }, [users, filter])

  const draftCount = draft ? Object.keys(draft.members).length : 0
  const draftLeaders = draft ? Object.values(draft.members).filter(Boolean).length : 0

  return (
    <section className="mt-8 max-w-4xl rounded-xl bg-white p-4 shadow-sm">
      <div className="mb-1 flex flex-wrap items-center justify-between gap-2">
        <h2 className="text-sm font-semibold text-slate-800">Zespoły — kto widzi czyje wyniki kampanii</h2>
        {draft === null && (
          <button
            type="button"
            disabled={busy || !loaded}
            onClick={openNew}
            className="rounded bg-blue-600 px-3 py-1.5 text-sm text-white hover:bg-blue-700 disabled:opacity-50"
          >
            Dodaj zespół
          </button>
        )}
      </div>
      <p className="mb-3 text-xs text-slate-500">
        Kierownik zespołu widzi w Raportach wynik kampanii wszystkich członków swoich zespołów, pozostali członkowie
        widzą tylko własne kampanie. Osoba z uprawnieniem „Wynik kampanii — wszyscy pracownicy” widzi wszystkich bez
        przypisania do zespołu.
      </p>

      {err && <p className="mb-2 text-sm text-red-600">{err}</p>}
      {msg && <p className="mb-2 text-sm text-green-700">{msg}</p>}

      {draft !== null && (
        <form onSubmit={(e) => void onSave(e)} className="mb-4 rounded-lg border border-slate-200 p-3">
          <h3 className="mb-2 text-sm font-semibold text-slate-800">
            {draft.id === null ? 'Nowy zespół' : `Zmiana zespołu „${teams.find((t) => t.id === draft.id)?.name ?? draft.name}”`}
          </h3>
          <label className="mb-3 flex max-w-md flex-col gap-1 text-xs text-slate-600">
            Nazwa zespołu
            <input
              className="rounded border px-2 py-1.5 text-sm text-slate-900"
              value={draft.name}
              onChange={(e) => setDraft({ ...draft, name: e.target.value })}
              maxLength={100}
              required
              autoFocus
              placeholder="Np. Handel Kraków"
            />
          </label>

          <div className="mb-2 flex flex-wrap items-center gap-3 text-xs text-slate-600">
            <span>
              W zespole: {draftCount} {plural(draftCount, 'osoba', 'osoby', 'osób')}, w tym kierowników: {draftLeaders}
            </span>
            {draftCount > 0 && draftLeaders === 0 && (
              <span className="text-amber-700">Zespół bez kierownika nie zmienia, kto co widzi.</span>
            )}
            <input
              className="ml-auto w-56 rounded border px-2 py-1 text-sm text-slate-900"
              value={filter}
              onChange={(e) => setFilter(e.target.value)}
              placeholder="Szukaj osoby"
              aria-label="Szukaj osoby na liście"
            />
          </div>

          <ul className="mb-3 max-h-80 divide-y divide-slate-100 overflow-auto rounded border border-slate-100">
            {visibleUsers.map((u) => {
              const isMember = u.id in draft.members
              return (
                <li key={u.id} className="flex flex-wrap items-center gap-x-4 gap-y-1 px-3 py-1.5 hover:bg-slate-50">
                  <label className="flex min-w-0 flex-1 cursor-pointer items-center gap-2 text-sm text-slate-900">
                    <input type="checkbox" checked={isMember} onChange={() => toggleMember(u.id)} />
                    <span className="truncate">{u.name}</span>
                    <span className="text-xs text-slate-500">{u.role}</span>
                  </label>
                  <label className="flex cursor-pointer items-center gap-2 text-xs text-slate-600">
                    <input
                      type="checkbox"
                      checked={draft.members[u.id] === true}
                      onChange={() => toggleLeader(u.id)}
                    />
                    kierownik zespołu
                  </label>
                </li>
              )
            })}
            {visibleUsers.length === 0 && <li className="px-3 py-2 text-xs text-slate-500">Nikt nie pasuje do wyszukiwania.</li>}
          </ul>

          <div className="flex flex-wrap gap-2">
            <button
              type="submit"
              disabled={busy || draft.name.trim() === ''}
              className="rounded bg-blue-600 px-3 py-1.5 text-sm text-white hover:bg-blue-700 disabled:opacity-50"
            >
              {draft.id === null ? 'Dodaj zespół' : 'Zapisz zespół'}
            </button>
            <button
              type="button"
              disabled={busy}
              onClick={() => setDraft(null)}
              className="rounded bg-slate-200 px-3 py-1.5 text-sm text-slate-800"
            >
              Anuluj
            </button>
          </div>
        </form>
      )}

      {loaded && teams.length === 0 && (
        <p className="text-sm text-slate-500">Nie ma jeszcze żadnego zespołu — każdy widzi tylko własne kampanie.</p>
      )}

      {teams.length > 0 && (
        <div className="overflow-x-auto">
          <table className="w-full text-left text-sm">
            <thead>
              <tr className="border-b border-slate-200 text-xs text-slate-500">
                <th className="py-1.5 pr-3 font-medium">Zespół</th>
                <th className="py-1.5 pr-3 font-medium">Kierownicy</th>
                <th className="py-1.5 pr-3 font-medium">Członkowie</th>
                <th className="py-1.5 font-medium" />
              </tr>
            </thead>
            <tbody>
              {teams.map((team) => {
                const leaders = team.members.filter((m) => m.is_leader)
                const others = team.members.filter((m) => !m.is_leader)
                return (
                  <tr key={team.id} className="border-b border-slate-100 align-top">
                    <td className="py-2 pr-3 font-medium text-slate-900">{team.name}</td>
                    <td className="py-2 pr-3 text-slate-700">
                      {leaders.length > 0 ? (
                        leaders.map((m) => m.name).join(', ')
                      ) : (
                        <span className="text-xs text-amber-700">brak kierownika — zespół nie zmienia, kto co widzi</span>
                      )}
                    </td>
                    <td className="py-2 pr-3 text-slate-700">
                      {others.length > 0 ? (
                        others.map((m) => m.name).join(', ')
                      ) : (
                        <span className="text-xs text-slate-500">brak</span>
                      )}
                    </td>
                    <td className="whitespace-nowrap py-2 text-right">
                      <button
                        type="button"
                        disabled={busy}
                        onClick={() => openEdit(team)}
                        className="mr-2 rounded bg-slate-200 px-2 py-1 text-xs text-slate-800 hover:bg-slate-300"
                      >
                        Zmień
                      </button>
                      <button
                        type="button"
                        disabled={busy}
                        onClick={() => void onDelete(team)}
                        className="rounded bg-red-100 px-2 py-1 text-xs text-red-700"
                      >
                        Usuń
                      </button>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      )}
    </section>
  )
}
