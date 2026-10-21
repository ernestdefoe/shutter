import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import extractText from 'flarum/common/utils/extractText';
import DiscussionControls from 'flarum/forum/utils/DiscussionControls';
import type Mithril from 'mithril';
import { api, place, t } from '../../common/api';
import type { Entry, Person } from '../../common/api';
import type ContestState from './state';

interface Attrs {
  gallery: ContestState;
  list: Entry[];
  index: number;
  slideshow: boolean;
  close: () => void;
}

const SLIDE_MS = 5000;

/**
 * The photo at full size, with its story: title, caption, entrant, camera,
 * the vote or the judge's score, and who voted (named contests, once
 * decided). Arrow keys, swipes, a slideshow, full screen.
 *
 * Drawn in a root of its own on <body>, outside the post it was opened from.
 */
class Lightbox extends Component<Attrs> {
  index = 0;
  playing = false;
  timer = 0;
  voters: Record<number, Person[] | 'loading' | 'none'> = {};
  touchX: number | null = null;
  info = true;
  previousFocus: Element | null = null;
  editing: { id: number; title: string; caption: string; saving: boolean } | null = null;

  oninit(vnode: Mithril.Vnode<Attrs, this>) {
    super.oninit(vnode);
    this.index = this.attrs.index;
    this.info = window.innerWidth > 768;
    if (this.attrs.slideshow) this.play();
  }

  oncreate(vnode: Mithril.VnodeDOM<Attrs, this>) {
    super.oncreate(vnode);
    this.previousFocus = document.activeElement;
    (vnode.dom as HTMLElement).focus();
    document.body.classList.add('Shutter-noScroll');
    this.preload();
  }

  onremove() {
    window.clearInterval(this.timer);
    document.body.classList.remove('Shutter-noScroll');
    if (document.fullscreenElement) document.exitFullscreen().catch(() => {});
    (this.previousFocus as HTMLElement | null)?.focus?.();
  }

  get entry(): Entry {
    return this.attrs.list[this.index];
  }

  go(step: number) {
    const list = this.attrs.list;
    let next = this.index + step;

    // Near the end of what is loaded: fetch the next page in the background.
    if (list === this.attrs.gallery.entries && next >= list.length - 3 && this.attrs.gallery.next !== null) this.attrs.gallery.more();

    if (next >= list.length) next = 0;
    if (next < 0) next = list.length - 1;
    this.index = next;
    this.editing = null;
    this.preload();
    m.redraw();
  }

  /** The neighbours, so the next photo is already there when asked for. */
  preload() {
    const list = this.attrs.list;
    [1, -1].forEach((d) => {
      const e = list[(this.index + d + list.length) % list.length];
      if (e) new Image().src = e.large;
    });
  }

  play() {
    this.playing = true;
    window.clearInterval(this.timer);
    this.timer = window.setInterval(() => this.go(1), SLIDE_MS);
  }

  pause() {
    this.playing = false;
    window.clearInterval(this.timer);
  }

  keydown(e: KeyboardEvent) {
    if ((e.target as HTMLElement).closest('input, textarea')) return;
    if (e.key === 'ArrowRight') this.go(1);
    else if (e.key === 'ArrowLeft') this.go(-1);
    else if (e.key === 'Escape') this.attrs.close();
    else if (e.key === ' ' && (e.target as HTMLElement).tagName !== 'BUTTON') {
      this.playing ? this.pause() : this.play();
    } else return;
    e.preventDefault();
  }

  fullscreen() {
    const el = document.querySelector('.ShutterLightbox') as HTMLElement | null;
    if (document.fullscreenElement) document.exitFullscreen().catch(() => {});
    else el?.requestFullscreen?.().catch(() => {});
  }

  loadVoters(entry: Entry) {
    const c = this.attrs.gallery.contest;
    if (!c || this.voters[entry.id] || !(c.canManage || (c.namedVotes && c.phase === 'closed' && c.scoring === 'votes'))) return;
    this.voters[entry.id] = 'loading';
    api<{ data: Person[] }>('GET', `/entries/${entry.id}/voters`).then(
      ({ data }) => {
        this.voters[entry.id] = data;
        m.redraw();
      },
      () => (this.voters[entry.id] = 'none')
    );
  }

