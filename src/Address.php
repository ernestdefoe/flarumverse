<?php

namespace ErnestDefoe\Flarumverse;

/** One spelling per forum address, so a forum is never stored twice. */
class Address
{
    /**
     * "Forum.example.com/", "https://forum.example.com/community?x" →
     * "https://forum.example.com/community". Null when it isn't an https address.
     */
    public static function normalise(string $input): ?string
    {
        $input = trim($input);
        if ($input === '' || strlen($input) > 191 || preg_match('/[\x00-\x20\x7f]/', $input)) {
            return null;
        }
        if (! preg_match('#^[a-z][a-z0-9+.-]*://#i', $input)) {
            $input = 'https://'.$input;
        }

        $parts = parse_url($input);
        if (! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $host = strtolower(rtrim($parts['host'], '.'));
        $port = isset($parts['port']) && (int) $parts['port'] !== 443 ? ':'.(int) $parts['port'] : '';
        $path = rtrim($parts['path'] ?? '', '/');
        if ($path !== '' && ! preg_match('#^(/[A-Za-z0-9._~%-]+)+$#', $path)) {
            return null;
        }

        return 'https://'.$host.$port.$path;
    }

    /** True when $url is a page of the forum at $base (same origin, under its path). */
    public static function belongsTo(string $url, string $base): bool
    {
        return str_starts_with($url, $base.'/') && ! preg_match('/[\x00-\x20\x7f"<>\\\\]/', $url)
            && strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https';
    }
}
