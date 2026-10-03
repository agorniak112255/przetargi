import type { ReactNode } from 'react'
import { AppFrame, Mark, Slideshow } from './kit'

/**
 * Samouczek „Czat” — rysunki poglądowe z przykładowymi osobami (prawdziwej strony czatu nie osadzamy:
 * otwarta rozmowa oznacza wiadomości jako przeczytane). Układ jak w pages/Chat.tsx.
 */

type Focus = 'list' | 'compose' | 'cards' | 'delete' | 'failed'

function M({ on, children }: { on: boolean; children: ReactNode }) {
  return on ? <Mark>{children}</Mark> : <>{children}</>
}

function Av({ text, color, online }: { text: string; color: string; online?: boolean }) {
  return (
    <span className={`relative grid h-6 w-6 shrink-0 place-items-center rounded-full text-[10px] font-semibold ${color}`}>
      {text}
      {online !== undefined && (
        <span
          className={`absolute -bottom-px -right-px h-2 w-2 rounded-full ring-2 ring-slate-50 ${online ? 'bg-green-600' : 'bg-slate-400'}`}
        />
      )}
    </span>
  )
}

function Row({
  avatar,
  name,
  preview,
  badge,
  active,
}: {
  avatar: ReactNode
  name: string
  preview: string
  badge?: number
  active?: boolean
}) {
  return (
    <div
      className={`grid grid-cols-[24px_1fr_auto] items-center gap-2 border-l-[3px] px-2.5 py-1 ${
        active ? 'border-l-sky-600 bg-sky-50' : 'border-l-transparent'
      }`}
    >
      {avatar}
      <span className="min-w-0">
        <span className="block truncate text-[11.5px] font-semibold text-slate-900">{name}</span>
        <span className="block truncate text-[10.5px] text-slate-500">{preview}</span>
      </span>
      {badge ? (
        <span className="rounded-full bg-sky-600 px-1.5 text-[10px] font-semibold leading-4 text-white">{badge}</span>
      ) : (
        <span />
      )}
    </div>
  )
}

const hash = <span className="grid h-6 w-6 place-items-center rounded-md bg-slate-100 text-xs text-slate-500">#</span>

