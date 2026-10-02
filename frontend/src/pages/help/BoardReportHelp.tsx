import { AppFrame, LivePage, Mark, Slideshow, Th } from './kit'
import { useAuth } from '../../auth'
import { InventoryBoardReport } from '../InventoryBoardReport'
import { can } from '../../lib/api'

/** Samouczek modułu „Raport dla zarządu” (strona InventoryBoardReport, adres /raport-zapasow). */

const NAV = 'Raport dla zarządu'

/** Nagłówek raportu: tytuł, data odczytu, wybór magazynów i oddziału. */
function ReportHeader({ markFilters = false }: { markFilters?: boolean }) {
  const filters = (
    <div className="flex flex-wrap items-center gap-2 text-[11px] text-slate-700">
      <span>Magazyny:</span>
      <span className="inline-flex overflow-hidden rounded-lg border border-slate-300">
        <span className="bg-blue-600 px-2 py-1 font-semibold text-white">✓ Handlowe</span>
        <span className="border-l border-slate-300 bg-white px-2 py-1">Usługowe</span>
        <span className="border-l border-slate-300 bg-white px-2 py-1">Wszystkie</span>
      </span>
      <span>Oddział:</span>
      <span className="rounded-lg border border-slate-300 bg-white px-2 py-1">Rzeszów ▾</span>
    </div>
  )
  return (
    <div className="mb-3 flex flex-wrap items-end justify-between gap-2">
      <div>
        <h1 className="text-base font-semibold text-slate-900">Zapasy — raport dla zarządu</h1>
        <p className="text-[11px] text-slate-700">Stan na 2 października 2026 · dane z programu magazynowego z nocy</p>
      </div>
      <div className="flex flex-wrap items-center gap-2">
        {markFilters ? <Mark>{filters}</Mark> : filters}
        <span className="rounded border border-slate-300 bg-white px-2 py-1 text-[11px] text-slate-800">Drukuj / PDF</span>
      </div>
    </div>
  )
}

/** Kafelek górnego rzędu: kwota, podpis, szczegół i „Pokaż listę ›”. */
function Tile({
  value,
  label,
  detail,
  tone,
}: {
  value: string
  label: string
  detail: string
  tone: 'neutral' | 'amber' | 'red'
}) {
  const box = {
    neutral: 'border-slate-300 bg-white',
    amber: 'border-amber-300 bg-amber-50',
    red: 'border-red-300 bg-red-50',
  }[tone]
  const number = { neutral: 'text-slate-900', amber: 'text-amber-900', red: 'text-red-800' }[tone]
  return (
    <div className={`flex flex-col rounded-xl border-2 px-3 py-2 ${box}`}>
      <span className={`text-lg font-semibold ${number}`}>{value}</span>
      <span className="text-xs font-medium text-slate-900">{label}</span>
      <span className="mt-1 text-[11px] text-slate-700">{detail}</span>
      <span className="text-[11px] font-semibold text-blue-700 underline">Pokaż listę ›</span>
    </div>
  )
}

/** Pasek „jak długo”: podpis, liczba towarów i udział, pasek w skali pierwszego, kwota. */
function Bar({
  label,
  detail,
  value,
  width,
  tone,
}: {
  label: string
  detail: string
  value: string
  width: number
  tone: 'amber' | 'red' | 'slate'
}) {
  const fill = { amber: 'bg-amber-400', red: 'bg-red-400', slate: 'bg-slate-400' }[tone]
  const number = { amber: 'text-amber-900', red: 'text-red-800', slate: 'text-slate-900' }[tone]
  return (
    <div className="grid grid-cols-[8.5rem_minmax(2rem,1fr)_auto] items-center gap-x-2 rounded px-1 py-0.5">
      <span>
        <span className="block text-xs font-medium text-slate-900">{label}</span>
        <span className="block text-[11px] text-slate-700">{detail}</span>
      </span>
      <span className="block h-3 overflow-hidden rounded-full bg-slate-100">
        <span className={`block h-full rounded-full ${fill}`} style={{ width: `${width}%` }} />
      </span>
      <span className="whitespace-nowrap">
        <span className={`text-sm font-semibold ${number}`}>{value}</span>
        <span className="ml-1 text-sm font-semibold text-blue-700">›</span>
      </span>
    </div>
  )
}

