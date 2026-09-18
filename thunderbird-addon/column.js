/*
 * Kolumna „Prowadzi” na liście wiadomości.
 *
 * Po co obok znaczników: znacznik koloruje cały wiersz i zlewa się z kolorami,
 * których handlowcy używają do własnych spraw. Kolumna nie rusza kolorów ani
 * statusów — pokazuje wprost nazwisko osoby, która prowadzi zapytanie z tego
 * maila, i „✓” po wysłaniu odpowiedzi do klienta.
 *
 * Skąd dane: z tej samej odpowiedzi serwera, z której powstają znaczniki
 * (POST /api/inquiries/lookup). Kolumna **nie wymaga żadnej zgody** na zmianę
 * wiadomości — niczego w mailu nie zapisuje, więc działa też wtedy, gdy
 * oznaczanie jest wyłączone.
 *
 * Samo rysowanie kolumny robi API eksperymentalne (experiment/columns), bo
 * Thunderbird nie ma na to zwykłego API. Gdy tamtej drogi zabraknie (starsze
 * wydanie albo zmiana we wnętrzu programu), zostaje tu wszystko bez zmian —
 * po prostu kolumny nie widać.
 */

/** Nagłówek kolumny. Podpowiedzi nad nagłówkiem API kolumn nie obsługuje. */
const COLUMN_LABEL = 'Prowadzi'

/** Znak przy nazwisku, gdy odpowiedź do klienta już poszła. */
const COLUMN_REPLIED = ' ✓'

/** Ile maili najwyżej trzymamy w pamięci kolumny — reszta i tak nie jest widoczna. */
const COLUMN_LIMIT = 5000

/** Czy w tym Thunderbirdzie kolumna w ogóle istnieje. */
function columnApi() {
  return typeof browser !== 'undefined' && browser.inquiryColumn ? browser.inquiryColumn : null
}

/**
 * Tekst dla jednego maila: nazwiska osób prowadzących, a przy wysłanej
 * odpowiedzi „✓”. Gdy nad jednym mailem siedzą dwie osoby, widać obie —
 * to jest właśnie ta informacja, dla której kolumna powstała.
 *
 * @param rows odpowiedź serwera dla jednego Message-ID
 */
function columnTextFor(rows) {
  const seen = []
  for (const row of Array.isArray(rows) ? rows : []) {
    const name = duplicateOwner(row) + (row && row.replied_at ? COLUMN_REPLIED : '')
    if (! seen.includes(name)) seen.push(name)
  }

  return seen.join(', ')
}

/**
 * Zapisy do pamięci kolumny idą jeden po drugim. Bez tego równoległe przejścia
 * (otwarcie maila i przejście w tle w tej samej chwili) czytały tę samą mapę
 * i zapisywały ją nawzajem, gubiąc wpisy.
 */
let columnQueue = Promise.resolve()

function queueColumnWork(work) {
  columnQueue = columnQueue.then(work, work)

  return columnQueue
}

/** Zapamiętana treść kolumny: Message-ID (małymi literami) → tekst. */
async function columnEntries() {
  const { columnEntries: stored } = await browser.storage.local.get({ columnEntries: {} })

  return stored && typeof stored === 'object' ? stored : {}
}

/**
 * Dopisuje do pamięci kolumny wynik sprawdzenia paczki maili i pokazuje go
 * od razu na liście. Maile, które straciły zapytanie (usunięte w aplikacji),
 * znikają z kolumny — brak wpisu w odpowiedzi serwera znaczy „nie ma nic”.
 *
 * @param ids   Message-ID, o które pytaliśmy
 * @param found mapa z odpowiedzi serwera (klucze małymi literami)
 */
function updateColumnEntries(ids, found) {
  return queueColumnWork(() => writeColumnEntries(ids, found))
}

