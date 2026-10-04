import { useCallback, useEffect, useRef, useState } from 'react'
import { api, type LossReason } from './api'
import { errorText } from './campaignFormat'
import { plural } from './plural'

/**
 * Moduł „Raporty”: typy odpowiedzi GET /api/reports/{catalog|prices|sources|sales|customers|effectiveness} (ReportController
 * i klasy App\Services\Reports\*), formatowanie liczb i dat oraz ładowanie danych z ochroną przed spóźnioną odpowiedzią.
 * Sekcja null = brak uprawnienia do danych, z których powstaje (nie zera).
 */

export type ReportKey = 'catalog' | 'prices' | 'sources' | 'sales' | 'customers' | 'effectiveness' | 'targets' | 'campaigns'

export type CatalogCoverage = {
  with_description: number
  with_image: number
  with_manufacturer_norms: number
  with_documents: number
}

export type CatalogReportData = {
  generated_at: string
  short_description_chars: number
  totals: CatalogCoverage & {
    products: number
    short_description: number
    with_norms_text: number
    vector_indexed: number
    enrichment: { none: number; queued: number; running: number; done: number; failed: number; manual: number }
  }
  documents_by_kind: { kind: string; products: number }[]
  sold: (CatalogCoverage & { window_months: number; products: number }) | null
  weekly: { week_start: string; added: number; enriched: number }[]
  manufacturers: (CatalogCoverage & {
    manufacturer: string
    products: number
    with_norms_text: number
    enrichment_failed: number
  })[]
  gaps_sold: {
    id: number
    sku: string
    name: string
    manufacturer: string | null
    last_sale_at: string
    missing: ('description' | 'image' | 'manufacturer_norms' | 'documents')[]
  }[]
}

export type PriceMove = {
  product_id: number
  sku: string
  name: string
  manufacturer: string | null
  source_label: string
  currency: string | null
  old: number
  new: number
  pct: number
  basis: 'purchase' | 'catalog'
  at: string
}

export type PricesReportData = {
  generated_at: string
  days: number
  from: string
  history_since: string | null
  masked: boolean
  totals: {
    changes: number
    increases: number
    decreases: number
    /** Zmiany o mniej niż 1% (zaokrąglenia, kurs) — wliczone w changes/increases/decreases. */
    minor_changes: number
    products: number
    sources: number
    additions: number
    discount_changes: number
    suspicious: number
    median_pct: number | null
    q1_pct: number | null
    q3_pct: number | null
  }
  /** Tu i w wierszach źródeł/producentów increases/decreases to tylko zmiany istotne (≥ 1%); drobne osobno w `minor`. */
  weekly: { week_start: string; increases: number; decreases: number; minor: number }[]
  sources: {
    source_label: string
    changes: number
    increases: number
    decreases: number
    minor: number
    median_pct: number | null
    max_pct: number | null
  }[]
  manufacturers: { manufacturer: string; changes: number; increases: number; decreases: number; minor: number; median_pct: number | null }[]
  top_increases: PriceMove[]
  top_decreases: PriceMove[]
  suspicious: PriceMove[]
}

export type RunState = 'ok' | 'partial' | 'failed' | 'cancelled' | 'running' | 'interrupted'

export type SourcesReportData = {
  generated_at: string
  thresholds: { daily_hours: number; weekly_days: number; file_old_days: number }
  b2b: {
    totals: { accounts: number; scheduled: number; fresh: number; stale: number; failing: number; never_ok: number }
    daily: { day: string; ok: number; partial: number; failed: number; cancelled: number; interrupted: number }[]
    accounts: {
      id: number
      label: string
      connector: string | null
      frequency: 'off' | 'daily' | 'weekly'
      last_status: RunState | null
      last_run_at: string | null
      last_ok_at: string | null
      hours_since_ok: number | null
      stale: boolean
      failed_streak: number
      runs_30d: number
      failed_30d: number
      products: number
      seen_7d_pct: number | null
      last_prices_changed: number | null
      message: string | null
    }[]
  } | null
  files: {
    price_list_id: number
    manufacturer: string
    version: string | null
    last_import_at: string | null
    days_ago: number | null
    imports_12m: number
    rows_total: number | null
    prices_changed: number | null
    rows_skipped: number | null
    suggested: boolean
    old: boolean
  }[] | null
}

