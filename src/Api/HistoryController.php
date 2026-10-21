<?php

namespace ErnestDefoe\Gauntlet\Api;

use ErnestDefoe\Gauntlet\Tournament;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /gauntlet/users/{id}: a member's tournaments, placings, prediction
 * brackets and ratings, for their profile. Three queries.
 */
class HistoryController implements RequestHandlerInterface
{
    use Concerns;

    public function __construct(private ConnectionInterface $db)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor($request);
        if (! $actor->hasPermission('gauntlet.view')) {
            throw new PermissionDeniedException();
        }

        $user = User::query()->whereVisibleTo($actor)->find($this->routeId($request));
        if (! $user) {
            throw new ModelNotFoundException();
        }

        $visible = Tournament::query()->whereVisibleTo($actor)->select('id');

        $entries = $this->db->table('gauntlet_entrant_members as m')
            ->join('gauntlet_entrants as e', 'e.id', '=', 'm.entrant_id')
            ->join('gauntlet_tournaments as t', 't.id', '=', 'e.tournament_id')
            ->where('m.user_id', $user->id)
            ->where('m.approved', true)
            ->whereIn('t.id', $visible)
            ->orderByDesc('t.id')
            ->limit(50)
            ->get(['t.id', 't.title', 't.slug', 't.game', 't.format', 't.status', 't.entrant_count', 't.finished_at', 'e.name', 'e.status as entry_status', 'e.final_rank', 'e.wins', 'e.losses', 'e.draws']);

        $better = $this->db->table('gauntlet_predictions as p2')
            ->whereColumn('p2.tournament_id', 'p.tournament_id')
            ->whereColumn('p2.score', '>', 'p.score')
            ->selectRaw('count(*)');

        $predictions = $this->db->table('gauntlet_predictions as p')
            ->join('gauntlet_tournaments as t', 't.id', '=', 'p.tournament_id')
            ->where('p.user_id', $user->id)
            ->whereIn('t.id', Tournament::query()->whereVisibleTo($actor)->select('id'))
            ->orderByDesc('t.id')
            ->limit(50)
            ->select(['t.id', 't.title', 't.slug', 't.status', 't.prediction_count', 'p.score', 'p.correct', 'p.possible'])
            ->selectSub($better, 'ahead')
            ->get();

        $ratings = $this->db->table('gauntlet_ratings')
            ->where('subject_type', 'user')
            ->where('subject_id', $user->id)
            ->where('played', '>', 0)
            ->orderByDesc('rating')
            ->limit(20)
            ->get(['game', 'game_label', 'rating', 'played', 'wins', 'losses', 'draws']);

        return new JsonResponse(['data' => [
            'entries' => $entries->map(fn ($r) => [
                'id' => (int) $r->id, 'title' => $r->title, 'slug' => $r->slug, 'game' => $r->game, 'format' => $r->format,
                'status' => $r->status, 'entrantCount' => (int) $r->entrant_count, 'name' => $r->name,
                'entryStatus' => $r->entry_status, 'finalRank' => $r->final_rank === null ? null : (int) $r->final_rank,
                'wins' => (int) $r->wins, 'losses' => (int) $r->losses, 'draws' => (int) $r->draws,
            ])->all(),
            'predictions' => $predictions->map(fn ($r) => [
                'id' => (int) $r->id, 'title' => $r->title, 'slug' => $r->slug, 'status' => $r->status,
                'count' => (int) $r->prediction_count, 'score' => (int) $r->score, 'correct' => (int) $r->correct,
                'possible' => (int) $r->possible, 'place' => (int) $r->ahead + 1,
            ])->all(),
            'ratings' => $ratings->map(fn ($r) => [
                'game' => $r->game_label !== '' ? $r->game_label : $r->game, 'rating' => (int) $r->rating, 'played' => (int) $r->played,
                'wins' => (int) $r->wins, 'losses' => (int) $r->losses, 'draws' => (int) $r->draws,
            ])->all(),
        ]]);
    }
}