async function writeColumnEntries(ids, found) {
  const entries = await columnEntries()
  let changed = false

  for (const id of ids) {
    const key = String(id).toLowerCase()
    const text = columnTextFor(found.get(key) || [])
    if (text === '') {
      if (entries[key] !== undefined) {
        delete entries[key]
        changed = true
      }
    } else if (entries[key] !== text) {
      entries[key] = text
      changed = true
    }
  }

  if (! changed) return

  const keys = Object.keys(entries)
  if (keys.length > COLUMN_LIMIT) {
    // Pamięć nie może puchnąć bez końca; najstarsze wpisy odchodzą pierwsze.
    for (const key of keys.slice(0, keys.length - COLUMN_LIMIT)) delete entries[key]
  }

  await browser.storage.local.set({ columnEntries: entries })
  await pushColumnEntries(entries)
}

/** Po ilu milisekundach ciszy podajemy kolumnie nową treść. */
const COLUMN_PUSH_DELAY = 1200

let pushTimer = null
let pushPending = null

/**
 * Podaje całą mapę do kolumny; pusta mapa czyści kolumnę.
 *
 * Wywołania są zbierane w jedno: każde podanie mapy przerysowuje listę
 * wiadomości we wszystkich oknach, a przy przejściu po wielu mailach szło ich
 * kilka pod rząd.
 */
function pushColumnEntries(entries = null) {
  pushPending = entries
  if (pushTimer !== null) return Promise.resolve()

  return new Promise((resolve) => {
    pushTimer = setTimeout(async () => {
      pushTimer = null
      const next = pushPending
      pushPending = null
      const api = columnApi()
      if (api !== null) {
        try {
          await api.setEntries(next === null ? await columnEntries() : next)
        } catch (e) {
          console.warn('Nie udało się odświeżyć kolumny „Prowadzi”:', e.message)
        }
      }
      resolve()
    }, COLUMN_PUSH_DELAY)
  })
}

/**
 * Stan kolumny słowami — do ustawień i do powiadomienia. Bez tego „nie ma
 * kolumny” było nie do odróżnienia od „Thunderbird jeszcze jej nie załadował”.
 *
 * @return {{ok: boolean, reason: string}}
 */
async function columnState() {
  if (columnApi() === null) {
    // Manifest deklaruje kolumnę, a Thunderbird jej nie udostępnił: API
    // eksperymentalne wchodzi dopiero przy starcie programu, więc po
    // aktualizacji dodatku trzeba go raz uruchomić ponownie.
    return {
      ok: false,
      reason: 'Uruchom Thunderbirda ponownie — kolumna włącza się przy starcie programu.',
    }
  }

  const { columnError } = await browser.storage.local.get({ columnError: '' })
  try {
    if (await columnApi().added()) return { ok: true, reason: '' }
  } catch (e) {
    return { ok: false, reason: 'Nie udało się zapytać o kolumnę: ' + e.message }
  }

  return {
    ok: false,
    reason: String(columnError || 'Kolumna jeszcze nie powstała — kliknij „Pokaż kolumnę”.'),
  }
}

/**
 * Pokazuje kolumnę i wypełnia ją tym, co już wiemy. Wołane przy starcie tła
 * i z ustawień. Układ kolumn Thunderbird pamięta sam, więc raz ukryta przez
 * handlowca kolumna zostaje ukryta.
 *
 * @return {{ok: boolean, reason: string}}
 */
async function showColumn() {
  const api = columnApi()
  if (api === null) return columnState()

  try {
    const problem = await api.show(COLUMN_LABEL)
    await browser.storage.local.set({ columnError: problem || '' })
    if (problem) return { ok: false, reason: problem }

    await pushColumnEntries()

    return { ok: true, reason: '' }
  } catch (e) {
    const reason = 'Kolumna „Prowadzi” się nie pojawiła: ' + e.message
    await browser.storage.local.set({ columnError: reason })

    return { ok: false, reason }
  }
}

/**
 * Zdejmuje kolumnę z listy wiadomości. Pamięci NIE kasujemy: przy wyłączonych
 * znacznikach nic by jej nie odtworzyło (serwer oddaje tylko zmiany od ostatniego
 * pytania) i kolumna po ponownym pokazaniu byłaby pusta na zawsze.
 */
async function hideColumn() {
  const api = columnApi()
  if (api === null) return

  try {
    await api.hide()
  } catch (e) {
    console.warn('Nie udało się zdjąć kolumny „Prowadzi”:', e.message)
  }
}
