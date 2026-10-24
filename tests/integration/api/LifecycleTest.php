<?php

namespace ErnestDefoe\Gauntlet\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\Gauntlet\Tests\integration\GauntletTestCase;
use PHPUnit\Framework\Attributes\Test;

class LifecycleTest extends GauntletTestCase
{
    #[Test]
    public function a_single_elimination_cup_runs_from_sign_up_to_champion(): void
    {
        $t = $this->tournament(['thirdPlace' => true, 'seeding' => 'manual']);
        foreach ([2, 3, 4, 5, 6] as $user) {
            $this->signUp($t['id'], $user);
        }

        $t = $this->start($t['id']);
        $this->assertSame('running', $t['status']);
        $this->assertCount(7 + 1, $t['matches'], '8-slot bracket plus third place');

        // Manual seeding with no seeds set keeps sign-up order: seed 1 (user 2) has a bye.
        $this->assertSame('bye', $this->match($t, 'W:1:1')['status']);

        for ($i = 0; $i < 5 && $t['status'] === 'running'; $i++) {
            $t = $this->playOpen($t);
        }

        $this->assertSame('finished', $t['status']);
        $ranks = array_column($t['entrants'], 'finalRank', 'id');
        sort($ranks);
        $this->assertSame([1, 2, 3, 4, 5], $ranks, 'third place was played, quarter-final loser is 5th');

        // Notifications went out for opened matches and the finish.
        $types = $this->database()->table('notifications')->pluck('type')->unique()->values()->all();
        $this->assertContains('gauntletMatchReady', $types);
        $this->assertContains('gauntletResultReported', $types);
        $this->assertContains('gauntletFinished', $types);
        $this->assertContains('gauntletStarted', $types);

        // Ratings moved: the champion is above 1500.
        $champion = collect($t['entrants'])->firstWhere('finalRank', 1);
        $this->assertGreaterThan(1500, (int) $this->database()->table('gauntlet_ratings')->where('subject_type', 'user')->where('subject_id', $champion['user']['id'])->value('rating'));

        // The profile shows it.
        [$status, $history] = $this->json('GET', '/api/gauntlet/users/'.$champion['user']['id'], 2);
        $this->assertSame(200, $status);
        $this->assertSame(1, $history['data']['entries'][0]['finalRank']);
        $this->assertSame('Chess', $history['data']['ratings'][0]['game'], 'shown as written, kept per game case-insensitively');
    }

    #[Test]
    public function the_cap_fills_the_waitlist_and_a_withdrawal_promotes_from_it(): void
    {
        $t = $this->tournament(['maxEntrants' => 2]);
        $this->signUp($t['id'], 2);
        $this->signUp($t['id'], 3);
        $data = $this->signUp($t['id'], 4);

        $statuses = array_column($data['entrants'], 'status', 'id');
        $this->assertSame(['registered', 'registered', 'waitlisted'], array_values($statuses));
        $this->assertSame(2, $data['entrantCount']);

        [$status] = $this->json('POST', "/api/gauntlet/tournaments/{$t['id']}/entrants", 2);
        $this->assertSame(422, $status, 'one entry per member');

        $first = $data['entrants'][0]['id'];
        [$status, $body] = $this->json('DELETE', "/api/gauntlet/entrants/$first", 2);
        $this->assertSame(200, $status);
        $this->assertSame(['registered', 'registered'], array_column($body['data']['entrants'], 'status'));
        $this->assertSame(1, $this->database()->table('notifications')->where('type', 'gauntletPromoted')->where('user_id', 4)->count());

        // Without a waitlist, a full tournament says so.
        $u = $this->tournament(['maxEntrants' => 2, 'waitlist' => false]);
        $this->signUp($u['id'], 2);
        $this->signUp($u['id'], 3);
        [$status] = $this->json('POST', "/api/gauntlet/tournaments/{$u['id']}/entrants", 4);
        $this->assertSame(422, $status);
    }

