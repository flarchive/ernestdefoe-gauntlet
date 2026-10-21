<?php

namespace ErnestDefoe\Gauntlet;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\Database\ScopeVisibilityTrait;
use Flarum\Discussion\Discussion;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string $title
 * @property string $slug
 * @property string|null $description
 * @property string $game
 * @property string $format
 * @property string $entry_mode
 * @property string $entry_type
 * @property int $team_size
 * @property string $status
 * @property int $max_entrants
 * @property bool $waitlist
 * @property int $checkin_minutes
 * @property Carbon|null $starts_at
 * @property Carbon|null $registration_closes_at
 * @property string $seeding
 * @property int $best_of
 * @property bool $third_place
 * @property bool $grand_final_reset
 * @property int $swiss_rounds
 * @property bool $allow_draws
 * @property string $confirmation
 * @property bool $match_discussions
 * @property bool $predictions
 * @property Carbon|null $predictions_lock_at
 * @property list<int>|null $prediction_points
 * @property list<int>|null $reward_points
 * @property int|null $reward_badge_id
 * @property int|null $discussion_id
 * @property int|null $tag_id
 * @property int $entrant_count
 * @property int $prediction_count
 * @property int $current_round
 * @property Carbon|null $checkin_notified_at
 * @property Carbon|null $rewarded_at
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User|null $user
 * @property-read Discussion|null $discussion
 */
class Tournament extends AbstractModel
{
    use ScopeVisibilityTrait;

    public const DRAFT = 'draft';
    public const REGISTRATION = 'registration';
    public const RUNNING = 'running';
    public const FINISHED = 'finished';
    public const CANCELLED = 'cancelled';

    public const FORMATS = ['single', 'double', 'round_robin', 'swiss'];

    protected $table = 'gauntlet_tournaments';

    public $timestamps = true;

    protected $casts = [
        'user_id' => 'integer',
        'team_size' => 'integer',
        'max_entrants' => 'integer',
        'waitlist' => 'boolean',
        'checkin_minutes' => 'integer',
        'starts_at' => 'datetime',
        'registration_closes_at' => 'datetime',
        'best_of' => 'integer',
        'third_place' => 'boolean',
        'grand_final_reset' => 'boolean',
        'swiss_rounds' => 'integer',
        'allow_draws' => 'boolean',
        'match_discussions' => 'boolean',
        'predictions' => 'boolean',
        'predictions_lock_at' => 'datetime',
        'prediction_points' => 'array',
        'reward_points' => 'array',
        'reward_badge_id' => 'integer',
        'discussion_id' => 'integer',
        'tag_id' => 'integer',
        'entrant_count' => 'integer',
        'prediction_count' => 'integer',
        'current_round' => 'integer',
        'checkin_notified_at' => 'datetime',
        'rewarded_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Discussion, $this> */
    public function discussion(): BelongsTo
    {
        return $this->belongsTo(Discussion::class);
    }

    /** @return HasMany<Entrant, $this> */
    public function entrants(): HasMany
    {
        return $this->hasMany(Entrant::class);
    }

    /** @return HasMany<Fixture, $this> */
    public function fixtures(): HasMany
    {
        return $this->hasMany(Fixture::class);
    }

    public function isElimination(): bool
    {
        return in_array($this->format, ['single', 'double'], true);
    }

    public function isStaffEntered(): bool
    {
        return $this->entry_mode === 'staff';
    }

    public function isTeam(): bool
    {
        return $this->entry_type === 'team' && ! $this->isStaffEntered();
    }

    /** Draws only where a table, not a bracket, decides who goes on. */
    public function drawsAllowed(): bool
    {
        return $this->allow_draws && ! $this->isElimination();
    }

    public function registrationOpen(?Carbon $now = null): bool
    {
        $now ??= Carbon::now();

        return $this->status === self::REGISTRATION
            && ! $this->isStaffEntered()
            && ($this->registration_closes_at === null || $now->lt($this->registration_closes_at));
    }

    public function checkinOpensAt(): ?Carbon
    {
        return $this->checkin_minutes > 0 && $this->starts_at ? $this->starts_at->copy()->subMinutes($this->checkin_minutes) : null;
    }

    public function checkinOpen(?Carbon $now = null): bool
    {
        $opens = $this->checkinOpensAt();

        return $opens !== null && $this->status === self::REGISTRATION && ($now ?? Carbon::now())->gte($opens);
    }

    /** The organiser's cap, or with none set the format's own limit. */
    public function cap(): int
    {
        $limit = Saver::CAPS[$this->format] ?? 256;

        return $this->max_entrants > 0 ? min($this->max_entrants, $limit) : $limit;
    }

    public function full(): bool
    {
        return $this->entrant_count >= $this->cap();
    }

    /**
     * Picks need the bracket, so they open when the organiser starts the
     * tournament (sets the field) and close at the lock time, or when the
     * first result is in if no lock is set.
     */
    public function predictionsOpen(?Carbon $now = null): bool
    {
        if (! $this->predictions || $this->status !== self::RUNNING) {
            return false;
        }

        return $this->predictions_lock_at === null || ($now ?? Carbon::now())->lt($this->predictions_lock_at);
    }

    /** Points for a correct pick in each winners round: 10, 20, 40… unless set. */
    public function pointsForRound(int $round): int
    {
        $points = $this->prediction_points;

        if (is_array($points) && isset($points[$round - 1])) {
            return max(0, (int) $points[$round - 1]);
        }

        return 10 * (2 ** ($round - 1));
    }

    public function hasPrizes(): bool
    {
        return (bool) $this->reward_badge_id || array_filter((array) $this->reward_points);
    }

    public function gameKey(): string
    {
        return mb_strtolower(trim($this->game));
    }
}
