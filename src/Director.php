<?php

namespace ErnestDefoe\Gauntlet;

use Carbon\Carbon;
use ErnestDefoe\Gauntlet\Access\Gate;
use ErnestDefoe\Gauntlet\Engine\Advancer;
use ErnestDefoe\Gauntlet\Engine\Builder;
use ErnestDefoe\Gauntlet\Engine\Standings;
use ErnestDefoe\Gauntlet\Engine\Swiss;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;

/**
 * Runs a tournament: sign-ups, the start, results and the finish.
 *
 * Every change to a tournament's field or bracket happens inside a
 * transaction that first locks the tournament's row, so two reports, a
 * withdrawal and a correction arriving together are applied one after
 * another and never see a half-advanced bracket. Events are dispatched only
 * once the transaction has committed, so a notification never announces
 * something that was rolled back.
 */
class Director
{
    private const COLUMNS = [
        'tournament_id', 'bracket', 'round', 'position', 'entrant1_id', 'entrant2_id', 'dead1', 'dead2', 'winner_id', 'status',
        'next_bracket', 'next_round', 'next_position', 'next_slot', 'loser_bracket', 'loser_round', 'loser_position', 'loser_slot',
        'best_of', 'created_at', 'updated_at',
    ];

    /** @var list<object> */
    private array $events = [];

    private int $depth = 0;

    public function __construct(
        private ConnectionInterface $db,
        private Ratings $ratings,
        private Dispatcher $dispatcher,
        private TranslatorInterface $translator,
        private Teams $teams,
    ) {
    }

    // ----------------------------------------------------------------- field

    /** @param array<string, mixed> $data */
    public function signUp(Tournament $tournament, User $actor, array $data): Entrant
    {
        if (! Gate::canJoin($actor)) {
            throw new PermissionDeniedException();
        }

        return $this->atomically($tournament, function (Tournament $tournament) use ($actor, $data) {
            if (! $tournament->registrationOpen()) {
                $this->fail('tournament', 'registration_closed');
            }

            $this->assertNotEntered($tournament, $actor->id);

            $entrant = new Entrant();
            $entrant->tournament_id = $tournament->id;
            $entrant->user_id = $actor->id;
            $entrant->name = '';

            if ($tournament->isTeam()) {
                $groupId = (int) ($data['groupId'] ?? 0);
                if ($groupId > 0) {
                    $group = $this->teams->groupCaptainedBy($actor, $groupId);
                    if (! $group) {
                        $this->fail('groupId', 'not_group_captain');
                    }
                    if (Entrant::query()->where('tournament_id', $tournament->id)->where('group_id', $groupId)->whereIn('status', [Entrant::REGISTERED, Entrant::WAITLISTED])->exists()) {
                        $this->fail('groupId', 'group_already_entered');
                    }
                    $entrant->group_id = $groupId;
                    $entrant->name = mb_substr($group['name'], 0, 100);
                } else {
                    $entrant->name = $this->teamName($data['name'] ?? '');
                }
            }

            $entrant->status = $this->placeFor($tournament);
            $entrant->save();

            $this->addMember($tournament, $entrant, $actor->id);
            $this->recount($tournament);

            return $entrant;
        });
    }

    /** Organisers enter the field of a staff-entered (real-world) bracket themselves. */
    public function addStaffEntrant(Tournament $tournament, string $name, ?string $logoUrl, ?int $picksTeamId): Entrant
    {
        return $this->atomically($tournament, function (Tournament $tournament) use ($name, $logoUrl, $picksTeamId) {
            if (! $tournament->isStaffEntered() || ! in_array($tournament->status, [Tournament::DRAFT, Tournament::REGISTRATION], true)) {
                $this->fail('tournament', 'field_locked');
            }
            if ($tournament->full()) {
                $this->fail('tournament', 'full');
            }

            $entrant = new Entrant();
            $entrant->tournament_id = $tournament->id;
            $entrant->name = $this->teamName($name);
            $entrant->logo_url = $logoUrl;
            $entrant->picks_team_id = $picksTeamId;
            $entrant->status = Entrant::REGISTERED;
            $entrant->save();

            $this->recount($tournament);

            return $entrant;
        });
    }

    public function joinTeam(Entrant $entrant, User $actor): void
    {
        if (! Gate::canJoin($actor)) {
            throw new PermissionDeniedException();
        }

        $this->atomically($entrant->tournament, function (Tournament $tournament) use ($entrant, $actor) {
            $entrant->refresh();

            if (! $tournament->isTeam() || $tournament->status !== Tournament::REGISTRATION || ! $entrant->active()) {
                $this->fail('entrant', 'team_closed');
            }

            $this->assertNotEntered($tournament, $actor->id);

            $this->assertRoom($tournament, $entrant);

            if ($entrant->group_id && ! $this->teams->isGroupMember($actor, (int) $entrant->group_id)) {
                $this->fail('entrant', 'not_group_member');
            }

            // A group's members are its team already; anyone else asks the
            // captain, so nobody can crowd a roster or ride on a winning team.
            $this->addMember($tournament, $entrant, $actor->id, (bool) $entrant->group_id);
        });
    }

