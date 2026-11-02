<?php

namespace ErnestDefoe\Gauntlet\Listener;

use ErnestDefoe\Gauntlet\Access\Gate;
use ErnestDefoe\Gauntlet\Discussions;
use ErnestDefoe\Gauntlet\Entrant;
use ErnestDefoe\Gauntlet\Event;
use ErnestDefoe\Gauntlet\Fixture;
use ErnestDefoe\Gauntlet\Integration\Picks\PicksBridge;
use ErnestDefoe\Gauntlet\Job\RescorePredictions;
use ErnestDefoe\Gauntlet\Job\StartMatchDiscussions;
use ErnestDefoe\Gauntlet\Notification;
use ErnestDefoe\Gauntlet\Predictions;
use ErnestDefoe\Gauntlet\Rewards;
use ErnestDefoe\Gauntlet\Tournament;
use Flarum\Notification\NotificationSyncer;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Database\ConnectionInterface;

/**
 * Everything that follows a change to a tournament, run after it commits:
 * notifications, match discussions, Picks links, prediction scores, prizes.
 */
class Announce
{
    public function __construct(
        private NotificationSyncer $notifications,
        private ConnectionInterface $db,
        private Discussions $discussions,
        private PicksBridge $picks,
        private Rewards $rewards,
        private Predictions $predictions,
        private Queue $queue,
    ) {
    }

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(Event\MatchesOpened::class, [$this, 'opened']);
        $events->listen(Event\MatchReported::class, [$this, 'reported']);
        $events->listen(Event\MatchCompleted::class, [$this, 'completed']);
        $events->listen(Event\TournamentStarted::class, [$this, 'started']);
        $events->listen(Event\TournamentFinished::class, [$this, 'finished']);
        $events->listen(Event\EntrantPromoted::class, [$this, 'promoted']);
    }

    public function opened(Event\MatchesOpened $event): void
    {
        $tournament = $event->tournament;

        $this->picks->autoLink($tournament, $event->matches);
        if ($tournament->match_discussions) {
            $this->queue->push(new StartMatchDiscussions($tournament->id, array_map(fn (Fixture $m) => $m->id, $event->matches)));
        }

        // The first round is announced by "the tournament has started".
        if ($tournament->isStaffEntered() || $event->atStart) {
            return;
        }

        $ids = [];
        foreach ($event->matches as $match) {
            $ids[] = (int) $match->entrant1_id;
            $ids[] = (int) $match->entrant2_id;
        }
        $members = $this->members($tournament, $ids);
        $playersOf = fn (Fixture $m) => array_merge($members[(int) $m->entrant1_id] ?? [], $members[(int) $m->entrant2_id] ?? []);

        if ($tournament->format === 'swiss') {
            // A Swiss round is paired all at once: one notice for the round.
            $users = array_merge([], ...array_map($playersOf, array_values(array_filter($event->matches, fn (Fixture $m) => $m->status === Fixture::OPEN))));
            $round = $event->matches[0]->round ?? 0;
            $this->send(new Notification\MatchReadyBlueprint($tournament, ['round' => $round]), $users, $tournament);

            return;
        }

        foreach ($event->matches as $match) {
            if ($match->status === Fixture::OPEN) {
                $this->send(new Notification\MatchReadyBlueprint($tournament, ['match' => $match->id]), $playersOf($match), $tournament);
            }
        }
    }

    public function reported(Event\MatchReported $event): void
    {
        $tournament = $event->tournament;
        $match = $event->match;
        $data = ['match' => $match->id];

        if ($event->disputed || $event->awaitingStaff) {
            $organiser = $tournament->user ? [$tournament->user] : [];
            $this->send(new Notification\RulingBlueprint($tournament, $data, $event->actor), array_filter($organiser, fn (User $u) => Gate::canManage($u, $tournament)), $tournament);

            if (! $event->disputed) {
                return;
            }
        }

        // The other side's captain is asked to confirm or answer.
        $other = Entrant::query()->with('user')->find((int) $match->{'entrant'.(3 - $event->side).'_id'});
        if ($other?->user) {
            $names = $this->names($tournament);
            $data['opponent'] = $names[(int) $match->{'entrant'.$event->side.'_id'}] ?? '';
            $this->send(new Notification\ResultReportedBlueprint($tournament, $data, $event->actor), [$other->user], $tournament);
        }
    }

    public function completed(Event\MatchCompleted $event): void
    {
        if ($event->tournament->predictions) {
            $this->queue->push(new RescorePredictions($event->tournament->id));
        }
    }

    public function started(Event\TournamentStarted $event): void
    {
        $tournament = $event->tournament;
        $ids = Entrant::query()->where('tournament_id', $tournament->id)->where('status', Entrant::REGISTERED)->pluck('id')->all();
        $this->send(new Notification\StartedBlueprint($tournament), array_merge([], ...array_values($this->members($tournament, $ids))), $tournament);
    }

    public function finished(Event\TournamentFinished $event): void
    {
        $tournament = $event->tournament;

        if ($tournament->isStaffEntered()) {
            // Predictors are paid once the last result is scored (RescorePredictions).
            return;
        }

        // A disqualified entrant keeps its place in the results, not a prize.
        $placed = Entrant::query()->where('tournament_id', $tournament->id)->whereNotNull('final_rank')->where('status', '!=', Entrant::DISQUALIFIED)->pluck('final_rank', 'id');
        $members = $this->members($tournament, $placed->keys()->all());

        $byPlace = [];
        foreach ($placed as $entrantId => $place) {
            foreach ($members[$entrantId] ?? [] as $user) {
                $byPlace[(int) $place][] = $user;
            }
        }

        $this->payOnce($tournament, $byPlace);

        // One notice for the whole field; each member's place is on the page.
        $this->send(new Notification\FinishedBlueprint($tournament), array_merge([], ...array_values($byPlace)), $tournament);
    }

    public function promoted(Event\EntrantPromoted $event): void
    {
        $members = $this->members($event->tournament, [$event->entrant->id]);
        $this->send(new Notification\PromotedBlueprint($event->tournament), $members[$event->entrant->id] ?? [], $event->tournament);
    }

    /** The best three predictors share the prizes. */
    public function payPredictors(Tournament $tournament): void
    {
        $top = $this->predictions->board($tournament)->with('user')->limit(3)->get();

        $byPlace = [];
        foreach ($top->values() as $i => $prediction) {
            if ($prediction->user && $prediction->score > 0) {
                $byPlace[$i + 1][] = $prediction->user;
            }
        }

        $this->payOnce($tournament, $byPlace);
    }

    /**
     * Prizes go out once per tournament, claimed in one statement so a
     * re-score or a corrected final never pays a second time.
     *
     * @param array<int, list<User>> $byPlace
     */
    private function payOnce(Tournament $tournament, array $byPlace): void
    {
        if (! $tournament->hasPrizes() || ! $byPlace) {
            return;
        }

        $claimed = Tournament::query()->whereKey($tournament->id)->whereNull('rewarded_at')->update(['rewarded_at' => \Carbon\Carbon::now()]);
        if ($claimed) {
            $this->rewards->pay($tournament, $byPlace);
        }
    }

    /**
     * Members of each entrant, in two queries.
     *
     * @param list<int> $entrantIds
     * @return array<int, list<User>>
     */
    public function members(Tournament $tournament, array $entrantIds): array
    {
        $entrantIds = array_values(array_unique(array_filter($entrantIds)));
        if (! $entrantIds) {
            return [];
        }

        $rows = $this->db->table('gauntlet_entrant_members')->whereIn('entrant_id', $entrantIds)->where('approved', true)->get(['entrant_id', 'user_id']);
        // Groups come along: whether each recipient may still see the tournament is asked of every one.
        $users = User::query()->with('groups')->whereIn('id', $rows->pluck('user_id')->unique()->all())->get()->keyBy('id');

        $members = [];
        foreach ($rows as $row) {
            if ($user = $users->get($row->user_id)) {
                $members[(int) $row->entrant_id][] = $user;
            }
        }

        return $members;
    }

    /** @return array<int, string> entrant id => name (a solo player's display name) */
    private function names(Tournament $tournament): array
    {
        $names = [];
        foreach (Entrant::query()->where('tournament_id', $tournament->id)->with('user')->get() as $entrant) {
            $names[$entrant->id] = Discussions::label($entrant);
        }

        return $names;
    }

    /** @param list<User> $users */
    private function send(Notification\TournamentBlueprint $blueprint, array $users, Tournament $tournament): void
    {
        // Never tell someone about a tournament they can no longer see.
        $users = array_values(array_filter($users, fn (User $u) => Gate::canView($u, $tournament)));
        if ($users) {
            $this->notifications->sync($blueprint, $users);
        }
    }
}
