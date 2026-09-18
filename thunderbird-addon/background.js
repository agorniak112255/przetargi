/*
 * Tło dodatku. Tu dzieje się wszystko, co trwa dłużej niż mgnienie oka:
 * okienko nad mailem zamyka się przy pierwszym kliknięciu obok, więc analiza
 * uruchomiona w okienku zostałaby przerwana w połowie.
 */

/* Stan analizy dla maila (`pending`) trzyma common.js — czyta go też okienko. */

async function notify(title, message) {
  try {
    await browser.notifications.create({
      type: 'basic',
      iconUrl: browser.runtime.getURL('icons/icon.svg'),
      title,
      message,
    })
  } catch (e) {
    console.warn('Powiadomienie się nie pokazało:', e.message)
  }
}

/**
 * Zakłada zapytanie i otwiera je w przeglądarce. Wywoływane z okienka, ale
 * wykonywane tutaj — zamknięcie okienka nie przerywa analizy.
 *
 * `force` puszczamy dopiero wtedy, gdy handlowiec zobaczył cudze zapytanie
 * i mimo to chce założyć własne (oba zostaną powiązane po stronie aplikacji).
 */
async function createInquiry({ headerMessageId, subject, sourceFrom, sourceSentAt, body, tone, force = false }) {
  // Przy „Załóż mimo to” zachowujemy dane duplikatu — gdyby próba się nie udała,
  // ostrzeżenie musi wrócić na ekran, a nie przepaść razem z błędem.
  const previous = force ? await pendingFor(headerMessageId) : null
  const duplicate = previous && previous.duplicate ? previous.duplicate : null

  await setPending(headerMessageId, { startedAt: Date.now() })
  try {
    const payload = {
      body,
      subject: subject || null,
      tone,
      source_channel: 'thunderbird',
      source_message_id: headerMessageId || null,
      // Powtórna zamiana: przez `runtime.sendMessage` data mogła stracić typ Date.
      source_from: senderHeader(sourceFrom),
      source_sent_at: toIsoDate(sourceSentAt),
    }
    if (force) payload.force = true

    const inquiry = await api('/api/inquiries', { method: 'POST', body: payload })

    await setSettings({ tone })
    await rememberInquiry(headerMessageId, inquiry.id)
    await setPending(headerMessageId, null)

    // Znacznik na liście od razu, bez czekania na kolejne przejście w tle.
    await markByHeaderId(headerMessageId)

    const { baseUrl } = await getSettings()
    await browser.windows.openDefaultBrowser(baseUrl + '/inquiries/' + inquiry.id)
    await notify('Zapytanie #' + inquiry.id + ' gotowe', 'Otworzyłem je w przeglądarce — wybierz produkty.')

    return { ok: true, id: inquiry.id }
  } catch (e) {
    // 409: ten sam mail prowadzi już kto inny. Nic nie zakładamy i nie otwieramy
    // przeglądarki — decyzję podejmuje człowiek w okienku nad mailem.
    const found = duplicateFromError(e)
    if (found !== null) {
      await setPending(headerMessageId, { startedAt: Date.now(), duplicate: found })
      await notify('Ten mail ma już zapytanie', duplicateNotice(found))

      return { ok: false, duplicate: found }
    }

    await setPending(headerMessageId, { startedAt: Date.now(), error: e.message, duplicate })
    await notify('Zapytanie nie powstało', e.message)

    return { ok: false, error: e.message }
  }
}

/**
 * Otwiera odpowiedź na mail i wstawia do niej list z aplikacji. Robione w tle,
 * bo okienko znika w chwili, gdy okno kompozycji przejmuje skupienie — razem
 * z ewentualnym komunikatem o błędzie.
 */
