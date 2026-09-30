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
     * verify or when the table cannot be read. The log context carries the
     * installation slug and a reason, nothing else.
     */
    public function current(): ?Manifest
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
            return null;
        }

        $manifest = $this->verifier->verify($token);

        if ($manifest === null) {
            Log::warning('Feature manifest refused, shipped defaults in use', [
                'installation' => $this->settings->installation,
                'reason' => 'invalid',
            ]);
        }

        return $manifest;
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
            $current = $this->current();

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
