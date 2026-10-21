import app from 'flarum/common/app';

export type Format = 'single' | 'double' | 'round_robin' | 'swiss';
export type Status = 'draft' | 'registration' | 'running' | 'finished' | 'cancelled';
export type MatchStatus = 'pending' | 'open' | 'reported' | 'disputed' | 'awaiting' | 'completed' | 'bye' | 'void';

export interface Person {
  id: number;
  username: string;
  displayName: string;
  avatarUrl: string | null;
}

export interface Summary {
  id: number;
  title: string;
  slug: string;
  game: string;
  format: Format;
  entryMode: 'members' | 'staff';
  entryType: 'solo' | 'team';
  status: Status;
  maxEntrants: number;
  entrantCount: number;
  predictions: boolean;
  predictionCount: number;
  startsAt: string | null;
  startedAt: string | null;
  finishedAt: string | null;
  organiser: Person | null;
}

export interface Entrant {
  id: number;
  name: string;
  user: Person | null;
  groupId: number | null;
  logoUrl: string | null;
  picksTeamId: number | null;
  status: 'registered' | 'waitlisted' | 'withdrawn' | 'disqualified' | 'no_show';
  seed: number | null;
  checkedIn: boolean;
  finalRank: number | null;
  wins: number;
  losses: number;
  draws: number;
  rating: number | null;
  members: Person[];
  /** Requests to join, for the captain and organisers. */
  requests?: Person[];
}

export interface Game {
  homeTeamId: number;
  awayTeamId: number;
  homeScore: number | null;
  awayScore: number | null;
  status: string;
  startsAt: string | null;
  detail: string;
}

export interface Match {
  id: number;
  bracket: 'W' | 'L' | 'F' | 'P' | 'R' | 'S';
  round: number;
  position: number;
  key: string;
  entrant1Id: number | null;
  entrant2Id: number | null;
  dead1: boolean;
  dead2: boolean;
  score1: number | null;
  score2: number | null;
  winnerId: number | null;
  status: MatchStatus;
  bestOf: number;
  discussionId: number | null;
  scheduledAt: string | null;
  completedAt: string | null;
  picksEventId: number | null;
  game: Game | null;
  next: [string, number] | null;
  loser: [string, number] | null;
  reports?: Record<1 | 2, [number, number] | null>;
  correctable?: boolean;
}

export interface StandingRow {
  entrant: number;
  played: number;
  wins: number;
  draws: number;
  losses: number;
  points: number;
  tiebreak: number;
  for: number;
  against: number;
  rank: number;
}

export interface PredictionData {
  picks: Record<string, number> | null;
  tiebreaker: number | null;
  score: number;
  correct: number;
  possible: number;
  user: Person | null;
  place?: number;
}

export interface Tournament extends Summary {
  description: string | null;
  teamSize: number;
  waitlist: boolean;
  checkinMinutes: number;
  checkinOpensAt: string | null;
  registrationClosesAt: string | null;
  seeding: 'random' | 'manual' | 'rating';
  bestOf: number;
  thirdPlace: boolean;
  grandFinalReset: boolean;
  swissRounds: number;
  currentRound: number;
  allowDraws: boolean;
  confirmation: 'agree' | 'staff';
  matchDiscussions: boolean;
  predictionsLockAt: string | null;
  predictionPoints: number[] | null;
  discussionId: number | null;
  tagId: number | null;
  rewardPoints?: number[] | null;
  rewardBadgeId?: number | null;
  entrants: Entrant[];
  matches: Match[];
  standings?: StandingRow[];
  me: {
    entrantId: number | null;
    requestedEntrantId: number | null;
    canManage: boolean;
    canReward: boolean;
    canJoin: boolean;
    canPredict: boolean;
    predictionsOpen: boolean;
    groups: { id: number; name: string }[];
    prediction: PredictionData | null;
    picksAvailable: boolean;
  };
}