    #[Test]
    public function check_in_drops_no_shows_and_lets_the_waitlist_in(): void
    {
        Carbon::setTestNow('2030-01-01 10:00:00');
        $t = $this->tournament(['maxEntrants' => 2, 'checkinMinutes' => 30, 'startsAt' => '2030-01-01T12:00:00Z']);
        $this->signUp($t['id'], 2);
        $this->signUp($t['id'], 3);
        $data = $this->signUp($t['id'], 4);
        [$a, $b, $c] = array_column($data['entrants'], 'id');

        [$status] = $this->json('POST', "/api/gauntlet/entrants/$a/checkin", 2);
        $this->assertSame(422, $status, 'not open until 11:30');

        Carbon::setTestNow('2030-01-01 11:40:00');
        $this->assertSame(200, $this->json('POST', "/api/gauntlet/entrants/$a/checkin", 2)[0]);
        $this->assertSame(200, $this->json('POST', "/api/gauntlet/entrants/$c/checkin", 4)[0]);
        $this->assertSame(403, $this->json('POST', "/api/gauntlet/entrants/$b/checkin", 4)[0], 'only for yourself');

        $t = $this->start($t['id']);
        $statuses = array_column($t['entrants'], 'status', 'id');
        $this->assertSame('registered', $statuses[$a]);
        $this->assertSame('no_show', $statuses[$b]);
        $this->assertSame('registered', $statuses[$c]);
        $this->assertSame(2, $t['entrantCount']);
    }

    #[Test]
    public function a_dispute_waits_for_the_organiser_who_can_also_correct_later(): void
    {
        $t = $this->tournament(['seeding' => 'manual']);
        foreach ([2, 3, 4, 5] as $user) {
            $this->signUp($t['id'], $user);
        }
        $t = $this->start($t['id']);
        $m = $this->match($t, 'W:1:1');
        $captains = $this->captains($t);
        $one = $captains[$m['entrant1Id']];
        $two = $captains[$m['entrant2Id']];

        $this->json('POST', "/api/gauntlet/matches/{$m['id']}/report", $one, ['score1' => 3, 'score2' => 0]);
        [$status, $body] = $this->json('POST', "/api/gauntlet/matches/{$m['id']}/report", $two, ['score1' => 0, 'score2' => 3]);
        $this->assertSame(200, $status);
        $this->assertSame('disputed', $this->match($body['data'], 'W:1:1')['status']);
        $this->assertSame(1, $this->database()->table('notifications')->where('type', 'gauntletRuling')->where('user_id', 1)->count());

        // A player can't rule on it, nor report someone else's match.
        $this->assertSame(403, $this->json('POST', "/api/gauntlet/matches/{$m['id']}/resolve", $one, ['score1' => 3, 'score2' => 0])[0]);
        $other = $this->match($t, 'W:1:2');
        $this->assertSame(403, $this->json('POST', "/api/gauntlet/matches/{$other['id']}/report", $one, ['score1' => 3, 'score2' => 0])[0]);
        // No draws in a bracket.
        $this->assertSame(422, $this->json('POST', "/api/gauntlet/matches/{$m['id']}/resolve", 1, ['score1' => 1, 'score2' => 1])[0]);

        [$status, $body] = $this->json('POST', "/api/gauntlet/matches/{$m['id']}/resolve", 1, ['score1' => 3, 'score2' => 0]);
        $this->assertSame(200, $status);
        $final = $this->match($body['data'], 'W:2:1');
        $this->assertSame($m['entrant1Id'], $final['entrant1Id']);

        // Corrected while the final hasn't been played: the other side goes through instead.
        [$status, $body] = $this->json('POST', "/api/gauntlet/matches/{$m['id']}/resolve", 1, ['score1' => 1, 'score2' => 4]);
        $this->assertSame(200, $status);
        $this->assertSame($m['entrant2Id'], $this->match($body['data'], 'W:2:1')['entrant1Id']);
        $this->assertSame(0, (int) $this->database()->table('gauntlet_entrants')->where('id', $m['entrant1Id'])->value('wins'));
        $this->assertSame(1, (int) $this->database()->table('gauntlet_ratings')->where('subject_id', $two)->value('played'), 'the first result\'s rating was taken back');

        // Once the final is reported, the semi-final is set.
        $t = $body['data'];
        for ($i = 0; $i < 3 && $t['status'] === 'running'; $i++) {
            $t = $this->playOpen($t);
        }
        $this->assertSame('finished', $t['status']);
        $finalMatch = $this->match($t, 'W:2:1');
        [$status] = $this->json('POST', "/api/gauntlet/matches/{$finalMatch['id']}/resolve", 1, ['score1' => 0, 'score2' => 5]);
        $this->assertSame(200, $status, 'the final itself can still be corrected');
        $this->assertSame(422, $this->json('POST', "/api/gauntlet/matches/{$m['id']}/resolve", 1, ['score1' => 4, 'score2' => 1])[0]);
    }

