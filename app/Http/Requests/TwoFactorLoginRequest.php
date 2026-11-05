<?php

namespace App\Http\Requests;

use App\Support\AccountAccess;
use Illuminate\Http\Exceptions\HttpResponseException;
use Laravel\Fortify\Http\Requests\TwoFactorLoginRequest as FortifyTwoFactorLoginRequest;

class TwoFactorLoginRequest extends FortifyTwoFactorLoginRequest
{
    public function challengedUser()
    {
        $user = parent::challengedUser();

        // L'account può essere stato disattivato dopo l'inserimento della password.
        if (! AccountAccess::allows($user)) {
            throw new HttpResponseException(AccountAccess::deny($this));
        }

        return $user;
    }
}
