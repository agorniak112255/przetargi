/*
 * Oznaczanie maili na liście wiadomości: który mail ma już zapytanie
 * w aplikacji, kto je prowadzi i czy odpowiedź do klienta już poszła.
 *
 * Skąd wspólny obraz na każdym komputerze: znaczniki są lokalne (siedzą
 * w profilu Thunderbirda), ale to, czym oznaczamy, pochodzi z serwera. Ten sam
 * mail wysłany na kilka adresów ma u wszystkich ten sam Message-ID, więc każdy
 * dodatek dostaje tę samą odpowiedź i stawia te same znaczniki.
 *
 * Kierunek pytania jest odwrotny, niż się wydaje: to serwer mówi, których maili
 * dotyczą zapytania (GET /api/inquiries/message-ids), a dodatek szuka ich
 * u siebie po Message-ID. Przeglądanie całej skrzynki co kilka minut byłoby
 * wielokrotnie droższe, a skrzynki handlowców mają dziesiątki tysięcy maili.
 *
 * Czego to nie obejmuje:
 *  - maila przekazanego ręcznie ze skrzynki ogólnej — ma nowy Message-ID
 *    i rozpoznaje go dopiero odcisk treści przy zakładaniu zapytania (409),
 *  - zapytań założonych przez wklejenie treści w przeglądarce — nie mają
 *    Message-ID, więc nie ma czego szukać w poczcie.
 */

/** Prefiks kluczy naszych znaczników — czegokolwiek bez niego nie ruszamy. */
const TAG_PREFIX = 'supon-'

/** Zapytanie założone, odpowiedź do klienta jeszcze nie poszła. */
const TAG_OPEN = { suffix: '-zapytanie', label: 'Zapytanie', color: '#D97706' }

/** Odpowiedź do klienta wysłana — sprawa zamknięta. */
const TAG_DONE = { suffix: '-wyslane', label: 'Wysłane', color: '#15803D' }

/** Ile Message-ID leci w jednym pytaniu do serwera (limit endpointu to 200). */
const LOOKUP_BATCH = 200

/** Ile stron listy z serwera bierzemy w jednym przejściu. */
const PULL_PAGES = 5

/** Ile maili najwyżej sprawdzamy ponownie w jednym przejściu. */
const RECHECK_LIMIT = 400

/**
 * Uprawnienia do zapisu znaczników. `messages.update` stoi za `messagesUpdate`
 * od Thunderbirda 122, a wcześniej za `messagesModify` — bierzemy to, co zna
 * ta wersja. Oba są w `optional_permissions`, bo uprawnienie dopisane jako
 * wymagane zatrzymuje automatyczną aktualizację dodatku do czasu, aż człowiek
 * klinie zgodę w Menedżerze dodatków.
 */
const TAG_PERMISSIONS_MODERN = ['messagesTags', 'messagesUpdate']
const TAG_PERMISSIONS_LEGACY = ['messagesTags', 'messagesModify']

/** Od tej wersji Thunderbirda zapis wiadomości stoi za `messagesUpdate`. */
const MESSAGES_UPDATE_SINCE = 122

/** Żeby dwa przejścia nie nachodziły na siebie przy wolnej sieci. */
let syncing = false

/**
 * Czy ostatnie oznaczanie padło z przyczyn technicznych (brak zgody, brak API
 * znaczników, błąd serwera). Wtedy nie wolno przesunąć `tagsSince`: serwer
 * oddaje zmiany „od” tej chwili, więc maile z okna, którego nie przetworzyliśmy,
 * nie wróciłyby już nigdy — i lista zostałaby bez oznaczeń na zawsze.
 */
let markBroken = false

/**
 * Powód ostatniej porażki, słowami. Wcześniej każdy błąd tej drogi kończył się
 * wpisem w konsoli tła dodatku — awaria wyglądała jak „nic się nie dzieje”,
 * bo do tej konsoli nikt nie zagląda. Teraz powód wychodzi na powiadomienie.
 */
let markError = null

/* ---------------------------- uprawnienia ---------------------------- */

async function thunderbirdMajor() {
  try {
    const info = await browser.runtime.getBrowserInfo()

    return Number.parseInt(String(info.version).split('.')[0], 10) || 0
  } catch (e) {
    return 0
  }
}

