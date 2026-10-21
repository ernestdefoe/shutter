import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import PageStructure from 'flarum/forum/components/PageStructure';
import IndexSidebar from 'flarum/forum/components/IndexSidebar';
import Button from 'flarum/common/components/Button';
import Link from 'flarum/common/components/Link';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import { api, relative, t } from '../../common/api';
import type { Phase } from '../../common/api';

interface Card {
  id: number;
  phase: Phase;
  opensAt: string | null;
  submissionsCloseAt: string;
  votingCloseAt: string;
  entryCount: number;
  discussion: { id: number; title: string | null; slug: string | null };
  cover: { thumb: string; blur: string | null } | null;
}

/** /contests — running and upcoming contests, then the decided ones. */
export default class ContestsPage extends Page {
  tab: 'active' | 'past' = 'active';
  cards: Card[] = [];
  next: number | null = null;
  loading = true;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    app.setTitle(extractText(t('contests.title')));
    app.history.push('contests', extractText(t('contests.title')));
    this.load();
  }

  load(more = false) {
    this.loading = true;
    api<{ data: Card[]; nextOffset: number | null }>('GET', '/contests', undefined, { status: this.tab, offset: more ? this.next : 0 }).then(
      ({ data, nextOffset }) => {
        this.cards = more ? [...this.cards, ...data] : data;
        this.next = nextOffset;
        this.loading = false;
        m.redraw();
      }
    );
  }

  view() {
    return (
      <PageStructure className="ShutterContestsPage" sidebar={() => <IndexSidebar />}>
        <div className="ShutterContestsPage-head">
          <h2>{t('contests.title')}</h2>
          <div className="Shutter-tabs" role="tablist">
            {(['active', 'past'] as const).map((tab) => (
              <Button
                className={'Button Button--flat' + (this.tab === tab ? ' active' : '')}
                role="tab"
                aria-selected={this.tab === tab}
                onclick={() => {
                  this.tab = tab;
                  this.load();
                }}
              >
                {t(`contests.${tab}`)}
              </Button>
            ))}
          </div>
        </div>

        {this.loading && !this.cards.length ? <LoadingIndicator display="block" /> : null}
        {!this.loading && !this.cards.length ? <p className="Shutter-empty">{t(`contests.empty_${this.tab}`)}</p> : null}

        <ul className="ShutterContests">{this.cards.map((card) => this.card(card))}</ul>
        {this.next !== null ? (
          <div className="Shutter-more">
            <Button className="Button" loading={this.loading} onclick={() => this.load(true)}>
              {t('panel.more')}
            </Button>
          </div>
        ) : null}
      </PageStructure>
    );
  }

  card(card: Card) {
    const href = app.route('discussion', { id: card.discussion.id + (card.discussion.slug ? '-' + card.discussion.slug : '') });
    const deadline = card.phase === 'upcoming' ? card.opensAt! : card.phase === 'submissions' ? card.submissionsCloseAt : card.votingCloseAt;
    const label = { upcoming: 'opens', submissions: 'submissions_close', voting: 'voting_closes', closed: 'closed' }[card.phase];

    return (
      <li key={card.id}>
        <Link href={href} className="ShutterContest">
          <span className="ShutterContest-cover" style={card.cover?.blur ? { backgroundImage: `url("${card.cover.blur}")` } : undefined}>
            {card.cover ? <img src={card.cover.thumb} alt="" loading="lazy" /> : <i className="fas fa-camera-retro" aria-hidden="true" />}
          </span>
          <span className="ShutterContest-body">
            <span className={`Shutter-phase Shutter-phase--${card.phase}`}>{t(`phase.${card.phase}`)}</span>
            <strong className="ShutterContest-title">{card.discussion.title}</strong>
            <span className="ShutterContest-meta">
              {t(`panel.${label}`, { when: relative(deadline) })} · {t('panel.photos', { count: card.entryCount })}
            </span>
          </span>
        </Link>
      </li>
    );
  }
}
