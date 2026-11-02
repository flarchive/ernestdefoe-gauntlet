<?php

namespace ErnestDefoe\Gauntlet\Integration\Picks;

use Carbon\Carbon;
use ErnestDefoe\Gauntlet\Director;
use ErnestDefoe\Gauntlet\Entrant;
use ErnestDefoe\Gauntlet\Fixture;
use ErnestDefoe\Gauntlet\Tournament;
use Flarum\Extension\ExtensionManager;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;

/**
 * Prediction brackets on top of the Picks extension, when it is on.
 *
 * Picks already keeps the teams (with their logos) and every game's live
 * score and result from its ESPN/CFBD sync. A staff-entered bracket picks its
 * field from Picks' teams and links each match to a Picks game; when Picks
 * marks that game finished, the match completes itself and every prediction
 * re-scores. No second feed, no second copy of a team.
 *
 * Members' bracket picks are not Picks' own picks: those are one game at a
 * time once a game exists, and a bracket is picked whole before the later
 * games are even scheduled.
 *
 * 🚨 Everything here reads Picks' tables by name, so Gauntlet loads the same
 * with Picks absent.
 */
class PicksBridge
{
    public const EXTENSION = 'ernestdefoe-picks';

    public function __construct(
        private ExtensionManager $extensions,
        private ConnectionInterface $db,
        private SettingsRepositoryInterface $settings,
        private LoggerInterface $log,
    ) {
    }

    public function available(): bool
    {
        return $this->extensions->isEnabled(self::EXTENSION);
    }

    /** @return list<array{id: int, name: string, abbreviation: string|null, logoUrl: string|null}> */
    public function searchTeams(string $q): array
    {
        if (! $this->available()) {
            return [];
        }

        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr(trim($q), 0, 60)).'%';