function ChatScreen({ focus }: { focus: Focus }) {
  return (
    <AppFrame nav="Czat">
      <p className="mb-2 text-base font-semibold">Czat</p>
      <div className="grid grid-cols-[200px_1fr] overflow-hidden rounded-md border border-slate-200 bg-white text-xs">
        <div className="border-r border-slate-200 pb-2">
          <div className="m-2 rounded border border-slate-300 px-2 py-1 text-[11px] text-slate-400">Szukaj osoby lub rozmowy</div>
          <div className="flex items-center justify-between px-2.5 pb-0.5 pt-1">
            <span className="text-[10px] uppercase tracking-wide text-slate-500">Kanały</span>
            <span className="text-[10.5px] font-medium text-sky-700">+ Nowy kanał</span>
          </div>
          <Row avatar={hash} name="Ogólny" preview="Anna: w poniedziałek inwentaryzacja" />
          <Row avatar={hash} name="Przetargi" preview="Marek: wynik — 2. miejsce" badge={1} />
          <div className="px-2.5 pb-0.5 pt-2 text-[10px] uppercase tracking-wide text-slate-500">Osoby</div>
          <M on={focus === 'list'}>
            <div className="w-full">
              <Row
                avatar={<Av text="MK" color="bg-blue-100 text-blue-800" online />}
                name="Marek Kowalski"
                preview="Ansell ma lepszą cenę od 500 par"
                badge={2}
                active
              />
              <Row avatar={<Av text="AN" color="bg-green-100 text-green-800" online />} name="Anna Nowak" preview="Ty: dzięki, wysłane" />
              <Row avatar={<Av text="TZ" color="bg-rose-100 text-rose-800" online={false} />} name="Tomasz Zając" preview="" />
            </div>
          </M>
        </div>
        <div className="grid min-w-0 grid-rows-[auto_1fr_auto]">
          <div className="flex items-center gap-2 border-b border-slate-200 px-3 py-1.5">
            <Av text="MK" color="bg-blue-100 text-blue-800" online />
            <div>
              <p className="text-[12px] font-semibold text-slate-900">Marek Kowalski</p>
              <p className="text-[10.5px] text-green-700">w pracy</p>
            </div>
          </div>
          <div className="flex flex-col gap-2 px-3 py-2">
            <span className="self-center rounded-full bg-slate-100 px-2 text-[10px] text-slate-500">Dzisiaj</span>
            <div className="grid max-w-[80%] gap-0.5 self-end">
              <div className="flex justify-end gap-2 text-[10.5px] text-slate-500">
                <M on={focus === 'delete'}>
                  <span className={focus === 'delete' ? 'text-slate-600' : 'invisible'}>
                    {focus === 'delete' ? (
                      <>
                        Usunąć tę wiadomość? <span className="rounded bg-red-600 px-1.5 text-white">Usuń</span> Anuluj
                      </>
                    ) : (
                      'Usuń'
                    )}
                  </span>
                </M>
                <span>10:42</span>
              </div>
              <div className="rounded-lg border border-sky-100 bg-sky-50 px-2 py-1 text-[11.5px] text-slate-900">
                Zerkniesz na pozycję 4? Klient chce 1200 par.
                <M on={focus === 'cards'}>
                  <div className="mt-1 grid gap-0.5 border border-l-[3px] border-slate-200 border-l-sky-600 bg-slate-50 px-2 py-1 text-[11px]">
                    <span className="text-[9.5px] uppercase tracking-wide text-slate-500">Zapytanie</span>
                    <span className="font-semibold text-slate-800">Budmax Sp. z o.o. · Rękawice antyprzecięciowe</span>
                    <span className="text-slate-600">Pozycja 4</span>
                    <span className="font-medium text-sky-700">Otwórz pozycję 4</span>
                  </div>
                </M>
              </div>
            </div>
            <div className="grid max-w-[80%] grid-cols-[24px_1fr] gap-2">
              <Av text="MK" color="bg-blue-100 text-blue-800" />
              <div className="text-[11.5px] text-slate-900">
                <div className="text-[10.5px] text-slate-500">
                  <span className="mr-1 font-semibold text-slate-900">Marek Kowalski</span>10:48
                </div>
                A to przyszło do mnie, ale to chyba Twój klient:
                <M on={focus === 'cards'}>
                  <div className="mt-1 grid gap-0.5 border border-l-[3px] border-slate-200 border-l-violet-700 bg-violet-50 px-2 py-1 text-[11px]">
                    <span className="text-[9.5px] uppercase tracking-wide text-slate-500">Mail z Thunderbirda</span>
                    <span className="font-semibold text-slate-800">Zapytanie o kamizelki ostrzegawcze — 40 szt.</span>
                    <span className="text-slate-600">Od: zaopatrzenie@pol-drog.pl · 03.10, 09:15</span>
                    <span className="font-medium text-violet-800">Pokaż treść maila</span>
                  </div>
                </M>
              </div>
            </div>
            {focus === 'failed' && (
              <M on>
                <div className="grid max-w-[80%] gap-0.5 justify-self-end">
                  <div className="text-right text-[10.5px] text-red-700">Nie wysłano</div>
                  <div className="rounded-lg border border-red-200 bg-red-50 px-2 py-1 text-[11.5px] text-slate-900">
                    Dzięki, zmieniam w ofercie.
                  </div>
                  <div className="text-right text-[10.5px]">
                    <span className="font-medium text-sky-700">Wyślij ponownie</span>{' '}
                    <span className="text-slate-600">Nie wysyłaj</span>
                  </div>
                </div>
              </M>
            )}
          </div>
          <div className="grid grid-cols-[1fr_auto] items-end gap-2 border-t border-slate-200 px-2.5 py-1.5">
            <M on={focus === 'compose'}>
              <div className="w-full rounded border border-slate-300 px-2 py-1 text-[11px] text-slate-400">Napisz wiadomość…</div>
            </M>
            <span className="rounded bg-sky-600 px-2.5 py-1 text-[11px] font-medium text-white">Wyślij</span>
            <span className="col-span-2 text-[10px] text-slate-500">Enter wysyła, Shift+Enter to nowa linia</span>
          </div>
        </div>
      </div>
    </AppFrame>
  )
}

