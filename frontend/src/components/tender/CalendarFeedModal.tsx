import { useEffect, useRef, useState } from 'react'
import { createCalendarFeed, deleteCalendarFeed, fetchCalendarFeed, type CalendarFeed } from '../../lib/api'

type Scope = 'mine' | 'all'

function dateTime(iso: string | null): string {
  if (!iso) return ''
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return ''
  return d.toLocaleString('pl-PL', { day: 'numeric', month: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}

const SCOPE_LABEL: Record<Scope, string> = {
  mine: 'moje przetargi',
  all: 'wszystkie przetargi',
}

/** Kopia zwykłego tekstu; bez Clipboard API (stara przeglądarka, adres http) — przez zaznaczenie pola. */
async function copyText(text: string, input: HTMLInputElement | null): Promise<boolean> {
  try {
    await navigator.clipboard.writeText(text)
    return true
  } catch {
    if (!input) return false
    input.select()
    try {
      return document.execCommand('copy')
    } catch {
      return false
    }
  }
}

/**
 * „Dodaj terminy do mojego kalendarza” — osobisty adres pliku kalendarza (ICS) do subskrypcji w Outlooku
 * i Thunderbirdzie. Adres widać tylko raz, zaraz po wygenerowaniu (w bazie jest tylko jego skrót); nowy adres
 * unieważnia poprzedni, „Wyłącz” unieważnia bez nowego. Zakres „wszystkie” tylko z tenders.view_all (canAll).
 */
export function CalendarFeedModal({ open, onClose, canAll }: { open: boolean; onClose: () => void; canAll: boolean }) {
  const [feed, setFeed] = useState<CalendarFeed | null>(null)
  const [scope, setScope] = useState<Scope>('mine')
  const [url, setUrl] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const [copied, setCopied] = useState<'' | 'ok' | 'fail'>('')
  const [reload, setReload] = useState(0)
  const urlRef = useRef<HTMLInputElement | null>(null)
  const closeRef = useRef<HTMLButtonElement | null>(null)

  useEffect(() => {
    if (!open) return
    let alive = true
    setErr('')
    setFeed(null)
    fetchCalendarFeed()
      .then((f) => {
        if (!alive) return
        setFeed(f)
        if (f.scope === 'all' && canAll) setScope('all')
      })
      .catch((ex: unknown) => {
        if (alive) setErr(ex instanceof Error ? ex.message : 'Nie udało się sprawdzić stanu kalendarza.')
      })
    return () => {
      alive = false
    }
  }, [open, canAll, reload])

  // adres pokazany raz — po zamknięciu okna znika z pamięci strony
  useEffect(() => {
    if (!open) {
      setUrl(null)
      setCopied('')
    }
  }, [open])

  // Escape i kliknięcie obok okna przy widocznym adresie pytają przed zamknięciem — adres jest jednorazowy
  const urlShown = url !== null
  const dismissRef = useRef<() => void>(onClose)
  useEffect(() => {
    dismissRef.current = () => {
      if (
        urlShown &&
        !window.confirm('Zamknąć okno? Adresu kalendarza nie da się potem odczytać — trzeba będzie wygenerować nowy. Skopiuj go, zanim zamkniesz.')
      ) {
        return
      }
      onClose()
    }
  }, [onClose, urlShown])

  useEffect(() => {
    if (!open) return
    const opener = document.activeElement instanceof HTMLElement ? document.activeElement : null
    closeRef.current?.focus()
    function onKey(e: KeyboardEvent) {
      // Escape obsłużony już wyżej (np. zamknięcie okna wyszukiwania otwartego nad tym oknem) nie zamyka tego okna
      if (e.key === 'Escape' && !e.defaultPrevented) {
        e.preventDefault()
        dismissRef.current()
      }
    }
    window.addEventListener('keydown', onKey)
    return () => {
      window.removeEventListener('keydown', onKey)
      opener?.focus()
    }
  }, [open])

  if (!open) return null

  const active = feed?.active === true

  async function generate() {
    if (
      active &&
      !window.confirm(
        'Obecny adres przestanie działać od razu. Kalendarz dodany z niego w Outlooku lub Thunderbirdzie przestanie się aktualizować — trzeba będzie dodać go od nowa z nowym adresem. Kontynuować?',
      )
    ) {
      return
    }
    setBusy(true)
    setErr('')
    setCopied('')
    try {
      const f = await createCalendarFeed(scope)
      setFeed(f)
      setUrl(f.url ?? null)
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Nie udało się wygenerować adresu.')
    } finally {
      setBusy(false)
    }
  }

  async function switchOff() {
    if (!window.confirm('Wyłączyć kalendarz? Adres przestanie działać, a terminy znikną z kalendarza w programie przy najbliższym odświeżeniu (albo przestaną się aktualizować).')) {
      return
    }
    setBusy(true)
    setErr('')
    try {
      await deleteCalendarFeed()
      setFeed({ active: false, scope: null, created_at: null, used_at: null })
      setUrl(null)
      setCopied('')
    } catch (ex) {
      setErr(ex instanceof Error ? ex.message : 'Nie udało się wyłączyć kalendarza.')
    } finally {
      setBusy(false)
    }
  }

  async function copy() {
    if (!url) return
    setCopied((await copyText(url, urlRef.current)) ? 'ok' : 'fail')
  }

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
      role="dialog"
      aria-modal="true"
      aria-labelledby="calendar-feed-title"
      onClick={() => dismissRef.current()}
    >
      <div
        className="flex max-h-[90vh] w-full max-w-2xl flex-col overflow-hidden rounded-xl bg-white shadow-lg"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="flex items-start justify-between gap-3 border-b border-slate-200 px-4 py-3">
          <div>
            <h2 id="calendar-feed-title" className="text-sm font-semibold text-slate-900">
              Dodaj terminy do mojego kalendarza
            </h2>
            <p className="text-xs text-slate-500">
              Terminy składania ofert w Outlooku albo Thunderbirdzie. Program sam pobiera nowe i przesunięte terminy.
            </p>
          </div>
          <button
            ref={closeRef}
            type="button"
            onClick={onClose}
            className="shrink-0 rounded border border-slate-300 px-2 py-1 text-xs hover:bg-slate-50"
          >
            Zamknij
          </button>
        </div>

        <div className="min-h-0 flex-1 space-y-4 overflow-y-auto px-4 py-3 text-sm">
          {err && (
            <p className="text-xs text-red-600">
              {err}{' '}
              {feed === null && (
                <button type="button" className="underline" onClick={() => setReload((n) => n + 1)}>
                  Spróbuj ponownie
                </button>
              )}
            </p>
          )}
          {feed === null && !err && <p className="text-xs text-slate-500">Ładowanie…</p>}

          {feed !== null && (
            <section className="space-y-2">
              <p className="text-xs text-slate-700">
                {active ? (
                  <>
                    Kalendarz jest włączony: {SCOPE_LABEL[feed.scope === 'all' ? 'all' : 'mine']}, adres utworzony{' '}
                    {dateTime(feed.created_at)}.{' '}
                    {feed.used_at
                      ? `Ostatnio pobrany przez program: ${dateTime(feed.used_at)}.`
                      : 'Żaden program jeszcze go nie pobrał.'}
                  </>
                ) : (
                  'Kalendarz jest wyłączony — nie ma aktywnego adresu.'
                )}
              </p>

              <fieldset className="space-y-1">
                <legend className="mb-1 text-xs font-medium text-slate-700">Które przetargi</legend>
                <label className="flex items-start gap-2 text-xs">
                  <input
                    type="radio"
                    name="calendar-scope"
                    className="mt-0.5"
                    checked={scope === 'mine'}
                    onChange={() => setScope('mine')}
                  />
                  <span>Moje — przetargi, które prowadzę, i te, do których mnie zaproszono</span>
                </label>
                {canAll && (
                  <label className="flex items-start gap-2 text-xs">
                    <input
                      type="radio"
                      name="calendar-scope"
                      className="mt-0.5"
                      checked={scope === 'all'}
                      onChange={() => setScope('all')}
                    />
                    <span>Wszystkie przetargi w aplikacji</span>
                  </label>
                )}
                {active && feed.scope !== scope && (
                  <p className="text-[11px] text-amber-700">
                    Zmiana zakresu wymaga nowego adresu — obecny dalej pokazuje {SCOPE_LABEL[feed.scope === 'all' ? 'all' : 'mine']}.
                  </p>
                )}
              </fieldset>

              <div className="flex flex-wrap gap-2">
                <button
                  type="button"
                  disabled={busy}
                  onClick={() => void generate()}
                  className="rounded bg-blue-600 px-3 py-1.5 text-xs text-white hover:bg-blue-700 disabled:opacity-50"
                >
                  {active ? 'Wygeneruj nowy adres' : 'Utwórz adres kalendarza'}
                </button>
                {active && (
                  <button
                    type="button"
                    disabled={busy}
                    onClick={() => void switchOff()}
                    className="rounded border border-red-300 px-3 py-1.5 text-xs text-red-700 hover:bg-red-50 disabled:opacity-50"
                  >
                    Wyłącz
                  </button>
                )}
              </div>
            </section>
          )}

          {url && (
            <section className="space-y-2 rounded-lg border border-blue-200 bg-blue-50 p-3">
              <label className="block text-xs font-medium text-slate-800" htmlFor="calendar-feed-url">
                Adres kalendarza
              </label>
              <div className="flex gap-2">
                <input
                  id="calendar-feed-url"
                  ref={urlRef}
                  readOnly
                  value={url}
                  onFocus={(e) => e.currentTarget.select()}
                  className="min-w-0 flex-1 rounded border border-slate-300 bg-white px-2 py-1.5 font-mono text-xs"
                />
                <button
                  type="button"
                  onClick={() => void copy()}
                  className="shrink-0 rounded bg-blue-600 px-3 py-1.5 text-xs text-white hover:bg-blue-700"
                >
                  Kopiuj
                </button>
              </div>
              {copied === 'ok' && <p className="text-[11px] text-green-700">Skopiowano.</p>}
              {copied === 'fail' && (
                <p className="text-[11px] text-red-700">Nie udało się skopiować — zaznacz adres w polu i skopiuj go ręcznie.</p>
              )}
              <p className="text-[11px] text-slate-700">
                Adres widać tylko teraz. Po zamknięciu tego okna nie da się go odczytać — można tylko wygenerować nowy.
                Kto zna ten adres, widzi numery, tytuły i terminy Twoich przetargów (bez cen), dlatego nie wysyłaj go
                innym osobom.
              </p>
            </section>
          )}

          <section className="space-y-2 text-xs text-slate-700">
            <h3 className="font-semibold text-slate-900">Jak dodać kalendarz</h3>
            <div>
              <p className="font-medium">Outlook</p>
              <ol className="ml-4 list-decimal">
                <li>Otwórz Kalendarz.</li>
                <li>Wybierz „Dodaj kalendarz”, potem „Z internetu” (w starszym Outlooku: „Otwórz kalendarz” → „Z internetu”).</li>
                <li>Wklej adres i potwierdź.</li>
              </ol>
            </div>
            <div>
              <p className="font-medium">Thunderbird</p>
              <ol className="ml-4 list-decimal">
                <li>Otwórz Kalendarz i wybierz „Nowy kalendarz”.</li>
                <li>Wybierz „W sieci” i wklej adres w polu na adres kalendarza (adres nie wymaga logowania).</li>
                <li>Przejdź dalej według kreatora i zasubskrybuj znaleziony kalendarz.</li>
              </ol>
            </div>
            <p className="text-[11px] text-slate-500">
              Program pobiera zmiany sam, według własnego harmonogramu — nowy albo przesunięty termin może pojawić się
              z opóźnieniem (w Outlooku nawet kilka godzin). Termin z godziną trwa w kalendarzu 30 minut, termin bez
              godziny zajmuje cały dzień. W kalendarzu są terminy od 90 dni wstecz do roku naprzód, bez przetargów
              odrzuconych. Gdy stracisz dostęp do przetargów w aplikacji, kalendarz przestanie się aktualizować.
            </p>
          </section>
        </div>
      </div>
    </div>
  )
}