/**
 * Od zapytania do sprzedaży: przyszło (kopie tego samego maila raz), odpowiedzieliśmy, zamówił — potwierdzone przez
 * handlowca; possible — same podpowiedzi z ERP XL (wniosek, pokazywany osobno i szaro).
 */
export type SalesFunnel = {
  received: number
  replied: number
  ordered_confirmed: number
  possible: number
  value_confirmed: string
  /** potwierdzone „zamówił” bez wskazanego dokumentu z ERP XL */
  ordered_without_document: number
}

/** Cele handlowców (GET /reports/targets?month=RRRR-MM, PUT /reports/targets/{RRRR-MM}). */
export type SalesTargetsReport = {
  month: string
  months: { key: string; label: string }[]
  /** miesiąc zamknięty (miniony) */
  closed: boolean
  /** miesiąc przyszły (następny) — cele ustalane z wyprzedzeniem, sprzedaży i realizacji jeszcze nie ma */
  upcoming: boolean
  /** dni robocze miesiąca w toku; null — miesiąc zamknięty albo przyszły */
  workdays: { total: number; elapsed: number } | null
  /** dane sprzedaży z ERP XL do dnia (ostatni nocny odczyt) */
  data_until: string | null
  /** reguła liczenia słowami (klienci z zakładki Klienci, opiekun według dzisiejszego stanu) */
  rule: string
  rows: {
    user_id: number
    name: string
    target: string | null
    sales: string
    percent: number | null
    clients_bought: number
    new_clients: number
    by_source: { xl: string; app: string }
    has_employee: boolean
  }[]
  unassigned: { sales: string; clients_bought: number; new_clients: number }
  total: { target: string | null; sales: string; percent: number | null }
}

export function fetchSalesTargets(month?: string, signal?: AbortSignal): Promise<SalesTargetsReport> {
  return api<SalesTargetsReport>(`/reports/targets${month ? `?month=${encodeURIComponent(month)}` : ''}`, { signal })
}

/** amount null usuwa cel osoby w tym miesiącu. */
export function saveSalesTargets(month: string, targets: { user_id: number; amount: number | null }[]): Promise<SalesTargetsReport> {
  return api<SalesTargetsReport>(`/reports/targets/${month}`, { method: 'PUT', body: JSON.stringify({ targets }) })
}

/**
 * Wynik kampanii (GET /reports/campaigns?month=RRRR-MM[&user_id=N], CampaignsReport w App\Services\Reports).
 * KONTRAKT zamrożony 04.10.2026. Kwoty w zł netto jako liczby z 2 miejscami; null = brak danych (nigdy 0 zamiast braku).
 * Miesiąc = data wystawienia faktury/paragonu/korekty (data polska). Każda pozycja faktury należy najwyżej do jednej
 * kampanii: ostatniego maila z tym towarem, który klient dostał przed zakupem (okno: dzień maila odbiorcy … dzień
 * startu kampanii + 30 dni, czas polski).
 * „Uwolnione pieniądze” = koszt zakupu sprzedanego towaru ZALEGAJĄCEGO w dniu wysyłki (bez sprzedaży ≥ stagnant_days
 * dni albo nigdy niesprzedany z partią ≥ stagnant_days dni), najwyżej do stanu z dnia wysyłki.
 */
export type CampaignsReportMoney = number | null

