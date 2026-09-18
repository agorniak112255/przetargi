// Typy 1:1 z kontraktem API „Zapytania” (PLAN_ZAPYTANIA_v2).

/** Szablon listu do klienta; nazwy w src/lib/inquiryTone.ts. */
export type InquiryTone = 'formal' | 'handlowy' | 'bez_sku'

export type InquiryAnswer = {
  option_id: string
  custom?: string | null
}

export type InquiryCardOption = {
  id: string
  label: string
}

export type InquiryCard = {
  id: string
  title: string
  prompt: string
  options: InquiryCardOption[]
  allow_custom: boolean
}

export type InquiryCandidate = {
  id: number
  sku: string
  name: string
  manufacturer: string
  norms: string
  catalog_pln: number | null
  offer_pln: number | null
  stock: number | null
  score: number
  reason: string | null
  /** null = pozycja bez warunków do sprawdzenia w karcie */
  requirements_ok: boolean | null
}

export type InquirySubstitute = {
  id: number
  sku: string
  name: string
  manufacturer: string
  norms: string
  catalog_pln: number | null
  offer_pln: number | null
  stock: number | null
  score: number | null
  reason: string | null
}

export type InquiryConfidence = 'high' | 'medium' | 'none'

export type InquiryFlag =
  | 'low_score'
  | 'ambiguous'
  | 'no_price'
  | 'card_default'
  | 'qty_unknown'
  | 'requirement_unconfirmed'
  | 'requirement_note'

/**
 * Warunek szczególny z wiersza klienta („w szczególności na kwas siarkowy 96%”).
 * `checkable` false = klauzula, której nie sprawdza żadna reguła — do przeczytania
 * przez człowieka. `ok` null = nie ma czego sprawdzić albo nic nie wybrano.
 */
export type InquiryRequirement = {
  text: string
  checkable: boolean
  ok: boolean | null
}

/** `chosen`: `"p:<id>"` albo `"check"`. */
export type InquiryItem = {
  id: string
  quote: string | null
  qty: string | null
  unit: string | null
  size: string | null
  answer_key: string
  substitute_key: string | null
  confidence: InquiryConfidence
  chosen: string
  flags: InquiryFlag[]
  requirements: InquiryRequirement[]
  candidates: InquiryCandidate[]
  substitutes: InquirySubstitute[]
  cards: InquiryCard[]
}

export type InquiryPriceMode = 'none' | 'catalog' | 'catalog_margin'

export type InquiryPrice = {
  answer_key: 'price'
  mode: InquiryPriceMode
  margin: number
  margin_max: number
}

export type InquiryClientRef = {
  id: number
  name: string
}

export type InquiryUserRef = {
  id: number
  name: string
}

/** Skąd przyszło zapytanie: wklejone w przeglądarce albo podjęte przez dodatek do Thunderbirda. */
export type InquiryChannel = 'web' | 'thunderbird'

/**
 * Po czym poznaliśmy, że to ten sam mail:
 * `message_id` — ten sam identyfikator wiadomości,
 * `fingerprint` — ta sama treść (np. mail przekazany ręcznie, inny identyfikator).
 */
export type InquiryDuplicateMatch = 'message_id' | 'fingerprint'

/**
 * Inne zapytanie założone z tego samego maila klienta.
 * `replied_at` niepuste = tamta osoba wysłała już odpowiedź do klienta.
 */
export type InquiryDuplicateRef = {
  id: number
  user: InquiryUserRef | null
  created_at: string | null
  source_subject?: string | null
  replied_at?: string | null
  match?: InquiryDuplicateMatch
}

/** Ciało odpowiedzi 409 z POST /api/inquiries — ten mail prowadzi już ktoś inny. */
export type InquiryDuplicateConflict = {
  message: string
  duplicate: InquiryDuplicateRef
}

/**
 * Kontakt wyciągnięty ze stopki maila. Puste pole = nie było go w stopce —
 * front niczego nie dopowiada. `null` w miejscu całego obiektu = nic nie wyciągnięto.
 */
export type InquiryContact = {
  person: string | null
  company: string | null
  emails: string[]
  phones: string[]
  address: string | null
  website: string | null
  raw: string | null
}

export type InquiryPayload = {
  id: number
  client_id: number | null
  client: InquiryClientRef | null
  tone: InquiryTone
  source_subject: string | null
  source_body: string
  /** Message-ID maila źródłowego — bez niego nie ma na co odpowiedzieć w Thunderbirdzie. */
  source_message_id: string | null
  source_channel: InquiryChannel | null
  source_from_name: string | null
  source_from_email: string | null
  /** Data wysłania maila źródłowego (ISO 8601) — inna niż data założenia zapytania. */
  source_sent_at: string | null
  contact: InquiryContact | null
  user: InquiryUserRef | null
  questions: string[]
  attention_count: number
  replied_at: string | null
  /** Ustawione, gdy list czeka na podjęcie przez dodatek do Thunderbirda. */
  send_requested_at: string | null
  price: InquiryPrice
  items: InquiryItem[]
  global_cards: InquiryCard[]
  /** Stare karty — front ich nie czyta. */
  cards: InquiryCard[]
  answers: Record<string, InquiryAnswer>
  extra_note: string | null
  reply_subject: string | null
  reply_body: string | null
  /** Ta sama treść jako tabela HTML; null po ręcznej poprawce tekstu. */
  reply_html: string | null
  created_at: string | null
  /** Zapytanie, którego to jest kopia (założone mimo ostrzeżenia); null = oryginał. */
  duplicate_of?: InquiryDuplicateRef | null
  /** Inne zapytania z tego samego maila, maks. 5. */
  duplicates?: InquiryDuplicateRef[]
}

export type InquiryListItem = {
  id: number
  source_subject: string | null
  reply_subject: string | null
  client: InquiryClientRef | null
  created_at: string | null
  source_channel: InquiryChannel | null
  source_from_name: string | null
  source_from_email: string | null
  source_sent_at: string | null
  has_reply: boolean
  replied_at: string | null
  /** Ustawione, gdy list czeka na podjęcie przez dodatek do Thunderbirda. */
  send_requested_at: string | null
  attention_count: number
  contact: InquiryContact | null
  user: InquiryUserRef | null
  /** Numer zapytania, którego ten wiersz jest kopią; null = oryginał. */
  duplicate_of_id?: number | null
  /** Ile innych osób ma zapytanie z tego samego maila; 0 = nikt. */
  duplicates_count?: number
}

export type InquiryListMeta = {
  page: number
  per_page: number
  total: number
  last_page: number
  /** Prawda, gdy użytkownik ma `inquiries.view_all` — dopiero wtedy działa `scope=all`. */
  can_view_all: boolean
}

export type InquiryListResponse = {
  data: InquiryListItem[]
  meta: InquiryListMeta
}

export type InquiryStatusFilter = 'all' | 'waiting' | 'replied'

export type InquiryChannelFilter = 'all' | InquiryChannel

export type InquiryScope = 'mine' | 'all'

export type InquiryPreferences = {
  tone: InquiryTone
  price_mode: InquiryPriceMode
  margin: number
}
