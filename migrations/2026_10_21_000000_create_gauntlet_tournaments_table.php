<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return Migration::createTable('gauntlet_tournaments', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('user_id')->nullable();
    $table->string('title', 150);
    $table->string('slug', 150);
    $table->text('description')->nullable();
    // What is played, free text ("Chess", "Rocket League"); ratings are kept per game.
    $table->string('game', 80)->default('');
    // single | double | round_robin | swiss
    $table->string('format', 20);
    // members: members sign up · staff: the organiser enters the field (a real-world bracket)
    $table->string('entry_mode', 10)->default('members');
    // solo | team
    $table->string('entry_type', 10)->default('solo');
    $table->unsignedTinyInteger('team_size')->default(0);
    // draft | registration | running | finished | cancelled
    $table->string('status', 20)->default('draft');
    $table->unsignedSmallInteger('max_entrants')->default(0);
    $table->boolean('waitlist')->default(true);
    $table->unsignedSmallInteger('checkin_minutes')->default(0);
    $table->dateTime('starts_at')->nullable();
    $table->dateTime('registration_closes_at')->nullable();
    // random | manual | rating
    $table->string('seeding', 10)->default('random');
    $table->unsignedTinyInteger('best_of')->default(1);
    $table->boolean('third_place')->default(false);
    $table->boolean('grand_final_reset')->default(true);
    $table->unsignedTinyInteger('swiss_rounds')->default(0);
    $table->boolean('allow_draws')->default(false);
    // agree: both sides agreeing completes the match · staff: staff confirm every result
    $table->string('confirmation', 10)->default('agree');
    $table->boolean('match_discussions')->default(false);
    $table->boolean('predictions')->default(false);
    $table->dateTime('predictions_lock_at')->nullable();
    $table->text('prediction_points')->nullable();
    $table->text('reward_points')->nullable();
    $table->unsignedInteger('reward_badge_id')->nullable();
    $table->unsignedInteger('discussion_id')->nullable();
    $table->unsignedInteger('tag_id')->nullable();
    $table->unsignedSmallInteger('entrant_count')->default(0);
    $table->unsignedInteger('prediction_count')->default(0);
    $table->unsignedSmallInteger('current_round')->default(0);
    $table->dateTime('checkin_notified_at')->nullable();
    // Prizes are paid once; a corrected final does not pay again.
    $table->dateTime('rewarded_at')->nullable();
    $table->dateTime('started_at')->nullable();
    $table->dateTime('finished_at')->nullable();
    $table->timestamps();

    $table->index(['status', 'starts_at'], 'gauntlet_tournaments_status_idx');
    $table->index('user_id');
    $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
    $table->foreign('discussion_id')->references('id')->on('discussions')->nullOnDelete();
});
