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
import type { Entrant, Match, PredictionData, Tournament } from '../../common/api';
import injectStyles from '../styles';
import Bracket, { entrantName, roundTitle } from '../components/Bracket';
import MatchModal from '../components/MatchModal';
import { maxRound, prune } from '../bracket/layout';
import { formatDate, personAvatar, pill } from '../components/bits';

type Tab = 'bracket' | 'standings' | 'matches' | 'entrants' | 'predict' | 'board' | 'manage';

/** /tournaments/:id — the whole tournament, and everything a reader may do in it. */
export default class TournamentPage extends Page {
  tournament: Tournament | null = null;
  missing = false;
  tab: Tab | null = null;
  busy = false;
  teamName = '';
  groupId = '';
  picks: Record<string, number> = {};
  tiebreaker = '';
  picksDirty = false;
  board: PredictionData[] | null = null;
  boardPage = 1;
  boardMore = false;
  staffName = '';
  staffLogo = '';
  teamQuery = '';
  teamResults: { id: number; name: string; logoUrl: string | null }[] = [];
  searchTimer = 0;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    injectStyles();
    this.load();
  }

  load() {
    const id = parseInt(String(m.route.param('id')), 10);
    api.show(id).then(
      ({ data }) => this.set(data, true),
      () => {
        this.missing = true;
        m.redraw();
      }
    );
  }

  set(data: Tournament, first = false) {
    this.tournament = data;
    if (first) {
      app.setTitle(data.title);
      app.history.push('tournament', data.title);
      const prediction = data.me.prediction;
      this.picks = prediction?.picks ? { ...prediction.picks } : {};
      this.tiebreaker = prediction?.tiebreaker != null ? String(prediction.tiebreaker) : '';
      const match = Number(m.route.param('match'));
      const linked = match ? data.matches.find((x) => x.id === match) : null;
      if (linked) setTimeout(() => this.open(linked));
    }
    if (!this.tab || !this.tabs().includes(this.tab)) this.tab = this.tabs()[0];
    m.redraw();
  }

  tabs(): Tab[] {
    const tt = this.tournament!;
    const tabs: Tab[] = [];
    const started = tt.matches.length > 0;

    if (tt.predictions && tt.me.canPredict && tt.me.predictionsOpen) tabs.push('predict');
    if (started && (tt.format === 'single' || tt.format === 'double')) tabs.push('bracket');
    if (started && tt.standings) tabs.push('standings');
    if (started) tabs.push('matches');
    if (tt.predictions && started) {
      if (!tabs.includes('predict') && tt.me.prediction) tabs.push('predict');
      tabs.push('board');
    }
    tabs.push('entrants');
    if (tt.me.canManage) tabs.push('manage');

    return tabs;
  }

  view(): Mithril.Children {
    return (
      <PageStructure className="Gauntlet GauntletPage" sidebar={() => <IndexSidebar />} loading={!this.tournament && !this.missing}>
        {this.missing ? <p className="Gauntlet-notice">{t('tournament.missing')}</p> : this.tournament ? this.content(this.tournament) : null}
      </PageStructure>
    );
  }

  content(tt: Tournament): Mithril.Children {
    return (
      <div>
        {this.header(tt)}
        {this.actions(tt)}
        <div className="Gauntlet-tabs" role="tablist">
          {this.tabs().map((tab) => (
            <Button
              className={'Button Button--flat' + (this.tab === tab ? ' active' : '')}
              role="tab"
              aria-selected={this.tab === tab}
              onclick={() => this.switchTab(tab)}
            >
              {t('tournament.tabs.' + tab)}
            </Button>
          ))}
        </div>
        <div role="tabpanel">{this.panel(tt)}</div>
      </div>
    );
  }

  switchTab(tab: Tab) {
    this.tab = tab;
    if (tab === 'board' && !this.board) this.loadBoard();
  }

  header(tt: Tournament): Mithril.Children {
    const capacity = tt.maxEntrants
      ? t('tournament.entrants_of', { count: tt.entrantCount, max: tt.maxEntrants })
      : t('tournament.entrants', { count: tt.entrantCount });

    return (
      <header className="Gauntlet-head">
        <h2>{tt.title}</h2>
        <ul className="Gauntlet-meta">
          <li>{pill(tt.status)}</li>
          <li>
            <i className="icon fas fa-sitemap" aria-hidden="true" />
            {t('format.' + tt.format)}
          </li>
          {tt.game ? (
            <li>
              <i className="icon fas fa-gamepad" aria-hidden="true" />
              {tt.game}
            </li>
          ) : null}
          {tt.entryMode === 'members' ? (
            <li>
              <i className="icon fas fa-users" aria-hidden="true" />
              {tt.entryType === 'team' ? t('tournament.teams_of', { size: tt.teamSize || '∞' }) : t('tournament.solo')} · {capacity}
            </li>
          ) : null}
          {tt.startsAt ? (
            <li>
              <i className="icon far fa-calendar" aria-hidden="true" />
              {formatDate(tt.startsAt)}
            </li>
          ) : null}
          {tt.bestOf > 1 ? <li>{t('match.best_of', { count: tt.bestOf })}</li> : null}
          {tt.organiser ? (
            <li>
              {t('tournament.organiser')} <Link href={app.route('user', { username: tt.organiser.username })}>{tt.organiser.displayName}</Link>
            </li>
          ) : null}
          {tt.discussionId ? (
            <li>
              <i className="icon far fa-comments" aria-hidden="true" />
              <Link href={app.route('discussion', { id: tt.discussionId })}>{t('tournament.discussion')}</Link>
            </li>
          ) : null}
        </ul>
        {tt.description ? <p className="Gauntlet-description">{tt.description}</p> : null}
      </header>
    );
  }

  mine(tt: Tournament): Entrant | undefined {
    return tt.me.entrantId ? tt.entrants.find((e) => e.id === tt.me.entrantId) : undefined;
  }

  actions(tt: Tournament): Mithril.Children {
    const me = this.mine(tt);
    const userId = Number(app.session.user?.id());
    const captain = me && me.user?.id === userId;
    const items: Mithril.Children[] = [];

    if (tt.status === 'registration' && tt.entryMode === 'members') {
      if (!app.session.user) {
        items.push(<p className="Gauntlet-notice">{t('tournament.log_in')}</p>);
      } else if (tt.me.requestedEntrantId) {
        items.push(
          <p className="Gauntlet-notice">
            {t('tournament.request_sent', { name: entrantName(tt.entrants.find((e) => e.id === tt.me.requestedEntrantId)) })}
          </p>
        );
      } else if (!me && tt.me.canJoin) {
        items.push(
          tt.entryType === 'team' ? (
            this.teamSignUp(tt)
          ) : (
            <Button className="Button Button--primary" icon="fas fa-right-to-bracket" loading={this.busy} onclick={() => this.run(api.signUp(tt.id))}>
              {tt.maxEntrants && tt.entrantCount >= tt.maxEntrants ? t('tournament.join_waitlist') : t('tournament.sign_up')}
            </Button>
          )
        );
      }

      if (me) {
        const waiting = me.status === 'waitlisted';
        items.push(
          <p className={'Gauntlet-notice' + (waiting ? ' is-warn' : '')}>
            {waiting
              ? t('tournament.you_waitlisted')
              : tt.entryType === 'team'
                ? t('tournament.you_team', { name: me.name })
                : t('tournament.you_in')}
            {tt.checkinMinutes
              ? ' ' + extractText(me.checkedIn ? t('tournament.checked_in') : t('tournament.checkin_window', { time: formatDate(tt.checkinOpensAt) }))
              : ''}
          </p>
        );
        if (tt.checkinMinutes && captain && tt.checkinOpensAt && new Date(tt.checkinOpensAt) <= new Date()) {
          items.push(
            <Button
              className={'Button' + (me.checkedIn ? '' : ' Button--primary')}
              icon="fas fa-check"
              loading={this.busy}
              onclick={() => this.run(api.checkIn(me.id, me.checkedIn))}
            >
              {me.checkedIn ? t('tournament.undo_checkin') : t('tournament.check_in')}
            </Button>
          );
        }
        items.push(
          captain ? (
            <Button className="Button" onclick={() => confirm(extractText(t('tournament.withdraw_confirm'))) && this.run(api.withdraw(me.id))}>
              {t('tournament.withdraw')}
            </Button>
          ) : (
            <Button className="Button" onclick={() => this.run(api.leave(me.id, userId))}>
              {t('tournament.leave_team')}
            </Button>
          )
        );
      }
    }

    if (tt.status === 'running') {
      const yours = tt.matches.filter(
        (x) => ['open', 'reported', 'disputed'].includes(x.status) && me && (x.entrant1Id === me.id || x.entrant2Id === me.id)
      );
      yours.forEach((x) =>
        items.push(
          <Button className="Button Button--primary" icon="fas fa-flag-checkered" onclick={() => this.open(x)}>
            {t('tournament.your_match', {
              name: entrantName(tt.entrants.find((e) => e.id === (x.entrant1Id === me!.id ? x.entrant2Id : x.entrant1Id))),
            })}
          </Button>
        )
      );
      if (tt.me.canManage) {
        const waiting = tt.matches.filter((x) => x.status === 'disputed' || x.status === 'awaiting').length;
        if (waiting) items.push(<p className="Gauntlet-notice is-warn">{t('tournament.staff_waiting', { count: waiting })}</p>);
      }
    }

    if (tt.status === 'running' && me && captain && me.status === 'registered' && tt.entryMode === 'members') {
      items.push(
        <Button
          className="Button Button--text"
          onclick={() => confirm(extractText(t('tournament.forfeit_confirm'))) && this.run(api.withdraw(me.id))}
        >
          {t('tournament.withdraw_running')}
        </Button>
      );
    }

    if (tt.status === 'finished') {
      const champion = tt.entrants.find((e) => e.finalRank === 1);
      if (champion) {
        items.push(
          <p className="Gauntlet-notice">
            <i className="icon fas fa-trophy" aria-hidden="true" /> {t('tournament.champion', { name: entrantName(champion) })}
          </p>
        );
      }
    }

    return items.length ? <div className="Gauntlet-actions">{items}</div> : null;
  }

  teamSignUp(tt: Tournament): Mithril.Children {
    return (
      <div className="Gauntlet-actions" style={{ margin: 0 }}>
        {tt.me.groups.length ? (
          <select
            className="FormControl"
            style={{ maxWidth: '220px' }}
            aria-label={extractText(t('tournament.team_group'))}
            value={this.groupId}
            onchange={(e: Event) => (this.groupId = (e.target as HTMLSelectElement).value)}
          >
            <option value="">{t('tournament.team_new')}</option>
            {tt.me.groups.map((g) => (
              <option value={g.id}>{g.name}</option>
            ))}
          </select>
        ) : null}
        {!this.groupId ? (
          <input
            className="FormControl"
            style={{ maxWidth: '220px' }}
            maxlength={100}
            placeholder={extractText(t('tournament.team_name'))}
            aria-label={extractText(t('tournament.team_name'))}
            value={this.teamName}
            oninput={(e: InputEvent) => (this.teamName = (e.target as HTMLInputElement).value)}
          />
        ) : null}
        <Button
          className="Button Button--primary"
          loading={this.busy}
          disabled={!this.groupId && !this.teamName.trim()}
          onclick={() => this.run(api.signUp(tt.id, this.groupId ? { groupId: Number(this.groupId) } : { name: this.teamName }))}
        >
          {t('tournament.enter_team')}
        </Button>
      </div>
    );
  }

  panel(tt: Tournament): Mithril.Children {
    switch (this.tab) {
      case 'bracket':
        return <Bracket tournament={tt} onOpen={(x: Match) => this.open(x)} />;
      case 'standings':
        return this.standings(tt);
      case 'matches':
        return this.matchList(tt);
      case 'predict':
        return this.predict(tt);
      case 'board':
        return this.boardView(tt);
      case 'manage':
        return this.manage(tt);
      default:
        return this.entrantsView(tt);
    }
  }

  standings(tt: Tournament): Mithril.Children {
    const byId = new Map(tt.entrants.map((e) => [e.id, e]));
    const pts = (n: number) => (n % 2 ? (n / 2).toFixed(1) : String(n / 2));

    return (
      <div className="Gauntlet-tableWrap">
        <table className="Gauntlet-table">
          <thead>
            <tr>
              <th>#</th>
              <th>{t('standings.entrant')}</th>
              <th className="num">{t('standings.played')}</th>
              <th className="num">{t('standings.record')}</th>
              <th className="num hide-phone">{t('standings.diff')}</th>
              <th className="num hide-phone" title={extractText(t(tt.format === 'swiss' ? 'standings.buchholz_help' : 'standings.sb_help'))}>
                {t(tt.format === 'swiss' ? 'standings.buchholz' : 'standings.sb')}
              </th>
              <th className="num">{t('standings.points')}</th>
            </tr>
          </thead>
          <tbody>
            {(tt.standings || []).map((row) => (
              <tr>
                <td>{row.rank}</td>
                <td>{this.person(byId.get(row.entrant))}</td>
                <td className="num">{row.played}</td>
                <td className="num">{`${row.wins}–${row.draws}–${row.losses}`}</td>
                <td className="num hide-phone">{row.for - row.against}</td>
                <td className="num hide-phone">{pts(row.tiebreak)}</td>
                <td className="num">
                  <strong>{pts(row.points)}</strong>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    );
  }

  matchList(tt: Tournament): Mithril.Children {
    const byId = new Map(tt.entrants.map((e) => [e.id, e]));
    const title = roundTitle(tt);
    const groups = new Map<string, Match[]>();
    const order = ['W', 'P', 'L', 'F', 'R', 'S'];
    [...tt.matches]
      .filter((x) => x.status !== 'void')
      .sort((a, b) => order.indexOf(a.bracket) - order.indexOf(b.bracket) || a.round - b.round || a.position - b.position)
      .forEach((x) => {
        const label =
          x.bracket === 'P'
            ? extractText(t('bracket.third_place'))
            : x.bracket === 'R' || x.bracket === 'S'
              ? extractText(t('bracket.round', { number: x.round }))
              : (tt.format === 'double' ? extractText(t('bracket.section_' + x.bracket)) + ' · ' : '') +
                title(x.bracket, x.round, maxRound(tt, x.bracket === 'F' ? 'W' : x.bracket));
        if (!groups.has(label)) groups.set(label, []);
        groups.get(label)!.push(x);
      });

    return (
      <div>
        {[...groups.entries()].map(([label, matches]) => (
          <section>
            <h3 className="Gauntlet-roundTitle">{label}</h3>
            <div className="Gauntlet-matches">
              {matches.map((x) => (
                <button
                  type="button"
                  className="Gauntlet-matchCard"
                  onclick={() => this.open(x)}
                  disabled={x.status === 'bye' || (x.status === 'pending' && !tt.me.canManage)}
                >
                  <header>
                    <span className={'Gauntlet-pill is-' + x.status}>{t('match.status.' + x.status)}</span>
                    {x.scheduledAt ? <span>{formatDate(x.scheduledAt)}</span> : null}
                  </header>
                  {[1, 2].map((side) => {
                    const id = side === 1 ? x.entrant1Id : x.entrant2Id;
                    const dead = side === 1 ? x.dead1 : x.dead2;
                    return (
                      <div className={'side' + (x.winnerId && id === x.winnerId ? ' is-won' : x.winnerId && id ? ' is-lost' : '')}>
                        <span>{id ? entrantName(byId.get(id)) : dead ? t('bracket.bye') : t('bracket.tbd')}</span>
                        <span>{side === 1 ? (x.score1 ?? '') : (x.score2 ?? '')}</span>
                      </div>
                    );
                  })}
                </button>
              ))}
            </div>
          </section>
        ))}
      </div>
    );
  }

  person(e: Entrant | undefined): Mithril.Children {
    if (!e) return null;
    const logo = e.logoUrl ? <img className="Gauntlet-teamLogo" src={e.logoUrl} alt="" loading="lazy" decoding="async" /> : null;
    const avatar = !logo && e.user ? personAvatar(e.user) : null;
    const name = entrantName(e);

    return (
      <span className="Gauntlet-person">
        {logo || avatar}
        {e.user && !e.name ? <Link href={app.route('user', { username: e.user.username })}>{name}</Link> : <span>{name}</span>}
      </span>
    );
  }

  entrantsView(tt: Tournament): Mithril.Children {
    const manage = tt.me.canManage && (tt.status === 'registration' || tt.status === 'draft');
    const me = this.mine(tt);
    const order = ['registered', 'waitlisted', 'withdrawn', 'disqualified', 'no_show'];
    const list = [...tt.entrants].sort(
      (a, b) =>
        order.indexOf(a.status) - order.indexOf(b.status) ||
        (a.finalRank ?? 999) - (b.finalRank ?? 999) ||
        (a.seed ?? 999) - (b.seed ?? 999) ||
        a.id - b.id
    );

    if (!list.length) return <p className="Gauntlet-notice">{t('entrants.empty')}</p>;

    // Seeds mean something once drawn, or while an organiser sets them by hand.
    const seeded = tt.status === 'running' || tt.status === 'finished' || (manage && tt.seeding === 'manual');

    return (
      <div className="Gauntlet-tableWrap">
        <table className="Gauntlet-table">
          <thead>
            <tr>
              {seeded ? <th>{tt.status === 'finished' ? t('entrants.place') : t('entrants.seed')}</th> : null}
              <th>{t('standings.entrant')}</th>
              {tt.entryMode === 'members' ? <th className="num hide-phone">{t('entrants.rating')}</th> : null}
              <th>{t('entrants.status')}</th>
              {manage || (tt.entryType === 'team' && !me) ? <th /> : null}
            </tr>
          </thead>
          <tbody>
            {list.map((e) => (
              <tr>
                {seeded ? (
                  <td>
                    {manage && tt.seeding === 'manual' ? (
                      <input
                        className="FormControl"
                        type="number"
                        min="1"
                        style={{ width: '64px' }}
                        aria-label={extractText(t('entrants.seed'))}
                        value={e.seed ?? ''}
                        onchange={(ev: Event) => this.run(api.updateEntrant(e.id, { seed: Number((ev.target as HTMLInputElement).value) || 0 }))}
                      />
                    ) : (
                      ((tt.status === 'finished' ? e.finalRank : e.seed) ?? '–')
                    )}
                  </td>
                ) : null}
                <td>
                  {this.person(e)}
                  {tt.entryType === 'team' && e.members.length ? (
                    <div className="Gauntlet-roster">{e.members.map((p) => p.displayName).join(', ')}</div>
                  ) : null}
                  {e.requests?.length && tt.status === 'registration'
                    ? e.requests.map((p) => (
                        <div className="Gauntlet-roster">
                          {t('entrants.request', { name: p.displayName })}{' '}
                          <Button className="Button Button--text" onclick={() => this.run(api.approve(e.id, p.id))}>
                            {t('entrants.approve')}
                          </Button>
                          <Button className="Button Button--text" onclick={() => this.run(api.leave(e.id, p.id))}>
                            {t('entrants.decline')}
                          </Button>
                        </div>
                      ))
                    : null}
                </td>
                {tt.entryMode === 'members' ? <td className="num hide-phone">{e.rating ?? ''}</td> : null}
                <td>
                  {t('entrants.statuses.' + e.status)}
                  {tt.checkinMinutes && e.checkedIn ? ' ✓' : ''}
                  {tt.status !== 'registration' && tt.status !== 'draft' && (e.wins || e.losses || e.draws) ? (
                    <div className="Gauntlet-roster">{`${e.wins}–${e.draws}–${e.losses}`}</div>
                  ) : null}
                </td>
                {manage ? (
                  <td className="num">
                    {e.status === 'waitlisted' ? (
                      <Button className="Button Button--text" onclick={() => this.run(api.updateEntrant(e.id, { promote: true }))}>
                        {t('entrants.promote')}
                      </Button>
                    ) : null}
                    {tt.checkinMinutes ? (
                      <Button className="Button Button--text" onclick={() => this.run(api.checkIn(e.id, e.checkedIn))}>
                        {e.checkedIn ? t('tournament.undo_checkin') : t('tournament.check_in')}
                      </Button>
                    ) : null}
                    <Button
                      className="Button Button--text"
                      icon="fas fa-xmark"
                      aria-label={extractText(t('entrants.remove'))}
                      onclick={() => confirm(extractText(t('entrants.remove_confirm', { name: entrantName(e) }))) && this.run(api.withdraw(e.id))}
                    />
                  </td>
                ) : tt.entryType === 'team' && !me ? (
                  <td className="num">
                    {tt.me.requestedEntrantId === e.id ? (
                      <Button className="Button Button--text" onclick={() => this.run(api.leave(e.id, Number(app.session.user!.id())))}>
                        {t('entrants.cancel_request')}
                      </Button>
                    ) : !tt.me.requestedEntrantId &&
                      tt.status === 'registration' &&
                      tt.me.canJoin &&
                      app.session.user &&
                      e.status !== 'withdrawn' &&
                      (!tt.teamSize || e.members.length < tt.teamSize) ? (
                      <Button className="Button" onclick={() => this.run(api.join(e.id))}>
                        {e.groupId ? t('entrants.join_team') : t('entrants.ask_to_join')}
                      </Button>
                    ) : null}
                  </td>
                ) : null}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    );
  }

  predict(tt: Tournament): Mithril.Children {
    const open = tt.me.predictionsOpen && tt.me.canPredict;
    const playable = tt.matches.filter((x) => (x.bracket === 'W' || x.bracket === 'P') && x.status !== 'bye' && x.status !== 'void');
    const made = playable.filter((x) => this.picks[x.key]).length;
    const mine = tt.me.prediction;

    return (
      <div>
        <p className="Gauntlet-notice">
          {open
            ? tt.predictionsLockAt
              ? t('predict.open_until', { time: formatDate(tt.predictionsLockAt) })
              : t('predict.open_until_first')
            : t('predict.closed')}{' '}
          {mine ? t('predict.score', { score: mine.score, correct: mine.correct, possible: mine.possible }) : null}
        </p>
        <Bracket tournament={tt} picks={this.picks} onPick={open ? (key: string, id: number) => this.pick(key, id) : undefined} />
        {open ? (
          <div className="Gauntlet-actions" style={{ alignItems: 'center' }}>
            <span>{t('predict.progress', { made, total: playable.length })}</span>
            <label className="Gauntlet-scoreForm" style={{ margin: 0 }}>
              <span>{t('predict.tiebreaker')}</span>
              <input
                className="FormControl"
                type="number"
                min="0"
                max="9999"
                inputmode="numeric"
                value={this.tiebreaker}
                oninput={(e: InputEvent) => ((this.tiebreaker = (e.target as HTMLInputElement).value), (this.picksDirty = true))}
              />
            </label>
            <Button className="Button Button--primary" loading={this.busy} disabled={!this.picksDirty || !made} onclick={() => this.savePicks(tt)}>
              {t('predict.save')}
            </Button>
          </div>
        ) : null}
      </div>
    );
  }

  pick(key: string, id: number) {
    this.picks = prune(this.tournament!, { ...this.picks, [key]: id });
    this.picksDirty = true;
  }

  savePicks(tt: Tournament) {
    this.busy = true;
    api.predict(tt.id, this.picks, this.tiebreaker === '' ? null : Number(this.tiebreaker)).then(
      ({ data }) => {
        this.busy = false;
        this.picksDirty = false;
        tt.me.prediction = data;
        this.board = null;
        app.alerts.show({ type: 'success' }, t('predict.saved'));
        m.redraw();
      },
      () => {
        this.busy = false;
        m.redraw();
      }
    );
  }

  loadBoard(page = 1) {
    api.board(this.tournament!.id, page).then(({ data, meta }) => {
      this.board = page === 1 ? data : [...(this.board || []), ...data];
      this.boardPage = page;
      this.boardMore = meta.more;
      m.redraw();
    });
  }

  boardView(tt: Tournament): Mithril.Children {
    if (!this.board) return <LoadingIndicator display="block" />;
    if (!this.board.length) return <p className="Gauntlet-notice">{t('predict.board_empty')}</p>;
    const me = Number(app.session.user?.id());

    return (
      <div className="Gauntlet-tableWrap Gauntlet-board">
        <table className="Gauntlet-table">
          <thead>
            <tr>
              <th>#</th>
              <th>{t('predict.member')}</th>
              <th className="num">{t('predict.points')}</th>
              <th className="num hide-phone">{t('predict.correct')}</th>
              <th className="num">{t('predict.possible')}</th>
            </tr>
          </thead>
          <tbody>
            {this.board.map((row) => (
              <tr className={row.user?.id === me ? 'is-me' : ''}>
                <td>{row.place}</td>
                <td>{row.user ? <Link href={app.route('user', { username: row.user.username })}>{row.user.displayName}</Link> : null}</td>
                <td className="num">
                  <strong>{row.score}</strong>
                </td>
                <td className="num hide-phone">{row.correct}</td>
                <td className="num">{row.possible}</td>
              </tr>
            ))}
          </tbody>
        </table>
        {this.boardMore ? (
          <Button className="Button" onclick={() => this.loadBoard(this.boardPage + 1)}>
            {t('tournaments.more')}
          </Button>
        ) : null}
      </div>
    );
  }

  manage(tt: Tournament): Mithril.Children {
    const confirmRun = (key: string, request: () => Promise<{ data: Tournament }>) => () => confirm(extractText(t(key))) && this.run(request());

    return (
      <div className="Gauntlet-form">
        <div className="Gauntlet-actions">
          <LinkButton className="Button" icon="fas fa-pen" href={app.route('gauntlet.edit', { id: tt.id })}>
            {t('manage.edit')}
          </LinkButton>
          {tt.status === 'draft' ? (
            <Button className="Button Button--primary" icon="fas fa-bullhorn" loading={this.busy} onclick={() => this.run(api.act(tt.id, 'publish'))}>
              {tt.entryMode === 'staff' ? t('manage.publish_staff') : t('manage.publish')}
            </Button>
          ) : null}
          {tt.status === 'registration' ? (
            <Button
              className="Button Button--primary"
              icon="fas fa-play"
              loading={this.busy}
              onclick={confirmRun('manage.start_confirm', () => api.act(tt.id, 'start'))}
            >
              {t('manage.start')}
            </Button>
          ) : null}
          {tt.status === 'running' ? (
            <Button className="Button" icon="fas fa-rotate-left" onclick={confirmRun('manage.reset_confirm', () => api.act(tt.id, 'reset'))}>
              {t('manage.reset')}
            </Button>
          ) : null}
          {!tt.discussionId && tt.status !== 'draft' ? (
            <Button className="Button" icon="far fa-comments" onclick={() => this.run(api.act(tt.id, 'discussion'))}>
              {t('manage.discussion')}
            </Button>
          ) : null}
          {tt.status !== 'finished' && tt.status !== 'cancelled' ? (
            <Button className="Button" icon="fas fa-ban" onclick={confirmRun('manage.cancel_confirm', () => api.act(tt.id, 'cancel'))}>
              {t('manage.cancel')}
            </Button>
          ) : null}
          <Button
            className="Button Button--danger"
            icon="fas fa-trash"
            onclick={() => confirm(extractText(t('manage.delete_confirm'))) && api.remove(tt.id).then(() => m.route.set(app.route('gauntlet.index')))}
          >
            {t('manage.delete')}
          </Button>
        </div>

        {tt.status === 'registration' && tt.entryMode === 'members' ? <p className="helpText">{t('manage.start_help')}</p> : null}

        {tt.entryMode === 'staff' && (tt.status === 'draft' || tt.status === 'registration') ? this.staffField(tt) : null}
      </div>
    );
  }

  staffField(tt: Tournament): Mithril.Children {
    return (
      <fieldset>
        <legend>{t('manage.add_heading')}</legend>
        {tt.me.playoffBoard ? (
          <div className="Gauntlet-notice">
            <p>{t('manage.playoff_board', { week: tt.me.playoffBoard.week, teams: tt.me.playoffBoard.teams.join(', ') })}</p>
            <Button
              className="Button Button--primary"
              icon="fas fa-football"
              loading={this.busy}
              onclick={() => this.run(api.act(tt.id, 'playoff-board'))}
            >
              {t('manage.playoff_board_fill')}
            </Button>
          </div>
        ) : null}
        {tt.me.picksAvailable ? (
          <div className="Form-group Gauntlet-search">
            <label htmlFor="gauntlet-picks">{t('manage.picks_search')}</label>
            <input
              id="gauntlet-picks"
              className="FormControl"
              type="search"
              value={this.teamQuery}
              oninput={(e: InputEvent) => this.searchTeams((e.target as HTMLInputElement).value)}
            />
            {this.teamResults.length ? (
              <ul className="Gauntlet-searchResults">
                {this.teamResults.map((team) => (
                  <li>
                    <button
                      type="button"
                      onclick={() =>
                        this.run(api.signUp(tt.id, { picksTeamId: team.id })).then(() => ((this.teamQuery = ''), (this.teamResults = [])))
                      }
                    >
                      {team.logoUrl ? <img src={team.logoUrl} alt="" loading="lazy" decoding="async" /> : null}
                      {team.name}
                    </button>
                  </li>
                ))}
              </ul>
            ) : null}
          </div>
        ) : null}
        <div className="Gauntlet-grid2">
          <div className="Form-group">
            <label htmlFor="gauntlet-staff-name">{t('manage.name')}</label>
            <input
              id="gauntlet-staff-name"
              className="FormControl"
              maxlength={100}
              value={this.staffName}
              oninput={(e: InputEvent) => (this.staffName = (e.target as HTMLInputElement).value)}
            />
          </div>
          <div className="Form-group">
            <label htmlFor="gauntlet-staff-logo">{t('manage.logo')}</label>
            <input
              id="gauntlet-staff-logo"
              className="FormControl"
              type="url"
              placeholder="https://"
              value={this.staffLogo}
              oninput={(e: InputEvent) => (this.staffLogo = (e.target as HTMLInputElement).value)}
            />
          </div>
        </div>
        <Button
          className="Button Button--primary"
          disabled={!this.staffName.trim()}
          loading={this.busy}
          onclick={() =>
            this.run(api.signUp(tt.id, { name: this.staffName, logoUrl: this.staffLogo || null })).then(
              () => ((this.staffName = ''), (this.staffLogo = ''))
            )
          }
        >
          {t('manage.add')}
        </Button>
        <p className="helpText">{t('manage.seed_help')}</p>
      </fieldset>
    );
  }

  searchTeams(q: string) {
    this.teamQuery = q;
    window.clearTimeout(this.searchTimer);
    if (q.trim().length < 2) {
      this.teamResults = [];
      return;
    }
    this.searchTimer = window.setTimeout(() => {
      api.picksTeams(q).then(({ data }) => {
        if (this.teamQuery === q) this.teamResults = data;
        m.redraw();
      });
    }, 250);
  }

  open(match: Match) {
    if (match.status === 'bye' || match.status === 'void') return;
    app.modal.show(MatchModal, { tournament: this.tournament!, match, onchange: (data: Tournament) => this.set(data) });
  }

  run(request: Promise<{ data: Tournament }>): Promise<void> {
    this.busy = true;
    return request.then(
      ({ data }) => {
        this.busy = false;
        this.set(data);
      },
      () => {
        this.busy = false;
        m.redraw();
      }
    );
  }
}

export { tournamentUrl };
