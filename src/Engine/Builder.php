<?php

namespace ErnestDefoe\Gauntlet\Engine;

/**
 * Builds every match a format needs, before a ball is kicked.
 *
 * A match is a plain array: bracket, round, position, the two entrants (or
 * null), whether a slot is dead — a bye, which will never be filled — and
 * where the winner and the loser go. Elimination brackets are built at a power
 * of two with the missing seeds as dead slots, so byes are just matches whose
 * other side never arrives; {@see Advancer} plays them through.
 *
 * Nothing here touches the database, so every shape can be tested exactly.
 */
final class Builder
{
    public const WINNERS = 'W';
    public const LOSERS = 'L';
    public const FINAL = 'F';
    public const THIRD = 'P';
    public const ROUND_ROBIN = 'R';
    public const SWISS = 'S';

    public static function key(string $bracket, int $round, int $position): string
    {
        return $bracket.':'.$round.':'.$position;
    }

    /**
     * The order seeds sit in the first round, so 1 and 2 can only meet in the
     * final: 8 → 1, 8, 4, 5, 2, 7, 3, 6.
     *
     * @return list<int>
     */
    public static function seedOrder(int $size): array
    {
        $order = [1];

        while (count($order) < $size) {
            $double = count($order) * 2;
            $next = [];
            foreach ($order as $seed) {
                $next[] = $seed;
                $next[] = $double + 1 - $seed;
            }
            $order = $next;
        }

        return $order;
    }

    public static function bracketSize(int $entrants): int
    {
        $size = 2;
        while ($size < $entrants) {
            $size *= 2;
        }

        return $size;
    }

    /** @return int number of winners-bracket rounds */
    public static function rounds(int $entrants): int
    {
        return (int) log(self::bracketSize($entrants), 2);
    }

    /**
     * @param list<int> $entrants entrant ids in seed order (best first)
     * @return array<string, array<string, mixed>>
     */
    public static function singleElimination(array $entrants, bool $thirdPlace = false): array
    {
        $size = self::bracketSize(count($entrants));
        $rounds = (int) log($size, 2);
        $matches = self::winnersBracket($entrants, $size);

        foreach ($matches as $key => &$match) {
            if ($match['round'] < $rounds) {
                $match['next'] = new Slot(self::WINNERS, $match['round'] + 1, intdiv($match['position'] + 1, 2), $match['position'] % 2 === 1 ? 1 : 2);
            }
        }
        unset($match);

        if ($thirdPlace && $rounds >= 2) {
            $matches[self::key(self::THIRD, $rounds, 1)] = self::blank(self::THIRD, $rounds, 1);
            foreach ([1, 2] as $position) {
                $matches[self::key(self::WINNERS, $rounds - 1, $position)]['loser'] = new Slot(self::THIRD, $rounds, 1, $position);
            }
        }

        return $matches;
    }

    /**
     * Winners bracket, losers bracket (2·(k−1) rounds, alternating a round
     * where the losers' winners meet the winners bracket's new losers with a
     * round where they meet each other), and a grand final, with an optional
     * reset match if the losers-bracket side wins the first.
     *
     * @param list<int> $entrants
     * @return array<string, array<string, mixed>>
     */
    public static function doubleElimination(array $entrants, bool $reset = true): array
    {
        $size = self::bracketSize(count($entrants));
        $k = (int) log($size, 2);
        $matches = self::winnersBracket($entrants, $size);
        $loserRounds = 2 * ($k - 1);

        for ($r = 1; $r <= $k; $r++) {
            $count = $size >> $r;
            for ($p = 1; $p <= $count; $p++) {
                $match = &$matches[self::key(self::WINNERS, $r, $p)];

                $match['next'] = $r < $k
                    ? new Slot(self::WINNERS, $r + 1, intdiv($p + 1, 2), $p % 2 === 1 ? 1 : 2)
                    : new Slot(self::FINAL, 1, 1, 1);

                if ($k === 1) {
                    $match['loser'] = new Slot(self::FINAL, 1, 1, 2);
                } elseif ($r === 1) {
                    // Pairs of first-round losers meet in losers round 1.
                    $match['loser'] = new Slot(self::LOSERS, 1, intdiv($p + 1, 2), $p % 2 === 1 ? 1 : 2);
                } else {
                    // Losers of winners round r drop into losers round 2(r−1),
                    // in reverse order every other round, so a pair that just
                    // met does not meet again straight away.
                    $target = ($r % 2 === 0) ? $count + 1 - $p : $p;
                    $match['loser'] = new Slot(self::LOSERS, 2 * ($r - 1), $target, 2);
                }
                unset($match);
            }
        }

        for ($r = 1; $r <= $loserRounds; $r++) {
            // Rounds 1 and 2 have size/4 matches, 3 and 4 size/8, and so on.
            $count = $size >> (intdiv($r + 1, 2) + 1);
            for ($p = 1; $p <= $count; $p++) {
                $match = self::blank(self::LOSERS, $r, $p);

                if ($r === $loserRounds) {
                    $match['next'] = new Slot(self::FINAL, 1, 1, 2);
                } elseif ($r % 2 === 1) {
                    // Odd rounds feed the drop-in round straight across.
                    $match['next'] = new Slot(self::LOSERS, $r + 1, $p, 1);
                } else {
                    $match['next'] = new Slot(self::LOSERS, $r + 1, intdiv($p + 1, 2), $p % 2 === 1 ? 1 : 2);
                }

                $matches[self::key(self::LOSERS, $r, $p)] = $match;
            }
        }

        $matches[self::key(self::FINAL, 1, 1)] = self::blank(self::FINAL, 1, 1);

        if ($reset) {
            // Filled by Advancer only if the losers-bracket side wins match 1.
            $matches[self::key(self::FINAL, 2, 1)] = self::blank(self::FINAL, 2, 1);
        }

        return $matches;
    }