/** Mały wykres historii: linia na prostej siatce. */
function MiniChart({ points, stroke }: { points: string; stroke: string }) {
  return (
    <svg viewBox="0 0 200 70" className="block h-16 w-full" aria-hidden="true">
      <line x1="0" y1="20" x2="200" y2="20" className="stroke-slate-200" />
      <line x1="0" y1="45" x2="200" y2="45" className="stroke-slate-200" />
      <line x1="0" y1="68" x2="200" y2="68" className="stroke-slate-400" />
      <polyline points={points} className={`fill-none ${stroke}`} strokeWidth="2" strokeLinejoin="round" />
    </svg>
  )
}

/** Przyciski okresu wykresów — napisy jak w HISTORY_RANGES. */
function RangeButtons({ active }: { active: string }) {
  return (
    <span className="inline-flex overflow-hidden rounded-lg border border-slate-300 text-[11px]">
      {['1 mies.', '3 mies.', '6 mies.', '12 mies.', '18 mies.', '2 lata'].map((r, i) => (
        <span
          key={r}
          className={`px-1.5 py-1 ${i > 0 ? 'border-l border-slate-300' : ''} ${
            r === active ? 'bg-blue-600 font-semibold text-white' : 'bg-white text-slate-800'
          }`}
        >
          {r}
        </span>
      ))}
    </span>
  )
}

