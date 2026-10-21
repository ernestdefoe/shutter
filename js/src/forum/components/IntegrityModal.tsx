import Modal from 'flarum/common/components/Modal';
import type { IInternalModalAttrs } from 'flarum/common/components/Modal';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import type Mithril from 'mithril';
import { api, t } from '../../common/api';
import type ContestState from './state';

interface Attrs extends IInternalModalAttrs {
  gallery: ContestState;
}

interface Flag {
  voteId: number;
  voter: { id: number; username: string };
  reasons: string[];
  voided: boolean;
}

interface Review {
  entries: { id: number; title: string; entrant: string | null; votes: number; flagged: Flag[] }[];
  flagged: number;
  total: number;
}

/**
 * Votes worth a second look, and why. Nothing is removed automatically:
 * staff void what they judge to be a ring, and can restore it.
 */
export default class IntegrityModal extends Modal<Attrs> {
  review: Review | null = null;
  busy = false;

  className() {
    return 'ShutterIntegrityModal Modal--large';
  }

  title() {
    return t('integrity.title');
  }

  oninit(vnode: Mithril.Vnode<Attrs, this>) {
    super.oninit(vnode);
    this.fetch('GET');
  }

  fetch(method: 'GET' | 'POST', body?: unknown) {
    this.busy = true;
    api<{ data: Review }>(method, `/contests/${this.attrs.gallery.id}/integrity`, body).then(({ data }) => {
      this.review = data;
      this.busy = false;
      if (method === 'POST') this.attrs.gallery.load();
      m.redraw();
    });
  }

  set(ids: number[], voided: boolean) {
    this.fetch('POST', { voteIds: ids, voided });
  }

  content() {
    const r = this.review;
    if (!r)
      return (
        <div className="Modal-body">
          <LoadingIndicator display="block" />
        </div>
      );

    return (
      <div className="Modal-body">
        <p>{t('integrity.summary', { flagged: r.flagged, total: r.total })}</p>
        <p className="helpText">{t('integrity.help')}</p>
        {r.entries.length === 0 ? <p className="Shutter-empty">{t('integrity.none')}</p> : null}
        {r.entries.map((entry) => {
          const open = entry.flagged.filter((f) => !f.voided).map((f) => f.voteId);
          return (
            <section className="ShutterIntegrity-entry">
              <header>
                <strong>{entry.title}</strong> {entry.entrant ? <span className="helpText">{t('panel.by', { name: entry.entrant })}</span> : null}
                <span className="ShutterIntegrity-count">{t('panel.votes', { count: entry.votes })}</span>
                {open.length ? (
                  <Button className="Button Button--danger" disabled={this.busy} onclick={() => this.set(open, true)}>
                    {t('integrity.void_all', { count: open.length })}
                  </Button>
                ) : null}
              </header>
              <table className="ShutterIntegrity-table">
                <tbody>
                  {entry.flagged.map((f) => (
                    <tr className={f.voided ? 'is-voided' : ''}>
                      <td>{f.voter.username}</td>
                      <td>
                        {f.reasons.map((reason) => (
                          <span className="ShutterIntegrity-reason">{t(`integrity.reason.${reason}`)}</span>
                        ))}
                      </td>
                      <td>
                        <Button className="Button Button--text" disabled={this.busy} onclick={() => this.set([f.voteId], !f.voided)}>
                          {t(f.voided ? 'integrity.restore' : 'integrity.void')}
                        </Button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </section>
          );
        })}
      </div>
    );
  }
}
