<?php

namespace ErnestDefoe\Gauntlet;

use Carbon\Carbon;
use ErnestDefoe\Gauntlet\Access\Gate;
use Flarum\Extension\ExtensionManager;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Creating and editing a tournament from the organiser's form. Only the
 * fields sent are touched; the shape of the competition (format, who enters,
 * team size) is fixed once it has started, and prizes are only for those
 * with the reward permission.
 */
class Saver
{
    /** Most entrants each format allows: round robin grows with the square. */
    public const CAPS = ['single' => 256, 'double' => 256, 'round_robin' => 32, 'swiss' => 256];

    public function __construct(private TranslatorInterface $translator, private ExtensionManager $extensions)
    {
    }

    /** @param array<string, mixed> $data */
    public function save(Tournament $tournament, User $actor, array $data): Tournament
    {
        $new = ! $tournament->exists;
        $started = in_array($tournament->status, [Tournament::RUNNING, Tournament::FINISHED, Tournament::CANCELLED], true);
        $published = ! $new && $tournament->status !== Tournament::DRAFT;

        if ($new) {
            $tournament->user_id = $actor->id;
            $tournament->status = Tournament::DRAFT;
        }

        $has = fn (string $key) => array_key_exists($key, $data);

        // The competition's shape: fixed once it has started.
        // The game too: ratings are kept per game, and a result is taken back
        // from the game it was rated in.
        $shape = ['format', 'entryMode', 'entryType', 'teamSize', 'thirdPlace', 'grandFinalReset', 'predictions', 'swissRounds', 'allowDraws', 'game'];
        if ($started && Arr::hasAny($data, $shape)) {
            foreach ($shape as $key) {
                if ($has($key) && $this->differs($tournament, $key, $data[$key])) {
                    $this->fail($key, 'locked_after_start');
                }
            }
        }

        // Points per round can't change under picks already scored.
        if ($started && $has('predictionPoints') && array_map('intval', array_values((array) $data['predictionPoints'])) !== array_map('intval', array_values((array) $tournament->prediction_points))) {
            $this->fail('predictionPoints', 'locked_after_start');
        }

        if ($has('title') || $new) {
            $title = trim(preg_replace('/\s+/u', ' ', (string) ($data['title'] ?? '')) ?? '');
            if ($title === '' || mb_strlen($title) > 150) {
                $this->fail('title', 'title');
            }
            $tournament->title = $title;
            $tournament->slug = Str::limit(Str::slug($title), 140, '') ?: '';
        }

        if ($has('description')) {
            $description = trim((string) $data['description']);
            if (mb_strlen($description) > 5000) {
                $this->fail('description', 'description');
            }
            $tournament->description = $description === '' ? null : $description;
        }

        if ($has('game')) {
            $game = trim(preg_replace('/\s+/u', ' ', (string) $data['game']) ?? '');
            if (mb_strlen($game) > 80) {
                $this->fail('game', 'game');
            }
            $tournament->game = $game;
        }

        if (! $started) {
            if ($has('format') || $new) {
                $format = (string) ($data['format'] ?? 'single');
                $this->oneOf('format', $format, Tournament::FORMATS);
                $tournament->format = $format;
            }
            if ($has('entryMode')) {
                $this->oneOf('entryMode', (string) $data['entryMode'], ['members', 'staff']);
                if ($published && $data['entryMode'] !== $tournament->entry_mode) {
                    $this->fail('entryMode', 'locked_after_publish');
                }
                $tournament->entry_mode = (string) $data['entryMode'];
            }
            if ($has('entryType')) {
                $this->oneOf('entryType', (string) $data['entryType'], ['solo', 'team']);
                if ($published && $data['entryType'] !== $tournament->entry_type && $tournament->entrant_count > 0) {
                    $this->fail('entryType', 'locked_after_publish');
                }
                $tournament->entry_type = (string) $data['entryType'];
            }
            if ($has('teamSize')) {
                $tournament->team_size = $this->int('teamSize', $data['teamSize'], 0, 50);
            }
            if ($has('thirdPlace')) {
                $tournament->third_place = (bool) $data['thirdPlace'];
            }
            if ($has('grandFinalReset')) {
                $tournament->grand_final_reset = (bool) $data['grandFinalReset'];
            }
            if ($has('swissRounds')) {
                $tournament->swiss_rounds = $this->int('swissRounds', $data['swissRounds'], 0, 20);
            }
            if ($has('allowDraws')) {
                $tournament->allow_draws = (bool) $data['allowDraws'];
            }
            if ($has('predictions')) {
                $tournament->predictions = (bool) $data['predictions'];
            }
            if ($has('seeding')) {
                $this->oneOf('seeding', (string) $data['seeding'], ['random', 'manual', 'rating']);
                $tournament->seeding = (string) $data['seeding'];
            }
            if ($has('maxEntrants')) {
                $tournament->max_entrants = $this->int('maxEntrants', $data['maxEntrants'], 0, 1024);
            }
            if ($has('bestOf')) {
                $tournament->best_of = $this->int('bestOf', $data['bestOf'], 1, 9);
                if ($tournament->best_of % 2 === 0) {
                    $this->fail('bestOf', 'best_of');
                }
            }
        }

        // A prediction bracket is a staff-entered single-elimination field.
        if ($tournament->predictions && ! ($tournament->format === 'single' && $tournament->entry_mode === 'staff')) {
            $this->fail('predictions', 'predictions_need_staff_single');
        }

        $cap = self::CAPS[$tournament->format] ?? 256;
        if ($tournament->max_entrants > $cap) {
            $this->fail('maxEntrants', 'max_entrants', ['max' => $cap]);
        }

        foreach (['waitlist' => 'waitlist', 'matchDiscussions' => 'match_discussions'] as $key => $column) {
            if ($has($key)) {
                $tournament->{$column} = (bool) $data[$key];
            }
        }

        if ($has('checkinMinutes')) {
            $tournament->checkin_minutes = $this->int('checkinMinutes', $data['checkinMinutes'], 0, 1440);
        }

        if ($has('confirmation')) {
            $this->oneOf('confirmation', (string) $data['confirmation'], ['agree', 'staff']);
            $tournament->confirmation = (string) $data['confirmation'];
        }

        foreach (['startsAt' => 'starts_at', 'registrationClosesAt' => 'registration_closes_at', 'predictionsLockAt' => 'predictions_lock_at'] as $key => $column) {
            if ($has($key)) {
                $tournament->{$column} = $this->date($key, $data[$key]);
            }
        }

        if ($tournament->checkin_minutes > 0 && ! $tournament->starts_at) {
            $this->fail('checkinMinutes', 'checkin_needs_start');
        }

        if ($has('predictionPoints')) {
            $points = $data['predictionPoints'];
            if ($points === null || $points === []) {
                $tournament->prediction_points = null;
            } elseif (! is_array($points) || count($points) > 12) {
                $this->fail('predictionPoints', 'points');
            } else {
                $tournament->prediction_points = array_map(fn ($p) => $this->int('predictionPoints', $p, 0, 100000), array_values($points));
            }
        }

        if ($has('tagId')) {
            $tournament->tag_id = $this->tag($actor, $data['tagId']);
        }

        if ($has('rewardPoints') || $has('rewardBadgeId')) {
            if (! Gate::canReward($actor)) {
                // Sending the values unchanged (a form saving everything) is fine.
                if ($this->rewardsChanged($tournament, $data)) {
                    throw new PermissionDeniedException();
                }
            } else {
                if ($has('rewardPoints')) {
                    $points = $data['rewardPoints'];
                    $tournament->reward_points = is_array($points) && array_filter($points)
                        ? array_map(fn ($p) => $this->int('rewardPoints', $p, 0, 100000), array_slice(array_values($points), 0, 3))
                        : null;
                }
                if ($has('rewardBadgeId')) {
                    $tournament->reward_badge_id = $this->badge($data['rewardBadgeId']);
                }
            }
        }

        $tournament->save();

        return $tournament;
    }

