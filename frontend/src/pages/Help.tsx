import { useEffect, useState, type ReactNode } from 'react'

const modules = [
  { id: 'dashboard', label: 'Dashboard' },
  { id: 'przetargi', label: 'Przetargi' },
  { id: 'produkty', label: 'Produkty' },
  { id: 'cenniki', label: 'Cenniki' },
  { id: 'zamienniki', label: 'Zamienniki' },
  { id: 'raporty', label: 'Raporty' },
  { id: 'klienci', label: 'Klienci' },
  { id: 'zapytania', label: 'Zapytania' },
] as const

type ModuleId = (typeof modules)[number]['id']
type Tone = 'blue' | 'violet' | 'green' | 'amber' | 'slate'

type Slide = {
  caption: string
  click: string
  tone: Tone
  screen: ReactNode
}

const toneBar: Record<Tone, string> = {
  blue: 'bg-blue-600',
  violet: 'bg-violet-600',
  green: 'bg-emerald-600',
  amber: 'bg-amber-500',
  slate: 'bg-slate-500',
}

function FakeBtn({
  label,
  tone = 'slate',
}: {
  label: string
  tone?: 'blue' | 'violet' | 'green' | 'slate'
}) {
  const cls = {
    blue: 'bg-blue-600 text-white',
    violet: 'bg-violet-600 text-white',
    green: 'bg-emerald-600 text-white',
    slate: 'bg-slate-800 text-white',
  } as const
  return (
    <span className={`inline-block rounded px-2 py-1 text-[11px] font-semibold ${cls[tone]}`}>{label}</span>
  )
}

function Window({ title, children }: { title: string; children: ReactNode }) {
  return (
    <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
      <div className="flex items-center gap-1.5 border-b border-slate-100 bg-slate-100 px-3 py-1.5">
        <span className="h-2 w-2 rounded-full bg-slate-300" />
        <span className="h-2 w-2 rounded-full bg-slate-300" />
        <span className="text-[11px] font-medium text-slate-500">{title}</span>
      </div>
      <div className="min-h-[220px] p-4">{children}</div>
    </div>
  )
}

function Slideshow({ title, slides }: { title: string; slides: Slide[] }) {
  const [i, setI] = useState(0)
  useEffect(() => {
    setI(0)
  }, [title])
  const s = slides[i]
  const pct = Math.round(((i + 1) / slides.length) * 100)

  return (
    <div className="space-y-3 rounded-xl bg-white p-5 shadow-sm">
      <div className="flex items-start justify-between gap-3">
        <div>
          <h2 className="text-lg font-semibold text-slate-900">{title}</h2>
          <p className="mt-0.5 text-xs text-slate-500">
            Krok {i + 1} z {slides.length}
          </p>
        </div>
        <span className={`rounded-full px-2 py-0.5 text-[10px] font-semibold text-white ${toneBar[s.tone]}`}>
          {s.tone === 'violet' ? 'AI liczy' : s.tone === 'green' ? 'gotowe' : s.tone === 'blue' ? 'Ty klikasz' : 'patrz'}
        </span>
      </div>
      <div className="h-1.5 overflow-hidden rounded-full bg-slate-100">
        <div className={`h-full ${toneBar[s.tone]}`} style={{ width: `${pct}%` }} />
      </div>
      <div>
        <p className="text-sm font-semibold text-slate-900">{s.caption}</p>
        <p className="text-xs text-slate-500">{s.click}</p>
      </div>
      {s.screen}
      <div className="flex items-center justify-between gap-2 pt-1">
        <button
          type="button"
          disabled={i === 0}
          onClick={() => setI((n) => n - 1)}
          className="rounded border border-slate-300 px-3 py-1.5 text-xs disabled:opacity-40"
        >
          Wstecz
        </button>
        <div className="flex flex-wrap justify-center gap-1">
          {slides.map((_, idx) => (
            <button
              key={idx}
              type="button"
              aria-label={`Krok ${idx + 1}`}
              onClick={() => setI(idx)}
              className={`h-2 rounded-full ${idx === i ? 'w-5 bg-blue-600' : 'w-2 bg-slate-300'}`}
            />
          ))}
        </div>
        <button
          type="button"
          disabled={i === slides.length - 1}
          onClick={() => setI((n) => n + 1)}
          className="rounded bg-blue-600 px-3 py-1.5 text-xs font-medium text-white disabled:opacity-40"
        >
          {i === slides.length - 1 ? 'Koniec' : 'Dalej'}
        </button>
      </div>
    </div>
  )
}