    /**
     * Everyone plays everyone once (the circle method). With an odd number,
     * one entrant sits out each round.
     *
     * @param list<int> $entrants
     * @return array<string, array<string, mixed>>
     */
    public static function roundRobin(array $entrants): array
    {
        $ring = array_values($entrants);
        if (count($ring) % 2 === 1) {
            $ring[] = null;
        }

        $n = count($ring);
        $matches = [];

        for ($r = 1; $r < $n; $r++) {
            $position = 0;
            for ($i = 0; $i < $n / 2; $i++) {
                $a = $ring[$i];
                $b = $ring[$n - 1 - $i];

                if ($a !== null && $b !== null) {
                    // Alternate who is listed first, as home and away would be.
                    [$a, $b] = ($r % 2 === 0 && $i === 0) ? [$b, $a] : [$a, $b];
                    $match = self::blank(self::ROUND_ROBIN, $r, ++$position);
                    $match['entrant1'] = $a;
                    $match['entrant2'] = $b;
                    $matches[self::key(self::ROUND_ROBIN, $r, $position)] = $match;
                }
            }

            // Keep the first fixed and rotate the rest one place.
            $last = array_pop($ring);
            array_splice($ring, 1, 0, [$last]);
        }

        return $matches;
    }

    /**
     * @param list<int> $entrants
     * @return array<string, array<string, mixed>>
     */
    private static function winnersBracket(array $entrants, int $size): array
    {
        $order = self::seedOrder($size);
        $matches = [];

        for ($p = 1; $p <= $size / 2; $p++) {
            $match = self::blank(self::WINNERS, 1, $p);
            foreach ([1, 2] as $side) {
                $seed = $order[($p - 1) * 2 + $side - 1];
                $match['entrant'.$side] = $entrants[$seed - 1] ?? null;
                $match['dead'.$side] = ! isset($entrants[$seed - 1]);
            }
            $matches[self::key(self::WINNERS, 1, $p)] = $match;
        }

        for ($r = 2, $count = $size / 4; $count >= 1; $r++, $count /= 2) {
            for ($p = 1; $p <= $count; $p++) {
                $matches[self::key(self::WINNERS, $r, $p)] = self::blank(self::WINNERS, $r, $p);
            }
        }

        return $matches;
    }

    /** @return array<string, mixed> */
    public static function blank(string $bracket, int $round, int $position): array
    {
        return [
            'bracket' => $bracket,
            'round' => $round,
            'position' => $position,
            'entrant1' => null,
            'entrant2' => null,
            'dead1' => false,
            'dead2' => false,
            'next' => null,
            'loser' => null,
        ];
    }

    /**
     * The specs as unsaved matches, keyed like the specs.
     *
     * @param array<string, array<string, mixed>> $specs
     * @return array<string, \ErnestDefoe\Gauntlet\Fixture>
     */
    public static function fixtures(array $specs, int $bestOf = 1): array
    {
        $fixtures = [];

        foreach ($specs as $key => $spec) {
            $fixture = new \ErnestDefoe\Gauntlet\Fixture();
            $fixture->bracket = $spec['bracket'];
            $fixture->round = $spec['round'];
            $fixture->position = $spec['position'];
            $fixture->entrant1_id = $spec['entrant1'];
            $fixture->entrant2_id = $spec['entrant2'];
            $fixture->dead1 = $spec['dead1'];
            $fixture->dead2 = $spec['dead2'];
            $fixture->best_of = $bestOf;
            $fixture->status = \ErnestDefoe\Gauntlet\Fixture::PENDING;

            foreach (['next' => 'next', 'loser' => 'loser'] as $route => $prefix) {
                /** @var Slot|null $slot */
                $slot = $spec[$route];
                $fixture->{$prefix.'_bracket'} = $slot?->bracket;
                $fixture->{$prefix.'_round'} = $slot?->round;
                $fixture->{$prefix.'_position'} = $slot?->position;
                $fixture->{$prefix.'_slot'} = $slot?->slot;
            }

            $fixtures[$key] = $fixture;
        }

        return $fixtures;
    }
}
