<?php

namespace ErnestDefoe\Gauntlet\Notification;

/** Your opponent reported a result: confirm it, or report your own. */
class ResultReportedBlueprint extends TournamentBlueprint
{
    public static function getType(): string
    {
        return 'gauntletResultReported';
    }
}
