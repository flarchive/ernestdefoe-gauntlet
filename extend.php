<?php

/*
 * Gauntlet — tournaments for Flarum.
 *
 * The shape of the extension: a tournament is built as matches that know
 * where their winner and loser go (src/Engine), every change to a field or a
 * bracket is applied under a lock on the tournament (Director), and
 * everything that follows — notifications, discussions, Picks links,
 * prediction scores, prizes — runs once that has committed (Listener\Announce).
 */

use ErnestDefoe\Gauntlet\Access\Gate;
use ErnestDefoe\Gauntlet\Access\ScopeTournamentVisibility;
use ErnestDefoe\Gauntlet\Api;
use ErnestDefoe\Gauntlet\Console\RemindCommand;
use ErnestDefoe\Gauntlet\Content\TournamentDocument;
use ErnestDefoe\Gauntlet\Integration\Badges\BadgesProvider;
use ErnestDefoe\Gauntlet\Integration\Picks\PicksBridge;
use ErnestDefoe\Gauntlet\Listener\Announce;
use ErnestDefoe\Gauntlet\Notification;
use ErnestDefoe\Gauntlet\Rewards;
use ErnestDefoe\Gauntlet\Tournament;
use Flarum\Api\Context;
use Flarum\Api\Resource\ForumResource;
use Flarum\Api\Schema;
use Flarum\Extend;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        // The pages, the bracket, the editor and their styles are their own
        // chunks: nothing of Gauntlet's is in forum.css.
        ->jsDirectory(__DIR__.'/js/dist/forum')
        // Static paths before {id}: FastRoute refuses a static route that an
        // earlier variable one already matches.
        ->route('/tournaments', 'gauntlet.index')
        ->route('/tournaments/new', 'gauntlet.new')
        ->route('/tournaments/{id:\d+(?:-[^/]*)?}', 'gauntlet.tournament', TournamentDocument::class)
        ->route('/tournaments/{id:\d+}/edit', 'gauntlet.edit')
        ->route('/u/{username}/tournaments', 'user.tournaments'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\View())
        ->namespace('ernestdefoe-gauntlet', __DIR__.'/views'),

    (new Extend\Routes('api'))
        ->get('/gauntlet/tournaments', 'gauntlet.tournaments.index', Api\TournamentsController::class)
        ->post('/gauntlet/tournaments', 'gauntlet.tournaments.create', Api\TournamentsController::class)
        ->get('/gauntlet/tournaments/{id:[0-9]+}', 'gauntlet.tournaments.show', Api\TournamentsController::class)
        ->patch('/gauntlet/tournaments/{id:[0-9]+}', 'gauntlet.tournaments.update', Api\TournamentsController::class)
        ->delete('/gauntlet/tournaments/{id:[0-9]+}', 'gauntlet.tournaments.delete', Api\TournamentsController::class)
        ->post('/gauntlet/tournaments/{id:[0-9]+}/entrants', 'gauntlet.entrants.create', Api\EntrantsController::class)
        ->put('/gauntlet/tournaments/{id:[0-9]+}/prediction', 'gauntlet.predictions.save', Api\PredictionsController::class)
        ->get('/gauntlet/tournaments/{id:[0-9]+}/predictions', 'gauntlet.predictions.index', Api\PredictionsController::class)
        ->post('/gauntlet/tournaments/{id:[0-9]+}/{action:publish|start|reset|cancel|discussion|playoff-board}', 'gauntlet.tournaments.action', Api\TournamentsController::class)
        ->patch('/gauntlet/entrants/{id:[0-9]+}', 'gauntlet.entrants.update', Api\EntrantsController::class)
        ->delete('/gauntlet/entrants/{id:[0-9]+}', 'gauntlet.entrants.delete', Api\EntrantsController::class)
        ->post('/gauntlet/entrants/{id:[0-9]+}/checkin', 'gauntlet.entrants.checkin', Api\EntrantsController::class)
        ->delete('/gauntlet/entrants/{id:[0-9]+}/checkin', 'gauntlet.entrants.checkout', Api\EntrantsController::class)
        ->post('/gauntlet/entrants/{id:[0-9]+}/members', 'gauntlet.entrants.join', Api\EntrantsController::class)
        ->post('/gauntlet/entrants/{id:[0-9]+}/members/{user:[0-9]+}', 'gauntlet.entrants.approve', Api\EntrantsController::class)
        ->delete('/gauntlet/entrants/{id:[0-9]+}/members/{user:[0-9]+}', 'gauntlet.entrants.leave', Api\EntrantsController::class)
        ->post('/gauntlet/matches/{id:[0-9]+}/report', 'gauntlet.matches.report', Api\MatchesController::class)
        ->post('/gauntlet/matches/{id:[0-9]+}/resolve', 'gauntlet.matches.resolve', Api\MatchesController::class)
        ->post('/gauntlet/matches/{id:[0-9]+}/discussion', 'gauntlet.matches.discussion', Api\MatchesController::class)
        ->patch('/gauntlet/matches/{id:[0-9]+}', 'gauntlet.matches.update', Api\MatchesController::class)
        ->get('/gauntlet/users/{id:[0-9]+}', 'gauntlet.history', Api\HistoryController::class)
        ->get('/gauntlet/picks/teams', 'gauntlet.picks.teams', Api\PicksController::class)
        ->get('/gauntlet/picks/games', 'gauntlet.picks.games', Api\PicksController::class),

    new Extend\ApiResource(Api\TournamentResource::class),

    (new Extend\ModelVisibility(Tournament::class))
        ->scope(ScopeTournamentVisibility::class),

    (new Extend\Notification())
        ->type(Notification\MatchReadyBlueprint::class, ['alert', 'email'])
        ->type(Notification\ResultReportedBlueprint::class, ['alert'])
        ->type(Notification\RulingBlueprint::class, ['alert', 'email'])
        ->type(Notification\StartedBlueprint::class, ['alert'])
        ->type(Notification\CheckinBlueprint::class, ['alert', 'email'])
        ->type(Notification\PromotedBlueprint::class, ['alert', 'email'])
        ->type(Notification\FinishedBlueprint::class, ['alert']),

    (new Extend\Event())
        ->subscribe(Announce::class),

    (new Extend\Console())
        ->command(RemindCommand::class)
        ->schedule(RemindCommand::class, function ($event): void {
            $event->everyFiveMinutes()->withoutOverlapping();
        }),

    (new Extend\Settings())
        ->default('ernestdefoe-gauntlet.show_nav', true)
        ->serializeToForum('gauntletShowNav', 'ernestdefoe-gauntlet.show_nav', 'boolval'),

    // Permissions the actor's groups were already loaded for: no query.
    (new Extend\ApiResource(ForumResource::class))
        ->fields(fn () => [
            Schema\Boolean::make('canViewTournaments')
                ->get(fn ($forum, Context $context) => $context->getActor()->hasPermission('gauntlet.view')),
            Schema\Boolean::make('canCreateTournaments')
                ->get(fn ($forum, Context $context) => Gate::canCreate($context->getActor())),
            Schema\Boolean::make('canRewardTournaments')
                ->get(fn ($forum, Context $context) => Gate::canCreate($context->getActor()) && Gate::canReward($context->getActor())),
            // Only for those who can attach prizes: whether points can be paid at all.
            Schema\Boolean::make('tournamentPointsAvailable')
                ->visible(fn ($forum, Context $context) => Gate::canReward($context->getActor()))
                ->get(fn () => resolve(Rewards::class)->pointsAvailable()),
        ]),

    (new Extend\Conditional())
        ->whenExtensionEnabled('fof-badges', fn () => [
            (new Extend\ServiceProvider())->register(BadgesProvider::class),
        ])
        ->whenExtensionEnabled(PicksBridge::EXTENSION, fn () => [
            // A finished game completes the bracket match linked to it.
            (new Extend\Event())->listen('eloquent.saved: Resofire\Picks\PickEvent', [PicksBridge::class, 'gameSaved']),
        ]),
];
