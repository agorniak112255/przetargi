import { NavLink } from 'react-router-dom'
import { useAuth } from '../auth'
import { can } from '../lib/api'

type Tile = {
  to: string
  label: string
  description: string
  end?: boolean
  permission?: string
}

const tiles: Tile[] = [
  {
    to: '/admin',
    label: 'Pracownicy',
    description: 'Konta i role użytkowników',
    end: true,
    permission: 'admin.users.manage',
  },
  {
    to: '/admin/roles',
    label: 'Role',
    description: 'Uprawnienia grup',
    permission: 'admin.roles.manage',
  },
  {
    to: '/admin/logs',
    label: 'Logi',
    description: 'Historia działań',
    permission: 'admin.activity.view',
  },
  {
    to: '/admin/enrichment',
    label: 'Logi AI',
    description: 'Zakończone pobierania opisów',
  },
  {
    to: '/admin/smtp',
    label: 'SMTP',
    description: 'Poczta wychodząca',
    permission: 'admin.mail.manage',
  },
  {
    to: '/admin/presta',
    label: 'Sklep Presta',
    description: 'Połączenie i wyszukiwanie w sklepie',
  },
  {
    to: '/admin/strony-wyszukiwarka',
    label: 'Strony wyszukiwarka',
    description: 'Domeny indeksu i liczba linków',
  },
  {
    to: '/admin/zargon',
    label: 'Żargon SIWZ',
    description: 'Słownik potocznych nazw z przetargów',
  },
  {
    to: '/admin/szablony-opisow',
    label: 'Szablony opisów',
    description: 'Instrukcje AI wg rodziny BHP',
  },
]

export function AdminNavTiles() {
  const { user } = useAuth()
  const visible = tiles.filter((t) => !t.permission || can(user, t.permission))

  return (
    <div className="mb-5">
      <h1 className="mb-3 text-xl font-semibold">Administracja</h1>
      <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
        {visible.map((t) => (
          <NavLink
            key={t.to}
            to={t.to}
            end={t.end}
            className={({ isActive }) =>
              `rounded-xl border px-4 py-3 shadow-sm transition ${
                isActive
                  ? 'border-sky-400 bg-sky-50 ring-1 ring-sky-300'
                  : 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50'
              }`
            }
          >
            <div className="text-sm font-semibold text-slate-800">{t.label}</div>
            <div className="mt-0.5 text-[11px] text-slate-500">{t.description}</div>
          </NavLink>
        ))}
      </div>
    </div>
  )
}
