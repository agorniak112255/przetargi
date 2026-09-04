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
  action: string
  does: string
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

const toneLabel: Record<Tone, string> = {
  blue: 'Ty klikasz',
  violet: 'AI liczy',
  green: 'gotowe',
  amber: 'sprawdź',
  slate: 'patrz',
}

const NAV = ['Dashboard', 'Przetargi', 'Produkty', 'Cenniki', 'Zamienniki', 'Raporty', 'Klienci', 'Zapytania']

function Mark({ children }: { children: ReactNode }) {
  return <span className="inline-flex rounded ring-2 ring-blue-500 ring-offset-2">{children}</span>
}

function AppFrame({ nav, children }: { nav: string; children: ReactNode }) {
  return (
    <div className="pointer-events-none overflow-hidden rounded-xl border border-slate-200 bg-slate-50 shadow-sm">
      <div className="flex min-h-[300px]">
        <aside className="hidden w-[9.5rem] shrink-0 bg-slate-800 text-[11px] text-slate-100 sm:block">
          <div className="border-b border-slate-700 px-3 py-2.5 text-xs font-bold">
            Przetargi Supon
            <small className="mt-0.5 block text-[10px] font-normal text-slate-400">Artur · admin</small>
          </div>
          {NAV.map((l) => (
            <div
              key={l}
              className={`border-l-2 px-3 py-1.5 ${
                l === nav ? 'border-blue-400 bg-slate-700 font-semibold' : 'border-transparent text-slate-300'
              }`}
            >
              {l}
            </div>
          ))}
        </aside>
        <div className="min-w-0 flex-1 overflow-x-auto p-4">{children}</div>
      </div>
    </div>
  )
}

function Card({ children, className = '' }: { children: ReactNode; className?: string }) {
  return <div className={`rounded-xl bg-white p-4 shadow-sm ${className}`}>{children}</div>
}

function Btn({
  label,
  color = 'blue',
}: {
  label: string
  color?: 'blue' | 'violet' | 'violetDark' | 'green' | 'greenDark' | 'amber' | 'slate' | 'sky' | 'indigo' | 'border'
}) {
  const cls = {
    blue: 'bg-blue-600 text-white',
    violet: 'bg-violet-600 text-white',
    violetDark: 'bg-violet-800 text-white',
    green: 'bg-emerald-600 text-white',
    greenDark: 'bg-emerald-800 text-white',
    amber: 'bg-amber-500 text-white',
    slate: 'bg-slate-800 text-white',
    sky: 'bg-sky-700 text-white',
    indigo: 'bg-indigo-600 text-white',
    border: 'border border-slate-300 bg-white text-slate-700',
  } as const
  return <span className={`inline-block rounded px-3 py-2 text-xs font-medium ${cls[color]}`}>{label}</span>
}

function Field({
  label,
  value,
  placeholder,
  mark,
}: {
  label: string
  value?: string
  placeholder?: string
  mark?: boolean
}) {
  const box = (
    <div
      className={`mt-1 w-full rounded border px-2 py-1.5 text-xs ${
        value ? 'border-slate-300 text-slate-800' : 'border-slate-300 text-slate-400'
      }`}
    >
      {value || placeholder || '—'}
    </div>
  )
  return (
    <label className="block text-xs">
      {label}
      {mark ? <Mark>{box}</Mark> : box}
    </label>
  )
}

