import { useEffect, useMemo, useState, type FormEvent } from 'react'
import { api } from '../lib/api'

type RoleRow = {
  id: number
  name: string
  label?: string
  is_system?: boolean
  users_count?: number
  permissions: string[]
}

type PermissionDef = {
  key: string
  label: string
  description: string
  group: string
}

type RolesResponse = {
  roles: RoleRow[]
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

  async function load() {
    const data = await api<RolesResponse>('/admin/roles')
    setRoles(data.roles)
    setDefinitions(data.permission_definitions ?? [])
    if (!selected && data.roles[0]) {
      selectRole(data.roles[0])
    } else if (selected) {
      const role = data.roles.find((r) => r.name === selected)
      if (role) selectRole(role)
      else if (data.roles[0]) selectRole(data.roles[0])
    }
  }

  function selectRole(role: RoleRow) {
    setSelected(role.name)
    setChecked(new Set(role.permissions))
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
        body: JSON.stringify({ permissions: [...checked] }),
      })
      setMsg('Zapisano uprawnienia roli.')
      await load()
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Błąd')
    } finally {
      setBusy(false)
    }
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
              Uprawnienia roli <strong>{selectedRole.label ?? selected}</strong> ({checked.size}/
              {definitions.length})
              {typeof selectedRole.users_count === 'number' && (
                <> · użytkowników: {selectedRole.users_count}</>
              )}
            </span>
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
        </>
      )}
    </div>
  )
}
