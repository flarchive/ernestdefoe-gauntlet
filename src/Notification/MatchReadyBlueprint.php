<?php

namespace ErnestDefoe\Gauntlet\Notification;

use Flarum\Notification\MailableInterface;

/** Your next match is ready: you know your opponent. */
class MatchReadyBlueprint extends TournamentBlueprint implements MailableInterface
{
    public static function getType(): string
    {
        return 'gauntletMatchReady';
    }

    protected static function emailView(): string
    {
        return 'match_ready';
    }
}
