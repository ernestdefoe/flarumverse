<?php

namespace ErnestDefoe\Flarumverse\Api;

use Carbon\Carbon;
use ErnestDefoe\Flarumverse\Directory;
use ErnestDefoe\Flarumverse\Listing;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** GET /api/flarumverse/directory?topic=&language=&q=&offset=: the public /forums page (host only). */
class DirectoryController implements RequestHandlerInterface
{
    private const PAGE = 24;

    public function __construct(protected Directory $directory)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertCan('viewForum');
        if (! $this->directory->isHost()) {
            return new JsonResponse(['forums' => [], 'more' => false], 404);
        }

        $query = $request->getQueryParams();
        $topic = (string) Arr::get($query, 'topic', '');
        $language = (string) Arr::get($query, 'language', '');
        $q = mb_substr(trim((string) Arr::get($query, 'q', '')), 0, 60);
        $offset = max(0, min((int) Arr::get($query, 'offset', 0), 5000));

        $base = Listing::query()
            ->where('status', Listing::APPROVED)
            ->where(fn ($w) => $w->whereNull('unreachable_since')->orWhere('unreachable_since', '>', Carbon::now()->subDays(Directory::HIDE_AFTER_DAYS)));

        $rows = (clone $base)
            ->when(in_array($topic, Directory::TOPICS, true), fn ($w) => $w->where('topic', $topic))
            ->when(in_array($language, Directory::LANGUAGES, true), fn ($w) => $w->where('language', $language))
            ->when($q !== '', function ($w) use ($q) {
                $like = '%'.addcslashes($q, '%_\\').'%';
                $w->where(fn ($x) => $x->where('name', 'like', $like)->orWhere('description', 'like', $like)->orWhere('url', 'like', $like));
            })
            ->orderByRaw('last_activity_at IS NULL')
            ->orderByDesc('last_activity_at')
            ->orderBy('id')
            ->offset($offset)
            ->limit(self::PAGE + 1)
            ->get();

        return new JsonResponse([
            'forums' => $rows->take(self::PAGE)->map(fn (Listing $l) => [
                'id' => $l->id,
                'url' => $l->url,
                'name' => $l->name,
                'description' => $l->description,
                'logo' => $l->logo,
                'icon' => $l->icon,
                'logoBg' => $l->logo_bg,
                'topic' => $l->topic,
                'language' => $l->language,
                'members' => $l->members,
                'discussions' => $l->discussions,
                'lastActivity' => $l->last_activity_at?->toIso8601String(),
                'latest' => json_decode((string) $l->latest, true) ?: [],
            ])->values()->all(),
            // Only topics and languages that have forums, for the filters.
            'topics' => (clone $base)->distinct()->orderBy('topic')->pluck('topic')->all(),
            'languages' => (clone $base)->distinct()->orderBy('language')->pluck('language')->all(),
            'more' => $rows->count() > self::PAGE,
        ]);
    }
}
