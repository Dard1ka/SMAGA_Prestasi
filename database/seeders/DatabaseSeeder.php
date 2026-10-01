<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Reference data first (safe everywhere, idempotent), then local-only demo data.
     * SignatureSeeder skips production; DemoSeeder runs only when APP_ENV=local.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            ClassroomSeeder::class,
            TalentSeeder::class,
            WeightSeeder::class,
            SettingSeeder::class,
            SignatureSeeder::class,
        ]);

        if (app()->environment('local')) {
            $this->call(DemoSeeder::class);
        }
    }
}
