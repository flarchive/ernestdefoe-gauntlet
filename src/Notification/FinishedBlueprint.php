<?php

namespace ErnestDefoe\Gauntlet\Notification;

/** A tournament you played in has finished; its page shows where you placed. */
class FinishedBlueprint extends TournamentBlueprint
{
    public static function getType(): string
    {
        return 'gauntletFinished';
    }
}
