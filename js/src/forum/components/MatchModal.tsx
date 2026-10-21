import app from 'flarum/forum/app';
import FormModal from 'flarum/common/components/FormModal';
import type { IFormModalAttrs } from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import Link from 'flarum/common/components/Link';
import Stream from 'flarum/common/utils/Stream';
import type Mithril from 'mithril';
import { api, t } from '../../common/api';
import type { Entrant, Match, Tournament } from '../../common/api';
import { entrantName } from './Bracket';

export interface MatchModalAttrs extends IFormModalAttrs {
  tournament: Tournament;
  match: Match;
  onchange: (tournament: Tournament) => void;
}

/**
 * One match: who is in it, what each side reported, and what the reader may
 * do about it — report, confirm, answer a report, or (organisers) rule,
 * award a forfeit, correct, link a Picks game, open a discussion.
 */
export default class MatchModal extends FormModal<MatchModalAttrs> {
  mine = Stream('');
  theirs = Stream('');
  score1 = Stream('');
  score2 = Stream('');
  scheduled = Stream('');
  games: { id: number; startsAt: string | null; status: string }[] | null = null;
  /** The tournament as of the last change made here (attrs keep the copy it opened with). */
  current!: Tournament;

  oninit(vnode: Mithril.Vnode<MatchModalAttrs, this>) {
    super.oninit(vnode);
    this.current = this.attrs.tournament;
    const match = this.attrs.match;
    this.score1(match.score1 !== null ? String(match.score1) : '');
    this.score2(match.score2 !== null ? String(match.score2) : '');
    this.scheduled(match.scheduledAt ? toLocalInput(match.scheduledAt) : '');

    const side = this.side();
    const report = side && match.reports?.[side];
    if (report) {
      this.mine(String(side === 1 ? report[0] : report[1]));
      this.theirs(String(side === 1 ? report[1] : report[0]));
    }
  }

  className() {
    return 'Modal--small Gauntlet';
  }

  title() {
    return t('match.title');
  }

  match(): Match {
    // Always the current copy: the tournament is replaced after each change.
    return this.current.matches.find((match) => match.id === this.attrs.match.id) || this.attrs.match;
  }

  entrant(id: number | null): Entrant | undefined {
    return id ? this.current.entrants.find((e) => e.id === id) : undefined;
  }

  /** 1 or 2 if the reader reports for a side here. */
  side(): 1 | 2 | null {
    const me = this.current.me.entrantId;
    const match = this.match();
    if (!me) return null;
    const entrant = this.entrant(me);
    if (!entrant || entrant.user?.id !== Number(app.session.user?.id())) return null;
    if (match.entrant1Id === me) return 1;
    if (match.entrant2Id === me) return 2;

    return null;
  }