function DashboardHelp() {
  return (
    <Slideshow
      title="Dashboard"
      slides={[
        {
          caption: 'Otwórz Dashboard',
          click: 'To pierwszy ekran po zalogowaniu.',
          tone: 'slate',
          screen: (
            <Window title="Dashboard">
              <div className="grid grid-cols-3 gap-2">
                {[
                  ['3', 'Moje przetargi'],
                  ['2', 'Do akceptacji'],
                  ['1', 'Deadline < 7 dni'],
                ].map(([v, l]) => (
                  <div key={l} className="rounded-lg border border-slate-200 p-3 text-center">
                    <b className="block text-xl text-blue-600">{v}</b>
                    <span className="text-[10px] text-slate-500">{l}</span>
                  </div>
                ))}
              </div>
            </Window>
          ),
        },
        {
          caption: 'Kliknij kafelek z liczbą',
          click: '„Zobacz” przy czerwonym / dużym numerze.',
          tone: 'blue',
          screen: (
            <Window title="Dashboard">
              <div className="rounded-lg border-2 border-amber-400 bg-amber-50 p-3">
                <b className="text-2xl text-amber-700">1</b>
                <p className="text-xs font-semibold text-amber-900">Deadline &lt; 7 dni</p>
                <FakeBtn label="Zobacz" tone="blue" />
              </div>
            </Window>
          ),
        },
        {
          caption: 'Lista już przefiltrowana',
          click: 'Otwórz wiersz — kolumna „Otwórz”.',
          tone: 'blue',
          screen: (
            <Window title="Przetargi · deadline">
              <div className="grid grid-cols-[1fr_auto_auto] gap-2 rounded bg-slate-50 px-2 py-2 text-xs">
                <span>P-042 Mittal · rękawice</span>
                <span className="font-semibold text-red-600">12.09</span>
                <FakeBtn label="Otwórz" tone="blue" />
              </div>
            </Window>
          ),
        },
        {
          caption: 'Jesteś w sprawie',
          click: 'Dalej pracujesz w zakładce Przetargi.',
          tone: 'green',
          screen: (
            <Window title="P-042 · Pakiet rękawic">
              <div className="flex flex-wrap gap-1">
                {['Pozycje', 'Dokumenty', 'Oferta'].map((t) => (
                  <span key={t} className="rounded bg-sky-100 px-2 py-0.5 text-[10px] font-semibold text-blue-800">
                    {t}
                  </span>
                ))}
              </div>
              <p className="mt-3 text-xs text-slate-500">Tu dopasowujesz produkty i liczysz ofertę.</p>
            </Window>
          ),
        },
      ]}
    />
  )
}

