import app from 'flarum/forum/app';
import FormModal from 'flarum/common/components/FormModal';
import type { IFormModalAttrs } from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import Switch from 'flarum/common/components/Switch';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import extractText from 'flarum/common/utils/extractText';
import type Discussion from 'flarum/common/models/Discussion';
import type Group from 'flarum/common/models/Group';
import type Mithril from 'mithril';
import { api, t } from '../../common/api';
import type { Contest } from '../../common/api';
import type ContestState from './state';

interface Attrs extends IFormModalAttrs {
  /** Editing or starting a contest on an existing discussion… */
  discussion?: Discussion;
  contestId?: number | null;
  gallery?: ContestState;
  /** …or choosing one for a discussion still in the composer. */
  draft?: Record<string, any> | null;
  onDraft?: (value: Record<string, any> | null) => void;
}

type Form = {
  opensAt: string;
  submissionsCloseAt: string;
  votingCloseAt: string;
  rules: string;
  entriesPerMember: number;
  votesPerMember: number;
  winnersCount: number;
  scoring: 'votes' | 'judges';
  judges: string;
  namedVotes: boolean;
  liveCounts: boolean;
  hideEntrants: boolean;
  moderateEntries: boolean;
  enterGroupIds: number[];
  voteGroupIds: number[];
  voterMinDays: number;
  voterMinPosts: number;
};

/** datetime-local wants local time without a zone; the API wants ISO with one. */
const toLocal = (iso: string | null) => {
  if (!iso) return '';
  const d = new Date(iso);
  return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
};
const toIso = (local: string) => (local ? new Date(local).toISOString() : null);
const days = (n: number) => toLocal(new Date(Date.now() + n * 86400000).toISOString());

export default class ContestEditorModal extends FormModal<Attrs> {
  form!: Form;
  ready = false;
  phase: string | null = null;

  className() {
    return 'ShutterEditorModal Modal--medium';
  }

  title() {
    return t(this.attrs.contestId ? 'editor.title_edit' : 'editor.title_new');
  }

  oninit(vnode: Mithril.Vnode<Attrs, this>) {
    super.oninit(vnode);

    const blank: Form = {
      opensAt: '',
      submissionsCloseAt: days(7),
      votingCloseAt: days(14),
      rules: '',
      entriesPerMember: 1,
      votesPerMember: 3,
      winnersCount: 3,
      scoring: 'votes',
      judges: '',
      namedVotes: false,
      liveCounts: false,
      hideEntrants: true,
      moderateEntries: false,
      enterGroupIds: [],
      voteGroupIds: [],
      voterMinDays: 0,
      voterMinPosts: 0,
    };

    if (this.attrs.contestId) {
      api<{ data: Contest }>('GET', `/contests/${this.attrs.contestId}`, undefined, { limit: 1 }).then(({ data }) => {
        this.phase = data.phase;
        this.form = {
          ...blank,
          ...data,
          opensAt: toLocal(data.opensAt),
          submissionsCloseAt: toLocal(data.submissionsCloseAt),
          votingCloseAt: toLocal(data.votingCloseAt),
          rules: data.rules ?? '',
          judges: (data.judges ?? []).map((j) => j.username).join(', '),
          enterGroupIds: data.enterGroups.map((g) => g.id),
          voteGroupIds: data.voteGroups.map((g) => g.id),
        };
        this.ready = true;
        m.redraw();
      });
    } else {
      const d = this.attrs.draft;
      this.form = d
        ? { ...blank, ...d, opensAt: toLocal(d.opensAt), submissionsCloseAt: toLocal(d.submissionsCloseAt), votingCloseAt: toLocal(d.votingCloseAt) }
        : blank;
      this.ready = true;
    }
  }

