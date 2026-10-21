<?php

namespace ErnestDefoe\Gauntlet\Engine;

/**
 * Pairs a Swiss round: entrants on similar scores meet, nobody meets the same
 * opponent twice when that can be avoided, and the bye (with an odd number)
 * goes to the lowest-placed entrant who has not had one.
 */
final class Swiss
{
    /** Search steps before giving up on a rematch-free pairing. */
    private const BUDGET = 20000;

    private int $steps = 0;

    /**
     * @param list<int> $standing entrant ids, best placed first
     * @param array<string, true> $played "a-b" keys (lower id first) of pairs that already met
     * @param array<int, true> $hadBye
     * @return array{pairs: list<array{int, int}>, bye: int|null}
     */
    public static function pair(array $standing, array $played, array $hadBye): array
    {
        $bye = null;
        if (count($standing) % 2 === 1) {
            for ($i = count($standing) - 1; $i >= 0; $i--) {
                if (! isset($hadBye[$standing[$i]])) {
                    $bye = $standing[$i];
                    break;
                }
            }
            $bye ??= $standing[count($standing) - 1];
            $standing = array_values(array_filter($standing, fn (int $id) => $id !== $bye));
        }

        $swiss = new self();
        $pairs = $swiss->search($standing, $played);

        if ($pairs === null) {
            // No way round a rematch: pair straight down the table.
            $pairs = [];
            for ($i = 0; $i + 1 < count($standing); $i += 2) {
                $pairs[] = [$standing[$i], $standing[$i + 1]];
            }
        }

        return ['pairs' => $pairs, 'bye' => $bye];
    }

    public static function pairKey(int $a, int $b): string
    {
        return min($a, $b).'-'.max($a, $b);
    }

    /**
     * @phpstan-impure counts its steps against the budget
     *
     * @param list<int> $rest
     * @param array<string, true> $played
     * @return list<array{int, int}>|null
     */
    private function search(array $rest, array $played): ?array
    {
        if ($rest === []) {
            return [];
        }

        if (++$this->steps > self::BUDGET) {
            return null;
        }

        $first = array_shift($rest);

        foreach ($rest as $i => $candidate) {
            if (isset($played[self::pairKey($first, $candidate)])) {
                continue;
            }

            $remaining = $rest;
            unset($remaining[$i]);
            $tail = $this->search(array_values($remaining), $played);

            if ($tail !== null) {
                return [[$first, $candidate], ...$tail];
            }

            if ($this->steps > self::BUDGET) {
                return null;
            }
        }

        return null;
    }
}
