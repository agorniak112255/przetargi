import type { ReactNode } from 'react'
import { AppFrame, LivePage, Mark, Slideshow } from './kit'
import { useAuth } from '../../auth'
import { CardMatches } from '../CardMatches'
import { can } from '../../lib/api'

/** Samouczek „Łączenie kart” — pary karta dystrybutora → karta producenta (pages/CardMatches.tsx). */

const TABS: [string, number][] = [
  ['Do decyzji', 24],
  ['Łączenie rozmiarów', 5],
  ['Rozdzielanie', 3],
  ['Niepewne', 7],
  ['Odrzucone', 12],
  ['Zrobione', 140],
]

function Head({ markRefresh }: { markRefresh?: boolean }) {
  const refresh = <span className="inline-block rounded border border-slate-300 bg-white px-3 py-1.5 text-xs">Odśwież propozycje</span>
  return (
    <div className="mb-2 flex flex-wrap items-end justify-between gap-2">
      <div className="min-w-0">
        <h1 className="text-xl font-semibold">Łączenie kart</h1>
        <p className="mt-1 max-w-xl text-[11px] text-slate-600">
          Pary „karta dystrybutora → karta producenta” znalezione po tym samym EAN-ie albo kodzie producenta tej samej marki — nigdy po podobnej nazwie.
        </p>
      </div>
      <div className="flex flex-col items-end gap-1">
        {markRefresh ? <Mark>{refresh}</Mark> : refresh}
        <span className="text-[10px] text-slate-500">Ostatnio przeliczone: 02.10.2026 06:15</span>
      </div>
    </div>
  )
}

function Tabs({ active, mark }: { active: string; mark?: string[] }) {
  return (
    <div className="mb-2 flex flex-wrap gap-1 border-b border-slate-200 text-xs">
      {TABS.map(([label, n]) => {
        const el = (
          <span className={`-mb-px inline-block border-b-2 px-2 py-1.5 ${label === active ? 'border-blue-600 font-semibold text-blue-700' : 'border-transparent text-slate-600'}`}>
            {label} <span className="text-slate-500">({n})</span>
          </span>
        )
        return <span key={label}>{mark?.includes(label) ? <Mark>{el}</Mark> : el}</span>
      })}
    </div>
  )
}

/** Jedna strona pary jak CardSide: miniatura, kod karty (odnośnik), nazwa, producent, cena karty i ceny źródeł. */
function Side({ code, name, maker, described, price, sources, markCode }: {
  code: string
  name: string
  maker: string
  described: boolean
  price: string
  sources: [string, string][]
  markCode?: boolean
}) {
  const codeEl = <span className="font-mono text-[11px] text-blue-600">{code}</span>
  return (
    <div className="flex min-w-0 gap-2">
      <span className="flex h-12 w-12 shrink-0 items-center justify-center rounded border border-slate-200 bg-white text-[9px] text-slate-400">zdjęcie</span>
      <div className="min-w-0 text-[11px]">
        {markCode ? <Mark>{codeEl}</Mark> : codeEl}
        <p className="text-xs text-slate-800">{name}</p>
        <p className="text-slate-500">
          {maker} · {described ? <span className="text-emerald-700">z opisem</span> : <span className="text-slate-400">bez opisu</span>}
        </p>
        <p className="text-slate-600">
          Cena karty: <b>{price}</b>
        </p>
        {sources.map(([label, p]) => (
          <p key={label} className="text-slate-600">
            {label}: {p}
          </p>
        ))}
      </div>
    </div>
  )
}

const DISTRIBUTOR = (markCode?: boolean) => (
  <Side code="ARĘK92600" name="Okulary ochronne UVEX pheos, bezbarwne" maker="UVEX" described={false} price="38,90 zł" sources={[['ARTRA', '38,90 zł']]} markCode={markCode} />
)

const PRODUCER = (
  <Side code="9192225" name="UVEX pheos 9192.225 okulary ochronne" maker="UVEX" described price="41,20 zł" sources={[['UVEX', '41,20 zł']]} />
)

