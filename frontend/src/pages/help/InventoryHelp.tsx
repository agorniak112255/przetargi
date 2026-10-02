import type { ReactNode } from 'react'
import { AppFrame, Card, LivePage, Mark, Slideshow, Th } from './kit'
import { useAuth } from '../../auth'
import { Inventory } from '../Inventory'
import { InventoryRwPw } from '../InventoryRwPw'
import { can, canAny } from '../../lib/api'

/**
 * Samouczek modułu Zapasy: zakładka „Zalegające” (pages/Inventory.tsx) i „RW → PW” (pages/InventoryRwPw.tsx).
 * Napisy przycisków, filtrów i kolumn przepisane z tych stron; dane w atrapach przykładowe.
 */

/** Podzakładki strony Zapasy (components/InventoryTabs.tsx). */
function InventoryTabsMock({ active, mark }: { active: 'Zalegające' | 'RW → PW'; mark?: 'RW → PW' }) {
  return (
    <div className="mb-3 flex gap-1 border-b border-slate-200">
      {(['Zalegające', 'RW → PW'] as const).map((t) => {
        const tab = (
          <span
            className={`-mb-px border-b-2 px-3 py-2 text-sm ${
              t === active ? 'border-blue-600 font-semibold text-blue-700' : 'border-transparent text-slate-600'
            }`}
          >
            {t}
          </span>
        )
        return <span key={t}>{mark === t ? <Mark>{tab}</Mark> : tab}</span>
      })}
    </div>
  )
}

/** Segmenty wyboru jak „Bez sprzedaży od” (MonthSegments / Segments na stronach Zapasów). */
function Segments({
  label,
  options,
  value,
  mark,
  suffix,
}: {
  label: string
  options: string[]
  value: string
  mark?: boolean
  suffix?: string
}) {
  const group = (
    <div className="inline-flex overflow-hidden rounded border border-slate-300">
      {options.map((o, i) => (
        <span
          key={o}
          className={`whitespace-nowrap px-1.5 py-1 text-[11px] tabular-nums ${i > 0 ? 'border-l border-slate-300' : ''} ${
            o === value ? 'bg-blue-600 font-semibold text-white' : 'bg-white text-slate-700'
          }`}
        >
          {o}
        </span>
      ))}
    </div>
  )
  return (
    <div className="flex items-end gap-1.5">
      <div className="flex flex-col gap-0.5 text-[11px] text-slate-500">
        <span>{label}</span>
        {mark ? <Mark>{group}</Mark> : group}
      </div>
      {suffix && <span className="pb-1 text-xs text-slate-500">{suffix}</span>}
    </div>
  )
}

function SelectMock({ label, value, mark }: { label: string; value: string; mark?: boolean }) {
  const box = (
    <span className="inline-block rounded border border-slate-300 bg-white px-1.5 py-1 text-xs text-slate-800">
      {value} ▾
    </span>
  )
  return (
    <div className="flex flex-col gap-0.5 text-[11px] text-slate-500">
      <span>{label}</span>
      {mark ? <Mark>{box}</Mark> : box}
    </div>
  )
}

function FilterBar({ children }: { children: ReactNode }) {
  return (
    <div className="mb-3 flex flex-wrap items-end gap-x-3 gap-y-2 rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs shadow-sm">
      {children}
    </div>
  )
}

function Tile({ number, label, hint, amber }: { number: string; label: string; hint: string; amber?: boolean }) {
  return (
    <div className="rounded-xl border border-slate-200 bg-white px-3 py-2 shadow-sm">
      <div className={`text-base font-semibold tabular-nums ${amber ? 'text-amber-800' : 'text-slate-800'}`}>{number}</div>
      <div className="text-xs font-medium text-slate-800">{label}</div>
      <div className="text-[11px] text-slate-500">{hint}</div>
    </div>
  )
}

function SortHead({ label, arrow = '◇', right }: { label: string; arrow?: string; right?: boolean }) {
  return (
    <th className={`whitespace-nowrap p-2 ${right ? 'text-right' : ''}`}>
      <span className={`font-semibold ${arrow === '◇' ? 'text-slate-700' : 'text-blue-700'}`}>
        {label} <span className="text-[10px] text-slate-400">{arrow}</span>
      </span>
    </th>
  )
}

