// Typy 1:1 z kontraktem API „Zapytania” (PLAN_ZAPYTANIA_v2).

export type InquiryTone = 'formal' | 'handlowy'

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

export type InquiryFlag = 'low_score' | 'ambiguous' | 'no_price' | 'card_default'

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
  candidates: InquiryCandidate[]
  substitutes: InquirySubstitute[]
  cards: InquiryCard[]
}

export type InquiryPriceMode = 'none' | 'catalog' | 'catalog_margin'

export type InquiryPrice = {
  answer_key: 'price'
  mode: InquiryPriceMode
  margin: number
}

export type InquiryClientRef = {
  id: number
  name: string
}

export type InquiryPayload = {
  id: number
  client_id: number | null
  client: InquiryClientRef | null
  tone: InquiryTone
  source_subject: string | null
  source_body: string
  questions: string[]
  attention_count: number
  replied_at: string | null
  price: InquiryPrice
  items: InquiryItem[]
  global_cards: InquiryCard[]
  /** Stare karty — front ich nie czyta. */
  cards: InquiryCard[]
  answers: Record<string, InquiryAnswer>
  extra_note: string | null
  reply_subject: string | null
  reply_body: string | null
  created_at: string | null
}

export type InquiryListItem = {
  id: number
  source_subject: string | null
  reply_subject: string | null
  client: InquiryClientRef | null
  created_at: string | null
  has_reply: boolean
  replied_at: string | null
  attention_count: number
}

export type InquiryPreferences = {
  tone: InquiryTone
  price_mode: InquiryPriceMode
  margin: number
}
