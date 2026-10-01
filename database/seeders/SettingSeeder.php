<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\SettingKey;
use App\Models\Setting;
use Illuminate\Database\Seeder;

/**
 * Settings defaults (report_deadline_days = 45 per D-25, archive 18 months, letter formats, signer
 * placeholders, activation window 14 days, empty letter template = built-in defaults). Only missing keys are created,
 * so Admin edits survive re-seeding. OTP test mode is an env flag (STH_OTP_TEST_MODE), never a setting.
 * The initial-password pattern is deliberately not a setting (D-24).
 */
class SettingSeeder extends Seeder
{
    public function run(): void
    {
        foreach (SettingKey::cases() as $key) {
            Setting::query()->firstOrCreate(['key' => $key->value], ['value' => $key->defaultValue()]);
        }
    }
}
