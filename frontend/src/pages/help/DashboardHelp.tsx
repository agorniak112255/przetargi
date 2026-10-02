import type { ReactNode } from 'react'
import { AppFrame, Mark, Slideshow, Th } from './kit'

/** Samouczek „Dashboard” — karta na każdy moduł (pages/Dashboard.tsx, odpowiedź GET /dashboard). */

function DashHead() {
  return (
    <div className="mb-3 flex flex-wrap items-baseline gap-x-3 gap-y-1">
      <h1 className="text-xl font-semibold">Dashboard</h1>
      <span className="text-xs text-slate-500">piątek, 2 października 2026</span>
    </div>
  )
}

/** Karta modułu jak na dashboardzie: ikona, tytuł, odnośnik w prawym górnym rogu, dolna linia „do uwagi”. */
function DashCard({
  title,
  link,
  markLink,
  attention,
  className = '',
  children,
}: {
  title: string
  link: string
  markLink?: boolean
  attention?: ReactNode
  className?: string
  children?: ReactNode
}) {
  const linkEl = <span className="text-[11px] font-medium whitespace-nowrap text-blue-600">{link} →</span>
  return (
    <div className={`flex min-w-0 flex-col rounded-xl bg-white p-3 shadow-sm ${className}`}>
      <div className="mb-2 flex items-center gap-2">
        <span className="grid h-5 w-5 shrink-0 place-items-center rounded-md bg-blue-50 text-[10px] text-blue-600">■</span>
        <span className="text-xs font-semibold">{title}</span>
        <span className="ml-auto">{markLink ? <Mark>{linkEl}</Mark> : linkEl}</span>
      </div>
      <div className="flex-1">{children}</div>
      {attention}
    </div>
  )
}

function Attention({ tone, children }: { tone: 'alert' | 'warn' | 'info'; children: ReactNode }) {
  const color = tone === 'alert' ? 'text-red-600' : tone === 'warn' ? 'text-amber-600' : 'text-slate-400'
  return (
    <div className="mt-2 flex items-start gap-1.5 border-t border-dashed border-slate-200 pt-2 text-[11px] text-slate-700">
      <span className={`shrink-0 font-bold ${color}`}>{tone === 'info' ? '→' : '▲'}</span>
      <span>{children}</span>
    </div>
  )
}

function Stat({ label, value, unit }: { label: string; value: string; unit?: string }) {
  return (
    <div className="min-w-0">
      <div className="text-[10px] font-medium tracking-wide text-slate-400 uppercase">{label}</div>
      <div className="text-lg leading-tight font-semibold">
        {value}
        {unit && <small className="ml-1 text-[11px] font-medium text-slate-500">{unit}</small>}
      </div>
    </div>
  )
}

function SubLabel({ children }: { children: ReactNode }) {
  return <div className="mb-1.5 text-[10px] font-medium tracking-wide text-slate-400 uppercase">{children}</div>
}

function Bar({ percent, fill }: { percent: number; fill: string }) {
  return (
    <div className="h-1.5 overflow-hidden rounded bg-slate-100">
      <div className={`h-full rounded ${fill}`} style={{ width: `${percent}%` }} />
    </div>
  )
}

const STAGES: [string, number, string, string][] = [
  ['Szkic', 2, '—', 'bg-slate-300 text-slate-700'],
  ['Wycena', 4, '412 tys. zł', 'bg-blue-500 text-white'],
  ['Akceptacja kierownika', 1, '96 tys. zł', 'bg-violet-400 text-white'],
  ['Zatwierdzona', 2, '214 tys. zł', 'bg-emerald-600 text-white'],
]

const UPCOMING: [string, string, string, string][] = [
  ['05.10', 'Mittal', 'za 3 dni', 'bg-red-50 text-red-700'],
  ['08.10', 'Sanitex', 'za 6 dni', 'bg-amber-50 text-amber-700'],
  ['20.10', 'Mittal', 'za 18 dni', 'bg-slate-100 text-slate-600'],
]

