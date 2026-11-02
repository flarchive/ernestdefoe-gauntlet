<?php

namespace ErnestDefoe\Gauntlet;

use ErnestDefoe\Gauntlet\Access\Gate;
use ErnestDefoe\Gauntlet\Engine\Builder;
use ErnestDefoe\Gauntlet\Integration\Picks\PicksBridge;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

/**
 * Prediction brackets: members pick the winner of every match before the
 * first one is played, and score as the real results come in — 10 points a
 * correct first-round pick, doubling each round, unless the organiser sets
 * other values.
 *
 * Only for single elimination: a pick for a later round has to be one of the
 * member's own picks from the matches that feed it, which is what makes it a
 * bracket and not a list of guesses.
 */
class Predictions
{
    public function __construct(private ConnectionInterface $db, private TranslatorInterface $translator, private PicksBridge $picks)
    {
    }

    /**
     * Picks close at whichever comes first: the lock time, the first result,
     * or kick-off of the first game linked in Picks. A lock time set later
     * than the first game never lets anyone pick with the score known.
     */
    public function open(Tournament $tournament): bool
    {
        if (! $tournament->predictionsOpen()) {
            return false;
        }

        if (Fixture::query()->where('tournament_id', $tournament->id)->where('status', Fixture::COMPLETED)->exists()) {
            return false;
        }

        $games = Fixture::query()->where('tournament_id', $tournament->id)->whereNotNull('picks_event_id')->pluck('picks_event_id')->all();

        return ! $this->picks->anyUnderway($games);
    }

    /** @param array<string, mixed> $picks */
    public function save(Tournament $tournament, User $actor, array $picks, ?int $tiebreaker): Prediction
    {
        if (! Gate::canPredict($actor)) {
            throw new PermissionDeniedException();
        }

        // Entrants in a members' tournament could throw a match to win their own bracket.
        if ($this->db->table('gauntlet_entrant_members')->where('tournament_id', $tournament->id)->where('user_id', $actor->id)->exists()) {
            $this->fail('picks', 'entrants_cannot_predict');
        }

        // Whoever settles the results can't also play for the prizes.
        if ($tournament->hasPrizes() && Gate::canManage($actor, $tournament)) {
            $this->fail('picks', 'organisers_cannot_predict');
        }

        return $this->db->transaction(function () use ($tournament, $actor, $picks, $tiebreaker) {
            // Serialised with the bracket's own changes, so the lock check
            // and the save see the same bracket.
            Tournament::query()->whereKey($tournament->id)->lockForUpdate()->first();

            if (! $this->open($tournament->refresh())) {
                $this->fail('picks', 'predictions_closed');
            }

            $fixtures = $this->fixtures($tournament);
            $clean = $this->validate($fixtures, $picks);

            if ($tiebreaker !== null && ($tiebreaker < 0 || $tiebreaker > 9999)) {
                $this->fail('tiebreaker', 'score_range');
            }

            $prediction = Prediction::query()->where('tournament_id', $tournament->id)->where('user_id', $actor->id)->first();
            $isNew = $prediction === null;
            if ($prediction === null) {
                $prediction = new Prediction();
                $prediction->tournament_id = $tournament->id;
                $prediction->user_id = $actor->id;
            }
            $prediction->picks = $clean;
            $prediction->tiebreaker = $tiebreaker;
            $this->score($prediction, $fixtures, $tournament);
            $prediction->save();

            if ($isNew) {
                $tournament->prediction_count = Prediction::query()->where('tournament_id', $tournament->id)->count();
                $tournament->save();
            }

            return $prediction;
        });
    }

    /** Re-score every prediction after a result, a few hundred at a time. */
    public function rescore(Tournament $tournament): void
    {
        $fixtures = $this->fixtures($tournament);

        Prediction::query()->where('tournament_id', $tournament->id)->orderBy('id')
            ->chunkById(500, function ($predictions) use ($fixtures, $tournament) {
                foreach ($predictions as $prediction) {
                    $this->score($prediction, $fixtures, $tournament);
                    if ($prediction->isDirty()) {
                        $prediction->save();
                    }
                }
            });
    }

    /**
     * Places on the board: score, then the tie-breaker nearest the final's
     * combined score (once there is one), then whoever picked first.
     *
     * @return \Illuminate\Database\Eloquent\Builder<Prediction>
     */
    public function board(Tournament $tournament): \Illuminate\Database\Eloquent\Builder
    {
        $query = Prediction::query()->where('tournament_id', $tournament->id)->orderByDesc('score');
        $total = $this->finalTotal($tournament);

        if ($total !== null) {
            $grammar = $this->db->getQueryGrammar();
            $column = $grammar->wrap('tiebreaker');
            // Bound, never interpolated; no tie-breaker sorts last.
            $query->orderByRaw("CASE WHEN $column IS NULL THEN 1 ELSE 0 END")
                ->orderByRaw("ABS($column - ?)", [$total]);
        }

        return $query->orderBy('updated_at')->orderBy('id');
    }

    public function finalTotal(Tournament $tournament): ?int
    {
        $rounds = (int) Fixture::query()->where('tournament_id', $tournament->id)->where('bracket', Builder::WINNERS)->max('round');
        $final = Fixture::query()->where('tournament_id', $tournament->id)->where('bracket', Builder::WINNERS)->where('round', $rounds)->first();

        return $final && $final->status === Fixture::COMPLETED && $final->score1 !== null ? $final->score1 + (int) $final->score2 : null;
    }