/** Zestaw uprawnień, o który wolno pytać w tej wersji Thunderbirda. */
async function tagPermissions() {
  const major = await thunderbirdMajor()

  return major >= MESSAGES_UPDATE_SINCE ? TAG_PERMISSIONS_MODERN : TAG_PERMISSIONS_LEGACY
}

/** Czy handlowiec zgodził się na oznaczanie maili. */
async function tagsAllowed() {
  try {
    return await browser.permissions.contains({ permissions: await tagPermissions() })
  } catch (e) {
    // Nieznana nazwa uprawnienia w tej wersji — traktujemy jak brak zgody.
    return false
  }
}

/**
 * Pytanie o zgodę; wolno je zadać tylko w odpowiedzi na kliknięcie, dlatego
 * wywołuje je strona ustawień, a nie tło dodatku.
 */
async function requestTagPermissions() {
  return browser.permissions.request({ permissions: await tagPermissions() })
}

/* ----------------------------- znaczniki ----------------------------- */

/**
 * Nowsze wydania mają `messages.tags.*`, starsze te same funkcje wprost
 * w `messages`. Zwracamy jedno wspólne oblicze albo null, gdy znaczników
 * nie da się w tym Thunderbirdzie zakładać.
 */
function tagApi() {
  const messages = browser.messages
  if (messages && messages.tags && typeof messages.tags.create === 'function') {
    return {
      list: () => messages.tags.list(),
      create: (key, label, color) => messages.tags.create(key, label, color),
      update: (key, patch) => messages.tags.update(key, patch),
    }
  }
  if (messages && typeof messages.createTag === 'function') {
    return {
      list: () => messages.listTags(),
      create: (key, label, color) => messages.createTag(key, label, color),
      update: (key, patch) => messages.updateTag(key, patch),
    }
  }

  return null
}

/**
 * Znacznik dla osoby i etapu. Klucz robimy z numeru konta w aplikacji, a nie
 * z nazwiska: Thunderbird zamienia klucze na małe litery, nie przyjmuje w nich
 * spacji, a polskie znaki i tak by się rozjechały (klucz trafia też do słów
 * kluczowych IMAP). Nazwisko idzie do widocznej nazwy — po to całe oznaczanie
 * jest: żeby na liście było widać, kto ten mail obrabia.
 */
function tagFor(row) {
  const stage = row && row.replied_at ? TAG_DONE : TAG_OPEN
  const userId = row && row.user && row.user.id !== undefined ? String(row.user.id) : '0'

  return {
    key: TAG_PREFIX + 'u' + userId + stage.suffix,
    label: stage.label + ': ' + duplicateOwner(row),
    color: stage.color,
  }
}

/**
 * Dba o to, żeby znacznik istniał i miał aktualną nazwę (nazwisko w aplikacji
 * mogło się zmienić). Zwraca prawdę, gdy znacznikiem można oznaczać.
 */
async function ensureTag(tags, known, wanted) {
  const existing = known.get(wanted.key)
  if (existing === undefined) {
    try {
      await tags.create(wanted.key, wanted.label, wanted.color)
      known.set(wanted.key, { tag: wanted.label, color: wanted.color })

      return true
    } catch (e) {
      // Wyścig dwóch okien albo zajęty klucz — przy następnym przejściu
      // znacznik będzie już na liście i po prostu go użyjemy.
      markError = 'Nie udało się założyć znacznika „' + wanted.label + '”: ' + e.message

      return false
    }
  }

  if (existing.tag !== wanted.label) {
    try {
      await tags.update(wanted.key, { tag: wanted.label })
      known.set(wanted.key, { tag: wanted.label, color: existing.color })
    } catch (e) {
      console.warn('Nie udało się poprawić nazwy znacznika ' + wanted.key + ':', e.message)
    }
  }

  return true
}

/** Mapa klucz → znacznik, po jednym odczycie listy z profilu. */
async function knownTags(tags) {
  const known = new Map()
  const rows = await tags.list()
  for (const row of Array.isArray(rows) ? rows : []) {
    known.set(String(row.key), { tag: String(row.tag || ''), color: String(row.color || '') })
  }

  return known
}

/* ------------------------- pamięć tego, co nasze ------------------------- */

