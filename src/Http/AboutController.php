<?php

namespace ErnestDefoe\Flarumverse\Http;

use ErnestDefoe\Flarumverse\Directory;
use ErnestDefoe\Flarumverse\Identity;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** GET /.well-known/flarumverse: who this forum is, and its public key. */
class AboutController implements RequestHandlerInterface
{
    public function __construct(
        protected Identity $identity,
        protected Directory $directory,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new JsonResponse([
            'protocol' => Protocol::VERSION,
            'url' => $this->identity->url(),
            'name' => $this->identity->name(),
            'description' => $this->identity->description(),
            'logo' => $this->identity->asset('logo_path'),
            'icon' => $this->identity->asset('favicon_path'),
            'color' => $this->identity->color(),
            'publicKey' => $this->identity->publicKey(),
            'directory' => $this->directory->profile(),
        ], 200, ['Cache-Control' => 'public, max-age=300']);
    }
}
