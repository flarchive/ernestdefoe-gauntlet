<?php

namespace ErnestDefoe\Gauntlet\Console;

use Carbon\Carbon;
use ErnestDefoe\Gauntlet\Entrant;
use ErnestDefoe\Gauntlet\Listener\Announce;
use ErnestDefoe\Gauntlet\Notification\CheckinBlueprint;
use ErnestDefoe\Gauntlet\Tournament;
use Flarum\Notification\NotificationSyncer;
use Illuminate\Console\Command;

/**
 * Tells entrants when check-in opens. Scheduled every five minutes; a
 * tournament is announced once (checkin_notified_at), and only while its
 * window is open.
 *
 * 🚨 No constructor dependencies: Flarum's scheduler builds every scheduled
 * command at boot. They are resolved in handle().
 */
class RemindCommand extends Command
{
    protected $signature = 'gauntlet:remind';

    protected $description = 'Notify entrants when a tournament opens check-in.';

    public function handle(NotificationSyncer $notifications, Announce $announce): int
    {
        $now = Carbon::now();

        $due = Tournament::query()
            ->where('status', Tournament::REGISTRATION)
            ->where('checkin_minutes', '>', 0)
            ->whereNull('checkin_notified_at')
            ->whereNotNull('starts_at')
            ->where('starts_at', '>', $now)
            ->where('starts_at', '<=', $now->copy()->addDay())
            ->limit(50)
            ->get()
            ->filter(fn (Tournament $t) => $t->checkinOpen($now));

        foreach ($due as $tournament) {
            // Claimed first, so two overlapping runs never both send.
            $claimed = Tournament::query()->whereKey($tournament->id)->whereNull('checkin_notified_at')->update(['checkin_notified_at' => $now]);
            if (! $claimed) {
                continue;
            }

            $ids = Entrant::query()->where('tournament_id', $tournament->id)->whereIn('status', [Entrant::REGISTERED, Entrant::WAITLISTED])->pluck('id')->all();
            $users = array_values(array_filter(
                array_merge([], ...array_values($announce->members($tournament, $ids))),
                fn ($user) => \ErnestDefoe\Gauntlet\Access\Gate::canView($user, $tournament)
            ));
            if ($users) {
                $notifications->sync(new CheckinBlueprint($tournament), $users);
            }
            $this->info("Tournament {$tournament->id}: ".count($users).' notified.');
        }

        return 0;
    }
}