/**
 * Message-ID → nasze znaczniki, które na nim postawiliśmy. Bez tego nie
 * dowiedzielibyśmy się, że coś trzeba zdjąć: usunięte zapytanie nie pojawi
 * się już w żadnej odpowiedzi serwera, a znacznik zostałby i kłamał.
 */
async function taggedMails() {
  const { tagged } = await browser.storage.local.get({ tagged: {} })

  return tagged && typeof tagged === 'object' ? tagged : {}
}

async function rememberTagged(tagged) {
  await browser.storage.local.set({ tagged })
}

/* --------------------------- pytanie do serwera --------------------------- */

/**
 * Które z podanych maili mają już zapytanie. Klucze odpowiedzi to Message-ID,
 * wartości — lista zapytań (jeden mail może mieć ich kilka, gdy ktoś świadomie
 * założył własne obok cudzego). Brak klucza znaczy „nie ma nic”.
 */
async function lookupMessageIds(headerMessageIds) {
  const found = new Map()

  for (let at = 0; at < headerMessageIds.length; at += LOOKUP_BATCH) {
    const batch = headerMessageIds.slice(at, at + LOOKUP_BATCH)
    const data = await api('/api/inquiries/lookup', {
      method: 'POST',
      body: { message_ids: batch },
    })
    const rows = data && data.data && typeof data.data === 'object' ? data.data : {}
    for (const [messageId, list] of Object.entries(rows)) {
      // Klucze trzymamy małymi literami: Message-ID wraca z serwera w zapisie
      // z bazy, a ten po drodze przez MySQL-a może mieć inną wielkość liter.
      found.set(String(messageId).toLowerCase(), Array.isArray(list) ? list : [])
    }
  }

  return found
}

/* ---------------------------- oznaczanie maili ---------------------------- */

/** Mail o podanym Message-ID we wszystkich folderach tego Thunderbirda. */
async function messagesWithHeaderId(headerMessageId) {
  if (!headerMessageId) return []
  try {
    const list = await browser.messages.query({ headerMessageId })

    return list && Array.isArray(list.messages) ? list.messages : []
  } catch (e) {
    return []
  }
}

function sameSet(one, two) {
  if (one.length !== two.length) return false
  const sorted = [...one].sort()
  const other = [...two].sort()

  return sorted.every((value, at) => value === other[at])
}

/** Nasze znaczniki maila; cudzych (kolorów handlowca) nie dotykamy. */
function ownTags(tags) {
  return (Array.isArray(tags) ? tags : []).filter((key) => String(key).startsWith(TAG_PREFIX))
}

/**
 * Ustawia na mailu dokładnie te nasze znaczniki, które wynikają ze stanu
 * w aplikacji — i tylko wtedy, gdy coś się zmieniło. Każdy zapis to wpis do
 * bazy Thunderbirda i polecenie do serwera IMAP, więc bez zmiany nie ruszamy.
 */
async function applyTags(message, wantedKeys) {
  const current = Array.isArray(message.tags) ? message.tags.map(String) : []
  if (sameSet(ownTags(current), wantedKeys)) return false

  const next = current.filter((key) => !key.startsWith(TAG_PREFIX)).concat(wantedKeys)
  try {
    await browser.messages.update(message.id, { tags: next })
  } catch (e) {
    markError = 'Thunderbird odmówił zapisu znacznika na mailu: ' + e.message

    return false
  }

  // Zapis bez wyjątku nie znaczy jeszcze, że znacznik został: serwer IMAP może
  // nie przyjmować własnych etykiet i wtedy wraca stan sprzed zapisu.
  try {
    const after = await browser.messages.get(message.id)
    const stuck = ownTags(after.tags)
    if (wantedKeys.length > 0 && stuck.length === 0) {
      markError = 'Znacznik nie został na mailu — serwer poczty prawdopodobnie nie przyjmuje '
        + 'własnych etykiet (folder: ' + String(message.folder && message.folder.name ? message.folder.name : 'nieznany') + ').'

      return false
    }
  } catch (e) {
    // Sprawdzenie jest dodatkiem; brak odczytu nie unieważnia samego zapisu.
  }

  return true
}