  view() {
    const entry = this.entry;
    if (!entry) return <div />;
    this.loadVoters(entry);
    const many = this.attrs.list.length > 1;

    return (
      <div
        className={'ShutterLightbox' + (this.info ? ' has-info' : '')}
        role="dialog"
        aria-modal="true"
        aria-label={entry.title}
        tabindex="-1"
        onkeydown={(e: KeyboardEvent) => this.keydown(e)}
      >
        <div
          className="ShutterLightbox-stage"
          onclick={(e: MouseEvent) => e.target === e.currentTarget && this.attrs.close()}
          ontouchstart={(e: TouchEvent) => (this.touchX = e.touches[0].clientX)}
          ontouchend={(e: TouchEvent) => {
            if (this.touchX === null) return;
            const dx = e.changedTouches[0].clientX - this.touchX;
            this.touchX = null;
            if (Math.abs(dx) > 50) this.go(dx < 0 ? 1 : -1);
          }}
        >
          <img
            className="ShutterLightbox-image"
            src={entry.large}
            alt={entry.title}
            width={entry.width}
            height={entry.height}
            style={entry.blur ? { backgroundImage: `url("${entry.blur}")` } : undefined}
          />
          {many ? (
            <>
              <button
                type="button"
                className="ShutterLightbox-nav ShutterLightbox-nav--prev"
                aria-label={extractText(t('lightbox.previous'))}
                onclick={() => this.go(-1)}
              >
                <Icon name="fas fa-chevron-left" />
              </button>
              <button
                type="button"
                className="ShutterLightbox-nav ShutterLightbox-nav--next"
                aria-label={extractText(t('lightbox.next'))}
                onclick={() => this.go(1)}
              >
                <Icon name="fas fa-chevron-right" />
              </button>
            </>
          ) : null}
        </div>

        <div className="ShutterLightbox-bar">
          <span className="ShutterLightbox-counter">{many ? `${this.index + 1} / ${this.attrs.list.length}` : ''}</span>
          {many ? (
            <Button
              className="Button Button--icon Button--flat"
              icon={this.playing ? 'fas fa-pause' : 'fas fa-play'}
              aria-label={extractText(t(this.playing ? 'lightbox.pause' : 'lightbox.play'))}
              onclick={() => (this.playing ? this.pause() : this.play())}
            />
          ) : null}
          <Button
            className="Button Button--icon Button--flat"
            icon="fas fa-circle-info"
            aria-pressed={this.info}
            aria-label={extractText(t('lightbox.info'))}
            onclick={() => (this.info = !this.info)}
          />
          {document.fullscreenEnabled ? (
            <Button
              className="Button Button--icon Button--flat"
              icon="fas fa-expand"
              aria-label={extractText(t('lightbox.fullscreen'))}
              onclick={() => this.fullscreen()}
            />
          ) : null}
          <Button
            className="Button Button--icon Button--flat"
            icon="fas fa-xmark"
            aria-label={extractText(t('lightbox.close'))}
            onclick={() => this.attrs.close()}
          />
        </div>

        {this.info ? this.panel(entry) : null}
      </div>
    );
  }