    /** The captain (or an organiser) takes a request to join onto the roster. */
    public function approveMember(Entrant $entrant, int $userId, User $actor): void
    {
        $this->atomically($entrant->tournament, function (Tournament $tournament) use ($entrant, $userId, $actor) {
            if ($actor->id !== $entrant->user_id && ! Gate::canManage($actor, $tournament)) {
                throw new PermissionDeniedException();
            }
            if (! $tournament->isTeam() || $tournament->status !== Tournament::REGISTRATION) {
                $this->fail('entrant', 'team_closed');
            }

            $this->assertRoom($tournament, $entrant);

            $this->db->table('gauntlet_entrant_members')->where('entrant_id', $entrant->id)->where('user_id', $userId)->update(['approved' => true]);
        });
    }

    private function assertRoom(Tournament $tournament, Entrant $entrant): void
    {
        if ($tournament->team_size > 0 && $this->db->table('gauntlet_entrant_members')->where('entrant_id', $entrant->id)->where('approved', true)->count() >= $tournament->team_size) {
            $this->fail('entrant', 'team_full');
        }
    }

    /** A member leaves a team, or its captain or an organiser takes them off it. */
    public function removeMember(Entrant $entrant, int $userId, User $actor): void
    {
        $this->atomically($entrant->tournament, function (Tournament $tournament) use ($entrant, $userId, $actor) {
            if ($actor->id !== $userId && $actor->id !== $entrant->user_id && ! Gate::canManage($actor, $tournament)) {
                throw new PermissionDeniedException();
            }
            if ($userId === $entrant->user_id) {
                // The captain leaves by withdrawing the team.
                $this->fail('entrant', 'captain_cannot_leave');
            }
            if ($tournament->status !== Tournament::REGISTRATION) {
                $this->fail('entrant', 'team_closed');
            }

            $this->db->table('gauntlet_entrant_members')->where('entrant_id', $entrant->id)->where('user_id', $userId)->delete();
        });
    }

    /**
     * Before the start a withdrawn entry is simply removed (and the first on
     * the waitlist takes its place). Once running, the entrant stays in the
     * results and forfeits every match from here on.
     */
    public function withdraw(Entrant $entrant, User $actor, bool $disqualify = false): void
    {
        $manages = Gate::canManage($actor, $entrant->tournament);
        if ($disqualify ? ! $manages : ($actor->id !== $entrant->user_id || $actor->isGuest()) && ! $manages) {
            throw new PermissionDeniedException();
        }

        $this->atomically($entrant->tournament, function (Tournament $tournament) use ($entrant, $actor, $disqualify) {
            $entrant->refresh();

            if (in_array($tournament->status, [Tournament::DRAFT, Tournament::REGISTRATION], true)) {
                $wasRegistered = $entrant->status === Entrant::REGISTERED;
                $entrant->delete();
                if ($wasRegistered) {
                    $this->promote($tournament);
                }
                $this->recount($tournament);

                return;
            }

            if ($tournament->status !== Tournament::RUNNING || $entrant->status !== Entrant::REGISTERED) {
                $this->fail('entrant', 'cannot_withdraw');
            }

            $entrant->status = $disqualify ? Entrant::DISQUALIFIED : Entrant::WITHDRAWN;
            $entrant->save();

            $this->forfeitLive($tournament, $actor);
        });
    }

    public function checkIn(Entrant $entrant, User $actor, bool $in = true): void
    {
        $manages = Gate::canManage($actor, $entrant->tournament);
        if ($actor->id !== $entrant->user_id && ! $manages) {
            throw new PermissionDeniedException();
        }

        $this->atomically($entrant->tournament, function (Tournament $tournament) use ($entrant, $manages, $in) {
            $entrant->refresh();
            // Organisers may check someone in by hand before the window opens.
            if ($tournament->checkin_minutes <= 0 || ! $entrant->active() || ! ($tournament->checkinOpen() || ($manages && $tournament->status === Tournament::REGISTRATION))) {
                $this->fail('entrant', 'checkin_closed');
            }

            $entrant->checked_in_at = $in ? Carbon::now() : null;
            $entrant->save();
        });
    }

