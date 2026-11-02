<?php

namespace ErnestDefoe\Gauntlet\Engine;

/**
 * Where a match's winner or loser goes: a match's coordinates in the same
 * tournament and which of its two slots.
 */
final class Slot
{
    public function __construct(
        public readonly string $bracket,
        public readonly int $round,
        public readonly int $position,
        public readonly int $slot,
    ) {
    }

    public function key(): string
    {
        return Builder::key($this->bracket, $this->round, $this->position);
    }
}
