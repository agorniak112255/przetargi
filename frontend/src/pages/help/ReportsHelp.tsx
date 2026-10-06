import type { ReactNode } from 'react'
import { AppFrame, Btn, Card, LivePage, Mark, Slideshow, Th } from './kit'
import { useAuth } from '../../auth'
import { AdminTeams } from '../../components/AdminTeams'
import { Reports } from '../Reports'
import { can, canAny } from '../../lib/api'

/** Samouczek „Raporty” — zakładki raportów (pages/Reports.tsx i pages/reports/*.tsx). */

const TABS = ['Baza wiedzy', 'Źródła danych', 'Ruchy cen', 'Sprzedaż i oferty', 'Klienci ERP', 'Skuteczność przetargów', 'Cele handlowców', 'Wynik kampanii']

const LEAD: Record<string, string> = {
  'Baza wiedzy': 'Jak kompletne są karty produktów, z których system dobiera wyroby do przetargów i zapytań.',
  'Źródła danych': 'Czy cenniki i konta B2B dostawców są aktualne i czy synchronizacje przechodzą.',
  'Ruchy cen': 'Podwyżki i obniżki cen u dostawców — gdzie i o ile zmieniły się ceny zakupu.',
  'Sprzedaż i oferty': 'Zapytania klientów, przetargi i kampanie: ile przyszło, ile obsłużono i jak szybko.',
  'Klienci ERP': 'Aktywność klientów z Comarch ERP XL: kto kupuje, kto przestał i do ilu można napisać.',
  'Skuteczność przetargów': 'Ile części zamówień wygrywamy, dlaczego przegrywamy i z kim — według terminu składania ofert.',
  'Cele handlowców': 'Miesięczne cele sprzedaży handlowców i ich realizacja według faktur i paragonów z ERP XL.',
  'Wynik kampanii': 'Ile pieniędzy zamrożonych w zalegającym towarze wróciło ze sprzedaży odbiorcom kampanii mailowych — według faktur i paragonów z ERP XL.',
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

/** Rysunek części „Zespoły” z Administracja → Role (dla osób bez uprawnienia do ról — przykładowe dane). */
function TeamsSketch() {
  const people: [string, string, boolean, boolean][] = [
    ['Jan Kowalski', 'dyrektor', true, true],
    ['Anna Nowak', 'handlowiec', true, false],
    ['Piotr Wiśniewski', 'handlowiec', true, false],
    ['Katarzyna Zielińska', 'handlowiec', false, false],
  ]
  return (
    <AppFrame nav="Administracja">
      <Card className="p-3">
        <div className="mb-1 flex items-center justify-between gap-2">
          <h2 className="text-xs font-semibold">Zespoły — kto widzi czyje wyniki kampanii</h2>
          <Mark>
            <Btn label="Dodaj zespół" />
          </Mark>
        </div>
        <p className="mb-2 text-[11px] text-slate-500">Kierownik zespołu widzi w Raportach wynik kampanii wszystkich członków swoich zespołów.</p>
        <table className="mb-3 w-full text-left text-xs">
          <thead>
            <tr className="border-b bg-slate-50">
              <Th>Zespół</Th>
              <Th>Kierownicy</Th>
              <Th>Członkowie</Th>
            </tr>
          </thead>
          <tbody>
            <tr className="border-b">
              <td className="p-2 font-medium">Handel Rzeszów</td>
              <td className="p-2">Jan Kowalski</td>
              <td className="p-2">Anna Nowak, Piotr Wiśniewski</td>
            </tr>
            <tr>
              <td className="p-2 font-medium">Handel Kraków</td>
              <td className="p-2 text-amber-700">brak kierownika — zespół nie zmienia, kto co widzi</td>
              <td className="p-2">Katarzyna Zielińska</td>
            </tr>
          </tbody>
        </table>
        <div className="rounded-lg border border-slate-200 p-2">
          <p className="mb-1 text-xs font-semibold">Zmiana zespołu „Handel Rzeszów”</p>
          <p className="mb-1 text-[11px] text-slate-600">W zespole: 3 osoby, w tym kierowników: 1</p>
          <ul className="divide-y divide-slate-100 rounded border border-slate-100 text-xs">
            {people.map(([name, role, member, leader]) => (
              <li key={name} className="flex items-center gap-3 px-2 py-1">
                <span className="flex flex-1 items-center gap-2">
                  <input type="checkbox" checked={member} readOnly aria-label={`${name} w zespole`} />
                  {name} <span className="text-slate-500">{role}</span>
                </span>
                <span className="flex items-center gap-1 text-slate-600">
                  <input type="checkbox" checked={leader} readOnly aria-label={`${name} — kierownik zespołu`} />
                  kierownik zespołu
                </span>
              </li>
            ))}
          </ul>
        </div>
      </Card>
    </AppFrame>
  )
}

export function ReportsHelp() {
  const { user } = useAuth()
  return (
    <Slideshow
      title="Raporty"
      slides={[
        {
          action: 'Wybór raportu',
          does: 'Raporty to zestawienia liczone z danych aplikacji, każde w swojej zakładce. Menu „Raporty” widzi tylko osoba z uprawnieniem do raportów, a w nim tylko zakładki z danymi, do których ma dostęp: Baza wiedzy i Ruchy cen — produkty, Źródła danych — cenniki albo konta B2B, Klienci ERP — kampanie, Skuteczność przetargów — przetargi, Cele handlowców — osobne uprawnienie „Cele handlowców” (na start ma je tylko administrator). Sprzedaż i oferty widać zawsze, ale tylko te części, do których masz uprawnienie. Wyjątek: „Wynik kampanii” widzi każdy, kto wysyła kampanie — także bez uprawnienia do raportów; wtedy menu „Raporty” ma tylko tę jedną zakładkę. Większość raportów zaczyna się od ramki „Najważniejsze” albo jednego zdania z wnioskiem — policzonych z danych, same fakty.',
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
            <LivePage
              nav="Raporty"
              path="/reports?raport=catalog"
              page={<Reports />}
              allowed={can(user, 'reports.view') && can(user, 'products.view')}
              fallback={
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
              }
            />
          ),
        },
        {
          action: 'Źródła danych',
          does: 'Czy ceny od dostawców są świeże. Kafelki: ile jest kont B2B (sklepów dostawców), ile ma ceny aktualne, ile przeterminowane (udany przebieg za dawno) i ile skończyło ostatni przebieg błędem. Wykres pokazuje przebiegi synchronizacji z 30 dni: udane, częściowe, z błędem lub przerwane, anulowane. W tabeli „Konta B2B dostawców” konta z problemami są na górze; w „Cennikach z plików” na pomarańczowo import starszy niż próg dni.',
          click: 'Przeczytaj stan w kolumnie „Ostatni przebieg”. Nagłówek kolumny sortuje, „Pokaż wszystkie konta” rozwija listę.',
          tone: 'amber',
          screen: (
            <LivePage
              nav="Raporty"
              path="/reports?raport=sources"
              page={<Reports />}
              allowed={can(user, 'reports.view') && canAny(user, ['price_lists.view', 'b2b_accounts.view'])}
              fallback={
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
              }
            />
          ),
        },
        {
          action: 'Ruchy cen',
          does: 'Podwyżki i obniżki cen zakupu u dostawców w wybranym okresie. Kafelki: liczba zmian, podwyżki i obniżki o co najmniej 1%, drobne korekty poniżej 1% i typowa zmiana. Wykres tydzień po tygodniu (podwyżki w górę, obniżki w dół), tabela „Źródła cen”, listy „Największe podwyżki” i „Największe obniżki”. „Skoki do sprawdzenia” to ceny wyższe albo niższe co najmniej 4 razy — zwykle inna jednostka (karton zamiast sztuki).',
          click: 'Okres u góry: „7 dni”, „30 dni” albo „90 dni”. Najedź na słupek wykresu, żeby zobaczyć liczby; nazwa wyrobu na liście otwiera jego kartę.',
          tone: 'blue',
          screen: (
            <LivePage
              nav="Raporty"
              path="/reports?raport=prices"
              page={<Reports />}
              allowed={can(user, 'reports.view') && can(user, 'products.view')}
              fallback={
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
              }
            />
          ),
        },
        {
          action: 'Sprzedaż i oferty',
          does: 'Trzy części, każda tylko z uprawnieniem do swoich danych. „Zapytania klientów”: ile przyszło, na ile odpowiedziano i ile w ciągu dnia roboczego, ile czeka, skąd przychodzą i obsługa według osoby. „Przetargi”: liczba i wartość według statusu, terminy w ciągu 14 dni i zestawienie według opiekuna — przełącznik okresu ich nie dotyczy. „Kampanie”: wysłane maile, kliknięcia, odpowiedzi, wypisani i kupujący. Szara plakietka przy tytule mówi, czy widzisz „cały zespół”, czy „tylko moje”. W zapytaniach blok „Od zapytania do sprzedaży”: przyszło (kopie tego samego maila liczone raz), odpowiedzieliśmy, zamówił — tylko to, co potwierdził handlowiec — i osobno, na szaro, „możliwe”: same podpowiedzi z ERP XL, których nikt jeszcze nie potwierdził. Przy osobach: zwykły czas odpowiedzi (mediana), zamówione z odpowiedzianych i wartość zamówień z potwierdzonych dokumentów.',
          click: 'Okres u góry: „30 dni”, „90 dni” albo „180 dni”.',
          tone: 'blue',
          screen: (
            <LivePage
              nav="Raporty"
              path="/reports?raport=sales"
              page={<Reports />}
              allowed={can(user, 'reports.view')}
              fallback={
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
              }
            />
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
          does: 'Klienci z systemu sprzedaży Comarch ERP XL według daty ostatniego zakupu: aktywni do 6 miesięcy, uśpieni 6–12 miesięcy i odchodzący 12–24 miesięcy, oraz zasięg mailowy — do ilu aktywnych klientów można napisać w kampanii. Liczone są faktury (także wystawione do WZ) i paragony z 24 miesięcy (liczba dokumentów, nie wartość sprzedaży). Niżej: wykres ostatnich zakupów, miasta, klienci według operatora (osoby, która wystawiała klientowi najwięcej dokumentów), najczęściej kupujący i towary kupowane przez najwięcej klientów.',
          click: 'Nic — przeczytaj kafelki i tabele. „Pokaż wszystkich operatorów” rozwija listę; nazwa towaru z kartą w katalogu otwiera tę kartę.',
          tone: 'slate',
          screen: (
            <LivePage
              nav="Raporty"
              path="/reports?raport=customers"
              page={<Reports />}
              allowed={can(user, 'reports.view') && can(user, 'campaigns.use')}
              fallback={
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
              }
            />
          ),
        },
        {
          action: 'Cele handlowców',
          does: 'Cel sprzedaży na miesiąc dla każdej osoby i jego realizacja: sprzedaż netto z faktur i paragonów w ERP XL, po korektach, klientów przypisanych do tej osoby. Faktura wystawiona do WZ liczy się z towarów na jej WZ, a WZ, do której nie ma jeszcze faktury — od dnia wydania towaru. Klient należy do handlowca według dzisiejszego opiekuna — najpierw opiekun z karty klienta w ERP XL (gdy administrator przypisał tego pracownika ERP XL do konta), a gdy go nie ma — opiekun w aplikacji. Liczą się tylko klienci z zakładki Klienci (kontrahenci ERP XL, którzy w roku kupili za co najmniej 3000 zł netto). Wiersz „Klienci bez opiekuna” pokazuje sprzedaż, której nikt nie ma w celu. „Klienci, którzy kupili” mają w miesiącu fakturę albo paragon, „nowi” nie kupowali przez 24 miesiące wcześniej. Bieżący miesiąc jest w toku: widać, ile dni roboczych minęło (od poniedziałku do piątku, bez odliczania świąt), a sprzedaż obejmuje dokumenty do ostatniego nocnego odczytu z ERP XL. Następny miesiąc służy do ustalenia celów z wyprzedzeniem — sprzedaż i realizacja pojawią się od jego pierwszego dnia. Handlowiec swój cel widzi na Dashboardzie — kafelek „Mój cel” pojawia się, gdy ma cel w bieżącym miesiącu.',
          click: 'Wybierz miesiąc (następny, bieżący i 11 poprzednich), potem „Ustaw cele”, wpisz kwoty i „Zapisz cele”. Puste pole usuwa cel; zapisują się tylko zmienione kwoty.',
          tone: 'violet',
          screen: (
            <LivePage
              nav="Raporty"
              path="/reports?raport=targets"
              page={<Reports />}
              allowed={can(user, 'reports.view') && can(user, 'reports.targets.manage')}
              fallback={
                <AppFrame nav="Raporty">
                  <ReportHead active="Cele handlowców" toolbar={<Mark>Ustaw cele</Mark>} />
                  <Card className="p-3">
                    <h2 className="mb-2 text-xs font-semibold">Wrzesień 2026 · zamknięty miesiąc</h2>
                    <table className="w-full text-left text-xs">
                      <thead>
                        <tr className="border-b bg-slate-50">
                          <Th>Handlowiec</Th>
                          <Th>Cel</Th>
                          <Th>Sprzedaż</Th>
                          <Th>Realizacja</Th>
                          <Th>Klienci, którzy kupili</Th>
                          <Th>w tym nowi</Th>
                        </tr>
                      </thead>
                      <tbody>
                        {(
                          [
                            ['Anna Nowak', '420 000 zł', '468 900 zł', '112%', '64', '3'],
                            ['Piotr Wiśniewski', '380 000 zł', '391 450 zł', '103%', '51', '1'],
                            ['Klienci bez opiekuna', '—', '184 200 zł', '', '212', '9'],
                          ] as const
                        ).map(([name, target, sales, pct, bought, fresh]) => (
                          <tr key={name} className="border-b last:border-0">
                            <td className="p-2">{name}</td>
                            <td className="p-2">{target}</td>
                            <td className="p-2">{sales}</td>
                            <td className="p-2">{pct}</td>
                            <td className="p-2">{bought}</td>
                            <td className="p-2">{fresh}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </Card>
                </AppFrame>
              }
            />
          ),
        },
        {
          action: 'Wynik kampanii — jak liczymy',
          does: 'Raport pokazuje, ile pieniędzy zamrożonych w zalegającym towarze wróciło ze sprzedaży odbiorcom kampanii mailowych. Pozycja faktury albo paragonu z ERP XL należy do kampanii, gdy klient dostał mail z tym towarem i kupił go w oknie kampanii: od dnia maila do 30. dnia po starcie wysyłki (czas polski). Gdy pasuje kilka kampanii, wygrywa ostatni mail przed zakupem — wcześniejsza kampania widzi tę sprzedaż jako „przejętą przez późniejszy mail”. Korekta liczy się w tej samej kampanii co korygowana faktura, w miesiącu swojej daty; korekta bez znalezionej faktury nie liczy się wcale. „Uwolnione pieniądze” to koszt zakupu sprzedanego towaru, który w dniu wysyłki był zalegający (co najmniej 180 dni bez sprzedaży albo nigdy niesprzedany, gdy partia leżała co najmniej 180 dni), najwyżej do stanu z dnia wysyłki — ten stan zmniejsza każda sprzedaż towaru po starcie (także innym klientom), więc dwie kampanie nie dostaną uwolnionych pieniędzy za ten sam zapas. Koszt bierzemy z ERP XL; gdy go brak — szacujemy z kosztu jednostki z dnia wysyłki i oznaczamy „szacunek”. Gdy kosztu nie ma wcale, marża jest „brak danych”, a nie zero. „Odzysk” mówi, ile klienci zapłacili za każde 100 zł kosztu zalegającego towaru. Kampanie wysłane przed wprowadzeniem raportu nie mają danych z dnia wysyłki, więc ich towar nie liczy się do uwolnionych.',
          click: 'Wybierz miesiąc (bieżący i 11 poprzednich). Liczby miesiąca stają się ostateczne 7 dni po jego końcu — data jest w nagłówku. „Pobierz pozycje faktur (CSV)” zapisuje plik do Excela z każdą przypisaną pozycją: klientem, kosztem i jego źródłem, kampanią i dopasowaniem klienta — do sprawdzenia przed premią.',
          tone: 'blue',
          screen: (
            <LivePage
              nav="Raporty"
              path="/reports?raport=campaigns"
              page={<Reports />}
              allowed={user?.campaign_report_scope != null}
              mark="text=Pobierz pozycje faktur (CSV)"
              fallback={
                <AppFrame nav="Raporty">
                  <ReportHead
                    active="Wynik kampanii"
                    toolbar={
                      <span className="flex flex-wrap items-center gap-2 text-[11px] text-slate-600">
                        Miesiąc <span className="rounded-md border border-slate-300 bg-white px-2 py-0.5">Wrzesień 2026</span>
                        <Mark>
                          <span className="inline-block rounded-md bg-emerald-600 px-3 py-1.5 text-xs font-medium text-white">Pobierz pozycje faktur (CSV)</span>
                        </Mark>
                      </span>
                    }
                  />
                  <Kpis
                    items={[
                      ['Uwolnione pieniądze', '48 tys. zł', 'towar leżał średnio 512 dni', null],
                      ['Sprzedaż odbiorcom kampanii', '126 tys. zł', '38 kupujących klientów', null],
                      ['Odzysk', '131 zł', 'za każde 100 zł kosztu', null],
                      ['Marża', '31 tys. zł', '24,6% sprzedaży ze znanym kosztem', null],
                    ]}
                  />
                </AppFrame>
              }
            />
          ),
        },
        {
          action: 'Wynik kampanii — kto co widzi',
          does: 'Handlowiec widzi wynik swoich kampanii. Kierownik zespołu widzi także członków swoich zespołów: ranking osób (uwolnione pieniądze, sprzedaż, odzysk, marża i pozycje sprzedane poniżej kosztu) i może zawęzić raport do jednej osoby. Zarząd z uprawnieniem „Wynik kampanii — wszyscy pracownicy” widzi wszystkich. Niżej: „Towary, które najlepiej zeszły” — ile leżały przed mailem — oraz kampanie z rozwijaną listą towarów: czy towar zalegał w dniu wysyłki, stan i wartość w ofercie, cena z oferty wobec ceny uzyskanej, sprzedaż w miesiącu i w całym oknie, nadwyżka ponad stan. Na dole zastrzeżenia do danych tego miesiąca i reguła liczenia słowami.',
          click: 'Kliknij osobę w rankingu albo wybierz ją na liście „Osoba”; „pokaż wszystkich” wraca do całości. Przy kampanii „Towary (…)” rozwija jej towary. Zespoły i kierowników ustawia administrator w Administracja → Role, w części „Zespoły”.',
          tone: 'slate',
          screen: (
            <AppFrame nav="Raporty">
              <ReportHead active="Wynik kampanii" toolbar={<Mark>Osoba: Cały mój zespół</Mark>} />
              <Card className="p-3">
                <h2 className="mb-2 text-xs font-semibold">Ranking osób</h2>
                <table className="w-full text-left text-xs">
                  <thead>
                    <tr className="border-b bg-slate-50">
                      <Th>Osoba</Th>
                      <Th>Kampanie</Th>
                      <Th>Sprzedaż netto</Th>
                      <Th>Uwolnione</Th>
                      <Th>Odzysk na 100 zł</Th>
                      <Th>Poniżej kosztu</Th>
                    </tr>
                  </thead>
                  <tbody>
                    {(
                      [
                        ['Anna Nowak', '3', '64 tys. zł', '27 tys. zł', '138 zł', 'żadnej pozycji'],
                        ['Piotr Wiśniewski', '2', '41 tys. zł', '15 tys. zł', '96 zł', '2 pozycje'],
                      ] as const
                    ).map(([name, campaigns, sales, freed, recovery, below]) => (
                      <tr key={name} className="border-b last:border-0">
                        <td className="p-2 font-medium text-blue-700">{name}</td>
                        <td className="p-2">{campaigns}</td>
                        <td className="p-2">{sales}</td>
                        <td className="p-2 font-semibold">{freed}</td>
                        <td className="p-2">{recovery}</td>
                        <td className="p-2">{below}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </Card>
            </AppFrame>
          ),
        },
        {
          action: 'Zespoły — kto czyje wyniki widzi',
          does: 'Zespół to grupa pracowników z jednym albo kilkoma kierownikami. Kierownik zespołu widzi w Raportach → „Wynik kampanii” kampanie wszystkich członków swoich zespołów (ranking, wybór osoby, plik z pozycjami faktur); członek zespołu bez zaznaczenia „kierownik zespołu” dalej widzi tylko swoje kampanie. Zespoły nie zmieniają uprawnień z ról — rola mówi, co ktoś może robić, zespół tylko, czyje wyniki kampanii widzi kierownik. Jedna osoba może być w kilku zespołach, a kierownik — kierować kilkoma. Zespół bez kierownika nic nie zmienia. Zarząd nie musi być w zespole: wystarczy mu w roli uprawnienie „Wynik kampanii — wszyscy pracownicy”. Zmiana składu działa od razu i wstecz — nowy kierownik widzi całą historię kampanii członków, a osoba usunięta z zespołu znika z jego raportu.',
          click: 'Zespoły ustawia administrator (uprawnienie „Zarządzanie rolami”): „Administracja” → „Role”, na dole część „Zespoły — kto widzi czyje wyniki kampanii”. „Dodaj zespół” → wpisz nazwę (np. „Handel Kraków”) → zaznacz osoby w zespole i przy kierowniku „kierownik zespołu” (to zaznaczenie samo dopisuje osobę do zespołu) → „Dodaj zespół”. „Zmień” przy zespole otwiera ten sam formularz, „Usuń” usuwa zespół po potwierdzeniu (konta i kampanie zostają). Wyszukiwarka „Szukaj osoby” zawęża listę.',
          tone: 'amber',
          screen: (
            <LivePage
              nav="Administracja"
              path="/admin/roles"
              page={<AdminTeams />}
              allowed={can(user, 'admin.roles.manage')}
              mark="text=Dodaj zespół"
              maxHeight={520}
              fallback={<TeamsSketch />}
            />
          ),
        },
      ]}
    />
  )
}
