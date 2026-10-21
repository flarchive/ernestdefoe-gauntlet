<?php

namespace ErnestDefoe\Gauntlet\Api;

use ErnestDefoe\Gauntlet\Access\Gate;
use ErnestDefoe\Gauntlet\Entrant;
use ErnestDefoe\Gauntlet\Fixture;
use ErnestDefoe\Gauntlet\Tournament;
use Flarum\Http\RequestUtil;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Psr\Http\Message\ServerRequestInterface;

/** The lookups every controller starts with. Missing and hidden answer the same 404. */
trait Concerns
{
    protected function actor(ServerRequestInterface $request): User
    {
        return RequestUtil::getActor($request);
    }

    /** The id in the route, or 0 (not found) for one too big for an id column. */
    protected function routeId(ServerRequestInterface $request, string $name = 'id'): int
    {
        $id = (string) Arr::get($request->getAttributes(), 'routeParameters.'.$name);

        return strlen($id) <= 10 && ctype_digit($id) && (int) $id <= 2147483647 ? (int) $id : 0;
    }

    protected function visibleTournament(User $actor, int $id): Tournament
    {
        $tournament = Tournament::query()->with('user')->find($id);

        if (! $tournament || ! Gate::canView($actor, $tournament)) {
            throw new ModelNotFoundException();
        }

        return $tournament;
    }

    protected function managedTournament(User $actor, int $id): Tournament
    {
        $tournament = $this->visibleTournament($actor, $id);

        if (! Gate::canManage($actor, $tournament)) {
            throw new PermissionDeniedException();
        }

        return $tournament;
    }

    protected function visibleEntrant(User $actor, int $id): Entrant
    {
        $entrant = Entrant::query()->find($id);
        if (! $entrant) {
            throw new ModelNotFoundException();
        }
        $entrant->setRelation('tournament', $this->visibleTournament($actor, $entrant->tournament_id));

        return $entrant;
    }

    protected function visibleMatch(User $actor, int $id): Fixture
    {
        $match = Fixture::query()->find($id);
        if (! $match) {
            throw new ModelNotFoundException();
        }
        $match->setRelation('tournament', $this->visibleTournament($actor, $match->tournament_id));

        return $match;
    }

    /** @return array<string, mixed> */
    protected function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();

        return is_array($body) ? $body : [];
    }

    protected function intOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $int = filter_var($value, FILTER_VALIDATE_INT);

        return $int === false ? null : $int;
    }
}
