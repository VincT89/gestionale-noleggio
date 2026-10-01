<?php

namespace App\Support;

use App\Models\{Organization, User};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AccountAccess
{
    public static function allows(User $user): bool
    {
        // Rileggiamo lo stato: la sessione o una relazione caricata possono precedere il blocco.
        $user = $user->fresh();
        if (! $user || $user->trashed() || ! $user->is_active) {
            return false;
        }

        // Un admin attivo mantiene la gestione anche se l'organizzazione è archiviata.
        if ($user->hasRole('admin')) {
            return true;
        }

        if (! $user->organization_id) {
            return ! $user->hasRole('renter');
        }

        $organization = Organization::withTrashed()->find($user->organization_id);

        return $organization && ! $organization->trashed() && $organization->is_active;
    }

    public static function deny(Request $request): Response
    {
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        Auth::forgetGuards();
        $request->setUserResolver(fn () => null);

        return $request->expectsJson()
            ? response()->json(['message' => 'Accesso sospeso.'], 403)
            : response()->view('auth.organization-blocked', [], 403);
    }
}
