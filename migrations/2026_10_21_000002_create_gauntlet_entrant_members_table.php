<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

// Who plays for an entrant: the one player of a solo entry, or a team's roster.
// Every "what has this member played" question reads this one table.
return Migration::createTable('gauntlet_entrant_members', function (Blueprint $table) {
    $table->unsignedInteger('entrant_id');
    $table->unsignedInteger('user_id');
    $table->unsignedInteger('tournament_id');
    $table->dateTime('joined_at');
    // A request to join an ad-hoc team waits for its captain.
    $table->boolean('approved')->default(true);

    $table->primary(['entrant_id', 'user_id']);
    // One entry per member per tournament, enforced by the database.
    $table->unique(['tournament_id', 'user_id'], 'gauntlet_members_once_unique');
    $table->index('user_id');
    $table->foreign('entrant_id')->references('id')->on('gauntlet_entrants')->cascadeOnDelete();
    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
    $table->foreign('tournament_id')->references('id')->on('gauntlet_tournaments')->cascadeOnDelete();
});
