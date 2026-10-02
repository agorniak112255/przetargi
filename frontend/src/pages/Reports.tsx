import { useSearchParams } from 'react-router-dom'
import { useAuth } from '../auth'
import { canAny, type User } from '../lib/api'
import type { ReportKey } from '../lib/reports'
import { ReportCatalog } from './reports/ReportCatalog'
import { ReportCustomers } from './reports/ReportCustomers'
import { ReportPrices } from './reports/ReportPrices'
import { ReportSales } from './reports/ReportSales'
import { ReportSources } from './reports/ReportSources'

/**
 * Raporty (reports.view): pięć zestawień z danych aplikacji, każde w swojej zakładce (`?raport=`). Zakładkę widać
 * tylko z uprawnieniem do danych, z których raport powstaje — to samo sprawdza serwer (ReportController).
 */

const REPORTS: { key: ReportKey; label: string; lead: string; anyOf: string[] | null }[] = [
  {
    key: 'catalog',
    label: 'Baza wiedzy',
    lead: 'Jak kompletne są karty produktów, z których AI dobiera wyroby do przetargów i zapytań.',
    anyOf: ['products.view'],
  },
  {
    key: 'sources',
    label: 'Źródła danych',
    lead: 'Czy cenniki i konta B2B dostawców są aktualne i czy synchronizacje przechodzą.',
    anyOf: ['price_lists.view', 'b2b_accounts.view'],
  },
  {
    key: 'prices',
    label: 'Ruchy cen',
    lead: 'Podwyżki i obniżki cen u dostawców — gdzie i o ile zmieniły się ceny zakupu.',
    anyOf: ['products.view'],
  },
  {
    key: 'sales',
    label: 'Sprzedaż i oferty',
    lead: 'Zapytania klientów, przetargi i kampanie: ile przyszło, ile obsłużono i jak szybko.',
    anyOf: null,
  },
  {
    key: 'customers',
    label: 'Klienci ERP',
    lead: 'Aktywność klientów z Comarch ERP XL: kto kupuje, kto przestał i do ilu można napisać.',
    anyOf: ['campaigns.use'],
  },
]

function visibleReports(user: User | null) {
  return REPORTS.filter((r) => r.anyOf === null || canAny(user, r.anyOf))
}

export function Reports() {
  const { user } = useAuth()
  const [params, setParams] = useSearchParams()
  const tabs = visibleReports(user)
  const active = tabs.find((t) => t.key === params.get('raport')) ?? tabs[0]

  return (
    <div className="app-reports">
      <div className="app-page-head mb-3 flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="app-page-title text-xl font-semibold">Raporty</h1>
          <p className="mt-0.5 text-sm text-slate-500">{active.lead}</p>
        </div>
      </div>

      <nav className="app-tabs mb-4 flex flex-wrap gap-1 border-b border-slate-200" aria-label="Raporty">
        {tabs.map((t) => {
          const on = t.key === active.key
          return (
            <button
              key={t.key}
              type="button"
              aria-current={on ? 'page' : undefined}
              onClick={() => setParams(t.key === tabs[0].key ? {} : { raport: t.key }, { replace: true })}
              className={`app-tab -mb-px border-b-2 px-3 py-2 text-sm ${
                on ? 'app-tab--active border-blue-600 font-semibold text-blue-700' : 'border-transparent text-slate-600 hover:text-slate-900'
              }`}
            >
              {t.label}
            </button>
          )
        })}
      </nav>

      {active.key === 'catalog' && <ReportCatalog />}
      {active.key === 'sources' && <ReportSources />}
      {active.key === 'prices' && <ReportPrices />}
      {active.key === 'sales' && <ReportSales />}
      {active.key === 'customers' && <ReportCustomers />}
    </div>
  )
}
