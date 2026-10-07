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

/** Po „Anuluj” nie pokazujemy ponownie ostrzeżenia znalezionego na serwerze. */
let skipServerDuplicate = false

/**
 * Załączniki maila, z których aplikacja umie wyciągnąć tekst. `state`: idle
 * (czeka albo odznaczony), loading, loaded (tekst jest w polu treści), error.
 */
let attachments = []

/** Załączniki pominięte — zdjęcia, archiwa, za duże pliki. */
let skippedAttachments = []

/** Widoczna sekcja — po odmowie spoza sieci wracamy z „Zakładam zapytanie…” tam, skąd przyszliśmy. */
let currentSection = null

function show(section) {
  currentSection = section
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

/**
 * Aplikacja odrzuciła połączenie spoza sieci firmy (od 1.36.0) — jeden komunikat na górze okienka, z przyciskiem
 * do aplikacji w przeglądarce (tam wpisuje się kod z e-maila). Kolejne odmowy (zapytanie, czat) tylko go
 * odświeżają, nie dokładają drugiego. Zwykły pasek stanu chowamy: mówiłby to samo innymi słowami.
 */
function showNetworkDenied(text) {
  el('networkText').textContent = text
  el('network').hidden = false
  status('')
}

/** Błąd z api() albo wynik z tła (`{ network: true, error }`) — true, gdy to odmowa spoza sieci i komunikat już jest. */
function handleNetworkDenied(error) {
  if (!error || error.network !== true) return false
  showNetworkDenied(error.message || error.error || '')

  return true
}

async function openAppInBrowser() {
  const { baseUrl } = await getSettings()
  await browser.windows.openDefaultBrowser(baseUrl + '/')
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

/**
 * Zapytania z tego maila — u kogokolwiek i z któregokolwiek komputera.
 * Lokalna pamięć dodatku zna tylko własne zapytania założone na tej maszynie,
 * a chodzi o to, żeby było widać także zapytanie kolegi (i własne, gdy zakładał
 * je na innym komputerze).
 */
async function inquiriesForMessage(headerMessageId) {
  if (!headerMessageId) return []

  try {
    const data = await api('/api/inquiries/lookup', {
      method: 'POST',
      body: { message_ids: [headerMessageId] },
    })
    const rows = data && data.data ? data.data[headerMessageId] : null

    return Array.isArray(rows) ? rows : []
  } catch (e) {
    // Odmowa spoza sieci: wysłanie i tak by nie przeszło — null zatrzymuje okienko na komunikacie.
    if (handleNetworkDenied(e)) return null
    // Bez połączenia zachowujemy się jak dotąd — ekran zwykłego wysłania.
    return []
  }
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

/* ------------------------------ załączniki ------------------------------- */

function extensionOf(name) {
  const match = /\.([a-z0-9]+)$/i.exec(String(name || ''))

  return match ? match[1].toLowerCase() : ''
}

function formatSize(bytes) {
  if (!(bytes > 0)) return ''
  if (bytes < 1024 * 1024) return Math.max(1, Math.round(bytes / 1024)) + ' kB'

  return (bytes / 1024 / 1024).toFixed(1).replace('.', ',') + ' MB'
}

/** Załączniki otwartego maila podzielone na te do odczytu i pominięte. */
async function loadAttachments() {
  attachments = []
  skippedAttachments = []
  let parts = []
  try {
    parts = await browser.messages.listAttachments(message.id)
  } catch (e) {
    console.warn('Nie udało się odczytać listy załączników:', e.message)
  }
  const seen = {}
  for (const part of parts || []) {
    const name = String(part.name || '').trim()
    if (name === '') continue
    if (!ATTACHMENT_EXTENSIONS.includes(extensionOf(name))) {
      skippedAttachments.push(name)
    } else if (part.size > MAX_ATTACHMENT_BYTES) {
      skippedAttachments.push(name + ' (ponad 20 MB)')
    } else {
      // Dwa pliki o tej samej nazwie muszą mieć różne nagłówki — po nagłówku
      // znajdujemy fragment do usunięcia, a drugi taki sam nie zostałby dopisany.
      seen[name] = (seen[name] || 0) + 1
      attachments.push({
        partName: part.partName,
        name,
        size: part.size,
        marker: fileMarker(seen[name] === 1 ? name : name + ' (' + seen[name] + ')'),
        checked: true,
        state: 'idle',
        chars: 0,
        note: '',
      })
    }
  }
}

function attachmentNote(item) {
  if (item.state === 'loading') return 'odczytuję…'
  if (item.state === 'loaded') return 'dopisany do treści — ' + item.chars + ' znaków'
  if (item.state === 'error') return item.note

  return item.checked ? 'czeka na odczyt' : 'pominięty'
}

function renderAttachments() {
  const list = el('attachmentList')
  list.textContent = ''
  for (const item of attachments) {
    const row = document.createElement('label')
    row.className = 'check'
    const box = document.createElement('input')
    box.type = 'checkbox'
    box.checked = item.checked
    box.disabled = item.state === 'loading'
    box.addEventListener('change', () => toggleAttachment(item, box.checked))
    const text = document.createElement('span')
    const size = formatSize(item.size)
    text.textContent = item.name + (size === '' ? '' : ' · ' + size)
    const note = document.createElement('span')
    note.className = 'note' + (item.state === 'error' ? ' error' : '')
    note.textContent = attachmentNote(item)
    text.appendChild(note)
    row.append(box, text)
    list.appendChild(row)
  }

  const shown = skippedAttachments.slice(0, 4).join(', ')
  const more = skippedAttachments.length > 4 ? ' i ' + (skippedAttachments.length - 4) + ' innych' : ''
  el('attachmentsSkipped').hidden = skippedAttachments.length === 0
  el('attachmentsSkipped').textContent = skippedAttachments.length === 0
    ? ''
    : 'Nie odczytam (tylko PDF, Excel, Word): ' + shown + more + '.'
  el('attachments').hidden = attachments.length === 0 && skippedAttachments.length === 0
}

/**
 * Tekst pliku w polu treści: od wiersza z jego nagłówkiem do nagłówka
 * następnego pliku albo do końca. null, gdy nagłówka nie ma (handlowiec go usunął).
 */
function findFileBlock(text, marker) {
  const lines = String(text).split('\n')
  const from = lines.findIndex((line) => line.trim() === marker)
  if (from < 0) return null
  let to = lines.length
  for (let i = from + 1; i < lines.length; i++) {
    if (/^=== Plik klienta: .+ ===$/.test(lines[i].trim())) {
      to = i
      break
    }
  }

  return { before: lines.slice(0, from).join('\n'), after: lines.slice(to).join('\n') }
}

function appendFileBlock(marker, text) {
  const current = el('body').value
  if (findFileBlock(current, marker) !== null) return
  const block = marker + '\n' + String(text).trim()
  el('body').value = current.trim() === '' ? block : current.trimEnd() + '\n\n' + block
}

function removeFileBlock(marker) {
  const found = findFileBlock(el('body').value, marker)
  if (found === null) return
  el('body').value = [found.before.trimEnd(), found.after.trimStart()].filter((part) => part !== '').join('\n\n')
}

/** Plik idzie do aplikacji tylko po tekst — zapytanie jeszcze nie powstaje, plik nie jest zapisywany. */
async function includeAttachment(item) {
  if (item.state === 'loading') return
  item.state = 'loading'
  item.note = ''
  renderAttachments()
  try {
    const file = await browser.messages.getAttachmentFile(message.id, item.partName)
    const form = new FormData()
    form.append('file', file, item.name)
    const res = await api('/api/inquiries/file-text', { method: 'POST', body: form })
    // odznaczony w trakcie odczytu — nie dopisujemy
    if (!item.checked) {
      item.state = 'idle'

      return
    }
    const text = String((res && res.text) || '')
    appendFileBlock(item.marker, text)
    item.state = 'loaded'
    item.chars = text.length
  } catch (e) {
    item.state = 'error'
    item.checked = false
    item.note = handleNetworkDenied(e) ? 'nieodczytany — brak dostępu spoza sieci firmy' : e.message || String(e)
  } finally {
    renderAttachments()
    updateCounter()
  }
}

function toggleAttachment(item, on) {
  item.checked = on
  if (on) {
    includeAttachment(item)

    return
  }
  removeFileBlock(item.marker)
  if (item.state === 'loaded') item.state = 'idle'
  renderAttachments()
  updateCounter()
}

/** Zaznaczone załączniki po kolei — w kolejności z maila, bez zasypywania serwera. */
async function offerAttachments() {
  await loadAttachments()
  renderAttachments()
  for (const item of attachments) {
    if (item.checked && item.state === 'idle') await includeAttachment(item)
  }
}

function attachmentsPending() {
  return attachments.some((item) => item.checked && (item.state === 'idle' || item.state === 'loading'))
}

/** Nazwy plików, których tekst jest w wysyłanej treści — aplikacja pokaże je przy zapytaniu. */
function includedFileNames(body) {
  const names = attachments
    .filter((item) => item.state === 'loaded' && item.checked && findFileBlock(body, item.marker) !== null)
    .map((item) => item.name)
    .join(', ')

  return names === '' ? null : names.slice(0, 255)
}

/* ------------------------------------------------------------------------- */

/** Założenie zapytania prowadzi tło — zamknięcie okienka go nie przerywa. */
function sendToBackground(body, tone, force, fileNames = null) {
  busy(true)
  const back = currentSection
  browser.runtime.sendMessage({
    type: 'createInquiry',
    headerMessageId: message.headerMessageId || null,
    // Numer otwartej wiadomości: dzięki niemu pierwsza wysyłka odpowiedzi
    // nie musi przeszukiwać całej skrzynki.
    messageId: message.id,
    subject: message.subject || '',
    // Nagłówek From i data maila — z listy wiadomości, nie z jego treści.
    sourceFrom: senderHeader(message.author),
    sourceSentAt: toIsoDate(message.date),
    body,
    tone,
    force,
    fileNames,
  }).then((result) => {
    // Odmowa spoza sieci, a okienko jeszcze otwarte: komunikat i powrót do treści — po wpisaniu kodu
    // w przeglądarce wystarczy kliknąć jeszcze raz.
    if (result && result.network === true && currentSection === 'working') {
      show(back)
      handleNetworkDenied(result)
    }
  }).catch(() => {})

  show('working')
  status('')
  busy(false)
}

function showVersion() {
  const version = addonVersion()
  el('version').textContent = version === '' ? '' : 'Supon Przetargi ' + version
}

/** Nowa wersja dodatku — z ostatniego sprawdzenia w tle, bez pytania serwera. */
async function showUpdateNotice() {
  const state = await lastUpdateCheck()
  const waiting = state !== null && state.newer === true
  el('update').hidden = !waiting
  el('update').textContent = waiting
    ? 'Pracujesz na starej wersji ' + addonVersion() + '. Na serwerze jest ' + state.version + '.'
    : ''
  el('getUpdate').hidden = !waiting
  el('getUpdate').dataset.link = waiting ? String(state.link || '') : ''
}

/**
 * Oznaczanie maili wymaga zgody na zmianę znaczników. O zgodę wolno poprosić
 * tylko w odpowiedzi na kliknięcie, a okienko nad mailem jest jedynym miejscem,
 * do którego handlowiec zagląda codziennie — ustawień dodatku nie otwiera nikt.
 */
async function showTagsOffer() {
  // Gdy działa kolumna „Prowadzi”, znaczniki są zbędne — nie namawiamy do nich.
  // Pasek zostaje tylko dla tych, u których kolumny nie ma (starszy Thunderbird).
  try {
    const state = await columnState()
    if (state.ok) {
      el('tagsOff').hidden = true

      return
    }
  } catch (e) {
    // Brak odpowiedzi o kolumnę traktujemy jak jej brak.
  }

  let allowed = true
  let partly = false
  try {
    allowed = await tagsAllowed()
    partly = allowed ? false : await tagsPartlyAllowed()
  } catch (e) {
    allowed = true
  }
  el('tagsOff').hidden = allowed
  el('enableTags').textContent = partly ? 'Dokończ włączanie' : 'Włącz oznaczanie'
  if (partly) {
    el('tagsOff').firstChild.textContent = 'Aktualizacja dodatku wymaga jednego kliknięcia — '
      + 'dochodzi zgoda na odczyt listy znaczników. '
  }
}

async function enableTags() {
  try {
    const granted = await requestTagPermissions()
    if (!granted) {
      status('Bez zgody na zmianę znaczników maile nie będą oznaczane.', 'warn')

      return
    }
    el('tagsOff').hidden = true
    status('Oznaczanie włączone — znaczniki pojawią się w ciągu kilku minut.', 'ok')
    browser.runtime.sendMessage({ type: 'syncTags', reset: true })
  } catch (e) {
    status('Zgodę można też włączyć w ustawieniach dodatku (Oznaczanie maili).', 'warn')
  }
}

async function init() {
  showVersion()
  await showUpdateNotice()
  await showTagsOffer()

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
      if (handleNetworkDenied(e)) return
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
  // Nieudana próba spoza sieci: sam komunikat o sieci pokaże się niżej, jeśli odmowa trwa.
  if (pending && pending.error) {
    status(
      pending.network === true
        ? 'Poprzednia próba się nie udała — aplikacja nie przyjęła połączenia spoza sieci firmy.'
        : 'Poprzednia próba się nie udała: ' + pending.error,
      'error',
    )
  }

  const text = await readBody()
  if (text === null) return
  sourceText = text

  // Ten sam mail prowadzi już kto inny — najpierw pokazujemy jego zapytanie.
  if (pending && pending.duplicate) {
    loadDuplicate(pending.duplicate, text)

    return
  }

  if (!skipServerDuplicate) {
    const rows = await inquiriesForMessage(message.headerMessageId)
    if (rows === null) return
    const own = rows.find((row) => row.mine === true)
    if (own !== undefined) {
      // Własne zapytanie z innego komputera — zapamiętujemy, żeby następny raz
      // był natychmiastowy.
      await rememberInquiry(message.headerMessageId, own.id)
      loadKnown(own.id, own.replied_at !== null)

      return
    }
    if (rows.length > 0) {
      loadDuplicate(rows[0], text)

      return
    }
  }

  openFresh(text, settings.tone)
  await offerAttachments()
  if (el('body').value.trim().length < 20) {
    status('Treść maila jest za krótka do analizy — uzupełnij ją poniżej.', 'warn')
  }
}

/** Ekran wysyłki: treść maila do poprawienia, pod nią tekst zaznaczonych załączników. */
function openFresh(text, tone) {
  el('tone').value = tone
  updateToneHint()
  el('body').value = text
  updateCounter()
  show('fresh')
}

/** Co klient zobaczy w wybranym szablonie — wprost pod listą wyboru. */
function updateToneHint() {
  const hint = TONES[el('tone').value]
  el('toneHint').textContent = hint === undefined ? '' : hint
}

function updateCounter() {
  const length = el('body').value.trim().length
  let note = ''
  if (length < 20) note = ' — za mało, potrzeba co najmniej 20'
  if (length > MAX_INQUIRY_BODY) note = ' — za dużo, aplikacja przyjmie najwyżej ' + MAX_INQUIRY_BODY
  el('counter').textContent = length + ' znaków' + note
}

async function send() {
  if (attachmentsPending()) {
    status('Czekam na odczyt załącznika — wyślij, gdy tekst pojawi się w treści.', 'warn')

    return
  }
  const body = el('body').value.trim()
  if (body.length < 20) {
    status('Treść jest za krótka — potrzeba co najmniej 20 znaków.', 'warn')

    return
  }
  // Nie ucinamy po cichu: obcięty koniec to zgubione pozycje z tabeli klienta.
  if (body.length > MAX_INQUIRY_BODY) {
    status(
      'Treść ma ' + body.length + ' znaków, a aplikacja przyjmie najwyżej ' + MAX_INQUIRY_BODY
        + '. Odznacz zbędny załącznik albo usuń niepotrzebne wiersze.',
      'error',
    )

    return
  }

  sendToBackground(body, el('tone').value, forced, includedFileNames(body))
}

/** „Załóż mimo to” — własne zapytanie obok cudzego; aplikacja je powiąże. */
async function forceCreate() {
  const settings = await getSettings()
  await loadAttachments()
  // Bez treści nie ma czego analizować, a załączniki trzeba pokazać przed
  // wysłaniem — wracamy do zwykłego ekranu, ale zapamiętujemy, że to świadome
  // założenie kopii.
  if (sourceText.trim().length < 20 || attachments.length > 0) {
    forced = true
    openFresh(sourceText, settings.tone)
    status(attachments.length > 0 ? 'Sprawdź treść razem z załącznikami i wyślij.' : 'Treść maila jest za krótka — uzupełnij ją i wyślij.', 'warn')
    await offerAttachments()

    return
  }

  sendToBackground(sourceText, settings.tone, true)
}

/** „Anuluj” — kasuje zapamiętane ostrzeżenie i wraca do zwykłego ekranu. */
async function cancelDuplicate() {
  await setPending(message.headerMessageId || null, null)
  duplicate = null
  // Ostrzeżenie mogło przyjść z serwera, a nie z zapamiętanej odpowiedzi 409 —
  // bez tego init() pokazałby je z powrotem i przycisk nic by nie dawał.
  skipServerDuplicate = true
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
    // Tło i tak sprawdzi Message-ID z zapytania; ten numer służy tylko
    // zapytaniom wklejonym w przeglądarce, które maila nie mają.
    messageId: message.id,
  }).then((result) => {
    // Okno odpowiedzi się nie otworzyło, więc okienko wciąż jest — mówimy, co zrobić.
    if (result && result.network === true) {
      busy(false)
      handleNetworkDenied(result)
    }
  }).catch(() => {})
  status('Otwieram okno odpowiedzi…', 'info')
}

/* ------------------------------ czat firmowy ------------------------------ */

/** Limity pól `mail` przy POST /api/chat/direct/{user}/messages. */
/**
 * Przycina tekst do `max` znaków bez rozcinania emoji: połówka pary UTF-16 na końcu psuje JSON — serwer
 * nie odczytałby wtedy całego żądania i odpowiedziałby mylącym błędem o braku pól.
 */
function cutText(text, max) {
  const out = String(text).slice(0, max)
  const last = out.charCodeAt(out.length - 1)

  return last >= 0xd800 && last <= 0xdbff ? out.slice(0, -1) : out
}

const CHAT_MAIL_BODY_MAX = 20000
const CHAT_MAIL_FIELD_MAX = 300
const CHAT_MESSAGE_ID_MAX = 998

/** Mail, który idzie do kolegi — odczytany osobno, bo założenie zapytania ma własny przebieg. */
let chatMessage = null

/** Współpracownicy z czatem, bez mnie. */
let chatPeople = []

/** Wybrana osoba albo null. */
let chatPick = null

/**
 * Identyfikator wysyłki. Przy ponowieniu po błędzie sieci zostaje ten sam — serwer rozpozna powtórkę i nie zapisze
 * wiadomości drugi raz. Nowy po udanej wysyłce i po zmianie osoby (ten sam identyfikator w innej rozmowie = 422).
 */
let chatUuid = null

let chatSending = false

/** Rozmowa z ostatniej wysyłki — „Otwórz czat” przechodzi wtedy prosto do niej. */
let chatConversationId = null

function chatStatus(text, kind = 'info') {
  const box = el('chatStatus')
  box.textContent = text
  box.className = 'status ' + kind
  box.hidden = text === ''
}

function chatSendLabel() {
  return chatPick === null ? 'Wybierz osobę' : 'Wyślij do: ' + chatPick.name
}

function renderChatPeople() {
  const list = el('chatPeople')
  list.textContent = ''
  for (const person of chatPeople) {
    const chip = document.createElement('button')
    chip.type = 'button'
    chip.className = chatPick !== null && chatPick.id === person.id ? 'on' : ''
    chip.setAttribute('aria-pressed', chip.className === 'on' ? 'true' : 'false')
    chip.title = person.online ? person.name + ' — w pracy' : person.name
    if (person.online) {
      const dot = document.createElement('span')
      dot.className = 'dot'
      chip.appendChild(dot)
    }
    chip.appendChild(document.createTextNode(person.name))
    chip.addEventListener('click', () => {
      if (chatPick === null || chatPick.id !== person.id) chatUuid = null
      chatPick = person
      chatStatus('')
      renderChatPeople()
    })
    list.appendChild(chip)
  }
  el('chatSendButton').textContent = chatSendLabel()
  el('chatSendButton').disabled = chatPick === null || chatSending
}

function showChatUnread(count) {
  const number = Number(count) || 0
  el('chatUnread').hidden = number <= 0
  el('chatUnread').textContent = number > 99 ? '99+' : String(number)
  el('openChat').title = number > 0 ? 'Nieprzeczytane wiadomości: ' + number : 'Brak nieprzeczytanych wiadomości'
}

/**
 * Sekcja czatu — tylko gdy konto ma czat. 403 (bez uprawnienia) i 404 (serwer jeszcze bez czatu) po prostu
 * ją ukrywają; reszta okienka działa jak dotąd.
 */
async function initChat() {
  const settings = await getSettings()
  if (!settings.token) return

  let users
  let unread
  try {
    [users, unread] = await Promise.all([api('/api/chat/users'), api('/api/chat/unread')])
  } catch (e) {
    // Odmowa spoza sieci: sekcja czatu zostaje ukryta, ale komunikat mówi, co zrobić.
    if (handleNetworkDenied(e)) return
    if (e.status !== 403 && e.status !== 404) console.warn('Czat w okienku:', e.message)

    return
  }

  showChatUnread(unread && unread.unread_total)
  el('chat').hidden = false

  chatPeople = (users && Array.isArray(users.data) ? users.data : [])
    .filter((person) => person && person.is_me !== true && person.id !== undefined)
    .map((person) => ({ id: person.id, name: String(person.name || '').trim() || 'Bez nazwy', online: person.online === true }))

  // Bez otwartego maila albo bez kolegów zostaje sam odnośnik do czatu.
  chatMessage = await displayedMessage()
  if (chatMessage === null || chatPeople.length === 0) return

  renderChatPeople()
  el('chatSend').hidden = false
}

async function chatMailBody() {
  const full = await browser.messages.getFull(chatMessage.id)

  return cutText(messageText(full), CHAT_MAIL_BODY_MAX)
}

/** „Wyślij do: …” — mail (temat, nadawca, data, identyfikator, opcjonalnie treść) z komentarzem do rozmowy 1:1. */
async function chatSend() {
  if (chatSending || chatMessage === null) return
  if (chatPick === null) {
    chatStatus('Wybierz osobę, do której wysłać mail.', 'warn')

    return
  }
  const person = chatPick
  const comment = el('chatComment').value.trim()

  chatSending = true
  el('chatSendButton').disabled = true
  chatStatus('Wysyłam…', 'info')
  try {
    let body = null
    if (el('chatIncludeBody').checked) {
      try {
        body = await chatMailBody()
      } catch (e) {
        chatStatus('Nie mogę odczytać treści maila (możliwe, że jest zaszyfrowany). Odznacz „Dołącz treść maila” i wyślij ponownie.', 'error')

        return
      }
    }

    if (chatUuid === null) chatUuid = crypto.randomUUID()
    const messageId = normalizeMessageId(chatMessage.headerMessageId)
    // Brak tematu albo nadawcy = null (nic nie zgadujemy); aplikacja pokaże wtedy samo „bez tematu”.
    const subject = cutText(String(chatMessage.subject || '').trim(), CHAT_MAIL_FIELD_MAX)
    const from = cutText(String(senderHeader(chatMessage.author) || ''), CHAT_MAIL_FIELD_MAX)
    const answer = await api('/api/chat/direct/' + encodeURIComponent(person.id) + '/messages', {
      method: 'POST',
      body: {
        client_uuid: chatUuid,
        body: comment === '' ? null : comment,
        mail: {
          subject: subject === '' ? null : subject,
          from: from === '' ? null : from,
          date: toIsoDate(chatMessage.date),
          message_id: messageId === '' ? null : cutText(messageId, CHAT_MESSAGE_ID_MAX),
          body,
        },
      },
    })

    chatUuid = null
    chatConversationId = answer && answer.conversation_id !== undefined ? answer.conversation_id : null
    el('chatComment').value = ''
    el('chatIncludeBody').checked = false
    chatStatus('Wysłane do: ' + person.name + '. Rozmowę zobaczysz po kliknięciu „Otwórz czat”.', 'ok')
    if (chatConversationId !== null) {
      browser.runtime.sendMessage({ type: 'chatSent', conversationId: chatConversationId }).catch(() => {})
    }
  } catch (e) {
    if (e.network === true) {
      handleNetworkDenied(e)
      chatStatus('Wiadomość nie poszła — brak dostępu spoza sieci firmy (komunikat na górze okienka).', 'error')
    } else if (e.status === 0) {
      chatStatus('Brak połączenia z aplikacją — wiadomość nie poszła. Spróbuj jeszcze raz.', 'error')
    } else if (e.status === 403) {
      chatStatus('Nie możesz wysyłać wiadomości w czacie — poproś kierownika o dostęp.', 'error')
    } else {
      chatStatus('Wiadomość nie poszła: ' + e.message, 'error')
    }
  } finally {
    chatSending = false
    el('chatSendButton').disabled = chatPick === null
  }
}

/** Czat otwiera tło (przestrzeń „Czat Supon”) — okienko zaraz zniknie. */
async function openChat() {
  await browser.runtime.sendMessage({ type: 'openChat', conversationId: chatConversationId })
  window.close()
}

el('openChat').addEventListener('click', () => {
  openChat().catch((e) => status(e.message || String(e), 'error'))
})
el('chatSendButton').addEventListener('click', () => {
  chatSend().catch((e) => chatStatus(e.message || String(e), 'error'))
})

el('openOptions').addEventListener('click', () => browser.runtime.openOptionsPage())
el('networkOpen').addEventListener('click', () => {
  openAppInBrowser().catch((e) => status(e.message || String(e), 'error'))
})
el('enableTags').addEventListener('click', () => {
  enableTags().catch((e) => status(e.message || String(e), 'error'))
})
el('getUpdate').addEventListener('click', async (event) => {
  const link = event.target.dataset.link || ''
  if (link !== '') await browser.windows.openDefaultBrowser(link)
})
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
el('tone').addEventListener('change', updateToneHint)
el('body').addEventListener('input', updateCounter)

init().catch((e) => status(e.message || String(e), 'error'))
// Czat niezależnie od zapytania: jego błąd nie może zasłonić „Wyślij do Przetargów”.
initChat().catch((e) => console.warn('Czat w okienku:', e.message || String(e)))
