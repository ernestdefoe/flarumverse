<?php

namespace ErnestDefoe\Flarumverse\Http;

use ErnestDefoe\Flarumverse\Identity;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** GET /.well-known/flarumverse: who this forum is, and its public key. */
class AboutController implements RequestHandlerInterface
{
    public function __construct(protected Identity $identity)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new JsonResponse([
            'protocol' => Protocol::VERSION,
            'url' => $this->identity->url(),
            'name' => $this->identity->name(),
            'description' => $this->identity->description(),
            'publicKey' => $this->identity->publicKey(),
        ], 200, ['Cache-Control' => 'public, max-age=300']);
    }
}
