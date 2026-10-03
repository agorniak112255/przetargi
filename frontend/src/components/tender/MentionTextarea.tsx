import { useEffect, useId, useLayoutEffect, useMemo, useRef, useState, type KeyboardEvent } from 'react'
import { fetchMentionCandidates, type MentionCandidate } from '../../lib/api'

/**
 * Pole komentarza przetargu ze wzmiankami „@”: po wpisaniu „@” lista osób z dostępem do przetargu
 * (fetchMentionCandidates), wybrane osoby trafiają do mentionedIds.
 *
 * Wybór z listy wstawia „@Imię Nazwisko ” i dopisuje id osoby. Gdy ktoś usunie z tekstu „@Imię Nazwisko”,
 * id znika z mentionedIds — powiadomienie dostaje tylko ten, kto jest wspomniany w wysyłanym tekście.
 * Klawiatura: strzałki w górę i w dół, Enter albo Tab wybiera, Escape zamyka listę.
 */
export type MentionTextareaProps = {
  tenderId: number
  value: string
  onChange: (value: string) => void
  /** identyfikatory osób wybranych z listy „@” (wysyłane jako mentioned_user_ids) */
  mentionedIds: number[]
  onMentionedChange: (ids: number[]) => void
  disabled?: boolean
  placeholder?: string
  rows?: number
  /** id elementu z etykietą pola (pole nie siedzi w <label> — kliknięcie w listę nie wraca wtedy do pola) */
  labelledBy?: string
}

/** Najwięcej osób na liście podpowiedzi i najdłuższy wpisany fragment po „@”. */
const MAX_SHOWN = 8
const MAX_QUERY = 40

type MentionQuery = { start: number; query: string }

/** Fragment „@…” tuż przed kursorem: „@” na początku albo po odstępie, bez nowego wiersza. */
function mentionAt(text: string, caret: number): MentionQuery | null {
  const before = text.slice(0, caret)
  const at = before.lastIndexOf('@')
  if (at < 0) return null
  if (at > 0 && !/\s/.test(before[at - 1])) return null
  const query = before.slice(at + 1)
  if (query.length > MAX_QUERY || /[\n@]/.test(query)) return null
  return { start: at, query }
}

/** Porównanie bez wielkości liter i polskich znaków („lukasz” znajdzie „Łukasz”). */
function fold(text: string): string {
  return text
    .toLocaleLowerCase('pl-PL')
    .replace(/ł/g, 'l')
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
}

