import { useEffect, useId, useRef, useState, type FormEvent, type KeyboardEvent } from 'react'
import { AdminLocalNetworks } from '../components/AdminLocalNetworks'
import { api, fetchErpEmployees, type ErpEmployee, type User } from '../lib/api'
import { TEMPLATES } from '../lib/appearance'
import { listErpOperators, type ErpOperator } from '../lib/campaigns'
import { plural } from '../lib/plural'

type RoleOption = { name: string; label?: string }

/**
 * Użytkownik na liście admina — dodatkowo operator ERP XL (kampanie) i pracownik ERP XL — opiekun klientów
 * (cele handlowców); oba ustawia tylko administrator.
 */
type AdminUser = User & {
  erp_operator_ident?: string | null
  erp_employee_gid?: number | null
  /** dostęp z sieci ustawiony na koncie; null = jak w grupie */
  network_access?: NetworkMode | null
  /** co obowiązuje i skąd: konto, grupa (rola) albo domyślnie */
  network_access_effective?: { mode: NetworkMode; source: 'user' | 'role' | 'default'; role: string | null }
}

type NetworkMode = 'any' | 'local'

const NETWORK_LABELS: Record<NetworkMode, string> = {
  any: 'z każdej sieci',
  local: 'tylko z sieci lokalnej',
}

/** Wartość pola edycji: '' = jak w grupie. */
type NetworkChoice = '' | NetworkMode

function customersLabel(n: number): string {
  return `${n} ${n === 1 ? 'klient' : 'klientów'}`
}

function employeeName(e: ErpEmployee): string {
  return e.name ?? `pracownik numer ${e.gid}`
}

/** Pracownik w polu po wyborze: „Jan Kowalski (12 klientów)”. */
function employeeLabel(e: ErpEmployee): string {
  return `${employeeName(e)} (${e.clients} ${plural(e.clients, 'klient', 'klientów', 'klientów')})`
}

/** Wpisany tekst = pracownik: ten sam numer albo dokładnie jedno pasujące imię i nazwisko (bez wielkości liter). */
function exactEmployee(employees: ErpEmployee[], text: string): ErpEmployee | null {
  const t = text.trim().toLocaleLowerCase('pl-PL')
  if (t === '') return null
  const same = employees.filter(
    (e) => String(e.gid) === t || (e.name ?? '').toLocaleLowerCase('pl-PL') === t || employeeLabel(e).toLocaleLowerCase('pl-PL') === t,
  )
  return same.length === 1 ? same[0] : null
}

/**
 * Wybór pracownika ERP XL (opiekuna klientów) dla konta — lista z podpowiedziami: strzałki, Enter, Escape;
 * lista zamyka się, gdy fokus wyjdzie poza pole. Wpisany tekst bez wyboru nie znika: zostaje w polu z ostrzeżeniem
 * (onPendingChange blokuje zapis), chyba że jednoznacznie wskazuje jednego pracownika. Pracownik przypisany innemu
 * kontu jest widoczny, ale nie da się go wybrać (serwer też by odmówił).
 */
