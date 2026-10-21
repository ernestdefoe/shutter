import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import Select from 'flarum/common/components/Select';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Tooltip from 'flarum/common/components/Tooltip';
import extractText from 'flarum/common/utils/extractText';
import type Discussion from 'flarum/common/models/Discussion';
import type Mithril from 'mithril';
import { api, place, relative, t, when } from '../../common/api';
import type { Entry, Sort } from '../../common/api';
import ContestState from './state';
import Tile from './Tile';
import { take } from '../deepLink';

interface Attrs {
  contestId: number;
  discussion: Discussion;
}

/** The phase the clock says it is, to notice a deadline passing. */
function phaseOf(c: { opensAt: string | null; submissionsCloseAt: string; votingCloseAt: string }) {
  const now = Date.now();
  if (c.opensAt && now < Date.parse(c.opensAt)) return 'upcoming';
  if (now < Date.parse(c.submissionsCloseAt)) return 'submissions';
  if (now < Date.parse(c.votingCloseAt)) return 'voting';
  return 'closed';
}

/** Contest states survive the post being redrawn, scrolled away and back. */
const states = new Map<number, ContestState>();

export function openLightbox(state: ContestState, list: Entry[], index: number, slideshow = false) {
  import('./Lightbox').then(({ default: open }) => open(state, list, index, slideshow));
}

/**
 * The contest, under its discussion's first post: where it stands, what the
 * reader can do, the podium once decided, and the gallery.
 */
export default class ContestPanel extends Component<Attrs> {
  gallery!: ContestState;
  timer = 0;

  oninit(vnode: Mithril.Vnode<Attrs, this>) {
    super.oninit(vnode);

    const id = this.attrs.contestId;
    if (!states.has(id)) states.set(id, new ContestState(id));
    this.gallery = states.get(id)!;
    this.gallery.load().then(
      () => this.deepLink(),
      () => {}
    );

    // Countdowns tick; when a deadline passes, the contest is fetched again
    // so the page moves to the next phase without a reload.
    this.timer = window.setInterval(() => {
      const c = this.gallery.contest;
      c && phaseOf(c) !== c.phase ? this.gallery.load() : m.redraw();
    }, 30000);
  }

  onremove() {
    window.clearInterval(this.timer);
  }

  /** ?photo=12 opens that photo: links from alerts, emails and replies. */
  deepLink() {
    const id = take();
    if (!id) return;

    const list = this.gallery.entries;
    const index = list.findIndex((e) => e.id === id);
    if (index >= 0) return openLightbox(this.gallery, list, index);

    // Only a photo of this contest: a link must not open another's here.
    api<{ data: Entry }>('GET', `/entries/${id}`).then(
      ({ data }) => data.contestId === this.gallery.id && openLightbox(this.gallery, [data], 0),
      () => {}
    );
  }

  view() {
    const gallery = this.gallery;
    const c = gallery.contest;

    if (!c) {
      return <div className="Shutter Shutter--loading">{gallery.loading ? <LoadingIndicator display="block" /> : null}</div>;
    }

    return (
      <section className={`Shutter Shutter--${c.phase}`} aria-label={extractText(t('panel.label'))}>
        {this.head()}
        {c.rules ? (
          <details className="Shutter-rules" open={c.phase !== 'closed'}>
            <summary>{t('panel.rules')}</summary>
            <p>{c.rules}</p>
          </details>
        ) : null}
        {c.judges?.length ? (
          <p className="Shutter-judges">
            <Icon name="fas fa-gavel" /> {t('panel.judged_by', { names: c.judges.map((j) => j.displayName).join(', ') })}
          </p>
        ) : null}
        {c.finalized && c.podium.length ? this.podium() : null}
        {this.toolbar()}
        {this.grid()}
      </section>
    );
  }

