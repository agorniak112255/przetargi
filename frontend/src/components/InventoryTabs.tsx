import { NavLink } from 'react-router-dom'

/** Styl zakładek jak w Cennikach (PriceListsTabs). */
function tabClass({ isActive }: { isActive: boolean }) {
  return `-mb-px border-b-2 px-3 py-2 text-sm ${
    isActive
      ? 'border-blue-600 font-semibold text-blue-700'
      : 'border-transparent text-slate-600 hover:text-slate-900'
  }`
}

/** Podzakładki strony Zapasy — obie pod tym samym uprawnieniem inventory.view (trasy w App.tsx). */
export function InventoryTabs() {
  return (
    <nav className="mb-4 flex gap-1 border-b border-slate-200">
      <NavLink end to="/zapasy" className={tabClass}>
        Zalegające
      </NavLink>
      <NavLink to="/zapasy/rw-pw" className={tabClass}>
        RW → PW
      </NavLink>
    </nav>
  )
}
