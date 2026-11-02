<?php

namespace ErnestDefoe\Gauntlet\Notification;

/** The tournament you entered has started. */
class StartedBlueprint extends TournamentBlueprint
{
    public static function getType(): string
    {
        return 'gauntletStarted';
    }
}
