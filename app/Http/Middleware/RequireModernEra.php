<?php

namespace App\Http\Middleware;

use App\Support\EraInterface;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireModernEra
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(EraInterface::modern($request->user()), 403);

        return $next($request);
    }
}
