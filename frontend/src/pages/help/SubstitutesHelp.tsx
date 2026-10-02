import type { ReactNode } from 'react'
import { AppFrame, Card, LivePage, Mark, Slideshow } from './kit'
import { useAuth } from '../../auth'
import { can } from '../../lib/api'
import { Substitutes } from '../Substitutes'

/**
 * Samouczek modułu Zamienniki po przebudowie ekranu (pages/Substitutes.tsx, components/SubstituteMatrix.tsx,
 * lib/substitutes.ts) i zamienników w przetargu (pages/TenderDetail.tsx). Napisy przepisane z tych plików;
 * dane w atrapach przykładowe.
 */

function Kpi({ value, label, hint, alert, active, mark }: {
  value: string
  label: string
  hint?: string
  alert?: boolean
  active?: boolean
  mark?: boolean
}) {
  const box = (
    <div
      className={`w-full rounded-xl border bg-white px-2.5 py-2 shadow-sm ${
        active ? 'border-slate-800 ring-1 ring-slate-800' : 'border-slate-200'
      }`}
    >
      <b className={`block text-lg font-semibold tabular-nums ${alert ? 'text-amber-800' : 'text-slate-800'}`}>{value}</b>
      <span className="block text-xs text-slate-500">{label}</span>
      {(hint || active) && <span className="block text-[11px] text-slate-500">{active ? '✓ filtr włączony' : hint}</span>}
    </div>
  )
  return mark ? <Mark>{box}</Mark> : box
}

function Select({ value, mark }: { value: string; mark?: boolean }) {
  const box = (
    <span className="inline-block whitespace-nowrap rounded-md border border-slate-300 bg-white px-2 py-1.5 text-xs text-slate-700">
      {value} ▾
    </span>
  )
  return mark ? <Mark>{box}</Mark> : box
}

function Maker({ name, main }: { name: string; main?: boolean }) {
  return (
    <span
      className={`inline-block rounded-md px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide ${
        main ? 'bg-slate-800 text-white' : 'border border-slate-300 bg-slate-50 text-slate-700'
      }`}
    >
      {name}
    </span>
  )
}

const STATUS_CLS = {
  'Do decyzji': 'bg-amber-100 text-amber-800',
  Zatwierdzony: 'bg-emerald-100 text-emerald-800',
  Odrzucony: 'bg-red-100 text-red-700',
} as const

type Status = keyof typeof STATUS_CLS

function StatusPill({ status }: { status: Status }) {
  return <span className={`whitespace-nowrap rounded-full px-2 py-0.5 text-[10px] font-medium ${STATUS_CLS[status]}`}>{status}</span>
}

const TYPE_CLS = {
  Preferowany: 'border-emerald-300 text-emerald-800',
  Premium: 'border-blue-200 text-blue-700',
  Tańszy: 'border-slate-300 text-slate-700',
  Awaryjny: 'border-slate-300 text-slate-500',
} as const

type SubType = keyof typeof TYPE_CLS

function TypeTag({ type }: { type: SubType }) {
  return <span className={`whitespace-nowrap rounded border px-1.5 py-px text-[10px] font-medium ${TYPE_CLS[type]}`}>{type}</span>
}

function Source({ auto }: { auto: boolean }) {
  return <span className="whitespace-nowrap text-[10px] text-slate-400">{auto ? 'automat' : 'ręczny'}</span>
}

function Price({ value, diff, cheaper }: { value: string; diff: string; cheaper?: boolean }) {
  return (
    <span className="inline-flex items-baseline gap-x-1.5 text-xs tabular-nums">
      <span className="font-semibold text-slate-800">{value}</span>
      <span className={`text-[11px] ${cheaper ? 'font-semibold text-emerald-700' : 'text-slate-500'}`}>{diff}</span>
    </span>
  )
}

function Thumb({ size = 'h-9 w-9' }: { size?: string }) {
  return <span className={`${size} shrink-0 rounded-lg border border-slate-200 bg-slate-100`} />
}

