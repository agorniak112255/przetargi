import { useState } from 'react'
import { NavLink, Outlet, useLocation } from 'react-router-dom'
import { useAuth } from '../auth'
import { can, canAny, type User } from '../lib/api'
import { CHAT_PERMISSION } from '../lib/chat'
import { usePresence } from '../lib/usePresence'
import { ChatNavBadge } from './ChatUnreadProvider'
import { GlobalSearch } from './GlobalSearch'
import { IncomingCallProvider } from './IncomingCallProvider'
import { NavIcon, type NavIconName } from './NavIcon'
import { NotificationBell } from './NotificationBell'

type NavLinkItem = {
  to: string
  label: string
  icon: NavIconName
  permission?: string
  anyOf?: string[]
  /** Widoczne także bez uprawnienia, gdy użytkownik ma zakres raportu „Wynik kampanii”. */
  orCampaignReport?: true
}

/** Rozwijana grupa pozycji; przy jednej widocznej pozycji menu pokazuje zwykły link, w zwiniętym pasku — ikony płasko. */
type NavGroupItem = {
  group: string
  label: string
  icon: NavIconName
  children: NavLinkItem[]
}

type NavEntry = NavLinkItem | NavGroupItem

const links: NavEntry[] = [
  { to: '/', label: 'Dashboard', icon: 'dashboard', permission: 'dashboard.view' },
  { to: '/tenders', label: 'Przetargi', icon: 'tenders', anyOf: ['tenders.view_own', 'tenders.view_all'] },
  { to: '/ogloszenia', label: 'Ogłoszenia', icon: 'notices', anyOf: ['notices.view'] },
  { to: '/products', label: 'Produkty', icon: 'products', permission: 'products.view' },
  { to: '/zapasy', label: 'Zapasy', icon: 'inventory', anyOf: ['inventory.view', 'campaigns.use'] },
  { to: '/raport-zapasow', label: 'Raport dla zarządu', icon: 'reports', permission: 'inventory.report.view' },
  { to: '/przeglady', label: 'Przeglądy', icon: 'inspections', anyOf: ['inspections.view', 'inspections.manage'] },
  {
    group: 'campaigns-offers',
    label: 'Kampanie i oferty',
    icon: 'campaigns',
    children: [
      { to: '/kampanie', label: 'Kampanie', icon: 'campaigns', anyOf: ['campaigns.use', 'campaigns.view'] },
      { to: '/oferty', label: 'Oferty', icon: 'offers', anyOf: ['offers.use', 'inspections.offer'] },
    ],
  },
  { to: '/card-matches', label: 'Łączenie kart', icon: 'substitutes', permission: 'card_matches.view' },
  { to: '/price-lists', label: 'Cenniki', icon: 'price-lists', permission: 'price_lists.view' },
  { to: '/substitutes', label: 'Zamienniki', icon: 'substitutes', permission: 'products.view' },
  { to: '/reports', label: 'Raporty', icon: 'reports', permission: 'reports.view', orCampaignReport: true },
  { to: '/clients', label: 'Klienci', icon: 'clients', permission: 'clients.view' },
  { to: '/inquiries', label: 'Zapytania', icon: 'inquiries', permission: 'inquiries.use' },
  { to: '/czat', label: 'Czat', icon: 'chat', permission: CHAT_PERMISSION },
  { to: '/ai-settings', label: 'Ustawienia AI', icon: 'ai-settings', permission: 'ai_settings.manage' },
  { to: '/admin', label: 'Administracja', icon: 'admin', permission: 'admin.access' },
  { to: '/help', label: 'Pomoc', icon: 'help' },
]

/** Zwinięty pasek boczny (same ikony) — zapamiętany w tej przeglądarce, np. na laptopie. */
const COLLAPSED_KEY = 'supon_sidebar_collapsed'

function readCollapsed(): boolean {
  try {
    return localStorage.getItem(COLLAPSED_KEY) === '1'
  } catch {
    return false
  }
}

/** Rozwinięcie grupy menu — zapamiętane w tej przeglądarce; bez zapisu grupa jest rozwinięta (łatwo znaleźć pozycje). */
const groupOpenKey = (group: string) => `supon_nav_group_${group}_open`

function readGroupOpen(group: string): boolean {
  try {
    return localStorage.getItem(groupOpenKey(group)) !== '0'
  } catch {
    return true
  }
}

