/**
 * Klucz logowania (token Sanctum) aplikacji.
 *
 * Zwykle w localStorage. Karta czatu w Thunderbirdzie (przycisk „Czat” dodatku) nie pozwala stronie korzystać
 * z localStorage — przeglądarka rzuca „The operation is insecure” i logowanie się nie utrzymywało. Tam klucz
 * podaje dodatek (jest już zalogowany) w końcówce adresu `#tb=…` — ta część adresu nie idzie na serwer ani do
 * dzienników — a strona zaraz ją usuwa z adresu i trzyma klucz tylko w pamięci.
 *
 * Klucz z dodatku należy do dodatku: wylogowanie w karcie czatu nie może go skasować na serwerze, bo dodatek
 * straciłby połączenie z aplikacją (zob. auth.tsx).
 */

const KEY = 'supon_token'

let memory: string | null = null
let fromAddon = false

function readStorage(): string | null {
  try {
    return localStorage.getItem(KEY)
  } catch {
    return null
  }
}

/** Jednorazowo przy starcie: klucz od dodatku Thunderbirda z `#tb=…`, usunięty z adresu. */
function takeAddonToken(): void {
  if (typeof window === 'undefined') return
  const match = /(?:^#|&)tb=([^&]+)/.exec(window.location.hash)
  if (!match) return
  try {
    memory = decodeURIComponent(match[1])
    fromAddon = memory !== ''
  } catch {
    memory = null
  }
  const rest = window.location.hash.replace(/(?:^#|&)tb=[^&]+/, '').replace(/^&/, '')
  window.history.replaceState(window.history.state, '', window.location.pathname + window.location.search + (rest ? `#${rest}` : ''))
}

takeAddonToken()

export function getToken(): string | null {
  return memory ?? readStorage()
}

/** Zapis po zalogowaniu; gdy localStorage jest zablokowany, klucz działa do zamknięcia karty. */
export function setToken(token: string): void {
  memory = token
  fromAddon = false
  try {
    localStorage.setItem(KEY, token)
    memory = null
  } catch {
    /* zablokowany zapis — zostaje klucz w pamięci */
  }
}

export function clearToken(): void {
  memory = null
  fromAddon = false
  try {
    localStorage.removeItem(KEY)
  } catch {
    /* zablokowany zapis — nie ma czego usuwać */
  }
}

/** Strona działa na kluczu dodatku Thunderbirda (karta czatu w Thunderbirdzie). */
export function isAddonSession(): boolean {
  return fromAddon
}
