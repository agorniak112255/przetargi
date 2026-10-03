import type { ReactNode } from 'react'
import { useAuth } from '../../auth'
import { can } from '../../lib/api'
import { AdminSystemStatus } from '../AdminSystemStatus'
import { DashboardView, type Dash } from '../Dashboard'
import { AppFrame, Btn, Card, Field, LiveFrame, LivePage, LiveScreen, Mark, Slideshow, Th } from './kit'

/**
 * Samouczek „Nowości (październik 2026)”. Ogłoszenia z Biuletynu Zamówień Publicznych. Etapy 3–4: kalendarz terminów i subskrypcja w programie pocztowym,
 * wyszukiwanie Ctrl+K, karta klienta i notatki, powiązanie zapytania z klientem, wynik zapytania i podpowiedź z ERP XL,
 * ważność oferty, cele handlowców i przypisanie pracownika ERP XL; „Czego jeszcze nie ma”. Etapy 0–1: godzina składania
 * i numer ogłoszenia, wynik przetargu i pobieranie wyniku z Biuletynu, raport skuteczności, powiadomienia i wzmianki
 * „@”, „Do zrobienia dziś”, stan systemu.
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
        { kind: 'offer_validity_ending', inquiry_id: 7, client: 'Ciepłownia Wisłok', subject: 'Zapytanie o rękawice nitrylowe', valid_until: day(1), has_hint: false, url: '/inquiries' },
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

/** Szkic listy ogłoszeń (przykładowe dane). */
function NoticesSketch() {
  return (
    <AppFrame nav="Ogłoszenia">
      <h3 className="mb-2 text-sm font-semibold">Ogłoszenia</h3>
      <Card className="text-xs">
        <div className="mb-2 flex flex-wrap gap-1">
          <span className="rounded-full border border-slate-300 px-2 py-0.5">Wszystkie</span>
          <span className="rounded-full bg-blue-600 px-2 py-0.5 text-white">Obuwie</span>
          <span className="rounded-full border border-slate-300 px-2 py-0.5">Rękawice</span>
        </div>
        <div className="mb-2 flex gap-3 border-b border-slate-200 pb-1">
          <span className="font-semibold text-blue-700">Nowe 7</span>
          <span className="text-slate-500">Założone jako przetarg 12</span>
          <span className="text-slate-500">Pominięte 31</span>
        </div>
        <table className="w-full text-left">
          <thead>
            <tr className="border-b">
              <Th>Zamawiający i przedmiot</Th>
              <Th>Termin składania</Th>
              <Th>Czego dotyczy</Th>
              <Th>Dokumenty</Th>
              <Th />
            </tr>
          </thead>
          <tbody>
            <Row>
              <Td>
                <b>Zarząd Dróg Powiatowych w Dolinie</b>
                <div>Dostawa odzieży roboczej i obuwia dla pracowników drogowych</div>
                <div className="text-[11px] text-slate-500">Biuletyn Zamówień Publicznych · 2026/BZP 00462011/01 · 2 części</div>
              </Td>
              <Td>13.10.2026, 10:00</Td>
              <Td>Obuwie, Odzież robocza i ochronna</Td>
              <Td>
                <span className="text-blue-700">strona postępowania ↗</span>
              </Td>
              <Td>
                <Mark>
                  <Btn label="Szczegóły" color="border" />
                </Mark>{' '}
                <Btn label="Załóż przetarg" /> <Btn label="Pomiń" color="border" />
              </Td>
            </Row>
          </tbody>
        </table>
      </Card>
    </AppFrame>
  )
}

/** Szkic panelu szczegółów ogłoszenia (przykładowe dane). */
function NoticeDetailsSketch() {
  return (
    <AppFrame nav="Ogłoszenia">
      <Card className="max-w-xl space-y-2 text-xs">
        <div className="font-semibold">Zarząd Dróg Powiatowych w Dolinie</div>
        <div>Dostawa odzieży roboczej i obuwia dla pracowników drogowych</div>
        <div className="text-slate-500">Termin składania ofert: 13.10.2026, 10:00 (czas polski)</div>
        <div className="rounded border border-slate-200 px-2 py-1">
          <b>Część 1: Odzież robocza</b>
          <div className="text-slate-600">Kurtka ostrzegawcza zgodna z normą EN ISO 20471, klasa 2 — 120 sztuk…</div>
        </div>
        <div className="rounded border border-slate-200 px-2 py-1">▾ Wadium · ▸ Warunki udziału w postępowaniu</div>
        <div className="font-semibold">Dokumenty postępowania</div>
        <div>☑ Załącznik nr 2 — Opis przedmiotu zamówienia</div>
        <div>☑ Załącznik nr 1 — Formularz ofertowy</div>
        <div>☐ Specyfikacja warunków zamówienia</div>
        <Mark>
          <Btn label="Załóż przetarg z pozycjami" />
        </Mark>
      </Card>
    </AppFrame>
  )
}

