<?php

namespace ErnestDefoe\Gauntlet\Job;

use ErnestDefoe\Gauntlet\Discussions;
use ErnestDefoe\Gauntlet\Fixture;
use ErnestDefoe\Gauntlet\Tournament;
use Flarum\Queue\AbstractJob;

/** Match discussions, on the queue: a first round can be a hundred matches. */
class StartMatchDiscussions extends AbstractJob
{
    /** @param list<int> $matchIds */
    public function __construct(public int $tournamentId, public array $matchIds)
    {
    }

    public function handle(Discussions $discussions): void
    {
        $tournament = Tournament::query()->with('user')->find($this->tournamentId);
        if (! $tournament || ! $tournament->match_discussions) {
            return;
        }

        $matches = Fixture::query()->whereIn('id', $this->matchIds)->whereNull('discussion_id')->get()->all();
        $discussions->forMatches($tournament, $matches);
    }
}
