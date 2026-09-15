import { useCallback, useEffect, useMemo, useRef, useState, useSyncExternalStore, type ReactNode } from 'react'
import { AppearanceContext, type AppearanceSaveState } from './appearanceContext'
import { useAuth } from './auth'
import { api, type User } from './lib/api'
import {
  DEFAULT_TEMPLATE_ID,
  applyAppearance,
  readCachedAppearance,
  resolveAppearance,
  writeCachedAppearance,
  type AppearanceChoice,
} from './lib/appearance'

const DARK_QUERY = '(prefers-color-scheme: dark)'
const SAVE_ERROR = 'Nie udało się zapisać wyglądu na koncie. Wygląd działa na tym komputerze.'

function subscribePrefersDark(onChange: () => void): () => void {
  if (typeof window === 'undefined' || !window.matchMedia) return () => {}
  const mq = window.matchMedia(DARK_QUERY)
  mq.addEventListener('change', onChange)
  return () => mq.removeEventListener('change', onChange)
}

function getPrefersDark(): boolean {
  return typeof window !== 'undefined' && Boolean(window.matchMedia?.(DARK_QUERY).matches)
}

export function AppearanceProvider({ children }: { children: ReactNode }) {
  const { user } = useAuth()
  const [choice, setChoiceState] = useState<AppearanceChoice>(
    () => readCachedAppearance() ?? { template: DEFAULT_TEMPLATE_ID, mode: 'system' },
  )
  const [saveState, setSaveState] = useState<AppearanceSaveState>('idle')
  const [saveError, setSaveError] = useState<string | null>(null)
  const requestSeq = useRef(0)
  const prefersDark = useSyncExternalStore(subscribePrefersDark, getPrefersDark, () => false)

  const resolved = useMemo(() => resolveAppearance(choice, prefersDark), [choice, prefersDark])

  useEffect(() => {
    applyAppearance(resolved)
  }, [resolved])

  // Wybór zapisany na koncie wygrywa z zapisem z tego komputera (po /me i po zalogowaniu).
  useEffect(() => {
    const prefs = user?.ui_preferences
    if (!prefs?.template) return
    const fromServer: AppearanceChoice = { template: prefs.template, mode: prefs.mode ?? 'system' }
    setChoiceState(fromServer)
    writeCachedAppearance(fromServer)
  }, [user])

  const setChoice = useCallback(
    (next: AppearanceChoice) => {
      setChoiceState(next)
      writeCachedAppearance(next)
      applyAppearance(resolveAppearance(next, getPrefersDark()))
      if (!user) return

      const seq = ++requestSeq.current
      setSaveState('saving')
      setSaveError(null)
      api<User>('/me/preferences', {
        method: 'PATCH',
        body: JSON.stringify({ template: next.template, mode: next.mode }),
      })
        .then(() => {
          if (seq !== requestSeq.current) return
          setSaveState('saved')
        })
        .catch(() => {
          if (seq !== requestSeq.current) return
          setSaveState('error')
          setSaveError(SAVE_ERROR)
        })
    },
    [user],
  )

  const value = useMemo(
    () => ({ choice, resolved, setChoice, saveState, saveError }),
    [choice, resolved, setChoice, saveState, saveError],
  )

  return <AppearanceContext.Provider value={value}>{children}</AppearanceContext.Provider>
}
