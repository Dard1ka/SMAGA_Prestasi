<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PS1 (D-152, lead 2026-10-15): a student confirms the birth date and the No HP during the first activation
     * ("Data diri", between the code and the new password). `profile_confirmed_at` = the moment of that step, written
     * only by CompleteActivation::confirmProfile() (not mass-assignable) and read by DetermineActivationStep.
     *
     * No backfill: the step is asked only while an account is `pending_activation`, so active students are never
     * sent back to it; a pending student (also one an Admin reset) without a confirmation sees it once.
     */
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->timestamp('profile_confirmed_at')->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn('profile_confirmed_at');
        });
    }
};
