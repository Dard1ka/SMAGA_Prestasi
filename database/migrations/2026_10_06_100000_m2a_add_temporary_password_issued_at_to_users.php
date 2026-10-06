<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M2-A (QA of 2026-10-05, Q2-02): `users.temporary_password_issued_at` tells the temporary password of an Admin
 * "Reset kata sandi & email" (the Admin saw it on screen) apart from the school password of the import. Both leave
 * `must_change_password` and `initial_password_expires_at` set, so no existing column could tell.
 *
 * - set by App\Actions\Accounts\ResetStudentAccount
 * - cleared by App\Actions\Accounts\SetNewPassword (every password the owner chooses)
 * - read by App\Actions\Accounts\CompleteActivation::mayKeepPassword(): while it is set there is no "Lewati"
 *
 * Backfill: student accounts that were reset before this migration and have not chosen a password since
 * (`must_change_password` still true) get the time of their last `account.reset` audit row. An account that
 * already kept a temporary password is not touched (no forced change is added behind its owner's back).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('temporary_password_issued_at')->nullable()->after('initial_password_expires_at');
        });

        $this->backfill();
    }

    /** Public so a test can run it on prepared rows (tests/Feature/Accounts/AccountAdminActionsTest.php). */
    public function backfill(): void
    {
        // Last reset per account (rows in id order, the newest wins). `account.reset` is only ever written for a
        // user by ResetStudentAccount; `subject_id` is a string column, users.id a number.
        $resets = DB::table('activity_log')
            ->where('event', 'account.reset')
            ->whereNotNull('subject_id')
            ->orderBy('id')
            ->get(['subject_id', 'created_at'])
            ->filter(fn (object $row): bool => ctype_digit((string) $row->subject_id))
            ->mapWithKeys(fn (object $row): array => [(int) $row->subject_id => $row->created_at]);

        foreach ($resets->keys()->chunk(500) as $ids) {
            DB::table('users')
                ->whereIn('id', $ids->all())
                ->where('must_change_password', true)
                ->whereIn('id', DB::table('students')->select('user_id'))
                ->orderBy('id')
                ->pluck('id')
                ->each(function (int|string $id) use ($resets): void {
                    DB::table('users')->where('id', $id)->update(['temporary_password_issued_at' => $resets[(int) $id]]);
                });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('temporary_password_issued_at');
        });
    }
};
