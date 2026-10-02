import { useEffect, useState, type ReactNode } from 'react'
import { appHref } from '../lib/api'

const modules = [
  { id: 'dashboard', label: 'Dashboard' },
  { id: 'przetargi', label: 'Przetargi' },
  { id: 'produkty', label: 'Produkty' },
  { id: 'cenniki', label: 'Cenniki' },
  { id: 'zamienniki', label: 'Zamienniki' },
  { id: 'raporty', label: 'Raporty' },
  { id: 'klienci', label: 'Klienci' },
  { id: 'zapytania', label: 'Zapytania' },
  { id: 'pobieranie', label: 'Pliki do pobrania' },
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

/** Sekcje menu bocznego pełnego widoku przetargu (jak TAB_GROUPS w TenderDetail). */
const TENDER_MENU = [
  { group: 'Przegląd', tabs: ['Podsumowanie'] },
  { group: 'Przygotowanie', tabs: ['Dokumenty', 'Warunki'] },
  { group: 'Wycena', tabs: ['Pozycje', 'Zamienniki', 'Oferta'] },
  { group: 'Zespół', tabs: ['Komentarze', 'Zaproszenia', 'Historia i statusy'] },
] as const

type TenderSection = (typeof TENDER_MENU)[number]['tabs'][number]

/** Zdanie pod nagłówkiem sekcji (jak SECTION_INTRO w TenderDetail; Dokumenty i Warunki mają opis we własnej sekcji). */
const TENDER_SECTION_INTRO: Partial<Record<TenderSection, string>> = {
  Podsumowanie: 'Najważniejsze liczby oferty i lista rzeczy, które trzeba jeszcze uzupełnić.',
  Pozycje: 'Produkty, o które prosi zamawiający. Do każdej pozycji dobieramy nasz produkt i cenę.',
  'Historia i statusy': 'Kto i kiedy co zmienił oraz na jakim etapie jest przetarg.',
}

/** Pełny widok przetargu: menu boczne z sekcjami i treść wybranej sekcji obok. */
function TenderTabs({ active, mark, children }: { active: TenderSection; mark?: boolean; children?: ReactNode }) {
  const intro = TENDER_SECTION_INTRO[active]
  return (
    <div className="flex flex-col gap-3 md:flex-row md:items-start">
      <nav className="flex flex-wrap gap-1 rounded-xl bg-white p-2 shadow-sm md:w-36 md:shrink-0 md:flex-col md:flex-nowrap">
        {TENDER_MENU.map((g) => (
          <div key={g.group} className="contents md:block">
            <p className="hidden px-2 pb-1 pt-2 text-[10px] font-bold uppercase tracking-wide text-slate-400 md:block">
              {g.group}
            </p>
            {g.tabs.map((t) => {
              const item = (
                <span
                  className={`block rounded px-2 py-1.5 text-[11px] ${
                    t === active ? 'bg-sky-100 font-semibold text-blue-700' : 'text-slate-600'
                  }`}
                >
                  {t}
                </span>
              )
              return <div key={t}>{t === active && mark ? <Mark>{item}</Mark> : item}</div>
            })}
          </div>
        ))}
      </nav>
      <div className="min-w-0 flex-1 space-y-3">
        {intro && (
          <div>
            <h2 className="text-sm font-semibold">{active}</h2>
            <p className="text-xs text-slate-500">{intro}</p>
          </div>
        )}
        {children}
      </div>
    </div>
  )
}

/** Nagłówek pełnego widoku: „Eksport ▾” i „⋯” po prawej; menu = rozwinięta lista Eksportu. */
function TenderHead({ menu }: { menu?: 'export' }) {
  const exportBtn = (
    <span className="rounded border border-slate-300 bg-white px-2 py-1.5 text-[11px] font-semibold text-slate-700">
      Eksport ▾
    </span>
  )
  return (
    <>
      <p className="text-xs text-blue-600">← Lista przetargów</p>
      <h1 className="mt-2 text-xl font-semibold">PRZ/2026/0004 · Pakiet rękawic Q2</h1>
      <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
        <p className="text-xs text-slate-500">
          Zamawiający: Mittal · opiekun przetargu: Artur · <strong>Wycena</strong> · średnia ocena dopasowania 85% ·
          narzut 18% · marża 15,3% · edycja włączona
        </p>
        <div className="flex flex-wrap gap-1">
          {menu === 'export' ? <Mark>{exportBtn}</Mark> : exportBtn}
          <span className="rounded border border-slate-300 bg-white px-2 py-1.5 text-[11px] font-semibold text-slate-700">
            ⋯
          </span>
        </div>
      </div>
      {menu === 'export' && (
        <div className="mb-3 ml-auto w-64 rounded-lg border border-slate-200 bg-white p-1 text-xs shadow-lg">
          {[
            ['Oferta w pliku Excel', 'Tabela z cenami do dalszej pracy'],
            ['Oferta w pliku PDF', 'Do wydruku albo wysłania'],
            [
              'Formularz ofertowy z cenami (Word)',
              'Wypełnia cenami z oferty formularz ofertowy dodany w sekcji Dokumenty',
            ],
          ].map(([label, hint], idx) => (
            <div key={label} className={`rounded px-2 py-1.5 ${idx === 0 ? 'bg-slate-100' : ''}`}>
              <span className="font-semibold">{label}</span>
              <span className="block text-[11px] text-slate-500">{hint}</span>
            </div>
          ))}
        </div>
      )}
    </>
  )
}

const WIZARD_STEPS: Array<{ label: string; todo: string; now: string; done: string; description?: string }> = [
  {
    label: 'Dokumenty',
    todo: 'dodaj dokumentację i formularz ofertowy',
    now: 'dodaj dokumentację i formularz ofertowy',
    done: 'Pliki: 1',
  },
  {
    label: 'Pozycje i produkty',
    todo: 'brak pozycji',
    now: '10 z 12 pozycji ma produkt',
    done: '12 z 12 pozycji ma produkt',
    description: 'Sprawdź listę pozycji i dopasuj do nich produkty z katalogu.',
  },
  { label: 'Warunki', todo: 'brak warunków', now: 'Warunki: 2', done: 'Warunki: 2' },
  {
    label: 'Termin i narzut',
    todo: 'bez terminu',
    now: 'termin 12.09.2026',
    done: 'termin 12.09.2026',
    description: 'Ustaw termin składania i narzut, zaproś osoby do pomocy i rozpocznij wycenę.',
  },
]

/** Kreator zakładania przetargu: nagłówek, kafelki 4 kroków i opis kroku; step = bieżący krok (0–3). */
function TenderWizardHead({ step, mark }: { step: number; mark?: boolean }) {
  const description = WIZARD_STEPS[step].description
  return (
    <>
      <div className="mb-3 flex flex-wrap items-start justify-between gap-2">
        <div className="min-w-0">
          <p className="text-xs text-blue-600">← Lista przetargów</p>
          <h1 className="mt-2 text-xl font-semibold">PRZ/2026/0004 · Pakiet rękawic Q2</h1>
          <p className="text-xs text-slate-500">
            Zamawiający: Mittal · opiekun przetargu: Artur · <strong>Zakładanie przetargu</strong> · Szkic
          </p>
        </div>
        <span className="px-2 py-1.5 text-[11px] text-blue-700 underline">Zamknij kreator i pokaż pełny widok</span>
      </div>
      <div className="mb-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
        {WIZARD_STEPS.map((s, i) => {
          const current = i === step
          const done = i < step
          const tile = (
            <div
              className={`flex w-full items-start gap-2 rounded-xl border bg-white p-3 text-xs ${
                current ? 'border-blue-600 ring-1 ring-blue-600' : 'border-slate-200'
              }`}
            >
              <span
                className={`inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-[11px] font-bold ${
                  current ? 'bg-blue-600 text-white' : done ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'
                }`}
              >
                {done ? '✓' : i + 1}
              </span>
              <span className="min-w-0">
                <span className="block text-[10px] text-slate-500">Krok {i + 1} z 4</span>
                <span className="block font-semibold">{s.label}</span>
                <span className={`block ${done ? 'text-emerald-700' : 'text-slate-500'}`}>
                  {done ? s.done : current ? s.now : s.todo}
                </span>
              </span>
            </div>
          )
          return <div key={s.label}>{current && mark ? <Mark>{tile}</Mark> : tile}</div>
        })}
      </div>
      {description && <p className="mb-3 text-xs text-slate-500">{description}</p>}
    </>
  )
}

/** Przyciski pod krokiem kreatora: „‹ Wstecz” i „Dalej: … ›”, a w ostatnim kroku „Rozpocznij wycenę ›”. */
function TenderWizardFooter({ step, mark }: { step: number; mark?: boolean }) {
  const next =
    step < 3 ? (
      <span className="rounded bg-blue-600 px-4 py-2 text-xs font-semibold text-white">
        Dalej: {WIZARD_STEPS[step + 1].label} ›
      </span>
    ) : (
      <span className="rounded bg-blue-600 px-4 py-2 text-xs font-semibold text-white">Rozpocznij wycenę ›</span>
    )
  return (
    <div className="mt-3 flex flex-wrap items-center justify-between gap-2">
      {step > 0 ? (
        <span className="rounded border border-slate-300 bg-white px-4 py-2 text-xs font-semibold text-slate-700">
          ‹ Wstecz
        </span>
      ) : (
        <span />
      )}
      <span className="flex flex-wrap items-center gap-2">
        {step === 3 && <span className="text-[11px] text-slate-500">Status zmieni się ze Szkicu na Wycenę.</span>}
        {mark ? <Mark>{next}</Mark> : next}
      </span>
    </div>
  )
}

/** Sekcja Dokumenty (krok 1 kreatora i pełny widok): pole na plik zamiast „Przeglądaj…”. */
function TenderDocumentsDrop({ mark, picked }: { mark?: boolean; picked?: string }) {
  const pick = (
    <span className="inline-flex items-center rounded bg-blue-600 px-3 py-2 text-xs font-medium text-white">
      Wybierz plik z komputera
    </span>
  )
  return (
    <Card>
      <h2 className="mb-1 text-sm font-semibold">Dokumenty od zamawiającego</h2>
      <p className="text-xs text-slate-500">
        Pliki od zamawiającego: specyfikacja warunków zamówienia (SWZ, dawniej SIWZ) z listą produktów i warunkami oraz
        formularz ofertowy do wypełnienia cenami.
      </p>
      <p className="mb-3 text-xs text-slate-500">
        Ze specyfikacji odczytamy pozycje i warunki. Formularz ofertowy (plik Word) zapiszemy, żeby wypełnić go cenami w
        menu Eksport › Formularz ofertowy z cenami (Word).
      </p>
      <div className="flex flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed border-slate-300 bg-slate-50 px-4 py-6 text-center">
        <span className="text-lg leading-none text-blue-600">⇪</span>
        <strong className="text-sm">Przeciągnij tu dokumentację przetargu albo formularz ofertowy</strong>
        <span className="text-xs text-slate-500">PDF, Excel albo Word</span>
        <span className="mt-1">{mark ? <Mark>{pick}</Mark> : pick}</span>
      </div>
      <p className="mt-3 rounded-lg border border-slate-200 px-3 py-2 text-xs">
        <span className="font-semibold">▸ Ustawienia odczytu</span>{' '}
        <span className="text-slate-500">· pozycje i warunki, odczyt z podglądem, dopisuje do istniejących</span>
      </p>
      {picked && (
        <p className="mt-3 inline-flex items-center gap-2 text-xs text-sky-700">
          <span className="inline-block h-3 w-3 animate-spin rounded-full border-2 border-sky-600 border-t-transparent" />
          Wybrano: {picked} — trwa odczyt z podglądem…
        </p>
      )}
    </Card>
  )
}

/** Pasek nad pozycjami w sekcji Pozycje: dopasowanie produktów, tańsze zamienniki, zapis. */
function TenderItemsToolbar({ highlight }: { highlight?: 'match' | 'cheaper' }) {
  const match = (
    <span className="rounded bg-violet-600 px-3 py-1.5 text-[11px] font-semibold text-white">
      Dopasuj produkty do pustych pozycji
    </span>
  )
  const cheaper = (
    <span className="rounded bg-amber-500 px-3 py-1.5 text-[11px] font-semibold text-white">
      Zastosuj tańsze zamienniki
    </span>
  )
  return (
    <div className="flex flex-wrap items-center justify-end gap-1.5">
      {highlight === 'match' ? <Mark>{match}</Mark> : match}
      <span className="rounded bg-violet-800 px-3 py-1.5 text-[11px] font-semibold text-white">
        Dopasuj od nowa: wszystkie pozycje
      </span>
      {highlight === 'cheaper' ? <Mark>{cheaper}</Mark> : cheaper}
      <span className="rounded bg-emerald-600 px-3 py-1.5 text-[11px] font-semibold text-white">Zapisz całość</span>
    </div>
  )
}

/** Sekcja Podsumowanie pełnego widoku: kafelki i lista braków z przyciskami. */
function TenderSummary({ markMissing }: { markMissing?: boolean }) {
  return (
    <div className="space-y-3 text-xs">
      <div className="grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
        {[
          ['Termin składania', '12.09.2026', 'za 5 dni'],
          ['Pozycje z produktem', '10 / 12', ''],
          ['Wartość oferty netto', '9 661,34 zł', 'Średnia ocena dopasowania: 85%'],
          ['Marża', '15,3%', 'Narzut, %: 18'],
        ].map(([label, value, note]) => (
          <div key={label} className="rounded-xl bg-white p-3 shadow-sm">
            <div className="text-slate-500">{label}</div>
            <strong className="mt-1 block text-base">{value}</strong>
            {note && (
              <span className={label === 'Termin składania' ? 'font-medium text-red-700' : 'text-slate-500'}>
                {note}
              </span>
            )}
          </div>
        ))}
      </div>
      <Card>
        <h2 className="mb-2 text-sm font-semibold">Czego jeszcze brakuje w ofercie</h2>
        <ul className="divide-y divide-slate-100">
          {[
            ['Pozycje bez produktu: 2', 'Pokaż'],
            ['Pozycje bez ceny: 1', 'Pokaż'],
            ['Warunki do sprawdzenia: 1', 'Warunki'],
          ].map(([label, action], idx) => {
            const btn = (
              <span className="rounded border border-slate-300 bg-white px-2 py-1 font-semibold text-slate-700">
                {action}
              </span>
            )
            return (
              <li key={label} className="flex flex-wrap items-center gap-2 py-2">
                <span className="inline-flex h-4 w-4 shrink-0 items-center justify-center rounded-full bg-amber-50 text-[10px] font-bold text-amber-800">
                  !
                </span>
                <span className="flex-1 text-amber-800">{label}</span>
                {idx === 0 && markMissing ? <Mark>{btn}</Mark> : btn}
              </li>
            )
          })}
        </ul>
      </Card>
    </div>
  )
}

function TenderListTable({ warn }: { warn?: boolean }) {
  return (
    <Card>
      <table className="w-full text-left text-xs">
        <thead>
          <tr className="border-b bg-slate-50">
            <Th>Numer</Th>
            <Th>Zamawiający</Th>
            <Th>Termin składania</Th>
            <Th>Wartość oferty netto</Th>
            <Th>Pozycje</Th>
            <Th>Status</Th>
            <Th>Dopasowanie</Th>
            <Th>Opiekun przetargu</Th>
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
            <td className="p-2">Wycena</td>
            <td className="p-2">85%</td>
            <td className="p-2">Artur</td>
          </tr>
          <tr className="border-b">
            <td className="p-2 font-medium text-blue-600">PRZ/2026/0003</td>
            <td className="p-2">Sanitex</td>
            <td className="p-2">2026-10-02</td>
            <td className="p-2">—</td>
            <td className="p-2">4</td>
            <td className="p-2">Szkic</td>
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
                  <option>Termin za mniej niż 7 dni</option>
                </select>
              </div>
              <TenderListTable warn />
            </AppFrame>
          ),
        },
        {
          action: 'Praca w projekcie',
          does: 'Jesteś w sprawie. Podsumowanie pokazuje termin, pokrycie, wartość i czego brakuje. Z menu bocznego przechodzisz do dokumentów, pozycji i oferty.',
          click: 'Menu po lewej stronie sprawy: Podsumowanie, Dokumenty, Pozycje, Oferta, Historia i statusy.',
          tone: 'green',
          screen: (
            <AppFrame nav="Przetargi">
              <TenderHead />
              <TenderTabs active="Podsumowanie">
                <TenderSummary />
              </TenderTabs>
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
          does: 'Zapisuje nowy przetarg z tytułem, zamawiającym, terminem składania i narzutem, potem od razu otwiera kreator zakładania w 4 krokach.',
          click: 'Wypełnij pola i „Utwórz przetarg”.',
          tone: 'blue',
          screen: (
            <AppFrame nav="Przetargi">
              <div className="mb-4 flex items-center justify-between">
                <h1 className="text-xl font-semibold">Przetargi</h1>
                <Btn label="+ Nowy przetarg" />
              </div>
              <Card className="mb-4 text-sm">
                <h2 className="mb-3 font-semibold">Nowy przetarg</h2>
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                  <Field label="Tytuł" value="Pakiet rękawic Q2" mark />
                  <Field label="Zamawiający" value="Mittal" />
                  <Field label="Termin składania" value="12.09.2026" />
                  <Field label="Narzut na cenę zakupu, %" value="18" />
                </div>
                <div className="mt-3">
                  <Mark>
                    <Btn label="Utwórz przetarg" />
                  </Mark>
                </div>
              </Card>
              <TenderListTable />
            </AppFrame>
          ),
        },
        {
          action: 'Kreator: krok 1 — Dokumenty',
          does: 'Nowy przetarg otwiera się w kreatorze zakładania: Dokumenty, Pozycje i produkty, Warunki, Termin i narzut. Kafelki u góry pokazują, co już zrobione — możesz klikać między krokami. „Zamknij kreator i pokaż pełny widok” od razu przechodzi do pełnego widoku.',
          click: 'Nic — kreator sam zaczyna od kroku „Dokumenty”.',
          tone: 'slate',
          screen: (
            <AppFrame nav="Przetargi">
              <TenderWizardHead step={0} mark />
              <TenderDocumentsDrop />
              <TenderWizardFooter step={0} />
            </AppFrame>
          ),
        },
        {
          action: 'Dodanie dokumentacji przetargu',
          does: 'Dodajesz specyfikację warunków zamówienia (SWZ, dawniej SIWZ) albo formularz ofertowy (plik Word). Ze specyfikacji zostaną odczytane pozycje i warunki, a formularz zapisany, żeby później wypełnić go cenami.',
          click: '„Wybierz plik z komputera” albo przeciągnij plik PDF, Excel albo Word na pole.',
          tone: 'blue',
          screen: (
            <AppFrame nav="Przetargi">
              <TenderWizardHead step={0} />
              <TenderDocumentsDrop mark />
            </AppFrame>
          ),
        },
        {
          action: 'Odczyt dokumentacji',
          does: 'Odczyt rusza od razu po wybraniu pliku. Co i jak odczytać (pozycje, warunki, tryb odczytu) jest schowane w „Ustawienia odczytu” — zwykle nic tam nie zmieniasz.',
          click: 'Czekaj — nie odświeżaj strony.',
          tone: 'violet',
          screen: (
            <AppFrame nav="Przetargi">
              <TenderWizardHead step={0} />
              <TenderDocumentsDrop picked="Specyfikacja_Mittal.pdf" />
            </AppFrame>
          ),
        },
        {
          action: 'Zatwierdzenie podglądu',
          does: 'Podgląd „Co zostanie zaimportowane” to dopiero propozycja. Pozycje i warunki trafią do przetargu po kliknięciu zielonego przycisku — wtedy kreator sam przejdzie do kroku 2.',
          click: 'Sprawdź listę i kliknij „Dodaj do przetargu: … pozycji, … warunków”.',
          tone: 'blue',
          screen: (
            <AppFrame nav="Przetargi">
              <TenderWizardHead step={0} />
              <div className="space-y-3 rounded-xl border-2 border-amber-400 bg-amber-50/40 p-4 text-xs">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <div>
                    <h3 className="text-sm font-semibold text-amber-950">Co zostanie zaimportowane</h3>
                    <p className="text-[11px] text-amber-900/80">
                      To tylko podgląd — pozycje trafią do przetargu dopiero po kliknięciu poniżej.
                    </p>
                  </div>
                  <div className="flex flex-wrap gap-2">
                    <span className="rounded border border-slate-300 bg-white px-2 py-1">Anuluj</span>
                    <Mark>
                      <span className="rounded bg-emerald-600 px-3 py-2 font-semibold text-white">
                        Dodaj do przetargu: 12 pozycji, 5 warunków
                      </span>
                    </Mark>
                  </div>
                </div>
                <p className="text-slate-600">
                  Zaznaczono: <strong>12</strong> pozycji, <strong>5</strong> warunków
                </p>
              </div>
            </AppFrame>
          ),
        },
        {
          action: 'Kreator: krok 2 — lista pustych pozycji',
          does: 'Widać wymagania zamawiającego. Kolumna „Produkt” jest pusta — oferty jeszcze nie ma.',
          click: 'Nic nie zapisuj, dopóki nie dopasujesz produktów.',
          tone: 'slate',
          screen: (
            <AppFrame nav="Przetargi">
              <TenderWizardHead step={1} />
              <Card>
                <table className="w-full text-left text-xs">
                  <thead>
                    <tr className="border-b bg-slate-50">
                      <Th>Pozycja</Th>
                      <Th>Wymaganie zamawiającego</Th>
                      <Th>Produkt</Th>
                      <Th>Ilość</Th>
                      <Th>Cena w ofercie</Th>
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
          action: 'Dopasowanie produktów do pustych pozycji',
          does: 'Do każdej pustej pozycji wyszukiwany jest produkt z katalogu i od razu zapisywany. Produktów wpisanych ręcznie to nie rusza.',
          click: 'Fioletowy „Dopasuj produkty do pustych pozycji” na pasku nad pozycjami.',
          tone: 'violet',
          screen: (
            <AppFrame nav="Przetargi">
              <TenderWizardHead step={1} />
              <TenderItemsToolbar highlight="match" />
              <div className="mt-3 rounded-xl border border-violet-200 bg-white p-4 text-sm shadow-xl">
                <p className="font-semibold text-slate-900">Trwa dopasowywanie produktów…</p>
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
          does: 'Pozycje dostają produkty z katalogu. Fioletowe tło i znacznik „Zmienione przy ostatnim dopasowaniu” pokazują, co się właśnie zmieniło. Sprawdź ocenę dopasowania — to szacunek, nie zastępuje sprawdzenia karty produktu.',
          click: 'Nic — przeglądasz. Słabą ocenę poprawiasz ręcznie w następnych krokach.',
          tone: 'violet',
          screen: (
            <AppFrame nav="Przetargi">
              <TenderWizardHead step={1} />
              <div className="mb-3 rounded-xl border border-violet-200 bg-violet-50 p-3 text-xs text-violet-950">
                <strong>Ostatnie dopasowanie produktów</strong>
                <p className="mt-1">Sprawdzono pozycji: 12 · zmieniono: 10 · bez pasującego produktu: 2</p>
              </div>
              <Card>
                <table className="w-full text-left text-xs">
                  <thead>
                    <tr className="border-b bg-slate-50">
                      <Th>Pozycja</Th>
                      <Th>Wymaganie zamawiającego</Th>
                      <Th>Produkt</Th>
                      <Th>Ocena dopasowania</Th>
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
          action: 'Stan oferty',
          does: 'Pasek pokazuje, czy oferta jest kompletna. Przyciski filtrują tylko pozycje, które trzeba poprawić.',
          click: 'Np. „Bez ceny” albo „Słabe dopasowanie”, żeby zobaczyć tylko te pozycje.',
          tone: 'amber',
          screen: (
            <AppFrame nav="Przetargi">
              <TenderWizardHead step={1} />
              <div className="rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs">
                <div className="mb-2 flex flex-wrap justify-between gap-2">
                  <strong>Stan oferty: trzeba uzupełnić</strong>
                  <span className="text-slate-600">10 z 12 pozycji ma produkt</span>
                </div>
                <div className="flex flex-wrap gap-1.5">
                  <span className="rounded bg-white px-2 py-1 text-slate-700">Bez produktu: 2</span>
                  <Mark>
                    <span className="rounded bg-slate-800 px-2 py-1 text-white">Bez ceny: 1</span>
                  </Mark>
                  <span className="rounded bg-white px-2 py-1 text-slate-700">Słabe dopasowanie: 1</span>
                  <span className="rounded bg-white/60 px-2 py-1 text-slate-400">Niska marża: 0</span>
                </div>
              </div>
            </AppFrame>
          ),
        },
        {
          action: 'Ręczna zmiana produktu',
          does: '„Szukaj po nazwie lub kodzie” szuka w katalogu jak na liście produktów. „Szukaj w katalogu po opisie” podsuwa produkty pasujące do opisu pozycji, a „Szukaj w internecie” szuka poza katalogiem. Przydaje się, gdy dopasowanie jest słabe albo zamawiający chce konkretną markę.',
          click: '„Szukaj po nazwie lub kodzie” przy pozycji, wpisz, czego szukasz, potem „Wybierz”.',
          tone: 'blue',
          screen: (
            <AppFrame nav="Przetargi">
              <TenderWizardHead step={1} />
              <Card>
                <div className="mb-3 flex flex-wrap gap-1.5 text-xs">
                  <Mark>
                    <span className="rounded bg-sky-600 px-2 py-1 text-white">Szukaj po nazwie lub kodzie</span>
                  </Mark>
                  <span className="rounded bg-violet-600 px-2 py-1 text-white">Szukaj w katalogu po opisie</span>
                  <span className="rounded border border-slate-300 px-2 py-1 text-slate-700">Szukaj w internecie</span>
                </div>
                <Field label="Czego szukasz" value="Rękawice do pracy w wysokiej temperaturze" />
                <table className="mt-3 w-full text-left text-xs">
                  <thead>
                    <tr className="border-b bg-slate-50">
                      <Th>Kod produktu</Th>
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
          action: 'Kreator: krok 3 — Warunki',
          does: 'Wymagania zamawiającego poza samymi produktami: terminy dostaw, wymagane dokumenty i certyfikaty, pogrupowane po kategoriach. Przy każdym zaznaczasz, czy go spełniamy — warunki niespełnione i niesprawdzone trafiają na Podsumowanie do „Czego jeszcze brakuje w ofercie”.',
          click: '„Dalej: Warunki ›”, potem przy każdym warunku „Spełniamy”, „Do sprawdzenia” albo „Nie spełniamy”. Brakujący warunek dopiszesz: kategoria, „Nowy warunek”, „Dodaj warunek”.',
          tone: 'blue',
          screen: (
            <AppFrame nav="Przetargi">
              <TenderWizardHead step={2} />
              <Card className="space-y-3 text-xs">
                <div>
                  <h2 className="text-sm font-semibold">Warunki</h2>
                  <p className="text-slate-500">
                    Wymagania zamawiającego poza samymi produktami: terminy dostaw, wymagane dokumenty i certyfikaty.
                  </p>
                </div>
                <div className="flex flex-wrap gap-1.5">
                  <span className="rounded bg-emerald-50 px-2 py-1 text-emerald-700">Spełniamy: 1</span>
                  <span className="rounded bg-amber-50 px-2 py-1 text-amber-800">Do sprawdzenia: 1</span>
                  <span className="rounded bg-red-50 px-2 py-1 text-red-700">Nie spełniamy: 0</span>
                </div>
                <div className="flex flex-wrap items-end gap-2">
                  <Field label="Kategoria" value="Dostawa" />
                  <div className="min-w-[180px] flex-1">
                    <Field label="Nowy warunek" placeholder="Na przykład: dostawa w ciągu 5 dni roboczych od zamówienia" />
                  </div>
                  <Btn label="Dodaj warunek" />
                </div>
                {[
                  {
                    group: 'Dostawa',
                    text: 'Dostawa w ciągu 14 dni od zamówienia',
                    note: 'z dokumentacji przetargu · zaznaczył(a) Artur, 02.10.2026',
                    status: 'Spełniamy',
                  },
                  {
                    group: 'Certyfikaty i normy',
                    text: 'Deklaracja zgodności do każdej pozycji',
                    note: 'z dokumentacji przetargu',
                    status: 'Do sprawdzenia',
                  },
                ].map((c) => (
                  <div key={c.group}>
                    <h3 className="mb-1 font-semibold text-slate-700">
                      {c.group} <span className="font-normal text-slate-500">· 1</span>
                    </h3>
                    <div className="flex flex-wrap items-start gap-3 rounded-lg border border-slate-200 p-2">
                      <div className="min-w-[180px] flex-1">
                        <p>{c.text}</p>
                        <p className="mt-0.5 text-[11px] text-slate-500">{c.note}</p>
                      </div>
                      <div className="flex flex-wrap items-center gap-1">
                        {['Spełniamy', 'Do sprawdzenia', 'Nie spełniamy'].map((label) => {
                          const on = label === c.status
                          const btn = (
                            <span
                              className={`rounded border px-2 py-1 ${
                                on
                                  ? label === 'Spełniamy'
                                    ? 'border-emerald-600 bg-emerald-600 text-white'
                                    : 'border-amber-500 bg-amber-500 text-white'
                                  : 'border-slate-300 bg-white text-slate-600'
                              }`}
                            >
                              {label}
                            </span>
                          )
                          return c.status === 'Do sprawdzenia' && label === 'Spełniamy' ? (
                            <Mark key={label}>{btn}</Mark>
                          ) : (
                            <span key={label}>{btn}</span>
                          )
                        })}
                      </div>
                    </div>
                  </div>
                ))}
              </Card>
              <TenderWizardFooter step={2} />
            </AppFrame>
          ),
        },
        {
          action: 'Kreator: krok 4 — Termin i narzut',
          does: 'Wpisujesz termin składania i narzut na cenę zakupu; z uprawnieniem do zaproszeń zapraszasz tu też osoby do pomocy. Lista po prawej pokazuje braki — nie blokują startu. „Rozpocznij wycenę” zmienia status ze Szkicu na Wycenę i otwiera pełny widok.',
          click: '„Zapisz termin”, potem niebieski „Rozpocznij wycenę ›”. Gdy przetarg jest już w Wycenie albo nie możesz zmienić statusu, przycisk nazywa się „Zakończ kreator ›”.',
          tone: 'green',
          screen: (
            <AppFrame nav="Przetargi">
              <TenderWizardHead step={3} />
              <div className="grid gap-3 lg:grid-cols-[2fr_1fr]">
                <Card className="space-y-3 text-xs">
                  <h2 className="text-sm font-semibold">Termin i narzut</h2>
                  <div className="flex flex-wrap items-end gap-2">
                    <Field label="Termin składania ofert" value="12.09.2026" />
                    <span className="rounded bg-slate-700 px-3 py-1.5 text-white">Zapisz termin</span>
                  </div>
                  <div className="flex flex-wrap items-end gap-2">
                    <Field label="Narzut na cenę zakupu, %" value="18" />
                    <span className="rounded bg-violet-700 px-3 py-1.5 text-white">Zapisz narzut</span>
                  </div>
                </Card>
                <Card className="space-y-1.5 text-xs">
                  <h2 className="text-sm font-semibold">Przed rozpoczęciem wyceny</h2>
                  {(
                    [
                      [true, 'Dokumenty: 1'],
                      [true, '12 z 12 pozycji ma produkt'],
                      [true, 'Warunki: sprawdzone 2 z 2'],
                      [true, 'Termin składania: 12.09.2026'],
                      [
                        false,
                        'Brak formularza ofertowego (plik Word) — nie da się pobrać formularza ofertowego z cenami',
                      ],
                    ] as Array<[boolean, string]>
                  ).map(([ok, label]) => (
                    <p key={label} className="flex items-start gap-2">
                      <span
                        className={`inline-flex h-4 w-4 shrink-0 items-center justify-center rounded-full text-[10px] font-bold ${
                          ok ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-800'
                        }`}
                      >
                        {ok ? '✓' : '!'}
                      </span>
                      <span className={ok ? 'text-slate-700' : 'text-amber-800'}>{label}</span>
                    </p>
                  ))}
                  <p className="text-slate-500">Braki nie blokują startu — uzupełnisz je w pełnym widoku przetargu.</p>
                </Card>
              </div>
              <TenderWizardFooter step={3} mark />
            </AppFrame>
          ),
        },
        {
          action: 'Pełny widok z menu bocznym',
          does: 'Po kreatorze sprawa otwiera się na Podsumowaniu: termin, pozycje z produktem, wartość, marża i lista braków. „Pokaż” przenosi prosto do pozycji do poprawienia, „Warunki” do warunków do sprawdzenia. Pod nagłówkiem każdej sekcji jest jedno zdanie, co w niej jest.',
          click: 'Menu boczne: Dokumenty, Warunki, Pozycje, Zamienniki, Oferta, Komentarze, Zaproszenia, Historia i statusy.',
          tone: 'slate',
          screen: (
            <AppFrame nav="Przetargi">
              <TenderHead />
              <TenderTabs active="Podsumowanie">
                <TenderSummary markMissing />
              </TenderTabs>
            </AppFrame>
          ),
        },
        {
          action: 'Przekazanie do akceptacji',
          does: 'Zmiana statusu puszcza sprawę dalej: Szkic → Wycena → Akceptacja kierownika → Akceptacja dyrektora. Bez tego oferta zostaje u Ciebie. Status zmieniasz w „Historia i statusy” — tam jest też historia zmian.',
          click: 'Menu boczne „Historia i statusy”, potem „Zmień na: …” z nazwą następnego statusu (zależnie od roli).',
          tone: 'blue',
          screen: (
            <AppFrame nav="Przetargi">
              <TenderHead />
              <TenderTabs active="Historia i statusy" mark>
                <Card className="text-xs">
                  <h2 className="mb-2 text-sm font-semibold">Zmiana statusu</h2>
                  <p className="mb-3 text-slate-500">
                    Teraz: <strong>Wycena</strong>
                  </p>
                  <Field
                    label="Notatka (wymagana przy odrzuceniu albo cofnięciu z akceptacji)"
                    placeholder="Uzasadnienie decyzji…"
                  />
                  <div className="mt-3">
                    <Mark>
                      <span className="inline-block rounded bg-blue-600 px-3 py-2 text-white">
                        Zmień na: Akceptacja kierownika
                      </span>
                    </Mark>
                  </div>
                </Card>
              </TenderTabs>
            </AppFrame>
          ),
        },
        {
          action: 'Pobranie oferty',
          does: 'Ściąga plik z cenami: ofertę w pliku Excel do dalszej pracy, w pliku PDF do wydruku albo wysłania, a „Formularz ofertowy z cenami (Word)” wypełnia formularz zamawiającego dodany w Dokumentach.',
          click: '„Eksport ▾” w prawym górnym rogu sprawy, potem wybierz plik. Usunięcie przetargu jest obok: menu „⋯” → „Usuń przetarg”.',
          tone: 'green',
          screen: (
            <AppFrame nav="Przetargi">
              <TenderHead menu="export" />
              <div className="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-xs">
                <strong>Stan oferty: gotowa do akceptacji</strong>
                <span className="ml-2 text-slate-600">12 z 12 pozycji ma produkt</span>
              </div>
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
          does: 'Tu jest baza SKU z cenami z cenników. Szukasz po kodzie, nazwie albo producencie — także po kodzie towaru z ERP XL (np. SOK9198014): karta powiązana z tym towarem wychodzi na górę listy, a pod SKU widać „XL: …”.',
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
                        placeholder="Kod, kod XL, nazwa lub producent…"
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
          click: 'Wiersz na liście albo kod produktu w pozycji przetargu.',
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
          action: 'Użycie produktu w ofercie',
          does: 'Kod produktu wpisujesz w pozycji przetargu przez „Szukaj po nazwie lub kodzie” — cena w ofercie weźmie się z cennika.',
          click: 'Skopiuj kod produktu, wróć do menu Przetargi i w sprawie otwórz sekcję „Pozycje” z menu bocznego.',
          tone: 'green',
          screen: (
            <AppFrame nav="Przetargi">
              <TenderHead />
              <TenderTabs active="Pozycje">
                <Card>
                  <table className="w-full text-left text-xs">
                    <thead>
                      <tr className="border-b bg-slate-50">
                        <Th>Wymaganie zamawiającego</Th>
                        <Th>Produkt</Th>
                        <Th>Cena w ofercie</Th>
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
              </TenderTabs>
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
          does: 'W przetargu pojawia się propozycja tańszych zatwierdzonych zamienników (co najmniej 3% taniej po upuście); po potwierdzeniu produkt w pozycji zostaje podmieniony.',
          click: 'Sekcja „Pozycje” w menu bocznym sprawy → „Zastosuj tańsze zamienniki” na pasku nad pozycjami, potem „Tak, zastosuj”.',
          tone: 'green',
          screen: (
            <AppFrame nav="Przetargi">
              <TenderHead />
              <TenderTabs active="Pozycje">
                <TenderItemsToolbar highlight="cheaper" />
                <div className="rounded-lg border-2 border-amber-400 bg-amber-50 p-3 text-xs">
                  <p className="font-semibold text-amber-950">Zastosować tańsze zamienniki na 3 pozycjach?</p>
                  <p className="mt-2 font-mono text-[11px]">Pozycja 1: ARĘKGLOMJ713 → ARĘKGLOMJ714 (taniej o 8% · cena zakupu 11,40 zł)</p>
                  <div className="mt-3 flex gap-2">
                    <span className="rounded border border-slate-300 bg-white px-3 py-1.5 text-[11px]">Anuluj</span>
                    <Mark>
                      <span className="rounded bg-amber-600 px-3 py-1.5 text-[11px] font-semibold text-white">
                        Tak, zastosuj
                      </span>
                    </Mark>
                  </div>
                </div>
              </TenderTabs>
            </AppFrame>
          ),
        },
      ]}
    />
  )
}

function ReportsHelp() {
  const tabs = ['Baza wiedzy', 'Źródła danych', 'Ruchy cen', 'Sprzedaż i oferty', 'Klienci ERP']
  const Tabs = ({ active }: { active: string }) => (
    <div className="mb-3 flex flex-wrap gap-1 border-b border-slate-200 text-xs">
      {tabs.map((t) => (
        <span key={t} className={`-mb-px border-b-2 px-2 py-1.5 ${t === active ? 'border-blue-600 font-semibold text-blue-700' : 'border-transparent text-slate-600'}`}>
          {t}
        </span>
      ))}
    </div>
  )
  return (
    <Slideshow
      title="Raporty"
      slides={[
        {
          action: 'Pięć raportów w zakładkach',
          does: 'Baza wiedzy — kompletność kart produktów. Źródła danych — czy cenniki i konta B2B są aktualne. Ruchy cen — podwyżki i obniżki u dostawców. Sprzedaż i oferty — zapytania klientów, przetargi i kampanie. Klienci ERP — kto kupuje, kto przestał i do ilu można napisać. Widzisz tylko zakładki z danymi, do których masz uprawnienie.',
          click: 'Menu „Raporty”, potem zakładka u góry.',
          tone: 'slate',
          screen: (
            <AppFrame nav="Raporty">
              <h1 className="text-xl font-semibold">Raporty</h1>
              <p className="mb-3 text-xs text-slate-500">Jak kompletne są karty produktów, z których AI dobiera wyroby do przetargów i zapytań.</p>
              <Mark>
                <Tabs active="Baza wiedzy" />
              </Mark>
            </AppFrame>
          ),
        },
        {
          action: 'Najważniejsze i kafelki',
          does: 'Na górze każdego raportu ramka „Najważniejsze” — kilka zdań policzonych z danych (same fakty). Pod nią kafelki z liczbami; kolorowa kropka i pasek: zielony = dobrze, pomarańczowy = do uwagi, czerwony = problem.',
          click: 'Przeczytaj ramkę „Najważniejsze”.',
          tone: 'blue',
          screen: (
            <AppFrame nav="Raporty">
              <Tabs active="Baza wiedzy" />
              <Mark>
                <div className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-800">
                  <b className="mb-1 block text-[10px] tracking-wide text-slate-500 uppercase">Najważniejsze</b>
                  Najsłabiej pokryte: normy potwierdzone u producenta — ma je 22% kart.
                </div>
              </Mark>
              <div className="mt-2 grid grid-cols-3 gap-2">
                {[
                  ['Z opisem', '97%', 'bg-teal-600'],
                  ['Ze zdjęciem', '97%', 'bg-teal-600'],
                  ['Normy u producenta', '22%', 'bg-rose-700'],
                ].map(([label, value, fill]) => (
                  <Card key={label} className="p-3">
                    <span className="block text-[11px] text-slate-500">{label}</span>
                    <b className="text-lg">{value}</b>
                    <span className="mt-1 block h-1 rounded-full bg-slate-100">
                      <span className={`block h-full rounded-full ${fill}`} style={{ width: value }} />
                    </span>
                  </Card>
                ))}
              </div>
            </AppFrame>
          ),
        },
        {
          action: 'Wykresy i tabele',
          does: 'Najedź kursorem na słupek, żeby zobaczyć dokładne liczby. W tabelach kliknij nagłówek kolumny, żeby posortować — np. producentów po normach, by znaleźć największe luki. W „Ruchach cen” i „Sprzedaży” wybierzesz okres (7/30/90 albo 30/90/180 dni).',
          click: 'Kursor na wykres albo klik w nagłówek tabeli.',
          tone: 'violet',
          screen: (
            <AppFrame nav="Raporty">
              <Tabs active="Ruchy cen" />
              <Card>
                <h2 className="mb-2 text-sm font-semibold">Podwyżki i obniżki — tydzień po tygodniu</h2>
                <div className="flex h-20 items-center gap-3 px-2">
                  {[
                    [10, 6],
                    [18, 9],
                    [30, 5],
                    [24, 12],
                  ].map(([up, down], i) => (
                    <div key={i} className="flex w-5 flex-col items-center">
                      <span className="w-full rounded-t bg-orange-600" style={{ height: up }} />
                      <span className="mt-0.5 w-full rounded-b bg-sky-600" style={{ height: down }} />
                    </div>
                  ))}
                  <Mark>
                    <span className="rounded border border-slate-200 bg-white px-2 py-1 text-[11px] shadow-sm">Podwyżki 30 · Obniżki 5</span>
                  </Mark>
                </div>
              </Card>
            </AppFrame>
          ),
        },
        {
          action: 'Eksport przetargów do CSV',
          does: 'W zakładce „Sprzedaż i oferty”, w części „Przetargi”, zielony przycisk pobiera listę przetargów do Excela (raport-przetargi.csv) — te same przetargi, które widzisz w raporcie.',
          click: 'Zielony „Eksport CSV” przy nagłówku „Przetargi”.',
          tone: 'green',
          screen: (
            <AppFrame nav="Raporty">
              <Tabs active="Sprzedaż i oferty" />
              <div className="mb-2 flex items-center justify-between">
                <h2 className="text-sm font-semibold">Przetargi</h2>
                <Mark>
                  <span className="rounded bg-emerald-600 px-3 py-1.5 text-xs text-white">Eksport CSV</span>
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
          action: 'Wybór zamawiającego w przetargu',
          does: 'W przetargu klient nazywa się zamawiającym. Nowy przetarg wymaga zamawiającego z tej listy — bez niego nie utworzysz sprawy.',
          click: 'W formularzu „Nowy przetarg” lista „Zamawiający”.',
          tone: 'blue',
          screen: (
            <AppFrame nav="Przetargi">
              <h1 className="mb-4 text-xl font-semibold">Przetargi</h1>
              <Card className="text-sm">
                <h2 className="mb-3 font-semibold">Nowy przetarg</h2>
                <div className="grid gap-3 sm:grid-cols-3">
                  <Field label="Tytuł" placeholder="np. Pakiet rękawic Q2" />
                  <Field label="Zamawiający" value="Zakład Szkła Sp. z o.o." mark />
                  <Field label="Termin składania" placeholder="dd.mm.rrrr" />
                </div>
                <div className="mt-3">
                  <Btn label="Utwórz przetarg" />
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
          does: 'Z treści maila system wyciąga pozycje, dobiera towary z katalogu i od razu pisze list. Nic nie wysyła pocztą. Szablon listu, tryb cen i marża są jak w Twoim ostatnim zapytaniu.',
          click: 'Menu „Zapytania”, wklej całą treść w pole „Treść maila”, potem „Przygotuj odpowiedź” (albo Ctrl+Enter). Temat, klient i szablon listu są pod „Więcej”.',
          tone: 'blue',
          screen: (
            <AppFrame nav="Zapytania">
              <h1 className="mb-1 text-xl font-semibold">Zapytania</h1>
              <p className="mb-4 text-sm text-slate-500">
                Wklej mail klienta. Dostaniesz gotowy list i listę pozycji, które warto sprawdzić przed wysłaniem.
              </p>
              <Card>
                <label className="block text-xs">
                  Treść maila *
                  <Mark>
                    <div className="mt-1 min-h-[110px] w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                      Proszę o ofertę: 30szt rękawice chemoodporne rozmiar 10, 4szt kalosze chemoodporne rozmiar 43…
                    </div>
                  </Mark>
                </label>
                <div className="mt-3 flex items-center gap-3">
                  <Btn label="Przygotuj odpowiedź" />
                  <span className="text-[11px] text-slate-400">Ctrl+Enter wysyła</span>
                  <span className="text-xs text-blue-600">Więcej</span>
                </div>
              </Card>
              <Card className="mt-3">
                <p className="mb-2 text-sm font-semibold">Ostatnie</p>
                <table className="w-full text-left text-xs">
                  <thead>
                    <tr className="border-b bg-slate-50">
                      <Th>Temat</Th>
                      <Th>Klient</Th>
                      <Th>Status</Th>
                      <Th />
                    </tr>
                  </thead>
                  <tbody>
                    <tr className="border-b">
                      <td className="p-2">Oferta — Rękawice i kalosze</td>
                      <td className="p-2">Firma Test</td>
                      <td className="p-2">
                        <span className="rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-medium text-amber-800">
                          Do sprawdzenia (1)
                        </span>
                      </td>
                      <td className="p-2 text-blue-600">Otwórz</td>
                    </tr>
                    <tr className="border-b">
                      <td className="p-2">Oferta — Okulary</td>
                      <td className="p-2">—</td>
                      <td className="p-2">
                        <span className="rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-medium text-emerald-800">
                          Wysłano
                        </span>
                      </td>
                      <td className="p-2 text-blue-600">Otwórz</td>
                    </tr>
                  </tbody>
                </table>
              </Card>
            </AppFrame>
          ),
        },
        {
          action: 'List od razu, pozycje do sprawdzenia oflagowane',
          does: 'Baner u góry mówi, ile pozycji wymaga sprawdzenia. Pod nim pasek z przyciskami listu — zostaje u góry ekranu, gdy przewijasz pozycje. Niżej, na całą szerokość, każda pozycja z cytatem klienta, dobranym towarem i plakietką: zielona „pewne”, żółta „sprawdź”, czerwona „brak w katalogu”. Gotowy list otwiera „Podgląd odpowiedzi”.',
          click: 'Przejrzyj tylko pozycje z żółtą lub czerwoną plakietką. Pozycje „pewne” nie wymagają nic. Gdy klient postawił warunek („w szczególności na kwas siarkowy 96%”), pod pozycją stoi ten warunek i werdykt karty; karta, która go nie potwierdza, nie wchodzi do listu — pozycja idzie jako „potwierdzimy po weryfikacji”. Gdy wybrana karta ma w nazwie inny rozmiar niż pozycja klienta (np. „rozmiar S” przy zapytaniu o M-XL), przy propozycji stoi czerwone „Rozmiar się nie zgadza” — list i tak poda rozmiar z zapytania, więc wybierz kartę we właściwym rozmiarze albo wyjaśnij to z klientem. Wyrób wycofany przez producenta ma czerwoną ramkę z nazwą następcy (gdy producent ją podaje) i przycisk „Szukaj następcy w katalogu”; klient informacji o wycofaniu w liście nie zobaczy.',
          tone: 'amber',
          screen: (
            <AppFrame nav="Zapytania">
              <p className="mb-3 rounded bg-amber-50 px-3 py-2 text-sm font-medium text-amber-800">
                1 z 2 pozycji wymaga sprawdzenia
              </p>
              <div className="grid gap-3">
                <Card>
                  <div className="flex flex-wrap items-center gap-2">
                    <Btn label="Zapisz i wyślij w Thunderbirdzie" />
                    <Btn label="Podgląd odpowiedzi" color="border" />
                    <Btn label="Oznacz, że wysłano" color="border" />
                    <Btn label="Kopiuj treść" color="border" />
                  </div>
                </Card>
                <Card>
                  <p className="mb-2 text-sm font-semibold">Pozycje</p>
                  <div className="text-xs">
                    <p>
                      <span className="rounded bg-slate-800 px-1.5 py-0.5 font-semibold text-white">1</span>{' '}
                      <span className="font-medium">30 szt.</span> · rozm. 10{' '}
                      <span className="rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-medium text-emerald-800">
                        pewne
                      </span>
                    </p>
                    <p className="mt-1 border-l-4 border-amber-400 bg-amber-50 px-2 py-1">30szt Rękawice chemoodporne rozmiar 10</p>
                    <p className="mt-1">
                      <span className="font-semibold">37900VP</span> · AlphaTec 37900VP · Ansell · 22,15 zł
                    </p>
                  </div>
                  <div className="mt-3 border-t border-slate-100 pt-2 text-xs">
                    <p>
                      <span className="rounded bg-slate-800 px-1.5 py-0.5 font-semibold text-white">2</span>{' '}
                      <span className="font-medium">4 szt.</span> · rozm. 43{' '}
                      <Mark>
                        <span className="rounded-full bg-red-100 px-2 py-0.5 text-[11px] font-medium text-red-800">
                          brak w katalogu
                        </span>
                      </Mark>
                    </p>
                    <p className="mt-1 border-l-4 border-amber-400 bg-amber-50 px-2 py-1">4szt Kalosze chemoodporne rozmiar 43</p>
                    <p className="mt-1 text-slate-600">W liście: sprawdzimy i wrócimy z propozycją — bez SKU.</p>
                  </div>
                </Card>
              </div>
            </AppFrame>
          ),
        },
        {
          action: 'Kliknięcie alternatywy przepisuje list',
          does: 'Pod każdą pozycją są chipy: kandydaci z katalogu (SKU · nazwa · % dopasowania) i „Sprawdzimy i wrócimy”. Kliknięcie od razu przepisuje list. „Opis” pokazuje kartę towaru. Gdy żaden kandydat nie jest tym, o co pyta klient, przy propozycji stoją „Szukaj” (po nazwie i kodzie) oraz „Szukaj AI” (po opisie wymagania) — wybrany tam wyrób dopisuje się do alternatyw tej pozycji z etykietą „ręcznie” (bez procentu, bo nikt go nie oceniał) i od razu wchodzi do listu. Pod słowami klienta, po prawej, stoi miniatura zdjęcia wyrobu, który wejdzie do listu (a przy wybranym zamienniku — także jego); kliknięcie pokazuje pełne zdjęcie, a „karta bez zdjęcia” znaczy, że karta w katalogu nie ma jeszcze zdjęcia. Niżej, w „Dla całej oferty”: szablon listu, ceny, dopisek do listu i lista pytań klienta. Szablony to „Handlowy (pełna specyfikacja)” z SKU i producentem, „Bez SKU (proste opisy)” — jedno zdanie opisu bez marki i modelu — „Oficjalny (długie opisy, zdjęcia)” z akapitem opisu z karty oraz „Oficjalny (krótkie opisy, zdjęcia)” z dwoma–trzema zdaniami opisu. W obu oficjalnych przy wyrobie stoi jego zdjęcie główne z karty (z podpisem „zdjęcie poglądowe”); karta bez zdjęcia — pozycja bez zdjęcia. Zmiana szablonu przepisuje list.',
          click: 'Chip z towarem, „Sprawdzimy i wrócimy” albo „Szukaj” / „Szukaj AI”. Jeśli ręcznie zmieniłeś treść, system zapyta, czy ją nadpisać.',
          tone: 'blue',
          screen: (
            <AppFrame nav="Zapytania">
              <Card>
                <p className="mb-2 text-sm font-semibold">Pozycje</p>
                <div className="text-xs">
                  <p>
                    <span className="rounded bg-slate-800 px-1.5 py-0.5 font-semibold text-white">2</span>{' '}
                    <span className="font-medium">4 szt.</span> · rozm. 43{' '}
                    <span className="rounded-full bg-red-100 px-2 py-0.5 text-[11px] font-medium text-red-800">
                      brak w katalogu
                    </span>
                  </p>
                  <p className="mt-1 border-l-4 border-amber-400 bg-amber-50 px-2 py-1">4szt Kalosze chemoodporne rozmiar 43</p>
                  <div className="mt-2 flex items-start justify-between gap-2">
                    <p className="text-slate-600">W liście: sprawdzimy i wrócimy z propozycją — bez SKU.</p>
                    <Mark>
                      <span className="flex shrink-0 gap-1.5">
                        <span className="rounded-full border border-sky-300 bg-white px-2 py-0.5 text-[11px] font-medium text-sky-800">
                          Szukaj
                        </span>
                        <span className="rounded-full border border-violet-300 bg-white px-2 py-0.5 text-[11px] font-medium text-violet-800">
                          Szukaj AI
                        </span>
                      </span>
                    </Mark>
                  </div>
                  <p className="mt-2 text-[11px] font-semibold uppercase tracking-wide text-slate-500">Alternatywy</p>
                  <div className="mt-1 flex flex-wrap items-center gap-1.5">
                    <Mark>
                      <span className="rounded-full border border-slate-300 bg-white px-2.5 py-1">FW94 · Kalosze S4 · 46%</span>
                    </Mark>
                    <span className="rounded-full border border-violet-300 px-2 py-0.5 text-[11px] font-medium text-violet-800">
                      Opis
                    </span>
                    <span className="rounded-full border border-blue-600 bg-blue-600 px-2.5 py-1 text-white">
                      Sprawdzimy i wrócimy
                    </span>
                  </div>
                </div>
                <div className="mt-3 border-t border-slate-100 pt-2 text-xs">
                  <p className="font-semibold text-slate-700">Ceny w liście</p>
                  <div className="mt-1 flex flex-wrap items-center gap-1.5">
                    <span className="rounded-full border border-slate-300 bg-white px-2.5 py-1">Bez cen</span>
                    <span className="rounded-full border border-slate-300 bg-white px-2.5 py-1">Cena katalogowa</span>
                    <span className="rounded-full border border-blue-600 bg-blue-600 px-2.5 py-1 text-white">
                      Zakup + marża
                    </span>
                    <span className="text-slate-700">Marża 18 %</span>
                  </div>
                  <p className="mt-2 font-semibold text-slate-700">Dopisek do listu (klient go zobaczy)</p>
                  <div className="mt-1 min-h-[32px] rounded border border-slate-300 px-2 py-1 text-slate-400">
                    np. termin realizacji, warunki dostawy…
                  </div>
                </div>
              </Card>
            </AppFrame>
          ),
        },
        {
          action: 'Podgląd listu, kopiowanie i oznaczenie jako wysłane',
          does: '„Podgląd odpowiedzi” otwiera okno: po lewej zapytanie klienta, po prawej list tak, jak zobaczy go klient (z tabelą). „Kopiuj z tabelą (HTML)” albo „Kopiuj treść” — i wklejasz do swojej poczty; zapytanie z Thunderbirda wysyłasz przyciskiem „Zapisz i wyślij w Thunderbirdzie”. „Popraw treść ręcznie” zmienia tekst listu (zapisuje się po opuszczeniu pola; poprawka usuwa tabelę). Po wysłaniu z innej poczty „Oznacz, że wysłano” daje zapytaniu zielone „Wysłano” na liście. System sam nie wysyła maila.',
          click: '„Podgląd odpowiedzi” → skopiuj list → wklej do poczty → „Oznacz, że wysłano”.',
          tone: 'green',
          screen: (
            <AppFrame nav="Zapytania">
              <p className="mb-3 rounded bg-emerald-50 px-3 py-2 text-sm font-medium text-emerald-800">
                Wszystkie pozycje pewne — list gotowy do skopiowania
              </p>
              <Card>
                <div className="flex flex-wrap items-center gap-2">
                  <Mark>
                    <Btn label="Podgląd odpowiedzi" color="border" />
                  </Mark>
                  <Mark>
                    <Btn label="Oznacz, że wysłano" />
                  </Mark>
                  <Btn label="Kopiuj treść" color="border" />
                  <Btn label="Kopiuj z tabelą (HTML)" color="border" />
                </div>
              </Card>
            </AppFrame>
          ),
        },
      ]}
    />
  )
}

const ADDON_ID = 'przetargi@supon.rzeszow.pl'
const ADDON_FILE = '/dodatek/supon-przetargi.xpi'
const ADDON_UPDATES = '/dodatek/updates.json'

type UpdatesManifest = {
  addons?: Record<
    string,
    { updates?: { version?: string; applications?: { gecko?: { strict_min_version?: string } } }[] }
  >
}

/**
 * Wersja dodatku czytana z tego samego `updates.json`, z którego bierze ją
 * Thunderbird — inaczej numer na stronie rozjeżdżałby się z plikiem po każdym
 * wgraniu nowej wersji. Gdy pliku nie ma (np. serwer deweloperski), strona po
 * prostu nie pokazuje numeru, zamiast go zgadywać.
 */
function useAddonRelease(): { version: string; minThunderbird: string } | null {
  const [release, setRelease] = useState<{ version: string; minThunderbird: string } | null>(null)

  useEffect(() => {
    let alive = true
    fetch(appHref(ADDON_UPDATES), { headers: { Accept: 'application/json' } })
      .then((res) => (res.ok ? (res.json() as Promise<UpdatesManifest>) : null))
      .then((manifest) => {
        const list = manifest?.addons?.[ADDON_ID]?.updates ?? []
        const newest = list[list.length - 1]
        if (!alive || !newest?.version) return
        setRelease({
          version: newest.version,
          minThunderbird: newest.applications?.gecko?.strict_min_version ?? '',
        })
      })
      .catch(() => undefined)
    return () => {
      alive = false
    }
  }, [])

  return release
}

type TbItem = 'sep' | { label: string; shortcut?: string; mark?: boolean; dim?: boolean }

/** Makieta rozwiniętego menu Thunderbirda: niebieska ramka = ta pozycja. */
function TbMenuList({ items, className = '' }: { items: TbItem[]; className?: string }) {
  return (
    <div className={`rounded-lg border border-slate-200 bg-white py-1.5 text-xs shadow-sm ${className}`}>
      {items.map((item, idx) =>
        item === 'sep' ? (
          <div key={`sep-${idx}`} className="my-1.5 border-t border-slate-100" />
        ) : (
          <div key={item.label} className="flex items-center justify-between gap-6 px-3 py-1">
            {item.mark ? (
              <Mark>
                <span className="px-1 font-semibold text-blue-800">{item.label}</span>
              </Mark>
            ) : (
              <span className={item.dim ? 'text-slate-400' : 'text-slate-800'}>{item.label}</span>
            )}
            {item.shortcut ? <span className="text-slate-400">{item.shortcut}</span> : null}
          </div>
        ),
      )}
    </div>
  )
}

/** Okno Thunderbirda z paskiem menu — odpowiednik AppFrame dla instrukcji instalacji. */
function TbFrame({ open, children }: { open?: string; children: ReactNode }) {
  return (
    <div className="pointer-events-none overflow-hidden rounded-xl border border-slate-200 bg-slate-50 shadow-sm">
      <div className="flex flex-wrap items-center gap-1 border-b border-slate-200 bg-white px-2 py-1.5 text-[11px]">
        <span className="pr-2 font-semibold text-slate-500">Thunderbird</span>
        {['Wydarzenia i zadania', 'Narzędzia', 'Pomoc'].map((m) => (
          <span
            key={m}
            className={`rounded px-2 py-1 ${
              m === open ? 'bg-slate-200 font-semibold text-slate-900' : 'text-slate-600'
            }`}
          >
            {m}
          </span>
        ))}
      </div>
      <div className="min-h-[240px] overflow-x-auto p-4">{children}</div>
    </div>
  )
}

const toolsMenu: TbItem[] = [
  { label: 'Książka adresowa', shortcut: 'Ctrl+Shift+B' },
  { label: 'Zapisane pliki', shortcut: 'Ctrl+J' },
  { label: 'Dodatki i motywy', mark: true },
  { label: 'Monitor aktywności' },
  'sep',
  { label: 'Filtrowanie wiadomości' },
  { label: 'Zastosuj filtry w bieżącym folderze', dim: true },
  'sep',
  { label: 'Importuj…' },
  { label: 'Eksportuj…' },
  { label: 'Narzędzia dla programistów' },
  'sep',
  { label: 'Ustawienia' },
  { label: 'Konfiguracja kont' },
]

const gearMenu = (mark: 'instaluj' | 'aktualizacje'): TbItem[] => [
  { label: 'Sprawdź dostępność aktualizacji', mark: mark === 'aktualizacje' },
  { label: 'Wyświetl ostatnie aktualizacje' },
  'sep',
  { label: 'Zainstaluj dodatek z pliku…', mark: mark === 'instaluj' },
  { label: 'Debuguj dodatki' },
  'sep',
  { label: 'Automatyczne aktualizacje dodatków' },
  { label: 'Przestaw wszystkie dodatki na ręczną aktualizację' },
  'sep',
  { label: 'Zarządzaj skrótami rozszerzeń' },
]

/** Menedżer dodatków: nagłówek z kołem zębatym i jego rozwinięte menu. */
function TbAddonsManager({ menu }: { menu: TbItem[] }) {
  return (
    <>
      <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-base font-semibold text-slate-900">Zarządzanie rozszerzeniami</h1>
        <div className="flex items-center gap-2">
          <span className="rounded border border-slate-300 bg-white px-2 py-1 text-[11px] text-slate-400">
            Znajdź na dodatki.thunderbird.net
          </span>
          <Mark>
            <span className="rounded border border-slate-300 bg-white px-2 py-1 text-xs">⚙</span>
          </Mark>
        </div>
      </div>
      <TbMenuList items={menu} className="max-w-md" />
    </>
  )
}

/** Okno wyboru pliku z filtrem „Dodatki (*.xpi;*.jar;*.zip)”. */
function TbFilePicker() {
  return (
    <div className="pointer-events-none overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
      <div className="border-b border-slate-200 px-4 py-2 text-xs font-semibold text-slate-700">
        Wybierz dodatek do zainstalowania
      </div>
      <div className="px-4 py-3">
        <p className="rounded border border-slate-200 bg-slate-50 px-2 py-1 text-xs text-slate-600">
          Pobrane (Downloads)
        </p>
        <p className="mt-3 text-[11px] font-semibold uppercase tracking-wide text-slate-500">Nazwa</p>
        <p className="mt-1 text-xs text-slate-500">Wczoraj</p>
        <div className="mt-1">
          <Mark>
            <span className="rounded bg-sky-100 px-2 py-1 text-xs font-medium text-blue-800">supon-przetargi.xpi</span>
          </Mark>
        </div>
      </div>
      <div className="flex flex-wrap items-center justify-end gap-2 border-t border-slate-200 px-4 py-3">
        <span className="rounded border border-slate-300 px-2 py-1.5 text-xs text-slate-700">
          Dodatki (*.xpi;*.jar;*.zip)
        </span>
        <Mark>
          <Btn label="Otwórz" />
        </Mark>
        <Btn label="Anuluj" color="border" />
      </div>
    </div>
  )
}

type AddonDownload = { state: 'idle' } | { state: 'started' } | { state: 'error'; message: string }

/**
 * Plik pobiera sama przeglądarka ze zwykłego linku, prosto z kliknięcia. Od 23.09 do 30.09.2026 strona
 * ściągała go fetch-em i „klikała” link do bloba po odpowiedzi serwera — dla Chrome i Edge to pobranie
 * nie pochodziło już z kliknięcia człowieka, a plik .xpi bez gestu użytkownika potrafią po cichu wstrzymać
 * (Safe Browsing / SmartScreen, zależnie od komputera). Po kliknięciu tylko mówimy, gdzie szukać pliku,
 * a w tle sprawdzamy, czy plik w ogóle jest na serwerze.
 */
function useAddonDownload() {
  const [status, setStatus] = useState<AddonDownload>({ state: 'idle' })

  function download() {
    setStatus({ state: 'started' })
    // Bez preventDefault: pobieranie robi link. Brak pliku na serwerze kończy się stroną aplikacji
    // (index.html) z kodem 200 — wtedy mówimy wprost, że pliku nie ma.
    fetch(appHref(ADDON_FILE), { method: 'HEAD', cache: 'no-store' })
      .then((res) => {
        if (!res.ok) throw new Error(`serwer odpowiedział kodem ${res.status}`)
        if ((res.headers.get('Content-Type') ?? '').includes('text/html')) {
          throw new Error('na serwerze brakuje pliku dodatku')
        }
      })
      .catch((ex: unknown) => {
        // sieć padła przy samym sprawdzeniu — pobieranie przez link mogło się udać, nie straszymy
        if (ex instanceof TypeError) return
        setStatus({ state: 'error', message: ex instanceof Error ? ex.message : 'nieznany błąd' })
      })
  }

  return { status, download }
}

function DownloadsHelp() {
  const release = useAddonRelease()
  const { status, download } = useAddonDownload()

  return (
    <div className="space-y-4">
      <div className="rounded-xl bg-white p-5 shadow-sm">
        <h2 className="text-lg font-semibold text-slate-900">Dodatek do Thunderbirda „Supon Przetargi”</h2>
        <p className="mt-1 text-sm leading-snug text-slate-700">
          Zakłada zapytanie w aplikacji z otwartego maila, wstawia przygotowaną odpowiedź do tego samego wątku
          i oznacza na liście wiadomości maile, którymi ktoś już się zajmuje.
        </p>
        <div className="mt-3 flex flex-wrap items-center gap-3">
          <a
            href={appHref(ADDON_FILE)}
            download
            onClick={download}
            className="rounded bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"
          >
            Pobierz dodatek (plik XPI)
          </a>
          <span className="text-xs text-slate-500">
            supon-przetargi.xpi
            {release ? ` · wersja ${release.version}` : ''}
            {release?.minThunderbird ? ` · Thunderbird ${release.minThunderbird} lub nowszy` : ''}
          </span>
        </div>
        {status.state === 'started' ? (
          <p role="status" className="mt-3 rounded border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-800">
            <strong>Przeglądarka pobiera plik supon-przetargi.xpi</strong> do folderu Pobrane — listę pobranych
            otworzysz skrótem <strong>Ctrl+J</strong>. Jeśli przy pliku jest ostrzeżenie, kliknij je i wybierz{' '}
            <strong>Zachowaj</strong> (w Edge pod „…”).
          </p>
        ) : null}
        {status.state === 'error' ? (
          <p role="alert" className="mt-3 rounded border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-800">
            Pliku dodatku nie da się teraz pobrać ({status.message}). Zmiana przeglądarki nie pomoże — daj znać
            administratorowi aplikacji.
          </p>
        ) : null}
        <p className="mt-3 rounded border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
          Masz już starszą wersję? Zainstaluj nową <strong>na wierzch</strong> — nie odinstalowuj poprzedniej, bo
          razem z nią znikają zapisane dane logowania.
        </p>
      </div>
      <Slideshow
        title="Instalacja dodatku krok po kroku"
        slides={[
          {
            action: 'Pobranie pliku',
            does: 'Przycisk nad instrukcją zapisuje plik supon-przetargi.xpi w Pobranych. Thunderbird instaluje go z dysku — nie z przeglądarki.',
            click: '„Pobierz dodatek (plik XPI)” nad tą instrukcją. Zapamiętaj, gdzie plik wylądował.',
            tone: 'blue',
            screen: (
              <div className="pointer-events-none rounded-xl border border-slate-200 bg-slate-50 p-4">
                <div className="rounded-xl bg-white p-4 shadow-sm">
                  <p className="text-sm font-semibold">Dodatek do Thunderbirda „Supon Przetargi”</p>
                  <div className="mt-3 flex flex-wrap items-center gap-2">
                    <Mark>
                      <Btn label="Pobierz dodatek (plik XPI)" />
                    </Mark>
                    <span className="text-xs text-slate-500">supon-przetargi.xpi</span>
                  </div>
                </div>
              </div>
            ),
          },
          {
            action: 'Otwarcie menedżera dodatków',
            does: 'To miejsce, w którym instaluje się plik XPI i w którym potem otwiera się ustawienia dodatku.',
            click: 'Menu „Narzędzia” → „Dodatki i motywy”.',
            tone: 'blue',
            screen: (
              <TbFrame open="Narzędzia">
                <TbMenuList items={toolsMenu} className="max-w-md" />
              </TbFrame>
            ),
          },
          {
            action: 'Instalacja z pliku',
            does: 'Na ekranie „Zarządzanie rozszerzeniami” instalacja z dysku kryje się pod kołem zębatym obok pola wyszukiwania.',
            click: 'Koło zębate → „Zainstaluj dodatek z pliku…”.',
            tone: 'blue',
            screen: (
              <TbFrame>
                <TbAddonsManager menu={gearMenu('instaluj')} />
              </TbFrame>
            ),
          },
          {
            action: 'Wskazanie pobranego pliku',
            does: 'Okno wyboru pokazuje tylko dodatki (*.xpi;*.jar;*.zip), więc w Pobranych zobaczysz właściwie jeden plik. Po „Otwórz” Thunderbird otwiera okno instalacji.',
            click: 'Pobrane → supon-przetargi.xpi → „Otwórz”, potem „Dodaj” w oknie Thunderbirda.',
            tone: 'blue',
            screen: <TbFilePicker />,
          },
          {
            action: 'Połączenie z aplikacją',
            does: 'Świeżo zainstalowany dodatek nie wie jeszcze, z czym ma rozmawiać. Adres aplikacji, e-mail i hasło podaje się raz. Hasło nie jest zapisywane — służy tylko do jednorazowego pobrania klucza dostępu.',
            click: 'Dodatki i motywy → przy „Supon Przetargi” Ustawienia → wypełnij pola → „Połącz”.',
            tone: 'green',
            screen: (
              <TbFrame>
                <div className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                  <p className="text-sm font-semibold text-slate-900">Supon Przetargi — ustawienia</p>
                  <p className="mt-0.5 text-xs text-slate-500">Połączenie z aplikacją</p>
                  <div className="mt-3 grid gap-2 sm:grid-cols-3">
                    <Field label="Adres aplikacji" value="https://przetargi.supon.rzeszow.pl" />
                    <Field label="E-mail" value="jan.kowalski@supon.rzeszow.pl" />
                    <Field label="Hasło" value="••••••••" />
                  </div>
                  <div className="mt-3 flex flex-wrap gap-2">
                    <Mark>
                      <Btn label="Połącz" />
                    </Mark>
                    <Btn label="Sprawdź połączenie" color="border" />
                  </div>
                </div>
              </TbFrame>
            ),
          },
          {
            action: 'Włączenie kolumny „Prowadzi”',
            does: 'Kolumna na liście wiadomości pokazuje przy każdym mailu, kto prowadzi z niego zapytanie, i „✓”, gdy odpowiedź do klienta już poszła. Niczego w mailu nie zapisuje i nie koloruje wierszy. Wchodzi przy starcie programu, więc po instalacji dodatku zamknij Thunderbirda i otwórz go ponownie — kolumna pojawi się sama.',
            click: 'Zamknij i otwórz Thunderbirda. Gdyby kolumny nadal nie było: Dodatki i motywy → przy „Supon Przetargi” Ustawienia → „Pokaż kolumnę” (w ustawieniach widać też stan kolumny).',
            tone: 'green',
            screen: (
              <TbFrame>
                <div className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                  <p className="text-sm font-semibold text-slate-900">Kolumna „Prowadzi”</p>
                  <p className="mt-1 text-xs text-slate-600">
                    Uruchom Thunderbirda ponownie — kolumna włącza się przy starcie programu.
                  </p>
                  <div className="mt-3 flex flex-wrap gap-2">
                    <Mark>
                      <Btn label="Pokaż kolumnę" />
                    </Mark>
                    <Btn label="Ukryj kolumnę" color="border" />
                  </div>
                </div>
              </TbFrame>
            ),
          },
          {
            action: 'Kolumna na liście wiadomości',
            does: 'Kolumna „Prowadzi” staje obok Tematu, Korespondentów i Daty. Szerokość, kolejność i ukrycie ustawia się ikoną po prawej stronie nagłówków listy — Thunderbird pamięta ten układ sam. Gdy kolumny nie widać mimo restartu, w Ustawieniach → Ogólne → Edytor konfiguracji ustawienie extensions.experiments.enabled musi być true (kolumna działa od Thunderbirda 128).',
            click: 'Ikona wyboru kolumn po prawej stronie nagłówków listy wiadomości.',
            tone: 'slate',
            screen: (
              <TbFrame>
                <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                  <div className="flex items-center gap-2 border-b border-slate-200 px-3 py-1.5 text-[11px] font-semibold text-slate-600">
                    <span className="flex-1">Temat</span>
                    <span className="w-28 shrink-0">Korespondenci</span>
                    <span className="w-16 shrink-0">Data</span>
                    <span className="w-28 shrink-0 text-blue-800">Prowadzi</span>
                    <Mark>
                      <span className="rounded border border-slate-300 px-1.5 py-0.5">☰</span>
                    </Mark>
                  </div>
                  {[
                    ['Zapytanie ofertowe — rękawice nitrylowe', 'Jan Kowalski', '09:14', 'Anna Kowalska'],
                    ['Prośba o wycenę kaloszy S5', 'Biuro ZOZ', 'wczoraj', 'Artur ✓'],
                    ['Faktura korygująca 4/2026', 'Księgowość', 'wczoraj', ''],
                  ].map(([subject, from, date, owner]) => (
                    <div
                      key={subject}
                      className="flex items-center gap-2 border-b border-slate-100 px-3 py-1.5 text-[11px] text-slate-700"
                    >
                      <span className="flex-1 truncate">{subject}</span>
                      <span className="w-28 shrink-0 truncate">{from}</span>
                      <span className="w-16 shrink-0">{date}</span>
                      <span className="w-28 shrink-0 font-medium text-slate-900">{owner || '—'}</span>
                      <span className="px-1.5 py-0.5 text-transparent">☰</span>
                    </div>
                  ))}
                </div>
              </TbFrame>
            ),
          },
          {
            action: 'Nowe wersje',
            does: 'Thunderbird sam pyta serwer o nowsze wydania i podmienia dodatek bez utraty ustawień. Dodatek dodatkowo przypomina powiadomieniem, gdy na serwerze leży nowsza wersja niż zainstalowana.',
            click: 'Nic — chyba że ręcznie: Dodatki i motywy → koło zębate → „Sprawdź dostępność aktualizacji”.',
            tone: 'slate',
            screen: (
              <TbFrame>
                <TbAddonsManager menu={gearMenu('aktualizacje')} />
              </TbFrame>
            ),
          },
        ]}
      />
    </div>
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
  pobieranie: () => <DownloadsHelp />,
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