  panel(entry: Entry) {
    const gallery = this.attrs.gallery;
    const c = gallery.contest;
    const voters = this.voters[entry.id];
    const camera = entry.camera ? Object.values(entry.camera).join(' · ') : null;

    return (
      <aside className="ShutterLightbox-info">
        {this.editing?.id === entry.id ? this.editor(entry) : null}
        <h3 className="ShutterLightbox-title" hidden={this.editing?.id === entry.id}>
          {entry.placement ? <span className={`Shutter-medal Shutter-medal--${Math.min(entry.placement, 4)}`}>{place(entry.placement)}</span> : null}
          {entry.title}
        </h3>
        {entry.user ? (
          <p className="ShutterLightbox-by">{t('panel.by', { name: entry.user.displayName })}</p>
        ) : c?.hideEntrants ? (
          <p className="ShutterLightbox-by is-hidden">{t('lightbox.anonymous')}</p>
        ) : null}
        {entry.caption && this.editing?.id !== entry.id ? <p className="ShutterLightbox-caption">{entry.caption}</p> : null}
        {camera ? (
          <p className="ShutterLightbox-camera">
            <Icon name="fas fa-camera" /> {camera}
          </p>
        ) : null}

        {gallery.canVote(entry) ? (
          <Button
            className={'Button ' + (entry.voted ? 'Button--primary' : '')}
            icon={entry.voted ? 'fas fa-heart' : 'far fa-heart'}
            disabled={(!entry.voted && gallery.votesLeft() === 0) || gallery.busy.has(entry.id)}
            onclick={() => gallery.vote(entry)}
          >
            {t(entry.voted ? 'entry.unvote' : gallery.votesLeft() === 0 ? 'entry.out_of_votes' : 'entry.vote')}
          </Button>
        ) : null}
        {entry.votes !== undefined ? <p className="ShutterLightbox-tally">{t('panel.votes', { count: entry.votes })}</p> : null}
        {entry.score !== undefined && entry.score !== null ? (
          <p className="ShutterLightbox-tally">{t('panel.score', { score: entry.score.toFixed(1), count: entry.scores ?? 0 })}</p>
        ) : null}

        {c?.viewer.isJudge && !c.viewer.scoreBlock && !entry.mine ? (
          <div className="ShutterLightbox-score" role="group" aria-label={extractText(t('lightbox.your_score'))}>
            <span>{t('lightbox.your_score')}</span>
            <div className="ShutterLightbox-scoreButtons">
              {[1, 2, 3, 4, 5, 6, 7, 8, 9, 10].map((n) => (
                <button
                  type="button"
                  className={'Shutter-scoreButton' + (entry.myScore === n ? ' is-active' : '')}
                  aria-pressed={entry.myScore === n}
                  disabled={gallery.busy.has(entry.id)}
                  onclick={() => gallery.score(entry, entry.myScore === n ? null : n)}
                >
                  {n}
                </button>
              ))}
            </div>
          </div>
        ) : null}

        {Array.isArray(voters) && voters.length ? (
          <div className="ShutterLightbox-voters">
            <span>{t('lightbox.voted_by')}</span> {voters.map((p) => p.displayName).join(', ')}
          </div>
        ) : null}

        <div className="ShutterLightbox-actions">
          {app.session.user && this.discussion()?.canReply() ? (
            <Button className="Button" icon="fas fa-reply" onclick={() => this.reply(entry)}>
              {t('lightbox.discuss')}
            </Button>
          ) : null}
          {c?.canManage ? this.staff(entry) : this.own(entry)}
        </div>
      </aside>
    );
  }

  /** The entrant's own photo, while entries are open (or it still awaits approval). */
  own(entry: Entry) {
    const c = this.attrs.gallery.contest;
    if (!entry.mine || !c || !(c.phase === 'submissions' || (entry.status === 'pending' && c.phase !== 'closed'))) return null;

    return [
      this.editing?.id === entry.id ? null : (
        <Button className="Button" icon="fas fa-pen" onclick={() => this.rename(entry)}>
          {t('lightbox.edit')}
        </Button>
      ),
      <Button
        className="Button Button--danger"
        icon="fas fa-trash"
        onclick={() => {
          if (confirm(extractText(t('lightbox.withdraw_confirm')))) this.attrs.gallery.remove(entry).then(() => this.attrs.close());
        }}
      >
        {t('lightbox.withdraw')}
      </Button>,
    ];
  }

  rename(entry: Entry) {
    this.editing = { id: entry.id, title: entry.title, caption: entry.caption ?? '', saving: false };
  }