function NewChannelScreen() {
  return (
    <AppFrame nav="Czat">
      <div className="mx-auto max-w-xs space-y-2 rounded-xl border border-slate-200 bg-white p-3 text-xs shadow-sm">
        <p className="text-sm font-semibold text-slate-900">Nowy kanał</p>
        <div>
          Nazwa kanału
          <div className="mt-1 rounded border border-slate-300 px-2 py-1 text-slate-800">Przetargi</div>
        </div>
        <div className="space-y-1 rounded border border-slate-200 p-1.5">
          {[
            ['MK', 'Marek Kowalski', true],
            ['AN', 'Anna Nowak', true],
            ['TZ', 'Tomasz Zając', false],
          ].map(([ini, name, on]) => (
            <div key={String(name)} className="flex items-center gap-2">
              <span className={`h-3 w-3 rounded-sm border ${on ? 'border-sky-600 bg-sky-600' : 'border-slate-400 bg-white'}`} />
              <span className="text-[10px] font-semibold text-slate-600">{ini}</span>
              {name}
            </div>
          ))}
        </div>
        <div className="flex justify-end gap-2">
          <span className="rounded border border-slate-300 px-2 py-1">Anuluj</span>
          <Mark>
            <span className="rounded bg-sky-600 px-2 py-1 font-medium text-white">Załóż kanał</span>
          </Mark>
        </div>
      </div>
    </AppFrame>
  )
}

function ShareScreen() {
  return (
    <AppFrame nav="Zapytania">
      <div className="mb-3 flex items-start justify-between gap-2">
        <div>
          <p className="text-base font-semibold">Odpowiedź na zapytanie</p>
          <p className="text-[11px] text-slate-500">Budmax Sp. z o.o. · Rękawice antyprzecięciowe</p>
        </div>
        <div className="flex items-center gap-2">
          <Mark>
            <span className="rounded border border-slate-300 bg-white px-2 py-1 text-[11px] font-semibold text-slate-700">
              Wyślij w czacie
            </span>
          </Mark>
          <span className="rounded-xl bg-white px-3 py-1.5 text-xs font-semibold text-blue-700 ring-1 ring-slate-200">
            ← Wróć do zapytań
          </span>
        </div>
      </div>
      <div className="mx-auto max-w-sm space-y-2 rounded-xl border border-slate-200 bg-white p-3 text-xs shadow-sm">
        <p className="text-sm font-semibold text-slate-900">Wyślij w czacie</p>
        <div>
          Do kogo
          <div className="mt-1 rounded border border-slate-300 px-2 py-1 text-slate-800">Marek Kowalski (w pracy) ▾</div>
        </div>
        <div>
          Której pozycji dotyczy
          <div className="mt-1 rounded border border-slate-300 px-2 py-1 text-slate-800">4. Rękawice antyprzecięciowe EN 388 ▾</div>
        </div>
        <div>
          Komentarz (nieobowiązkowy)
          <div className="mt-1 rounded border border-slate-300 px-2 py-1 text-slate-800">Zerkniesz na cenę?</div>
        </div>
        <div className="flex justify-end">
          <span className="rounded bg-sky-600 px-2.5 py-1 font-medium text-white">Wyślij</span>
        </div>
      </div>
    </AppFrame>
  )
}

