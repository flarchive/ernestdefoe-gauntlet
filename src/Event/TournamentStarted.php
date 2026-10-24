<?php

namespace ErnestDefoe\Gauntlet\Event;

use ErnestDefoe\Gauntlet\Tournament;
use Flarum\User\User;

class TournamentStarted
{
    public function __construct(public Tournament $tournament, public ?User $actor = null)
    {
    }
}
