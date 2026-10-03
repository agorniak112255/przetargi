import { Link, useParams } from 'react-router-dom'

/**
 * Karta klienta z historią współpracy (/clients/:id, ekran 12 makiety) — GET /clients/{id} i oś czasu
 * (fetchClientCard, fetchClientTimeline w lib/api).
 *
 * ZAŚLEPKA (krok 0) — kafelki, oś czasu i notatki dopisuje strumień B.
 */
export function ClientDetail() {
  const { id } = useParams()
  return (
    <div>
      <p className="mb-3 text-sm">
        <Link to="/clients">← Klienci</Link>
      </p>
      <h1 className="app-page-title text-xl font-semibold">Karta klienta</h1>
      <p className="mt-2 text-sm text-slate-500">Karta klienta numer {id} jeszcze nie jest gotowa.</p>
    </div>
  )
}