/** Etapy 3–4: kalendarz terminów, wyszukiwanie, karta klienta, wynik zapytania, cele handlowców. */
function Stage34Slideshow() {
  return (
    <Slideshow
      title="Nowości: ogłoszenia, kalendarz, wyszukiwanie, karta klienta, wynik zapytania, cele handlowców"
      slides={[
        {
          action: 'Ogłoszenia przetargowe',
          does: 'Nowa pozycja menu „Ogłoszenia” (zaraz pod „Przetargi”, dla osób, które mogą zakładać przetargi albo widzą wszystkie przetargi). Lista pokazuje ogłoszenia o zamówieniu z Biuletynu Zamówień Publicznych z kodami rodzaju zamówienia (CPV) na odzież, obuwie i środki ochrony: zamawiającego, przedmiot, termin składania ofert w czasie polskim, czego dotyczy, wartość (gdy ogłoszenie ją podaje) i odnośniki do strony postępowania i do ogłoszenia w Biuletynie. Aplikacja sprawdza Biuletyn codziennie o 6:30, więc ogłoszenie opublikowane w ciągu dnia pojawi się następnego ranka. Dziennik Urzędowy Unii Europejskiej (TED) nie jest pobierany, a zamówienia poniżej 130 000 zł nie mają wspólnego źródła, więc ich tu nie ma. Ogłoszenie nie zawiera listy pozycji — jest w dokumentach postępowania (zobacz następny slajd: „Szczegóły ogłoszenia i przetarg z pozycjami”). Zakładki: „Nowe” (bez decyzji, domyślnie tylko z terminem składania w przyszłości albo bez podanego terminu), „Założone jako przetarg” (z odnośnikiem do przetargu, gdy masz do niego dostęp) i „Pominięte” (kto i kiedy pominął). „Pominięte” jest wspólne dla zespołu i dotyczy całego postępowania: ogłoszenie pominięte przez jedną osobę znika z „Nowe” u wszystkich i nie wraca, gdy Biuletyn opublikuje jego nową wersję (np. ze zmienionym terminem); „Przywróć” je cofa. „Załóż przetarg” pokazuje najpierw, co zostanie wypełnione: tytuł z przedmiotu zamówienia, termin składania z godziną, numer ogłoszenia i zamawiający. Zamawiającego aplikacja dobiera tak: najpierw po NIP-ie (gdy w zakładce Klienci jest dokładnie jeden klient z tym NIP-em; gdy jest ich kilku — ten z tą samą nazwą), potem po nazwie (gdy dokładnie jeden pasuje). Gdy pasuje kilku klientów, aplikacja nie zgaduje i nie dopisuje nowego — w oknie wybierasz klienta z listy, a dopiero potem „Załóż przetarg” staje się aktywne. Gdy żaden nie pasuje — dopisuje nowego klienta z nazwą, NIP-em i miastem z ogłoszenia. Przetarg dostaje dane z najnowszej wersji ogłoszenia postępowania. Powstaje jako szkic, Ty jesteś opiekunem i otwiera się kreator. Gdy ktoś już założył przetarg z tym postępowaniem, drugi nie powstaje — okno pokazuje numer istniejącego przetargu i przycisk przejścia do niego, jeśli masz do niego dostęp.',
          click: 'Menu „Ogłoszenia” → wybór rodzaju zamówienia, województwa albo wpisanie szukanego słowa → „Szczegóły” (albo kliknięcie przedmiotu zamówienia), żeby przeczytać ogłoszenie i wybrać dokumenty → „Załóż przetarg” → sprawdzenie, co zostanie wypełnione → „Załóż przetarg” w oknie. Ogłoszenie, które Was nie interesuje — „Pomiń”.',
          tone: 'blue',
          screen: <NoticesSketch />,
        },
        {
          action: 'Szczegóły ogłoszenia i przetarg z pozycjami',
          does: '„Szczegóły” przy ogłoszeniu (albo kliknięcie przedmiotu zamówienia) otwiera panel z prawej. U góry: zamawiający, przedmiot, termin składania ofert, wartość z ogłoszenia, czego dotyczy i odnośniki. Niżej części zamówienia z opisami i kodami rodzaju zamówienia (CPV) oraz sekcje ogłoszenia (przedmiot zamówienia, terminy, wadium, warunki udziału, kryteria oceny ofert, kontakt) — tekst słowo w słowo z Biuletynu, bez skracania i bez wniosków aplikacji; przedmiot, terminy i wadium są rozwinięte, resztę rozwijasz kliknięciem. Pełną treść ogłoszenia, z którego nie założono przetargu, aplikacja przechowuje 30 dni — potem panel pokazuje tylko części i ich opisy i mówi o tym wprost. Dokumenty: gdy postępowanie jest na platformie e-Zamówień, panel pokazuje listę dokumentów z polami wyboru. Na start zaznaczone są te, które z nazwy wyglądają na opis przedmiotu zamówienia albo formularz cenowy lub ofertowy — rodzaj to tylko podpowiedź z nazwy pliku, sprawdź ją; specyfikację warunków zamówienia (SWZ) zaznaczasz sam, gdy to w niej jest lista pozycji. Wybór dokumentów wymaga uprawnienia do zakładania przetargów i uprawnienia „Dodawanie dokumentów” (bez niego przetarg powstaje bez dokumentów). Dlaczego z innych platform trzeba pobrać dokumenty ręcznie: aplikacja pobiera je sama tylko z e-Zamówień, które publikują listę dokumentów publicznie; inne platformy (na przykład platformazakupowa.pl) zabraniają pobierania plików przez automat. Wtedy pobierz dokumenty przez „strona postępowania ↗” i przeciągnij je do panelu (PDF, Excel, CSV albo Word, do 50 MB na plik; archiwum ZIP najpierw rozpakuj). Gdy ogłoszenie samo wymienia towary i ilości (na przykład „hełm strażacki – 23 szt.” w opisie części), kreator po założeniu przetargu bez dokumentów sam odczytuje je modelem z treści ogłoszenia; w kroku „Dokumenty” jest też przycisk „Odczytaj pozycje z treści ogłoszenia” — działa także w przetargu założonym wcześniej. Gdy przetarg nie ma jeszcze pozycji, towary BHP z ilością podaną w ogłoszeniu i cytatem znalezionym w ogłoszeniu aplikacja dodaje do przetargu sama, bez klikania (ocena „BHP” to ocena modelu — sprawdź pozycje w zakładce Pozycje); historia przetargu notuje każdy dodany towar z cytatem z ogłoszenia. Pozostałe towary zostają w podglądzie: spoza BHP (na przykład agregat, radiotelefon) z dopiskiem „poza BHP”, bez cytatu w ogłoszeniu z dopiskiem „nie znaleziono w treści ogłoszenia”, a bez ilości w cytacie — z dopiskiem „ilość nie podana w ogłoszeniu” (ilości aplikacja nie zgaduje); dodajesz je przyciskiem „Dodaj do przetargu”. Gdy przetarg ma już pozycje, nic nie dodaje się samo — wszystko jest w podglądzie. „Załóż przetarg z pozycjami” zakłada przetarg tak samo jak „Załóż przetarg” (to samo okno z zamawiającym) i otwiera kreator w kroku „Dokumenty” z ramką „Dokumenty z ogłoszenia … czekające na odczyt”. Kreator sam pobiera i odczytuje pierwszy dokument (najpierw te z e-Zamówień, potem pliki z komputera); kolejne odczytujesz przyciskiem przy dokumencie („Pobierz i odczytaj” albo „Odczytaj”), gdy skończysz z poprzednim podglądem. Dokumenty z e-Zamówień zostają w archiwum dokumentów przetargu z dopiskiem „z e-Zamówień ↗” (odnośnik do dokumentu na platformie); pliki z komputera — tak jak przy zwykłym wgraniu (przy ustawieniu odczytu „tylko tekst” zostaje tylko plik Word). Historia przetargu notuje każdy plik zapisany w archiwum i skąd pochodzi. Gdy pobranie z e-Zamówień się nie uda, przy dokumencie jest powód i „Spróbuj ponownie” — możesz też pobrać plik ze strony postępowania i przeciągnąć go w kreatorze. Nic nie trafia do przetargu samo — pozycje i warunki dodaje dopiero Twoje „Dodaj do przetargu” pod podglądem. Pliki przeciągnięte z komputera są tylko w pamięci karty przeglądarki: zamknięcie panelu z wybranymi plikami pyta o potwierdzenie, a po odświeżeniu strony kreatora pliki jeszcze nieodczytane trzeba przeciągnąć ponownie.',
          click: 'Menu „Ogłoszenia” → „Szczegóły” → przeczytaj części i sekcje → zaznacz dokumenty z e-Zamówień albo przeciągnij pliki pobrane ze strony postępowania → „Załóż przetarg z pozycjami” → „Załóż przetarg” w oknie → w kreatorze sprawdź podgląd i kliknij „Dodaj do przetargu”. Escape albo „Zamknij” zamyka panel.',
          tone: 'blue',
          screen: <NoticeDetailsSketch />,
        },
        {
          action: 'Kalendarz terminów',
          does: 'Lista przetargów ma przełącznik „Lista” / „Kalendarz”. Kalendarz pokazuje cały miesiąc (od poniedziałku do niedzieli) z terminami składania ofert — z godziną w czasie polskim, gdy jest wpisana — i z wpisem „Wpisz wynik:” przy przetargach po terminie bez wpisanego wyniku (najwyżej 60 dni po terminie). Kolory z legendy: niebieski — „wycena gotowa” (każda pozycja ma produkt i cenę), bursztynowy — „wycena w toku” (są braki, termin dalej niż za 3 dni), czerwony — „braki, termin blisko” (brakuje produktu albo ceny albo nie ma żadnej pozycji, a termin jest dziś lub w ciągu 3 dni), biały z przerywaną ramką — „do zrobienia: wpisz wynik”, szary — „zamknięte” (oferta wysłana, przetarg w archiwum, odrzucony albo z wpisanym wynikiem). Widać tylko przetargi, do których masz dostęp, i tylko te z wpisaną datą terminu składania. Lista obok przełącznika zawęża kalendarz: „Tylko te, których jestem opiekunem” albo „Tylko zaproszenia” (pozostałe filtry listy dotyczą tylko widoku „Lista”).',
          click: 'Menu „Przetargi” → przełącznik „Kalendarz”. Strzałki obok nazwy miesiąca zmieniają miesiąc, „Bieżący miesiąc” wraca do dzisiejszego. Najechanie na przetarg pokazuje szczegóły i braki, kliknięcie otwiera przetarg.',
          tone: 'blue',
          screen: (
            <AppFrame nav="Przetargi">
              <div className="mb-2 flex items-center gap-2 text-xs">
                <span className="rounded border border-slate-300 px-2 py-0.5">Lista</span>
                <Mark>
                  <span className="rounded border border-blue-300 bg-blue-50 px-2 py-0.5 font-semibold text-blue-800">Kalendarz</span>
                </Mark>
              </div>
              <Card className="text-xs">
                <div className="mb-1 grid grid-cols-5 gap-1 text-[10px] text-slate-500">
                  {['Poniedziałek', 'Wtorek', 'Środa', 'Czwartek', 'Piątek'].map((d) => (
                    <span key={d}>{d}</span>
                  ))}
                </div>
                <div className="grid grid-cols-5 gap-1">
                  <div className="rounded border-l-4 border-blue-500 bg-blue-50 p-1 text-blue-900">10:00 Szpital nr 2</div>
                  <div className="rounded border-l-4 border-red-500 bg-red-50 p-1 text-red-800">12:00 Gmina Zielony Dół</div>
                  <div className="rounded border-l-4 border-amber-500 bg-amber-50 p-1 text-amber-900">Wodociągi</div>
                  <div className="rounded border border-l-4 border-dashed border-slate-500 bg-white p-1 font-semibold text-slate-900">Wpisz wynik: Zakład</div>
                  <div className="rounded border-l-4 border-slate-300 bg-slate-100 p-1 text-slate-600">Powiat</div>
                </div>
              </Card>
            </AppFrame>
          ),
        },
        {
          action: 'Terminy w Outlooku i Thunderbirdzie',
          does: 'Terminy składania mogą trafić do Twojego kalendarza w Outlooku albo Thunderbirdzie jako subskrypcja. Aplikacja tworzy osobisty, tajny adres — widać go tylko raz, zaraz po utworzeniu; po zamknięciu okna nie da się go odczytać, można tylko wygenerować nowy. Zakres do wyboru: „Moje — przetargi, które prowadzę, i te, do których mnie zaproszono” albo — tylko z uprawnieniem do wszystkich przetargów — „Wszystkie przetargi w aplikacji”; zmiana zakresu wymaga nowego adresu. W kalendarzu jest numer, tytuł, zamawiający i link do przetargu, bez cen. Termin z godziną to wydarzenie o tej godzinie (czas polski), trwające 30 minut; termin bez godziny zajmuje cały dzień. Obejmuje terminy od 90 dni wstecz do roku naprzód, bez odrzuconych przetargów. Nowy albo przesunięty termin program pocztowy pobiera sam, według własnego harmonogramu — może się pojawić z opóźnieniem (w Outlooku nawet kilka godzin). Dopóki adres jest widoczny, zamknięcie okna klawiszem Escape albo kliknięciem obok okna pyta najpierw o potwierdzenie, żeby adres nie przepadł przez pomyłkę. „Wygeneruj nowy adres” unieważnia poprzedni od razu, a „Wyłącz” unieważnia adres bez tworzenia nowego — użyj, gdy adres trafił do niewłaściwej osoby. Gdy stracisz dostęp do przetargów w aplikacji, kalendarz przestanie się aktualizować.',
          click: 'Menu „Przetargi” → przycisk „Dodaj terminy do mojego kalendarza” (u góry, przy liście i przy kalendarzu) → wybór zakresu → „Utwórz adres kalendarza” → „Kopiuj”. W Outlooku: otwórz Kalendarz, „Dodaj kalendarz” → „Z internetu”, wklej adres i potwierdź. W Thunderbirdzie: otwórz Kalendarz, „Nowy kalendarz” → „W sieci”, wklej adres i zasubskrybuj znaleziony kalendarz.',
          tone: 'violet',
          screen: (
            <AppFrame nav="Przetargi">
              <Card className="max-w-xl space-y-2 text-xs">
                <div className="font-semibold">Dodaj terminy do mojego kalendarza</div>
                <p className="text-slate-600">Adres kalendarza</p>
                <div className="rounded border border-slate-300 bg-slate-50 px-2 py-1 font-mono">https://…/api/calendar/••••••••.ics</div>
                <p className="text-slate-500">Adres widać tylko teraz. Po zamknięciu tego okna nie da się go odczytać — można tylko wygenerować nowy.</p>
                <div className="flex gap-2">
                  <Btn label="Kopiuj" color="border" />
                  <Mark>
                    <Btn label="Wygeneruj nowy adres" color="border" />
                  </Mark>
                  <Btn label="Wyłącz" color="border" />
                </div>
              </Card>
            </AppFrame>
          ),
        },
        {
          action: 'Jedno pole wyszukiwania (Ctrl+K)',
          does: 'Szuka naraz w produktach (jak lista Produkty: nazwa, kod produktu, producent, numer modelu, marka, a także kod towaru z ERP XL), przetargach (numer, numer ogłoszenia, tytuł, zamawiający; od 3 znaków także treść pozycji przetargów spoza archiwum), zapytaniach (temat, nadawca, adres e-mail, firma z podpisu; treść maila tylko z ostatnich 90 dni) i klientach (nazwa, skrót nazwy, NIP, miasto). Pokazuje do 5 wyników w grupie. Gdy jest ich więcej, w produktach link „Pokaż wszystkie: produkty” otwiera listę Produkty z tą samą frazą. W zapytaniach „Pokaż wszystkie: zapytania” otwiera listę Zapytania z tą samą frazą — z uprawnieniem do listy wszystkich zapytań od razu w widoku „Wszystkie”, bez niego w widoku „Moje”. Kto może otwierać cudze zapytania, ale nie ma listy wszystkich zapytań, linku nie dostaje (lista nie pokazałaby tych samych wyników) — wtedy, jak w przetargach i klientach, trzeba wpisać dokładniejszą frazę. Każda grupa pokazuje się tylko z uprawnieniem do swojego modułu; zapytania — Twoje, a cudze tylko z uprawnieniem do otwierania zapytań innych osób. Wyniki nie pokazują cen. Stan magazynu z ostatniego nocnego odczytu ERP XL widać tylko z uprawnieniem do Zapasów.',
          click: 'Ctrl+K (na Macu Cmd+K) albo przycisk „Szukaj (Ctrl+K)” w menu po lewej, nad „Moje konto”. Wpisz co najmniej 2 znaki; strzałki wybierają wynik, Enter go otwiera (tak samo jak kliknięcie — gdy na stronie są niezapisane zmiany, aplikacja najpierw zapyta, czy wyjść), Escape zamyka tylko okno wyszukiwania. Klawisz Tab nie wychodzi poza okno — fokus zostaje w polu wyszukiwania.',
          tone: 'blue',
          screen: (
            <AppFrame nav="Pomoc">
              <Card className="max-w-xl text-xs">
                <div className="mb-2 rounded border border-blue-300 px-2 py-1.5">rękawice nitrylowe</div>
                <div className="text-[10px] font-semibold text-slate-500 uppercase">Produkty</div>
                <div className="rounded bg-slate-100 px-2 py-1">Rękawice nitrylowe Nitrile Pro · kod RN-100</div>
                <div className="px-2 py-1">Rękawice nitrylowe długie · kod RN-300</div>
                <div className="mt-1 text-[10px] font-semibold text-slate-500 uppercase">Zapytania</div>
                <div className="px-2 py-1">Zapytanie o rękawice · Ciepłownia Wisłok</div>
              </Card>
            </AppFrame>
          ),
        },
        {
          action: 'Karta klienta',
          does: 'Kliknięcie wiersza na liście Klienci otwiera kartę klienta. U góry: „Opiekun w ERP XL”, „Opiekun w aplikacji” i „Sprzedaż liczy się do celów” (komu klient liczy się do celu sprzedaży). Kafelki: „Zakupy netto” w tym roku, „Ostatni zakup” i „Ostatnie 12 miesięcy” (zapytania, przetargi, faktury i paragony). Niżej „Historia współpracy” — ostatnie 24 miesiące, z filtrami „Wszystko”, „Faktury”, „Zapytania”, „Przetargi”, „Kampanie”, „Notatki” — a z boku „Dodaj notatkę” i „Najczęściej kupuje (24 miesiące)”. Faktury i paragony pochodzą z nocnego odczytu ERP XL (codziennie o 5:40) — dzisiejsze dokumenty pojawią się następnego dnia. Każda część karty pokazuje się tylko z uprawnieniem do swojego modułu. Na karcie są tylko zapytania pewnie powiązane z klientem: ten sam adres e-mail co w ERP XL, NIP z maila albo wybór handlowca (zasady na slajdzie „Zapytanie a klient”) — nigdy po samej domenie adresu. Zapytanie, które handlowiec oznaczył „Bez klienta”, na karcie się nie pojawi. Przy fakturze, którą handlowiec potwierdził jako zamówienie z zapytania, jest data tego zapytania — jako link tylko wtedy, gdy możesz to zapytanie otworzyć (Twoje albo z uprawnieniem do otwierania zapytań innych osób). Zaległych płatności karta nie pokazuje (zobacz „Czego jeszcze nie ma”).',
          click: 'Menu „Klienci” → kliknij wiersz klienta (albo jego nazwę). „Dane klienta i osoby kontaktowe” rozwija dane z ERP XL. Opiekuna w aplikacji zmienia przyciskiem „Zmień” tylko osoba z uprawnieniem do zarządzania klientami.',
          tone: 'green',
          screen: (
            <AppFrame nav="Klienci">
              <div className="mb-2 text-sm font-semibold">Ciepłownia Wisłok S.A.</div>
              <div className="mb-2 grid grid-cols-3 gap-2 text-xs">
                <Card className="p-2">
                  Zakupy netto 2026
                  <b className="block text-base">186 420 zł</b>
                </Card>
                <Card className="p-2">
                  Ostatni zakup
                  <b className="block text-base">22.09.2026</b>
                </Card>
                <Card className="p-2">
                  Ostatnie 12 miesięcy
                  <b className="block text-base">9 zapytań · 2 przetargi</b>
                </Card>
              </div>
              <Card className="text-xs">
                <Mark>
                  <span>Wszystko · Faktury · Zapytania · Przetargi · Kampanie · Notatki</span>
                </Mark>
              </Card>
            </AppFrame>
          ),
        },
        {
          action: 'Notatki o kliencie z przypomnieniem',
          does: 'Na karcie klienta każda osoba z dostępem do kart klientów może dodać notatkę — na przykład po rozmowie telefonicznej. Notatki widzą wszyscy, którzy mają dostęp do kart klientów, więc nie wpisuj w nich niczego prywatnego. Przy notatce można ustawić przypomnienie („Przypomnij mi”): „nie przypominaj”, „za tydzień” albo „w wybrany dzień”. Przypomnienie przychodzi tylko do autora notatki, w wybranym dniu od 7:00 — w dzwonku i e-mailem (kanały zmienisz w „Moje konto” → „Powiadomienia”). Gdy w tym dniu przypomnienie nie wyszło (na przykład serwer nie działał), aplikacja nadrabia je najwyżej 7 dni później; starsze przepadają. Notatkę zmienia („Zmień”) albo usuwa („Usuń”) jej autor albo osoba z uprawnieniem do zarządzania klientami; zmiana dnia przypomnienia ustawia przypomnienie od nowa.',
          click: 'Karta klienta → po prawej „Dodaj notatkę” → wpisz treść, wybierz „Przypomnij mi” → „Zapisz notatkę”. Zapisane notatki są w historii współpracy, w filtrze „Notatki”.',
          tone: 'green',
          screen: (
            <AppFrame nav="Klienci">
              <Card className="max-w-xl space-y-2 text-xs">
                <div className="font-semibold">Dodaj notatkę</div>
                <div className="rounded border border-slate-300 px-2 py-1.5">Rozmowa z zaopatrzeniem: w listopadzie przetarg na odzież zimową.</div>
                <div>
                  <div className="mb-1 text-slate-600">Przypomnij mi</div>
                  <Mark>
                    <span className="rounded border border-slate-300 bg-white px-2 py-0.5">za tydzień</span>
                  </Mark>
                </div>
                <Btn label="Zapisz notatkę" />
              </Card>
            </AppFrame>
          ),
        },
        {
          action: 'Zapytanie a klient',
          does: 'Zapytanie łączy się z klientem tylko wtedy, gdy powiązanie jest pewne: (1) handlowiec sam wybrał klienta — tego automat nigdy nie zmienia; (2) adres nadawcy jest dokładnie taki sam jak adres e-mail z karty klienta albo osoby kontaktowej w ERP XL i należy tylko do jednego klienta; (3) w treści maila jest NIP przy słowie „NIP”, z poprawną sumą kontrolną, jedyny taki w mailu, nie nasz, i pasuje do dokładnie jednego klienta. Gdy adres e-mail i NIP wskazują różnych klientów — powiązania nie ma. Sama domena adresu nigdy nie wystarcza. Powiązania automatyczne aplikacja przelicza co noc (o 5:55) — gdy dopasowanie przestanie się zgadzać, powiązanie automatyczne znika. Przy kliencie w zapytaniu widać w nawiasie, skąd się wzięło: „ten sam adres e-mail co w ERP XL”, „NIP z maila” albo „wybrane przez handlowca”. Tylko takie pewne zapytania pokazują się na karcie klienta.',
          click: 'W zapytaniu, pod tematem (tylko autor zapytania): „Powiąż z klientem”, gdy powiązania nie ma, albo „Zmień klienta”, gdy jest błędne — klienta wybiera się z listy Klienci, więc potrzebny jest do niej dostęp. „Bez klienta” zostawia zapytanie świadomie bez klienta — pod tematem widać wtedy „Bez klienta — wybór handlowca; nocne powiązanie tego nie zmieni”, a panel wyniku: „Wybrano „Bez klienta” — podpowiedzi nie ma”. Przy takim zapytaniu jest „Powiąż z klientem”, gdyby trzeba było jednak wybrać klienta. Zmiana klienta (ręczna albo nocna) usuwa podpowiedzi z ERP XL policzone dla poprzedniego klienta i zdejmuje z wyniku dokument z ERP XL; sam wynik i powód zostają.',
          tone: 'slate',
          screen: (
            <AppFrame nav="Zapytania">
              <Card className="max-w-xl text-xs">
                Klient: <b>Ciepłownia Wisłok S.A.</b> <span className="text-slate-500">(ten sam adres e-mail co w ERP XL)</span>
                <div className="mt-2 flex gap-2">
                  <Mark>
                    <Btn label="Zmień klienta" color="border" />
                  </Mark>
                  <Btn label="Bez klienta" color="border" />
                </div>
              </Card>
            </AppFrame>
          ),
        },
        {
          action: 'Jak się skończyło zapytanie',
          does: 'Wynik wpisuje tylko autor zapytania (osoba, która je prowadzi) i dopiero po wysłaniu odpowiedzi do klienta: „Zamówił”, „Zamówił część”, „Nie zamówił” albo „Nie wiadomo”; przy „Zamówił część” i „Nie zamówił” można podać powód: „Cena”, „Termin dostawy”, „Kupił gdzie indziej” albo „Klient nie odpowiedział”. Widać, kto i kiedy wpisał wynik. Na liście zapytań jest kolumna „Wynik” i filtr „Wynik” (także „Wysłane, wynik niewpisany”). Podpowiedź z ERP XL: co noc (o 5:55) aplikacja sprawdza, czy klient dostał fakturę albo paragon na zaoferowany towar w ciągu 60 dni od dnia odpowiedzi. Liczą się tylko towary oferty, których karta produktu jest powiązana z towarem w ERP XL (wybrane karty i zatwierdzone zamienniki); ile towarów oferty nie ma takiego powiązania, podpowiedź mówi osobno — ich zakupu nie widać. To wniosek, a nie fakt: klient mógł kupić z innego powodu, dlatego podpowiedź jest szara, z regułą słowami, numerem, datą i wartością dokumentu oraz liczbą trafionych towarów. Aplikacja nigdy nie wpisuje wyniku sama. Podpowiedzi nie ma, gdy zapytanie nie jest pewnie powiązane z klientem z ERP XL — panel mówi wtedy dlaczego.',
          click: 'W zapytaniu po wysłaniu odpowiedzi, panel „Jak się skończyło”: wybierz wynik (i ewentualnie powód), potem „Zapisz”. Przy podpowiedzi „Potwierdź ten dokument” tylko wskazuje dokument (i zaznacza „Zamówił”, jeśli nic o zakupie nie wybrano) — numer, data i wartość dokumentu zapiszą się w wyniku dopiero po „Zapisz”. „Wyczyść wynik” cofa zapytanie do „wysłane, wynik niewpisany”.',
          tone: 'amber',
          screen: (
            <AppFrame nav="Zapytania">
              <Card className="max-w-xl space-y-2 text-xs">
                <div className="font-semibold">Jak się skończyło</div>
                <div className="rounded border border-slate-200 bg-slate-50 p-2 text-slate-600">
                  <div className="font-semibold text-slate-700">Podpowiedź z ERP XL</div>
                  22.09.2026 wystawiono temu klientowi dokument <b>FS-1842/09/2026</b> na 3 z 5 zaoferowanych towarów, 6 240,00 zł netto (w tym towary z
                  oferty: 4 180,00 zł). Możliwe, że to zamówienie z tej oferty. Sprawdź i potwierdź.
                  <div className="mt-1">
                    <Mark>
                      <Btn label="Potwierdź ten dokument" color="border" />
                    </Mark>
                  </div>
                </div>
                <div className="flex gap-2">
                  <span className="rounded border border-blue-300 bg-blue-50 px-2 py-0.5">Zamówił</span>
                  <span className="rounded border px-2 py-0.5">Zamówił część</span>
                  <span className="rounded border px-2 py-0.5">Nie zamówił</span>
                  <span className="rounded border px-2 py-0.5">Nie wiadomo</span>
                </div>
                <Btn label="Zapisz" />
              </Card>
            </AppFrame>
          ),
        },
        {
          action: 'Oferta przestaje być ważna',
          does: 'Aplikacja czyta warunek „Ważność oferty” z odpowiedzi i liczy datę od dnia jej wysłania. Rozumie tylko jednoznaczne zapisy: samą liczbę dni („14”), „14 dni” (kalendarzowych), „10 dni roboczych” (od poniedziałku do piątku, bez odliczania świąt), tygodnie („2 tygodnie”, „tydzień”), miesiące („1 miesiąc”, „miesiąc”) albo datę w postaci DD.MM.RRRR („31.10.2026”), najwyżej rok naprzód. W zapytaniu i na liście zapytań widać wtedy, do kiedy oferta jest ważna („oferta ważna do …”). Przy innych zapisach (na przykład „do odwołania”) daty końca nie ma, więc nie ma też przypomnienia — zapytanie mówi to wprost. Od ostatniego dnia roboczego przed końcem ważności do jej końca, jeśli wynik zapytania nie jest wpisany, Twoje zapytanie pojawia się w „Do zrobienia dziś” („Oferta ważna …, wynik niewpisany”, z dopiskiem „Jeśli klient jeszcze nie zamówił, warto zadzwonić; potem wpisz wynik.” albo — gdy nocne sprawdzenie znalazło podpowiedź — „ERP XL podpowiada możliwe zamówienie — sprawdź i wpisz wynik.”), a w dzwonku przychodzi powiadomienie („Oferta ważna do …, wynik zapytania nie jest wpisany”), raz na ofertę, od 7:00. Aplikacja nie wie, czy klient zamówił — wie tylko, że wynik nie jest wpisany. E-mail domyślnie nie przychodzi — możesz go włączyć w „Moje konto” → „Powiadomienia”.',
          click: 'Dashboard → „Do zrobienia dziś” → „Otwórz zapytanie”.',
          tone: 'green',
          screen: (
            <AppFrame nav="Dashboard">
              <Card className="max-w-xl text-xs">
                <b>Oferta ważna do jutra, wynik niewpisany</b> · Ciepłownia Wisłok
                <br />
                <span className="text-slate-500">Zapytanie o rękawice nitrylowe. Jeśli klient jeszcze nie zamówił, warto zadzwonić; potem wpisz wynik.</span>
                <div className="mt-2">
                  <Mark>
                    <Btn label="Otwórz zapytanie" color="border" />
                  </Mark>
                </div>
              </Card>
            </AppFrame>
          ),
        },
        {
          action: 'Cele handlowców i przypisanie pracownika ERP XL',
          does: 'Zarząd (uprawnienie „Cele handlowców”, na start tylko administrator) wpisuje cel sprzedaży na miesiąc dla każdej osoby w Raportach → „Cele handlowców”. Do wyboru jest następny miesiąc (cele ustala się z wyprzedzeniem — sprzedaż i realizacja pojawią się od jego pierwszego dnia), bieżący i 11 poprzednich. Realizacja to sprzedaż netto z faktur i paragonów w ERP XL, po korektach (dokument liczy się do miesiąca według daty wystawienia), klientów przypisanych do handlowca według dzisiejszego opiekuna: najpierw opiekun z karty w ERP XL, gdy administrator przypisał tego pracownika do konta, inaczej opiekun w aplikacji. Liczą się tylko klienci z zakładki Klienci (kupili w roku za co najmniej 3000 zł netto). Osobny wiersz pokazuje sprzedaż klientów bez opiekuna. Handlowiec widzi tylko swój cel — w kafelku „Mój cel” na Dashboardzie, gdy ma cel w bieżącym miesiącu (cel na następny miesiąc pokaże się tam od jego pierwszego dnia). Przypisanie pracownika ERP XL do konta robi administrator; podpowiedź „ten sam e-mail co konto” jest tylko propozycją do sprawdzenia, a jeden pracownik może być przypisany do jednego konta.',
          click: 'Administracja → Użytkownicy → „Edytuj” → kolumna „Pracownik ERP XL (opiekun klientów)” → wybór z listy → „Zapisz”. Cele: Raporty → „Cele handlowców” → wybór miesiąca → „Ustaw cele” → kwoty → „Zapisz cele” (puste pole usuwa cel).',
          tone: 'violet',
          screen: (
            <AppFrame nav="Administracja">
              <Card className="max-w-xl text-xs">
                <div className="mb-1 font-semibold">Pracownik ERP XL (opiekun klientów)</div>
                <Mark>
                  <div className="rounded border border-slate-300 px-2 py-1">Anna Nowak (64 klientów)</div>
                </Mark>
                <p className="mt-1 text-[11px] text-blue-700">propozycja — ten sam e-mail co to konto (sprawdź, zanim wybierzesz)</p>
              </Card>
            </AppFrame>
          ),
        },
      ]}
    />
  )
}

