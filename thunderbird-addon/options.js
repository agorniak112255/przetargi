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
  el('connected').hidden = !settings.token
  el('connected').textContent = settings.token ? 'Dodatek jest połączony z aplikacją.' : ''

  await showTagging()

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

async function logout() {
  await setSettings({ token: '' })
  status('Odłączono. Token pozostaje aktywny na serwerze.', 'info')
  await refresh()
}

/* ------------------------- oznaczanie maili na liście ------------------------- */

async function showTagging() {
  const allowed = await tagsAllowed()

  el('tagState').textContent = allowed
    ? 'Oznaczanie jest włączone.'
    : 'Oznaczanie jest wyłączone — maile na liście nie dostają znaczników.'
  el('enableTags').hidden = allowed
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
    // Tło ma pełny obraz i pamięć tego, co już oznaczone — niech ono to zrobi.
    browser.runtime.sendMessage({ type: 'syncTags' })
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
el('disableTags').addEventListener('click', disableTags)
el('checkUpdate').addEventListener('click', checkUpdateNow)
el('getUpdate').addEventListener('click', async (event) => {
  const link = event.target.dataset.link || ''
  if (link !== '') await browser.windows.openDefaultBrowser(link)
})
el('useAppSubject').addEventListener('change', async (event) => {
  await setSettings({ useAppSubject: event.target.checked })
})

refresh().catch((e) => status(e.message || String(e), 'error'))
