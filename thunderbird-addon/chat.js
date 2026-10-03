/*
 * Czat firmowy w tle dodatku (od 1.32.0): przestrzeń „Czat Supon” na pionowym pasku Thunderbirda, liczba
 * nieprzeczytanych na jej przycisku, powiadomienia o nowych wiadomościach i połączenie na żywo z serwerem
 * (Reverb, protokół Pushera — vendor/pusher.min.js). Od 1.33.0 także dzwonek rozmów głosowych i wideo
 * (chat.call.ringing / chat.call.updated) — rozmowa sama odbywa się w przeglądarce. Od 1.34.0 przycisk „Czat” na
 * górnym pasku z małym osobnym oknem czatu, miganie przycisku przy nieprzeczytanych, mruganie Thunderbirda na pasku
 * zadań i przenoszenie stron aplikacji otwartych z czatu (rozmowa, zapytanie) do domyślnej przeglądarki.
 *
 * To samo połączenie niesie sygnał `queue.updated`: w kolejce dodatku pojawiła się praca („Zapisz i wyślij”,
 * oferta). Dzięki niemu background.js pyta o kolejkę od razu, a przy działającym połączeniu rzadziej na zapas.
 * Dlatego połączenie zakładamy także wtedy, gdy konto nie ma uprawnienia do czatu — czat jest wtedy wyłączony
 * (bez przestrzeni i powiadomień), a sygnał o kolejce działa.
 *
 * Strona tła w MV2 jest trwała (manifest nie ustawia `persistent: false`), więc połączenie i zegar żyją
 * przez całą sesję Thunderbirda.
 */

/** Nazwa przestrzeni — wolno w niej tylko litery, cyfry i podkreślenie. */
const CHAT_SPACE_NAME = 'supon_czat'

const CHAT_SPACE_TITLE = 'Czat Supon'

const CHAT_ICON = 'icons/chat.svg'

const CHAT_BADGE_COLOR = '#dc2626'

/** Przedrostek identyfikatora powiadomienia — po nim kliknięcie rozpoznaje wiadomość z czatu. */
const CHAT_NOTIFICATION_PREFIX = 'chat-'

/** Bez połączenia na żywo licznik nieprzeczytanych bierzemy z serwera co tyle sekund. */
const CHAT_POLL_SECONDS = 60

/**
 * Przy połączeniu na żywo — zapasowo co tyle sekund: zdarzenie mogło przepaść (serwer wiadomości odpowiedział za
 * wolno, zła konfiguracja), a połączenie dalej wygląda na żywe; licznik stałby do następnej wiadomości.
 */
const CHAT_LIVE_BACKUP_SECONDS = 180

/** Kiedy następne pytanie o licznik: rzadko przy połączeniu na żywo, co minutę bez niego. */
function chatNextUnreadAt() {
  return Date.now() + (chatRealtimeLive() ? CHAT_LIVE_BACKUP_SECONDS : CHAT_POLL_SECONDS) * 1000
}

/** Takt zegara czatu; o pytaniach decydują bramki czasu niżej. */
const CHAT_TICK_SECONDS = 15

/** Błąd sieci albo serwera przy starcie — ponawiamy po tylu sekundach. */
const CHAT_RETRY_SECONDS = 60

/** Serwer odrzucił klucz (401) — do ponownego „Połącz” pytamy co 5 minut, bez powiadomień. */
const CHAT_REJECTED_SECONDS = 300

/** Brak uprawnienia do czatu (403) albo serwer bez czatu — sprawdzamy ponownie po tylu minutach. */
const CHAT_DISABLED_RECHECK_MINUTES = 15

/** Serwer bez połączenia na żywo (`realtime: null`) — pytamy o to ponownie po tylu minutach. */
const CHAT_REALTIME_RECHECK_MINUTES = 15

/** Najdłuższa treść powiadomienia — dłuższą system i tak utnie. */
const CHAT_PREVIEW_CHARS = 300

/** Ikona przycisku „Czat” w drugiej fazie migania (od 1.34.0): ten sam dymek na czerwono. */
const CHAT_ALERT_ICON = 'icons/chat-alert.svg'

/** Drugi kolor migającej plakietki z liczbą (pierwszy: CHAT_BADGE_COLOR). */
const CHAT_BLINK_BADGE_COLOR = '#f59e0b'

/** Zwykły napis na przycisku „Czat” (jak default_label w manifeście). */
const CHAT_ACTION_LABEL = 'Czat'

/** Co tyle milisekund przycisk „Czat” zmienia ikonę, dopóki są nieprzeczytane. */
const CHAT_BLINK_MS = 700

/** Wymiary małego okna czatu (od 1.34.0) — jak okno komunikatora obok poczty. */
const CHAT_WINDOW_WIDTH = 400

const CHAT_WINDOW_HEIGHT = 640

/** Strony czatu w aplikacji — te zostają w Thunderbirdzie; każdą inną stronę aplikacji przenosimy do przeglądarki. */
const CHAT_PAGE_PATHS = ['/czat', '/czat-okno']

/** Przedrostek powiadomienia o dzwoniącej rozmowie (od 1.33.0) — po nim kliknięcie otwiera rozmowę w przeglądarce. */
const CALL_NOTIFICATION_PREFIX = 'call-'

/** Tyle sekund dzwoni rozmowa, jeśli serwer wcześniej nie powie, że już nie trzeba (jak `ring_seconds` w aplikacji). */
const CALL_RING_SECONDS = 45

/** Co tyle sekund dzwonek powtarza krótki dwutonowy sygnał. */
const CALL_BEEP_EVERY_SECONDS = 3

const chatState = {
  /** Numer przebiegu: zmiana klucza albo adresu unieważnia wszystko, co było w drodze. */
  generation: 0,
  /** Start (GET /me, licznik, konfiguracja połączenia) zakończony. */
  ready: false,
  starting: false,
  /** Najwcześniejszy ponowny start (ms), gdy poprzedni się nie udał. */
  retryAt: 0,
  /** true = czat działa, false = brak uprawnienia (403) albo serwer bez czatu, null = jeszcze nie wiadomo. */
  enabled: null,
  /** Po tej chwili sprawdzamy ponownie wyłączony czat. */
  recheckAt: 0,
  userId: null,
  unread: 0,
  nextUnreadAt: 0,
  unreadBusy: false,
  unreadAgain: false,
  /** Typ rozmowy (`channel`/`direct`) po jej numerze — do tytułu powiadomienia. */
  types: new Map(),
  spaceId: null,
  spacePromise: null,
  pusher: null,
  socketState: 'initialized',
  subscribed: false,
  /** Kod ostatniej odmowy autoryzacji kanału (401/403) — pusher-js go nie przekazuje. */
  authStatus: 0,
  /** Po tej chwili ponawiamy konfigurację połączenia na żywo (0 = nie trzeba). */
  realtimeRetryAt: 0,
  restartTimer: null,
  /** Dzwoniące rozmowy po numerze: { stopTimer } — każda ma własne 45 s i milknie niezależnie. */
  calls: new Map(),
  /** Jeden zegar dzwonka dla wszystkich dzwoniących rozmów — dwie naraz nie grają jedna przez drugą. */
  ringTimer: null,
  /** Wspólny AudioContext dzwonka; zakładany przy pierwszym dzwonku, zamykany, gdy nic już nie dzwoni. */
  audio: null,
  /** Małe okno czatu (od 1.34.0): jego numer, gdy jest otwarte, i otwieranie w toku (dwa szybkie kliknięcia = jedno okno). */
  windowId: null,
  windowPromise: null,
  /** Okno czatu ma fokus — wtedy przycisk nie miga, a dymek i mruganie paska zadań są zbędne. */
  windowFocused: false,
  /** Zegar migania przycisku „Czat” i jego bieżąca faza (true = czerwona ikona). */
  blinkTimer: null,
  blinkOn: false,
  /** Ustawienie „Migająca ikona czatu” (domyślnie włączone). */
  blinkSetting: true,
  /** Karty ze stroną aplikacji, które właśnie przenosimy do przeglądarki — jedno przeniesienie na kartę. */
  redirecting: new Set(),
  /** Karty, w których była strona czatu (/czat, /czat-okno) — tych nie przenosimy, nawet gdy przejdą dalej. */
  chatTabs: new Set(),
}

