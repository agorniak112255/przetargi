import type { ReactNode } from 'react'
import { useAuth } from '../../auth'
import { can } from '../../lib/api'
import { Inspections } from '../Inspections'
import { AppFrame, Btn, Card, LivePage, Mark, Slideshow, Th } from './kit'

/**
 * Pomoc modułu „Przeglądy” (06.10.2026). Pierwszy slajd to prawdziwa lista (LivePage — przy wejściu tylko GET
 * /inspections), reszta to atrapy z kit.tsx z przykładowymi danymi.
 */

function Chip({ tone, children }: { tone: 'slate' | 'amber' | 'red' | 'blue'; children: ReactNode }) {
  const cls = {
    slate: 'bg-slate-100 text-slate-700',
    amber: 'bg-amber-100 text-amber-900',
    red: 'bg-red-100 text-red-800',
    blue: 'bg-sky-100 text-sky-800',
  } as const
  return <span className={`inline-block rounded-full px-2 py-0.5 text-[10px] font-medium ${cls[tone]}`}>{children}</span>
}

function Small({ children }: { children: ReactNode }) {
  return <span className="block text-[10px] text-slate-500">{children}</span>
}

/** Atrapa tabeli klientów z listy Przeglądów. */
function ListMock({ markOffer }: { markOffer?: boolean }) {
  return (
    <Card>
      <table className="w-full text-left text-[11px]">
        <thead>
          <tr className="border-b bg-slate-50">
            <Th />
            <Th>Klient</Th>
            <Th>Najbliższy termin</Th>
            <Th>Co wymaga przeglądu</Th>
            <Th>Ostatni przegląd lub zakup</Th>
            <Th>Adres e-mail</Th>
            <Th>Ostatnia oferta przeglądu</Th>
          </tr>
        </thead>
        <tbody>
          <tr className="border-b bg-red-50/50 align-top">
            <td className="p-2">☑</td>
            <td className="p-2">
              <b>PIEKARNIA-KOWAL</b>
              <Small>Rzeszów · NIP 8130000000</Small>
            </td>
            <td className="p-2">
              14.09.2026 <Chip tone="red">zaległy 22 dni</Chip>
            </td>
            <td className="p-2">
              PRZEGLĄD GAŚNICY PROSZKOWEJ GP-6 · 8 szt
              <Small>PRZEGLĄD KOCA GAŚNICZEGO · 2 szt</Small>
            </td>
            <td className="p-2">
              14.09.2025
              <Small>z faktur i WZ w ERP XL</Small>
            </td>
            <td className="p-2 font-mono">biuro@piekarnia.pl</td>
            <td className="p-2 text-slate-400">nie wysyłano</td>
          </tr>
          <tr className="border-b align-top">
            <td className="p-2">☑</td>
            <td className="p-2">
              <b>HURTOWNIA-ABC</b>
              <Small>Tarnów</Small>
            </td>
            <td className="p-2">
              20.10.2026 <Chip tone="slate">za 14 dni</Chip>
            </td>
            <td className="p-2">PRZEGLĄD GAŚNICY PROSZKOWEJ GP-4 · 12 szt</td>
            <td className="p-2">
              20.10.2025
              <Small>z faktur i WZ w ERP XL</Small>
            </td>
            <td className="p-2">
              <Chip tone="amber">brak adresu e-mail</Chip>
            </td>
            <td className="p-2">
              {markOffer ? (
                <Mark>
                  <span className="font-mono text-blue-600">OF-0012</span>
                </Mark>
              ) : (
                <span className="font-mono text-blue-600">OF-0012</span>
              )}
              <Small>wysłana 01.09.2026 przez Jan Nowak</Small>
            </td>
          </tr>
        </tbody>
      </table>
    </Card>
  )
}

