<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * One row per (discussion, user) who has a visible comment in that
 * discussion, plus a per-discussion meta row holding the exact total.
 *
 * Deliberately NOT stored on `discussions.participants_count` — that column
 * belongs to Flarum core and an extension has no business writing to it, let
 * alone dropping it on uninstall.
 */
return [
    'up' => function (Builder $schema) {
        if (! $schema->hasTable('discussion_participants')) {
            $schema->create('discussion_participants', function (Blueprint $table) {
                $table->unsignedInteger('discussion_id');
                $table->unsignedInteger('user_id');
                $table->unsignedInteger('post_count')->default(0);
                $table->dateTime('first_post_at')->nullable();
                $table->dateTime('last_post_at')->nullable();

                $table->primary(['discussion_id', 'user_id']);

                // The three supported strip orderings, each covered so the
                // per-parent window function never sorts on disk.
                $table->index(['discussion_id', 'first_post_at'], 'dp_first');
                $table->index(['discussion_id', 'post_count'], 'dp_active');
                $table->index(['discussion_id', 'last_post_at'], 'dp_recent');
                $table->index('user_id');

                $table->foreign('discussion_id')->references('id')->on('discussions')->cascadeOnDelete();
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }

        if (! $schema->hasTable('discussion_participant_meta')) {
            $schema->create('discussion_participant_meta', function (Blueprint $table) {
                $table->unsignedInteger('discussion_id')->primary();

                // Everyone with a visible comment, and everyone except the
                // person who started the discussion. The overflow badge
                // counts repliers, the modal reports the total, and both
                // need to be exact or they visibly contradict each other.
                $table->unsignedInteger('participant_count')->default(0);
                $table->unsignedInteger('replier_count')->default(0);

                $table->foreign('discussion_id')->references('id')->on('discussions')->cascadeOnDelete();
            });
        }
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('discussion_participants');
        $schema->dropIfExists('discussion_participant_meta');
    },
];
