<?php

namespace ErnestDefoe\Gauntlet\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\Gauntlet\Tests\integration\GauntletTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The holes an adversarial review found before the first release, each
 * proved against the old code, now closed.
 */
class SecurityTest extends GauntletTestCase
{
    protected function allowedRepeatedQueries(): array
    {
        return array_merge(parent::allowedRepeatedQueries(), ['fof_badge_user', 'fof_badges', 'notifications']);
    }

    private function staff(array $settings, array $names, int $actor = 1): array
    {
        $t = $this->tournament($settings + ['entryMode' => 'staff', 'seeding' => 'manual'], true, $actor);
        foreach ($names as $name) {
            [$status, , $raw] = $this->json('POST', "/api/gauntlet/tournaments/{$t['id']}/entrants", $actor, is_int($name) ? ['picksTeamId' => $name] : ['name' => $name]);
            $this->assertSame(200, $status, $raw);
        }

        return $this->start($t['id'], $actor);
    }

    private function picksTeams(): void
    {
        $this->extension('ernestdefoe-picks');
        $now = Carbon::now();
        $this->prepareDatabase(['picks_teams' => [
            ['id' => 1, 'name' => 'Oregon', 'slug' => 'oregon', 'logo_custom' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'name' => 'Georgia', 'slug' => 'georgia', 'logo_custom' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 3, 'name' => 'Texas', 'slug' => 'texas', 'logo_custom' => 0, 'created_at' => $now, 'updated_at' => $now],
        ]]);
    }

    #[Test]
    public function picks_close_with_the_first_result_even_before_the_lock_time(): void
    {
        Carbon::setTestNow('2030-06-01 12:00:00');
        $t = $this->staff(['predictions' => true, 'predictionsLockAt' => '2030-06-02T18:00:00Z'], ['A', 'B', 'C', 'D']);
        $semi = $this->match($t, 'W:1:1');
        $this->assertSame(200, $this->json('POST', "/api/gauntlet/matches/{$semi['id']}/resolve", 1, ['score1' => 3, 'score2' => 1])[0]);

        [$status] = $this->json('PUT', "/api/gauntlet/tournaments/{$t['id']}/prediction", 5, ['picks' => ['W:1:1' => $semi['entrant1Id']]]);
        $this->assertSame(422, $status);
        $this->assertSame(422, $this->json('PATCH', "/api/gauntlet/tournaments/{$t['id']}", 1, ['predictionPoints' => [100, 200]])[0], 'points are fixed once it runs');
    }

    #[Test]
    public function picks_close_when_a_linked_game_kicks_off(): void
    {
        $this->picksTeams();
        $game = $this->database()->table('picks_events')->insertGetId(['home_team_id' => 1, 'away_team_id' => 2, 'status' => 'scheduled', 'match_date' => Carbon::now()->addDay(), 'cutoff_date' => Carbon::now()->addDay(), 'neutral_site' => 1]);
        $t = $this->staff(['predictions' => true, 'predictionsLockAt' => Carbon::now()->addDays(3)->toIso8601String()], [1, 2]);
        $this->assertSame($game, $this->match($t, 'W:1:1')['picksEventId']);
        $this->assertSame(200, $this->json('PUT', "/api/gauntlet/tournaments/{$t['id']}/prediction", 5, ['picks' => ['W:1:1' => $t['entrants'][0]['id']]])[0]);

        $this->database()->table('picks_events')->where('id', $game)->update(['status' => 'in_progress', 'home_score' => 14, 'away_score' => 0]);
        $this->assertSame(422, $this->json('PUT', "/api/gauntlet/tournaments/{$t['id']}/prediction", 5, ['picks' => ['W:1:1' => $t['entrants'][0]['id']]])[0]);
    }

    #[Test]
    public function a_picks_game_settles_only_brackets_it_belongs_to(): void
    {
        $this->picksTeams();
        $this->prepareDatabase(['group_permission' => [['group_id' => 3, 'permission' => 'gauntlet.create']]]);
        $now = Carbon::now();

        // A meeting last week, before either bracket was set: never links itself.
        $this->database()->table('picks_events')->insert(['home_team_id' => 1, 'away_team_id' => 2, 'home_score' => 10, 'away_score' => 3, 'result' => 'home', 'status' => 'finished', 'match_date' => $now->copy()->subDays(7), 'cutoff_date' => $now->copy()->subDays(7), 'neutral_site' => 0]);
        $victim = $this->staff([], [1, 2], 1);
        $this->assertSame('open', $this->match($victim, 'W:1:1')['status']);
        $this->assertNull($this->match($victim, 'W:1:1')['picksEventId']);

        // Another organiser can't link an old game, nor one between other teams, nor settle someone else's bracket.
        $mine = $this->staff(['title' => 'Mine'], [1, 2], 2);
        $m = $this->match($mine, 'W:1:1');
        $old = $this->database()->table('picks_events')->insertGetId(['home_team_id' => 2, 'away_team_id' => 1, 'home_score' => 50, 'away_score' => 0, 'result' => 'home', 'status' => 'finished', 'match_date' => $now->copy()->subYears(3), 'cutoff_date' => $now->copy()->subYears(3), 'neutral_site' => 0]);
        $other = $this->database()->table('picks_events')->insertGetId(['home_team_id' => 1, 'away_team_id' => 3, 'status' => 'scheduled', 'match_date' => $now->copy()->addDay(), 'cutoff_date' => $now->copy()->addDay(), 'neutral_site' => 0]);
        $this->assertSame(422, $this->json('PATCH', "/api/gauntlet/matches/{$m['id']}", 2, ['picksEventId' => $old])[0]);
        $this->assertSame(422, $this->json('PATCH', "/api/gauntlet/matches/{$m['id']}", 2, ['picksEventId' => $other])[0]);

        $fresh = $this->database()->table('picks_events')->insertGetId(['home_team_id' => 2, 'away_team_id' => 1, 'home_score' => 7, 'away_score' => 21, 'result' => 'away', 'status' => 'finished', 'match_date' => $now, 'cutoff_date' => $now, 'neutral_site' => 0]);
        [$status, $body] = $this->json('PATCH', "/api/gauntlet/matches/{$m['id']}", 2, ['picksEventId' => $fresh]);
        $this->assertSame(200, $status);
        $this->assertSame('finished', $body['data']['status']);
        $this->assertSame('open', $this->match($this->show($victim['id']), 'W:1:1')['status'], 'a hand link settles only its own match');
    }

