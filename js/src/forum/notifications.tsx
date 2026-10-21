import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import Notification from 'flarum/forum/components/Notification';
import type Discussion from 'flarum/common/models/Discussion';
import { place, t } from '../common/api';

/** The three contest alerts. Each names the contest's discussion, its subject. */
function make(icon: string, text: (n: any) => any, query?: (n: any) => string) {
  return class extends Notification {
    icon() {
      return icon;
    }

    href() {
      const discussion = this.attrs.notification.subject() as Discussion | null;
      return discussion ? app.route.discussion(discussion) + (query ? query(this.attrs.notification) : '') : '#';
    }

    content() {
      return text(this.attrs.notification);
    }

    excerpt() {
      return (this.attrs.notification.subject() as Discussion | null)?.title() ?? null;
    }
  };
}

export default function notifications() {
  const placed = (n: any) => n.content()?.placement as number;

  app.notificationComponents.shutterPlaced = make(
    'fas fa-trophy',
    (n) => t('notifications.placed', { place: place(placed(n)) }),
    (n) => '?photo=' + Number(n.content()?.entry)
  );
  app.notificationComponents.shutterResults = make('fas fa-camera-retro', () => t('notifications.results'));
  app.notificationComponents.shutterVotingOpen = make('fas fa-check-to-slot', () => t('notifications.voting_open'));

  extend('flarum/forum/components/NotificationGrid', 'notificationTypes', function (items: any) {
    items.add('shutterVotingOpen', { name: 'shutterVotingOpen', icon: 'fas fa-check-to-slot', label: t('settings.notify_voting_open') });
    items.add('shutterPlaced', { name: 'shutterPlaced', icon: 'fas fa-trophy', label: t('settings.notify_placed') });
    items.add('shutterResults', { name: 'shutterResults', icon: 'fas fa-camera-retro', label: t('settings.notify_results') });
  });
}
