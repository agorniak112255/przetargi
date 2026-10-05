import { NavLink } from 'react-router-dom'
import { useAuth } from '../auth'
import { can } from '../lib/api'

function tabClass({ isActive }: { isActive: boolean }) {
  return `-mb-px border-b-2 px-3 py-2 text-sm ${
    isActive
      ? 'border-blue-600 font-semibold text-blue-700'
      : 'border-transparent text-slate-600 hover:text-slate-900'
  }`
}

type Tab = { to: string; label: string; end?: boolean; permission: string | null }

const TABS: Tab[] = [
  { to: '/price-lists', label: 'Cenniki', end: true, permission: null },
  { to: '/price-lists/files', label: 'Z pliku', permission: 'price_lists.view' },
  { to: '/price-lists/b2b', label: 'B2B', permission: 'b2b_accounts.view' },
  { to: '/price-lists/excluded', label: 'Usunięte z pominięciem', permission: 'products.delete' },
]

/** Zakładki cenników — każda według swojego uprawnienia; przy jednej dostępnej zakładce pasek się nie pokazuje. */
export function PriceListsTabs() {
  const { user } = useAuth()
  const tabs = TABS.filter((t) => t.permission === null || can(user, t.permission))
  if (tabs.length < 2) return null

  return (
    <nav className="mb-4 flex gap-1 border-b border-slate-200">
      {tabs.map((t) => (
        <NavLink key={t.to} end={t.end} to={t.to} className={tabClass}>
          {t.label}
        </NavLink>
      ))}
    </nav>
  )
}
