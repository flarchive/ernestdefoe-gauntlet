<?php

namespace ErnestDefoe\Gauntlet;

use Flarum\Api\JsonApi;
use Flarum\Api\Resource\DiscussionResource;
use Flarum\Discussion\Discussion;
use Flarum\Extension\ExtensionManager;
use Flarum\Http\UrlGenerator;
use Flarum\Locale\TranslatorInterface;
use Flarum\User\User;
use Laminas\Diactoros\ServerRequest;
use Psr\Log\LoggerInterface;

/**
 * A tournament's discussion, and optionally one per match: real discussions,
 * started through core's own endpoint as the organiser, so they pass the
 * same tag and permission checks as one started from the composer.
 *
 * A thread that can't be started (no tag chosen, the organiser can't post
 * there any more) never stops the tournament; it is logged and skipped.
 */
class Discussions
{
    public function __construct(
        private JsonApi $api,
        private ExtensionManager $extensions,
        private UrlGenerator $url,
        private TranslatorInterface $translator,
        private LoggerInterface $log,
    ) {
    }

    public function forTournament(Tournament $tournament): void
    {
        if ($tournament->discussion_id || ! $tournament->user) {
            return;
        }

        $url = $this->url->to('forum')->route('gauntlet.tournament', ['id' => $tournament->id.'-'.$tournament->slug]);
        $body = $this->translator->trans('ernestdefoe-gauntlet.forum.discussion.tournament_body', ['title' => $tournament->title, 'url' => $url]);
        if ($tournament->description) {
            $body = $tournament->description."\n\n".$body;
        }

        if ($discussion = $this->start($tournament, $tournament->user, $tournament->title, $body)) {
            $tournament->discussion_id = $discussion->id;
            $tournament->save();
        }
    }

    /**
     * @param list<Fixture> $matches
     * @param bool $force an organiser asked for this one, setting or not
     */
    public function forMatches(Tournament $tournament, array $matches, bool $force = false): void
    {
        if ((! $tournament->match_discussions && ! $force) || ! $tournament->user) {
            return;
        }

        $entrants = Entrant::query()->where('tournament_id', $tournament->id)->with('user')->get()->keyBy('id');
        $url = $this->url->to('forum')->route('gauntlet.tournament', ['id' => $tournament->id.'-'.$tournament->slug]);

        foreach ($matches as $match) {
            if ($match->discussion_id) {
                continue;
            }

            $title = $this->translator->trans('ernestdefoe-gauntlet.forum.discussion.match_title', [
                'tournament' => $tournament->title,
                'one' => self::label($entrants->get((int) $match->entrant1_id)),
                'two' => self::label($entrants->get((int) $match->entrant2_id)),
            ]);
            $body = $this->translator->trans('ernestdefoe-gauntlet.forum.discussion.match_body', ['title' => $tournament->title, 'url' => $url.'?match='.$match->id]);

            if ($discussion = $this->start($tournament, $tournament->user, mb_substr($title, 0, 200), $body)) {
                $match->discussion_id = $discussion->id;
                $match->save();
            }
        }
    }

    /** A team's name, or a solo player's display name. */
    public static function label(?Entrant $entrant): string
    {
        if ($entrant && $entrant->name !== '') {
            return $entrant->name;
        }

        return $entrant?->user ? $entrant->user->display_name : '?';
    }

    private function start(Tournament $tournament, User $actor, string $title, string $body): ?Discussion
    {
        try {
            $actor->assertCan('startDiscussion');

            $data = ['attributes' => ['title' => $title, 'content' => $body]];
            if ($this->extensions->isEnabled('flarum-tags') && $tournament->tag_id) {
                $data['relationships']['tags']['data'] = [['type' => 'tags', 'id' => (string) $tournament->tag_id]];
            }

            /** @var Discussion $discussion */
            $discussion = $this->api->forResource(DiscussionResource::class)
                ->forEndpoint('create')
                ->withRequest(new ServerRequest())
                ->process(['data' => $data], [], ['actor' => $actor]);

            return $discussion;
        } catch (\Throwable $e) {
            $this->log->info('[gauntlet] tournament '.$tournament->id.' discussion not started: '.$e->getMessage());

            return null;
        }
    }
}
