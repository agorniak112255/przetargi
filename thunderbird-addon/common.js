/* Wspólny kod dodatku: ustawienia, wywołania API i odczyt treści maila. */

const DEFAULT_BASE_URL = 'https://przetargi.supon.rzeszow.pl'

/** Limit `max:20000` w StoreClientInquiryRequest, z zapasem. */
const MAX_BODY = 19000

/** Limit `max:400` dla `source_from` w StoreClientInquiryRequest. */
const MAX_FROM = 400

/**
 * Szablony listu do klienta — ta sama lista co w ClientInquiry::TONES.
 * Aplikacja odrzuca nieznany szablon (422), więc zapisany wybór z poprzedniej
 * wersji dodatku sprowadzamy do czegoś, co serwer zna.
 */
const TONES = {
  handlowy: 'Handlowy (pełna specyfikacja): nazwa z katalogu, SKU i producent, normy i cena.',
  bez_sku: 'Bez SKU (proste opisy): jedno zdanie opisu bez marki i modelu, normy i cena.',
  formal: 'Oficjalny (długie opisy): nazwa, akapit opisu z karty wyrobu, normy i cena — bez SKU.',
}

const DEFAULT_TONE = 'handlowy'

async function getSettings() {
  const s = await browser.storage.local.get({
    baseUrl: DEFAULT_BASE_URL,
    token: '',
    tone: DEFAULT_TONE,
    useAppSubject: false,
  })
  s.baseUrl = String(s.baseUrl || DEFAULT_BASE_URL).replace(/\/+$/, '')
  if (TONES[s.tone] === undefined) s.tone = DEFAULT_TONE

  return s
}

async function setSettings(patch) {
  await browser.storage.local.set(patch)
}