function EmployeeCombobox({
  employees,
  userId,
  value,
  onChange,
  onPendingChange,
  loadFailed,
}: {
  /** lista pracowników nie wczytała się (pusta lista nie znaczy wtedy „brak pracowników”) */
  loadFailed: boolean
  employees: ErpEmployee[]
  userId: number
  value: number | null
  onChange: (gid: number | null) => void
  onPendingChange: (pending: boolean) => void
}) {
  const listId = useId()
  const [query, setQueryState] = useState('')
  const queryRef = useRef('')
  const [open, setOpen] = useState(false)
  const [active, setActive] = useState(0)
  const [pending, setPendingState] = useState(false)

  function setQuery(text: string) {
    queryRef.current = text
    setQueryState(text)
  }
  function setPending(next: boolean) {
    setPendingState(next)
    onPendingChange(next)
  }

  const selected = value == null ? null : (employees.find((e) => e.gid === value) ?? null)
  const typed = query.trim().toLocaleLowerCase('pl-PL')
  const matches = employees
    .filter((e) => typed === '' || `${e.gid} ${e.name ?? ''} ${e.email ?? ''}`.toLocaleLowerCase('pl-PL').includes(typed))
    // propozycja dla tego konta na górze
    .sort((a, b) => Number(b.suggested_user?.id === userId) - Number(a.suggested_user?.id === userId))
  const options: Array<{ kind: 'none' } | { kind: 'employee'; employee: ErpEmployee }> = [
    ...(typed === '' ? [{ kind: 'none' as const }] : []),
    ...matches.map((employee) => ({ kind: 'employee' as const, employee })),
  ]
  const isTaken = (e: ErpEmployee) => e.user != null && e.user.id !== userId
  const activeIndex = options.length > 0 ? Math.min(active, options.length - 1) : -1

  function close() {
    setOpen(false)
    setActive(0)
  }

  function pick(option: (typeof options)[number]) {
    if (option.kind === 'employee' && isTaken(option.employee)) return
    onChange(option.kind === 'none' ? null : option.employee.gid)
    setQuery('')
    setPending(false)
    close()
  }

  /** Wyjście z pola z wpisanym tekstem: jednoznaczny pracownik zostaje wybrany, inny tekst zostaje z ostrzeżeniem. */
  function commitTyped() {
    const text = queryRef.current.trim()
    if (text === '') {
      setPending(false)
      return
    }
    const hit = exactEmployee(employees, text)
    if (hit && !isTaken(hit)) {
      onChange(hit.gid)
      setQuery('')
      setPending(false)
      return
    }
    setPending(true)
  }

  function onKeyDown(e: KeyboardEvent<HTMLInputElement>) {
    if (e.key === 'Escape') {
      if (open) {
        e.preventDefault()
        close()
      }
      return
    }
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault()
      if (!open) {
        setOpen(true)
        return
      }
      if (options.length === 0) return
      const step = e.key === 'ArrowDown' ? 1 : -1
      setActive((activeIndex + step + options.length) % options.length)
      return
    }
    if (e.key === 'Enter') {
      e.preventDefault()
      if (open && activeIndex >= 0) pick(options[activeIndex])
    }
  }

  const inputValue = query !== '' || open ? query : selected ? employeeLabel(selected) : value != null ? `pracownik numer ${value}` : ''

  return (
    <div
      className="relative"
      onBlur={(e) => {
        if (e.relatedTarget instanceof Node && e.currentTarget.contains(e.relatedTarget)) return
        close()
        commitTyped()
      }}
    >
      <input
        aria-label="Pracownik ERP XL (opiekun klientów)"
        role="combobox"
        aria-autocomplete="list"
        aria-expanded={open}
        aria-controls={open ? listId : undefined}
        aria-activedescendant={open && activeIndex >= 0 ? `${listId}-${activeIndex}` : undefined}
        aria-invalid={pending || undefined}
        className={`w-full rounded border px-2 py-1 text-xs ${pending ? 'border-red-500' : ''}`}
        placeholder={open || value == null ? 'Szukaj po nazwisku, e-mailu albo numerze' : ''}
        value={inputValue}
        onFocus={() => {
          setOpen(true)
          if (query === '') setActive(0)
        }}
        onKeyDown={onKeyDown}
        onChange={(e) => {
          setQuery(e.target.value)
          setActive(0)
          setOpen(true)
          if (pending) setPending(false)
        }}
      />
      {open && (
        <div
          tabIndex={-1}
          onMouseDown={(e) => e.preventDefault()}
          className="absolute z-20 mt-1 max-h-64 w-[22rem] max-w-[80vw] overflow-auto rounded border border-slate-200 bg-white text-xs shadow-lg"
        >
          {loadFailed && <p className="px-2 py-1.5 text-red-700">Nie udało się wczytać listy pracowników ERP XL. Odśwież stronę.</p>}
          {!loadFailed && employees.length === 0 && (
            <p className="px-2 py-1.5 text-slate-500">
              Lista jest pusta — pracownicy ERP XL pojawiają się po nocnym odczycie klientów z ERP XL (opiekun z karty klienta).
            </p>
          )}
          {employees.length > 0 && matches.length === 0 && typed !== '' && (
            <p className="px-2 py-1.5 text-slate-500">Brak takiego pracownika na liście.</p>
          )}
          <ul id={listId} role="listbox" aria-label="Pracownicy ERP XL: podpowiedzi">
            {options.map((option, i) => {
              const isActive = i === activeIndex
              if (option.kind === 'none') {
                return (
                  <li
                    key="none"
                    id={`${listId}-${i}`}
                    role="option"
                    aria-selected={isActive}
                    className={`cursor-pointer px-2 py-1.5 text-slate-600 ${isActive ? 'bg-slate-100' : 'hover:bg-slate-50'}`}
                    onMouseEnter={() => setActive(i)}
                    onClick={() => pick(option)}
                  >
                    — brak pracownika —
                  </li>
                )
              }
              const emp = option.employee
              const taken = isTaken(emp)
              const suggested = emp.suggested_user?.id === userId
              return (
                <li
                  key={emp.gid}
                  id={`${listId}-${i}`}
                  role="option"
                  aria-selected={isActive}
                  aria-disabled={taken || undefined}
                  className={`px-2 py-1.5 ${taken ? 'cursor-not-allowed text-slate-400' : 'cursor-pointer'} ${isActive ? 'bg-slate-100' : 'hover:bg-slate-50'}`}
                  onMouseEnter={() => setActive(i)}
                  onClick={() => pick(option)}
                >
                  <span className="font-medium">{employeeName(emp)}</span>
                  <span className="text-slate-500">
                    {' '}
                    · numer {emp.gid} · {customersLabel(emp.clients)}
                    {emp.email ? ` · ${emp.email}` : ''}
                  </span>
                  {taken && <span className="block text-[11px]">przypisany do konta: {emp.user?.name}</span>}
                  {suggested && (
                    <span className="block text-[11px] text-blue-700">propozycja — ten sam e-mail co to konto (sprawdź, zanim wybierzesz)</span>
                  )}
                </li>
              )
            })}
          </ul>
        </div>
      )}
      {pending && (
        <p className="mt-1 text-[11px] leading-snug text-red-700" role="alert">
          Nie wybrano pracownika — wybierz go z listy albo wyczyść pole.
        </p>
      )}
    </div>
  )
}

