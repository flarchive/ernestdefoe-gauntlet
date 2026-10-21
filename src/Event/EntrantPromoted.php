<?php

namespace ErnestDefoe\Gauntlet\Event;

use ErnestDefoe\Gauntlet\Entrant;
use ErnestDefoe\Gauntlet\Tournament;

/** A waitlisted entrant got a place. */
class EntrantPromoted
{
    public function __construct(public Tournament $tournament, public Entrant $entrant)
    {
    }
}
