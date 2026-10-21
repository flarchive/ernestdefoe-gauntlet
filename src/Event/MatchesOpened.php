<?php

namespace ErnestDefoe\Gauntlet\Event;

use ErnestDefoe\Gauntlet\Fixture;
use ErnestDefoe\Gauntlet\Tournament;

/** Matches whose two sides are now known and can be played. */
class MatchesOpened
{
    /**
     * @param list<Fixture> $matches
     * @param bool $atStart the first round, which "the tournament has started" already announces
     */
    public function __construct(public Tournament $tournament, public array $matches, public bool $atStart = false)
    {
    }
}