  save(entry: Entry) {
    const e = this.editing!;
    if (!e.title.trim()) return;
    e.saving = true;

    api<{ data: Entry }>('PATCH', `/entries/${entry.id}`, { title: e.title.trim(), caption: e.caption.trim() }).then(
      ({ data }) => {
        entry.title = data.title;
        entry.caption = data.caption;
        this.editing = null;
        m.redraw();
      },
      () => {
        e.saving = false;
        m.redraw();
      }
    );
  }

  /** The title and caption as fields, for the entrant renaming their photo. */
  editor(entry: Entry) {
    const e = this.editing!;

    return (
      <form
        className="ShutterLightbox-edit"
        onsubmit={(ev: Event) => {
          ev.preventDefault();
          this.save(entry);
        }}
      >
        <input
          className="FormControl"
          maxlength={100}
          required
          aria-label={extractText(t('submit.photo_title'))}
          value={e.title}
          oninput={(ev: InputEvent) => (e.title = (ev.target as HTMLInputElement).value)}
        />
        <textarea
          className="FormControl"
          maxlength={500}
          rows={3}
          aria-label={extractText(t('submit.caption'))}
          value={e.caption}
          oninput={(ev: InputEvent) => (e.caption = (ev.target as HTMLTextAreaElement).value)}
        />
        <div className="ShutterLightbox-editButtons">
          <Button type="submit" className="Button Button--primary" loading={e.saving}>
            {t('lightbox.save')}
          </Button>
          <Button className="Button" onclick={() => (this.editing = null)}>
            {t('lightbox.cancel')}
          </Button>
        </div>
      </form>
    );
  }

  staff(entry: Entry) {
    const gallery = this.attrs.gallery;
    const after = () => this.attrs.close();

    return [
      entry.status === 'pending' ? (
        <Button className="Button" icon="fas fa-check" onclick={() => gallery.moderate(entry, { approved: true }).then(after)}>
          {t('lightbox.approve')}
        </Button>
      ) : null,
      <Button
        className="Button"
        icon={entry.status === 'disqualified' ? 'fas fa-rotate-left' : 'fas fa-ban'}
        onclick={() => gallery.moderate(entry, { disqualified: entry.status !== 'disqualified' }).then(after)}
      >
        {t(entry.status === 'disqualified' ? 'lightbox.reinstate' : 'lightbox.disqualify')}
      </Button>,
      <Button
        className="Button Button--danger"
        icon="fas fa-trash"
        onclick={() => {
          if (confirm(extractText(t('lightbox.delete_confirm')))) gallery.remove(entry).then(after);
        }}
      >
        {t('lightbox.delete')}
      </Button>,
    ];
  }

  discussion() {
    const id = this.attrs.gallery.contest?.discussionId;
    return id ? app.store.getById<any>('discussions', String(id)) : null;
  }

  /** A reply quoting the photo's link, so the thread can talk about it. */
  reply(entry: Entry) {
    const discussion = this.discussion();
    if (!discussion) return;
    const url = location.origin + app.route.discussion(discussion) + '?photo=' + entry.id;
    this.attrs.close();

    DiscussionControls.replyAction
      .call(discussion, true, false)
      .then(() => app.composer.editor?.insertAtCursor(`[${entry.title.replace(/[[\]]/g, '')}](${url}) `, false))
      .catch(() => {});
  }
}

let root: HTMLElement | null = null;

export default function open(gallery: ContestState, list: Entry[], index: number, slideshow = false) {
  if (!root) {
    root = document.createElement('div');
    root.className = 'ShutterLightboxRoot';
    document.body.appendChild(root);
  }

  const close = () => {
    m.mount(root!, null);
    // The link that opened it, if any, is spent.
    const url = new URL(location.href);
    if (url.searchParams.has('photo')) {
      url.searchParams.delete('photo');
      history.replaceState(history.state, '', url.toString());
    }
  };

  m.mount(root, { view: () => <Lightbox gallery={gallery} list={list} index={index} slideshow={slideshow} close={close} /> });
}
