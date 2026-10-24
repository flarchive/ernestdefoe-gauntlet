import app from 'flarum/forum/app';
import Extend from 'flarum/common/extenders';
import { extend } from 'flarum/common/extend';
import IndexSidebar from 'flarum/forum/components/IndexSidebar';
import UserPage from 'flarum/forum/components/UserPage';
import LinkButton from 'flarum/common/components/LinkButton';
import TournamentModel from '../common/TournamentModel';
import TournamentNotification from './notifications/TournamentNotification';

const TYPES = [
  'gauntletMatchReady',
  'gauntletResultReported',
  'gauntletRuling',
  'gauntletStarted',
  'gauntletCheckin',
  'gauntletPromoted',
  'gauntletFinished',
];

/*
 * Everything here runs on every page for every visitor, so it is only the
 * wiring. The pages, the bracket, the editor and the styles are their own
 * chunks, fetched the first time someone opens a tournament page.
 */
export const extenders = [
  new Extend.Store().add('gauntlet-tournaments', TournamentModel),

  TYPES.reduce((ext, type) => ext.add(type, TournamentNotification), new Extend.Notification()),

  new Extend.Routes()
    .add('gauntlet.index', '/tournaments', () => import('./pages/TournamentsPage'))
    .add('gauntlet.new', '/tournaments/new', () => import('./pages/EditorPage'))
    .add('gauntlet.tournament', '/tournaments/:id', () => import('./pages/TournamentPage'))
    .add('gauntlet.edit', '/tournaments/:id/edit', () => import('./pages/EditorPage'))
    .add('user.tournaments', '/u/:username/tournaments', () => import('./pages/UserTournamentsPage')),
];

export { extenders as extend };

app.initializers.add('ernestdefoe-gauntlet', () => {
  const t = (key: string) => app.translator.trans(`ernestdefoe-gauntlet.forum.${key}`);

  extend(IndexSidebar.prototype, 'navItems', function (items) {
    if (!app.forum.attribute('canViewTournaments') || app.forum.attribute('gauntletShowNav') === false) return;

    items.add(
      'tournaments',
      <LinkButton href={app.route('gauntlet.index')} icon="fas fa-trophy">
        {t('nav')}
      </LinkButton>,
      4
    );
  });

  extend(UserPage.prototype, 'navItems', function (items) {
    const user = this.user;
    if (!user || !app.forum.attribute('canViewTournaments')) return;

    items.add(
      'tournaments',
      <LinkButton href={app.route('user.tournaments', { username: user.slug() })} icon="fas fa-trophy">
        {t('profile.nav')}
      </LinkButton>,
      70
    );
  });

  // 🚨 By module name: NotificationGrid is in a lazy chunk in Flarum 2, so a
  // static import of it is undefined when initializers run.
  extend('flarum/forum/components/NotificationGrid', 'notificationTypes', (items: any) => {
    if (!app.forum.attribute('canViewTournaments')) return;

    items.add('gauntletMatchReady', { name: 'gauntletMatchReady', icon: 'fas fa-flag-checkered', label: t('settings.match_ready') });
    items.add('gauntletResultReported', { name: 'gauntletResultReported', icon: 'fas fa-clipboard-check', label: t('settings.result_reported') });
    items.add('gauntletCheckin', { name: 'gauntletCheckin', icon: 'fas fa-user-check', label: t('settings.checkin') });
    items.add('gauntletPromoted', { name: 'gauntletPromoted', icon: 'fas fa-arrow-up', label: t('settings.promoted') });
    items.add('gauntletStarted', { name: 'gauntletStarted', icon: 'fas fa-play', label: t('settings.started') });
    items.add('gauntletFinished', { name: 'gauntletFinished', icon: 'fas fa-trophy', label: t('settings.finished') });

    if (app.forum.attribute('canCreateTournaments')) {
      items.add('gauntletRuling', { name: 'gauntletRuling', icon: 'fas fa-scale-balanced', label: t('settings.ruling') });
    }
  });
});