    #[Test]
    public function staff_confirmation_holds_an_agreed_result(): void
    {
        $t = $this->tournament(['confirmation' => 'staff']);
        $this->signUp($t['id'], 2);
        $this->signUp($t['id'], 3);
        $t = $this->start($t['id']);
        $t = $this->playOpen($t);

        $m = $this->match($t, 'W:1:1');
        $this->assertSame('awaiting', $m['status']);
        $this->assertSame('running', $t['status']);
        $this->assertSame(1, $this->database()->table('notifications')->where('type', 'gauntletRuling')->count());

        [$status, $body] = $this->json('POST', "/api/gauntlet/matches/{$m['id']}/resolve", 1, ['score1' => 2, 'score2' => 1]);
        $this->assertSame(200, $status);
        $this->assertSame('finished', $body['data']['status']);
    }

    #[Test]
    public function a_withdrawal_mid_tournament_forfeits_every_match_from_then_on(): void
    {
        $t = $this->tournament(['format' => 'double', 'seeding' => 'manual']);
        foreach ([2, 3, 4, 5] as $user) {
            $this->signUp($t['id'], $user);
        }
        $t = $this->start($t['id']);
        $leaver = collect($t['entrants'])->firstWhere('user.id', 3);

        [$status, $body] = $this->json('DELETE', "/api/gauntlet/entrants/{$leaver['id']}", 3);
        $this->assertSame(200, $status);
        $t = $body['data'];
        $this->assertSame('withdrawn', collect($t['entrants'])->firstWhere('id', $leaver['id'])['status']);

        for ($i = 0; $i < 8 && $t['status'] === 'running'; $i++) {
            $t = $this->playOpen($t);
        }
        $this->assertSame('finished', $t['status']);
        foreach ($t['matches'] as $m) {
            if (in_array($leaver['id'], [$m['entrant1Id'], $m['entrant2Id']], true) && $m['status'] === 'completed') {
                $this->assertNotSame($leaver['id'], $m['winnerId']);
                $this->assertNull($m['score1'], 'a forfeit has no score');
            }
        }
    }

    #[Test]
    public function round_robin_and_swiss_finish_on_the_table(): void
    {
        foreach (['round_robin' => 5, 'swiss' => 7] as $format => $n) {
            $t = $this->tournament(['format' => $format, 'allowDraws' => true]);
            for ($u = 2; $u < 2 + $n; $u++) {
                $this->signUp($t['id'], $u);
            }
            $t = $this->start($t['id']);
            for ($i = 0; $i < 12 && $t['status'] === 'running'; $i++) {
                $t = $this->playOpen($t, fn ($m) => $m['entrant1Id'] < $m['entrant2Id']);
            }
            $this->assertSame('finished', $t['status'], $format);
            $this->assertCount($n, $t['standings']);
            $this->assertSame(1, $t['standings'][0]['rank']);
            $this->assertNotNull(collect($t['entrants'])->firstWhere('finalRank', 1));
            if ($format === 'swiss') {
                $this->assertSame(3, $t['currentRound']);
                $this->assertSame(3, collect($t['matches'])->where('status', 'bye')->count(), 'one bye a round with seven');
            }
        }
    }

