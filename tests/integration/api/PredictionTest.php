<?php

namespace ErnestDefoe\Gauntlet\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\Gauntlet\Tests\integration\GauntletTestCase;
use PHPUnit\Framework\Attributes\Test;

class PredictionTest extends GauntletTestCase
{
    /** A staff-entered 4-team bracket, started, picks open until a set lock. */
    private function bracket(array $settings = []): array
    {
        $t = $this->tournament($settings + ['entryMode' => 'staff', 'predictions' => true, 'seeding' => 'manual', 'predictionsLockAt' => '2030-06-01T18:00:00Z']);
        foreach (['Georgia', 'Ohio State', 'Oregon', 'Texas'] as $name) {
            [$status, , $raw] = $this->json('POST', "/api/gauntlet/tournaments/{$t['id']}/entrants", 1, ['name' => $name, 'logoUrl' => 'https://example.com/'.rawurlencode($name).'.png']);
            $this->assertSame(200, $status, $raw);
        }

        return $this->start($t['id']);
    }

    private function ids(array $t): array
    {
        return array_column($t['entrants'], 'id', 'name');
    }

    #[Test]
    public function members_pick_a_whole_bracket_and_score_as_results_come_in(): void
    {
        Carbon::setTestNow('2030-06-01 12:00:00');
        $t = $this->bracket();
        $id = $this->ids($t);
        // Seeds: Georgia 1, Ohio State 2, Oregon 3, Texas 4 → semis 1v4 (W:1:1), 2v3 (W:1:2).
        $semi1 = $this->match($t, 'W:1:1');
        $this->assertSame([$id['Georgia'], $id['Texas']], [$semi1['entrant1Id'], $semi1['entrant2Id']]);

        // A pick for the final has to come from your own semi-final picks.
        [$status] = $this->json('PUT', "/api/gauntlet/tournaments/{$t['id']}/prediction", 2, ['picks' => ['W:1:1' => $id['Georgia'], 'W:1:2' => $id['Oregon'], 'W:2:1' => $id['Texas']]]);
        $this->assertSame(422, $status);
        [$status] = $this->json('PUT', "/api/gauntlet/tournaments/{$t['id']}/prediction", 2, ['picks' => ['W:1:1' => $id['Oregon']]]);
        $this->assertSame(422, $status, 'Oregon is not in that semi-final');
        [$status] = $this->json('PUT', "/api/gauntlet/tournaments/{$t['id']}/prediction", 2, ['picks' => ['W:9:9' => $id['Oregon']]]);
        $this->assertSame(422, $status, 'no such match');

        [$status, $body, $raw] = $this->json('PUT', "/api/gauntlet/tournaments/{$t['id']}/prediction", 2, ['picks' => ['W:1:1' => $id['Georgia'], 'W:1:2' => $id['Oregon'], 'W:2:1' => $id['Oregon']], 'tiebreaker' => 45]);
        $this->assertSame(200, $status, $raw);
        $this->assertSame(10 + 10 + 20, $body['data']['possible']);

        $this->json('PUT', "/api/gauntlet/tournaments/{$t['id']}/prediction", 3, ['picks' => ['W:1:1' => $id['Texas'], 'W:1:2' => $id['Ohio State'], 'W:2:1' => $id['Texas']], 'tiebreaker' => 40]);

        // While open, nobody sees anyone else's picks.
        [, $board] = $this->json('GET', "/api/gauntlet/tournaments/{$t['id']}/predictions", 3);
        $mine = collect($board['data'])->firstWhere('user.id', 3);
        $theirs = collect($board['data'])->firstWhere('user.id', 2);
        $this->assertNotNull($mine['picks']);
        $this->assertNull($theirs['picks']);

        // Results come in after the lock.
        Carbon::setTestNow('2030-06-01 19:00:00');
        [$status] = $this->json('PUT', "/api/gauntlet/tournaments/{$t['id']}/prediction", 4, ['picks' => ['W:1:1' => $id['Georgia']]]);
        $this->assertSame(422, $status, 'locked');

        $this->json('POST', "/api/gauntlet/matches/{$semi1['id']}/resolve", 1, ['score1' => 31, 'score2' => 24]);
        $semi2 = $this->match($t, 'W:1:2');
        $this->json('POST', "/api/gauntlet/matches/{$semi2['id']}/resolve", 1, ['score1' => 20, 'score2' => 27]);
        $final = $this->match($this->show($t['id']), 'W:2:1');
        [$status, $body, $raw] = $this->json('POST', "/api/gauntlet/matches/{$final['id']}/resolve", 1, ['score1' => 21, 'score2' => 24]);
        $this->assertSame(200, $status, substr($raw, 0, 600));
        $this->assertSame('finished', $body['data']['status']);

        [, $board] = $this->json('GET', "/api/gauntlet/tournaments/{$t['id']}/predictions", 3);
        $this->assertSame([2, 3], array_column(array_column($board['data'], 'user'), 'id'));
        $this->assertSame([40, 0], array_column($board['data'], 'score'));
        $this->assertNotNull($board['data'][1]['picks'], 'picks are public once locked');

        [, $history] = $this->json('GET', '/api/gauntlet/users/2', 3);
        $this->assertSame(1, $history['data']['predictions'][0]['place']);
    }

    #[Test]
    public function without_a_lock_picks_close_with_the_first_result(): void
    {
        $t = $this->bracket(['predictionsLockAt' => null]);
        $id = $this->ids($t);
        $this->assertSame(200, $this->json('PUT', "/api/gauntlet/tournaments/{$t['id']}/prediction", 2, ['picks' => ['W:1:1' => $id['Georgia']]])[0]);

        $this->json('POST', '/api/gauntlet/matches/'.$this->match($t, 'W:1:1')['id'].'/resolve', 1, ['score1' => 1, 'score2' => 0]);
        $this->assertSame(422, $this->json('PUT', "/api/gauntlet/tournaments/{$t['id']}/prediction", 2, ['picks' => ['W:1:1' => $id['Georgia']]])[0]);
    }

    #[Test]
    public function only_a_staff_entered_single_elimination_bracket_takes_predictions(): void
    {
        [$status] = $this->json('POST', '/api/gauntlet/tournaments', 1, ['title' => 'X', 'format' => 'double', 'entryMode' => 'staff', 'predictions' => true]);
        $this->assertSame(422, $status);
        [$status] = $this->json('POST', '/api/gauntlet/tournaments', 1, ['title' => 'X', 'format' => 'single', 'predictions' => true]);
        $this->assertSame(422, $status);

        // Members can't sign up to a staff-entered field, nor add teams to it.
        $t = $this->tournament(['entryMode' => 'staff']);
        $this->assertSame(403, $this->json('POST', "/api/gauntlet/tournaments/{$t['id']}/entrants", 2, ['name' => 'Mine'])[0]);
        // A logo must be https and nothing else.
        foreach (['javascript:alert(1)', 'http://example.com/a.png', 'https://user:pw@example.com/a.png', 'https://example.com/a".png'] as $bad) {
            $this->assertSame(422, $this->json('POST', "/api/gauntlet/tournaments/{$t['id']}/entrants", 1, ['name' => 'X', 'logoUrl' => $bad])[0], $bad);
        }
    }
}
