<?php

namespace ErnestDefoe\Flarumverse;

use Flarum\Foundation\Config;
use Flarum\Http\UrlGenerator;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionResolverInterface;

/**
 * This forum as the Flarumverse knows it: its address and its Ed25519 signing key.
 *
 * The secret key is encrypted at rest with a key derived from config.php (or
 * FLARUMVERSE_SECRET), so database access alone can't sign as this forum. Ported
 * from Federation's KeyManager, which survived six audit passes.
 */
class Identity
{
    public const PREFIX = 'ernestdefoe-flarumverse.';

    private const ENC_PREFIX = 'enc:v1:';

    private ?string $secretKey = null;

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected UrlGenerator $url,
        protected Config $config,
        protected ConnectionResolverInterface $db,
    ) {
    }

    /** This forum's address, normalised the way partners store it. */
    public function url(): string
    {
        return Address::normalise($this->url->to('forum')->base()) ?? rtrim($this->url->to('forum')->base(), '/');
    }

    public function name(): string
    {
        return mb_substr((string) $this->settings->get('forum_title'), 0, 100);
    }

    public function description(): string
    {
        return mb_substr((string) $this->settings->get('forum_description'), 0, 300);
    }

    /** The forum's brand colour (its primary colour), as #rrggbb, when set. */
    public function color(): ?string
    {
        $color = strtolower((string) $this->settings->get('theme_primary_color'));

        return preg_match('/^#[0-9a-f]{6}$/', $color) ? $color : null;
    }

    /** The forum's logo and favicon as absolute URLs, when set. */
    public function asset(string $setting): ?string
    {
        $path = (string) $this->settings->get($setting);

        return $path === '' ? null : resolve('filesystem')->disk('flarum-assets')->url($path);
    }

    public function publicKey(): string
    {
        $this->keys();

        return (string) $this->settings->get(self::PREFIX.'public_key');
    }

    /** Detached Ed25519 signature, base64. */
    public function sign(string $message): string
    {
        return base64_encode(sodium_crypto_sign_detached($message, $this->keys()));
    }

    /** The secret key, generating the pair under a lock the first time. */
    private function keys(): string
    {
        if ($this->secretKey !== null) {
            return $this->secretKey;
        }

        $stored = (string) $this->settings->get(self::PREFIX.'secret_key', '');
        if ($stored !== '' && ($key = $this->decrypt($stored)) !== '') {
            return $this->secretKey = $key;
        }

        // Two first requests at once must not each make a key pair: the loser's
        // public key would be published while the winner's secret key signs.
        return $this->secretKey = $this->db->connection()->transaction(function () {
            $conn = $this->db->connection();
            $secretSetting = self::PREFIX.'secret_key';

            $conn->table('settings')->insertOrIgnore(['key' => $secretSetting, 'value' => '']);
            $current = (string) ($conn->table('settings')->where('key', $secretSetting)->lockForUpdate()->value('value') ?? '');
            if ($current !== '' && ($key = $this->decrypt($current)) !== '') {
                return $key;
            }

            $pair = sodium_crypto_sign_keypair();
            $secret = sodium_crypto_sign_secretkey($pair);
            $this->settings->set(self::PREFIX.'public_key', base64_encode(sodium_crypto_sign_publickey($pair)));
            $this->settings->set($secretSetting, $this->encrypt($secret));

            return $secret;
        });
    }

    private function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return self::ENC_PREFIX.base64_encode($nonce.sodium_crypto_secretbox($plain, $nonce, $this->atRestKey()));
    }

    private function decrypt(string $value): string
    {
        if (! str_starts_with($value, self::ENC_PREFIX)) {
            return '';
        }
        $raw = base64_decode(substr($value, strlen(self::ENC_PREFIX)), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return '';
        }
        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->atRestKey(),
        );

        return $plain === false ? '' : $plain;
    }

    /** 32 bytes from outside the database: FLARUMVERSE_SECRET, else config.php. */
    private function atRestKey(): string
    {
        $env = getenv('FLARUMVERSE_SECRET');
        $material = is_string($env) && $env !== '' ? $env : (json_encode($this->config['database'] ?? null) ?: (string) $this->config->url());

        return hash('sha256', 'ernestdefoe-flarumverse|'.$material, true);
    }
}