function ItemName({ code, name }: { code: string; name: string }) {
  return (
    <td className="p-2">
      <span className="font-mono text-slate-900">{code}</span>
      <div className="text-slate-800">{name}</div>
    </td>
  )
}

function DateAge({ date, age }: { date: string; age: string }) {
  return (
    <td className="whitespace-nowrap p-2">
      <div className="tabular-nums text-slate-700">{date}</div>
      <div className="text-[11px] text-slate-500">{age}</div>
    </td>
  )
}

function RwPwHeader() {
  return (
    <div className="mb-3">
      <h1 className="text-xl font-semibold">Zapasy — RW → PW</h1>
      <p className="mt-1 text-xs text-slate-500">Łącznie 86 · wyświetlono 1–50 · 50/stronę · od 02.10.2025</p>
    </div>
  )
}

function DocMock({ number, date, qty, value, feature, note }: {
  number: string
  date: string
  qty: string
  value: string
  feature: string
  note?: string
}) {
  return (
    <td className="whitespace-nowrap p-2">
      <div className="font-mono text-slate-900">{number}</div>
      <div className="tabular-nums text-slate-600">{date}</div>
      <div className="tabular-nums text-slate-700">
        {qty} · <span className="font-semibold text-slate-900">{value}</span>
      </div>
      <div className="text-[11px] text-slate-500">
        cecha: <span className="font-mono text-slate-700">{feature}</span>
      </div>
      {note && <div className="mt-0.5 rounded bg-slate-100 px-1.5 py-0.5 text-[11px] text-slate-700">uwagi: {note}</div>}
    </td>
  )
}