/** Czego jeszcze nie ma — żeby nikt nie szukał funkcji, której nie zbudowano. */
function NotYetCard() {
  return (
    <div className="space-y-2 rounded-xl bg-white p-5 text-sm text-slate-700 shadow-sm">
      <h2 className="text-lg font-semibold text-slate-900">Czego jeszcze nie ma</h2>
      <p>
        <b>Zaległe płatności klienta.</b> Aplikacja ich nie pokazuje: konto, którym czyta ERP XL, nie ma prawa odczytu
        rozrachunków. Żeby to zbudować, właściciel bazy ERP XL musi nadać temu kontu prawo odczytu (SELECT) tabeli
        rozrachunków CDN.TraPlat. Które kolumny będą potrzebne (termin płatności, kwota pozostała do zapłaty), trzeba
        jeszcze potwierdzić ze strukturą bazy ERP XL.
      </p>
      <p>
        <b>Także nie ma jeszcze:</b> przekazania zapytania innej osobie, cen w wynikach wyszukiwania, sprzedaży kontrahentów
        ERP XL spoza zakładki Klienci w celach handlowców, liczenia celu według opiekuna z dnia zamknięcia miesiąca (liczy się
        dzisiejszy opiekun) i odliczania świąt w dniach roboczych.
      </p>
    </div>
  )
}

