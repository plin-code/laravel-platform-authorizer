<?php

namespace PlinCode\PlatformAuthorizer\Manifests;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use PlinCode\PlatformAuthorizer\Exceptions\InvalidConfigurationException;

/**
 * The verified manifest, read once per request. It is bound as a scoped
 * instance, so a long lived worker starts every request with a clean slate.
 */
final class ManifestCache
{
    private bool $loaded = false;

    private ?Manifest $manifest = null;

    public function __construct(private readonly Container $container) {}

    /**
     * The flags of the manifest, or none when there is no trustworthy one.
     *
     * @return array<array-key, bool>
     */
    public function flags(): array
    {
        if (! $this->loaded) {
            $this->manifest = $this->load();
            $this->loaded = true;
        }

        return $this->manifest->flags ?? [];
    }

    public function flush(): void
    {
        $this->loaded = false;
        $this->manifest = null;
    }

    private function load(): ?Manifest
    {
        try {
            return $this->container->make(ManifestRepository::class)->current();
        } catch (InvalidConfigurationException $exception) {
            Log::error('Platform authorizer configuration is invalid, shipped defaults in use', ['setting' => $exception->setting]);

            return null;
        }
    }
}