export function InventoryHelp() {
  const { user } = useAuth()
  return (
    <Slideshow
      title="Zapasy"
      slides={[
        {
          action: 'Wejście do Zapasów',
          does: 'Zapasy to lista towarów z programu magazynowego Comarch ERP XL (na ekranie „XL”), które leżą na magazynie i nie sprzedały się od wybranej liczby miesięcy — od razu widać, ile pieniędzy w nich stoi. Dane z XL są odczytywane raz na dobę o 2:00. Menu widzą osoby z uprawnieniem „Zapasy — podgląd” albo „Kampanie — własne kampanie”; zakładkę „RW → PW” (wydania i przyjęcia wewnętrzne — opisane dalej) tylko osoby z „Zapasy — podgląd”.',
          click: 'Menu „Zapasy” — otwiera się zakładka „Zalegające”.',
          tone: 'slate',
          screen: (
            <LivePage
              nav="Zapasy"
              path="/zapasy"
              page={<Inventory />}
              allowed={canAny(user, ['inventory.view', 'campaigns.use'])}
              fallback={
              <AppFrame nav="Zapasy">
                <InventoryTabsMock active="Zalegające" />
                <div className="mb-3 flex flex-wrap items-end justify-between gap-2">
                  <div>
                    <h1 className="text-xl font-semibold">Zapasy</h1>
                    <p className="mt-1 text-xs text-slate-500">
                      Łącznie <span className="font-medium text-slate-700">1 284</span> · wyświetlono 1–50 · 50/stronę · bez
                      sprzedaży od 02.04.2026 · magazyny handlowe
                    </p>
                  </div>
                  <p className="text-[11px] text-slate-500">Odczyt z XL: 02.10.2026 02:14 (raz na dobę o 2:00)</p>
                </div>
                <div className="grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                  <Tile number="1 284" label="Pozycje" hint="towary ze stanem bez sprzedaży od progu" />
                  <Tile number="412 380,55 zł" label="Wartość zapasu" hint="netto, ilość × cena zakupu partii na stanie" amber />
                  <Tile number="317" label="W tym bez karty" hint="towar XL bez karty w katalogu" />
                  <Tile number="96" label="Nigdy niesprzedane" hint="brak sprzedaży w całej historii XL" />
                </div>
              </AppFrame>
              }
            />
          ),
        },
        {
          action: 'Próg braku sprzedaży i wiek partii',
          does: '„Bez sprzedaży od” ustala, od ilu miesięcy towar nie może mieć sprzedaży (faktury, paragonu ani wydania na zewnątrz), żeby trafił na listę — na start 6; „wszystkie” zdejmuje ten warunek. „Także nigdy niesprzedane” dokłada towary bez żadnej sprzedaży w historii XL. „Partia leży od” zostawia towary, których najstarsza dostawa na magazynie leży co najmniej tyle miesięcy albo lat — ilość i wartość liczą się wtedy tylko z tych starych dostaw.',
          click: 'W pasku filtrów kliknij liczbę w „Bez sprzedaży od”, np. 12; obok zaznacz „Także nigdy niesprzedane”; w „Partia leży od (mies. / lat)” kliknij np. „2 lata” albo zostaw „wszystkie”.',
          tone: 'blue',
          screen: (
            <AppFrame nav="Zapasy">
              <InventoryTabsMock active="Zalegające" />
              <FilterBar>
                <Segments
                  label="Bez sprzedaży od"
                  options={['wszystkie', '1', '2', '3', '6', '9', '12', '18', '24']}
                  value="12"
                  suffix="mies."
                  mark
                />
                <label className="flex items-center gap-1.5 pb-1 text-xs text-slate-700">
                  <input type="checkbox" readOnly checked />
                  Także nigdy niesprzedane
                </label>
                <Segments
                  label="Partia leży od (mies. / lat)"
                  options={['wszystkie', '6', '12', '18', '24', '3 lata', '4 lata', '5 lat']}
                  value="wszystkie"
                />
              </FilterBar>
              <p className="text-xs text-slate-500">
                Łącznie <span className="font-medium text-slate-700">742</span> · bez sprzedaży od 02.10.2025 · magazyny
                handlowe
              </p>
            </AppFrame>
          ),
        },
        {
          action: 'Magazyny, oddział i szukanie',
          does: '„Magazyny” wybiera, które magazyny się liczą: handlowe (towar na sprzedaż — ustawione na start), usługowe (towar trzymany dla klientów) albo wszystkie. „Oddział” liczy stan, wartość i wiek partii tylko z magazynów jednego oddziału, np. Rzeszów. „Szukaj” znajduje towar po kodzie XL albo nazwie; są też listy „Karta” i „Grupa” oraz pole „Ostatni dostawca”, a „Wyczyść filtry” wraca do ustawień początkowych.',
          click: 'Listy „Oddział” i „Magazyny” w pasku filtrów; pole „Szukaj”; na końcu ewentualnie „Wyczyść filtry”.',
          tone: 'blue',
          screen: (
            <AppFrame nav="Zapasy">
              <InventoryTabsMock active="Zalegające" />
              <FilterBar>
                <SelectMock label="Oddział" value="Rzeszów (01)" mark />
                <SelectMock label="Magazyny" value="handlowe" mark />
                <SelectMock label="Karta" value="Wszystkie" />
                <SelectMock label="Grupa" value="wszystkie" />
                <div className="flex flex-col gap-0.5 text-[11px] text-slate-500">
                  <span>Ostatni dostawca</span>
                  <span className="inline-block w-28 rounded border border-slate-300 bg-white px-1.5 py-1 text-xs text-slate-400">
                    np. UVEX
                  </span>
                </div>
                <div className="flex min-w-[9rem] flex-1 flex-col gap-0.5 text-[11px] text-slate-500">
                  <span>Szukaj</span>
                  <span className="rounded border border-slate-300 bg-white px-1.5 py-1 text-xs text-slate-800">ARĘK</span>
                </div>
                <span className="mb-0.5 rounded border border-slate-300 bg-white px-2.5 py-1 text-xs">Wyczyść filtry</span>
              </FilterBar>
              <p className="text-xs text-slate-500">
                Łącznie <span className="font-medium text-slate-700">58</span> · bez sprzedaży od 02.04.2026 · oddział Rzeszów ·
                magazyny handlowe
              </p>
            </AppFrame>
          ),
        },
        {
          action: 'Czytanie wiersza',
          does: '„Stan” to ilość na wybranych magazynach, a pod nią magazyny (zielone = handlowe). „Wartość” = ilość × cena zakupu partii z XL; dopisek „wg ostatniej PZ” znaczy, że policzono ją z ceny ostatniego przyjęcia od dostawcy (PZ — przyjęcie zewnętrzne). „Ostatnia sprzedaż”, „Najstarsza partia” i „Ostatni zakup” mają datę i wiek, np. „14 mies. temu”; „nigdy” = brak sprzedaży w całej historii XL.',
          click: 'Nic nie klikasz — czytasz wiersz od lewej. Zdjęcie albo karta w wierszu otwiera okno weryfikacji karty.',
          tone: 'amber',
          screen: (
            <AppFrame nav="Zapasy">
              <Card>
                <table className="w-full text-left text-xs">
                  <thead>
                    <tr className="border-b bg-slate-50">
                      <Th>Kod XL / Nazwa</Th>
                      <th className="p-2 text-right font-semibold text-slate-700">Stan</th>
                      <th className="p-2 text-right font-semibold text-slate-700">Wartość</th>
                      <Th>Ostatnia sprzedaż</Th>
                      <Th>Najstarsza partia</Th>
                      <Th>Ostatni zakup</Th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr className="border-b align-top">
                      <ItemName code="ARĘK92600" name="Rękawice powlekane nitrylem, rozmiar 10" />
                      <td className="whitespace-nowrap p-2 text-right">
                        <span className="font-semibold tabular-nums text-slate-800">240</span>
                        <span className="text-slate-500"> para</span>
                        <div className="mt-1 flex justify-end gap-1 text-[11px]">
                          <span className="rounded border border-emerald-200 bg-emerald-50 px-1.5 py-0.5 tabular-nums text-emerald-900">
                            01H 180
                          </span>
                          <span className="rounded border border-emerald-200 bg-emerald-50 px-1.5 py-0.5 tabular-nums text-emerald-900">
                            02H 60
                          </span>
                        </div>
                      </td>
                      <td className="whitespace-nowrap p-2 text-right font-semibold tabular-nums text-slate-900">1644,00 zł</td>
                      <DateAge date="14.07.2025" age="14 mies. temu" />
                      <DateAge date="05.03.2024" age="30 mies. temu" />
                      <td className="whitespace-nowrap p-2">
                        <div className="tabular-nums text-slate-700">10.02.2025</div>
                        <div className="text-[11px] text-slate-500">19 mies. temu</div>
                        <div className="text-[11px] tabular-nums text-slate-500">120 para</div>
                        <div className="text-[11px] text-slate-500">UVEX</div>
                      </td>
                    </tr>
                    <tr className="border-b bg-slate-100/60 align-top">
                      <ItemName code="BPÓŁ41207" name="Półbuty ochronne S3, rozmiar 41" />
                      <td className="whitespace-nowrap p-2 text-right">
                        <span className="font-semibold tabular-nums text-slate-800">36</span>
                        <span className="text-slate-500"> para</span>
                      </td>
                      <td className="whitespace-nowrap p-2 text-right tabular-nums">
                        <span className="font-semibold text-slate-900">4262,40 zł</span>
                        <span className="block text-[10px] text-slate-500">wg ostatniej PZ</span>
                      </td>
                      <td className="whitespace-nowrap p-2">
                        <span className="rounded bg-amber-100 px-1.5 py-0.5 font-medium text-amber-800">nigdy</span>
                      </td>
                      <DateAge date="18.11.2024" age="22 mies. temu" />
                      <DateAge date="18.11.2024" age="22 mies. temu" />
                    </tr>
                  </tbody>
                </table>
              </Card>
            </AppFrame>
          ),
        },
        {
          action: 'Cena zakupu',
          does: '„Cena zakupu” pokazuje średnią cenę towaru leżącego na magazynie, policzoną z partii („z partii”), a pod nią cenę z ostatniego przyjęcia od dostawcy („ost. PZ”). Żółte „PZ … — niezgodna z partiami” znaczy, że cena z ostatniego przyjęcia mocno odbiega od ceny partii (przyjęcie mogło być poprawiane później) — wtedy wiarygodna jest cena z partii.',
          click: 'Najedź myszą na cenę, żeby zobaczyć wyjaśnienie.',
          tone: 'amber',
          screen: (
            <AppFrame nav="Zapasy">
              <Card>
                <table className="w-full text-left text-xs">
                  <thead>
                    <tr className="border-b bg-slate-50">
                      <Th>Kod XL / Nazwa</Th>
                      <th className="p-2 text-right font-semibold text-slate-700">Stan</th>
                      <th className="p-2 text-right font-semibold text-slate-700">Wartość</th>
                      <Th>Cena zakupu</Th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr className="border-b align-top">
                      <ItemName code="ARĘK92600" name="Rękawice powlekane nitrylem, rozmiar 10" />
                      <td className="whitespace-nowrap p-2 text-right tabular-nums text-slate-800">240 para</td>
                      <td className="whitespace-nowrap p-2 text-right font-semibold tabular-nums text-slate-900">1644,00 zł</td>
                      <td className="p-2">
                        <div className="whitespace-nowrap font-medium tabular-nums text-slate-900">
                          6,85 zł<span className="font-normal text-slate-500">/para</span>
                          <span className="ml-1 text-[10px] font-normal text-slate-500">z partii</span>
                        </div>
                        <div className="whitespace-nowrap text-[11px] tabular-nums text-slate-500">ost. PZ 6,90 zł/para</div>
                      </td>
                    </tr>
                    <tr className="border-b bg-slate-100/60 align-top">
                      <ItemName code="BZIM44310" name="Buty zimowe ocieplane S3, rozmiar 44" />
                      <td className="whitespace-nowrap p-2 text-right tabular-nums text-slate-800">20 para</td>
                      <td className="whitespace-nowrap p-2 text-right font-semibold tabular-nums text-slate-900">3180,00 zł</td>
                      <td className="p-2">
                        <div className="whitespace-nowrap font-medium tabular-nums text-slate-900">
                          159,00 zł<span className="font-normal text-slate-500">/para</span>
                          <span className="ml-1 text-[10px] font-normal text-slate-500">z partii</span>
                        </div>
                        <Mark>
                          <div className="whitespace-nowrap rounded bg-amber-100 px-1 text-[11px] tabular-nums text-amber-900">
                            PZ 15,90 zł — niezgodna z partiami
                          </div>
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
          action: 'Sortowanie listy',
          does: 'Kliknięcie nagłówka kolumny układa listę według niej, drugie kliknięcie odwraca kolejność. Na start lista jest ułożona po „Wartość” od największej; kwoty i stany idą od największych, daty od najstarszych. Znak ◇ oznacza kolumnę, po której lista nie jest teraz ułożona, a ▲ i ▼ — kierunek.',
          click: 'Nagłówek „Kod XL”, „Nazwa”, „Stan”, „Wartość”, „Ostatnia sprzedaż”, „Najstarsza partia” albo „Ostatni zakup” — np. „Ostatnia sprzedaż”.',
          tone: 'blue',
          screen: (
            <AppFrame nav="Zapasy">
              <Card>
                <table className="w-full text-left text-xs">
                  <thead>
                    <tr className="border-b bg-slate-50">
                      <SortHead label="Kod XL" />
                      <SortHead label="Stan" right />
                      <SortHead label="Wartość" right />
                      <th className="whitespace-nowrap p-2">
                        <Mark>
                          <span className="font-semibold text-blue-700">
                            Ostatnia sprzedaż <span className="text-[10px] text-slate-400">▲</span>
                          </span>
                        </Mark>
                      </th>
                      <SortHead label="Najstarsza partia" />
                    </tr>
                  </thead>
                  <tbody>
                    <tr className="border-b">
                      <td className="p-2 font-mono">BPÓŁ41207</td>
                      <td className="p-2 text-right tabular-nums">36 para</td>
                      <td className="p-2 text-right tabular-nums">4262,40 zł</td>
                      <td className="p-2">
                        <span className="rounded bg-amber-100 px-1.5 py-0.5 font-medium text-amber-800">nigdy</span>
                      </td>
                      <td className="p-2 tabular-nums">18.11.2024</td>
                    </tr>
                    <tr className="border-b bg-slate-100/60">
                      <td className="p-2 font-mono">ARĘK92600</td>
                      <td className="p-2 text-right tabular-nums">240 para</td>
                      <td className="p-2 text-right tabular-nums">1644,00 zł</td>
                      <td className="p-2 tabular-nums">14.07.2025</td>
                      <td className="p-2 tabular-nums">05.03.2024</td>
                    </tr>
                  </tbody>
                </table>
              </Card>
            </AppFrame>
          ),
        },
        {
          action: 'Dodanie towaru do kampanii',
          does: 'Osoby z uprawnieniem „Kampanie — własne kampanie” mają przy wierszach pola wyboru. Zaznaczasz towary (Shift i kliknięcie zaznacza zakres; zaznaczenie zostaje także po zmianie strony), a pasek u góry pokazuje, ile zaznaczono i ile pieniędzy w nich leży. Kampania mieści najwyżej 12 pozycji.',
          click: 'Zaznacz wiersze, potem „Dodaj do kampanii ▾” i wybierz „Nowa kampania z zaznaczonych” albo jeden ze swoich projektów. „Wyczyść” odznacza wszystko.',
          tone: 'blue',
          screen: (
            <AppFrame nav="Zapasy">
              <div className="mb-3 flex flex-wrap items-center justify-between gap-2 rounded-xl bg-slate-800 px-4 py-2.5 text-xs text-white shadow-xl">
                <span>
                  <b className="tabular-nums">2</b> zaznaczone · razem ok. <b className="tabular-nums">5906 zł</b> zapasu
                </span>
                <span className="flex items-center gap-2">
                  <span className="rounded border border-slate-500 px-3 py-1.5">Wyczyść</span>
                  <Mark>
                    <span className="rounded bg-sky-500 px-3 py-1.5 font-semibold text-slate-900">Dodaj do kampanii ▾</span>
                  </Mark>
                </span>
              </div>
              <Card>
                <table className="w-full text-left text-xs">
                  <thead>
                    <tr className="border-b bg-slate-50">
                      <th className="w-8 p-2">
                        <input type="checkbox" readOnly />
                      </th>
                      <Th>Kod XL / Nazwa</Th>
                      <th className="p-2 text-right font-semibold text-slate-700">Wartość</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr className="border-b bg-blue-50/40">
                      <td className="p-2">
                        <input type="checkbox" readOnly checked />
                      </td>
                      <ItemName code="BPÓŁ41207" name="Półbuty ochronne S3, rozmiar 41" />
                      <td className="p-2 text-right tabular-nums">4262,40 zł</td>
                    </tr>
                    <tr className="border-b bg-blue-50/40">
                      <td className="p-2">
                        <input type="checkbox" readOnly checked />
                      </td>
                      <ItemName code="ARĘK92600" name="Rękawice powlekane nitrylem, rozmiar 10" />
                      <td className="p-2 text-right tabular-nums">1644,00 zł</td>
                    </tr>
                  </tbody>
                </table>
              </Card>
            </AppFrame>
          ),
        },
        {
          action: 'Przejście do RW → PW',
          does: 'RW to rozchód wewnętrzny (wydanie towaru z magazynu), a PW to przyjęcie wewnętrzne (przyjęcie towaru z powrotem). Gdy w ostatnich 12 miesiącach towar wydano RW i przyjęto PW, powstała nowa partia z nową datą — w kolumnie „Najstarsza partia” jest wtedy znacznik „RW/PW ×2”, bo towar może leżeć dłużej, niż pokazuje data.',
          click: 'Kliknij znacznik „RW/PW ×2” w wierszu (otwiera pary tego towaru) albo zakładkę „RW → PW” u góry. Bez uprawnienia „Zapasy — podgląd” znacznik jest tylko do odczytu.',
          tone: 'blue',
          screen: (
            <AppFrame nav="Zapasy">
              <InventoryTabsMock active="Zalegające" mark="RW → PW" />
              <Card>
                <table className="w-full text-left text-xs">
                  <thead>
                    <tr className="border-b bg-slate-50">
                      <Th>Kod XL / Nazwa</Th>
                      <Th>Ostatnia sprzedaż</Th>
                      <Th>Najstarsza partia</Th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr className="border-b align-top">
                      <ItemName code="BPÓŁ41207" name="Półbuty ochronne S3, rozmiar 41" />
                      <td className="p-2">
                        <span className="rounded bg-amber-100 px-1.5 py-0.5 font-medium text-amber-800">nigdy</span>
                      </td>
                      <td className="whitespace-nowrap p-2">
                        <div className="tabular-nums text-slate-700">18.11.2024</div>
                        <div className="text-[11px] text-slate-500">22 mies. temu</div>
                        <span className="mt-1 inline-block">
                          <Mark>
                            <span className="rounded bg-amber-100 px-1.5 py-0.5 text-[11px] font-medium tabular-nums text-amber-800">
                              RW/PW ×2
                            </span>
                          </Mark>
                        </span>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </Card>
            </AppFrame>
          ),
        },
        {
          action: 'Para RW → PW',
          does: 'Każdy wiersz to para: RW i PW tego samego towaru w tej samej ilości w krótkim odstępie. „Kto” pokazuje, kto wystawił i zatwierdził oba dokumenty, a „Partia na RW” — ile miesięcy leżała wydana partia. W „Uwagi” znacznik „ta sama wartość” oznacza parę do wyjaśnienia w pierwszej kolejności, a „zmiana cechy: 38 → 39” — zwykłą zamianę rozmiaru.',
          click: 'Sprawdź wiersz; kliknięcie osoby w kolumnie „Kto” pokazuje wszystkie pary tej osoby.',
          tone: 'amber',
          screen: (
            <LivePage
              nav="Zapasy"
              path="/zapasy/rw-pw"
              page={<InventoryRwPw />}
              allowed={can(user, 'inventory.view')}
              fallback={
              <AppFrame nav="Zapasy">
                <InventoryTabsMock active="RW → PW" />
                <RwPwHeader />
                <Card>
                  <table className="w-full text-left text-xs">
                    <thead>
                      <tr className="border-b bg-slate-50">
                        <Th>Towar (kod XL)</Th>
                        <Th>Kto</Th>
                        <Th>RW</Th>
                        <Th>PW</Th>
                        <Th>Partia na RW</Th>
                        <Th>Uwagi</Th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr className="border-b align-top">
                        <ItemName code="BPÓŁ41207" name="Półbuty ochronne S3" />
                        <td className="whitespace-nowrap p-2">
                          <div className="font-semibold text-slate-900">Nowak</div>
                          <div className="text-[11px] text-slate-500">RW i PW</div>
                        </td>
                        <DocMock number="RW-112/26" date="12.09.2026" qty="18 para" value="2131,20 zł" feature="41" />
                        <DocMock number="PW-87/26" date="12.09.2026" qty="18 para" value="2131,20 zł" feature="41" />
                        <td className="whitespace-nowrap p-2">
                          <div className="text-sm font-semibold tabular-nums text-amber-800">21 mies.</div>
                          <div className="tabular-nums text-slate-600">przyjęta 18.11.2024</div>
                        </td>
                        <td className="p-2">
                          <span className="whitespace-nowrap rounded bg-amber-100 px-1.5 py-0.5 text-[11px] font-medium text-amber-800">
                            ta sama wartość
                          </span>
                        </td>
                      </tr>
                      <tr className="border-b bg-slate-100/60 align-top">
                        <ItemName code="BPÓŁ41207" name="Półbuty ochronne S3" />
                        <td className="whitespace-nowrap p-2">
                          <div className="font-semibold text-slate-900">Artur</div>
                          <div className="text-[11px] text-slate-500">RW i PW</div>
                        </td>
                        <DocMock
                          number="RW-98/26"
                          date="03.06.2026"
                          qty="4 para"
                          value="473,60 zł"
                          feature="38"
                          note="ZAMIANA ROZMIARÓW"
                        />
                        <DocMock number="PW-71/26" date="03.06.2026" qty="4 para" value="473,60 zł" feature="39" />
                        <td className="whitespace-nowrap p-2">
                          <div className="text-sm font-semibold tabular-nums text-amber-800">18 mies.</div>
                          <div className="tabular-nums text-slate-600">przyjęta 18.11.2024</div>
                        </td>
                        <td className="p-2">
                          <span className="whitespace-nowrap rounded bg-slate-100 px-1.5 py-0.5 text-[11px] text-slate-600">
                            zmiana cechy: <span className="font-mono">38</span> → <span className="font-mono">39</span>
                          </span>
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </Card>
              </AppFrame>
              }
            />
          ),
        },
        {
          action: 'Widoki i filtry par',
          does: '„Widok” przełącza listę: „Pary” (każda para osobno), „Po towarze” (ile par i za ile miał każdy towar) i „Po osobie” (ile par zrobiła każda osoba). Pola „Tylko ta sama wartość” i „Bez zmiany cechy (rozmiaru)” zostawiają pary najbardziej podejrzane; „Okres”, „Odstęp RW → PW”, „Osoba” i „Uwagi RW” zawężają listę dalej.',
          click: 'Przyciski „Pary”, „Po towarze”, „Po osobie” nad tabelą; liczba w kolumnie „Pary” albo nazwisko w widoku „Po osobie” wraca do par tego towaru lub tej osoby.',
          tone: 'blue',
          screen: (
            <AppFrame nav="Zapasy">
              <InventoryTabsMock active="RW → PW" />
              <FilterBar>
                <Segments label="Okres" options={['3', '6', '12']} value="12" suffix="mies." />
                <Segments label="Odstęp RW → PW" options={['ten sam dzień', 'do 3 dni', 'do 7 dni', 'do 30 dni']} value="do 3 dni" />
                <label className="flex items-center gap-1.5 pb-1 text-xs text-slate-700">
                  <input type="checkbox" readOnly checked />
                  Tylko ta sama wartość
                </label>
                <label className="flex items-center gap-1.5 pb-1 text-xs text-slate-700">
                  <input type="checkbox" readOnly checked />
                  Bez zmiany cechy (rozmiaru)
                </label>
              </FilterBar>
              <Card>
                <div className="mb-2">
                  <Segments label="Widok" options={['Pary', 'Po towarze', 'Po osobie']} value="Po osobie" mark />
                </div>
                <table className="w-full text-left text-xs">
                  <thead>
                    <tr className="border-b bg-slate-50">
                      <Th>Osoba (RW)</Th>
                      <th className="p-2 text-right font-semibold text-slate-700">Pary</th>
                      <th className="p-2 text-right font-semibold text-slate-700">Towary</th>
                      <th className="p-2 text-right font-semibold text-slate-700">Wartość ▼</th>
                      <th className="p-2 text-right font-semibold text-slate-700">Ta sama wartość</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr className="border-b">
                      <td className="p-2 text-sm font-semibold text-blue-700">Nowak</td>
                      <td className="p-2 text-right tabular-nums">14</td>
                      <td className="p-2 text-right tabular-nums">9</td>
                      <td className="p-2 text-right font-semibold tabular-nums">18 420,50 zł</td>
                      <td className="p-2 text-right font-semibold tabular-nums text-amber-800">6</td>
                    </tr>
                    <tr className="border-b bg-slate-100/60">
                      <td className="p-2 text-sm font-semibold text-blue-700">Artur</td>
                      <td className="p-2 text-right tabular-nums">5</td>
                      <td className="p-2 text-right tabular-nums">4</td>
                      <td className="p-2 text-right font-semibold tabular-nums">2960,00 zł</td>
                      <td className="p-2 text-right tabular-nums text-slate-400">0</td>
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