export type CampaignsReportFigures = {
  /** sprzedaż netto przypisana kampaniom (faktury i paragony minus korekty) */
  sales_net: number
  /** koszt zakupu sprzedanego zalegającego towaru w limicie stanu z dnia wysyłki */
  freed_capital: number
  /** część freed_capital liczona szacunkiem (ilość × koszt jednostki z dnia wysyłki), gdy ERP XL nie podał kosztu */
  freed_estimated: number
  /** netto uzyskane za ten sam zalegający towar (licznik „odzysku”) */
  freed_sales_net: number
  /** freed_sales_net / freed_capital × 100 — „za 100 zł zapasu klient zapłacił X zł”; null przy freed_capital 0 */
  recovery_percent: number | null
  /** netto − koszt po pozycjach ze znanym kosztem (z ERP XL albo szacunkiem); null, gdy żadna pozycja nie ma kosztu */
  margin: CampaignsReportMoney
  /** margin / netto tych samych pozycji × 100 */
  margin_percent: number | null
  /** pozycje sprzedaży (bez korekt) z netto niższym niż koszt i łączna strata na nich */
  below_cost_lines: number
  below_cost_loss: number
  /** unikalni klienci z fakturą lub paragonem */
  buyers: number
  /** pozycje bez kosztu ani w ERP XL, ani w migawce (marża nieznana) */
  lines_without_cost: number
  /** pozycje, przy których nie wiadomo, ile uwolniły (brak danych z dnia wysyłki albo zalegający bez kosztu/stanu) */
  freed_unknown_lines: number
}

export type CampaignsReportItem = {
  erp_item_id: number
  code: string
  name: string
  unit: string | null
  /** zalegający w dniu wysyłki; null = brak danych z chwili wysyłki */
  stagnant: boolean | null
  /** dni bez sprzedaży przed mailem (dzień startu − ostatnia sprzedaż); null przy never_sold albo braku migawki */
  idle_days: number | null
  /** w ERP XL nie było żadnej sprzedaży przed wysyłką */
  never_sold: boolean
  /** wiek najstarszej partii w dniu wysyłki (dni); null = nieznany */
  lot_age_days: number | null
  /** 'send' = zapis przy starcie; null = kampania sprzed raportu (brak danych z chwili wysyłki) */
  snap_source: string | null
  stock_at_send: number | null
  unit_cost: number | null
  /** stock_at_send × unit_cost */
  offered_value: CampaignsReportMoney
  promo_price: CampaignsReportMoney
  /** wybrany miesiąc */
  month: { quantity: number; sales_net: number; freed_capital: number; margin: CampaignsReportMoney }
  /** całe okno kampanii (do dziś) */
  window: {
    quantity: number
    sales_net: number
    freed_capital: number
    /** netto / ilość sprzedaży (bez korekt); null przy braku sprzedaży */
    avg_price: CampaignsReportMoney
    buyers: number
    /** dni od startu do pierwszej sprzedaży; null = brak */
    first_sale_days: number | null
    /** ilość sprzedana ponad stan z dnia wysyłki (nie liczy się do uwolnionych) */
    over_stock_quantity: number
  }
  /** czy któraś pozycja tego towaru ma koszt szacowany */
  cost_estimated: boolean
}

export type CampaignsReportCampaign = {
  id: number
  code: string
  name: string
  user_id: number
  user_name: string
  status: string
  started_at: string
  /** ostatni dzień okna (RRRR-MM-DD) */
  window_end: string
  window_open: boolean
  recipients_sent: number
  /** liczby tej kampanii w wybranym miesiącu */
  month: CampaignsReportFigures
  /** całe okno do dziś */
  window: CampaignsReportFigures & {
    /** wartość zalegającego towaru w ofercie (Σ stan × koszt pozycji zalegających) */
    offered_stock_value: CampaignsReportMoney
    /** window.freed_capital / offered_stock_value × 100 */
    effectiveness_percent: number | null
  }
  /** zakupy klientów tej kampanii przypisane późniejszemu mailowi innej kampanii (cały okres okna) */
  taken_over: { lines: number; sales_net: number; by: { campaign_id: number; code: string; user_name: string }[] }
  items: CampaignsReportItem[]
}

export type CampaignsReportPerson = CampaignsReportFigures & {
  user_id: number
  name: string
  /** kampanie z oknem nachodzącym na miesiąc */
  campaigns: number
  /** maile wysłane w miesiącu */
  recipients_sent: number
  avg_idle_days: number | null
}

