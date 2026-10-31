<?php

namespace ErnestDefoe\Flarumverse\Http;

use ErnestDefoe\Flarumverse\Identity;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Signing and checking requests between forums.
 *
 * Each request carries who sent it, when, and an Ed25519 signature over the
 * method, the FULL address it was sent to (so a request to one forum can't be
 * replayed at another), the sender, the time and a hash of the body. A signature
 * is accepted once, and only within five minutes of its time.
 */
class Protocol
{
    public const VERSION = 1;

    public const FROM = 'X-Flarumverse-From';
    public const DATE = 'X-Flarumverse-Date';
    public const SIGNATURE = 'X-Flarumverse-Signature';

    /** The well-known path every Flarumverse forum answers on, under its address. */
    public const PATH = '/.well-known/flarumverse';

    private const WINDOW = 300;

    public function __construct(
        protected Identity $identity,
        protected Cache $cache,
    ) {
    }

    /** @return array<string,string> headers for a request to $target */
    public function sign(string $method, string $target, string $body): array
    {
        $from = $this->identity->url();
        $date = (string) time();

        return [
            self::FROM => $from,
            self::DATE => $date,
            self::SIGNATURE => $this->identity->sign(self::message($method, $target, $from, $date, $body)),
        ];
    }

    /**
     * True when the request was signed by $publicKey for exactly this address,
     * recently, and hasn't been seen before.
     *
     * @param array<string,string> $headers lower-cased names
     */
    public function verify(string $method, string $target, array $headers, string $body, string $publicKey, int $receivedAt): bool
    {
        $from = $headers[strtolower(self::FROM)] ?? '';
        $date = $headers[strtolower(self::DATE)] ?? '';
        $signature = base64_decode($headers[strtolower(self::SIGNATURE)] ?? '', true);
        $key = base64_decode($publicKey, true);

        if ($from === '' || ! ctype_digit($date) || abs($receivedAt - (int) $date) > self::WINDOW
            || $signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || $key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }

        if (! sodium_crypto_sign_verify_detached($signature, self::message($method, $target, $from, $date, $body), $key)) {
            return false;
        }

        // Once only: a captured request replayed inside the window is dropped.
        return $this->cache->add('flarumverse:sig:'.sha1($signature), 1, 2 * self::WINDOW);
    }

    private static function message(string $method, string $target, string $from, string $date, string $body): string
    {
        return implode("\n", ['flarumverse/'.self::VERSION, strtoupper($method), $target, $from, $date, hash('sha256', $body)]);
    }
}
