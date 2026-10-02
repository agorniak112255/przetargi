import type { ReactNode } from 'react'
import { AppFrame, Btn, Card, Field, Mark, Slideshow, Th } from './kit'

/** Zakładki modułu — jak CampaignsTabs w components/CampaignsUi.tsx. */
const CAMPAIGN_TABS = ['Moje kampanie', 'Wszystkie', 'Moje szablony', 'Grupy odbiorców', 'Wypisani'] as const

function CampaignTabs({ active }: { active: (typeof CAMPAIGN_TABS)[number] }) {
  return (
    <div className="mb-3 flex flex-wrap gap-1 border-b border-slate-200 text-[11px]">
      {CAMPAIGN_TABS.map((t) => (
        <span
          key={t}
          className={`-mb-px border-b-2 px-2.5 py-1.5 ${
            t === active ? 'border-blue-600 font-semibold text-blue-700' : 'border-transparent text-slate-600'
          }`}
        >
          {t}
        </span>
      ))}
    </div>
  )
}

/** Kroki kreatora kampanii — jak nawigacja „Kroki kampanii” w CampaignEditor (DraftWizard). */
const WIZARD_STEPS = [
  { n: 1, title: 'Produkty', hint: '2 pozycje', done: true },
  { n: 2, title: 'Odbiorcy', hint: '284 odbiorców', done: true },
  { n: 3, title: 'Treść', hint: 'układ: siatka po 2', done: true },
  { n: 4, title: 'Podgląd i test', hint: 'test wysłany', done: true },
  { n: 5, title: 'Wyślij', hint: '–', done: false },
] as const

function WizardSteps({ active, mark }: { active: number; mark?: boolean }) {
  return (
    <div className="mb-3 grid grid-cols-5 gap-1">
      {WIZARD_STEPS.map((s) => {
        const done = s.done && s.n < active
        const box = (
          <div
            className={`grid w-full gap-px rounded-lg border bg-white px-1.5 py-1.5 text-left ${
              s.n === active ? 'border-blue-600 ring-1 ring-blue-600' : 'border-slate-200'
            }`}
          >
            <b className={`text-[9px] font-semibold tracking-wide ${done ? 'text-emerald-700' : 'text-slate-400'}`}>
              KROK {s.n}
              {done ? ' ✓' : ''}
            </b>
            <span className="truncate text-[11px] font-medium text-slate-900">{s.title}</span>
            <em className="truncate text-[10px] not-italic text-slate-500">{s.n <= active ? s.hint : '–'}</em>
          </div>
        )
        return <div key={s.n}>{s.n === active && mark ? <Mark>{box}</Mark> : box}</div>
      })}
    </div>
  )
}

/** Atrapa okna (Modal z CampaignsUi): tytuł z ×, treść i stopka z przyciskami. */
function Dialog({ title, children, footer }: { title: string; children: ReactNode; footer: ReactNode }) {
  return (
    <div className="rounded-xl border border-slate-200 bg-white text-xs shadow-xl">
      <div className="flex items-start justify-between gap-3 border-b border-slate-200 px-3 py-2">
        <p className="text-sm font-semibold text-slate-900">{title}</p>
        <span className="text-lg leading-none text-slate-500">×</span>
      </div>
      <div className="space-y-2 px-3 py-2.5">{children}</div>
      <div className="flex flex-wrap items-center justify-end gap-2 border-t border-slate-200 px-3 py-2">{footer}</div>
    </div>
  )
}

function Chip({ tone, children }: { tone: 'slate' | 'green' | 'blue' | 'amber'; children: ReactNode }) {
  const cls = {
    slate: 'bg-slate-100 text-slate-700',
    green: 'bg-emerald-100 text-emerald-800',
    blue: 'bg-sky-100 text-blue-800',
    amber: 'bg-amber-100 text-amber-900',
  } as const
  return <span className={`inline-block rounded px-1.5 py-0.5 text-[10px] font-medium ${cls[tone]}`}>{children}</span>
}

/** Pasek „zeszło z magazynu” — jak DropBar w CampaignsUi. */
function Drop({ percent, days }: { percent: number; days: 7 | 30 }) {
  return (
    <span className="inline-flex items-center gap-1.5 whitespace-nowrap">
      <span className="inline-block h-1.5 w-14 overflow-hidden rounded bg-slate-200">
        <span className="block h-full bg-emerald-600" style={{ width: `${percent}%` }} />
      </span>
      <span className="tabular-nums text-slate-800">
        −{percent}% ({days} dni)
      </span>
    </span>
  )
}

/** Nagłówek kolumny z sortowaniem — jak SortTh (▲ ▼ dla aktywnej, ↕ dla pozostałych). */
function SortHead({ label, dir, right }: { label: string; dir?: '▲' | '▼'; right?: boolean }) {
  return (
    <th className={`p-2 font-semibold text-slate-700 ${right ? 'text-right' : ''}`}>
      {label} <span className={dir ? 'text-slate-700' : 'text-slate-300'}>{dir ?? '↕'}</span>
    </th>
  )
}