/** Kompaktowa karta zamiennika w rzędzie pod kartą główną (SubstituteChip). */
function Chip({ maker, name, price, diff, cheaper, status, type, auto, summary, focused }: {
  maker: string
  name: string
  price: string
  diff: string
  cheaper?: boolean
  status: Status
  type: SubType
  auto: boolean
  summary: string
  focused?: boolean
}) {
  const accent =
    status === 'Zatwierdzony' ? 'border-l-emerald-500' : status === 'Odrzucony' ? 'border-l-red-300' : 'border-l-amber-400'
  return (
    <div
      className={`flex w-48 shrink-0 flex-col rounded-lg border border-l-[3px] bg-white p-2 ${accent} ${
        focused ? 'border-slate-800 ring-1 ring-slate-800' : 'border-slate-200'
      }`}
    >
      <span className="flex min-w-0 gap-2">
        <Thumb />
        <span className="min-w-0">
          <Maker name={maker} />
          <span className="mt-0.5 block text-[11px] font-medium leading-snug text-slate-800">{name}</span>
        </span>
      </span>
      <span className="mt-1.5 flex items-center justify-between gap-1">
        <Price value={price} diff={diff} cheaper={cheaper} />
        <StatusPill status={status} />
      </span>
      <span className="mt-1 flex flex-wrap items-center gap-1">
        <TypeTag type={type} />
        <Source auto={auto} />
      </span>
      <span className="mt-1 text-[10.5px] leading-snug text-slate-500">{summary}</span>
    </div>
  )
}

/** Lewa część grupy: karta główna z przyciskiem porównania. */
function MainCard({ open, mark }: { open?: boolean; mark?: boolean }) {
  const btn = (
    <span className="whitespace-nowrap rounded-md border border-slate-300 px-2 py-0.5 text-[11px] font-medium text-slate-700">
      {open ? '▴ Zwiń porównanie' : '▾ Porównaj parametry'}
    </span>
  )
  return (
    <div className="flex w-56 shrink-0 gap-2">
      <Thumb size="h-12 w-12" />
      <div className="min-w-0">
        <div className="flex flex-wrap items-center gap-1">
          <Maker name="ARDON" main />
          <span className="rounded-full bg-slate-100 px-2 py-px text-[10px] text-slate-600">Obuwie</span>
        </div>
        <p className="mt-0.5 text-sm font-semibold leading-snug text-slate-900">Półbuty ochronne S3 SRC</p>
        <p className="mt-0.5 flex gap-2 text-[11px] text-slate-500">
          <span className="font-mono">G3070</span>
          <span className="font-semibold tabular-nums text-slate-800">189,00 zł</span>
        </p>
        <div className="mt-1 flex flex-wrap gap-1">
          {['półbuty', 'S3', 'SRC'].map((c) => (
            <span key={c} className="rounded border border-slate-200 bg-slate-50 px-1.5 py-px text-[10px] text-slate-700">
              {c}
            </span>
          ))}
        </div>
        <div className="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1">
          {mark ? <Mark>{btn}</Mark> : btn}
          <span className="text-[11px] text-slate-500">
            3 zamienniki<b className="font-medium text-amber-800">, 2 do decyzji</b>
          </span>
        </div>
      </div>
    </div>
  )
}

function Group({ open, mark, focusUvex }: { open?: boolean; mark?: boolean; focusUvex?: boolean }) {
  return (
    <div className={`rounded-xl border bg-white p-3 shadow-sm ${open ? 'border-slate-400' : 'border-slate-200'}`}>
      <div className="flex gap-3">
        <MainCard open={open} mark={mark} />
        <div className="flex min-w-0 flex-1 flex-wrap content-start gap-2">
          <Chip
            maker="UVEX"
            name="Półbuty ochronne S7 SRC"
            price="214,50 zł"
            diff="+13,5%"
            status="Do decyzji"
            type="Premium"
            auto
            summary="3 z 4 parametrów równych, 1 wyższy"
            focused={focusUvex}
          />
          <Chip
            maker="PROTEKT"
            name="Półbuty ochronne S3 SRC"
            price="164,80 zł"
            diff="−12,8%"
            cheaper
            status="Do decyzji"
            type="Preferowany"
            auto
            summary="4 z 4 parametrów równych"
          />
          <Chip
            maker="ARTRA"
            name="Półbuty robocze S3"
            price="149,00 zł"
            diff="−21,2%"
            cheaper
            status="Zatwierdzony"
            type="Tańszy"
            auto={false}
            summary="wpisany ręcznie"
          />
        </div>
      </div>
    </div>
  )
}

