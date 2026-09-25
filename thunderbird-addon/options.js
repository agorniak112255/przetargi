/* Ustawienia dodatku: jednorazowe logowanie i zapis tokenu. */

const el = (id) => document.getElementById(id)

function status(text, kind = 'info') {
  const box = el('status')
  box.textContent = text
  box.className = 'status ' + kind
  box.hidden = text === ''
}

function busy(on) {
  for (const button of document.querySelectorAll('button')) {
    button.disabled = on
  }
}

function showVersion() {
  const version = addonVersion()
  el('version').textContent = version === '' ? '' : 'Supon Przetargi ' + version
}

async function refresh() {
  showVersion()

  const settings = await getSettings()
  el('baseUrl').value = settings.baseUrl
  el('useAppSubject').checked = settings.useAppSubject
  await showIdentityState()
  await showLastTiming()
  el('connected').hidden = !settings.token
  el('connected').textContent = settings.token ? 'Dodatek jest połączony z aplikacją.' : ''

  await showTagging()
  await showColumnState()

  // Bez pytania serwera — pokazujemy to, co wiadomo z ostatniego sprawdzenia.
  showUpdate(await lastUpdateCheck())
}

async function login() {
  const baseUrl = el('baseUrl').value.trim().replace(/\/+$/, '')
  const email = el('email').value.trim()
  const password = el('password').value

  if (baseUrl === '' || email === '' || password === '') {
    status('Uzupełnij adres, e-mail i hasło.', 'warn')

    return
  }

  busy(true)
  status('Łączę…')
  try {
    const previous = await getSettings()
    const res = await api('/api/login', {
      method: 'POST',
      body: { email, password },
      baseUrl,
      token: '',
    })
    if (!res || typeof res.token !== 'string' || res.token === '') {
      throw new ApiError(0, 'Serwer nie zwrócił tokenu.')
    }

    await setSettings({ baseUrl, token: res.token })
    // Hasło zostaje tylko w polu formularza — nigdzie go nie zapisujemy.
    el('password').value = ''
    // Poprzedni klucz dodatku przestaje być potrzebny — bez tego każde „Połącz” dokładałoby
    // w aplikacji kolejną sesję. Klucz z innego adresu aplikacji zostawiamy: tam go nie wylogujemy.
    if (previous.token !== '' && previous.token !== res.token && previous.baseUrl === baseUrl) {
      await revokeToken(previous.token, baseUrl)
    }
    status('Połączono.', 'ok')
    await refresh()
  } catch (e) {
    status(e.message, 'error')
  } finally {
    busy(false)
  }
}

async function check() {
  busy(true)
  status('Sprawdzam…')
  try {
    await api('/api/inquiries')
    status('Połączenie działa.', 'ok')
  } catch (e) {
    status(e.message, 'error')
  } finally {
    busy(false)
  }
}

/**
 * Wylogowuje klucz dodatku w aplikacji. Zwraca pusty tekst, gdy klucza na serwerze już nie ma
 * (także 401 — ktoś go wcześniej wylogował), albo opis problemu, gdy serwer tego nie potwierdził.
 */
async function revokeToken(token, baseUrl) {
  try {
    await api('/api/logout', { method: 'POST', token, baseUrl })

    return ''
  } catch (e) {
    return e.status === 401 ? '' : e.message
  }
}

async function logout() {
  busy(true)
  try {
    const settings = await getSettings()
    const problem = settings.token === '' ? '' : await revokeToken(settings.token, settings.baseUrl)
    await setSettings({ token: '' })
    status(
      problem === ''
        ? 'Odłączono. Sesja dodatku w aplikacji jest wylogowana.'
        : 'Odłączono w dodatku, ale aplikacja nie potwierdziła wylogowania (' + problem + '). '
          + 'Nieużywaną sesję administrator wyloguje przyciskiem „Wyloguj stare sesje” (Administracja → Aktywne sesje).',
      problem === '' ? 'ok' : 'warn',
    )
  } finally {
    busy(false)
  }
  await refresh()
}

/* ------------------------- oznaczanie maili na liście ------------------------- */

