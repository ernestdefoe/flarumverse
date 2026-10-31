<?php

namespace ErnestDefoe\Flarumverse\Http;

use ErnestDefoe\Flarumverse\Address;
use ErnestDefoe\Flarumverse\Identity;
use ErnestDefoe\Flarumverse\Partner;
use ErnestDefoe\Flarumverse\Threads;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** GET /.well-known/flarumverse/threads: our latest shared discussions, for partners only. */
class ThreadsController implements RequestHandlerInterface
{
    public function __construct(
        protected Protocol $protocol,
        protected Identity $identity,
        protected Threads $threads,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $headers = Headers::of($request);
        $from = Address::normalise($headers[strtolower(Protocol::FROM)] ?? '');
        $partner = $from ? Partner::forUrl($from) : null;

        if ($partner?->status !== Partner::PAIRED
            || ! $this->protocol->verify('GET', $this->identity->url().Protocol::PATH.'/threads', $headers, '', $partner->public_key, time())) {
            return new JsonResponse(['error' => 'not_paired'], 403);
        }

        return new JsonResponse(['threads' => $this->threads->latest()]);
    }
}
