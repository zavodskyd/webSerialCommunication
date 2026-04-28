<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\SerialHelperTokens;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class InternalToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $provided = (string) $request->header('X-Internal-Token', '');
        $expected = SerialHelperTokens::current();

        if ($provided === '' || ! hash_equals($expected, $provided)) {
            abort(401);
        }

        return $next($request);
    }
}
