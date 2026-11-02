<?php

namespace ErnestDefoe\Gauntlet\Api;

use ErnestDefoe\Gauntlet\Access\Gate;
use ErnestDefoe\Gauntlet\Director;
use ErnestDefoe\Gauntlet\Discussions;
use ErnestDefoe\Gauntlet\Integration\PlayoffBoard\PlayoffBoardBridge;
use ErnestDefoe\Gauntlet\Presenter;
use ErnestDefoe\Gauntlet\Saver;
use ErnestDefoe\Gauntlet\Tournament;
use Flarum\User\Exception\PermissionDeniedException;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET    /gauntlet/tournaments             list
 * POST   /gauntlet/tournaments             create
 * GET    /gauntlet/tournaments/{id}        one, whole
 * PATCH  /gauntlet/tournaments/{id}        edit
 * DELETE /gauntlet/tournaments/{id}        delete
 * POST   /gauntlet/tournaments/{id}/{action}  publish | start | reset | cancel | discussion | playoff-board (fill a staff field from the Playoff Board).
 */
class TournamentsController implements RequestHandlerInterface
{
    use Concerns;

    private const PER_PAGE = 20;

    public function __construct(
        private Presenter $presenter,
        private Saver $saver,
        private Director $director,
        private Discussions $discussions,
        private ConnectionInterface $db,
        private PlayoffBoardBridge $playoffBoard,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor($request);
        $id = $this->routeId($request);
        $method = $request->getMethod();
        $action = (string) ($request->getAttribute('routeParameters')['action'] ?? '');

        if ($action !== '') {
            return $this->act($request, $id, $action);
        }

        if ($id === 0 && $method === 'GET') {
            return $this->index($request);
        }

        if ($id === 0 && $method === 'POST') {
            if (! Gate::canCreate($actor)) {
                throw new PermissionDeniedException();
            }
            $tournament = $this->saver->save(new Tournament(), $actor, $this->body($request));

            return new JsonResponse(['data' => $this->presenter->full($tournament->loadMissing('user'), $actor)], 201);
        }

        if ($method === 'GET') {
            return new JsonResponse(['data' => $this->presenter->full($this->visibleTournament($actor, $id), $actor)]);
        }

        $tournament = $this->managedTournament($actor, $id);

        if ($method === 'DELETE') {
            $this->director->delete($tournament);

            return new EmptyResponse(204);
        }

        $this->saver->save($tournament, $actor, $this->body($request));

        return new JsonResponse(['data' => $this->presenter->full($tournament->loadMissing('user'), $actor)]);
    }

    private function act(ServerRequestInterface $request, int $id, string $action): ResponseInterface
    {
        $actor = $this->actor($request);
        $tournament = $this->managedTournament($actor, $id);

        match ($action) {
            'publish' => $this->director->publish($tournament),
            'start' => $this->director->start($tournament, $actor),
            'reset' => $this->director->reset($tournament),
            'cancel' => $this->director->cancel($tournament),
            'discussion' => null,
            'playoff-board' => $this->director->fillField($tournament, ($this->playoffBoard->field() ?? throw new \Illuminate\Database\Eloquent\ModelNotFoundException())['teams']),
            default => throw new \Illuminate\Database\Eloquent\ModelNotFoundException(),
        };

        // The tournament's thread opens with sign-ups (or by hand, for one
        // published before a tag was set).
        if (in_array($action, ['publish', 'discussion'], true) && $tournament->status !== Tournament::DRAFT) {
            $this->discussions->forTournament($tournament->loadMissing('user'));
        }

        return new JsonResponse(['data' => $this->presenter->full($tournament->loadMissing('user'), $actor)]);
    }

    private function index(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor($request);
        if (! $actor->hasPermission('gauntlet.view')) {
            throw new PermissionDeniedException();
        }

        $params = $request->getQueryParams();
        $filter = (string) ($params['filter'] ?? 'all');
        $page = max(1, min(1000, (int) ($params['page'] ?? 1)));

        $query = Tournament::query()->whereVisibleTo($actor)->with('user');

        match ($filter) {
            'open' => $query->whereIn('status', [Tournament::REGISTRATION]),
            'running' => $query->where('status', Tournament::RUNNING),
            'finished' => $query->where('status', Tournament::FINISHED),
            'drafts' => $query->where('status', Tournament::DRAFT),
            'mine' => $actor->isGuest() ? $query->whereRaw('1 = 0') : $query->where(function ($q) use ($actor) {
                $q->where('user_id', $actor->id)
                    ->orWhereIn('id', $this->db->table('gauntlet_entrant_members')->where('user_id', $actor->id)->select('tournament_id'))
                    ->orWhereIn('id', $this->db->table('gauntlet_predictions')->where('user_id', $actor->id)->select('tournament_id'));
            }),
            default => $query->where('status', '!=', Tournament::CANCELLED),
        };

        // Running first, then open for sign-ups, then the rest, newest first.
        $status = $this->db->getQueryGrammar()->wrap('status');
        $tournaments = $query
            ->orderByRaw("CASE $status WHEN 'running' THEN 0 WHEN 'registration' THEN 1 WHEN 'draft' THEN 2 ELSE 3 END")
            ->orderByDesc('id')
            ->offset(($page - 1) * self::PER_PAGE)
            ->limit(self::PER_PAGE + 1)
            ->get();

        $more = $tournaments->count() > self::PER_PAGE;

        return new JsonResponse([
            'data' => $tournaments->take(self::PER_PAGE)->map(fn (Tournament $t) => $this->presenter->summary($t, $actor))->values()->all(),
            'meta' => ['page' => $page, 'more' => $more, 'canCreate' => Gate::canCreate($actor)],
        ]);
    }
}