export function CampaignsHelp() {
  return (
    <Slideshow
      title="Kampanie"
      slides={[
        {
          action: 'Lista kampanii',
          does: 'Tu są Twoje kampanie mailowe z towarem: projekty (jeszcze niewysłane), zaplanowane i wysłane. Przy wysłanej widać liczbę odbiorców i ile towaru zeszło z magazynu po 7 i po 30 dniach. Moduł widzą osoby z uprawnieniem „Kampanie — własne kampanie”; osoba z samym „Kampanie — podgląd wysłanych” widzi tylko zakładkę „Wszystkie” z kampaniami po wysyłce i niczego w nich nie zmienia.',
          click: 'Menu „Kampanie”. Nowa kampania: „+ Pusta kampania” albo „+ Nowa kampania z Zapasów”. Istniejącą otwierasz przyciskiem „Edytuj” (projekt) albo „Otwórz” (wysłana).',
          tone: 'blue',
          screen: (
            <AppFrame nav="Kampanie">
              <div className="mb-3 flex flex-wrap items-end justify-between gap-2">
                <div>
                  <h1 className="text-xl font-semibold">Kampanie</h1>
                  <p className="text-[11px] text-slate-600">
                    Twoje kampanie. Wynik to przede wszystkim to, ile towaru zeszło z magazynu po wysyłce.
                  </p>
                </div>
                <div className="flex flex-wrap gap-2">
                  <Btn label="+ Pusta kampania" color="border" />
                  <Mark>
                    <Btn label="+ Nowa kampania z Zapasów" />
                  </Mark>
                </div>
              </div>
              <CampaignTabs active="Moje kampanie" />
              <Card>
                <table className="w-full text-left text-xs">
                  <thead>
                    <tr className="border-b bg-slate-50">
                      <Th>Kampania</Th>
                      <Th>Status</Th>
                      <Th>Wysyłka</Th>
                      <Th>Odbiorcy</Th>
                      <Th>Zeszło z magazynu</Th>
                      <Th />
                    </tr>
                  </thead>
                  <tbody>
                    <tr className="border-b">
                      <td className="p-2">
                        <span className="font-medium">Wyprzedaż obuwia S3</span>
                        <span className="block text-[10px] text-slate-500">K-0012 · 2 pozycje</span>
                      </td>
                      <td className="p-2">
                        <Chip tone="slate">Projekt</Chip>
                      </td>
                      <td className="p-2 text-slate-400">—</td>
                      <td className="p-2 text-slate-400">—</td>
                      <td className="p-2 text-slate-400">—</td>
                      <td className="p-2 text-right">
                        <span className="rounded border border-slate-300 px-2 py-0.5 text-[11px]">Edytuj</span>
                      </td>
                    </tr>
                    <tr className="border-b">
                      <td className="p-2">
                        <span className="font-medium">Rękawice powlekane — jesień</span>
                        <span className="block text-[10px] text-slate-500">K-0009 · 3 pozycje</span>
                      </td>
                      <td className="p-2">
                        <Chip tone="green">Wysłana</Chip>
                      </td>
                      <td className="whitespace-nowrap p-2 tabular-nums">18.09.2026 14:05</td>
                      <td className="p-2 tabular-nums">
                        284
                        <span className="block text-[10px] text-emerald-700">odpowiedzi 2</span>
                      </td>
                      <td className="p-2">
                        <Drop percent={38} days={30} />
                      </td>
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
          action: 'Krok 1: produkty',
          does: 'Kampania prowadzi przez pięć kroków: Produkty, Odbiorcy, Treść, Podgląd i test, Wyślij — wszystko zapisuje się samo. W kroku Produkty dodajesz najwyżej 12 pozycji: z Zapasów (towar zalegający w magazynie), z Produktów albo z listy „Zaproponuj pozycje”. Przy pozycji wpisujesz „Cena kampanii netto”, a „Cena przed” tylko wtedy, gdy naprawdę taka była — w mailu będzie przekreślona.',
          click: '„+ z Zapasów” otwiera Zapasy z paskiem tej kampanii: zaznacz wiersze i kliknij „Dodaj do K-0012” — wrócisz do kreatora. Możesz też zacząć w Zapasach: zaznacz towar, „Dodaj do kampanii ▾”, potem „Nowa kampania z zaznaczonych”.',
          tone: 'blue',
          screen: (
            <AppFrame nav="Kampanie">
              <p className="text-xs text-blue-600">← Kampanie</p>
              <h1 className="mb-2 text-lg font-semibold">Wyprzedaż obuwia S3</h1>
              <WizardSteps active={1} />
              <div className="rounded-xl bg-white shadow-sm">
                <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-3 py-2">
                  <b className="text-sm text-slate-900">Produkty w kampanii</b>
                  <div className="flex flex-wrap gap-1.5">
                    <Mark>
                      <Btn label="+ z Zapasów" color="border" />
                    </Mark>
                    <Btn label="+ z Produktów" color="border" />
                    <Btn label="Zaproponuj pozycje" color="border" />
                  </div>
                </div>
                <table className="w-full text-left text-xs">
                  <thead>
                    <tr className="border-b bg-slate-50">
                      <Th>Produkt</Th>
                      <Th>Dostępne</Th>
                      <Th>Koszt</Th>
                      <Th>Cena kampanii netto</Th>
                      <Th>Cena przed</Th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr className="border-b align-top">
                      <td className="p-2">
                        <b className="block font-medium">Półbuty robocze S3 SRC</b>
                        <span className="font-mono text-[10px] text-slate-500">BOBU41210</span>
                      </td>
                      <td className="p-2 tabular-nums">
                        120 par
                        <span className="block text-[10px] text-slate-500">stan z 01.10.2026</span>
                      </td>
                      <td className="p-2 tabular-nums">61,20 zł</td>
                      <td className="p-2">
                        <span className="inline-block rounded border border-slate-300 px-2 py-0.5 tabular-nums">89,00</span>
                      </td>
                      <td className="p-2">
                        <span className="inline-block rounded border border-slate-300 px-2 py-0.5 tabular-nums">119,00</span>
                        <span className="block text-[10px] text-slate-500">przekreślona w mailu</span>
                      </td>
                    </tr>
                    <tr className="border-b align-top">
                      <td className="p-2">
                        <b className="block font-medium">Trzewiki zimowe S3</b>
                        <span className="font-mono text-[10px] text-slate-500">BOBU45120</span>
                      </td>
                      <td className="p-2 tabular-nums">
                        46 par
                        <span className="block text-[10px] text-slate-500">stan z 01.10.2026</span>
                      </td>
                      <td className="p-2 tabular-nums">98,40 zł</td>
                      <td className="p-2">
                        <span className="inline-block rounded border border-slate-300 px-2 py-0.5 tabular-nums">139,00</span>
                      </td>
                      <td className="p-2">
                        <span className="inline-block rounded border border-slate-300 px-2 py-0.5 text-slate-400">—</span>
                        <span className="block text-[10px] text-slate-500">nie pokazujemy</span>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </AppFrame>
          ),
        },
        {
          action: 'Drugi przycisk przy produkcie',
          does: 'Pod każdym produktem w mailu jest przycisk „Zapytaj o ofertę” — klient wysyła wtedy mail do Ciebie z kodem kampanii i towaru. Możesz dodać drugi przycisk z własnym linkiem, na przykład do sklepu internetowego: wpisujesz link (musi zaczynać się od https://), nazwę przycisku i wybierasz kolor. Okno od razu pokazuje oba przyciski tak, jak w mailu.',
          click: 'Przy pozycji w kroku Produkty „+ Dodaj link”. W oknie wypełnij „Link” i „Nazwa przycisku”, wybierz „Kolor przycisku” i kliknij „Zapisz”. Później przy pozycji są „zmień” i „usuń”.',
          tone: 'blue',
          screen: (
            <AppFrame nav="Kampanie">
              <div className="mx-auto max-w-md">
                <Dialog
                  title="Dodaj link przy produkcie"
                  footer={
                    <>
                      <Btn label="Anuluj" color="border" />
                      <Mark>
                        <Btn label="Zapisz" />
                      </Mark>
                    </>
                  }
                >
                  <p className="text-slate-600">
                    W mailu pod „Zapytaj o ofertę” przy produkcie <b className="font-medium text-slate-800">Półbuty robocze S3 SRC</b>{' '}
                    pojawi się drugi przycisk prowadzący pod ten link.
                  </p>
                  <Field label="Link" value="https://sklep.przyklad.pl/polbuty-s3" />
                  <Field label="Nazwa przycisku" value="Kup w sklepie" />
                  <div>
                    <span className="mb-1 block font-medium text-slate-700">Kolor przycisku</span>
                    <div className="flex items-center gap-1.5">
                      <span className="h-5 w-5 rounded-full bg-emerald-700 ring-1 ring-slate-300" />
                      <span className="h-5 w-5 rounded-full border-2 border-slate-900 bg-blue-700 ring-2 ring-slate-300" />
                      <span className="h-5 w-5 rounded-full bg-red-700 ring-1 ring-slate-300" />
                      <span className="h-5 w-5 rounded-full bg-amber-700 ring-1 ring-slate-300" />
                      <span className="h-5 w-5 rounded-full bg-violet-700 ring-1 ring-slate-300" />
                      <span className="h-5 w-5 rounded-full bg-slate-700 ring-1 ring-slate-300" />
                      <span className="text-slate-500">Niebieski</span>
                    </div>
                  </div>
                  <div>
                    <span className="mb-1 block font-medium text-slate-700">Podgląd w mailu</span>
                    <div className="w-44 space-y-1.5 rounded-md border border-slate-200 p-2">
                      <div className="rounded bg-emerald-700 py-1 text-center text-[11px] font-bold text-white">Zapytaj o ofertę</div>
                      <div className="rounded bg-blue-700 py-1 text-center text-[11px] font-bold text-white">Kup w sklepie</div>
                    </div>
                  </div>
                </Dialog>
              </div>
            </AppFrame>
          ),
        },
        {
          action: 'Grupa odbiorców z pliku',
          does: 'Grupa odbiorców to Twoja lista adresów e-mail. W zakładce „Grupy odbiorców” zakładasz grupę i dodajesz do niej adresy: pojedynczo, wklejone albo z pliku Excel lub CSV (na przykład eksport klientów ze sklepu). Nad każdą kolumną pliku wybierasz, co zawiera — wymagany jest tylko adres e-mail, a kolumny „Pomiń” nie są zapisywane. Dla całego importu wybierasz podstawę wysyłki: „Stały klient” albo „Zgoda”; bez podstawy adres nie dostanie kampanii.',
          click: 'Zakładka „Grupy odbiorców”, „+ Nowa grupa”, potem przy grupie „Otwórz / dodaj adresy”. W ramce „Z pliku (Excel, CSV)” przycisk „Wybierz plik…”; w oknie ustaw kolumny i kliknij „Importuj … wierszy”.',
          tone: 'blue',
          screen: (
            <AppFrame nav="Kampanie">
              <Dialog
                title="Import z pliku: klienci-sklep.xlsx"
                footer={
                  <>
                    <Btn label="Anuluj" color="border" />
                    <Mark>
                      <Btn label="Importuj 240 wierszy" />
                    </Mark>
                  </>
                }
              >
                <p className="flex flex-wrap items-center gap-3 text-slate-700">
                  <span>☑ pierwszy wiersz to nagłówki kolumn</span>
                  <span className="text-slate-500">Wierszy z danymi: 240</span>
                </p>
                <p className="text-slate-600">
                  Nad każdą kolumną wybierz, co zawiera. Wymagany jest tylko <b>adres e-mail</b>; kolumny „Pomiń” nie są
                  zapisywane.
                </p>
                <table className="w-full text-left text-xs">
                  <thead>
                    <tr className="border-b bg-slate-50">
                      <th className="bg-blue-50 p-1.5 align-top">
                        <Mark>
                          <span className="block rounded border border-blue-400 bg-white px-1.5 py-0.5 font-medium text-blue-900">
                            Adres e-mail ▾
                          </span>
                        </Mark>
                        <span className="mt-1 block font-semibold text-slate-800">E-mail</span>
                      </th>
                      <th className="bg-blue-50 p-1.5 align-top">
                        <span className="block rounded border border-blue-400 bg-white px-1.5 py-0.5 font-medium text-blue-900">
                          Imię i nazwisko (razem) ▾
                        </span>
                        <span className="mt-1 block font-semibold text-slate-800">Osoba</span>
                      </th>
                      <th className="bg-blue-50 p-1.5 align-top">
                        <span className="block rounded border border-blue-400 bg-white px-1.5 py-0.5 font-medium text-blue-900">
                          Firma ▾
                        </span>
                        <span className="mt-1 block font-semibold text-slate-800">Firma</span>
                      </th>
                      <th className="p-1.5 align-top">
                        <span className="block rounded border border-slate-300 bg-white px-1.5 py-0.5 text-slate-500">Pomiń ▾</span>
                        <span className="mt-1 block font-semibold text-slate-800">Miasto</span>
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr className="border-b">
                      <td className="p-1.5">bhp@mittal.pl</td>
                      <td className="p-1.5">Jan Nowak</td>
                      <td className="p-1.5">Mittal</td>
                      <td className="p-1.5 text-slate-400">Kraków</td>
                    </tr>
                    <tr className="border-b">
                      <td className="p-1.5">zaopatrzenie@sanitex.pl</td>
                      <td className="p-1.5">Anna Nowak</td>
                      <td className="p-1.5">Sanitex</td>
                      <td className="p-1.5 text-slate-400">Rzeszów</td>
                    </tr>
                  </tbody>
                </table>
                <div className="rounded border border-slate-200 p-2">
                  <p className="text-slate-600">Podstawa wysyłki dla całego importu</p>
                  <p className="mt-1 flex flex-wrap gap-4">
                    <span>
                      ◉ <b>Stały klient</b> (kupuje u nas)
                    </span>
                    <span className="text-slate-600">
                      ○ <b>Zgoda</b> na informacje handlowe
                    </span>
                  </p>
                </div>
              </Dialog>
            </AppFrame>
          ),
        },
        {
          action: 'Krok 2: odbiorcy',
          does: 'Zaznaczasz grupy odbiorców i/lub klientów z ERP XL (adresy z kartotek kontrahentów): „Kupowali te towary”, „Kupowali z tej grupy” albo „Moi klienci”. „Pokaż / wybierz” otwiera adresy grupy lub listę klientów — możesz je przejrzeć i odznaczyć część. Ramka „Kto dostanie maila” liczy na bieżąco i odejmuje powtórzone adresy, wypisanych z mailingu i tych, którzy niedawno dostali inną kampanię.',
          click: 'Krok „Odbiorcy”: zaznacz grupę w „Wybierz grupy odbiorców”, w „Klienci z ERP XL” wybierz rodzaj klientów, potem „Dalej: treść →”.',
          tone: 'blue',
          screen: (
            <AppFrame nav="Kampanie">
              <WizardSteps active={2} />
              <div className="grid items-start gap-3 md:grid-cols-[minmax(0,1fr)_190px]">
                <div className="space-y-3">
                  <div className="rounded-xl bg-white text-xs shadow-sm">
                    <div className="flex items-center justify-between border-b border-slate-200 px-3 py-2">
                      <b className="text-sm text-slate-900">Wybierz grupy odbiorców</b>
                      <span className="text-blue-600">Zarządzaj grupami</span>
                    </div>
                    <div className="flex items-center gap-2 px-3 py-2">
                      <Mark>
                        <span className="grid h-4 w-4 place-items-center rounded bg-blue-600 text-[10px] text-white">✓</span>
                      </Mark>
                      <span className="min-w-0 flex-1">
                        <b className="font-medium">Klienci sklepu internetowego</b>
                        <small className="block text-[10px] text-slate-500">
                          moja grupa · 240 adresów · podstawa: stały klient 240, zgoda 0
                        </small>
                      </span>
                      <span className="rounded border border-slate-300 px-2 py-0.5 text-[11px]">Pokaż / wybierz</span>
                    </div>
                  </div>
                  <div className="rounded-xl bg-white text-xs shadow-sm">
                    <div className="border-b border-slate-200 px-3 py-2">
                      <b className="text-sm text-slate-900">Klienci z ERP XL</b>
                    </div>
                    <div className="grid gap-1.5 p-3 sm:grid-cols-2">
                      <span className="rounded border border-slate-200 px-2 py-1.5">○ Bez klientów z ERP XL</span>
                      <span className="rounded border border-blue-600 bg-sky-50 px-2 py-1.5">
                        ◉ <b className="font-medium">Kupowali te towary</b>
                      </span>
                      <span className="rounded border border-slate-200 px-2 py-1.5">○ Kupowali z tej grupy</span>
                      <span className="rounded border border-slate-200 px-2 py-1.5">○ Moi klienci</span>
                    </div>
                  </div>
                </div>
                <div className="rounded-xl bg-white p-3 text-xs shadow-sm">
                  <b className="mb-1.5 block text-sm text-slate-900">Kto dostanie maila</b>
                  <dl className="grid grid-cols-[1fr_auto] gap-x-2 gap-y-1">
                    <dt className="text-slate-600">Adresy z grup</dt>
                    <dd className="text-right tabular-nums">240</dd>
                    <dt className="text-slate-600">Klienci z ERP XL</dt>
                    <dd className="text-right tabular-nums">61</dd>
                    <dt className="text-slate-600">Powtórzone adresy</dt>
                    <dd className="text-right tabular-nums">−9</dd>
                    <dt className="text-slate-600">Wypisani z mailingu</dt>
                    <dd className="text-right tabular-nums">−3</dd>
                    <dt className="text-slate-600">Dostali niedawno inną kampanię</dt>
                    <dd className="text-right tabular-nums">−5</dd>
                    <dt className="border-t border-slate-200 pt-1 font-semibold">Wyślemy do</dt>
                    <dd className="border-t border-slate-200 pt-1 text-right font-semibold tabular-nums text-emerald-700">284</dd>
                  </dl>
                  <span className="mt-2 block rounded bg-blue-600 px-2 py-1.5 text-center font-medium text-white">
                    Dalej: treść →
                  </span>
                </div>
              </div>
            </AppFrame>
          ),
        },
        {
          action: 'Krok 3: treść, układ i nagłówek',
          does: 'Wpisujesz „Temat wiadomości” (do tego „Zajawka w skrzynce” i „Ważne do”). Mail składa się z elementów: na górze „Logo” — bez własnego obrazka mail zaczyna się banerem SUPON na całą szerokość. W elemencie „Produkty” wybierasz „Układ”: „Siatka po 3” (trzy produkty w rzędzie) albo „Siatka po 2” (większe zdjęcia, dwa w rzędzie); są też układy z opisem i „Cennik (tabela)”. Podgląd obok odświeża się sam.',
          click: 'Krok „Treść”: pole „Temat wiadomości”, w elemencie „Produkty” lista „Układ”. Własne logo zamiast banera: „Wgraj obrazek” w elemencie „Logo”. Gotowy wygląd całego maila: lista „Szablon” i „Zastosuj”.',
          tone: 'blue',
          screen: (
            <AppFrame nav="Kampanie">
              <WizardSteps active={3} />
              <div className="grid items-start gap-3 md:grid-cols-[minmax(0,1fr)_170px]">
                <div className="space-y-2 rounded-xl bg-white p-3 text-xs shadow-sm">
                  <Field label="Temat wiadomości" value="Wyprzedaż BHP: półbuty S3 od 89 zł – do wyczerpania" />
                  <b className="block pt-1 text-sm text-slate-900">Wygląd maila</b>
                  <div className="rounded-lg border border-slate-200">
                    <p className="border-b border-slate-100 bg-slate-50 px-2 py-1 font-semibold text-slate-800">
                      <span className="text-slate-500">1.</span> Logo
                    </p>
                    <div className="flex flex-wrap items-center gap-2 px-2 py-1.5">
                      <span className="rounded bg-emerald-700 px-6 py-2 text-[11px] font-bold tracking-wide text-white">SUPON</span>
                      <span className="rounded border border-slate-300 px-2 py-0.5 text-[11px]">Wgraj obrazek</span>
                      <span className="text-[10px] text-slate-500">domyślny baner SUPON</span>
                    </div>
                  </div>
                  <div className="rounded-lg border border-slate-200">
                    <p className="border-b border-slate-100 bg-slate-50 px-2 py-1 font-semibold text-slate-800">
                      <span className="text-slate-500">4.</span> Produkty
                    </p>
                    <div className="space-y-1 px-2 py-1.5">
                      <span className="inline-flex items-center gap-1.5 text-slate-600">
                        Układ
                        <Mark>
                          <span className="rounded border border-slate-300 bg-white px-2 py-0.5 text-slate-800">Siatka po 2 ▾</span>
                        </Mark>
                      </span>
                      <p className="text-[10px] text-slate-500">
                        Większe zdjęcia, dwa produkty w rzędzie, karty równej wysokości. Nad pozycjami zawsze „Ceny netto ważne
                        do …”.
                      </p>
                    </div>
                  </div>
                </div>
                <div className="rounded-xl bg-white p-2 text-[10px] shadow-sm">
                  <b className="mb-1 block text-xs text-slate-800">Podgląd</b>
                  <div className="rounded bg-emerald-700 py-1.5 text-center font-bold text-white">SUPON</div>
                  <div className="mt-1.5 grid grid-cols-2 gap-1">
                    {['Półbuty S3', 'Trzewiki S3'].map((n) => (
                      <div key={n} className="rounded border border-slate-200 p-1 text-center">
                        <div className="mb-1 h-8 rounded bg-slate-100" />
                        <span className="block truncate">{n}</span>
                        <span className="mt-0.5 block rounded bg-emerald-700 py-0.5 text-white">Zapytaj o ofertę</span>
                      </div>
                    ))}
                  </div>
                </div>
              </div>
            </AppFrame>
          ),
        },
        {
          action: 'Wysyłka albo zaplanowanie',
          does: 'W kroku „Podgląd i test” widzisz mail dokładnie tak, jak dostanie go klient, i wysyłasz test na swoją skrzynkę. W kroku „Wyślij” wysyłasz od razu albo wybierasz dzień i godzinę. Przed wysyłką i przed zaplanowaniem otwiera się okno z dokładną listą adresów: zakładki „Do wysyłki” i „Pominięci” (z powodem), wyszukiwarka i kolumna „Skąd” — grupa albo klient z ERP XL.',
          click: '„Wyślij teraz do 284 odbiorców” albo „zaplanuj na” i „Zaplanuj”. W oknie sprawdź listę i kliknij „Wyślij do 284 odbiorców” (przy planowaniu „Zaplanuj”).',
          tone: 'amber',
          screen: (
            <AppFrame nav="Kampanie">
              <div className="mb-3 flex flex-wrap items-center gap-2 text-xs">
                <Btn label="Wyślij teraz do 284 odbiorców" />
                <span className="text-slate-500">albo zaplanuj na</span>
                <span className="rounded border border-slate-300 bg-white px-2 py-1 tabular-nums">06.10.2026 08:00</span>
                <Btn label="Zaplanuj" color="border" />
              </div>
              <Dialog
                title="Wysłać kampanię teraz?"
                footer={
                  <>
                    <Btn label="Anuluj" color="border" />
                    <Mark>
                      <Btn label="Wyślij do 284 odbiorców" />
                    </Mark>
                  </>
                }
              >
                <div className="flex flex-wrap items-center gap-4 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
                  <div>
                    <p className="text-[10px] font-semibold uppercase text-slate-500">Wyślemy do</p>
                    <p className="text-2xl font-semibold tabular-nums text-emerald-700">284</p>
                  </div>
                  <p className="min-w-0 flex-1 tabular-nums text-slate-600">
                    z grup <b>240</b> · z ERP XL <b>61</b> · powtórzone <b>−9</b> · pominięci <b>−8</b>
                  </p>
                </div>
                <div className="flex flex-wrap items-end justify-between gap-2 border-b border-slate-200">
                  <span className="flex gap-1">
                    <span className="-mb-px border-b-2 border-blue-600 px-2 py-1 font-semibold text-blue-700">Do wysyłki (284)</span>
                    <span className="px-2 py-1 text-slate-600">Pominięci (8)</span>
                  </span>
                  <span className="mb-1 rounded border border-slate-300 px-2 py-0.5 text-slate-400">
                    Szukaj: adres, osoba, grupa, akronim XL…
                  </span>
                </div>
                <table className="w-full text-left text-xs">
                  <thead>
                    <tr className="border-b bg-slate-50">
                      <Th>E-mail</Th>
                      <Th>Osoba / firma</Th>
                      <Th>Skąd</Th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr className="border-b">
                      <td className="p-2 font-mono text-[11px]">bhp@mittal.pl</td>
                      <td className="p-2">Jan Nowak</td>
                      <td className="p-2">
                        <Chip tone="green">grupa: Klienci sklepu internetowego</Chip>
                      </td>
                    </tr>
                    <tr className="border-b">
                      <td className="p-2 font-mono text-[11px]">zaopatrzenie@sanitex.pl</td>
                      <td className="p-2">Sanitex</td>
                      <td className="p-2">
                        <Chip tone="blue">XL: SANITEX</Chip>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </Dialog>
            </AppFrame>
          ),
        },
        {
          action: 'Wysłana kampania: wynik i dopisanie odbiorców',
          does: 'Wysłanej kampanii nie da się już zmienić. Na górze są liczniki, niżej tabele: „Wynik: ile zeszło z magazynu” (stan przy wysyłce, po 7 i po 30 dniach, kto z odbiorców kupił według faktur z ERP XL, kliknięcia) oraz „Odbiorcy” ze statusem każdego adresu. Każdą tabelę posortujesz, klikając nagłówek kolumny (▲ ▼). „+ Dopisz odbiorców” wysyła ten sam mail kolejnym osobom — kto już go dostał, nie dostanie drugi raz.',
          click: 'Na liście kampanii „Otwórz”. Sortowanie: kliknij nazwę kolumny, na przykład „Zeszło”. Dopisanie: „+ Dopisz odbiorców”, wybór grup lub klientów, „Dalej: sprawdź nowych odbiorców →”.',
          tone: 'blue',
          screen: (
            <AppFrame nav="Kampanie">
              <h1 className="text-lg font-semibold">Rękawice powlekane — jesień</h1>
              <p className="mb-2 flex items-center gap-2 text-[11px] text-slate-600">
                <Chip tone="green">Wysłana</Chip> K-0009
              </p>
              <div className="mb-2 grid grid-cols-4 gap-1.5">
                {[
                  ['284', 'Odbiorcy'],
                  ['284', 'Wysłane'],
                  ['0', 'Błędy'],
                  ['0', 'Pominięte (wypisani w trakcie)'],
                ].map(([v, l]) => (
                  <div key={l} className="rounded-lg bg-white px-2 py-1.5 shadow-sm">
                    <b className="block text-base tabular-nums text-slate-800">{v}</b>
                    <span className="block truncate text-[10px] text-slate-500">{l}</span>
                  </div>
                ))}
              </div>
              <div className="mb-2 flex items-center justify-between gap-2 rounded-xl bg-white px-3 py-1.5 text-[11px] shadow-sm">
                <span className="text-slate-600">Chcesz wysłać tę kampanię jeszcze komuś?</span>
                <Mark>
                  <span className="rounded border border-slate-300 px-2 py-1">+ Dopisz odbiorców</span>
                </Mark>
              </div>
              <div className="rounded-xl bg-white shadow-sm">
                <b className="block border-b border-slate-200 px-3 py-1.5 text-sm text-slate-900">Wynik: ile zeszło z magazynu</b>
                <table className="w-full text-left text-xs">
                  <thead>
                    <tr className="border-b bg-slate-50">
                      <SortHead label="Pozycja" />
                      <SortHead label="Przy wysyłce" right />
                      <SortHead label="Po 30 dniach" right />
                      <SortHead label="Zeszło" dir="▼" />
                      <SortHead label="Kliknięcia" right />
                    </tr>
                  </thead>
                  <tbody>
                    <tr className="border-b">
                      <td className="p-2">
                        Rękawice powlekane nitrylem <span className="font-mono text-[10px] text-slate-500">ARĘK92600</span>
                      </td>
                      <td className="p-2 text-right tabular-nums">800 par</td>
                      <td className="p-2 text-right tabular-nums">380 par</td>
                      <td className="p-2">
                        <Drop percent={52} days={30} />
                      </td>
                      <td className="p-2 text-right tabular-nums">14</td>
                    </tr>
                    <tr className="border-b">
                      <td className="p-2">
                        Rękawice powlekane lateksem <span className="font-mono text-[10px] text-slate-500">ARĘK92610</span>
                      </td>
                      <td className="p-2 text-right tabular-nums">500 par</td>
                      <td className="p-2 text-right tabular-nums">390 par</td>
                      <td className="p-2">
                        <Drop percent={22} days={30} />
                      </td>
                      <td className="p-2 text-right tabular-nums">6</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </AppFrame>
          ),
        },
        {
          action: 'Odpowiedzi klientów',
          does: 'Tabela „Odpowiedzi klientów” zbiera maile ze skrzynki autora kampanii: z kodem kampanii w temacie (przycisk „Zapytaj o ofertę”) i odpowiedzi na mail kampanii; skrzynka jest sprawdzana zwykle co 10 minut. Kod w kolumnie „Towar” jest niebieski i klikalny — otwiera kartę produktu tej pozycji. System nie czyta treści maili: klientowi odpowiadasz jak zwykle ze swojej poczty.',
          click: 'W wysłanej kampanii kliknij niebieski kod w kolumnie „Towar”. Żeby od razu wczytać nowe maile: „Sprawdź skrzynkę teraz”.',
          tone: 'green',
          screen: (
            <AppFrame nav="Kampanie">
              <div className="rounded-xl bg-white shadow-sm">
                <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-3 py-2">
                  <b className="text-sm text-slate-900">Odpowiedzi klientów</b>
                  <span className="flex flex-wrap items-center gap-2 text-[11px] text-slate-500">
                    2 odpowiedzi · odpowiedziało 2 z 284 odbiorców
                    <span className="rounded border border-slate-300 px-2 py-0.5 text-slate-700">Sprawdź skrzynkę teraz</span>
                  </span>
                </div>
                <table className="w-full text-left text-xs">
                  <thead>
                    <tr className="border-b bg-slate-50">
                      <SortHead label="Od" />
                      <SortHead label="Otrzymano" dir="▼" />
                      <SortHead label="Towar" />
                      <SortHead label="Temat" />
                    </tr>
                  </thead>
                  <tbody>
                    <tr className="border-b align-top">
                      <td className="p-2">
                        <span className="font-mono">bhp@mittal.pl</span>
                        <span className="block text-[10px] text-slate-500">Jan Nowak</span>
                      </td>
                      <td className="whitespace-nowrap p-2 tabular-nums text-slate-600">20.09.2026 09:12</td>
                      <td className="p-2">
                        <Mark>
                          <span className="font-mono text-blue-600 underline">ARĘK92600</span>
                        </Mark>
                      </td>
                      <td className="p-2">
                        Zapytanie K-0009 ARĘK92600
                        <span className="block text-[10px] text-slate-500">kod kampanii w temacie</span>
                      </td>
                    </tr>
                    <tr className="border-b align-top">
                      <td className="p-2">
                        <span className="font-mono">zaopatrzenie@sanitex.pl</span>
                      </td>
                      <td className="whitespace-nowrap p-2 tabular-nums text-slate-600">19.09.2026 11:40</td>
                      <td className="p-2 text-slate-400">—</td>
                      <td className="p-2">
                        Odp: Rękawice powlekane — jesień
                        <span className="block text-[10px] text-slate-500">odpowiedź na mail kampanii</span>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </AppFrame>
          ),
        },
        {
          action: 'Usunięcie kampanii',
          does: 'Projekt usuwasz przyciskiem „Usuń projekt”. Wysłaną kampanię usuwa „Usuń kampanię” — tylko osoba z uprawnieniem „Kampanie — usuwanie wysłanych”; znika razem z odbiorcami, kliknięciami, odpowiedziami klientów i wynikami sprzedaży, tego nie da się cofnąć. Czerwony przycisk w oknie działa dopiero po wpisaniu słowa „Tak”. Maile, które wyszły, zostają u klientów, a wypisani z mailingu dalej nie dostaną kampanii.',
          click: 'U góry kampanii „Usuń kampanię” (przy projekcie „Usuń projekt”). W oknie wpisz Tak w polu pod treścią i kliknij czerwony „Usuń kampanię”.',
          tone: 'amber',
          screen: (
            <AppFrame nav="Kampanie">
              <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h1 className="text-lg font-semibold">Rękawice powlekane — jesień</h1>
                <span className="flex gap-2">
                  <Btn label="Duplikuj" color="border" />
                  <span className="rounded border border-slate-300 bg-white px-3 py-2 text-xs font-medium text-red-700">
                    Usuń kampanię
                  </span>
                </span>
              </div>
              <div className="mx-auto max-w-md">
                <Dialog
                  title="Usunąć wysłaną kampanię?"
                  footer={
                    <>
                      <Btn label="Anuluj" color="border" />
                      <span className="rounded bg-red-600 px-3 py-2 text-xs font-medium text-white">Usuń kampanię</span>
                    </>
                  }
                >
                  <p className="text-slate-800">
                    <b>K-0009 Rękawice powlekane — jesień</b> zniknie z listy razem z odbiorcami, kliknięciami, odpowiedziami
                    klientów i wynikami sprzedaży. Tego nie da się cofnąć.
                  </p>
                  <p className="text-slate-600">
                    Maile, które wyszły, zostają u klientów. Wypisy z mailingu zostają — te adresy dalej nie dostaną kampanii.
                  </p>
                  <label className="block text-slate-700">
                    Aby potwierdzić, wpisz <b>Tak</b>
                    <span className="mt-1 block">
                      <Mark>
                        <span className="block w-32 rounded border border-slate-300 bg-white px-2 py-1 text-sm text-slate-800">Tak</span>
                      </Mark>
                    </span>
                  </label>
                </Dialog>
              </div>
            </AppFrame>
          ),
        },
      ]}
    />
  )
}