    #[Test]
    public function the_game_is_fixed_once_results_are_rated(): void
    {
        $t = $this->tournament(['game' => 'Chess']);
        $this->signUp($t['id'], 3);
        $this->signUp($t['id'], 4);
        $t = $this->playOpen($this->start($t['id']));
        $this->assertSame(422, $this->json('PATCH', "/api/gauntlet/tournaments/{$t['id']}", 1, ['game' => 'Go'])[0]);
        $this->assertSame(200, $this->json('PATCH', "/api/gauntlet/tournaments/{$t['id']}", 1, ['game' => ' Chess ', 'title' => 'Renamed'])[0], 'the same game, as the form sends it');
    }

    #[Test]
    public function prizes_are_paid_once_and_only_a_rewarder_changes_a_prized_final(): void
    {
        $this->extension('fof-badges');
        $this->prepareDatabase([
            'group_permission' => [['group_id' => 3, 'permission' => 'gauntlet.create']],
            'fof_badges' => [['id' => 1, 'name' => 'Champion', 'slug' => 'champion', 'icon' => 'fas fa-trophy', 'is_active' => 1, 'is_visible' => 1]],
        ]);

        $t = $this->tournament([], true, 2);
        $this->assertSame(200, $this->json('PATCH', "/api/gauntlet/tournaments/{$t['id']}", 1, ['rewardBadgeId' => 1])[0]);
        $this->signUp($t['id'], 3);
        $this->signUp($t['id'], 4);
        $t = $this->start($t['id'], 2);
        $final = $this->match($t, 'W:1:1');
        $this->assertSame(200, $this->json('POST', "/api/gauntlet/matches/{$final['id']}/resolve", 2, ['score1' => 2, 'score2' => 0])[0]);
        $this->assertSame(422, $this->json('POST', "/api/gauntlet/matches/{$final['id']}/resolve", 2, ['score1' => 0, 'score2' => 2])[0], 'the organiser can\'t move a paid prize');

        // An admin may correct it; the prize is not paid a second time.
        $this->assertSame(200, $this->json('POST', "/api/gauntlet/matches/{$final['id']}/resolve", 1, ['score1' => 0, 'score2' => 2])[0]);
        $this->assertCount(1, $this->database()->table('fof_badge_user')->where('badge_id', 1)->get());
    }

    #[Test]
    public function swiss_finishes_when_nobody_is_left_to_pair(): void
    {
        $t = $this->tournament(['format' => 'swiss', 'swissRounds' => 3]);
        foreach ([3, 4, 5, 6] as $u) {
            $this->signUp($t['id'], $u);
        }
        $t = $this->start($t['id']);
        $byUser = array_flip($this->captains($t));
        foreach ([4, 5, 6] as $u) {
            $this->assertSame(200, $this->json('DELETE', "/api/gauntlet/entrants/{$byUser[$u]}", $u)[0]);
        }
        $this->assertSame('finished', $this->show($t['id'])['status']);
    }

    #[Test]
    public function an_ad_hoc_team_takes_only_members_its_captain_accepts(): void
    {
        $t = $this->tournament(['entryType' => 'team', 'teamSize' => 2]);
        $team = $this->signUp($t['id'], 3, ['name' => 'Favourites'])['entrants'][0];

        // Asking is not joining: no place on the roster, no prize, no entry elsewhere.
        $this->assertSame(200, $this->json('POST', "/api/gauntlet/entrants/{$team['id']}/members", 4)[0]);
        $view = $this->show($t['id'], 4);
        $this->assertSame($team['id'], $view['me']['requestedEntrantId']);
        $this->assertNull($view['me']['entrantId']);
        $this->assertCount(1, $view['entrants'][0]['members']);
        $this->assertArrayNotHasKey('requests', $view['entrants'][0], 'requests are for the captain');
        $this->assertSame(422, $this->json('POST', "/api/gauntlet/tournaments/{$t['id']}/entrants", 4, ['name' => 'Other'])[0]);

        // Only the captain (or an organiser) accepts.
        $this->assertSame(403, $this->json('POST', "/api/gauntlet/entrants/{$team['id']}/members/4", 4)[0]);
        $this->assertSame(403, $this->json('POST', "/api/gauntlet/entrants/{$team['id']}/members/4", 5)[0]);
        $this->assertSame([4], array_column($this->show($t['id'], 3)['entrants'][0]['requests'], 'id'));
        $this->assertSame(200, $this->json('POST', "/api/gauntlet/entrants/{$team['id']}/members/4", 3)[0]);
        $this->assertCount(2, $this->show($t['id'], 3)['entrants'][0]['members']);

        // Full now: no more requests.
        $this->assertSame(422, $this->json('POST', "/api/gauntlet/entrants/{$team['id']}/members", 7)[0], 'a full team takes no more requests');
    }
}