function TendersHelp() {
  return (
    <Slideshow
      title="Przetargi"
      slides={[
        {
          caption: 'Lista przetargów',
          click: 'Kliknij „+ Nowy przetarg”.',
          tone: 'blue',
          screen: (
            <Window title="Przetargi">
              <div className="mb-3 flex justify-end">
                <FakeBtn label="+ Nowy przetarg" tone="blue" />
              </div>
              <div className="rounded bg-slate-50 px-2 py-2 text-xs text-slate-500">P-041 · Sanitex · szkic</div>
            </Window>
          ),
        },
        {
          caption: 'Wypełnij formularz',
          click: 'Tytuł, klient z listy, termin. Zapisz.',
          tone: 'blue',
          screen: (
            <Window title="Nowy przetarg">
              <div className="space-y-2 text-xs">
                <p className="text-[10px] text-slate-400">Tytuł</p>
                <p className="rounded border border-blue-300 bg-blue-50 px-2 py-1">Pakiet rękawic Q2</p>
                <p className="text-[10px] text-slate-400">Klient · termin</p>
                <p className="rounded border border-slate-200 px-2 py-1">Mittal · 12.09.2026</p>
                <FakeBtn label="Zapisz" tone="blue" />
              </div>
            </Window>
          ),
        },
        {
          caption: 'Wejdź w Dokumenty',
          click: 'Zakładka „Dokumenty” w projekcie.',
          tone: 'blue',
          screen: (
            <Window title="P-042 · Dokumenty">
              <div className="flex flex-wrap gap-1">
                {['Pozycje', 'Dokumenty', 'Oferta'].map((t) => (
                  <span
                    key={t}
                    className={`rounded px-2 py-0.5 text-[10px] font-semibold ${
                      t === 'Dokumenty' ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-600'
                    }`}
                  >
                    {t}
                  </span>
                ))}
              </div>
              <p className="mt-3 text-xs text-slate-500">Tu wrzucasz SIWZ / formularz.</p>
            </Window>
          ),
        },
        {
          caption: 'Wgraj SIWZ',
          click: 'Wybierz PDF/XLSX i upuść plik.',
          tone: 'blue',
          screen: (
            <Window title="Import dokumentu SIWZ">
              <div className="rounded-lg border border-dashed border-slate-300 bg-slate-50 px-4 py-8 text-center">
                <p className="text-sm font-semibold text-slate-800">SIWZ_Mittal.pdf</p>
                <p className="text-[11px] text-slate-400">kliknij albo upuść</p>
              </div>
            </Window>
          ),
        },
        {
          caption: 'Analizuj AI',
          click: 'Poczekaj — model czyta dokument.',
          tone: 'violet',
          screen: (
            <Window title="Dokumenty">
              <FakeBtn label="Analizuj AI" tone="violet" />
              <div className="mt-4 h-2 overflow-hidden rounded-full bg-violet-100">
                <div className="h-full w-3/5 rounded-full bg-violet-600" />
              </div>
              <p className="mt-2 text-xs text-violet-800">Wyciągam pozycje z SIWZ… 18s</p>
            </Window>
          ),
        },
        {
          caption: 'Są puste wiersze',
          click: 'Zakładka Pozycje — jeszcze bez produktu.',
          tone: 'slate',
          screen: (
            <Window title="Pozycje">
              <div className="space-y-2 text-xs">
                <div className="flex justify-between rounded bg-slate-50 px-2 py-2">
                  <span>Rękawice nitrylowe L</span>
                  <span className="text-slate-400">produkt: —</span>
                </div>
                <div className="flex justify-between rounded bg-slate-50 px-2 py-2">
                  <span>Okulary UV</span>
                  <span className="text-slate-400">produkt: —</span>
                </div>
              </div>
            </Window>
          ),
        },
        {
          caption: 'Dopasuj AI (puste)',
          click: 'Fioletowy przycisk nad tabelą.',
          tone: 'violet',
          screen: (
            <Window title="Pozycje">
              <div className="mb-3">
                <FakeBtn label="Dopasuj AI (puste)" tone="violet" />
              </div>
              <p className="text-xs text-violet-800">Szukam w katalogu…</p>
              <div className="mt-2 h-2 overflow-hidden rounded-full bg-violet-100">
                <div className="h-full w-2/3 rounded-full bg-violet-600" />
              </div>
            </Window>
          ),
        },
        {
          caption: 'Wiersze z SKU',
          click: 'Fioletowa ramka = AI coś zmieniło.',
          tone: 'violet',
          screen: (
            <Window title="Pozycje">
              <div className="space-y-2 text-xs">
                <div className="rounded border border-violet-300 bg-violet-50 px-2 py-2">
                  Rękawice nitrylowe · <b>RNITZ-100</b> · 91%
                </div>
                <div className="rounded border border-violet-300 bg-violet-50 px-2 py-2">
                  Okulary UV · <b>UVX-UNIDUR</b> · 88%
                </div>
              </div>
            </Window>
          ),
        },
        {
          caption: 'Pokrycie oferty',
          click: 'Zielone OK, żółte do poprawki.',
          tone: 'amber',
          screen: (
            <Window title="Pokrycie oferty">
              <div className="grid grid-cols-2 gap-3 text-center">
                <div className="rounded-lg bg-emerald-50 py-4">
                  <b className="block text-2xl text-emerald-700">10</b>
                  <span className="text-[11px] text-emerald-800">gotowe</span>
                </div>
                <div className="rounded-lg border-2 border-amber-400 bg-amber-50 py-4">
                  <b className="block text-2xl text-amber-700">2</b>
                  <span className="text-[11px] text-amber-800">brak ceny / słabe AI</span>
                </div>
              </div>
            </Window>
          ),
        },
        {
          caption: 'Popraw żółty wiersz',
          click: 'Lupka → wybierz inny SKU z katalogu.',
          tone: 'blue',
          screen: (
            <Window title="Wyszukiwanie produktu">
              <p className="rounded border border-slate-200 px-2 py-1 text-xs text-slate-400">Szukaj w SIWZ / produkcie…</p>
              <div className="mt-2 rounded border border-blue-300 bg-blue-50 px-2 py-2 text-xs">
                <b>A611</b> · rękawice do 500°C
              </div>
              <div className="mt-3">
                <FakeBtn label="Wybierz" tone="blue" />
              </div>
            </Window>
          ),
        },
        {
          caption: 'Akceptacje',
          click: 'Workflow: kierownik → dyrektor.',
          tone: 'blue',
          screen: (
            <Window title="Workflow">
              <div className="flex flex-wrap items-center justify-center gap-2 text-[11px]">
                <span className="rounded bg-slate-100 px-2 py-1">Szkic</span>
                <span>→</span>
                <span className="rounded bg-blue-600 px-2 py-1 text-white">Wycena</span>
                <span>→</span>
                <span className="rounded bg-amber-100 px-2 py-1">Kierownik</span>
                <span>→</span>
                <span className="rounded bg-emerald-100 px-2 py-1">Dyrektor</span>
              </div>
            </Window>
          ),
        },
        {
          caption: 'Eksport oferty',
          click: 'Excel, PDF albo DOCX.',
          tone: 'green',
          screen: (
            <Window title="Oferta">
              <div className="flex flex-wrap gap-2">
                <FakeBtn label="Excel" tone="green" />
                <FakeBtn label="PDF" tone="green" />
                <FakeBtn label="DOCX" tone="slate" />
              </div>
              <p className="mt-4 text-xs text-emerald-800">Pokrycie 12/12 — można wysyłać.</p>
            </Window>
          ),
        },
      ]}
    />
  )
}

