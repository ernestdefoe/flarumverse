<?php

namespace ErnestDefoe\Flarumverse\Http;

use Psr\Http\Message\ServerRequestInterface;

class Headers
{
    /** @return array<string,string> lower-cased name => value */
    public static function of(ServerRequestInterface $request): array
    {
        $headers = [];
        foreach ([Protocol::FROM, Protocol::DATE, Protocol::SIGNATURE] as $name) {
            $headers[strtolower($name)] = $request->getHeaderLine($name);
        }

        return $headers;
    }
}
