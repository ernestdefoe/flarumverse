<?php

namespace ErnestDefoe\Flarumverse\Api;

use ErnestDefoe\Flarumverse\FlarumverseThread;
use ErnestDefoe\Flarumverse\Partner;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** GET /api/flarumverse/threads?partner=&offset=: the Flarumverse page. Reads our table only. */
class ThreadsController implements RequestHandlerInterface
{
    private const PAGE = 20;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertCan('viewForum');

        $query = $request->getQueryParams();
        $partnerId = (int) Arr::get($query, 'partner', 0);
        $offset = max(0, min((int) Arr::get($query, 'offset', 0), 5000));

        $threads = FlarumverseThread::query()
            ->whereHas('partner', fn ($q) => $q->where('status', Partner::PAIRED))
            ->when($partnerId > 0, fn ($q) => $q->where('partner_id', $partnerId))
            ->orderByDesc('posted_at')
            ->orderByDesc('id')
            ->offset($offset)
            ->limit(self::PAGE + 1)
            ->get();

        $partners = Partner::query()->where('status', Partner::PAIRED)->orderBy('name')->get(['id', 'name', 'url', 'icon', 'color']);
        $names = $partners->keyBy('id');

        return new JsonResponse([
            'threads' => $threads->take(self::PAGE)->map(fn (FlarumverseThread $t) => [
                'id' => $t->id,
                'partner' => $t->partner_id,
                'forum' => $names->get($t->partner_id)?->name,
                'title' => $t->title,
                'excerpt' => $t->excerpt,
                'author' => $t->author,
                'tag' => $t->tag,
                'replies' => $t->replies,
                'url' => $t->url,
                'postedAt' => $t->posted_at->toIso8601String(),
            ])->values()->all(),
            'partners' => $partners->map(fn (Partner $p) => ['id' => $p->id, 'name' => $p->name, 'url' => $p->url, 'icon' => $p->icon, 'color' => $p->color])->all(),
            'more' => $threads->count() > self::PAGE,
        ]);
    }
}