async function insertReply({ inquiryId, messageId }) {
  try {
    const inquiry = await api('/api/inquiries/' + inquiryId)
    const text = String(inquiry.reply_body || '').trim()
    if (text === '') {
      await notify('Odpowiedź nie jest gotowa', 'Dokończ ją w aplikacji, potem wróć tutaj.')

      return { ok: false }
    }

    const settings = await getSettings()
    // Tabela „pozycja z zapytania — nasza propozycja”; brak = ręcznie poprawiony
    // list, wtedy wysyłamy sam tekst, żeby nic się nie rozjechało.
    const table = String(inquiry.reply_html || '').trim()

    // Bez `details` w beginReply, żeby zachować cytat, adresata i podpis.
    const tab = await browser.compose.beginReply(messageId, 'replyToSender')
    const details = await composeDetailsWhenReady(tab.id)
    const before = String(details.isPlainText ? details.plainTextBody : details.body || '')

    const snippet = table !== '' ? table + '<br>' : textToHtml(text) + '<br><br>'
    const patch = details.isPlainText
      ? { plainTextBody: text + '\n\n' + (details.plainTextBody || '') }
      : { body: insertIntoHtmlBody(details.body, snippet) }
    if (settings.useAppSubject && inquiry.reply_subject) {
      patch.subject = inquiry.reply_subject
    }

    await browser.compose.setComposeDetails(tab.id, patch)

    // Sprawdzamy, czy treść faktycznie weszła — edytor potrafi odrzucić zmianę.
    const after = await browser.compose.getComposeDetails(tab.id)
    const written = String(after.isPlainText ? after.plainTextBody : after.body)
    const probe = text.slice(0, 24)
    const grew = written.length > before.length + 20
    if (!grew && !written.includes(probe) && !written.includes(escapeHtml(probe))) {
      await notify('Nie udało się wstawić treści', 'Skopiuj list z aplikacji i wklej ręcznie.')

      return { ok: false }
    }

    // Message-ID oryginału: po wysłaniu odpowiedzi przestawimy znacznik maila.
    let headerMessageId = null
    try {
      const header = await browser.messages.get(messageId)
      headerMessageId = header && header.headerMessageId ? header.headerMessageId : null
    } catch (e) {
      // Bez identyfikatora znacznik przestawi się przy najbliższym przejściu w tle.
    }

    await rememberComposeTab(tab.id, inquiry.id, headerMessageId)

    return { ok: true }
  } catch (e) {
    await notify('Nie udało się otworzyć odpowiedzi', e.message)

    return { ok: false, error: e.message }
  }
}

/* ---------------- wysyłka zlecona z aplikacji w przeglądarce ---------------- */

/** Co ile sekund pytamy serwer o listy czekające na wysłanie. */
const QUEUE_POLL_SECONDS = 15

/** Numeryczne id wiadomości ważne są tylko w tej sesji — mail szukamy po Message-ID. */
async function findMessageByHeaderId(headerMessageId) {
  const list = await browser.messages.query({ headerMessageId })
  const found = list && Array.isArray(list.messages) ? list.messages[0] : null

  return found || null
}

async function handleQueued(row) {
  const message = await findMessageByHeaderId(row.source_message_id)
  if (message === null) {
    await notify('Nie znalazłem maila', 'Zapytanie #' + row.id + ' — tej wiadomości nie ma w tym Thunderbirdzie.')

    return
  }

  await insertReply({ inquiryId: row.id, messageId: message.id })
}

/**
 * Przycisk „Zapisz i wyślij w Thunderbirdzie” w aplikacji zostawia na serwerze
 * prośbę — strona w przeglądarce nie ma jak sięgnąć do poczty na komputerze.
 * Prośbę kasujemy zawsze po podjęciu, żeby nie otwierać okna w kółko.
 */
async function pollQueue() {
  const { token } = await getSettings()
  if (!token) return

  let rows
  try {
    rows = await api('/api/inquiries/queued')
  } catch (e) {
    return
  }

  for (const row of Array.isArray(rows) ? rows : []) {
    try {
      await api('/api/inquiries/' + row.id + '/queue-reply', {
        method: 'POST',
        body: { queued: false },
      })
    } catch (e) {
      continue
    }

    try {
      await handleQueued(row)
    } catch (e) {
      await notify('Nie udało się otworzyć odpowiedzi', e.message)
    }
  }
}

setInterval(() => {
  pollQueue().catch((e) => console.warn('Sprawdzenie kolejki się nie powiodło:', e.message))
}, QUEUE_POLL_SECONDS * 1000)

/* --------------------------- aktualizacje dodatku --------------------------- */

/** Co ile godzin pytamy serwer o nową wersję dodatku. */
const UPDATE_CHECK_HOURS = 6

/** Ile sekund po starcie robimy pierwsze sprawdzenie — żeby nie opóźniać startu. */
const UPDATE_FIRST_CHECK_SECONDS = 45

/**
 * Cichy dozór nad wersją. Thunderbird sam pobiera aktualizacje z tego samego
 * updates.json, ale robi to po cichu i co kilka godzin — powiadomienie mówi
 * handlowcowi, że nowa wersja jest, i pozwala nie czekać.
 *
 * O każdej wersji mówimy tylko raz: powtórka przy każdym sprawdzeniu byłaby
 * uciążliwa.
 */
async function watchVersion() {
  let state
  try {
    state = await checkUpdate()
  } catch (e) {
    // Brak sieci albo wyłączony serwer nie jest tu żadnym zdarzeniem.
    return
  }
  if (!state.newer) return

  const { updateNotified } = await browser.storage.local.get({ updateNotified: '' })
  if (updateNotified === state.version) return

  await browser.storage.local.set({ updateNotified: state.version })
  await notify(
    'Nowa wersja dodatku ' + state.version,
    'Thunderbird zainstaluje ją sam. Od razu: Dodatki i motywy → koło zębate → Sprawdź dostępność aktualizacji.',
  )
}

