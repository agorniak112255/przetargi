/*
 * Kolumna „Prowadzi” w liście wiadomości.
 *
 * Dlaczego to wygląda inaczej niż reszta dodatku: Thunderbird nie ma zwykłego
 * API do dokładania kolumn (zgłoszenie 1615801 otwarte od lat), więc sięgamy
 * wprost do jego wnętrza — to jest „API eksperymentalne”. Kod działa w procesie
 * głównym programu, nie w dodatku, i dlatego jest osobno, w tym jednym pliku.
 *
 * Cena tej drogi: moduł, z którego korzystamy, należy do wnętrza Thunderbirda
 * i przy większym wydaniu może zmienić nazwę albo zniknąć. Wszystko jest więc
 * w osłonach: gdy modułu nie ma, kolumna po prostu się nie pokazuje, a reszta
 * dodatku (zapytania, odpowiedzi, znaczniki) działa dalej bez zmian.
 *
 * Skąd treść kolumny: tło dodatku pyta aplikację, z których maili powstały
 * zapytania i kto je prowadzi (POST /api/inquiries/lookup), i podaje tu gotową
 * mapę „Message-ID → tekst”. Tutaj nie ma żadnej logiki biznesowej ani
 * połączeń z siecią — tylko odczyt tej mapy przy rysowaniu wiersza.
 */

const { ExtensionCommon } = ChromeUtils.importESModule('resource://gre/modules/ExtensionCommon.sys.mjs')

/** Identyfikator kolumny; musi być stały, bo Thunderbird zapamiętuje układ kolumn. */
const COLUMN_ID = 'suponProwadzi'

/**
 * Moduł kolumn listy wiadomości. Od Thunderbirda 128 leży pod tą ścieżką
 * (wcześniej nosił inną nazwę), więc próbujemy po kolei i godzimy się z tym,
 * że w starszych wydaniach kolumny nie będzie.
 */
const MODULE_PATHS = [
  'chrome://messenger/content/ThreadPaneColumns.mjs',
  'resource:///modules/ThreadPaneColumns.mjs',
]

/** Message-ID (małymi literami) → tekst w kolumnie. Ustawia to tło dodatku. */
let entries = {}

let columnAdded = false

function loadColumns() {
  for (const path of MODULE_PATHS) {
    try {
      const module = ChromeUtils.importESModule(path)
      if (module && module.ThreadPaneColumns) {
        return module.ThreadPaneColumns
      }
    } catch (e) {
      // Ta wersja Thunderbirda trzyma moduł gdzie indziej — próbujemy dalej.
    }
  }

  return null
}

/**
 * Tekst w wierszu. Dostajemy nagłówek wiadomości z bazy Thunderbirda, więc
 * bierzemy z niego sam Message-ID i patrzymy do mapy — żadnego pytania do
 * sieci ani do bazy, bo ta funkcja biegnie przy rysowaniu każdego wiersza.
 */
function textFor(message) {
  try {
    const id = String(message.messageId || '').replace(/^</, '').replace(/>$/, '').trim().toLowerCase()

    return id === '' ? '' : (entries[id] || '')
  } catch (e) {
    return ''
  }
}

var inquiryColumn = class extends ExtensionCommon.ExtensionAPI {
  getAPI(context) {
    context.callOnClose(this)

    return {
      inquiryColumn: {
        async available() {
          return loadColumns() !== null
        },

        async show(label, tooltip) {
          const columns = loadColumns()
          if (columns === null) return false

          try {
            if (columnAdded) columns.removeCustomColumn(COLUMN_ID)
          } catch (e) {
            // Nie było czego zdejmować.
          }

          try {
            columns.addCustomColumn(COLUMN_ID, {
              name: label,
              tooltip,
              hidden: false,
              icon: false,
              resizable: true,
              sortable: true,
              // Bez `sortCallback`: sprawdzony w praktyce zestaw to `sortable`
              // plus `textCallback`, po którym Thunderbird sortuje sam.
              textCallback: textFor,
            })
            columnAdded = true

            return true
          } catch (e) {
            console.warn('Supon Przetargi: nie udało się dołożyć kolumny —', e.message)

            return false
          }
        },

        async hide() {
          const columns = loadColumns()
          if (columns === null || ! columnAdded) return

          try {
            columns.removeCustomColumn(COLUMN_ID)
          } catch (e) {
            // Kolumny już nie ma — nic nie szkodzi.
          }
          columnAdded = false
        },

        async setEntries(next) {
          entries = next && typeof next === 'object' ? next : {}

          // Wiersze już narysowane trzeba odświeżyć, inaczej nazwisko pojawiłoby
          // się dopiero po przejściu do innego folderu i z powrotem.
          const columns = loadColumns()
          if (columns === null || ! columnAdded) return

          try {
            if (typeof columns.refreshCustomColumn === 'function') {
              columns.refreshCustomColumn(COLUMN_ID)
            }
          } catch (e) {
            // Brak odświeżenia nie jest awarią: wartości wejdą przy przerysowaniu.
          }
        },
      },
    }
  }

  /** Wyłączenie albo aktualizacja dodatku nie może zostawić martwej kolumny. */
  close() {
    const columns = loadColumns()
    if (columns === null || ! columnAdded) return

    try {
      columns.removeCustomColumn(COLUMN_ID)
    } catch (e) {
      // trudno
    }
    columnAdded = false
  }
}
