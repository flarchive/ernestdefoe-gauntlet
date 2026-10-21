<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return Migration::createTable('gauntlet_predictions', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('tournament_id');
    $table->unsignedInteger('user_id');
    // {"W:2:1": entrantId, ...}: the member's winner for every match.
    $table->text('picks');
    // The final's combined score, to break ties.
    // Signed: the board sorts on ABS(tiebreaker - total), which MySQL
    // refuses to compute on an unsigned column when it goes negative.
    $table->smallInteger('tiebreaker')->nullable();
    $table->unsignedInteger('score')->default(0);
    $table->unsignedSmallInteger('correct')->default(0);
    $table->unsignedInteger('possible')->default(0);
    $table->timestamps();

    $table->unique(['tournament_id', 'user_id'], 'gauntlet_predictions_once_unique');
    $table->index(['tournament_id', 'score'], 'gauntlet_predictions_score_idx');
    $table->index('user_id');
    $table->foreign('tournament_id')->references('id')->on('gauntlet_tournaments')->cascadeOnDelete();
    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
});
