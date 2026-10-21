<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\RoleName;
use App\Models\RoleChangeRequest;
use App\Models\User;
use Tests\Feature\Auth\AuthTestHelpers;

/**
 * Fixtures for tests/Feature/Admin (fictional data only, DESIGN-SPEC-v2 §5). Staff accounts come with a
 * confirmed TOTP so `staff.2fa` lets them through, like a real session after setup.
 */
final class AdminTestHelpers
{
    public static function seedRoles(): void
    {
        AuthTestHelpers::seedRoles();
    }

    public static function staff(RoleName $role, array $attributes = []): User
    {
        $user = User::factory()->staff($role)->create(array_merge([
            'email' => 'staf.'.$role->value.'.'.fake()->unique()->numerify('###').'@example.test',
        ], $attributes));
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        return $user->fresh();
    }

    public static function admin(array $attributes = []): User
    {
        return self::staff(RoleName::Admin, array_merge(['name' => 'Operator Sekolah', 'username' => 'operator'], $attributes));
    }

    /**
     * A role request as RequestRoleChange::open() wrote it before 21 Okt 2026 (no code writes one any more): approval
     * mode by default, `$delay` = the single-Admin mode that applies by itself after 24 h. Fictional data only.
     */
    public static function legacyRoleRequest(User $requester, User $target, RoleName $role, string $reason = 'Permintaan lama.', bool $delay = false): RoleChangeRequest
    {
        $request = new RoleChangeRequest;
        $request->forceFill([
            'user_id' => $target->getKey(),
            'from_roles' => $target->getRoleNames()->values()->all(),
            'to_role' => $role,
            'requested_by' => $requester->getKey(),
            'reason' => $reason,
            'effective_at' => $delay ? now()->addHours(24) : null,
        ])->save();

        return $request->fresh();
    }

    /** Session value Fortify writes after /user/confirm-password (valid for both the route and the 15 min check). */
    public static function passwordConfirmed(): array
    {
        return ['auth.password_confirmed_at' => time()];
    }
}
