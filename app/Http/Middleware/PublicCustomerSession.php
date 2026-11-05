<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PublicCustomerSession
{
    public function handle(Request $request, Closure $next, string $mode = 'verified')
    {
        $guard = Auth::guard('public_customer');
        $customer = $guard->user();
        if ($customer && $request->session()->has('public_customer_password_hash')
            && !hash_equals($customer->getAuthPassword(), (string) $request->session()->get('public_customer_password_hash'))) {
            $guard->logoutCurrentDevice();
            $request->session()->forget('public_customer_password_hash');
            $request->session()->regenerate(true);
            $customer = null;
        }
        if ($mode === 'guest') {
            return $customer ? redirect()->route($customer->hasVerifiedEmail() ? 'public-account.bookings' : 'public-account.verification.notice') : $this->private($next($request));
        }
        if ($mode === 'optional') {
            $response = $next($request);
            return $customer ? $this->private($response) : $response;
        }
        if (!$customer) {
            if ($request->isMethod('get')) $request->session()->put('public_customer_intended', $request->getRequestUri());
            return redirect()->route('public-account.login');
        }
        $request->session()->put('public_customer_password_hash', $customer->getAuthPassword());
        if ($mode === 'verified' && !$customer->hasVerifiedEmail()) return redirect()->route('public-account.verification.notice');

        return $this->private($next($request));
    }

    private function private($response)
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        return $response;
    }
}
