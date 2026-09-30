import { useCallback, useEffect, useRef } from 'react'

/**
 * Autozapis szkicu po kolei: zmiany zbierają się w `pending` i idą po `delayMs` bez pisania (albo od razu).
 * Nowy zapis nie startuje, póki trwa poprzedni — czekające zmiany scalają się w jeden PATCH, więc starsza
 * odpowiedź nigdy nie przychodzi po nowszej. `flush()` kończy się dopiero po zapisaniu wszystkiego, co było
 * w kolejce. `enqueue()` wstawia do tej samej kolejki inne żądanie (np. „Zastosuj szablon”).
 * `save` nie powinno rzucać — błąd pokazuje samo (jak `mutate` w edytorze kampanii).
 * Przy odmontowaniu niezapisane zmiany idą od razu.
 */
export function useSerialAutosave<P extends object>(save: (patch: Partial<P>) => Promise<unknown>, delayMs: number) {
  const pending = useRef<Partial<P>>({})
  const timer = useRef<number | null>(null)
  const chain = useRef<Promise<unknown>>(Promise.resolve())
  const saveRef = useRef(save)

  useEffect(() => {
    saveRef.current = save
  }, [save])

  const clearTimer = useCallback(() => {
    if (timer.current !== null) {
      window.clearTimeout(timer.current)
      timer.current = null
    }
  }, [])

  const enqueue = useCallback(<T>(task: () => Promise<T>): Promise<T> => {
    const run = chain.current.then(task)
    chain.current = run.catch(() => undefined)
    return run
  }, [])

  const flush = useCallback((): Promise<void> => {
    clearTimer()
    return enqueue(async () => {
      // zmiany zebrane do chwili startu zapisu (także te wpisane w trakcie poprzedniego PATCH)
      const patch = pending.current
      if (Object.keys(patch).length === 0) return
      pending.current = {}
      await saveRef.current(patch)
    })
  }, [clearTimer, enqueue])

  const edit = useCallback(
    (patch: Partial<P>, immediate = false) => {
      pending.current = { ...pending.current, ...patch }
      clearTimer()
      if (immediate) void flush()
      else timer.current = window.setTimeout(() => void flush(), delayMs)
    },
    [clearTimer, flush, delayMs],
  )

  /** Wyrzuca czekające zmiany podanych pól (np. elementy maila przed zastosowaniem szablonu). */
  const discard = useCallback((keys: (keyof P)[]) => {
    const next = { ...pending.current }
    for (const k of keys) delete next[k]
    pending.current = next
  }, [])

  /** Czy są zmiany czekające na zapis (odliczanie albo zebrane w trakcie trwającego zapisu). */
  const hasPending = useCallback(() => timer.current !== null || Object.keys(pending.current).length > 0, [])

  useEffect(() => () => void flush(), [flush])

  return { edit, flush, enqueue, discard, hasPending }
}