  head() {
    const c = this.gallery.contest!;
    const v = c.viewer;
    const milestone =
      c.phase === 'upcoming'
        ? ['opens', c.opensAt!]
        : c.phase === 'submissions'
          ? ['submissions_close', c.submissionsCloseAt]
          : c.phase === 'voting'
            ? ['voting_closes', c.votingCloseAt]
            : ['closed', c.votingCloseAt];

    return (
      <header className="Shutter-head">
        <div className="Shutter-status">
          <span className={`Shutter-phase Shutter-phase--${c.phase}`}>
            <Icon name="fas fa-camera-retro" /> {t(`phase.${c.phase}`)}
          </span>
          <Tooltip text={when(milestone[1])}>
            <span className="Shutter-when">{t(`panel.${milestone[0]}`, { when: relative(milestone[1]) })}</span>
          </Tooltip>
          <span className="Shutter-count">{t('panel.photos', { count: c.entryCount })}</span>
        </div>

        <div className="Shutter-you">
          {this.youText()}
          <div className="Shutter-actions">
            {!v.enterBlock ? (
              <Button
                className="Button Button--primary"
                icon="fas fa-upload"
                onclick={() => app.modal.show(() => import('./SubmitModal'), { gallery: this.gallery })}
              >
                {t('panel.submit')}
              </Button>
            ) : null}
            {c.canManage ? (
              <Button
                className="Button"
                icon="fas fa-pen"
                onclick={() =>
                  app.modal.show(() => import('./ContestEditorModal'), { discussion: this.attrs.discussion, contestId: c.id, gallery: this.gallery })
                }
              >
                {t('panel.edit')}
              </Button>
            ) : null}
            {c.canManage && c.scoring === 'votes' && c.phase !== 'upcoming' && c.phase !== 'submissions' ? (
              <Button
                className="Button"
                icon="fas fa-shield-halved"
                onclick={() => app.modal.show(() => import('./IntegrityModal'), { gallery: this.gallery })}
              >
                {t('panel.review_votes')}
              </Button>
            ) : null}
          </div>
        </div>
      </header>
    );
  }

  /** One line saying what this reader can do now, and if not, why not. */
  youText() {
    const c = this.gallery.contest!;
    const v = c.viewer;
    const lines: Mithril.Children[] = [];

    if (c.phase === 'submissions' || c.phase === 'upcoming') {
      if (!v.enterBlock) {
        lines.push(t('panel.you.entries_left', { count: c.entriesPerMember - v.entries, total: c.entriesPerMember }));
      } else if (v.enterBlock !== 'closed') {
        lines.push(this.reason('enter', v.enterBlock, c.enterGroups));
      } else if (c.phase === 'upcoming') {
        lines.push(t('panel.you.not_open_yet'));
      }
    }

    if (c.phase === 'voting') {
      if (c.scoring === 'judges') {
        lines.push(v.isJudge ? t('panel.you.judge') : t('panel.you.judges_deciding'));
      } else if (!v.voteBlock) {
        lines.push(t('panel.you.votes_left', { count: this.gallery.votesLeft(), total: c.votesPerMember }));
      } else {
        lines.push(this.reason('vote', v.voteBlock, c.voteGroups));
      }
    }

    if (c.phase === 'closed' && !c.finalized) lines.push(t('panel.you.counting'));

    return lines.length ? <p className="Shutter-youText">{lines}</p> : null;
  }

  reason(kind: 'enter' | 'vote', block: string, groups: { name: string }[]) {
    const c = this.gallery.contest!;
    return t(`block.${block}`, {
      groups: groups.map((g) => g.name).join(', '),
      days: c.voterMinDays,
      posts: c.voterMinPosts,
      kind: extractText(t(`block.kind_${kind}`)),
    });
  }