    /** An organiser moves someone off the waitlist, or sets a manual seed. */
    public function updateEntrant(Entrant $entrant, ?int $seed, ?bool $promote, ?string $name, ?string $logoUrl, ?int $picksTeamId, bool $setLogo, bool $setTeam): void
    {
        $this->atomically($entrant->tournament, function (Tournament $tournament) use ($entrant, $seed, $promote, $name, $logoUrl, $picksTeamId, $setLogo, $setTeam) {
            $entrant->refresh();

            if (! in_array($tournament->status, [Tournament::DRAFT, Tournament::REGISTRATION], true)) {
                $this->fail('entrant', 'field_locked');
            }

            if ($seed !== null) {
                $entrant->seed = $seed > 0 ? min($seed, 1024) : null;
            }
            if ($promote && $entrant->status === Entrant::WAITLISTED) {
                $entrant->status = Entrant::REGISTERED;
            }
            if ($name !== null && ($tournament->isStaffEntered() || $tournament->isTeam())) {
                $entrant->name = $this->teamName($name);
            }
            if ($setLogo && $tournament->isStaffEntered()) {
                $entrant->logo_url = $logoUrl;
            }
            if ($setTeam && $tournament->isStaffEntered()) {
                $entrant->picks_team_id = $picksTeamId;
            }

            $entrant->save();
            $this->recount($tournament);
        });
    }

    // ------------------------------------------------------------- lifecycle

    public function publish(Tournament $tournament): void
    {
        $this->atomically($tournament, function (Tournament $tournament) {
            if ($tournament->status !== Tournament::DRAFT) {
                $this->fail('tournament', 'already_published');
            }
            $tournament->status = Tournament::REGISTRATION;
            $tournament->save();
        });
    }

    public function start(Tournament $tournament, User $actor): void
    {
        $this->atomically($tournament, function (Tournament $tournament) use ($actor) {
            if ($tournament->status !== Tournament::REGISTRATION) {
                $this->fail('tournament', 'cannot_start');
            }

            $field = $this->field($tournament);
            if ($field->count() < 2) {
                $this->fail('tournament', 'too_few_entrants');
            }
            // With no cap set, the format's own limit still holds.
            if ($field->count() > (Saver::CAPS[$tournament->format] ?? 256)) {
                $this->fail('tournament', 'too_many_entrants');
            }

            // Requests the captain never answered lapse with the start.
            $this->db->table('gauntlet_entrant_members')->where('tournament_id', $tournament->id)->where('approved', false)->delete();

            $ordered = $this->seedOrder($tournament, $field);
            $ids = array_map(fn (Entrant $e) => $e->id, $ordered);
            $this->storeSeeds($ids);

            $tournament->status = Tournament::RUNNING;
            $tournament->started_at = Carbon::now();
            $tournament->current_round = 1;
            $tournament->entrant_count = count($ids);
            $tournament->save();

            if ($tournament->format === 'swiss') {
                $this->pairSwissRound($tournament, [], $ids, 1);
            } else {
                $specs = match ($tournament->format) {
                    'single' => Builder::singleElimination($ids, $tournament->third_place),
                    'double' => Builder::doubleElimination($ids, $tournament->grand_final_reset),
                    default => Builder::roundRobin($ids),
                };
                $fixtures = Builder::fixtures($specs, $tournament->best_of);

                // Byes are played through before anything is written, so the
                // whole bracket goes in as one insert.
                if ($tournament->isElimination()) {
                    (new Advancer($fixtures))->start();
                } else {
                    foreach ($fixtures as $fixture) {
                        $fixture->status = Fixture::OPEN;
                    }
                }
                $this->insert($tournament, $fixtures);
            }

            $opened = array_filter($this->fixtures($tournament), fn (Fixture $f) => $f->status === Fixture::OPEN);

            $this->events[] = new Event\TournamentStarted($tournament, $actor);
            if ($opened) {
                $this->events[] = new Event\MatchesOpened($tournament, array_values($opened), true);
            }
        });
    }

    /** Back to sign-ups: the bracket, its results, their ratings and every prediction go. */
    public function reset(Tournament $tournament): void
    {
        $this->atomically($tournament, function (Tournament $tournament) {
            if ($tournament->status !== Tournament::RUNNING) {
                $this->fail('tournament', 'cannot_reset');
            }

            $this->revertAllRatings($tournament);

            Fixture::query()->where('tournament_id', $tournament->id)->delete();
            Prediction::query()->where('tournament_id', $tournament->id)->delete();
            Entrant::query()->where('tournament_id', $tournament->id)->where('status', Entrant::NO_SHOW)->update(['status' => Entrant::REGISTERED]);
            Entrant::query()->where('tournament_id', $tournament->id)->update(['final_rank' => null, 'wins' => 0, 'losses' => 0, 'draws' => 0]);
            Entrant::query()->where('tournament_id', $tournament->id)->whereIn('status', [Entrant::WITHDRAWN, Entrant::DISQUALIFIED])->delete();

            $tournament->status = Tournament::REGISTRATION;
            $tournament->started_at = null;
            $tournament->finished_at = null;
            $tournament->current_round = 0;
            $tournament->prediction_count = 0;
            $tournament->save();
            $this->recount($tournament);
        });
    }

