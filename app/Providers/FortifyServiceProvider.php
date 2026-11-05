<?php

namespace App\Providers;

use App\Actions\Fortify\{CreateNewUser, RedirectIfOrganizationTrashed, ResetUserPassword, UpdateUserPassword, UpdateUserProfileInformation};
use App\Http\Requests\TwoFactorLoginRequest;
use App\Support\AccountAccess;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth, RateLimiter};
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\{AttemptToAuthenticate, EnsureLoginIsNotThrottled, PrepareAuthenticatedSession, RedirectIfTwoFactorAuthenticatable};
use Laravel\Fortify\Fortify;
use Laravel\Fortify\Http\Requests\TwoFactorLoginRequest as FortifyTwoFactorLoginRequest;

class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(FortifyTwoFactorLoginRequest::class, TwoFactorLoginRequest::class);
    }

    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::redirectUserForTwoFactorAuthenticationUsing(RedirectIfTwoFactorAuthenticatable::class);

        Fortify::authenticateUsing(function (Request $request) {
            $provider = Auth::guard(config('fortify.guard'))->getProvider();
            $credentials = $request->only(Fortify::username(), 'password');
            $user = $provider->retrieveByCredentials($credentials);

            if (! $user || ! $provider->validateCredentials($user, $credentials)) {
                return null;
            }

            // Comunica lo stato dell'account solo dopo aver verificato le credenziali.
            if (! AccountAccess::allows($user)) {
                throw new HttpResponseException(AccountAccess::deny($request));
            }

            if (config('hashing.rehash_on_login', true) && method_exists($provider, 'rehashPasswordIfRequired')) {
                $provider->rehashPasswordIfRequired($user, $credentials);
            }

            return $user;
        });

        Fortify::authenticateThrough(fn (Request $request) => array_filter([
            config('fortify.limiters.login') ? null : EnsureLoginIsNotThrottled::class,
            RedirectIfTwoFactorAuthenticatable::class,
            AttemptToAuthenticate::class,
            RedirectIfOrganizationTrashed::class,
            PrepareAuthenticatedSession::class,
        ]));

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });
    }
}
