<?php

namespace ErnestDefoe\Gauntlet\Tests\integration;

use Carbon\Carbon;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;

abstract class GauntletTestCase extends TestCase
{
    use RetrievesAuthorizedUsers;

    /** Members 3–20, beside the admin (1) and the normal user (2). */
    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-gauntlet');

        $users = [$this->normalUser()];
        for ($i = 3; $i <= 20; $i++) {
            $users[] = ['id' => $i, 'username' => "player$i", 'email' => "player$i@machine.local", 'is_email_confirmed' => 1];
        }
        $this->prepareDatabase([User::class => $users]);
    }

    /**
     * Core's email notifications queue one job per recipient; on the test's
     * sync queue each job restores its user and writes an unsubscribe token.
     * That is per person mailed, by design, not per match or entrant.
     */
    protected function allowedRepeatedQueries(): array
    {
        return ['unsubscribe_tokens', 'users"."id" = ? limit ?', 'users`.`id` = ? limit ?'];
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: int, 1: array<string, mixed>|null, 2: string} */
    protected function json(string $method, string $path, ?int $actor = null, ?array $body = null, array $query = []): array
    {
        $options = $actor ? ['authenticatedAs' => $actor] : [];
        if ($body !== null) {
            $options['json'] = $body;
        }

        $request = $this->request($method, $path, $options);
        if ($query) {
            $request = $request->withQueryParams($query);
        }
        $response = $this->send($actor ? $request : $request->withAttribute('bypassCsrfToken', true));
        $raw = (string) $response->getBody();

        return [$response->getStatusCode(), json_decode($raw, true), $raw];
    }

    /** @return array<string, mixed> the created tournament, published unless 'draft' */
    protected function tournament(array $settings = [], bool $publish = true, int $actor = 1): array
    {
        [$status, $body, $raw] = $this->json('POST', '/api/gauntlet/tournaments', $actor, $settings + [
            'title' => 'Spring Cup',
            'game' => 'Chess',
            'format' => 'single',
        ]);
        $this->assertSame(201, $status, $raw);

        if ($publish) {
            [$status, $body, $raw] = $this->json('POST', '/api/gauntlet/tournaments/'.$body['data']['id'].'/publish', $actor);
            $this->assertSame(200, $status, $raw);
        }

        return $body['data'];
    }

    /** @return array<string, mixed> */
    protected function signUp(int $tournamentId, int $actor, array $body = []): array
    {
        [$status, $data, $raw] = $this->json('POST', "/api/gauntlet/tournaments/$tournamentId/entrants", $actor, $body);
        $this->assertSame(200, $status, $raw);

        return $data['data'];
    }

    /** @return array<string, mixed> */
    protected function start(int $tournamentId, int $actor = 1): array
    {
        [$status, $data, $raw] = $this->json('POST', "/api/gauntlet/tournaments/$tournamentId/start", $actor);
        $this->assertSame(200, $status, $raw);

        return $data['data'];
    }

    /** @return array<string, mixed> */
    protected function show(int $tournamentId, ?int $actor = 1): array
    {
        [$status, $data, $raw] = $this->json('GET', "/api/gauntlet/tournaments/$tournamentId", $actor);
        $this->assertSame(200, $status, $raw);

        return $data['data'];
    }

    /** @return array<int, int> entrant id => user id */
    protected function captains(array $tournament): array
    {
        $map = [];
        foreach ($tournament['entrants'] as $e) {
            $map[$e['id']] = $e['user']['id'] ?? 0;
        }

        return $map;
    }

    /** Play every open match: both sides report, entrant 1 wins unless told otherwise. */
    protected function playOpen(array $tournament, ?callable $winnerIsOne = null): array
    {
        $captains = $this->captains($tournament);
        foreach ($tournament['matches'] as $m) {
            if ($m['status'] !== 'open') {
                continue;
            }
            $one = $winnerIsOne ? $winnerIsOne($m) : true;
            $score = $one ? ['score1' => 2, 'score2' => 1] : ['score1' => 0, 'score2' => 2];
            foreach ([1, 2] as $side) {
                [$status, , $raw] = $this->json('POST', "/api/gauntlet/matches/{$m['id']}/report", $captains[$m["entrant{$side}Id"]], $score);
                $this->assertSame(200, $status, $raw);
            }
        }

        return $this->show($tournament['id']);
    }

    protected function match(array $tournament, string $key): array
    {
        foreach ($tournament['matches'] as $m) {
            if ($m['key'] === $key) {
                return $m;
            }
        }
        $this->fail("No match $key");
    }
}
