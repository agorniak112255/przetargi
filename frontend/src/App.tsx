import { Navigate, Route, Routes } from 'react-router-dom'
import { AuthProvider, useAuth } from './auth'
import { Layout } from './components/Layout'
import { AdminRoles } from './pages/AdminRoles'
import { AdminUsers } from './pages/AdminUsers'
import { Clients } from './pages/Clients'
import { Dashboard } from './pages/Dashboard'
import { Help } from './pages/Help'
import { Login } from './pages/Login'
import { ProductCompare } from './pages/ProductCompare'
import { ProductDetail } from './pages/ProductDetail'
import { AiSettingsPage } from './pages/AiSettings'
import { PriceLists } from './pages/PriceLists'
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

export default function App() {
  return (
    <AuthProvider>
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
          <Route path="price-lists" element={<PriceLists />} />
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
            path="admin"
            element={
              <PermissionGuard permission="admin.access">
                <AdminUsers />
              </PermissionGuard>
            }
          />
          <Route
            path="admin/roles"
            element={
              <PermissionGuard permission="admin.roles.manage">
                <AdminRoles />
              </PermissionGuard>
            }
          />
          <Route path="help" element={<Help />} />
          <Route path="*" element={<Navigate to="/" replace />} />
        </Route>
      </Routes>
    </AuthProvider>
  )
}
