import type { ReactNode } from 'react'
import { AppFrame, Mark, Slideshow } from './kit'

/**
 * Samouczek „Czat” — rysunki poglądowe z przykładowymi osobami (prawdziwej strony czatu nie osadzamy:
 * otwarta rozmowa oznacza wiadomości jako przeczytane). Układ jak w pages/Chat.tsx.
 */

type Focus = 'list' | 'compose' | 'cards' | 'delete' | 'failed' | 'call' | 'callcard' | 'history' | 'search' | 'image'

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

/** Szukany tekst zaznaczony jak w wynikach (pages/Chat.tsx, Highlight). */
function Hl({ children }: { children: ReactNode }) {
  return <span className="rounded-sm bg-amber-100 px-0.5 text-slate-900">{children}</span>
}

/** Panel „Historia rozmowy” obok otwartej rozmowy (pages/Chat.tsx, HistoryPanel). */
function HistoryMock() {
  const entry = (who: string, time: string, kind: string | null, kindCls: string, text: ReactNode, url?: string) => (
    <div className="px-2 py-1">
      <div className="flex items-center gap-1 text-[10px]">
        <span className="font-semibold text-slate-800">{who}</span>
        {kind && <span className={`rounded px-1 text-[9px] font-medium ${kindCls}`}>{kind}</span>}
        <span className="ml-auto text-slate-400">{time}</span>
      </div>
      <div className="text-[10.5px] leading-snug text-slate-700">{text}</div>
      {url && <div className="truncate text-[10px] text-sky-700 underline">{url}</div>}
    </div>
  )
  return (
    <div className="grid content-start border-l border-slate-200 bg-white">
      <div className="flex items-center justify-between border-b border-slate-100 px-2 py-1.5">
        <span className="text-[11px] font-semibold text-slate-900">Historia rozmowy</span>
        <span className="text-slate-400">×</span>
      </div>
      <div className="m-1.5 rounded bg-slate-100 px-2 py-1 text-[10.5px] text-slate-800">cennik</div>
      <Mark>
        <div className="flex flex-wrap gap-1 px-1.5 text-[9.5px] font-medium">
          <span className="rounded-full bg-slate-100 px-1.5 text-slate-700">Wszystko</span>
          <span className="rounded-full bg-sky-600 px-1.5 text-white">Linki</span>
          <span className="rounded-full bg-slate-100 px-1.5 text-slate-700">Maile</span>
          <span className="rounded-full bg-slate-100 px-1.5 text-slate-700">Połączenia</span>
          <span className="rounded-full bg-slate-100 px-1.5 text-slate-700">Zdjęcia</span>
        </div>
      </Mark>
      <span className="px-2 pt-1.5 text-[9.5px] font-semibold uppercase tracking-wide text-slate-500">Dzisiaj</span>
      {entry('Ty', '20:14', 'Link', 'bg-sky-50 text-sky-700', <>zaktualizowałem <Hl>cennik</Hl> Bolle</>, 'przetargi…/products/23003')}
      {entry('Marek Kowalski', '10:46', 'Link', 'bg-sky-50 text-sky-700', <>Ansell ma lepszą cenę. <Hl>Cennik</Hl>: …</>, 'example.com/cennik')}
    </div>
  )
}

