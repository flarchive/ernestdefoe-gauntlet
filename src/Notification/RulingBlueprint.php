<?php

namespace ErnestDefoe\Gauntlet\Notification;

use Flarum\Notification\MailableInterface;

/** For organisers: a result needs their decision — the sides disagree, or agree and staff confirm. */
class RulingBlueprint extends TournamentBlueprint implements MailableInterface
{
    public static function getType(): string
    {
        return 'gauntletRuling';
    }

    protected static function emailView(): string
    {
        return 'ruling';
    }
}