setTimeout(() => {
  watchVersion().catch((e) => console.warn('Sprawdzenie wersji się nie powiodło:', e.message))
}, UPDATE_FIRST_CHECK_SECONDS * 1000)

setInterval(() => {
  watchVersion().catch((e) => console.warn('Sprawdzenie wersji się nie powiodło:', e.message))
}, UPDATE_CHECK_HOURS * 60 * 60 * 1000)

/* ------------------------- oznaczanie maili na liście ------------------------- */

/** Co ile minut pytamy serwer o nowe i zmienione zapytania. */
const TAG_SYNC_MINUTES = 5

/** Co które przejście sprawdzamy też maile bez zmian (usunięte zapytania). */
const TAG_FULL_EVERY = 6

/** Ile sekund po starcie robimy pierwsze przejście — żeby nie opóźniać startu. */
const TAG_FIRST_SYNC_SECONDS = 25

/** Ile sekund nie pytamy powtórnie o ten sam otwarty mail. */
const TAG_DISPLAY_QUIET_SECONDS = 60

/** Ostatnio sprawdzone otwarte maile — przeklikiwanie listy nie ma bić w serwer. */
const recentlyChecked = new Map()

let syncTick = 0

function checkedRecently(headerMessageId) {
  const at = recentlyChecked.get(headerMessageId)
  const now = Date.now()
  if (at !== undefined && now - at < TAG_DISPLAY_QUIET_SECONDS * 1000) return true

  recentlyChecked.set(headerMessageId, now)
  // Mapa nie może rosnąć bez końca przy całodziennej pracy.
  if (recentlyChecked.size > 500) recentlyChecked.clear()

  return false
}

/**
 * Otwarcie maila: sprawdzamy go od razu, bo to jedyna chwila, w której
 * handlowiec naprawdę patrzy — a zapytanie kolegi mogło powstać minutę temu.
 */
browser.messageDisplay.onMessageDisplayed.addListener(async (tab, message) => {
  const headerMessageId = message && message.headerMessageId ? message.headerMessageId : ''
  if (headerMessageId === '' || checkedRecently(headerMessageId)) return

  try {
    await markByHeaderId(headerMessageId)
  } catch (e) {
    console.warn('Nie udało się oznaczyć otwartego maila:', e.message)
  }
})

setTimeout(() => {
  syncTags({ full: true }).catch((e) => console.warn('Pierwsze przejście znaczników:', e.message))
}, TAG_FIRST_SYNC_SECONDS * 1000)

setInterval(() => {
  syncTick += 1
  syncTags({ full: syncTick % TAG_FULL_EVERY === 0 })
    .catch((e) => console.warn('Przejście znaczników się nie powiodło:', e.message))
}, TAG_SYNC_MINUTES * 60 * 1000)

browser.runtime.onMessage.addListener((request) => {
  if (request && request.type === 'createInquiry') {
    return createInquiry(request)
  }
  if (request && request.type === 'insertReply') {
    return insertReply(request)
  }
  // Ustawienia po włączeniu oznaczania: pierwsze przejście od razu, nie po 5 minutach.
  if (request && request.type === 'syncTags') {
    return syncTags({ full: true })
  }

  return undefined
})

/**
 * Po wysłaniu odpowiedzi oznaczamy zapytanie jako obsłużone. Okno kompozycji
 * rozpoznajemy po `tab.id` zapamiętanym przy jego otwieraniu — Message-ID nie
 * wystarczy, bo naraz może być otwartych kilka okien.
 */
browser.compose.onAfterSend.addListener(async (tab, info) => {
  // „sendLater” trafia do Skrzynki nadawczej, ale odpowiedź jest już zatwierdzona.
  if (info.mode !== 'sendNow' && info.mode !== 'sendLater') return

  const entry = await takeComposeTab(tab.id)
  if (entry === null) return

  try {
    await api('/api/inquiries/' + entry.inquiryId + '/replied', {
      method: 'POST',
      body: { replied: true },
    })
  } catch (e) {
    // Bez sieci zostaje ręczne „Oznacz wysłane” w aplikacji — nie blokujemy wysyłki.
    console.warn('Nie udało się oznaczyć zapytania ' + entry.inquiryId + ' jako wysłane:', e.message)

    return
  }

  // Zielony znacznik „Wysłane” od razu — także dla kolegów, po ich stronie
  // wyjdzie przy najbliższym przejściu w tle.
  await markByHeaderId(entry.headerMessageId)
})

/** Porzucone okna kompozycji nie mogą puchnąć w pamięci ustawień. */
browser.tabs.onRemoved.addListener(async (tabId) => {
  await takeComposeTab(tabId)
})