    public function cancel(Tournament $tournament): void
    {
        $this->atomically($tournament, function (Tournament $tournament) {
            if (in_array($tournament->status, [Tournament::FINISHED, Tournament::CANCELLED], true)) {
                $this->fail('tournament', 'cannot_cancel');
            }
            $tournament->status = Tournament::CANCELLED;
            $tournament->save();
        });
    }

    public function delete(Tournament $tournament): void
    {
        $this->atomically($tournament, function (Tournament $tournament) {
            $this->revertAllRatings($tournament);
            $tournament->delete();
        });
    }

    // --------------------------------------------------------------- results

    public function report(Fixture $match, User $actor, int $score1, int $score2): void
    {
        $this->atomically($match->tournament, function (Tournament $tournament) use ($match, $actor, $score1, $score2) {
            $match->refresh();
            $entrants = $this->entrants($tournament);

            $side = null;
            foreach ([1, 2] as $s) {
                if (Gate::speaksFor($actor, $entrants->get((int) $match->{'entrant'.$s.'_id'}))) {
                    $side = $s;
                }
            }
            if ($side === null) {
                throw new PermissionDeniedException();
            }

            if ($tournament->status !== Tournament::RUNNING || ! in_array($match->status, [Fixture::OPEN, Fixture::REPORTED, Fixture::DISPUTED], true)) {
                $this->fail('match', 'not_open');
            }

            $this->assertScores($tournament, $score1, $score2);

            $match->{'report'.$side.'_score1'} = $score1;
            $match->{'report'.$side.'_score2'} = $score2;

            $other = 3 - $side;
            $theirs = $match->{'report'.$other.'_score1'} === null ? null : [$match->{'report'.$other.'_score1'}, $match->{'report'.$other.'_score2'}];
            $disputed = false;
            $awaiting = false;

            if ($theirs === null) {
                $match->status = Fixture::REPORTED;
            } elseif ($theirs !== [$score1, $score2]) {
                $match->status = Fixture::DISPUTED;
                $disputed = true;
            } elseif ($tournament->confirmation === 'staff') {
                $match->status = Fixture::AWAITING;
                $awaiting = true;
            } else {
                $match->save();
                $this->settle($tournament, $match, $this->winnerFrom($match, $score1, $score2), $score1, $score2, null);

                return;
            }

            $match->save();
            $this->events[] = new Event\MatchReported($tournament, $match, $side, $actor, $disputed, $awaiting);
        });
    }

    /**
     * An organiser settles a match: confirms a report, rules on a dispute,
     * awards a forfeit (a winner with no score), or corrects a result that
     * nothing has been built on yet.
     */
    public function resolve(Fixture $match, User $actor, ?int $score1, ?int $score2, ?int $winnerSide): void
    {
        $this->atomically($match->tournament, function (Tournament $tournament) use ($match, $actor, $score1, $score2, $winnerSide) {
            $match->refresh();

            if (! in_array($tournament->status, [Tournament::RUNNING, Tournament::FINISHED], true)) {
                $this->fail('match', 'not_open');
            }
            if ($match->entrant1_id === null || $match->entrant2_id === null) {
                $this->fail('match', 'not_open');
            }

            if (($score1 === null) !== ($score2 === null)) {
                $this->fail('score1', 'both_scores');
            }
            if ($score1 !== null && $score2 !== null) {
                $this->assertScores($tournament, $score1, $score2, $winnerSide !== null);
            }

            $winner = match ($winnerSide) {
                1 => $match->entrant1_id,
                2 => $match->entrant2_id,
                null => $score1 === null ? $this->fail('winner', 'winner_or_scores') : $this->winnerFrom($match, (int) $score1, (int) $score2),
                default => $this->fail('winner', 'winner_or_scores'),
            };

            // A forfeit's scores can't contradict its winner.
            if ($winnerSide !== null && $score1 !== null && $score1 !== $score2 && ($score1 > $score2 ? 1 : 2) !== $winnerSide) {
                $this->fail('winner', 'winner_contradicts_score');
            }

            if ($match->status === Fixture::COMPLETED) {
                // Prizes are paid once. Changing who won them is for someone
                // who could have set them (an organiser might be a finalist's alt).
                if ($tournament->status === Tournament::FINISHED && $tournament->hasPrizes() && ! Gate::canReward($actor)) {
                    $this->fail('match', 'prizes_paid');
                }
                $this->correct($tournament, $match);
            } elseif (! in_array($match->status, Fixture::LIVE, true)) {
                $this->fail('match', 'not_open');
            }

            $this->settle($tournament, $match, $winner, $score1, $score2, $actor);
        });
    }

    /** A result from outside — a finished game in Picks — for a match still being played. */
    public function external(Fixture $match, int $winnerId, ?int $score1, ?int $score2): void
    {
        $this->atomically($match->tournament, function (Tournament $tournament) use ($match, $winnerId, $score1, $score2) {
            $match->refresh();
            if ($tournament->status === Tournament::RUNNING && in_array($match->status, Fixture::LIVE, true) && $match->sideOf($winnerId) !== null) {
                $this->settle($tournament, $match, $winnerId, $score1, $score2, null);
            }
        });
    }

