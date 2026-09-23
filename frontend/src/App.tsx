import { Navigate, Route, Routes } from 'react-router-dom'
import { AppearanceProvider } from './appearance'
import { AuthProvider, useAuth } from './auth'
import { Layout } from './components/Layout'
import { adminTiles } from './components/AdminNavTiles'
import { Account } from './pages/Account'
import { AdminActivityLog } from './pages/AdminActivityLog'
import { AdminEnrichmentLogs } from './pages/AdminEnrichmentLogs'
import { AdminLayout } from './pages/AdminLayout'
import { AdminRoles } from './pages/AdminRoles'
import { AdminPresta } from './pages/AdminPresta'
import { AdminSearchSites } from './pages/AdminSearchSites'
import { AdminSessions } from './pages/AdminSessions'
import { AdminAiTuning } from './pages/AdminAiTuning'
import { AdminCatalogSlang } from './pages/AdminCatalogSlang'
import { AdminDescriptionTemplates } from './pages/AdminDescriptionTemplates'
import { AdminDictionaries } from './pages/AdminDictionaries'
import { AdminSmtp } from './pages/AdminSmtp'
import { AdminUsers } from './pages/AdminUsers'
import { CardMatches } from './pages/CardMatches'
import { Clients } from './pages/Clients'
import { Inquiries } from './pages/Inquiries'
import { InquiryReply } from './pages/InquiryReply'
import { Dashboard } from './pages/Dashboard'
import { Help } from './pages/Help'
import { Login } from './pages/Login'
import { ProductCompare } from './pages/ProductCompare'
import { ProductDetail } from './pages/ProductDetail'
import { AiSettingsPage } from './pages/AiSettings'
import { PriceLists } from './pages/PriceLists'
import { PriceListsB2b } from './pages/PriceListsB2b'
import { Products } from './pages/Products'
import { Reports } from './pages/Reports'
import { Substitutes } from './pages/Substitutes'
import { TenderDetail } from './pages/TenderDetail'
import { Tenders } from './pages/Tenders'
import { can } from './lib/api'
import type { ReactNode } from 'react'

function Guard({ children }: { children: ReactNode }) {
  const { user, loading } = useAuth()
  if (loading) return <p className="p-8 text-sm text-slate-500">Ładowanie…</p>
  if (!user) return <Navigate to="/login" replace />
  return children
}

function PermissionGuard({ permission, children }: { permission: string; children: ReactNode }) {
  const { user, loading } = useAuth()
  if (loading) return <p className="p-8 text-sm text-slate-500">Ładowanie…</p>
  if (!user) return <Navigate to="/login" replace />
  if (!can(user, permission)) return <Navigate to="/" replace />
  return children
}

/** Administracja bez prawa do listy pracowników otwiera pierwszy dostępny kafelek zamiast wracać na pulpit. */
function AdminIndex() {
  const { user } = useAuth()
  if (can(user, 'admin.users.manage')) return <AdminUsers />
  const firstAllowed = adminTiles.find(
    (t) => t.to !== '/admin' && (!t.permission || can(user, t.permission)),
  )
  return <Navigate to={firstAllowed ? firstAllowed.to : '/'} replace />
}

export default function App() {
  return (
    <AuthProvider>
      <AppearanceProvider>
        <Routes>
          <Route path="/login" element={<Login />} />
          <Route
            element={
              <Guard>
                <Layout />
              </Guard>
            }
          >
            <Route index element={<Dashboard />} />
            <Route path="tenders" element={<Tenders />} />
            <Route path="tenders/:id" element={<TenderDetail />} />
            <Route path="products" element={<Products />} />
            <Route path="products/compare" element={<ProductCompare />} />
            <Route path="products/:id" element={<ProductDetail />} />
            <Route
              path="card-matches"
              element={
                <PermissionGuard permission="products.view">
                  <CardMatches />
                </PermissionGuard>
              }
            />
            <Route path="price-lists" element={<PriceLists />} />
            <Route
              path="price-lists/b2b"
              element={
                <PermissionGuard permission="b2b_accounts.view">
                  <PriceListsB2b />
                </PermissionGuard>
              }
            />
            <Route
              path="reports"
              element={
                <PermissionGuard permission="reports.view">
                  <Reports />
                </PermissionGuard>
              }
            />

            <Route
              path="ai-settings"
              element={
                <PermissionGuard permission="ai_settings.manage">
                  <AiSettingsPage />
                </PermissionGuard>
              }
            />
            <Route path="substitutes" element={<Substitutes />} />
            <Route path="clients" element={<Clients />} />
            <Route
              path="inquiries"
              element={
                <PermissionGuard permission="inquiries.use">
                  <Inquiries />
                </PermissionGuard>
              }
            />
            <Route
              path="inquiries/:id"
              element={
                <PermissionGuard permission="inquiries.use">
                  <InquiryReply />
                </PermissionGuard>
              }
            />
            <Route
              path="admin"
              element={
                <PermissionGuard permission="admin.access">
                  <AdminLayout />
                </PermissionGuard>
              }
            >
              <Route index element={<AdminIndex />} />
              <Route
                path="roles"
                element={
                  <PermissionGuard permission="admin.roles.manage">
                    <AdminRoles />
                  </PermissionGuard>
                }
              />
              <Route
                path="logs"
                element={
                  <PermissionGuard permission="admin.activity.view">
                    <AdminActivityLog />
                  </PermissionGuard>
                }
              />
              <Route
                path="sesje"
                element={
                  <PermissionGuard permission="admin.sessions.view">
                    <AdminSessions />
                  </PermissionGuard>
                }
              />
              <Route
                path="enrichment"
                element={
                  <PermissionGuard permission="admin.enrichment.view">
                    <AdminEnrichmentLogs />
                  </PermissionGuard>
                }
              />
              <Route
                path="smtp"
                element={
                  <PermissionGuard permission="admin.mail.manage">
                    <AdminSmtp />
                  </PermissionGuard>
                }
              />
              <Route
                path="presta"
                element={
                  <PermissionGuard permission="admin.presta.manage">
                    <AdminPresta />
                  </PermissionGuard>
                }
              />
              <Route
                path="strony-wyszukiwarka"
                element={
                  <PermissionGuard permission="admin.search_sites.manage">
                    <AdminSearchSites />
                  </PermissionGuard>
                }
              />
              <Route
                path="strojenie-ai"
                element={
                  <PermissionGuard permission="admin.ai_tuning.manage">
                    <AdminAiTuning />
                  </PermissionGuard>
                }
              />
              <Route
                path="zargon"
                element={
                  <PermissionGuard permission="admin.catalog_slang.manage">
                    <AdminCatalogSlang />
                  </PermissionGuard>
                }
              />
              <Route
                path="slowniki"
                element={
                  <PermissionGuard permission="admin.dictionaries.manage">
                    <AdminDictionaries />
                  </PermissionGuard>
                }
              />
              <Route
                path="szablony-opisow"
                element={
                  <PermissionGuard permission="admin.description_templates.manage">
                    <AdminDescriptionTemplates />
                  </PermissionGuard>
                }
              />
            </Route>
            <Route path="help" element={<Help />} />
            <Route path="account" element={<Account />} />
            <Route path="*" element={<Navigate to="/" replace />} />
          </Route>
        </Routes>
      </AppearanceProvider>
    </AuthProvider>
  )
}