export function MentionTextarea({
  tenderId,
  value,
  onChange,
  mentionedIds,
  onMentionedChange,
  disabled,
  placeholder,
  rows = 2,
  labelledBy,
}: MentionTextareaProps) {
  const listId = useId()
  const areaRef = useRef<HTMLTextAreaElement | null>(null)
  const [candidates, setCandidates] = useState<MentionCandidate[] | null>(null)
  const [loadError, setLoadError] = useState('')
  const [mention, setMention] = useState<MentionQuery | null>(null)
  const [active, setActive] = useState(0)
  const pendingCaret = useRef<{ text: string; position: number } | null>(null)
  /** przetarg, dla którego lista jest wczytywana albo wczytana (null = żaden) */
  const loadedFor = useRef<number | null>(null)

  // inny przetarg = inna lista osób
  useEffect(() => {
    setCandidates(null)
    setLoadError('')
    loadedFor.current = null
  }, [tenderId])

  function ensureCandidates() {
    if (loadedFor.current === tenderId) return
    const forTender = tenderId
    loadedFor.current = forTender
    // ponowna próba po błędzie: znowu „Wczytuję…”, bez starego komunikatu
    setLoadError('')
    setCandidates(null)
    fetchMentionCandidates(forTender)
      .then((rows) => {
        if (loadedFor.current === forTender) setCandidates(rows)
      })
      .catch((e: unknown) => {
        if (loadedFor.current !== forTender) return
        setLoadError(e instanceof Error ? e.message : 'Nie udało się pobrać listy osób.')
        setCandidates([])
        // następne „@” spróbuje jeszcze raz
        loadedFor.current = null
      })
  }

  const matches = useMemo(() => {
    if (mention === null || candidates === null) return []
    const q = fold(mention.query.trim())
    return candidates
      .filter((c) => q === '' || fold(c.name).startsWith(q) || fold(c.name).split(/\s+/).some((part) => part.startsWith(q)))
      .slice(0, MAX_SHOWN)
  }, [mention, candidates])

  // „@Ewa sprawdź…” po wyborze osoby to już zwykły tekst — bez listy „nikt nie pasuje”
  const plainText = mention !== null && candidates !== null && matches.length === 0 && /\s/.test(mention.query)
  // „@Ewa Kowalska ” (pełna nazwa i odstęp) to wzmianka już wybrana — lista nie otwiera się drugi raz,
  // a następny Enter robi nowy wiersz zamiast wstawiać tę osobę ponownie
  const completed =
    mention !== null &&
    candidates !== null &&
    /\s$/.test(mention.query) &&
    candidates.some((c) => fold(c.name) === fold(mention.query.trim()))
  const open = mention !== null && !disabled && !plainText && !completed

  function syncMentioned(text: string) {
    if (candidates === null || mentionedIds.length === 0) return
    const kept = mentionedIds.filter((id) => {
      const person = candidates.find((c) => c.id === id)
      // osoby spoza listy (np. lista jeszcze się nie wczytała) zostają
      return person === undefined || text.includes(`@${person.name}`)
    })
    if (kept.length !== mentionedIds.length) onMentionedChange(kept)
  }

  function updateMention(text: string, caret: number) {
    const next = mentionAt(text, caret)
    setMention(next)
    setActive(0)
    if (next !== null) ensureCandidates()
  }

  function choose(person: MentionCandidate) {
    if (mention === null) return
    const area = areaRef.current
    const caret = area?.selectionStart ?? value.length
    const insert = `@${person.name} `
    const text = value.slice(0, mention.start) + insert + value.slice(caret)
    onChange(text)
    if (!mentionedIds.includes(person.id)) onMentionedChange([...mentionedIds, person.id])
    setMention(null)
    // kursor za wstawioną osobą — dopiero gdy pole dostanie nowy tekst od rodzica (useLayoutEffect niżej)
    pendingCaret.current = { text, position: mention.start + insert.length }
  }

  useLayoutEffect(() => {
    const pending = pendingCaret.current
    const area = areaRef.current
    if (pending === null || area === null || area.value !== pending.text) return
    pendingCaret.current = null
    area.focus()
    area.setSelectionRange(pending.position, pending.position)
  }, [value])

  function onKeyDown(e: KeyboardEvent<HTMLTextAreaElement>) {
    if (!open) return
    if (e.key === 'Escape') {
      e.preventDefault()
      setMention(null)
      return
    }
    if (matches.length === 0) return
    if (e.key === 'ArrowDown') {
      e.preventDefault()
      setActive((i) => (i + 1) % matches.length)
    } else if (e.key === 'ArrowUp') {
      e.preventDefault()
      setActive((i) => (i - 1 + matches.length) % matches.length)
    } else if (e.key === 'Enter' || e.key === 'Tab') {
      e.preventDefault()
      choose(matches[Math.min(active, matches.length - 1)])
    }
  }

  const activeId = open && matches.length > 0 ? `${listId}-${Math.min(active, matches.length - 1)}` : undefined

  return (
    <div className="relative">
      <textarea
        ref={areaRef}
        className="mt-1 w-full rounded border border-slate-300 px-2 py-1"
        rows={rows}
        value={value}
        disabled={disabled}
        placeholder={placeholder}
        role="combobox"
        aria-autocomplete="list"
        aria-expanded={open}
        aria-controls={open ? listId : undefined}
        aria-activedescendant={activeId}
        aria-labelledby={labelledBy}
        onChange={(e) => {
          const text = e.target.value
          onChange(text)
          syncMentioned(text)
          updateMention(text, e.target.selectionStart ?? text.length)
        }}
        onKeyDown={onKeyDown}
        onKeyUp={(e) => {
          if (e.key === 'ArrowLeft' || e.key === 'ArrowRight' || e.key === 'Home' || e.key === 'End') {
            updateMention(e.currentTarget.value, e.currentTarget.selectionStart ?? 0)
          }
        }}
        onClick={(e) => updateMention(e.currentTarget.value, e.currentTarget.selectionStart ?? 0)}
        onBlur={() => window.setTimeout(() => setMention(null), 150)}
      />
      {open && (
        <ul
          id={listId}
          role="listbox"
          aria-label="Osoby, o których możesz wspomnieć"
          className="absolute left-0 top-full z-30 mt-1 max-h-60 w-72 overflow-auto rounded-lg border border-slate-200 bg-white py-1 text-sm shadow-lg"
        >
          {candidates === null && <li className="px-3 py-2 text-xs text-slate-500">Wczytuję listę osób…</li>}
          {candidates !== null && loadError !== '' && (
            <li className="px-3 py-2 text-xs text-red-700">{loadError}</li>
          )}
          {candidates !== null && loadError === '' && matches.length === 0 && (
            <li className="px-3 py-2 text-xs text-slate-500">
              Nikt z dostępem do tego przetargu nie pasuje do „{mention.query}”.
            </li>
          )}
          {matches.map((person, i) => {
            const selected = i === Math.min(active, matches.length - 1)
            return (
              <li
                key={person.id}
                id={`${listId}-${i}`}
                role="option"
                aria-selected={selected}
                className={`cursor-pointer px-3 py-1.5 ${selected ? 'bg-blue-50' : 'hover:bg-slate-50'}`}
                onMouseDown={(e) => {
                  // przed utratą fokusu pola, inaczej lista zniknie przed wyborem
                  e.preventDefault()
                  choose(person)
                }}
                onMouseEnter={() => setActive(i)}
              >
                <div className="font-medium text-slate-900">{person.name}</div>
                <div className="text-xs text-slate-500">{person.role}</div>
              </li>
            )
          })}
        </ul>
      )}
    </div>
  )
}
