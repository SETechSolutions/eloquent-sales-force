<?php

namespace Lester\EloquentSalesForce\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifySyncSecret
{
    public const HEADER = 'X-Salesforce-Sync-Secret';

    /**
     * Reject sync webhook calls that do not carry the shared secret.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('eloquent_sf.routes.sync.secret');

        // false hands authentication over to the other route middleware.
        if ($secret === false) {
            return $next($request);
        }

        $given = (string) $request->header(self::HEADER, '');

        abort_unless(is_string($secret) && $secret !== '' && hash_equals($secret, $given), 403);

        return $next($request);
    }
}
