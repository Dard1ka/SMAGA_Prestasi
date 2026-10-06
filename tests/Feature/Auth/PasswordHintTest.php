<?php

declare(strict_types=1);

use App\Actions\Accounts\SetNewPassword;
use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Tests\Feature\Auth\AuthTestHelpers as H;

/*
| M2-A (QA 2026-10-05, Q2-05): the line under a "new password" field says what really applies to the account.
| Staff used to read the student text ("NIS/NISN"); now SetNewPassword::hint() follows SetNewPassword::rules().
| Fictional data only.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    H::seedRoles();
});

const M2A_STUDENT_HINT = 'Minimal 10 karakter, ada huruf dan angka. Jangan pakai tanggal lahir, NIS/NISN, nama, atau kata yang mudah ditebak.';

it('gives a student, and a guest whose account is not known, the student text', function (): void {
    $hint = app(SetNewPassword::class);

    expect($hint->hint(H::pendingStudent()->user))->toBe(M2A_STUDENT_HINT)
        ->and($hint->hint())->toBe(M2A_STUDENT_HINT);
});

it('gives staff their own identifiers and the staff rules of the environment', function (): void {
    $staff = H::staff(RoleName::Guru, ['name' => 'Dwi Rahayu', 'username' => 'dwi.rahayu']);
    $hint = app(SetNewPassword::class);

    // Outside production (AppServiceProvider): length only, plus the letters-and-digits rule of PasswordPolicy.
    expect($hint->hint($staff))
        ->toBe('Minimal 10 karakter, ada huruf dan angka. Jangan pakai nama, nama pengguna, email, atau kata yang mudah ditebak.');

    // The production defaults, set by hand (the provider sets its own again when the next test boots the app).
    Password::defaults(fn () => Password::min(12)->max(64)->letters()->mixedCase()->numbers()->symbols());

    expect($hint->hint($staff))
        ->toBe('Minimal 12 karakter, ada huruf besar, huruf kecil, angka, dan simbol. Jangan pakai nama, nama pengguna, email, atau kata yang mudah ditebak.');

    // The text and the rules agree: what the hint asks for is what the validator wants.
    $check = fn (string $password): bool => Validator::make(
        ['password' => $password, 'password_confirmation' => $password],
        ['password' => $hint->rules($staff)],
    )->passes();

    expect($check('Bintang-Pagi-77'))->toBeTrue()
        ->and($check('bintang-pagi-77'))->toBeFalse() // no capital letter
        ->and($check('BintangPagi77'))->toBeFalse() // no symbol
        ->and($check('Bintang-77'))->toBeFalse(); // shorter than 12
});

it('does not tell an account without a student record about NIS/NISN, whatever its role', function (): void {
    // A new privileged staff account has no role until a second Admin approves it (D-61): it follows the
    // general length rule, but it has no NIS either.
    $withoutRole = User::factory()->create(['username' => 'sri.h'])->fresh();

    expect(app(SetNewPassword::class)->hint($withoutRole))
        ->toBe('Minimal 10 karakter, ada huruf dan angka. Jangan pakai nama, nama pengguna, email, atau kata yang mudah ditebak.');
});