    // ------------------------------------------------------------- internals

    /**
     * @template T
     * @param callable(Tournament): T $work
     * @return T
     */
    private function atomically(Tournament $tournament, callable $work): mixed
    {
        $this->depth++;

        try {
            $result = $this->db->transaction(function () use ($tournament, $work) {
                /** @var Tournament $locked */
                $locked = Tournament::query()->whereKey($tournament->id)->lockForUpdate()->firstOrFail();
                $tournament->setRawAttributes($locked->getAttributes(), true);

                return $work($tournament);
            });
        } catch (\Throwable $e) {
            if (--$this->depth === 0) {
                $this->events = [];
            }

            throw $e;
        }

        if (--$this->depth === 0) {
            $events = $this->events;
            $this->events = [];
            foreach ($events as $event) {
                $this->dispatcher->dispatch($event);
            }
        }

        return $result;
    }

    /** Complete a match and carry everything that follows from it. */
    private function settle(Tournament $tournament, Fixture $match, ?int $winnerId, ?int $score1, ?int $score2, ?User $actor): void
    {
        $fixtures = $this->fixtures($tournament);
        $match = $fixtures[$match->key()];
        $entrants = $this->entrants($tournament);
        $advancer = new Advancer($fixtures);

        $this->record($tournament, $match, $winnerId, $score1, $score2, $actor, $entrants, $advancer);

        // Withdrawn entrants forfeit whatever opens for them.
        $opened = $advancer->opened();
        for ($guard = 0; $guard < 2048 && $tournament->isElimination(); $guard++) {
            $forfeit = null;
            foreach ($advancer->opened() as $key => $open) {
                if ($open->status === Fixture::OPEN && ($side = $this->retiredSide($open, $entrants))) {
                    $forfeit = [$open, $side];
                    break;
                }
            }
            if (! $forfeit) {
                break;
            }
            [$open, $side] = $forfeit;
            $this->record($tournament, $open, (int) $open->{'entrant'.(3 - $side).'_id'}, null, null, null, $entrants, $advancer);
        }

        $this->saveAll($advancer->dirty());
        foreach ($entrants as $entrant) {
            if ($entrant->isDirty()) {
                $entrant->save();
            }
        }

        $this->events[] = new Event\MatchCompleted($tournament, $match, $actor);

        $opened = array_filter($advancer->opened(), fn (Fixture $f) => $f->status === Fixture::OPEN);

        if ($tournament->format === 'swiss') {
            $current = array_filter($fixtures, fn (Fixture $f) => $f->round === $tournament->current_round);
            $roundDone = ! array_filter($current, fn (Fixture $f) => in_array($f->status, Fixture::LIVE, true));
            $active = $entrants->filter(fn (Entrant $e) => $e->status === Entrant::REGISTERED);
            // With fewer than two left in it there is nobody to pair.
            if ($roundDone && $tournament->current_round < $this->swissRounds($tournament) && $active->count() >= 2) {
                $table = Standings::table($this->seeds($active), $fixtures, 'swiss');
                $this->pairSwissRound($tournament, $fixtures, array_column($table, 'entrant'), $tournament->current_round + 1);
                $tournament->current_round++;
                $tournament->save();
                $fixtures = $this->fixtures($tournament);
                $opened = array_filter($fixtures, fn (Fixture $f) => $f->round === $tournament->current_round && $f->status === Fixture::OPEN);
            } elseif ($roundDone) {
                $this->finish($tournament, $fixtures, $entrants);
            }
        } elseif ($advancer->finished()) {
            $this->finish($tournament, $fixtures, $entrants);
        }

        if ($opened) {
            $this->events[] = new Event\MatchesOpened($tournament, array_values($opened));
        }
    }

    /** @param Collection<int, Entrant> $entrants */
    private function record(Tournament $tournament, Fixture $match, ?int $winnerId, ?int $score1, ?int $score2, ?User $actor, Collection $entrants, Advancer $advancer): void
    {
        $match->score1 = $score1;
        $match->score2 = $score2;
        $match->completed_at = Carbon::now();
        $match->resolved_by_id = $actor?->id;
        $match->clearReports();

        if ($tournament->isElimination()) {
            $advancer->complete($match, (int) $winnerId);
        } else {
            $match->winner_id = $winnerId;
            $match->status = Fixture::COMPLETED;
            $match->save();
        }

        $one = $entrants->get((int) $match->entrant1_id);
        $two = $entrants->get((int) $match->entrant2_id);

        if ($one && $two) {
            if ($winnerId === null) {
                $one->draws++;
                $two->draws++;
            } else {
                $winner = $winnerId === $one->id ? $one : $two;
                $loser = $winner === $one ? $two : $one;
                $winner->wins++;
                $loser->losses++;
            }

            // A forfeit is not a game: nothing to rate.
            if ($score1 !== null) {
                $this->ratings->apply($tournament, $match, $one, $two);
            }
        }
    }

