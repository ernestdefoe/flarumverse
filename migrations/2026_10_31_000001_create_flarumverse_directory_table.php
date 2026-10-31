<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        // Listings, only used on the forum that hosts the directory.
        $schema->create('flarumverse_directory', function (Blueprint $table) {
            $table->increments('id');
            $table->string('url', 191)->unique();
            $table->string('name', 100);
            $table->string('description', 300)->nullable();
            $table->string('public_key', 64);
            $table->string('topic', 30)->index();
            $table->string('language', 8)->index();
            // pending | approved | rejected
            $table->string('status', 10)->index();
            $table->unsignedInteger('members')->default(0);
            $table->unsignedInteger('discussions')->default(0);
            $table->dateTime('last_activity_at')->nullable();
            $table->text('latest')->nullable();
            $table->dateTime('checked_at')->nullable();
            $table->dateTime('unreachable_since')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
        });
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('flarumverse_directory');
    },
];
