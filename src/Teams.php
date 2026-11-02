<?php

namespace ErnestDefoe\Gauntlet;

use Flarum\Extension\ExtensionManager;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

/**
 * Teams that are Social Groups groups, when that extension is on.
 *
 * Read straight from its tables, so Gauntlet needs nothing from it at load
 * time. A group's creator or moderators may enter it; its active members
 * (not banned) may join its roster.
 */
class Teams
{
    private const MANAGERS = ['creator', 'moderator'];

    public function __construct(private ExtensionManager $extensions, private ConnectionInterface $db)
    {
    }

    public function available(): bool
    {
        return $this->extensions->isEnabled('ernestdefoe-social-groups');
    }

    /** @return array{id: int, name: string}|null */
    public function groupCaptainedBy(User $actor, int $groupId): ?array
    {
        if (! $this->available() || $actor->isGuest()) {
            return null;
        }

        $row = $this->db->table('social_groups')
            ->join('social_group_members', 'social_group_members.group_id', '=', 'social_groups.id')
            ->where('social_groups.id', $groupId)
            ->where('social_group_members.user_id', $actor->id)
            ->whereIn('social_group_members.role', self::MANAGERS)
            ->whereNull('social_group_members.banned_at')
            ->first(['social_groups.id', 'social_groups.name']);

        return $row ? ['id' => (int) $row->id, 'name' => (string) $row->name] : null;
    }

    /** @return list<array{id: int, name: string}> the groups the actor may enter */
    public function captainedGroups(User $actor): array
    {
        if (! $this->available() || $actor->isGuest()) {
            return [];
        }

        return $this->db->table('social_groups')
            ->join('social_group_members', 'social_group_members.group_id', '=', 'social_groups.id')
            ->where('social_group_members.user_id', $actor->id)
            ->whereIn('social_group_members.role', self::MANAGERS)
            ->whereNull('social_group_members.banned_at')
            ->orderBy('social_groups.name')
            ->limit(50)
            ->get(['social_groups.id', 'social_groups.name'])
            ->map(fn ($row) => ['id' => (int) $row->id, 'name' => (string) $row->name])
            ->all();
    }

    public function isGroupMember(User $actor, int $groupId): bool
    {
        return $this->available() && ! $actor->isGuest() && $this->db->table('social_group_members')
            ->where('group_id', $groupId)
            ->where('user_id', $actor->id)
            ->whereNull('banned_at')
            ->exists();
    }
}
