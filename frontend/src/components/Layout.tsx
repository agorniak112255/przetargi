import { NavLink, Outlet } from 'react-router-dom'
import { useAuth } from '../auth'
import { can, canAny } from '../lib/api'
import { usePresence } from '../lib/usePresence'
import { NavIcon, type NavIconName } from './NavIcon'
import { NotificationBell } from './NotificationBell'

type NavLinkItem = {
  to: string
  label: string
  icon: NavIconName
  permission?: string
  anyOf?: string[]
}

const links: NavLinkItem[] = [
  { to: '/', label: 'Dashboard', icon: 'dashboard', permission: 'dashboard.view' },
  { to: '/tenders', label: 'Przetargi', icon: 'tenders', anyOf: ['tenders.view_own', 'tenders.view_all'] },
  { to: '/products', label: 'Produkty', icon: 'products', permission: 'products.view' },
  { to: '/card-matches', label: 'Łączenie kart', icon: 'substitutes', permission: 'card_matches.view' },
  { to: '/price-lists', label: 'Cenniki', icon: 'price-lists', permission: 'price_lists.view' },
  { to: '/substitutes', label: 'Zamienniki', icon: 'substitutes', permission: 'products.view' },
  { to: '/reports', label: 'Raporty', icon: 'reports', permission: 'reports.view' },
  { to: '/clients', label: 'Klienci', icon: 'clients', permission: 'clients.view' },
  { to: '/inquiries', label: 'Zapytania', icon: 'inquiries', permission: 'inquiries.use' },
  { to: '/ai-settings', label: 'Ustawienia AI', icon: 'ai-settings', permission: 'ai_settings.manage' },
  { to: '/admin', label: 'Administracja', icon: 'admin', permission: 'admin.access' },
  { to: '/help', label: 'Pomoc', icon: 'help' },
]

export function Layout() {
  const { user, logout } = useAuth()
  usePresence(Boolean(user))
  const visible = links.filter((l) => {
    if (l.permission) return can(user, l.permission)
    if (l.anyOf) return canAny(user, l.anyOf)
    return true
  })

  return (
    <div className="app-shell flex min-h-screen">
      <aside className="app-sidebar w-60 shrink-0 bg-slate-800 text-slate-100">
        <div className="app-brand border-b border-slate-700 p-4 text-xl font-bold">
          Przetargi Supon
          <small className="app-brand-sub mt-1 block text-xs font-normal text-slate-400">
            {user?.name} · {user?.role}
          </small>
        </div>
        <nav className="app-nav">
          {visible.map((l) => (
            <NavLink
              key={l.to}
              to={l.to}
              end={l.to === '/'}
              className={({ isActive }) =>
                `app-nav-link block border-b border-slate-700 px-4 py-3 text-sm ${
                  isActive
                    ? 'app-nav-link--active border-l-4 border-l-sky-400 bg-slate-700 pl-3'
                    : 'hover:bg-slate-700'
                }`
              }
            >
              <NavIcon name={l.icon} className="app-nav-icon" />
              <span className="app-nav-label">{l.label}</span>
            </NavLink>
          ))}
        </nav>
        <div className="app-sidebar-footer">
          <NavLink
            to="/account"
            className={({ isActive }) =>
              `app-sidebar-btn mx-4 mt-4 block rounded bg-slate-700 px-3 py-2 text-xs hover:bg-slate-600${
                isActive ? ' app-sidebar-btn--active ring-1 ring-sky-400' : ''
              }`
            }
          >
            <NavIcon name="account" className="app-nav-icon" />
            <span className="app-nav-label">Moje konto</span>
          </NavLink>
          <NotificationBell />
          <button
            type="button"
            onClick={() => void logout()}
            className="app-sidebar-btn mx-4 mb-4 rounded bg-slate-700 px-3 py-2 text-xs hover:bg-slate-600"
          >
            <NavIcon name="logout" className="app-nav-icon" />
            <span className="app-nav-label">Wyloguj</span>
          </button>
        </div>
      </aside>
      <main className="app-main flex-1 overflow-auto p-5">
        <Outlet />
      </main>
    </div>
  )
}
