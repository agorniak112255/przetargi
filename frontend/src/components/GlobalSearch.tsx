/**
 * Jedno pole wyszukiwania dla całej aplikacji (Ctrl+K, przycisk „Szukaj” w pasku bocznym) — GET /search
 * (globalSearch w lib/api).
 *
 * ZAŚLEPKA (krok 0) — okno z wynikami, skrót klawiszowy i obsługę klawiatury dopisuje strumień A.
 */
export function GlobalSearch({ open, onOpenChange }: { open: boolean; onOpenChange: (open: boolean) => void }) {
  if (!open) return null
  return (
    <div className="fixed inset-0 z-50 grid place-items-start justify-center bg-slate-900/40 p-4 pt-24" onClick={() => onOpenChange(false)}>
      <div className="app-card w-full max-w-lg rounded-xl bg-white p-4 text-sm shadow-lg" onClick={(e) => e.stopPropagation()}>
        <p className="text-slate-600">Wyszukiwanie w całej aplikacji jeszcze nie jest gotowe.</p>
        <button type="button" className="mt-3 rounded border border-slate-300 px-3 py-1.5 text-xs hover:bg-slate-50" onClick={() => onOpenChange(false)}>
          Zamknij
        </button>
      </div>
    </div>
  )
}
