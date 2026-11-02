<?php

namespace ErnestDefoe\Gauntlet\Api;

use ErnestDefoe\Gauntlet\Tournament;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;

/**
 * Only so a notification can carry its tournament (core serialises a
 * notification's subject through the subject's resource). No endpoints:
 * everything else goes through Gauntlet's own controllers.
 *
 * @extends AbstractDatabaseResource<Tournament>
 */
class TournamentResource extends AbstractDatabaseResource
{
    public function type(): string
    {
        return 'gauntlet-tournaments';
    }

    public function model(): string
    {
        return Tournament::class;
    }

    public function endpoints(): array
    {
        return [];
    }

    public function fields(): array
    {
        return [
            Schema\Str::make('title'),
            Schema\Str::make('slug'),
        ];
    }
}