/** Połączenie na żywo działa i kanał użytkownika jest zasubskrybowany — sygnały dotrą bez pytania serwera. */
function chatRealtimeLive() {
  return chatState.pusher !== null && chatState.socketState === 'connected' && chatState.subscribed
}

function chatBadgeText(count) {
  const number = Number(count) || 0
  if (number <= 0) return ''

  return number > 99 ? '99+' : String(number)
}

/**
 * Adres czatu w karcie Thunderbirda, z kluczem dodatku w końcówce `#tb=…`. Karta Thunderbirda nie pozwala stronie
 * zapisać logowania (localStorage: „The operation is insecure”), więc bez tego trzeba by logować się przy każdym
 * otwarciu. Część po `#` nie idzie na serwer ani do dzienników, a strona zaraz usuwa ją z adresu i trzyma klucz
 * tylko w pamięci (frontend/src/lib/tokenStore.ts).
 */
async function chatPageUrl(conversationId = null, withToken = true, page = '/czat') {
  const { baseUrl, token } = await getSettings()
  const id = Number.parseInt(String(conversationId ?? ''), 10)
  const query = Number.isFinite(id) && id > 0 ? '?c=' + id : ''

  return baseUrl + page + query + (withToken && token ? '#tb=' + encodeURIComponent(token) : '')
}

/** Adres małego okna czatu (od 1.34.0): `/czat-okno` — ten sam czat w wąskim układzie, bez menu aplikacji. */
async function chatWindowUrl(conversationId = null) {
  return chatPageUrl(conversationId, true, '/czat-okno')
}

async function chatHomeUrl() {
  return chatPageUrl(null)
}

function chatButtonProperties() {
  return {
    title: CHAT_SPACE_TITLE,
    defaultIcons: CHAT_ICON,
    badgeText: chatBadgeText(chatState.unread),
    badgeBackgroundColor: CHAT_BADGE_COLOR,
  }
}

/* ------------------------------ przestrzeń ------------------------------ */

/**
 * Przestrzeń „Czat Supon” (ikona na pionowym pasku). Po restarcie Thunderbirda albo przeładowaniu dodatku
 * może już istnieć — wtedy ją przejmujemy, a nie zakładamy drugi raz (ta sama nazwa = wyjątek).
 * Drugi argument `spaces.update` zawsze jako adres (łańcuch): tak samo rozumieją go Thunderbird 115 i nowsze.
 */
async function chatEnsureSpace() {
  if (!browser.spaces || chatState.enabled === false) return null
  if (chatState.spaceId !== null) return chatState.spaceId
  if (chatState.spacePromise !== null) return chatState.spacePromise

  chatState.spacePromise = (async () => {
    const home = await chatHomeUrl()
    let found = []
    try {
      found = await browser.spaces.query({ name: CHAT_SPACE_NAME, isSelfOwned: true })
    } catch (e) {
      found = []
    }
    let space = Array.isArray(found) && found.length > 0 ? found[0] : null
    if (space !== null) {
      await browser.spaces.update(space.id, home, chatButtonProperties())
    } else {
      space = await browser.spaces.create(CHAT_SPACE_NAME, home, chatButtonProperties())
    }
    chatState.spaceId = space.id

    return space.id
  })()
    .catch((e) => {
      console.warn('Supon: nie udało się założyć przestrzeni czatu:', e.message)

      return null
    })
    .finally(() => {
      chatState.spacePromise = null
    })

  return chatState.spacePromise
}

/** Bez uprawnienia do czatu przestrzeni nie ma — także tej z poprzedniej sesji. */
async function chatRemoveSpace() {
  if (!browser.spaces) return
  try {
    const found = await browser.spaces.query({ name: CHAT_SPACE_NAME, isSelfOwned: true })
    for (const space of Array.isArray(found) ? found : []) {
      await browser.spaces.remove(space.id)
    }
  } catch (e) {
    console.warn('Supon: nie udało się usunąć przestrzeni czatu:', e.message)
  }
  chatState.spaceId = null
}

async function chatPaintBadge() {
  if (chatState.spaceId === null) return
  try {
    await browser.spaces.update(chatState.spaceId, await chatHomeUrl(), chatButtonProperties())
  } catch (e) {
    // Przestrzeń zniknęła (np. przeładowanie dodatku) — przy następnej okazji założymy ją od nowa.
    chatState.spaceId = null
    console.warn('Supon: nie udało się odświeżyć licznika czatu:', e.message)
  }
}

function chatSetUnread(count) {
  const number = Math.max(0, Number.parseInt(String(count ?? 0), 10) || 0)
  const changed = number !== chatState.unread
  chatState.unread = number
  if (changed) {
    chatPaintBadge()
    chatPaintActionBadge()
  }
  chatSyncBlink()
}

/* ------------------------- przycisk „Czat” i małe okno (od 1.34.0) ------------------------- */

/*
 * Przycisk „Czat” na górnym pasku Thunderbirda (browser_action, bez okienka — kliknięcie to onClicked) otwiera
 * małe osobne okno czatu, a drugie kliknięcie przywołuje to samo okno. Przestrzeń na lewym pasku zostaje jako
 * druga droga wejścia. Dopóki są nieprzeczytane, a okno czatu nie ma fokusu, przycisk miga na czerwono.
 */

/** Liczba nieprzeczytanych na przycisku „Czat” — ta sama co na przestrzeni. */
function chatPaintActionBadge() {
  if (!browser.browserAction) return
  const text = chatBadgeText(chatState.unread)
  Promise.resolve()
    .then(() => browser.browserAction.setBadgeText({ text }))
    .then(() => browser.browserAction.setTitle({ title: text === '' ? 'Czat' : 'Czat — nieprzeczytane: ' + text }))
    .catch((e) => console.warn('Supon: nie udało się odświeżyć licznika na przycisku czatu:', e.message))
}

function chatSetActionIcon(path) {
  if (!browser.browserAction) return
  Promise.resolve()
    .then(() => browser.browserAction.setIcon({ path }))
    .catch((e) => console.warn('Supon: nie udało się zmienić ikony przycisku czatu:', e.message))
}

