<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Mcp\Middleware;

use ArtisanBuild\BuiltForCloud\TokenRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateHoneMcp
{
    public function __construct(private readonly TokenRegistry $tokens) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->tokens->resolve((string) $request->bearerToken()) === null) {
            abort(401);
        }

        return $next($request);
    }
}
