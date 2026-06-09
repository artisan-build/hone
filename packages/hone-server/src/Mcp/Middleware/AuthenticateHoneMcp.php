<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Mcp\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateHoneMcp
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $configuredToken = trim((string) config('hone-server.mcp.token', ''));
        $presentedToken = $request->bearerToken();

        if ($configuredToken === '' || ! is_string($presentedToken) || ! hash_equals($configuredToken, $presentedToken)) {
            abort(401);
        }

        return $next($request);
    }
}