/** Backend odpowiada po polsku, więc jego komunikat pokazujemy wprost. */
class ApiError extends Error {
  constructor(status, message, data = null) {
    super(message)
    this.status = status
    // Całe ciało odpowiedzi — przy 409 jest w nim pole `duplicate`.
    this.data = data
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
    throw new ApiError(res.status, message, data)
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

/**
 * Data maila (`MessageHeader.date`) przychodzi jako obiekt Date, ale po przejściu
 * przez `runtime.sendMessage` bywa już łańcuchem znaków — a w starszych wersjach
 * Thunderbirda pola potrafi w ogóle zabraknąć. Obsługujemy wszystkie przypadki
 * i zwracamy ISO 8601 albo null, gdy daty nie ma lub jest nieczytelna.
 */
function toIsoDate(value) {
  if (value === null || value === undefined || value === '') return null

  let date
  if (Object.prototype.toString.call(value) === '[object Date]') {
    // `instanceof Date` zawodzi między kontekstami dodatku (okienko ↔ tło).
    date = value
  } else if (typeof value === 'number') {
    date = new Date(value)
  } else {
    date = new Date(String(value))
  }

  if (Number.isNaN(date.getTime())) return null

  return date.toISOString()
}

/** Nagłówek From w całości („Jan Kowalski <jan@firma.pl>”); rozbija go aplikacja. */
function senderHeader(value) {
  const from = String(value === null || value === undefined ? '' : value).trim()
  if (from === '') return null

  return from.length > MAX_FROM ? from.slice(0, MAX_FROM) : from
}

/** Message-ID bez nawiasów „< >” i bez spacji — tak samo jak w aplikacji. */
function normalizeMessageId(value) {
  const id = String(value === null || value === undefined ? '' : value).trim()

  return id.replace(/^</, '').replace(/>$/, '').trim()
}

/** Numer wersji z manifestu — żeby dało się sprawdzić, co jest zainstalowane. */
function addonVersion() {
  try {
    return browser.runtime.getManifest().version
  } catch (e) {
    return ''
  }
}

/* ---------------------------- aktualizacje ---------------------------- */

/** Identyfikator dodatku — tym kluczem opisana jest wersja w updates.json. */
const ADDON_ID = 'przetargi@supon.rzeszow.pl'

/** Plik, z którego Thunderbird sam czyta informację o nowej wersji. */
const UPDATE_MANIFEST_PATH = '/dodatek/updates.json'

/**
 * Adres, spod którego bierze się aktualizacje — na sztywno, tak samo jak
 * `update_url` w manifeście i `UPDATE_BASE` w build.py. Gdyby czytać go
 * z ustawień, komputer wskazujący na serwer testowy pokazywałby inną wersję
 * niż ta, którą faktycznie pobiera Thunderbird.
 */
const UPDATE_BASE = 'https://przetargi.supon.rzeszow.pl'

/**
 * „1.10.0” jest nowsze niż „1.9.0”, więc porównujemy człon po członie jako
 * liczby. Zwraca 1, gdy `a` jest nowsze, -1 gdy starsze, 0 gdy to samo.
 */
function compareVersions(a, b) {
  const left = String(a || '').split('.')
  const right = String(b || '').split('.')
  const length = Math.max(left.length, right.length)

  for (let i = 0; i < length; i += 1) {
    const one = Number.parseInt(left[i] || '0', 10) || 0
    const two = Number.parseInt(right[i] || '0', 10) || 0
    if (one > two) return 1
    if (one < two) return -1
  }

  return 0
}

/**
 * Najnowsza wersja opisana w updates.json na serwerze — ten sam plik, z którego
 * Thunderbird bierze aktualizacje automatyczne. Dzięki temu przycisk w dodatku
 * nigdy nie powie czegoś innego niż sam program.
 */
async function serverVersion() {
  // Parametr z czasem: bez niego dostalibyśmy plik z pamięci podręcznej.
  const url = UPDATE_BASE + UPDATE_MANIFEST_PATH + '?t=' + Date.now()

  let res
  try {
    res = await fetch(url, { headers: { Accept: 'application/json' }, cache: 'no-store' })
  } catch (e) {
    throw new ApiError(0, 'Brak połączenia z ' + UPDATE_BASE + UPDATE_MANIFEST_PATH)
  }
  if (!res.ok) {
    throw new ApiError(res.status, 'Serwer nie podał wersji dodatku (' + res.status + ').')
  }

  let data
  try {
    data = await res.json()
  } catch (e) {
    throw new ApiError(0, 'Plik z wersją dodatku jest nieczytelny.')
  }

  const entry = data && data.addons && typeof data.addons === 'object' ? data.addons[ADDON_ID] : null
  const updates = entry && Array.isArray(entry.updates) ? entry.updates : []

  // Zwykle jest jedna pozycja, ale gdyby było ich więcej — bierzemy najnowszą.
  let best = null
  for (const row of updates) {
    const version = row && typeof row.version === 'string' ? row.version : ''
    if (version === '') continue
    if (best === null || compareVersions(version, best.version) > 0) {
      best = { version, link: String(row.update_link || '') }
    }
  }

  if (best === null) {
    throw new ApiError(0, 'Serwer nie ma informacji o wersji tego dodatku.')
  }

  return best
}

/**
 * Stan aktualizacji: co jest zainstalowane, co leży na serwerze i czy warto
 * ruszyć palcem. Wynik zapisujemy, żeby okienko nad mailem mogło o nowej
 * wersji powiedzieć bez ponownego pytania serwera.
 */
async function checkUpdate() {
  const installed = addonVersion()
  const latest = await serverVersion()
  const state = {
    installed,
    version: latest.version,
    link: latest.link,
    newer: installed !== '' && compareVersions(latest.version, installed) > 0,
    checkedAt: Date.now(),
  }

  await browser.storage.local.set({ update: state })

  return state
}

/** Ostatnio sprawdzony stan aktualizacji; null, gdy jeszcze nie sprawdzaliśmy. */
async function lastUpdateCheck() {
  const { update } = await browser.storage.local.get({ update: null })
  if (!update || typeof update !== 'object') return null

  // Po samej aktualizacji zapis jest już nieaktualny — wersja się zmieniła.
  const installed = addonVersion()
  if (installed !== '' && compareVersions(installed, update.version) >= 0) return null

  return update
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
async function composeDetailsWhenReady(tabId, tries = 30, everyMs = 100) {
  let previous = null
  let empty = 0
  for (let i = 0; i < tries; i += 1) {
    const details = await browser.compose.getComposeDetails(tabId)
    const body = details.isPlainText ? details.plainTextBody : details.body
    const current = String(body || '')
    if (current.trim() !== '' && current === previous) {
      return details
    }
    // Odpowiedź bez cytatu nigdy nie przestanie być pusta, a czekanie na nią
    // kosztowało handlowca trzy sekundy przy każdym liście.
    empty = current.trim() === '' ? empty + 1 : 0
    if (empty >= 4) {
      return details
    }
    previous = current
    await sleep(everyMs)
  }

  return browser.compose.getComposeDetails(tabId)
}

/* -------------------- powiązanie okna odpowiedzi -------------------- */

async function rememberComposeTab(tabId, inquiryId, headerMessageId = null) {
  const { composeTabs } = await browser.storage.local.get({ composeTabs: {} })
  // Message-ID oryginału zapamiętujemy po to, żeby po wysłaniu odpowiedzi od
  // razu przestawić znacznik maila na „Wysłane”.
  composeTabs[String(tabId)] = { inquiryId, headerMessageId }
  await browser.storage.local.set({ composeTabs })
}

/** @return {{inquiryId: number, headerMessageId: string|null}|null} */
async function takeComposeTab(tabId) {
  const { composeTabs } = await browser.storage.local.get({ composeTabs: {} })
  const key = String(tabId)
  const entry = composeTabs[key]
  if (entry === undefined) return null
  delete composeTabs[key]
  await browser.storage.local.set({ composeTabs })

  // Wpis z wersji 1.4.0 i starszych trzymał sam numer zapytania.
  return typeof entry === 'object' && entry !== null
    ? { inquiryId: entry.inquiryId, headerMessageId: entry.headerMessageId || null }
    : { inquiryId: entry, headerMessageId: null }
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

/* ------------- ten sam mail u kilku handlowców (odpowiedź 409) ------------- */

/** „17.09.2026 08:15” — czas lokalny, bo tak go czyta handlowiec. */
function formatDateTime(value) {
  const iso = toIsoDate(value)
  if (iso === null) return ''

  const date = new Date(iso)
  const pad = (number) => String(number).padStart(2, '0')

  return pad(date.getDate()) + '.' + pad(date.getMonth() + 1) + '.' + date.getFullYear()
    + ' ' + pad(date.getHours()) + ':' + pad(date.getMinutes())
}

/** Imię i nazwisko osoby, która prowadzi zapytanie; bez zgadywania, gdy go brak. */
function duplicateOwner(duplicate) {
  const name = duplicate && duplicate.user ? String(duplicate.user.name || '').trim() : ''

  return name === '' ? 'inna osoba' : name
}

/**
 * Komunikat powiadomienia po odpowiedzi 409: kto prowadzi zapytanie i od kiedy.
 * Wysłana już odpowiedź to najważniejsza informacja — grozi drugą ofertą
 * u tego samego klienta, więc mówimy o niej wprost.
 */
function duplicateNotice(duplicate) {
  const when = formatDateTime(duplicate && duplicate.created_at)
  let text = 'Tym zapytaniem zajmuje się już ' + duplicateOwner(duplicate)
  text += when === '' ? '.' : ' (od ' + when + ').'
  if (duplicate && duplicate.replied_at) {
    text += ' Odpowiedź do klienta już poszła — nie wysyłaj drugiej oferty.'
  }

  return text
}

/** Skąd wiemy, że to ten sam mail — `message_id` albo ta sama treść. */
function duplicateMatchNote(duplicate) {
  return duplicate && duplicate.match === 'fingerprint'
    ? 'Rozpoznane po treści — ten sam mail dotarł do was osobno.'
    : 'Rozpoznane po identyfikatorze wiadomości — to ten sam mail.'
}

/** Zdanie o odpowiedzi tamtej osoby albo pusty łańcuch, gdy jej jeszcze nie ma. */
function duplicateRepliedNote(duplicate) {
  if (!duplicate || !duplicate.replied_at) return ''

  const when = formatDateTime(duplicate.replied_at)
  const owner = duplicateOwner(duplicate)

  return owner + ' wysłał(a) już odpowiedź do klienta'
    + (when === '' ? '' : ' (' + when + ')')
    + ' — druga oferta od nas byłaby błędem.'
}

/** Z odpowiedzi 409 bierzemy tylko pola z kontraktu; resztę pomijamy. */
function duplicateFromError(error) {
  const data = error && error.status === 409 ? error.data : null
  const duplicate = data && typeof data === 'object' ? data.duplicate : null
  if (!duplicate || typeof duplicate !== 'object' || duplicate.id === undefined) return null

  return {
    id: duplicate.id,
    user: duplicate.user && typeof duplicate.user === 'object'
      ? { id: duplicate.user.id, name: String(duplicate.user.name || '') }
      : null,
    created_at: duplicate.created_at || null,
    source_subject: duplicate.source_subject || null,
    replied_at: duplicate.replied_at || null,
    match: duplicate.match || null,
  }
}

/* ------------------------- stan analizy dla maila ------------------------- */

/** Po tylu minutach uznajemy, że analiza przepadła, i pozwalamy spróbować ponownie. */
const PENDING_TIMEOUT_MIN = 15

/**
 * Wpis z duplikatem czeka na decyzję człowieka, więc nie przedawnia się razem
 * z zawieszoną analizą — ostrzeżenie „tym zajmuje się już ktoś inny” ma przetrwać
 * zamknięcie okienka i restart Thunderbirda. Znika dopiero po „Anuluj” albo
 * po faktycznym założeniu zapytania.
 */
function pendingStale(entry, now = Date.now()) {
  if (!entry) return true
  if (entry.duplicate) return false

  return now - (entry.startedAt || 0) > PENDING_TIMEOUT_MIN * 60 * 1000
}

async function getPending() {
  const { pending } = await browser.storage.local.get({ pending: {} })
  const now = Date.now()
  let changed = false
  for (const [key, entry] of Object.entries(pending)) {
    if (pendingStale(entry, now)) {
      delete pending[key]
      changed = true
    }
  }
  if (changed) await browser.storage.local.set({ pending })

  return pending
}

async function setPending(messageId, entry) {
  const pending = await getPending()
  if (entry === null) delete pending[messageId]
  else pending[messageId] = entry
  await browser.storage.local.set({ pending })
}

async function pendingFor(messageId) {
  if (!messageId) return null
  const pending = await getPending()
  const entry = pending[messageId]

  return entry === undefined ? null : entry
}
