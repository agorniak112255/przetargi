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
    // `loud`: to jest moment, w którym handlowiec patrzy — jeśli oznaczanie
    // nie działa, ma o tym usłyszeć teraz, a nie nigdy.
    //
    // Osobna osłona: zapytanie już powstało, więc błąd oznaczania nie może
    // wywołać komunikatu „Zapytanie nie powstało” — po takim komunikacie
    // handlowiec zakłada drugie i dostaje ostrzeżenie o duplikacie.
    try {
      await markByHeaderId(headerMessageId, { loud: true })
    } catch (e) {
      console.warn('Oznaczenie maila po założeniu zapytania się nie powiodło:', e.message)
    }

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
/**
 * Mail, na który wolno otworzyć odpowiedź do tego zapytania.
 *
 * Zasada: decyduje Message-ID zapisany w zapytaniu, a nie to, co jest w danej
 * chwili zaznaczone na liście. Wcześniej numeryczne id wiadomości podawało
 * okienko, a ścieżka z aplikacji brała pierwszą wiadomość z wyszukiwania —
 * przy niezgodności odpowiedź trafiała na inny mail niż ten z zapytania.
 *
 * Zapytanie wklejone w przeglądarce nie ma Message-ID; wtedy jedynym wskazaniem
 * jest mail otwarty w okienku dodatku.
 */
async function replyTargetFor(inquiry, messageId) {
  const wanted = normalizeMessageId(inquiry.source_message_id)

  if (wanted !== '') {
    const found = await findMessageByHeaderId(wanted)
    if (found === null) {
      await notify(
        'Nie znalazłem maila',
        'Zapytanie #' + inquiry.id + ' — wiadomości „' + wanted + '” nie ma w tym Thunderbirdzie.',
      )

      return null
    }

    return found
  }

  if (messageId === null || messageId === undefined) {
    await notify(
      'Nie wiem, na co odpowiedzieć',
      'Zapytanie #' + inquiry.id + ' nie pochodzi z maila — odpowiedz z aplikacji albo otwórz mail i użyj okienka dodatku.',
    )

    return null
  }

  try {
    return await browser.messages.get(messageId)
  } catch (e) {
    await notify('Nie znalazłem maila', 'Ta wiadomość nie jest już dostępna w Thunderbirdzie.')

    return null
  }
}

async function insertReply({ inquiryId, messageId = null, inquiry: known = null }) {
  // Czasy etapów: w konsoli dodatku zawsze, a gdy otwieranie trwa długo — też
  // w powiadomieniu. Bez nich nie da się powiedzieć, czy handlowiec czeka na
  // serwer, na wyszukanie maila w skrzynce, czy na samo okno odpowiedzi.
  const marks = { start: Date.now() }
  try {
    // Kolejka oddaje już całe zapytanie (treść listu, tabelę, Message-ID), więc
    // drugie pytanie o to samo było czystym czekaniem.
    const inquiry = known !== null ? known : await api('/api/inquiries/' + inquiryId)
    marks.inquiry = Date.now()
    const text = String(inquiry.reply_body || '').trim()
    if (text === '') {
      await notify('Odpowiedź nie jest gotowa', 'Dokończ ją w aplikacji, potem wróć tutaj.')

      return { ok: false }
    }

    // Mail bierzemy z zapytania, nie z zaznaczenia na liście.
    const target = await replyTargetFor(inquiry, messageId)
    if (target === null) return { ok: false }
    marks.target = Date.now()

    const settings = await getSettings()
    // Tabela „pozycja z zapytania — nasza propozycja”; brak = ręcznie poprawiony
    // list, wtedy wysyłamy sam tekst, żeby nic się nie rozjechało.
    const table = String(inquiry.reply_html || '').trim()

    // Bez `details` w beginReply, żeby zachować cytat, adresata i podpis.
    const tab = await browser.compose.beginReply(target.id, 'replyToSender')
    marks.window = Date.now()
    const details = await composeDetailsWhenReady(tab.id)
    marks.ready = Date.now()
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
    await rememberComposeTab(tab.id, inquiry.id, target.headerMessageId || null)
    await raiseComposeWindow(tab)
    await reportTiming(marks)

    return { ok: true }
  } catch (e) {
    await notify('Nie udało się otworzyć odpowiedzi', e.message)

    return { ok: false, error: e.message }
  }
}

/**
 * Okno odpowiedzi na wierzch. Handlowiec klika „Zapisz i wyślij” w przeglądarce
 * i nie ma jak zobaczyć, czy Thunderbird już zareagował — okno otwierało się
 * pod spodem, czasem na drugim ekranie. `drawAttention` zostaje na wypadek,
 * gdyby system nie pozwolił przełożyć okna na wierzch: wtedy przycisk
 * Thunderbirda miga na pasku zadań.
 */