/** Środkowa kolumna „Dlaczego to ten sam wyrób”. */
function Why() {
  return (
    <div className="text-[11px]">
      <p className="text-slate-600">ten sam EAN</p>
      <p className="font-mono text-xs font-semibold text-slate-900">4031101552236</p>
      <p className="mt-0.5 text-slate-500">marka UVEX · z ARTRA</p>
      <p className="text-slate-500">trafione pozycje: 1 z 1</p>
      <div className="mt-1 rounded bg-slate-50 px-1.5 py-1 text-slate-600">
        <p className="font-medium text-slate-700">Po połączeniu — od najtańszej:</p>
        <p className="font-medium text-emerald-800">ARTRA: 38,90 zł · najtaniej</p>
        <p>UVEX: 41,20 zł</p>
      </div>
    </div>
  )
}

function PairTable({ select, actions }: { select?: ReactNode; actions: ReactNode }) {
  return (
    <div className="rounded-xl bg-white p-3 shadow-sm">
      {select}
      <table className="w-full text-left text-xs">
        <thead>
          <tr className="border-b bg-slate-50">
            {select && <th className="w-6 p-2" />}
            <th className="p-2 font-semibold text-slate-700">Karta dystrybutora</th>
            <th className="p-2 font-semibold text-slate-700">Dlaczego to ten sam wyrób</th>
            <th className="p-2 font-semibold text-slate-700">Karta producenta — zostaje</th>
            <th className="p-2 font-semibold text-slate-700">Akcja</th>
          </tr>
        </thead>
        <tbody>
          <tr className="align-top">
            {select && (
              <td className="p-2">
                <input type="checkbox" checked readOnly />
              </td>
            )}
            <td className="p-2">{DISTRIBUTOR()}</td>
            <td className="p-2">
              <Why />
            </td>
            <td className="p-2">{PRODUCER}</td>
            <td className="p-2">{actions}</td>
          </tr>
        </tbody>
      </table>
    </div>
  )
}

/** Okno potwierdzenia przeglądarki (window.confirm) z tekstem z kodu strony. */
function ConfirmBox({ children }: { children: ReactNode }) {
  return (
    <div className="mt-2 max-w-lg rounded-lg border border-slate-300 bg-white p-3 text-[11px] text-slate-800 shadow-md">
      {children}
      <div className="mt-2 flex justify-end gap-2">
        <Mark>
          <span className="rounded bg-blue-600 px-3 py-1 text-white">OK</span>
        </Mark>
        <span className="rounded border border-slate-300 px-3 py-1">Anuluj</span>
      </div>
    </div>
  )
}

const btn = 'inline-block rounded px-2.5 py-1 text-[11px]'

