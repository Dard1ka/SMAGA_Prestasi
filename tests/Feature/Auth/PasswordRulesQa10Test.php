<?php

declare(strict_types=1);

use App\Actions\Accounts\SetNewPassword;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Tests\Feature\Auth\AuthTestHelpers as H;

/*
| QA10 item 4: a new Admin / TU / Kesiswaan account has no role until a second Admin approves it (D-61), but it may
| finish the first login meanwhile. Its new password follows the staff rules and the staff hint, never the student
| ones: SetNewPassword asks "is this a student account?", not "does it hold a staff role?". Fictional data only.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    H::seedRoles();

    // The production staff defaults, set by hand (the provider sets its own again when the next test boots the app).
    Password::defaults(fn () => Password::min(12)->max(64)->letters()->mixedCase()->numbers()->symbols());
});

function qa10AuthRolelessAccount(): User
{
    return User::factory()->create([
        'name' => 'Sri Handayani',
        'username' => 'sri.h',
        'email' => 'sri.handayani@example.test',
    ])->fresh();
}

function qa10AuthPasswordPasses(User $user, string $password): bool
{
    return Validator::make(
        ['password' => $password, 'password_confirmation' => $password],
        ['password' => app(SetNewPassword::class)->rules($user)],
    )->passes();
}

it('gives an account without a role yet the staff password rules (QA10)', function (): void {
    $account = qa10AuthRolelessAccount();

    expect(qa10AuthPasswordPasses($account, 'bintang-pagi-77'))->toBeFalse() // no capital, no symbol: staff rule
        ->and(qa10AuthPasswordPasses($account, 'Bintang-Pagi'))->toBeFalse() // no digit
        ->and(qa10AuthPasswordPasses($account, 'Bintang-Pagi-77'))->toBeTrue();
});

it('gives an account without a role yet the staff hint (QA10)', function (): void {
    expect(app(SetNewPassword::class)->hint(qa10AuthRolelessAccount()))
        ->toBe('Minimal 12 karakter, ada huruf besar, huruf kecil, angka, dan simbol. Jangan pakai nama, nama pengguna, email, atau kata yang mudah ditebak.');
});

it('keeps the student rules and the student hint for a student account (QA10)', function (): void {
    $student = H::activeStudent()->user;

    expect(qa10AuthPasswordPasses($student, 'bintang-pagi-77'))->toBeTrue()
        ->and(app(SetNewPassword::class)->hint($student))
        ->toBe('Minimal 10 karakter, ada huruf dan angka. Jangan pakai tanggal lahir, NIS/NISN, nama, atau kata yang mudah ditebak.');
});