function TendersCard({ markShowAll }: { markShowAll?: boolean }) {
  const showAll = <span className="text-blue-600">Pokaż wszystkie</span>
  return (
    <DashCard
      title="Przetargi"
      link="Wszystkie przetargi"
      attention={
        <Attention tone="alert">
          <b>2 terminy w ciągu 7 dni.</b> Najbliższy 05.10: Mittal, status: wycena.{' '}
          {markShowAll ? <Mark>{showAll}</Mark> : showAll}
        </Attention>
      }
    >
      <div className="grid grid-cols-3 gap-2">
        <Stat label="W toku" value="9" />
        <Stat label="Wartość ofert netto" value="722 600" unit="zł" />
        <Stat label="Średnia marża" value="16,4" unit="%" />
      </div>
      <div className="mt-3 grid gap-3 sm:grid-cols-2">
        <div>
          <SubLabel>Etapy i wartość ofert</SubLabel>
          <div className="grid gap-1.5">
            {STAGES.map(([label, count, value, fill]) => (
              <div key={label} className="grid grid-cols-[72px_minmax(0,1fr)_62px] items-center gap-1.5 text-[11px]">
                <span className="truncate">{label}</span>
                <span className="h-4 overflow-hidden rounded bg-slate-100">
                  <span className={`flex h-full items-center rounded pl-1 text-[10px] font-semibold ${fill}`} style={{ width: `${(count / 4) * 100}%` }}>
                    {count}
                  </span>
                </span>
                <span className="text-right text-slate-500">{value}</span>
              </div>
            ))}
          </div>
        </div>
        <div>
          <SubLabel>Najbliższe terminy</SubLabel>
          <div className="grid gap-1">
            {UPCOMING.map(([date, client, when, pill]) => (
              <div key={date} className="grid grid-cols-[34px_minmax(0,1fr)_auto] items-center gap-1.5 rounded-lg bg-slate-50 px-2 py-1 text-[11px]">
                <span className={`font-semibold ${when === 'za 3 dni' ? 'text-red-600' : ''}`}>{date}</span>
                <span className="truncate">{client}</span>
                <span className={`rounded-full px-1.5 py-0.5 text-[10px] font-semibold whitespace-nowrap ${pill}`}>{when}</span>
              </div>
            ))}
          </div>
        </div>
      </div>
    </DashCard>
  )
}

