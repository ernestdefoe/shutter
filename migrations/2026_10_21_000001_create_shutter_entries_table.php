<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * One submitted photograph.
 *
 * 🚨 `slot` is how the per-member entry limit holds under concurrent uploads
 * on every database: a member's entries take slots 1..limit, and the unique
 * index refuses a second row in the same slot. No row lock, so SQLite,
 * MySQL and PostgreSQL all behave the same.
 *
 * `hash` is the SHA-256 of the bytes as uploaded: it names the stored files
 * (no part of a path comes from the upload) and refuses the same picture
 * entered twice in one contest. The files themselves are re-encoded copies;
 * the upload is never stored.
 */
return Migration::createTable('shutter_entries', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('contest_id');
    $table->unsignedInteger('user_id')->nullable();
    $table->unsignedTinyInteger('slot')->nullable();
    $table->string('title', 100);
    $table->text('caption')->nullable();
    $table->char('hash', 64);
    $table->string('ext', 4);
    $table->unsignedSmallInteger('width');
    $table->unsignedSmallInteger('height');
    $table->unsignedInteger('size');
    $table->text('blur')->nullable();
    $table->text('camera')->nullable();
    $table->char('ip_hash', 16)->nullable();
    $table->dateTime('approved_at')->nullable();
    $table->dateTime('disqualified_at')->nullable();
    $table->unsignedTinyInteger('placement')->nullable();
    $table->dateTime('created_at')->nullable();
    $table->dateTime('updated_at')->nullable();

    $table->unique(['contest_id', 'user_id', 'slot']);
    $table->unique(['contest_id', 'hash']);
    $table->index(['user_id', 'placement']);
    $table->index('hash');

    $table->foreign('contest_id')->references('id')->on('shutter_contests')->cascadeOnDelete();
    $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
});
