<?php

declare(strict_types=1);

use App\Actions\Accounts\AccountControls;
use App\Actions\Accounts\ResetStudentAccount;
use App\Actions\Accounts\UnfreezeAccount;
use App\Actions\Users\ResetStaffAccount;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Admin\AdminTestHelpers as A;
use Tests\Feature\Auth\AuthTestHelpers as H;

uses(RefreshDatabase::class);

/*
 | QA9 (2026-10-09): account Actions behind Admin › Pengguna & peran and Status aktivasi. Fictional data only.
 */

beforeEach(function (): void {
    A::seedRoles();
    config(['session.driver' => 'database']);
    Notification::fake();
    $this->admin = A::admin();
});

/** The messages of a ValidationException thrown by $call, flattened (empty when nothing was thrown). */
function qa9AccountErrors(Closure $call): array
{
    try {
        $call();
    } catch (ValidationException $e) {
        return collect($e->errors())->flatten()->all();
    }

    return [];
}

// Item 2: a staff reset lifts a freeze, like the student reset ----------------------------------------------

it('lifts a freeze when an Admin resets a staff account and says so in the audit row (QA9)', function (): void {
    $guru = A::staff(RoleName::Guru, ['name' => 'Pak Hendra Kusuma']);
    $guru->forceFill([
        'frozen_at' => now(),
        'frozen_reason' => AccountControls::FROZEN_NOT_ME,
        'failed_activation_attempts' => 4,
    ])->save();

    app(ResetStaffAccount::class)->handle($this->admin, $guru->fresh(), 'Sementara-2026!y', 'Bukan saya: akun diamankan.');

    $guru = $guru->fresh();

    expect(AccountControls::isFrozen($guru))->toBeFalse()
        ->and(AccountControls::frozenReason($guru))->toBeNull()
        ->and(AccountControls::failedAttempts($guru))->toBe(0)
        ->and(Activity::query()->where('event', 'account.staff_reset')->sole()->properties['unfrozen'])->toBe(AccountControls::FROZEN_NOT_ME);
});

// Item 3: an account that cannot log in is not reset ----------------------------------------------------------

it('refuses to reset a student account that cannot log in (QA9)', function (UserStatus $status, string $message): void {
    $user = H::activeStudent(['status' => $status])->user->fresh();
    $hash = $user->password;

    expect(qa9AccountErrors(fn () => app(ResetStudentAccount::class)->handle($this->admin, $user, 'Lupa kata sandi.')))->toBe([$message])
        ->and($user->fresh()->password)->toBe($hash)
        ->and($user->fresh()->status)->toBe($status)
        ->and(Activity::query()->where('event', 'account.reset')->exists())->toBeFalse();
})->with([
    'nonaktif' => [UserStatus::Disabled, 'Akun nonaktif tidak bisa login, jadi tidak bisa direset.'],
    'alumni' => [UserStatus::Archived, 'Akun alumni tidak bisa login, jadi tidak bisa direset. Pulihkan dulu di Arsip otomatis bila perlu.'],
]);

it('refuses to reset a staff account that cannot log in (QA9)', function (UserStatus $status, string $message): void {
    $guru = A::staff(RoleName::Guru, ['status' => $status]);
    $hash = $guru->password;

    expect(qa9AccountErrors(fn () => app(ResetStaffAccount::class)->handle($this->admin, $guru, 'Sementara-2026!y', 'Lupa kata sandi.')))->toBe([$message])
        ->and($guru->fresh()->password)->toBe($hash)
        ->and($guru->fresh()->status)->toBe($status)
        ->and(Activity::query()->where('event', 'account.staff_reset')->exists())->toBeFalse();
})->with([
    'nonaktif' => [UserStatus::Disabled, 'Akun nonaktif tidak bisa login, jadi tidak bisa direset.'],
    'diarsipkan' => [UserStatus::Archived, 'Akun yang diarsipkan tidak bisa login, jadi tidak bisa direset.'],
]);

it('still resets a pending or active account (QA9)', function (): void {
    $pending = H::pendingStudent()->user->fresh();
    $guru = A::staff(RoleName::Guru);

    expect(app(ResetStudentAccount::class)->handle($this->admin, $pending, 'Lupa kata sandi.'))->toHaveLength(12);
    app(ResetStaffAccount::class)->handle($this->admin, $guru, 'Sementara-2026!y', 'Lupa kata sandi.');

    expect($pending->fresh()->status)->toBe(UserStatus::PendingActivation)
        ->and($guru->fresh()->status)->toBe(UserStatus::PendingActivation);
});

// Item 10: "Buka pembekuan" never shortens the activation deadline ------------------------------------------

it('keeps a later activation deadline when it lifts a freeze (QA9)', function (): void {
    $this->travelTo('2026-10-09 10:00:00');
    $user = H::pendingStudent()->user;
    $later = now()->addDays(10)->toImmutable();
    $user->forceFill([
        'initial_password_expires_at' => $later,
        'frozen_at' => now(),
        'frozen_reason' => AccountControls::FROZEN_FAILED_ATTEMPTS,
        'failed_activation_attempts' => AccountControls::MAX_FAILED_ATTEMPTS,
    ])->save();

    app(UnfreezeAccount::class)->handle($this->admin, User::query()->findOrFail($user->getKey()), 'Identitas dicek di TU.');

    $user = $user->fresh();

    expect(AccountControls::isFrozen($user))->toBeFalse()
        ->and(AccountControls::date($user, 'initial_password_expires_at')?->toDateTimeString())->toBe($later->toDateTimeString());
});

it('gives 72 hours from now when the activation deadline has passed (QA9)', function (): void {
    $this->travelTo('2026-10-09 10:00:00');
    $user = H::pendingStudent()->user;
    $user->forceFill([
        'initial_password_expires_at' => now()->subDay(),
        'frozen_at' => now(),
        'frozen_reason' => AccountControls::FROZEN_FAILED_ATTEMPTS,
    ])->save();

    app(UnfreezeAccount::class)->handle($this->admin, User::query()->findOrFail($user->getKey()), 'Identitas dicek di TU.');

    expect(AccountControls::date($user->fresh(), 'initial_password_expires_at')?->toDateTimeString())
        ->toBe(now()->addHours(AccountControls::REOPEN_HOURS)->toDateTimeString());
});
