<?php

namespace ErnestDefoe\Gauntlet\Tests\unit;

use ErnestDefoe\Gauntlet\Engine\Builder;
use ErnestDefoe\Gauntlet\Engine\Standings;
use ErnestDefoe\Gauntlet\Engine\Swiss;
use ErnestDefoe\Gauntlet\Fixture;
use PHPUnit\Framework\TestCase;

class TableFormatsTest extends TestCase
{
    public function test_round_robin_pairs_everyone_exactly_once(): void
    {
        foreach (range(2, 15) as $n) {
            $matches = Builder::roundRobin(range(1, $n));
            $pairs = [];
            $perRound = [];
            foreach ($matches as $m) {
                $key = Swiss::pairKey($m['entrant1'], $m['entrant2']);
                $this->assertArrayNotHasKey($key, $pairs, "$n: $key twice");
                $pairs[$key] = true;
                foreach ([$m['entrant1'], $m['entrant2']] as $e) {
                    $this->assertArrayNotHasKey($e, $perRound[$m['round']] ?? [], "$n: $e twice in round {$m['round']}");
                    $perRound[$m['round']][$e] = true;
                }
            }
            $this->assertCount($n * ($n - 1) / 2, $pairs);
            $this->assertCount($n % 2 ? $n : $n - 1, $perRound);
        }
    }

    public function test_swiss_avoids_rematches_and_gives_one_bye_each(): void
    {
        foreach ([4, 5, 7, 8, 9, 16, 21] as $n) {
            $played = [];
            $hadBye = [];
            $points = array_fill(1, $n, 0);
            $rounds = (int) ceil(log($n, 2));

            for ($r = 1; $r <= $rounds; $r++) {
                $standing = range(1, $n);
                usort($standing, fn ($a, $b) => [$points[$b], $a] <=> [$points[$a], $b]);
                $round = Swiss::pair($standing, $played, $hadBye);

                if ($n % 2) {
                    $this->assertNotNull($round['bye']);
                    $this->assertArrayNotHasKey($round['bye'], $hadBye, 'one bye each');
                    $hadBye[$round['bye']] = true;
                    $points[$round['bye']] += 2;
                }
                $this->assertCount(intdiv($n, 2), $round['pairs']);

                foreach ($round['pairs'] as [$a, $b]) {
                    $this->assertArrayNotHasKey(Swiss::pairKey($a, $b), $played, "$n players, round $r: rematch");
                    $played[Swiss::pairKey($a, $b)] = true;
                    $points[random_int(0, 1) ? $a : $b] += 2;
                }
            }
        }
    }

    public function test_table_orders_by_points_then_tiebreak(): void
    {
        $m = function (int $a, int $b, ?int $winner, int $s1, int $s2): Fixture {
            $f = new Fixture();
            $f->entrant1_id = $a;
            $f->entrant2_id = $b;
            $f->winner_id = $winner;
            $f->score1 = $s1;
            $f->score2 = $s2;
            $f->status = Fixture::COMPLETED;

            return $f;
        };

        // 1 beats 2 and 3; 2 beats 3; 3 draws nobody.
        $table = Standings::table([1 => 1, 2 => 2, 3 => 3], [$m(1, 2, 1, 3, 1), $m(1, 3, 1, 2, 0), $m(2, 3, 2, 1, 0)], 'round_robin');
        $this->assertSame([1, 2, 3], array_column($table, 'entrant'));
        $this->assertSame([4, 2, 0], array_column($table, 'points'));
        $this->assertSame([1, 2, 3], array_column($table, 'rank'));

        // Two draws: equal on everything, so equal rank.
        $table = Standings::table([1 => 1, 2 => 2], [$m(1, 2, null, 1, 1)], 'swiss');
        $this->assertSame([1, 1], array_column($table, 'rank'));
        $this->assertSame([1, 1], array_column($table, 'draws'));
    }
}
