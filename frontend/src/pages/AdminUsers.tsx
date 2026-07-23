import { useEffect, useState, type FormEvent } from 'react'
import { api, type User } from '../lib/api'

type RoleOption = { name: string; label?: string }

export function AdminUsers() {
  const [users, setUsers] = useState<User[]>([])
  const [roleOptions, setRoleOptions] = useState<RoleOption[]>([])
  const [err, setErr] = useState('')
  const [msg, setMsg] = useState('')
  const [busy, setBusy] = useState(false)
  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [role, setRole] = useState<string>('handlowiec')
  const [editId, setEditId] = useState<number | null>(null)
  const [editRole, setEditRole] = useState('handlowiec')
  const [editEmail, setEditEmail] = useState('')
  const [editPassword, setEditPassword] = useState('')

  async function load() {
    const [usersData, rolesData] = await Promise.all([
      api<User[]>('/admin/users'),
      api<{ roles: RoleOption[] }>('/admin/roles'),
    ])
    setUsers(usersData)
    setRoleOptions(rolesData.roles)
    if (rolesData.roles.length && !rolesData.roles.some((r) => r.name === role)) {
      setRole(rolesData.roles[0].name)
    }
  }

  useEffect(() => {
    void load().catch((e: Error) => setErr(e.message))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  function roleLabel(code: string): string {
    return roleOptions.find((r) => r.name === code)?.label ?? code
  }

  async function onCreate(e: FormEvent) {
    e.preventDefault()
    setBusy(true)
    setErr('')
    setMsg('')
    try {
      await api('/admin/users', {
        method: 'POST',
        body: JSON.stringify({ name, email, password, role }),
      })
      setName('')
      setEmail('')
      setPassword('')
      setMsg('Użytkownik utworzony.')
      await load()
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Błąd')
    } finally {
      setBusy(false)
    }
  }

  async function onSaveEdit(userId: number) {
    setBusy(true)
    setErr('')
    setMsg('')
    try {
      const body: Record<string, string> = {
        role: editRole,
        email: editEmail.trim(),
      }
      if (editPassword) body.password = editPassword
      await api(`/admin/users/${userId}`, {
        method: 'PATCH',
        body: JSON.stringify(body),
      })
      setEditId(null)
      setEditEmail('')
      setEditPassword('')
      setMsg('Zapisano zmiany.')
      await load()
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Błąd')
    } finally {
      setBusy(false)
    }
  }

  async function onDelete(userId: number) {
    if (!confirm('Usunąć użytkownika?')) return
    setBusy(true)
    setErr('')
    try {
      await api(`/admin/users/${userId}`, { method: 'DELETE' })
      setMsg('Usunięto.')
      await load()
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Błąd')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div>
      {err && <p className="mb-2 text-sm text-red-600">{err}</p>}
      {msg && <p className="mb-2 text-sm text-green-700">{msg}</p>}

      <form onSubmit={(e) => void onCreate(e)} className="mb-6 grid max-w-2xl gap-2 rounded-xl bg-white p-4 shadow-sm sm:grid-cols-2">
        <h2 className="sm:col-span-2 text-sm font-semibold">Nowy użytkownik</h2>
        <input
          className="rounded border px-2 py-1.5 text-sm"
          placeholder="Imię"
          value={name}
          onChange={(e) => setName(e.target.value)}
          required
        />
        <input
          className="rounded border px-2 py-1.5 text-sm"
          type="email"
          placeholder="E-mail"
          value={email}
          onChange={(e) => setEmail(e.target.value)}
          required
        />
        <input
          className="rounded border px-2 py-1.5 text-sm"
          type="password"
          placeholder="Hasło (min. 8)"
          value={password}
          onChange={(e) => setPassword(e.target.value)}
          required
          minLength={8}
        />
        <select className="rounded border px-2 py-1.5 text-sm" value={role} onChange={(e) => setRole(e.target.value)}>
          {roleOptions.map((r) => (
            <option key={r.name} value={r.name}>
              {r.label ?? r.name}
            </option>
          ))}
        </select>
        <button
          type="submit"
          disabled={busy}
          className="sm:col-span-2 rounded bg-blue-600 px-3 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-50"
        >
          Utwórz
        </button>
      </form>

      <table className="w-full text-left text-sm">
        <thead>
          <tr className="border-b bg-slate-50 text-xs uppercase text-slate-500">
            <th className="p-2">Nazwa</th>
            <th className="p-2">E-mail</th>
            <th className="p-2">Rola</th>
            <th className="p-2">Akcje</th>
          </tr>
        </thead>
        <tbody>
          {users.map((u) => (
            <tr key={u.id} className="border-b">
              <td className="p-2">{u.name}</td>
              <td className="p-2">
                {editId === u.id ? (
                  <input
                    type="email"
                    required
                    className="w-full min-w-[180px] rounded border px-2 py-1 text-xs"
                    value={editEmail}
                    onChange={(e) => setEditEmail(e.target.value)}
                  />
                ) : (
                  u.email
                )}
              </td>
              <td className="p-2">
                {editId === u.id ? (
                  <select
                    className="rounded border px-2 py-1 text-xs"
                    value={editRole}
                    onChange={(e) => setEditRole(e.target.value)}
                  >
                    {roleOptions.map((r) => (
                      <option key={r.name} value={r.name}>
                        {r.label ?? r.name}
                      </option>
                    ))}
                  </select>
                ) : (
                  roleLabel(u.role)
                )}
              </td>
              <td className="p-2">
                {editId === u.id ? (
                  <div className="flex flex-wrap items-center gap-1">
                    <input
                      type="password"
                      placeholder="Nowe hasło (opc.)"
                      className="rounded border px-2 py-1 text-xs"
                      value={editPassword}
                      onChange={(e) => setEditPassword(e.target.value)}
                    />
                    <button
                      type="button"
                      disabled={busy || !editEmail.trim()}
                      onClick={() => void onSaveEdit(u.id)}
                      className="rounded bg-green-600 px-2 py-1 text-xs text-white disabled:opacity-50"
                    >
                      Zapisz
                    </button>
                    <button
                      type="button"
                      onClick={() => {
                        setEditId(null)
                        setEditEmail('')
                        setEditPassword('')
                      }}
                      className="rounded bg-slate-200 px-2 py-1 text-xs"
                    >
                      Anuluj
                    </button>
                  </div>
                ) : (
                  <div className="flex gap-1">
                    <button
                      type="button"
                      onClick={() => {
                        setEditId(u.id)
                        setEditRole(u.role)
                        setEditEmail(u.email)
                        setEditPassword('')
                      }}
                      className="rounded bg-slate-200 px-2 py-1 text-xs"
                    >
                      Edytuj
                    </button>
                    <button
                      type="button"
                      disabled={busy}
                      onClick={() => void onDelete(u.id)}
                      className="rounded bg-red-100 px-2 py-1 text-xs text-red-700"
                    >
                      Usuń
                    </button>
                  </div>
                )}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
