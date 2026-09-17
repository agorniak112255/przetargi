/*
 * Tło dodatku. Tu dzieje się wszystko, co trwa dłużej niż mgnienie oka:
 * okienko nad mailem zamyka się przy pierwszym kliknięciu obok, więc analiza
 * uruchomiona w okienku zostałaby przerwana w połowie.
 */

/** Po tylu minutach uznajemy, że analiza przepadła, i pozwalamy spróbować ponownie. */
const PENDING_TIMEOUT_MIN = 15

async function getPending() {
  const { pending } = await browser.storage.local.get({ pending: {} })
  const now = Date.now()
  let changed = false
  for (const [key, entry] of Object.entries(pending)) {
    if (now - (entry.startedAt || 0) > PENDING_TIMEOUT_MIN * 60 * 1000) {
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
 */
async function createInquiry({ headerMessageId, subject, sourceFrom, sourceSentAt, body, tone }) {
  await setPending(headerMessageId, { startedAt: Date.now() })
  try {
    const inquiry = await api('/api/inquiries', {
      method: 'POST',
      body: {
        body,
        subject: subject || null,
        tone,
        source_channel: 'thunderbird',
        source_message_id: headerMessageId || null,
        // Powtórna zamiana: przez `runtime.sendMessage` data mogła stracić typ Date.
        source_from: senderHeader(sourceFrom),
        source_sent_at: toIsoDate(sourceSentAt),
      },
    })

    await setSettings({ tone })
    await rememberInquiry(headerMessageId, inquiry.id)
    await setPending(headerMessageId, null)

    const { baseUrl } = await getSettings()
    await browser.windows.openDefaultBrowser(baseUrl + '/inquiries/' + inquiry.id)
    await notify('Zapytanie #' + inquiry.id + ' gotowe', 'Otworzyłem je w przeglądarce — wybierz produkty.')

    return { ok: true, id: inquiry.id }
  } catch (e) {
    await setPending(headerMessageId, { startedAt: Date.now(), error: e.message })
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
    // Bez `details` w beginReply, żeby zachować cytat, adresata i podpis.
    const tab = await browser.compose.beginReply(messageId, 'replyToSender')
    const details = await composeDetailsWhenReady(tab.id)

    const patch = details.isPlainText
      ? { plainTextBody: text + '\n\n' + (details.plainTextBody || '') }
      : { body: insertIntoHtmlBody(details.body, textToHtml(text) + '<br><br>') }
    if (settings.useAppSubject && inquiry.reply_subject) {
      patch.subject = inquiry.reply_subject
    }

    await browser.compose.setComposeDetails(tab.id, patch)

    // Sprawdzamy, czy treść faktycznie weszła — edytor potrafi odrzucić zmianę.
    const after = await browser.compose.getComposeDetails(tab.id)
    const written = String(after.isPlainText ? after.plainTextBody : after.body)
    const probe = text.slice(0, 24)
    if (!written.includes(probe) && !written.includes(escapeHtml(probe))) {
      await notify('Nie udało się wstawić treści', 'Skopiuj list z aplikacji i wklej ręcznie.')

      return { ok: false }
    }

    await rememberComposeTab(tab.id, inquiry.id)

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

browser.runtime.onMessage.addListener((request) => {
  if (request && request.type === 'createInquiry') {
    return createInquiry(request)
  }
  if (request && request.type === 'insertReply') {
    return insertReply(request)
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

  const inquiryId = await takeComposeTab(tab.id)
  if (inquiryId === null) return

  try {
    await api('/api/inquiries/' + inquiryId + '/replied', {
      method: 'POST',
      body: { replied: true },
    })
  } catch (e) {
    // Bez sieci zostaje ręczne „Oznacz wysłane” w aplikacji — nie blokujemy wysyłki.
    console.warn('Nie udało się oznaczyć zapytania ' + inquiryId + ' jako wysłane:', e.message)
  }
})

/** Porzucone okna kompozycji nie mogą puchnąć w pamięci ustawień. */
browser.tabs.onRemoved.addListener(async (tabId) => {
  await takeComposeTab(tabId)
})
