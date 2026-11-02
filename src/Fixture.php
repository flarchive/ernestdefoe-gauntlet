<?php

namespace ErnestDefoe\Gauntlet;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One match. ("Match" is a PHP keyword.).
 *
 * @property int $id
 * @property int $tournament_id
 * @property string $bracket
 * @property int $round
 * @property int $position
 * @property int|null $entrant1_id
 * @property int|null $entrant2_id
 * @property bool $dead1
 * @property bool $dead2
 * @property int|null $score1
 * @property int|null $score2
 * @property int|null $winner_id
 * @property string $status
 * @property int|null $report1_score1
 * @property int|null $report1_score2
 * @property int|null $report2_score1
 * @property int|null $report2_score2
 * @property string|null $next_bracket
 * @property int|null $next_round
 * @property int|null $next_position
 * @property int|null $next_slot
 * @property string|null $loser_bracket
 * @property int|null $loser_round
 * @property int|null $loser_position
 * @property int|null $loser_slot
 * @property int $best_of
 * @property int|null $rating_delta1
 * @property int|null $rating_delta2
 * @property int|null $picks_event_id
 * @property int|null $discussion_id
 * @property int|null $resolved_by_id
 * @property Carbon|null $scheduled_at
 * @property Carbon|null $completed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Tournament $tournament
 */
class Fixture extends AbstractModel
{
    public const PENDING = 'pending';
    public const OPEN = 'open';
    public const REPORTED = 'reported';
    public const DISPUTED = 'disputed';
    public const AWAITING = 'awaiting';
    public const COMPLETED = 'completed';
    public const BYE = 'bye';
    public const VOID = 'void';

    /** Waiting on the players or on staff. */
    public const LIVE = [self::OPEN, self::REPORTED, self::DISPUTED, self::AWAITING];

    protected $table = 'gauntlet_matches';

    public $timestamps = true;

    protected $attributes = [
        'status' => self::PENDING,
        'dead1' => false,
        'dead2' => false,
        'best_of' => 1,
    ];

    protected $casts = [
        'tournament_id' => 'integer',
        'round' => 'integer',
        'position' => 'integer',
        'entrant1_id' => 'integer',
        'entrant2_id' => 'integer',
        'dead1' => 'boolean',
        'dead2' => 'boolean',
        'score1' => 'integer',
        'score2' => 'integer',
        'winner_id' => 'integer',
        'report1_score1' => 'integer',
        'report1_score2' => 'integer',
        'report2_score1' => 'integer',
        'report2_score2' => 'integer',
        'next_round' => 'integer',
        'next_position' => 'integer',
        'next_slot' => 'integer',
        'loser_round' => 'integer',
        'loser_position' => 'integer',
        'loser_slot' => 'integer',
        'best_of' => 'integer',
        'rating_delta1' => 'integer',
        'rating_delta2' => 'integer',
        'picks_event_id' => 'integer',
        'discussion_id' => 'integer',
        'resolved_by_id' => 'integer',
        'scheduled_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /** @return BelongsTo<Tournament, $this> */
    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    public function key(): string
    {
        return Engine\Builder::key($this->bracket, (int) $this->round, (int) $this->position);
    }

    public function hasReports(): bool
    {
        return $this->report1_score1 !== null || $this->report2_score1 !== null;
    }

    /** 1 or 2 for an entrant in this match, else null. */
    public function sideOf(?int $entrantId): ?int
    {
        if ($entrantId === null) {
            return null;
        }

        return match ($entrantId) {
            $this->entrant1_id => 1,
            $this->entrant2_id => 2,
            default => null,
        };
    }

    public function clearReports(): void
    {
        $this->report1_score1 = $this->report1_score2 = $this->report2_score1 = $this->report2_score2 = null;
    }
}
