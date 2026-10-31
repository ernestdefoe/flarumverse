<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        // Forums we're paired with, or asking / being asked to pair with.
        $schema->create('flarumverse_partners', function (Blueprint $table) {
            $table->increments('id');
            $table->string('url', 191)->unique();
            $table->string('name', 100);
            $table->string('description', 300)->nullable();
            $table->string('public_key', 64);
            // incoming | outgoing | paired | declined (by us) | refused (by them)
            $table->string('status', 10)->index();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->dateTime('paired_at')->nullable();
            $table->dateTime('last_attempt_at')->nullable();
            $table->unsignedSmallInteger('failures')->default(0);
            $table->string('last_error', 191)->nullable();
        });

        // A partner's latest shared discussions, refreshed by flarumverse:pull.
        $schema->create('flarumverse_threads', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('partner_id');
            $table->unsignedInteger('remote_id');
            $table->string('title', 200);
            $table->string('excerpt', 400)->nullable();
            $table->string('author', 100)->nullable();
            $table->string('tag', 100)->nullable();
            $table->unsignedInteger('replies')->default(0);
            $table->string('url', 400);
            $table->dateTime('posted_at')->index();

            $table->unique(['partner_id', 'remote_id']);
            $table->foreign('partner_id')->references('id')->on('flarumverse_partners')->cascadeOnDelete();
        });
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('flarumverse_threads');
        $schema->dropIfExists('flarumverse_partners');
    },
];
