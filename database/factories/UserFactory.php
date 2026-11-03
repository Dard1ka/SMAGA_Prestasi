<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Fictional accounts only (Indonesian Faker). Default = active staff-less account with password "password".
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake('id_ID')->name(),
            'username' => null,
            'email' => fake('id_ID')->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'status' => UserStatus::Active,
            'must_change_password' => false,
            'password_changed_at' => now(),
            'remember_token' => Str::random(10),
        ];
    }

    /** Indicate that the model's email address should be unverified. */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /** Imported student before first login: no email yet, must change the initial password. */
    public function pendingActivation(): static
    {
        return $this->state(fn (array $attributes) => [
            'email' => null,
            'email_verified_at' => null,
            'status' => UserStatus::PendingActivation,
            'must_change_password' => true,
            'password_changed_at' => null,
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes) => ['status' => UserStatus::Archived]);
    }

    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => ['status' => UserStatus::Disabled]);
    }

    /** Assign a role after creating (creates the role on the fly when RoleSeeder has not run). */
    public function withRole(RoleName|string $role): static
    {
        $name = $role instanceof RoleName ? $role->value : $role;

        return $this->afterCreating(function (User $user) use ($name): void {
            $user->assignRole(Role::findOrCreate($name, 'web'));
        });
    }

    /** Staff account with a username and the given role. */
    public function staff(RoleName $role = RoleName::Guru): static
    {
        return $this->state(fn (array $attributes) => [
            'username' => Str::lower(fake('id_ID')->unique()->bothify('staf.????##')),
        ])->withRole($role);
    }
}