function ProductsHelp() {
  return (
    <Slideshow
      title="Produkty"
      slides={[
        {
          caption: 'Otwórz Produkty',
          click: 'Pole szukaj na górze listy.',
          tone: 'slate',
          screen: (
            <Window title="Produkty">
              <p className="rounded border border-slate-200 px-2 py-1.5 text-xs text-slate-400">
                Szukaj kod, nazwa, producent…
              </p>
              <div className="mt-3 space-y-1 text-xs text-slate-500">
                <p>ARĘKGLOMJ713 · Lebon POWERCUT</p>
                <p>UVX-UNIDUR · uvex</p>
              </div>
            </Window>
          ),
        },
        {
          caption: 'Wpisz kod albo nazwę',
          click: 'Np. POWERCUT — lista się zawęża.',
          tone: 'blue',
          screen: (
            <Window title="Produkty">
              <p className="rounded border border-blue-400 bg-blue-50 px-2 py-1.5 text-xs font-medium text-blue-900">
                POWERCUT
              </p>
              <div className="mt-3 rounded bg-slate-50 px-2 py-2 text-xs">
                <code className="font-mono text-[10px]">ARĘKGLOMJ713</code> · Lebon POWERCUT · <b>12,40 zł</b>
              </div>
            </Window>
          ),
        },
        {
          caption: 'Albo Szukaj AI',
          click: 'Opisz zastosowanie / normę — AI szuka po opisach.',
          tone: 'violet',
          screen: (
            <Window title="Produkty">
              <p className="rounded border border-violet-300 bg-violet-50 px-2 py-1.5 text-xs">
                rękawice do pieca szkła 500°C
              </p>
              <div className="mt-2">
                <FakeBtn label="Szukaj AI" tone="violet" />
              </div>
            </Window>
          ),
        },
        {
          caption: 'Kliknij wiersz',
          click: 'Otwiera się karta produktu.',
          tone: 'blue',
          screen: (
            <Window title="Lebon POWERCUT">
              <p className="font-mono text-[11px] text-slate-500">ARĘKGLOMJ713</p>
              <p className="mt-1 text-2xl font-bold text-blue-600">12,40 zł</p>
              <p className="mt-2 text-xs text-slate-500">Opis · zdjęcia · zamienniki · historia ceny</p>
            </Window>
          ),
        },
        {
          caption: 'Tu bierzesz SKU do oferty',
          click: 'W przetargu wklejasz ten kod albo wybierasz z lupki.',
          tone: 'green',
          screen: (
            <Window title="Pozycja przetargu">
              <p className="text-xs text-slate-500">produkt główny</p>
              <p className="mt-1 rounded border border-emerald-300 bg-emerald-50 px-2 py-2 text-sm">
                ARĘKGLOMJ713 · POWERCUT
              </p>
            </Window>
          ),
        },
      ]}
    />
  )
}

