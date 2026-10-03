import { createContext, useContext } from 'react'

/** Liczba nieprzeczytanych wiadomości czatu — wspólna dla menu, tytułu karty i strony Czat. */
export type ChatUnreadCtx = {
  /** null = jeszcze nie wiadomo (albo brak uprawnienia do czatu). */
  unreadTotal: number | null
  /** Wartość prosto z odpowiedzi serwera (lista rozmów, oznaczenie przeczytanych). */
  setUnreadTotal: (n: number) => void
  /** Dociąga licznik z GET /chat/unread. */
  refreshUnread: () => void
}

export const ChatUnreadContext = createContext<ChatUnreadCtx>({
  unreadTotal: null,
  setUnreadTotal: () => {},
  refreshUnread: () => {},
})

export function useChatUnread(): ChatUnreadCtx {
  return useContext(ChatUnreadContext)
}
