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

export function PriceListsTabs() {
  const { user } = useAuth()
  if (!can(user, 'b2b_accounts.view')) return null

  return (
    <nav className="mb-4 flex gap-1 border-b border-slate-200">
      <NavLink end to="/price-lists" className={tabClass}>
        Cenniki
      </NavLink>
      <NavLink to="/price-lists/b2b" className={tabClass}>
        B2B
      </NavLink>
    </nav>
  )
}