export type CampaignsReport = {
  generated_at: string
  month: string
  /** bieżący i 11 poprzednich, od najnowszego */
  months: { key: string; label: string }[]
  scope: 'own' | 'team' | 'all'
  /** osoby w zakresie (filtr); przy own — tylko zalogowany */
  people_options: { user_id: number; name: string }[]
  /** wybrana osoba (filtr) albo null = wszyscy w zakresie */
  user_id: number | null
  /** miesiąc miniony */
  closed: boolean
  /** od tego dnia liczby miesiąca są ostateczne (koniec miesiąca + 7 dni nocnych odczytów); korekty mogą przyjść później */
  final_after: string
  /** ostatni nocny odczyt sprzedaży z ERP XL (ISO) */
  data_until: string | null
  stagnant_days: number
  /** reguły liczenia słowami */
  rule: string
  totals: CampaignsReportFigures & { campaigns: number; recipients_sent: number; avg_idle_days: number | null; corrections_net: number }
  /** osoby posortowane malejąco po freed_capital */
  people: CampaignsReportPerson[]
  campaigns: CampaignsReportCampaign[]
  /** 10 towarów z największymi uwolnionymi pieniędzmi w miesiącu */
  top_items: {
    campaign_id: number
    campaign_code: string
    user_name: string
    erp_item_id: number
    code: string
    name: string
    idle_days: number | null
    never_sold: boolean
    lot_age_days: number | null
    quantity: number
    unit: string | null
    sales_net: number
    freed_capital: number
  }[]
  /** zastrzeżenia do danych (liczby pozycji w miesiącu) */
  warnings: {
    cost_estimated_lines: number
    multi_card_lines: number
    unlinked_corrections: number
    over_stock_lines: number
    no_snapshot_items: number
    /** pozycje, przy których nie wiadomo, ile uwolniły (brak danych z dnia wysyłki albo zalegający bez kosztu/stanu) */
    freed_unknown_lines: number
  }
}

export function campaignsReportPath(month: string | null, userId: number | null): string {
  const q = new URLSearchParams()
  if (month) q.set('month', month)
  if (userId !== null) q.set('user_id', String(userId))
  const s = q.toString()
  return `/reports/campaigns${s ? `?${s}` : ''}`
}

export type SalesReportData = {
  generated_at: string
  days: number
  from: string
  inquiries: {
    scope: 'all' | 'own'
    totals: {
      received: number
      replied: number
      replied_1bd: number
      waiting: number
      waiting_over_1bd: number
      in_thunderbird: number
      analysis_failed: number
      duplicates: number
    }
    weekly: { week_start: string; received: number; replied: number }[]
    channels: { channel: string; received: number }[]
    /** „Od zapytania do sprzedaży” (etap 4) */
    funnel: SalesFunnel
    people:
      | {
          user_id: number
          name: string
          received: number
          replied: number
          replied_1bd: number
          waiting: number
          /** zwykle odpowiada w (mediana, sekundy); null — brak odpowiedzi w okresie */
          median_reply_seconds: number | null
          /** zamówione z odpowiedzianych (potwierdzone przez handlowca) */
          ordered: number
          ordered_percent: number | null
          /** wartość zamówień z dokumentów potwierdzonych przez handlowca */
          order_value: string
        }[]
      | null
  } | null
  tenders: {
    scope: 'all' | 'own'
    by_status: { status: string; count: number; offer_value_net: number; avg_margin: number | null }[]
    by_owner: { owner_id: number | null; owner_name: string; count: number; offer_value_net: number; avg_margin: number | null }[]
    upcoming: {
      id: number
      number: string
      title: string
      client: string | null
      deadline: string
      days_left: number
      status: string
      owner_name: string | null
      /** null — przetarg jeszcze bez wyceny. */
      offer_value_net: number | null
    }[]
  } | null
  campaigns: {
    scope: 'all' | 'own'
    rows: {
      id: number
      code: string
      name: string
      status: string
      started_at: string | null
      sent: number
      clicked: number
      replies: number
      unsubscribed: number
      buyers: number | null
      sales_net: number | null
      /** false — 30 dni liczenia sprzedaży od wysyłki jeszcze trwa (wynik „na razie”). */
      sales_complete: boolean | null
    }[]
    totals: { campaigns: number; sent: number; clicked: number; replies: number; unsubscribed: number }
  } | null
}

