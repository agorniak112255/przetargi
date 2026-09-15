import { NavLink, Outlet } from 'react-router-dom'
import { useAuth } from '../auth'
import { can, canAny } from '../lib/api'
import { NotificationBell } from './NotificationBell'

type NavLinkItem = {
  to: string
  label: string
  permission?: string
  anyOf?: string[]
}

const links: NavLinkItem[] = [
  { to: '/', label: 'Dashboard', permission: 'dashboard.view' },
  { to: '/tenders', label: 'Przetargi', anyOf: ['tenders.view_own', 'tenders.view_all'] },
  { to: '/products', label: 'Produkty', permission: 'products.view' },
  { to: '/price-lists', label: 'Cenniki', permission: 'price_lists.view' },
  { to: '/substitutes', label: 'Zamienniki', permission: 'products.view' },
  { to: '/reports', label: 'Raporty', permission: 'reports.view' },
  { to: '/clients', label: 'Klienci', permission: 'clients.view' },
  { to: '/inquiries', label: 'Zapytania', permission: 'inquiries.use' },
  { to: '/ai-settings', label: 'Ustawienia AI', permission: 'ai_settings.manage' },
  { to: '/admin', label: 'Administracja', permission: 'admin.access' },
  { to: '/help', label: 'Pomoc' },
]

export function Layout() {
  const { user, logout } = useAuth()
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
              {l.label}
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
            Moje konto
          </NavLink>
          <NotificationBell />
          <button
            type="button"
            onClick={() => void logout()}
            className="app-sidebar-btn mx-4 mb-4 rounded bg-slate-700 px-3 py-2 text-xs hover:bg-slate-600"
          >
            Wyloguj
          </button>
        </div>
      </aside>
      <main className="app-main flex-1 overflow-auto p-5">
        <Outlet />
      </main>
    </div>
  )
}
