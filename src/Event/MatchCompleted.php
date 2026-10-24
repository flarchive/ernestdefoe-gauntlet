<?php

namespace ErnestDefoe\Gauntlet\Event;

use ErnestDefoe\Gauntlet\Fixture;
use ErnestDefoe\Gauntlet\Tournament;
use Flarum\User\User;

class MatchCompleted
{
    public function __construct(public Tournament $tournament, public Fixture $match, public ?User $actor = null)
    {
    }
}
