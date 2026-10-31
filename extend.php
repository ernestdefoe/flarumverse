<?php

/*
 * Flarumverse — Flarum forums pair with each other and show each other's new
 * discussions. Forums talk directly, over signed requests under
 * /.well-known/flarumverse (a path bot guards leave alone); there is no
 * central server. The Flarumverse page only ever reads this forum's own table.
 */

use ErnestDefoe\Flarumverse\Api;
use ErnestDefoe\Flarumverse\Console\DirectoryCommand;
use ErnestDefoe\Flarumverse\Console\PullCommand;
use ErnestDefoe\Flarumverse\Directory;
use ErnestDefoe\Flarumverse\Http;
use ErnestDefoe\Flarumverse\Identity;
use ErnestDefoe\Flarumverse\Listener\QueuePush;
use Flarum\Api\Resource\ForumResource;
use Flarum\Api\Schema;
use Flarum\Discussion\Event\Started;
use Flarum\Extend;
use Illuminate\Console\Scheduling\Event;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        ->css(__DIR__.'/less/forum.less')
        ->route('/flarumverse', 'flarumverse')
        ->route('/forums', 'flarumverse.directory'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/less/admin.less'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\Routes('forum'))
        ->get('/.well-known/flarumverse', 'flarumverse.about', Http\AboutController::class)
        ->get('/.well-known/flarumverse/threads', 'flarumverse.threads', Http\ThreadsController::class)
        ->post('/.well-known/flarumverse/inbox', 'flarumverse.inbox', Http\InboxController::class),

    // Other forums have no session: every inbox message is signed instead.
    (new Extend\Csrf())
        ->exemptRoute('flarumverse.inbox'),

    (new Extend\Routes('api'))
        ->get('/flarumverse/threads', 'flarumverse.page', Api\ThreadsController::class)
        ->get('/flarumverse/partners', 'flarumverse.partners', Api\PartnersController::class)
        ->post('/flarumverse/partners', 'flarumverse.partners.add', Api\PartnersController::class)
        ->patch('/flarumverse/partners/{id:[0-9]+}', 'flarumverse.partners.update', Api\PartnersController::class)
        ->delete('/flarumverse/partners/{id:[0-9]+}', 'flarumverse.partners.remove', Api\PartnersController::class)
        ->put('/flarumverse/partners', 'flarumverse.directory.optin', Api\PartnersController::class)
        ->get('/flarumverse/directory', 'flarumverse.directory', Api\DirectoryController::class)
        ->get('/flarumverse/directory/listings', 'flarumverse.listings', Api\ListingsController::class)
        ->patch('/flarumverse/directory/listings/{id:[0-9]+}', 'flarumverse.listings.update', Api\ListingsController::class)
        ->delete('/flarumverse/directory/listings/{id:[0-9]+}', 'flarumverse.listings.remove', Api\ListingsController::class),

    (new Extend\Settings())
        ->serializeToForum('flarumversePartners', Identity::PREFIX.'partners', fn ($v) => (int) $v),

    // True only on the forum hosting the directory: settings already loaded, no query.
    (new Extend\ApiResource(ForumResource::class))
        ->fields(fn () => [
            Schema\Boolean::make('flarumverseDirectory')->get(fn () => resolve(Directory::class)->isHost()),
        ]),

    (new Extend\Event())
        ->listen(Started::class, QueuePush::class),

    (new Extend\Console())
        ->command(PullCommand::class)
        ->command(DirectoryCommand::class)
        ->schedule(PullCommand::class, function (Event $event): void {
            $event->everyFifteenMinutes()->withoutOverlapping();
        })
        ->schedule(DirectoryCommand::class, function (Event $event): void {
            $event->dailyAt('05:23')->withoutOverlapping();
        }),
];