export function NewFeaturesHelp() {
  return (
    <div className="space-y-4">
      <Stage34Slideshow />
      <Stage01Slideshow />
      <NotYetCard />
    </div>
  )
}

/** Etapy 0–1: godzina i numer ogłoszenia, wynik przetargu, powiadomienia, „Do zrobienia dziś”, stan systemu. */
function Stage01Slideshow() {
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
          does: 'Na górze dashboardu lista Twoich pilnych spraw: terminy składania w ciągu 7 dni (z godziną i brakami oferty), zapytania czekające na odpowiedź ponad dobę, przetargi po terminie bez wpisanego wyniku, nieprzeczytane wzmianki i oferty z zapytań, którym kończy się ważność. Liczą się tylko Twoje sprawy — przetargi, które prowadzisz albo do których Cię zaproszono. Sprawa znika, gdy zostanie zrobiona. W karcie „Przetargi” nowy kafelek „Wygrane, 90 dni” — procent części wygranych spośród części z wpisanym wynikiem.',
          click: 'Przycisk przy sprawie („Otwórz przetarg”, „Pokaż zapytania”, „Wpisz wynik”, „Otwórz zapytanie”, „Odpowiedz”) prowadzi prosto do miejsca, gdzie się ją załatwia.',
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
          does: 'Jeden ekran pokazuje, czy działają zadania nocne, pobieranie cen z kont dostawców, kolejka analiz zapytań i dopasowywanie produktów. Gdy zadanie nocne albo konto dostawcy przestanie działać, osoby z uprawnieniem „Stan systemu” i dostępem do Administracji dostają jeden e-mail na każdy problem; znany problem można wyciszyć. Godziny zadań są w czasie polskim. Niżej „Dane do uzupełnienia”: przetargi bez godziny składania albo numeru ogłoszenia, handlowcy bez operatora ERP XL, zamawiający bez powiązania z ERP XL, sprzedawane towary bez karty produktu, klienci z ERP XL bez opiekuna (ich sprzedaż nie liczy się do niczyjego celu) i handlowcy bez przypisanego pracownika ERP XL — „Pokaż” rozwija listę. Wśród zadań są też: „Faktury i paragony klientów z ERP XL” (codziennie o 5:40), „Powiązania zapytań z klientami i podpowiedzi zamówień” (o 5:55) i „Przypomnienia z notatek o klientach i o ważności ofert” (co 15 minut).',
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
