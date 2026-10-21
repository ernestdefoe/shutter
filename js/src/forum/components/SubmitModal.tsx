import app from 'flarum/forum/app';
import FormModal from 'flarum/common/components/FormModal';
import type { IFormModalAttrs } from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import { t } from '../../common/api';
import type ContestState from './state';

interface Attrs extends IFormModalAttrs {
  gallery: ContestState;
}

/**
 * Enter a photo. The preview is the browser's own reading of the file, so the
 * entrant sees what they chose; the server reads it again from scratch and
 * believes nothing the browser said about it.
 */
export default class SubmitModal extends FormModal<Attrs> {
  file: File | null = null;
  preview: string | null = null;
  photoTitle = '';
  caption = '';
  problem: string | null = null;

  className() {
    return 'ShutterSubmitModal Modal--medium';
  }

  title() {
    return t('submit.title');
  }

  onremove(vnode: Mithril.VnodeDOM<Attrs, this>) {
    super.onremove(vnode);
    if (this.preview) URL.revokeObjectURL(this.preview);
  }

  pick(file: File | undefined) {
    this.problem = null;
    if (this.preview) URL.revokeObjectURL(this.preview);
    this.file = null;
    this.preview = null;
    if (!file) return;

    const maxMb = app.forum.attribute<number>('shutterMaxMb') || 10;
    if (!/^image\/(jpeg|png|webp)$/.test(file.type)) {
      this.problem = extractText(t('submit.type'));
      return;
    }
    if (file.size > maxMb * 1024 * 1024) {
      this.problem = extractText(t('submit.too_big', { size: maxMb }));
      return;
    }

    this.file = file;
    this.preview = URL.createObjectURL(file);
    if (!this.photoTitle)
      this.photoTitle = file.name
        .replace(/\.[a-z0-9]+$/i, '')
        .replace(/[_-]+/g, ' ')
        .slice(0, 100);
  }

  content() {
    const c = this.attrs.gallery.contest!;

    return (
      <div className="Modal-body">
        {c.rules ? <p className="ShutterSubmitModal-rules">{c.rules}</p> : null}

        <label className={'ShutterDrop' + (this.preview ? ' has-file' : '')}>
          <input
            type="file"
            accept="image/jpeg,image/png,image/webp"
            className="ShutterDrop-input"
            onchange={(e: Event) => this.pick((e.target as HTMLInputElement).files?.[0])}
          />
          {this.preview ? (
            <img className="ShutterDrop-preview" src={this.preview} alt="" />
          ) : (
            <span className="ShutterDrop-hint">
              <i className="fas fa-image" aria-hidden="true" /> {t('submit.choose', { size: app.forum.attribute<number>('shutterMaxMb') || 10 })}
            </span>
          )}
        </label>
        {this.problem ? <p className="ShutterSubmitModal-problem">{this.problem}</p> : null}

        <div className="Form-group">
          <label for="shutter-title">{t('submit.photo_title')}</label>
          <input
            id="shutter-title"
            className="FormControl"
            maxlength={100}
            required
            value={this.photoTitle}
            oninput={(e: InputEvent) => (this.photoTitle = (e.target as HTMLInputElement).value)}
          />
        </div>
        <div className="Form-group">
          <label for="shutter-caption">{t('submit.caption')}</label>
          <textarea
            id="shutter-caption"
            className="FormControl"
            maxlength={500}
            rows={3}
            value={this.caption}
            oninput={(e: InputEvent) => (this.caption = (e.target as HTMLTextAreaElement).value)}
          />
        </div>
        <p className="helpText">{t('submit.privacy')}</p>

        <div className="Form-group">
          <Button
            type="submit"
            className="Button Button--primary Button--block"
            loading={this.loading}
            disabled={!this.file || !this.photoTitle.trim()}
          >
            {t('submit.send')}
          </Button>
        </div>
      </div>
    );
  }

  onsubmit(e: SubmitEvent) {
    e.preventDefault();
    if (!this.file) return;

    const body = new FormData();
    body.append('photo', this.file);
    body.append('title', this.photoTitle.trim());
    body.append('caption', this.caption.trim());
    this.loading = true;

    app
      .request({
        method: 'POST',
        url: `${app.forum.attribute('apiUrl')}/shutter/contests/${this.attrs.gallery.id}/entries`,
        serialize: (raw: unknown) => raw,
        body,
      })
      .then(
        () => {
          this.hide();
          this.attrs.gallery.view = 'all';
          this.attrs.gallery.sort = 'newest';
          this.attrs.gallery.load();
          app.alerts.show(
            { type: 'success' },
            t(this.attrs.gallery.contest!.moderateEntries && !this.attrs.gallery.contest!.canManage ? 'submit.pending' : 'submit.done')
          );
        },
        (error) => {
          this.loading = false;
          this.onerror(error);
          m.redraw();
        }
      );
  }
}
