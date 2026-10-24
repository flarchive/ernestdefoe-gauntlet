<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return Migration::createTable('gauntlet_entrants', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('tournament_id');
    // The member who signed up: the player, or a team's captain. Null for a
    // staff-entered entrant (a real-world team) or a deleted account.
    $table->unsignedInteger('user_id')->nullable();
    // A Social Groups group the team plays as, when that extension is on.
    $table->unsignedBigInteger('group_id')->nullable();
    $table->string('name', 100)->default('');
    $table->string('logo_url', 500)->nullable();
    // A Picks team, for a staff-entered field built from Picks' teams.
    $table->unsignedInteger('picks_team_id')->nullable();
    // registered | waitlisted | withdrawn | disqualified | no_show
    $table->string('status', 20)->default('registered');
    $table->unsignedSmallInteger('seed')->nullable();
    $table->dateTime('checked_in_at')->nullable();
    $table->unsignedSmallInteger('final_rank')->nullable();
    $table->unsignedSmallInteger('wins')->default(0);
    $table->unsignedSmallInteger('losses')->default(0);
    $table->unsignedSmallInteger('draws')->default(0);
    $table->timestamps();

    $table->index(['tournament_id', 'status'], 'gauntlet_entrants_status_idx');
    $table->index('user_id');
    $table->foreign('tournament_id')->references('id')->on('gauntlet_tournaments')->cascadeOnDelete();
    $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
});
