<?php

namespace ErnestDefoe\Gauntlet\Api;

use ErnestDefoe\Gauntlet\Access\Gate;
use ErnestDefoe\Gauntlet\Integration\Picks\PicksBridge;
use Flarum\User\Exception\PermissionDeniedException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * For organisers building a bracket from Picks:
 * GET /gauntlet/picks/teams?q=      search Picks' teams
 * GET /gauntlet/picks/games?a=&b=   games between two Picks teams
 */
class PicksController implements RequestHandlerInterface
{
    use Concerns;

    public function __construct(private PicksBridge $picks)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor($request);
        if (! Gate::canCreate($actor)) {
            throw new PermissionDeniedException();
        }

        $params = $request->getQueryParams();

        if ($request->getAttribute('routeName') === 'gauntlet.picks.games') {
            return new JsonResponse(['data' => $this->picks->gamesBetween((int) ($params['a'] ?? 0), (int) ($params['b'] ?? 0))]);
        }

        return new JsonResponse(['data' => $this->picks->searchTeams((string) ($params['q'] ?? ''))]);
    }
}
