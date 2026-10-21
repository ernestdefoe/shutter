import Component from 'flarum/common/Component';
import Icon from 'flarum/common/components/Icon';
import Tooltip from 'flarum/common/components/Tooltip';
import extractText from 'flarum/common/utils/extractText';
import { place, t } from '../../common/api';
import type { Entry } from '../../common/api';
import type ContestState from './state';

interface Attrs {
  gallery: ContestState;
  entry: Entry;
  onopen: () => void;
}

/** One photo in the grid: the thumbnail over its blurred stand-in, and the vote. */
export default class Tile extends Component<Attrs> {
  view() {
    const { gallery, entry } = this.attrs;

    return (
      <article className={'Shutter-tile' + (entry.status && entry.status !== 'approved' ? ` is-${entry.status}` : '')}>
        <button type="button" className="Shutter-tileImage" onclick={this.attrs.onopen} aria-label={entry.title}>
          <img
            src={entry.thumb}
            alt=""
            loading="lazy"
            decoding="async"
            style={entry.blur ? { backgroundImage: `url("${entry.blur}")` } : undefined}
          />
          {entry.placement ? <span className={`Shutter-medal Shutter-medal--${Math.min(entry.placement, 4)}`}>{place(entry.placement)}</span> : null}
          {entry.status && entry.status !== 'approved' ? <span className="Shutter-entryStatus">{t(`entry.status.${entry.status}`)}</span> : null}
        </button>
        <div className="Shutter-tileFoot">
          <span className="Shutter-tileTitle" title={entry.title}>
            {entry.title}
          </span>
          {this.vote()}
        </div>
      </article>
    );
  }

  vote() {
    const { gallery, entry } = this.attrs;
    const count = entry.votes !== undefined ? <span className="Shutter-voteCount">{entry.votes}</span> : null;

    // A judge sees which photos they have scored.
    if (entry.myScore !== undefined) {
      return entry.myScore ? <span className="Shutter-myScore">{t('entry.my_score', { score: entry.myScore })}</span> : null;
    }

    if (!gallery.canVote(entry)) {
      return count ? (
        <span className="Shutter-voteStatic">
          <Icon name="fas fa-heart" /> {count}
        </span>
      ) : null;
    }

    const out = !entry.voted && gallery.votesLeft() === 0;
    const label = extractText(t(entry.voted ? 'entry.unvote' : out ? 'entry.out_of_votes' : 'entry.vote'));

    return (
      <Tooltip text={label}>
        <button
          type="button"
          className={'Shutter-vote' + (entry.voted ? ' is-voted' : '')}
          aria-pressed={!!entry.voted}
          aria-label={label}
          disabled={out || gallery.busy.has(entry.id)}
          onclick={() => gallery.vote(entry)}
        >
          <Icon name={entry.voted ? 'fas fa-heart' : 'far fa-heart'} />
          {count}
        </button>
      </Tooltip>
    );
  }
}
