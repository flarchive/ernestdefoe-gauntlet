<?php

namespace ErnestDefoe\Gauntlet;

use Flarum\Extension\ExtensionManager;
use Flarum\User\User;
use Illuminate\Contracts\Container\Container;

/**
 * Prizes from FoF Gamification (points for 1st, 2nd and 3rd) and FoF Badges
 * (a badge for the winners), paid to every member of a placed entrant — or,
 * in a staff-entered prediction bracket, to the best predictors.
 *
 * Both are idempotent on their own side: a point grant is keyed by the
 * tournament and place, and a badge is held once. A corrected final pays the
 * new winners too; it does not take the first payment back.
 *
 * 🚨 Both are resolved by NAME: Gauntlet loads the same with either, both or
 * neither installed. Points need FoF Gamification's point-grants seam.
 */
class Rewards
{
    public const POINT_GRANTS = 'FoF\Gamification\PointGrants';

    public function __construct(private ExtensionManager $extensions, private Container $container)
    {
    }

    public function pointsAvailable(): bool
    {
        return $this->extensions->isEnabled('fof-gamification') && class_exists(self::POINT_GRANTS);
    }

    public function badgesAvailable(): bool
    {
        return $this->extensions->isEnabled('fof-badges') && class_exists('FoF\Badges\Badge');
    }

    /** @param array<int, list<User>> $byPlace place => members */
    public function pay(Tournament $tournament, array $byPlace): void
    {
        $points = array_values((array) $tournament->reward_points);

        if ($this->pointsAvailable()) {
            $grants = $this->container->make(self::POINT_GRANTS);
            foreach ([1, 2, 3] as $place) {
                $amount = (int) ($points[$place - 1] ?? 0);
                if ($amount > 0) {
                    foreach ($byPlace[$place] ?? [] as $user) {
                        $grants->grant($user, 'gauntlet', 'tournament:'.$tournament->id.':'.$place, $amount);
                    }
                }
            }
        }

        if ($tournament->reward_badge_id && $this->badgesAvailable() && ! empty($byPlace[1])) {
            $badge = \FoF\Badges\Badge::query()->find($tournament->reward_badge_id);
            if ($badge) {
                $awarder = $this->container->make('FoF\Badges\BadgeAwarder');
                foreach ($byPlace[1] as $user) {
                    $awarder->award($user, $badge, \FoF\Badges\UserBadge::GRANTED_BY_SYSTEM);
                }
            }
        }
    }
}