/**
 * Kolor plakietki z liczbą: przy miganiu naprzemiennie czerwony i pomarańczowy. Plakietka jest widoczna zawsze
 * (licznik działał, gdy sama podmiana ikony na pasku Thunderbirda nie była widać — 03.10.2026), więc miga i ona.
 */
function chatSetActionBadgeColor(color) {
  if (!browser.browserAction) return
  Promise.resolve()
    .then(() => browser.browserAction.setBadgeBackgroundColor({ color }))
    .catch(() => {})
}

/**
 * Napis na przycisku: przy miganiu naprzemiennie „Czat” i „Nowa wiadomość” — sama plakietka z liczbą była za mała,
 * żeby ją zauważyć (03.10.2026). Napis to największy element przycisku na pasku Thunderbirda.
 */
function chatSetActionLabel(label) {
  if (!browser.browserAction || typeof browser.browserAction.setLabel !== 'function') return
  Promise.resolve()
    .then(() => browser.browserAction.setLabel({ label }))
    .catch(() => {})
}

function chatUnreadLabel(count) {
  const n = Number(count) || 0
  if (n <= 1) return 'Nowa wiadomość'

  return 'Nowe wiadomości (' + (n > 99 ? '99+' : n) + ')'
}

function chatBlinkStep() {
  chatState.blinkOn = !chatState.blinkOn
  chatSetActionIcon(chatState.blinkOn ? CHAT_ALERT_ICON : CHAT_ICON)
  chatSetActionBadgeColor(chatState.blinkOn ? CHAT_BLINK_BADGE_COLOR : CHAT_BADGE_COLOR)
  chatSetActionLabel(chatState.blinkOn ? chatUnreadLabel(chatState.unread) : CHAT_ACTION_LABEL)
}

/**
 * Miganie przycisku: tylko przy włączonym czacie i ustawieniu, gdy są nieprzeczytane, a okno czatu nie ma fokusu.
 * Jeden zegar; po zatrzymaniu ikona zawsze wraca do zwykłej.
 */
function chatSyncBlink() {
  const blink = chatState.blinkSetting && chatState.enabled === true && chatState.unread > 0 && !chatState.windowFocused
  if (blink) {
    if (chatState.blinkTimer === null) {
      chatState.blinkTimer = setInterval(chatBlinkStep, CHAT_BLINK_MS)
      chatBlinkStep()
    }

    return
  }
  if (chatState.blinkTimer !== null) {
    clearInterval(chatState.blinkTimer)
    chatState.blinkTimer = null
  }
  if (chatState.blinkOn) {
    chatState.blinkOn = false
    chatSetActionIcon(CHAT_ICON)
    chatSetActionBadgeColor(CHAT_BADGE_COLOR)
    chatSetActionLabel(CHAT_ACTION_LABEL)
  }
}

/**
 * Otwiera małe okno czatu, a gdy już jest — przywołuje je na wierzch (z numerem rozmowy: przechodzi do niej).
 * Bez połączenia z aplikacją otwiera ustawienia dodatku; gdy Thunderbird nie da okna — czat w przestrzeni.
 */
async function chatOpenWindow(conversationId = null) {
  const { token } = await getSettings()
  if (!token) {
    await browser.runtime.openOptionsPage()

    return
  }
  if (chatState.windowPromise !== null) {
    // Drugie kliknięcie, zanim pierwsze otworzyło okno — czekamy na tamto zamiast otwierać drugie.
    await chatState.windowPromise.catch(() => {})
    if (conversationId === null && chatState.windowId !== null) return
  }

  if (chatState.windowId !== null) {
    const windowId = chatState.windowId
    try {
      await browser.windows.update(windowId, { focused: true })
    } catch (e) {
      // Okno zniknęło bez zdarzenia — otwieramy nowe niżej.
      if (chatState.windowId === windowId) chatState.windowId = null
    }
    if (chatState.windowId === windowId) {
      if (conversationId !== null) await chatWindowGoTo(windowId, conversationId)

      return
    }
  }

  chatState.windowPromise = (async () => {
    const created = await browser.windows.create({
      type: 'popup',
      url: await chatWindowUrl(conversationId),
      width: CHAT_WINDOW_WIDTH,
      height: CHAT_WINDOW_HEIGHT,
    })
    chatState.windowId = created && created.id !== undefined ? created.id : null
    // Nowe okno dostaje fokus; zdarzenie onFocusChanged to potwierdzi albo poprawi.
    chatState.windowFocused = chatState.windowId !== null && (!created || created.focused !== false)
    chatSyncBlink()
  })()
  try {
    await chatState.windowPromise
  } catch (e) {
    console.warn('Supon: nie udało się otworzyć okna czatu, otwieram czat w karcie:', e.message)
    await chatOpen(conversationId)
  } finally {
    chatState.windowPromise = null
  }
}

/** Otwarte okno czatu przechodzi do wskazanej rozmowy (`/czat-okno?c=…`). */
async function chatWindowGoTo(windowId, conversationId) {
  try {
    const win = await browser.windows.get(windowId, { populate: true })
    const tab = win && Array.isArray(win.tabs) ? win.tabs[0] : null
    if (tab && tab.id !== undefined) await browser.tabs.update(tab.id, { url: await chatWindowUrl(conversationId) })
  } catch (e) {
    // Okno zostaje na wierzchu z tym, co pokazywało — rozmowę wybierze się z listy.
    console.warn('Supon: nie udało się przejść do rozmowy w oknie czatu:', e.message)
  }
}

/**
 * Karta albo okno, które dodatek sam otworzył dla czatu: małe okno czatu albo karta przestrzeni. Tych nie ruszamy,
 * nawet gdy strona przejdzie w nich pod inny adres aplikacji.
 */
function chatOwnTab(tab) {
  return (chatState.windowId !== null && tab.windowId === chatState.windowId)
    || (chatState.spaceId !== null && tab.spaceId === chatState.spaceId)
    || chatState.chatTabs.has(tab.id)
}

/**
 * Adres strony aplikacji do otwarcia w przeglądarce (bez końcówki `#…`) albo null. Strony czatu (`/czat`,
 * `/czat-okno` z dowolnym `?…`) zostają w Thunderbirdzie; każda inna strona aplikacji — w tym rozmowa
 * `/czat/rozmowa/{id}` i np. `/inquiries/91` — w Thunderbirdzie nie ma logowania, a rozmowa nie ma tam
 * mikrofonu i kamery.
 */
function chatAppUrlForBrowser(url, baseUrl) {
  if (typeof url !== 'string' || !url.startsWith(baseUrl + '/')) return null
  const bare = url.split('#')[0]
  const path = bare.slice(baseUrl.length).split('?')[0].replace(/\/+$/, '')
  if (CHAT_PAGE_PATHS.includes(path)) return null

  return bare
}

