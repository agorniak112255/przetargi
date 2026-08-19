import { useState, type ReactNode } from 'react'

const modules = [
  { id: 'dashboard', label: 'Dashboard' },
  { id: 'przetargi', label: 'Przetargi' },
  { id: 'produkty', label: 'Produkty' },
  { id: 'cenniki', label: 'Cenniki' },
  { id: 'zamienniki', label: 'Zamienniki' },
  { id: 'raporty', label: 'Raporty' },
  { id: 'klienci', label: 'Klienci' },
] as const

type ModuleId = (typeof modules)[number]['id']

function Arrow() {
  return (
    <span className="shrink-0 text-lg font-bold text-slate-300" aria-hidden>
      →
    </span>
  )
}

function Flow({ steps }: { steps: { label: string; tone?: 'blue' | 'green' | 'amber' | 'slate' }[] }) {
  const toneCls = {
    blue: 'border-blue-200 bg-blue-50 text-blue-900',
    green: 'border-emerald-200 bg-emerald-50 text-emerald-900',
    amber: 'border-amber-200 bg-amber-50 text-amber-900',
    slate: 'border-slate-200 bg-white text-slate-800',
  } as const

  return (
    <div className="flex flex-wrap items-center justify-center gap-2 rounded-xl bg-slate-50 p-4">
      {steps.map((s, i) => (
        <div key={s.label} className="flex items-center gap-2">
          {i > 0 && <Arrow />}
          <div
            className={`min-w-[5.5rem] max-w-[7.5rem] rounded-lg border-2 px-2 py-2 text-center text-[11px] leading-snug font-medium ${toneCls[s.tone ?? 'slate']}`}
          >
            {s.label}
          </div>
        </div>
      ))}
    </div>
  )
}

function TreeMain({ title, children }: { title: ReactNode; children: ReactNode }) {
  return (
    <div className="flex flex-col items-center rounded-xl bg-slate-50 p-4">
      <div className="rounded-lg bg-slate-800 px-4 py-3 text-center text-xs font-semibold text-white">
        {title}
      </div>
      <div className="h-4 w-0.5 bg-slate-300" />
      <div className="flex flex-wrap justify-center gap-2">{children}</div>
    </div>
  )
}

function TreeLeaf({ label, sub }: { label: string; sub?: string }) {
  return (
    <div className="min-w-[6.5rem] rounded-lg border-2 border-dashed border-slate-300 bg-white px-3 py-2 text-center text-[11px]">
      <strong className="block text-slate-800">{label}</strong>
      {sub && <span className="text-slate-500">{sub}</span>}
    </div>
  )
}

function KpiMock({ items }: { items: { value: string; label: string }[] }) {
  return (
    <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
      {items.map((k) => (
        <div
          key={k.label}
          className="rounded-xl border border-slate-200 bg-white p-3 text-center shadow-sm"
        >
          <b className="block text-xl text-blue-600">{k.value}</b>
          <span className="text-[10px] text-slate-500">{k.label}</span>
        </div>
      ))}
    </div>
  )
}

function Steps({ items }: { items: string[] }) {
  return (
    <ol className="mt-3 space-y-2">
      {items.map((t, i) => (
        <li key={t} className="flex gap-3 text-sm leading-snug text-slate-700">
          <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-blue-600 text-[11px] font-bold text-white">
            {i + 1}
          </span>
          <span className="pt-0.5">{t}</span>
        </li>
      ))}
    </ol>
  )
}

function Compare({ bad, good }: { bad: ReactNode; good: ReactNode }) {
  return (
    <div className="grid gap-3 sm:grid-cols-2">
      <div className="rounded-xl border border-red-200 bg-red-50 p-3 text-xs leading-relaxed text-red-900">
        <strong className="mb-1 block">Bez systemu</strong>
        {bad}
      </div>
      <div className="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-xs leading-relaxed text-emerald-900">
        <strong className="mb-1 block">Z Przetargi Supon</strong>
        {good}
      </div>
    </div>
  )
}

