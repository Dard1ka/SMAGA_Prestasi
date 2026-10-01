<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Permission as PermissionName;
use App\Enums\RoleName;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The 7 roles and `<area>.<action>` permissions (ARCHITECTURE §2). Idempotent: safe in production.
 * Matrix: App\Enums\RoleName::defaultPermissions().
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        // Clear explicitly: DatabaseSeeder runs WithoutModelEvents, which also silences spatie's
        // cache-busting model events, so a stale (empty) permission cache would otherwise be used.
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        foreach (PermissionName::cases() as $permission) {
            Permission::findOrCreate($permission->value, 'web');
            $registrar->forgetCachedPermissions();
        }

        foreach (RoleName::cases() as $roleName) {
            Role::findOrCreate($roleName->value, 'web')->syncPermissions(
                array_map(fn (PermissionName $p) => $p->value, $roleName->defaultPermissions()),
            );
        }

        $registrar->forgetCachedPermissions();
    }
}