/**
 * Sedno: dla podanych Message-ID pyta serwer, zakłada brakujące znaczniki
 * i ustawia je na mailach w tym Thunderbirdzie. Maile, których serwer nie zna,
 * tracą nasze znaczniki — to ta sama droga, którą znika oznaczenie po
 * usunięciu zapytania w aplikacji.
 */
async function markHeaderIds(headerMessageIds) {
  const ids = [...new Set(headerMessageIds.filter((id) => String(id || '') !== ''))]
  if (ids.length === 0) return 0

  markBroken = true
  markError = null

  const { token } = await getSettings()
  if (!token) {
    markError = 'Dodatek nie jest połączony z aplikacją — zaloguj się w ustawieniach dodatku.'

    return 0
  }
  if (!await tagsAllowed()) {
    markError = 'Brak zgody na zmianę znaczników wiadomości — włącz oznaczanie w ustawieniach dodatku.'

    return 0
  }

  const tags = tagApi()
  if (tags === null) {
    markError = 'Ta wersja Thunderbirda nie pozwala zakładać znaczników przez dodatek.'

    return 0
  }

  let found
  try {
    found = await lookupMessageIds(ids)
  } catch (e) {
    // Brak sieci albo wygasły token: znaczniki zostają takie, jakie są.
    markError = 'Aplikacja nie odpowiedziała na pytanie o zapytania: ' + e.message

    return 0
  }

  markBroken = false

  const known = await knownTags(tags)
  const tagged = await taggedMails()
  let changed = 0

  for (const headerMessageId of ids) {
    const rows = found.get(String(headerMessageId).toLowerCase()) || []

    const keys = []
    for (const row of rows) {
      const wanted = tagFor(row)
      if (keys.includes(wanted.key)) continue
      if (await ensureTag(tags, known, wanted)) keys.push(wanted.key)
    }

    // Ten sam mail bywa w kilku folderach (kopia w archiwum) — oznaczamy każdą.
    const copies = await messagesWithHeaderId(headerMessageId)
    for (const message of copies) {
      if (await applyTags(message, keys)) changed += 1
    }

    if (keys.length === 0) delete tagged[headerMessageId]
    else tagged[headerMessageId] = keys
  }

  await rememberTagged(tagged)

  return changed
}

/** Co ile minut najwyżej raz mówimy o tej samej awarii oznaczania. */
const MARK_COMPLAIN_MINUTES = 60

/**
 * Oznaczenie jednego maila — po otwarciu go albo po własnej akcji w dodatku.
 *
 * `loud` włącza powiadomienie o porażce. Używamy go tam, gdzie człowiek właśnie
 * patrzy (świeżo założone zapytanie, otwarty mail), żeby awaria nie była cicha.
 */
async function markByHeaderId(headerMessageId, { loud = false } = {}) {
  if (!headerMessageId) return

  await markHeaderIds([headerMessageId])
  if (!loud || markError === null) return

  const { markComplainedAt } = await browser.storage.local.get({ markComplainedAt: 0 })
  if (Date.now() - Number(markComplainedAt || 0) < MARK_COMPLAIN_MINUTES * 60 * 1000) return

  await browser.storage.local.set({ markComplainedAt: Date.now() })
  try {
    await browser.notifications.create({
      type: 'basic',
      iconUrl: browser.runtime.getURL('icons/icon.svg'),
      title: 'Nie udało się oznaczyć maila',
      message: markError,
    })
  } catch (e) {
    console.warn('Powiadomienie o oznaczaniu się nie pokazało:', e.message)
  }
}

/* --------------------------- nadążanie za innymi --------------------------- */

/**
 * Nowe i zmienione zapytania z serwera. Pierwsze uruchomienie bierze okno
 * dziewięćdziesięciu dni (dodatek mógł zostać zainstalowany długo po tym, jak
 * zapytania powstały), potem pytamy tylko o zmiany od ostatniego razu.
 */