function PriceListsHelp() {
  return (
    <Slideshow
      title="Cenniki"
      slides={[
        {
          caption: 'Otwórz Cenniki',
          click: 'Formularz „Import cennika → baza produktów”.',
          tone: 'slate',
          screen: (
            <Window title="Cenniki producentów">
              <p className="text-sm font-semibold">Import cennika → baza produktów</p>
              <p className="mt-2 text-xs text-slate-500">Producent · wersja · plik XLSX/PDF</p>
            </Window>
          ),
        },
        {
          caption: 'Wpisz producenta',
          click: 'Np. Lebon — albo zostaw, AI zgadnie z pliku.',
          tone: 'blue',
          screen: (
            <Window title="Import">
              <p className="text-[10px] text-slate-400">Producent</p>
              <p className="rounded border border-blue-300 bg-blue-50 px-2 py-1 text-sm">Lebon</p>
              <p className="mt-2 text-[10px] text-slate-400">Wersja</p>
              <p className="rounded border border-slate-200 px-2 py-1 text-xs">2026-08</p>
            </Window>
          ),
        },
        {
          caption: 'Wybierz plik',
          click: 'Przycisk „Przeglądaj…” — XLSX, CSV albo PDF.',
          tone: 'blue',
          screen: (
            <Window title="Import">
              <FakeBtn label="Przeglądaj…" tone="slate" />
              <p className="mt-3 text-xs">
                Wybrano: <b>lebon_2026.xlsx</b>
              </p>
            </Window>
          ),
        },
        {
          caption: '1. Analizuj AI',
          click: 'Poczekaj — czyta kolumny. Duży PDF = minuty.',
          tone: 'violet',
          screen: (
            <Window title="Import">
              <FakeBtn label="1. Analizuj AI" tone="violet" />
              <div className="mt-4 h-2 overflow-hidden rounded-full bg-violet-100">
                <div className="h-full w-1/2 rounded-full bg-violet-600" />
              </div>
              <p className="mt-2 text-xs text-violet-800">Analiza AI w toku · 42%</p>
            </Window>
          ),
        },
        {
          caption: 'Sprawdź mapowanie',
          click: 'SKU, nazwa, cena — czy kolumny się zgadzają.',
          tone: 'amber',
          screen: (
            <Window title="Podgląd kolumn">
              <div className="grid grid-cols-3 gap-2 text-center text-[11px] font-semibold text-slate-500">
                <span>SKU</span>
                <span>Nazwa</span>
                <span>Cena</span>
              </div>
              <div className="mt-1 grid grid-cols-3 gap-2 rounded bg-slate-50 px-2 py-2 text-center text-xs">
                <span>ARĘK…</span>
                <span>POWERCUT</span>
                <span>12,40</span>
              </div>
            </Window>
          ),
        },
        {
          caption: '2. Importuj wg AI',
          click: 'Zapisuje produkty i ceny do katalogu.',
          tone: 'blue',
          screen: (
            <Window title="Import">
              <FakeBtn label="2. Importuj wg AI" tone="blue" />
              <p className="mt-4 rounded bg-emerald-50 px-3 py-2 text-center text-sm font-semibold text-emerald-800">
                + 86 produktów
              </p>
            </Window>
          ),
        },
        {
          caption: 'Szukaj w Produktach',
          click: 'Menu Produkty → wpisz nazwę z cennika.',
          tone: 'blue',
          screen: (
            <Window title="Produkty">
              <p className="rounded border border-blue-300 bg-blue-50 px-2 py-1.5 text-xs">POWERCUT</p>
              <div className="mt-2">
                <FakeBtn label="Szukaj AI" tone="violet" />
              </div>
            </Window>
          ),
        },
        {
          caption: 'Cena z cennika',
          click: 'Karta pokazuje aktualną cenę katalogową.',
          tone: 'green',
          screen: (
            <Window title="Lebon POWERCUT">
              <p className="font-mono text-[11px] text-slate-500">ARĘKGLOMJ713</p>
              <p className="mt-1 text-2xl font-bold text-blue-600">12,40 zł</p>
              <p className="text-xs text-emerald-700">z cennika Lebon 2026-08</p>
            </Window>
          ),
        },
      ]}
    />
  )
}

