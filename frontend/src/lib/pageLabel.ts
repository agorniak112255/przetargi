/** Stałe trasy SPA (App.tsx) z nazwami jak w menu bocznym i kafelkach Administracji. */
const staticLabels: Record<string, string> = {
  '/': 'Dashboard',
  '/login': 'Logowanie',
  '/tenders': 'Przetargi',
  '/products': 'Produkty',
  '/products/compare': 'Porównanie produktów',
  '/price-lists': 'Cenniki',
  '/price-lists/b2b': 'Cenniki B2B',
  '/reports': 'Raporty',
  '/ai-settings': 'Ustawienia AI',
  '/substitutes': 'Zamienniki',
  '/clients': 'Klienci',
  '/inquiries': 'Zapytania',
  '/admin': 'Administracja — Pracownicy',
  '/admin/roles': 'Administracja — Role',
  '/admin/logs': 'Administracja — Logi',
  '/admin/sesje': 'Administracja — Aktywne sesje',
  '/admin/enrichment': 'Administracja — Logi AI',
  '/admin/smtp': 'Administracja — SMTP',
  '/admin/presta': 'Administracja — Sklep Presta',
  '/admin/strony-wyszukiwarka': 'Administracja — Strony wyszukiwarka',
  '/admin/strojenie-ai': 'Administracja — Strojenie AI',
  '/admin/zargon': 'Administracja — Żargon SIWZ',
  '/admin/szablony-opisow': 'Administracja — Szablony opisów',
  '/help': 'Pomoc',
  '/account': 'Moje konto',
}

/** Trasy z identyfikatorem (:id). */
const detailPatterns: Array<{ re: RegExp; label: (id: string) => string }> = [
  { re: /^\/tenders\/([^/]+)$/, label: (id) => `Przetarg #${id}` },
  { re: /^\/products\/([^/]+)$/, label: (id) => `Produkt #${id}` },
  { re: /^\/inquiries\/([^/]+)$/, label: (id) => `Zapytanie #${id}` },
]

/** Czytelna nazwa podstrony dla ścieżki SPA; nieznana ścieżka wraca bez zmian. */
export function pageLabel(path: string | null): string {
  if (path == null || path === '') return '—'
  const normalized = path.length > 1 ? path.replace(/\/+$/, '') || '/' : path
  const known = staticLabels[normalized]
  if (known) return known
  for (const p of detailPatterns) {
    const m = normalized.match(p.re)
    if (m) return p.label(decodeSegment(m[1]))
  }
  return path
}

function decodeSegment(segment: string): string {
  try {
    return decodeURIComponent(segment)
  } catch {
    return segment
  }
}