    /** Undo a completed result's effects, leaving the match open. */
    private function correct(Tournament $tournament, Fixture $match): void
    {
        $fixtures = $this->fixtures($tournament);
        $match = $fixtures[$match->key()];
        $advancer = new Advancer($fixtures);

        if ($tournament->isElimination() && ! $advancer->correctable($match)) {
            $this->fail('match', 'not_correctable');
        }
        if ($tournament->format === 'swiss' && $match->round < $tournament->current_round) {
            // The next round was paired on this result.
            $this->fail('match', 'not_correctable');
        }

        $entrants = $this->entrants($tournament);
        $one = $entrants->get((int) $match->entrant1_id);
        $two = $entrants->get((int) $match->entrant2_id);

        if ($one && $two) {
            if ($match->winner_id === null) {
                $one->draws--;
                $two->draws--;
            } else {
                $winner = $match->winner_id === $one->id ? $one : $two;
                $loser = $winner === $one ? $two : $one;
                $winner->wins--;
                $loser->losses--;
            }
            $this->ratings->revert($tournament, $match, $one, $two);
            $one->save();
            $two->save();
        }

        if ($tournament->isElimination()) {
            $advancer->reopen($match);
            $this->saveAll($advancer->dirty());
        } else {
            $match->winner_id = null;
            $match->status = Fixture::OPEN;
            $match->save();
        }

        if ($tournament->status === Tournament::FINISHED) {
            $tournament->status = Tournament::RUNNING;
            $tournament->finished_at = null;
            $tournament->save();
            Entrant::query()->where('tournament_id', $tournament->id)->update(['final_rank' => null]);
        }
    }

    /**
     * @param array<string, Fixture> $fixtures
     * @param Collection<int, Entrant> $entrants
     */
    private function finish(Tournament $tournament, array $fixtures, Collection $entrants): void
    {
        $inField = $entrants->filter(fn (Entrant $e) => $e->seed !== null && in_array($e->status, [Entrant::REGISTERED, Entrant::WITHDRAWN, Entrant::DISQUALIFIED], true));

        if ($tournament->isElimination()) {
            $places = Standings::eliminationPlaces($inField->keys()->all(), $fixtures, $tournament->format === 'double');
        } else {
            $places = array_column(Standings::table($this->seeds($inField), $fixtures, $tournament->format), 'rank', 'entrant');
        }

        // One statement for every place; integers only, so built inline.
        $cases = '';
        foreach ($places as $id => $place) {
            $cases .= ' WHEN '.(int) $id.' THEN '.(int) $place;
        }
        if ($cases !== '') {
            Entrant::query()->whereIn('id', array_keys($places))->update([
                'final_rank' => $this->db->raw('CASE '.$this->db->getQueryGrammar()->wrap('id').$cases.' END'),
            ]);
        }

        $tournament->status = Tournament::FINISHED;
        $tournament->finished_at = Carbon::now();
        $tournament->save();

        $this->events[] = new Event\TournamentFinished($tournament);
    }

    /**
     * @param array<string, Fixture> $fixtures
     * @param list<int> $standing
     */
    private function pairSwissRound(Tournament $tournament, array $fixtures, array $standing, int $round): void
    {
        $played = [];
        $hadBye = [];
        foreach ($fixtures as $fixture) {
            if ($fixture->entrant1_id && $fixture->entrant2_id) {
                $played[Swiss::pairKey($fixture->entrant1_id, $fixture->entrant2_id)] = true;
            } elseif ($fixture->status === Fixture::BYE && $fixture->winner_id) {
                $hadBye[$fixture->winner_id] = true;
            }
        }

        $pairing = Swiss::pair($standing, $played, $hadBye);
        $specs = [];
        $position = 0;
        foreach ($pairing['pairs'] as [$a, $b]) {
            $spec = Builder::blank(Builder::SWISS, $round, ++$position);
            $spec['entrant1'] = $a;
            $spec['entrant2'] = $b;
            $specs[Builder::key(Builder::SWISS, $round, $position)] = $spec;
        }
        if ($pairing['bye'] !== null) {
            $spec = Builder::blank(Builder::SWISS, $round, ++$position);
            $spec['entrant1'] = $pairing['bye'];
            $spec['dead2'] = true;
            $specs[Builder::key(Builder::SWISS, $round, $position)] = $spec;
        }

        $fixtures = Builder::fixtures($specs);
        foreach ($fixtures as $fixture) {
            $fixture->status = $fixture->dead2 ? Fixture::BYE : Fixture::OPEN;
            $fixture->winner_id = $fixture->dead2 ? $fixture->entrant1_id : null;
        }

        $this->insert($tournament, $fixtures);
    }

