<?php

declare(strict_types=1);

/*
 * E2E only (tests/E2E/compose.e2e.yaml): makes the seeded demo student usable by the Playwright smoke test without
 * ever typing the initial password (CLAUDE.md rule 3). Sets the E2E password, marks the account activated with a
 * fictional email and resets the menu tour so the test sees it once.
 *
 * "Activated" = what the first-login flow leaves behind (email → code → password, lead 2026-10-04): a verified
 * email, no forced password change, status active (App\Actions\Accounts\DetermineActivationStep::finished()).
 *
 * Refuses to run unless APP_ENV=local and STH_E2E=1 (set only on the app service of the isolated E2E project), so it
 * can never touch the dev database or any server.
 *
 *   php tests/E2E/support/prepare-student.php      env: E2E_STUDENT_PASSWORD (required), E2E_STUDENT_NIS (225226201)
 */

use App\Enums\UserStatus;
use App\Models\Student;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Schema;

require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$fail = static function (string $message): never {
    fwrite(STDERR, "[e2e] {$message}\n");
    exit(1);
};

if (! app()->environment('local') || getenv('STH_E2E') !== '1') {
    $fail('refused: only inside the E2E compose project (APP_ENV=local and STH_E2E=1).');
}

$password = (string) getenv('E2E_STUDENT_PASSWORD');
$nis = (string) (getenv('E2E_STUDENT_NIS') ?: '225226201');

if (mb_strlen($password) < 12) {
    $fail('E2E_STUDENT_PASSWORD must be set (at least 12 characters).');
}

$student = Student::query()->with('user')->where('nis', $nis)->first();

if ($student === null || $student->user === null) {
    $fail("demo student {$nis} not found: run the seeders first (DemoSeeder, APP_ENV=local).");
}

$attributes = [
    'password' => $password, // hashed by the User cast
    'email' => 'e2e.'.$nis.'@sth.test',
    'email_verified_at' => now(),
    'status' => UserStatus::Active,
    'must_change_password' => false,
    'password_changed_at' => now(),
    'tour_seen_at' => null,
];

// Account-control columns (backlog #15) exist only after their migration: set them when present.
$controls = ['activated_at' => now(), 'frozen_at' => null, 'frozen_reason' => null, 'failed_activation_attempts' => 0];

foreach ($controls as $column => $value) {
    if (Schema::hasColumn('users', $column)) {
        $attributes[$column] = $value;
    }
}

$student->user->forceFill($attributes)->save();

fwrite(STDOUT, "[e2e] demo student {$nis} prepared\n");