function SubstitutesHelp() {
  return (
    <Slideshow
      title="Zamienniki"
      slides={[
        {
          caption: 'Otwórz Zamienniki',
          click: 'Lista: jeden główny → pod nim zamienniki.',
          tone: 'slate',
          screen: (
            <Window title="Zamienniki">
              <p className="rounded bg-slate-800 px-3 py-2 text-center text-xs font-semibold text-white">
                GŁÓWNY · ARĘKGLOMJ713 POWERCUT
              </p>
              <p className="mt-2 text-center text-xs text-slate-500">zamienniki poniżej</p>
            </Window>
          ),
        },
        {
          caption: '+ Dodaj zamiennik',
          click: 'Niebieski przycisk nad listą.',
          tone: 'blue',
          screen: (
            <Window title="Zamienniki">
              <div className="flex justify-end">
                <FakeBtn label="+ Dodaj zamiennik" tone="blue" />
              </div>
            </Window>
          ),
        },
        {
          caption: 'Wybierz parę i typ',
          click: 'Główny, zamiennik, typ (tańszy / premium), %.',
          tone: 'blue',
          screen: (
            <Window title="Nowy zamiennik">
              <div className="space-y-2 text-xs">
                <p>Główny: POWERCUT</p>
                <p>Zamiennik: POWERFIT</p>
                <p>
                  Typ: <b>tańszy</b> · 86%
                </p>
                <FakeBtn label="Zapisz" tone="blue" />
              </div>
            </Window>
          ),
        },
        {
          caption: 'Czeka na akceptację',
          click: 'Status „oczekuje” — kierownik klika.',
          tone: 'amber',
          screen: (
            <Window title="Zamienniki">
              <div className="rounded border border-amber-300 bg-amber-50 px-3 py-2 text-xs">
                POWERFIT · tańszy · <b>oczekuje</b>
              </div>
            </Window>
          ),
        },
        {
          caption: 'Zatwierdź',
          click: 'Kierownik: akceptuj albo odrzuć.',
          tone: 'green',
          screen: (
            <Window title="Zamienniki">
              <div className="flex gap-2">
                <FakeBtn label="Akceptuj" tone="green" />
                <FakeBtn label="Odrzuć" tone="slate" />
              </div>
            </Window>
          ),
        },
        {
          caption: 'W przetargu',
          click: '„Zastosuj tańsze zamienniki” na pozycjach.',
          tone: 'green',
          screen: (
            <Window title="Pozycje przetargu">
              <FakeBtn label="Zastosuj tańsze zamienniki" tone="green" />
              <p className="mt-3 text-xs text-emerald-800">POWERFIT zamiast POWERCUT · −8%</p>
            </Window>
          ),
        },
      ]}
    />
  )
}