  content() {
    if (!this.ready) return <LoadingIndicator display="block" />;
    const f = this.form;
    const set = (key: keyof Form) => (e: Event) => ((f as any)[key] = (e.target as HTMLInputElement).value);
    const num = (key: keyof Form) => (e: Event) => ((f as any)[key] = Number((e.target as HTMLInputElement).value));
    const groups = (app.store.all('groups') as Group[]).filter((g) => g.id() !== '2');
    const locked = this.phase === 'voting' || this.phase === 'closed';

    const groupPicker = (key: 'enterGroupIds' | 'voteGroupIds') => (
      <div className="ShutterEditor-groups">
        {groups.map((g) => {
          const id = Number(g.id());
          return (
            <label className="checkbox">
              <input
                type="checkbox"
                checked={f[key].includes(id)}
                onchange={(e: Event) => (f[key] = (e.target as HTMLInputElement).checked ? [...f[key], id] : f[key].filter((x) => x !== id))}
              />
              {g.namePlural()}
            </label>
          );
        })}
        <p className="helpText">{t('editor.groups_help')}</p>
      </div>
    );

    return (
      <div className="Modal-body">
        <fieldset>
          <legend>{t('editor.schedule')}</legend>
          <div className="ShutterEditor-dates">
            <label>
              {t('editor.opens_at')}
              <input type="datetime-local" className="FormControl" value={f.opensAt} oninput={set('opensAt')} />
              <span className="helpText">{t('editor.opens_at_help')}</span>
            </label>
            <label>
              {t('editor.submissions_close_at')}
              <input type="datetime-local" className="FormControl" required value={f.submissionsCloseAt} oninput={set('submissionsCloseAt')} />
            </label>
            <label>
              {t('editor.voting_close_at')}
              <input type="datetime-local" className="FormControl" required value={f.votingCloseAt} oninput={set('votingCloseAt')} />
            </label>
          </div>
        </fieldset>

        <div className="Form-group">
          <label for="shutter-rules">{t('editor.rules')}</label>
          <textarea
            id="shutter-rules"
            className="FormControl"
            rows={3}
            maxlength={5000}
            value={f.rules}
            oninput={set('rules')}
            placeholder={extractText(t('editor.rules_placeholder'))}
          />
        </div>

        <fieldset>
          <legend>{t('editor.entering')}</legend>
          <label className="ShutterEditor-number">
            {t('editor.entries_per_member')}
            <input type="number" className="FormControl" min={1} max={20} value={f.entriesPerMember} oninput={num('entriesPerMember')} />
          </label>
          <span className="ShutterEditor-sub">{t('editor.who_enters')}</span>
          {groupPicker('enterGroupIds')}
          <Switch state={f.moderateEntries} onchange={(v: boolean) => (f.moderateEntries = v)}>
            {t('editor.moderate_entries')}
          </Switch>
        </fieldset>

        <fieldset>
          <legend>{t('editor.deciding')}</legend>
          <div className="ShutterEditor-scoring" role="radiogroup">
            {(['votes', 'judges'] as const).map((s) => (
              <label className="checkbox">
                <input type="radio" name="shutter-scoring" checked={f.scoring === s} disabled={locked} onchange={() => (f.scoring = s)} />
                {t(`editor.scoring_${s}`)}
              </label>
            ))}
          </div>
          {locked ? <p className="helpText">{t('editor.scoring_locked')}</p> : null}

          {f.scoring === 'judges' ? (
            <label className="ShutterEditor-wide">
              {t('editor.judges')}
              <input className="FormControl" value={f.judges} oninput={set('judges')} placeholder={extractText(t('editor.judges_placeholder'))} />
              <span className="helpText">{t('editor.judges_help')}</span>
            </label>
          ) : (
            <>
              <label className="ShutterEditor-number">
                {t('editor.votes_per_member')}
                <input type="number" className="FormControl" min={1} max={50} value={f.votesPerMember} oninput={num('votesPerMember')} />
              </label>
              <span className="ShutterEditor-sub">{t('editor.who_votes')}</span>
              {groupPicker('voteGroupIds')}
              <div className="ShutterEditor-pair">
                <label className="ShutterEditor-number">
                  {t('editor.voter_min_days')}
                  <input type="number" className="FormControl" min={0} max={3650} value={f.voterMinDays} oninput={num('voterMinDays')} />
                </label>
                <label className="ShutterEditor-number">
                  {t('editor.voter_min_posts')}
                  <input type="number" className="FormControl" min={0} max={10000} value={f.voterMinPosts} oninput={num('voterMinPosts')} />
                </label>
              </div>
              <Switch state={f.namedVotes} onchange={(v: boolean) => (f.namedVotes = v)}>
                {t('editor.named_votes')}
              </Switch>
              <Switch state={f.liveCounts} onchange={(v: boolean) => (f.liveCounts = v)}>
                {t('editor.live_counts')}
              </Switch>
            </>
          )}
          <Switch state={f.hideEntrants} onchange={(v: boolean) => (f.hideEntrants = v)}>
            {t('editor.hide_entrants')}
          </Switch>
          <label className="ShutterEditor-number">
            {t('editor.winners_count')}
            <input type="number" className="FormControl" min={1} max={10} value={f.winnersCount} oninput={num('winnersCount')} />
          </label>
        </fieldset>

        <div className="Form-group ShutterEditor-buttons">
          <Button type="submit" className="Button Button--primary" loading={this.loading}>
            {t(this.attrs.onDraft ? 'editor.use' : 'editor.save')}
          </Button>
          {this.attrs.onDraft && this.attrs.draft ? (
            <Button className="Button" onclick={() => (this.attrs.onDraft!(null), this.hide())}>
              {t('editor.remove_draft')}
            </Button>
          ) : null}
          {this.attrs.contestId ? (
            <Button className="Button Button--danger" icon="fas fa-trash" onclick={() => this.remove()}>
              {t('editor.delete')}
            </Button>
          ) : null}
        </div>
      </div>
    );
  }