/**
 * Strona czatu otworzyła nową kartę albo okno Thunderbirda ze stroną aplikacji (window.open: Zadzwoń, Wideo,
 * Dołącz, „Otwórz” zapytanie) — zamykamy je i otwieramy ten adres w domyślnej przeglądarce. Karta zwykle startuje
 * jako about:blank i dopiero potem dostaje adres, dlatego patrzymy na każdą zmianę adresu (tabs.onUpdated).
 * Filtra `urls` w tabs.onUpdated nie używamy: wymaga uprawnienia „tabs”, którego dodatek nie ma (nowa zgoda
 * zatrzymałaby cichą aktualizację). Adres karty widać dzięki uprawnieniu do domeny aplikacji.
 */
async function chatRedirectTab(tab, url) {
  if (!tab || tab.id === undefined || typeof url !== 'string' || !url.startsWith('http')) return
  if (chatState.redirecting.has(tab.id) || chatOwnTab(tab)) return
  const { baseUrl } = await getSettings()
  const target = chatAppUrlForBrowser(url, baseUrl)
  if (target === null) {
    // Karta ze stroną czatu zostaje czatem także po przejściu w niej do innej strony aplikacji (menu w /czat).
    if (url.startsWith(baseUrl + '/')) chatState.chatTabs.add(tab.id)

    return
  }
  if (chatState.redirecting.has(tab.id) || chatOwnTab(tab)) return

  // Do zamknięcia karty (tabs.onRemoved) — kolejne zdarzenia tej samej karty nie otworzą przeglądarki drugi raz.
  chatState.redirecting.add(tab.id)
  try {
    await browser.windows.openDefaultBrowser(target)
  } catch (e) {
    console.warn('Supon: nie udało się otworzyć strony w przeglądarce:', e.message)
  }
  try {
    await browser.tabs.remove(tab.id)
  } catch (e) {
    // Osobne okno, którego karty Thunderbird nie zamyka — zamykamy całe okno (nigdy główne ani okno czatu).
    try {
      const win = await browser.windows.get(tab.windowId)
      if (win && win.type === 'popup' && win.id !== chatState.windowId) await browser.windows.remove(win.id)
    } catch (e2) {
      console.warn('Supon: nie udało się zamknąć karty ze stroną aplikacji:', e2.message)
    }
  }
}

/**
 * Sygnał 3 (od 1.34.0): przycisk Thunderbirda mruga na pasku zadań, gdy żadne jego okno nie ma fokusu.
 * Mruga główne okno poczty (ostatnio używane okno typu „normal”).
 */
async function chatDrawAttention() {
  if (chatState.windowFocused) return
  try {
    const last = await browser.windows.getLastFocused()
    if (!last || last.focused) return
    let target = last.type === 'normal' ? last : null
    if (target === null) {
      const all = await browser.windows.getAll()
      target = (Array.isArray(all) ? all : []).find((w) => w.type === 'normal') || null
    }
    if (target !== null) await browser.windows.update(target.id, { drawAttention: true })
  } catch (e) {
    console.warn('Supon: nie udało się zwrócić uwagi na Thunderbirda:', e.message)
  }
}

/**
 * Otwiera czat w Thunderbirdzie (przestrzeń), a z numerem rozmowy — od razu tę rozmowę (`/czat?c=…`).
 * Zamknięta karta czatu otwiera się pod adresem przestrzeni, więc na tę jedną chwilę podmieniamy go na adres
 * rozmowy i zaraz przywracamy. Otwartą kartę tylko przełączamy i przenosimy do rozmowy.
 */
async function chatOpen(conversationId = null) {
  const home = await chatHomeUrl()
  const target = await chatPageUrl(conversationId)

  const spaceId = await chatEnsureSpace()
  if (spaceId === null) {
    // zwykła przeglądarka ma własne logowanie — klucz dodatku nie może trafić do jej historii
    await browser.windows.openDefaultBrowser(await chatPageUrl(conversationId, false))

    return
  }

  let existing = null
  try {
    existing = (await browser.tabs.query({})).find((tab) => tab.spaceId === spaceId) || null
  } catch (e) {
    existing = null
  }

  if (existing === null && target !== home) {
    await browser.spaces.update(spaceId, target, chatButtonProperties())
    try {
      await browser.spaces.open(spaceId)
    } finally {
      await browser.spaces.update(spaceId, home, chatButtonProperties())
    }

    return
  }

  const tab = await browser.spaces.open(spaceId)
  if (target !== home && tab && tab.id !== undefined) {
    try {
      await browser.tabs.update(tab.id, { url: target })
    } catch (e) {
      // Thunderbird nie przeniósł karty — zostaje otwarty czat, rozmowę wybierze się z listy.
      console.warn('Supon: nie udało się przejść do rozmowy w czacie:', e.message)
    }
  }
}

/** Czy człowiek patrzy właśnie na czat — wtedy powiadomienie byłoby zbędne. */
async function chatInFront() {
  if (chatState.windowFocused) return true
  try {
    // adres bez klucza — strona usuwa końcówkę #tb=… zaraz po wczytaniu
    const home = await chatPageUrl(null, false)
    const tabs = await browser.tabs.query({ active: true, lastFocusedWindow: true })

    return tabs.some((tab) => (chatState.spaceId !== null && tab.spaceId === chatState.spaceId)
      || (typeof tab.url === 'string' && (tab.url === home || tab.url.startsWith(home + '?') || tab.url.startsWith(home + '/'))))
  } catch (e) {
    return false
  }
}

/* ------------------------------ licznik ------------------------------ */

/** Czat działa — przestrzeń pojawia się na pasku. */
function chatEnable() {
  if (chatState.enabled === true) return
  chatState.enabled = true
  chatEnsureSpace()
  chatSetActionEnabled(true)
  chatSyncBlink()
}

/** Bez uprawnienia do czatu przycisk „Czat” jest wyszarzony (dodatek nie może go schować). */
function chatSetActionEnabled(on) {
  if (!browser.browserAction) return
  Promise.resolve()
    .then(() => (on ? browser.browserAction.enable() : browser.browserAction.disable()))
    .catch(() => {})
}

/** Brak uprawnienia (403) albo serwer bez czatu (404): bez przestrzeni, licznika i powiadomień. */
async function chatDisable() {
  chatState.enabled = false
  chatState.unread = 0
  chatPaintActionBadge()
  chatSyncBlink()
  chatSetActionEnabled(false)
  chatCallStopAll()
  chatState.recheckAt = Date.now() + CHAT_DISABLED_RECHECK_MINUTES * 60 * 1000
  await chatRemoveSpace()
}

/**
 * Błąd pytania o czat. 401 = klucz odrzucony: cisza do zmiany klucza albo 5 minut (o kluczu mówi już kolejka
 * i ustawienia — bez powtarzania tego co minutę). Brak sieci czy błąd serwera — zwykłe ponowienie.
 */
async function chatHandleError(error) {
  const status = error && error.status
  if (status === 403 || status === 404) {
    await chatDisable()

    return
  }
  const seconds = status === 401 ? CHAT_REJECTED_SECONDS : CHAT_POLL_SECONDS
  chatState.nextUnreadAt = Date.now() + seconds * 1000
}

