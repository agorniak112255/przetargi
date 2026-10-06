import { useEffect, useMemo, useState, type FormEvent } from 'react'
import { api } from '../lib/api'
import { AdminLocalNetworks } from '../components/AdminLocalNetworks'
import { AdminTeams } from '../components/AdminTeams'

type RoleRow = {
  id: number
  name: string
  label?: string
  is_system?: boolean
  users_count?: number
  permissions: string[]
  /** dostęp z sieci całej grupy; konto z własnym ustawieniem go nie dziedziczy */
  network_access?: 'any' | 'local'
  /** osoby, których oferty widzi rola z uprawnieniem „Oferty — podgląd ofert wybranych osób” */
  offer_visible_user_ids?: number[]
}

type UserOption = {
  id: number
  name: string
}

/** Uprawnienie, bez którego lista osób (oferty do podglądu) nic nie daje. */
const OFFERS_VIEW_SELECTED = 'offers.view_selected'
const OFFERS_VIEW_ALL = 'offers.view_all'
/** Od tylu osób pokazujemy wyszukiwarkę nad listą. */
const USER_SEARCH_FROM = 10

type PermissionDef = {
  key: string
  label: string
  description: string
  group: string
}

type RolesResponse = {
  roles: RoleRow[]
  users?: UserOption[]
  all_permissions: string[]
  permission_definitions: PermissionDef[]
}

