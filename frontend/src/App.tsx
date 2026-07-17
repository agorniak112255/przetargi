import { Navigate, Route, Routes } from 'react-router-dom'
import { AuthProvider, useAuth } from './auth'
import { Layout } from './components/Layout'
import { Clients } from './pages/Clients'
import { Dashboard } from './pages/Dashboard'
import { Help } from './pages/Help'
import { Login } from './pages/Login'
import { ProductDetail } from './pages/ProductDetail'
import { AiSettingsPage } from './pages/AiSettings'
import { PriceLists } from './pages/PriceLists'
import { Products } from './pages/Products'
import { Substitutes } from './pages/Substitutes'
import { TenderDetail } from './pages/TenderDetail'
import { Tenders } from './pages/Tenders'
import type { ReactNode } from 'react'

function Guard({ children }: { children: ReactNode }) {
  const { user, loading } = useAuth()
  if (loading) return <p className="p-8 text-sm text-slate-500">Ładowanie…</p>
  if (!user) return <Navigate to="/login" replace />
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
          <Route path="products/:id" element={<ProductDetail />} />
          <Route path="price-lists" element={<PriceLists />} />
          <Route path="ai-settings" element={<AiSettingsPage />} />
          <Route path="substitutes" element={<Substitutes />} />
          <Route path="clients" element={<Clients />} />
          <Route path="help" element={<Help />} />
        </Route>
      </Routes>
    </AuthProvider>
  )
}