/** Liczba nieprzeczytanych z serwera; równoczesne prośby łączą się w jedno pytanie. */
async function chatRefreshUnread() {
  if (chatState.unreadBusy) {
    chatState.unreadAgain = true

    return
  }
  chatState.unreadBusy = true
  const generation = chatState.generation
  try {
    const data = await api('/api/chat/unread')
    if (generation !== chatState.generation) return
    chatState.nextUnreadAt = chatNextUnreadAt()
    chatEnable()
    chatSetUnread(data && data.unread_total)
  } catch (e) {
    if (generation === chatState.generation) await chatHandleError(e)
  } finally {
    chatState.unreadBusy = false
    if (chatState.unreadAgain) {
      chatState.unreadAgain = false
      chatRefreshUnread()
    }
  }
}

/** Lista rozmów: typy do tytułów powiadomień i przy okazji liczba nieprzeczytanych. */
async function chatLoadConversations() {
  const generation = chatState.generation
  const data = await api('/api/chat/conversations')
  if (generation !== chatState.generation) return
  for (const row of data && Array.isArray(data.data) ? data.data : []) {
    if (row && row.id !== undefined) chatState.types.set(Number(row.id), String(row.type || ''))
  }
  chatState.nextUnreadAt = chatNextUnreadAt()
  chatEnable()
  if (data && data.unread_total !== undefined) chatSetUnread(data.unread_total)
}

/**
 * Kliknięcie „Czat”: gdy nieprzeczytana jest dokładnie jedna rozmowa — od razu ta rozmowa (prośba właściciela
 * 03.10.2026), przy kilku albo żadnej — lista. Bez licznika nie pytamy serwera (okno otwiera się bez zwłoki); błąd
 * zapytania też otwiera listę.
 */
async function chatSingleUnreadConversation() {
  if (chatState.unread <= 0) return null
  try {
    const data = await api('/api/chat/conversations')
    const unread = (data && Array.isArray(data.data) ? data.data : []).filter((row) => row && Number(row.unread) > 0)

    return unread.length === 1 ? Number(unread[0].id) : null
  } catch (e) {
    return null
  }
}

/* ------------------------------ zdarzenia ------------------------------ */

function chatNotificationText(data) {
  const preview = String(data.preview || '').replace(/\s+/g, ' ').trim().slice(0, CHAT_PREVIEW_CHARS)
  if (data.kind === 'mail') return 'Przekazany mail: ' + (preview === '' ? '(bez tematu)' : preview)
  if (preview === '') return 'Nowa wiadomość'

  return preview
}

function chatNotificationTitle(data) {
  const sender = data.user && String(data.user.name || '').trim() !== ''
    ? String(data.user.name).trim()
    : data.kind === 'system' ? 'Czat' : 'Konto usunięte'
  const conversation = String(data.conversation_name || '').trim()
  if (chatState.types.get(Number(data.conversation_id)) === 'channel' && conversation !== '') {
    return sender + ' — kanał ' + conversation
  }

  return sender
}

/**
 * Nowa wiadomość. Od innej osoby: nowy licznik i powiadomienie (chyba że czat jest właśnie na wierzchu).
 * Własna (z innego komputera) licznika nie zmienia. Treści nie ma w zdarzeniu — tylko zapowiedź.
 */
async function chatOnMessage(data) {
  if (!data || typeof data !== 'object' || chatState.enabled === false) return
  const fromOther = !data.user || Number(data.user.id) !== Number(chatState.userId)
  if (!fromOther) return

  const conversationId = Number(data.conversation_id)
  if (!chatState.types.has(conversationId)) {
    try {
      await chatLoadConversations()
    } catch (e) {
      await chatHandleError(e)
      if (chatState.enabled === false) return
      chatRefreshUnread()
    }
  } else {
    chatRefreshUnread()
  }

  // Wpis o rozmowie (kind=call) tylko odświeża licznik — dzwoni osobne zdarzenie chat.call.ringing.
  if (data.kind === 'call') return
  if (chatState.enabled !== true) return
  // Sygnał 3: Thunderbird schowany albo zminimalizowany — jego przycisk mruga na pasku zadań.
  await chatDrawAttention()
  if (await chatInFront()) return

  try {
    await browser.notifications.create(
      CHAT_NOTIFICATION_PREFIX + conversationId + '-' + Number(data.message_id),
      {
        type: 'basic',
        iconUrl: browser.runtime.getURL(CHAT_ICON),
        title: chatNotificationTitle(data),
        message: chatNotificationText(data),
      },
    )
  } catch (e) {
    console.warn('Supon: powiadomienie czatu się nie pokazało:', e.message)
  }
}

/** Ktoś (ja, na dowolnym urządzeniu) przeczytał rozmowę — serwer podaje nowy licznik. */
function chatOnRead(data) {
  if (!data || typeof data !== 'object' || chatState.enabled === false) return
  if (data.unread_total !== undefined) chatSetUnread(data.unread_total)
}

/** W kolejce dodatku jest praca — pytamy o nią od razu (background.js). */
function chatOnQueue() {
  if (typeof queueSignal === 'function') queueSignal()
}

/* ------------------------------ rozmowy (dzwonek) ------------------------------ */

/*
 * Od 1.33.0: rozmowa głosowa albo wideo z czatu. Sama rozmowa odbywa się w przeglądarce (Chrome/Edge) — dodatek
 * tylko dzwoni: powiadomienie i dźwięk, a kliknięcie otwiera stronę rozmowy. Dzwonek milknie, gdy serwer powie,
 * że rozmowa już nie dzwoni (chat.call.updated: status ≠ ringing albo odebrałem / odrzuciłem na innym urządzeniu),
 * po kliknięciu powiadomienia albo po 45 s.
 */

/** Numer rozmowy z danych zdarzenia albo identyfikatora powiadomienia; cokolwiek innego = null. */
function chatCallId(value) {
  const text = String(value ?? '')
  if (!/^\d+$/.test(text)) return null
  const id = Number(text)

  return id > 0 ? id : null
}

function chatCallTitle(data) {
  const conversation = String(data.conversation_name || '').trim()
  if (chatState.types.get(Number(data.conversation_id)) === 'channel' && conversation !== '') {
    return 'Rozmowa w kanale ' + conversation
  }
  const caller = data.started_by && typeof data.started_by === 'object' ? String(data.started_by.name || '').trim() : ''
  if (caller !== '') return 'Dzwoni ' + caller
  // W rozmowie 1:1 nazwą rozmowy jest imię dzwoniącego.
  if (conversation !== '') return 'Dzwoni ' + conversation

  return 'Ktoś dzwoni'
}

function chatCallText(data) {
  return (data.kind === 'video' ? 'Rozmowa wideo' : 'Rozmowa głosowa') + ' — kliknij, aby odebrać w przeglądarce.'
}

/**
 * Jeden krótki dwutonowy sygnał przez WebAudio. Bez AudioContext albo z zawieszonym (blokada odtwarzania bez
 * kliknięcia) — cisza, zostaje samo powiadomienie; zawieszony prosimy o wznowienie, więc może zagrać następny sygnał.
 */
