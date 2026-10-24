<?php

namespace ErnestDefoe\Gauntlet\Notification;

use Flarum\Notification\MailableInterface;

/** Check-in is open for a tournament you entered. */
class CheckinBlueprint extends TournamentBlueprint implements MailableInterface
{
    public static function getType(): string
    {
        return 'gauntletCheckin';
    }

    protected static function emailView(): string
    {
        return 'checkin';
    }
}
