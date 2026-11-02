import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import type { Entrant, Match, Tournament } from '../../common/api';
import { t } from '../../common/api';
import { hue } from './bits';
import { BOX_H, BOX_W, COL_GAP, HEAD_H, ROW_H, candidates, feeders, layout, maxRound } from '../bracket/layout';
import type { Placed, Section } from '../bracket/layout';

export interface BracketAttrs {
  tournament: Tournament;
  /** Picking a prediction bracket: rows are buttons. */
  picks?: Record<string, number> | null;
  onPick?: (key: string, entrantId: number) => void;
  onOpen?: (match: Match) => void;
}

const MAX_NAME = 19;

export function entrantName(e: Entrant | undefined | null): string {
  if (!e) return '';
  return e.name || e.user?.displayName || extractDeleted();
}

function extractDeleted(): string {
  return app.translator.trans('core.lib.username.deleted_text') as unknown as string;
}

export function roundTitle(tournament: Tournament) {
  return (bracket: string, round: number, rounds: number): string => {
    if (bracket === 'F') return String(round === 1 ? t('bracket.grand_final') : t('bracket.reset'));
    if (bracket === 'L') return String(round === rounds ? t('bracket.losers_final') : t('bracket.round', { number: round }));
    if (tournament.format === 'double' && round === rounds) return extractText(t('bracket.winners_final'));
    if (round === rounds) return extractText(t('bracket.final'));
    if (round === rounds - 1) return extractText(t('bracket.semi_finals'));
    if (round === rounds - 2) return extractText(t('bracket.quarter_finals'));

    return extractText(t('bracket.round', { number: round }));
  };
}

/**
 * The bracket as SVG, at its natural size inside a scroller: on a phone it
 * pans sideways rather than shrinking text to nothing, and the round chips
 * above jump to a column.
 */
export default class Bracket extends Component<BracketAttrs> {
  scroller: HTMLElement | null = null;

  view(): Mithril.Children {
    const tournament = this.attrs.tournament;
    const sections = layout(tournament, roundTitle(tournament), (key) =>
      tournament.format === 'double' ? extractText(t(`bracket.section_${key}`)) : null
    );
    const entrants = new Map(tournament.entrants.map((e) => [e.id, e]));
    const into = this.attrs.picks ? feeders(tournament) : null;

    return (
      <div className="Gauntlet-bracket">
        {sections.map((section) => (
          <section className="Gauntlet-bracketSection" key={section.key}>
            {section.title ? <h3 className="Gauntlet-sectionTitle">{section.title}</h3> : null}
            {section.columns.length > 2 ? (
              <div className="Gauntlet-roundChips" role="toolbar" aria-label={extractText(t('bracket.jump'))}>
                {section.columns.map((col) => (
                  <button type="button" className="Button Button--text Gauntlet-chip" onclick={(e: MouseEvent) => this.jump(e, col.x)}>
                    {col.title}
                  </button>
                ))}
              </div>
            ) : null}
            <div className="Gauntlet-bracketScroll">
              <svg
                className="Gauntlet-svg"
                width={section.width + 2}
                height={section.height}
                viewBox={`-1 0 ${section.width + 2} ${section.height}`}
                role="group"
                aria-label={section.title || extractText(t('bracket.title'))}
              >
                {section.columns.map((col) => (
                  <text className="Gauntlet-colTitle" x={col.x + BOX_W / 2} y={16} text-anchor="middle">
                    {col.title}
                  </text>
                ))}
                {this.lines(section)}
                {section.placed.map((p) => this.box(p, entrants, into))}
              </svg>
            </div>
          </section>
        ))}
      </div>
    );
  }

  jump(e: MouseEvent, x: number) {
    const scroller = (e.currentTarget as HTMLElement).closest('.Gauntlet-bracketSection')?.querySelector('.Gauntlet-bracketScroll');
    scroller?.scrollTo({ left: Math.max(0, x - 8), behavior: 'smooth' });
  }

  lines(section: Section): Mithril.Children {
    const at = new Map(section.placed.map((p) => [p.match.key, p]));

    return section.placed.map((p) => {
      const link = p.match.next;
      const target = link ? at.get(link[0]) : null;
      if (!link || !target) return null;

      const x1 = p.x + BOX_W;
      const y1 = p.y + BOX_H / 2;
      const x2 = target.x;
      const y2 = target.y + (link[1] === 1 ? ROW_H / 2 : ROW_H * 1.5);
      const mid = x1 + COL_GAP / 2;
      const done = p.match.winnerId !== null;

      return <path className={'Gauntlet-line' + (done ? ' is-done' : '')} d={`M${x1} ${y1} H${mid} V${y2} H${x2}`} />;
    });
  }

