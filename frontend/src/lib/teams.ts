/**
 * Zespoły (Administracja → Role, /api/admin/teams). Kierownik zespołu (is_leader) widzi w Raportach wynik kampanii
 * członków swoich zespołów; zwykły członek widzi tylko własne kampanie.
 */

export type TeamMember = {
  user_id: number
  name: string
  /** Kierownik zespołu. */
  is_leader: boolean
}

export type Team = {
  id: number
  name: string
  /** Kierownicy na początku, potem alfabetycznie. */
  members: TeamMember[]
}

/** Użytkownik do wyboru członków zespołu; role = kod roli uprawnień. */
export type TeamUserOption = {
  id: number
  name: string
  role: string
}

export type TeamsResponse = {
  data: Team[]
  users: TeamUserOption[]
}

/** Treść POST /admin/teams i PUT /admin/teams/{id}; lista członków zastępuje dotychczasową w całości. */
export type TeamPayload = {
  name: string
  members: { user_id: number; is_leader: boolean }[]
}

export function sortTeamsByName(teams: Team[]): Team[] {
  return [...teams].sort((a, b) => a.name.localeCompare(b.name, 'pl'))
}
