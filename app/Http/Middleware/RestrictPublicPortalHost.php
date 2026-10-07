<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RestrictPublicPortalHost
{
    public function handle(Request $request, Closure $next): Response
    {
        $domain = config('public_cars.domain');

        if ($domain && strcasecmp($request->getHost(), $domain) === 0) {
            abort_unless($request->routeIs(
                'public-cars.index', 'public-cars.show', 'public-cars.photo',
                'public-cars.map.search', 'public-cars.map.store', 'public-cars.map.address',
                'public-cars.return-map.store',
                'public-cars.booking.create', 'public-cars.booking.store', 'public-cars.legacy.*',
                'public-bookings.confirmation', 'public-bookings.pdf',
                'public-site.how-it-works', 'public-site.support',
                'public-site.privacy', 'public-site.cookies',
                'public-site.long-term', 'public-site.long-term.store', 'public-enquiries.*', 'public-bookings.pay', 'amd-rent.stripe.webhook',
                'public-account.*',
            ), 404);
        }

        return $next($request);
    }
}
