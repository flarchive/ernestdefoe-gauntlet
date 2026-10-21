<?php

namespace ErnestDefoe\Gauntlet\Tests\integration\api;

use ErnestDefoe\Gauntlet\Tests\integration\GauntletTestCase;
use PHPUnit\Framework\Attributes\Test;

/** A tournament page costs the same number of queries for a small field or a big one. */
class QueryCountTest extends GauntletTestCase
{
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
}
