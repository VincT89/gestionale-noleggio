<?php

namespace App\Http\Middleware;

use App\Support\AccountAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOrganizationIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! AccountAccess::allows($user)) {
            return AccountAccess::deny($request);
        }

        return $next($request);
    }
}
