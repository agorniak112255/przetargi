/* Wspólny kod dodatku: ustawienia, wywołania API i czyszczenie treści maila. */

const DEFAULT_BASE_URL = 'https://przetargi.supon.rzeszow.pl'

/** Zapas względem limitu `max:20000` w StoreClientInquiryRequest. */
const MAX_BODY = 19000

async function getSettings() {
  const s = await browser.storage.local.get({
    baseUrl: DEFAULT_BASE_URL,
    token: '',
    tone: 'formal',
    useAppSubject: false,
  })
  s.baseUrl = String(s.baseUrl || DEFAULT_BASE_URL).replace(/\/+$/, '')

  return s
}

async function setSettings(patch) {
  await browser.storage.local.set(patch)
}

/** Backend odpowiada po polsku, więc jego komunikat pokazujemy wprost. */
class ApiError extends Error {
  constructor(status, message) {
    super(message)
    this.status = status
  }
}

async function api(path, { method = 'GET', body = null, token = null, baseUrl = null } = {}) {
  const settings = await getSettings()
  const url = (baseUrl || settings.baseUrl) + path
  const headers = { Accept: 'application/json' }
  const auth = token !== null ? token : settings.token
  if (auth) headers.Authorization = 'Bearer ' + auth
  if (body !== null) headers['Content-Type'] = 'application/json'

  let res
  try {
    res = await fetch(url, {
      method,
      headers,
      body: body === null ? undefined : JSON.stringify(body),
    })
  } catch (e) {
    throw new ApiError(0, 'Brak połączenia z ' + url)
  }

  if (res.status === 204) return null

  let data = null
  try {
    data = await res.json()
  } catch (e) {
    data = null
  }

  if (!res.ok) {
    if (res.status === 401) {
      throw new ApiError(401, 'Sesja wygasła — zaloguj się ponownie w ustawieniach dodatku.')
    }
    const message = data && typeof data.message === 'string' && data.message !== ''
      ? data.message
      : 'Błąd serwera (' + res.status + ').'
    throw new ApiError(res.status, message)
  }

  return data
}

/* ------------------------------ treść maila ------------------------------ */

/** Początek cytatu lub przekazanej wiadomości. */
const SEPARATORS = [
  /^-{2,}\s*(wiadomość (oryginalna|przekazana)|original message|forwarded message)/i,
  /^_{5,}\s*$/,
  /^w dniu .{0,120}napisa[łl]/i,
  /^dnia .{0,120}napisa[łl]/i,
  /^on .{0,160}wrote:\s*$/i,
]

/** Wiersz nagłówka przekazanej wiadomości. */
const HEADER_LINE = /^(od|from|do|to|dw|cc|udw|bcc|wysłano|wyslano|sent|data|date|temat|subject)\s*:/i

/** Początek stopki wg RFC 3676. */
const SIGNATURE = /^--\s*$/

function stripQuotedLines(text) {
  return text
    .split('\n')
    .filter((line) => !/^\s*>/.test(line))
    .join('\n')
}

/** „Od:” liczy się jako nagłówek dopiero w bloku co najmniej dwóch takich linii. */
function looksLikeHeaderBlock(lines, index) {
  let headers = 0
  for (let i = index; i < lines.length && lines[i].trim() !== ''; i += 1) {
    if (!HEADER_LINE.test(lines[i].trim())) return false
    headers += 1
  }

  return headers >= 2
}

/**
 * Mail przekazany przez współpracownika zaczyna się blokiem „Od:/Temat:…”.
 * Zdejmujemy go, żeby do analizy poszła właściwa treść zapytania, a nie nagłówki.
 */
function dropForwardHeader(text) {
  const lines = text.split('\n')
  let i = 0
  while (i < lines.length && lines[i].trim() === '') i += 1
  if (i >= lines.length) return text

  let start = i
  if (SEPARATORS.some((re) => re.test(lines[i].trim()))) {
    start = i + 1
    while (start < lines.length && lines[start].trim() === '') start += 1
  } else if (!HEADER_LINE.test(lines[i].trim())) {
    return text
  }

  let j = start
  let headers = 0
  while (j < lines.length && lines[j].trim() !== '' && HEADER_LINE.test(lines[j].trim())) {
    headers += 1
    j += 1
  }

  if (headers < 2) return text

  return lines.slice(j).join('\n')
}

