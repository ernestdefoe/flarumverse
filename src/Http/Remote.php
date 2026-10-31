<?php

namespace ErnestDefoe\Flarumverse\Http;

use ErnestDefoe\Flarumverse\Address;
use GuzzleHttp\Client;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;

/**
 * Calls to other forums. Every address goes through {@see UrlGuard}, the
 * connection is pinned to the checked IP, redirects are never followed, bodies
 * are capped and requests time out quickly: a partner can't make this server
 * reach its own network, hang a worker or fill its memory.
 */
class Remote
{
    private const MAX_BODY = 262144; // 256 KB: 50 threads are ~40 KB

    public function __construct(
        protected UrlGuard $guard,
        protected Protocol $protocol,
        protected Client $http,
        protected LoggerInterface $log,
    ) {
    }

    /**
     * A forum's public description, or null when it isn't a Flarumverse forum.
     *
     * @return array{url:string,name:string,description:string,publicKey:string,logo:string|null,icon:string|null,directory:array<string,mixed>|null}|null
     */
    public function about(string $base): ?array
    {
        $doc = $this->request('GET', $base.Protocol::PATH, null, false)[1];
        if (! is_array($doc) || ($doc['protocol'] ?? null) !== Protocol::VERSION) {
            return null;
        }

        // A forum speaks only for its own address.
        $url = Address::normalise((string) ($doc['url'] ?? ''));
        $key = base64_decode((string) ($doc['publicKey'] ?? ''), true);
        if ($url !== $base || $key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return null;
        }

        return [
            'url' => $url,
            'name' => mb_substr(trim((string) ($doc['name'] ?? '')), 0, 100) ?: (string) parse_url($url, PHP_URL_HOST),
            'description' => mb_substr(trim((string) ($doc['description'] ?? '')), 0, 300),
            'publicKey' => (string) $doc['publicKey'],
            'logo' => is_string($doc['logo'] ?? null) ? $doc['logo'] : null,
            'icon' => is_string($doc['icon'] ?? null) ? $doc['icon'] : null,
            // Present only while the forum asks to be in the directory.
            'directory' => is_array($doc['directory'] ?? null) ? $doc['directory'] : null,
        ];
    }

    /**
     * Signed POST of a message to a forum's inbox.
     *
     * @param array<string,mixed> $message
     * @return array{0:int,1:array<mixed>|null} status (0 = not reached) and decoded reply
     */
    public function send(string $base, array $message): array
    {
        return $this->request('POST', $base.Protocol::PATH.'/inbox', json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
    }

    /** @return array{0:int,1:array<mixed>|null} */
    public function threads(string $base): array
    {
        return $this->request('GET', $base.Protocol::PATH.'/threads', '');
    }

    /** @return array{0:int,1:array<mixed>|null} */
    private function request(string $method, string $url, ?string $body, bool $signed = true): array
    {
        $pin = $this->guard->pinnedIp($url);
        if ($pin === null) {
            return [0, null];
        }

        $headers = ['Accept' => 'application/json', 'User-Agent' => 'Flarumverse/1'];
        if ($signed) {
            $headers += $this->protocol->sign($method, $url, (string) $body);
        }
        if ($body !== null && $body !== '') {
            $headers['Content-Type'] = 'application/json';
        }

        try {
            $res = $this->http->request($method, $url, [
                'timeout' => 8,
                'connect_timeout' => 4,
                'headers' => $headers,
                'body' => $body ?: null,
                'http_errors' => false,
                'allow_redirects' => false,
                'stream' => true,
            ] + $this->pin($url, $pin));
            $raw = $this->read($res->getBody());
            $res->getBody()->close();
            $data = $raw === null ? null : json_decode($raw, true);

            return [$res->getStatusCode(), is_array($data) ? $data : null];
        } catch (\Throwable $e) {
            $this->log->info('[flarumverse] '.$method.' '.$url.' failed: '.$e->getMessage());

            return [0, null];
        }
    }

    private function read(StreamInterface $body): ?string
    {
        $buf = '';
        while (! $body->eof()) {
            $chunk = $body->read(65536);
            if ($chunk === '') {
                break;
            }
            $buf .= $chunk;
            if (strlen($buf) > self::MAX_BODY) {
                return null;
            }
        }

        return $buf;
    }

    /** @return array<string,mixed> */
    private function pin(string $url, string $pin): array
    {
        if ($pin === '' || ! defined('CURLOPT_RESOLVE')) {
            return [];
        }
        $parts = parse_url($url);
        $host = trim((string) ($parts['host'] ?? ''), '[]');
        $port = $parts['port'] ?? 443;

        return ['curl' => [CURLOPT_RESOLVE => ["$host:$port:".(str_contains($pin, ':') ? "[$pin]" : $pin)]]];
    }
}
