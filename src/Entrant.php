<?php

namespace ErnestDefoe\Gauntlet;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property int $tournament_id
 * @property int|null $user_id
 * @property int|null $group_id
 * @property string $name
 * @property string|null $logo_url
 * @property int|null $picks_team_id
 * @property string $status
 * @property int|null $seed
 * @property Carbon|null $checked_in_at
 * @property int|null $final_rank
 * @property int $wins
 * @property int $losses
 * @property int $draws
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Tournament $tournament
 * @property-read User|null $user
 * @property-read \Illuminate\Database\Eloquent\Collection<int, User> $members
 */
class Entrant extends AbstractModel
{
    public const REGISTERED = 'registered';
    public const WAITLISTED = 'waitlisted';
    public const WITHDRAWN = 'withdrawn';
    public const DISQUALIFIED = 'disqualified';
    public const NO_SHOW = 'no_show';

    protected $table = 'gauntlet_entrants';

    public $timestamps = true;

    protected $casts = [
        'tournament_id' => 'integer',
        'user_id' => 'integer',
        'group_id' => 'integer',
        'picks_team_id' => 'integer',
        'seed' => 'integer',
        'checked_in_at' => 'datetime',
        'final_rank' => 'integer',
        'wins' => 'integer',
        'losses' => 'integer',
        'draws' => 'integer',
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

    /** @return BelongsToMany<User, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'gauntlet_entrant_members', 'entrant_id', 'user_id');
    }

    /** Still in the field (or waiting to be). */
    public function active(): bool
    {
        return in_array($this->status, [self::REGISTERED, self::WAITLISTED], true);
    }
}
