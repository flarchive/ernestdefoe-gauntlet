<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return Migration::createTable('gauntlet_matches', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('tournament_id');
    // W winners · L losers · F grand final · P third place · R round robin · S swiss
    $table->char('bracket', 1);
    $table->unsignedSmallInteger('round');
    $table->unsignedSmallInteger('position');
    $table->unsignedInteger('entrant1_id')->nullable();
    $table->unsignedInteger('entrant2_id')->nullable();
    // A slot that will never be filled: a bye, or the loser of a bye.
    $table->boolean('dead1')->default(false);
    $table->boolean('dead2')->default(false);
    $table->unsignedSmallInteger('score1')->nullable();
    $table->unsignedSmallInteger('score2')->nullable();
    $table->unsignedInteger('winner_id')->nullable();
    // pending | open | reported | disputed | awaiting | completed | bye | void
    $table->string('status', 10)->default('pending');
    // Each side's report, from entrant 1's point of view.
    $table->unsignedSmallInteger('report1_score1')->nullable();
    $table->unsignedSmallInteger('report1_score2')->nullable();
    $table->unsignedSmallInteger('report2_score1')->nullable();
    $table->unsignedSmallInteger('report2_score2')->nullable();
    // Where the winner and the loser go, as coordinates in the same tournament.
    $table->char('next_bracket', 1)->nullable();
    $table->unsignedSmallInteger('next_round')->nullable();
    $table->unsignedSmallInteger('next_position')->nullable();
    $table->unsignedTinyInteger('next_slot')->nullable();
    $table->char('loser_bracket', 1)->nullable();
    $table->unsignedSmallInteger('loser_round')->nullable();
    $table->unsignedSmallInteger('loser_position')->nullable();
    $table->unsignedTinyInteger('loser_slot')->nullable();
    $table->unsignedTinyInteger('best_of')->default(1);
    // The rating change this result made, kept so a correction can undo it.
    $table->smallInteger('rating_delta1')->nullable();
    $table->smallInteger('rating_delta2')->nullable();
    $table->unsignedInteger('picks_event_id')->nullable();
    $table->unsignedInteger('discussion_id')->nullable();
    $table->unsignedInteger('resolved_by_id')->nullable();
    $table->dateTime('scheduled_at')->nullable();
    $table->dateTime('completed_at')->nullable();
    $table->timestamps();

    // Named: the generated name passes MySQL's 64 characters with a table prefix.
    $table->unique(['tournament_id', 'bracket', 'round', 'position'], 'gauntlet_matches_slot_unique');
    $table->index('picks_event_id');
    $table->index('entrant1_id');
    $table->index('entrant2_id');
    $table->foreign('tournament_id')->references('id')->on('gauntlet_tournaments')->cascadeOnDelete();
    $table->foreign('discussion_id')->references('id')->on('discussions')->nullOnDelete();
    $table->foreign('resolved_by_id')->references('id')->on('users')->nullOnDelete();
});