  podium() {
    const c = this.gallery.contest!;
    const list = c.podium;

    return (
      <div className="Shutter-podium" aria-label={extractText(t('panel.winners'))}>
        <h3 className="Shutter-podiumTitle">
          <Icon name="fas fa-trophy" /> {t('panel.winners')}
        </h3>
        <div className="Shutter-podiumRow">
          {list.map((entry, i) => (
            <figure className={`Shutter-place Shutter-place--${Math.min(entry.placement ?? 4, 4)}`} key={entry.id}>
              <button type="button" className="Shutter-placeImage" onclick={() => openLightbox(this.gallery, list, i)} aria-label={entry.title}>
                <img src={entry.thumb} alt="" loading="lazy" style={entry.blur ? { backgroundImage: `url("${entry.blur}")` } : undefined} />
                <span className={`Shutter-medal Shutter-medal--${Math.min(entry.placement!, 4)}`}>{place(entry.placement!)}</span>
              </button>
              <figcaption>
                <strong>{entry.title}</strong>
                {entry.user ? <span className="Shutter-by">{t('panel.by', { name: entry.user.displayName })}</span> : null}
                <span className="Shutter-tally">{this.tally(entry)}</span>
                {entry.atriumUrl ? (
                  <a className="Shutter-gallery" href={entry.atriumUrl}>
                    {t('panel.in_gallery')}
                  </a>
                ) : null}
              </figcaption>
            </figure>
          ))}
        </div>
      </div>
    );
  }

  tally(entry: Entry) {
    if (entry.votes !== undefined) return t('panel.votes', { count: entry.votes });
    if (entry.score !== undefined && entry.score !== null) return t('panel.score', { score: entry.score.toFixed(1), count: entry.scores ?? 0 });
    return null;
  }

  toolbar() {
    const gallery = this.gallery;
    const c = gallery.contest!;
    const sorts: Sort[] = c.seesTallies ? ['top', 'shuffle', 'newest'] : ['shuffle', 'newest'];
    const views: Array<'all' | 'mine' | 'pending'> = ['all'];
    if (c.viewer.entries > 0) views.push('mine');
    if (c.canManage) views.push('pending');

    return (
      <div className="Shutter-toolbar">
        {views.length > 1 ? (
          <div className="Shutter-tabs" role="tablist">
            {views.map((view) => (
              <Button
                className={'Button Button--flat' + (gallery.view === view ? ' active' : '')}
                role="tab"
                aria-selected={gallery.view === view}
                onclick={() => gallery.setView(view)}
              >
                {t(`panel.view.${view}`)}
              </Button>
            ))}
          </div>
        ) : (
          <span />
        )}
        <div className="Shutter-toolbarEnd">
          <Select
            className="Shutter-sort"
            aria-label={extractText(t('panel.sort.label'))}
            value={gallery.sort ?? ''}
            options={Object.fromEntries(sorts.map((sort) => [sort, extractText(t(`panel.sort.${sort}`))]))}
            onchange={(value: Sort) => gallery.setSort(value)}
          />
          {gallery.entries.length > 1 ? (
            <Button className="Button" icon="fas fa-play" onclick={() => openLightbox(gallery, gallery.entries, 0, true)}>
              {t('panel.slideshow')}
            </Button>
          ) : null}
        </div>
      </div>
    );
  }

  grid() {
    const gallery = this.gallery;

    if (gallery.loading && !gallery.entries.length) return <LoadingIndicator display="block" />;

    if (!gallery.entries.length) {
      return <p className="Shutter-empty">{t(gallery.view === 'all' ? 'panel.empty' : `panel.empty_${gallery.view}`)}</p>;
    }

    return (
      <div className={'Shutter-gridWrap' + (gallery.loading ? ' is-loading' : '')}>
        <ul className="Shutter-grid">
          {gallery.entries.map((entry, i) => (
            <li key={entry.id}>
              <Tile gallery={gallery} entry={entry} onopen={() => openLightbox(gallery, gallery.entries, i)} />
            </li>
          ))}
        </ul>
        {gallery.next !== null ? (
          <div className="Shutter-more">
            <Button className="Button" loading={gallery.loadingMore} onclick={() => gallery.more()}>
              {t('panel.more')}
            </Button>
          </div>
        ) : null}
      </div>
    );
  }
}
