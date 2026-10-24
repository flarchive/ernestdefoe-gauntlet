<?php

namespace ErnestDefoe\Gauntlet\Job;

use ErnestDefoe\Gauntlet\Listener\Announce;
use ErnestDefoe\Gauntlet\Predictions;
use ErnestDefoe\Gauntlet\Tournament;
use Flarum\Queue\AbstractJob;

/** Re-score a prediction bracket after a result; pays the best predictors once it is over. */
class RescorePredictions extends AbstractJob
{
    public function __construct(public int $tournamentId)
    {
    }

    public function handle(Predictions $predictions, Announce $announce): void
    {
        $tournament = Tournament::query()->find($this->tournamentId);
        if (! $tournament || ! $tournament->predictions) {
            return;
        }

        $predictions->rescore($tournament);

        if ($tournament->status === Tournament::FINISHED && $tournament->isStaffEntered()) {
            $announce->payPredictors($tournament);
        }
    }
}
