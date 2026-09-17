/* Okienko nad otwartym mailem: założenie zapytania i wstawienie odpowiedzi. */

const el = (id) => document.getElementById(id)

let message = null
let inquiryId = null

/** Cudze zapytanie na ten sam mail (z odpowiedzi 409) albo null. */
let duplicate = null

/** Treść maila odczytana przy otwarciu okienka — potrzebna przy „Załóż mimo to”. */
let sourceText = ''

/** Handlowiec zobaczył już cudze zapytanie i mimo to chce założyć własne. */
let forced = false

function show(section) {
  for (const name of ['setup', 'known', 'fresh', 'working', 'duplicate']) {
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

/**
 * Ekran duplikatu: kto prowadzi zapytanie, od kiedy, czy już odpowiedział
 * klientowi. Dane pochodzą z odpowiedzi 409 zapisanej przez tło dodatku,
 * więc przetrwały zamknięcie okienka.
 */
function loadDuplicate(found, text) {
  duplicate = found
  sourceText = text

  const when = formatDateTime(found.created_at)
  el('dupLead').textContent = 'Zapytanie #' + found.id + ' z tego maila prowadzi już '
    + duplicateOwner(found) + (when === '' ? '.' : ' — od ' + when + '.')

  const replied = duplicateRepliedNote(found)
  el('dupReplied').hidden = replied === ''
  el('dupReplied').textContent = replied

  el('dupMatch').textContent = duplicateMatchNote(found)

  const subject = String(found.source_subject || '').trim()
  el('dupSubject').hidden = subject === ''
  el('dupSubject').textContent = subject === '' ? '' : 'Mail: „' + subject + '”'

  show('duplicate')
}

/** Treść otwartego maila; null, gdy nie da się jej odczytać (komunikat już poszedł). */
async function readBody() {
  let full
  try {
    full = await browser.messages.getFull(message.id)
  } catch (e) {
    status('Nie mogę odczytać treści maila (możliwe, że jest zaszyfrowany).', 'error')

    return null
  }

  return messageText(full)
}

/** Założenie zapytania prowadzi tło — zamknięcie okienka go nie przerywa. */
function sendToBackground(body, tone, force) {
  busy(true)
  browser.runtime.sendMessage({
    type: 'createInquiry',
    headerMessageId: message.headerMessageId || null,
    subject: message.subject || '',
    // Nagłówek From i data maila — z listy wiadomości, nie z jego treści.
    sourceFrom: senderHeader(message.author),
    sourceSentAt: toIsoDate(message.date),
    body,
    tone,
    force,
  })

  show('working')
  status('')
  busy(false)
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
  if (pending && !pending.error && !pending.duplicate) {
    show('working')

    return
  }
  if (pending && pending.error) {
    status('Poprzednia próba się nie udała: ' + pending.error, 'error')
  }

  const text = await readBody()
  if (text === null) return
  sourceText = text

  // Ten sam mail prowadzi już kto inny — najpierw pokazujemy jego zapytanie.
  if (pending && pending.duplicate) {
    loadDuplicate(pending.duplicate, text)

    return
  }

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

  sendToBackground(body, el('tone').value, forced)
}

/** „Załóż mimo to” — własne zapytanie obok cudzego; aplikacja je powiąże. */
async function forceCreate() {
  const settings = await getSettings()
  if (sourceText.trim().length < 20) {
    // Bez treści nie ma czego analizować — wracamy do zwykłego ekranu,
    // ale zapamiętujemy, że to świadome założenie kopii.
    forced = true
    el('tone').value = settings.tone
    el('body').value = sourceText
    updateCounter()
    show('fresh')
    status('Treść maila jest za krótka — uzupełnij ją i wyślij.', 'warn')

    return
  }

  sendToBackground(sourceText, settings.tone, true)
}

/** „Anuluj” — kasuje zapamiętane ostrzeżenie i wraca do zwykłego ekranu. */
async function cancelDuplicate() {
  await setPending(message.headerMessageId || null, null)
  duplicate = null
  status('')
  await init()
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
el('dupOpen').addEventListener('click', () => openInquiryInBrowser(duplicate.id))
el('dupForce').addEventListener('click', () => {
  forceCreate().catch((e) => status(e.message || String(e), 'error'))
})
el('dupCancel').addEventListener('click', () => {
  cancelDuplicate().catch((e) => status(e.message || String(e), 'error'))
})
el('body').addEventListener('input', updateCounter)

init().catch((e) => status(e.message || String(e), 'error'))