function ReportsHelp() {
  return (
    <Slideshow
      title="Raporty"
      slides={[
        {
          caption: 'Otwórz Raporty',
          click: 'Tylko podgląd — tu nic nie edytujesz.',
          tone: 'slate',
          screen: (
            <Window title="Raporty">
              <p className="text-sm font-semibold">Pipeline wg statusu</p>
              <p className="mt-2 text-xs text-slate-500">ile spraw · wartość · marża</p>
            </Window>
          ),
        },
        {
          caption: 'Pipeline',
          click: 'Kolumny: szkic → wycena → akceptacje → export.',
          tone: 'blue',
          screen: (
            <Window title="Pipeline">
              <div className="flex h-28 items-end gap-3">
                {[
                  ['70%', 'Wycena', 'bg-blue-500'],
                  ['45%', 'Akcept.', 'bg-amber-500'],
                  ['30%', 'Zatw.', 'bg-emerald-500'],
                ].map(([h, l, c]) => (
                  <div key={l} className="flex flex-1 flex-col items-center gap-1">
                    <div className={`w-full rounded-t ${c}`} style={{ height: h }} />
                    <span className="text-[10px] text-slate-500">{l}</span>
                  </div>
                ))}
              </div>
            </Window>
          ),
        },
        {
          caption: 'Wg opiekuna',
          click: 'Kto prowadzi ile spraw i z jaką marżą.',
          tone: 'blue',
          screen: (
            <Window title="Wg opiekuna">
              <div className="grid grid-cols-3 gap-2 text-[11px] font-semibold text-slate-500">
                <span>Osoba</span>
                <span>Sprawy</span>
                <span>Wartość</span>
              </div>
              <div className="mt-1 grid grid-cols-3 gap-2 rounded bg-slate-50 px-1 py-2 text-xs">
                <span>Arek</span>
                <span>7</span>
                <span>184 tys.</span>
              </div>
            </Window>
          ),
        },
        {
          caption: 'Eksport CSV',
          click: 'Ściąga plik do Excela.',
          tone: 'green',
          screen: (
            <Window title="Raporty">
              <FakeBtn label="Eksport CSV" tone="green" />
              <p className="mt-3 text-xs text-slate-500">raport-przetargi.csv</p>
            </Window>
          ),
        },
      ]}
    />
  )
}

function ClientsHelp() {
  return (
    <Slideshow
      title="Klienci"
      slides={[
        {
          caption: 'Otwórz Klienci',
          click: 'Książka firm do przetargów.',
          tone: 'slate',
          screen: (
            <Window title="Klienci">
              <div className="flex justify-end">
                <FakeBtn label="+ Nowy klient" tone="blue" />
              </div>
              <p className="mt-3 text-xs text-slate-500">Mittal · Sanitex</p>
            </Window>
          ),
        },
        {
          caption: '+ Nowy klient',
          click: 'Nazwa wymagana. NIP i miasto opcjonalnie.',
          tone: 'blue',
          screen: (
            <Window title="Nowy klient">
              <div className="space-y-2 text-xs">
                <p className="rounded border border-blue-300 bg-blue-50 px-2 py-1">Zakład Szkła Sp. z o.o.</p>
                <p className="rounded border border-slate-200 px-2 py-1 text-slate-400">NIP · miasto</p>
                <FakeBtn label="Zapisz klienta" tone="blue" />
              </div>
            </Window>
          ),
        },
        {
          caption: 'Jest na liście',
          click: 'Widać opiekuna i liczbę przetargów.',
          tone: 'green',
          screen: (
            <Window title="Klienci">
              <div className="grid grid-cols-3 gap-2 rounded bg-emerald-50 px-2 py-2 text-xs">
                <span>Zakład Szkła</span>
                <span>Rzeszów</span>
                <span>0 przetargów</span>
              </div>
            </Window>
          ),
        },
        {
          caption: 'Wybierz w nowym przetargu',
          click: 'Bez klienta nie założysz projektu.',
          tone: 'blue',
          screen: (
            <Window title="Nowy przetarg">
              <p className="text-[10px] text-slate-400">Klient</p>
              <p className="rounded border border-blue-300 bg-blue-50 px-2 py-1 text-sm">Zakład Szkła Sp. z o.o.</p>
              <div className="mt-3">
                <FakeBtn label="Zapisz" tone="blue" />
              </div>
            </Window>
          ),
        },
      ]}
    />
  )
}

