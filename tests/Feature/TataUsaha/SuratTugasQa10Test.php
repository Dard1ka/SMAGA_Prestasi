<?php

declare(strict_types=1);

use App\Actions\Letters\IssueLetters;
use App\Actions\Letters\LetterDraft;
use App\Actions\Letters\SendLetters;
use App\Enums\LetterType;
use App\Enums\RegistrationStatus;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Livewire\TataUsaha\SuratTugas;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Auth\AuthTestHelpers;
use Tests\Feature\KepalaSekolah\W2cFixtures as F;

/*
 * QA10 (D-109): "Surat terkirim ke siswa dan guru pembimbing." although SendLetters skips a guru pembimbing (or a
 * student) who cannot log in. SendLetters now says who is told and the toast names only them. Fictional data only.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    AuthTestHelpers::seedRoles();
    Notification::fake();
    $this->tu = F::staff(RoleName::TataUsaha, ['name' => 'Sri Handayani']);
    F::signature();
    $this->mentor = F::mentor();
    $this->registration = F::registration(RegistrationStatus::Disetujui, ['Raka Pradipta', 'Bima Satria Wicaksono'], $this->mentor);

    foreach (['siswa' => '400.3.8/313/MRD/X/2026', 'guru' => '400.3.8.6/1681/2026'] as $type => $number) {
        app(IssueLetters::class)->handle($this->tu, $this->registration, LetterType::from($type), qa10RegSuratDraft($number));
    }
});

function qa10RegSuratDraft(string $number): LetterDraft
{
    return new LetterDraft(
        number: $number,
        issuedOn: CarbonImmutable::parse('2026-10-01'),
        basisFrom: 'Panitia NSIF 2026',
        basisNumber: '017/PAN-NSIF/IX/2026',
        basisDate: CarbonImmutable::parse('2026-09-15'),
        scheduleDay: 'Sabtu',
        scheduleDate: '7 November 2026',
        scheduleTime: '07.00 WIB s.d Selesai',
        place: 'Daring',
    );
}

it('names in the send toast only who was told (QA10)', function (bool $mentorActive, bool $studentsActive, string $toast): void {
    if (! $mentorActive) {
        $this->mentor->forceFill(['status' => UserStatus::Disabled])->save();
    }

    if (! $studentsActive) {
        User::query()->whereHas('student.memberships', fn ($m) => $m->where('registration_id', $this->registration->getKey()))
            ->update(['status' => UserStatus::Disabled]);
    }

    expect(SendLetters::notifiesMentor($this->registration))->toBe($mentorActive)
        ->and(SendLetters::notifiesStudents($this->registration))->toBe($studentsActive);

    Livewire::actingAs($this->tu)->test(SuratTugas::class, ['registration' => $this->registration])
        ->call('send')
        ->assertHasNoErrors()
        ->assertDispatched('sth-toast', message: $toast, tone: 'success');

    expect($this->registration->fresh()->status)->toBe(RegistrationStatus::SuratTerbit);
})->with([
    'everyone' => [true, true, 'Surat terkirim ke siswa dan guru pembimbing.'],
    'guru disabled' => [false, true, 'Surat terkirim ke siswa.'],
    'students disabled' => [true, false, 'Surat terkirim ke guru pembimbing.'],
    'nobody' => [false, false, 'Surat ditandai terkirim. Tidak ada akun aktif yang diberi tahu.'],
]);

it('does not tell a guru pembimbing who is no longer a guru when the letters are sent (QA10)', function (): void {
    // The registration notices already follow this rule (ApproveRegistration::mentorToTell); the letters did not.
    $this->mentor->syncRoles([RoleName::Humas->value]);

    expect(SendLetters::notifiesMentor($this->registration))->toBeFalse();

    Livewire::actingAs($this->tu)->test(SuratTugas::class, ['registration' => $this->registration])
        ->call('send')
        ->assertHasNoErrors();

    Notification::assertNothingSentTo($this->mentor);
});

it('names in the send dialog only who will be told (QA10)', function (bool $mentorActive, int $studentsDisabled, string $see, string $dontSee): void {
    if (! $mentorActive) {
        $this->mentor->forceFill(['status' => UserStatus::Disabled])->save();
    }

    if ($studentsDisabled > 0) {
        User::query()->whereHas('student.memberships', fn ($m) => $m->where('registration_id', $this->registration->getKey()))
            ->orderBy('id')->limit($studentsDisabled)->get()
            ->each(fn (User $u) => $u->forceFill(['status' => UserStatus::Disabled])->save());
    }

    Livewire::actingAs($this->tu)->test(SuratTugas::class, ['registration' => $this->registration])
        ->assertSee($see)
        ->assertDontSee($dontSee);
})->with([
    'everyone' => [true, 0, ' siswa dan 1 guru pembimbing mendapat notifikasi dan bisa men-download suratnya.', 'tidak diberi tahu'],
    'guru disabled' => [false, 0, 'Guru pembimbing tidak diberi tahu.', '1 guru pembimbing mendapat notifikasi'],
    'one student disabled' => [true, 1, '1 siswa tidak diberi tahu karena akunnya tidak aktif.', 'tidak ada akun aktif'],
]);
