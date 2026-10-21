<?php

namespace ErnestDefoe\Gauntlet\Event;

use ErnestDefoe\Gauntlet\Fixture;
use ErnestDefoe\Gauntlet\Tournament;
use Flarum\User\User;

/** One side reported; $disputed when it disagrees with the other side's report. */
class MatchReported
{
    public function __construct(public Tournament $tournament, public Fixture $match, public int $side, public User $actor, public bool $disputed, public bool $awaitingStaff)
    {
    }
}
