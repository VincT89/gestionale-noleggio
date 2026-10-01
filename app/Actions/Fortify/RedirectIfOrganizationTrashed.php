<?php

namespace App\Actions\Fortify;

use App\Http\Middleware\EnsureOrganizationIsActive;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectIfOrganizationTrashed
{
    public function __invoke(Request $request, Closure $next): Response
    {
        // Ricontrolla lo stato dopo l'autenticazione, prima di preparare la sessione.
        return app(EnsureOrganizationIsActive::class)->handle($request, $next);
    }
}
