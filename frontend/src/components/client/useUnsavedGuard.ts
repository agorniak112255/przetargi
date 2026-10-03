import { useEffect, useRef } from 'react'

/**
 * Ochrona niezapisanego tekstu (notatka na karcie klienta): zamknięcie albo odświeżenie karty przeglądarki
 * (beforeunload) i kliknięty link do innej strony aplikacji pytają przed wyjściem — jak wynik przetargu w TenderDetail.
 * Nasłuch kliknięć na document w fazie przechwytywania działa przed obsługą <Link> (BrowserRouter nie ma useBlocker).
 * Ograniczenia: navigate() z kodu i przycisk „Wstecz” przeglądarki nie są zatrzymywane.
 */
export function useUnsavedGuard(dirty: boolean, message: string) {
  const dirtyRef = useRef(dirty)
  useEffect(() => {
    dirtyRef.current = dirty
  }, [dirty])

  useEffect(() => {
    if (!dirty) return
    const warn = (e: BeforeUnloadEvent) => {
      e.preventDefault()
      e.returnValue = ''
    }
    const onClick = (e: MouseEvent) => {
      if (e.defaultPrevented || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return
      const anchor = e.target instanceof Element ? e.target.closest('a[href]') : null
      if (!(anchor instanceof HTMLAnchorElement)) return
      if ((anchor.target && anchor.target !== '_self') || anchor.hasAttribute('download')) return
      const url = new URL(anchor.href, window.location.href)
      if (url.origin !== window.location.origin || url.pathname === window.location.pathname) return
      if (!dirtyRef.current) return
      if (window.confirm(message)) {
        dirtyRef.current = false
        return
      }
      e.preventDefault()
      e.stopPropagation()
    }
    window.addEventListener('beforeunload', warn)
    document.addEventListener('click', onClick, true)
    return () => {
      window.removeEventListener('beforeunload', warn)
      document.removeEventListener('click', onClick, true)
    }
  }, [dirty, message])
}