function Tip({ children }: { children: ReactNode }) {
  return (
    <p className="mt-3 rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-xs text-sky-900">
      <strong>Wskazówka: </strong>
      {children}
    </p>
  )
}

function Panel({
  title,
  lead,
  children,
}: {
  title: string
  lead: string
  children: ReactNode
}) {
  return (
    <div className="space-y-4 rounded-xl bg-white p-5 shadow-sm">
      <div>
        <h2 className="text-lg font-semibold text-slate-900">{title}</h2>
        <p className="mt-1 text-sm text-slate-600">{lead}</p>
      </div>
      {children}
    </div>
  )
}

function DashboardHelp() {
  return (
    <Panel title="Dashboard" lead="Twój pulpit startowy — widać tu, co wymaga uwagi dzisiaj.">
      <KpiMock
        items={[
          { value: '3', label: 'Moje przetargi' },
          { value: '2', label: 'Do akceptacji' },
          { value: '1', label: 'Deadline < 7 dni' },
        ]}
      />
      <p className="text-center text-[11px] text-slate-400">Kafelki jak na ekranie — kliknij „Zobacz”, żeby przejść dalej</p>
      <Flow
        steps={[
          { label: 'Otwórz Dashboard', tone: 'blue' },
          { label: 'Sprawdź czerwone / duże liczby', tone: 'amber' },
          { label: 'Wejdź w przetarg lub zamienniki', tone: 'green' },
        ]}
      />
      <Steps
        items={[
          'Na górze masz kafelki: ile masz przetargów, wartość ofert, marża, rzeczy do Twojej akceptacji, terminy poniżej 7 dni, zamienniki czekające na akceptację.',
          'Liczba na kafelku = ile pozycji wymaga działania. Link „Zobacz” przenosi od razu do listy.',
          'Niżej — tabela „Ostatnie przetargi”: numer, klient, status, termin, % AI. Kolumna „Otwórz” prowadzi do projektu.',
          'Czerwony wykrzyknik przy terminie = deadline w ciągu tygodnia — zajmij się tym najpierw.',
        ]}
      />
      <Tip>Zaczynaj dzień od Dashboardu. Najpierw „Do mojej akceptacji” i „Deadline &lt; 7 dni”.</Tip>
    </Panel>
  )
}