    /**
     * Keep only picks that form a bracket: every pick is a side that can be
     * in that match given the member's earlier picks.
     *
     * @param array<string, Fixture> $fixtures
     * @param array<string, mixed> $picks
     * @return array<string, int>
     */
    public function validate(array $fixtures, array $picks): array
    {
        $clean = [];

        foreach ($this->ordered($fixtures) as $key => $fixture) {
            if (! array_key_exists($key, $picks) || $picks[$key] === null || $picks[$key] === '') {
                continue;
            }

            $pick = filter_var($picks[$key], FILTER_VALIDATE_INT);
            $candidates = $this->candidates($fixtures, $fixture, $clean);

            if ($pick === false || ! in_array($pick, $candidates, true)) {
                $this->fail('picks', 'invalid_pick');
            }

            $clean[$key] = $pick;
        }

        foreach (array_keys($picks) as $key) {
            if (! isset($fixtures[$key])) {
                $this->fail('picks', 'invalid_pick');
            }
        }

        return $clean;
    }

    /**
     * @param array<string, Fixture> $fixtures
     * @param array<string, int> $picks
     * @return list<int> who could be in this match, by these picks
     */
    public function candidates(array $fixtures, Fixture $fixture, array $picks): array
    {
        if (! $this->playable($fixture)) {
            return [];
        }

        $feeders = $this->feeders($fixtures);
        $candidates = [];

        foreach ([1, 2] as $slot) {
            $feeder = $feeders[$fixture->key()][$slot] ?? null;

            if ($feeder === null || ! $this->playable($feeder[0])) {
                // Fed by nothing that needs a pick: whoever is in the slot.
                if ($fixture->{'entrant'.$slot.'_id'} !== null) {
                    $candidates[] = (int) $fixture->{'entrant'.$slot.'_id'};
                }
                continue;
            }

            [$from, $route] = $feeder;
            $picked = $picks[$from->key()] ?? null;
            if ($picked === null) {
                continue;
            }

            if ($route === 'next') {
                $candidates[] = $picked;
            } else {
                // A third-place match takes the side the member did not pick.
                $other = array_values(array_diff($this->candidates($fixtures, $from, $picks), [$picked]));
                if (count($other) === 1) {
                    $candidates[] = $other[0];
                }
            }
        }

        return $candidates;
    }

    /** @param array<string, Fixture> $fixtures */
    public function score(Prediction $prediction, array $fixtures, Tournament $tournament): void
    {
        $out = [];
        foreach ($fixtures as $fixture) {
            if ($fixture->status === Fixture::COMPLETED && $fixture->bracket === Builder::WINNERS) {
                $out[$fixture->winner_id === $fixture->entrant1_id ? $fixture->entrant2_id : $fixture->entrant1_id] = true;
            }
        }

        $score = $correct = $possible = 0;
        foreach ((array) $prediction->picks as $key => $pick) {
            $fixture = $fixtures[$key] ?? null;
            if (! $fixture) {
                continue;
            }

            $points = $tournament->pointsForRound($fixture->bracket === Builder::THIRD ? max(1, $fixture->round - 1) : $fixture->round);

            if ($fixture->status === Fixture::COMPLETED) {
                if ($fixture->winner_id === (int) $pick) {
                    $score += $points;
                    $correct++;
                }
            } elseif (! isset($out[(int) $pick]) || $fixture->bracket === Builder::THIRD) {
                // A third-place pick is someone knocked out of the semi-finals on purpose.
                $possible += $points;
            }
        }

        $prediction->score = $score;
        $prediction->correct = $correct;
        $prediction->possible = $score + $possible;
    }

    /** @return array<string, Fixture> */
    public function fixtures(Tournament $tournament): array
    {
        $fixtures = [];
        foreach (Fixture::query()->where('tournament_id', $tournament->id)->whereIn('bracket', [Builder::WINNERS, Builder::THIRD])->get() as $fixture) {
            $fixtures[$fixture->key()] = $fixture;
        }

        return $fixtures;
    }

    private function playable(Fixture $fixture): bool
    {
        return ! in_array($fixture->status, [Fixture::BYE, Fixture::VOID], true);
    }

    /**
     * @param array<string, Fixture> $fixtures
     * @return array<string, Fixture> earliest rounds first, third place last
     */
    private function ordered(array $fixtures): array
    {
        uasort($fixtures, fn (Fixture $a, Fixture $b) => [$a->bracket === Builder::THIRD, $a->round, $a->position] <=> [$b->bracket === Builder::THIRD, $b->round, $b->position]);

        return $fixtures;
    }

    /**
     * @param array<string, Fixture> $fixtures
     * @return array<string, array<int, array{Fixture, string}>> match key => slot => [feeder, route]
     */
    private function feeders(array $fixtures): array
    {
        $feeders = [];
        foreach ($fixtures as $fixture) {
            foreach (['next', 'loser'] as $route) {
                if ($fixture->{$route.'_bracket'} !== null) {
                    $target = Builder::key($fixture->{$route.'_bracket'}, (int) $fixture->{$route.'_round'}, (int) $fixture->{$route.'_position'});
                    $feeders[$target][(int) $fixture->{$route.'_slot'}] = [$fixture, $route];
                }
            }
        }

        return $feeders;
    }

    private function fail(string $field, string $key): never
    {
        throw new ValidationException([$field => $this->translator->trans('ernestdefoe-gauntlet.api.'.$key)]);
    }
}
