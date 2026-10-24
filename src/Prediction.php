<?php

namespace ErnestDefoe\Gauntlet;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A member's picks for a prediction bracket.
 *
 * @property int $id
 * @property int $tournament_id
 * @property int $user_id
 * @property array<string, int> $picks
 * @property int|null $tiebreaker
 * @property int $score
 * @property int $correct
 * @property int $possible
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Tournament $tournament
 * @property-read User|null $user
 */
class Prediction extends AbstractModel
{
    protected $table = 'gauntlet_predictions';

    public $timestamps = true;

    protected $casts = [
        'tournament_id' => 'integer',
        'user_id' => 'integer',
        'picks' => 'array',
        'tiebreaker' => 'integer',
        'score' => 'integer',
        'correct' => 'integer',
        'possible' => 'integer',
    ];

    /** @return BelongsTo<Tournament, $this> */
    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
