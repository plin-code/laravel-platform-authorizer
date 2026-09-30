<?php

namespace PlinCode\PlatformAuthorizer;

use PlinCode\PlatformAuthorizer\Exceptions\InvalidConfigurationException;

/**
 * The validated configuration. Anything that decides who gets in or which
 * flags apply is read from here, so a mistake fails loudly instead of
 * silently weakening the check.
 */
final readonly class Settings
{
    private const int DEFAULT_EXPIRED_STATUS = 419;

    /**
     * @param  array<non-empty-string, string>  $keys  key id => base64 public key
     * @param  list<non-empty-string>  $protectedLivewireNamespaces
     * @param  list<non-empty-string>  $exceptRoutes
     */
    public function __construct(
        public string $url,
        public string $product,
        public string $installation,
        public array $keys,
        public int $timeout,
        public int $livewireGraceSeconds,
        public int $expiredStatus,
        public array $protectedLivewireNamespaces,
        public array $exceptRoutes,
        public string $globalScope,
        public ?string $guard,
        public string $home,
        public string $deniedUrl,
    ) {}

    /**
     * @param  array<mixed>  $config
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            url: self::url($config['url'] ?? null),
            product: self::slug('product', $config['product'] ?? null),
            installation: self::slug('installation', $config['installation'] ?? null),
            keys: self::keys($config['keys'] ?? null),
            timeout: self::integer('timeout', $config['timeout'] ?? null, 1, 10),
            livewireGraceSeconds: self::integer('livewire_grace_seconds', $config['livewire_grace_seconds'] ?? null, 0, 3600),
            expiredStatus: self::expiredStatus($config['expired_status'] ?? self::DEFAULT_EXPIRED_STATUS),
            protectedLivewireNamespaces: self::strings('protected_livewire_namespaces', $config['protected_livewire_namespaces'] ?? []),
            exceptRoutes: self::strings('except_routes', $config['except_routes'] ?? []),
            globalScope: self::nonEmpty('global_scope', $config['global_scope'] ?? null),
            guard: self::optionalString('guard', $config['guard'] ?? null),
            home: self::destination('home', $config['home'] ?? null),
            deniedUrl: self::destination('denied_url', $config['denied_url'] ?? null),
        );
    }

    private static function url(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            throw InvalidConfigurationException::for('url', 'a URL is required');
        }

        $parts = parse_url($value);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw InvalidConfigurationException::for('url', 'it must be an absolute URL without credentials, query string or fragment');
        }

        $loopback = in_array($parts['host'], ['localhost', '127.0.0.1', '[::1]'], true);

        if ($parts['scheme'] !== 'https' && ! ($parts['scheme'] === 'http' && $loopback)) {
            throw InvalidConfigurationException::for('url', 'it must use https, or http towards a loopback host');
        }

        return rtrim($value, '/');
    }

    private static function slug(string $setting, mixed $value): string
    {
        if (! is_string($value) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/', $value) !== 1) {
            throw InvalidConfigurationException::for($setting, 'a slug of letters, digits, dots, underscores and hyphens is required');
        }

        return $value;
    }

    /**
     * @return array<non-empty-string, string>
     */
    private static function keys(mixed $value): array
    {
        if (! is_array($value) || $value === []) {
            throw InvalidConfigurationException::for('keys', 'at least one public key is required');
        }

        $keys = [];

        foreach ($value as $kid => $publicKey) {
            $raw = is_string($publicKey) ? base64_decode($publicKey, true) : false;

            if (! is_string($kid) || $kid === '' || $raw === false || strlen($raw) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                throw InvalidConfigurationException::for('keys', 'every entry must map a key id to a base64 Ed25519 public key');
            }

            $keys[$kid] = $publicKey;
        }

        return $keys;
    }

    private static function integer(string $setting, mixed $value, int $min, int $max): int
    {
        if (! is_int($value) || $value < $min || $value > $max) {
            throw InvalidConfigurationException::for($setting, "an integer between {$min} and {$max} is required");
        }

        return $value;
    }

    /**
     * 419 makes Livewire reload the page, 403 shows its error modal. Nothing
     * else is a meaningful answer to an expired authorization.
     */
    private static function expiredStatus(mixed $value): int
    {
        if ($value !== 403 && $value !== 419) {
            throw InvalidConfigurationException::for('expired_status', 'the integer 403 or 419 is required');
        }

        return $value;
    }

    /**
     * @return list<non-empty-string>
     */
    private static function strings(string $setting, mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw InvalidConfigurationException::for($setting, 'a list of strings is required');
        }

        foreach ($value as $item) {
            if (! is_string($item) || $item === '') {
                throw InvalidConfigurationException::for($setting, 'a list of non-empty strings is required');
            }
        }

        return $value;
    }

    private static function nonEmpty(string $setting, mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            throw InvalidConfigurationException::for($setting, 'a non-empty string is required');
        }

        return $value;
    }

    /**
     * A path on this application or an absolute http(s) URL. A value that
     * starts with two slashes would leave the site, so it is refused.
     */
    private static function destination(string $setting, mixed $value): string
    {
        $local = is_string($value) && str_starts_with($value, '/') && ! str_starts_with($value, '//');
        $absolute = is_string($value) && preg_match('#^https?://[^/\s]+#', $value) === 1;

        if (! $local && ! $absolute) {
            throw InvalidConfigurationException::for($setting, 'a path starting with a single slash or an absolute URL is required');
        }

        return $value;
    }

    private static function optionalString(string $setting, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return self::nonEmpty($setting, $value);
    }
}
