<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Spatie\Permission\Middleware\{RoleMiddleware, PermissionMiddleware, RoleOrPermissionMiddleware};
use App\Http\Middleware\EnsureOrganizationIsActive;
use App\Http\Middleware\RestrictPublicPortalHost;


return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Copre anche gli aggiornamenti Livewire e le pagine account di Fortify/Jetstream.
        $middleware->web(prepend: [RestrictPublicPortalHost::class], append: [EnsureOrganizationIsActive::class, \App\Http\Middleware\ProtectAmdRentPayment::class]);
        $middleware->validateCsrfTokens(except: ['amd-rent/stripe/webhook']);
        $middleware->api(prepend: [RestrictPublicPortalHost::class], append: [\App\Http\Middleware\ProtectAmdRentPayment::class]);
        $middleware->prependToPriorityList(\Illuminate\Cookie\Middleware\EncryptCookies::class, RestrictPublicPortalHost::class);

        $middleware->alias([
            'role'               => RoleMiddleware::class,
            'permission'         => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            'ensure.organization.active' => EnsureOrganizationIsActive::class,
            'public.customer' => \App\Http\Middleware\PublicCustomerSession::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