function chatCallBeep() {
  if (chatState.audio === null) {
    if (typeof AudioContext !== 'function') return
    try {
      chatState.audio = new AudioContext()
    } catch (e) {
      return
    }
  }
  const audio = chatState.audio
  if (audio.state !== 'running') {
    if (audio.state === 'suspended') {
      try {
        Promise.resolve(audio.resume()).catch(() => {})
      } catch (e) {
        // zostaje samo powiadomienie
      }
    }

    return
  }
  try {
    const start = audio.currentTime
    for (const [offset, frequency] of [[0, 880], [0.45, 660]]) {
      const oscillator = audio.createOscillator()
      const gain = audio.createGain()
      oscillator.type = 'sine'
      oscillator.frequency.value = frequency
      // Łagodne narastanie i wygaszanie — bez trzasków na początku i końcu tonu.
      gain.gain.setValueAtTime(0, start + offset)
      gain.gain.linearRampToValueAtTime(0.2, start + offset + 0.03)
      gain.gain.linearRampToValueAtTime(0, start + offset + 0.4)
      oscillator.connect(gain)
      gain.connect(audio.destination)
      oscillator.start(start + offset)
      oscillator.stop(start + offset + 0.42)
    }
  } catch (e) {
    console.warn('Supon: dzwonek nie zagrał:', e.message)
  }
}

/** Dzwonek gra, dopóki dzwoni choć jedna rozmowa; gdy żadna — zegar stoi, a AudioContext jest zamknięty. */
function chatCallSyncRinger() {
  if (chatState.calls.size > 0) {
    if (chatState.ringTimer === null) {
      chatCallBeep()
      chatState.ringTimer = setInterval(chatCallBeep, CALL_BEEP_EVERY_SECONDS * 1000)
    }

    return
  }
  if (chatState.ringTimer !== null) {
    clearInterval(chatState.ringTimer)
    chatState.ringTimer = null
  }
  if (chatState.audio !== null) {
    const audio = chatState.audio
    chatState.audio = null
    try {
      Promise.resolve(audio.close()).catch(() => {})
    } catch (e) {
      // i tak go porzucamy
    }
  }
}

/** Rozmowa przestaje dzwonić: jej zegar, powiadomienie i (gdy to była ostatnia) dźwięk. */
function chatCallStop(callId) {
  const call = chatState.calls.get(callId)
  if (call === undefined) return
  clearTimeout(call.stopTimer)
  chatState.calls.delete(callId)
  chatCallSyncRinger()
  Promise.resolve()
    .then(() => browser.notifications.clear(CALL_NOTIFICATION_PREFIX + callId))
    .catch(() => {})
}

function chatCallStopAll() {
  for (const callId of [...chatState.calls.keys()]) chatCallStop(callId)
}

/** Ktoś dzwoni (chat.call.ringing): dźwięk od razu, powiadomienie z tytułem zależnym od typu rozmowy. */
async function chatOnCallRinging(data) {
  if (!data || typeof data !== 'object' || chatState.enabled === false) return
  const callId = chatCallId(data.call_id)
  if (callId === null || chatState.calls.has(callId)) return
  // Serwer nie dzwoni do dzwoniącego — to tylko zabezpieczenie przed dzwonieniem do samego siebie.
  const caller = data.started_by && typeof data.started_by === 'object' ? data.started_by : null
  if (caller !== null && chatState.userId !== null && Number(caller.id) === Number(chatState.userId)) return

  const call = { stopTimer: null }
  call.stopTimer = setTimeout(() => chatCallStop(callId), CALL_RING_SECONDS * 1000)
  chatState.calls.set(callId, call)
  chatCallSyncRinger()

  // Typ rozmowy (kanał czy 1:1) decyduje o tytule; nowej rozmowy jeszcze nie znamy — pytamy o listę.
  if (!chatState.types.has(Number(data.conversation_id))) {
    try {
      await chatLoadConversations()
    } catch (e) {
      // tytuł bez typu rozmowy: „Dzwoni {kto}”
    }
  }
  // W międzyczasie rozmowa mogła przestać dzwonić (albo zmienił się klucz) — wtedy bez powiadomienia.
  if (chatState.calls.get(callId) !== call) return

  const notificationId = CALL_NOTIFICATION_PREFIX + callId
  try {
    await browser.notifications.create(notificationId, {
      type: 'basic',
      iconUrl: browser.runtime.getURL(CHAT_ICON),
      title: chatCallTitle(data),
      message: chatCallText(data),
    })
  } catch (e) {
    console.warn('Supon: powiadomienie o rozmowie się nie pokazało:', e.message)

    return
  }
  // Rozmowa przestała dzwonić, zanim powiadomienie się pokazało — sprzątanie wyprzedziło pokazanie.
  if (chatState.calls.get(callId) !== call) browser.notifications.clear(notificationId).catch(() => {})
}

/** Zmiana stanu rozmowy (chat.call.updated) — reguła końca dzwonienia jak w aplikacji. */
function chatOnCallUpdated(data) {
  if (!data || typeof data !== 'object') return
  const callId = chatCallId(data.call_id)
  if (callId === null || !chatState.calls.has(callId)) return
  if (data.status !== 'ringing' || data.reason === 'declined' || data.reason === 'joined') chatCallStop(callId)
}

/** Kliknięcie powiadomienia o rozmowie: dzwonek milknie, strona rozmowy otwiera się w przeglądarce. */
async function chatCallAnswer(callId) {
  chatCallStop(callId)
  const { baseUrl } = await getSettings()
  await browser.windows.openDefaultBrowser(baseUrl + '/czat/rozmowa/' + callId)
}

/* --------------------------- połączenie na żywo --------------------------- */

/** Autoryzacja kanału prywatnego przez nasze API (Bearer z ustawień dodatku). */
function chatAuthorize(params, callback) {
  api('/api/broadcasting/auth', {
    method: 'POST',
    body: { socket_id: params.socketId, channel_name: params.channelName },
  })
    .then((data) => {
      chatState.authStatus = 0
      callback(null, data)
    })
    .catch((e) => {
      chatState.authStatus = e && e.status ? e.status : 0
      callback(new Error(e && e.message ? e.message : 'Brak autoryzacji kanału'), null)
    })
}

function chatStopRealtime() {
  const pusher = chatState.pusher
  const wasLive = chatRealtimeLive()
  chatState.pusher = null
  chatState.subscribed = false
  chatState.socketState = 'disconnected'
  if (pusher !== null) {
    try {
      pusher.disconnect()
    } catch (e) {
      // i tak go porzucamy
    }
  }
  // Kolejka pytała rzadziej, licząc na sygnał — teraz musi wrócić do zwykłego tempa od razu.
  if (wasLive) chatOnQueue()
}

/**
 * Połączenie z serwerem Reverb na kanale `private-user.{id}`. Zdarzenia z `broadcastAs()` przychodzą w pusher-js
 * pod gołą nazwą (`chat.message`) — kropkę z przodu dokleja tylko Laravel Echo, żeby pominąć przestrzeń nazw.
 */
