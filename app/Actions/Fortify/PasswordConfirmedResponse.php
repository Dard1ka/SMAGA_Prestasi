<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\User;
use Laravel\Fortify\Http\Responses\PasswordConfirmedResponse as FortifyPasswordConfirmedResponse;

/**
 * Fortify's answer to a correct password on `/user/confirm-password`, plus the remembered confirmation of this
 * browser for a staff account (RememberedPasswordConfirmation, 30 days).
 */
final class PasswordConfirmedResponse extends FortifyPasswordConfirmedResponse
{
    public function toResponse($request)
    {
        $user = $request->user();

        if ($user instanceof User) {
            RememberedPasswordConfirmation::remember($user, $request);
        }

        return parent::toResponse($request);
    }
}
