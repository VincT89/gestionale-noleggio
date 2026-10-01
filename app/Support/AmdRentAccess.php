<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

final class AmdRentAccess
{
    public static function check(User $user, bool $write = false): void
    {
        abort_unless($user->is_active && $user->organization?->is_active && $user->hasAnyRole(['admin', 'renter']), 403);
        Gate::forUser($user)->authorize($write ? 'rentals.create' : 'rentals.viewAny');
    }
    public static function scope(Builder $query, User $user): Builder
    {
        self::check($user);
        return $query->when(!$user->hasRole('admin'), fn ($q) => $q->where('organization_id', $user->organization_id));
    }
    public static function admin(User $user): void
    {
        self::check($user);
        abort_unless($user->hasRole('admin'), 403);
    }
}
