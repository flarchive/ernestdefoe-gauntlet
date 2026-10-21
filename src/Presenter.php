<?php

namespace ErnestDefoe\Gauntlet;

use ErnestDefoe\Gauntlet\Access\Gate;
use ErnestDefoe\Gauntlet\Engine\Standings;
use ErnestDefoe\Gauntlet\Integration\Picks\PicksBridge;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;

/**
 * Tournaments as the forum sees them. A whole tournament — field, bracket,
 * table, the actor's own state — costs a fixed handful of queries however
 * big it is: entrants, members, users, matches, ratings, and (with Picks)
 * teams and games, each loaded once.
 */
class Presenter
{
    public function __construct(
        private ConnectionInterface $db,
        private Ratings $ratings,
        private Teams $teams,
        private Predictions $predictions,
        private PicksBridge $picks,
    ) {
    }

    /** @return array<string, mixed> */
    public function summary(Tournament $tournament, User $actor): array
    {
        return [
            'id' => $tournament->id,
            'title' => $tournament->title,
            'slug' => $tournament->slug,
            'game' => $tournament->game,
            'format' => $tournament->format,
            'entryMode' => $tournament->entry_mode,
            'entryType' => $tournament->entry_type,
            'status' => $tournament->status,
            'maxEntrants' => $tournament->max_entrants,
            'entrantCount' => $tournament->entrant_count,
            'predictions' => $tournament->predictions,
            'predictionCount' => $tournament->prediction_count,
            'startsAt' => $tournament->starts_at?->toIso8601String(),
            'startedAt' => $tournament->started_at?->toIso8601String(),
            'finishedAt' => $tournament->finished_at?->toIso8601String(),
            'organiser' => $this->user($tournament->user),
        ];
    }

