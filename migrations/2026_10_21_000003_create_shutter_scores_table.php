<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/** A judge's score (1–10) for one entry, in a contest scored by judges. */
return Migration::createTable('shutter_scores', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('contest_id');
    $table->unsignedInteger('entry_id');
    $table->unsignedInteger('user_id');
    $table->unsignedTinyInteger('score');
    $table->dateTime('created_at')->nullable();
    $table->dateTime('updated_at')->nullable();

    $table->unique(['entry_id', 'user_id']);
    $table->index(['contest_id', 'entry_id']);

    $table->foreign('contest_id')->references('id')->on('shutter_contests')->cascadeOnDelete();
    $table->foreign('entry_id')->references('id')->on('shutter_entries')->cascadeOnDelete();
    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
});