async function pullChangedMails() {
  const { tagsSince } = await browser.storage.local.get({ tagsSince: '' })
  let since = String(tagsSince || '')

  for (let page = 0; page < PULL_PAGES; page += 1) {
    const path = '/api/inquiries/message-ids' + (since === '' ? '' : '?since=' + encodeURIComponent(since))

    let data
    try {
      data = await api(path)
    } catch (e) {
      console.warn('Nie udało się pobrać listy maili z zapytaniami:', e.message)

      return
    }

    const ids = data && Array.isArray(data.ids) ? data.ids : []
    if (ids.length > 0) await markHeaderIds(ids)
    if (markBroken) {
      // Nic nie oznaczyliśmy — okno czasu zostaje otwarte na następne przejście.
      console.warn('Oznaczanie nie zadziałało — nie przesuwam znacznika czasu.')

      return
    }

    since = String(data && data.next_since ? data.next_since : since)
    await browser.storage.local.set({ tagsSince: since })

    if (!data || data.has_more !== true) return
  }
}

/**
 * Powtórne sprawdzenie maili, które sami oznaczyliśmy. Tu wychodzą zmiany,
 * o których lista zmian nie powie: usunięte zapytanie znika z bazy bez śladu,
 * a znacznik musi zejść z maila.
 */
async function recheckTaggedMails() {
  const tagged = await taggedMails()
  const ids = Object.keys(tagged).slice(0, RECHECK_LIMIT)
  if (ids.length === 0) return

  await markHeaderIds(ids)
}


/**
 * Jedno przejście synchronizacji. `full` dorzuca powtórne sprawdzenie tego,
 * co już oznaczone — robimy je rzadziej, bo dotyczy maili bez zmian.
 */
async function syncTags({ full = false } = {}) {
  if (syncing) return

  const { token } = await getSettings()
  if (!token || !await tagsAllowed()) return

  syncing = true
  try {
    await pullChangedMails()
    if (full) await recheckTaggedMails()
  } catch (e) {
    console.warn('Synchronizacja znaczników się nie powiodła:', e.message)
  } finally {
    syncing = false
  }
}

/* ------------------------------ samotest ------------------------------ */

/**
 * Samotest oznaczania: wypisuje po kolei, co działa, a co nie.
 *
 * Powód: każdy błąd w tej drodze kończył się wpisem w konsoli tła dodatku,
 * do której nikt nie zagląda — awaria wyglądała jak „nic się nie dzieje”.
 * Raport ma być czytelny dla człowieka i dać się wkleić w zgłoszeniu.
 */
