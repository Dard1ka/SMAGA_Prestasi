<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\RoleName;
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

    /** Session value Fortify writes after /user/confirm-password (valid for both the route and the 15 min check). */
    public static function passwordConfirmed(): array
    {
        return ['auth.password_confirmed_at' => time()];
    }
}
