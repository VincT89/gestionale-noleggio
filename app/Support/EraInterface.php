<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

final class EraInterface
{
    public static function modern(?User $user): bool
    {
        return $user !== null && $user->hasRole('admin');
    }

    public static function classic(): bool
    {
        $user = Auth::user();

        // Public pages retain their own presentation, even with an ERA session.
        return $user !== null && !self::modern($user)
            && !request()->routeIs('public-cars.*', 'public-site.*', 'public-account.*',
                'public-enquiries.*', 'public-bookings.confirmation', 'public-bookings.pdf', 'public-bookings.pay');
    }

    public static function present(View $view): void
    {
        if (!self::classic()) {
            return;
        }

        $root = str_replace('\\', '/', resource_path('views')).'/';
        $path = str_replace('\\', '/', $view->getPath());
        if (!str_starts_with($path, $root) || str_starts_with($path, $root.'era-classic/')) {
            return;
        }

        $classic = $root.'era-classic/'.substr($path, strlen($root));
        if (is_file($classic)) {
            // Select per render, including Livewire requests. Do not cache a role in
            // the view finder: a later request may belong to another user.
            $view->setPath($classic);
        }
    }
}
