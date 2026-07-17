import { NavLink, Outlet } from 'react-router-dom'
import { useAuth } from '../auth'

const links = [
  { to: '/', label: 'Dashboard' },
  { to: '/tenders', label: 'Przetargi' },
  { to: '/products', label: 'Produkty' },
  { to: '/price-lists', label: 'Cenniki' },
  { to: '/substitutes', label: 'Zamienniki' },
  { to: '/clients', label: 'Klienci' },
  { to: '/ai-settings', label: 'Ustawienia AI' },
  { to: '/help', label: 'Pomoc' },
]

export function Layout() {
  const { user, logout } = useAuth()

  return (
    <div className="flex min-h-screen">
      <aside className="w-60 shrink-0 bg-slate-800 text-slate-100">
        <div className="border-b border-slate-700 p-4 text-xl font-bold">
          SUPON AI
          <small className="mt-1 block text-xs font-normal text-slate-400">
            {user?.name} · {user?.role}
          </small>
        </div>
        <nav>
          {links.map((l) => (
            <NavLink
              key={l.to}
              to={l.to}
              end={l.to === '/'}
              className={({ isActive }) =>
                `block border-b border-slate-700 px-4 py-3 text-sm ${
                  isActive ? 'border-l-4 border-l-sky-400 bg-slate-700 pl-3' : 'hover:bg-slate-700'
                }`
              }
            >
              {l.label}
            </NavLink>
          ))}
        </nav>
        <button
          type="button"
          onClick={() => void logout()}
          className="m-4 rounded bg-slate-700 px-3 py-2 text-xs hover:bg-slate-600"
        >
          Wyloguj
        </button>
      </aside>
      <main className="flex-1 overflow-auto p-5">
        <Outlet />
      </main>
    </div>
  )
}
