/* Okienko nad otwartym mailem: założenie zapytania i wstawienie odpowiedzi. */

const el = (id) => document.getElementById(id)

let message = null
let inquiryId = null

function show(section) {
  for (const name of ['setup', 'known', 'fresh']) {
    el(name).hidden = name !== section
  }
}

function status(text, kind = 'info') {
  const box = el('status')
  box.textContent = text
  box.className = 'status ' + kind
  box.hidden = text === ''
}

function busy(on, text = '') {
  for (const button of document.querySelectorAll('button')) {
    button.disabled = on
  }
  if (text !== '') status(text, 'info')
}

/** Mail otwarty w karcie albo w osobnym oknie. */
async function displayedMessage() {
  const queries = [{ active: true, currentWindow: true }, { active: true, lastFocusedWindow: true }]
  for (const query of queries) {
    const tabs = await browser.tabs.query(query)
    for (const tab of tabs) {
      try {
        const found = await browser.messageDisplay.getDisplayedMessage(tab.id)
        if (found) return found
      } catch (e) {
        // karta bez wyświetlonej wiadomości — próbujemy dalej
      }
    }
  }

  return null
}

async function openInquiryInBrowser(id) {
  const { baseUrl } = await getSettings()
  await browser.windows.openDefaultBrowser(baseUrl + '/inquiries/' + id)
}

async function loadKnown(id, replied) {
  inquiryId = id
  el('knownId').textContent = '#' + id
  el('knownReplied').hidden = !replied
  el('knownReplied').textContent = replied ? 'Odpowiedź została już wysłana.' : ''
  show('known')
}

async function init() {
  const settings = await getSettings()
  if (!settings.token) {
    show('setup')

    return
  }

  message = await displayedMessage()
  if (!message) {
    status('Otwórz mail, z którego mam założyć zapytanie.', 'warn')

    return
  }

  const known = await knownInquiry(message.headerMessageId)
  if (known !== null) {
    try {
      const inquiry = await api('/api/inquiries/' + known)
      await loadKnown(inquiry.id, inquiry.replied_at !== null)

      return
    } catch (e) {
      if (e.status === 404 || e.status === 403) {
        // zapytanie usunięte albo cudze — zakładamy nowe
      } else {
        status(e.message, 'error')

        return
      }
    }
  }

  let full
  try {
    full = await browser.messages.getFull(message.id)
  } catch (e) {
    status('Nie mogę odczytać treści maila (możliwe, że jest zaszyfrowany).', 'error')

    return
  }

  const text = cleanBody(messageText(full))
  if (text.length < 20) {
    status('Treść maila jest za krótka do analizy — uzupełnij ją poniżej.', 'warn')
  }

  el('tone').value = settings.tone
  el('body').value = text
  updateCounter()
  show('fresh')
}

function updateCounter() {
  const length = el('body').value.trim().length
  el('counter').textContent = length + ' znaków' + (length < 20 ? ' — za mało, potrzeba co najmniej 20' : '')
}

async function send() {
  const body = el('body').value.trim()
  if (body.length < 20) {
    status('Treść jest za krótka — potrzeba co najmniej 20 znaków.', 'warn')

    return
  }

  const tone = el('tone').value
  busy(true, 'Analizuję zapytanie… to może potrwać ponad minutę.')
  try {
    const inquiry = await api('/api/inquiries', {
      method: 'POST',
      body: {
        body,
        subject: message.subject || null,
        tone,
        source_channel: 'thunderbird',
        source_message_id: message.headerMessageId || null,
      },
    })
    await setSettings({ tone })
    await rememberInquiry(message.headerMessageId, inquiry.id)
    await openInquiryInBrowser(inquiry.id)
    status('Gotowe — zapytanie #' + inquiry.id + ' czeka w przeglądarce.', 'ok')
    await loadKnown(inquiry.id, inquiry.replied_at !== null)
  } catch (e) {
    status(e.message, 'error')
  } finally {
    busy(false)
  }
}

async function insertReply() {
  busy(true, 'Pobieram odpowiedź…')
  try {
    const inquiry = await api('/api/inquiries/' + inquiryId)
    const text = String(inquiry.reply_body || '').trim()
    if (text === '') {
      status('Odpowiedź nie jest jeszcze gotowa — dokończ ją w aplikacji.', 'warn')

      return
    }

    const settings = await getSettings()
    // Bez `details` w beginReply, żeby zachować cytat, adresata i podpis.
    const tab = await browser.compose.beginReply(message.id, 'replyToSender')
    const details = await browser.compose.getComposeDetails(tab.id)
    const patch = details.isPlainText
      ? { plainTextBody: text + '\n\n' + (details.plainTextBody || '') }
      : { body: textToHtml(text) + '<br><br>' + (details.body || '') }
    if (settings.useAppSubject && inquiry.reply_subject) {
      patch.subject = inquiry.reply_subject
    }

    await browser.compose.setComposeDetails(tab.id, patch)
    await rememberComposeTab(tab.id, inquiry.id)
    window.close()
  } catch (e) {
    status(e.message || String(e), 'error')
  } finally {
    busy(false)
  }
}

el('openOptions').addEventListener('click', () => browser.runtime.openOptionsPage())
el('openApp').addEventListener('click', () => openInquiryInBrowser(inquiryId))
el('insertReply').addEventListener('click', insertReply)
el('send').addEventListener('click', send)
el('body').addEventListener('input', updateCounter)

init().catch((e) => status(e.message || String(e), 'error'))
