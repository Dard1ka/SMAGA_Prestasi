<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use Illuminate\Http\Exceptions\HttpResponseException;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\Http\Requests\TwoFactorLoginRequest;

/**
 * Fortify's request for `/two-factor-challenge` (GET and POST), bound in its place by FortifyServiceProvider (QA10).
 * Before Fortify reads `login.id`, TwoFactorChallenge decides whether the challenge may go on; if not, the challenge
 * is ended and the browser goes back to the login page with the reason under the login field.
 */
class GuardedTwoFactorLoginRequest extends TwoFactorLoginRequest
{
    protected function prepareForValidation(): void
    {
        $challenge = app(TwoFactorChallenge::class);
        $refusal = $challenge->refusal($this);

        if ($refusal === null) {
            return;
        }

        $challenge->end($this);

        throw new HttpResponseException($this->expectsJson()
            ? response()->json(['message' => $refusal, 'errors' => [Fortify::username() => [$refusal]]], 422)
            : redirect()->route('login')->withErrors([Fortify::username() => $refusal]));
    }
}
