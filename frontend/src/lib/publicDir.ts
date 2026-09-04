/** Prefiks aplikacji: XAMPP `/Przetargi`, produkcja i Vite — pusty. */
export function publicDir(): string {
  const path = window.location.pathname
  return path === '/Przetargi' || path.startsWith('/Przetargi/') ? '/Przetargi' : ''
}

export function routerBasename(): string | undefined {
  const dir = publicDir()
  return dir === '' ? undefined : dir
}