        return $this->db->table('picks_teams')
            ->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('abbreviation', 'like', $like))
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'abbreviation', 'logo_path'])
            ->map(fn ($t) => ['id' => (int) $t->id, 'name' => (string) $t->name, 'abbreviation' => $t->abbreviation, 'logoUrl' => $this->logoUrl($t->logo_path)])
            ->all();
    }

    /**
     * Teams by id, one query.
     *
     * @param list<int> $ids
     * @return array<int, array{name: string, logoUrl: string|null}>
     */
    public function teams(array $ids): array
    {
        if (! $ids || ! $this->available()) {
            return [];
        }

        $teams = [];
        foreach ($this->db->table('picks_teams')->whereIn('id', array_values(array_unique($ids)))->get(['id', 'name', 'logo_path']) as $team) {
            $teams[(int) $team->id] = ['name' => (string) $team->name, 'logoUrl' => $this->logoUrl($team->logo_path)];
        }

        return $teams;
    }

    /**
     * The linked games' state, for live scores on the bracket. One query.
     *
     * @param list<int> $ids
     * @return array<int, array<string, mixed>>
     */
    public function games(array $ids): array
    {
        if (! $ids || ! $this->available()) {
            return [];
        }

        $games = [];
        foreach ($this->db->table('picks_events')->whereIn('id', array_values(array_unique($ids)))->get(['id', 'home_team_id', 'away_team_id', 'home_score', 'away_score', 'status', 'match_date', 'clock_detail']) as $g) {
            $games[(int) $g->id] = [
                'homeTeamId' => (int) $g->home_team_id,
                'awayTeamId' => (int) $g->away_team_id,
                'homeScore' => $g->home_score === null ? null : (int) $g->home_score,
                'awayScore' => $g->away_score === null ? null : (int) $g->away_score,
                'status' => (string) $g->status,
                'startsAt' => $g->match_date ? Carbon::parse($g->match_date)->toIso8601String() : null,
                'detail' => (string) ($g->clock_detail ?? ''),
            ];
        }

        return $games;
    }

    /** @param list<int> $ids whether any of these games has kicked off */
    public function anyUnderway(array $ids): bool
    {
        if (! $ids || ! $this->available()) {
            return false;
        }

        return $this->db->table('picks_events')->whereIn('id', array_values(array_unique($ids)))->where('status', '!=', 'scheduled')->exists();
    }

    /** Games between two teams, newest first, for linking a match by hand. */
    public function gamesBetween(int $teamA, int $teamB): array
    {
        if (! $this->available()) {
            return [];
        }

        return $this->db->table('picks_events')
            ->where(fn ($w) => $w->where(['home_team_id' => $teamA, 'away_team_id' => $teamB])->orWhere(fn ($o) => $o->where(['home_team_id' => $teamB, 'away_team_id' => $teamA])))
            ->orderByDesc('match_date')
            ->limit(10)
            ->get(['id', 'match_date', 'status'])
            ->map(fn ($g) => ['id' => (int) $g->id, 'startsAt' => $g->match_date ? Carbon::parse($g->match_date)->toIso8601String() : null, 'status' => (string) $g->status])
            ->all();
    }

    /**
     * When a match between two Picks teams opens, link it to their game:
     * the first one between them since the bracket was set. An earlier
     * meeting (a regular-season game, a conference final) never counts.
     *
     * @param list<Fixture> $matches
     */
    public function autoLink(Tournament $tournament, array $matches): void
    {
        if (! $this->available() || ! $tournament->isStaffEntered()) {
            return;
        }

        $entrants = Entrant::query()->where('tournament_id', $tournament->id)->whereNotNull('picks_team_id')->pluck('picks_team_id', 'id');
        $since = $this->since($tournament);

        foreach ($matches as $match) {
            $a = $entrants[$match->entrant1_id] ?? null;
            $b = $entrants[$match->entrant2_id] ?? null;
            if ($match->picks_event_id || ! $a || ! $b) {
                continue;
            }

            $game = $this->db->table('picks_events')
                ->where(fn ($w) => $w->where(['home_team_id' => $a, 'away_team_id' => $b])->orWhere(fn ($o) => $o->where(['home_team_id' => $b, 'away_team_id' => $a])))
                ->where('match_date', '>=', $since)
                ->orderBy('match_date')
                ->first(['id', 'status']);

            if ($game) {
                $match->picks_event_id = (int) $game->id;
                $match->save();

                if ($game->status === 'finished') {
                    $this->settleFromGame((int) $game->id);
                }
            }
        }
    }

    /**
     * Picks saved a game. Only a game that has just finished costs a query:
     * live score updates save the same row every few seconds.
     */
    public function gameSaved(object $game): void
    {
        if (! $this->available() || ($game->status ?? null) !== 'finished' || ! ($game->result ?? null)) {
            return;
        }

        if (method_exists($game, 'wasChanged') && ! $game->wasRecentlyCreated && ! $game->wasChanged(['status', 'result', 'home_score', 'away_score'])) {
            return;
        }

        $this->settleFromGame((int) $game->id);
    }

    /** Complete every live match linked to a finished game (or only $onlyMatch). */
    public function settleFromGame(int $gameId, ?int $onlyMatch = null): void
    {
        $game = $this->db->table('picks_events')->where('id', $gameId)->first(['home_team_id', 'away_team_id', 'home_score', 'away_score', 'result', 'status', 'match_date']);
        if (! $game || $game->status !== 'finished' || ! in_array($game->result, ['home', 'away'], true)) {
            return;
        }

        $winnerTeam = (int) ($game->result === 'home' ? $game->home_team_id : $game->away_team_id);
        $scores = [(int) $game->home_team_id => $game->home_score, (int) $game->away_team_id => $game->away_score];

        $matches = $onlyMatch !== null
            ? Fixture::query()->whereKey($onlyMatch)->where('picks_event_id', $gameId)->whereIn('status', Fixture::LIVE)->with('tournament')->get()
            : Fixture::query()->where('picks_event_id', $gameId)->whereIn('status', Fixture::LIVE)->with('tournament')->get()
                ->merge($this->unlinkedBetween((int) $game->home_team_id, (int) $game->away_team_id, $gameId, $game->match_date ? Carbon::parse($game->match_date) : Carbon::now()));
        foreach ($matches as $match) {
            $teams = Entrant::query()->whereIn('id', [$match->entrant1_id, $match->entrant2_id])->pluck('picks_team_id', 'id');
            $winner = array_search($winnerTeam, $teams->all(), false);
            if ($winner === false) {
                $this->log->info("[gauntlet] match {$match->id}: Picks game $gameId was won by a team not in the match");
                continue;
            }

            $s1 = $scores[(int) ($teams[$match->entrant1_id] ?? 0)] ?? null;
            $s2 = $scores[(int) ($teams[$match->entrant2_id] ?? 0)] ?? null;

            try {
                resolve(Director::class)->external($match, (int) $winner, $s1 === null ? null : (int) $s1, $s2 === null || $s1 === null ? null : (int) $s2);
            } catch (\Throwable $e) {
                $this->log->warning("[gauntlet] match {$match->id} not settled from Picks game $gameId: ".$e->getMessage());
            }
        }
    }

    /**
     * Live matches between these two teams that nothing linked yet — a
     * later round opens before Picks has synced its game — linked now.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Fixture>
     */
    private function unlinkedBetween(int $home, int $away, int $gameId, Carbon $played): \Illuminate\Database\Eloquent\Collection
    {
        $ids = $this->db->table('gauntlet_matches as m')
            ->join('gauntlet_tournaments as t', 't.id', '=', 'm.tournament_id')
            ->join('gauntlet_entrants as a', 'a.id', '=', 'm.entrant1_id')
            ->join('gauntlet_entrants as b', 'b.id', '=', 'm.entrant2_id')
            ->whereNull('m.picks_event_id')
            ->whereIn('m.status', Fixture::LIVE)
            ->where('t.status', Tournament::RUNNING)
            ->where('t.entry_mode', 'staff')
            // Only brackets set before this game was played (12 hours' grace, as autoLink).
            ->where('t.started_at', '<=', $played->copy()->addHours(12))
            ->where(fn ($w) => $w->where(fn ($x) => $x->where('a.picks_team_id', $home)->where('b.picks_team_id', $away))
                ->orWhere(fn ($x) => $x->where('a.picks_team_id', $away)->where('b.picks_team_id', $home)))
            ->pluck('m.id')
            ->all();

        if (! $ids) {
            return new \Illuminate\Database\Eloquent\Collection();
        }

        Fixture::query()->whereIn('id', $ids)->update(['picks_event_id' => $gameId]);

        return Fixture::query()->whereIn('id', $ids)->with('tournament')->get();
    }

    /**
     * The earliest a game can be part of this bracket: the bracket's start,
     * with a few hours' grace for a field set on game day.
     */
    private function since(Tournament $tournament): Carbon
    {
        return ($tournament->started_at ?? Carbon::now())->copy()->subHours(12);
    }

    /**
     * An organiser links a match to a game by hand: only a game between the
     * match's own two teams, played since the bracket was set, and it
     * settles only this match.
     */
    public function link(Fixture $match, int $gameId): bool
    {
        $tournament = $match->tournament;
        $teams = Entrant::query()->whereIn('id', [$match->entrant1_id, $match->entrant2_id])->pluck('picks_team_id')->filter()->map(fn ($id) => (int) $id)->sort()->values()->all();
        $game = $this->db->table('picks_events')->where('id', $gameId)->first(['home_team_id', 'away_team_id', 'match_date']);

        if (! $game || count($teams) !== 2) {
            return false;
        }

        $pair = [(int) $game->home_team_id, (int) $game->away_team_id];
        sort($pair);
        if ($pair !== $teams || ($game->match_date && Carbon::parse($game->match_date)->lt($this->since($tournament)))) {
            return false;
        }

        $match->picks_event_id = $gameId;
        $match->save();
        $this->settleFromGame($gameId, $match->id);

        return true;
    }

    private function logoUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        if (Str::startsWith($path, ['http://', 'https://'])) {
            return $path;
        }

        return rtrim((string) $this->settings->get('url'), '/').'/'.ltrim($path, '/');
    }
}
