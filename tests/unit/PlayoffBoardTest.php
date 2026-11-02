<?php

namespace ErnestDefoe\Gauntlet\Tests\unit;

use ErnestDefoe\Gauntlet\Integration\PlayoffBoard\PlayoffBoardBridge;
use PHPUnit\Framework\TestCase;

class PlayoffBoardTest extends TestCase
{
    /** @return array<int, array<string, mixed>> */
    private function cards(int $n, bool $withGroupOfFive): array
    {
        $cards = [];
        for ($i = 1; $i <= $n; $i++) {
            $cards[1000 + $i] = ['id' => 1000 + $i, 'name' => "Team $i", 'playoff' => 100 - $i * 3, 'p4' => true];
        }
        if ($withGroupOfFive) {
            $cards[77] = ['id' => 77, 'name' => 'Boise State', 'playoff' => 4.5, 'p4' => false];
        }

        return $cards;
    }

    public function test_the_best_odds_make_the_field_in_order(): void
    {
        $field = PlayoffBoardBridge::project($this->cards(20, false));
        $this->assertCount(12, $field);
        $this->assertSame('Team 1', $field[0]['name']);
        $this->assertSame('Team 12', $field[11]['name']);
    }

    public function test_one_place_is_kept_for_a_team_outside_the_power_four(): void
    {
        $field = PlayoffBoardBridge::project($this->cards(20, true));
        $this->assertCount(12, $field);
        $this->assertSame('Boise State', $field[11]['name'], 'the twelfth team on odds gives way');
        $this->assertSame('Team 11', $field[10]['name']);
    }

    public function test_a_broken_board_gives_no_field(): void
    {
        $this->assertSame([], PlayoffBoardBridge::project([['name' => 'no id'], 'junk', null]));
    }
}
