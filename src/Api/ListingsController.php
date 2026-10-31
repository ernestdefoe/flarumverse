<?php

namespace ErnestDefoe\Flarumverse\Api;

use ErnestDefoe\Flarumverse\Directory;
use ErnestDefoe\Flarumverse\Listing;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The host's approval queue (admins, directory host only):
 *   GET    /api/flarumverse/directory/listings
 *   PATCH  /api/flarumverse/directory/listings/{id}  {action: approve|reject}
 *   DELETE /api/flarumverse/directory/listings/{id}
 */
class ListingsController implements RequestHandlerInterface
{
    public function __construct(protected Directory $directory)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();
        if (! $this->directory->isHost()) {
            return new JsonResponse(['listings' => []], 404);
        }

        $id = (int) Arr::get($request->getQueryParams(), 'id', 0);
        if ($request->getMethod() === 'PATCH') {
            $action = Arr::get((array) $request->getParsedBody(), 'action');
            $this->directory->decide(Listing::query()->findOrFail($id), $action === 'approve' ? Listing::APPROVED : Listing::REJECTED);
        } elseif ($request->getMethod() === 'DELETE') {
            $this->directory->remove(Listing::query()->findOrFail($id));
        }

        return new JsonResponse([
            'listings' => Listing::query()->orderByRaw("CASE status WHEN 'pending' THEN 0 WHEN 'approved' THEN 1 ELSE 2 END")->orderByDesc('updated_at')->limit(500)->get()
                ->map(fn (Listing $l) => [
                    'id' => $l->id,
                    'url' => $l->url,
                    'name' => $l->name,
                    'description' => $l->description,
                    'topic' => $l->topic,
                    'language' => $l->language,
                    'status' => $l->status,
                    'members' => $l->members,
                    'discussions' => $l->discussions,
                    'unreachable' => $l->unreachable_since !== null,
                    'updatedAt' => $l->updated_at->toIso8601String(),
                ])->all(),
        ]);
    }
}