  box(p: Placed, entrants: Map<number, Entrant>, into: ReturnType<typeof feeders> | null): Mithril.Children {
    const match = p.match;
    const predicting = !!this.attrs.picks && !!into;
    const picks = this.attrs.picks || {};
    const options = predicting ? candidates(this.attrs.tournament, match, picks, into!) : [];
    const ids = [match.entrant1Id, match.entrant2Id];
    const live = match.game && match.game.status === 'in_progress';
    const bye = match.status === 'bye';
    // A match still waiting for its sides has nothing in it for members.
    const clickable =
      !predicting && !!this.attrs.onOpen && !bye && match.status !== 'void' && (match.status !== 'pending' || this.attrs.tournament.me.canManage);

    const classes = ['Gauntlet-match', `is-${match.status}`];
    if (live) classes.push('is-live');
    if (clickable) classes.push('is-clickable');

    const label = [entrantName(entrants.get(ids[0] as number)) || '?', entrantName(entrants.get(ids[1] as number)) || '?'].join(' – ');

    return (
      <g
        className={classes.join(' ')}
        transform={`translate(${p.x} ${p.y})`}
        role={clickable ? 'button' : undefined}
        tabindex={clickable ? 0 : undefined}
        aria-label={clickable ? label : undefined}
        onclick={clickable ? () => this.attrs.onOpen!(match) : undefined}
        onkeydown={clickable ? onActivate(() => this.attrs.onOpen!(match)) : undefined}
      >
        <rect className="Gauntlet-boxBg" width={BOX_W} height={BOX_H} rx={5} />
        <line className="Gauntlet-boxDivider" x1={0} x2={BOX_W} y1={ROW_H} y2={ROW_H} />
        {[1, 2].map((slot) => {
          const actual = ids[slot - 1];
          // In a prediction bracket an empty slot shows the member's own pick.
          const shown = actual ?? (predicting ? (options[slot - 1] ?? null) : null);
          const entrant = shown ? entrants.get(shown) : undefined;
          const dead = (slot === 1 ? match.dead1 : match.dead2) && !actual;
          const won = match.winnerId !== null && actual === match.winnerId;
          const lost = match.winnerId !== null && actual !== null && actual !== match.winnerId;
          const score = live ? this.liveScore(match, entrant) : slot === 1 ? match.score1 : match.score2;
          const picked = predicting && shown !== null && picks[match.key] === shown;
          const canPick = predicting && shown !== null && options.includes(shown) && this.attrs.onPick && match.status !== 'completed';
          const verdict =
            predicting && match.status === 'completed' && picks[match.key] && shown === picks[match.key]
              ? match.winnerId === shown
                ? 'right'
                : 'wrong'
              : null;

          const row = ['Gauntlet-row'];
          if (won) row.push('is-won');
          if (lost) row.push('is-lost');
          if (!actual && shown) row.push('is-guess');
          if (picked) row.push('is-picked');
          if (verdict) row.push('is-' + verdict);
          if (canPick) row.push('is-pickable');

          const y = (slot - 1) * ROW_H;

          return (
            <g
              className={row.join(' ')}
              role={canPick ? 'button' : undefined}
              tabindex={canPick ? 0 : undefined}
              aria-pressed={canPick ? String(picked) : undefined}
              aria-label={canPick ? extractText(t('predict.pick_label', { name: entrantName(entrant) })) : undefined}
              onclick={canPick ? () => this.attrs.onPick!(match.key, shown as number) : undefined}
              onkeydown={canPick ? onActivate(() => this.attrs.onPick!(match.key, shown as number)) : undefined}
            >
              <rect className="Gauntlet-rowBg" y={y} width={BOX_W} height={ROW_H} rx={slot === 1 ? 5 : 0} />
              {entrant?.seed ? (
                <text className="Gauntlet-seed" x={14} y={y + 17} text-anchor="middle">
                  {entrant.seed}
                </text>
              ) : null}
              {entrant ? this.mark(entrant, y) : null}
              <text className={'Gauntlet-name' + (!entrant && !dead ? ' is-tbd' : '')} x={50} y={y + 17}>
                {dead ? t('bracket.bye') : entrant ? truncate(entrantName(entrant)) : bye ? '' : t('bracket.tbd')}
              </text>
              {verdict ? (
                <text className={'Gauntlet-verdict is-' + verdict} x={BOX_W - 8} y={y + 17} text-anchor="end">
                  {verdict === 'right' ? '✓' : '✗'}
                </text>
              ) : score !== null && score !== undefined ? (
                <text className="Gauntlet-score" x={BOX_W - 8} y={y + 17} text-anchor="end">
                  {score}
                </text>
              ) : null}
            </g>
          );
        })}
        {match.bracket === 'P' ? (
          <text className="Gauntlet-colTitle" x={BOX_W / 2} y={-6} text-anchor="middle">
            {t('bracket.third_place')}
          </text>
        ) : null}
        {live ? (
          <text className="Gauntlet-live" x={BOX_W - 4} y={-4} text-anchor="end">
            {match.game!.detail || t('bracket.live')}
          </text>
        ) : match.status === 'disputed' && !predicting ? (
          <text className="Gauntlet-flag" x={BOX_W - 4} y={-4} text-anchor="end">
            {t('match.status.disputed')}
          </text>
        ) : null}
      </g>
    );
  }

  liveScore(match: Match, entrant: Entrant | undefined): number | null {
    if (!match.game || !entrant?.picksTeamId) return null;
    if (entrant.picksTeamId === match.game.homeTeamId) return match.game.homeScore;
    if (entrant.picksTeamId === match.game.awayTeamId) return match.game.awayScore;

    return null;
  }

  /** A logo, an avatar, or the first letter on a coloured disc. */
  mark(entrant: Entrant, y: number): Mithril.Children {
    const src = entrant.logoUrl || entrant.user?.avatarUrl;
    if (src) {
      return <image className="Gauntlet-logo" href={src} x={26} y={y + 4} width={18} height={18} preserveAspectRatio="xMidYMid meet" />;
    }

    const name = entrantName(entrant);
    return (
      <g>
        <circle className="Gauntlet-initialBg" cx={35} cy={y + 13} r={9} style={{ fill: hue(name) }} />
        <text className="Gauntlet-initial" x={35} y={y + 17} text-anchor="middle">
          {name.charAt(0).toUpperCase()}
        </text>
      </g>
    );
  }
}

/**
 * Enter or Space on something drawn as a button. Never returns false: Mithril
 * would cancel the key, and Tab would stop moving focus.
 */
function onActivate(run: () => void) {
  return (e: KeyboardEvent) => {
    if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      run();
    }
  };
}

function truncate(name: string): string {
  return name.length > MAX_NAME ? name.slice(0, MAX_NAME - 1) + '…' : name;
}

export { maxRound, HEAD_H };
