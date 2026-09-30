<?php

namespace PlinCode\PlatformAuthorizer\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PlinCode\PlatformAuthorizer\Client\AuthorizerClient;
use PlinCode\PlatformAuthorizer\Exceptions\AuthorizerUnavailableException;
use PlinCode\PlatformAuthorizer\Exceptions\InvalidConfigurationException;
use PlinCode\PlatformAuthorizer\Manifests\ManifestRepository;
use PlinCode\PlatformAuthorizer\Manifests\StoreOutcome;

/**
 * Downloads the latest signed manifest, so a flag changed on the authorizer
 * reaches this installation without anyone using the panel. A version older
 * than the one stored is refused: it is what putting back an old manifest
 * looks like.
 */
class SyncFlagsCommand extends Command
{
    protected $signature = 'platform-authorizer:sync-flags';

    protected $description = 'Download the latest signed feature flag manifest from the authorizer';

    public function handle(): int
    {
        try {
            $token = $this->laravel->make(AuthorizerClient::class)->fetchManifest();
            $repository = $this->laravel->make(ManifestRepository::class);
        } catch (InvalidConfigurationException $exception) {
            Log::error('Platform authorizer configuration is invalid', ['setting' => $exception->setting]);
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        } catch (AuthorizerUnavailableException) {
            $this->components->error('The platform authorizer is not available.');

            return self::FAILURE;
        }

        if ($token === null) {
            $this->components->info('No manifest has been issued for this installation yet.');

            return self::SUCCESS;
        }

        $outcome = $repository->store($token);

        if ($outcome === StoreOutcome::Stored) {
            $this->components->info('Flags synchronised, version '.($repository->current()->version ?? 0).'.');

            return self::SUCCESS;
        }

        Log::warning('Platform authorizer manifest not accepted during synchronisation', ['outcome' => $outcome->value]);
        $this->components->error($outcome === StoreOutcome::Older
            ? 'The manifest is older than the one stored, it was not accepted.'
            : 'The manifest was not accepted: it is not a genuine manifest for this installation.');

        return self::FAILURE;
    }
}