export function AdminRoles() {
  const [roles, setRoles] = useState<RoleRow[]>([])
  const [definitions, setDefinitions] = useState<PermissionDef[]>([])
  const [selected, setSelected] = useState<string | null>(null)
  const [checked, setChecked] = useState<Set<string>>(new Set())
  const [err, setErr] = useState('')
  const [msg, setMsg] = useState('')
  const [busy, setBusy] = useState(false)
  const [newCode, setNewCode] = useState('')
  const [newLabel, setNewLabel] = useState('')
  const [copyFrom, setCopyFrom] = useState('handlowiec')
  // null = nie edytujemy; obiekt = otwarte pola zmiany nazwy i kodu wybranej roli
  const [renaming, setRenaming] = useState<{ label: string; code: string } | null>(null)
  const [users, setUsers] = useState<UserOption[]>([])
  // osoby zaznaczone na ekranie (oferty do podglądu); zapis osobno przyciskiem „Zapisz osoby”
  const [viewers, setViewers] = useState<Set<number>>(new Set())
  const [viewerQuery, setViewerQuery] = useState('')

  async function load() {
    const data = await api<RolesResponse>('/admin/roles')
    setRoles(data.roles)
    setUsers(data.users ?? [])
    setDefinitions(data.permission_definitions ?? [])
    if (!selected && data.roles[0]) {
      selectRole(data.roles[0])
    } else if (selected) {
      const role = data.roles.find((r) => r.name === selected)
      // ta sama rola po zapisie uprawnień: niezapisane zaznaczenia osób zostają na ekranie
      if (role) selectRole(role, true)
      else if (data.roles[0]) selectRole(data.roles[0])
    }
  }

  function selectRole(role: RoleRow, keepViewers = false) {
    setSelected(role.name)
    setChecked(new Set(role.permissions))
    if (!keepViewers) setViewers(new Set(role.offer_visible_user_ids ?? []))
    setRenaming(null)
  }

  useEffect(() => {
    void load().catch((e: Error) => setErr(e.message))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  function toggle(perm: string) {
    setChecked((prev) => {
      const next = new Set(prev)
      if (next.has(perm)) next.delete(perm)
      else next.add(perm)
      return next
    })
  }

  async function save() {
    if (!selected) return
    setBusy(true)
    setErr('')
    setMsg('')
    try {
      await api(`/admin/roles/${selected}`, {
        method: 'PUT',
        // known = uprawnienia pokazane na tej stronie; serwer nie ruszy innych (np. dodanych
        // wdrożeniem, gdy strona była już otwarta).
        body: JSON.stringify({ permissions: [...checked], known: definitions.map((d) => d.key) }),
      })
      setMsg('Zapisano uprawnienia roli.')
      await load()
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Błąd')
    } finally {
      setBusy(false)
    }
  }

  /** Dostęp z sieci grupy — zapis od razu, osobno od zaznaczeń uprawnień (te zostają na ekranie niezapisane). */
  async function saveNetworkAccess(mode: 'any' | 'local') {
    if (!selected) return
    setBusy(true)
    setErr('')
    setMsg('')
    try {
      const updated = await api<RoleRow>(`/admin/roles/${selected}/network-access`, {
        method: 'PATCH',
        body: JSON.stringify({ network_access: mode }),
      })
      setRoles((prev) => prev.map((r) => (r.name === updated.name ? { ...r, network_access: updated.network_access } : r)))
      setMsg(
        mode === 'local'
          ? `Grupa „${updated.label ?? updated.name}” pracuje tylko z sieci lokalnej (poza kontami z własnym ustawieniem).`
          : `Grupa „${updated.label ?? updated.name}” pracuje z każdej sieci (poza kontami z własnym ustawieniem).`,
      )
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Błąd')
    } finally {
      setBusy(false)
    }
  }

  /** Osoby, których oferty widzi rola — zapis osobno od zaznaczeń uprawnień (te zostają na ekranie niezapisane). */
  async function saveOfferViewers() {
    if (!selected) return
    // tylko osoby z bieżącej listy — usunięte konto odrzuciłby serwer
    const known = new Set(users.map((u) => u.id))
    const ids = [...viewers].filter((id) => known.has(id)).sort((a, b) => a - b)
    setBusy(true)
    setErr('')
    setMsg('')
    try {
      const updated = await api<RoleRow>(`/admin/roles/${selected}/offer-viewers`, {
        method: 'PATCH',
        body: JSON.stringify({ user_ids: ids }),
      })
      const saved = updated.offer_visible_user_ids ?? []
      setRoles((prev) =>
        prev.map((r) => (r.name === updated.name ? { ...r, offer_visible_user_ids: saved } : r)),
      )
      setViewers(new Set(saved))
      setMsg(
        `Zapisano osoby, których oferty widzi rola „${updated.label ?? updated.name}”: ${saved.length}.`,
      )
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Błąd')
    } finally {
      setBusy(false)
    }
  }

  function toggleViewer(id: number) {
    setViewers((prev) => {
      const next = new Set(prev)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })
  }

  async function onCreateRole(e: FormEvent) {
    e.preventDefault()
    setBusy(true)
    setErr('')
    setMsg('')
    try {
      const created = await api<RoleRow>('/admin/roles', {
        method: 'POST',
        body: JSON.stringify({
          name: newCode.trim(),
          display_name: newLabel.trim(),
          copy_from: copyFrom || null,
        }),
      })
      setNewCode('')
      setNewLabel('')
      setMsg(`Utworzono rolę „${created.label ?? created.name}”.`)
      await load()
      selectRole(created)
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Błąd')
    } finally {
      setBusy(false)
    }
  }

  async function onRenameRole(e: FormEvent) {
    e.preventDefault()
    if (!selected || renaming === null) return
    const oldCode = selected
    const code = renaming.code.trim()
    setBusy(true)
    setErr('')
    setMsg('')
    try {
      const updated = await api<RoleRow>(`/admin/roles/${oldCode}`, {
        method: 'PATCH',
        body: JSON.stringify({
          display_name: renaming.label.trim(),
          ...(code !== oldCode ? { name: code } : {}),
        }),
      })
      // Tylko nazwa i kod — niezapisane zaznaczenia uprawnień zostają na ekranie.
      setRoles((prev) =>
        prev.map((r) => (r.name === oldCode ? { ...r, name: updated.name, label: updated.label } : r)),
      )
      setSelected(updated.name)
      setCopyFrom((prev) => (prev === oldCode ? updated.name : prev))
      setRenaming(null)
      setMsg(
        updated.name !== oldCode
          ? `Zmieniono rolę: „${updated.label ?? updated.name}”, kod ${updated.name}.`
          : `Zmieniono nazwę roli na „${updated.label ?? updated.name}”.`,
      )
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Błąd')
    } finally {
      setBusy(false)
    }
  }

  async function onDeleteRole() {
    if (!selected) return
    const role = roles.find((r) => r.name === selected)
    if (!role || role.is_system) return
    if (!confirm(`Usunąć rolę „${role.label ?? role.name}”?`)) return
    setBusy(true)
    setErr('')
    setMsg('')
    try {
      await api(`/admin/roles/${selected}`, { method: 'DELETE' })
      setSelected(null)
      setMsg('Usunięto rolę.')
      await load()
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Błąd')
    } finally {
      setBusy(false)
    }
  }

  const grouped = useMemo(() => {
    const map = new Map<string, PermissionDef[]>()
    for (const def of definitions) {
      const list = map.get(def.group) ?? []
      list.push(def)
      map.set(def.group, list)
    }
    return [...map.entries()]
  }, [definitions])

  const selectedRole = roles.find((r) => r.name === selected)

  const savedViewers = new Set(selectedRole?.offer_visible_user_ids ?? [])
  const viewersDirty = viewers.size !== savedViewers.size || [...viewers].some((id) => !savedViewers.has(id))
  const viewerNeedle = viewerQuery.trim().toLocaleLowerCase('pl')
  const shownUsers =
    viewerNeedle === '' ? users : users.filter((u) => u.name.toLocaleLowerCase('pl').includes(viewerNeedle))
  const allShownViewers = shownUsers.length > 0 && shownUsers.every((u) => viewers.has(u.id))
  const viewersCount = users.filter((u) => viewers.has(u.id)).length
  // działa według zapisanych uprawnień roli, nie zaznaczeń na ekranie
  const viewSelectedSaved = selectedRole?.permissions.includes(OFFERS_VIEW_SELECTED) ?? false
  const viewAllSaved = selectedRole?.permissions.includes(OFFERS_VIEW_ALL) ?? false

  function toggleAllShownViewers() {
    setViewers((prev) => {
      const next = new Set(prev)
      for (const u of shownUsers) {
        if (allShownViewers) next.delete(u.id)
        else next.add(u.id)
      }
      return next
    })
  }

  return (
    <div>
      {err && <p className="mb-2 text-sm text-red-600">{err}</p>}
      {msg && <p className="mb-2 text-sm text-green-700">{msg}</p>}

      <form
        onSubmit={(e) => void onCreateRole(e)}
        className="mb-5 grid max-w-3xl gap-2 rounded-xl bg-white p-4 shadow-sm sm:grid-cols-2"
      >
        <h2 className="sm:col-span-2 text-sm font-semibold">Nowa rola / grupa</h2>
        <p className="sm:col-span-2 text-xs text-slate-500">
          Np. kod <code>handel-krakow</code>, nazwa „Handel Kraków” — potem zaznaczysz uprawnienia i
          przypiszesz rolę użytkownikom.
        </p>
        <input
          className="rounded border px-2 py-1.5 text-sm font-mono"
          placeholder="kod-roli (np. handel-krakow)"
          value={newCode}
          onChange={(e) => setNewCode(e.target.value.toLowerCase())}
          maxLength={32}
          required
          pattern="[a-z0-9]+(?:-[a-z0-9]+)*"
        />
        <input
          className="rounded border px-2 py-1.5 text-sm"
          placeholder="Nazwa wyświetlana (np. Handel Kraków)"
          value={newLabel}
          onChange={(e) => setNewLabel(e.target.value)}
          required
        />
        <label className="sm:col-span-2 flex flex-col gap-1 text-xs text-slate-600">
          Skopiuj uprawnienia z roli
          <select
            className="rounded border px-2 py-1.5 text-sm text-slate-900"
            value={copyFrom}
            onChange={(e) => setCopyFrom(e.target.value)}
          >
            <option value="">— pusta rola —</option>
            {roles.map((r) => (
              <option key={r.name} value={r.name}>
                {r.label ?? r.name}
              </option>
            ))}
          </select>
        </label>
        <button
          type="submit"
          disabled={busy}
          className="sm:col-span-2 rounded bg-blue-600 px-3 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-50"
        >
          Utwórz rolę
        </button>
      </form>

      <div className="mb-4 flex flex-wrap gap-2">
        {roles.map((r) => (
          <button
            key={r.name}
            type="button"
            onClick={() => selectRole(r)}
            className={`rounded px-3 py-1.5 text-sm ${
              selected === r.name ? 'bg-blue-600 text-white' : 'bg-slate-200 text-slate-800'
            }`}
          >
            {r.label ?? r.name}
          </button>
        ))}
      </div>

      {selected && selectedRole && (
        <>
          <div className="mb-3 flex flex-wrap items-center gap-3 text-sm text-slate-600">
            <span>
              Uprawnienia roli <strong>{selectedRole.label ?? selected}</strong>{' '}
              <code className="text-xs text-slate-500">{selected}</code> ({checked.size}/
              {definitions.length})
              {typeof selectedRole.users_count === 'number' && (
                <> · użytkowników: {selectedRole.users_count}</>
              )}
            </span>
            {renaming !== null ? (
              <form onSubmit={(e) => void onRenameRole(e)} className="flex flex-wrap items-center gap-2">
                <input
                  className="w-64 rounded border px-2 py-1 text-sm text-slate-900"
                  value={renaming.label}
                  onChange={(e) => setRenaming({ ...renaming, label: e.target.value })}
                  maxLength={255}
                  required
                  autoFocus
                  aria-label="Nowa nazwa roli"
                />
                {selectedRole.is_system ? (
                  <span
                    className="text-xs text-slate-500"
                    title="Program odwołuje się do kodu ról systemowych, dlatego nie można go zmienić."
                  >
                    kod <code>{selected}</code> — rola systemowa, bez zmian
                  </span>
                ) : (
                  <label className="flex items-center gap-1 text-xs text-slate-500">
                    kod
                    <input
                      className="w-44 rounded border px-2 py-1 font-mono text-sm text-slate-900"
                      value={renaming.code}
                      onChange={(e) => setRenaming({ ...renaming, code: e.target.value.toLowerCase() })}
                      maxLength={32}
                      required
                      pattern="[a-z0-9]+(?:-[a-z0-9]+)*"
                      aria-label="Nowy kod roli"
                    />
                  </label>
                )}
                <button
                  type="submit"
                  disabled={busy || renaming.label.trim() === '' || renaming.code.trim() === ''}
                  className="rounded bg-blue-600 px-2 py-1 text-xs text-white hover:bg-blue-700 disabled:opacity-50"
                >
                  Zapisz
                </button>
                <button
                  type="button"
                  disabled={busy}
                  onClick={() => setRenaming(null)}
                  className="rounded bg-slate-200 px-2 py-1 text-xs text-slate-800"
                >
                  Anuluj
                </button>
              </form>
            ) : (
              <button
                type="button"
                disabled={busy}
                onClick={() =>
                  setRenaming({ label: selectedRole.label ?? selectedRole.name, code: selectedRole.name })
                }
                className="rounded bg-slate-200 px-2 py-1 text-xs text-slate-800 hover:bg-slate-300"
              >
                {selectedRole.is_system ? 'Zmień nazwę' : 'Zmień nazwę / kod'}
              </button>
            )}
            {!selectedRole.is_system && (
              <button
                type="button"
                disabled={busy}
                onClick={() => void onDeleteRole()}
                className="rounded bg-red-100 px-2 py-1 text-xs text-red-700"
              >
                Usuń rolę
              </button>
            )}
          </div>

          <div className="mb-3 flex flex-wrap items-center gap-2 text-sm text-slate-600">
            <label className="flex items-center gap-2">
              Dostęp z sieci
              <select
                className="rounded border px-2 py-1 text-sm text-slate-900"
                value={selectedRole.network_access ?? 'any'}
                disabled={busy}
                onChange={(e) => void saveNetworkAccess(e.target.value as 'any' | 'local')}
              >
                <option value="any">z każdej sieci</option>
                <option value="local">tylko z sieci lokalnej</option>
              </select>
            </label>
            <span className="text-xs text-slate-500">
              zapis od razu; konto z własnym ustawieniem (Użytkownicy) ma pierwszeństwo. Sieć lokalna to{' '}
              <button
                type="button"
                onClick={() => document.getElementById('siec-lokalna')?.scrollIntoView({ behavior: 'smooth', block: 'start' })}
                className="text-blue-700 underline"
              >
                lista adresów
              </button>{' '}
              niżej na tej stronie.
            </span>
          </div>

          <div className="mb-4 max-h-[55vh] space-y-4 overflow-auto">
            {grouped.map(([group, items]) => (
              <section key={group} className="rounded-xl bg-white p-4 shadow-sm">
                <h2 className="mb-3 text-sm font-semibold text-slate-800">{group}</h2>
                <ul className="space-y-2">
                  {items.map((def) => (
                    <li key={def.key}>
                      <label className="flex cursor-pointer items-start gap-3 rounded-lg border border-slate-100 px-3 py-2 hover:bg-slate-50">
                        <input
                          type="checkbox"
                          className="mt-1"
                          checked={checked.has(def.key)}
                          onChange={() => toggle(def.key)}
                        />
                        <span>
                          <span className="block text-sm font-medium text-slate-900">{def.label}</span>
                          <span className="block text-xs text-slate-500">{def.description}</span>
                        </span>
                      </label>
                    </li>
                  ))}
                </ul>
              </section>
            ))}
          </div>

          <button
            type="button"
            disabled={busy}
            onClick={() => void save()}
            className="rounded bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-50"
          >
            Zapisz uprawnienia
          </button>

          <section
            className={`mt-5 max-w-3xl rounded-xl p-4 shadow-sm ${viewSelectedSaved ? 'bg-white' : 'bg-slate-100'}`}
            aria-label="Oferty których osób widzi ta rola"
          >
            <h2 className={`text-sm font-semibold ${viewSelectedSaved ? 'text-slate-800' : 'text-slate-500'}`}>
              Oferty których osób widzi ta rola
            </h2>
            <p className="mt-1 text-xs text-slate-500">
              Osoby z tą rolą widzą oferty zaznaczonych osób tylko do podglądu — zmienia i wysyła je autor.
            </p>
            {!viewSelectedSaved && (
              <p className="mt-1 text-xs text-amber-800">
                Działa po nadaniu uprawnienia „Oferty — podgląd ofert wybranych osób”
                {checked.has(OFFERS_VIEW_SELECTED) ? ' — jest zaznaczone wyżej, zapisz uprawnienia.' : '.'}
              </p>
            )}
            {viewAllSaved && (
              <p className="mt-1 text-xs text-slate-500">
                Ta rola ma też uprawnienie „Oferty — podgląd wszystkich”, więc i tak widzi oferty wszystkich osób.
              </p>
            )}
            <div className={`mt-3 ${viewSelectedSaved ? '' : 'opacity-60'}`}>
              <div className="mb-2 flex flex-wrap items-center gap-2 text-xs text-slate-600">
                {users.length >= USER_SEARCH_FROM && (
                  <input
                    type="search"
                    className="w-56 rounded border px-2 py-1 text-sm text-slate-900"
                    placeholder="Szukaj po imieniu i nazwisku"
                    aria-label="Szukaj osoby"
                    value={viewerQuery}
                    onChange={(e) => setViewerQuery(e.target.value)}
                  />
                )}
                <button
                  type="button"
                  disabled={busy || shownUsers.length === 0}
                  onClick={toggleAllShownViewers}
                  className="rounded bg-slate-200 px-2 py-1 text-xs text-slate-800 hover:bg-slate-300 disabled:opacity-50"
                >
                  {allShownViewers
                    ? viewerNeedle === ''
                      ? 'Odznacz wszystkich'
                      : 'Odznacz znalezionych'
                    : viewerNeedle === ''
                      ? 'Zaznacz wszystkich'
                      : 'Zaznacz znalezionych'}
                </button>
                <span>
                  zaznaczono {viewersCount} z {users.length}
                </span>
              </div>
              <ul className="grid max-h-64 gap-1 overflow-auto sm:grid-cols-2">
                {shownUsers.map((u) => (
                  <li key={u.id}>
                    <label className="flex cursor-pointer items-center gap-2 rounded px-2 py-1 text-sm text-slate-800 hover:bg-slate-50">
                      <input type="checkbox" checked={viewers.has(u.id)} onChange={() => toggleViewer(u.id)} />
                      {u.name}
                    </label>
                  </li>
                ))}
                {shownUsers.length === 0 && (
                  <li className="px-2 py-1 text-xs text-slate-500">
                    {users.length === 0 ? 'Brak użytkowników.' : 'Nikogo nie znaleziono.'}
                  </li>
                )}
              </ul>
            </div>
            <div className="mt-3 flex flex-wrap items-center gap-2">
              <button
                type="button"
                disabled={busy || !viewersDirty}
                onClick={() => void saveOfferViewers()}
                className="rounded bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-50"
              >
                Zapisz osoby
              </button>
              {viewersDirty && <span className="text-xs text-amber-800">niezapisane zmiany na liście osób</span>}
            </div>
          </section>
        </>
      )}

      <AdminLocalNetworks />

      <AdminTeams />
    </div>
  )
}
