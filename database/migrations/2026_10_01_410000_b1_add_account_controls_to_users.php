<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * B1 — account controls (docs/SECURITY.md §3a):
 * - initial_password_expires_at  K1 activation window (import + N days), K2 reopen (+72 h), K10 temporary password (+72 h)
 * - failed_activation_attempts   K3 cumulative failed logins of a not-yet-activated student account
 * - frozen_at / frozen_reason    K3 (failed_attempts) and K8 "Bukan saya?" (not_me); login refused while set
 * - activated_at                 K9 activation report (set by CompleteActivation)
 *
 * Backfill: activated_at of active accounts from the `account.activated` audit rows (fallback: password_changed_at);
 * pending student accounts that still use the initial password get a fresh 14-day window from now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('initial_password_expires_at')->nullable()->after('must_change_password');
            $table->unsignedSmallInteger('failed_activation_attempts')->default(0)->after('initial_password_expires_at');
            $table->timestamp('frozen_at')->nullable()->after('failed_activation_attempts');
            $table->string('frozen_reason', 32)->nullable()->after('frozen_at');
            $table->timestamp('activated_at')->nullable()->after('frozen_reason');
        });

        $now = now();

        // activated_at: latest `account.activated` audit row per user, else the first password change.
        $fromAudit = DB::table('activity_log')
            ->where('event', 'account.activated')
            ->whereNotNull('subject_id')
            ->orderBy('created_at')
            ->get(['subject_id', 'created_at'])
            ->mapWithKeys(fn (object $row): array => [(string) $row->subject_id => $row->created_at]);

        DB::table('users')
            ->where('status', 'active')
            ->orderBy('id')
            ->chunkById(500, function ($users) use ($fromAudit): void {
                foreach ($users as $user) {
                    $activatedAt = $fromAudit[(string) $user->id] ?? $user->password_changed_at ?? $user->updated_at;

                    DB::table('users')->where('id', $user->id)->update(['activated_at' => $activatedAt]);
                }
            });

        // K1 for accounts imported before this migration: a fresh window from now.
        DB::table('users')
            ->where('status', 'pending_activation')
            ->where('must_change_password', true)
            ->whereIn('id', DB::table('students')->select('user_id'))
            ->update(['initial_password_expires_at' => $now->copy()->addDays(14)]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'initial_password_expires_at',
                'failed_activation_attempts',
                'frozen_at',
                'frozen_reason',
                'activated_at',
            ]);
        });
    }
};