async function showTagging() {
  const allowed = await tagsAllowed()
  const partly = allowed ? false : await tagsPartlyAllowed()
  const column = await columnState()

  el('tagState').textContent = allowed
    ? (column.ok
      ? 'Oznaczanie jest włączone. Przy działającej kolumnie „Prowadzi” nie jest potrzebne — '
        + 'kolorowe etykiety możesz zdjąć przyciskiem poniżej.'
      : 'Oznaczanie jest włączone.')
    : partly
      // Po aktualizacji, która dołożyła uprawnienie — zgoda była, ale jest niepełna.
      ? 'Aktualizacja dodatku wymaga jednego kliknięcia: dochodzi zgoda na odczyt listy znaczników.'
      : 'Oznaczanie jest wyłączone — maile na liście nie dostają znaczników.'
  el('enableTags').hidden = allowed
  el('enableTags').textContent = partly ? 'Dokończ włączanie oznaczania' : 'Włącz oznaczanie maili'
  el('disableTags').hidden = !allowed
}

async function enableTags() {
  busy(true)
  try {
    // Zgody wolno żądać tylko w odpowiedzi na kliknięcie — dlatego jest tu,
    // a nie w tle dodatku.
    const granted = await requestTagPermissions()
    if (!granted) {
      status('Bez zgody na zmianę znaczników oznaczanie nie zadziała.', 'warn')

      return
    }

    status('Włączone. Pierwsze znaczniki pojawią się w ciągu kilku minut.', 'ok')
    // Pełne przejście, nie zwykłe: maile sprawdzone w czasie bez zgody mają już
    // przesunięty znacznik czasu i bez zerowania nie dostałyby znacznika nigdy.
    browser.runtime.sendMessage({ type: 'syncTags', reset: true })
  } catch (e) {
    status(e.message, 'error')
  } finally {
    busy(false)
    await showTagging()
  }
}

async function checkTags() {
  busy(true)
  status('Sprawdzam oznaczanie…')
  el('tagReport').hidden = false
  el('tagReport').value = 'Sprawdzam…'
  try {
    el('tagReport').value = await tagDiagnostics()
    status('Gotowe — przeczytaj raport poniżej.', 'ok')
  } catch (e) {
    el('tagReport').value = 'Samotest się wywrócił: ' + (e.message || String(e))
    status(e.message, 'error')
  } finally {
    busy(false)
  }
}

async function syncTagsNow() {
  busy(true)
  status('Oznaczam maile od nowa…')
  try {
    await resetTagSync()
    status('Przejście wykonane. Jeśli nadal nic nie widać, kliknij „Sprawdź oznaczanie”.', 'ok')
  } catch (e) {
    status(e.message, 'error')
  } finally {
    busy(false)
    await showTagging()
  }
}

async function disableTags() {
  busy(true)
  status('Zdejmuję znaczniki…')
  try {
    const cleared = await clearOurTags()
    try {
      await browser.permissions.remove({ permissions: await tagPermissions() })
    } catch (e) {
      // Zgoda zostaje, ale oznaczanie i tak jest wyłączone — znaczniki zdjęte.
    }
    status('Zdjęte znaczniki: ' + cleared + '. Oznaczanie wyłączone.', 'ok')
  } catch (e) {
    status(e.message, 'error')
  } finally {
    busy(false)
    await showTagging()
  }
}

/* ---------------------------- kolumna „Prowadzi” ---------------------------- */

async function showColumnState() {
  const state = await columnState()
  const { columnEntries } = await browser.storage.local.get({ columnEntries: {} })
  const known = Object.keys(columnEntries || {}).length

  el('columnState').textContent = state.ok
    ? 'Kolumna działa. Maili z wpisem: ' + known + '.'
    : state.reason
  el('hideColumn').hidden = ! state.ok
  el('showColumn').hidden = state.ok
}

async function showColumnNow() {
  busy(true)
  status('Zakładam kolumnę…')
  try {
    const state = await showColumn()
    status(state.ok ? 'Kolumna założona.' : state.reason, state.ok ? 'ok' : 'warn')
  } catch (e) {
    status(e.message, 'error')
  } finally {
    busy(false)
    await showColumnState()
  }
}

async function hideColumnNow() {
  busy(true)
  try {
    await hideColumn()
    status('Kolumna zdjęta. Wróci po ponownym uruchomieniu Thunderbirda — treść zostaje zapamiętana.', 'ok')
  } catch (e) {
    status(e.message, 'error')
  } finally {
    busy(false)
    await showColumnState()
  }
}