    private function swissRounds(Tournament $tournament): int
    {
        $n = max(2, $tournament->entrant_count);
        $default = (int) ceil(log($n, 2));

        return max(1, min($n - 1, $tournament->swiss_rounds > 0 ? $tournament->swiss_rounds : $default));
    }

    /** @param iterable<Fixture> $fixtures unsaved */
    private function insert(Tournament $tournament, iterable $fixtures): void
    {
        $now = Carbon::now();
        $rows = [];

        foreach ($fixtures as $fixture) {
            $fixture->tournament_id = $tournament->id;
            $fixture->best_of = $tournament->best_of;
            $fixture->created_at = $now;
            $fixture->updated_at = $now;
            // Every row with the same columns, in the same order: a bulk
            // insert takes its column list from the first row.
            $attributes = $fixture->getAttributes();
            $row = [];
            foreach (self::COLUMNS as $column) {
                $row[$column] = $attributes[$column] ?? null;
            }
            $rows[] = $row;
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            Fixture::query()->insert($chunk);
        }
    }

    /** @return array<string, Fixture> */
    private function fixtures(Tournament $tournament): array
    {
        $fixtures = [];
        foreach (Fixture::query()->where('tournament_id', $tournament->id)->orderBy('id')->get() as $fixture) {
            $fixture->setRelation('tournament', $tournament);
            $fixtures[$fixture->key()] = $fixture;
        }

        return $fixtures;
    }

    /** @return Collection<int, Entrant> keyed by id */
    private function entrants(Tournament $tournament): Collection
    {
        return Entrant::query()->where('tournament_id', $tournament->id)->get()->keyBy('id');
    }

    /** @param array<string, Fixture> $fixtures */
    private function saveAll(array $fixtures): void
    {
        foreach ($fixtures as $fixture) {
            if ($fixture->isDirty()) {
                $fixture->save();
            }
        }
    }

    /**
     * Who plays: the registered entrants, or with check-in on, those who
     * checked in, topped up from the checked-in waitlist. Registered entrants
     * who did not check in are marked as no-shows.
     *
     * @return Collection<int, Entrant>
     */
    private function field(Tournament $tournament): Collection
    {
        $all = Entrant::query()->where('tournament_id', $tournament->id)->orderBy('id')->get();
        $registered = $all->where('status', Entrant::REGISTERED)->values();

        if ($tournament->checkin_minutes <= 0) {
            return $registered;
        }

        $field = $registered->filter(fn (Entrant $e) => $e->checked_in_at !== null)->values();
        $cap = $tournament->cap();

        foreach ($all->where('status', Entrant::WAITLISTED) as $waiting) {
            if ($field->count() >= $cap) {
                break;
            }
            if ($waiting->checked_in_at !== null) {
                $waiting->status = Entrant::REGISTERED;
                $waiting->save();
                $field->push($waiting);
            }
        }

        foreach ($registered as $entrant) {
            if ($entrant->checked_in_at === null) {
                $entrant->status = Entrant::NO_SHOW;
                $entrant->save();
            }
        }

        return $field;
    }

    /**
     * Every seed in one statement, however big the field.
     *
     * @param list<int> $ids entrant ids in seed order
     */
    private function storeSeeds(array $ids): void
    {
        // Integers only (ids from the database, seeds counted here), so the
        // CASE is built inline rather than bound.
        $cases = '';
        foreach ($ids as $i => $entrantId) {
            $cases .= ' WHEN '.(int) $entrantId.' THEN '.($i + 1);
        }

        Entrant::query()->whereIn('id', $ids)->update([
            'seed' => $this->db->raw('CASE '.$this->db->getQueryGrammar()->wrap('id').$cases.' END'),
        ]);
    }

    /**
     * @param Collection<int, Entrant> $field
     * @return list<Entrant>
     */
    private function seedOrder(Tournament $tournament, Collection $field): array
    {
        $entrants = $field->values()->all();

        if ($tournament->seeding === 'manual') {
            usort($entrants, fn (Entrant $a, Entrant $b) => [$a->seed ?? PHP_INT_MAX, $a->id] <=> [$b->seed ?? PHP_INT_MAX, $b->id]);

            return $entrants;
        }

        if ($tournament->seeding === 'rating') {
            $ratings = $this->ratings->forEntrants($tournament, $field);
            usort($entrants, fn (Entrant $a, Entrant $b) => [$ratings[$b->id], $a->id] <=> [$ratings[$a->id], $b->id]);

            return $entrants;
        }

        for ($i = count($entrants) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$entrants[$i], $entrants[$j]] = [$entrants[$j], $entrants[$i]];
        }

