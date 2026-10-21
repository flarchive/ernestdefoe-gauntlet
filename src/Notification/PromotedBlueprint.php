<?php

namespace ErnestDefoe\Gauntlet\Notification;

use Flarum\Notification\MailableInterface;

/** You were moved off the waitlist into the field. */
class PromotedBlueprint extends TournamentBlueprint implements MailableInterface
{
    public static function getType(): string
    {
        return 'gauntletPromoted';
    }

    protected static function emailView(): string
    {
        return 'promoted';
    }
}
