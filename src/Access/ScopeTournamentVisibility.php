<?php

namespace ErnestDefoe\Gauntlet\Access;

use ErnestDefoe\Gauntlet\Tournament;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;

/** Drafts stay with their organiser (and managers); nothing without view permission. */
class ScopeTournamentVisibility
{
    /** @param Builder<Tournament> $query */
    public function __invoke(User $actor, Builder $query): void
    {
        if (! $actor->hasPermission('gauntlet.view')) {
            $query->whereRaw('1 = 0');

            return;
        }

        if ($actor->hasPermission('gauntlet.manage')) {
            return;
        }

        $query->where(function (Builder $q) use ($actor) {
            $q->where('status', '!=', Tournament::DRAFT);
            if (! $actor->isGuest()) {
                $q->orWhere('user_id', $actor->id);
            }
        });
    }
}
