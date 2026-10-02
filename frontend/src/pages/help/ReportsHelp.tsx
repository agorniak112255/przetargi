import type { ReactNode } from 'react'
import { AppFrame, Card, Mark, Slideshow, Th } from './kit'

/** Samouczek „Raporty” — pięć zakładek (pages/Reports.tsx i pages/reports/*.tsx). */

const TABS = ['Baza wiedzy', 'Źródła danych', 'Ruchy cen', 'Sprzedaż i oferty', 'Klienci ERP']

const LEAD: Record<string, string> = {
  'Baza wiedzy': 'Jak kompletne są karty produktów, z których system dobiera wyroby do przetargów i zapytań.',
  'Źródła danych': 'Czy cenniki i konta B2B dostawców są aktualne i czy synchronizacje przechodzą.',
  'Ruchy cen': 'Podwyżki i obniżki cen u dostawców — gdzie i o ile zmieniły się ceny zakupu.',
  'Sprzedaż i oferty': 'Zapytania klientów, przetargi i kampanie: ile przyszło, ile obsłużono i jak szybko.',
  'Klienci ERP': 'Aktywność klientów z Comarch ERP XL: kto kupuje, kto przestał i do ilu można napisać.',
}

/** Raporty z uwagą „dane odświeżane co 10 min” przy dacie (ReportCatalog, ReportSources, ReportPrices). */
const REFRESHED = ['Baza wiedzy', 'Źródła danych', 'Ruchy cen']

/** Nagłówek strony z zakładkami raportów i linią „Stan na …” jak w ReportFrame. */
function ReportHead({ active, markTabs, toolbar }: { active: string; markTabs?: boolean; toolbar?: ReactNode }) {
  const tabs = (
    <div className="flex flex-wrap gap-1 border-b border-slate-200 text-xs">
      {TABS.map((t) => (
        <span
          key={t}
          className={`-mb-px border-b-2 px-2 py-1.5 ${t === active ? 'border-blue-600 font-semibold text-blue-700' : 'border-transparent text-slate-600'}`}
        >
          {t}
        </span>
      ))}
    </div>
  )
  return (
    <>
      <h1 className="text-xl font-semibold">Raporty</h1>
      <p className="mb-2 text-xs text-slate-500">{LEAD[active]}</p>
      <div className="mb-3">{markTabs ? <Mark>{tabs}</Mark> : tabs}</div>
      <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
        <div>{toolbar}</div>
        <span className="text-[11px] text-slate-500">
          Stan na 2.10.2026, 08:40{REFRESHED.includes(active) ? ' · dane odświeżane co 10 min' : ''}
        </span>
      </div>
    </>
  )
}

/** Przełącznik okresu jak Segmented w ReportKit. */
function Period({ options, value, mark }: { options: string[]; value: string; mark?: boolean }) {
  const el = (
    <span className="inline-flex items-center gap-1 rounded-lg bg-slate-100 p-0.5">
      {options.map((o) => (
        <span key={o} className={`rounded-md px-2 py-0.5 text-[11px] ${o === value ? 'bg-white font-semibold text-slate-900 shadow-sm' : 'text-slate-600'}`}>
          {o}
        </span>
      ))}
    </span>
  )
  return mark ? <Mark>{el}</Mark> : el
}

function Insights({ items }: { items: [string, string][] }) {
  return (
    <div className="mb-2 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-[11px] text-slate-800">
      <b className="mb-1 block text-[10px] tracking-wide text-slate-500 uppercase">Najważniejsze</b>
      <ul className="space-y-0.5">
        {items.map(([dot, text]) => (
          <li key={text} className="flex items-start gap-1.5">
            <span className={`mt-1 h-1.5 w-1.5 shrink-0 rounded-full ${dot}`} />
            <span>{text}</span>
          </li>
        ))}
      </ul>
    </div>
  )
}

/** Kafelki wskaźników: etykieta, wartość, opcjonalny pasek, podpis. */
function Kpis({ items }: { items: [string, string, string, string | null][] }) {
  return (
    <div className="mb-2 grid grid-cols-2 gap-2 sm:grid-cols-4">
      {items.map(([label, value, sub, fill]) => (
        <Card key={label} className="p-2.5">
          <span className="block truncate text-[10px] text-slate-500">{label}</span>
          <b className="text-base">{value}</b>
          {fill && (
            <span className="mt-0.5 block h-1 rounded-full bg-slate-100">
              <span className={`block h-full rounded-full ${fill}`} style={{ width: value }} />
            </span>
          )}
          <span className="mt-0.5 block truncate text-[10px] text-slate-500">{sub}</span>
        </Card>
      ))}
    </div>
  )
}

