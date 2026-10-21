<?php

declare(strict_types=1);

namespace App\Actions\Reports;

use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Who verifies a laporan hasil and is therefore told when one arrives (D-139): the accounts that can log in and hold
 * Kesiswaan or Humas, each once (an account with both roles is one recipient). Admin may verify too but is not mailed.
 */
final class ReportVerifiers
{
    public const ROLES = [RoleName::Kesiswaan, RoleName::Humas];

    /** @return Collection<int, User> */
    public static function recipients(): Collection
    {
        return User::query()
            ->canLogIn()
            ->whereHas('roles', fn (Builder $q) => $q->whereIn('name', array_map(fn (RoleName $r): string => $r->value, self::ROLES)))
            ->get();
    }
}