function writeGroupOpen(group: string, open: boolean) {
  try {
    localStorage.setItem(groupOpenKey(group), open ? '1' : '0')
  } catch {
    /* bez zapisu — stan grupy działa do odświeżenia strony */
  }
}

function isLinkVisible(user: User | null | undefined, l: NavLinkItem): boolean {
  if (l.orCampaignReport && user?.campaign_report_scope != null) return true
  if (l.permission) return can(user, l.permission)
  if (l.anyOf) return canAny(user, l.anyOf)
  return true
}

/** Trasa należy do pozycji: sam adres albo strona w środku (/oferty/12). */
function isUnder(pathname: string, to: string): boolean {
  return pathname === to || pathname.startsWith(`${to}/`)
}

function SideLink({ l, collapsed }: { l: NavLinkItem; collapsed: boolean }) {
  return (
    <NavLink
      to={l.to}
      end={l.to === '/'}
      data-tip={collapsed ? l.label : undefined}
      className={({ isActive }) =>
        `app-nav-link block border-b border-slate-700 px-4 py-3 text-sm ${
          isActive ? 'app-nav-link--active border-l-4 border-l-sky-400 bg-slate-700 pl-3' : 'hover:bg-slate-700'
        }`
      }
    >
      <NavIcon name={l.icon} className="app-nav-icon" />
      <span className="app-nav-label">{l.label}</span>
      {l.to === '/czat' && <ChatNavBadge />}
    </NavLink>
  )
}

/**
 * Rozwijana grupa w rozwiniętym pasku. Wejście na stronę z grupy (także z linku spoza menu) rozwija ją; poza tym
 * stan z localStorage. Zwinięta grupa z aktywną stroną w środku podświetla nagłówek jak aktywny link.
 */
function SideGroup({ g, items }: { g: NavGroupItem; items: NavLinkItem[] }) {
  const { pathname } = useLocation()
  const inside = items.some((l) => isUnder(pathname, l.to))
  const [open, setOpen] = useState(() => inside || readGroupOpen(g.group))
  const [wasInside, setWasInside] = useState(inside)
  if (inside !== wasInside) {
    // zmiana trasy w czasie renderu (bez efektu): wejście do grupy ją rozwija, wyjście niczego nie zwija
    setWasInside(inside)
    if (inside && !open) setOpen(true)
  }

  function toggle() {
    const next = !open
    setOpen(next)
    writeGroupOpen(g.group, next)
  }

  const listId = `app-nav-group-${g.group}`
  const activeHeader = inside && !open
  return (
    <>
      {/* app-sidebar-btn: w szablonach z marginesem pozycji (nocna zmiana) przycisk dostaje szerokość jak linki */}
      <button
        type="button"
        onClick={toggle}
        aria-expanded={open}
        aria-controls={listId}
        className={`app-nav-link app-sidebar-btn flex w-full items-center border-b border-slate-700 px-4 py-3 text-left text-sm ${
          activeHeader ? 'app-nav-link--active border-l-4 border-l-sky-400 bg-slate-700 pl-3' : 'hover:bg-slate-700'
        }`}
      >
        <NavIcon name={g.icon} className="app-nav-icon" />
        <span className="app-nav-label">{g.label}</span>
        <svg
          className={`ml-auto shrink-0 transition-transform ${open ? 'rotate-180' : ''}`}
          width="12"
          height="12"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          strokeWidth="2.2"
          strokeLinecap="round"
          strokeLinejoin="round"
          aria-hidden="true"
        >
          <path d="M6 9l6 6 6-6" />
        </svg>
      </button>
      <div id={listId} hidden={!open} className="pl-4">
        {items.map((l) => (
          <SideLink key={l.to} l={l} collapsed={false} />
        ))}
      </div>
    </>
  )
}