export function BoardReportHelp() {
  const { user } = useAuth()
  return (
    <Slideshow
      title="Raport dla zarządu"
      slides={[
        {
          action: 'Wejście do raportu',
          does: 'Jedna strona z kwotami zapasów: ile towaru leży w magazynach, ile się nie sprzedaje i od kiedy. Wszystkie liczby pochodzą z programu magazynowego (odczyt z nocy) — raport pokazuje same fakty i niczego nie zaleca. Menu „Raport dla zarządu” widzą tylko osoby z nadanym uprawnieniem do tego raportu (zarząd); kto ma tylko ten raport, po zalogowaniu trafia prosto na niego.',
          click: 'Menu „Raport dla zarządu”.',
          tone: 'slate',
          screen: (
            <LivePage
              nav="Raport dla zarządu"
              path="/raport-zapasow"
              page={<InventoryBoardReport />}
              allowed={can(user, 'inventory.report.view')}
              fallback={
              <AppFrame nav={NAV}>
                <ReportHeader />
                <p className="mb-2 text-[11px] text-slate-700">
                  Kafelek z napisem „Pokaż listę ›” można kliknąć — otworzy się lista towarów albo dokumentów.
                </p>
                <div className="grid grid-cols-2 gap-2">
                  <Tile tone="neutral" value="3,9 mln zł" label="cały towar (Rzeszów, handlowe)" detail="5 812 towarów" />
                  <Tile tone="red" value="431 tys. zł" label="ponad rok bez sprzedaży" detail="684 towary · 11% magazynu" />
                </div>
              </AppFrame>
              }
            />
          ),
        },
        {
          action: 'Wybór magazynów i oddziału',
          does: '„Handlowe” to towar na sprzedaż, „Usługowe” — towar trzymany dla klientów, „Wszystkie” — oba razem. W polu „Oddział” wybierasz jeden oddział (magazyny łączone po cyfrach na początku kodu, na przykład 01H, 01MTU = Rzeszów) albo „Wszystkie”. Wybór zapisuje się w adresie strony, więc ten sam link otwiera ten sam widok. „Drukuj / PDF” drukuje raport na jednej kartce A4.',
          click: 'Przycisk „Handlowe”, „Usługowe” albo „Wszystkie”, potem lista „Oddział:”.',
          tone: 'blue',
          screen: (
            <AppFrame nav={NAV}>
              <ReportHeader markFilters />
              <div className="rounded-lg border border-slate-200 bg-white p-2 text-[11px] text-slate-800">
                <div className="font-semibold text-slate-900">Oddział:</div>
                {['Wszystkie', 'Rzeszów', 'Kraków', 'Lublin'].map((o) => (
                  <div key={o} className={`rounded px-2 py-0.5 ${o === 'Rzeszów' ? 'bg-blue-600 text-white' : ''}`}>
                    {o}
                  </div>
                ))}
              </div>
            </AppFrame>
          ),
        },
        {
          action: 'Cztery główne kwoty',
          does: 'Na górze cztery kafelki: cały towar w wybranych magazynach, towar ponad pół roku bez sprzedaży, ponad rok bez sprzedaży oraz towar wydany i od razu przyjęty z powrotem bez opisu. Przy kwocie: liczba towarów i jaka to część wartości magazynu. Wartość to cena zakupu towaru według programu magazynowego.',
          click: 'Nic — przeczytaj kafelki od lewej.',
          tone: 'slate',
          screen: (
            <AppFrame nav={NAV}>
              <ReportHeader />
              <div className="grid grid-cols-2 gap-2 lg:grid-cols-4">
                <Tile tone="neutral" value="3,9 mln zł" label="cały towar (Rzeszów, handlowe)" detail="5 812 towarów" />
                <Tile tone="amber" value="712 tys. zł" label="ponad pół roku bez sprzedaży" detail="1 240 towarów · 18% magazynu" />
                <Tile tone="red" value="431 tys. zł" label="ponad rok bez sprzedaży" detail="684 towary · 11% magazynu" />
                <Tile tone="red" value="86 tys. zł" label="wydane i przyjęte bez opisu" detail="12 razy w ostatnim roku" />
              </div>
            </AppFrame>
          ),
        },
        {
          action: 'Kliknięcie kwoty — lista towarów',
          does: 'Każda kwota z napisem „Pokaż listę ›” otwiera okno z listą towarów, z których się składa — liczbę da się sprawdzić towar po towarze. W oknie: pole „Szukaj w liście…” (nazwa, kod, dostawca, rok albo miesiąc), sortowanie kliknięciem w nagłówek kolumny, „Wierszy na stronie” i strony na dole, „Drukuj” drukuje samą listę. Okno zamyka „Zamknij”, klawisz Esc albo kliknięcie w tło.',
          click: 'Kafelek „ponad rok bez sprzedaży”, w oknie nagłówek „Wartość”.',
          tone: 'blue',
          screen: (
            <AppFrame nav={NAV}>
              <div className="rounded-xl border border-slate-300 bg-white shadow-sm">
                <div className="border-b border-slate-200 px-3 py-2">
                  <div className="flex flex-wrap items-baseline gap-x-3">
                    <b className="text-xs text-slate-900">Nie sprzedaje się ponad rok (Rzeszów, magazyny handlowe)</b>
                    <span className="text-[11px] text-slate-800">684 towary · razem 431 tys. zł · to 11% wartości magazynu</span>
                  </div>
                  <div className="mt-1.5 flex flex-wrap items-center gap-2">
                    <span className="w-40 rounded border border-slate-300 px-2 py-1 text-[11px] text-slate-500">Szukaj w liście…</span>
                    <span className="ml-auto rounded border border-slate-300 px-2 py-1 text-[11px]">Drukuj</span>
                    <span className="rounded border border-slate-300 px-2 py-1 text-[11px]">Zamknij</span>
                  </div>
                </div>
                <table className="w-full text-left text-xs">
                  <thead className="bg-slate-100">
                    <tr>
                      <Th>Towar</Th>
                      <Th>Ile leży</Th>
                      <Th>
                        <Mark>
                          <span className="px-1 text-blue-800">Wartość ▼</span>
                        </Mark>
                      </Th>
                      <Th>Ostatnia sprzedaż</Th>
                      <Th>Najstarsza dostawa</Th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr className="border-t border-slate-200">
                      <td className="p-2">
                        <b className="block">Rękawice powlekane nitrylem</b>
                        <span className="text-[11px] text-slate-700">ARĘK92600</span>
                      </td>
                      <td className="p-2">1 200 par</td>
                      <td className="p-2">
                        <b className="block">4 680 zł</b>
                        <span className="text-[11px] text-slate-700">3,90 zł/para</span>
                      </td>
                      <td className="p-2">
                        marzec 2025
                        <span className="block text-[11px] text-slate-700">(18 mies. temu)</span>
                      </td>
                      <td className="p-2">listopad 2023</td>
                    </tr>
                    <tr className="border-t border-slate-200 bg-slate-50">
                      <td className="p-2">
                        <b className="block">Półbuty bezpieczne S3, rozmiar 46</b>
                        <span className="text-[11px] text-slate-700">BUT46210</span>
                      </td>
                      <td className="p-2">24 pary</td>
                      <td className="p-2">
                        <b className="block">3 960 zł</b>
                        <span className="text-[11px] text-slate-700">165 zł/para</span>
                      </td>
                      <td className="p-2">ani razu</td>
                      <td className="p-2">czerwiec 2024</td>
                    </tr>
                  </tbody>
                </table>
                <div className="flex flex-wrap items-center justify-between gap-2 border-t border-slate-200 px-3 py-1.5 text-[11px] text-slate-800">
                  <span>Wierszy na stronie: 10 · 20 · 50 · 100</span>
                  <span>‹ Poprzednie · Strona 1 z 69 · Następne ›</span>
                </div>
              </div>
            </AppFrame>
          ),
        },
        {
          action: 'Jak długo towar nie sprzedaje się',
          does: 'Paski liczone od ostatniej sprzedaży: ponad pół roku, w tym ponad rok, w tym ponad 2 lata, w tym ani razu nie sprzedany. Niższe paski są częścią pierwszego („w tym”), więc kwot się nie dodaje; % to część wartości magazynu. Poniżej: towar bez sprzedaży ponad rok, którego najstarsza sztuka leży ponad 3 lata albo ponad 5 lat. Kliknięcie paska otwiera listę.',
          click: 'Pasek, na przykład „w tym ponad 2 lata”.',
          tone: 'blue',
          screen: (
            <AppFrame nav={NAV}>
              <div className="rounded-xl border-2 border-slate-300 bg-white px-3 py-2">
                <h2 className="text-sm font-semibold text-slate-900">Jak długo towar nie sprzedaje się</h2>
                <p className="mb-1 text-[11px] text-slate-700">
                  Niższe paski są częścią pierwszego („w tym”), więc kwot się nie dodaje. % = część wartości magazynu.
                </p>
                <Bar tone="amber" label="ponad pół roku" detail="1 240 towarów · 18%" value="712 tys. zł" width={100} />
                <Bar tone="red" label="w tym ponad rok" detail="684 towary · 11%" value="431 tys. zł" width={61} />
                <Mark>
                  <div className="w-full">
                    <Bar tone="red" label="w tym ponad 2 lata" detail="301 towarów · 5%" value="205 tys. zł" width={29} />
                  </div>
                </Mark>
                <Bar tone="red" label="w tym ani razu nie sprzedany" detail="97 towarów · 2%" value="64 tys. zł" width={9} />
                <h3 className="mt-2 text-xs font-semibold text-slate-900">Bez sprzedaży ponad rok, a najstarsza sztuka leży:</h3>
                <Bar tone="slate" label="ponad 3 lata" detail="188 towarów · 3%" value="118 tys. zł" width={27} />
                <Bar tone="slate" label="ponad 5 lat" detail="61 towarów · 1%" value="39 tys. zł" width={9} />
              </div>
            </AppFrame>
          ),
        },
        {
          action: 'Jak długo leży towar w magazynie',
          does: 'Tu liczy się wiek samych sztuk: tylko te, które naprawdę leżą co najmniej tyle (z dostaw sprzed pół roku, roku…) — świeże dostawy tego samego towaru się nie liczą. Paski narastają: ponad pół roku, w tym ponad rok, 2, 3, 4 i 5 lat; niższe są częścią pierwszego. % to część wartości całego towaru w magazynie. Kliknięcie paska otwiera listę z ilością i wartością tylko tych starych sztuk.',
          click: 'Pasek, na przykład „w tym ponad rok”.',
          tone: 'blue',
          screen: (
            <AppFrame nav={NAV}>
              <div className="rounded-xl border-2 border-slate-300 bg-white px-3 py-2">
                <h2 className="text-sm font-semibold text-slate-900">Jak długo leży towar w magazynie</h2>
                <p className="mb-1 text-[11px] text-slate-700">
                  Liczą się tylko sztuki, które naprawdę leżą co najmniej tyle (z dostaw sprzed pół roku, roku…) — świeże
                  dostawy nie. Niższe paski są częścią pierwszego („w tym”). % = część wartości całego towaru w magazynie
                  (3,9 mln zł).
                </p>
                <div className="grid gap-x-4 lg:grid-cols-2">
                  <div>
                    <Bar tone="amber" label="ponad pół roku" detail="1 512 towarów · 28%" value="1,1 mln zł" width={100} />
                    <Mark>
                      <div className="w-full">
                        <Bar tone="red" label="w tym ponad rok" detail="903 towary · 16%" value="640 tys. zł" width={58} />
                      </div>
                    </Mark>
                    <Bar tone="red" label="w tym ponad 2 lata" detail="410 towarów · 8%" value="298 tys. zł" width={27} />
                  </div>
                  <div>
                    <Bar tone="red" label="w tym ponad 3 lata" detail="255 towarów · 4%" value="176 tys. zł" width={16} />
                    <Bar tone="red" label="w tym ponad 4 lata" detail="148 towarów · 3%" value="102 tys. zł" width={9} />
                    <Bar tone="red" label="w tym ponad 5 lat" detail="92 towary · 2%" value="61 tys. zł" width={6} />
                  </div>
                </div>
              </div>
            </AppFrame>
          ),
        },
        {
          action: 'Wykresy „Jak zmieniają się zapasy”',
          does: 'Stan zapisywany co noc po odczycie z programu magazynowego, dla magazynów i oddziału wybranych u góry strony. Sześć wykresów: cały towar, dwa progi bez sprzedaży i trzy progi „leży w magazynie”. Okres wybierasz przyciskami albo polami „od” i „do”. Przy zalegającym towarze spadek jest zielony, wzrost czerwony; przy całym towarze bez oceny. Pod wykresami tabela „Oddziały” — kwota na koniec okresu i zmiana.',
          click: 'Przycisk okresu, na przykład „6 mies.”.',
          tone: 'blue',
          screen: (
            <AppFrame nav={NAV}>
              <div className="rounded-xl border-2 border-slate-300 bg-white px-3 py-2">
                <div className="flex flex-wrap items-end justify-between gap-2">
                  <div>
                    <h2 className="text-sm font-semibold text-slate-900">Jak zmieniają się zapasy</h2>
                    <p className="text-[11px] text-slate-700">Stan zapisywany co noc po odczycie z programu magazynowego.</p>
                  </div>
                  <div className="flex flex-wrap items-center gap-1 text-[11px] text-slate-700">
                    <Mark>
                      <RangeButtons active="6 mies." />
                    </Mark>
                    <span>od 02.04.2026</span>
                    <span>do 02.10.2026</span>
                  </div>
                </div>
                <div className="mt-2 grid gap-2 sm:grid-cols-3">
                  {[
                    { label: 'Cały towar', value: '3,94 mln zł', change: '+120 tys. zł', cls: 'text-slate-900', stroke: 'stroke-slate-700', points: '0,40 40,38 80,42 120,34 160,30 200,28' },
                    { label: 'Ponad pół roku bez sprzedaży', value: '712 tys. zł', change: '−38 tys. zł', cls: 'text-emerald-700', stroke: 'stroke-amber-700', points: '0,22 40,26 80,30 120,34 160,40 200,46' },
                    { label: 'Ponad rok bez sprzedaży', value: '431 tys. zł', change: '+12 tys. zł', cls: 'text-red-800', stroke: 'stroke-red-700', points: '0,46 40,44 80,45 120,40 160,38 200,36' },
                  ].map((c) => (
                    <div key={c.label} className="rounded-lg border border-slate-200 px-2 py-1.5">
                      <div className="flex items-baseline justify-between gap-1">
                        <span className="text-[11px] font-semibold text-slate-900">{c.label}</span>
                        <span className="text-[10px] font-semibold whitespace-nowrap text-blue-700">Powiększ ›</span>
                      </div>
                      <div className="flex items-baseline justify-between">
                        <b className="text-sm text-slate-900">{c.value}</b>
                        <span className={`text-[11px] font-semibold ${c.cls}`}>{c.change}</span>
                      </div>
                      <MiniChart points={c.points} stroke={c.stroke} />
                    </div>
                  ))}
                </div>
              </div>
            </AppFrame>
          ),
        },
        {
          action: 'Duże okno wykresu',
          does: 'Kliknięcie wykresu otwiera go w dużym oknie: kwota na początku i na końcu okresu, najniżej i najwyżej (z dniem). Przyciskami u góry przełączasz się między wykresami bez zamykania okna. Najedź kursorem na linię — pokaże się kwota, dzień i liczba towarów z tego dnia. Przy długim okresie na wykresie jest jeden punkt na tydzień.',
          click: 'Wykres (napis „Powiększ ›”), potem kursor na linię.',
          tone: 'blue',
          screen: (
            <AppFrame nav={NAV}>
              <div className="rounded-xl border border-slate-300 bg-white px-3 py-2 shadow-sm">
                <div className="flex items-start justify-between gap-2">
                  <div>
                    <b className="text-sm text-slate-900">Ponad pół roku bez sprzedaży</b>
                    <p className="text-[11px] text-slate-700">Oddział Rzeszów · magazyny handlowe · 2 kwietnia 2026 – 2 października 2026</p>
                  </div>
                  <span className="rounded border border-slate-300 px-2 py-1 text-[11px]">Zamknij</span>
                </div>
                <div className="mt-1.5 flex flex-wrap gap-1 text-[10px]">
                  {['Cały towar', 'Ponad pół roku bez sprzedaży', 'Ponad rok bez sprzedaży', 'Leży w magazynie ponad pół roku'].map((m) => (
                    <span
                      key={m}
                      className={`rounded border border-slate-300 px-1.5 py-0.5 ${m === 'Ponad pół roku bez sprzedaży' ? 'bg-blue-600 font-semibold text-white' : 'bg-white text-slate-800'}`}
                    >
                      {m}
                    </span>
                  ))}
                </div>
                <div className="mt-1.5 grid grid-cols-4 gap-1 text-[10px] text-slate-700">
                  {[
                    ['Na początku okresu', '750 tys. zł'],
                    ['Na końcu okresu', '712 tys. zł'],
                    ['Najniżej', '705 tys. zł'],
                    ['Najwyżej', '768 tys. zł'],
                  ].map(([l, v]) => (
                    <div key={l} className="rounded border border-slate-200 bg-slate-50 px-1.5 py-1">
                      {l}
                      <b className="block text-xs text-slate-900">{v}</b>
                    </div>
                  ))}
                </div>
                <div className="relative mt-1.5">
                  <svg viewBox="0 0 400 110" className="block h-28 w-full" aria-hidden="true">
                    <line x1="0" y1="30" x2="400" y2="30" className="stroke-slate-200" />
                    <line x1="0" y1="70" x2="400" y2="70" className="stroke-slate-200" />
                    <line x1="0" y1="106" x2="400" y2="106" className="stroke-slate-400" />
                    <polyline
                      points="0,40 50,30 100,36 150,48 200,52 250,62 300,70 350,74 400,78"
                      className="fill-none stroke-amber-700"
                      strokeWidth="2"
                      strokeLinejoin="round"
                    />
                    <line x1="250" y1="5" x2="250" y2="106" className="stroke-slate-700" strokeDasharray="2 2" />
                    <circle cx="250" cy="62" r="4" className="fill-amber-700" />
                  </svg>
                  <span className="absolute top-0 left-[66%]">
                    <Mark>
                      <span className="block rounded border border-slate-300 bg-white px-2 py-0.5 text-[11px] whitespace-nowrap text-slate-900">
                        <b>731 tys. zł</b> · 18 lipca 2026
                        <span className="block text-slate-700">1 268 towarów</span>
                      </span>
                    </Mark>
                  </span>
                </div>
                <p className="text-[11px] text-slate-700">Najedź na linię, aby zobaczyć kwotę i dzień.</p>
              </div>
            </AppFrame>
          ),
        },
        {
          action: 'Wydane i od razu przyjęte z powrotem',
          does: 'Pary dokumentów RW → PW z ostatnich 12 miesięcy: RW (rozchód wewnętrzny) wydaje towar z magazynu, a PW (przyjęcie wewnętrzne) od razu przyjmuje go z powrotem. Liczony jest tylko towar, który przed wydaniem długo leżał — po takim zapisie wygląda jak nowa dostawa. „bez żadnego opisu” to pary bez wpisanego powodu; „Kto wystawił bez opisu” pokazuje osoby (ile bez opisu z wszystkich ich takich dokumentów).',
          click: 'Wiersz „bez żadnego opisu” albo nazwisko osoby.',
          tone: 'blue',
          screen: (
            <AppFrame nav={NAV}>
              <div className="rounded-xl border-2 border-slate-300 bg-white px-3 py-2">
                <h2 className="text-sm font-semibold text-slate-900">Wydane i od razu przyjęte z powrotem</h2>
                <p className="mb-1 text-[11px] text-slate-700">
                  Ostatnie 12 miesięcy; towar, który leżał co najmniej 6 miesięcy. Po takim zapisie wygląda jak nowa dostawa.
                </p>
                <div className="flex items-baseline justify-between px-1 py-1 text-xs">
                  <span>wszystkie</span>
                  <span>
                    <b>48 razy</b> <span className="font-semibold text-blue-700">›</span>
                  </span>
                </div>
                <Mark>
                  <div className="flex w-full items-baseline justify-between gap-6 px-1 py-1 text-xs">
                    <span>bez żadnego opisu</span>
                    <span>
                      <b className="text-red-800">12 razy · 86 tys. zł</b> <span className="font-semibold text-blue-700">›</span>
                    </span>
                  </div>
                </Mark>
                <h3 className="mt-2 text-xs font-semibold text-slate-900">Kto wystawił bez opisu</h3>
                <p className="px-1 text-[11px] text-slate-700">Przy osobie: ile bez opisu z wszystkich jej takich dokumentów.</p>
                {[
                  ['Nowak', '9', '30'],
                  ['Artur', '3', '18'],
                ].map(([name, count, total]) => (
                  <div key={name} className="flex items-baseline justify-between border-t border-slate-200 px-1 py-1 text-xs">
                    <span>{name}</span>
                    <span>
                      <b>{count}</b> <span className="text-slate-700">z {total}</span>{' '}
                      <span className="font-semibold text-blue-700">›</span>
                    </span>
                  </div>
                ))}
              </div>
            </AppFrame>
          ),
        },
        {
          action: 'Lista dokumentów wydania i przyjęcia',
          does: 'Okno pokazuje każdą parę: datę, towar z numerami „Wydanie … → Przyjęcie …”, kto wystawił, ilość i kwotę, ile towar leżał przed wydaniem i opis z dokumentu. Jeśli przy przyjęciu zmieniono rozmiar albo kolor, widać to pod nazwą. Lista pokazuje tylko to, co jest w dokumentach, i nie mówi, dlaczego tak zrobiono — powód może wyjaśnić osoba, która wystawiła dokument.',
          click: '„Co pokazuje ta lista?” rozwija objaśnienie; „Drukuj” drukuje samą listę.',
          tone: 'amber',
          screen: (
            <AppFrame nav={NAV}>
              <div className="rounded-xl border border-slate-300 bg-white shadow-sm">
                <div className="border-b border-slate-200 px-3 py-2">
                  <div className="flex flex-wrap items-baseline gap-x-3">
                    <b className="text-xs text-slate-900">
                      Towar wydany i od razu przyjęty z powrotem bez żadnego opisu (Rzeszów, magazyny handlowe)
                    </b>
                    <span className="text-[11px] text-slate-800">12 razy · razem 86 tys. zł</span>
                  </div>
                  <div className="mt-1.5 flex flex-wrap items-center gap-2 text-[11px]">
                    <span className="w-32 rounded border border-slate-300 px-2 py-1 text-slate-500">Szukaj w liście…</span>
                    <Mark>
                      <span className="px-1 font-semibold text-blue-700 underline">Co pokazuje ta lista?</span>
                    </Mark>
                    <span className="ml-auto rounded border border-slate-300 px-2 py-1">Drukuj</span>
                    <span className="rounded border border-slate-300 px-2 py-1">Zamknij</span>
                  </div>
                </div>
                <table className="w-full text-left text-xs">
                  <thead className="bg-slate-100">
                    <tr>
                      <Th>Data ▼</Th>
                      <Th>Towar</Th>
                      <Th>Kto wystawił</Th>
                      <Th>Ile i za ile</Th>
                      <Th>Ile leżał</Th>
                      <Th>Opis</Th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr className="border-t border-slate-200">
                      <td className="p-2">14 września 2026</td>
                      <td className="p-2">
                        <b className="block">Półbuty bezpieczne S3, rozmiar 43</b>
                        <span className="text-[11px] text-slate-700">Wydanie RW-412/09/2026 → Przyjęcie PW-388/09/2026</span>
                      </td>
                      <td className="p-2">Nowak</td>
                      <td className="p-2">
                        40 par
                        <b className="block">7 960 zł</b>
                      </td>
                      <td className="p-2">2 lata 3 mies.</td>
                      <td className="p-2 text-slate-600 italic">brak opisu</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </AppFrame>
          ),
        },
      ]}
    />
  )
}