function TendersHelp() {
  return (
    <Panel
      title="Przetargi"
      lead="Tu żyje cała oferta: od dokumentów klienta, przez produkty, aż po Excel/PDF."
    >
      <Flow
        steps={[
          { label: 'Lista przetargów', tone: 'slate' },
          { label: 'Nowy / otwórz', tone: 'blue' },
          { label: 'Dokumenty + AI', tone: 'blue' },
          { label: 'Pozycje i ceny', tone: 'amber' },
          { label: 'Akceptacje', tone: 'green' },
          { label: 'Excel / PDF', tone: 'green' },
        ]}
      />
      <div className="rounded-xl border border-slate-200 bg-slate-50 p-3">
        <p className="mb-2 text-center text-[11px] font-semibold text-slate-600">
          Zakładki wewnątrz jednego przetargu
        </p>
        <div className="flex flex-wrap justify-center gap-1.5">
          {[
            'Pozycje',
            'Warunki',
            'Dokumenty',
            'Zamienniki',
            'Oferta',
            'Komentarze',
            'Historia',
            'Workflow',
          ].map((t) => (
            <span
              key={t}
              className="rounded-md bg-sky-100 px-2 py-1 text-[10px] font-semibold text-blue-800"
            >
              {t}
            </span>
          ))}
        </div>
      </div>
      <Steps
        items={[
          'Lista: filtr „Moje” / „Deadline &lt; 7 dni” albo wszystkie. Przycisk „+ Nowy przetarg” — tytuł, klient, termin.',
          'W projekcie wgraj dokumenty (SIWZ / formularz) w zakładce Dokumenty. System czyta pliki i tworzy pozycje.',
          'Pozycje: przy każdym wierszu wybierany jest produkt główny z bazy. Wyszukaj frazę (SIWZ / produkt), popraw ręcznie albo uruchom „Dopasuj AI (puste)” / „Dopasuj AI (wszystkie)”. Po zakończeniu widać, które wiersze się zmieniły (fioletowa ramka / „Tylko zmienione”).',
          'Sprawdź „Pokrycie oferty” — zielone = gotowe; żółte = brak produktu, ceny, słabe AI albo niska marża. Filtry pomagają znaleźć problematyczne wiersze.',
          'Zamienniki w przetargu: alternatywy do produktu głównego. „Zastosuj tańsze zamienniki” proponuje oszczędności (≥3% po upuście).',
          'Oferta: ceny i marża. Workflow: kolejne akceptacje (kierownik → dyrektor). Na końcu Excel, PDF lub DOCX (wypełnia wgrany formularz).',
        ]}
      />
      <Compare
        bad="Szukasz kodów w Excelu i mailach — łatwo pomylić normę albo cenę."
        good="AI proponuje produkt, Ty zatwierdzasz. Historia pokazuje, kto co zmienił."
      />
      <Tip>
        Najpierw produkt główny w Pozycjach, dopiero potem zamienniki. Zamiennik zawsze zastępuje konkretny główny produkt — nie „coś z kategorii”.
      </Tip>
    </Panel>
  )
}

function ProductsHelp() {
  return (
    <Panel title="Produkty" lead="Katalog Supon — jak półka w magazynie, ale w komputerze.">
      <div className="overflow-hidden rounded-xl border border-slate-200">
        <div className="grid grid-cols-[auto_1fr_auto] gap-2 border-b bg-slate-100 px-3 py-2 text-[10px] font-semibold text-slate-600">
          <span>SKU</span>
          <span>Nazwa · producent</span>
          <span>Cena</span>
        </div>
        {[
          ['ARĘKGLOMJ713', 'Lebon POWERCUT', '12,40'],
          ['UVX-UNIDUR', 'uvex UNIDUR', '18,90'],
        ].map(([sku, name, price]) => (
          <div
            key={sku}
            className="grid grid-cols-[auto_1fr_auto] gap-2 border-b px-3 py-2 text-[11px] last:border-0"
          >
            <code className="rounded bg-slate-100 px-1 font-mono text-[10px]">{sku}</code>
            <span className="truncate text-slate-700">{name}</span>
            <span className="font-semibold text-slate-900">{price} zł</span>
          </div>
        ))}
      </div>
      <Flow
        steps={[
          { label: 'Szukaj / filtr', tone: 'blue' },
          { label: 'Otwórz kartę', tone: 'slate' },
          { label: 'Użyj w przetargu', tone: 'green' },
        ]}
      />
      <Steps
        items={[
          'Szukaj po kodzie (SKU), nazwie albo producencie. Filtr producenta zawęża listę.',
          'Klik w wiersz → karta produktu: opis, zdjęcia, dokumenty, ceny, zamienniki.',
          'Porównanie produktów — gdy wahasz się między dwoma kodami.',
          'Przy uprawnieniach: „Pobierz zaznaczone” ściąga opisy i zdjęcia z zewnętrznych źródeł (enrichment). Możesz też „Pobierz widoczne bez opisu”.',
        ]}
      />
      <Tip>
        Gdy AI źle dopasuje pozycję w przetargu — wejdź w Produkty, znajdź właściwy kod i podmień go w pozycji. Zamienniki przeliczą się pod nowy główny.
      </Tip>
    </Panel>
  )
}

