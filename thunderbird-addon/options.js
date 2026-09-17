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

el('login').addEventListener('click', login)
el('check').addEventListener('click', check)
el('logout').addEventListener('click', logout)
el('useAppSubject').addEventListener('change', async (event) => {
  await setSettings({ useAppSubject: event.target.checked })
})

refresh().catch((e) => status(e.message || String(e), 'error'))
