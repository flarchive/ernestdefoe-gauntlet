<?php

namespace ErnestDefoe\Gauntlet\Engine;

use ErnestDefoe\Gauntlet\Fixture;

/**
 * Moves entrants through an elimination bracket.
 *
 * Works on every match of one tournament, loaded once, keyed by
 * {@see Builder::key()}. Completing a match sends its winner on and its loser
 * down (or out); a match whose two sides are both known is then settled:
 * two entrants open it, one entrant and a dead slot is a bye played straight
 * through, two dead slots void it and send "nobody" on. The caller saves
 * {@see dirty()} and announces {@see opened()}.
 */
final class Advancer
{
    /** @var array<string, Fixture> */
    private array $dirty = [];

    /** @var array<string, Fixture> */
    private array $opened = [];

    /** @param array<string, Fixture> $matches */
    public function __construct(private array $matches)
    {
    }

    /** Settle the first round: open real matches, play byes through. */
    public function start(): void
    {
        foreach ($this->matches as $match) {
            if ($match->status === Fixture::PENDING && $this->determined($match)) {
                $this->settle($match);
            }
        }
    }

    public function complete(Fixture $match, int $winnerId): void
    {
        $loserId = $winnerId === $match->entrant1_id ? $match->entrant2_id : $match->entrant1_id;

        $match->winner_id = $winnerId;
        $match->status = Fixture::COMPLETED;
        $this->touch($match);

        if ($match->bracket === Builder::FINAL && $match->round === 1 && ($reset = $this->find(Builder::FINAL, 2, 1))) {
            // The winners-bracket champion (slot 1) has not lost yet; if they
            // win, it is over. If the other side wins, both have one loss.
            if ($winnerId === $match->entrant1_id) {
                $reset->status = Fixture::VOID;
            } else {
                $reset->entrant1_id = $match->entrant1_id;
                $reset->entrant2_id = $match->entrant2_id;
                $reset->status = Fixture::OPEN;
                $this->opened[$this->keyOf($reset)] = $reset;
            }
            $this->touch($reset);

            return;
        }

        $this->send($match, 'next', $winnerId);
        $this->send($match, 'loser', $loserId);
    }

    /**
     * A completed result may be changed while nothing it fed has been played
     * or reported: the matches it sent its winner and loser to are still
     * waiting or open with no report.
     */
    public function correctable(Fixture $match): bool
    {
        if ($match->status !== Fixture::COMPLETED) {
            return false;
        }

        foreach ($this->targets($match) as $target) {
            if (! in_array($target->status, [Fixture::PENDING, Fixture::OPEN, Fixture::VOID], true) || $target->hasReports()) {
                return false;
            }
            // A void reset match is fine; any other void or bye was played through.
            if ($target->status === Fixture::VOID && ! ($target->bracket === Builder::FINAL && $target->round === 2)) {
                return false;
            }
        }

        return true;
    }

    /** Take a completed result back out of the bracket, ready to be completed again. */
    public function reopen(Fixture $match): void
    {
        foreach ($this->targets($match) as $slotKey => $target) {
            if ($target->bracket === Builder::FINAL && $target->round === 2) {
                $target->entrant1_id = null;
                $target->entrant2_id = null;
            } else {
                $target->{'entrant'.substr($slotKey, -1).'_id'} = null;
            }
            $target->status = Fixture::PENDING;
            unset($this->opened[$this->keyOf($target)]);
            $this->touch($target);
        }

        $match->winner_id = null;
        $match->status = Fixture::OPEN;
        $this->touch($match);
    }

    public function finished(): bool
    {
        foreach ($this->matches as $match) {
            if (in_array($match->status, [Fixture::PENDING, Fixture::OPEN, Fixture::REPORTED, Fixture::DISPUTED, Fixture::AWAITING], true)) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, Fixture> */
    public function dirty(): array
    {
        return $this->dirty;
    }

    /** @return array<string, Fixture> */
    public function opened(): array
    {
        return $this->opened;
    }

    private function send(Fixture $from, string $route, ?int $entrantId): void
    {
        $bracket = $from->{$route.'_bracket'};
        if ($bracket === null) {
            return;
        }

        $target = $this->find($bracket, (int) $from->{$route.'_round'}, (int) $from->{$route.'_position'});
        if (! $target) {
            return;
        }

        $slot = (int) $from->{$route.'_slot'};
        if ($entrantId === null) {
            $target->{'dead'.$slot} = true;
        } else {
            $target->{'entrant'.$slot.'_id'} = $entrantId;
        }
        $this->touch($target);

        if ($target->status === Fixture::PENDING && $this->determined($target)) {
            $this->settle($target);
        }
    }

    private function settle(Fixture $match): void
    {
        $one = $match->entrant1_id;
        $two = $match->entrant2_id;

        if ($one !== null && $two !== null) {
            $match->status = Fixture::OPEN;
            $this->opened[$this->keyOf($match)] = $match;
            $this->touch($match);
        } elseif ($one === null && $two === null) {
            $match->status = Fixture::VOID;
            $this->touch($match);
            $this->send($match, 'next', null);
            $this->send($match, 'loser', null);
        } else {
            $winner = (int) ($one ?? $two);
            $match->winner_id = $winner;
            $match->status = Fixture::BYE;
            $this->touch($match);
            $this->send($match, 'next', $winner);
            $this->send($match, 'loser', null);
        }
    }

    private function determined(Fixture $match): bool
    {
        return ($match->entrant1_id !== null || $match->dead1) && ($match->entrant2_id !== null || $match->dead2);
    }

    /** @return array<string, Fixture> the matches a result fed, keyed "route:slot" */
    private function targets(Fixture $match): array
    {
        $targets = [];

        if ($match->bracket === Builder::FINAL && $match->round === 1) {
            if ($reset = $this->find(Builder::FINAL, 2, 1)) {
                $targets['reset:0'] = $reset;
            }

            return $targets;
        }

        foreach (['next', 'loser'] as $route) {
            if ($match->{$route.'_bracket'} !== null && ($target = $this->find($match->{$route.'_bracket'}, (int) $match->{$route.'_round'}, (int) $match->{$route.'_position'}))) {
                $targets[$route.':'.$match->{$route.'_slot'}] = $target;
            }
        }

        return $targets;
    }

    private function find(string $bracket, int $round, int $position): ?Fixture
    {
        return $this->matches[Builder::key($bracket, $round, $position)] ?? null;
    }

    private function keyOf(Fixture $match): string
    {
        return Builder::key($match->bracket, (int) $match->round, (int) $match->position);
    }

    private function touch(Fixture $match): void
    {
        $this->dirty[$this->keyOf($match)] = $match;
    }
}
