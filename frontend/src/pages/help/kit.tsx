import { Component, useEffect, useLayoutEffect, useRef, useState, type ReactNode } from 'react'
import { createRoot } from 'react-dom/client'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { AuthBridge, useAuth } from '../../auth'
import { NavIcon, type NavIconName } from '../../components/NavIcon'

/** Wspólne klocki samouczka (Pomoc): pokaz slajdów i atrapy ekranów aplikacji. */

export type Tone = 'blue' | 'violet' | 'green' | 'amber' | 'slate'

export type Slide = {
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

/** Menu boczne atrapy — kolejność i ikony jak w components/Layout.tsx (bez Ustawień AI i Administracji). */
const NAV: Array<[string, NavIconName]> = [
  ['Dashboard', 'dashboard'],
  ['Przetargi', 'tenders'],
  ['Produkty', 'products'],
  ['Zapasy', 'inventory'],
  ['Raport dla zarządu', 'reports'],
  ['Kampanie', 'campaigns'],
  ['Łączenie kart', 'substitutes'],
  ['Cenniki', 'price-lists'],
  ['Zamienniki', 'substitutes'],
  ['Raporty', 'reports'],
  ['Klienci', 'clients'],
  ['Zapytania', 'inquiries'],
  ['Czat', 'chat'],
  ['Pomoc', 'help'],
]

export function Mark({ children }: { children: ReactNode }) {
  return <span className="inline-flex rounded ring-2 ring-blue-500 ring-offset-2">{children}</span>
}

export function AppFrame({ nav, children }: { nav: string; children: ReactNode }) {
  return (
    <div className="pointer-events-none overflow-hidden rounded-xl border border-slate-200 bg-slate-50 shadow-sm">
      <div className="flex min-h-[300px]">
        <aside className="hidden w-[9.5rem] shrink-0 bg-slate-800 text-[11px] text-slate-100 sm:block">
          <div className="border-b border-slate-700 px-3 py-2.5 text-xs font-bold">
            Przetargi Supon
            <small className="mt-0.5 block text-[10px] font-normal text-slate-400">Artur · admin</small>
          </div>
          {NAV.map(([l, icon]) => (
            <div
              key={l}
              className={`flex items-center gap-1.5 border-l-2 px-3 py-1.5 ${
                l === nav ? 'border-sky-400 bg-slate-700 font-semibold' : 'border-transparent text-slate-300'
              }`}
            >
              <NavIcon name={icon} className="h-3 w-3 shrink-0" />
              {l}
            </div>
          ))}
        </aside>
        <div className="min-w-0 flex-1 overflow-x-auto p-4">{children}</div>
      </div>
    </div>
  )
}

const MARK_CLASSES = ['rounded', 'ring-2', 'ring-blue-500', 'ring-offset-2']

/** Element do obramowania: selektor CSS albo „text=Napis” (przycisk, odnośnik, nagłówek kolumny o dokładnie tym napisie). */
function findMark(host: HTMLElement, mark: string): HTMLElement | null {
  if (!mark.startsWith('text=')) return host.querySelector<HTMLElement>(mark)
  const want = mark.slice(5).trim()
  const candidates = host.querySelectorAll<HTMLElement>('button, a, th, label, summary, select, [role="tab"]')
  return [...candidates].find((el) => (el.textContent ?? '').replace(/\s+/g, ' ').trim() === want) ?? null
}

/**
 * Prawdziwy ekran aplikacji pomniejszony do szerokości slajdu.
 * width — szerokość, w jakiej ekran się układa (jak na laptopie); maxHeight — wysokość widoczna na slajdzie;
 * mark — element do obramowania (patrz findMark), szukany także po dociągnięciu danych.
 */
export function LiveScreen({
  children,
  width = 1180,
  maxHeight,
  mark,
}: {
  children: ReactNode
  width?: number
  maxHeight?: number
  mark?: string
}) {
  const box = useRef<HTMLDivElement>(null)
  const [zoom, setZoom] = useState(0.6)
  useLayoutEffect(() => {
    const el = box.current
    if (!el) return
    const fit = () => setZoom(Math.min(1, el.clientWidth / width))
    fit()
    const ro = new ResizeObserver(fit)
    ro.observe(el)
    return () => ro.disconnect()
  }, [width])
  useEffect(() => {
    const host = box.current
    if (!mark || !host) return
    let marked: HTMLElement | null = null
    const apply = () => {
      const target = findMark(host, mark)
      if (target === marked) return
      marked?.classList.remove(...MARK_CLASSES)
      target?.classList.add(...MARK_CLASSES)
      marked = target
    }
    apply()
    const mo = new MutationObserver(apply)
    mo.observe(host, { childList: true, subtree: true })
    return () => {
      mo.disconnect()
      marked?.classList.remove(...MARK_CLASSES)
    }
  }, [mark])
  return (
    <div ref={box} className="overflow-hidden" style={maxHeight ? { maxHeight } : undefined}>
      <div style={{ width, zoom }}>{children}</div>
    </div>
  )
}

class LiveBoundary extends Component<{ onError: () => void; children: ReactNode }, { failed: boolean }> {
  state = { failed: false }
  static getDerivedStateFromError() {
    return { failed: true }
  }
  componentDidCatch() {
    this.props.onError()
  }
  render() {
    return this.state.failed ? null : this.props.children
  }
}

/**
 * Prawdziwa strona aplikacji z AKTUALNYMI danymi zalogowanej osoby — osobne drzewo React z własnym adresem w pamięci
 * (strona może zmieniać swój adres, nie ruszając adresu Pomocy), to samo logowanie. Mysz i klawiatura są wyłączone
 * (LiveFrame: pointer-events-none + inert), więc żaden przycisk strony nie zadziała; strony przy wejściu tylko czytają
 * dane. Bez uprawnienia albo po błędzie strony — fallback (rysunek poglądowy).
 */
export function LivePage({
  nav,
  path,
  route,
  page,
  fallback,
  allowed,
  mark,
  width = 1100,
  maxHeight = 620,
}: {
  /** Pozycja menu atrapy (jak w AppFrame). */
  nav: string
  /** Adres strony, np. „/zapasy/rw-pw?widok=osoba”. */
  path: string
  /** Wzorzec trasy, gdy adres ma parametry (domyślnie ścieżka z path). */
  route?: string
  page: ReactNode
  fallback: ReactNode
  allowed: boolean
  mark?: string
  width?: number
  maxHeight?: number
}) {
  const auth = useAuth()
  const host = useRef<HTMLDivElement>(null)
  // błąd zapamiętany dla adresu — ten sam LivePage na kolejnym slajdzie (inny adres) próbuje od nowa
  const [failedPath, setFailedPath] = useState<string | null>(null)
  const show = allowed && failedPath !== path
  useEffect(() => {
    const el = host.current
    if (!show || !el) return
    // Każde uruchomienie dostaje własny kontener: poprzedni korzeń zdejmujemy z opóźnieniem (niżej), więc w tym samym
    // elemencie nie wolno od razu założyć nowego (zmiana slajdu na inny podgląd, podwójny efekt w trybie StrictMode).
    const container = document.createElement('div')
    el.appendChild(container)
    const root = createRoot(container)
    root.render(
      <AuthBridge value={auth}>
        <MemoryRouter initialEntries={[path]}>
          <LiveBoundary onError={() => setFailedPath(path)}>
            <Routes>
              <Route path={route ?? path.split('?')[0]} element={page} />
            </Routes>
          </LiveBoundary>
        </MemoryRouter>
      </AuthBridge>,
    )
    // odmontowanie poza bieżącym renderem głównego drzewa (React nie pozwala zdjąć korzenia w trakcie renderu)
    return () => {
      setTimeout(() => {
        root.unmount()
        container.remove()
      })
    }
    // page i auth celowo poza zależnościami: nowy obiekt przy każdym renderze Pomocy nie ma przeładowywać strony
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [show, path, route])
  if (!show) return <>{fallback}</>
  return (
    <LiveFrame live label={nav}>
      <LiveScreen width={width} maxHeight={maxHeight} mark={mark}>
        <div ref={host} />
      </LiveScreen>
    </LiveFrame>
  )
}

/**
 * Ramka podglądu prawdziwego ekranu: bez rysowanego menu (prawdziwe menu aplikacji jest obok), na całą szerokość
 * slajdu, kliknięcia wyłączone. live = dane z serwera; inaczej przykładowe.
 */
export function LiveFrame({ live, label, children }: { live: boolean; label: string; children: ReactNode }) {
  return (
    <div inert className="pointer-events-none overflow-hidden rounded-xl border border-slate-200 bg-slate-50 p-3 shadow-sm">
      <p
        className={`mb-2 inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[11px] font-medium ${
          live ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'
        }`}
      >
        <span className={`h-1.5 w-1.5 rounded-full ${live ? 'bg-emerald-600' : 'bg-slate-400'}`} />
        {label} · {live ? 'Twoje aktualne dane — podgląd, kliknięcia wyłączone' : 'przykładowe dane'}
      </p>
      {children}
    </div>
  )
}

export function Card({ children, className = '' }: { children: ReactNode; className?: string }) {
  return <div className={`rounded-xl bg-white p-4 shadow-sm ${className}`}>{children}</div>
}

export function Btn({
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

export function Field({
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

export function Th({ children }: { children?: ReactNode }) {
  return <th className="p-2 font-semibold text-slate-700">{children}</th>
}

export function Slideshow({ title, slides }: { title: string; slides: Slide[] }) {
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