    private function differs(Tournament $tournament, string $key, mixed $value): bool
    {
        $column = Str::snake($key);
        $current = $tournament->{$column};

        if ($key === 'game') {
            return $current !== trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');
        }

        return match (true) {
            is_bool($current) => $current !== (bool) $value,
            is_int($current) => $current !== (int) $value,
            default => (string) $current !== (string) $value,
        };
    }

    /** @param array<string, mixed> $data */
    private function rewardsChanged(Tournament $tournament, array $data): bool
    {
        if (array_key_exists('rewardBadgeId', $data) && ($data['rewardBadgeId'] ? (int) $data['rewardBadgeId'] : null) !== $tournament->reward_badge_id) {
            return true;
        }

        if (array_key_exists('rewardPoints', $data)) {
            $sent = is_array($data['rewardPoints']) ? array_map('intval', array_values($data['rewardPoints'])) : [];

            return array_filter($sent) !== array_filter(array_map('intval', (array) $tournament->reward_points));
        }

        return false;
    }

    private function tag(User $actor, mixed $value): ?int
    {
        if ($value === null || $value === '' || ! $this->extensions->isEnabled('flarum-tags')) {
            return null;
        }

        $tag = \Flarum\Tags\Tag::query()->whereVisibleTo($actor)->find((int) $value);
        if (! $tag || ! $actor->can('startDiscussion', $tag)) {
            $this->fail('tagId', 'tag');
        }

        return $tag->id;
    }

    private function badge(mixed $value): ?int
    {
        if ($value === null || $value === '' || (int) $value === 0) {
            return null;
        }
        if (! class_exists('FoF\Badges\Badge') || ! \FoF\Badges\Badge::query()->whereKey((int) $value)->exists()) {
            $this->fail('rewardBadgeId', 'badge');
        }

        return (int) $value;
    }

    private function date(string $field, mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $date = Carbon::parse((string) $value)->utc();
        } catch (\Throwable) {
            $this->fail($field, 'date');
        }

        if ($date->year < 2000 || $date->year > 2100) {
            $this->fail($field, 'date');
        }

        return $date;
    }

    private function int(string $field, mixed $value, int $min, int $max): int
    {
        $int = filter_var($value, FILTER_VALIDATE_INT);
        if ($int === false || $int < $min || $int > $max) {
            $this->fail($field, 'number', ['min' => $min, 'max' => $max]);
        }

        return $int;
    }

    /** @param list<string> $allowed */
    private function oneOf(string $field, string $value, array $allowed): void
    {
        if (! in_array($value, $allowed, true)) {
            $this->fail($field, 'choice');
        }
    }

    /** @param array<string, mixed> $params */
    private function fail(string $field, string $key, array $params = []): never
    {
        throw new ValidationException([$field => $this->translator->trans('ernestdefoe-gauntlet.api.'.$key, $params)]);
    }
}
