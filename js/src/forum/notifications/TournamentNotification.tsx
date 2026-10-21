import app from 'flarum/forum/app';
import Notification from 'flarum/forum/components/Notification';
import type TournamentModel from '../../common/TournamentModel';

const ICONS: Record<string, string> = {
  gauntletMatchReady: 'fas fa-flag-checkered',
  gauntletResultReported: 'fas fa-clipboard-check',
  gauntletRuling: 'fas fa-scale-balanced',
  gauntletStarted: 'fas fa-play',
  gauntletCheckin: 'fas fa-user-check',
  gauntletPromoted: 'fas fa-arrow-up',
  gauntletFinished: 'fas fa-trophy',
};

/** Every Gauntlet notification: the type picks the icon and the words. */
export default class TournamentNotification extends Notification {
  type(): string {
    return this.attrs.notification.contentType() || '';
  }

  icon() {
    return ICONS[this.type()] || 'fas fa-trophy';
  }

  href() {
    const tournament = this.attrs.notification.subject() as TournamentModel | null;
    if (!tournament) return app.route('gauntlet.index');

    const data = (this.attrs.notification.content() || {}) as { match?: number };
    const id = tournament.id() + (tournament.slug() ? '-' + tournament.slug() : '');

    return app.route('gauntlet.tournament', data.match ? { id, match: data.match } : { id });
  }

  content() {
    const tournament = this.attrs.notification.subject() as TournamentModel | null;
    const data = (this.attrs.notification.content() || {}) as { opponent?: string; round?: number };
    const from = this.attrs.notification.fromUser();

    // Not the shared `t` helper: that module brings the whole API client into
    // forum.js, which every page loads.
    return app.translator.trans('ernestdefoe-gauntlet.forum.notifications.' + this.type(), {
      title: tournament?.title() ?? '',
      opponent: data.opponent ?? '',
      round: data.round ?? '',
      username: from ? from.displayName() : '',
    });
  }

  excerpt() {
    return null;
  }
}