function PriceListsHelp() {
  return (
    <Panel title="Cenniki" lead="Skąd biorą się aktualne ceny producentów w systemie.">
      <Flow
        steps={[
          { label: 'Plik Excel/CSV', tone: 'slate' },
          { label: 'Import cennika', tone: 'blue' },
          { label: 'Grupy i upusty', tone: 'amber' },
          { label: 'Ceny w produktach', tone: 'green' },
        ]}
      />
      <div className="grid gap-2 sm:grid-cols-3">
        {[
          { t: 'Producent', d: 'np. Lebon, Ansell' },
          { t: 'Wersja', d: 'data / nr cennika' },
          { t: 'Historia', d: 'co się zmieniło' },
        ].map((x) => (
          <div key={x.t} className="rounded-xl border border-slate-200 bg-white p-3 text-center text-xs">
            <strong className="block text-slate-800">{x.t}</strong>
            <span className="text-slate-500">{x.d}</span>
          </div>
        ))}
      </div>
      <Steps
        items={[
          'Wybierz producenta, wersję i plik — system analizuje kolumny i wgrywa ceny.',
          'Po imporcie zobaczysz, ile cen się zmieniło (historia). Rozwiń wiersz, żeby zobaczyć szczegóły.',
          'Grupy asortymentowe + domyślny upust — jak liczyć cenę handlową z cennika producenta.',
          'Opcjonalnie: pobieranie opisów/zdjęć produktów z danego cennika (w tle — możesz zamknąć stronę).',
        ]}
      />
      <Tip>
        Oferta w przetargu bierze ceny z cenników i katalogu — nie ze sklepu internetowego „na oko”. Aktualny cennik = aktualna wycena.
      </Tip>
    </Panel>
  )
}

function SubstitutesHelp() {
  return (
    <Panel
      title="Zamienniki"
      lead="Co może zastąpić dany produkt główny — globalna baza relacji, nie lista z kategorii."
    >
      <TreeMain title={<>PRODUKT GŁÓWNY<br /><span className="font-normal opacity-90">Lebon POWERCUT · ARĘKGLOMJ713</span></>}>
        <TreeLeaf label="Preferowany" sub="POWERFIT" />
        <TreeLeaf label="Tańszy" sub="normy OK" />
        <TreeLeaf label="Premium" sub="uvex" />
        <TreeLeaf label="Awaryjny" sub="brak stocku" />
      </TreeMain>
      <Flow
        steps={[
          { label: 'Główny produkt', tone: 'blue' },
          { label: 'Dodaj zamiennik', tone: 'slate' },
          { label: 'Typ + % dopasowania', tone: 'amber' },
          { label: 'Akceptacja', tone: 'green' },
        ]}
      />
      <Steps
        items={[
          'Lista grupuje: jeden główny → pod nim zamienniki. Filtruj po statusie akceptacji i typie.',
          '„+ Dodaj zamiennik”: wybierz główny i zamiennik, typ (preferowany / tańszy / premium / awaryjny), % dopasowania, normy i certyfikaty, krótki powód.',
          'Kierownik (uprawnienie) zatwierdza lub odrzuca. Do czasu akceptacji zamiennik jest „w toku”.',
          'W przetargu korzystasz z tych relacji przy pozycjach — tu budujesz bazę na przyszłość.',
        ]}
      />
      <Compare
        bad="„Podobne rękawice z kategorii” — ryzyko złej normy."
        good="Zamiennik zawsze do konkretnego głównego + powód i % AI."
      />
    </Panel>
  )
}

