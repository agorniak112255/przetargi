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
async function createInquiry({ headerMessageId, subject, body, tone }) {
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

browser.runtime.onMessage.addListener((request) => {
  if (request && request.type === 'createInquiry') {
    return createInquiry(request)
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