export function ReportsHelp() {
  return (
    <Slideshow
      title="Raporty"
      slides={[
        {
          action: 'Wybór raportu',
          does: 'Raporty to pięć zestawień liczonych z danych aplikacji, każde w swojej zakładce. Menu „Raporty” widzi tylko osoba z uprawnieniem do raportów, a w nim tylko zakładki z danymi, do których ma dostęp: Baza wiedzy i Ruchy cen — produkty, Źródła danych — cenniki albo konta B2B, Klienci ERP — kampanie. Sprzedaż i oferty widać zawsze, ale tylko te części, do których masz uprawnienie. Każdy raport zaczyna się od ramki „Najważniejsze” — kilku zdań policzonych z danych, same fakty.',
          click: 'Menu „Raporty”, potem nazwa raportu w zakładkach u góry. Pod tytułem widać, czego dotyczy wybrany raport.',
          tone: 'blue',
          screen: (
            <AppFrame nav="Raporty">
              <ReportHead active="Baza wiedzy" markTabs />
              <Insights
                items={[
                  ['bg-red-600', 'Najsłabiej pokryte: normy potwierdzone u producenta — ma je 22% kart (4 051 z 18 412).'],
                  ['bg-amber-500', 'Najwięcej kart bez opisu ma Portwest: 96 z 1 120.'],
                ]}
              />
            </AppFrame>
          ),
        },
        {
          action: 'Baza wiedzy',
          does: 'Jak kompletne są karty produktów: ile ma opis, zdjęcie, normy potwierdzone u producenta i dokumenty (karty katalogowe, certyfikaty, instrukcje) oraz ile jest gotowych do wyszukiwania. Kolor kafelka: zielony — dobrze, pomarańczowy — do uwagi, czerwony — problem. Niżej: dodane karty i uzupełnione opisy tydzień po tygodniu, porównanie „Cały katalog a karty, które sprzedajemy”, lista „Sprzedawane karty z brakami” i tabela „Producenci”.',
          click: 'W tabeli „Producenci” wpisz nazwę w „Szukaj producenta” albo kliknij nagłówek kolumny, żeby posortować (na przykład „Normy producenta”). „Pokaż wszystkich” rozwija listę ponad 25 największych.',
          tone: 'blue',
          screen: (
            <AppFrame nav="Raporty">
              <ReportHead active="Baza wiedzy" />
              <Kpis
                items={[
                  ['Z opisem', '97%', '214 krótszych niż 120 znaków', 'bg-emerald-600'],
                  ['Ze zdjęciem', '96%', '730 bez zdjęcia', 'bg-emerald-600'],
                  ['Normy u producenta', '22%', 'normy w opisie: 61%', 'bg-red-600'],
                  ['Z dokumentami', '48%', 'karty katalogowe, certyfikaty', 'bg-amber-500'],
                ]}
              />
              <Card className="p-3">
                <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                  <h2 className="text-xs font-semibold">Producenci</h2>
                  <Mark>
                    <span className="inline-block w-40 rounded-md border border-slate-300 bg-white px-2 py-1 text-[11px] text-slate-400">Szukaj producenta</span>
                  </Mark>
                </div>
                <table className="w-full text-left text-xs">
                  <thead>
                    <tr className="border-b bg-slate-50">
                      <Th>Producent</Th>
                      <Th>Kart</Th>
                      <Th>Opis</Th>
                      <Th>Zdjęcie</Th>
                      <Th>
                        <Mark>
                          <span>Normy producenta ↓</span>
                        </Mark>
                      </Th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr className="border-b">
                      <td className="p-2 font-medium">UVEX</td>
                      <td className="p-2">1 864</td>
                      <td className="p-2">99%</td>
                      <td className="p-2">98%</td>
                      <td className="p-2">71%</td>
                    </tr>
                    <tr>
                      <td className="p-2 font-medium">Portwest</td>
                      <td className="p-2">1 120</td>
                      <td className="p-2">91%</td>
                      <td className="p-2">97%</td>
                      <td className="p-2 text-red-700">4%</td>
                    </tr>
                  </tbody>
                </table>
              </Card>
            </AppFrame>
          ),
        },
        {
          action: 'Źródła danych',
          does: 'Czy ceny od dostawców są świeże. Kafelki: ile jest kont B2B (sklepów dostawców), ile ma ceny aktualne, ile przeterminowane (udany przebieg za dawno) i ile skończyło ostatni przebieg błędem. Wykres pokazuje przebiegi synchronizacji z 30 dni: udane, częściowe, z błędem lub przerwane, anulowane. W tabeli „Konta B2B dostawców” konta z problemami są na górze; w „Cennikach z plików” na pomarańczowo import starszy niż próg dni.',
          click: 'Przeczytaj stan w kolumnie „Ostatni przebieg”. Nagłówek kolumny sortuje, „Pokaż wszystkie konta” rozwija listę.',
          tone: 'amber',
          screen: (
            <AppFrame nav="Raporty">
              <ReportHead active="Źródła danych" />
              <Kpis
                items={[
                  ['Konta B2B', '23', '21 z synchronizacją według harmonogramu', null],
                  ['Ceny aktualne', '19', 'ostatni udany przebieg w terminie', null],
                  ['Ceny przeterminowane', '2', 'udany przebieg za dawno', null],
                  ['Ostatni przebieg z błędem', '1', 'błąd albo przerwany', null],
                ]}
              />
              <Card className="p-3">
                <h2 className="mb-2 text-xs font-semibold">Konta B2B dostawców</h2>
                <table className="w-full text-left text-xs">
                  <thead>
                    <tr className="border-b bg-slate-50">
                      <Th>Dostawca</Th>
                      <Th>Ostatni przebieg</Th>
                      <Th>Ostatni udany</Th>
                      <Th>Kart</Th>
                      <Th>Nieudane / 30 dni</Th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr className="border-b align-top">
                      <td className="p-2">
                        <b className="font-medium">Procera</b>
                        <span className="block text-[10px] text-slate-500">synchronizacja codziennie</span>
                      </td>
                      <td className="p-2">
                        <span className="inline-flex items-center gap-1 rounded-full bg-red-50 px-2 py-0.5 text-[10px] text-red-800">
                          <i className="h-1.5 w-1.5 rounded-full bg-red-600" />
                          Błąd
                        </span>
                        <span className="ml-1 text-[10px] text-red-700">3× z rzędu</span>
                      </td>
                      <td className="p-2 font-semibold text-amber-800">4 dni temu</td>
                      <td className="p-2">640</td>
                      <td className="p-2 text-red-700">3 / 30</td>
                    </tr>
                    <tr className="align-top">
                      <td className="p-2">
                        <b className="font-medium">UVEX</b>
                        <span className="block text-[10px] text-slate-500">synchronizacja co tydzień</span>
                      </td>
                      <td className="p-2">
                        <span className="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] text-emerald-800">
                          <i className="h-1.5 w-1.5 rounded-full bg-emerald-600" />
                          Udany
                        </span>
                      </td>
                      <td className="p-2">2 dni temu</td>
                      <td className="p-2">1 864</td>
                      <td className="p-2 text-slate-400">0 / 4</td>
                    </tr>
                  </tbody>
                </table>
              </Card>
            </AppFrame>
          ),
        },
        {
          action: 'Ruchy cen',
          does: 'Podwyżki i obniżki cen zakupu u dostawców w wybranym okresie. Kafelki: liczba zmian, podwyżki i obniżki o co najmniej 1%, drobne korekty poniżej 1% i typowa zmiana. Wykres tydzień po tygodniu (podwyżki w górę, obniżki w dół), tabela „Źródła cen”, listy „Największe podwyżki” i „Największe obniżki”. „Skoki do sprawdzenia” to ceny wyższe albo niższe co najmniej 4 razy — zwykle inna jednostka (karton zamiast sztuki).',
          click: 'Okres u góry: „7 dni”, „30 dni” albo „90 dni”. Najedź na słupek wykresu, żeby zobaczyć liczby; nazwa wyrobu na liście otwiera jego kartę.',
          tone: 'blue',
          screen: (
            <AppFrame nav="Raporty">
              <ReportHead active="Ruchy cen" toolbar={<Period options={['7 dni', '30 dni', '90 dni']} value="30 dni" mark />} />
              <Kpis
                items={[
                  ['Zmiany cen', '1 284', '612 kart · 14 źródeł', null],
                  ['Podwyżki ≥ 1%', '318', 'wszystkich podwyżek: 702', null],
                  ['Obniżki ≥ 1%', '96', 'wszystkich obniżek: 411', null],
                  ['Typowa zmiana zakupu', '+3,2%', 'mediana', null],
                ]}
              />
              <Card className="p-3">
                <h2 className="mb-2 text-xs font-semibold">Podwyżki i obniżki — tydzień po tygodniu</h2>
                <svg viewBox="0 0 260 80" className="block w-full max-w-md" role="img" aria-label="Przykładowy wykres podwyżek i obniżek">
                  <line x1="0" x2="260" y1="44" y2="44" className="stroke-slate-300" strokeWidth="1" />
                  {(
                    [
                      [20, 14, 6],
                      [80, 26, 10],
                      [140, 38, 5],
                      [200, 20, 16],
                    ] as const
                  ).map(([x, up, down]) => (
                    <g key={x}>
                      <rect x={x} y={44 - up} width="26" height={up} rx="2" className="fill-amber-500" />
                      <rect x={x} y="45" width="26" height={down} rx="2" className="fill-sky-600" />
                    </g>
                  ))}
                </svg>
                <div className="mt-1 flex gap-3 text-[10px] text-slate-500">
                  <span className="inline-flex items-center gap-1">
                    <i className="h-2 w-2 rounded-sm bg-amber-500" />
                    Podwyżki
                  </span>
                  <span className="inline-flex items-center gap-1">
                    <i className="h-2 w-2 rounded-sm bg-sky-600" />
                    Obniżki
                  </span>
                </div>
              </Card>
            </AppFrame>
          ),
        },
        {
          action: 'Sprzedaż i oferty',
          does: 'Trzy części, każda tylko z uprawnieniem do swoich danych. „Zapytania klientów”: ile przyszło, na ile odpowiedziano i ile w ciągu dnia roboczego, ile czeka, skąd przychodzą i obsługa według osoby. „Przetargi”: liczba i wartość według statusu, terminy w ciągu 14 dni i zestawienie według opiekuna — przełącznik okresu ich nie dotyczy. „Kampanie”: wysłane maile, kliknięcia, odpowiedzi, wypisani i kupujący. Szara plakietka przy tytule mówi, czy widzisz „cały zespół”, czy „tylko moje”.',
          click: 'Okres u góry: „30 dni”, „90 dni” albo „180 dni”.',
          tone: 'blue',
          screen: (
            <AppFrame nav="Raporty">
              <ReportHead active="Sprzedaż i oferty" toolbar={<Period options={['30 dni', '90 dni', '180 dni']} value="90 dni" mark />} />
              <h2 className="mb-2 flex items-center gap-2 text-sm font-semibold">
                Zapytania klientów
                <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-normal text-slate-600">cały zespół</span>
              </h2>
              <Kpis
                items={[
                  ['Zapytania', '342', '+ 18 kopii u innych osób', null],
                  ['Odpowiedziane', '88%', '301 z 342', 'bg-emerald-600'],
                  ['W ciągu dnia roboczego', '74%', 'odpowiedzi od daty maila klienta', 'bg-amber-500'],
                  ['Czekają na odpowiedź', '41', '9 dłużej niż dzień roboczy', null],
                ]}
              />
            </AppFrame>
          ),
        },
        {
          action: 'Eksport przetargów do pliku CSV',
          does: 'W raporcie „Sprzedaż i oferty”, przy nagłówku „Przetargi”, przycisk pobiera plik raport-przetargi.csv, który otworzysz w Excelu. Każdy przetarg to jeden wiersz: numer, tytuł, zamawiający, opiekun, status, wartość oferty netto, marża, termin składania i dopasowanie. Bez uprawnienia do wszystkich przetargów plik zawiera tylko Twoje.',
          click: 'Zielony przycisk „Eksport CSV” po prawej od nagłówka „Przetargi”.',
          tone: 'blue',
          screen: (
            <AppFrame nav="Raporty">
              <ReportHead active="Sprzedaż i oferty" toolbar={<Period options={['30 dni', '90 dni', '180 dni']} value="90 dni" />} />
              <div className="mb-1 flex items-center justify-between gap-2">
                <h2 className="flex items-center gap-2 text-sm font-semibold">
                  Przetargi
                  <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-normal text-slate-600">cały zespół</span>
                </h2>
                <Mark>
                  <span className="inline-block rounded-md bg-emerald-600 px-3 py-1.5 text-xs font-medium text-white">Eksport CSV</span>
                </Mark>
              </div>
              <p className="mb-2 text-[11px] text-slate-500">Wszystkie przetargi w toku i zakończone — przełącznik okresu ich nie dotyczy.</p>
              <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                {(
                  [
                    ['Szkic', '2', '—'],
                    ['Wycena', '4', '412 600 zł'],
                    ['Zatwierdzona', '2', '214 300 zł'],
                    ['Wyeksportowana', '11', '1 240 800 zł'],
                  ] as const
                ).map(([status, count, value]) => (
                  <Card key={status} className="p-2.5">
                    <span className="block text-[10px] text-slate-500">{status}</span>
                    <b className="text-base">{count}</b>
                    <span className="block text-[10px] text-slate-600">{value}</span>
                  </Card>
                ))}
              </div>
            </AppFrame>
          ),
        },
        {
          action: 'Klienci ERP',
          does: 'Klienci z systemu sprzedaży Comarch ERP XL według daty ostatniego zakupu: aktywni do 6 miesięcy, uśpieni 6–12 miesięcy i odchodzący 12–24 miesięcy, oraz zasięg mailowy — do ilu aktywnych klientów można napisać w kampanii. Liczone są faktury i paragony z 24 miesięcy (liczba dokumentów, nie wartość sprzedaży). Niżej: wykres ostatnich zakupów, miasta, klienci według operatora (osoby, która wystawiała klientowi najwięcej dokumentów), najczęściej kupujący i towary kupowane przez najwięcej klientów.',
          click: 'Nic — przeczytaj kafelki i tabele. „Pokaż wszystkich operatorów” rozwija listę; nazwa towaru z kartą w katalogu otwiera tę kartę.',
          tone: 'slate',
          screen: (
            <AppFrame nav="Raporty">
              <ReportHead active="Klienci ERP" />
              <Kpis
                items={[
                  ['Aktywni do 6 miesięcy', '612', 'ostatni zakup w półroczu', null],
                  ['Uśpieni 6–12 miesięcy', '148', 'warto przypomnieć się', null],
                  ['Odchodzący 12–24 miesięcy', '203', 'ostatni zakup ponad rok temu', null],
                  ['Zasięg mailowy', '64%', '487 aktywnych z adresem e-mail', 'bg-emerald-600'],
                ]}
              />
              <Card className="p-3">
                <h2 className="mb-2 text-xs font-semibold">Klienci według operatora ERP XL</h2>
                <table className="w-full text-left text-xs">
                  <thead>
                    <tr className="border-b bg-slate-50">
                      <Th>Operator</Th>
                      <Th>Aktywni / uśpieni / odchodzący</Th>
                      <Th>Uśpieni</Th>
                      <Th>Z e-mailem</Th>
                    </tr>
                  </thead>
                  <tbody>
                    {(
                      [
                        ['Artur', 60, 25, 15, '38', '142'],
                        ['Nowak', 45, 30, 25, '51', '96'],
                      ] as const
                    ).map(([name, a, d, l, dormant, mail]) => (
                      <tr key={name} className="border-b last:border-0">
                        <td className="p-2 font-medium">{name}</td>
                        <td className="p-2">
                          <span className="flex h-2 w-32 gap-0.5 overflow-hidden rounded-full bg-slate-100">
                            <span className="h-full bg-emerald-600" style={{ width: `${a}%` }} />
                            <span className="h-full bg-amber-500" style={{ width: `${d}%` }} />
                            <span className="h-full bg-red-600" style={{ width: `${l}%` }} />
                          </span>
                        </td>
                        <td className="p-2">{dormant}</td>
                        <td className="p-2">{mail}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </Card>
            </AppFrame>
          ),
        },
      ]}
    />
  )
}
