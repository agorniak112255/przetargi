/* Okienko nad otwartym mailem: założenie zapytania i wstawienie odpowiedzi. */

const el = (id) => document.getElementById(id)

let message = null
let inquiryId = null

function show(section) {
  for (const name of ['setup', 'known', 'fresh', 'working']) {
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

function loadKnown(id, replied) {
  inquiryId = id
  el('knownId').textContent = '#' + id
  el('knownReplied').hidden = !replied
  el('knownReplied').textContent = replied ? 'Odpowiedź została już wysłana.' : ''
  show('known')
}

async function pendingFor(messageId) {
  const { pending } = await browser.storage.local.get({ pending: {} })
  const entry = pending[messageId]
  if (!entry) return null
  if (Date.now() - (entry.startedAt || 0) > 15 * 60 * 1000) return null

  return entry
}

function showVersion() {
  const version = addonVersion()
  el('version').textContent = version === '' ? '' : 'Supon Przetargi ' + version
}

async function init() {
  showVersion()

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
      loadKnown(inquiry.id, inquiry.replied_at !== null)

      return
    } catch (e) {
      if (e.status !== 404 && e.status !== 403) {
        status(e.message, 'error')

        return
      }
      // zapytanie usunięte albo cudze — zakładamy nowe
    }
  }

  const pending = await pendingFor(message.headerMessageId)
  if (pending && !pending.error) {
    show('working')

    return
  }
  if (pending && pending.error) {
    status('Poprzednia próba się nie udała: ' + pending.error, 'error')
  }

  let full
  try {
    full = await browser.messages.getFull(message.id)
  } catch (e) {
    status('Nie mogę odczytać treści maila (możliwe, że jest zaszyfrowany).', 'error')

    return
  }

  const text = messageText(full)
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

  busy(true)
  // Analizę prowadzi tło dodatku, więc zamknięcie okienka jej nie przerywa.
  browser.runtime.sendMessage({
    type: 'createInquiry',
    headerMessageId: message.headerMessageId || null,
    subject: message.subject || '',
    // Nagłówek From i data maila — z listy wiadomości, nie z jego treści.
    sourceFrom: senderHeader(message.author),
    sourceSentAt: toIsoDate(message.date),
    body,
    tone: el('tone').value,
  })

  show('working')
  status('')
  busy(false)
}

function insertReply() {
  busy(true)
  // Wstawianiem zajmuje się tło: okienko zniknie, gdy okno odpowiedzi
  // przejmie skupienie, a wtedy przepadłby też komunikat o błędzie.
  browser.runtime.sendMessage({
    type: 'insertReply',
    inquiryId,
    messageId: message.id,
  })
  status('Otwieram okno odpowiedzi…', 'info')
}

el('openOptions').addEventListener('click', () => browser.runtime.openOptionsPage())
el('openApp').addEventListener('click', () => openInquiryInBrowser(inquiryId))
el('insertReply').addEventListener('click', insertReply)
el('send').addEventListener('click', send)
el('body').addEventListener('input', updateCounter)

init().catch((e) => status(e.message || String(e), 'error'))