/* ---------------------------- aktualizacje ---------------------------- */

function showUpdate(state) {
  const installed = addonVersion()
  const same = state === null || !state.newer

  el('updateState').textContent = same
    ? 'Zainstalowana wersja: ' + (installed === '' ? 'nieznana' : installed) + '.'
    : 'Zainstalowana wersja: ' + installed + ', na serwerze czeka ' + state.version + '.'

  el('updateAlert').hidden = same
  el('updateAlert').textContent = same
    ? ''
    : 'Dostępna nowa wersja ' + state.version + '.'

  const link = same ? '' : String(state.link || '')
  el('getUpdate').hidden = link === ''
  el('getUpdate').dataset.link = link
  el('updateHelp').hidden = link === ''
}

async function checkUpdateNow() {
  busy(true)
  status('Sprawdzam wersję na serwerze…')
  try {
    const state = await checkUpdate()
    showUpdate(state)
    status(
      state.newer
        ? 'Jest nowsza wersja: ' + state.version + '.'
        : 'Masz najnowszą wersję.',
      state.newer ? 'warn' : 'ok',
    )
  } catch (e) {
    status(e.message, 'error')
  } finally {
    busy(false)
  }
}

el('login').addEventListener('click', login)
el('check').addEventListener('click', check)
el('logout').addEventListener('click', logout)
el('enableTags').addEventListener('click', enableTags)
el('checkTags').addEventListener('click', checkTags)
el('showColumn').addEventListener('click', showColumnNow)
el('hideColumn').addEventListener('click', hideColumnNow)
el('syncTags').addEventListener('click', syncTagsNow)
el('disableTags').addEventListener('click', disableTags)
el('checkUpdate').addEventListener('click', checkUpdateNow)
el('getUpdate').addEventListener('click', async (event) => {
  const link = event.target.dataset.link || ''
  if (link !== '') await browser.windows.openDefaultBrowser(link)
})
/**
 * Czy dodatek może odpowiadać z konta, na które przyszedł mail. Wymaga zgody na
 * odczyt kont — tej samej, której używa oznaczanie maili na liście. Bez niej
 * Thunderbird wybiera konto domyślne, co przy dwóch skrzynkach wysyła ofertę
 * z niewłaściwego adresu.
 */
async function showIdentityState() {
  let allowed = false
  try {
    allowed = await browser.permissions.contains({ permissions: ['accountsRead'] })
  } catch (e) {
    allowed = false
  }
  el('identityState').textContent = allowed
    ? 'Konto nadawcy: odpowiedź wychodzi z konta, na które przyszedł mail.'
    : 'Konto nadawcy: wybiera Thunderbird (konto domyślne). Zezwól na odczyt kont, żeby odpowiedź szła z tej skrzynki, na którą napisał klient.'
  el('allowAccounts').hidden = allowed
}

el('allowAccounts').addEventListener('click', async () => {
  try {
    await browser.permissions.request({ permissions: ['accountsRead'] })
  } catch (e) {
    status(e.message, 'error')
  }
  await showIdentityState()
})

/**
 * Ile trwało ostatnie otwarcie okna odpowiedzi, z podziałem na etapy. Bez tego
 * „u mnie się wlecze” zostaje bez liczb, a powiadomienie z pomiarem bywa
 * w systemie wyciszone.
 */
async function showLastTiming() {
  const { lastReplyTiming } = await browser.storage.local.get({ lastReplyTiming: null })
  if (!lastReplyTiming || !lastReplyTiming.text) {
    el('lastTiming').textContent = 'Ostatnie otwarcie odpowiedzi: jeszcze żadnego.'

    return
  }
  const seconds = (Number(lastReplyTiming.total || 0) / 1000).toFixed(1)
  el('lastTiming').textContent = 'Ostatnie otwarcie odpowiedzi: ' + seconds + ' s ('
    + lastReplyTiming.text + ').'
}

el('useAppSubject').addEventListener('change', async (event) => {
  await setSettings({ useAppSubject: event.target.checked })
})

refresh().catch((e) => status(e.message || String(e), 'error'))
