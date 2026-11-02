<?php

namespace ErnestDefoe\Gauntlet\Tests\integration\api;

use ErnestDefoe\Gauntlet\Tests\integration\GauntletTestCase;
use PHPUnit\Framework\Attributes\Test;

/** A tournament page costs the same number of queries for a small field or a big one. */
class QueryCountTest extends GauntletTestCase
{
    protected function allowedRepeatedQueries(): array
    {
        // A withdrawal from a round robin forfeits each of the leaver's
        // remaining matches: one write per match and one per opponent's win,
        // each a different row with its own values.
        return [...parent::allowedRepeatedQueries(), 'gauntlet_matches` set `winner_id`', 'gauntlet_matches" set "winner_id"', 'set `wins` = ?', 'set "wins" = ?'];
    }

    private function queriesFor(int $tournamentId, ?int $actor): int
    {
        $queries = 0;
        $this->database()->listen(function () use (&$queries) {
            $queries++;
        });
        $this->show($tournamentId, $actor); // warm
        $queries = 0;
        $this->show($tournamentId, $actor);

        return $queries;
    }

    #[Test]
    public function the_page_is_flat_in_the_size_of_the_field(): void
    {
        $counts = [];
        foreach ([3 => 'double', 17 => 'double', 4 => 'round_robin', 12 => 'round_robin'] as $n => $format) {
            $t = $this->tournament(['format' => $format, 'title' => "$format $n"]);
            for ($u = 2; $u < 2 + $n; $u++) {
                $this->signUp($t['id'], $u);
            }
            $t = $this->playOpen($this->start($t['id']));
            $counts[$format][] = [$this->queriesFor($t['id'], null), $this->queriesFor($t['id'], 2), $this->queriesFor($t['id'], 1)];
        }

        foreach ($counts as $format => [$small, $big]) {
            $this->assertSame($small, $big, "$format: guest, member, admin");
        }
    }

    #[Test]
    public function the_list_is_flat_too(): void
    {
        $count = function () {
            $queries = 0;
            $this->database()->listen(function () use (&$queries) {
                $queries++;
            });
            $this->json('GET', '/api/gauntlet/tournaments', 2);
            $queries = 0;
            $this->json('GET', '/api/gauntlet/tournaments', 2);

            return $queries;
        };

        $this->tournament();
        $one = $count();
        for ($i = 0; $i < 9; $i++) {
            $this->tournament(['title' => "Cup $i"], true, 1);
        }
        $this->assertSame($one, $count());
    }
    #[Test]
    public function a_withdrawal_reads_the_bracket_once_however_many_matches_it_forfeits(): void
    {
        $reads = [];
        $count = 0;
        $this->database()->listen(function ($query) use (&$count) {
            if (str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, 'gauntlet_matches')) {
                $count++;
            }
        });

        foreach ([4, 12] as $n) {
            $t = $this->tournament(['format' => 'round_robin', 'title' => "Withdraw $n"]);
            for ($u = 2; $u < 2 + $n; $u++) {
                $this->signUp($t['id'], $u);
            }
            $t = $this->start($t['id']);
            $leaver = collect($t['entrants'])->firstWhere('user.id', 2);

            $count = 0;
            [$status, $body] = $this->json('DELETE', "/api/gauntlet/entrants/{$leaver['id']}", 2);
            $this->assertSame(200, $status);
            $reads[$n] = $count;
            $this->assertCount($n - 1, array_filter($body['data']['matches'], fn ($m) => in_array($leaver['id'], [$m['entrant1Id'], $m['entrant2Id']], true) && $m['status'] === 'completed' && $m['winnerId'] !== $leaver['id']));
        }

        // Three forfeits or eleven: the matches are loaded the same number of times.
        $this->assertSame($reads[4], $reads[12]);
    }
}