  /** Only the contest's own settings: the form was seeded from the full contest. */
  payload() {
    const f = this.form;
    const keys: (keyof Form)[] = [
      'rules',
      'entriesPerMember',
      'votesPerMember',
      'winnersCount',
      'scoring',
      'judges',
      'namedVotes',
      'liveCounts',
      'hideEntrants',
      'moderateEntries',
      'enterGroupIds',
      'voteGroupIds',
      'voterMinDays',
      'voterMinPosts',
    ];
    return {
      ...Object.fromEntries(keys.map((k) => [k, f[k]])),
      opensAt: toIso(f.opensAt),
      submissionsCloseAt: toIso(f.submissionsCloseAt),
      votingCloseAt: toIso(f.votingCloseAt),
    };
  }

  onsubmit(e: SubmitEvent) {
    e.preventDefault();

    // In the composer: kept with the discussion and sent when it is posted,
    // where the server checks it before anything is saved.
    if (this.attrs.onDraft) {
      this.attrs.onDraft(this.payload());
      this.hide();
      return;
    }

    this.loading = true;
    const id = this.attrs.contestId;
    const request = id
      ? api('PATCH', `/contests/${id}`, this.payload())
      : api<{ data: Contest }>('POST', '/contests', { ...this.payload(), discussionId: Number(this.attrs.discussion!.id()) });

    request.then(
      (response: any) => {
        this.hide();
        if (this.attrs.gallery) this.attrs.gallery.load();
        else if (!id) this.refreshDiscussion(response.data.id);
      },
      (error) => {
        this.loading = false;
        this.onerror(error);
        m.redraw();
      }
    );
  }

  /** The discussion's own attribute, so its first post grows the gallery without a reload. */
  refreshDiscussion(contestId: number | null) {
    const d = this.attrs.discussion;
    if (!d) return;
    d.pushAttributes({ shutterContest: contestId ? { id: contestId, phase: 'submissions' } : null });
    app.store.find('discussions', d.id()!).then(
      () => m.redraw(),
      () => m.redraw()
    );
  }

  remove() {
    if (!confirm(extractText(t('editor.delete_confirm')))) return;
    this.loading = true;

    api('DELETE', `/contests/${this.attrs.contestId}`).then(
      () => {
        this.hide();
        this.refreshDiscussion(null);
      },
      (error) => {
        this.loading = false;
        this.onerror(error);
        m.redraw();
      }
    );
  }
}
