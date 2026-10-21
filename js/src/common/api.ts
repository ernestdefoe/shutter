import app from 'flarum/common/app';

export type Phase = 'upcoming' | 'submissions' | 'voting' | 'closed';

export interface Person {
  id: number;
  username: string;
  displayName: string;
  avatarUrl: string | null;
}

export interface Entry {
  id: number;
  contestId: number;
  title: string;
  caption: string | null;
  width: number;
  height: number;
  thumb: string;
  large: string;
  blur: string | null;
  camera: Record<string, string> | null;
  createdAt: string;
  mine: boolean;
  user: Person | null;
  placement: number | null;
  status?: 'approved' | 'pending' | 'disqualified';
  votes?: number;
  score?: number | null;
  scores?: number;
  voted?: boolean;
  myScore?: number | null;
}

export interface Viewer {
  enterBlock: string | null;
  voteBlock: string | null;
  scoreBlock: string | null;
  isJudge: boolean;
  entries: number;
  votesUsed: number;
}

export interface Contest {
  id: number;
  discussionId: number;
  phase: Phase;
  opensAt: string | null;
  submissionsCloseAt: string;
  votingCloseAt: string;
  rules: string | null;
  entriesPerMember: number;
  votesPerMember: number;
  scoring: 'votes' | 'judges';
  namedVotes: boolean;
  liveCounts: boolean;
  hideEntrants: boolean;
  moderateEntries: boolean;
  winnersCount: number;
  voterMinDays: number;
  voterMinPosts: number;
  enterGroups: { id: number; name: string }[];
  voteGroups: { id: number; name: string }[];
  resultsPublic: boolean;
  seesTallies: boolean;
  finalized: boolean;
  canManage: boolean;
  viewer: Viewer;
  entryCount: number;
  judges?: Person[];
  podium: Entry[];
  entries: Entry[];
  nextOffset: number | null;
  sort: Sort;
}

export type Sort = 'shuffle' | 'newest' | 'top';

const base = () => app.forum.attribute<string>('apiUrl') + '/shutter';

export function api<T>(method: string, path: string, body?: unknown, params?: Record<string, unknown>): Promise<T> {
  return app.request<T>({ method, url: base() + path, body: body as any, params: params as any });
}

export const t = (key: string, params: Record<string, unknown> = {}) => app.translator.trans(`ernestdefoe-shutter.forum.${key}`, params);

/** 1st, 2nd, 3rd, 4th… in the forum's language. */
export function place(n: number) {
  return app.translator.trans(`ernestdefoe-shutter.lib.place.${Math.min(n, 4)}`, { n });
}

/** "in 3 days", "2 hours ago" — the browser's own wording for the forum's locale. */
export function relative(iso: string): string {
  const diff = (new Date(iso).getTime() - Date.now()) / 1000;
  const units: [Intl.RelativeTimeFormatUnit, number][] = [
    ['day', 86400],
    ['hour', 3600],
    ['minute', 60],
  ];
  const rtf = new Intl.RelativeTimeFormat(app.translator.getLocale() || undefined, { numeric: 'auto' });
  for (const [unit, secs] of units) {
    if (Math.abs(diff) >= secs) return rtf.format(Math.round(diff / secs), unit);
  }
  return rtf.format(Math.round(diff), 'second');
}

export function when(iso: string): string {
  return new Date(iso).toLocaleString(app.translator.getLocale() || undefined, { dateStyle: 'medium', timeStyle: 'short' });
}
