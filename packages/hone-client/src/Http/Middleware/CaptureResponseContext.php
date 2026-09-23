<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneClient\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

final class CaptureResponseContext
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $vary = $response->getVary();

        Context::add('hone.response', [
            'sets_cookie' => $response->headers->has('set-cookie'),
            'cache_control' => $response->headers->get('cache-control'),
            'vary' => $vary === [] ? null : implode(', ', $vary),
        ]);

        return $response;
    }
}