async function raiseComposeWindow(tab) {
  try {
    await browser.tabs.update(tab.id, { active: true })
  } catch (e) {
    console.warn('Nie udało się uaktywnić karty odpowiedzi:', e.message)
  }
  try {
    await browser.windows.update(tab.windowId, { focused: true, drawAttention: true })
  } catch (e) {
    console.warn('Nie udało się podnieść okna odpowiedzi:', e.message)
  }
}

/** Od tylu sekund oczekiwania mówimy handlowcowi, na co poszedł czas. */
const SLOW_REPLY_SECONDS = 5

/**
 * Czasy etapów otwierania odpowiedzi. W konsoli dodatku zawsze, a gdy całość
 * trwała długo — także w powiadomieniu: inaczej „u mnie się wlecze” zostaje
 * bez liczb, a bez liczb nie wiadomo, co naprawiać.
 */
async function reportTiming(marks) {
  const parts = [
    ['zapytanie', marks.inquiry - marks.start],
    ['szukanie maila', marks.target - marks.inquiry],
    ['otwarcie okna', marks.window - marks.target],
    ['gotowy cytat', marks.ready - marks.window],
    ['wstawienie treści', Date.now() - marks.ready],
  ]
  const total = Date.now() - marks.start
  const text = parts.map(([label, ms]) => label + ' ' + ms + ' ms').join(', ')
  console.info('Supon: odpowiedź w ' + total + ' ms (' + text + ')')

  if (total >= SLOW_REPLY_SECONDS * 1000) {
    await notify('Okno odpowiedzi po ' + Math.round(total / 1000) + ' s', text)
  }
}

/* ---------------- wysyłka zlecona z aplikacji w przeglądarce ---------------- */

/**
 * Co ile sekund pytamy serwer o listy czekające na wysłanie. Pytanie jest tanie
 * (jedno zapytanie po zapytaniach tego handlowca z ostatniej doby, ~60 ms), a to
 * ono decyduje, ile handlowiec czeka po kliknięciu „Zapisz i wyślij” w aplikacji:
 * przy 15 sekundach czekał średnio 7 sekund na sam początek pracy dodatku.
 */
const QUEUE_POLL_SECONDS = 5

/** Jedno przejście naraz: okno odpowiedzi otwiera się dłużej niż odstęp między przejściami. */
let queueBusy = false

/**
 * Mail o podanym Message-ID. Numeryczne id wiadomości ważne są tylko w tej
 * sesji, więc do odpowiedzi szukamy maila po identyfikatorze z zapytania.
 *
 * Pusty identyfikator odrzucamy od razu: `messages.query({})` bez filtra oddaje
 * CAŁĄ skrzynkę, a wzięcie z niej pierwszej wiadomości otwierało odpowiedź na
 * przypadkowym mailu. Z kilku kopii tej samej wiadomości wybieramy tę ze
 * zwykłego folderu — kopia w Wysłanych czy w Koszu to nie jest mail klienta.
 */
async function findMessageByHeaderId(headerMessageId) {
  const wanted = normalizeMessageId(headerMessageId)
  if (wanted === '') return null

  let list
  try {
    list = await browser.messages.query({ headerMessageId: wanted })
  } catch (e) {
    return null
  }

  const exact = (list && Array.isArray(list.messages) ? list.messages : [])
    .filter((message) => normalizeMessageId(message.headerMessageId) === wanted)
  if (exact.length === 0) return null

  // `skipFolder` (tags.js) rozpoznaje Wysłane, Kosz, Szkice i spam; bez
  // uprawnienia do kont Thunderbird nie poda folderu i wtedy bierzemy pierwszą.
  const normal = exact.filter((message) => {
    try {
      return message.folder === undefined || message.folder === null || !skipFolder(message.folder)
    } catch (e) {
      return true
    }
  })

  return (normal.length > 0 ? normal : exact)[0]
}

async function handleQueued(row) {
  // Maila wskazuje samo zapytanie — insertReply znajdzie go po Message-ID
  // i nie otworzy odpowiedzi na żadnym innym.
  await insertReply({ inquiryId: row.id, inquiry: row })
}

/**
 * Przycisk „Zapisz i wyślij w Thunderbirdzie” w aplikacji zostawia na serwerze
 * prośbę — strona w przeglądarce nie ma jak sięgnąć do poczty na komputerze.
 * Prośbę kasujemy zawsze po podjęciu, żeby nie otwierać okna w kółko.
 */
async function pollQueue() {
  if (queueBusy) return
  const { token } = await getSettings()
  if (!token) return

  queueBusy = true
  try {
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

      const started = Date.now()
      try {
        await handleQueued(row)
        console.info('Supon: odpowiedź na zapytanie #' + row.id + ' otwarta w ' + (Date.now() - started) + ' ms')
      } catch (e) {
        await notify('Nie udało się otworzyć odpowiedzi', e.message)
      }
    }
  } finally {
    queueBusy = false
  }
}

setInterval(() => {
  pollQueue().catch((e) => console.warn('Sprawdzenie kolejki się nie powiodło:', e.message))
}, QUEUE_POLL_SECONDS * 1000)

