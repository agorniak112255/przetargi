import type { ReactNode } from 'react'
import { AppFrame, Btn, Card, Field, Mark, Slideshow, Th } from './kit'

/**
 * Pomoc modułu „Oferty” (05.10.2026). Same atrapy z kit.tsx — strony ofert dopiero powstają, więc bez LivePage;
 * po ustabilizowaniu ekranu listy można podmienić pierwszy slajd na LivePage jak w CampaignsHelp.
 */

function Chip({ tone, children }: { tone: 'slate' | 'green' | 'amber' | 'red'; children: ReactNode }) {
  const cls = {
    slate: 'bg-slate-100 text-slate-700',
    green: 'bg-emerald-100 text-emerald-800',
    amber: 'bg-amber-100 text-amber-900',
    red: 'bg-red-100 text-red-800',
  } as const
  return <span className={`inline-block rounded px-1.5 py-0.5 text-[10px] font-medium ${cls[tone]}`}>{children}</span>
}

/** Nagłówek sekcji edytora oferty (Produkty, Treść, Wysyłka). */
function Section({ title, children }: { title: string; children: ReactNode }) {
  return (
    <Card className="mb-3">
      <p className="mb-2 text-sm font-semibold text-slate-900">{title}</p>
      {children}
    </Card>
  )
}

/** Pozycja oferty w atrapie tabeli produktów. */
function ItemRow({
  name,
  code,
  stock,
  cost,
  suggested,
  price,
  warn,
  mark,
}: {
  name: string
  code: string
  stock: string
  cost: string
  suggested: string
  price: string
  warn?: ReactNode
  mark?: boolean
}) {
  const priceBox = (
    <span
      className={`inline-block min-w-[4.5rem] rounded border px-2 py-1 text-right tabular-nums ${
        price ? 'border-slate-300 bg-white text-slate-800' : 'border-amber-400 bg-amber-50 text-slate-400'
      }`}
    >
      {price || '—'}
    </span>
  )
  return (
    <tr className="border-b align-top">
      <td className="p-2">
        <span className="font-medium">{name}</span>
        <span className="block font-mono text-[10px] text-slate-500">{code}</span>
        {warn && <span className="mt-0.5 block">{warn}</span>}
      </td>
      <td className="p-2 text-right tabular-nums">{stock}</td>
      <td className="p-2 text-right tabular-nums">{cost}</td>
      <td className="p-2 text-right">
        {mark ? <Mark>{priceBox}</Mark> : priceBox}
        <span className="mt-0.5 block text-[10px] text-slate-500">
          {suggested ? `sugerowana ${suggested} (koszt + 18%)` : 'brak kosztu — bez ceny sugerowanej'}
        </span>
      </td>
    </tr>
  )
}