function operatorOptionLabel(o: ErpOperator, editedUserId: number): string {
  const assigned = o.user && o.user.id !== editedUserId ? ` · przypisany: ${o.user.name}` : ''
  return `${o.ident}${o.name ? ` — ${o.name}` : ''} · ${customersLabel(o.customers)}${assigned}`
}

/** Pracownik ERP XL konta na liście (bez edycji) i — gdy go nie ma — propozycja po tym samym e-mailu. */
function EmployeeCell({ u, employees }: { u: AdminUser; employees: ErpEmployee[] }) {
  if (u.erp_employee_gid != null) {
    const e = employees.find((x) => x.gid === u.erp_employee_gid)
    return <span>{e ? employeeLabel(e) : `pracownik numer ${u.erp_employee_gid}`}</span>
  }
  const proposal = employees.find((x) => x.suggested_user?.id === u.id)
  return (
    <span className="text-slate-500">
      nie przypisany
      {proposal && <span className="block text-[11px] text-blue-700">propozycja: {employeeName(proposal)} (ten sam e-mail)</span>}
    </span>
  )
}

/** Wartość pola „Wygląd”: "szablon|tryb" (puste = brak). */
const NO_APPEARANCE = '|'
/** Wygląd wybrany na start przy dodawaniu użytkownika (administrator może zmienić). */
const DEFAULT_NEW_USER_APPEARANCE = 'nocna-zmiana|light'

const appearanceOptions: { value: string; label: string }[] = [
  ...TEMPLATES.flatMap((t) =>
    t.schemes.length === 1
      ? [{ value: `${t.id}|`, label: t.label }]
      : [
          { value: `${t.id}|light`, label: `${t.label} — Dzień` },
          { value: `${t.id}|dark`, label: `${t.label} — Noc` },
          { value: `${t.id}|system`, label: `${t.label} — jak w systemie` },
        ],
  ),
  { value: NO_APPEARANCE, label: 'Nie ustawiaj (użytkownik wybierze sam)' },
]

function appearanceValue(u: AdminUser): string {
  const template = u.ui_preferences?.template ?? null
  if (!template) return NO_APPEARANCE
  const known = TEMPLATES.find((t) => t.id === template)
  // Szablon z jednym schematem nie ma trybu — zapisany tryb nie zmienia wyglądu.
  if (known && known.schemes.length === 1) return `${template}|`
  return `${template}|${u.ui_preferences?.mode ?? ''}`
}

function appearanceLabel(value: string): string {
  return appearanceOptions.find((o) => o.value === value)?.label ?? value.replace('|', ' — ')
}

function appearancePayload(value: string): { ui_template: string | null; ui_mode: string | null } {
  const [template, mode] = value.split('|')
  return { ui_template: template || null, ui_mode: template && mode ? mode : null }
}

