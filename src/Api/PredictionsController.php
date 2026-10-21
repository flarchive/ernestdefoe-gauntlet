<?php

namespace ErnestDefoe\Gauntlet\Api;

use ErnestDefoe\Gauntlet\Predictions;
use ErnestDefoe\Gauntlet\Presenter;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * PUT /gauntlet/tournaments/{id}/prediction    save the actor's bracket {picks: {key: entrantId}, tiebreaker}
 * GET /gauntlet/tournaments/{id}/predictions   the board, 50 a page.
 */
class PredictionsController implements RequestHandlerInterface
{
    use Concerns;

    private const PER_PAGE = 50;

    public function __construct(private Predictions $predictions, private Presenter $presenter)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor($request);
        $tournament = $this->visibleTournament($actor, $this->routeId($request));

        if ($request->getMethod() === 'PUT') {
            $body = $this->body($request);
            $picks = is_array($body['picks'] ?? null) ? $body['picks'] : [];
            $prediction = $this->predictions->save($tournament, $actor, $picks, $this->intOrNull($body['tiebreaker'] ?? null));

            return new JsonResponse(['data' => $this->presenter->prediction($prediction)]);
        }

        $page = max(1, min(1000, (int) ($request->getQueryParams()['page'] ?? 1)));
        $offset = ($page - 1) * self::PER_PAGE;

        $rows = $tournament->predictions
            ? $this->predictions->board($tournament)->with('user')->offset($offset)->limit(self::PER_PAGE + 1)->get()
            : collect();

        $data = [];
        foreach ($rows->take(self::PER_PAGE)->values() as $i => $prediction) {
            // Picks stay private until they lock: nobody copies the leader's bracket.
            $row = $this->presenter->prediction($prediction);
            if ($this->predictions->open($tournament) && $prediction->user_id !== $actor->id) {
                $row['picks'] = null;
                $row['tiebreaker'] = null;
            }
            $data[] = ['place' => $offset + $i + 1] + $row;
        }

        return new JsonResponse(['data' => $data, 'meta' => ['page' => $page, 'more' => $rows->count() > self::PER_PAGE]]);
    }
}
