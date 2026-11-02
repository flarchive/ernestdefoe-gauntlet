<?php

namespace ErnestDefoe\Gauntlet\Api;

use Carbon\Carbon;
use ErnestDefoe\Gauntlet\Access\Gate;
use ErnestDefoe\Gauntlet\Director;
use ErnestDefoe\Gauntlet\Discussions;
use ErnestDefoe\Gauntlet\Fixture;
use ErnestDefoe\Gauntlet\Integration\Picks\PicksBridge;
use ErnestDefoe\Gauntlet\Presenter;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Flarum\User\Exception\PermissionDeniedException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST  /gauntlet/matches/{id}/report      a side reports {score1, score2}
 * POST  /gauntlet/matches/{id}/resolve     organiser: {score1, score2} and/or {winner: 1|2}
 * PATCH /gauntlet/matches/{id}             organiser: {scheduledAt, picksEventId}
 * POST  /gauntlet/matches/{id}/discussion  organiser: start its discussion now.
 */
class MatchesController implements RequestHandlerInterface
{
    use Concerns;

    public function __construct(
        private Director $director,
        private Presenter $presenter,
        private Discussions $discussions,
        private PicksBridge $picks,
        private TranslatorInterface $translator,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor($request);
        $match = $this->visibleMatch($actor, $this->routeId($request));
        $tournament = $match->tournament;
        $body = $this->body($request);
        $route = (string) $request->getAttribute('routeName');

        if ($route === 'gauntlet.matches.report') {
            $s1 = $this->intOrNull($body['score1'] ?? null);
            $s2 = $this->intOrNull($body['score2'] ?? null);
            if ($s1 === null || $s2 === null) {
                throw new ValidationException(['score1' => $this->translator->trans('ernestdefoe-gauntlet.api.both_scores')]);
            }
            $this->director->report($match, $actor, $s1, $s2);
        } else {
            if (! Gate::canManage($actor, $tournament)) {
                throw new PermissionDeniedException();
            }

            if ($route === 'gauntlet.matches.resolve') {
                $winner = $this->intOrNull($body['winner'] ?? null);
                if ($winner !== null && ! in_array($winner, [1, 2], true)) {
                    throw new ValidationException(['winner' => $this->translator->trans('ernestdefoe-gauntlet.api.winner_or_scores')]);
                }
                $this->director->resolve($match, $actor, $this->intOrNull($body['score1'] ?? null), $this->intOrNull($body['score2'] ?? null), $winner);
            } elseif ($route === 'gauntlet.matches.discussion') {
                $this->discussions->forMatches($tournament->loadMissing('user'), [$match->setRelation('tournament', $tournament)], true);
            } else {
                $this->update($match, $body);
                // A linked game may have just settled the match, through its own copy.
                $tournament->refresh();
            }
        }

        return new JsonResponse(['data' => $this->presenter->full($tournament->loadMissing('user'), $actor)]);
    }

    /** @param array<string, mixed> $body */
    private function update(Fixture $match, array $body): void
    {
        if (array_key_exists('scheduledAt', $body)) {
            try {
                $match->scheduled_at = $body['scheduledAt'] ? Carbon::parse((string) $body['scheduledAt'])->utc() : null;
            } catch (\Throwable) {
                throw new ValidationException(['scheduledAt' => $this->translator->trans('ernestdefoe-gauntlet.api.date')]);
            }
        }

        $match->save();

        if (array_key_exists('picksEventId', $body)) {
            $id = $this->intOrNull($body['picksEventId']);
            if ($id === null) {
                $match->picks_event_id = null;
                $match->save();
            } elseif (! $this->picks->link($match, $id)) {
                throw new ValidationException(['picksEventId' => $this->translator->trans('ernestdefoe-gauntlet.api.picks_game')]);
            }
        }
    }
}
