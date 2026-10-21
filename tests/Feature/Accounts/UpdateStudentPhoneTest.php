<?php

declare(strict_types=1);

use App\Actions\Accounts\UpdateStudentPhone;
use App\Domain\Accounts\PhoneNumber;
use App\Enums\RoleName;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Siswa\Lomba\LombaFixtures as F;

/*
 * FS1 Paket B (D-149): a student keeps the own No HP current from the registration form and from "Ikut tim". Only the
 * own record; the audit row never carries the number. Fictional data only.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::roles();
    $this->nadia = F::student();
    $this->nadiaUser = F::user($this->nadia);
    $this->rizky = F::student('Rizky Maulana', 'XI-A');
});

function usPhoneError(Closure $call): string
{
    try {
        $call();
    } catch (ValidationException $e) {
        return (string) ($e->errors()['phone'][0] ?? '');
    }

    return '';
}

it('saves the own number and audits without it', function (): void {
    app(UpdateStudentPhone::class)->handle($this->nadiaUser, $this->nadia, '0812 3456 7890');

    expect($this->nadia->fresh()->phone)->toBe('081234567890');
    $row = Activity::query()->where('event', 'student.phone_updated')->sole();
    expect(json_encode($row->properties))->not->toContain('0812')
        ->and(json_encode($row->properties))->not->toContain('34567890')
        ->and($row->causer_id)->toBe($this->nadiaUser->getKey())
        ->and($row->subject_id)->toBe($this->nadia->getKey());
});

it('writes +62 numbers as typed and fixes the usual separators', function (string $typed, string $stored): void {
    app(UpdateStudentPhone::class)->handle($this->nadiaUser, $this->nadia, $typed);

    expect($this->nadia->fresh()->phone)->toBe($stored);
})->with([
    'plus 62' => ['+62 812-3456-7890', '+6281234567890'],
    '62 without plus' => ['6281234567890', '+6281234567890'],
    'dots and brackets' => ['(0812) 3456.7890', '081234567890'],
]);

it('refuses another student, a bad number and an empty one', function (): void {
    expect(fn () => app(UpdateStudentPhone::class)->handle($this->nadiaUser, $this->rizky, '081234567890'))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(UpdateStudentPhone::class)->handle(F::staff(RoleName::Kesiswaan), $this->nadia, '081234567890'))
        ->toThrow(AuthorizationException::class)
        ->and(usPhoneError(fn () => app(UpdateStudentPhone::class)->handle($this->nadiaUser, $this->nadia, '12345')))
        ->toBe('No HP tidak valid (contoh 0812…).')
        ->and(usPhoneError(fn () => app(UpdateStudentPhone::class)->handle($this->nadiaUser, $this->nadia, '   ')))
        ->toBe('Isi No HP.')
        ->and($this->nadia->fresh()->phone)->toBeNull()
        ->and(Activity::query()->where('event', 'student.phone_updated')->exists())->toBeFalse();
});

it('writes nothing for the same number', function (): void {
    app(UpdateStudentPhone::class)->handle($this->nadiaUser, $this->nadia, '081234567890');
    app(UpdateStudentPhone::class)->handle($this->nadiaUser, $this->nadia, '0812-3456-7890');

    expect(Activity::query()->where('event', 'student.phone_updated')->count())->toBe(1);
});

it('shares one rule with the student import', function (): void {
    expect(PhoneNumber::PATTERN)->toBe('/^(\+62|0)8\d{7,12}$/')
        ->and(PhoneNumber::INVALID)->toBe('No HP tidak valid (contoh 0812…).')
        ->and(PhoneNumber::normalize(' 812 3456 7890 '))->toBe('081234567890')
        ->and(PhoneNumber::isValid('081234567890'))->toBeTrue()
        ->and(PhoneNumber::isValid('0712345678'))->toBeFalse();
});
