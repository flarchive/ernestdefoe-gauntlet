<?php

namespace ErnestDefoe\Gauntlet\Engine;

use ErnestDefoe\Gauntlet\Fixture;

/**
 * The table for round robin and Swiss, and final places for every format.
 *
 * Points are kept in halves (a win is 2, a draw 1) so nothing is a float.
 * Ties break on Buchholz (Swiss: the sum of your opponents' points) or
 * Sonneborn–Berger (round robin: the points of the opponents you beat, and
 * half of those you drew), then score difference, then seed.
 */
final class Standings
{
    /**
     * @param array<int, int> $seeds entrant id => seed (lower is better)
     * @param iterable<Fixture> $matches
     * @return list<array{entrant: int, played: int, wins: int, draws: int, losses: int, points: int, tiebreak: int, for: int, against: int, rank: int}>
     */
    public static function table(array $seeds, iterable $matches, string $format): array
    {
        $rows = [];
        foreach ($seeds as $id => $seed) {
            $rows[$id] = ['entrant' => $id, 'played' => 0, 'wins' => 0, 'draws' => 0, 'losses' => 0, 'points' => 0, 'tiebreak' => 0, 'for' => 0, 'against' => 0, 'rank' => 0];
        }

        $results = [];
        foreach ($matches as $match) {
            if ($match->status === Fixture::BYE && $match->winner_id && isset($rows[$match->winner_id])) {
                // A Swiss bye counts as a win, but it is not a game played.
                $rows[$match->winner_id]['wins']++;
                $rows[$match->winner_id]['points'] += 2;
                continue;
            }

            if ($match->status !== Fixture::COMPLETED || ! isset($rows[$match->entrant1_id], $rows[$match->entrant2_id])) {
                continue;
            }

            $a = (int) $match->entrant1_id;
            $b = (int) $match->entrant2_id;
            $results[] = [$a, $b, $match->winner_id === null ? 1 : ($match->winner_id === $a ? 2 : 0)];

            foreach ([[$a, $b, (int) $match->score1, (int) $match->score2], [$b, $a, (int) $match->score2, (int) $match->score1]] as [$me, , $mine, $theirs]) {
                $rows[$me]['played']++;
                $rows[$me]['for'] += $mine;
                $rows[$me]['against'] += $theirs;
                if ($match->winner_id === null) {
                    $rows[$me]['draws']++;
                    $rows[$me]['points'] += 1;
                } elseif ($match->winner_id === $me) {
                    $rows[$me]['wins']++;
                    $rows[$me]['points'] += 2;
                } else {
                    $rows[$me]['losses']++;
                }
            }
        }

        foreach ($results as [$a, $b, $aGot]) {
            if ($format === 'swiss') {
                $rows[$a]['tiebreak'] += $rows[$b]['points'];
                $rows[$b]['tiebreak'] += $rows[$a]['points'];
            } else {
                // In halves of halves: a beaten opponent's points count twice, a drawn one's once.
                $rows[$a]['tiebreak'] += $rows[$b]['points'] * $aGot;
                $rows[$b]['tiebreak'] += $rows[$a]['points'] * (2 - $aGot);
            }
        }

        $rows = array_values($rows);
        usort($rows, fn (array $x, array $y) => [$y['points'], $y['tiebreak'], $y['for'] - $y['against'], $seeds[$x['entrant']]]
            <=> [$x['points'], $x['tiebreak'], $x['for'] - $x['against'], $seeds[$y['entrant']]]);

        $previous = null;
        foreach ($rows as $i => &$row) {
            $signature = [$row['points'], $row['tiebreak'], $row['for'] - $row['against']];
            $row['rank'] = $signature === $previous ? $rows[$i - 1]['rank'] : $i + 1;
            $previous = $signature;
        }

        return $rows;
    }

    /**
     * Final places in an elimination bracket: by how far each entrant got.
     * Entrants knocked out in the same round share a place (both losing
     * semi-finalists are 3rd unless a third-place match was played).
     *
     * In double elimination a winners-bracket loss knocks nobody out, so
     * only the losers bracket and the grand final decide places there.
     *
     * @param list<int> $entrants
     * @param iterable<Fixture> $matches
     * @return array<int, int> entrant id => place
     */
    public static function eliminationPlaces(array $entrants, iterable $matches, bool $double = false): array
    {
        $reach = array_fill_keys($entrants, 0);

        foreach ($matches as $match) {
            if ($match->status !== Fixture::COMPLETED || ! $match->winner_id) {
                continue;
            }

            $loser = $match->winner_id === $match->entrant1_id ? $match->entrant2_id : $match->entrant1_id;
            $depth = self::depth($match, $double);

            // A loser's reach is the deepest match they lost.
            if ($loser !== null && isset($reach[$loser])) {
                $reach[$loser] = max($reach[$loser], $depth);
            }

            if (isset($reach[$match->winner_id])) {
                $reach[$match->winner_id] = max($reach[$match->winner_id], $depth + 1);
            }
        }

        $places = [];
        foreach ($reach as $id => $value) {
            $better = 0;
            foreach ($reach as $other) {
                if ($other > $value) {
                    $better++;
                }
            }
            $places[$id] = $better + 1;
        }

        return $places;
    }

    /**
     * How deep in the bracket a match is. Winners round r is 2r (so a third
     * place match, 2k−1, sits between the semi-finals and the final); in
     * double elimination it counts for nothing, and losers round r is r; a
     * grand final beats everything.
     */
    private static function depth(Fixture $match, bool $double): int
    {
        return match ($match->bracket) {
            Builder::WINNERS => $double ? 0 : 2 * (int) $match->round * 100,
            Builder::THIRD => (2 * (int) $match->round - 1) * 100,
            Builder::LOSERS => (int) $match->round,
            Builder::FINAL => 1000000 + (int) $match->round,
            default => 0,
        };
    }
}
