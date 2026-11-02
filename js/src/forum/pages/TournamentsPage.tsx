import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import PageStructure from 'flarum/forum/components/PageStructure';
import IndexSidebar from 'flarum/forum/components/IndexSidebar';
import Button from 'flarum/common/components/Button';
import LinkButton from 'flarum/common/components/LinkButton';
import Link from 'flarum/common/components/Link';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import { api, t, tournamentUrl } from '../../common/api';
import type { Summary } from '../../common/api';
import injectStyles from '../styles';
import { formatDate, pill } from '../components/bits';

const FILTERS = ['all', 'open', 'running', 'finished', 'mine'] as const;

/** /tournaments — running ones first, then those open for sign-ups, then the rest. */
export default class TournamentsPage extends Page {
  list: Summary[] = [];
  loading = true;
  more = false;
  page = 1;
  canCreate = false;
  filter: (typeof FILTERS)[number] = 'all';

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    injectStyles();
    app.setTitle(extractText(t('tournaments.title')));
    app.history.push('tournaments', extractText(t('tournaments.title')));
    this.load();
  }

  load(page = 1) {
    this.loading = true;
    api.list(this.filter, page).then(
      ({ data, meta }) => {
        this.list = page === 1 ? data : [...this.list, ...data];
        this.page = page;
        this.more = meta.more;
        this.canCreate = meta.canCreate;
        this.loading = false;
        m.redraw();
      },
      () => {
        this.loading = false;
        m.redraw();
      }
    );
  }

  view(): Mithril.Children {
    const filters = FILTERS.filter((f) => f !== 'mine' || app.session.user);

    return (
      <PageStructure className="Gauntlet GauntletIndex" sidebar={() => <IndexSidebar />}>
        <div
          className="Gauntlet-head"
          style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: '12px', flexWrap: 'wrap' }}
        >
          <h2>{t('tournaments.title')}</h2>
          {this.canCreate ? (
            <LinkButton className="Button Button--primary" icon="fas fa-plus" href={app.route('gauntlet.new')}>
              {t('tournaments.new')}
            </LinkButton>
          ) : null}
        </div>
        <div className="Gauntlet-tabs" role="tablist">
          {filters.map((f) => (
            <Button
              className={'Button Button--flat' + (this.filter === f ? ' active' : '')}
              role="tab"
              aria-selected={this.filter === f}
              onclick={() => {
                this.filter = f;
                this.load();
              }}
            >
              {t('tournaments.filters.' + f)}
            </Button>
          ))}
        </div>
        <ul className="Gauntlet-list">
          {this.list.map((item) => (
            <li key={item.id}>
              <Link className="Gauntlet-card" href={tournamentUrl(item)}>
                <h3>
                  {item.title} {pill(item.status)}
                </h3>
                <ul className="Gauntlet-meta">
                  <li>{t('format.' + item.format)}</li>
                  {item.game ? <li>{item.game}</li> : null}
                  {item.entryMode === 'members' ? (
                    <li>
                      {item.maxEntrants
                        ? t('tournament.entrants_of', { count: item.entrantCount, max: item.maxEntrants })
                        : t('tournament.entrants', { count: item.entrantCount })}
                    </li>
                  ) : null}
                  {item.predictions ? <li>{t('tournament.predictions', { count: item.predictionCount })}</li> : null}
                  {item.startsAt && item.status !== 'finished' ? <li>{formatDate(item.startsAt)}</li> : null}
                </ul>
              </Link>
            </li>
          ))}
        </ul>
        {this.loading ? <LoadingIndicator display="block" /> : null}
        {!this.loading && !this.list.length ? <p className="Gauntlet-notice">{t('tournaments.empty')}</p> : null}
        {!this.loading && this.more ? (
          <Button className="Button" onclick={() => this.load(this.page + 1)}>
            {t('tournaments.more')}
          </Button>
        ) : null}
      </PageStructure>
    );
  }
}