        return $entrants;
    }

    /**
     * @param Collection<int, Entrant> $entrants
     * @return array<int, int>
     */
    private function seeds(Collection $entrants): array
    {
        $seeds = [];
        foreach ($entrants as $entrant) {
            $seeds[$entrant->id] = $entrant->seed ?? PHP_INT_MAX;
        }

        return $seeds;
    }

    /** @param Collection<int, Entrant> $entrants */
    private function retiredSide(Fixture $match, Collection $entrants): ?int
    {
        foreach ([1, 2] as $side) {
            $entrant = $entrants->get((int) $match->{'entrant'.$side.'_id'});
            if ($entrant && in_array($entrant->status, [Entrant::WITHDRAWN, Entrant::DISQUALIFIED], true)) {
                return $side;
            }
        }

        return null;
    }

    /** Every match a retired entrant is still due to play is a forfeit. */
    private function forfeitLive(Tournament $tournament, User $actor): void
    {
        $entrants = $this->entrants($tournament);

        for ($guard = 0; $guard < 4096; $guard++) {
            $next = null;
            foreach ($this->fixtures($tournament) as $fixture) {
                if (in_array($fixture->status, Fixture::LIVE, true) && ($side = $this->retiredSide($fixture, $entrants))) {
                    $next = [$fixture, $side];
                    break;
                }
            }
            if (! $next) {
                return;
            }
            [$fixture, $side] = $next;
            $this->settle($tournament, $fixture, (int) $fixture->{'entrant'.(3 - $side).'_id'}, null, null, $actor);
            if ($tournament->status !== Tournament::RUNNING) {
                return;
            }
        }
    }

    private function revertAllRatings(Tournament $tournament): void
    {
        $rated = Fixture::query()->where('tournament_id', $tournament->id)->whereNotNull('rating_delta1')->get();
        if ($rated->isEmpty()) {
            return;
        }

        $entrants = $this->entrants($tournament);
        foreach ($rated as $match) {
            $one = $entrants->get((int) $match->entrant1_id);
            $two = $entrants->get((int) $match->entrant2_id);
            if ($one && $two) {
                $this->ratings->revert($tournament, $match, $one, $two);
            }
        }
    }

    private function promote(Tournament $tournament): void
    {
        if (Entrant::query()->where('tournament_id', $tournament->id)->where('status', Entrant::REGISTERED)->count() >= $tournament->cap()) {
            return;
        }

        $next = Entrant::query()->where('tournament_id', $tournament->id)->where('status', Entrant::WAITLISTED)->orderBy('id')->first();
        if ($next) {
            $next->status = Entrant::REGISTERED;
            $next->save();
            $this->events[] = new Event\EntrantPromoted($tournament, $next);
        }
    }

    private function placeFor(Tournament $tournament): string
    {
        $registered = Entrant::query()->where('tournament_id', $tournament->id)->where('status', Entrant::REGISTERED)->count();

        if ($registered >= $tournament->cap()) {
            if (! $tournament->waitlist) {
                $this->fail('tournament', 'full');
            }

            return Entrant::WAITLISTED;
        }

        return Entrant::REGISTERED;
    }

    private function recount(Tournament $tournament): void
    {
        $tournament->entrant_count = Entrant::query()->where('tournament_id', $tournament->id)->where('status', Entrant::REGISTERED)->count();
        $tournament->save();
    }

    private function assertNotEntered(Tournament $tournament, int $userId): void
    {
        if ($this->db->table('gauntlet_entrant_members')->where('tournament_id', $tournament->id)->where('user_id', $userId)->exists()) {
            $this->fail('tournament', 'already_entered');
        }
    }

    private function addMember(Tournament $tournament, Entrant $entrant, int $userId, bool $approved = true): void
    {
        try {
            $this->db->table('gauntlet_entrant_members')->insert([
                'entrant_id' => $entrant->id,
                'user_id' => $userId,
                'tournament_id' => $tournament->id,
                'joined_at' => Carbon::now(),
                'approved' => $approved,
            ]);
        } catch (QueryException) {
            // The unique key caught a second entry racing this one.
            $this->fail('tournament', 'already_entered');
        }
    }

    private function assertScores(Tournament $tournament, int $score1, int $score2, bool $forfeit = false): void
    {
        if ($score1 < 0 || $score2 < 0 || $score1 > 9999 || $score2 > 9999) {
            $this->fail('score1', 'score_range');
        }
        if ($score1 === $score2 && ! $forfeit && ! $tournament->drawsAllowed()) {
            $this->fail('score1', 'no_draws');
        }
    }

    private function winnerFrom(Fixture $match, int $score1, int $score2): ?int
    {
        return $score1 === $score2 ? null : ($score1 > $score2 ? $match->entrant1_id : $match->entrant2_id);
    }

    private function teamName(mixed $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', (string) $name) ?? '');
        if ($name === '' || mb_strlen($name) > 100) {
            $this->fail('name', 'team_name');
        }

        return $name;
    }

    private function fail(string $field, string $key): never
    {
        throw new ValidationException([$field => $this->translator->trans('ernestdefoe-gauntlet.api.'.$key)]);
    }
}
