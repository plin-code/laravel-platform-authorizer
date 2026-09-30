<?php

namespace PlinCode\PlatformAuthorizer\Http;

use Livewire\Mechanisms\HandleRequests\HandleRequests;

/**
 * Tells a real Livewire update from anything else.
 *
 * The X-Livewire header is set by the client, so it proves nothing: a plain
 * GET carrying it must not be treated as a Livewire request. What counts is
 * that the request in flight is a POST to Livewire's update endpoint. The
 * current request is read from the container and not from the one handed to
 * a middleware, because Livewire replays persistent middleware on a
 * request it rebuilds from the page the component was rendered on.
 */
final class LivewireRequest
{
    public static function isUpdate(): bool
    {
        if (! class_exists(HandleRequests::class)) {
            return false;
        }

        $request = request();

        return $request->isMethod('POST')
            && '/'.$request->path() === app(HandleRequests::class)->getUpdateUri();
    }
}
