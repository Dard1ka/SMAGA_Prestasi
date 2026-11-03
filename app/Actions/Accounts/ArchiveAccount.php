<?php

declare(strict_types=1);

namespace App\Actions\Accounts;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Admin archives an account by hand (D-30). Archived users cannot log in (D-29); their achievements stay
 * in rankings and public pages. Same checks, audit and session revocation as DisableAccount.
 */
final class ArchiveAccount
{
    public function __construct(private readonly DisableAccount $disable) {}

    /**
     * @throws ValidationException
     */
    public function handle(User $actor, User $target, string $reason): void
    {
        $this->disable->handle($actor, $target, $reason, UserStatus::Archived);
    }
}
