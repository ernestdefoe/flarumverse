<?php

namespace ErnestDefoe\Flarumverse\Http;

use ErnestDefoe\Flarumverse\Address;
use ErnestDefoe\Flarumverse\Identity;
use ErnestDefoe\Flarumverse\Pairing;
use ErnestDefoe\Flarumverse\Partner;
use ErnestDefoe\Flarumverse\Threads;
use Illuminate\Contracts\Cache\Repository as Cache;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /.well-known/flarumverse/inbox: signed messages from other forums.
 *
 * A known forum is checked against the key stored when we paired, with no
 * outbound call. Only a pair-request may come from a forum we don't know, and
 * those are capped per day before anything is fetched.
 */
class InboxController implements RequestHandlerInterface
{
    private const MAX_BODY = 65536;
    private const STRANGERS_PER_DAY = 10;

    public function __construct(
        protected Protocol $protocol,
        protected Identity $identity,
        protected Remote $remote,
        protected Pairing $pairing,
        protected Threads $threads,
        protected Cache $cache,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $receivedAt = time();
        // Flarum's body parsing has already read the stream: start again.
        $stream = $request->getBody();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }
        $body = $stream->read(self::MAX_BODY + 1);
        if (strlen($body) > self::MAX_BODY) {
            return self::error('too_large', 413);
        }
        $message = json_decode($body, true);
        $headers = Headers::of($request);
        $from = Address::normalise($headers[strtolower(Protocol::FROM)] ?? '');
        if (! is_array($message) || ! is_string($message['type'] ?? null) || $from === null || $from === $this->identity->url()) {
            return self::error('bad_request', 400);
        }

        $partner = Partner::forUrl($from);
        $about = null;
        $key = $partner?->public_key;

        if ($message['type'] === 'pair-request' && ! in_array($partner?->status, [Partner::PAIRED, Partner::OUTGOING], true)) {
            if (! $this->strangerAllowed($from)) {
                return self::error('busy', 429);
            }
            $about = $this->remote->about($from);
            if ($about === null) {
                $this->cache->put('flarumverse:nofetch:'.sha1($from), 1, 600);

                return self::error('not_a_flarumverse_forum', 422);
            }
            $key = $about['publicKey'];
        }

        $target = $this->identity->url().Protocol::PATH.'/inbox';
        if ($key === null || ! $this->protocol->verify('POST', $target, $headers, $body, $key, $receivedAt)) {
            return self::error('bad_signature', 401);
        }

        if ($about !== null) {
            $reply = $this->pairing->received($about, $partner);

            return new JsonResponse($reply, isset($reply['error']) ? 409 : 202);
        }
        if ($partner === null) {
            return self::error('not_paired', 403);
        }

        match ($message['type']) {
            'pair-request' => null, // already paired, or crossed requests: handled when we asked
            'pair-accept' => $this->pairing->accepted($partner),
            'pair-decline' => $this->pairing->refused($partner),
            'unpair' => $this->pairing->ended($partner),
            'thread' => $this->thread($partner, $message['thread'] ?? null),
            default => null,
        };

        return new JsonResponse(['status' => $partner->exists ? $partner->status : 'removed'], 202);
    }

    private function thread(Partner $partner, mixed $thread): void
    {
        if ($partner->status === Partner::PAIRED && ($row = Threads::clean($thread, $partner))) {
            $this->threads->upsert($row);
        }
    }

    /** Pair-requests from forums we don't know: a daily cap, and none from addresses that just failed. */
    private function strangerAllowed(string $from): bool
    {
        if ($this->cache->has('flarumverse:nofetch:'.sha1($from))) {
            return false;
        }
        $key = 'flarumverse:strangers:'.gmdate('Ymd');
        $this->cache->add($key, 0, 86400);

        return $this->cache->increment($key) <= self::STRANGERS_PER_DAY;
    }

    private static function error(string $code, int $status): JsonResponse
    {
        return new JsonResponse(['error' => $code], $status);
    }
}
