export type Scheme = 'light' | 'dark'
export type AppearanceMode = 'light' | 'dark' | 'system'

export type AppearanceTemplate = {
  id: string
  label: string
  description: string
  schemes: Scheme[]
  swatches: Record<Scheme, string[]>
}

export const DEFAULT_TEMPLATE_ID = 'klasyczny'

/**
 * Rejestr szablonów wyglądu. Nowy szablon = wpis tutaj + plik CSS w src/themes/
 * + dopisanie id i schematów do skryptu inline w index.html (zapobiega mignięciu).
 */
export const TEMPLATES: AppearanceTemplate[] = [
  {
    id: 'klasyczny',
    label: 'Klasyczny',
    description: 'Obecny wygląd aplikacji: ciemny pasek boczny i jasne karty.',
    schemes: ['light'],
    swatches: {
      light: ['#edf1f5', '#1e293b', '#ffffff', '#2563eb', '#38bdf8'],
      dark: [],
    },
  },
  {
    id: 'nocna-zmiana',
    label: 'Nocna zmiana',
    description: 'Spokojna konsola robocza z trybem nocnym i dziennym. Font Geist, morski akcent.',
    schemes: ['dark', 'light'],
    swatches: {
      dark: ['#0C1318', '#18232B', '#E2E9EE', '#4CCBB3', '#EFB443'],
      light: ['#F3F6F8', '#FFFFFF', '#15222C', '#0B7D6A', '#9A6200'],
    },
  },
]

export type AppearanceChoice = { template: string; mode: AppearanceMode }

export type ResolvedAppearance = { template: AppearanceTemplate; mode: AppearanceMode; scheme: Scheme }

const CACHE_KEY = 'supon_appearance'

function isMode(value: unknown): value is AppearanceMode {
  return value === 'light' || value === 'dark' || value === 'system'
}

export function findTemplate(id: string | null | undefined): AppearanceTemplate {
  return (
    TEMPLATES.find((t) => t.id === id) ??
    TEMPLATES.find((t) => t.id === DEFAULT_TEMPLATE_ID) ??
    TEMPLATES[0]
  )
}

export function resolveAppearance(
  choice: Partial<AppearanceChoice> | null | undefined,
  prefersDark: boolean,
): ResolvedAppearance {
  const template = findTemplate(choice?.template)
  const rawMode = choice?.mode
  const mode: AppearanceMode = isMode(rawMode) ? rawMode : 'system'
  let scheme: Scheme
  if (template.schemes.length === 1) {
    scheme = template.schemes[0]
  } else if (mode === 'system') {
    scheme = prefersDark ? 'dark' : 'light'
  } else {
    scheme = mode
  }
  return { template, mode, scheme }
}

export function applyAppearance(resolved: ResolvedAppearance): void {
  const root = document.documentElement
  root.setAttribute('data-template', resolved.template.id)
  root.setAttribute('data-scheme', resolved.scheme)
  root.style.colorScheme = resolved.scheme
}

export function readCachedAppearance(): AppearanceChoice | null {
  try {
    const raw = localStorage.getItem(CACHE_KEY)
    if (!raw) return null
    const parsed = JSON.parse(raw) as Partial<AppearanceChoice> | null
    if (!parsed || typeof parsed.template !== 'string') return null
    return { template: parsed.template, mode: isMode(parsed.mode) ? parsed.mode : 'system' }
  } catch {
    return null
  }
}

export function writeCachedAppearance(choice: AppearanceChoice): void {
  try {
    localStorage.setItem(CACHE_KEY, JSON.stringify(choice))
  } catch {
    /* brak dostępu do localStorage — wygląd działa do odświeżenia strony */
  }
}