function chatConnect(config, userId) {
  if (typeof Pusher !== 'function') {
    console.warn('Supon: brak biblioteki połączenia na żywo — czat działa przez odpytywanie.')

    return
  }
  const tls = config.scheme !== 'http'
  const port = Number.parseInt(String(config.port ?? ''), 10) || (tls ? 443 : 80)
  const generation = chatState.generation

  const pusher = new Pusher(String(config.key), {
    // Klucz wymagany przez pusher-js, ale bez znaczenia przy własnym serwerze (wsHost).
    cluster: '',
    wsHost: String(config.host),
    wsPort: port,
    wssPort: port,
    forceTLS: tls,
    enabledTransports: ['ws', 'wss'],
    enableStats: false,
    channelAuthorization: {
      customHandler: chatAuthorize,
    },
  })
  chatState.pusher = pusher
  chatState.socketState = 'connecting'
  chatState.subscribed = false

  pusher.connection.bind('state_change', (states) => {
    if (generation !== chatState.generation || chatState.pusher !== pusher) return
    const wasLive = chatRealtimeLive()
    chatState.socketState = states && states.current ? states.current : 'unavailable'
    if (chatState.socketState !== 'connected') chatState.subscribed = false
    // Zerwane połączenie: licznik wraca do odpytywania od razu, kolejka do zwykłego tempa.
    if (wasLive && !chatRealtimeLive()) {
      chatState.nextUnreadAt = 0
      chatOnQueue()
    }
  })

  const channel = pusher.subscribe('private-user.' + userId)
  channel.bind('pusher:subscription_succeeded', () => {
    if (generation !== chatState.generation || chatState.pusher !== pusher) return
    chatState.subscribed = true
    // Co przyszło, gdy nas nie było: licznik i kolejka jeszcze raz z serwera.
    if (chatState.enabled !== false) chatRefreshUnread()
    chatOnQueue()
  })
  channel.bind('pusher:subscription_error', () => {
    if (generation !== chatState.generation || chatState.pusher !== pusher) return
    const rejected = chatState.authStatus === 401 || chatState.authStatus === 403
    // Bez kanału połączenie nic nie daje — zamykamy je i próbujemy później (odrzucony klucz: po 5 minutach).
    chatStopRealtime()
    chatState.realtimeRetryAt = Date.now() + (rejected ? CHAT_REJECTED_SECONDS : CHAT_RETRY_SECONDS) * 1000
  })
  channel.bind('chat.message', (data) => {
    if (generation !== chatState.generation) return
    chatOnMessage(data).catch((e) => console.warn('Supon: wiadomość czatu:', e.message))
  })
  channel.bind('chat.read', (data) => {
    if (generation === chatState.generation) chatOnRead(data)
  })
  // autor usunął wiadomość — usunięta nie liczy się do nieprzeczytanych
  channel.bind('chat.deleted', () => {
    if (generation !== chatState.generation || chatState.enabled === false) return
    chatRefreshUnread().catch((e) => console.warn('Supon: licznik czatu:', e.message))
  })
  channel.bind('queue.updated', () => {
    if (generation === chatState.generation) chatOnQueue()
  })
  // Rozmowy głosowe i wideo (od 1.33.0) — dodatek tylko dzwoni, rozmowa jest w przeglądarce.
  channel.bind('chat.call.ringing', (data) => {
    if (generation !== chatState.generation) return
    chatOnCallRinging(data).catch((e) => console.warn('Supon: dzwonek rozmowy:', e.message))
  })
  channel.bind('chat.call.updated', (data) => {
    if (generation === chatState.generation) chatOnCallUpdated(data)
  })
}

/** Konfiguracja połączenia z serwera; `realtime: null` = serwer go nie ma, zostaje odpytywanie. */
async function chatSetupRealtime() {
  const generation = chatState.generation
  chatState.realtimeRetryAt = 0
  let data
  try {
    data = await api('/api/realtime')
  } catch (e) {
    if (generation !== chatState.generation) return
    const status = e && e.status
    const seconds = status === 404 ? CHAT_REALTIME_RECHECK_MINUTES * 60
      : status === 401 || status === 403 ? CHAT_REJECTED_SECONDS : CHAT_RETRY_SECONDS
    chatState.realtimeRetryAt = Date.now() + seconds * 1000

    return
  }
  if (generation !== chatState.generation) return

  const config = data && data.realtime && typeof data.realtime === 'object' ? data.realtime : null
  if (config === null || !config.key || !config.host || chatState.userId === null) {
    chatState.realtimeRetryAt = Date.now() + CHAT_REALTIME_RECHECK_MINUTES * 60 * 1000

    return
  }
  chatConnect(config, chatState.userId)
}

/* ------------------------------ start ------------------------------ */

/**
 * Start czatu: kim jestem (GET /me), czy mam czat, licznik, połączenie na żywo. Wywołuje go background.js
 * po wczytaniu wszystkich plików tła, a potem zmiana klucza albo adresu aplikacji.
 */
async function chatStart() {
  if (chatState.starting) return
  chatState.starting = true
  const generation = chatState.generation
  try {
    const { token } = await getSettings()
    if (!token) {
      // Bez połączenia z aplikacją nie ma czego pokazywać; po „Połącz” start ruszy sam (storage.onChanged).
      chatState.ready = true
      chatState.enabled = null

      return
    }

    let me
    try {
      me = await api('/api/me')
    } catch (e) {
      if (generation === chatState.generation) {
        chatState.retryAt = Date.now() + (e && e.status === 401 ? CHAT_REJECTED_SECONDS : CHAT_RETRY_SECONDS) * 1000
      }

      return
    }
    if (generation !== chatState.generation) return
    chatState.userId = me && me.id !== undefined ? me.id : null

    // Uprawnienia podaje już /me — bez czatu nie pytamy o licznik (pewne 403).
    const permissions = me && Array.isArray(me.permissions) ? me.permissions : null
    if (permissions !== null && !permissions.includes('chat')) {
      await chatDisable()
    } else {
      chatState.enabled = null
      await chatRefreshUnread()
    }
    if (generation !== chatState.generation) return

    chatState.ready = true
    await chatSetupRealtime()
  } finally {
    // Start z poprzedniego przebiegu nie zdejmuje blokady nowemu (chatRestart ją zwolnił i nowy już ruszył).
    if (generation === chatState.generation) chatState.starting = false
  }
}

/**
 * Wyłączony czat sprawdzamy co kwadrans bez zrywania połączenia — to samo połączenie niesie sygnał o kolejce.
 * Uprawnienie nadane w aplikacji włącza czat bez restartu Thunderbirda.
 */
async function chatRecheck() {
  chatState.recheckAt = Date.now() + CHAT_DISABLED_RECHECK_MINUTES * 60 * 1000
  const generation = chatState.generation
  let me
  try {
    me = await api('/api/me')
  } catch (e) {
    return
  }
  if (generation !== chatState.generation) return
  const permissions = me && Array.isArray(me.permissions) ? me.permissions : null
  if (permissions !== null && !permissions.includes('chat')) return

  // Sukces włącza czat (chatEnable), kolejne 403 wyłącza go na następny kwadrans.
  await chatRefreshUnread()
}