export type CustomersReportData = {
  generated_at: string
  synced_at: string | null
  totals: {
    customers: number
    archived: number
    buying_24m: number
    active_6m: number
    dormant_6_12: number
    lapsing_12_24: number
    reachable_active_12m: number
  }
  recency: { label: string; from_months: number; to_months: number; customers: number }[]
  operators: {
    operator: string
    name: string | null
    active_6m: number
    dormant_6_12: number
    lapsing_12_24: number
    reachable_active_12m: number
  }[]
  cities: { city: string; customers: number }[]
  top_customers: {
    id: number
    acronym: string | null
    name: string
    city: string | null
    documents_24m: number
    items_24m: number
    last_sale_at: string | null
  }[]
  top_items: { erp_item_id: number; code: string; name: string; customers: number; documents: number; product_id: number | null }[]
}

/** Okres raportu „Skuteczność przetargów” — po dacie terminu składania ofert. */
export type EffectivenessPeriod = '90d' | 'year'

/** Przetarg w listach raportu skuteczności (bez wyniku, unieważnione części). */
export type EffectivenessTenderRef = {
  tender_id: number
  number: string
  notice_number: string | null
  title: string
  owner_id: number | null
  owner_name: string
  /** Dzień terminu „RRRR-MM-DD” (czas polski). */
  deadline: string
  deadline_time: string | null
  /** Ścieżka aplikacji do zakładki „Wynik przetargu”. */
  url: string
}

/**
 * GET /reports/effectiveness?period= (TenderEffectivenessReport). Liczy części zamówień: rozstrzygnięte = wygrane +
 * przegrane; procenty jako liczby 0–100 z jednym miejscem po przecinku (null bez rozstrzygniętych).
 * Różnice cen: nasza cena brutto do ceny zwycięzcy, dodatnia = byliśmy drożsi (zwycięzca tańszy).
 */
export type EffectivenessReportData = {
  generated_at: string
  period: EffectivenessPeriod
  from: string
  to: string
  scope: 'all' | 'own'
  summary: {
    decided_lots: number
    won_lots: number
    lost_lots: number
    win_rate: number | null
    cancelled_lots: number
    not_submitted_lots: number
    no_result_tenders: number
    top_loss_reason: { reason: LossReason; count: number } | null
    avg_price_gap_percent: number | null
    /** Przegrane części, w których znamy obie ceny (podstawa średniej różnicy). */
    price_gap_lots: number
    weakest_category: { key: string; label: string; win_rate: number | null; decided: number } | null
  }
  by_owner: { owner_id: number | null; owner_name: string; decided: number; won: number; win_rate: number | null }[]
  /** reason null — przegrana bez wpisanego powodu. */
  loss_reasons: { reason: LossReason | null; label: string; count: number }[]
  competitors: { competitor_id: number; name: string; nip: string | null; won_against_us: number; avg_cheaper_percent: number | null }[]
  categories: { key: string; label: string; decided: number; won: number; win_rate: number | null }[]
  categories_note: string | null
  cancelled: (EffectivenessTenderRef & { lot_no: number; lot_name: string | null })[]
  /** Najwyżej 50 przetargów; pełna liczba w summary.no_result_tenders. */
  no_result: (EffectivenessTenderRef & { status: string })[]
  no_result_by_owner: { owner_id: number | null; owner_name: string; count: number }[]
}

/**
 * Dane raportu z serwera. Zmiana adresu (np. okres) zostawia poprzednie dane na ekranie do czasu nowej odpowiedzi;
 * odpowiedź starszego żądania nie nadpisuje nowszej.
 */
