import { useId, useState } from 'react'
import { updateClientOwner, type ClientRecord } from '../../lib/api'
import { errorText } from '../../lib/campaignFormat'

export type OwnerOption = { id: number; name: string }

/**
 * Opiekun w aplikacji (clients.owner_id) z możliwością zmiany — tylko dla osób z clients.manage (sprawdza też serwer).
 * Lista osób wczytywana dopiero po „Zmień” (loadOptions). Opiekun w aplikacji liczy się do celów handlowca, gdy
 * opiekun z ERP XL nie jest przypisany do żadnego konta.
 */
export function ClientOwnerPicker({
  clientId,
  owner,
  canManage,
  loadOptions,
  onSaved,
}: {
  clientId: number
  owner: OwnerOption | null
  canManage: boolean
  loadOptions: () => Promise<OwnerOption[]>
  onSaved: (client: ClientRecord & { owner?: OwnerOption | null }) => void
}) {
  const ids = useId()
  const [editing, setEditing] = useState(false)
  const [options, setOptions] = useState<OwnerOption[] | null>(null)
  const [value, setValue] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')

  async function start() {
    setError('')
    setValue(owner ? String(owner.id) : '')
    setEditing(true)
    if (options) return
    try {
      setOptions(await loadOptions())
    } catch (ex) {
      setError(errorText(ex, 'Nie udało się wczytać listy osób.'))
    }
  }

  async function save() {
    const next = value === '' ? null : Number(value)
    if (next === (owner?.id ?? null)) {
      setEditing(false)
      return
    }
    setBusy(true)
    setError('')
    try {
      onSaved(await updateClientOwner(clientId, next))
      setEditing(false)
    } catch (ex) {
      setError(errorText(ex, 'Nie udało się zmienić opiekuna.'))
    } finally {
      setBusy(false)
    }
  }

  if (!editing) {
    return (
      <span>
        {owner?.name ?? <span className="text-slate-500">brak</span>}
        {canManage && (
          <button type="button" onClick={() => void start()} className="ml-2 text-blue-700 hover:underline">
            Zmień
          </button>
        )}
      </span>
    )
  }

  // osoba spoza listy (np. konto bez imienia na liście) — zostaje do wyboru, nie znika po cichu
  const list = options ?? []
  const withCurrent = owner && !list.some((o) => o.id === owner.id) ? [owner, ...list] : list

  return (
    <span className="inline-flex flex-wrap items-center gap-1.5">
      <label htmlFor={`${ids}-owner`} className="sr-only">
        Opiekun w aplikacji
      </label>
      <select
        id={`${ids}-owner`}
        value={value}
        disabled={options === null || busy}
        onChange={(e) => setValue(e.target.value)}
        onKeyDown={(e) => {
          if (e.key === 'Escape') setEditing(false)
        }}
        className="max-w-[16rem] rounded border border-slate-300 px-1.5 py-1 text-xs"
      >
        <option value="">bez opiekuna w aplikacji</option>
        {withCurrent.map((o) => (
          <option key={o.id} value={o.id}>
            {o.name}
          </option>
        ))}
      </select>
      <button
        type="button"
        onClick={() => void save()}
        disabled={options === null || busy}
        className="rounded bg-blue-600 px-2 py-1 text-xs text-white hover:bg-blue-700 disabled:opacity-50"
      >
        {busy ? 'Zapisuję…' : 'Zapisz'}
      </button>
      <button type="button" onClick={() => setEditing(false)} className="rounded border border-slate-300 px-2 py-1 text-xs hover:bg-slate-50">
        Anuluj
      </button>
      {error && <span className="text-red-700">{error}</span>}
    </span>
  )
}
