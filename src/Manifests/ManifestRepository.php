<?php

namespace PlinCode\PlatformAuthorizer\Manifests;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PlinCode\PlatformAuthorizer\Settings;

/**
 * Keeps the signed manifest of this installation in the database. The row is
 * only a cache of something the authorizer signed: it is verified on every
 * read, so editing it by hand has no effect.
 */
final class ManifestRepository
{
    private const string TABLE = 'feature_manifests';

    public function __construct(
        private readonly ManifestVerifier $verifier,
        private readonly Settings $settings,
    ) {}

    /**
     * The stored manifest, or null when there is none, when it does not
     * verify or when the table cannot be read. Each of these is logged, with
     * the installation slug and a reason code and nothing else. The flags
     * read it through the scoped ManifestCache, so a request logs it once.
     */
    public function current(): ?Manifest
    {
        return $this->read(warnWhenMissing: true);
    }

    /**
     * @param  bool  $warnWhenMissing  false where no manifest yet is expected, like before the first store
     */
    private function read(bool $warnWhenMissing): ?Manifest
    {
        try {
            $token = DB::table(self::TABLE)->where('installation', $this->settings->installation)->value('token');
        } catch (QueryException) {
            Log::warning('Feature manifest unreadable, shipped defaults in use', [
                'installation' => $this->settings->installation,
                'reason' => 'unreadable',
            ]);

            return null;
        }

        if (! is_string($token)) {
            if ($warnWhenMissing) {
                Log::warning('Feature manifest missing, shipped defaults in use', [
                    'installation' => $this->settings->installation,
                    'reason' => 'missing',
                ]);
            }

            return null;
        }

        $verification = $this->verifier->check($token);

        if ($verification->manifest === null) {
            Log::warning('Feature manifest refused, shipped defaults in use', [
                'installation' => $this->settings->installation,
                'reason' => $verification->reason,
            ]);
        }

        return $verification->manifest;
    }

    /**
     * Stores a manifest received from the authorizer. A version lower than
     * the one already stored is refused, so an old manifest cannot be put
     * back; the same version is accepted, which makes a repeat harmless.
     */
    public function store(string $token): StoreOutcome
    {
        $manifest = $this->verifier->verify($token);

        if ($manifest === null) {
            return StoreOutcome::Invalid;
        }

        return DB::transaction(function () use ($token, $manifest): StoreOutcome {
            $current = $this->read(warnWhenMissing: false);

            if ($current !== null && $manifest->version < $current->version) {
                return StoreOutcome::Older;
            }

            DB::table(self::TABLE)->upsert(
                [[
                    'installation' => $this->settings->installation,
                    'token' => $token,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]],
                ['installation'],
                ['token', 'updated_at'],
            );

            return StoreOutcome::Stored;
        });
    }
}