export function Layout() {
  const { user, logout } = useAuth()
  usePresence(Boolean(user))
  const [collapsed, setCollapsed] = useState(readCollapsed)
  const [searchOpen, setSearchOpen] = useState(false)

  function toggleCollapsed() {
    const next = !collapsed
    setCollapsed(next)
    try {
      if (next) localStorage.setItem(COLLAPSED_KEY, '1')
      else localStorage.removeItem(COLLAPSED_KEY)
    } catch {
      /* bez zapisu — zwinięcie działa do odświeżenia strony */
    }
  }

  // Grupa z jedną widoczną pozycją = zwykły link (jak przed grupą); w zwiniętym pasku pozycje grupy idą płasko.
  const visible: NavEntry[] = links.flatMap((entry): NavEntry[] => {
    if (!('group' in entry)) return isLinkVisible(user, entry) ? [entry] : []
    const children = entry.children.filter((l) => isLinkVisible(user, l))
    if (children.length > 1 && !collapsed) return [{ ...entry, children }]
    return children
  })

  return (
    <div className="app-shell flex min-h-screen">
      <aside
        className={`app-sidebar shrink-0 bg-slate-800 text-slate-100 print:hidden ${
          collapsed ? 'app-sidebar--collapsed w-16' : 'w-60'
        }`}
      >
        {/* Kliknięcie w logo lub strzałki obok zwija / rozwija pasek — jak przycisk na dole paska */}
        <button
          type="button"
          onClick={toggleCollapsed}
          aria-expanded={!collapsed}
          title={collapsed ? undefined : 'Zwiń menu do samych ikon'}
          data-tip={collapsed ? 'Rozwiń menu' : undefined}
          className="app-brand relative w-full cursor-pointer border-b border-slate-700 p-4 pr-10 text-left text-xl font-bold hover:bg-slate-700"
        >
          <span className="app-brand-name">Przetargi Supon</span>
          <small className="app-brand-sub mt-1 block text-xs font-normal text-slate-400">
            {user?.name} · {user?.role}
          </small>
          <NavIcon name={collapsed ? 'expand' : 'collapse'} className="app-brand-toggle" />
        </button>
        <nav className="app-nav">
          {visible.map((entry) =>
            'group' in entry ? (
              <SideGroup key={entry.group} g={entry} items={entry.children} />
            ) : (
              <SideLink key={entry.to} l={entry} collapsed={collapsed} />
            ),
          )}
        </nav>
        <div className="app-sidebar-footer">
          {/* jedno pole wyszukiwania dla całej aplikacji; skrót Ctrl+K obsługuje samo okno GlobalSearch */}
          <button
            type="button"
            onClick={() => setSearchOpen(true)}
            data-tip={collapsed ? 'Szukaj (Ctrl+K)' : undefined}
            className="app-sidebar-btn mx-4 mt-4 block w-[calc(100%-2rem)] rounded bg-slate-700 px-3 py-2 text-left text-xs hover:bg-slate-600"
          >
            <NavIcon name="search" className="app-nav-icon" />
            <span className="app-nav-label">Szukaj (Ctrl+K)</span>
          </button>
          <NavLink
            to="/account"
            data-tip={collapsed ? 'Moje konto' : undefined}
            className={({ isActive }) =>
              `app-sidebar-btn mx-4 mt-4 block rounded bg-slate-700 px-3 py-2 text-xs hover:bg-slate-600${
                isActive ? ' app-sidebar-btn--active ring-1 ring-sky-400' : ''
              }`
            }
          >
            <NavIcon name="account" className="app-nav-icon" />
            <span className="app-nav-label">Moje konto</span>
          </NavLink>
          <NotificationBell collapsed={collapsed} />
          <button
            type="button"
            onClick={() => void logout()}
            data-tip={collapsed ? 'Wyloguj' : undefined}
            className="app-sidebar-btn mx-4 mb-4 rounded bg-slate-700 px-3 py-2 text-xs hover:bg-slate-600"
          >
            <NavIcon name="logout" className="app-nav-icon" />
            <span className="app-nav-label">Wyloguj</span>
          </button>
          <button
            type="button"
            onClick={toggleCollapsed}
            aria-expanded={!collapsed}
            title={collapsed ? undefined : 'Zwiń menu do samych ikon'}
            data-tip={collapsed ? 'Rozwiń menu' : undefined}
            className="app-sidebar-btn app-sidebar-toggle mx-4 mb-4 rounded px-3 py-2 text-xs text-slate-400 hover:bg-slate-700 hover:text-white"
          >
            <NavIcon name={collapsed ? 'expand' : 'collapse'} className="app-nav-icon" />
            <span className="app-nav-label">{collapsed ? 'Rozwiń menu' : 'Zwiń menu'}</span>
          </button>
        </div>
      </aside>
      <main className="app-main flex-1 overflow-auto p-5">
        <Outlet />
      </main>
      <IncomingCallProvider />
      <GlobalSearch open={searchOpen} onOpenChange={setSearchOpen} />
    </div>
  )
}
