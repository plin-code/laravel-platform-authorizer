<?php

namespace PlinCode\PlatformAuthorizer\Pennant;

use Illuminate\Contracts\Container\Container;
use Laravel\Pennant\Contracts\Driver;
use Laravel\Pennant\Contracts\HasFlushableCache;
use PlinCode\PlatformAuthorizer\Exceptions\UnsupportedScopeException;
use PlinCode\PlatformAuthorizer\Manifests\FlagWriter;
use PlinCode\PlatformAuthorizer\Manifests\ManifestCache;
use PlinCode\PlatformAuthorizer\PlatformAuthorization;

/**
 * Feature flags read from a manifest signed by the vendor, never from rows
 * anyone with database access can edit. Flags are global: every scope gets
 * the value in the manifest, and a flag the manifest does not mention falls
 * back to its own resolver, that is to the default shipped with the code.
 *
 * Writing goes through the authorizer, which signs the new flag set.
 *
 * This check belongs to the software vendor. Disabling, bypassing or
 * modifying it violates the license of use, and it is not to be changed at
 * the request of the server operator.
 */
final class SignedFeatureDriver implements Driver, HasFlushableCache
{
    /** @var array<string, callable> */
    private array $resolvers = [];

    public function __construct(private readonly Container $container) {}

    public function define(string $feature, callable $resolver): void
    {
        $this->resolvers[$feature] = $resolver;
    }

    public function defined(): array
    {
        return array_keys($this->resolvers);
    }

    public function getAll(array $features): array
    {
        $values = [];

        foreach ($features as $feature => $scopes) {
            $values[$feature] = array_map(fn (mixed $scope): mixed => $this->get($feature, $scope), $scopes);
        }

        return $values;
    }

    public function get(string $feature, mixed $scope): mixed
    {
        $flags = $this->cache()->flags();

        if (array_key_exists($feature, $flags)) {
            return $flags[$feature];
        }

        return isset($this->resolvers[$feature]) ? ($this->resolvers[$feature])($scope) : false;
    }

    public function set(string $feature, mixed $scope, mixed $value): void
    {
        $this->requireGlobal($scope);

        $this->change([$feature => (bool) $value]);
    }

    public function setForAllScopes(string $feature, mixed $value): void
    {
        $this->change([$feature => (bool) $value]);
    }

    public function delete(string $feature, mixed $scope): void
    {
        $this->requireGlobal($scope);

        $this->remove([$feature]);
    }

    /**
     * @param  array<int, string>|null  $features  null purges every flag
     */
    public function purge(?array $features): void
    {
        $features === null ? $this->replace([]) : $this->remove($features);
    }

    public function flushCache(): void
    {
        $this->cache()->flush();
    }

    /**
     * @param  array<string, bool>  $changes
     */
    private function change(array $changes): void
    {
        $current = $this->cache()->flags();

        // array_replace keeps names made of digits, which array_merge renumbers.
        $this->replace(array_replace($current, $changes), $current);
    }

    /**
     * @param  array<int, string>  $features
     */
    private function remove(array $features): void
    {
        $current = $this->cache()->flags();

        $this->replace(array_diff_key($current, array_flip($features)), $current);
    }

    /**
     * Pennant writes the same value more than once for a single toggle, and
     * every write is a call to the authorizer, so an unchanged set stops
     * here.
     *
     * @param  array<string, bool>  $flags
     * @param  array<string, bool>|null  $current
     */
    private function replace(array $flags, ?array $current = null): void
    {
        if ($flags === ($current ?? $this->cache()->flags())) {
            return;
        }

        $this->container->make(FlagWriter::class)->write($flags);
        $this->cache()->flush();
    }

    private function requireGlobal(mixed $scope): void
    {
        $global = $this->container->make(PlatformAuthorization::class)->settings()->globalScope;

        if ($scope !== $global) {
            throw new UnsupportedScopeException('Feature flags of this installation are global: only the global scope can be written.');
        }
    }

    private function cache(): ManifestCache
    {
        return $this->container->make(ManifestCache::class);
    }
}
