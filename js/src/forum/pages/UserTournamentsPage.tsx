import app from 'flarum/forum/app';
import UserPage from 'flarum/forum/components/UserPage';
import Link from 'flarum/common/components/Link';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import type User from 'flarum/common/models/User';
import type Mithril from 'mithril';
import { api, t, tournamentUrl } from '../../common/api';
import type { History } from '../../common/api';
import injectStyles from '../styles';
import { pill } from '../components/bits';

/** /u/:username/tournaments — placings, prediction brackets and ratings. */
export default class UserTournamentsPage extends UserPage {
  history: History | null = null;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    injectStyles();
    this.loadUser(m.route.param('username'));
  }

  show(user: User): void {
    super.show(user);
    api.history(user.id()!).then(({ data }) => {
      this.history = data;
      m.redraw();
    });
  }

  content(): Mithril.Children {
    const h = this.history;
    if (!h) return <LoadingIndicator display="block" />;

    const won = h.entries.filter((e) => e.finalRank === 1).length;
    const podium = h.entries.filter((e) => e.finalRank !== null && e.finalRank <= 3).length;

    if (!h.entries.length && !h.predictions.length)
      return (
        <div className="Gauntlet">
          <p className="Gauntlet-notice">{t('profile.empty')}</p>
        </div>
      );

    return (
      <div className="Gauntlet">
        <div className="Gauntlet-stats">
          <div className="Gauntlet-stat">
            <strong>{h.entries.length}</strong>
            <span>{t('profile.entered')}</span>
          </div>
          <div className="Gauntlet-stat">
            <strong>{won}</strong>
            <span>{t('profile.won')}</span>
          </div>
          <div className="Gauntlet-stat">
            <strong>{podium}</strong>
            <span>{t('profile.podium')}</span>
          </div>
          {h.ratings.slice(0, 3).map((r) => (
            <div className="Gauntlet-stat">
              <strong>{r.rating}</strong>
              <span>{t('profile.rating', { game: r.game || t('profile.any_game') })}</span>
            </div>
          ))}
        </div>

        {h.entries.length ? (
          <div className="Gauntlet-tableWrap">
            <table className="Gauntlet-table">
              <thead>
                <tr>
                  <th>{t('profile.tournament')}</th>
                  <th className="num">{t('entrants.place')}</th>
                  <th className="num hide-phone">{t('standings.record')}</th>
                </tr>
              </thead>
              <tbody>
                {h.entries.map((e) => (
                  <tr>
                    <td>
                      <Link href={tournamentUrl(e)}>{e.title}</Link> {e.status !== 'finished' ? pill(e.status) : null}
                      {e.name ? <div className="Gauntlet-roster">{e.name}</div> : null}
                    </td>
                    <td className="num">{e.finalRank ? t('profile.place_of', { place: e.finalRank, count: e.entrantCount }) : '–'}</td>
                    <td className="num hide-phone">{`${e.wins}–${e.draws}–${e.losses}`}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : null}

        {h.predictions.length ? (
          <div className="Gauntlet-tableWrap">
            <h3 className="Gauntlet-roundTitle">{t('profile.predictions')}</h3>
            <table className="Gauntlet-table">
              <thead>
                <tr>
                  <th>{t('profile.tournament')}</th>
                  <th className="num">{t('predict.points')}</th>
                  <th className="num">{t('entrants.place')}</th>
                </tr>
              </thead>
              <tbody>
                {h.predictions.map((p) => (
                  <tr>
                    <td>
                      <Link href={tournamentUrl(p)}>{p.title}</Link>
                    </td>
                    <td className="num">{p.score}</td>
                    <td className="num">{t('profile.place_of', { place: p.place, count: p.count })}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : null}
      </div>
    );
  }
}