    #[Test]
    public function teams_take_members_up_to_the_size(): void
    {
        $t = $this->tournament(['entryType' => 'team', 'teamSize' => 2]);
        $data = $this->signUp($t['id'], 2, ['name' => '  The   Knights ']);
        $team = $data['entrants'][0];
        $this->assertSame('The Knights', $team['name']);

        $this->assertSame(422, $this->json('POST', "/api/gauntlet/tournaments/{$t['id']}/entrants", 3, [])[0], 'a team needs a name');
        $this->assertSame(200, $this->json('POST', "/api/gauntlet/entrants/{$team['id']}/members", 3)[0]);
        $this->assertSame(200, $this->json('POST', "/api/gauntlet/entrants/{$team['id']}/members/3", 2)[0]);
        $this->assertSame(422, $this->json('POST', "/api/gauntlet/entrants/{$team['id']}/members", 4)[0], 'full');
        $this->assertSame(422, $this->json('POST', "/api/gauntlet/tournaments/{$t['id']}/entrants", 3, ['name' => 'Again'])[0], 'already in a team');

        // A member leaves; the captain can't, and nobody else may remove them.
        $this->assertSame(403, $this->json('DELETE', "/api/gauntlet/entrants/{$team['id']}/members/3", 4)[0]);
        $this->assertSame(200, $this->json('DELETE', "/api/gauntlet/entrants/{$team['id']}/members/3", 3)[0]);
        $this->assertSame(422, $this->json('DELETE', "/api/gauntlet/entrants/{$team['id']}/members/2", 2)[0]);
    }

    #[Test]
    public function drafts_and_permissions_hold(): void
    {
        // Members of group 3 may organise their own tournaments.
        $this->prepareDatabase(['group_permission' => [['group_id' => 3, 'permission' => 'gauntlet.create']]]);
        $draft = $this->tournament([], false);
        $this->assertSame(404, $this->json('GET', "/api/gauntlet/tournaments/{$draft['id']}", 2)[0]);
        $this->assertSame(404, $this->json('GET', "/api/gauntlet/tournaments/{$draft['id']}")[0]);
        [, $list] = $this->json('GET', '/api/gauntlet/tournaments', 2);
        $this->assertSame([], $list['data']);

        // Guests can't create; members can't edit or start someone else's.
        $this->assertSame(403, $this->json('POST', '/api/gauntlet/tournaments', null, ['title' => 'Mine', 'format' => 'single'])[0]);
        $open = $this->tournament();
        $this->assertSame(403, $this->json('PATCH', "/api/gauntlet/tournaments/{$open['id']}", 2, ['title' => 'Hacked'])[0]);
        $this->assertSame(403, $this->json('POST', "/api/gauntlet/tournaments/{$open['id']}/start", 2)[0]);
        $this->assertSame(403, $this->json('POST', "/api/gauntlet/tournaments/{$open['id']}/entrants")[0], 'guests can\'t sign up');

        // Guests see a published one; its reports are kept to the sides.
        $this->assertSame(200, $this->json('GET', "/api/gauntlet/tournaments/{$open['id']}")[0]);

        // Prizes need the reward permission, which organisers lack.
        [$status] = $this->json('POST', '/api/gauntlet/tournaments', 2, ['title' => 'Mine', 'format' => 'single', 'rewardPoints' => [100, 0, 0]]);
        $this->assertSame(403, $status);
        [$status, $mine] = $this->json('POST', '/api/gauntlet/tournaments', 2, ['title' => 'Mine', 'format' => 'single']);
        $this->assertSame(201, $status);
        // An organiser manages their own, not someone else's.
        $this->assertSame(200, $this->json('PATCH', "/api/gauntlet/tournaments/{$mine['data']['id']}", 2, ['title' => 'Mine 2'])[0]);
        $this->assertSame(403, $this->json('PATCH', "/api/gauntlet/tournaments/{$open['id']}", 2, ['title' => 'Hacked'])[0]);
        $this->assertArrayNotHasKey('rewardPoints', $this->show($open['id'], 3));
    }

    #[Test]
    public function a_running_tournament_resets_to_sign_ups(): void
    {
        $t = $this->tournament();
        foreach ([2, 3, 4] as $user) {
            $this->signUp($t['id'], $user);
        }
        $t = $this->playOpen($this->start($t['id']));
        $this->assertGreaterThan(0, $this->database()->table('gauntlet_ratings')->sum('played'));

        [$status, $body] = $this->json('POST', "/api/gauntlet/tournaments/{$t['id']}/reset", 1);
        $this->assertSame(200, $status);
        $this->assertSame('registration', $body['data']['status']);
        $this->assertSame([], $body['data']['matches']);
        $this->assertSame(0, (int) $this->database()->table('gauntlet_ratings')->sum('played'), 'ratings taken back');
        $this->assertSame(1500, (int) $this->database()->table('gauntlet_ratings')->max('rating'));
    }
}