export function ChatHelp() {
  return (
    <Slideshow
      title="Czat"
      slides={[
        {
          action: 'Gdzie jest czat',
          does: 'Pozycja „Czat” w menu. Po lewej są kanały (rozmowy kilku osób) i osoby, po prawej otwarta rozmowa. Zielona kropka przy osobie znaczy, że jest teraz w aplikacji albo w Thunderbirdzie. Niebieska liczba to wiadomości, których jeszcze nie przeczytałeś — ta sama liczba jest przy „Czat” w menu i w nazwie karty przeglądarki.',
          click: 'Osobę albo kanał na liście. Do osoby, z którą jeszcze nie pisałeś, też wystarczy kliknąć jej nazwisko. Pole „Szukaj osoby lub rozmowy” zawęża listę.',
          tone: 'blue',
          screen: <ChatScreen focus="list" />,
        },
        {
          action: 'Pisanie wiadomości',
          does: 'Wiadomość pojawia się od razu po prawej stronie. Enter wysyła, Shift+Enter przenosi do nowej linii. Wiadomość może mieć do 4000 znaków — przy dłuższym tekście pod polem pojawia się licznik. Adresy stron zaczynające się od http albo https da się kliknąć; otwierają się w nowej karcie.',
          click: 'Pole „Napisz wiadomość…” na dole, potem Enter albo „Wyślij”.',
          tone: 'blue',
          screen: <ChatScreen focus="compose" />,
        },
        {
          action: 'Gdy wiadomość nie doszła',
          does: 'Bez internetu albo przy chwilowym błędzie serwera wiadomość zostaje na czerwono z napisem „Nie wysłano”. Ponowna próba nie zrobi podwójnej wiadomości, nawet jeśli pierwsza jednak doszła.',
          click: '„Wyślij ponownie”. „Nie wysyłaj” usuwa ją z ekranu i wstawia tekst z powrotem do pola.',
          tone: 'amber',
          screen: <ChatScreen focus="failed" />,
        },
        {
          action: 'Nowy kanał',
          does: 'Kanał to rozmowa kilku osób, np. „Przetargi”. Kanał „Ogólny” mają wszyscy i nie da się z niego wyjść. Do własnych kanałów można później dodać osoby przyciskiem „Dodaj osoby” albo z nich wyjść — „Wyjdź z kanału” u góry rozmowy.',
          click: '„+ Nowy kanał” nad listą kanałów, wpisz nazwę, zaznacz osoby i „Załóż kanał”.',
          tone: 'blue',
          screen: <NewChannelScreen />,
        },
        {
          action: 'Zapytanie albo przetarg w czacie',
          does: 'Na stronie zapytania i przetargu jest przycisk „Wyślij w czacie”. Wybierasz osobę albo kanał, przy zapytaniu możesz wskazać pozycję, i dopisujesz komentarz. W rozmowie pojawia się niebieska ramka z nazwą zapytania; kliknięcie otwiera je w aplikacji. Otworzy je tylko ktoś, kto ma do niego dostęp.',
          click: '„Wyślij w czacie” obok „← Wróć do zapytań” (w przetargu — przy przycisku „Eksport”).',
          tone: 'blue',
          screen: <ShareScreen />,
        },
        {
          action: 'Link do zapytania i mail w rozmowie',
          does: 'Niebieska ramka to link do zapytania albo przetargu. Fioletowa ramka to mail przesłany z Thunderbirda przyciskiem „Wyślij koledze”: temat, nadawca i data, a jeśli nadawca dołączył treść — także treść maila do rozwinięcia.',
          click: '„Otwórz pozycję 4” (albo „Otwórz zapytanie”) w niebieskiej ramce; „Pokaż treść maila” w fioletowej.',
          tone: 'slate',
          screen: <ChatScreen focus="cards" />,
        },
        {
          action: 'Usuwanie własnej wiadomości',
          does: 'Usunąć można tylko swoją wiadomość. Pozostali zamiast treści zobaczą „wiadomość usunięta”. Tego nie da się cofnąć.',
          click: 'Najedź na swoją wiadomość, kliknij „Usuń” nad nią i potwierdź czerwonym „Usuń”.',
          tone: 'amber',
          screen: <ChatScreen focus="delete" />,
        },
      ]}
    />
  )
}
