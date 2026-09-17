/* Wspólny kod dodatku: ustawienia, wywołania API i odczyt treści maila. */

const DEFAULT_BASE_URL = 'https://przetargi.supon.rzeszow.pl'

/** Limit `max:20000` w StoreClientInquiryRequest, z zapasem. */
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

/**
 * Cała treść maila — cytat, nagłówek przekazania i stopkę odcina aplikacja
 * (App\Support\InquiryMailText), żeby ta sama zasada działała też przy
 * wklejaniu maila w przeglądarce.
 */
function messageText(full) {
  const plain = bodyFromParts(full, 'text/plain')
  const text = plain.trim() !== '' ? plain : htmlToText(bodyFromParts(full, 'text/html'))
  const normalized = String(text).replace(/\r\n?/g, '\n').replace(/\n{3,}/g, '\n\n').trim()

  return normalized.length > MAX_BODY ? normalized.slice(0, MAX_BODY) : normalized
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

/**
 * Wstawia nasz list na początek wiadomości HTML. Treść okna kompozycji to cały
 * dokument („<html>…<body>…”), więc doklejenie czegokolwiek przed nim edytor
 * odrzuca — wchodzimy zaraz za znacznik <body>.
 */
function insertIntoHtmlBody(html, snippet) {
  const document = String(html || '')
  const opening = /<body[^>]*>/i.exec(document)
  if (opening === null) {
    return snippet + document
  }

  const at = opening.index + opening[0].length

  return document.slice(0, at) + snippet + document.slice(at)
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms))
}

/**
 * Okno odpowiedzi dostaje cytat i podpis chwilę po otwarciu — wstawienie treści
 * zbyt wcześnie zostałoby nadpisane. Czekamy, aż zawartość przestanie się zmieniać.
 */
async function composeDetailsWhenReady(tabId, tries = 20, everyMs = 150) {
  let previous = null
  for (let i = 0; i < tries; i += 1) {
    const details = await browser.compose.getComposeDetails(tabId)
    const body = details.isPlainText ? details.plainTextBody : details.body
    const current = String(body || '')
    if (current.trim() !== '' && current === previous) {
      return details
    }
    previous = current
    await sleep(everyMs)
  }

  return browser.compose.getComposeDetails(tabId)
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
