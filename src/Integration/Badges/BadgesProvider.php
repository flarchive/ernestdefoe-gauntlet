<?php

namespace ErnestDefoe\Gauntlet\Integration\Badges;

use ErnestDefoe\Gauntlet\Event\TournamentFinished;
use ErnestDefoe\Gauntlet\Listener\Announce;
use Flarum\Foundation\AbstractServiceProvider;
use FoF\Badges\BadgeAwarder;
use FoF\Badges\Trigger\MetricManager;
use FoF\Badges\Trigger\TriggerEvaluator;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;

/**
 * Gauntlet's triggers in FoF Badges, and the listener that evaluates them for
 * every member of the field when a tournament finishes. FoF Badges' own
 * listener only knows its own events.
 *
 * Registered from extend.php only when fof-badges is enabled.
 */
class BadgesProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->extend(MetricManager::class, function (MetricManager $manager) {
            $db = $this->container->make(ConnectionInterface::class);
            $manager->register(new Metric($db, 'tournaments_won', 1));
            $manager->register(new Metric($db, 'tournaments_podium', 3));
            $manager->register(new Metric($db, 'tournaments_played', null));

            return $manager;
        });
    }

    public function boot(Dispatcher $events): void
    {
        $events->listen(TournamentFinished::class, function (TournamentFinished $event): void {
            if ($event->tournament->isStaffEntered()) {
                return;
            }

            $evaluator = $this->container->make(TriggerEvaluator::class);
            $awarder = $this->container->make(BadgeAwarder::class);
            $announce = $this->container->make(Announce::class);
            $ids = $this->container->make(ConnectionInterface::class)->table('gauntlet_entrants')
                ->where('tournament_id', $event->tournament->id)->whereNotNull('final_rank')->pluck('id')->all();

            foreach ($announce->members($event->tournament, $ids) as $users) {
                foreach ($users as $user) {
                    foreach ($evaluator->evaluateForEvent($user, TournamentFinished::class) as $badge) {
                        $awarder->award($user, $badge);
                    }
                }
            }
        });
    }
}
