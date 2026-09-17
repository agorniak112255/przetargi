/*
 * Jedyne zadanie tła: po wysłaniu odpowiedzi oznaczyć zapytanie jako obsłużone.
 * Okno kompozycji rozpoznajemy po `tab.id` zapamiętanym przy otwieraniu odpowiedzi —
 * Message-ID nie wystarczy, bo naraz może być otwartych kilka okien.
 */
browser.compose.onAfterSend.addListener(async (tab, info) => {
  // „sendLater” trafia do Skrzynki nadawczej, ale z punktu widzenia handlowca
  // odpowiedź jest już napisana i zatwierdzona.
  if (info.mode !== 'sendNow' && info.mode !== 'sendLater') return

  const inquiryId = await takeComposeTab(tab.id)
  if (inquiryId === null) return

  try {
    await api('/api/inquiries/' + inquiryId + '/replied', {
      method: 'POST',
      body: { replied: true },
    })
  } catch (e) {
    // Bez sieci zostaje ręczne „Oznacz wysłane” w aplikacji — nie blokujemy wysyłki.
    console.warn('Nie udało się oznaczyć zapytania ' + inquiryId + ' jako wysłane:', e.message)
  }
})

/** Porzucone okna kompozycji nie mogą puchnąć w pamięci ustawień. */
browser.tabs.onRemoved.addListener(async (tabId) => {
  await takeComposeTab(tabId)
})
