<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

// An Elo rating per member (or Social Groups team) per game, for seeding by rating.
return Migration::createTable('gauntlet_ratings', function (Blueprint $table) {
    $table->increments('id');
    // user | group
    $table->string('subject_type', 5);
    $table->unsignedBigInteger('subject_id');
    // The game as a key (lower case) and as last written, for showing.
    $table->string('game', 80)->default('');
    $table->string('game_label', 80)->default('');
    $table->smallInteger('rating')->default(1500);
    $table->unsignedInteger('played')->default(0);
    $table->unsignedInteger('wins')->default(0);
    $table->unsignedInteger('losses')->default(0);
    $table->unsignedInteger('draws')->default(0);
    $table->timestamps();

    $table->unique(['subject_type', 'subject_id', 'game'], 'gauntlet_ratings_subject_unique');
});
