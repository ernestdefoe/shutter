import app from 'flarum/forum/app';
import { api } from '../../common/api';
import type { Contest, Entry, Sort } from '../../common/api';

export type View = 'all' | 'mine' | 'pending';

/**
 * One contest as the page knows it: the contest, the entries loaded so far,
 * and every action on them. Shared by the panel and the lightbox, so a vote
 * cast in one shows in the other.
 */
export default class ContestState {
  contest: Contest | null = null;
  entries: Entry[] = [];
  next: number | null = null;
  sort: Sort | null = null;
  view: View = 'all';
  loading = true;
  loadingMore = false;
  busy = new Set<number>();
  /** Only the newest request's answer is kept. */
  private asked = 0;

  constructor(public id: number) {}

  params(offset = 0) {
    const p: Record<string, unknown> = { offset };
    if (this.sort) p.sort = this.sort;
    if (this.view === 'mine') p.mine = 1;
    if (this.view === 'pending') p.pending = 1;
    return p;
  }

  load(): Promise<void> {
    this.loading = true;
    const ask = ++this.asked;

    return api<{ data: Contest }>('GET', `/contests/${this.id}`, undefined, this.params()).then(
      ({ data }) => {
        if (ask !== this.asked) return;
        this.contest = data;
        this.entries = data.entries;
        this.next = data.nextOffset;
        this.sort = data.sort;
        this.loading = false;
        m.redraw();
      },
      (e) => {
        this.loading = false;
        m.redraw();
        throw e;
      }
    );
  }

  more(): Promise<void> {
    if (this.next === null || this.loadingMore) return Promise.resolve();
    this.loadingMore = true;
    const ask = this.asked;

    return api<{ data: Entry[]; nextOffset: number | null }>('GET', `/contests/${this.id}/entries`, undefined, this.params(this.next)).then(
      ({ data, nextOffset }) => {
        this.loadingMore = false;
        if (ask !== this.asked) return;
        const seen = new Set(this.entries.map((e) => e.id));
        this.entries.push(...data.filter((e) => !seen.has(e.id)));
        this.next = nextOffset;
        m.redraw();
      },
      () => {
        this.loadingMore = false;
        m.redraw();
      }
    );
  }

  canVote(entry: Entry): boolean {
    const c = this.contest;
    return !!c && !c.viewer.voteBlock && !entry.mine && entry.status !== 'pending' && entry.status !== 'disqualified';
  }

  votesLeft(): number {
    const c = this.contest!;
    return Math.max(0, c.votesPerMember - c.viewer.votesUsed);
  }

  vote(entry: Entry) {
    if (this.busy.has(entry.id) || !this.contest) return;
    const undo = !!entry.voted;
    if (!undo && this.votesLeft() === 0) return;

    this.busy.add(entry.id);
    const request = api<{ data: { voted: boolean; votesUsed: number; votes: number | null } }>(undo ? 'DELETE' : 'POST', `/entries/${entry.id}/vote`);

    request
      .then(
        ({ data }) => this.applyVote(entry.id, data),
        () => {}
      )
      .finally(() => {
        this.busy.delete(entry.id);
        m.redraw();
      });
  }

  private applyVote(id: number, data: { voted: boolean; votesUsed: number; votes: number | null }) {
    for (const e of [...this.entries, ...(this.contest?.podium ?? [])]) {
      if (e.id !== id) continue;
      e.voted = data.voted;
      if (data.votes !== null && data.votes !== undefined) e.votes = data.votes;
    }
    this.contest!.viewer.votesUsed = data.votesUsed;
  }

  score(entry: Entry, score: number | null) {
    this.busy.add(entry.id);
    api<{ data: { myScore: number | null } }>('PUT', `/entries/${entry.id}/score`, { score })
      .then(
        ({ data }) => {
          entry.myScore = data.myScore;
        },
        () => {}
      )
      .finally(() => {
        this.busy.delete(entry.id);
        m.redraw();
      });
  }

  /** Staff: approve, disqualify, reinstate. The gallery is reloaded: placings may move. */
  moderate(entry: Entry, change: Record<string, boolean>) {
    return api('PATCH', `/entries/${entry.id}`, change).then(() => this.load());
  }

  remove(entry: Entry) {
    return api('DELETE', `/entries/${entry.id}`).then(() => this.load());
  }

  setView(view: View) {
    this.view = view;
    this.sort = null;
    this.load();
  }

  setSort(sort: Sort) {
    this.sort = sort;
    this.load();
  }

  isSignedIn() {
    return !!app.session.user;
  }
}