export function useReportData<T>(path: string) {
  const [loaded, setLoaded] = useState<{ path: string; data: T } | null>(null)
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(true)
  const seq = useRef(0)

  const load = useCallback(async () => {
    const my = ++seq.current
    setLoading(true)
    setError('')
    try {
      const res = await api<T>(path)
      if (my === seq.current) setLoaded({ path, data: res })
    } catch (ex) {
      if (my === seq.current) setError(errorText(ex, 'Nie udało się wczytać raportu.'))
    } finally {
      if (my === seq.current) setLoading(false)
    }
  }, [path])

  useEffect(() => {
    void load()
  }, [load])

  // dane z innego adresu (np. poprzedni okres po nieudanej zmianie) są nieaktualne — rama je przyciemnia
  return { data: loaded?.data ?? null, stale: loaded !== null && loaded.path !== path, error, loading, reload: load }
}

/** Ton wskaźnika i paska: neutralny, dobrze, uwaga, źle (kolory w components/ReportKit). */
export type Tone = 'neutral' | 'good' | 'warn' | 'bad'

/** Ton udziału pokrycia: ≥ 90% dobrze, ≥ 50% uwaga, niżej źle. */
export function coverageTone(part: number, whole: number): Tone {
  const p = shareValue(part, whole)
  return p >= 90 ? 'good' : p >= 50 ? 'warn' : 'bad'
}

/**
 * Seria tygodniowa bez pustych tygodni sprzed pierwszych danych (np. moduł działa od września, a okres to 90 dni) —
 * zostaje co najmniej `keep` ostatnich tygodni.
 */
export function trimLeadingEmpty<T>(rows: readonly T[], isEmpty: (row: T) => boolean, keep = 4): T[] {
  const first = rows.findIndex((r) => !isEmpty(r))
  if (first < 0) return rows.slice(-keep)
  return rows.slice(Math.min(first, Math.max(0, rows.length - keep)))
}

/* ---------- Formatowanie ---------- */

/** Twarda spacja między grupami cyfr — liczba nie łamie się na końcu wiersza. */
export const NBSP = ' '

/** Liczba całkowita z odstępem co 3 cyfry także dla 4 cyfr („7 589”) — Intl pl-PL grupuje dopiero od 5. */
export function groupInt(n: number): string {
  const rounded = Math.round(Math.abs(n))
  const digits = String(rounded).replace(/\B(?=(\d{3})+(?!\d))/g, NBSP)
  return n < 0 && rounded > 0 ? `−${digits}` : digits
}

/** Liczba z jednym miejscem po przecinku, gdy ma część ułamkową („12,5”), inaczej całkowita. */
export function fmtDec(n: number, digits = 1): string {
  const fixed = Math.abs(n).toFixed(digits).replace(/\.?0+$/, '')
  const [int, frac] = fixed.split('.')
  const sign = n < 0 && Number(fixed) !== 0 ? '−' : ''
  return `${sign}${groupInt(Number(int))}${frac ? `,${frac}` : ''}`
}

/** Udział jako procent całkowity („96%”), „<1%” dla małych niezerowych; null, gdy całości nie ma. */
export function sharePct(part: number, whole: number): string | null {
  if (!(whole > 0)) return null
  const p = (part / whole) * 100
  if (p <= 0) return '0%'
  if (p < 1) return '<1%'
  if (p > 99 && p < 100) return '>99%'
  return `${Math.round(p)}%`
}

/** Udział 0–100 do szerokości paska (bez zaokrągleń w dół do zera dla małych wartości). */
export function shareValue(part: number, whole: number): number {
  return whole > 0 ? Math.min(100, Math.max(0, (part / whole) * 100)) : 0
}

/** Zmiana procentowa ze znakiem („+4,2%”, „−12%”). */
export function signedPct(p: number | null | undefined): string {
  if (p == null) return '—'
  if (p === 0) return '0%'
  return `${p > 0 ? '+' : '−'}${fmtDec(Math.abs(p))}%`
}

