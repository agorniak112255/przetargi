import { Outlet } from 'react-router-dom'
import { AdminNavTiles } from '../components/AdminNavTiles'

export function AdminLayout() {
  return (
    <div>
      <AdminNavTiles />
      <Outlet />
    </div>
  )
}
