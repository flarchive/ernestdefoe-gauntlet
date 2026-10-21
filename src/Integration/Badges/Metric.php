<?php

namespace ErnestDefoe\Gauntlet\Integration\Badges;

use ErnestDefoe\Gauntlet\Event\TournamentFinished;
use Flarum\User\User;
use FoF\Badges\Trigger\MetricInterface;
use Illuminate\Database\ConnectionInterface;

/** FoF Badges triggers: tournaments won, tournaments played, podium finishes. */
class Metric implements MetricInterface
{
    public function __construct(private ConnectionInterface $db, private string $type, private ?int $maxPlace)
    {
    }

    public function getType(): string
    {
        return 'gauntlet_'.$this->type;
    }

    public function getTranslationKey(): string
    {
        return 'ernestdefoe-gauntlet.admin.badges.'.$this->type;
    }

    public function getEventTriggers(): array
    {
        return [TournamentFinished::class => fn () => null];
    }

    public function getValue(User $user, array $config = []): int
    {
        $query = $this->db->table('gauntlet_entrant_members')
            ->join('gauntlet_entrants', 'gauntlet_entrants.id', '=', 'gauntlet_entrant_members.entrant_id')
            ->join('gauntlet_tournaments', 'gauntlet_tournaments.id', '=', 'gauntlet_entrants.tournament_id')
            ->where('gauntlet_entrant_members.user_id', $user->id)
            ->where('gauntlet_entrant_members.approved', true)
            ->where('gauntlet_tournaments.status', 'finished')
            ->whereNotNull('gauntlet_entrants.final_rank');

        if ($this->maxPlace !== null) {
            $query->where('gauntlet_entrants.final_rank', '<=', $this->maxPlace);
        }

        return $query->count();
    }

    public function isBoolean(): bool
    {
        return false;
    }

    public function getConfigFields(): array
    {
        return [];
    }

    public function getExtensionDependencies(): array
    {
        return ['ernestdefoe/gauntlet'];
    }
}