export interface History {
  entries: {
    id: number;
    title: string;
    slug: string;
    game: string;
    format: Format;
    status: Status;
    entrantCount: number;
    name: string;
    entryStatus: string;
    finalRank: number | null;
    wins: number;
    losses: number;
    draws: number;
  }[];
  predictions: {
    id: number;
    title: string;
    slug: string;
    status: Status;
    count: number;
    score: number;
    correct: number;
    possible: number;
    place: number;
  }[];
  ratings: { game: string; rating: number; played: number; wins: number; losses: number; draws: number }[];
}

const url = (path: string) => app.forum.attribute<string>('apiUrl') + '/gauntlet' + path;

export function call<T>(method: string, path: string, body?: object, params?: Record<string, unknown>): Promise<T> {
  return app.request<T>({ method, url: url(path), body, params });
}

type Full = Promise<{ data: Tournament }>;

export const api = {
  list: (filter: string, page = 1) =>
    call<{ data: Summary[]; meta: { page: number; more: boolean; canCreate: boolean } }>('GET', '/tournaments', undefined, { filter, page }),
  show: (id: number): Full => call('GET', `/tournaments/${id}`),
  save: (data: object, id?: number): Full => call(id ? 'PATCH' : 'POST', id ? `/tournaments/${id}` : '/tournaments', data),
  remove: (id: number) => call<void>('DELETE', `/tournaments/${id}`),
  act: (id: number, action: 'publish' | 'start' | 'reset' | 'cancel' | 'discussion'): Full => call('POST', `/tournaments/${id}/${action}`),
  signUp: (id: number, data: object = {}): Full => call('POST', `/tournaments/${id}/entrants`, data),
  updateEntrant: (id: number, data: object): Full => call('PATCH', `/entrants/${id}`, data),
  withdraw: (id: number, disqualify = false): Full => call('DELETE', `/entrants/${id}`, undefined, disqualify ? { disqualify: 1 } : undefined),
  checkIn: (id: number, undo = false): Full => call(undo ? 'DELETE' : 'POST', `/entrants/${id}/checkin`),
  join: (id: number): Full => call('POST', `/entrants/${id}/members`),
  leave: (id: number, userId: number): Full => call('DELETE', `/entrants/${id}/members/${userId}`),
  approve: (id: number, userId: number): Full => call('POST', `/entrants/${id}/members/${userId}`),
  report: (id: number, score1: number, score2: number): Full => call('POST', `/matches/${id}/report`, { score1, score2 }),
  resolve: (id: number, data: { score1?: number | null; score2?: number | null; winner?: 1 | 2 | null }): Full =>
    call('POST', `/matches/${id}/resolve`, data),
  updateMatch: (id: number, data: object): Full => call('PATCH', `/matches/${id}`, data),
  matchDiscussion: (id: number): Full => call('POST', `/matches/${id}/discussion`),
  predict: (id: number, picks: Record<string, number>, tiebreaker: number | null) =>
    call<{ data: PredictionData }>('PUT', `/tournaments/${id}/prediction`, { picks, tiebreaker }),
  board: (id: number, page = 1) =>
    call<{ data: PredictionData[]; meta: { page: number; more: boolean } }>('GET', `/tournaments/${id}/predictions`, undefined, { page }),
  history: (userId: number | string) => call<{ data: History }>('GET', `/users/${userId}`),
  picksTeams: (q: string) =>
    call<{ data: { id: number; name: string; abbreviation: string | null; logoUrl: string | null }[] }>('GET', '/picks/teams', undefined, { q }),
  picksGames: (a: number, b: number) =>
    call<{ data: { id: number; startsAt: string | null; status: string }[] }>('GET', '/picks/games', undefined, { a, b }),
};

/** "/tournaments/12-spring-cup" */
export function tournamentUrl(t: { id: number; slug: string }): string {
  return app.route('gauntlet.tournament', { id: t.id + (t.slug ? '-' + t.slug : '') });
}

export const t = (key: string, params: Record<string, unknown> = {}) => app.translator.trans(`ernestdefoe-gauntlet.forum.${key}`, params);
