import { NavLink } from 'react-router-dom'
import { useAuth } from '../auth'
import { canAny } from '../lib/api'

/** Styl zakładek jak w Cennikach (PriceListsTabs). */
function tabClass({ isActive }: { isActive: boolean }) {
  return `-mb-px border-b-2 px-3 py-2 text-sm ${
    isActive
      ? 'border-blue-600 font-semibold text-blue-700'
      : 'border-transparent text-slate-600 hover:text-slate-900'
  }`
}

type Tab = { to: string; label: string; end?: boolean; anyOf: string[] }

/** Uprawnienia jak trasy w App.tsx: listę Zapasów widzi też handlowiec (campaigns.use), RW → PW tylko inventory.view. */
const TABS: Tab[] = [
  { to: '/zapasy', label: 'Zalegające', end: true, anyOf: ['inventory.view', 'campaigns.use'] },
  { to: '/zapasy/rw-pw', label: 'RW → PW', anyOf: ['inventory.view'] },
]

/** Podzakładki strony Zapasy — każda według swojego uprawnienia; przy jednej dostępnej pasek się nie pokazuje. */
export function InventoryTabs() {
  const { user } = useAuth()
  const tabs = TABS.filter((t) => canAny(user, t.anyOf))
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