export function DashboardHelp() {
  return (
    <Slideshow
      title="Dashboard"
      slides={[
        {
          action: 'Co widać po zalogowaniu',
          does: 'Dashboard to pierwszy ekran: po jednej karcie na moduł — Przetargi, Produkty, Zapasy, Cenniki i Kampanie. Każda osoba widzi tylko karty modułów, do których ma uprawnienie; gdy jednej karty z pary brakuje, druga zajmuje całą szerokość.',
          click: 'Nic — przeczytaj karty. Odnośnik w prawym górnym rogu karty prowadzi do modułu.',
          tone: 'slate',
          screen: (
            <AppFrame nav="Dashboard">
              <DashHead />
              <div className="grid gap-2 sm:grid-cols-6">
                <DashCard title="Przetargi" link="Wszystkie przetargi" className="sm:col-span-4">
                  <div className="grid grid-cols-3 gap-2">
                    <Stat label="W toku" value="9" />
                    <Stat label="Wartość ofert netto" value="722 600" unit="zł" />
                    <Stat label="Średnia marża" value="16,4" unit="%" />
                  </div>
                </DashCard>
                <DashCard title="Produkty" link="Katalog" className="sm:col-span-2">
                  <Stat label="Karty w katalogu" value="18 412" />
                </DashCard>
                <DashCard title="Zapasy" link="Zapasy" className="sm:col-span-3">
                  <Stat label="Wartość magazynu" value="1,85 mln" unit="zł" />
                </DashCard>
                <DashCard title="Cenniki" link="Konta B2B" className="sm:col-span-3">
                  <Stat label="Konta B2B bez błędów" value="21" unit="z 23" />
                </DashCard>
                <DashCard title="Kampanie" link="Kampanie" className="sm:col-span-6">
                  <span className="text-[11px] text-slate-600">Najbliższa wysyłka, szkice, wysłane maile i odpowiedzi klientów.</span>
                </DashCard>
              </div>
            </AppFrame>
          ),
        },
        {
          action: 'Karta Przetargi i ostrzeżenie o terminach',
          does: 'Na górze: ile przetargów jest w toku, wartość ofert netto i średnia marża. Pod spodem pasek etapów (ile przetargów i za ile na każdym etapie) oraz najbliższe terminy — czerwone do 3 dni, pomarańczowe do 7 dni. Dolna linia ostrzega, ile terminów wypada w ciągu 7 dni, i podaje najbliższy.',
          click: '„Pokaż wszystkie” w czerwonej linii ostrzeżenia. Wiersz z terminem otwiera od razu ten przetarg.',
          tone: 'blue',
          screen: (
            <AppFrame nav="Dashboard">
              <DashHead />
              <TendersCard markShowAll />
            </AppFrame>
          ),
        },
        {
          action: 'Lista przetargów z pilnym terminem',
          does: '„Pokaż wszystkie” otwiera listę przetargów już przefiltrowaną: „Termin za mniej niż 7 dni”. Czerwony wykrzyknik przy dacie oznacza termin składania za mniej niż 7 dni. Gdy żaden przetarg nie ma takiego terminu, karta na dashboardzie pisze to w szarej linii zamiast ostrzeżenia.',
          click: 'Niebieski numer przetargu, żeby go otworzyć. Ten sam filtr wybierzesz ręcznie z listy rozwijanej na stronie Przetargi.',
          tone: 'green',
          screen: (
            <AppFrame nav="Przetargi">
              <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h1 className="text-xl font-semibold">Przetargi</h1>
                <Mark>
                  <span className="inline-block rounded border border-slate-300 bg-white px-2 py-1.5 text-xs">Termin za mniej niż 7 dni ▾</span>
                </Mark>
              </div>
              <div className="rounded-xl bg-white p-3 shadow-sm">
                <table className="w-full text-left text-xs">
                  <thead>
                    <tr className="border-b bg-slate-50">
                      <Th>Numer</Th>
                      <Th>Zamawiający</Th>
                      <Th>Termin składania</Th>
                      <Th>Wartość oferty netto</Th>
                      <Th>Status</Th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr className="border-b">
                      <td className="p-2 font-medium text-blue-600">PRZ/2026/0012</td>
                      <td className="p-2">Mittal</td>
                      <td className="p-2">
                        2026-10-05<span className="ml-1 font-semibold text-red-600">!</span>
                      </td>
                      <td className="p-2">48 210,40 zł</td>
                      <td className="p-2">Wycena</td>
                    </tr>
                    <tr>
                      <td className="p-2 font-medium text-blue-600">PRZ/2026/0011</td>
                      <td className="p-2">Sanitex</td>
                      <td className="p-2">
                        2026-10-08<span className="ml-1 font-semibold text-red-600">!</span>
                      </td>
                      <td className="p-2">—</td>
                      <td className="p-2">Szkic</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </AppFrame>
          ),
        },
        {
          action: 'Karta Produkty',
          does: 'Ile kart jest w katalogu, ile ma opis, a ilu brakuje opisu albo zdjęcia i ile czeka na ręczny opis. Karta bez opisu nie trafia do propozycji przetargowych — dlatego dolna linia ostrzega o kartach bez opisu. Na dole liczba zamienników czekających na akceptację.',
          click: '„Katalog →” otwiera listę produktów, „Zamienniki do akceptacji” — stronę Zamienniki.',
          tone: 'amber',
          screen: (
            <AppFrame nav="Dashboard">
              <DashHead />
              <DashCard
                title="Produkty"
                link="Katalog"
                className="max-w-sm"
                attention={
                  <Attention tone="warn">
                    <b>412</b> kart bez opisu. Takie karty nie trafiają do propozycji przetargowych.
                  </Attention>
                }
              >
                <Stat label="Karty w katalogu" value="18 412" />
                <div className="mt-2 grid gap-1.5">
                  {(
                    [
                      ['Z opisem (97%)', '18 000', 97, 'bg-blue-600'],
                      ['Bez opisu', '412', 2, 'bg-amber-500'],
                      ['Bez zdjęcia', '530', 3, 'bg-amber-500'],
                      ['Do ręcznego opisu', '37', 1, 'bg-red-500'],
                    ] as const
                  ).map(([label, value, pct, fill]) => (
                    <div key={label} className="grid grid-cols-[minmax(0,1fr)_auto] gap-x-2 gap-y-0.5 text-[11px]">
                      <span>{label}</span>
                      <b className="text-right">{value}</b>
                      <div className="col-span-2">
                        <Bar percent={pct} fill={fill} />
                      </div>
                    </div>
                  ))}
                </div>
                <Mark>
                  <span className="mt-2 flex w-56 justify-between text-[11px]">
                    <span className="text-slate-600">Zamienniki do akceptacji</span>
                    <b>6</b>
                  </span>
                </Mark>
              </DashCard>
            </AppFrame>
          ),
        },
        {
          action: 'Karty Zapasy i Cenniki',
          does: 'Zapasy: wartość magazynu, liczba towarów i ile nigdy się nie sprzedało; wykres pokazuje wartość towaru bez sprzedaży od pół roku, roku, 2 lat i nigdy, obok towary z największą zamrożoną gotówką. Stan pochodzi z nocnego odczytu systemu ERP XL. Cenniki: ile kont B2B (sklepów dostawców) przeszło ostatnie sprawdzenie bez błędów, ile cen zmieniło się w 7 dni i ostatnie cenniki z pliku; konto z błędem świeci na czerwono.',
          click: '„Zapasy →” otwiera Zapasy (bez dostępu do Zapasów — Raport dla zarządu). „Konta B2B →” otwiera konta dostawców (bez dostępu do kont — „Cenniki →”).',
          tone: 'slate',
          screen: (
            <AppFrame nav="Dashboard">
              <DashHead />
              <div className="grid gap-2 lg:grid-cols-2">
                <DashCard
                  title="Zapasy"
                  link="Zapasy"
                  attention={
                    <Attention tone="warn">
                      <b>286 tys. zł</b> w towarze bez sprzedaży ponad rok (214 pozycji).
                    </Attention>
                  }
                >
                  <div className="flex flex-wrap gap-x-4">
                    <Stat label="Wartość magazynu" value="1,85 mln" unit="zł" />
                    <Stat label="Towary" value="3 920" />
                  </div>
                  <div className="mt-2">
                    <SubLabel>Towar bez sprzedaży od…, tys. zł</SubLabel>
                  </div>
                  <svg viewBox="0 0 240 90" className="block w-full" role="img" aria-label="Przykładowy wykres">
                    <line x1="0" x2="240" y1="72" y2="72" className="stroke-slate-200" strokeWidth="1" />
                    {(
                      [
                        [10, 40, 'fill-blue-600', 'ponad pół roku'],
                        [70, 30, 'fill-amber-500', 'ponad rok'],
                        [130, 18, 'fill-amber-500', 'ponad 2 lata'],
                        [190, 12, 'fill-amber-500', 'nigdy'],
                      ] as const
                    ).map(([x, h, fill, label]) => (
                      <g key={label}>
                        <rect x={x + 8} y={72 - h} width="28" height={h} rx="2" className={fill} />
                        <text x={x + 22} y="86" textAnchor="middle" className="fill-current text-[8px] text-slate-500">
                          {label}
                        </text>
                      </g>
                    ))}
                  </svg>
                </DashCard>
                <DashCard
                  title="Cenniki"
                  link="Konta B2B"
                  attention={
                    <Attention tone="alert">
                      <b>1 konto z błędem:</b> Procera (nie udało się zalogować)
                    </Attention>
                  }
                >
                  <div className="flex flex-wrap gap-x-4">
                    <Stat label="Konta B2B bez błędów" value="21" unit="z 23" />
                    <Stat label="Zmiany cen, 7 dni" value="148" />
                  </div>
                  <div className="mt-2 grid grid-cols-3 gap-1">
                    {(
                      [
                        ['UVEX', 'bg-emerald-600', 'bg-slate-100'],
                        ['Procera', 'bg-red-600', 'bg-red-50 text-red-800'],
                        ['Mascot', 'bg-blue-500', 'bg-slate-100'],
                        ['Delta Plus', 'bg-emerald-600', 'bg-slate-100'],
                        ['Portwest', 'bg-emerald-600', 'bg-slate-100'],
                        ['3M', 'bg-slate-300', 'bg-slate-100 text-slate-500'],
                      ] as const
                    ).map(([label, dot, box]) => (
                      <span key={label} className={`flex items-center gap-1 rounded-md px-1.5 py-0.5 text-[10px] ${box}`}>
                        <i className={`h-1.5 w-1.5 shrink-0 rounded-full ${dot}`} />
                        <span className="truncate">{label}</span>
                      </span>
                    ))}
                  </div>
                  <div className="mt-1.5 flex flex-wrap gap-x-2 text-[10px] text-slate-500">
                    {(
                      [
                        ['bez błędów', 'bg-emerald-600'],
                        ['błąd', 'bg-red-600'],
                        ['trwa', 'bg-blue-500'],
                        ['wyłączone', 'bg-slate-300'],
                      ] as const
                    ).map(([label, dot]) => (
                      <span key={label} className="inline-flex items-center gap-1">
                        <i className={`h-1.5 w-1.5 rounded-sm ${dot}`} />
                        {label}
                      </span>
                    ))}
                  </div>
                  <p className="mt-1.5 flex justify-between text-[11px]">
                    <span className="text-slate-600">Cennik z pliku: Ansell</span>
                    <b>29.09 · 1 240 wierszy</b>
                  </p>
                </DashCard>
              </div>
            </AppFrame>
          ),
        },
        {
          action: 'Karta Kampanie',
          does: 'Najbliższa zaplanowana wysyłka, liczba szkiców do wysłania oraz wysłane maile i odpowiedzi klientów z 30 dni. Tabela „Ostatnie kampanie” pokazuje dla każdej kampanii: kiedy wysłana, ile maili poszło, ile było kliknięć i odpowiedzi oraz o ile zszedł zapas promowanego towaru.',
          click: 'Nazwa kampanii (w ramce wysyłki albo w tabeli) otwiera tę kampanię; „Kampanie →” — cały moduł.',
          tone: 'blue',
          screen: (
            <AppFrame nav="Dashboard">
              <DashHead />
              <DashCard title="Kampanie" link="Kampanie">
                <div className="grid gap-3 sm:grid-cols-[150px_minmax(0,1fr)]">
                  <div>
                    <div className="rounded-lg bg-blue-50 px-2.5 py-2">
                      <div className="text-[10px] font-medium tracking-wide text-slate-500 uppercase">Najbliższa wysyłka</div>
                      <Mark>
                        <b className="block text-xs">Rękawice zimowe</b>
                      </Mark>
                      <span className="block text-[11px] text-slate-600">05.10, 09:00</span>
                    </div>
                    <dl className="mt-2 grid grid-cols-[minmax(0,1fr)_auto] gap-x-2 gap-y-0.5 text-[11px]">
                      <dt className="text-slate-600">Szkice do wysłania</dt>
                      <dd className="text-right font-semibold">2</dd>
                      <dt className="text-slate-600">Wysłane maile, 30 dni</dt>
                      <dd className="text-right font-semibold">1 340</dd>
                      <dt className="text-slate-600">Odpowiedzi klientów, 30 dni</dt>
                      <dd className="text-right font-semibold">17</dd>
                    </dl>
                  </div>
                  <table className="w-full text-left text-[11px]">
                    <thead>
                      <tr className="border-b">
                        <Th>Ostatnie kampanie</Th>
                        <Th>Wysłana</Th>
                        <Th>Kliknięcia</Th>
                        <Th>Odpowiedzi</Th>
                        <Th>Zapas zszedł</Th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr className="border-b">
                        <td className="p-2 font-semibold">Obuwie S3 jesień</td>
                        <td className="p-2">22.09</td>
                        <td className="p-2">84 (9,6%)</td>
                        <td className="p-2">11</td>
                        <td className="p-2 font-semibold">−24%</td>
                      </tr>
                      <tr>
                        <td className="p-2 font-semibold">Kurtki softshell</td>
                        <td className="p-2">10.09</td>
                        <td className="p-2">41 (6,2%)</td>
                        <td className="p-2">6</td>
                        <td className="p-2 text-slate-400">—</td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </DashCard>
            </AppFrame>
          ),
        },
      ]}
    />
  )
}
