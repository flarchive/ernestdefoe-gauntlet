<?php

namespace ErnestDefoe\Gauntlet\Tests\unit;

use ErnestDefoe\Gauntlet\Engine\Advancer;
use ErnestDefoe\Gauntlet\Engine\Builder;
use ErnestDefoe\Gauntlet\Engine\Standings;
use ErnestDefoe\Gauntlet\Fixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Plays whole brackets of every size through with random results and checks
 * the invariants: nobody plays themselves, nobody is knocked out before they
 * should be, the right number of games is played, and it ends.
 */
class EliminationTest extends TestCase
{
    public function test_seed_order_keeps_the_top_seeds_apart(): void
    {
        $this->assertSame([1, 8, 4, 5, 2, 7, 3, 6], Builder::seedOrder(8));
        $this->assertSame([1, 4, 2, 3], Builder::seedOrder(4));
    }

    /** @return iterable<array{int}> */
    public static function sizes(): iterable
    {
        foreach ([2, 3, 4, 5, 6, 7, 8, 9, 12, 13, 16, 17, 24, 31, 32, 33, 64] as $n) {
            yield "$n entrants" => [$n];
        }
    }

    #[DataProvider('sizes')]
    public function test_single_elimination_plays_n_minus_one_games(int $n): void
    {
        foreach ([false, true] as $third) {
            for ($run = 0; $run < 5; $run++) {
                [$played, $matches, $losses] = $this->play(Builder::singleElimination(range(1, $n), $third), $n);

                $this->assertSame($n - 1 + ($third && $n >= 4 ? 1 : 0), $played);
                $this->assertSame(1, count(array_filter($losses, fn ($l) => $l === 0)), 'one unbeaten champion');

                $places = Standings::eliminationPlaces(range(1, $n), $matches);
                $this->assertSame(1, count(array_filter($places, fn ($p) => $p === 1)));
                $this->assertSame(1, count(array_filter($places, fn ($p) => $p === 2)));
                if ($third && $n >= 4) {
                    $this->assertSame(1, count(array_filter($places, fn ($p) => $p === 3)));
                    $this->assertSame(1, count(array_filter($places, fn ($p) => $p === 4)));
                }
            }
        }
    }

    #[DataProvider('sizes')]
    public function test_double_elimination_needs_two_losses(int $n): void
    {
        foreach ([false, true] as $reset) {
            for ($run = 0; $run < 5; $run++) {
                [$played, $matches, $losses, $resetPlayed] = $this->play(Builder::doubleElimination(range(1, $n), $reset), $n);

                // Everyone but the champion loses twice — except, with no
                // reset match, a winners-bracket champion beaten in the final.
                $final = $resetPlayed ? $matches['F:2:1'] : $matches['F:1:1'];
                $champion = $final->winner_id;
                $survivors = array_keys(array_filter($losses, fn ($l) => $l < 2));
                $this->assertContains($champion, $survivors);
                $this->assertLessThanOrEqual($reset ? 1 : 2, count($survivors));
                $this->assertSame(2 * $n - 2 + ($resetPlayed ? 1 : 0), $played);

                $places = Standings::eliminationPlaces(range(1, $n), $matches, true);
                $this->assertSame(1, $places[$champion]);
                $this->assertSame(1, count(array_filter($places, fn ($p) => $p === 2)));
                if ($n >= 3) {
                    $this->assertSame(1, count(array_filter($places, fn ($p) => $p === 3)));
                }
            }
        }
    }

    public function test_top_seed_gets_the_bye_in_a_field_of_three(): void
    {
        $fixtures = Builder::fixtures(Builder::singleElimination([10, 20, 30]));
        $advancer = new Advancer($fixtures);
        $advancer->start();

        $this->assertSame(Fixture::BYE, $fixtures['W:1:1']->status);
        $this->assertSame(10, $fixtures['W:1:1']->winner_id);
        $this->assertSame(10, $fixtures['W:2:1']->entrant1_id);
        $this->assertSame(Fixture::OPEN, $fixtures['W:1:2']->status);
        $this->assertSame([20, 30], [$fixtures['W:1:2']->entrant1_id, $fixtures['W:1:2']->entrant2_id]);
    }