    /** @return array<string, mixed> */
    public function full(Tournament $tournament, User $actor): array
    {
        $manages = Gate::canManage($actor, $tournament);

        $entrants = Entrant::query()->where('tournament_id', $tournament->id)->orderBy('id')->get()->keyBy('id');
        $memberRows = $this->db->table('gauntlet_entrant_members')->where('tournament_id', $tournament->id)->orderBy('joined_at')->get(['entrant_id', 'user_id', 'approved']);
        $userIds = $memberRows->pluck('user_id')->merge($entrants->pluck('user_id'))->filter()->unique()->values()->all();
        $users = $userIds ? User::query()->whereVisibleTo($actor)->whereIn('id', $userIds)->get()->keyBy('id') : new Collection();
        $matches = Fixture::query()->where('tournament_id', $tournament->id)->orderBy('bracket')->orderBy('round')->orderBy('position')->get();

        $members = [];
        $pending = [];
        $mine = null;
        $asked = null;
        foreach ($memberRows as $row) {
            if ($row->approved) {
                $members[(int) $row->entrant_id][] = (int) $row->user_id;
            } else {
                $pending[(int) $row->entrant_id][] = (int) $row->user_id;
            }
            if ((int) $row->user_id === $actor->id) {
                if ($row->approved) {
                    $mine = (int) $row->entrant_id;
                } else {
                    $asked = (int) $row->entrant_id;
                }
            }
        }

        $ratings = $tournament->isStaffEntered() ? [] : $this->ratings->forEntrants($tournament, $entrants);
        $teams = $this->picks->teams($entrants->pluck('picks_team_id')->filter()->all());
        $games = $this->picks->games($matches->pluck('picks_event_id')->filter()->all());

        $data = $this->summary($tournament, $actor) + [
            'description' => $tournament->description,
            'teamSize' => $tournament->team_size,
            'waitlist' => $tournament->waitlist,
            'checkinMinutes' => $tournament->checkin_minutes,
            'checkinOpensAt' => $tournament->checkinOpensAt()?->toIso8601String(),
            'registrationClosesAt' => $tournament->registration_closes_at?->toIso8601String(),
            'seeding' => $tournament->seeding,
            'bestOf' => $tournament->best_of,
            'thirdPlace' => $tournament->third_place,
            'grandFinalReset' => $tournament->grand_final_reset,
            'swissRounds' => $tournament->swiss_rounds,
            'currentRound' => $tournament->current_round,
            'allowDraws' => $tournament->allow_draws,
            'confirmation' => $tournament->confirmation,
            'matchDiscussions' => $tournament->match_discussions,
            'predictionsLockAt' => $tournament->predictions_lock_at?->toIso8601String(),
            'predictionPoints' => $tournament->prediction_points,
            'discussionId' => $tournament->discussion_id,
            'tagId' => $tournament->tag_id,
        ];

        if ($manages) {
            $data['rewardPoints'] = $tournament->reward_points;
            $data['rewardBadgeId'] = $tournament->reward_badge_id;
        }

        $data['entrants'] = $entrants->map(function (Entrant $e) use ($users, $members, $pending, $ratings, $teams, $actor, $manages) {
            $team = $e->picks_team_id ? ($teams[$e->picks_team_id] ?? null) : null;
            $row = [
                'id' => $e->id,
                'name' => $e->name !== '' ? $e->name : ($team['name'] ?? ''),
                'user' => $this->user($e->user_id ? $users->get($e->user_id) : null),
                'groupId' => $e->group_id,
                'logoUrl' => $e->logo_url ?? ($team['logoUrl'] ?? null),
                'picksTeamId' => $e->picks_team_id,
                'status' => $e->status,
                'seed' => $e->seed,
                'checkedIn' => $e->checked_in_at !== null,
                'finalRank' => $e->final_rank,
                'wins' => $e->wins,
                'losses' => $e->losses,
                'draws' => $e->draws,
                'rating' => $ratings[$e->id] ?? null,
                'members' => array_values(array_filter(array_map(fn (int $id) => $this->user($users->get($id)), $members[$e->id] ?? []))),
            ];

            // Requests to join: for the captain and organisers to answer.
            if ($manages || ($e->user_id !== null && $e->user_id === $actor->id)) {
                $row['requests'] = array_values(array_filter(array_map(fn (int $id) => $this->user($users->get($id)), $pending[$e->id] ?? [])));
            }

            return $row;
        })->values()->all();

        $data['matches'] = $matches->map(function (Fixture $m) use ($manages, $entrants, $actor, $games) {
            $row = [
                'id' => $m->id,
                'bracket' => $m->bracket,
                'round' => $m->round,
                'position' => $m->position,
                'key' => $m->key(),
                'entrant1Id' => $m->entrant1_id,
                'entrant2Id' => $m->entrant2_id,
                'dead1' => $m->dead1,
                'dead2' => $m->dead2,
                'score1' => $m->score1,
                'score2' => $m->score2,
                'winnerId' => $m->winner_id,
                'status' => $m->status,
                'bestOf' => $m->best_of,
                'discussionId' => $m->discussion_id,
                'scheduledAt' => $m->scheduled_at?->toIso8601String(),
                'completedAt' => $m->completed_at?->toIso8601String(),
                'picksEventId' => $m->picks_event_id,
                // Where the winner and loser go, so the client draws the
                // bracket's lines without its own copy of the rules.
                'next' => $m->next_bracket ? [Engine\Builder::key($m->next_bracket, (int) $m->next_round, (int) $m->next_position), $m->next_slot] : null,
                'loser' => $m->loser_bracket ? [Engine\Builder::key($m->loser_bracket, (int) $m->loser_round, (int) $m->loser_position), $m->loser_slot] : null,
                'game' => $m->picks_event_id ? ($games[$m->picks_event_id] ?? null) : null,
            ];

            // Each side's report: to the two sides and the organisers only.
            $side = $m->sideOf($this->entrantFor($entrants, $actor));
            if ($manages || $side !== null) {
                $row['reports'] = [
                    1 => $m->report1_score1 === null ? null : [$m->report1_score1, $m->report1_score2],
                    2 => $m->report2_score1 === null ? null : [$m->report2_score1, $m->report2_score2],
                ];
            }
            if ($manages) {
                $row['correctable'] = $m->status === Fixture::COMPLETED;
            }

            return $row;
        })->values()->all();

        if (! $tournament->isElimination() && $matches->isNotEmpty()) {
            $field = $entrants->filter(fn (Entrant $e) => $e->seed !== null && ! in_array($e->status, [Entrant::NO_SHOW, Entrant::WAITLISTED], true));
            $seeds = [];
            foreach ($field as $e) {
                $seeds[$e->id] = $e->seed;
            }
            $data['standings'] = Standings::table($seeds, $matches->all(), $tournament->format);
        }

        $data['me'] = [
            'entrantId' => $mine,
            'requestedEntrantId' => $asked,
            'canManage' => $manages,
            'canReward' => $manages && Gate::canReward($actor),
            'canJoin' => Gate::canJoin($actor),
            'canPredict' => $tournament->predictions && Gate::canPredict($actor) && $mine === null && $asked === null && ! ($manages && $tournament->hasPrizes()),
            'predictionsOpen' => $tournament->predictions && $this->predictions->open($tournament),
            'groups' => $tournament->isTeam() && $tournament->status === Tournament::REGISTRATION ? $this->teams->captainedGroups($actor) : [],
            'prediction' => null,
            'picksAvailable' => $manages && $tournament->isStaffEntered() && $this->picks->available(),
        ];

        if ($tournament->predictions && ! $actor->isGuest()) {
            $prediction = Prediction::query()->where('tournament_id', $tournament->id)->where('user_id', $actor->id)->first();
            if ($prediction) {
                $data['me']['prediction'] = $this->prediction($prediction);
            }
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public function prediction(Prediction $prediction): array
    {
        return [
            'picks' => (object) $prediction->picks,
            'tiebreaker' => $prediction->tiebreaker,
            'score' => $prediction->score,
            'correct' => $prediction->correct,
            'possible' => $prediction->possible,
            'user' => $prediction->relationLoaded('user') ? $this->user($prediction->user) : null,
        ];
    }

    /** @return array<string, mixed>|null */
    public function user(?User $user): ?array
    {
        if (! $user) {
            return null;
        }

        return [
            'id' => $user->id,
            'username' => $user->username,
            'displayName' => $user->display_name,
            'avatarUrl' => $user->avatar_url,
        ];
    }

    /** @param Collection<int, Entrant> $entrants */
    private function entrantFor(Collection $entrants, User $actor): ?int
    {
        if ($actor->isGuest()) {
            return null;
        }

        foreach ($entrants as $entrant) {
            if ($entrant->user_id === $actor->id) {
                return $entrant->id;
            }
        }

        return null;
    }
}
