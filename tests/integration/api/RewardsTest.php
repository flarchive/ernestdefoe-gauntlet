<?php

namespace ErnestDefoe\Gauntlet\Tests\integration\api;

use ErnestDefoe\Gauntlet\Tests\integration\GauntletTestCase;
use PHPUnit\Framework\Attributes\Test;

class RewardsTest extends GauntletTestCase
{
    /**
     * FoF Badges awards one badge at a time — look it up, insert it, notify —
     * and evaluates its triggers one member at a time. At a finish that is
     * once per member of the field, by FoF's design, not per page view.
     */
    protected function allowedRepeatedQueries(): array
    {
        return array_merge(parent::allowedRepeatedQueries(), ['fof_badge_user', 'notifications', 'gauntlet_entrant_members', 'fof_badges']);
    }

    #[Test]
    public function the_winners_get_the_prize_badge_and_the_trigger_badges_count_finishes(): void
    {
        $this->extension('fof-badges');
        $this->prepareDatabase(['fof_badges' => [
            ['id' => 1, 'name' => 'Champion', 'slug' => 'champion', 'icon' => 'fas fa-trophy', 'is_active' => 1, 'is_visible' => 1],
            ['id' => 2, 'name' => 'Competitor', 'slug' => 'competitor', 'icon' => 'fas fa-flag', 'is_active' => 1, 'is_visible' => 1,
                'trigger_config' => json_encode(['conditions' => [['metric' => 'gauntlet_tournaments_played', 'operator' => '>=', 'value' => 1]]])],
            ['id' => 3, 'name' => 'Podium', 'slug' => 'podium', 'icon' => 'fas fa-medal', 'is_active' => 1, 'is_visible' => 1,
                'trigger_config' => json_encode(['conditions' => [['metric' => 'gauntlet_tournaments_podium', 'operator' => '>=', 'value' => 1]]])],
        ]]);

        $t = $this->tournament(['rewardBadgeId' => 1, 'seeding' => 'manual']);
        [$status] = $this->json('POST', '/api/gauntlet/tournaments', 1, ['title' => 'X', 'format' => 'single', 'rewardBadgeId' => 99]);
        $this->assertSame(422, $status, 'no such badge');

        foreach ([2, 3, 4, 5] as $user) {
            $this->signUp($t['id'], $user);
        }
        $t = $this->start($t['id']);
        for ($i = 0; $i < 3 && $t['status'] === 'running'; $i++) {
            $t = $this->playOpen($t);
        }
        $this->assertSame('finished', $t['status']);

        $champion = collect($t['entrants'])->firstWhere('finalRank', 1)['user']['id'];
        $held = fn (int $badge) => $this->database()->table('fof_badge_user')->where('badge_id', $badge)->orderBy('user_id')->pluck('user_id')->all();

        $this->assertSame([$champion], $held(1));
        $this->assertSame([2, 3, 4, 5], $held(2), 'everyone who played to the finish');
        $this->assertCount(4, $held(3), 'without a third-place match both losing semi-finalists are third');
    }
}