function InquiriesHelp() {
  return (
    <Slideshow
      title="Zapytania"
      slides={[
        {
          caption: 'Otwórz Zapytania',
          click: 'Wklej całą treść maila od klienta.',
          tone: 'blue',
          screen: (
            <Window title="Zapytania">
              <p className="min-h-[88px] rounded border border-blue-300 bg-blue-50 px-2 py-2 text-xs">
                Potrzebuję rękawice do pracy przy piecu szkła…
              </p>
            </Window>
          ),
        },
        {
          caption: 'Przygotuj odpowiedź',
          click: 'Model szuka w katalogu — spinner i sekundy.',
          tone: 'violet',
          screen: (
            <Window title="Zapytania">
              <div className="rounded bg-violet-600 px-3 py-2 text-center text-xs font-medium text-white">
                Analizuję zapytanie · 9s
              </div>
              <p className="mt-2 text-center text-[11px] text-violet-800">nie odświeżaj strony</p>
            </Window>
          ),
        },
        {
          caption: 'Karty niuansów',
          click: 'Klikaj chipy: produkt, cena, zamiennik.',
          tone: 'blue',
          screen: (
            <Window title="Doprecyzowanie">
              <p className="text-[11px] font-semibold">Produkt</p>
              <div className="mt-1 flex flex-wrap gap-1">
                <span className="rounded-full bg-blue-600 px-2 py-0.5 text-[10px] text-white">A611</span>
                <span className="rounded-full border border-slate-300 px-2 py-0.5 text-[10px]">A621</span>
              </div>
              <p className="mt-2 text-[11px] font-semibold">Ceny</p>
              <span className="rounded-full bg-blue-600 px-2 py-0.5 text-[10px] text-white">Bez ceny</span>
            </Window>
          ),
        },
        {
          caption: 'Napisz odpowiedź',
          click: 'Znowu spinner — model składa list.',
          tone: 'violet',
          screen: (
            <Window title="Doprecyzowanie">
              <div className="flex flex-col items-center py-6">
                <span className="h-8 w-8 rounded-full border-4 border-violet-600 border-t-transparent" />
                <p className="mt-2 text-sm font-semibold text-violet-900">Model pisze list… 6s</p>
              </div>
            </Window>
          ),
        },
        {
          caption: 'Okno z listem',
          click: 'Kopiuj treść albo Kopiuj całość.',
          tone: 'green',
          screen: (
            <Window title="Odpowiedź do skopiowania">
              <p className="text-[11px] text-slate-400">Temat</p>
              <p className="rounded bg-slate-50 px-2 py-1 text-xs">Oferta rękawic do pieców</p>
              <p className="mt-2 min-h-[64px] rounded border border-slate-200 px-2 py-2 text-[11px] text-slate-600">
                Dzień dobry, potwierdzamy dostępność A611…
              </p>
              <div className="mt-3">
                <FakeBtn label="Kopiuj całość" tone="blue" />
              </div>
            </Window>
          ),
        },
        {
          caption: 'Powrót albo Zamknij',
          click: 'Górny pasek — wracasz do listy zapytań.',
          tone: 'slate',
          screen: (
            <Window title="Przetargi Supon · odpowiedź">
              <div className="flex justify-end gap-2">
                <FakeBtn label="Powrót" tone="slate" />
                <FakeBtn label="Zamknij" tone="slate" />
              </div>
            </Window>
          ),
        },
      ]}
    />
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
  zapytania: () => <InquiriesHelp />,
}

export function Help() {
  const [active, setActive] = useState<ModuleId>('przetargi')

  return (
    <div className="mx-auto max-w-3xl space-y-4">
      <div>
        <h1 className="text-xl font-semibold">Pomoc</h1>
        <p className="mt-1 text-sm text-slate-500">Slajdy jak film — jeden ekran, potem Dalej.</p>
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
