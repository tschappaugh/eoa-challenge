export interface Game {
  id: number
  title: string
  votes: number
  votedByMe: boolean
  canRemove: boolean
}

export interface DailyAction {
  type: 'vote' | 'add'
  gameId: number | null
}

export interface Me {
  timezone: string
  localDate: string
  actionAvailable: boolean
  action: DailyAction | null
  resetsAt: string
}

export const MAX_TITLE_LENGTH = 100