async function tagDiagnostics() {
  const lines = []
  const say = (label, value) => lines.push(label + ': ' + value)

  say('Wersja dodatku', addonVersion() || 'nieznana')
  let version = 'nieznana'
  try {
    const info = await browser.runtime.getBrowserInfo()
    version = String(info.name || 'Thunderbird') + ' ' + String(info.version || '')
  } catch (e) {
    version = 'nie podaje (' + e.message + ')'
  }
  say('Thunderbird', version)

  const wanted = await tagPermissions()
  say('Potrzebne uprawnienia', wanted.join(', '))
  let allowed = false
  try {
    allowed = await browser.permissions.contains({ permissions: wanted })
    say('Zgoda na znaczniki', allowed ? 'jest' : 'BRAK — włącz oznaczanie')
  } catch (e) {
    say('Zgoda na znaczniki', 'nie dało się sprawdzić (' + e.message + ')')
  }

  // Uwaga: nazwa `tags`, nie `api` — `api()` to funkcja HTTP z common.js.
  const tags = tagApi()
  say('API znaczników', tags === null ? 'BRAK w tej wersji' : 'jest')

  const settings = await getSettings()
  say('Połączenie z aplikacją', settings.token ? 'jest' : 'BRAK — zaloguj się w ustawieniach')
  say('Adres aplikacji', settings.baseUrl)

  const { tagsSince, tagged } = await browser.storage.local.get({ tagsSince: '', tagged: {} })
  say('Znacznik czasu', tagsSince === '' ? 'pusty (pełne nadgonienie 90 dni)' : String(tagsSince))
  say('Maile oznaczone przez dodatek', String(Object.keys(tagged || {}).length))

  if (tags !== null) {
    try {
      const ours = (await tags.list()).filter((row) => String(row.key).startsWith(TAG_PREFIX))
      say('Znaczniki dodatku w profilu', ours.length === 0
        ? 'żadnych'
        : ours.map((row) => row.key + ' („' + row.tag + '”)').join(', '))
    } catch (e) {
      say('Znaczniki dodatku w profilu', 'nie dało się odczytać (' + e.message + ')')
    }
  }

  if (!settings.token) return lines.join('\n')

  // Serwer: które maile mają zapytania
  let ids = []
  try {
    const data = await api('/api/inquiries/message-ids')
    ids = data && Array.isArray(data.ids) ? data.ids : []
    say('Maile z zapytaniami na serwerze (90 dni)', String(ids.length))
  } catch (e) {
    say('Pytanie do serwera', 'BŁĄD — ' + e.message)

    return lines.join('\n')
  }

  const sample = ids.slice(0, 5)
  if (sample.length === 0) {
    lines.push('Serwer nie ma żadnego zapytania z maila — nie ma czego oznaczać.')

    return lines.join('\n')
  }

  let rows
  try {
    rows = await lookupMessageIds(sample)
  } catch (e) {
    say('Sprawdzenie zapytań', 'BŁĄD — ' + e.message)

    return lines.join('\n')
  }

  lines.push('')
  lines.push('Pierwsze ' + sample.length + ' maili z zapytaniami:')
  for (const id of sample) {
    const list = rows.get(String(id).toLowerCase()) || []
    const copies = await messagesWithHeaderId(id)
    const parts = [
      'zapytania: ' + list.length,
      'w tym Thunderbirdzie: ' + copies.length,
    ]
    if (list.length > 0) {
      parts.push('znaczniki: ' + list.map((row) => tagFor(row).key).join(' + '))
    }
    if (copies.length > 0) {
      const mine = ownTags(copies[0].tags)
      parts.push('na mailu stoi: ' + (mine.length === 0 ? 'nic naszego' : mine.join(' + ')))
    }
    lines.push('  ' + id + ' — ' + parts.join(', '))
  }

  // Próba na żywo: pierwszy mail, który jest i tu, i na serwerze
  for (const id of sample) {
    const list = rows.get(String(id).toLowerCase()) || []
    const copies = await messagesWithHeaderId(id)
    if (list.length === 0 || copies.length === 0) continue

    lines.push('')
    lines.push('Próba oznaczenia maila ' + id + ':')
    if (tags === null || !allowed) {
      lines.push('  pominięta — brak zgody albo API znaczników')
      break
    }
    try {
      const known = await knownTags(tags)
      const wantedTag = tagFor(list[0])
      const ready = await ensureTag(api, known, wantedTag)
      lines.push('  znacznik ' + wantedTag.key + ': ' + (ready ? 'gotowy' : 'NIE UDAŁO SIĘ założyć'))
      if (ready) {
        await browser.messages.update(copies[0].id, {
          tags: (copies[0].tags || []).filter((key) => !String(key).startsWith(TAG_PREFIX)).concat([wantedTag.key]),
        })
        const after = await browser.messages.get(copies[0].id)
        const stuck = ownTags(after.tags).includes(wantedTag.key)
        lines.push('  zapis na mailu: ' + (stuck
          ? 'UDANY — znacznik jest na mailu'
          : 'zapis przeszedł, ale znacznik nie został (serwer poczty może nie przyjmować własnych etykiet)'))
      }
    } catch (e) {
      lines.push('  BŁĄD zapisu: ' + e.message)
    }
    break
  }

  return lines.join('\n')
}

/**
 * Pełne nadgonienie: kasuje znacznik czasu i pamięć oznaczonych maili, więc
 * najbliższe przejście przechodzi całe okno 90 dni od nowa.
 */
async function resetTagSync() {
  await browser.storage.local.set({ tagsSince: '', tagged: {} })
  markBroken = false
  await syncTags({ full: true })
}

/**
 * Sprzątanie: zdejmuje z maili wszystkie znaczniki dodatku. Do użycia
 * z ustawień, gdy ktoś nie chce już oznaczania albo gdy na liście znaczników
 * zostały nazwiska osób, których dawno nie ma w firmie.
 */
async function clearOurTags() {
  const tagged = await taggedMails()
  let cleared = 0

  for (const headerMessageId of Object.keys(tagged)) {
    for (const message of await messagesWithHeaderId(headerMessageId)) {
      if (await applyTags(message, [])) cleared += 1
    }
  }

  await browser.storage.local.set({ tagged: {}, tagsSince: '' })

  return cleared
}