const RELATION = {
  '=': { label: 'równe karcie głównej', cls: 'bg-emerald-50 text-emerald-700' },
  '↑': { label: 'wyższe niż karta główna', cls: 'bg-emerald-100 text-emerald-800' },
  '≥': { label: 'spełnia (inny zapis)', cls: 'bg-slate-100 text-slate-700' },
} as const

type Rel = keyof typeof RELATION

function RelMark({ rel }: { rel: Rel }) {
  return (
    <span className={`inline-flex h-4 w-4 shrink-0 items-center justify-center rounded text-[11px] font-bold ${RELATION[rel].cls}`}>
      {rel}
    </span>
  )
}

/** Wartość z etykietą pola karty; inferred — kursywa i „wniosek automatu”. */
function Val({ text, src, inferred }: { text?: string; src?: string; inferred?: boolean }) {
  if (!text) return <span className="text-slate-400">brak na karcie</span>
  return (
    <span className="block min-w-0">
      <span className={inferred ? 'italic text-slate-600' : 'font-medium text-slate-800'}>{text}</span>
      <span className="block text-[10px] leading-tight text-slate-400">
        {src}
        {inferred && <span className="text-amber-700"> · wniosek automatu</span>}
      </span>
    </span>
  )
}

function SubCell({ rel, children, focus }: { rel?: Rel; children: ReactNode; focus?: boolean }) {
  return (
    <td className={`border-b border-l border-slate-200 p-2 align-top ${focus ? 'bg-amber-50' : ''}`}>
      <div className="flex items-start gap-1.5">
        {rel && <RelMark rel={rel} />}
        {children}
      </div>
    </td>
  )
}

function ParamHead({ children }: { children: ReactNode }) {
  return <th className="border-b border-slate-200 bg-white p-2 align-top text-[11px] font-medium text-slate-600">{children}</th>
}

function MainCell({ children }: { children: ReactNode }) {
  return <td className="border-b border-l border-slate-200 bg-slate-50 p-2 align-top">{children}</td>
}

function ColHead({ maker, name, type, status, auto, summary, main, focus }: {
  maker: string
  name: string
  type?: SubType
  status?: Status
  auto?: boolean
  summary?: string
  main?: boolean
  focus?: boolean
}) {
  return (
    <th
      className={`border-b border-l border-slate-200 p-2 align-top font-normal ${main ? 'bg-slate-50' : ''} ${focus ? 'bg-amber-50' : ''}`}
    >
      {main ? (
        <p className="mb-1 text-[10px] font-semibold uppercase tracking-wide text-slate-500">Karta główna</p>
      ) : (
        <div className="mb-1 flex flex-wrap items-center gap-1">
          {type && <TypeTag type={type} />}
          {status && <StatusPill status={status} />}
          <Source auto={Boolean(auto)} />
        </div>
      )}
      <Maker name={maker} main={main} />
      <span className="mt-0.5 block text-xs font-medium text-slate-800">{name}</span>
      {summary && <p className="mt-1 text-[11px] text-slate-500">{summary}</p>}
    </th>
  )
}

function Legend() {
  return (
    <p className="mb-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-slate-500">
      {(Object.keys(RELATION) as Rel[]).map((r) => (
        <span key={r} className="inline-flex items-center gap-1">
          <RelMark rel={r} />
          {RELATION[r].label}
        </span>
      ))}
      <span>
        <i className="text-slate-600">kursywa</i> — wniosek automatu
      </span>
      <span>najedź na wartość, by zobaczyć cytat z karty</span>
    </p>
  )
}

