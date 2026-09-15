import { createContext, useContext } from 'react'
import type { AppearanceChoice, ResolvedAppearance } from './lib/appearance'

export type AppearanceSaveState = 'idle' | 'saving' | 'saved' | 'error'

export type AppearanceCtx = {
  choice: AppearanceChoice
  resolved: ResolvedAppearance
  setChoice: (choice: AppearanceChoice) => void
  saveState: AppearanceSaveState
  saveError: string | null
}

/** Kontekst i hook osobno od AppearanceProvider (appearance.tsx), żeby nie psuć odświeżania na żywo w Vite. */
export const AppearanceContext = createContext<AppearanceCtx | null>(null)

export function useAppearance(): AppearanceCtx {
  const ctx = useContext(AppearanceContext)
  if (!ctx) throw new Error('useAppearance outside provider')
  return ctx
}
