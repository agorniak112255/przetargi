import { useEffect, useState } from 'react'
import { useAuth } from '../../auth'
import { api, can, canAny } from '../../lib/api'
import { DashboardView, type Dash } from '../Dashboard'
import { Tenders } from '../Tenders'
import { AppFrame, LiveFrame, LivePage, LiveScreen, Mark, Slideshow, Th } from './kit'

/**
 * Samouczek „Dashboard” — prawdziwy widok strony (DashboardView z pages/Dashboard.tsx) z AKTUALNYMI danymi
 * z GET /dashboard; sekcje bez dostępu (null) i brak połączenia — przykładowe dane. Wygląda jak w aplikacji,
 * także w szablonie „Nocna zmiana”. Karty pojedynczo = dane z jedną sekcją.
 */

const day = (offset: number) => new Date(Date.now() + offset * 864e5).toISOString().slice(0, 10)

/** Przykładowa odpowiedź GET /dashboard (zamawiający i liczby zmyślone; daty liczone względem dziś). */
function sample(): Dash {
  return {
    tenders: {
      active: 23,
      value_net: 1846300,
      avg_margin_percent: 18.4,
      deadline_soon: 3,
      stages: [
        { status: 'draft', count: 6, value_net: 0 },
        { status: 'wycena', count: 11, value_net: 1124800 },
        { status: 'zatwierdzona', count: 3, value_net: 431900 },
        { status: 'exported', count: 3, value_net: 289600 },
      ],
      upcoming: [
        { id: 12, number: 'PRZ/2026/0012', title: 'Odzież robocza', client: 'Mittal', status: 'wycena', deadline: day(3) },
        { id: 11, number: 'PRZ/2026/0011', title: 'Obuwie', client: 'Sanitex', status: 'zatwierdzona', deadline: day(4) },
        { id: 10, number: 'PRZ/2026/0010', title: 'Rękawice', client: 'Mittal', status: 'wycena', deadline: day(6) },
        { id: 9, number: 'PRZ/2026/0009', title: 'Kurtki', client: 'Sanitex', status: 'draft', deadline: day(12) },
      ],
    },
    products: {
      total: 41862,
      with_description: 37118,
      missing_description: 4744,
      missing_images: 2310,
      manual_review: 128,
      vector_indexed: 40905,
      substitutes_pending: 14,
    },
    stock: {
      as_of: day(0),
      buckets: {
        stock: { items: 6412, value: 3284500 },
        no_sale_6: { items: 1140, value: 912300 },
        no_sale_12: { items: 640, value: 498700 },
        no_sale_24: { items: 310, value: 236800 },
        never_sold: { items: 212, value: 118400 },
      },
      top_unsold: [
        { code: 'APORS461XXL', name: 'Kurtka zimowa ostrzegawcza Portwest S461', value: 38400, last_sale_at: day(-420) },
        { code: 'BVMOSLO', name: 'Półbuty S3 VM Footwear Oslo', value: 27900, last_sale_at: null },
        { code: 'SANS92600', name: 'Rękawice nitrylowe Ansell 92-600 (karton)', value: 21200, last_sale_at: day(-75) },
      ],
    },
    prices: {
      accounts: ['UVEX', '3M', 'Delta Plus', 'Mascot', 'JHK', 'MAVIBO', 'Ejendals', 'VM Footwear', 'Fagum', 'Portwest', 'Hultafors', 'Ardon'].map(
        (label, i) => ({
          id: i + 1,
          label,
          finished_at: null,
          progress: label === 'Hultafors' ? 62 : null,
          state: label === 'Mascot' ? 'failed' : label === 'Hultafors' ? 'running' : label === 'Ardon' ? 'off' : 'ok',
          message: label === 'Mascot' ? 'logowanie odrzucone' : null,
        }),
      ),
      prices_changed_7d: 3412,
      file_imports: [
        { id: 1, manufacturer: 'Ansell', rows_total: 1286, prices_changed: 214, created_at: day(0) },
        { id: 2, manufacturer: 'Canis', rows_total: 9914, prices_changed: 803, created_at: day(-5) },
      ],
    },
    campaigns: {
      drafts: 2,
      next: { id: 9, name: 'Rękawice zimowe z magazynu', scheduled_at: day(1) + 'T06:00:00Z' },
      sent_30d: 896,
      replies_30d: 15,
      recent: [
        { id: 7, name: 'Obuwie S3 z magazynu', status: 'sent', sent_at: day(-14), sent: 412, clicked: 37, replies: 9, drop_percent: 18 },
        { id: 6, name: 'Półmaski 3M serii 6000', status: 'sent', sent_at: day(-7), sent: 198, clicked: 15, replies: 2, drop_percent: 11 },
      ],
    },
  }
}

