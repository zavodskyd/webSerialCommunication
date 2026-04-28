<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LocalhostOnly
{
    private const LOOPBACK_IPS = ['127.0.0.1', '::1'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->ip(), self::LOOPBACK_IPS, true)) {
            abort(403);
        }

        return $next($request);
    }
}
