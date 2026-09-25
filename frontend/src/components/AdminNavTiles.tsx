import { NavLink } from 'react-router-dom'
import { useAuth } from '../auth'
import { can } from '../lib/api'

export type AdminTile = {
  to: string
  label: string
  description: string
  end?: boolean
  permission?: string
}

export const adminTiles: AdminTile[] = [
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
    to: '/admin/sesje',
    label: 'Aktywne sesje',
    description: 'Kto teraz korzysta i ostatnie wizyty',
    permission: 'admin.sessions.view',
  },
  {
    to: '/admin/enrichment',
    label: 'Logi AI',
    description: 'Zakończone pobierania opisów',
    permission: 'admin.enrichment.view',
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
    permission: 'admin.presta.manage',
  },
  {
    to: '/admin/strony-wyszukiwarka',
    label: 'Strony wyszukiwarka',
    description: 'Domeny indeksu i liczba linków',
    permission: 'admin.search_sites.manage',
  },
  {
    to: '/admin/strojenie-ai',
    label: 'Strojenie AI',
    description: 'Limit wyników wyszukiwania w katalogu',
    permission: 'admin.ai_tuning.manage',
  },
  {
    to: '/admin/statystyki-ai',
    label: 'Statystyki AI',
    description: 'Koszt i przebieg wyszukiwań AI',
    permission: 'admin.ai_stats.view',
  },
  {
    to: '/admin/zargon',
    label: 'Żargon SIWZ',
    description: 'Słownik potocznych nazw z przetargów',
    permission: 'admin.catalog_slang.manage',
  },
  {
    to: '/admin/slowniki',
    label: 'Słowniki',
    description: 'Producenci, marki i wykluczenia',
    permission: 'admin.dictionaries.manage',
  },
  {
    to: '/admin/szablony-opisow',
    label: 'Szablony opisów',
    description: 'Instrukcje AI wg rodziny BHP',
    permission: 'admin.description_templates.manage',
  },
]

export function AdminNavTiles() {
  const { user } = useAuth()
  const visible = adminTiles.filter((t) => !t.permission || can(user, t.permission))

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