/** Pole decyzji pod kolumną zamiennika czekającego na decyzję (DecisionBox). */
function DecisionPending({ note, markButtons, reason }: { note?: string; markButtons?: boolean; reason: string }) {
  const buttons = (
    <div className="flex gap-1.5">
      <span className="rounded-md bg-emerald-600 px-2.5 py-1 font-medium text-white">Zatwierdź</span>
      <span className="rounded-md border border-red-300 px-2.5 py-1 font-medium text-red-700">Odrzuć</span>
    </div>
  )
  return (
    <div className="space-y-1.5 text-[11px]">
      <p className="text-slate-600">{reason}</p>
      <div
        className={`w-full rounded-md border border-slate-300 bg-white px-2 py-1 ${note ? 'text-slate-800' : 'text-slate-400'}`}
      >
        {note || 'Notatka do decyzji (opcjonalnie)'}
      </div>
      {markButtons ? <Mark>{buttons}</Mark> : buttons}
      <div className="flex gap-3">
        <span className="text-slate-500 underline">Edytuj</span>
      </div>
    </div>
  )
}

export function SubstitutesHelp() {
  const { user } = useAuth()
  return (
    <Slideshow
      title="Zamienniki"
      slides={[
        {
          action: 'Wejście do Zamienników',
          does: 'Zamienniki to karty innych producentów, którymi można zastąpić kartę główną, bo spełniają każdy jej parametr ochronny. Automat sam wyszukuje takie pary i przy każdej wartości zapisuje, skąd ją wziął; decyzję zawsze podejmuje człowiek. Menu widzą osoby z uprawnieniem „Produkty — podgląd”; dodawanie i edycja wymagają „Zamienniki — edycja”, a zatwierdzanie i odrzucanie — „Akceptacja zamienników”.',
          click: 'Menu „Zamienniki”.',
          tone: 'slate',
          screen: (
            <LivePage
              nav="Zamienniki"
              path="/substitutes"
              page={<Substitutes />}
              allowed={can(user, 'products.view')}
              mark="text=+ Dodaj zamiennik ręcznie"
              fallback={
                <AppFrame nav="Zamienniki">
                  <div className="mb-3 flex flex-wrap items-end justify-between gap-3">
                    <div className="max-w-md">
                      <h1 className="text-xl font-semibold">Zamienniki</h1>
                      <p className="mt-1 text-xs text-slate-500">
                        Automat proponuje karty innych producentów, które spełniają każdy parametr ochronny karty głównej — z
                        cytatem źródła przy każdej wartości. Decyzję o zamienniku zawsze podejmuje człowiek.
                      </p>
                    </div>
                    <span className="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700">
                      + Dodaj zamiennik ręcznie
                    </span>
                  </div>
                  <div className="mb-3 grid grid-cols-3 gap-2 xl:grid-cols-6">
                    <Kpi value="412" label="Karty główne" hint="pokaż wszystko" />
                    <Kpi value="1 036" label="Zamienniki" hint="981 automat · 55 ręcznych" />
                    <Kpi value="874" label="Do decyzji" alert />
                    <Kpi value="139" label="Zatwierdzone" />
                    <Kpi value="23" label="Odrzucone" />
                    <Kpi value="4" label="Nieaktualne" hint="wśród zatwierdzonych" alert />
                  </div>
                  <Group />
                </AppFrame>
              }
            />
          ),
        },
        {
          action: 'Liczniki i filtry',
          does: 'Kliknięcie licznika „Do decyzji”, „Zatwierdzone” albo „Odrzucone” zostawia na liście tylko zamienniki o tym statusie (drugie kliknięcie wyłącza filtr); „Karty główne” czyści wszystkie filtry. „Nieaktualne” to zatwierdzone pary, których automat już nie potwierdza — mają na liście plakietkę „nieaktualne”. Pasek filtrów zawęża listę po nazwie, kodzie produktu lub producencie, grupie, producencie karty głównej, statusie, typie i źródle (automat albo ręczne).',
          click: 'Licznik „Do decyzji”, potem w pasku np. „Producent karty głównej”; „Wyczyść” wraca do pełnej listy.',
          tone: 'blue',
          screen: (
            <AppFrame nav="Zamienniki">
              <div className="mb-3 grid grid-cols-3 gap-2">
                <Kpi value="412" label="Karty główne" hint="pokaż wszystko" />
                <Kpi value="1 036" label="Zamienniki" hint="981 automat · 55 ręcznych" />
                <Kpi value="874" label="Do decyzji" alert active mark />
              </div>
              <div className="mb-3 flex flex-wrap items-center gap-2 rounded-xl border border-slate-200 bg-white p-2.5">
                <span className="min-w-[10rem] flex-1 rounded-md border border-slate-300 bg-white px-2 py-1.5 text-xs text-slate-400">
                  Szukaj SKU, nazwy lub producenta…
                </span>
                <Select value="Obuwie (128)" />
                <Select value="ARDON (37)" mark />
                <Select value="Do decyzji" />
                <Select value="Wszystkie typy" />
                <Select value="Automat i ręczne" />
                <span className="px-1 text-xs text-slate-500 underline">Wyczyść</span>
              </div>
              <p className="text-xs text-slate-500">31 kart głównych (z filtrem)</p>
            </AppFrame>
          ),
        },
        {
          action: 'Karta główna i jej zamienniki',
          does: 'Po lewej jest karta główna (ciemna plakietka producenta), po prawej rząd jej zamienników. Każdy zamiennik ma cenę i różnicę do karty głównej (zielona = taniej), status, typ i źródło. Automat nadaje typ „Preferowany” (wszystkie parametry na tym samym poziomie) albo „Premium” (co najmniej jeden parametr wyższy); „Tańszy” i „Awaryjny” wybiera się przy dodawaniu ręcznym. Pod spodem skrót porównania, np. „3 z 4 parametrów równych, 1 wyższy”.',
          click: 'Nic nie klikasz — czytasz rząd. Kolorowy pasek z lewej strony zamiennika: żółty = do decyzji, zielony = zatwierdzony.',
          tone: 'amber',
          screen: (
            <AppFrame nav="Zamienniki">
              <Group />
            </AppFrame>
          ),
        },
        {
          action: 'Porównanie parametrów',
          does: 'Tabela porównania ma kolumnę karty głównej i kolumnę każdego zamiennika, a wiersze to parametry ochronne. Znak przy wartości mówi, jak zamiennik wypada: „=” równe, „↑” wyższe, „≥” spełnia, ale zapisane inaczej. Kursywa z dopiskiem „wniosek automatu” to wartość wywnioskowana z tekstu karty, nie przepisana; szare „brak na karcie” znaczy, że karta tego nie podaje — to niewiadoma, nie zgodność. Najechanie na wartość pokazuje cytat z karty.',
          click: '„▾ Porównaj parametry” pod kartą główną albo kliknięcie zamiennika w rzędzie (jego kolumna jest wtedy podświetlona). „▴ Zwiń porównanie” chowa tabelę.',
          tone: 'blue',
          screen: (
            <AppFrame nav="Zamienniki">
              <div className="mb-3 rounded-xl border border-slate-400 bg-white p-3 shadow-sm">
                <MainCard open mark />
              </div>
              <Legend />
              <Card className="overflow-x-auto p-0">
                <table className="w-full border-separate border-spacing-0 text-left text-xs">
                  <thead>
                    <tr>
                      <th className="w-24 border-b border-slate-200 bg-white p-2 text-[11px] font-medium text-slate-500">Parametr</th>
                      <ColHead maker="ARDON" name="Półbuty ochronne S3 SRC" main />
                      <ColHead
                        maker="UVEX"
                        name="Półbuty ochronne S7 SRC"
                        type="Premium"
                        status="Do decyzji"
                        auto
                        summary="3 z 4 parametrów równych, 1 wyższy"
                        focus
                      />
                      <ColHead
                        maker="PROTEKT"
                        name="Półbuty ochronne S3 SRC"
                        type="Preferowany"
                        status="Do decyzji"
                        auto
                        summary="4 z 4 parametrów równych"
                      />
                    </tr>
                  </thead>
                  <tbody>
                    <tr>
                      <ParamHead>Rodzaj wyrobu</ParamHead>
                      <MainCell>
                        <Val text="półbuty" src="nazwa" />
                      </MainCell>
                      <SubCell rel="=" focus>
                        <Val text="półbuty" src="nazwa" />
                      </SubCell>
                      <SubCell rel="=">
                        <Val text="półbuty" src="opis" />
                      </SubCell>
                    </tr>
                    <tr>
                      <ParamHead>Klasa obuwia</ParamHead>
                      <MainCell>
                        <Val text="S3" src="normy" />
                      </MainCell>
                      <SubCell rel="↑" focus>
                        <Val text="S7" src="normy" />
                      </SubCell>
                      <SubCell rel="=">
                        <Val text="S3" src="specyfikacja" />
                      </SubCell>
                    </tr>
                    <tr>
                      <ParamHead>Oznaczenia</ParamHead>
                      <MainCell>
                        <Val text="SRC" src="odczyt automatu" />
                      </MainCell>
                      <SubCell rel="=" focus>
                        <Val text="SRC" src="odczyt automatu" />
                      </SubCell>
                      <SubCell rel="=">
                        <Val text="SRC" src="odczyt automatu" />
                      </SubCell>
                    </tr>
                    <tr>
                      <ParamHead>Normy</ParamHead>
                      <MainCell>
                        <Val text="EN ISO 20345" src="normy" />
                      </MainCell>
                      <SubCell rel="=" focus>
                        <Val text="EN ISO 20345" src="opis" inferred />
                      </SubCell>
                      <SubCell rel="=">
                        <Val text="EN ISO 20345" src="normy" />
                      </SubCell>
                    </tr>
                    <tr>
                      <ParamHead>Cena netto</ParamHead>
                      <MainCell>
                        <span className="text-sm font-semibold tabular-nums text-slate-800">189,00 zł</span>
                      </MainCell>
                      <SubCell focus>
                        <Price value="214,50 zł" diff="+13,5%" />
                      </SubCell>
                      <SubCell>
                        <Price value="164,80 zł" diff="−12,8%" cheaper />
                      </SubCell>
                    </tr>
                  </tbody>
                </table>
              </Card>
            </AppFrame>
          ),
        },
        {
          action: 'Czego automat nie sprawdził',
          does: 'Pod tabelą są dwie ramki. „Nie sprawdzono automatem” wymienia cechy, których automat nie porównuje (dla obuwia np. rozmiarówka i tęgość, materiał cholewki, kolor, waga) — te cechy handlowiec sprawdza sam przed zatwierdzeniem. „Dodatkowo u zamiennika” pokazuje, co zamiennik ma ponad kartę główną. Wiersz „Producent / SKU” w tabeli prowadzi do karty produktu i ostrzega „karta bez opisu”.',
          click: 'Nic nie klikasz — przeczytaj obie ramki przed decyzją.',
          tone: 'amber',
          screen: (
            <AppFrame nav="Zamienniki">
              <div className="grid gap-2 text-xs sm:grid-cols-2">
                <Mark>
                  <div className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
                    <p className="font-medium text-slate-700">Nie sprawdzono automatem</p>
                    <p className="mt-0.5 text-slate-600">rozmiarówka i tęgość, materiał cholewki, kolor, waga</p>
                    <p className="mt-1 text-[11px] text-slate-500">Te cechy handlowiec sprawdza sam przed zatwierdzeniem.</p>
                  </div>
                </Mark>
                <div className="rounded-lg border border-slate-200 bg-white px-3 py-2">
                  <p className="font-medium text-slate-700">Dodatkowo u zamiennika</p>
                  <ul className="mt-0.5 space-y-0.5 text-slate-600">
                    <li>
                      <span className="text-slate-500">UVEX 6840.2:</span> EN ISO 20347
                    </li>
                  </ul>
                </div>
              </div>
            </AppFrame>
          ),
        },
        {
          action: 'Zatwierdzenie albo odrzucenie',
          does: 'Ostatni wiersz tabeli, „Decyzja”, pokazuje uzasadnienie pary, a przy zamienniku czekającym na decyzję — pole notatki i dwa przyciski. Notatka zapisuje się razem z decyzją. Po decyzji w kolumnie widać status, kto zdecydował i notatkę, a „Cofnij decyzję” przywraca stan „Do decyzji”. Propozycja automatu trafia do przetargów dopiero po zatwierdzeniu.',
          click: 'Wpisz notatkę (nie trzeba), potem „Zatwierdź” albo „Odrzuć”.',
          tone: 'blue',
          screen: (
            <AppFrame nav="Zamienniki">
              <Card className="overflow-x-auto p-0">
                <table className="w-full border-separate border-spacing-0 text-left text-xs">
                  <thead>
                    <tr>
                      <th className="w-24 border-b border-slate-200 bg-white p-2 text-[11px] font-medium text-slate-500">Parametr</th>
                      <ColHead maker="UVEX" name="Półbuty ochronne S7 SRC" type="Premium" status="Do decyzji" auto />
                      <ColHead maker="ARTRA" name="Półbuty robocze S3" type="Tańszy" status="Zatwierdzony" />
                    </tr>
                  </thead>
                  <tbody>
                    <tr>
                      <th className="bg-white p-2 align-top text-[11px] font-medium text-slate-600">Decyzja</th>
                      <td className="border-l border-slate-200 p-2 align-top">
                        <DecisionPending
                          reason="Wszystkie parametry karty głównej spełnione, klasa obuwia wyższa."
                          note="Sprawdziłem rozmiarówkę 38–47"
                          markButtons
                        />
                      </td>
                      <td className="border-l border-slate-200 p-2 align-top">
                        <div className="space-y-1.5 text-[11px]">
                          <p className="text-slate-600">Tańsza para do przetargów z niskim budżetem.</p>
                          <p className="text-slate-500">
                            Zatwierdzony · Nowak
                            <span className="block text-slate-600">Notatka: tylko rozmiary 39–46</span>
                          </p>
                          <div className="flex gap-3">
                            <span className="text-slate-500 underline">Cofnij decyzję</span>
                            <span className="text-slate-500 underline">Edytuj</span>
                            <span className="text-red-700 underline">Usuń</span>
                          </div>
                        </div>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </Card>
            </AppFrame>
          ),
        },
        {
          action: 'Dodanie zamiennika ręcznie',
          does: 'Gdy znasz zamiennik, którego automat nie zaproponował, dodajesz go sam: wybierasz kartę główną i zamiennik (wpisz co najmniej 2 znaki kodu produktu albo nazwy), typ, ocenę zgodności w procentach, zaznaczasz zgodność norm i certyfikatów i piszesz uzasadnienie. Ręczny zamiennik nie ma porównania automatu — w tabeli ma dopisek „Wpisany ręcznie — automat nie porównał parametrów.”. Ręczne pary można zmienić („Edytuj”) albo usunąć („Usuń”); propozycji automatu nie da się usunąć, a po zmianie jej treści staje się ręczna i wraca do decyzji.',
          click: '„+ Dodaj zamiennik ręcznie” u góry strony, wypełnij formularz i kliknij „Dodaj”.',
          tone: 'blue',
          screen: (
            <AppFrame nav="Zamienniki">
              <div className="rounded-xl border border-slate-200 bg-white p-4 text-sm shadow-sm">
                <h2 className="mb-3 font-semibold">Nowy zamiennik (ręcznie)</h2>
                <div className="grid gap-3 text-xs sm:grid-cols-2">
                  <label className="block">
                    Karta główna *
                    <div className="mt-1 rounded border border-slate-300 px-2 py-1.5 text-slate-800">G3070 · Półbuty ochronne S3 SRC</div>
                  </label>
                  <label className="block">
                    Zamiennik *
                    <div className="mt-1 rounded border border-slate-300 px-2 py-1.5 text-slate-800">BPÓŁ41207 · Półbuty robocze S3</div>
                  </label>
                  <label className="block">
                    Typ *
                    <div className="mt-1 rounded border border-slate-300 px-2 py-1.5 text-slate-800">Tańszy ▾</div>
                  </label>
                  <label className="block">
                    Zgodność AI (%) *
                    <div className="mt-1 rounded border border-slate-300 px-2 py-1.5 text-slate-800">80</div>
                  </label>
                  <label className="flex items-center gap-2">
                    <input type="checkbox" readOnly checked />
                    Zgodność norm
                  </label>
                  <label className="flex items-center gap-2">
                    <input type="checkbox" readOnly checked />
                    Zgodność certyfikatów
                  </label>
                  <label className="block sm:col-span-2">
                    Uzasadnienie
                    <div className="mt-1 rounded border border-slate-300 px-2 py-1.5 text-slate-800">
                      Tańsza para do przetargów z niskim budżetem.
                    </div>
                  </label>
                </div>
                <div className="mt-3 flex gap-2">
                  <Mark>
                    <span className="rounded bg-blue-600 px-3 py-2 text-xs text-white">Dodaj</span>
                  </Mark>
                  <span className="rounded border border-slate-300 px-3 py-2 text-xs">Anuluj</span>
                </div>
              </div>
            </AppFrame>
          ),
        },
        {
          action: 'Tańsze zamienniki w przetargu',
          does: 'Do przetargu trafiają tylko pary, za które ręczy człowiek: ręczne, których nikt nie odrzucił, i propozycje automatu po zatwierdzeniu. W sprawie przetargu przycisk „Zastosuj tańsze zamienniki” szuka dla każdej pozycji zamiennika co najmniej 3% tańszego (po upuście) i najpierw pokazuje listę zmian; dopiero „Tak, zastosuj” zapisuje je w ofercie. Ręczne pary czekające na decyzję widać w przetargu jako „Zamienniki do zatwierdzenia”.',
          click: 'W sprawie przetargu sekcja „Pozycje” → „Zastosuj tańsze zamienniki” → sprawdź listę → „Tak, zastosuj” albo „Anuluj”.',
          tone: 'blue',
          screen: (
            <AppFrame nav="Przetargi">
              <div className="mb-3 flex flex-wrap justify-end gap-2">
                <span className="rounded bg-violet-600 px-3 py-2 text-xs font-semibold text-white">Dopasuj produkty do pustych pozycji</span>
                <Mark>
                  <span className="rounded bg-amber-500 px-3 py-2 text-xs font-semibold text-white">Zastosuj tańsze zamienniki</span>
                </Mark>
                <span className="rounded bg-emerald-600 px-3 py-2 text-xs font-semibold text-white">Zapisz całość</span>
              </div>
              <div className="rounded-lg border-2 border-amber-400 bg-amber-50 p-3 text-xs text-slate-800 shadow-sm">
                <p className="font-semibold text-amber-950">Zastosować tańsze zamienniki na 2 pozycjach?</p>
                <p className="mt-1 text-[11px] text-amber-900/80">
                  To tylko podgląd — potwierdź poniżej, żeby zapisać zmiany w ofercie.
                </p>
                <ul className="mt-2 space-y-1">
                  <li className="font-mono text-[11px]">Pozycja 4: G3070 → BPÓŁ41207 (taniej o 18% · cena zakupu 149,00 zł)</li>
                  <li className="font-mono text-[11px]">Pozycja 9: ARĘK92600 → ARĘK92710 (taniej o 6% · cena zakupu 6,41 zł)</li>
                </ul>
                <div className="mt-3 flex gap-2">
                  <span className="rounded border border-slate-300 bg-white px-3 py-1.5 text-[11px]">Anuluj</span>
                  <Mark>
                    <span className="rounded bg-amber-600 px-3 py-1.5 text-[11px] font-semibold text-white">Tak, zastosuj</span>
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