function Th({ children }: { children?: ReactNode }) {
  return <th className="p-2 font-semibold text-slate-700">{children}</th>
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
          {toneLabel[s.tone]}
        </span>
      </div>
      <div className="h-1.5 overflow-hidden rounded-full bg-slate-100">
        <div className={`h-full ${toneBar[s.tone]}`} style={{ width: `${pct}%` }} />
      </div>
      <div className="rounded-lg border border-slate-200 bg-slate-50 px-4 py-3">
        <p className="text-base font-semibold text-slate-900">{s.action}</p>
        <p className="mt-1 text-sm leading-snug text-slate-700">{s.does}</p>
        <p className="mt-2 text-xs text-slate-500">
          <span className="font-semibold text-slate-700">Co kliknąć:</span> {s.click}
        </p>
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

function TenderTabs({ active }: { active: string }) {
  return (
    <div className="mb-3 flex flex-wrap gap-1 border-b border-slate-200 pb-2">
      {['pozycje', 'dokumenty', 'warunki', 'oferta', 'historia'].map((t) => (
        <span
          key={t}
          className={`rounded-t px-3 py-2 text-xs capitalize ${
            t === active ? 'bg-sky-100 font-semibold text-blue-700' : 'bg-slate-100 text-slate-600'
          }`}
        >
          {t}
        </span>
      ))}
    </div>
  )
}

function TenderHead({ highlight }: { highlight?: 'match' | 'excel' }) {
  return (
    <>
      <p className="text-xs text-blue-600">← Lista przetargów</p>
      <h1 className="mt-2 text-xl font-semibold">PRZ/2026/0004 · Pakiet rękawic Q2</h1>
      <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
        <p className="text-xs text-slate-500">
          Mittal · opiekun Artur · <strong>wycena</strong> · AI 85% · marża 18% · edycja włączona
        </p>
        <div className="flex flex-wrap gap-1">
          {highlight === 'match' ? (
            <Mark>
              <span className="rounded bg-violet-600 px-2 py-1.5 text-[11px] text-white">Dopasuj AI (puste)</span>
            </Mark>
          ) : (
            <span className="rounded bg-violet-600 px-2 py-1.5 text-[11px] text-white">Dopasuj AI (puste)</span>
          )}
          <span className="rounded bg-violet-800 px-2 py-1.5 text-[11px] text-white">Dopasuj AI (wszystkie)</span>
          <span className="rounded bg-amber-500 px-2 py-1.5 text-[11px] font-semibold text-white">
            Zastosuj tańsze zamienniki
          </span>
          {highlight === 'excel' ? (
            <Mark>
              <span className="rounded bg-emerald-600 px-2 py-1.5 text-[11px] text-white">Eksport Excel</span>
            </Mark>
          ) : (
            <span className="rounded bg-emerald-600 px-2 py-1.5 text-[11px] text-white">Eksport Excel</span>
          )}
          <span className="rounded bg-emerald-800 px-2 py-1.5 text-[11px] text-white">PDF</span>
          <span className="rounded bg-sky-700 px-2 py-1.5 text-[11px] text-white">DOCX</span>
        </div>
      </div>
    </>
  )
}

function TenderListTable({ warn }: { warn?: boolean }) {
  return (
    <Card>
      <table className="w-full text-left text-xs">
        <thead>
          <tr className="border-b bg-slate-50">
            <Th>Numer</Th>
            <Th>Klient</Th>
            <Th>Termin</Th>
            <Th>Wartość</Th>
            <Th>Poz.</Th>
            <Th>Status</Th>
            <Th>AI %</Th>
            <Th>Opiekun</Th>
          </tr>
        </thead>
        <tbody>
          <tr className="border-b">
            <td className="p-2 font-medium text-blue-600">PRZ/2026/0004</td>
            <td className="p-2">Mittal</td>
            <td className="p-2">
              2026-09-12
              {warn && <span className="ml-1 font-semibold text-red-600">!</span>}
            </td>
            <td className="p-2">9 661,34 zł</td>
            <td className="p-2">12</td>
            <td className="p-2">wycena</td>
            <td className="p-2">85%</td>
            <td className="p-2">Artur</td>
          </tr>
          <tr className="border-b">
            <td className="p-2 font-medium text-blue-600">PRZ/2026/0003</td>
            <td className="p-2">Sanitex</td>
            <td className="p-2">2026-10-02</td>
            <td className="p-2">—</td>
            <td className="p-2">4</td>
            <td className="p-2">szkic</td>
            <td className="p-2">0%</td>
            <td className="p-2">Artur</td>
          </tr>
        </tbody>
      </table>
    </Card>
  )
}

function DashboardHelp() {
  return (
    <Slideshow
      title="Dashboard"
      slides={[
        {
          action: 'Podgląd pulpitów',
          does: 'Po zalogowaniu widzisz liczby: ile masz spraw, ile czeka na Twoją akceptację i które terminy się kończą.',
          click: 'Nic — to pierwszy ekran. Przeczytaj kafelki.',
          tone: 'slate',
          screen: (
            <AppFrame nav="Dashboard">
              <h1 className="mb-4 text-xl font-semibold">Dashboard</h1>
              <div className="mb-4 grid gap-3 sm:grid-cols-3">
                {[
                  ['3', 'Moje przetargi'],
                  ['184 200 zł', 'Wartość ofert'],
                  ['18%', 'Śr. marża'],
                  ['2', 'Do mojej akceptacji'],
                  ['1', 'Deadline < 7 dni'],
                  ['1', 'Zamienniki do akcept.'],
                ].map(([v, l]) => (
                  <div key={l} className="rounded-xl bg-white p-4 text-center shadow-sm">
                    <b className="block text-2xl text-blue-600">{v}</b>
                    <span className="text-xs text-slate-500">{l}</span>
                    {l !== 'Wartość ofert' && l !== 'Śr. marża' && (
                      <span className="mt-1 block text-[11px] text-blue-600">Zobacz</span>
                    )}
                  </div>
                ))}
              </div>
            </AppFrame>
          ),
        },
        {
          action: 'Wejście w pilny termin',
          does: 'Kafelek „Deadline < 7 dni” otwiera listę tylko tych przetargów, którym kończy się czas.',
          click: '„Zobacz” pod kafelkiem z czerwoną / dużą liczbą.',
          tone: 'blue',
          screen: (
            <AppFrame nav="Dashboard">
              <h1 className="mb-4 text-xl font-semibold">Dashboard</h1>
              <div className="max-w-xs rounded-xl border-2 border-amber-400 bg-white p-4 text-center shadow-sm">
                <b className="block text-2xl text-blue-600">1</b>
                <span className="text-xs text-slate-500">Deadline &lt; 7 dni</span>
                <Mark>
                  <span className="mt-1 inline-block text-[11px] text-blue-600">Zobacz</span>
                </Mark>
              </div>
            </AppFrame>
          ),
        },
        {
          action: 'Otwarcie sprawy z listy',
          does: 'Lista jest już przefiltrowana. Czerwony wykrzyknik przy dacie oznacza termin w ciągu 7 dni.',
          click: 'Niebieski numer przetargu (np. PRZ/2026/0004).',
          tone: 'blue',
          screen: (
            <AppFrame nav="Przetargi">
              <div className="mb-4 flex items-center justify-between">
                <h1 className="text-xl font-semibold">Przetargi</h1>
                <select className="rounded border border-slate-300 px-2 py-1.5 text-xs">
                  <option>Deadline &lt; 7 dni</option>
                </select>
              </div>
              <TenderListTable warn />
            </AppFrame>
          ),
        },
        {
          action: 'Praca w projekcie',
          does: 'Jesteś w sprawie. Tu dopasowujesz produkty, wgrywasz SIWZ i liczysz ofertę.',
          click: 'Zakładki pod nagłówkiem: pozycje, dokumenty, oferta.',
          tone: 'green',
          screen: (
            <AppFrame nav="Przetargi">
              <TenderHead />
              <TenderTabs active="pozycje" />
              <Card>
                <p className="text-xs text-slate-500">12 pozycji · pokrycie 10/12</p>
              </Card>
            </AppFrame>
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
          action: 'Start nowego przetargu',
          does: 'Z listy wszystkich spraw otwierasz pusty formularz — jeszcze nic nie zapisuje.',
          click: 'Niebieski przycisk „+ Nowy przetarg” po prawej.',
          tone: 'blue',
          screen: (
            <AppFrame nav="Przetargi">
              <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-xl font-semibold">Przetargi</h1>
                <div className="flex flex-wrap items-center gap-2">
                  <select className="rounded border border-slate-300 px-2 py-1.5 text-xs">
                    <option>Wszystkie przetargi</option>
                  </select>
                  <Mark>
                    <Btn label="+ Nowy przetarg" />
                  </Mark>
                </div>
              </div>
              <TenderListTable />
            </AppFrame>
          ),
        },
        {
          action: 'Utworzenie sprawy',
          does: 'Zapisuje nowy przetarg z tytułem, klientem i terminem, potem od razu otwiera projekt.',
          click: 'Wypełnij pola i „Utwórz”.',
          tone: 'blue',
          screen: (
            <AppFrame nav="Przetargi">
              <div className="mb-4 flex items-center justify-between">
                <h1 className="text-xl font-semibold">Przetargi</h1>
                <Btn label="+ Nowy przetarg" />
              </div>
              <Card className="mb-4 text-sm">
                <h2 className="mb-3 font-semibold">Nowy przetarg</h2>
                <div className="grid gap-3 sm:grid-cols-3">
                  <Field label="Tytuł" value="Pakiet rękawic Q2" mark />
                  <Field label="Klient" value="Mittal" />
                  <Field label="Termin" value="12.09.2026" />
                </div>
                <div className="mt-3">
                  <Mark>
                    <Btn label="Utwórz" />
                  </Mark>
                </div>
              </Card>
              <TenderListTable />
            </AppFrame>
          ),
        },
        {
          action: 'Wejście w dokumenty',
          does: 'Zakładka Dokumenty służy do wrzucenia SIWZ / formularza — stąd AI wyciągnie pozycje.',
          click: 'Zakładka „dokumenty” pod nagłówkiem sprawy.',
          tone: 'blue',
          screen: (
            <AppFrame nav="Przetargi">
              <TenderHead />
              <div className="mb-3 flex flex-wrap gap-1 border-b border-slate-200 pb-2">
                {['pozycje', 'dokumenty', 'warunki', 'oferta'].map((t) =>
                  t === 'dokumenty' ? (
                    <Mark key={t}>
                      <span className="rounded-t bg-sky-100 px-3 py-2 text-xs font-semibold capitalize text-blue-700">
                        {t}
                      </span>
                    </Mark>
                  ) : (
                    <span key={t} className="rounded-t bg-slate-100 px-3 py-2 text-xs capitalize text-slate-600">
                      {t}
                    </span>
                  ),
                )}
              </div>
              <Card>
                <h2 className="mb-2 text-sm font-semibold">Import dokumentu SIWZ</h2>
                <p className="text-xs text-slate-500">PDF, Excel (xlsx/xls/csv), Word (doc/docx)</p>
              </Card>
            </AppFrame>
          ),
        },
        {
          action: 'Wgranie SIWZ',
          does: 'Wrzucasz plik specyfikacji. System go zapamięta i będzie mógł wyciągnąć pozycje oraz warunki.',
          click: '„Wybierz plik” albo upuść PDF/XLSX w polu.',
          tone: 'blue',
          screen: (
            <AppFrame nav="Przetargi">
              <TenderHead />
              <TenderTabs active="dokumenty" />
              <Card>
                <h2 className="mb-2 text-sm font-semibold">Import dokumentu SIWZ</h2>
                <p className="mb-3 text-xs text-slate-500">
                  PDF, Excel (xlsx/xls/csv), Word (doc/docx) → pozycje i/lub warunki.
                </p>
                <Mark>
                  <span className="inline-flex cursor-pointer items-center rounded bg-blue-600 px-3 py-2 text-xs font-medium text-white">
                    Wybierz plik
                  </span>
                </Mark>
                <p className="mt-2 text-xs text-slate-600">
                  Wybrano: <span className="font-medium">SIWZ_Mittal.pdf</span>
                </p>
              </Card>
            </AppFrame>
          ),
        },
        {
          action: 'Analiza SIWZ przez AI',
          does: 'Model czyta dokument i tworzy puste wiersze oferty (nazwy z SIWZ, jeszcze bez produktu z katalogu).',
          click: '„Analizuj AI” i czekaj — nie odświeżaj strony.',
          tone: 'violet',
          screen: (
            <AppFrame nav="Przetargi">
              <TenderHead />
              <TenderTabs active="dokumenty" />
              <Card>
                <Mark>
                  <span className="rounded bg-violet-600 px-3 py-2 text-xs font-medium text-white">Analizuj AI</span>
                </Mark>
                <div className="mt-4 rounded-lg border border-violet-100 bg-violet-50 px-3 py-3">
                  <div className="mb-1.5 flex justify-between text-xs">
                    <span className="font-medium text-violet-900">
                      Analiza AI w toku
                      <span className="ml-2 inline-block h-2 w-2 animate-pulse rounded-full bg-violet-500" />
                    </span>
                    <span className="tabular-nums text-violet-800">42% · 18s</span>
                  </div>
                  <div className="h-2 overflow-hidden rounded-full bg-violet-100">
                    <div className="h-full w-2/5 rounded-full bg-violet-600" />
                  </div>
                </div>
              </Card>
            </AppFrame>
          ),
        },
        {
          action: 'Lista pustych pozycji',
          does: 'W „pozycje” widać wymagania z SIWZ. Kolumna „Produkt główny” jest pusta — oferty jeszcze nie ma.',
          click: 'Zakładka „pozycje”. Nic nie zapisuj, dopóki nie dopasujesz.',
          tone: 'slate',
          screen: (
            <AppFrame nav="Przetargi">
              <TenderHead />
              <TenderTabs active="pozycje" />
              <Card>
                <table className="w-full text-left text-xs">
                  <thead>
                    <tr className="border-b bg-slate-50">
                      <Th>Lp</Th>
                      <Th>SIWZ</Th>
                      <Th>Produkt główny</Th>
                      <Th>Ilość</Th>
                      <Th>Cena oferty</Th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr className="border-b">
                      <td className="p-2">1</td>
                      <td className="p-2">Rękawice nitrylowe L</td>
                      <td className="p-2 text-slate-400">—</td>
                      <td className="p-2">200</td>
                      <td className="p-2 text-slate-400">—</td>
                    </tr>
                    <tr className="border-b">
                      <td className="p-2">2</td>
                      <td className="p-2">Okulary UV</td>
                      <td className="p-2 text-slate-400">—</td>
                      <td className="p-2">50</td>
                      <td className="p-2 text-slate-400">—</td>
                    </tr>
                  </tbody>
                </table>
              </Card>
            </AppFrame>
          ),
        },
        {
          action: 'Dopasowanie pustych do katalogu',
          does: 'AI szuka w bazie produktów SKU do każdej pustej pozycji i zapisuje je od razu. Ręcznych własnych ofert nie rusza.',
          click: 'Fioletowy „Dopasuj AI (puste)” nad sprawą.',
          tone: 'violet',
          screen: (
            <AppFrame nav="Przetargi">
              <TenderHead highlight="match" />
              <TenderTabs active="pozycje" />
              <div className="rounded-xl border border-violet-200 bg-white p-4 text-sm shadow-xl">
                <p className="font-semibold text-slate-900">Trwa dopasowanie AI…</p>
                <p className="mt-1 text-xs text-slate-600">Nie odświeżaj strony.</p>
                <p className="mt-3 font-mono text-2xl font-semibold text-violet-800">7 / 12</p>
                <p className="text-xs text-slate-500">sprawdzonych pozycji</p>
                <div className="mt-2 h-2 overflow-hidden rounded-full bg-slate-200">
                  <div className="h-full w-3/5 rounded-full bg-violet-600" />
                </div>
                <p className="mt-2 font-mono text-sm text-violet-800">24 s</p>
              </div>
            </AppFrame>
          ),
        },
        {
          action: 'Wynik dopasowania',
          does: 'Wiersze dostają SKU z katalogu. Fioletowa ramka = AI właśnie zmieniło ten wiersz. Sprawdź procent dopasowania.',
          click: 'Nic — przeglądasz. Słabe % poprawiasz lupką w następnym kroku.',
          tone: 'violet',
          screen: (
            <AppFrame nav="Przetargi">
              <TenderHead />
              <div className="mb-3 rounded-xl border border-violet-200 bg-violet-50 p-3 text-xs text-violet-950">
                <strong>Ostatnie dopasowanie AI</strong>
                <p className="mt-1">Przerobiono 12 · zmieniono 10 · bez produktu 2</p>
              </div>
              <TenderTabs active="pozycje" />
              <Card>
                <table className="w-full text-left text-xs">
                  <thead>
                    <tr className="border-b bg-slate-50">
                      <Th>Lp</Th>
                      <Th>SIWZ</Th>
                      <Th>Produkt główny</Th>
                      <Th>AI</Th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr className="border-b bg-violet-50">
                      <td className="p-2">1</td>
                      <td className="p-2">Rękawice nitrylowe L</td>
                      <td className="p-2 font-medium">RNITZ-100 · nitryl L</td>
                      <td className="p-2">91%</td>
                    </tr>
                    <tr className="border-b bg-violet-50">
                      <td className="p-2">2</td>
                      <td className="p-2">Okulary UV</td>
                      <td className="p-2 font-medium">UVX-UNIDUR · uvex</td>
                      <td className="p-2">88%</td>
                    </tr>
                  </tbody>
                </table>
              </Card>
            </AppFrame>
          ),
        },
        {
          action: 'Kontrola pokrycia oferty',
          does: 'Pasek pokazuje, czy oferta jest kompletna. Żółte przyciski filtrują tylko problematyczne wiersze.',
          click: 'Np. „Bez ceny” albo „Słabe AI”, żeby zobaczyć tylko te pozycje.',
          tone: 'amber',
          screen: (
            <AppFrame nav="Przetargi">
              <TenderHead />
              <div className="mb-3 rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs">
                <div className="mb-2 flex justify-between">
                  <strong>Pokrycie oferty: wymaga uzupełnienia</strong>
                  <span className="text-slate-600">10/12 z produktem</span>
                </div>
                <div className="flex flex-wrap gap-1.5">
                  <span className="rounded bg-white px-2 py-1 text-slate-700">Bez produktu (2)</span>
                  <Mark>
                    <span className="rounded bg-slate-800 px-2 py-1 text-white">Bez ceny (1)</span>
                  </Mark>
                  <span className="rounded bg-white px-2 py-1 text-slate-700">Słabe AI (1)</span>
                  <span className="rounded bg-white/60 px-2 py-1 text-slate-400">Niska marża (0)</span>
                </div>
              </div>
            </AppFrame>
          ),
        },
        {
          action: 'Ręczna zmiana produktu',
          does: 'Niebieski „Szukaj” w wierszu szuka po nazwie/SKU jak na liście produktów — bez AI. Wybierasz inny SKU, gdy AI trafiło słabo albo klient chce konkretną markę.',
          click: 'Przycisk „Szukaj” przy pozycji, potem „Wybierz”.',
          tone: 'blue',
          screen: (
            <AppFrame nav="Przetargi">
              <TenderHead />
              <TenderTabs active="pozycje" />
              <Card>
                <div className="mb-3 flex flex-wrap gap-1.5">
                  <Mark>
                    <span className="rounded bg-sky-600 px-2 py-1 text-white">Szukaj</span>
                  </Mark>
                  <span className="rounded bg-violet-600 px-2 py-1 text-white">AI</span>
                </div>
                <input
                  readOnly
                  className="mb-3 w-full rounded border border-slate-300 px-2 py-1.5 text-xs"
                  value="Adapter P3E do hełmu 3M"
                />
                <table className="w-full text-left text-xs">
                  <thead>
                    <tr className="border-b bg-slate-50">
                      <Th>SKU</Th>
                      <Th>Nazwa</Th>
                      <Th>Cena</Th>
                      <Th></Th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr className="border-b bg-blue-50">
                      <td className="p-2 font-medium">A611</td>
                      <td className="p-2">Rękawice do 500°C</td>
                      <td className="p-2">48,20 zł</td>
                      <td className="p-2">
                        <Mark>
                          <Btn label="Wybierz" />
                        </Mark>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </Card>
            </AppFrame>
          ),
        },
        {
          action: 'Przekazanie do akceptacji',
          does: 'Zmiana statusu puszcza sprawę dalej: szkic → wycena → kierownik → dyrektor. Bez tego oferta zostaje u Ciebie.',
          click: 'Przycisk następnego statusu w workflow (zależnie od roli).',
          tone: 'blue',
          screen: (
            <AppFrame nav="Przetargi">
              <TenderHead />
              <Card>
                <p className="mb-3 text-xs font-semibold">Status sprawy</p>
                <div className="flex flex-wrap items-center gap-2 text-xs">
                  <span className="rounded bg-slate-100 px-2 py-1">szkic</span>
                  <span className="text-slate-400">→</span>
                  <span className="rounded bg-blue-600 px-2 py-1 text-white">wycena</span>
                  <span className="text-slate-400">→</span>
                  <Mark>
                    <span className="rounded bg-amber-500 px-3 py-2 text-xs font-medium text-white">
                      Wyślij do kierownika
                    </span>
                  </Mark>
                  <span className="text-slate-400">→</span>
                  <span className="rounded bg-emerald-100 px-2 py-1">dyrektor</span>
                </div>
              </Card>
            </AppFrame>
          ),
        },
        {
          action: 'Pobranie oferty',
          does: 'Ściąga plik z cenami: Excel do pracy, PDF do wysyłki, DOCX gdy wgrano formularz klienta.',
          click: '„Eksport Excel”, „PDF” albo „DOCX” w prawym górnym rogu sprawy.',
          tone: 'green',
          screen: (
            <AppFrame nav="Przetargi">
              <TenderHead highlight="excel" />
              <div className="mb-3 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-xs">
                <strong>Pokrycie oferty: gotowa do akceptacji</strong>
                <span className="ml-2 text-slate-600">12/12 z produktem</span>
              </div>
              <p className="rounded bg-green-50 px-3 py-2 text-xs text-green-800">Pobrano Excel.</p>
            </AppFrame>
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
          action: 'Przegląd katalogu',
          does: 'Tu jest baza SKU z cenami z cenników. Szukasz po kodzie, nazwie albo producencie.',
          click: 'Menu „Produkty”, potem pole „Szukaj w katalogu” albo przycisk Szukaj.',
          tone: 'slate',
          screen: (
            <AppFrame nav="Produkty">
              <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-xl font-semibold">Produkty</h1>
                <div className="flex flex-wrap gap-2">
                  <select className="rounded border border-slate-300 px-3 py-2 text-sm">
                    <option>Wszyscy producenci</option>
                  </select>
                  <Mark>
                    <div className="flex overflow-hidden rounded-lg border-2 border-slate-400 bg-white">
                      <input
                        readOnly
                        className="w-52 border-0 px-3 py-2 text-sm outline-none"
                        placeholder="Kod, nazwa lub producent…"
                      />
                      <span className="bg-slate-800 px-3 py-2 text-sm text-white">Szukaj</span>
                    </div>
                  </Mark>
                </div>
              </div>
              <Card>
                <table className="w-full text-left text-xs">
                  <thead>
                    <tr className="border-b bg-slate-50">
                      <Th>SKU</Th>
                      <Th>Nazwa</Th>
                      <Th>Producent</Th>
                      <Th>Cena</Th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr className="border-b">
                      <td className="p-2 font-mono">ARĘKGLOMJ713</td>
                      <td className="p-2">Lebon POWERCUT</td>
                      <td className="p-2">Lebon</td>
                      <td className="p-2">12,40 zł</td>
                    </tr>
                    <tr className="border-b">
                      <td className="p-2 font-mono">UVX-UNIDUR</td>
                      <td className="p-2">uvex Unidur</td>
                      <td className="p-2">uvex</td>
                      <td className="p-2">18,90 zł</td>
                    </tr>
                  </tbody>
                </table>
              </Card>
            </AppFrame>
          ),
        },
        {
          action: 'Szybkie wyszukanie po kodzie',
          does: 'Lista zawęża się na żywo. Kliknięcie wiersza otwiera kartę produktu.',
          click: 'Wpisz np. POWERCUT, potem kliknij wiersz.',
          tone: 'blue',
          screen: (
            <AppFrame nav="Produkty">
              <div className="mb-4 flex justify-end">
                <Mark>
                  <input
                    readOnly
                    className="w-56 rounded border border-blue-400 bg-blue-50 px-3 py-2 text-sm"
                    value="POWERCUT"
                  />
                </Mark>
              </div>
              <Card>
                <table className="w-full text-left text-xs">
                  <thead>
                    <tr className="border-b bg-slate-50">
                      <Th>SKU</Th>
                      <Th>Nazwa</Th>
                      <Th>Cena</Th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr className="border-b bg-blue-50">
                      <td className="p-2 font-mono">ARĘKGLOMJ713</td>
                      <td className="p-2">Lebon POWERCUT</td>
                      <td className="p-2">12,40 zł</td>
                    </tr>
                  </tbody>
                </table>
              </Card>
            </AppFrame>
          ),
        },
        {
          action: 'Szukanie po zastosowaniu (AI)',
          does: 'Gdy nie znasz SKU, opisujesz potrzebę (norma, temperatura, substancja). AI szuka po opisach w katalogu.',
          click: 'Pole „Wymaganie dla AI”, potem fioletowy „Szukaj AI”.',
          tone: 'violet',
          screen: (
            <AppFrame nav="Produkty">
              <h1 className="mb-4 text-xl font-semibold">Produkty</h1>
              <div className="rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
                <label className="mb-1 block text-xs font-medium text-slate-600">
                  Wymaganie dla AI (np. rękawice do pracy z amoniakiem)
                </label>
                <div className="flex flex-wrap items-end gap-2">
                  <Mark>
                    <textarea
                      readOnly
                      rows={2}
                      className="min-h-[2.5rem] w-full max-w-xl flex-1 rounded border border-slate-300 px-3 py-2 text-sm"
                      value="rękawice do pieca szkła 500°C"
                    />
                  </Mark>
                  <Mark>
                    <span className="rounded bg-indigo-600 px-3 py-2 text-xs text-white">Szukaj AI</span>
                  </Mark>
                  <span className="rounded bg-red-600 px-3 py-2 text-xs text-white">AI Internet</span>
                </div>
              </div>
            </AppFrame>
          ),
        },
        {
          action: 'Karta produktu',
          does: 'Pokazuje SKU, cenę katalogową, opis, zdjęcia i zamienniki — to źródło kodu do oferty.',
          click: 'Wiersz na liście albo numer SKU w przetargu.',
          tone: 'blue',
          screen: (
            <AppFrame nav="Produkty">
              <p className="text-xs text-blue-600">← Produkty</p>
              <h1 className="mt-2 text-xl font-semibold">Lebon POWERCUT</h1>
              <p className="mb-3 text-sm text-slate-500">ARĘKGLOMJ713 · Lebon · EN 388</p>
              <Card>
                <p className="text-xs text-slate-500">Cena katalogowa</p>
                <p className="text-2xl font-semibold text-blue-600">12,40 zł</p>
                <p className="mt-2 text-xs text-slate-500">Opis/zdjęcia: <b>Gotowe</b></p>
              </Card>
            </AppFrame>
          ),
        },
        {
          action: 'Użycie SKU w ofercie',
          does: 'Ten kod wklejasz w pozycji przetargu albo wybierasz lupką — cena weźmie się z cennika.',
          click: 'Skopiuj SKU i wróć do zakładki Przetargi.',
          tone: 'green',
          screen: (
            <AppFrame nav="Przetargi">
              <TenderHead />
              <TenderTabs active="pozycje" />
              <Card>
                <table className="w-full text-left text-xs">
                  <thead>
                    <tr className="border-b bg-slate-50">
                      <Th>SIWZ</Th>
                      <Th>Produkt główny</Th>
                      <Th>Cena oferty</Th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr className="border-b bg-emerald-50">
                      <td className="p-2">Rękawice cięte</td>
                      <td className="p-2 font-medium">ARĘKGLOMJ713 · POWERCUT</td>
                      <td className="p-2">12,40 zł</td>
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

function PriceListsHelp() {
  return (
    <Slideshow
      title="Cenniki"
      slides={[
        {
          action: 'Import cennika do bazy',
          does: 'Formularz wrzuca XLSX/PDF producenta do katalogu produktów (nazwa, kod, cena, grupa, upust).',
          click: 'Menu „Cenniki” — karta „Import cennika → baza produktów”.',
          tone: 'slate',
          screen: (
            <AppFrame nav="Cenniki">
              <h1 className="mb-2 text-xl font-semibold">Cenniki producentów</h1>
              <p className="mb-4 text-xs text-slate-500">
                Importujemy: <strong>nazwa</strong>, <strong>symbol/kod</strong>, <strong>cena</strong>…
              </p>
              <Card className="text-sm">
                <h2 className="mb-3 font-semibold">Import cennika → baza produktów</h2>
                <div className="grid gap-3 sm:grid-cols-3">
                  <Field label="Producent" placeholder="z nazwy / treści pliku" />
                  <Field label="Wersja cennika" placeholder="z nazwy pliku" />
                  <Field label="Kategoria domyślna" placeholder="opcjonalnie" />
                </div>
              </Card>
            </AppFrame>
          ),
        },
        {
          action: 'Uzupełnienie nagłówka importu',
          does: 'Producent i wersja opiszą historię cennika. Możesz zostawić puste — AI spróbuje zgadnąć z nazwy pliku.',
          click: 'Pole „Producent”, np. Lebon.',
          tone: 'blue',
          screen: (
            <AppFrame nav="Cenniki">
              <h1 className="mb-4 text-xl font-semibold">Cenniki producentów</h1>
              <Card className="text-sm">
                <h2 className="mb-3 font-semibold">Import cennika → baza produktów</h2>
                <div className="grid gap-3 sm:grid-cols-3">
                  <Field label="Producent" value="Lebon" mark />
                  <Field label="Wersja cennika" value="2026-08" />
                  <Field label="Kategoria domyślna" placeholder="opcjonalnie" />
                </div>
              </Card>
            </AppFrame>
          ),
        },
        {
          action: 'Wybór pliku cennika',
          does: 'Wskazujesz XLSX, CSV albo PDF. Duży skan PDF trwa dłużej przy analizie.',
          click: 'Czarny przycisk „Przeglądaj…”.',
          tone: 'blue',
          screen: (
            <AppFrame nav="Cenniki">
              <h1 className="mb-4 text-xl font-semibold">Cenniki producentów</h1>
              <Card className="text-sm">
                <h2 className="mb-3 font-semibold">Import cennika → baza produktów</h2>
                <p className="mb-1 text-xs">Plik cennika (XLSX / XLS / CSV / PDF) *</p>
                <div className="flex flex-wrap items-center gap-2">
                  <Mark>
                    <span className="inline-flex rounded-md bg-slate-800 px-4 py-2.5 text-sm font-semibold text-white">
                      Przeglądaj…
                    </span>
                  </Mark>
                  <span className="text-xs text-slate-600">
                    Wybrano: <span className="font-medium text-slate-800">lebon_2026.xlsx</span>
                  </span>
                </div>
              </Card>
            </AppFrame>
          ),
        },
        {
          action: 'Analiza kolumn przez AI',
          does: 'Model odczytuje, która kolumna to SKU, nazwa i cena. Nic jeszcze nie zapisuje do bazy.',
          click: '„1. Analizuj AI” i czekaj (duży PDF = minuty).',
          tone: 'violet',
          screen: (
            <AppFrame nav="Cenniki">
              <h1 className="mb-4 text-xl font-semibold">Cenniki producentów</h1>
              <Card className="text-sm">
                <div className="flex flex-wrap gap-2">
                  <Mark>
                    <span className="inline-flex rounded-md bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white">
                      Analizuję… 42%
                    </span>
                  </Mark>
                  <span className="inline-flex rounded-md bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white opacity-50">
                    2. Importuj wg AI
                  </span>
                </div>
                <div className="mt-4 rounded-lg border border-indigo-100 bg-indigo-50/80 px-3 py-3">
                  <div className="mb-1.5 flex justify-between text-xs">
                    <span className="font-medium text-indigo-900">Analiza AI w toku</span>
                    <span className="tabular-nums text-indigo-800">42% · 28s</span>
                  </div>
                  <div className="h-2 overflow-hidden rounded-full bg-indigo-100">
                    <div className="h-full w-2/5 rounded-full bg-indigo-600" />
                  </div>
                </div>
              </Card>
            </AppFrame>
          ),
        },
        {
          action: 'Sprawdzenie mapowania',
          does: 'Podgląd pokazuje, czy AI dobrze rozpoznało kolumny. Zła mapa = złe ceny w katalogu.',
          click: 'Przejrzyj SKU / nazwę / cenę. Jak źle — popraw i analizuj ponownie.',
          tone: 'amber',
          screen: (
            <AppFrame nav="Cenniki">
              <h1 className="mb-4 text-xl font-semibold">Cenniki producentów</h1>
              <Card>
                <h2 className="mb-2 text-sm font-semibold">Podgląd kolumn</h2>
                <table className="w-full text-left text-xs">
                  <thead>
                    <tr className="border-b bg-slate-50">
                      <Th>SKU</Th>
                      <Th>Nazwa</Th>
                      <Th>Cena</Th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr className="border-b">
                      <td className="p-2 font-mono">ARĘKGLOMJ713</td>
                      <td className="p-2">POWERCUT</td>
                      <td className="p-2">12,40</td>
                    </tr>
                    <tr className="border-b">
                      <td className="p-2 font-mono">ARĘKGLOMJ714</td>
                      <td className="p-2">POWERFIT</td>
                      <td className="p-2">9,80</td>
                    </tr>
                  </tbody>
                </table>
              </Card>
            </AppFrame>
          ),
        },
        {
          action: 'Zapis do katalogu',
          does: 'Dopiero ten krok tworzy / aktualizuje produkty i ceny. Poprzedni był tylko analizą.',
          click: 'Niebieski „2. Importuj wg AI”.',
          tone: 'blue',
          screen: (
            <AppFrame nav="Cenniki">
              <h1 className="mb-4 text-xl font-semibold">Cenniki producentów</h1>
              <Card className="text-sm">
                <div className="flex flex-wrap gap-2">
                  <span className="inline-flex rounded-md bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white">
                    1. Analizuj AI
                  </span>
                  <Mark>
                    <span className="inline-flex rounded-md bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white">
                      2. Importuj wg AI
                    </span>
                  </Mark>
                </div>
                <p className="mt-4 rounded bg-green-50 px-3 py-2 text-xs text-green-800">+ 86 produktów</p>
              </Card>
            </AppFrame>
          ),
        },
        {
          action: 'Weryfikacja w Produktach',
          does: 'Po imporcie szukasz nazwy z cennika na liście produktów — potwierdzasz, że cena weszła.',
          click: 'Menu „Produkty”, wpisz nazwę z cennika.',
          tone: 'blue',
          screen: (
            <AppFrame nav="Produkty">
              <div className="mb-4 flex justify-end">
                <Mark>
                  <input
                    readOnly
                    className="w-56 rounded border border-blue-400 bg-blue-50 px-3 py-2 text-sm"
                    value="POWERCUT"
                  />
                </Mark>
              </div>
              <Card>
                <table className="w-full text-left text-xs">
                  <thead>
                    <tr className="border-b bg-slate-50">
                      <Th>SKU</Th>
                      <Th>Nazwa</Th>
                      <Th>Cena</Th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr className="border-b">
                      <td className="p-2 font-mono">ARĘKGLOMJ713</td>
                      <td className="p-2">Lebon POWERCUT</td>
                      <td className="p-2">12,40 zł</td>
                    </tr>
                  </tbody>
                </table>
              </Card>
            </AppFrame>
          ),
        },
        {
          action: 'Cena katalogowa na karcie',
          does: 'Karta pokazuje aktualną cenę z wgranego cennika — tę kwotę system podpowie w ofercie.',
          click: 'Otwórz wiersz produktu.',
          tone: 'green',
          screen: (
            <AppFrame nav="Produkty">
              <p className="text-xs text-blue-600">← Produkty</p>
              <h1 className="mt-2 text-xl font-semibold">Lebon POWERCUT</h1>
              <p className="mb-3 text-sm text-slate-500">ARĘKGLOMJ713 · Lebon</p>
              <Card>
                <p className="text-xs text-slate-500">Cena katalogowa</p>
                <p className="text-2xl font-semibold text-blue-600">12,40 zł</p>
                <p className="mt-1 text-xs text-emerald-700">z cennika Lebon 2026-08</p>
              </Card>
            </AppFrame>
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
          action: 'Przegląd par zamienników',
          does: 'Każda karta to produkt główny, a pod nim zamienniki (tańszy / równoważny / premium) ze statusem akceptacji.',
          click: 'Menu „Zamienniki”.',
          tone: 'slate',
          screen: (
            <AppFrame nav="Zamienniki">
              <div className="mb-4 flex items-center justify-between">
                <div>
                  <h1 className="text-xl font-semibold">Zamienniki</h1>
                  <p className="mt-1 text-xs text-slate-500">Relacja produkt główny → zamienniki · 12 pozycji</p>
                </div>
                <Btn label="+ Dodaj zamiennik" />
              </div>
              <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
                <div className="border-b bg-slate-50 px-4 py-3 text-sm font-semibold">
                  <span className="mr-1 rounded bg-slate-800 px-1.5 py-0.5 text-[10px] text-white">
                    PRODUKT GŁÓWNY
                  </span>
                  Lebon POWERCUT · ARĘKGLOMJ713
                </div>
                <table className="w-full text-left text-xs">
                  <thead>
                    <tr className="border-b bg-slate-50/80">
                      <Th>Zamiennik</Th>
                      <Th>Typ</Th>
                      <Th>AI</Th>
                      <Th>Status</Th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr className="border-b">
                      <td className="p-2">POWERFIT (ARĘKGLOMJ714)</td>
                      <td className="p-2">tańszy</td>
                      <td className="p-2">86%</td>
                      <td className="p-2">
                        <span className="rounded bg-emerald-100 px-1.5 py-0.5 text-[10px] text-emerald-800">
                          zatwierdzony
                        </span>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </AppFrame>
          ),
        },
        {
          action: 'Dodanie nowej pary',
          does: 'Otwiera formularz: wybierasz główny produkt, zamiennik, typ i zgodność.',
          click: '„+ Dodaj zamiennik”.',
          tone: 'blue',
          screen: (
            <AppFrame nav="Zamienniki">
              <div className="mb-4 flex items-center justify-between">
                <h1 className="text-xl font-semibold">Zamienniki</h1>
                <Mark>
                  <Btn label="+ Dodaj zamiennik" />
                </Mark>
              </div>
            </AppFrame>
          ),
        },
        {
          action: 'Zapis relacji',
          does: 'Tworzy parę w bazie. Zostaje ze statusem „oczekuje”, dopóki kierownik nie potwierdzi.',
          click: 'Wybierz produkty, typ, % i „Dodaj”.',
          tone: 'blue',
          screen: (
            <AppFrame nav="Zamienniki">
              <h1 className="mb-4 text-xl font-semibold">Zamienniki</h1>
              <Card className="text-sm">
                <h2 className="mb-3 font-semibold">Nowy zamiennik</h2>
                <div className="grid gap-3 sm:grid-cols-2">
                  <Field label="Produkt główny *" value="ARĘKGLOMJ713 · POWERCUT" />
                  <Field label="Zamiennik *" value="ARĘKGLOMJ714 · POWERFIT" mark />
                  <Field label="Typ *" value="tańszy" />
                  <Field label="Zgodność AI (%) *" value="86" />
                </div>
                <div className="mt-3">
                  <Mark>
                    <Btn label="Dodaj" />
                  </Mark>
                </div>
              </Card>
            </AppFrame>
          ),
        },
        {
          action: 'Oczekiwanie na akceptację',
          does: 'Para istnieje, ale oferta nie użyje jej automatycznie, dopóki status to „oczekuje”.',
          click: 'Filtr statusu albo po prostu znajdź żółty wiersz.',
          tone: 'amber',
          screen: (
            <AppFrame nav="Zamienniki">
              <h1 className="mb-4 text-xl font-semibold">Zamienniki</h1>
              <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
                <div className="border-b bg-slate-50 px-4 py-3 text-sm font-semibold">
                  <span className="mr-1 rounded bg-slate-800 px-1.5 py-0.5 text-[10px] text-white">
                    PRODUKT GŁÓWNY
                  </span>
                  Lebon POWERCUT · ARĘKGLOMJ713
                </div>
                <table className="w-full text-left text-xs">
                  <thead>
                    <tr className="border-b bg-slate-50/80">
                      <Th>Zamiennik</Th>
                      <Th>Typ</Th>
                      <Th>Status</Th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr className="border-b bg-amber-50">
                      <td className="p-2">POWERFIT (ARĘKGLOMJ714)</td>
                      <td className="p-2">tańszy</td>
                      <td className="p-2">
                        <span className="rounded bg-amber-100 px-1.5 py-0.5 text-[10px] text-amber-800">
                          oczekuje
                        </span>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </AppFrame>
          ),
        },
        {
          action: 'Akceptacja zamiennika',
          does: 'Kierownik zatwierdza parę. Dopiero wtedy „Zastosuj tańsze zamienniki” w przetargu może ją użyć.',
          click: 'Zielone „OK” albo czerwone odrzucenie w kolumnie Akcje.',
          tone: 'green',
          screen: (
            <AppFrame nav="Zamienniki">
              <h1 className="mb-4 text-xl font-semibold">Zamienniki</h1>
              <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
                <table className="w-full text-left text-xs">
                  <thead>
                    <tr className="border-b bg-slate-50">
                      <Th>Zamiennik</Th>
                      <Th>Status</Th>
                      <Th>Akcje</Th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr className="border-b">
                      <td className="p-2">POWERFIT (ARĘKGLOMJ714)</td>
                      <td className="p-2">
                        <span className="rounded bg-amber-100 px-1.5 py-0.5 text-[10px]">oczekuje</span>
                      </td>
                      <td className="p-2">
                        <div className="flex gap-1">
                          <Mark>
                            <span className="rounded bg-green-600 px-2 py-1 text-[10px] text-white">OK</span>
                          </Mark>
                          <span className="rounded border border-red-200 px-2 py-1 text-[10px] text-red-700">
                            Odrzuć
                          </span>
                        </div>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </AppFrame>
          ),
        },
        {
          action: 'Podmiana w ofercie',
          does: 'W przetargu system proponuje tańsze zatwierdzone zamienniki (≥3% po upuście) i po potwierdzeniu podmienia SKU.',
          click: '„Zastosuj tańsze zamienniki”, potem „Tak, zastosuj”.',
          tone: 'green',
          screen: (
            <AppFrame nav="Przetargi">
              <TenderHead />
              <div className="rounded-lg border-2 border-amber-400 bg-amber-50 p-3 text-xs">
                <p className="font-semibold text-amber-950">Zastosować tańsze zamienniki na 3 pozycjach?</p>
                <p className="mt-2 font-mono text-[11px]">Poz. 1: ARĘKGLOMJ713 → ARĘKGLOMJ714 (−8%)</p>
                <div className="mt-3 flex gap-2">
                  <span className="rounded border border-slate-300 bg-white px-3 py-1.5 text-[11px]">Anuluj</span>
                  <Mark>
                    <span className="rounded bg-amber-600 px-3 py-1.5 text-[11px] font-semibold text-white">
                      Tak, zastosuj
                    </span>
                  </Mark>
                </div>
              </div>
            </AppFrame>
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
          action: 'Podgląd pipeline’u',
          does: 'Zestawienie ile spraw jest w każdym statusie oraz jaka jest ich wartość i średnia marża. Tu nic nie edytujesz.',
          click: 'Menu „Raporty”.',
          tone: 'slate',
          screen: (
            <AppFrame nav="Raporty">
              <div className="mb-4 flex items-center justify-between">
                <h1 className="text-xl font-semibold">Raporty</h1>
                <span className="rounded bg-emerald-600 px-3 py-2 text-xs text-white">Eksport CSV</span>
              </div>
              <Card>
                <h2 className="mb-2 text-sm font-semibold">Pipeline wg statusu</h2>
                <table className="w-full text-left text-xs">
                  <thead>
                    <tr className="border-b bg-slate-50">
                      <Th>Status</Th>
                      <Th>Liczba</Th>
                      <Th>Wartość</Th>
                      <Th>Śr. marża</Th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr className="border-b">
                      <td className="p-2">wycena</td>
                      <td className="p-2">7</td>
                      <td className="p-2">184 200 zł</td>
                      <td className="p-2">18%</td>
                    </tr>
                    <tr className="border-b">
                      <td className="p-2">akceptacja</td>
                      <td className="p-2">2</td>
                      <td className="p-2">41 000 zł</td>
                      <td className="p-2">16%</td>
                    </tr>
                  </tbody>
                </table>
              </Card>
            </AppFrame>
          ),
        },
        {
          action: 'Wynik po opiekunie',
          does: 'Druga tabela pokazuje, kto prowadzi ile spraw i z jaką wartością — do podziału pracy.',
          click: 'Przewiń do „Wg opiekuna”.',
          tone: 'blue',
          screen: (
            <AppFrame nav="Raporty">
              <h1 className="mb-4 text-xl font-semibold">Raporty</h1>
              <Card>
                <h2 className="mb-2 text-sm font-semibold">Wg opiekuna</h2>
                <table className="w-full text-left text-xs">
                  <thead>
                    <tr className="border-b bg-slate-50">
                      <Th>Opiekun</Th>
                      <Th>Liczba</Th>
                      <Th>Wartość</Th>
                      <Th>Śr. marża</Th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr className="border-b">
                      <td className="p-2">Artur</td>
                      <td className="p-2">7</td>
                      <td className="p-2">184 200 zł</td>
                      <td className="p-2">18%</td>
                    </tr>
                  </tbody>
                </table>
              </Card>
            </AppFrame>
          ),
        },
        {
          action: 'Ściągnięcie CSV',
          does: 'Pobiera ten sam raport do Excela (raport-przetargi.csv).',
          click: 'Zielony „Eksport CSV” w prawym górnym rogu.',
          tone: 'green',
          screen: (
            <AppFrame nav="Raporty">
              <div className="mb-4 flex items-center justify-between">
                <h1 className="text-xl font-semibold">Raporty</h1>
                <Mark>
                  <span className="rounded bg-emerald-600 px-3 py-2 text-xs text-white">Eksport CSV</span>
                </Mark>
              </div>
              <p className="rounded bg-green-50 px-3 py-2 text-xs text-green-800">raport-przetargi.csv</p>
            </AppFrame>
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
          action: 'Lista firm',
          does: 'Książka klientów do wyboru w nowym przetargu. Widać NIP, miasto, liczbę spraw i opiekuna.',
          click: 'Menu „Klienci”.',
          tone: 'slate',
          screen: (
            <AppFrame nav="Klienci">
              <div className="mb-4 flex items-center justify-between">
                <h1 className="text-xl font-semibold">Klienci</h1>
                <Btn label="+ Nowy klient" />
              </div>
              <Card>
                <table className="w-full text-left text-xs">
                  <thead>
                    <tr className="border-b bg-slate-50">
                      <Th>Nazwa</Th>
                      <Th>NIP</Th>
                      <Th>Miasto</Th>
                      <Th>Przetargi</Th>
                      <Th>Opiekun</Th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr className="border-b">
                      <td className="p-2">Mittal</td>
                      <td className="p-2">5170001111</td>
                      <td className="p-2">Dąbrowa</td>
                      <td className="p-2">4</td>
                      <td className="p-2">Artur</td>
                    </tr>
                    <tr className="border-b">
                      <td className="p-2">Sanitex</td>
                      <td className="p-2">—</td>
                      <td className="p-2">Rzeszów</td>
                      <td className="p-2">2</td>
                      <td className="p-2">Artur</td>
                    </tr>
                  </tbody>
                </table>
              </Card>
            </AppFrame>
          ),
        },
        {
          action: 'Dodanie klienta',
          does: 'Zapisuje firmę do książki. Nazwa jest wymagana — bez niej nie założysz przetargu na tę firmę.',
          click: '„+ Nowy klient”, potem „Zapisz klienta”.',
          tone: 'blue',
          screen: (
            <AppFrame nav="Klienci">
              <div className="mb-4 flex items-center justify-between">
                <h1 className="text-xl font-semibold">Klienci</h1>
                <Mark>
                  <Btn label="+ Nowy klient" />
                </Mark>
              </div>
              <Card className="text-sm">
                <h2 className="mb-3 font-semibold">Nowy klient</h2>
                <div className="grid gap-3 sm:grid-cols-3">
                  <Field label="Nazwa *" value="Zakład Szkła Sp. z o.o." mark />
                  <Field label="NIP" placeholder="0000000000" />
                  <Field label="Miasto" placeholder="Rzeszów" />
                </div>
                <div className="mt-3">
                  <Mark>
                    <Btn label="Zapisz klienta" />
                  </Mark>
                </div>
              </Card>
            </AppFrame>
          ),
        },
        {
          action: 'Klient na liście',
          does: 'Firma jest w książce. Możesz ją wybrać przy tworzeniu przetargu.',
          click: 'Nic — potwierdź, że wiersz się pojawił.',
          tone: 'green',
          screen: (
            <AppFrame nav="Klienci">
              <p className="mb-2 rounded bg-green-50 px-3 py-2 text-xs text-green-800">Klient dodany.</p>
              <Card>
                <table className="w-full text-left text-xs">
                  <thead>
                    <tr className="border-b bg-slate-50">
                      <Th>Nazwa</Th>
                      <Th>Miasto</Th>
                      <Th>Przetargi</Th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr className="border-b bg-emerald-50">
                      <td className="p-2">Zakład Szkła Sp. z o.o.</td>
                      <td className="p-2">Rzeszów</td>
                      <td className="p-2">0</td>
                    </tr>
                  </tbody>
                </table>
              </Card>
            </AppFrame>
          ),
        },
        {
          action: 'Wybór klienta w przetargu',
          does: 'Nowy przetarg wymaga klienta z tej listy — bez niego nie utworzysz sprawy.',
          click: 'W formularzu „Nowy przetarg” lista „Klient”.',
          tone: 'blue',
          screen: (
            <AppFrame nav="Przetargi">
              <h1 className="mb-4 text-xl font-semibold">Przetargi</h1>
              <Card className="text-sm">
                <h2 className="mb-3 font-semibold">Nowy przetarg</h2>
                <div className="grid gap-3 sm:grid-cols-3">
                  <Field label="Tytuł" placeholder="np. Pakiet rękawic Q2" />
                  <Field label="Klient" value="Zakład Szkła Sp. z o.o." mark />
                  <Field label="Termin" placeholder="dd.mm.rrrr" />
                </div>
                <div className="mt-3">
                  <Btn label="Utwórz" />
                </div>
              </Card>
            </AppFrame>
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
          action: 'Wklejenie maila klienta',
          does: 'Z treści maila system wyciągnie produkty z katalogu i przygotuje szkic odpowiedzi. Nic nie wysyła pocztą.',
          click: 'Menu „Zapytania”, wklej całą treść w pole „Treść maila”.',
          tone: 'blue',
          screen: (
            <AppFrame nav="Zapytania">
              <h1 className="mb-1 text-xl font-semibold">Zapytania</h1>
              <p className="mb-4 text-sm text-slate-500">
                Wklej mail klienta. System dopyta tylko o niuanse, potem otworzy okno z listem do skopiowania.
              </p>
              <Card>
                <div className="grid gap-3 lg:grid-cols-[1fr_12rem]">
                  <label className="block text-xs">
                    Treść maila *
                    <Mark>
                      <div className="mt-1 min-h-[120px] w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                        Potrzebuję rękawice do pracy przy piecu szkła, ok. 500°C…
                      </div>
                    </Mark>
                  </label>
                  <div className="space-y-3">
                    <Field label="Temat (opcjonalnie)" />
                    <Field label="Ton" value="Formalny" />
                    <Btn label="Przygotuj odpowiedź" />
                  </div>
                </div>
              </Card>
            </AppFrame>
          ),
        },
        {
          action: 'Analiza zapytania',
          does: 'Model czyta mail i szuka w katalogu. Przycisk pokazuje spinner i sekundy — nie odświeżaj strony.',
          click: '„Przygotuj odpowiedź”.',
          tone: 'violet',
          screen: (
            <AppFrame nav="Zapytania">
              <h1 className="mb-4 text-xl font-semibold">Zapytania</h1>
              <Card>
                <Mark>
                  <span className="inline-flex w-full items-center justify-center gap-2 rounded bg-violet-600 px-3 py-2 text-xs font-medium text-white">
                    <span className="inline-block h-3.5 w-3.5 animate-spin rounded-full border-2 border-current border-t-transparent" />
                    Analizuję zapytanie · 9s
                  </span>
                </Mark>
              </Card>
            </AppFrame>
          ),
        },
        {
          action: 'Doprecyzowanie niuansów',
          does: 'Każda pozycja ma cytat, towar i zamienniki. Przy SKU jest Opis. Na końcu cena katalogowa albo katalog + marża (18%).',
          click: 'Chipy przy pozycji, potem „Napisz odpowiedź”.',
          tone: 'blue',
          screen: (
            <AppFrame nav="Zapytania">
              <div className="mx-auto max-w-4xl rounded-2xl bg-white shadow-sm">
                <div className="border-b border-slate-100 px-6 py-4">
                  <p className="text-base font-semibold">Doprecyzowanie</p>
                  <p className="text-sm text-slate-500">2 pozycje z zapytania — przy każdej widać cytat klienta.</p>
                </div>
                <div className="space-y-3 px-6 py-4">
                  <div className="rounded-xl border border-slate-200 bg-slate-50 p-3">
                    <p className="mb-2 text-sm font-semibold">1 · 30 szt. · rozm. 10</p>
                    <p className="mb-2 border-l-4 border-amber-400 bg-amber-50 px-2 py-1.5 text-sm">
                      30szt Rękawice chemoodporne… rozmiar 10
                    </p>
                    <p className="mb-1 text-xs font-semibold">Towar z katalogu</p>
                    <Mark>
                      <span className="rounded-full border border-blue-600 bg-blue-600 px-3 py-1 text-sm text-white">
                        A611
                      </span>
                    </Mark>
                    <span className="ml-1 rounded-full border border-violet-300 px-2 py-0.5 text-xs text-violet-800">
                      Opis
                    </span>
                    <p className="mb-1 mt-2 text-xs font-semibold">Zamienniki</p>
                    <span className="rounded-full border border-slate-300 px-3 py-1 text-sm">Tylko wskazany towar</span>
                  </div>
                  <div className="rounded-xl border border-slate-200 bg-slate-50 p-3">
                    <p className="mb-1 text-sm font-semibold">2 · 4 szt. · rozm. 43</p>
                    <p className="border-l-4 border-amber-400 bg-amber-50 px-2 py-1.5 text-sm">
                      4szt Kalosze chemoodporne… rozmiar 43
                    </p>
                  </div>
                  <p className="text-sm font-semibold">Ceny</p>
                  <span className="rounded-full border border-slate-300 px-3 py-1 text-sm">Bez ceny</span>
                  <span className="ml-1 rounded-full border border-blue-600 bg-blue-600 px-3 py-1 text-sm text-white">
                    Cena katalogowa + marża
                  </span>
                  <span className="ml-2 text-sm text-slate-600">18%</span>
                  <span className="mt-2 block w-full rounded-lg bg-blue-600 px-4 py-2.5 text-center text-sm font-medium text-white">
                    Napisz odpowiedź
                  </span>
                </div>
              </div>
            </AppFrame>
          ),
        },
        {
          action: 'Pisanie listu',
          does: 'Model składa treść z katalogu i Twoich chipów. Okno pokazuje „Model pisze list…” z licznikiem sekund.',
          click: 'Nic — czekaj, nie zamykaj okna.',
          tone: 'violet',
          screen: (
            <AppFrame nav="Zapytania">
              <div className="relative mx-auto max-w-md rounded-xl bg-white p-8 shadow-sm">
                <div className="flex flex-col items-center">
                  <span className="inline-block h-8 w-8 animate-spin rounded-full border-4 border-violet-600 border-t-transparent" />
                  <p className="mt-3 text-sm font-semibold text-violet-900">Model pisze list…</p>
                  <p className="mt-1 text-xs text-slate-500">6s — nie zamykaj okna</p>
                </div>
              </div>
            </AppFrame>
          ),
        },
        {
          action: 'Skopiowanie odpowiedzi',
          does: 'Otwiera się okno z tematem i treścią. Możesz poprawić, potem kopiujesz do swojej poczty. System nie wysyła maila.',
          click: '„Kopiuj całość” (temat + treść) albo „Kopiuj treść”.',
          tone: 'green',
          screen: (
            <div className="pointer-events-none overflow-hidden rounded-xl border border-slate-200 bg-slate-50 shadow-sm">
              <div className="flex items-center justify-between border-b border-slate-200 bg-white px-5 py-2">
                <span className="text-sm font-semibold text-slate-800">Przetargi Supon · odpowiedź</span>
                <div className="flex gap-2">
                  <span className="rounded border border-slate-300 px-3 py-1.5 text-xs">Powrót</span>
                  <span className="rounded bg-slate-800 px-3 py-1.5 text-xs font-medium text-white">Zamknij</span>
                </div>
              </div>
              <div className="p-5">
                <div className="mb-3 flex justify-between">
                  <h1 className="text-lg font-semibold">Odpowiedź do skopiowania</h1>
                  <Mark>
                    <span className="rounded bg-blue-600 px-3 py-1.5 text-xs font-medium text-white">
                      Kopiuj całość
                    </span>
                  </Mark>
                </div>
                <Card>
                  <p className="text-xs font-medium text-slate-600">Temat</p>
                  <p className="mt-1 rounded border border-slate-300 px-2 py-1.5 text-sm">Oferta rękawic do pieców</p>
                  <p className="mt-3 text-xs font-medium text-slate-600">Treść</p>
                  <p className="mt-1 min-h-[72px] rounded border border-slate-300 px-2 py-1.5 text-sm text-slate-700">
                    Dzień dobry, potwierdzamy dostępność A611…
                  </p>
                </Card>
              </div>
            </div>
          ),
        },
        {
          action: 'Powrót albo zamknięcie okna',
          does: '„Powrót” wraca do listy zapytań. „Zamknij” zamyka dodatkowe okno (albo wraca, gdy nie było popup).',
          click: '„Powrót” albo „Zamknij” na górnym pasku.',
          tone: 'slate',
          screen: (
            <div className="pointer-events-none overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
              <div className="flex items-center justify-between border-b border-slate-200 px-5 py-2">
                <span className="text-sm font-semibold text-slate-800">Przetargi Supon · odpowiedź</span>
                <div className="flex gap-2">
                  <Mark>
                    <span className="rounded border border-slate-300 px-3 py-1.5 text-xs">Powrót</span>
                  </Mark>
                  <Mark>
                    <span className="rounded bg-slate-800 px-3 py-1.5 text-xs font-medium text-white">Zamknij</span>
                  </Mark>
                </div>
              </div>
              <p className="p-5 text-xs text-slate-500">Lista zapytań albo zamknięte okno.</p>
            </div>
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
    <div className="mx-auto max-w-5xl space-y-4">
      <div>
        <h1 className="text-xl font-semibold">Pomoc</h1>
        <p className="mt-1 text-sm text-slate-500">
          U góry slajdu: co ta czynność robi. Niebieska ramka na ekranie = ten przycisk.
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