    public function test_cfp_style_twelve_team_field(): void
    {
        $fixtures = Builder::fixtures(Builder::singleElimination(range(1, 12)));
        (new Advancer($fixtures))->start();

        // Seeds 1–4 have byes; 8 v 9, 5 v 12, 6 v 11, 7 v 10 play in round one.
        $round1 = [];
        foreach ($fixtures as $f) {
            if ($f->bracket === 'W' && $f->round === 1 && $f->status === Fixture::OPEN) {
                $round1[] = [$f->entrant1_id, $f->entrant2_id];
            }
        }
        $this->assertSame([[8, 9], [5, 12], [7, 10], [6, 11]], $round1);
        $this->assertSame(1, $fixtures['W:2:1']->entrant1_id);
        $this->assertSame(4, $fixtures['W:2:2']->entrant1_id);
    }

    public function test_a_result_can_be_corrected_until_the_next_match_is_reported(): void
    {
        $fixtures = Builder::fixtures(Builder::singleElimination([1, 2, 3, 4]));
        $advancer = new Advancer($fixtures);
        $advancer->start();

        $advancer->complete($fixtures['W:1:1'], 1);
        $this->assertTrue($advancer->correctable($fixtures['W:1:1']));

        $advancer->reopen($fixtures['W:1:1']);
        $this->assertNull($fixtures['W:2:1']->entrant1_id);
        $advancer->complete($fixtures['W:1:1'], 4);
        $this->assertSame(4, $fixtures['W:2:1']->entrant1_id);

        $advancer->complete($fixtures['W:1:2'], 2);
        $this->assertSame(Fixture::OPEN, $fixtures['W:2:1']->status);
        $fixtures['W:2:1']->report1_score1 = 1;
        $fixtures['W:2:1']->report1_score2 = 0;
        $this->assertFalse($advancer->correctable($fixtures['W:1:1']));
    }

    public function test_grand_final_reset_only_when_the_losers_side_wins(): void
    {
        foreach ([1 => false, 2 => true] as $winnerSide => $resetPlays) {
            $fixtures = Builder::fixtures(Builder::doubleElimination([1, 2]));
            $advancer = new Advancer($fixtures);
            $advancer->start();
            $advancer->complete($fixtures['W:1:1'], 1);
            $this->assertSame(Fixture::OPEN, $fixtures['F:1:1']->status);
            $this->assertSame([1, 2], [$fixtures['F:1:1']->entrant1_id, $fixtures['F:1:1']->entrant2_id]);

            $advancer->complete($fixtures['F:1:1'], $winnerSide);
            $this->assertSame($resetPlays ? Fixture::OPEN : Fixture::VOID, $fixtures['F:2:1']->status);
            $this->assertSame(! $resetPlays, $advancer->finished());
        }
    }

    /**
     * @param array<string, array<string, mixed>> $specs
     * @return array{int, array<string, Fixture>, array<int, int>, bool}
     */
    private function play(array $specs, int $n): array
    {
        $fixtures = Builder::fixtures($specs);
        $advancer = new Advancer($fixtures);
        $advancer->start();

        $losses = array_fill(1, $n, 0);
        $played = 0;
        $resetPlayed = false;

        for ($guard = 0; $guard < 10 * $n && ! $advancer->finished(); $guard++) {
            $open = array_values(array_filter($fixtures, fn (Fixture $f) => $f->status === Fixture::OPEN));
            $this->assertNotEmpty($open, 'a bracket that is not finished has a match to play');

            foreach ($open as $match) {
                $this->assertNotSame($match->entrant1_id, $match->entrant2_id);
                $this->assertLessThan(2, $losses[$match->entrant1_id], 'nobody plays on after two losses');
                $this->assertLessThan(2, $losses[$match->entrant2_id]);

                $winner = random_int(0, 1) ? $match->entrant1_id : $match->entrant2_id;
                $loser = $winner === $match->entrant1_id ? $match->entrant2_id : $match->entrant1_id;
                $losses[$loser]++;
                $played++;
                $resetPlayed = $resetPlayed || $match->key() === 'F:2:1';
                $advancer->complete($match, $winner);
            }
        }

        $this->assertTrue($advancer->finished());

        return [$played, $fixtures, $losses, $resetPlayed];
    }
}
