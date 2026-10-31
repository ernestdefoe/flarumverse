<?php

namespace ErnestDefoe\Flarumverse\Api;

use ErnestDefoe\Flarumverse\Directory;
use ErnestDefoe\Flarumverse\Identity;
use ErnestDefoe\Flarumverse\Pairing;
use ErnestDefoe\Flarumverse\Partner;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The admin screen's API (admins only):
 *   GET    /api/flarumverse/partners       everything + our address + suggestions
 *   POST   /api/flarumverse/partners       {url}: ask a forum to pair
 *   PATCH  /api/flarumverse/partners/{id}  {action: approve|decline}
 *   DELETE /api/flarumverse/partners/{id}  end the pairing / cancel / forget
 *   PUT    /api/flarumverse/partners       {listed, topic, language}: the directory opt-in
 */
class PartnersController implements RequestHandlerInterface
{
    public function __construct(
        protected Pairing $pairing,
        protected Identity $identity,
        protected Directory $directory,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $id = (int) Arr::get($request->getQueryParams(), 'id', 0);
        $body = (array) $request->getParsedBody();

        switch ($request->getMethod()) {
            case 'POST':
                $this->pairing->request((string) Arr::get($body, 'url', ''));
                break;
            case 'PATCH':
                $partner = Partner::query()->findOrFail($id);
                Arr::get($body, 'action') === 'approve' ? $this->pairing->approve($partner) : $this->pairing->decline($partner);
                break;
            case 'PUT': // the directory opt-in
                $this->directory->save((bool) Arr::get($body, 'listed'), (string) Arr::get($body, 'topic', ''), (string) Arr::get($body, 'language', ''), (string) Arr::get($body, 'logoBg', 'light'));
                break;
            case 'DELETE':
                $this->pairing->remove(Partner::query()->findOrFail($id));

                return new EmptyResponse(204);
        }

        return new JsonResponse($this->state());
    }

    /** @return array<string,mixed> */
    private function state(): array
    {
        $partners = Partner::query()->withCount('threads')->orderByDesc('updated_at')->get();
        $known = $partners->pluck('url')->all();

        return [
            'url' => $this->identity->url(),
            'max' => Pairing::MAX_PARTNERS,
            'directory' => $this->directory->state(),
            'partners' => $partners->map(fn (Partner $p) => [
                'id' => $p->id,
                'url' => $p->url,
                'name' => $p->name,
                'description' => $p->description,
                'status' => $p->status,
                'threads' => (int) $p->threads_count,
                'pairedAt' => $p->paired_at?->toIso8601String(),
                'updatedAt' => $p->updated_at?->toIso8601String(),
                'lastError' => $p->status === Partner::PAIRED && $p->failures > 0 ? $p->last_error : null,
            ])->all(),
            'suggestions' => array_values(array_filter(
                [$this->directory->host()],
                fn ($url) => $url !== $this->identity->url() && ! in_array($url, $known, true),
            )),
        ];
    }
}
