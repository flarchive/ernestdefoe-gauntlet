<?php

namespace ErnestDefoe\Gauntlet\Integration\PlayoffBoard;

use Flarum\Extension\ExtensionManager;
use Illuminate\Database\ConnectionInterface;

/**
 * A College Football Playoff field from the Playoff Board, when it is on.
 *
 * The board already rebuilds every week from ESPN with each contender's
 * playoff odds; this reads its latest stored week and nothing else, so a
 * prediction bracket starts from the same numbers the forum's board shows,
 * without fetching anything again. Its teams are matched to Picks' teams by
 * their ESPN id, so the bracket gets Picks' logos and live games.
 *
 * It is a projection: twelve teams by playoff odds, seeded by them, keeping
 * one place for the best team outside the Power Four as the playoff does.
 * The organiser checks the seeds against the committee's before starting.
 *
 * 🚨 Read by table name: Gauntlet loads the same without the Playoff Board.
 */
class PlayoffBoardBridge
{
    public const EXTENSION = 'ernestdefoe-playoff-board';

    public const FIELD = 12;

    public function __construct(private ExtensionManager $extensions, private ConnectionInterface $db)
    {
    }

    public function available(): bool
    {
        return $this->extensions->isEnabled(self::EXTENSION);
    }

    /**
     * The projected field: the best playoff odds, keeping a place for the
     * best team outside the Power Four if none made it on odds.
     *
     * @param array<mixed> $cards the board's cards
     * @return list<array<string, mixed>>
     */
    public static function project(array $cards, int $size = self::FIELD): array
    {
        $cards = array_values(array_filter($cards, fn ($c) => is_array($c) && isset($c['id'], $c['name'])));
        usort($cards, fn ($a, $b) => (float) ($b['playoff'] ?? 0) <=> (float) ($a['playoff'] ?? 0));

        $field = array_slice($cards, 0, $size);
        if ($field && ! array_filter($field, fn ($c) => empty($c['p4']))) {
            $outside = current(array_filter($cards, fn ($c) => empty($c['p4'])));
            if ($outside) {
                $field[count($field) - 1] = $outside;
            }
        }

        return $field;
    }

    /**
     * @return array{season: int, week: int, teams: list<array{name: string, logo: string|null, espnId: int, playoff: float, picksTeamId: int|null}>}|null
     */
    public function field(int $size = self::FIELD): ?array
    {
        if (! $this->available()) {
            return null;
        }

        $row = $this->db->table('playoff_board_snapshots')->orderByDesc('season')->orderByDesc('week')->first(['season', 'week', 'payload']);
        $board = $row ? json_decode((string) $row->payload, true) : null;
        if (! is_array($board) || empty($board['cards']) || ! is_array($board['cards'])) {
            return null;
        }

        $field = self::project($board['cards'], $size);

        $ids = array_map(fn ($c) => (int) $c['id'], $field);
        $picks = $this->picksTeams($ids);

        return [
            'season' => (int) $row->season,
            'week' => (int) $row->week,
            'teams' => array_map(fn ($c) => [
                'name' => mb_substr((string) $c['name'], 0, 100),
                'logo' => isset($c['logo']) && is_string($c['logo']) && str_starts_with($c['logo'], 'https://') ? $c['logo'] : null,
                'espnId' => (int) $c['id'],
                'playoff' => (float) ($c['playoff'] ?? 0),
                'picksTeamId' => isset($picks[(int) $c['id']]) ? (int) $picks[(int) $c['id']] : null,
            ], $field),
        ];
    }

    /**
     * Picks' teams for these ESPN ids. CollegeFootballData's team ids are
     * ESPN's, so a team synced from either provider matches.
     *
     * @param list<int> $espnIds
     * @return array<int, int> ESPN id => Picks team id
     */
    public function picksTeams(array $espnIds): array
    {
        if (! $espnIds || ! $this->extensions->isEnabled('ernestdefoe-picks')) {
            return [];
        }

        $picks = [];
        foreach ($this->db->table('picks_teams')->whereIn('espn_id', $espnIds)->orWhereIn('cfbd_id', $espnIds)->orderBy('id')->get(['id', 'espn_id', 'cfbd_id']) as $team) {
            foreach ([$team->espn_id, $team->cfbd_id] as $id) {
                if ($id !== null && in_array((int) $id, $espnIds, true)) {
                    $picks[(int) $id] ??= (int) $team->id;
                }
            }
        }

        return $picks;
    }
}
