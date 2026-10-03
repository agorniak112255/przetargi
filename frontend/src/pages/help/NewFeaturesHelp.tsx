import type { ReactNode } from 'react'
import { useAuth } from '../../auth'
import { can } from '../../lib/api'
import { AdminSystemStatus } from '../AdminSystemStatus'
import { DashboardView, type Dash } from '../Dashboard'
import { AppFrame, Btn, Card, Field, LiveFrame, LivePage, LiveScreen, Mark, Slideshow, Th } from './kit'

/**
 * Samouczek „Nowości (październik 2026)”: godzina składania i numer ogłoszenia, wynik przetargu i pobieranie wyniku
 * z Biuletynu, raport skuteczności, powiadomienia i wzmianki „@”, „Do zrobienia dziś”, stan systemu.
 * Ekrany to rysunki poglądowe z przykładowymi danymi (poza dashboardem i stanem systemu — tam prawdziwy widok).
 */

/** Dzień YYYY-MM-DD przesunięty o n dni od dziś — przykład zawsze „aktualny”. */
function day(n: number): string {
  const d = new Date()
  d.setDate(d.getDate() + n)
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

function sampleDashboard(): Dash {
  return {
    todo: {
      items: [
        {
          kind: 'tender_deadline',
          tender_id: 1,
          number: 'PRZ/2026/0142',
          notice_number: '2026/BZP 00431178/01',
          title: 'Dostawa odzieży i obuwia roboczego',
          client: 'Szpital Wojewódzki nr 2',
          deadline: day(2),
          deadline_time: '10:00',
          missing: { without_product: 6, without_price: 3 },
          url: '/tenders',
        },
        { kind: 'inquiries_waiting', count: 3, oldest: { id: 1, client: 'Ciepłownia Wisłok', since: new Date(Date.now() - 2 * 86400000).toISOString() }, url: '/inquiries?status=waiting' },
        { kind: 'tender_result_needed', tender_id: 2, number: 'PRZ/2026/0120', title: 'Rękawice ochronne', client: 'Zakład Energetyczny', deadline: day(-3), url: '/tenders' },
        { kind: 'mention', notification_id: 'x', title: 'Piotr Wiśniewski wspomniał o Tobie w komentarzu', body: '„Sprawdź, czy ten model ma podnosek kompozytowy”', url: '/tenders', created_at: new Date().toISOString() },
      ],
      won_90d: { won_lots: 16, decided_lots: 39 },
    },
    tenders: {
      active: 14,
      value_net: 1284300,
      avg_margin_percent: 17.8,
      deadline_soon: 2,
      stages: [
        { status: 'wycena', count: 6, value_net: 512000 },
        { status: 'zatwierdzona', count: 3, value_net: 402300 },
      ],
      upcoming: [
        { id: 1, number: 'PRZ/2026/0142', title: null, client: 'Szpital Wojewódzki nr 2', status: 'wycena', deadline: day(2), deadline_time: '10:00' },
        { id: 3, number: 'PRZ/2026/0145', title: null, client: 'Gmina Zielony Dół', status: 'wycena', deadline: day(6), deadline_time: null },
      ],
    },
    products: null,
    stock: null,
    prices: null,
    campaigns: null,
  }
}

function Row({ children }: { children: ReactNode }) {
  return <tr className="border-b border-slate-100 last:border-0">{children}</tr>
}

function Td({ children, className = '' }: { children?: ReactNode; className?: string }) {
  return <td className={`p-2 ${className}`}>{children}</td>
}

function Pill({ tone, children }: { tone: 'ok' | 'bad' | 'muted'; children: ReactNode }) {
  const cls = { ok: 'bg-emerald-50 text-emerald-800', bad: 'bg-red-50 text-red-700', muted: 'bg-slate-100 text-slate-700' }[tone]
  return <span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${cls}`}>{children}</span>
}

function SystemStatusSketch() {
  return (
    <AppFrame nav="Pomoc">
      <h3 className="mb-2 text-sm font-semibold">Stan systemu</h3>
      <div className="mb-3 grid grid-cols-2 gap-2 lg:grid-cols-4">
        {[
          ['Zadania nocne', '11 z 11', 'ostatnie dziś 6:30'],
          ['Konta dostawców', '36 z 37', '1 nie działa'],
          ['Zapytania czekające na analizę', '2', 'najdłużej czeka 40 sekund'],
          ['Dopasowywanie produktów', 'działa', 'odpowiedź średnio po 4 sekundach'],
        ].map(([l, v, s]) => (
          <Card key={l} className="p-3">
            <div className="text-[10px] text-slate-500 uppercase">{l}</div>
            <div className="text-lg font-semibold">{v}</div>
            <div className="text-[11px] text-slate-500">{s}</div>
          </Card>
        ))}
      </div>
      <Card className="p-3 text-xs">
        <div className="mb-1 font-semibold">Wymaga uwagi</div>
        <table className="w-full text-left">
          <thead>
            <tr className="border-b">
              <Th>Co</Th>
              <Th>Od kiedy</Th>
              <Th>E-mail do administratora</Th>
              <Th />
            </tr>
          </thead>
          <tbody>
            <Row>
              <Td>Konto Portwest · pobieranie cen</Td>
              <Td>1.10.2026, 4:00</Td>
              <Td>wysłany 1.10, 4:04</Td>
              <Td>
                <Mark>
                  <Btn label="Wycisz e-mail" color="border" />
                </Mark>
              </Td>
            </Row>
          </tbody>
        </table>
      </Card>
    </AppFrame>
  )
}

export function NewFeaturesHelp() {
  const { user } = useAuth()

  return (
    <Slideshow
      title="Nowości: wynik przetargu, powiadomienia, stan systemu"
      slides={[
        {
          action: 'Godzina składania i numer ogłoszenia',
          does: 'Przy przetargu oprócz daty wpisujesz godzinę składania ofert (czas polski) i numer ogłoszenia — z Biuletynu Zamówień Publicznych, na przykład 2026/BZP 00431178/01, albo z Dziennika Urzędowego Unii Europejskiej (TED), na przykład 606345-2026. Termin widać wtedy wszędzie jako „5.10.2026, 10:00”. Po numerze ogłoszenia aplikacja sama znajduje wynik przetargu w Biuletynie.',
          click: 'W przetargu: krok „Termin i narzut” — pola „Godzina” (czas polski) i „Numer ogłoszenia”. Godziny nie da się zapisać bez daty.',
          tone: 'blue',
          screen: (
            <AppFrame nav="Przetargi">
              <Card className="grid max-w-xl gap-3 sm:grid-cols-3">
                <Field label="Termin składania" value="5.10.2026" />
                <Field label="Godzina" value="10:00" mark />
                <Field label="Numer ogłoszenia" value="2026/BZP 00431178/01" mark />
              </Card>
            </AppFrame>
          ),
        },
        {
          action: 'Wynik przetargu',
          does: 'Po terminie składania wpisujesz wynik dla każdej części zamówienia: wygrana, przegrana, unieważniona albo „nie złożyliśmy oferty”. Przy przegranej — kto wygrał, za ile i dlaczego przegraliśmy (cena, nie spełniliśmy wymagania, termin dostawy, błąd formalny, inny). Przetarg bez podziału na części ma jedną część. Z wyników części aplikacja sama liczy wynik całego przetargu: wygrany, częściowo wygrany, przegrany, unieważniony albo bez wyniku.',
          click: 'W przetargu menu po lewej: „Po terminie” → „Wynik przetargu”. Wynik zmienia osoba z uprawnieniem do edycji oferty, która ma dostęp do tego przetargu — także opiekun przetargu potrzebuje tego uprawnienia.',
          tone: 'blue',
          screen: (
            <AppFrame nav="Przetargi">
              <Card className="text-xs">
                <div className="mb-2 font-semibold">Wynik przetargu</div>
                <table className="w-full text-left">
                  <thead>
                    <tr className="border-b">
                      <Th>Część zamówienia</Th>
                      <Th>Wynik</Th>
                      <Th>Wygrała firma</Th>
                      <Th>Cena zwycięzcy</Th>
                      <Th>Powód przegranej</Th>
                    </tr>
                  </thead>
                  <tbody>
                    <Row>
                      <Td>1. Rękawice ochronne</Td>
                      <Td>
                        <Pill tone="ok">wygrana</Pill>
                      </Td>
                      <Td>nasza firma</Td>
                      <Td>48 210,00 zł</Td>
                      <Td>—</Td>
                    </Row>
                    <Row>
                      <Td>2. Obuwie robocze</Td>
                      <Td>
                        <Pill tone="bad">przegrana</Pill>
                      </Td>
                      <Td>Przykładowa Firma sp. z o.o.</Td>
                      <Td>103 024,03 zł</Td>
                      <Td>Cena</Td>
                    </Row>
                  </tbody>
                </table>
              </Card>
            </AppFrame>
          ),
        },
        {
          action: 'Wynik z Biuletynu Zamówień Publicznych',
          does: 'Codziennie rano aplikacja pobiera ogłoszenia o zamówieniu i o wyniku z Biuletynu Zamówień Publicznych i łączy je z przetargami po numerze ogłoszenia. Z ogłoszenia o wyniku wypełnia zwycięzcę, jego cenę, liczbę ofert oraz najniższą i najwyższą cenę. Sama zaznacza tylko wygraną (gdy wygrała nasza firma) i unieważnienie. Gdy wygrała inna firma, „przegrana” i jej powód zaznaczasz Ty. Tego, co wpisałeś ręcznie, Biuletyn nie nadpisuje — przy różnicy pokazuje ostrzeżenie. Gdy ogłoszenie ma kilka części, a w przetargu jest tylko część 1 wpisana wcześniej, aplikacja nie zgaduje, której części dotyczyła oferta: wybierz „Zmień numer części” albo „Tak, startowaliśmy w części 1 ogłoszenia”. Zmiana numeru ogłoszenia na inne postępowanie usuwa dane pobrane z Biuletynu, dlatego może ją zrobić tylko osoba z uprawnieniem do edycji oferty.',
          click: '„Sprawdź w Biuletynie Zamówień Publicznych” w sekcji „Wynik przetargu” — od razu dopasowuje przetarg do ogłoszeń już pobranych przez aplikację. Nowe ogłoszenia aplikacja pobiera z Biuletynu raz dziennie, o 6:30, więc wynik opublikowany w ciągu dnia pojawi się dopiero następnego ranka.',
          tone: 'violet',
          screen: (
            <AppFrame nav="Przetargi">
              <Card className="space-y-2 text-xs">
                <p>Ogłoszenie o wyniku postępowania w Biuletynie Zamówień Publicznych: 2026/BZP 00512240/01</p>
                <p className="text-slate-500">Pola oznaczone „z Biuletynu” aplikacja wypełniła z tego ogłoszenia.</p>
                <Mark>
                  <Btn label="Sprawdź w Biuletynie Zamówień Publicznych" color="border" />
                </Mark>
              </Card>
            </AppFrame>
          ),
        },
        {
          action: 'Skuteczność przetargów',
          does: 'Raport liczy części zamówień z wynikiem: ile wygraliśmy, według opiekuna, najczęstsze powody przegranych, firmy, które wygrywały z nami, i rodzaje towaru. Unieważnione i przetargi bez wpisanego wyniku są pokazane osobno — nie psują procentu wygranych. Okres liczy się po dacie terminu składania. Wynik da się pobrać do pliku dla Excela.',
          click: 'Menu „Raporty”, zakładka „Skuteczność przetargów”; okres „Ostatnie 90 dni” albo „Ten rok”.',
          tone: 'slate',
          screen: (
            <AppFrame nav="Raporty">
              <Card className="text-xs">
                <p className="mb-2 font-semibold">Wygraliśmy 16 z 39 części z wynikiem (41%). Najczęstszy powód przegranej: cena.</p>
                <p className="text-slate-500">Według opiekuna · Powody przegranych · Konkurenci · Rodzaj towaru · Unieważnione i bez wyniku</p>
              </Card>
            </AppFrame>
          ),
        },
        {
          action: 'Powiadomienia i ich ustawienia',
          does: 'Aplikacja przypomina o terminie składania (7 dni, 3 dni, ostatni dzień roboczy przed terminem, a gdy jest godzina — także 3 godziny przed), o wpisaniu wyniku (dzień po terminie, potem co 3 dni), o gotowej analizie zapytania, o odpowiedzi klienta na kampanię i o zaproszeniu do przetargu. Przypomnienie o terminie podaje, czego brakuje w ofercie. Każde powiadomienie przychodzi raz. Dla każdego zdarzenia wybierasz, czy ma przyjść do dzwonka, e-mailem, czy w oba miejsca.',
          click: '„Moje konto” → „Powiadomienia” (albo „Ustawienia” w okienku dzwonka), zaznacz „W dzwonku” i „E-mailem”, potem „Zapisz powiadomienia”.',
          tone: 'blue',
          screen: (
            <AppFrame nav="Pomoc">
              <Card className="text-xs">
                <div className="mb-2 font-semibold">Powiadomienia</div>
                <table className="w-full text-left">
                  <thead>
                    <tr className="border-b">
                      <Th>Zdarzenie</Th>
                      <Th>W dzwonku</Th>
                      <Th>E-mailem</Th>
                    </tr>
                  </thead>
                  <tbody>
                    {[
                      ['Zbliża się termin składania oferty', true, true],
                      ['Trzeba wpisać wynik przetargu', true, false],
                      ['Ktoś wspomniał o Tobie w komentarzu', true, true],
                    ].map(([label, bell, mail]) => (
                      <Row key={String(label)}>
                        <Td>{label}</Td>
                        <Td>{bell ? '☑' : '☐'}</Td>
                        <Td>{mail ? '☑' : '☐'}</Td>
                      </Row>
                    ))}
                  </tbody>
                </table>
                <div className="mt-2">
                  <Mark>
                    <Btn label="Zapisz powiadomienia" />
                  </Mark>
                </div>
              </Card>
            </AppFrame>
          ),
        },
        {
          action: 'Wzmianka „@” w komentarzu',
          does: 'W komentarzu przetargu wpisz „@” i zacznij pisać imię — pojawi się lista osób, które mają dostęp do tego przetargu. Wybrana osoba dostaje powiadomienie z linkiem prosto do komentarzy, a na dashboardzie widzi tę wzmiankę w „Do zrobienia dziś”, dopóki jej nie przeczyta.',
          click: 'Przetarg → „Komentarze”, w polu komentarza „@”, wybór osoby z listy, potem dodanie komentarza.',
          tone: 'blue',
          screen: (
            <AppFrame nav="Przetargi">
              <Card className="max-w-lg text-xs">
                <div className="rounded border border-slate-300 px-2 py-1.5">
                  Sprawdź, czy ten model ma podnosek kompozytowy, <Mark>@Ewa</Mark>
                </div>
                <div className="mt-1 w-56 rounded border border-slate-200 bg-white shadow-sm">
                  <div className="bg-blue-50 px-2 py-1">Ewa Kowalska · opiekun</div>
                  <div className="px-2 py-1">Ewelina Nowak · zaproszona</div>
                </div>
              </Card>
            </AppFrame>
          ),
        },
        {
          action: 'Do zrobienia dziś',
          does: 'Na górze dashboardu lista Twoich pilnych spraw: terminy składania w ciągu 7 dni (z godziną i brakami oferty), zapytania czekające na odpowiedź ponad dobę, przetargi po terminie bez wpisanego wyniku i nieprzeczytane wzmianki. Liczą się tylko Twoje sprawy — przetargi, które prowadzisz albo do których Cię zaproszono. Sprawa znika, gdy zostanie zrobiona. W karcie „Przetargi” nowy kafelek „Wygrane, 90 dni” — procent części wygranych spośród części z wpisanym wynikiem.',
          click: 'Przycisk przy sprawie („Otwórz przetarg”, „Pokaż zapytania”, „Wpisz wynik”, „Odpowiedz”) prowadzi prosto do miejsca, gdzie się ją załatwia.',
          tone: 'green',
          screen: (
            <LiveFrame live={false} label="Dashboard">
              <LiveScreen width={1100}>
                <DashboardView data={sampleDashboard()} stockLink="/zapasy" />
              </LiveScreen>
            </LiveFrame>
          ),
        },
        {
          action: 'Stan systemu (dla administratora)',
          does: 'Jeden ekran pokazuje, czy działają zadania nocne, pobieranie cen z kont dostawców, kolejka analiz zapytań i dopasowywanie produktów. Gdy zadanie nocne albo konto dostawcy przestanie działać, osoby z uprawnieniem „Stan systemu” i dostępem do Administracji dostają jeden e-mail na każdy problem; znany problem można wyciszyć. Godziny zadań są w czasie polskim. Niżej „Dane do uzupełnienia”: przetargi bez godziny składania albo numeru ogłoszenia, handlowcy bez operatora ERP XL, zamawiający bez powiązania z ERP XL i sprzedawane towary bez karty produktu — „Pokaż” rozwija listę.',
          click: '„Administracja” → kafelek „Stan systemu”. „Wycisz e-mail” przy problemie, który jest znany; „Włącz e-mail” cofa wyciszenie.',
          tone: 'amber',
          screen: (
            <LivePage
              nav="Stan systemu"
              path="/admin/stan-systemu"
              page={<AdminSystemStatus />}
              allowed={can(user, 'admin.access') && can(user, 'admin.system.view')}
              fallback={<SystemStatusSketch />}
              mark="text=Wycisz e-mail"
            />
          ),
        },
      ]}
    />
  )
}
