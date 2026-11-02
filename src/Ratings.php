<?php

namespace ErnestDefoe\Gauntlet;

use Carbon\Carbon;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;

/**
 * Elo ratings per game, for seeding by rating and for profiles.
 *
 * A solo entry rates its player; a Social Groups team rates the group; an
 * ad-hoc team or a staff-entered field is not rated (there is nobody stable
 * to rate). Each result's change is stored on the match, so a corrected or
 * reset result takes back exactly what it gave.
 */
class Ratings
{
    public const START = 1500;

    private const K = 32;

    public function __construct(private ConnectionInterface $db)
    {
    }

    /** @return array{0: string, 1: int}|null */
    public function subject(Tournament $tournament, Entrant $entrant): ?array
    {
        if ($tournament->isStaffEntered()) {
            return null;
        }

        if ($tournament->isTeam()) {
            return $entrant->group_id ? ['group', (int) $entrant->group_id] : null;
        }

        return $entrant->user_id ? ['user', (int) $entrant->user_id] : null;
    }

    /**
     * Each entrant's current rating, in one query.
     *
     * @param Collection<int, Entrant> $entrants
     * @return array<int, int> entrant id => rating
     */
    public function forEntrants(Tournament $tournament, Collection $entrants): array
    {
        $subjects = [];
        foreach ($entrants as $entrant) {
            if ($subject = $this->subject($tournament, $entrant)) {
                $subjects[$entrant->id] = $subject;
            }
        }

        $known = [];
        if ($subjects) {
            $rows = $this->db->table('gauntlet_ratings')
                ->where('game', $tournament->gameKey())
                ->where(function ($q) use ($subjects) {
                    foreach (['user', 'group'] as $type) {
                        $ids = array_map(fn ($s) => $s[1], array_filter($subjects, fn ($s) => $s[0] === $type));
                        if ($ids) {
                            $q->orWhere(fn ($q) => $q->where('subject_type', $type)->whereIn('subject_id', array_values($ids)));
                        }
                    }
                })
                ->get(['subject_type', 'subject_id', 'rating']);

            foreach ($rows as $row) {
                $known[$row->subject_type.':'.$row->subject_id] = (int) $row->rating;
            }
        }

        $ratings = [];
        foreach ($entrants as $entrant) {
            $subject = $subjects[$entrant->id] ?? null;
            $ratings[$entrant->id] = $subject ? ($known[$subject[0].':'.$subject[1]] ?? self::START) : self::START;
        }

        return $ratings;
    }

    /** Rate a played result and remember the change on the match. */
    public function apply(Tournament $tournament, Fixture $match, Entrant $one, Entrant $two): void
    {
        $a = $this->subject($tournament, $one);
        $b = $this->subject($tournament, $two);

        if (! $a || ! $b || $a === $b) {
            return;
        }

        $game = $tournament->gameKey();
        $ra = $this->current($a, $game);
        $rb = $this->current($b, $game);

        $expected = 1 / (1 + 10 ** (($rb - $ra) / 400));
        $actual = $match->winner_id === null ? 0.5 : ($match->winner_id === $one->id ? 1.0 : 0.0);
        $delta = (int) round(self::K * ($actual - $expected));

        $match->rating_delta1 = $delta;
        $match->rating_delta2 = -$delta;

        $this->bump($a, $game, $delta, $actual === 1.0 ? 'wins' : ($actual === 0.0 ? 'losses' : 'draws'), 1, trim($tournament->game));
        $this->bump($b, $game, -$delta, $actual === 0.0 ? 'wins' : ($actual === 1.0 ? 'losses' : 'draws'), 1, trim($tournament->game));
    }

    /** Take back what a result gave. */
    public function revert(Tournament $tournament, Fixture $match, Entrant $one, Entrant $two): void
    {
        if ($match->rating_delta1 === null) {
            return;
        }

        $a = $this->subject($tournament, $one);
        $b = $this->subject($tournament, $two);

        if ($a && $b) {
            $game = $tournament->gameKey();
            $won = $match->winner_id === null ? null : $match->winner_id === $one->id;
            $this->bump($a, $game, -$match->rating_delta1, $won === null ? 'draws' : ($won ? 'wins' : 'losses'), -1);
            $this->bump($b, $game, -(int) $match->rating_delta2, $won === null ? 'draws' : ($won ? 'losses' : 'wins'), -1);
        }

        $match->rating_delta1 = null;
        $match->rating_delta2 = null;
    }

    /** @param array{0: string, 1: int} $subject */
    private function current(array $subject, string $game): int
    {
        $rating = $this->db->table('gauntlet_ratings')
            ->where(['subject_type' => $subject[0], 'subject_id' => $subject[1], 'game' => $game])
            ->value('rating');

        return $rating === null ? self::START : (int) $rating;
    }

    /** @param array{0: string, 1: int} $subject */
    private function bump(array $subject, string $game, int $delta, string $column, int $count, ?string $label = null): void
    {
        $key = ['subject_type' => $subject[0], 'subject_id' => $subject[1], 'game' => $game];
        $now = Carbon::now();

        if (! $this->db->table('gauntlet_ratings')->where($key)->exists()) {
            // Ignored if a result in another tournament created it a moment ago.
            $this->db->table('gauntlet_ratings')->insertOrIgnore($key + ['rating' => self::START, 'game_label' => (string) $label, 'created_at' => $now, 'updated_at' => $now]);
        }

        $this->db->table('gauntlet_ratings')->where($key)->update(($label !== null ? ['game_label' => $label] : []) + [
            'rating' => $this->db->raw($this->db->getQueryGrammar()->wrap('rating').' + '.(int) $delta),
            'played' => $this->db->raw($this->db->getQueryGrammar()->wrap('played').' + '.$count),
            $column => $this->db->raw($this->db->getQueryGrammar()->wrap($column).' + '.$count),
            'updated_at' => $now,
        ]);
    }
}