/** Nowy klucz albo adres: wszystko od zera, łącznie z połączeniem. */
async function chatRestart() {
  chatState.generation += 1
  chatStopRealtime()
  // Dzwonek z poprzedniego konta nie może grać dalej — serwer nie powie mu już, że ma przestać.
  chatCallStopAll()
  chatState.ready = false
  chatState.starting = false
  chatState.retryAt = 0
  chatState.userId = null
  chatState.types = new Map()
  chatState.unread = 0
  chatState.nextUnreadAt = 0
  chatState.realtimeRetryAt = 0
  chatState.authStatus = 0
  // Przycisk „Czat”: bez licznika i migania do odpowiedzi nowego konta; wyszarzy go dopiero brak uprawnienia.
  chatPaintActionBadge()
  chatSyncBlink()
  chatSetActionEnabled(true)
  // Okno czatu trzyma w pamięci stary klucz — po zmianie konta albo „Odłącz” nie może zostać otwarte.
  if (chatState.windowId !== null) {
    const windowId = chatState.windowId
    chatState.windowId = null
    chatState.windowFocused = false
    Promise.resolve().then(() => browser.windows.remove(windowId)).catch(() => {})
  }
  // Adres przestrzeni idzie za adresem aplikacji, a bez klucza i bez czatu przestrzeni nie ma.
  if (chatState.spaceId !== null) await chatPaintBadge()
  const { token } = await getSettings()
  if (!token) await chatRemoveSpace()
  await chatStart()
}

function chatTick() {
  const now = Date.now()
  if (!chatState.ready) {
    if (!chatState.starting && now >= chatState.retryAt) chatStart()

    return
  }
  if (chatState.userId === null) return

  if (chatState.enabled === false) {
    if (now >= chatState.recheckAt) chatRecheck()
  } else if (now >= chatState.nextUnreadAt) {
    chatRefreshUnread()
  }
  if (chatState.pusher === null && chatState.realtimeRetryAt > 0 && now >= chatState.realtimeRetryAt) {
    chatSetupRealtime()
  }
}

setInterval(() => {
  try {
    chatTick()
  } catch (e) {
    console.warn('Supon: zegar czatu:', e.message)
  }
}, CHAT_TICK_SECONDS * 1000)

// „Połącz” w ustawieniach zapisuje klucz i adres osobno — czekamy chwilę, żeby nie startować dwa razy.
browser.storage.onChanged.addListener((changes, area) => {
  if (area !== 'local' || !(changes.token || changes.baseUrl)) return
  if (chatState.restartTimer !== null) clearTimeout(chatState.restartTimer)
  chatState.restartTimer = setTimeout(() => {
    chatState.restartTimer = null
    chatRestart().catch((e) => console.warn('Supon: ponowny start czatu:', e.message))
  }, 500)
})

browser.notifications.onClicked.addListener((notificationId) => {
  const id = String(notificationId || '')
  if (!id.startsWith(CHAT_NOTIFICATION_PREFIX)) return
  const match = /^chat-(\d+)-/.exec(id)
  // Od 1.34.0 dymek otwiera (albo przywołuje) małe okno czatu od razu na tej rozmowie.
  chatOpenWindow(match ? Number(match[1]) : null)
    .catch((e) => console.warn('Supon: nie udało się otworzyć czatu:', e.message))
  browser.notifications.clear(id).catch(() => {})
})

// Przycisk „Czat” na górnym pasku (bez okienka, więc kliknięcie przychodzi tutaj).
if (browser.browserAction) {
  browser.browserAction.onClicked.addListener(() => {
    chatSingleUnreadConversation()
      .then((conversationId) => chatOpenWindow(conversationId))
      .catch((e) => console.warn('Supon: nie udało się otworzyć okna czatu:', e.message))
  })
  Promise.resolve()
    .then(() => browser.browserAction.setBadgeBackgroundColor({ color: CHAT_BADGE_COLOR }))
    .catch(() => {})
}

browser.windows.onRemoved.addListener((windowId) => {
  if (windowId !== chatState.windowId) return
  chatState.windowId = null
  chatState.windowFocused = false
  chatSyncBlink()
})

// Fokus na oknie czatu zatrzymuje miganie; przejście do poczty albo poza Thunderbirda (WINDOW_ID_NONE) je wznawia.
browser.windows.onFocusChanged.addListener((windowId) => {
  const focused = chatState.windowId !== null && windowId === chatState.windowId
  if (focused === chatState.windowFocused) return
  chatState.windowFocused = focused
  chatSyncBlink()
})

// Strony aplikacji otwarte z czatu w nowej karcie albo oknie Thunderbirda → domyślna przeglądarka.
browser.tabs.onCreated.addListener((tab) => {
  chatRedirectTab(tab, tab && tab.url).catch((e) => console.warn('Supon: przeniesienie karty:', e.message))
})
browser.tabs.onUpdated.addListener((tabId, changeInfo, tab) => {
  // Zmiana adresu (about:blank → strona) albo koniec ładowania karty, która od początku miała adres.
  const url = changeInfo && typeof changeInfo.url === 'string' ? changeInfo.url
    : changeInfo && changeInfo.status === 'complete' && tab && typeof tab.url === 'string' ? tab.url : null
  if (url === null) return
  chatRedirectTab(Object.assign({ id: tabId }, tab || {}), url)
    .catch((e) => console.warn('Supon: przeniesienie karty:', e.message))
})
browser.tabs.onRemoved.addListener((tabId) => {
  chatState.redirecting.delete(tabId)
  chatState.chatTabs.delete(tabId)
})

// Ustawienie „Migająca ikona czatu” (strona ustawień) — od razu, bez restartu.
browser.storage.onChanged.addListener((changes, area) => {
  if (area !== 'local' || !changes.blinkChatIcon) return
  chatState.blinkSetting = changes.blinkChatIcon.newValue !== false
  chatSyncBlink()
})
getSettings()
  .then((settings) => {
    chatState.blinkSetting = settings.blinkChatIcon !== false
    chatSyncBlink()
  })
  .catch(() => {})

// Powiadomienie o rozmowie: strona rozmowy w domyślnej przeglądarce — sama rozmowa nie odbywa się w Thunderbirdzie.
browser.notifications.onClicked.addListener((notificationId) => {
  const id = String(notificationId || '')
  if (!id.startsWith(CALL_NOTIFICATION_PREFIX)) return
  const callId = chatCallId(id.slice(CALL_NOTIFICATION_PREFIX.length))
  if (callId === null) return
  chatCallAnswer(callId).catch((e) => console.warn('Supon: nie udało się otworzyć rozmowy:', e.message))
  browser.notifications.clear(id).catch(() => {})
})

// Okienko nad mailem: „Otwórz czat” i licznik. Inne prośby zostawiamy background.js (undefined = nie nasza).
browser.runtime.onMessage.addListener((request) => {
  if (request && request.type === 'openChat') {
    return chatOpen(request.conversationId ?? null).then(
      () => ({ ok: true }),
      (e) => ({ ok: false, error: e.message }),
    )
  }
  if (request && request.type === 'chatSent') {
    // Nowa rozmowa 1:1 z „Wyślij koledze” — typ znamy, licznik się nie zmienia.
    const id = Number(request.conversationId)
    if (Number.isFinite(id) && id > 0) chatState.types.set(id, 'direct')

    return Promise.resolve({ ok: true })
  }

  return undefined
})
