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

/** Nagłówek kolumny i podpowiedź nad nim. */
const COLUMN_LABEL = 'Prowadzi'
const COLUMN_TOOLTIP = 'Kto prowadzi zapytanie z tego maila w aplikacji Przetargi'

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
async function updateColumnEntries(ids, found) {
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

/** Podaje całą mapę do kolumny; pusta mapa czyści kolumnę. */
async function pushColumnEntries(entries = null) {
  const api = columnApi()
  if (api === null) return

  try {
    await api.setEntries(entries === null ? await columnEntries() : entries)
  } catch (e) {
    console.warn('Nie udało się odświeżyć kolumny „Prowadzi”:', e.message)
  }
}

/**
 * Pokazuje kolumnę i wypełnia ją tym, co już wiemy. Wołane przy starcie tła —
 * układ kolumn Thunderbird pamięta sam, więc raz ukryta przez handlowca
 * kolumna zostaje ukryta.
 */
async function showColumn() {
  const api = columnApi()
  if (api === null) return false

  try {
    if (! await api.available()) return false
    await api.show(COLUMN_LABEL, COLUMN_TOOLTIP)
    await pushColumnEntries()

    return true
  } catch (e) {
    console.warn('Kolumna „Prowadzi” się nie pojawiła:', e.message)

    return false
  }
}

/** Zdejmuje kolumnę i czyści jej pamięć — z ustawień dodatku. */
async function hideColumn() {
  const api = columnApi()
  await browser.storage.local.set({ columnEntries: {} })
  if (api === null) return

  try {
    await api.setEntries({})
    await api.hide()
  } catch (e) {
    console.warn('Nie udało się zdjąć kolumny „Prowadzi”:', e.message)
  }
}