function ReportsHelp() {
  return (
    <Panel title="Raporty" lead="Podsumowanie dla zarządu — ile przetargów, jaka wartość, jaka marża.">
      <div className="flex h-36 items-end gap-3 rounded-xl border border-slate-200 bg-white p-4">
        {[
          { h: '85%', label: 'Wycena', c: 'bg-blue-500' },
          { h: '55%', label: 'Akcept.', c: 'bg-amber-500' },
          { h: '40%', label: 'Zatw.', c: 'bg-emerald-500' },
          { h: '25%', label: 'Export', c: 'bg-slate-400' },
        ].map((b) => (
          <div key={b.label} className="flex flex-1 flex-col items-center gap-1">
            <div className={`w-full rounded-t-md ${b.c}`} style={{ height: b.h }} />
            <span className="text-[10px] text-slate-500">{b.label}</span>
          </div>
        ))}
      </div>
      <p className="text-center text-[11px] text-slate-400">Pipeline — ile spraw jest na każdym etapie (poglądowo)</p>
      <Steps
        items={[
          'Tabela „Pipeline wg statusu”: ile przetargów w danym statusie, suma wartości ofert, średnia marża.',
          'Tabela „Wg opiekuna”: kto prowadzi ile spraw i z jaką wartością / marżą.',
          'Przycisk „Eksport CSV” — ściągasz plik do Excela (raport-przetargi.csv).',
        ]}
      />
      <Tip>Raporty nie służą do edycji oferty — tylko do podglądu i eksportu. Zmiany robisz w Przetargach.</Tip>
    </Panel>
  )
}

function ClientsHelp() {
  return (
    <Panel title="Klienci" lead="Książka firm, dla których robicie przetargi.">
      <Flow
        steps={[
          { label: 'Dodaj klienta', tone: 'blue' },
          { label: 'Nazwa · NIP · miasto', tone: 'slate' },
          { label: 'Wybierz w nowym przetargu', tone: 'green' },
        ]}
      />
      <div className="overflow-hidden rounded-xl border border-slate-200 text-xs">
        <div className="grid grid-cols-4 gap-1 bg-slate-100 px-3 py-2 font-semibold text-slate-600">
          <span>Nazwa</span>
          <span>NIP</span>
          <span>Miasto</span>
          <span>Przetargi</span>
        </div>
        <div className="grid grid-cols-4 gap-1 border-t px-3 py-2 text-slate-700">
          <span>Firma Sp. z o.o.</span>
          <span>5250000000</span>
          <span>Rzeszów</span>
          <span className="font-semibold text-blue-600">4</span>
        </div>
      </div>
      <Steps
        items={[
          '„+ Nowy klient” — nazwa (wymagana), opcjonalnie NIP i miasto.',
          'Na liście widać opiekuna i ile przetargów ma dana firma.',
          'Przy tworzeniu przetargu wybierasz klienta z tej listy — bez klienta nie założysz projektu.',
        ]}
      />
      <Tip>Najpierw dodaj klienta w Klienci, potem „+ Nowy przetarg” i przypisz go do sprawy.</Tip>
    </Panel>
  )
}

const panels: Record<ModuleId, () => ReactNode> = {
  dashboard: () => <DashboardHelp />,
  przetargi: () => <TendersHelp />,
  produkty: () => <ProductsHelp />,
  cenniki: () => <PriceListsHelp />,
  zamienniki: () => <SubstitutesHelp />,
  raporty: () => <ReportsHelp />,
  klienci: () => <ClientsHelp />,
}

export function Help() {
  const [active, setActive] = useState<ModuleId>('dashboard')

  return (
    <div className="mx-auto max-w-3xl space-y-4">
      <div>
        <h1 className="text-xl font-semibold">Pomoc</h1>
        <p className="mt-1 text-sm text-slate-500">
          Krótko i obrazkowo — wybierz moduł z menu po lewej stronie systemu.
        </p>
      </div>

      <nav className="flex flex-wrap gap-1.5" aria-label="Moduły pomocy">
        {modules.map((m) => (
          <button
            key={m.id}
            type="button"
            onClick={() => setActive(m.id)}
            className={`rounded-lg border px-3 py-1.5 text-xs transition ${
              active === m.id
                ? 'border-blue-300 bg-blue-50 font-semibold text-blue-800'
                : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50'
            }`}
          >
            {m.label}
          </button>
        ))}
      </nav>

      {panels[active]()}
    </div>
  )
}
