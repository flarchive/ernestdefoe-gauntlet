<?php

namespace ErnestDefoe\Gauntlet\Api;

use ErnestDefoe\Gauntlet\Access\Gate;
use ErnestDefoe\Gauntlet\Director;
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
 * POST   /gauntlet/tournaments/{id}/entrants      sign up (or, for organisers of a staff-entered bracket, add a team)
 * PATCH  /gauntlet/entrants/{id}                  organiser: seed, waitlist, name, logo, Picks team
 * DELETE /gauntlet/entrants/{id}                  withdraw (?disqualify=1 for organisers)
 * POST   /gauntlet/entrants/{id}/checkin          check in (DELETE to undo)
 * POST   /gauntlet/entrants/{id}/members          join a team (a request, for an ad-hoc team)
 * POST   /gauntlet/entrants/{id}/members/{user}   the captain approves a request
 * DELETE /gauntlet/entrants/{id}/members/{user}   leave, or take someone off.
 */
class EntrantsController implements RequestHandlerInterface
{
    use Concerns;

    public function __construct(
        private Director $director,
        private Presenter $presenter,
        private PicksBridge $picks,
        private TranslatorInterface $translator,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor($request);
        $route = (string) $request->getAttribute('routeName');
        $body = $this->body($request);

        if ($route === 'gauntlet.entrants.create') {
            $tournament = $this->visibleTournament($actor, $this->routeId($request));

            if ($tournament->isStaffEntered()) {
                if (! Gate::canManage($actor, $tournament)) {
                    throw new PermissionDeniedException();
                }
                [$name, $teamId, $logo] = $this->staffFields($body);
                $this->director->addStaffEntrant($tournament, $name, $logo, $teamId);
            } else {
                $this->director->signUp($tournament, $actor, $body);
            }

            return $this->respond($tournament->loadMissing('user'), $actor);
        }

        $entrant = $this->visibleEntrant($actor, $this->routeId($request));
        $tournament = $entrant->tournament;

        switch ($route) {
            case 'gauntlet.entrants.update':
                if (! Gate::canManage($actor, $tournament)) {
                    throw new PermissionDeniedException();
                }
                $setTeam = array_key_exists('picksTeamId', $body);
                $setLogo = array_key_exists('logoUrl', $body);
                [$name, $teamId, $logo] = $setTeam || $setLogo || array_key_exists('name', $body)
                    ? $this->staffFields($body + ['name' => $entrant->name], false)
                    : [null, null, null];
                $this->director->updateEntrant(
                    $entrant,
                    array_key_exists('seed', $body) ? (int) $this->intOrNull($body['seed']) : null,
                    ! empty($body['promote']),
                    array_key_exists('name', $body) ? $name : null,
                    $logo,
                    $teamId,
                    $setLogo,
                    $setTeam,
                );
                break;
            case 'gauntlet.entrants.delete':
                $this->director->withdraw($entrant, $actor, ! empty($request->getQueryParams()['disqualify']));
                break;
            case 'gauntlet.entrants.checkin':
                $this->director->checkIn($entrant, $actor, $request->getMethod() === 'POST');
                break;
            case 'gauntlet.entrants.join':
                $this->director->joinTeam($entrant, $actor);
                break;
            case 'gauntlet.entrants.approve':
                $this->director->approveMember($entrant, $this->routeId($request, 'user'), $actor);
                break;
            case 'gauntlet.entrants.leave':
                $this->director->removeMember($entrant, $this->routeId($request, 'user'), $actor);
                break;
        }

        return $this->respond($tournament->loadMissing('user'), $actor);
    }

    /**
     * A staff-entered team: a Picks team (its name and logo come along) or a
     * name, with an optional https logo.
     *
     * @param array<string, mixed> $body
     * @return array{0: string, 1: int|null, 2: string|null}
     */
    private function staffFields(array $body, bool $creating = true): array
    {
        $teamId = $this->intOrNull($body['picksTeamId'] ?? null);
        $name = trim((string) ($body['name'] ?? ''));

        if ($teamId !== null) {
            $team = $this->picks->teams([$teamId])[$teamId] ?? null;
            if (! $team) {
                throw new ValidationException(['picksTeamId' => $this->translator->trans('ernestdefoe-gauntlet.api.picks_team')]);
            }
            if ($name === '' || $creating) {
                $name = $name !== '' ? $name : $team['name'];
            }
        }

        return [$name, $teamId, $this->logo($body['logoUrl'] ?? null)];
    }

    /** Only an https image address, nothing that could run or reach inside. */
    private function logo(mixed $value): ?string
    {
        $url = trim((string) $value);
        if ($url === '') {
            return null;
        }

        $parts = parse_url($url);
        if (mb_strlen($url) > 500 || preg_match('/[\x00-\x20"\'<>\\\\]/', $url) || ! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new ValidationException(['logoUrl' => $this->translator->trans('ernestdefoe-gauntlet.api.logo_url')]);
        }

        return $url;
    }

    private function respond(\ErnestDefoe\Gauntlet\Tournament $tournament, \Flarum\User\User $actor): ResponseInterface
    {
        return new JsonResponse(['data' => $this->presenter->full($tournament, $actor)]);
    }
}