/** Kwota w złotych: od 1 mln „12,3 mln zł”, od 10 tys. „712 tys. zł”, poniżej „7 589 zł”. */
export function fmtBigZl(value: number): string {
  const abs = Math.abs(value)
  const sign = value < 0 ? '−' : ''
  if (abs >= 10_000) {
    const thousands = Math.round(abs / 1000)
    if (thousands < 1000) return `${sign}${groupInt(thousands)}${NBSP}tys.${NBSP}zł`
    const millions = (Math.round(abs / 100_000) / 10).toFixed(1).replace(/\.0$/, '').replace('.', ',')
    return `${sign}${millions}${NBSP}mln${NBSP}zł`
  }
  return `${sign}${groupInt(abs)}${NBSP}zł`
}

/** Cena z groszami w walucie wiersza („12,50 zł”, „8,40 EUR”); waluta nieznana — sama liczba. */
export function fmtPrice(value: number, currency: string | null): string {
  const [int, frac] = Math.abs(value).toFixed(2).split('.')
  const unit = currency == null ? '' : currency.toUpperCase() === 'PLN' ? `${NBSP}zł` : `${NBSP}${currency.toUpperCase()}`
  return `${value < 0 ? '−' : ''}${groupInt(Number(int))},${frac}${unit}`
}

/** Sama data jako dzień kalendarzowy (bez przesunięcia strefy); ISO z godziną — w czasie przeglądarki. */
function toDate(iso: string): Date {
  return /^\d{4}-\d{2}-\d{2}$/.test(iso) ? new Date(`${iso}T12:00:00`) : new Date(iso)
}

/** „2 października 2026”. */
export function longDate(iso: string | null | undefined): string {
  if (!iso) return '—'
  const d = toDate(iso)
  return Number.isNaN(d.getTime()) ? iso : d.toLocaleDateString('pl-PL', { day: 'numeric', month: 'long', year: 'numeric' })
}

/** „2 paź 2026, 21:14”. */
export function stampDate(iso: string | null | undefined): string {
  if (!iso) return '—'
  const d = toDate(iso)
  if (Number.isNaN(d.getTime())) return iso
  return `${d.toLocaleDateString('pl-PL', { day: 'numeric', month: 'short', year: 'numeric' })}, ${d.toLocaleTimeString('pl-PL', {
    hour: '2-digit',
    minute: '2-digit',
  })}`
}

/** „02.10” — podpis osi i krótkie daty w tabelach. */
export function dayMonth(iso: string): string {
  const d = toDate(iso)
  if (Number.isNaN(d.getTime())) return iso
  return `${String(d.getDate()).padStart(2, '0')}.${String(d.getMonth() + 1).padStart(2, '0')}`
}

/** „02.10.2026”. */
export function shortDate(iso: string | null | undefined): string {
  if (!iso) return '—'
  const d = toDate(iso)
  if (Number.isNaN(d.getTime())) return iso
  return d.toLocaleDateString('pl-PL', { day: '2-digit', month: '2-digit', year: 'numeric' })
}

/** Tydzień od poniedziałku: „29.09–05.10”. */
export function weekRange(weekStart: string): string {
  const d = toDate(weekStart)
  const end = new Date(d.getTime() + 6 * 86_400_000)
  return `${dayMonth(weekStart)}–${dayMonth(end.toISOString().slice(0, 10))}`
}

/** Wiek w godzinach jako „5 godzin”, „3 dni”, „2 tygodnie”. */
export function ageFromHours(hours: number | null | undefined): string {
  if (hours == null) return '—'
  if (hours < 1) return 'przed chwilą'
  if (hours < 48) {
    const h = Math.round(hours)
    return `${h}${NBSP}${plural(h, 'godzina', 'godziny', 'godzin')}`
  }
  const days = Math.round(hours / 24)
  if (days < 21) return `${days}${NBSP}${plural(days, 'dzień', 'dni', 'dni')}`
  const weeks = Math.round(days / 7)
  return `${weeks}${NBSP}${plural(weeks, 'tydzień', 'tygodnie', 'tygodni')}`
}

/** „12 kart”, „1 karta”, „3 karty”. */
export function cards(n: number): string {
  return `${groupInt(n)}${NBSP}${plural(n, 'karta', 'karty', 'kart')}`
}
