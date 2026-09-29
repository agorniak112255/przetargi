/**
 * Kopia HTML przez zaznaczenie wstawionego fragmentu — dla przeglądarek bez ClipboardItem (starszy Firefox).
 * Poczta wkleja wtedy gotowy widok (tabele, zdjęcia), a nie kod.
 */
export function copyHtmlBySelection(html: string): boolean {
  const holder = document.createElement('div')
  holder.innerHTML = html
  holder.setAttribute('aria-hidden', 'true')
  holder.style.position = 'fixed'
  holder.style.left = '-10000px'
  holder.style.top = '0'
  document.body.appendChild(holder)
  const selection = window.getSelection()
  const range = document.createRange()
  range.selectNodeContents(holder)
  selection?.removeAllRanges()
  selection?.addRange(range)
  let ok = false
  try {
    ok = document.execCommand('copy')
  } catch {
    ok = false
  }
  selection?.removeAllRanges()
  holder.remove()
  return ok
}

/** HTML i zwykły tekst naraz: Outlook czy Gmail wklejają widok, pole bez formatowania sam tekst. */
export async function copyRichHtml(html: string, text: string): Promise<boolean> {
  try {
    await navigator.clipboard.write([
      new ClipboardItem({
        'text/html': new Blob([html], { type: 'text/html' }),
        'text/plain': new Blob([text], { type: 'text/plain' }),
      }),
    ])
    return true
  } catch {
    return copyHtmlBySelection(html)
  }
}