export function CardMatchesHelp() {
  const { user } = useAuth()
  return (
    <Slideshow
      title="Łączenie kart"
      slides={[
        {
          action: 'Co to jest Łączenie kart',
          does: 'Ten sam wyrób bywa w katalogu dwa razy: na karcie dystrybutora (z jego cennika albo sklepu B2B) i na karcie producenta. System szuka takich par po tym samym EAN-ie (numerze z kodu kreskowego) albo kodzie producenta tej samej marki — nigdy po podobnej nazwie. Po połączeniu zostaje karta producenta z jej nazwą, opisem i zdjęciem, a ceny dystrybutora dochodzą do jej „Ceny ze źródeł”. Ekran widzi osoba z uprawnieniem do łączenia kart; decyzje podejmuje tylko osoba z prawem decyzji — inni widzą „czeka na decyzję”.',
          click: 'Menu „Łączenie kart”. „Odśwież propozycje” przelicza propozycje z identyfikatorów zapisanych na kartach — niczego nie łączy.',
          tone: 'slate',
          screen: (
            <AppFrame nav="Łączenie kart">
              <Head markRefresh />
              <Tabs active="Do decyzji" />
              <p className="text-[11px] text-slate-500">
                Zakładki: trzy rodzaje propozycji do decyzji, potem Niepewne, Odrzucone i Zrobione. Liczba w nawiasie to liczba propozycji.
              </p>
            </AppFrame>
          ),
        },
        {
          action: 'Przegląd pary',
          does: 'W zakładce „Do decyzji” każdy wiersz to para. Po lewej karta dystrybutora, po prawej karta producenta, która zostanie. W środku dowód: ten sam EAN albo ten sam kod producenta, marka, skąd pochodzi klucz i ile pozycji karty dystrybutora trafiło w tę kartę. „Po połączeniu — od najtańszej” pokazuje, jak ułożą się ceny wszystkich dostawców.',
          click: 'Niebieski kod karty otwiera kartę w nowej karcie przeglądarki — porównaj nazwy, zdjęcia i ceny, zanim zdecydujesz.',
          tone: 'amber',
          screen: (
            <LivePage
              nav="Łączenie kart"
              path="/card-matches"
              page={<CardMatches />}
              allowed={can(user, 'card_matches.view')}
              fallback={
              <AppFrame nav="Łączenie kart">
                <Tabs active="Do decyzji" />
                <div className="rounded-xl bg-white p-3 shadow-sm">
                  <table className="w-full text-left text-xs">
                    <thead>
                      <tr className="border-b bg-slate-50">
                        <th className="p-2 font-semibold text-slate-700">Karta dystrybutora</th>
                        <th className="p-2 font-semibold text-slate-700">Dlaczego to ten sam wyrób</th>
                        <th className="p-2 font-semibold text-slate-700">Karta producenta — zostaje</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr className="align-top">
                        <td className="p-2">{DISTRIBUTOR(true)}</td>
                        <td className="p-2">
                          <Why />
                        </td>
                        <td className="p-2">{PRODUCER}</td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </AppFrame>
              }
            />
          ),
        },
        {
          action: 'Połączenie albo odrzucenie pary',
          does: '„Połącz” pyta o potwierdzenie: zostaje karta producenta (nazwa, opis, zdjęcie główne), ceny i powiązania dystrybutora przechodzą do jej „Ceny ze źródeł”, a karta dystrybutora znika. Przed połączeniem zapisuje się kopia zapasowa. „Odrzuć” prosi o powód (niewymagany, na przykład „inny kolor”) — odrzucona para nie wróci przy kolejnym odświeżeniu.',
          click: '„Połącz” przy wierszu, potem „OK” w oknie potwierdzenia. Gdy to nie ten sam wyrób — „Odrzuć”.',
          tone: 'blue',
          screen: (
            <AppFrame nav="Łączenie kart">
              <PairTable
                actions={
                  <div className="flex flex-wrap gap-1">
                    <Mark>
                      <span className={`${btn} bg-blue-600 text-white`}>Połącz</span>
                    </Mark>
                    <span className={`${btn} border border-slate-300`}>Odrzuć</span>
                  </div>
                }
              />
              <ConfirmBox>
                <p>Połączyć kartę dystrybutora ARĘK92600 z kartą producenta 9192225?</p>
                <p className="mt-1">
                  Zostaje karta producenta 9192225 (nazwa, opis, zdjęcie główne). Ceny i powiązania dystrybutora przejdą do jej „Ceny ze źródeł”, a karta ARĘK92600
                  zniknie. Przed połączeniem zapisuje się kopia zapasowa.
                </p>
              </ConfirmBox>
            </AppFrame>
          ),
        },
        {
          action: 'Kilka par naraz',
          does: 'W „Do decyzji” możesz zaznaczyć wiele par i połączyć albo odrzucić je jednym kliknięciem. Każda para jest sprawdzana jeszcze raz tuż przed połączeniem. Po akcji zielony pasek mówi, ile się udało; pary, których nie udało się połączyć, zostają zaznaczone, a powód widać przy wierszu.',
          click: '„Zaznacz widoczne” albo pola przy wierszach (Shift+klik zaznacza zakres), potem „Połącz zaznaczone” albo „Odrzuć zaznaczone”.',
          tone: 'blue',
          screen: (
            <AppFrame nav="Łączenie kart">
              <PairTable
                select={
                  <div className="flex flex-wrap items-center gap-2 pb-2 text-xs">
                    <span className="flex items-center gap-1.5 text-slate-600">
                      <input type="checkbox" checked readOnly />
                      Zaznacz widoczne (24) · zaznaczono 24
                    </span>
                    <Mark>
                      <span className={`${btn} bg-blue-600 text-white`}>Połącz zaznaczone (24)</span>
                    </Mark>
                    <span className={`${btn} border border-slate-300`}>Odrzuć zaznaczone (24)</span>
                    <span className="text-slate-500">24 pary</span>
                  </div>
                }
                actions={
                  <div className="flex flex-wrap gap-1">
                    <span className={`${btn} bg-blue-600 text-white`}>Połącz</span>
                    <span className={`${btn} border border-slate-300`}>Odrzuć</span>
                  </div>
                }
              />
            </AppFrame>
          ),
        },
        {
          action: 'Łączenie rozmiarów',
          does: 'Dystrybutor ma jeden wyrób we wszystkich rozmiarach, a producent osobną kartę na każdy rozmiar w tej samej cenie. Po połączeniu zostaje jedna karta producenta z listą rozmiarów, a cena dystrybutora jest obok. W panelu sprawdzasz trzy rzeczy: która karta zostaje, nazwę karty modelu i listę rozmiarów, a potem potwierdzasz, że pozycje różnią się tylko rozmiarem.',
          click: 'Zakładka „Łączenie rozmiarów”, przy wierszu „Połącz rozmiary…”. Zaznacz punkt 4 i kliknij „Połącz rozmiary” — przycisk działa dopiero po zaznaczeniu.',
          tone: 'blue',
          screen: (
            <AppFrame nav="Łączenie kart">
              <Tabs active="Łączenie rozmiarów" />
              <div className="rounded-lg border border-blue-200 bg-blue-50 p-3 text-[11px]">
                <p className="text-sm font-semibold text-slate-900">Połącz rozmiary w jedną kartę Delta Plus</p>
                <p className="text-slate-600">
                  Sprawdź trzy rzeczy: która karta zostaje, jaka będzie nazwa i jakie rozmiary. Nic się nie zmieni, dopóki nie potwierdzisz.
                </p>
                <p className="mt-2 font-medium text-slate-800">1. Zostaje karta</p>
                <div className="mt-1 grid grid-cols-3 gap-1.5">
                  {(
                    [
                      ['rozmiar 8', 'zostaje'],
                      ['rozmiar 9', 'zniknie — przejdzie do wybranej'],
                      ['rozmiar 10', 'zniknie — przejdzie do wybranej'],
                    ] as const
                  ).map(([size, state]) => (
                    <span
                      key={size}
                      className={`rounded border bg-white p-1.5 ${state === 'zostaje' ? 'border-blue-500 ring-1 ring-blue-500' : 'border-slate-200'}`}
                    >
                      <span className="rounded bg-sky-100 px-1 text-sky-800">{size}</span>{' '}
                      <span className={state === 'zostaje' ? 'font-medium text-blue-700' : 'text-slate-500'}>{state}</span>
                    </span>
                  ))}
                </div>
                <p className="mt-2 font-medium text-slate-800">2. Nazwa karty modelu</p>
                <span className="mt-1 block rounded border border-slate-300 bg-white px-2 py-1">Rękawice Delta Plus VV735</span>
                <p className="mt-2 font-medium text-slate-800">3. Lista rozmiarów na karcie modelu</p>
                <span className="mt-1 block rounded border border-slate-200 bg-white px-2 py-1">8, 9, 10</span>
                <div className="mt-2 flex flex-wrap items-center gap-2">
                  <Mark>
                    <span className="flex items-center gap-1.5 font-medium text-slate-800">
                      <input type="checkbox" checked readOnly />
                      4. Pozycje różnią się tylko rozmiarem (to ten sam wyrób).
                    </span>
                  </Mark>
                  <Mark>
                    <span className={`${btn} bg-blue-600 text-white`}>Połącz rozmiary</span>
                  </Mark>
                  <span className={`${btn} border border-slate-300 bg-white`}>Anuluj</span>
                </div>
              </div>
            </AppFrame>
          ),
        },
        {
          action: 'Rozdzielanie',
          does: 'Odwrotny przypadek: karta dystrybutora trzyma kilka kolorów albo rozmiarów, które producent ma na osobnych kartach. W kolumnie „Co proponujemy” widać zdanie z planem i tabelkę „pozycja u dystrybutora → karta producenta”. Po rozdzieleniu cena dystrybutora trafia na każdą z kart producenta, a karta dystrybutora znika; karty producenta zostają bez zmian. Gdy plan ma przeszkody, przycisk jest wyłączony, a powód pokazuje się po najechaniu.',
          click: 'Zakładka „Rozdzielanie”, przy wierszu „Rozdziel…”, potem „OK” w oknie z listą pozycji.',
          tone: 'blue',
          screen: (
            <AppFrame nav="Łączenie kart">
              <Tabs active="Rozdzielanie" />
              <div className="rounded-xl bg-white p-3 text-[11px] shadow-sm">
                <p className="text-slate-800">
                  ARTRA trzyma 3 kolory na jednej karcie, Portwest ma osobną kartę na każdy kolor. Po rozdzieleniu: cena ARTRA trafi na każdą z 3 kart Portwest, a karta
                  ARTRA zniknie.
                </p>
                <table className="mt-1.5 w-full text-left text-xs">
                  <thead>
                    <tr className="border-b bg-slate-50 text-[11px] text-slate-600">
                      <th className="p-1.5 font-medium">Pozycja u ARTRA</th>
                      <th className="p-1.5 font-medium">Karta Portwest</th>
                      <th className="p-1.5 font-medium">Rozmiar / kolor</th>
                    </tr>
                  </thead>
                  <tbody>
                    {(
                      [
                        ['granatowa', 'S477NAR'],
                        ['czarna', 'S477BKR'],
                        ['szara', 'S477GRR'],
                      ] as const
                    ).map(([colour, code]) => (
                      <tr key={code} className="border-b border-slate-100">
                        <td className="p-1.5">Kurtka softshell, {colour}</td>
                        <td className="p-1.5 font-mono text-blue-600">{code}</td>
                        <td className="p-1.5">
                          <span className="rounded bg-violet-100 px-1.5 text-violet-800">kolor</span>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
                <div className="mt-2 flex gap-1">
                  <Mark>
                    <span className={`${btn} bg-blue-600 text-white`}>Rozdziel…</span>
                  </Mark>
                  <span className={`${btn} border border-slate-300`}>Odrzuć</span>
                </div>
              </div>
            </AppFrame>
          ),
        },
        {
          action: 'Niepewne, Odrzucone i Zrobione',
          does: '„Niepewne” to propozycje, których nie da się zdecydować automatycznie — na przykład klucz wskazuje kilka kart producenta; przy nich jest tylko „Odrzuć”. „Odrzucone” i „Zrobione” to historia: w kolumnie „Decyzja” widać, co zrobiono, kto i kiedy, oraz powód odrzucenia.',
          click: 'Zakładka „Niepewne”, „Odrzucone” albo „Zrobione”. Na niepewnej parze otwórz wskazane karty („karta #…”), żeby sprawdzić, o który wyrób chodzi.',
          tone: 'green',
          screen: (
            <AppFrame nav="Łączenie kart">
              <Tabs active="Zrobione" mark={['Niepewne', 'Zrobione']} />
              <div className="rounded-xl bg-white p-3 shadow-sm">
                <table className="w-full text-left text-xs">
                  <thead>
                    <tr className="border-b bg-slate-50">
                      <th className="p-2 font-semibold text-slate-700">Karta dystrybutora</th>
                      <th className="p-2 font-semibold text-slate-700">Dlaczego / co proponujemy</th>
                      <th className="p-2 font-semibold text-slate-700">Decyzja</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr className="border-b align-top">
                      <td className="p-2 font-mono text-[11px] text-blue-600">ARĘK92600</td>
                      <td className="p-2 text-[11px]">
                        <span className="rounded bg-slate-100 px-1.5 text-slate-700">Połącz</span>
                        <p className="text-slate-600">ten sam EAN</p>
                      </td>
                      <td className="p-2 text-[11px] text-slate-600">
                        <p className="font-medium text-emerald-700">Połączono</p>
                        <p>Artur</p>
                        <p className="text-slate-500">02.10.2026 09:12</p>
                      </td>
                    </tr>
                    <tr className="align-top">
                      <td className="p-2 font-mono text-[11px] text-blue-600">ARĘK41870</td>
                      <td className="p-2 text-[11px]">
                        <span className="rounded bg-sky-100 px-1.5 text-sky-800">Łączenie rozmiarów</span>
                      </td>
                      <td className="p-2 text-[11px] text-emerald-800">
                        <span className="font-medium text-emerald-700">Połączono rozmiary</span> — zostaje <span className="font-mono">0735RN08</span>, nazwa „Rękawice Delta
                        Plus VV735”; dołączono kartę <span className="font-mono">ARĘK41870</span>.
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </AppFrame>
          ),
        },
      ]}
    />
  )
}
