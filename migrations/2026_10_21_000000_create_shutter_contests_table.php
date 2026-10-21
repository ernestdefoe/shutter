<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * A photo contest. It belongs to one discussion, which is where it is shown,
 * discussed and found, and whose visibility it shares.
 *
 * The phase is never stored: it is read off the three times, so a contest
 * moves from submissions to voting to closed on schedule whether or not the
 * forum runs its scheduler. Only the side effects (notifications, placings,
 * badges) wait for somebody to notice, and `finalized_at` makes those once.
 */
return Migration::createTable('shutter_contests', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('discussion_id')->unique();
    $table->unsignedInteger('user_id')->nullable();
    // null = submissions open from the moment the contest exists.
    $table->dateTime('opens_at')->nullable();
    $table->dateTime('submissions_close_at');
    $table->dateTime('voting_close_at');
    $table->text('rules')->nullable();
    $table->unsignedTinyInteger('entries_per_member')->default(1);
    $table->unsignedTinyInteger('votes_per_member')->default(3);
    // Group ids, JSON; empty = anyone holding the forum-wide permission.
    $table->text('enter_group_ids')->nullable();
    $table->text('vote_group_ids')->nullable();
    // votes | judges
    $table->string('scoring', 10)->default('votes');
    $table->text('judge_ids')->nullable();
    $table->boolean('named_votes')->default(false);
    $table->boolean('live_counts')->default(false);
    $table->boolean('hide_entrants')->default(true);
    $table->boolean('moderate_entries')->default(false);
    $table->unsignedTinyInteger('winners_count')->default(3);
    $table->unsignedSmallInteger('voter_min_days')->default(0);
    $table->unsignedSmallInteger('voter_min_posts')->default(0);
    $table->dateTime('voting_notified_at')->nullable();
    $table->dateTime('finalized_at')->nullable();
    $table->dateTime('created_at')->nullable();
    $table->dateTime('updated_at')->nullable();

    $table->index(['voting_close_at', 'finalized_at']);

    $table->foreign('discussion_id')->references('id')->on('discussions')->cascadeOnDelete();
    $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
});