export function OffersHelp() {
  return (
    <Slideshow
      title="Oferty"
      slides={[
        {
          action: 'Oferta dla jednego klienta',
          does: 'Oferta to lekki mail z wybranymi produktami i cenami dla konkretnego klienta — na przykład odpowiedź na rozmowę telefoniczną. Kampania to mailing do wielu odbiorców naraz z wynikiem sprzedaży; oferty nie mają grup odbiorców, planowania ani wyników. Mail oferty wygląda jak mail kampanii: baner, kafelki produktów ze zdjęciem i ceną netto. Widzisz tylko swoje oferty. Moduł widzą osoby z uprawnieniem „Oferty dla klientów” (nadaje je administrator w Administracja → Role).',
          click: 'Menu „Kampanie i oferty” → „Oferty” (bez dostępu do kampanii w menu jest od razu „Oferty”). Nowa pusta oferta: „+ Nowa oferta”. Istniejącą otwierasz przyciskiem „Otwórz” albo kliknięciem w temat; „Usuń” kasuje ofertę razem z historią wysyłek.',
          tone: 'blue',
          screen: (
            <AppFrame nav="Oferty">
              <div className="mb-3 flex flex-wrap items-end justify-between gap-2">
                <div>
                  <h1 className="text-xl font-semibold">Oferty</h1>
                  <p className="text-[11px] text-slate-600">
                    Twoje oferty dla klientów — wysyłka z Twojej skrzynki albo nowa wiadomość w Thunderbirdzie.
                  </p>
                </div>
                <Btn label="+ Nowa oferta" />
              </div>
              <Card>
                <table className="w-full text-left text-xs">
                  <thead>
                    <tr className="border-b bg-slate-50">
                      <Th>Oferta</Th>
                      <Th>Pozycje</Th>
                      <Th>Wysłano do</Th>
                      <Th>Ostatnia wysyłka</Th>
                      <Th>Zmieniona</Th>
                      <Th />
                    </tr>
                  </thead>
                  <tbody>
                    <tr className="border-b">
                      <td className="p-2">
                        <span className="font-medium">Rękawice dla działu utrzymania ruchu</span>
                        <span className="block font-mono text-[10px] text-slate-500">OF-0007</span>
                      </td>
                      <td className="p-2 text-right tabular-nums">4</td>
                      <td className="p-2 text-right tabular-nums">2 adresów</td>
                      <td className="whitespace-nowrap p-2 tabular-nums">03.10.2026 15:10</td>
                      <td className="whitespace-nowrap p-2 tabular-nums">03.10.2026 15:10</td>
                      <td className="p-2 text-right">
                        <Mark>
                          <span className="rounded border border-slate-300 px-2 py-0.5 text-[11px]">Otwórz</span>
                        </Mark>
                      </td>
                    </tr>
                    <tr className="border-b">
                      <td className="p-2">
                        <span className="font-medium">Obuwie S3 — zapytanie telefoniczne</span>
                        <span className="block font-mono text-[10px] text-slate-500">OF-0008</span>
                      </td>
                      <td className="p-2 text-right tabular-nums">3</td>
                      <td className="p-2 text-right text-slate-400">—</td>
                      <td className="p-2 text-slate-400">—</td>
                      <td className="whitespace-nowrap p-2 tabular-nums">05.10.2026 09:20</td>
                      <td className="p-2 text-right">
                        <span className="rounded border border-slate-300 px-2 py-0.5 text-[11px]">Otwórz</span>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </Card>
            </AppFrame>
          ),
        },
        {
          action: 'Produkty z listy Produktów albo Zapasów',
          does: 'W ofercie kliknij „Wybierz w Produktach” albo „Wybierz w Zapasach” (przy pozycjach: „albo zaznacz w Produktach / Zapasach”). Lista otworzy się z zieloną belką „Dobierasz karty do oferty OF-…” przypiętą u góry — zostaje widoczna, gdy przewijasz listę. Zaznaczasz pozycje i klikasz na belce „Dodaj do OF-…”: produkty trafiają do oferty, a Ty wracasz do niej. Pozycje, które już są w ofercie, nie dublują się. „← Wróć do oferty” wraca bez dodawania. W Zapasach przy zaznaczonych jest też menu „Dodaj do oferty ▾” — nowa oferta z zaznaczonych albo dopisanie do jednej z ostatnich ofert.',
          click: 'W ofercie „Wybierz w Produktach” → zaznacz wiersze (Shift+klik: zakres) → na belce u góry „Dodaj do OF-0008”.',
          tone: 'blue',
          screen: (
            <AppFrame nav="Produkty">
              <div className="mb-3 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-xs text-emerald-950">
                <span>
                  Dobierasz karty do oferty <b>OF-0008</b> „Obuwie S3 — zapytanie telefoniczne” — 2 z 30 pozycji, zostało
                  28. Zaznacz i kliknij „Dodaj do OF-0008”.
                </span>
                <span className="flex items-center gap-2">
                  <Mark>
                    <span className="inline-block rounded bg-emerald-600 px-3 py-1.5 font-semibold text-white">
                      Dodaj do OF-0008 (3)
                    </span>
                  </Mark>
                  <span className="rounded border border-emerald-300 bg-white px-3 py-1.5 font-medium">← Wróć do oferty</span>
                </span>
              </div>
              <h1 className="mb-1 text-lg font-semibold">Produkty</h1>
              <p className="text-xs text-slate-500">
                Belka zostaje u góry ekranu, gdy przewijasz listę — przycisk dodania jest zawsze pod ręką.
              </p>
            </AppFrame>
          ),
        },
        {
          action: 'Ceny w ofercie',
          does: 'Przy każdej pozycji widzisz stan magazynu, koszt zakupu (średni koszt partii towaru z ERP XL, a dla karty bez towaru w XL — cenę zakupu karty) i cenę sugerowaną = koszt zakupu + Twoja domyślna marża z Moje konto → Oferty → „Domyślna marża”. Cena sugerowana wpisuje się sama jako „Cena netto w ofercie” przy dodaniu pozycji — możesz ją zmienić, a „wstaw sugerowaną” przywraca ją jednym kliknięciem. Gdy kosztu brak, pole jest puste i trzeba wpisać cenę ręcznie: bez ceny przy każdej pozycji oferty nie da się wysłać. Cena niższa od kosztu zakupu dostaje ostrzeżenie, ale nie blokuje wysyłki. Kolejne produkty dodasz też przyciskiem „+ Dodaj produkt” w samej ofercie: otwiera duże okno z wyszukiwarką (każde słowo zawęża listę), podglądem karty ze zdjęciem i opisem — wybierz kartę i „Dodaj … do oferty”; okno zostaje otwarte, więc dodasz kilka produktów pod rząd (wymaga uprawnienia „Produkty — podgląd”); kolejność pozycji w mailu zmieniasz strzałkami w górę i w dół.',
          click: 'Kliknij w pole „Cena netto w ofercie” i wpisz kwotę. Pozycje bez ceny mają też pole kwoty nad podglądem maila — tam uzupełnisz brakującą cenę bez przewijania do tabeli. Nowy produkt: „+ Dodaj produkt” nad tabelą — wpisz nazwę lub kod, kliknij kartę i „Dodaj … do oferty”.',
          tone: 'amber',
          screen: (
            <AppFrame nav="Oferty">
              <h1 className="mb-2 text-lg font-semibold">Obuwie S3 — zapytanie telefoniczne</h1>
              <Section title="Produkty w ofercie">
                <div className="mb-2 flex flex-wrap items-center gap-2 text-xs text-slate-600">
                  <Chip tone="red">bez ceny: 1</Chip>
                  <Chip tone="amber">poniżej kosztu: 1</Chip>
                  <span className="ml-auto rounded bg-blue-600 px-3 py-1 font-medium text-white">+ Dodaj produkt</span>
                </div>
                <table className="w-full text-left text-xs">
                  <thead>
                    <tr className="border-b bg-slate-50">
                      <Th>Produkt</Th>
                      <Th>Stan</Th>
                      <Th>Koszt zakupu</Th>
                      <Th>Cena netto w ofercie</Th>
                    </tr>
                  </thead>
                  <tbody>
                    <ItemRow
                      name="Półbuty robocze S3 SRC"
                      code="OB-S3-412"
                      stock="38 par"
                      cost="112,40 zł"
                      suggested="132,63 zł"
                      price="129,00"
                      mark
                    />
                    <ItemRow
                      name="Trzewiki zimowe S3 CI"
                      code="OB-S3-518"
                      stock="—"
                      cost="—"
                      suggested=""
                      price=""
                      warn={<Chip tone="red">Brak kosztu zakupu — wpisz cenę</Chip>}
                    />
                    <ItemRow
                      name="Kalosze PCV S5"
                      code="OB-S5-020"
                      stock="12 par"
                      cost="64,80 zł"
                      suggested="76,46 zł"
                      price="59,00"
                      warn={<Chip tone="amber">Cena niższa od kosztu zakupu</Chip>}
                    />
                  </tbody>
                </table>
              </Section>
            </AppFrame>
          ),
        },
        {
          action: 'Treść maila i podgląd',
          does: 'W sekcji „Treść maila” wpisujesz „Temat wiadomości” (bez niego oferty nie wyślesz) i krótki „Wstęp” (na przykład „Dzień dobry, zgodnie z rozmową przesyłam…”), wybierasz „Układ produktów” i opcjonalnie datę „Oferta ważna do” — gdy ta data minie, oferty nie da się wysłać, dopóki jej nie zmienisz albo nie wyczyścisz. „Podgląd maila” pokazuje mail dokładnie tak, jak go zobaczy klient: baner, wstęp i kafelki produktów z ceną netto. Przycisku „Zapytaj o ofertę” w ofercie nie ma — klient dostaje cenę; zamiast niego przy pozycji możesz dodać własny przycisk z linkiem, np. „Zobacz w sklepie” („+ Dodaj link” pod opisem pozycji: link https://, nazwa przycisku i kolor). W ofercie nie ma linku „Wypisz mnie” — to nie jest mailing. Wszystko zapisuje się samo.',
          click: 'Sekcja „Treść maila”: pola „Temat wiadomości”, „Wstęp”, „Układ produktów” i „Oferta ważna do”. Podgląd odświeża się po zmianie.',
          tone: 'blue',
          screen: (
            <AppFrame nav="Oferty">
              <Section title="Treść maila">
                <div className="grid gap-3 sm:grid-cols-2">
                  <div className="space-y-2">
                    <Field label="Temat wiadomości" value="Oferta: obuwie S3 dla Państwa zakładu" mark />
                    <Field label="Wstęp" value="Dzień dobry, zgodnie z rozmową przesyłam ofertę na obuwie…" />
                    <div className="grid grid-cols-2 gap-2">
                      <Field label="Układ produktów" value="Siatka po 3" />
                      <Field label="Oferta ważna do" value="31.10.2026" />
                    </div>
                  </div>
                  <div className="rounded-lg border border-slate-200 bg-white text-[10px]">
                    <div className="rounded-t-lg bg-slate-800 px-3 py-2 text-xs font-bold text-white">SUPON</div>
                    <div className="space-y-2 p-2">
                      <p className="text-slate-700">Dzień dobry, zgodnie z rozmową przesyłam ofertę na obuwie…</p>
                      <div className="grid grid-cols-3 gap-1.5">
                        {['Półbuty S3 SRC', 'Trzewiki S3 CI', 'Kalosze PCV S5'].map((n, i) => (
                          <div key={n} className="rounded border border-slate-200 p-1">
                            <div className="mb-1 h-8 rounded bg-slate-100" />
                            <p className="truncate font-medium text-slate-800">{n}</p>
                            <p className="tabular-nums text-slate-700">{['129,00', '189,00', '59,00'][i]} zł netto</p>
                            {i === 0 && (
                              <span className="mt-0.5 inline-block rounded bg-emerald-700 px-1 text-[9px] text-white">
                                Zobacz w sklepie
                              </span>
                            )}
                          </div>
                        ))}
                      </div>
                      <p className="text-slate-500">Ceny netto. Oferta ważna do 31.10.2026</p>
                    </div>
                  </div>
                </div>
              </Section>
            </AppFrame>
          ),
        },
        {
          action: 'Wysyłka z Twojej skrzynki',
          does: 'Wpisujesz jeden albo kilka adresów (oddzielone przecinkiem, średnikiem albo nową linią). Każdy adres dostaje osobny mail — klienci nie widzą siebie nawzajem. Mail wychodzi z Twojej skrzynki ustawionej w Moje konto → „Moja poczta”; bez niej wysyłka nie ruszy. Na Twoją skrzynkę przychodzi kopia z dopiskiem „[Kopia]” i listą adresów. Adresy z listy „Wypisani” w Kampaniach (wypisani z mailingu, adresy, na które maile nie dochodzą, i dopisani ręcznie) blokują wysyłkę — komunikat pokaże, który adres i dlaczego; usuń go z pola. Wysłać można dopiero, gdy każda pozycja ma cenę.',
          click: 'Sekcja „Wysyłka”: wpisz adresy w polu „Adresy e-mail klientów”, kliknij „Wyślij do 2 adresów” i potwierdź w oknie.',
          tone: 'green',
          screen: (
            <AppFrame nav="Oferty">
              <Section title="Wysyłka">
                <Field label="Adresy e-mail klientów" value="zaopatrzenie@sanitex.pl, bhp@przyklad-firma.pl" />
                <p className="mt-1 text-[11px] text-slate-500">
                  2 adresy. Każdy adres dostaje osobny mail — klienci nie widzą siebie nawzajem. Kopia z listą adresów trafi do
                  Twojej skrzynki.
                </p>
                <div className="mt-3 flex flex-wrap items-center gap-2">
                  <Mark>
                    <Btn label="Wyślij do 2 adresów" />
                  </Mark>
                </div>
                <p className="mt-3 rounded-lg bg-red-50 px-2.5 py-2 text-xs text-red-700">
                  Przykład blokady: adres biuro@kowalski.pl wypisał się z mailingu — usuń go z listy adresów.
                </p>
              </Section>
            </AppFrame>
          ),
        },
        {
          action: 'Forma oferty: w treści maila albo w PDF',
          does: 'W sekcji „Wysyłka” wybierasz „Forma oferty”: „W treści maila” (produkty z cenami w treści wiadomości, jak dotąd), „Tylko PDF w załączniku” (krótki mail — Twój wstęp albo „Dzień dobry, w załączeniu przesyłam ofertę…” — a produkty z cenami w pliku PDF) albo „Treść maila i PDF” (oba naraz). Wybór zapisuje się od razu przy ofercie i obowiązuje przy wysyłce z aplikacji i „Otwórz w Thunderbirdzie”. PDF wygląda jak mail oferty: baner, kafelki produktów ze zdjęciem i ceną netto. „Pobierz PDF” pobiera plik z bieżącą ofertą — do obejrzenia przed wysyłką. „Otwórz w Thunderbirdzie” dołącza PDF samo, gdy dodatek Thunderbirda ma wersję 1.35 lub nowszą. W „Historii wysyłek” przy wysyłce z PDF jest „Pobierz wysłany PDF” — dokładnie ten plik, który dostali klienci.',
          click: 'Sekcja „Wysyłka” → „Forma oferty”: zaznacz jedną z trzech form; przy PDF obok pojawi się „Pobierz PDF”.',
          tone: 'blue',
          screen: (
            <AppFrame nav="Oferty">
              <Section title="Wysyłka">
                <p className="mb-1.5 text-xs font-medium text-slate-700">Forma oferty</p>
                <div className="flex flex-wrap gap-2 text-xs">
                  {[
                    { label: 'W treści maila', hint: 'produkty z cenami w treści wiadomości', on: false },
                    { label: 'Tylko PDF w załączniku', hint: 'krótki mail, produkty z cenami w pliku PDF', on: true },
                    { label: 'Treść maila i PDF', hint: 'produkty w treści wiadomości i ten sam wygląd w pliku PDF', on: false },
                  ].map(({ label, hint, on }) => {
                    const box = (
                      <span
                        className={`inline-block rounded border px-2.5 py-1.5 ${
                          on ? 'border-blue-400 bg-blue-50 text-blue-900' : 'border-slate-300 bg-white text-slate-700'
                        }`}
                      >
                        <span className="block font-medium">
                          {on ? '◉' : '○'} {label}
                        </span>
                        <span className="block text-[10px] text-slate-500">{hint}</span>
                      </span>
                    )
                    return <span key={label}>{on ? <Mark>{box}</Mark> : box}</span>
                  })}
                </div>
                <div className="mt-2 flex flex-wrap items-center gap-2 text-[11px]">
                  <span className="rounded border border-slate-300 px-2 py-0.5">Pobierz PDF</span>
                  <span className="text-slate-500">PDF wygląda jak oferta w treści maila — plik Oferta-OF-0008.pdf.</span>
                </div>
              </Section>
            </AppFrame>
          ),
        },
        {
          action: 'Otwórz w Thunderbirdzie',
          does: 'Gdy wolisz wysłać ofertę sam ze swojego Thunderbirda, kliknij „Otwórz w Thunderbirdzie” — dodatek Thunderbirda otworzy nową wiadomość z gotową ofertą (przy formie z PDF także z plikiem w załączniku, od wersji dodatku 1.35). Oferta jest bez podpisu — pod spodem Thunderbird doda Twój własny podpis. Adresata wpisujesz w Thunderbirdzie. Przycisk widać, gdy dodatek jest uruchomiony i połączony z aplikacją. Takiej wiadomości historia wysyłek nie obejmuje — wysyłasz ją sam z Thunderbirda.',
          click: '„Otwórz w Thunderbirdzie” w sekcji „Wysyłka”, potem w oknie Thunderbirda wpisz adresata i wyślij.',
          tone: 'blue',
          screen: (
            <AppFrame nav="Oferty">
              <Section title="Wysyłka">
                <div className="flex flex-wrap items-center gap-2">
                  <Btn label="Wyślij do 0 adresów" color="border" />
                  <Mark>
                    <Btn label="Otwórz w Thunderbirdzie" color="border" />
                  </Mark>
                  <span className="text-[11px] text-slate-500">PDF dołączy dodatek (wersja 1.35 lub nowsza)</span>
                </div>
              </Section>
            </AppFrame>
          ),
        },
        {
          action: 'Historia: co dostał klient',
          does: 'Każda wysyłka zapisuje dokładnie ten mail, który wyszedł, i listę adresów ze stanem: wysłano, błąd (z opisem) albo pominięto. Gdy zawiedzie Twoja skrzynka, pozostałe adresy dostają stan „pominięto”, a wysyłka z Twojej skrzynki wstrzymuje się na kwadrans — sprawdź ustawienia w Moje konto → „Moja poczta” i wyślij do tych adresów jeszcze raz. Ofertę możesz dalej zmieniać i wysyłać kolejnym osobom; zmiana ceny czy treści nie zmienia maili, które już wyszły — „Pokaż wysłaną” przy wysyłce otwiera to, co klient faktycznie dostał.',
          click: 'Sekcja „Historia wysyłek” pod ofertą: „Pokaż wysłaną” przy wybranej wysyłce.',
          tone: 'slate',
          screen: (
            <AppFrame nav="Oferty">
              <Section title="Historia wysyłek">
                <ul className="divide-y divide-slate-200 text-xs">
                  <li className="py-2">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                      <span className="text-slate-700">
                        <b className="font-medium tabular-nums text-slate-900">05.10.2026 10:42</b> · wysłano 1 z 2
                      </span>
                      <Mark>
                        <span className="rounded border border-slate-300 px-2 py-0.5 text-[11px]">Pokaż wysłaną</span>
                      </Mark>
                    </div>
                    <span className="mt-1 block">
                      <span className="font-mono text-[11px]">zaopatrzenie@sanitex.pl</span> <Chip tone="green">wysłano</Chip>
                    </span>
                    <span className="mt-1 block">
                      <span className="font-mono text-[11px]">bhp@przyklad-firma.pl</span> <Chip tone="red">błąd</Chip>{' '}
                      <span className="text-slate-500">skrzynka odbiorcy nie istnieje</span>
                    </span>
                  </li>
                  <li className="py-2">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                      <span className="text-slate-700">
                        <b className="font-medium tabular-nums text-slate-900">03.10.2026 15:10</b> · wysłano 1 z 1
                      </span>
                      <span className="rounded border border-slate-300 px-2 py-0.5 text-[11px]">Pokaż wysłaną</span>
                    </div>
                    <span className="mt-1 block">
                      <span className="font-mono text-[11px]">zaopatrzenie@sanitex.pl</span> <Chip tone="green">wysłano</Chip>
                    </span>
                  </li>
                </ul>
              </Section>
            </AppFrame>
          ),
        },
      ]}
    />
  )
}