/** Dashboard z wybranymi sekcjami (reszta null — jak u osoby bez uprawnień do tych modułów). */
function Screen({ live, only, mark }: { live: Dash | null; only?: Array<keyof Dash>; mark?: string }) {
  const example = sample()
  const keys = (only ?? (Object.keys(example) as Array<keyof Dash>))
  // sekcja z serwera, gdy jest; inaczej przykład (brak dostępu albo dane jeszcze się wczytują)
  const fromLive = keys.every((k) => live?.[k] != null)
  const pick = (k: keyof Dash) => (keys.includes(k) ? (live?.[k] ?? example[k]) : null)
  const data = Object.fromEntries((Object.keys(example) as Array<keyof Dash>).map((k) => [k, pick(k)])) as Dash
  return (
    <LiveFrame live={fromLive} label="Dashboard">
      <LiveScreen width={1100} mark={mark}>
        <DashboardView data={data} stockLink="/zapasy" />
      </LiveScreen>
    </LiveFrame>
  )
}

export function DashboardHelp() {
  const { user } = useAuth()
  const [live, setLive] = useState<Dash | null>(null)
  const allowed = can(user, 'dashboard.view')
  useEffect(() => {
    if (!allowed) return
    let off = false
    api<Dash>('/dashboard').then(
      (d) => {
        if (!off) setLive(d)
      },
      () => {},
    )
    return () => {
      off = true
    }
  }, [allowed])
  return (
    <Slideshow
      title="Dashboard"
      slides={[
        {
          action: 'Co widać po zalogowaniu',
          does: 'Dashboard to pierwszy ekran: po jednej karcie na moduł — Przetargi, Produkty, Zapasy, Cenniki i Kampanie. Każda osoba widzi tylko karty modułów, do których ma uprawnienie; gdy jednej karty z pary brakuje, druga zajmuje całą szerokość. Nad nimi „Do zrobienia dziś”, a gdy masz cel sprzedaży w bieżącym miesiącu — kafelek „Mój cel” z realizacją (sprzedaż Twoich klientów z faktur i paragonów w ERP XL, z nocnego odczytu).',
          click: 'Nic — przeczytaj karty. Odnośnik w prawym górnym rogu karty prowadzi do modułu.',
          tone: 'slate',
          screen: <Screen live={live} />,
        },
        {
          action: 'Karta Przetargi i ostrzeżenie o terminach',
          does: 'Na górze: ile przetargów jest w toku, wartość ofert netto i średnia marża. Pod spodem pasek etapów (ile przetargów i za ile na każdym etapie) oraz najbliższe terminy — czerwone do 3 dni, pomarańczowe do 7 dni. Dolna linia ostrzega, ile terminów wypada w ciągu 7 dni, i podaje najbliższy.',
          click: '„Pokaż wszystkie” w czerwonej linii ostrzeżenia. Wiersz z terminem otwiera od razu ten przetarg.',
          tone: 'blue',
          screen: <Screen live={live} only={['tenders']} mark={'a[href*="deadline_soon"]'} />,
        },
        {
          action: 'Lista przetargów z pilnym terminem',
          does: '„Pokaż wszystkie” otwiera listę przetargów już przefiltrowaną: „Termin za mniej niż 7 dni”. Czerwony wykrzyknik przy dacie oznacza termin składania za mniej niż 7 dni. Gdy żaden przetarg nie ma takiego terminu, karta na dashboardzie pisze to w szarej linii zamiast ostrzeżenia.',
          click: 'Niebieski numer przetargu, żeby go otworzyć. Ten sam filtr wybierzesz ręcznie z listy rozwijanej na stronie Przetargi.',
          tone: 'green',
          screen: (
            <LivePage
              nav="Przetargi"
              path="/tenders?filter=deadline_soon"
              page={<Tenders />}
              allowed={canAny(user, ['tenders.view_own', 'tenders.view_all'])}
              fallback={
              <AppFrame nav="Przetargi">
                <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                  <h1 className="text-xl font-semibold">Przetargi</h1>
                  <Mark>
                    <span className="inline-block rounded border border-slate-300 bg-white px-2 py-1.5 text-xs">Termin za mniej niż 7 dni ▾</span>
                  </Mark>
                </div>
                <div className="rounded-xl bg-white p-3 shadow-sm">
                  <table className="w-full text-left text-xs">
                    <thead>
                      <tr className="border-b bg-slate-50">
                        <Th>Numer</Th>
                        <Th>Zamawiający</Th>
                        <Th>Termin składania</Th>
                        <Th>Wartość oferty netto</Th>
                        <Th>Status</Th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr className="border-b">
                        <td className="p-2 font-medium text-blue-600">PRZ/2026/0012</td>
                        <td className="p-2">Mittal</td>
                        <td className="p-2">
                          2026-10-05<span className="ml-1 font-semibold text-red-600">!</span>
                        </td>
                        <td className="p-2">48 210,40 zł</td>
                        <td className="p-2">Wycena</td>
                      </tr>
                      <tr>
                        <td className="p-2 font-medium text-blue-600">PRZ/2026/0011</td>
                        <td className="p-2">Sanitex</td>
                        <td className="p-2">
                          2026-10-08<span className="ml-1 font-semibold text-red-600">!</span>
                        </td>
                        <td className="p-2">—</td>
                        <td className="p-2">Szkic</td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </AppFrame>
              }
            />
          ),
        },
        {
          action: 'Karta Produkty',
          does: 'Ile kart jest w katalogu, ile ma opis, a ilu brakuje opisu albo zdjęcia i ile czeka na ręczny opis. Karta bez opisu nie trafia do propozycji przetargowych — dlatego dolna linia ostrzega o kartach bez opisu. Na dole liczba zamienników czekających na akceptację.',
          click: '„Katalog →” otwiera listę produktów, „Zamienniki do akceptacji” — stronę Zamienniki.',
          tone: 'slate',
          screen: <Screen live={live} only={['products']} mark={'a[href$="/products"]'} />,
        },
        {
          action: 'Karty Zapasy i Cenniki',
          does: 'Zapasy: wartość magazynu, liczba towarów i ile nigdy się nie sprzedało; wykres pokazuje wartość towaru bez sprzedaży od pół roku, roku, 2 lat i nigdy, obok towary z największą zamrożoną gotówką. Stan pochodzi z nocnego odczytu systemu ERP XL. Cenniki: ile kont B2B (sklepów dostawców) przeszło ostatnie sprawdzenie bez błędów, ile cen zmieniło się w 7 dni i ostatnie cenniki z pliku; konto z błędem świeci na czerwono.',
          click: '„Zapasy →” otwiera Zapasy (bez dostępu do Zapasów — Raport dla zarządu). „Konta B2B →” otwiera konta dostawców (bez dostępu do kont — „Cenniki →”).',
          tone: 'slate',
          screen: <Screen live={live} only={['stock', 'prices']} />,
        },
        {
          action: 'Karta Kampanie',
          does: 'Najbliższa zaplanowana wysyłka, liczba szkiców do wysłania oraz wysłane maile i odpowiedzi klientów z 30 dni. Tabela „Ostatnie kampanie” pokazuje dla każdej kampanii: kiedy wysłana, ile maili poszło, ile było kliknięć i odpowiedzi oraz o ile zszedł zapas promowanego towaru.',
          click: 'Nazwa kampanii (w ramce wysyłki albo w tabeli) otwiera tę kampanię; „Kampanie →” — cały moduł.',
          tone: 'slate',
          screen: <Screen live={live} only={['campaigns']} mark={'a[href$="/kampanie"]'} />,
        },
      ]}
    />
  )
}