export function PrzegladyHelp() {
  const { user } = useAuth()
  return (
    <Slideshow
      title="Przeglądy"
      slides={[
        {
          action: 'Lista klientów z terminem przeglądu',
          does: 'Przeglądy pokazują klientów, którym zbliża się albo minął termin przeglądu gaśnic, hydrantów, legalizacji i innych urządzeń. Termin wylicza system z faktur i WZ w ERP XL: data ostatniego przeglądu (usługa) albo zakupu urządzenia (towar) plus interwał ustawiony w „Pozycjach przeglądów”. System nie wymyśla terminów ani przepisów. Zaległe terminy są na czerwono. Domyślnie widać terminy w ciągu 30 dni i zaległe.',
          click: 'Menu „Przeglądy”. Kliknij akronim klienta albo „Szczegóły”, żeby zobaczyć wszystkie jego terminy, historię faktur i wysłane oferty.',
          tone: 'blue',
          screen: (
            <LivePage
              nav="Przeglądy"
              path="/przeglady"
              page={<Inspections />}
              allowed={can(user, 'inspections.view')}
              fallback={
                <AppFrame nav="Przeglądy">
                  <h1 className="mb-1 text-xl font-semibold">Przeglądy</h1>
                  <p className="mb-3 text-[11px] text-slate-600">
                    Klienci, którym zbliża się albo minął termin przeglądu. Termin wylicza system z faktur i WZ w ERP XL.
                  </p>
                  <ListMock />
                </AppFrame>
              }
            />
          ),
        },
        {
          action: 'Filtry listy',
          does: '„Termin w ciągu” zmienia okno (7–365 dni); zaległe widać zawsze. „Stan” pokazuje tylko zaległe albo tylko nadchodzące. „Oddział” i „Pozycja” zawężają listę. „Moi klienci” to klienci, którym ostatnią fakturę przeglądu wystawił Twój operator ERP XL. „Pokaż starsze zaległe” odsłania terminy zaległe dłużej niż trzy interwały (zwykle klienci, którzy odeszli). „Pokaż pominiętych” odsłania ukrytych klientów.',
          click: 'Pasek filtrów nad tabelą. „Wyczyść filtry” wraca do widoku domyślnego.',
          tone: 'blue',
          screen: (
            <AppFrame nav="Przeglądy">
              <Card className="mb-3">
                <div className="flex flex-wrap items-end gap-3 text-[11px]">
                  <Mark>
                    <span className="inline-flex overflow-hidden rounded border border-slate-300">
                      {['7 dni', '14 dni', '30 dni', '60 dni', '90 dni'].map((d) => (
                        <span key={d} className={`px-2 py-1 ${d === '30 dni' ? 'bg-blue-600 text-white' : 'bg-white'}`}>
                          {d}
                        </span>
                      ))}
                    </span>
                  </Mark>
                  <span className="rounded border border-slate-300 px-2 py-1">Stan: wszystkie</span>
                  <span className="rounded border border-slate-300 px-2 py-1">Oddział: Rzeszów (01)</span>
                  <span>☐ Moi klienci</span>
                  <span>☐ Tylko z adresem e-mail</span>
                  <span>☐ Pokaż starsze zaległe</span>
                  <span>☐ Pokaż pominiętych</span>
                </div>
              </Card>
              <ListMock />
            </AppFrame>
          ),
        },
        {
          action: 'Skąd są daty i ilości',
          does: '„Pokaż szczegóły pozycji” rozwija klienta. Data ostatniego przeglądu, numer faktury i ilość pochodzą z faktur i WZ w ERP XL. Termin jest wyliczony — pod nim widać, z czego. Kilka wizyt bez przeglądu (np. kilka obiektów) daje kilka terminów; lista pokazuje najwcześniejszy. Ostrzeżenie „inna karta klienta z tym samym NIP-em…” znaczy, że przegląd mógł być zafakturowany na inną kartę tej firmy — sprawdź przed ofertą.',
          click: 'Link „Pokaż szczegóły pozycji” w kolumnie „Co wymaga przeglądu”.',
          tone: 'amber',
          screen: (
            <AppFrame nav="Przeglądy">
              <Card>
                <table className="w-full text-left text-[11px]">
                  <thead>
                    <tr className="border-b bg-slate-50">
                      <Th>Pozycja</Th>
                      <Th>Ilość do przeglądu</Th>
                      <Th>Ostatni przegląd lub zakup</Th>
                      <Th>Termin przeglądu</Th>
                      <Th>Wartość ostatniej wizyty</Th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr className="align-top">
                      <td className="p-2">
                        <b>PRZEGLĄD GAŚNICY PROSZKOWEJ GP-6</b>
                        <Small>UPRGP6 · usługa · przegląd co 12 miesięcy</Small>
                        <Chip tone="amber">inna karta klienta z tym samym NIP-em ma przegląd po tej dacie</Chip>
                      </td>
                      <td className="p-2">8 szt</td>
                      <td className="p-2">
                        14.09.2025 · 8 szt
                        <Small>ostatni przegląd, z faktur i WZ w ERP XL</Small>
                        <Small>FS-123/25/01G</Small>
                      </td>
                      <td className="p-2">
                        14.09.2026 <Chip tone="red">zaległy 22 dni</Chip>
                        <Mark>
                          <Small>termin wyliczony: ostatni przegląd + 12 miesięcy</Small>
                        </Mark>
                      </td>
                      <td className="p-2">73,12 zł</td>
                    </tr>
                  </tbody>
                </table>
              </Card>
              <p className="mt-2 text-[11px] text-slate-500">
                Wartość netto ostatniej wizyty widzisz tylko Ty — oferta dla klienta jest bez cen.
              </p>
            </AppFrame>
          ),
        },
        {
          action: 'Pomiń klienta',
          does: 'Klient robi przeglądy u innej firmy albo zrezygnował? „Pomiń klienta” ukrywa go na liście i w ofertach — na zawsze albo do wybranej daty, z powodem i uwagą. „Pomiń pozycję” ukrywa tylko jedną pozycję. Pominiętych pokazuje filtr „Pokaż pominiętych”, a „Przywróć” zdejmuje pominięcie.',
          click: 'Przycisk „Pomiń klienta” na końcu wiersza (albo „Pomiń pozycję” w szczegółach) → powód, na jak długo → „Pomiń”.',
          tone: 'blue',
          screen: (
            <AppFrame nav="Przeglądy">
              <Card className="max-w-md">
                <p className="mb-2 text-sm font-semibold">Pomiń klienta: PIEKARNIA-KOWAL</p>
                <p className="mb-2 text-[11px] text-slate-600">Klient zniknie z listy terminów i z ofert przeglądu.</p>
                <p className="text-[11px]">Powód: Robi przeglądy u innej firmy</p>
                <p className="mt-1 text-[11px]">◉ na zawsze ○ do dnia</p>
                <div className="mt-3 flex justify-end gap-2">
                  <Btn label="Anuluj" color="border" />
                  <Mark>
                    <Btn label="Pomiń" />
                  </Mark>
                </div>
              </Card>
            </AppFrame>
          ),
        },
        {
          action: 'Raport, plik Excela i oferty dla zaznaczonych',
          does: 'Zaznacz klientów kwadracikami (Shift+klik zaznacza zakres). Na belce u góry: „Raport PDF” — raport wewnętrzny z pozycjami, terminami i wartością; „Eksport do Excela” — wiersz to klient i pozycja; „Przygotuj oferty” — po jednym szkicu oferty przeglądu na klienta (najwyżej 50 naraz). Przed przygotowaniem widać, kto już dostał ofertę przeglądu i kto nie ma adresu e-mail.',
          click: 'Zaznacz klientów → belka u góry → „Przygotuj oferty” → „Przygotuj szkice ofert”. W wyniku kliknij numer oferty, żeby ją otworzyć.',
          tone: 'blue',
          screen: (
            <AppFrame nav="Przeglądy">
              <div className="mb-3 flex flex-wrap items-center justify-between gap-2 rounded-xl bg-slate-800 px-3 py-2 text-[11px] text-white">
                <span>
                  <b>2</b> zaznaczonych klientów
                </span>
                <span className="flex gap-1.5">
                  <span className="rounded border border-slate-500 px-2 py-1">Raport PDF</span>
                  <span className="rounded border border-slate-500 px-2 py-1">Eksport do Excela</span>
                  <Mark>
                    <span className="rounded bg-emerald-500 px-2 py-1 font-semibold text-slate-900">Przygotuj oferty</span>
                  </Mark>
                </span>
              </div>
              <ListMock markOffer />
            </AppFrame>
          ),
        },
        {
          action: 'Oferta przeglądu — bez cen',
          does: 'Szkic trafia do modułu Oferty ze znacznikiem „Przegląd”. To przypomnienie dla klienta: co i kiedy wymaga przeglądu, żeby zaczął rozmowę. W ofercie nie ma cen ani wyboru produktów. Możesz poprawić ilość, termin i uwagę w wierszu, usunąć wiersz i zmienić treść. „Wstaw adresy klienta” wpisuje adresy z karty klienta w ERP XL. Wysyłka, PDF i Thunderbird działają jak w zwykłej ofercie.',
          click: 'Otwórz ofertę z wyniku albo z listy Ofert → sprawdź tabelę „Co wymaga przeglądu” → „Wstaw adresy klienta” → „Wyślij”.',
          tone: 'green',
          screen: (
            <AppFrame nav="Oferty">
              <Card className="mb-3">
                <p className="text-sm font-semibold">Klient</p>
                <p className="text-[11px]">
                  Piekarnia Kowal Sp. z o.o. · Rzeszów · <span className="font-mono">biuro@piekarnia.pl</span>
                </p>
              </Card>
              <Card>
                <p className="mb-2 text-sm font-semibold">Co wymaga przeglądu</p>
                <table className="w-full text-left text-[11px]">
                  <thead>
                    <tr className="border-b bg-slate-50">
                      <Th>Urządzenie lub usługa</Th>
                      <Th>Ilość</Th>
                      <Th>Ostatni przegląd lub zakup u nas</Th>
                      <Th>Termin przeglądu</Th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr className="border-b">
                      <td className="p-2">PRZEGLĄD GAŚNICY PROSZKOWEJ GP-6</td>
                      <td className="p-2">
                        <Mark>
                          <span className="rounded border border-slate-300 px-2 py-0.5">8</span>
                        </Mark>{' '}
                        szt
                      </td>
                      <td className="p-2">14.09.2025</td>
                      <td className="p-2">14.09.2026</td>
                    </tr>
                    <tr>
                      <td className="p-2">PRZEGLĄD KOCA GAŚNICZEGO</td>
                      <td className="p-2">2 szt</td>
                      <td className="p-2">14.09.2025</td>
                      <td className="p-2">14.09.2026</td>
                    </tr>
                  </tbody>
                </table>
              </Card>
            </AppFrame>
          ),
        },
        {
          action: 'Pozycje przeglądów — co i jak często',
          does: 'Zakładka „Pozycje przeglądów” (uprawnienie do zarządzania przeglądami) mówi systemowi, które usługi i towary z ERP XL śledzić i co ile miesięcy. Interwał wybierasz z listy (1, 3, 6, 9, 12, 15, 18 albo 24 miesiące). Towar (urządzenie) może mieć usługę odnawiającą — gdy klient kupi u nas przegląd, termin z zakupu się zamyka. Każda zmiana od razu przelicza terminy.',
          click: '„Pozycje przeglądów” → „+ Dodaj z ERP XL” → wyszukaj, zaznacz kilka pozycji → wybierz wspólny interwał → „Dodaj”.',
          tone: 'blue',
          screen: (
            <AppFrame nav="Przeglądy">
              <div className="mb-3 flex items-end justify-between">
                <h1 className="text-xl font-semibold">Pozycje przeglądów</h1>
                <Mark>
                  <Btn label="+ Dodaj z ERP XL" />
                </Mark>
              </div>
              <Card>
                <table className="w-full text-left text-[11px]">
                  <thead>
                    <tr className="border-b bg-slate-50">
                      <Th>Pozycja z ERP XL</Th>
                      <Th>Interwał przeglądu</Th>
                      <Th>Usługa odnawiająca</Th>
                      <Th>Klienci z terminem</Th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr className="border-b">
                      <td className="p-2">
                        PRZEGLĄD GAŚNICY PROSZKOWEJ GP-6
                        <Small>UPRGP6 · usługa</Small>
                      </td>
                      <td className="p-2">
                        <span className="rounded border border-slate-300 px-2 py-0.5">co 12 miesięcy ▾</span>
                      </td>
                      <td className="p-2 text-slate-400">nie dotyczy usługi</td>
                      <td className="p-2">412</td>
                    </tr>
                    <tr>
                      <td className="p-2">
                        GAŚNICA PROSZKOWA GP-6X ABC
                        <Small>SGP6X · towar</Small>
                      </td>
                      <td className="p-2">
                        <span className="rounded border border-slate-300 px-2 py-0.5">co 12 miesięcy ▾</span>
                      </td>
                      <td className="p-2">PRZEGLĄD GAŚNICY PROSZKOWEJ GP-6</td>
                      <td className="p-2">95</td>
                    </tr>
                  </tbody>
                </table>
              </Card>
            </AppFrame>
          ),
        },
        {
          action: 'Podpowiedzi — sprawdź przed zatwierdzeniem',
          does: 'Pod listą pozycji system podpowiada pozycje o podobnej nazwie do już zdefiniowanych (np. GP-4 i GP-9 obok GP-6), z interwałem skopiowanym ze wzoru. To tylko propozycja na podstawie nazwy — zatwierdzasz ją Ty. Odrzucona podpowiedź nie wróci.',
          click: 'Sekcja „Podpowiedzi” → „Zatwierdź” albo „Odrzuć” przy pozycji; kilka naraz: zaznacz i „Zatwierdź zaznaczone”.',
          tone: 'amber',
          screen: (
            <AppFrame nav="Przeglądy">
              <Card>
                <p className="text-sm font-semibold">Podpowiedzi</p>
                <p className="mb-2 text-[11px] text-amber-800">
                  To propozycja na podstawie podobnej nazwy — sprawdź przed zatwierdzeniem.
                </p>
                <p className="mb-1 text-[11px]">
                  Wzór: <b>PRZEGLĄD GAŚNICY PROSZKOWEJ GP-6</b> (co 12 miesięcy)
                </p>
                <table className="w-full text-left text-[11px]">
                  <tbody>
                    <tr className="border-b">
                      <td className="p-2">
                        PRZEGLĄD GAŚNICY PROSZKOWEJ GP-4
                        <Small>UPRGP4 · usługa · model GP-4</Small>
                      </td>
                      <td className="p-2">co 12 miesięcy</td>
                      <td className="p-2 text-right">
                        <Mark>
                          <span className="rounded border border-slate-300 px-2 py-0.5">Zatwierdź</span>
                        </Mark>{' '}
                        <span className="rounded border border-slate-300 px-2 py-0.5">Odrzuć</span>
                      </td>
                    </tr>
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