export function AdminUsers() {
  const [users, setUsers] = useState<AdminUser[]>([])
  const [operators, setOperators] = useState<ErpOperator[]>([])
  const [employees, setEmployees] = useState<ErpEmployee[]>([])
  const [employeesFailed, setEmployeesFailed] = useState(false)
  const [roleOptions, setRoleOptions] = useState<RoleOption[]>([])
  const [err, setErr] = useState('')
  const [msg, setMsg] = useState('')
  const [busy, setBusy] = useState(false)
  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [role, setRole] = useState<string>('handlowiec')
  const [appearance, setAppearance] = useState(DEFAULT_NEW_USER_APPEARANCE)
  const [sendOnCreate, setSendOnCreate] = useState(true)
  const [editId, setEditId] = useState<number | null>(null)
  const [editRole, setEditRole] = useState('handlowiec')
  const [editName, setEditName] = useState('')
  const [editEmail, setEditEmail] = useState('')
  const [editPassword, setEditPassword] = useState('')
  const [editAppearance, setEditAppearance] = useState(NO_APPEARANCE)
  const [editOperator, setEditOperator] = useState('')
  const [editEmployee, setEditEmployee] = useState<number | null>(null)
  const [editEmployeePending, setEditEmployeePending] = useState(false)
  const [editNetwork, setEditNetwork] = useState<NetworkChoice>('')

  async function load() {
    const [usersData, rolesData] = await Promise.all([
      api<AdminUser[]>('/admin/users'),
      api<{ roles: RoleOption[] }>('/admin/roles'),
    ])
    setUsers(usersData)
    setRoleOptions(rolesData.roles)
    // lista operatorów XL tylko pomaga wybrać — jej błąd nie blokuje listy użytkowników
    void listErpOperators()
      .then((res) => setOperators(res.data))
      .catch(() => setOperators([]))
    // tak samo lista pracowników ERP XL (opiekunów klientów)
    void fetchErpEmployees()
      .then((list) => {
        setEmployees(list)
        setEmployeesFailed(false)
      })
      .catch(() => {
        setEmployees([])
        setEmployeesFailed(true)
      })
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

  /** „tylko z sieci lokalnej (grupa Handlowiec)” — obowiązujący dostęp z sieci i jego źródło. */
  function networkSummary(u: AdminUser): string {
    const eff = u.network_access_effective
    if (!eff) return '—'
    const source = eff.source === 'user' ? 'ustawione na koncie' : eff.role ? `grupa ${roleLabel(eff.role)}` : 'bez grupy'
    return `${NETWORK_LABELS[eff.mode]} (${source})`
  }

  async function onCreate(e: FormEvent) {
    e.preventDefault()
    setBusy(true)
    setErr('')
    setMsg('')
    try {
      const created = await api<User>('/admin/users', {
        method: 'POST',
        body: JSON.stringify({ name, email, password, role, ...appearancePayload(appearance) }),
      })
      setName('')
      setEmail('')
      setPassword('')
      setAppearance(DEFAULT_NEW_USER_APPEARANCE)
      setMsg('Użytkownik utworzony.')
      // Konto już istnieje — nieudana wysyłka nie cofa go; wpisane hasło nadal działa.
      if (sendOnCreate) {
        try {
          const res = await api<{ message: string }>(`/admin/users/${created.id}/send-credentials`, {
            method: 'POST',
            body: JSON.stringify({ password }),
          })
          setMsg(`Użytkownik utworzony. ${res.message}`)
        } catch (ex) {
          setMsg('')
          setErr(
            `Użytkownik utworzony, ale nie wysłano danych logowania (${ex instanceof Error ? ex.message : 'błąd'}). ` +
              'Użyj „Wyślij dane” na liście.',
          )
        }
      }
      await load()
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Błąd')
    } finally {
      setBusy(false)
    }
  }

  async function onSaveEdit(u: AdminUser) {
    const userId = u.id
    setBusy(true)
    setErr('')
    setMsg('')
    try {
      const body: Record<string, string | number | null> = {
        role: editRole,
        name: editName.trim(),
        email: editEmail.trim(),
        ...appearancePayload(editAppearance),
        erp_operator_ident: editOperator || null,
      }
      if (editPassword) body.password = editPassword
      // pracownik ERP XL tylko po zmianie — zapis innych pól nie rusza przypisania
      if (editEmployee !== (u.erp_employee_gid ?? null)) body.erp_employee_gid = editEmployee
      // dostęp z sieci tylko po zmianie — jak pracownik ERP XL
      if (editNetwork !== (u.network_access ?? '')) body.network_access = editNetwork === '' ? null : editNetwork
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

  /** Hasło w bazie jest hashowane — wysyłka zawsze ustawia nowe (podane w edycji albo wygenerowane). */
  async function onSendCredentials(u: AdminUser, password?: string) {
    const question = password
      ? `Ustawić hasło wpisane w polu i wysłać dane logowania na ${u.email}?`
      : `Wysłać dane logowania na ${u.email}? Zostanie ustawione nowe, losowe hasło — dotychczasowe przestanie działać.`
    if (!confirm(question)) return
    setBusy(true)
    setErr('')
    setMsg('')
    try {
      const res = await api<{ message: string }>(`/admin/users/${u.id}/send-credentials`, {
        method: 'POST',
        body: JSON.stringify(password ? { password } : {}),
      })
      setEditPassword('')
      setMsg(res.message)
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
          placeholder="Imię i nazwisko"
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
        <label className="sm:col-span-2 flex flex-wrap items-center gap-2 text-sm">
          <span className="text-slate-600">Wygląd</span>
          <select
            className="min-w-[16rem] flex-1 rounded border px-2 py-1.5 text-sm"
            value={appearance}
            onChange={(e) => setAppearance(e.target.value)}
          >
            {appearanceOptions.map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </select>
        </label>
        <label className="sm:col-span-2 flex items-center gap-2 text-sm">
          <input type="checkbox" checked={sendOnCreate} onChange={(e) => setSendOnCreate(e.target.checked)} />
          <span>Wyślij dane logowania na e-mail użytkownika (login i wpisane hasło)</span>
        </label>
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
            <th className="p-2">Imię i nazwisko</th>
            <th className="p-2">E-mail</th>
            <th className="p-2">Rola</th>
            <th className="p-2">Wygląd</th>
            <th className="p-2">Operator ERP XL</th>
            <th className="p-2">Pracownik ERP XL (opiekun klientów)</th>
            <th className="p-2">Dostęp z sieci</th>
            <th className="p-2">Akcje</th>
          </tr>
        </thead>
        <tbody>
          {users.map((u) => (
            <tr key={u.id} className="border-b">
              <td className="p-2">
                {editId === u.id ? (
                  <input
                    required
                    placeholder="Imię i nazwisko"
                    className="w-full min-w-[160px] rounded border px-2 py-1 text-xs"
                    value={editName}
                    onChange={(e) => setEditName(e.target.value)}
                  />
                ) : (
                  u.name
                )}
              </td>
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
                  <select
                    className="rounded border px-2 py-1 text-xs"
                    value={editAppearance}
                    onChange={(e) => setEditAppearance(e.target.value)}
                  >
                    {!appearanceOptions.some((o) => o.value === editAppearance) && (
                      <option value={editAppearance}>{appearanceLabel(editAppearance)}</option>
                    )}
                    {appearanceOptions.map((o) => (
                      <option key={o.value} value={o.value}>
                        {o.label}
                      </option>
                    ))}
                  </select>
                ) : (
                  <span className={appearanceValue(u) === NO_APPEARANCE ? 'text-slate-500' : undefined}>
                    {appearanceValue(u) === NO_APPEARANCE ? 'nie ustawiony' : appearanceLabel(appearanceValue(u))}
                  </span>
                )}
              </td>
              <td className="p-2">
                {editId === u.id ? (
                  <div className="max-w-[18rem]">
                    <select
                      className="w-full rounded border px-2 py-1 text-xs"
                      value={editOperator}
                      onChange={(e) => setEditOperator(e.target.value)}
                    >
                      <option value="">— brak —</option>
                      {editOperator && !operators.some((o) => o.ident === editOperator) && (
                        <option value={editOperator}>{editOperator} · brak klientów w XL</option>
                      )}
                      {operators.map((o) => (
                        <option key={o.ident} value={o.ident}>
                          {operatorOptionLabel(o, u.id)}
                        </option>
                      ))}
                    </select>
                    <p className="mt-1 text-[11px] leading-snug text-slate-500">
                      Klienci, którym ten operator wystawiał faktury w XL, będą w kampaniach „moimi klientami” tego
                      użytkownika.
                    </p>
                  </div>
                ) : u.erp_operator_ident ? (
                  <span>
                    {u.erp_operator_ident}
                    {(() => {
                      const o = operators.find((x) => x.ident === u.erp_operator_ident)
                      return o?.name ? <span className="text-slate-500"> — {o.name}</span> : null
                    })()}
                  </span>
                ) : (
                  <span className="text-slate-500">nie przypisany</span>
                )}
              </td>
              <td className="p-2">
                {editId === u.id ? (
                  <div className="max-w-[18rem] min-w-[14rem]">
                    <EmployeeCombobox
                      employees={employees}
                      userId={u.id}
                      value={editEmployee}
                      onChange={setEditEmployee}
                      onPendingChange={setEditEmployeePending}
                      loadFailed={employeesFailed}
                    />
                    {(() => {
                      // propozycja „ten sam e-mail” — tylko przycisk; nic nie przypisuje się samo
                      const proposal = employees.find((e) => e.suggested_user?.id === u.id)
                      return proposal && editEmployee == null ? (
                        <button
                          type="button"
                          onClick={() => setEditEmployee(proposal.gid)}
                          className="mt-1 text-left text-[11px] text-blue-700 underline"
                        >
                          Propozycja: {employeeLabel(proposal)} — ten sam e-mail co konto. Użyj
                        </button>
                      ) : null
                    })()}
                    <p className="mt-1 text-[11px] leading-snug text-slate-500">
                      Klienci, których ten pracownik jest opiekunem w karcie ERP XL, liczą się do celu sprzedaży tego
                      użytkownika (Raporty → Cele handlowców).
                    </p>
                  </div>
                ) : (
                  <EmployeeCell u={u} employees={employees} />
                )}
              </td>
              <td className="p-2">
                {editId === u.id ? (
                  <div className="max-w-[16rem]">
                    <select
                      aria-label="Dostęp z sieci"
                      className="w-full rounded border px-2 py-1 text-xs"
                      value={editNetwork}
                      onChange={(e) => setEditNetwork(e.target.value as NetworkChoice)}
                    >
                      <option value="">jak w grupie</option>
                      <option value="any">{NETWORK_LABELS.any}</option>
                      <option value="local">{NETWORK_LABELS.local}</option>
                    </select>
                    <p className="mt-1 text-[11px] leading-snug text-slate-500">
                      Ustawienie konta ma pierwszeństwo przed grupą. Adresy sieci lokalnej — pod listą.
                    </p>
                  </div>
                ) : (
                  <span className={u.network_access_effective?.mode === 'local' ? 'text-amber-800' : 'text-slate-600'}>
                    {networkSummary(u)}
                  </span>
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
                      disabled={busy || !editEmail.trim() || !editName.trim() || editEmployeePending}
                      title={editEmployeePending ? 'Wybierz pracownika ERP XL z listy albo wyczyść pole' : undefined}
                      onClick={() => void onSaveEdit(u)}
                      className="rounded bg-green-600 px-2 py-1 text-xs text-white disabled:opacity-50"
                    >
                      Zapisz
                    </button>
                    <button
                      type="button"
                      disabled={busy}
                      onClick={() => void onSendCredentials(u, editPassword || undefined)}
                      className="rounded bg-blue-100 px-2 py-1 text-xs text-blue-700 disabled:opacity-50"
                      title="Wyśle na adres użytkownika login i hasło z pola obok (puste pole = hasło losowe)"
                    >
                      Wyślij dane
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
                        setEditName(u.name)
                        setEditEmail(u.email)
                        setEditPassword('')
                        setEditAppearance(appearanceValue(u))
                        setEditOperator(u.erp_operator_ident ?? '')
                        setEditEmployee(u.erp_employee_gid ?? null)
                        setEditEmployeePending(false)
                        setEditNetwork(u.network_access ?? '')
                      }}
                      className="rounded bg-slate-200 px-2 py-1 text-xs"
                    >
                      Edytuj
                    </button>
                    <button
                      type="button"
                      disabled={busy}
                      onClick={() => void onSendCredentials(u)}
                      className="rounded bg-blue-100 px-2 py-1 text-xs text-blue-700 disabled:opacity-50"
                      title="Wyśle na adres użytkownika login i nowe, losowe hasło"
                    >
                      Wyślij dane
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

      <AdminLocalNetworks />
    </div>
  )
}