/* --------------------------- aktualizacje dodatku --------------------------- */

/** Co ile godzin pytamy serwer o nową wersję dodatku. */
const UPDATE_CHECK_HOURS = 2

/** Po tylu godzinach przypominamy o tej samej nowej wersji jeszcze raz. */
const UPDATE_REMIND_HOURS = 20

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

  const { updateNotified } = await browser.storage.local.get({ updateNotified: null })
  const said = updateNotified && typeof updateNotified === 'object' ? updateNotified : {}
  const fresh = said.version !== state.version
  const quiet = Date.now() - (said.at || 0) < UPDATE_REMIND_HOURS * 60 * 60 * 1000
  if (! fresh && quiet) return

  await browser.storage.local.set({ updateNotified: { version: state.version, at: Date.now() } })
  await notify(
    'Nowa wersja dodatku ' + state.version,
    'Thunderbird zainstaluje ją sam w ciągu doby. Od razu: Dodatki i motywy → koło zębate → Sprawdź dostępność aktualizacji.',
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
const TAG_SYNC_MINUTES = 2

/**
 * Co które przejście sprawdzamy też maile bez zmian. To z tego sprawdzenia
 * znika oznaczenie po usunięciu zapytania w aplikacji, więc nie musi być często
 * — co piętnaste przejście, czyli mniej więcej co pół godziny.
 */
const TAG_FULL_EVERY = 15

/** Ile sekund po starcie robimy pierwsze przejście — żeby nie opóźniać startu. */
const TAG_FIRST_SYNC_SECONDS = 25

let syncTick = 0

/*
 * Przy otwarciu maila dodatek NIE pyta serwera.
 *
 * Wcześniej każde kliknięcie w inny mail szło własnym zapytaniem do aplikacji,
 * czytało listę znaczników i przerysowywało listę wiadomości — przy przewijaniu
 * skrzynki strzałkami robiło się z tego kilkanaście przebiegów na sekundę i to
 * właśnie spowalniało pocztę. Nic na tym nie tracimy: przejście w tle i tak
 * pyta o zmiany co dwie minuty, a okienko nad mailem sprawdza stan na żywo,
 * gdy handlowiec sam je otworzy.
 */

/**
 * Kolumna od razu po starcie tła — nie ma na co czekać, bo pokazuje to, co już
 * zapamiętane. Gdy się nie uda (API eksperymentalne wchodzi dopiero przy
 * starcie Thunderbirda), mówimy o tym raz powiadomieniem: bez tego brak
 * kolumny wyglądał jak awaria bez przyczyny.
 */
async function startColumn() {
  const state = await showColumn()
  if (state.ok) return

  const { columnComplainedAt } = await browser.storage.local.get({ columnComplainedAt: 0 })
  if (Date.now() - Number(columnComplainedAt || 0) < 24 * 60 * 60 * 1000) return

  await browser.storage.local.set({ columnComplainedAt: Date.now() })
  await notify('Kolumna „Prowadzi” jeszcze nie działa', state.reason)
}

startColumn().catch((e) => console.warn('Kolumna przy starcie:', e.message))

setTimeout(() => {
  // Druga próba: gdy Thunderbird ładował się dłużej niż tło dodatku.
  showColumn()
    .then(() => syncTags({ full: true }))
    .catch((e) => console.warn('Pierwsze przejście znaczników:', e.message))
}, TAG_FIRST_SYNC_SECONDS * 1000)

setInterval(() => {
  syncTick += 1
  syncTags({ full: syncTick % TAG_FULL_EVERY === 0 })
    .catch((e) => console.warn('Przejście znaczników się nie powiodło:', e.message))
}, TAG_SYNC_MINUTES * 60 * 1000)

/**
 * Numery kart okien odpowiedzi żyją tylko w jednej sesji Thunderbirda, a wpis
 * w pamięci dodatku przeżywa restart. Bez tego sprzątania przypadkowe okno
 * o tym samym numerze zostałoby po restarcie wzięte za odpowiedź na stare
 * zapytanie — aplikacja dostałaby „wysłane” dla cudzej sprawy.
 */
browser.storage.local.set({ composeTabs: {} }).catch((e) => {
  console.warn('Nie udało się wyczyścić okien odpowiedzi:', e.message)
})

browser.runtime.onMessage.addListener((request) => {
  if (request && request.type === 'createInquiry') {
    return createInquiry(request)
  }
  if (request && request.type === 'insertReply') {
    return insertReply(request)
  }
  // Ustawienia po włączeniu oznaczania: pierwsze przejście od razu, nie po 5 minutach.
  // `reset` przechodzi całe okno 90 dni od nowa — potrzebne po świeżo danej
  // zgodzie, bo maile sprawdzone bez niej mają już przesunięty znacznik czasu.
  if (request && request.type === 'syncTags') {
    return request.reset === true ? resetTagSync() : syncTags({ full: true })
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