function ChatScreen({ focus }: { focus: Focus }) {
  return (
    <AppFrame nav="Czat">
      <p className="mb-2 text-base font-semibold">Czat</p>
      <div className="grid grid-cols-[200px_1fr] overflow-hidden rounded-md border border-slate-200 bg-white text-xs">
        <div className="border-r border-slate-200 pb-2">
          <M on={focus === 'search'}>
            <div
              className={`m-2 rounded border border-slate-300 px-2 py-1 text-[11px] ${focus === 'search' ? 'text-slate-800' : 'text-slate-400'}`}
            >
              {focus === 'search' ? 'rabat' : 'Szukaj osoby, rozmowy lub wiadomości'}
            </div>
          </M>
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
          {focus === 'search' && (
            <>
              <div className="px-2.5 pb-0.5 pt-2 text-[10px] uppercase tracking-wide text-slate-500">Wiadomości</div>
              <Mark>
                <div className="grid w-full grid-cols-[24px_1fr] items-center gap-2 px-2.5 py-1">
                  <Av text="AN" color="bg-green-100 text-green-800" />
                  <span className="min-w-0">
                    <span className="block truncate text-[11.5px] font-semibold text-slate-900">Anna Nowak</span>
                    <span className="block truncate text-[10.5px] text-slate-500">
                      Anna: czy możemy dać <Hl>rabat</Hl> 5%?
                    </span>
                  </span>
                </div>
              </Mark>
            </>
          )}
        </div>
        <div className="grid min-w-0 grid-rows-[auto_1fr_auto]">
          <div className="flex items-center gap-2 border-b border-slate-200 px-3 py-1.5">
            <Av text="MK" color="bg-blue-100 text-blue-800" online />
            <div className="flex-1">
              <p className="text-[12px] font-semibold text-slate-900">Marek Kowalski</p>
              <p className="text-[10.5px] text-green-700">w pracy</p>
            </div>
            <M on={focus === 'history'}>
              <span
                className={`rounded border px-2 py-0.5 text-[10.5px] font-medium ${
                  focus === 'history' ? 'border-sky-200 bg-sky-50 text-sky-700' : 'border-slate-300 bg-white text-slate-700'
                }`}
              >
                Historia
              </span>
            </M>
            <M on={focus === 'call'}>
              <span className="flex gap-1.5">
                <span className="rounded border border-slate-300 bg-white px-2 py-0.5 text-[10.5px] font-medium text-slate-700">
                  Zadzwoń
                </span>
                <span className="rounded border border-slate-300 bg-white px-2 py-0.5 text-[10.5px] font-medium text-slate-700">
                  Wideo
                </span>
              </span>
            </M>
          </div>
          <div className={focus === 'history' ? 'grid grid-cols-[1fr_190px]' : 'grid'}>
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
            {focus === 'callcard' && (
              <div className="grid max-w-[80%] grid-cols-[24px_1fr] gap-2">
                <Av text="MK" color="bg-blue-100 text-blue-800" />
                <div className="text-[11.5px] text-slate-900">
                  <div className="text-[10.5px] text-slate-500">
                    <span className="mr-1 font-semibold text-slate-900">Marek Kowalski</span>10:55
                  </div>
                  <M on>
                    <div className="grid gap-0.5 border border-l-[3px] border-slate-200 border-l-green-600 bg-green-50 px-2 py-1 text-[11px]">
                      <span className="text-[9.5px] uppercase tracking-wide text-slate-500">Rozmowa wideo</span>
                      <span className="font-semibold text-slate-800">Nieodebrane połączenie</span>
                      <span className="font-medium text-sky-700">Oddzwoń</span>
                    </div>
                  </M>
                </div>
              </div>
            )}
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
            {focus === 'image' && (
              <M on>
                <div className="grid max-w-[80%] justify-items-end gap-0.5 justify-self-end">
                  <div className="grid h-16 w-28 content-start gap-1 rounded-lg border border-slate-200 bg-slate-50 p-1.5">
                    <span className="h-1.5 w-14 rounded bg-sky-600" />
                    <span className="h-1 w-20 rounded bg-slate-300" />
                    <span className="h-1 w-16 rounded bg-slate-300" />
                    <span className="mt-1 h-4 w-10 rounded bg-red-200" />
                  </div>
                  <div className="rounded-lg border border-sky-100 bg-sky-50 px-2 py-1 text-[11.5px] text-slate-900">
                    Taki błąd pokazuje cennik Ansella
                  </div>
                </div>
              </M>
            )}
          </div>
          {focus === 'history' && <HistoryMock />}
          </div>
          <div className="grid grid-cols-[1fr_auto_auto] items-end gap-2 border-t border-slate-200 px-2.5 py-1.5">
            <M on={focus === 'compose'}>
              <div className="w-full rounded border border-slate-300 px-2 py-1 text-[11px] text-slate-400">Napisz wiadomość…</div>
            </M>
            <M on={focus === 'image'}>
              <span className="rounded border border-slate-300 bg-white px-2 py-1 text-[11px] font-medium text-slate-700">Zdjęcie</span>
            </M>
            <span className="rounded bg-sky-600 px-2.5 py-1 text-[11px] font-medium text-white">Wyślij</span>
            <span className="col-span-3 text-[10px] text-slate-500">Enter wysyła, Shift+Enter to nowa linia, Ctrl+V wkleja zdjęcie</span>
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

/** Okno dzwonka w prawym górnym rogu aplikacji (components/IncomingCallProvider.tsx). */
function RingScreen() {
  return (
    <AppFrame nav="Zapytania">
      <div className="relative min-h-[230px]">
        <div className="space-y-1.5 opacity-40">
          <p className="text-base font-semibold">Zapytanie #91 · Budmax Sp. z o.o.</p>
          <div className="rounded border border-slate-200 bg-white px-2 py-1 text-[11px]">1. Rękawice nitrylowe, rozm. M — 200 op.</div>
          <div className="rounded border border-slate-200 bg-white px-2 py-1 text-[11px]">2. Okulary ochronne EN 166 — 60 szt.</div>
          <div className="rounded border border-slate-200 bg-white px-2 py-1 text-[11px]">3. Kask ochronny z paskiem — 30 szt.</div>
        </div>
        <div className="absolute right-0 top-0 grid w-[230px] gap-2 rounded-lg border border-slate-300 bg-white p-2.5 text-slate-900 shadow-lg">
          <div className="grid grid-cols-[34px_1fr] items-center gap-2">
            <span className="grid h-[34px] w-[34px] place-items-center rounded-full bg-blue-100 text-xs font-semibold text-blue-800 ring-4 ring-green-600/30">
              MK
            </span>
            <div>
              <b className="block text-[12px] font-semibold">Marek Kowalski</b>
              <span className="block text-[10.5px] text-slate-500">dzwoni · rozmowa wideo</span>
            </div>
          </div>
          <div className="grid grid-cols-2 gap-1.5">
            <Mark>
              <span className="w-full rounded bg-green-600 px-2 py-1 text-center text-[11px] font-medium text-white">Odbierz</span>
            </Mark>
            <span className="rounded bg-red-600 px-2 py-1 text-center text-[11px] font-medium text-white">Odrzuć</span>
          </div>
          <span className="justify-self-center text-[10.5px] font-medium text-sky-700">Odbierz bez kamery</span>
        </div>
      </div>
    </AppFrame>
  )
}

function DarkTile({ ini, name, color, talk, children }: { ini?: string; name: string; color?: string; talk?: boolean; children?: ReactNode }) {
  return (
    <div
      className={`relative grid min-h-[80px] place-items-center rounded-md bg-[#172033] ${talk ? 'outline outline-2 -outline-offset-2 outline-[#22c55e]' : ''}`}
    >
      {children ?? <span className={`grid h-10 w-10 place-items-center rounded-full text-sm font-semibold ${color}`}>{ini}</span>}
      <span className="absolute bottom-1 left-1 rounded bg-[#020617]/70 px-1.5 text-[9.5px] text-[#e2e8f0]">{name}</span>
    </div>
  )
}

function DarkButton({ label, mark, tone = 'normal' }: { label: string; mark?: boolean; tone?: 'normal' | 'off' | 'hang' }) {
  const look =
    tone === 'off'
      ? 'bg-[#f1f5f9] border-[#f1f5f9]'
      : tone === 'hang'
        ? 'w-10 bg-red-600 border-red-600'
        : 'bg-[#1e293b] border-[#334155]'
  const dot = <span className={`block h-7 min-w-7 rounded-full border ${look}`} />
  return (
    <div className="grid justify-items-center gap-0.5">
      {mark ? <Mark>{dot}</Mark> : dot}
      <span className="text-[9px] text-[#94a3b8]">{label}</span>
    </div>
  )
}

/** Rozmowa w osobnej karcie (pages/CallPage.tsx) — zawsze ciemna, jak na makiecie. */
function CallScreen({ focus }: { focus: 'controls' | 'screen' }) {
  return (
    <div className="pointer-events-none overflow-hidden rounded-xl border border-slate-200 shadow-sm">
      <div className="grid gap-2 bg-[#0b1220] p-3 text-[#e2e8f0]">
        <div className="flex items-center gap-2 text-[10.5px] text-[#94a3b8]">
          <b className="font-semibold text-[#f1f5f9]">Kanał „Przetargi”</b>
          <span>3 osoby · 12:41</span>
          <span className="ml-auto">Rozmowa nie jest nagrywana</span>
        </div>
        <div className="grid grid-cols-[2fr_1fr] gap-2">
          <div className="row-span-2 grid min-h-[170px] place-items-center rounded-md bg-[#020617] outline outline-2 -outline-offset-2 outline-[#22c55e]">
            <div className="w-[88%] rounded bg-white p-2 text-[9.5px] text-slate-900">
              <p className="font-semibold">Cenniki → Ansell · rękawice antyprzecięciowe</p>
              <p className="mt-1 text-slate-500">HyFlex 11-542 · poziom C · 14,80 zł</p>
              <p className="text-slate-500">HyFlex 11-727 · poziom C · 19,40 zł</p>
            </div>
          </div>
          <DarkTile ini="AN" name="Anna Nowak" color="bg-green-100 text-green-800" />
          <DarkTile ini="KW" name="Ty" color="bg-sky-100 text-sky-800" />
        </div>
        <div className="flex justify-center gap-3">
          <DarkButton label="Mikrofon" />
          <DarkButton label="Kamera wył." tone="off" />
          <DarkButton label="Pokaż ekran" mark={focus === 'screen'} />
          <DarkButton label="Podnieś rękę" mark={focus === 'controls'} />
          <DarkButton label="Osoby (3)" />
          <DarkButton label="Rozłącz" tone="hang" mark={focus === 'controls'} />
        </div>
      </div>
    </div>
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
          click: 'Osobę albo kanał na liście. Do osoby, z którą jeszcze nie pisałeś, też wystarczy kliknąć jej nazwisko. Pole „Szukaj osoby, rozmowy lub wiadomości” zawęża listę i szuka też w treści wiadomości.',
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
          action: 'Zdjęcie albo zrzut ekranu',
          does: 'Zrzut ekranu (Win+Shift+S albo klawisz Print Screen) wklejasz w pole wiadomości skrótem Ctrl+V. Zdjęcie z dysku przeciągnij do rozmowy albo wybierz przyciskiem z obrazkiem obok „Wyślij”. Nad polem pojawia się podgląd — możesz dopisać podpis albo wysłać samo zdjęcie; krzyżyk przy podglądzie je usuwa. W rozmowie zdjęcie widać jako miniaturę: kliknięcie powiększa je na cały ekran, a „Pobierz” zapisuje plik. Wysłać można JPG, PNG, GIF i WEBP do 10 MB; GIF zatrzymuje się na pierwszej klatce. Zdjęcie widzą tylko osoby z tej rozmowy, a usunięcie wiadomości usuwa też zdjęcie.',
          click: 'Ctrl+V w polu „Napisz wiadomość…” albo przycisk z obrazkiem obok „Wyślij”, potem Enter.',
          tone: 'blue',
          screen: <ChatScreen focus="image" />,
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
          action: 'Historia rozmowy',
          does: 'Wszystko, co przewinęło się w rozmowie z osobą albo w kanale, od najnowszych: same linki (także adresy wpisane w tekst wiadomości), przekazane maile, połączenia albo zdjęcia. Pole u góry szuka w tej jednej rozmowie — w treści i podpisach zdjęć, w nazwie zapytania lub przetargu i w temacie, nadawcy i treści maila. Adres strony z listy otwiera się od razu w nowej karcie.',
          click: '„Historia” (ikona zegara) w górnym pasku rozmowy, potem „Linki”, „Maile”, „Połączenia” albo „Zdjęcia”. Kliknięcie wpisu przewija rozmowę do tej wiadomości i na chwilę ją obrysowuje. Zamknięcie — „×” albo Esc.',
          tone: 'blue',
          screen: <ChatScreen focus="history" />,
        },
        {
          action: 'Szukanie we wszystkich rozmowach',
          does: 'Od 2 liter pole „Szukaj” nad listą pokazuje też sekcję „Wiadomości” — pasujące wiadomości ze wszystkich Twoich rozmów i kanałów, od najnowszych, z nazwą rozmowy i zaznaczonym tekstem. Wielkość liter nie ma znaczenia. Usuniętych wiadomości nie widać.',
          click: 'Wpisz tekst w „Szukaj”, potem kliknij wiadomość w sekcji „Wiadomości” — otworzy się rozmowa przewinięta do niej. „Pokaż więcej” wczytuje starsze wyniki.',
          tone: 'blue',
          screen: <ChatScreen focus="search" />,
        },
        {
          action: 'Usuwanie własnej wiadomości',
          does: 'Usunąć można tylko swoją wiadomość. Pozostali zamiast treści zobaczą „wiadomość usunięta”. Tego nie da się cofnąć.',
          click: 'Najedź na swoją wiadomość, kliknij „Usuń” nad nią i potwierdź czerwonym „Usuń”.',
          tone: 'amber',
          screen: <ChatScreen focus="delete" />,
        },
        {
          action: 'Rozmowa głosowa i wideo',
          does: 'Z czatu można zadzwonić do jednej osoby albo do całego kanału. „Zadzwoń” to rozmowa głosowa, „Wideo” — z kamerą. Rozmowa otwiera się w nowej karcie przeglądarki; najpierw widzisz podgląd kamery i możesz włączyć albo wyłączyć mikrofon i kamerę, a potem klikasz „Dołącz”. Nie trzeba niczego instalować — wystarczy Chrome albo Edge. Rozmowy nie są nagrywane.',
          click: '„Zadzwoń” albo „Wideo” w górnym pasku otwartej rozmowy, a w nowej karcie — „Dołącz”.',
          tone: 'blue',
          screen: <ChatScreen focus="call" />,
        },
        {
          action: 'Ktoś dzwoni',
          does: 'Gdy masz otwartą aplikację, w prawym górnym rogu pojawia się okno z dzwonkiem — na każdej stronie, nie tylko w czacie. Dzwonek trwa 45 sekund. Dźwięk zagra dopiero wtedy, gdy wcześniej choć raz kliknąłeś coś na stronie (tak działają przeglądarki); inaczej zobaczysz samo okno. Gdy aplikacja jest zamknięta, dzwoni Thunderbird. Odebranie na jednym komputerze wycisza dzwonek na pozostałych.',
          click: '„Odbierz” (rozmowa otworzy się w nowej karcie), „Odbierz bez kamery” albo „Odrzuć”.',
          tone: 'blue',
          screen: <RingScreen />,
        },
        {
          action: 'W trakcie rozmowy',
          does: 'Na dole są przyciski: mikrofon, kamera, pokazanie ekranu, podniesienie ręki (pozostali widzą żółtą rączkę przy Twoim nazwisku), lista osób i ustawienia (wybór mikrofonu, kamery i głośników). Zielona ramka pokazuje, kto teraz mówi. Gdy przeglądarka wstrzyma dźwięk, pojawi się przycisk „Włącz dźwięk”. Przy chwilowej utracie internetu rozmowa łączy się ponownie sama.',
          click: 'Przyciski pod obrazem. „Rozłącz” (czerwony) kończy Twój udział — w rozmowie z jedną osobą (nie w kanale) kończy całą rozmowę.',
          tone: 'blue',
          screen: <CallScreen focus="controls" />,
        },
        {
          action: 'Pokazanie ekranu',
          does: 'Możesz pokazać pozostałym swój ekran, jedno okno albo kartę przeglądarki — np. cennik albo ofertę. Pokazywany ekran jest największy na środku. Przeglądarka zapyta, co udostępnić; „Anuluj” niczego nie pokazuje.',
          click: '„Pokaż ekran”, wybierz okno i „Udostępnij”. Koniec — „Przestań pokazywać”.',
          tone: 'blue',
          screen: <CallScreen focus="screen" />,
        },
        {
          action: 'Ile osób i co widać',
          does: 'W jednej rozmowie może być najwyżej 50 osób. Obraz widać naraz najwyżej od 9 osób — pierwszeństwo ma pokazywany ekran, osoby, które mówią, i te, które mówiły niedawno; resztę widać na liście „Osoby”. Gdy w rozmowie są już 4 osoby, dołączasz z wyłączoną kamerą, a od 10 osób — także z wyłączonym mikrofonem. Włączysz je przyciskami w każdej chwili.',
          click: '„Osoby” — lista wszystkich w rozmowie z ikonami mikrofonu, kamery i podniesionej ręki.',
          tone: 'slate',
          screen: <CallScreen focus="controls" />,
        },
        {
          action: 'Rozmowa w czacie',
          does: 'Każda rozmowa zostawia w czacie wpis w zielonej ramce. Gdy rozmowa trwa — „Rozmowa trwa” z przyciskiem „Dołącz”. Po zakończeniu — „Rozmowa · 12 min”. Gdy nikt nie odebrał — „Nieodebrane połączenie” z przyciskiem „Oddzwoń”. Takiego wpisu nie da się usunąć.',
          click: '„Dołącz” albo „Oddzwoń” w zielonej ramce.',
          tone: 'slate',
          screen: <ChatScreen focus="callcard" />,
        },
      ]}
    />
  )
}