/** Ucina wszystko od pierwszego cytatu albo stopki. */
function cutAtSeparator(text) {
  const lines = text.split('\n')
  for (let i = 0; i < lines.length; i += 1) {
    const line = lines[i].trim()
    if (line === '') continue
    if (SIGNATURE.test(lines[i]) || SEPARATORS.some((re) => re.test(line))) {
      return lines.slice(0, i).join('\n')
    }
    if (i > 0 && HEADER_LINE.test(line) && looksLikeHeaderBlock(lines, i)) {
      return lines.slice(0, i).join('\n')
    }
  }

  return text
}

/**
 * Surowa treść maila psuje analizę: parser pozycji w aplikacji czyta wzorzec
 * „liczba + separator + reszta”, więc „35-001 Rzeszów” albo telefon „500 123 456”
 * ze stopki stałyby się pozycjami zamówienia.
 */
function cleanBody(raw) {
  let text = String(raw || '').replace(/\r\n?/g, '\n')
  text = stripQuotedLines(text)
  text = dropForwardHeader(text)
  text = cutAtSeparator(text)
  text = text.replace(/[ \t]+$/gm, '').replace(/\n{3,}/g, '\n\n').trim()

  return text.length > MAX_BODY ? text.slice(0, MAX_BODY) : text
}

function bodyFromParts(part, wanted) {
  if (!part) return ''
  const type = String(part.contentType || '').toLowerCase()
  if (type.startsWith(wanted) && typeof part.body === 'string') return part.body
  for (const child of part.parts || []) {
    const found = bodyFromParts(child, wanted)
    if (found) return found
  }

  return ''
}

function htmlToText(html) {
  return String(html || '')
    .replace(/<!--[\s\S]*?-->/g, '')
    .replace(/<(script|style)[\s\S]*?<\/\1>/gi, '')
    .replace(/<br\s*\/?>/gi, '\n')
    .replace(/<\/(p|div|tr|li|h[1-6])>/gi, '\n')
    .replace(/<[^>]+>/g, '')
    .replace(/&nbsp;/gi, ' ')
    .replace(/&#x([0-9a-f]+);/gi, (_, hex) => String.fromCodePoint(parseInt(hex, 16)))
    .replace(/&#(\d+);/g, (_, dec) => String.fromCodePoint(Number(dec)))
    .replace(/&lt;/gi, '<')
    .replace(/&gt;/gi, '>')
    .replace(/&quot;/gi, '"')
    .replace(/&amp;/gi, '&')
}

/** Treść z części text/plain; gdy jej nie ma — HTML zamieniony na tekst. */
function messageText(full) {
  const plain = bodyFromParts(full, 'text/plain')
  if (plain.trim() !== '') return plain

  return htmlToText(bodyFromParts(full, 'text/html'))
}

function escapeHtml(text) {
  return String(text)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
}

/** Tekst z aplikacji do okna kompozycji HTML — bez tego list byłby jednym akapitem. */
function textToHtml(text) {
  return escapeHtml(text).replace(/\n/g, '<br>')
}

/* -------------------- powiązanie okna odpowiedzi -------------------- */

async function rememberComposeTab(tabId, inquiryId) {
  const { composeTabs } = await browser.storage.local.get({ composeTabs: {} })
  composeTabs[String(tabId)] = inquiryId
  await browser.storage.local.set({ composeTabs })
}

async function takeComposeTab(tabId) {
  const { composeTabs } = await browser.storage.local.get({ composeTabs: {} })
  const key = String(tabId)
  const inquiryId = composeTabs[key]
  if (inquiryId === undefined) return null
  delete composeTabs[key]
  await browser.storage.local.set({ composeTabs })

  return inquiryId
}

/** Message-ID maila ↔ numer zapytania; pozwala wrócić do zapytania po restarcie. */
async function rememberInquiry(messageId, inquiryId) {
  if (!messageId) return
  const { inquiries } = await browser.storage.local.get({ inquiries: {} })
  inquiries[messageId] = inquiryId
  await browser.storage.local.set({ inquiries })
}

async function knownInquiry(messageId) {
  if (!messageId) return null
  const { inquiries } = await browser.storage.local.get({ inquiries: {} })
  const found = inquiries[messageId]

  return found === undefined ? null : found
}
