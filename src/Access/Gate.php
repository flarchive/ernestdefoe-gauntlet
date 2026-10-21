<?php

namespace ErnestDefoe\Gauntlet\Access;

use ErnestDefoe\Gauntlet\Entrant;
use ErnestDefoe\Gauntlet\Tournament;
use Flarum\User\User;

/**
 * Who may do what. Every controller asks here, so the button a member sees
 * and the server's answer come from the same rules.
 */
class Gate
{
    public static function canView(User $actor, Tournament $tournament): bool
    {
        return $actor->hasPermission('gauntlet.view')
            && ($tournament->status !== Tournament::DRAFT || self::canManage($actor, $tournament));
    }

    public static function canCreate(User $actor): bool
    {
        return ! $actor->isGuest() && ($actor->hasPermission('gauntlet.create') || $actor->hasPermission('gauntlet.manage'));
    }

    /** The organiser while they may still organise, or a manager. */
    public static function canManage(User $actor, Tournament $tournament): bool
    {
        if ($actor->isGuest()) {
            return false;
        }

        return $actor->hasPermission('gauntlet.manage')
            || ($tournament->user_id === $actor->id && $actor->hasPermission('gauntlet.create'));
    }

    /**
     * Points and badges are kept from organisers on purpose: an organiser who
     * could attach a reward could run a tournament for their own alt account.
     */
    public static function canReward(User $actor): bool
    {
        return $actor->hasPermission('gauntlet.reward');
    }

    public static function canJoin(User $actor): bool
    {
        return ! $actor->isGuest() && $actor->hasPermission('gauntlet.view') && $actor->hasPermission('gauntlet.join');
    }

    public static function canPredict(User $actor): bool
    {
        return ! $actor->isGuest() && $actor->hasPermission('gauntlet.view') && $actor->hasPermission('gauntlet.predict');
    }

    /** Reporting for an entrant: the player, or the team's captain. */
    public static function speaksFor(User $actor, ?Entrant $entrant): bool
    {
        return $entrant !== null && ! $actor->isGuest() && $entrant->user_id === $actor->id && $entrant->status === Entrant::REGISTERED;
    }
}