  content(): Mithril.Children {
    const tournament = this.current;
    const match = this.match();
    const one = this.entrant(match.entrant1Id);
    const two = this.entrant(match.entrant2Id);
    const side = this.side();
    const live = ['open', 'reported', 'disputed'].includes(match.status);
    const manage = tournament.me.canManage && tournament.status !== 'cancelled' && !!one && !!two;

    return (
      <div className="Modal-body">
        <p>
          <span className={'Gauntlet-pill is-' + match.status}>{t('match.status.' + match.status)}</span>{' '}
          {match.bestOf > 1 ? <span className="helpText">{t('match.best_of', { count: match.bestOf })}</span> : null}
        </p>

        <table className="Gauntlet-table">
          <tbody>
            {[one, two].map((e, i) => {
              const id = i === 0 ? match.entrant1Id : match.entrant2Id;
              const score = i === 0 ? match.score1 : match.score2;
              return (
                <tr className={match.winnerId && id === match.winnerId ? 'is-won' : ''}>
                  <td>
                    <strong>{e ? entrantName(e) : t('bracket.tbd')}</strong>
                    {e && e.members.length > 1 ? <div className="Gauntlet-roster">{e.members.map((p) => p.displayName).join(', ')}</div> : null}
                  </td>
                  <td className="num">
                    <strong>{score ?? (match.winnerId && id === match.winnerId ? t('match.forfeit_win') : '')}</strong>
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>

        {match.reports && (match.reports[1] || match.reports[2]) && match.status !== 'completed' ? (
          <ul className="Gauntlet-meta" style={{ margin: '10px 0' }}>
            {([1, 2] as const).map((s) =>
              match.reports![s] ? (
                <li>
                  {t('match.reported_by', {
                    name: entrantName(this.entrant(s === 1 ? match.entrant1Id : match.entrant2Id)),
                    one: entrantName(one),
                    two: entrantName(two),
                    a: match.reports![s]![0],
                    b: match.reports![s]![1],
                  })}
                </li>
              ) : null
            )}
          </ul>
        ) : null}

        {match.game ? <p className="helpText">{t('match.picks_game', { status: match.game.status, detail: match.game.detail || '' })}</p> : null}

        {match.discussionId ? (
          <p>
            <Link href={app.route('discussion', { id: match.discussionId })}>{t('match.discussion')}</Link>
          </p>
        ) : null}

        {side && live && tournament.status === 'running' ? this.reportForm(side, match) : null}
        {match.status === 'awaiting' && side ? <p className="Gauntlet-notice">{t('match.awaiting_staff')}</p> : null}

        {manage ? this.staffTools(match, one!, two!) : null}
      </div>
    );
  }

  reportForm(side: 1 | 2, match: Match): Mithril.Children {
    const other = side === 1 ? 2 : 1;
    const theirReport = match.reports?.[other];
    const otherName = entrantName(this.entrant(other === 1 ? match.entrant1Id : match.entrant2Id));

    return (
      <div className="Form">
        <h4>{t('match.report_heading')}</h4>
        {theirReport ? (
          <p className="Gauntlet-notice">
            {t('match.they_reported', {
              name: otherName,
              mine: side === 1 ? theirReport[0] : theirReport[1],
              theirs: side === 1 ? theirReport[1] : theirReport[0],
            })}{' '}
            <Button
              className="Button Button--primary"
              loading={this.loading}
              onclick={() => this.submitReport(side, side === 1 ? theirReport[0] : theirReport[1], side === 1 ? theirReport[1] : theirReport[0])}
            >
              {t('match.confirm')}
            </Button>
          </p>
        ) : null}
        <div className="Gauntlet-scoreForm">
          <label htmlFor="gauntlet-mine">{t('match.my_score')}</label>
          <input id="gauntlet-mine" className="FormControl" type="number" min="0" max="9999" inputmode="numeric" bidi={this.mine} />
        </div>
        <div className="Gauntlet-scoreForm">
          <label htmlFor="gauntlet-theirs">{t('match.their_score', { name: otherName })}</label>
          <input id="gauntlet-theirs" className="FormControl" type="number" min="0" max="9999" inputmode="numeric" bidi={this.theirs} />
        </div>
        <Button
          className="Button Button--primary"
          loading={this.loading}
          onclick={() => this.submitReport(side, Number(this.mine()), Number(this.theirs()))}
          disabled={this.mine() === '' || this.theirs() === ''}
        >
          {theirReport ? t('match.report_different') : t('match.report')}
        </Button>
      </div>
    );
  }

  staffTools(match: Match, one: Entrant, two: Entrant): Mithril.Children {
    const tournament = this.current;
    const canRule = tournament.status === 'running' || (tournament.status === 'finished' && match.status === 'completed');
    const done = match.status === 'completed';

    return (
      <div className="Form" style={{ marginTop: '16px', borderTop: '1px solid var(--control-bg)', paddingTop: '12px' }}>
        <h4>{done ? t('match.correct_heading') : t('match.rule_heading')}</h4>
        {canRule ? (
          <div>
            <div className="Gauntlet-scoreForm">
              <label htmlFor="gauntlet-s1">{entrantName(one)}</label>
              <input id="gauntlet-s1" className="FormControl" type="number" min="0" max="9999" inputmode="numeric" bidi={this.score1} />
            </div>
            <div className="Gauntlet-scoreForm">
              <label htmlFor="gauntlet-s2">{entrantName(two)}</label>
              <input id="gauntlet-s2" className="FormControl" type="number" min="0" max="9999" inputmode="numeric" bidi={this.score2} />
            </div>
            <div className="Gauntlet-actions">
              <Button
                className="Button Button--primary"
                loading={this.loading}
                disabled={this.score1() === '' || this.score2() === ''}
                onclick={() => this.resolve({ score1: Number(this.score1()), score2: Number(this.score2()) })}
              >
                {done ? t('match.save_correction') : t('match.save_result')}
              </Button>
              <Button className="Button" onclick={() => this.resolve({ winner: 1 })} disabled={this.loading}>
                {t('match.forfeit_to', { name: entrantName(one) })}
              </Button>
              <Button className="Button" onclick={() => this.resolve({ winner: 2 })} disabled={this.loading}>
                {t('match.forfeit_to', { name: entrantName(two) })}
              </Button>
            </div>
          </div>
        ) : null}

        <div className="Form-group">
          <label htmlFor="gauntlet-when">{t('match.scheduled')}</label>
          <div className="Gauntlet-actions" style={{ margin: 0 }}>
            <input id="gauntlet-when" className="FormControl" type="datetime-local" bidi={this.scheduled} style={{ maxWidth: '240px' }} />
            <Button
              className="Button"
              onclick={() =>
                this.change(api.updateMatch(match.id, { scheduledAt: this.scheduled() ? new Date(this.scheduled()).toISOString() : null }))
              }
            >
              {t('match.save_time')}
            </Button>
          </div>
        </div>

        {tournament.me.picksAvailable && one.picksTeamId && two.picksTeamId ? this.picksLink(match, one, two) : null}

        {!match.discussionId ? (
          <Button className="Button" icon="far fa-comments" onclick={() => this.change(api.matchDiscussion(match.id))}>
            {t('match.start_discussion')}
          </Button>
        ) : null}
      </div>
    );
  }

  picksLink(match: Match, one: Entrant, two: Entrant): Mithril.Children {
    if (this.games === null) {
      this.games = [];
      api.picksGames(one.picksTeamId!, two.picksTeamId!).then(({ data }) => {
        this.games = data;
        m.redraw();
      });
    }

    return (
      <div className="Form-group">
        <label htmlFor="gauntlet-game">{t('match.picks_link')}</label>
        <select
          id="gauntlet-game"
          className="FormControl"
          onchange={(e: Event) => this.change(api.updateMatch(match.id, { picksEventId: (e.target as HTMLSelectElement).value || null }))}
        >
          <option value="">{t('match.picks_none')}</option>
          {this.games.map((g) => (
            <option value={g.id} selected={g.id === match.picksEventId}>
              {(g.startsAt ? new Date(g.startsAt).toLocaleString() : '?') + ' · ' + g.status}
            </option>
          ))}
        </select>
      </div>
    );
  }

  submitReport(side: 1 | 2, mine: number, theirs: number) {
    const [s1, s2] = side === 1 ? [mine, theirs] : [theirs, mine];
    this.change(api.report(this.match().id, s1, s2));
  }

  resolve(data: { score1?: number; score2?: number; winner?: 1 | 2 }) {
    this.change(api.resolve(this.match().id, { score1: null, score2: null, winner: null, ...data }));
  }

  change(request: Promise<{ data: Tournament }>) {
    this.loading = true;
    request.then(
      ({ data }) => {
        this.loading = false;
        this.current = data;
        this.attrs.onchange(data);
        const updated = data.matches.find((x) => x.id === this.attrs.match.id);
        // Done once the match is settled; otherwise stay to show the new state.
        if (!updated || ['completed', 'awaiting'].includes(updated.status)) this.hide();
        m.redraw();
      },
      () => {
        this.loading = false;
        m.redraw();
      }
    );
  }

  onsubmit(e: SubmitEvent) {
    e.preventDefault();
  }
}

function toLocalInput(iso: string): string {
  const d = new Date(iso);
  const pad = (n: number) => String(n).padStart(2, '0');

  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}
