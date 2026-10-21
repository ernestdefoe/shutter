import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import Discussion from 'flarum/common/models/Discussion';
import Badge from 'flarum/common/components/Badge';
import Button from 'flarum/common/components/Button';
import LinkButton from 'flarum/common/components/LinkButton';
import IndexSidebar from 'flarum/forum/components/IndexSidebar';
import CommentPost from 'flarum/forum/components/CommentPost';
import DiscussionControls from 'flarum/forum/utils/DiscussionControls';
import type ItemList from 'flarum/common/utils/ItemList';
import type Mithril from 'mithril';
import { t } from '../common/api';
import notifications from './notifications';
import Mount from './components/Mount';
import DiscussionPage from 'flarum/forum/components/DiscussionPage';
import { remember } from './deepLink';

/*
 * Everything here runs on every page for every visitor, so it is only the
 * wiring: a route, a nav link, a badge, a placeholder in a contest's first
 * post. The gallery, the lightbox, the editor and the contests page are
 * their own chunks, fetched the first time something needs them.
 */
const loadPanel = () => import('./components/ContestPanel');

app.initializers.add('ernestdefoe-shutter', () => {
  app.routes['shutter.contests'] = { path: '/contests', component: () => import('./pages/ContestsPage') };

  const contestOf = (discussion: Discussion) => discussion.attribute<{ id: number; phase: string } | null>('shutterContest');

  extend(IndexSidebar.prototype, 'navItems', function (items) {
    if (!app.forum.attribute('shutterNav')) return;

    items.add(
      'photoContests',
      <LinkButton href={app.route('shutter.contests')} icon="fas fa-camera-retro">
        {t('nav')}
      </LinkButton>,
      4
    );
  });

  extend(Discussion.prototype, 'badges', function (this: Discussion, badges: ItemList<Mithril.Children>) {
    const contest = contestOf(this);
    if (contest) {
      badges.add(
        'photoContest',
        <Badge type="photoContest" icon="fas fa-camera-retro" label={t(`phase.${contest.phase}`)} className={`Badge--shutter-${contest.phase}`} />,
        5
      );
    }
  });

  // 🚨 Posts keep their rendered subtree until something they check changes,
  // so a gallery inside one would never redraw. The contest's first post
  // (and only that one) opts out.
  let tick = 0;
  extend(CommentPost.prototype, 'oninit', function (this: CommentPost) {
    this.subtree.check(() => {
      const discussion = this.attrs.post.discussion();
      return this.attrs.post.number() === 1 && discussion && contestOf(discussion) ? ++tick : 0;
    });
  });

  // The contest lives under the discussion's first post: the brief, then the gallery.
  extend(CommentPost.prototype, 'content', function (this: CommentPost, content: Mithril.Children[]) {
    const post = this.attrs.post;
    const discussion = post.discussion();
    const contest = discussion && contestOf(discussion);

    if (contest && post.number() === 1 && !this.isEditing()) {
      content.push(<Mount name="panel" load={loadPanel} attrs={{ contestId: contest.id, discussion }} />);
    }
  });

  extend(DiscussionControls, 'moderationControls', function (items, discussion: Discussion) {
    if (!app.forum.attribute('canManagePhotoContests')) return;
    const contest = contestOf(discussion);

    items.add(
      'photoContest',
      <Button
        icon="fas fa-camera-retro"
        onclick={() => app.modal.show(() => import('./components/ContestEditorModal'), { discussion, contestId: contest?.id ?? null })}
      >
        {t(contest ? 'controls.edit' : 'controls.start')}
      </Button>
    );
  });

  // Staff can make a new discussion a contest as they write it. Composers are
  // lazy in Flarum 2, so they are extended by module name, and the choice is
  // kept on the composer's body attrs, which survive redraws.
  extend('flarum/forum/components/DiscussionComposer', 'headerItems', function (this: any, items: ItemList<Mithril.Children>) {
    if (!app.forum.attribute('canManagePhotoContests')) return;
    const attrs = this.composer.body.attrs;

    items.add(
      'photoContest',
      <Button
        className={'Button Button--ua-reset ShutterComposerButton' + (attrs.shutterContest ? ' active' : '')}
        icon="fas fa-camera-retro"
        onclick={() =>
          app.modal.show(() => import('./components/ContestEditorModal'), {
            draft: attrs.shutterContest ?? null,
            onDraft: (value: Record<string, unknown> | null) => (attrs.shutterContest = value),
          })
        }
      >
        {t(attrs.shutterContest ? 'composer.edit' : 'composer.add')}
      </Button>,
      1
    );
  });

  extend('flarum/forum/components/DiscussionComposer', 'data', function (this: any, data: Record<string, unknown>) {
    const draft = this.composer.body.attrs.shutterContest;
    if (draft) data.shutterContest = draft;
  });

  extend(DiscussionPage.prototype, 'oninit', remember);

  notifications();
});
