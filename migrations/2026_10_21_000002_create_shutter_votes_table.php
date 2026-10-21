<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * One member's vote for one entry.
 *
 * 🚨 Two unique indexes make voting race-safe without locks: one vote per
 * entry per member, and a member's votes in a contest take slots
 * 1..votes_per_member, so two votes sent at the same instant cannot both
 * take the last one.
 *
 * `ip_hash` is a keyed hash of the voter's network (/24 or /64), kept only
 * to spot votes coming from the entrant's own network. It is never sent to
 * anyone, not even staff: they see "same network", not an address.
 */
return Migration::createTable('shutter_votes', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('contest_id');
    $table->unsignedInteger('entry_id');
    $table->unsignedInteger('user_id');
    $table->unsignedTinyInteger('slot');
    $table->char('ip_hash', 16)->nullable();
    // Set by staff reviewing suspicious votes. A voided vote still holds its
    // slot and its entry, so it cannot simply be cast again.
    $table->dateTime('voided_at')->nullable();
    $table->dateTime('created_at')->nullable();

    $table->unique(['entry_id', 'user_id']);
    $table->unique(['contest_id', 'user_id', 'slot']);
    $table->index(['contest_id', 'entry_id']);

    $table->foreign('contest_id')->references('id')->on('shutter_contests')->cascadeOnDelete();
    $table->foreign('entry_id')->references('id')->on('shutter_entries')->cascadeOnDelete();
    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
});
