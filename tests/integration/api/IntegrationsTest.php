<?php

namespace ErnestDefoe\Gauntlet\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\Gauntlet\Tests\integration\GauntletTestCase;
use PHPUnit\Framework\Attributes\Test;
use Resofire\Picks\PickEvent;

/** Picks settles a staff bracket; Social Groups teams enter as their group. */
class IntegrationsTest extends GauntletTestCase
{
    #[Test]
    public function a_finished_picks_game_settles_its_match_and_links_a_late_one(): void
    {
        $this->extension('ernestdefoe-picks');
        $now = Carbon::now();
        $this->prepareDatabase(['picks_teams' => [
            ['id' => 1, 'name' => 'Oregon', 'slug' => 'oregon', 'logo_path' => 'https://a.espncdn.com/i/teamlogos/ncaa/500/2483.png', 'logo_custom' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'name' => 'Georgia', 'slug' => 'georgia', 'logo_custom' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 3, 'name' => 'Texas', 'slug' => 'texas', 'logo_custom' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 4, 'name' => 'Ohio State', 'slug' => 'ohio-state', 'logo_custom' => 0, 'created_at' => $now, 'updated_at' => $now],
        ]]);

        $t = $this->tournament(['entryMode' => 'staff', 'predictions' => true, 'seeding' => 'manual', 'predictionsLockAt' => $now->copy()->addHour()->toIso8601String()]);
        foreach ([1, 2, 3, 4] as $seed => $team) {
            [$status, $body, $raw] = $this->json('POST', "/api/gauntlet/tournaments/{$t['id']}/entrants", 1, ['picksTeamId' => $team]);
            $this->assertSame(200, $status, $raw);
        }
        $this->assertSame(['Oregon', 'Georgia', 'Texas', 'Ohio State'], array_column($body['data']['entrants'], 'name'), 'names come from Picks');
        $this->assertStringContainsString('2483.png', $body['data']['entrants'][0]['logoUrl']);
        [$status] = $this->json('POST', "/api/gauntlet/tournaments/{$t['id']}/entrants", 1, ['picksTeamId' => 99]);
        $this->assertSame(422, $status);

        // Seeds 1 v 4 and 2 v 3; the semi-final games are already in Picks.
        $game = fn (int $home, int $away) => PickEvent::query()->create(['home_team_id' => $home, 'away_team_id' => $away, 'match_date' => $now->copy()->addDay(), 'cutoff_date' => $now->copy()->addDay(), 'status' => 'scheduled', 'neutral_site' => true]);
        $semi1 = $game(4, 1);
        $semi2 = $game(2, 3);

        $t = $this->start($t['id']);
        $this->assertSame($semi1->id, $this->match($t, 'W:1:1')['picksEventId'], 'linked by its two teams, either way round');
        $this->assertSame($semi2->id, $this->match($t, 'W:1:2')['picksEventId']);

        $semi1->fill(['status' => 'finished', 'home_score' => 20, 'away_score' => 31, 'result' => 'away'])->save();
        $semi2->fill(['status' => 'finished', 'home_score' => 17, 'away_score' => 10, 'result' => 'home'])->save();

        $t = $this->show($t['id']);
        $first = $this->match($t, 'W:1:1');
        $this->assertSame('completed', $first['status']);
        $this->assertSame([31, 20], [$first['score1'], $first['score2']], 'scores follow the entrants, not home and away');
        $final = $this->match($t, 'W:2:1');
        $this->assertSame('open', $final['status']);
        $this->assertNull($final['picksEventId'], 'no final game in Picks yet');

        // The final's game reaches Picks only later, already decided.
        $late = $game(2, 1);
        $late->fill(['status' => 'finished', 'home_score' => 21, 'away_score' => 24, 'result' => 'away'])->save();

        $t = $this->show($t['id']);
        $this->assertSame('finished', $t['status']);
        $this->assertSame(1, collect($t['entrants'])->firstWhere('finalRank', 1)['picksTeamId']);
    }

    #[Test]
    public function a_social_group_enters_as_a_team_and_only_its_members_join(): void
    {
        $this->extension('ernestdefoe-social-groups');
        $now = Carbon::now();
        $this->prepareDatabase([
            'social_groups' => [
                ['id' => 1, 'user_id' => 3, 'name' => 'The Knights', 'slug' => 'knights', 'color' => '#336699', 'member_count' => 2, 'created_at' => $now, 'updated_at' => $now],
                ['id' => 2, 'user_id' => 7, 'name' => 'The Rooks', 'slug' => 'rooks', 'color' => '#993366', 'member_count' => 1, 'created_at' => $now, 'updated_at' => $now],
            ],
            'social_group_members' => [
                ['group_id' => 1, 'user_id' => 3, 'role' => 'creator', 'joined_at' => $now],
                ['group_id' => 1, 'user_id' => 4, 'role' => 'member', 'joined_at' => $now],
                ['group_id' => 1, 'user_id' => 5, 'role' => 'member', 'joined_at' => $now, 'banned_at' => $now],
                ['group_id' => 2, 'user_id' => 7, 'role' => 'creator', 'joined_at' => $now],
            ],
        ]);

        $t = $this->tournament(['entryType' => 'team', 'teamSize' => 3]);
        $this->assertSame([['id' => 1, 'name' => 'The Knights']], $this->show($t['id'], 3)['me']['groups']);
        $this->assertSame([], $this->show($t['id'], 4)['me']['groups'], 'members may not enter the group');

        $this->assertSame(422, $this->json('POST', "/api/gauntlet/tournaments/{$t['id']}/entrants", 4, ['groupId' => 1])[0]);
        $data = $this->signUp($t['id'], 3, ['groupId' => 1]);
        $team = $data['entrants'][0];
        $this->assertSame('The Knights', $team['name']);

        $this->assertSame(200, $this->json('POST', "/api/gauntlet/entrants/{$team['id']}/members", 4)[0]);
        $this->assertSame(422, $this->json('POST', "/api/gauntlet/entrants/{$team['id']}/members", 5)[0], 'banned from the group');
        $this->assertSame(422, $this->json('POST', "/api/gauntlet/entrants/{$team['id']}/members", 6)[0], 'not in the group');

        // Groups carry a rating from their results; members' own ratings are untouched.
        $this->signUp($t['id'], 7, ['groupId' => 2]);
        $t = $this->playOpen($this->start($t['id']));
        $this->assertSame('finished', $t['status']);
        $this->assertSame(2, $this->database()->table('gauntlet_ratings')->where('subject_type', 'group')->where('played', 1)->count());
        $this->assertSame(0, $this->database()->table('gauntlet_ratings')->where('subject_type', 'user')->count());

        // An ad-hoc team has nothing stable to rate: its matches aren't rated.
        $u = $this->tournament(['entryType' => 'team', 'title' => 'Pick-up']);
        $this->signUp($u['id'], 3, ['groupId' => 1]);
        $this->signUp($u['id'], 8, ['name' => 'Pick-up side']);
        $this->playOpen($this->start($u['id']));
        $this->assertSame(1, (int) $this->database()->table('gauntlet_ratings')->where('subject_id', 1)->where('subject_type', 'group')->value('played'));
    }
}
