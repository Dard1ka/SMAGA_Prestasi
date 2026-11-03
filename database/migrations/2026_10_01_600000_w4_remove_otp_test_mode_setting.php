<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * W4 (backlog #30): OTP test mode is only the env flag STH_OTP_TEST_MODE, effective with APP_ENV=local (D-15,
 * CLAUDE.md §3.7). The never-read `settings.otp_test_mode` row seeded by earlier SettingSeeder runs is removed so
 * nobody mistakes it for a switch. Data only, forward-only (nothing to restore).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->where('key', 'otp_test_mode')->delete();
    }

    public function down(): void
    {
        // Forward-only: the row was never read.
    }
};
