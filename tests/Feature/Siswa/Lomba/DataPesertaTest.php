<?php

declare(strict_types=1);

use App\Actions\Registrations\CreateRegistration;
use App\Enums\MemberRole;
use App\Enums\RegistrationStatus;
use App\Enums\RegistrationType;
use App\Enums\RoleName;
use App\Livewire\Siswa\KonfirmasiTim;
use App\Livewire\Siswa\Pendaftaran\Form as PendaftaranForm;
use App\Models\Registration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Siswa\Lomba\LombaFixtures as F;

/*
 * FS1 Paket B (D-149): the registration form shows "Data peserta" (name, class, NISN, birth date: read-only, from the
 * account, D-03) and asks for the own No HP, required and pre-filled; the anggota checks the own No HP on "Ikut tim".
 * Fictional data only.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::roles();
    Notification::fake();
    $this->guru = F::staff(RoleName::Guru);
    $this->nadia = F::student();
    $this->nadia->forceFill(['phone' => '081211112222', 'birth_date' => '2010-03-14'])->save();
    $this->nadia = $this->nadia->fresh(['user', 'classroom']);
    $this->nadiaUser = F::user($this->nadia);
    $this->rizky = F::student('Rizky Maulana', 'XI-1');
    // Two different birth dates: Undangan tim shows the invitee's own (FS1 QA round 2), never the ketua's.
    $this->rizky->forceFill(['birth_date' => '2010-11-02'])->save();
    $this->rizkyUser = F::user($this->rizky);
});

it('shows the read-only data from the account and the phone field', function (): void {
    $lomba = F::openCompetition();

    Livewire::actingAs($this->nadiaUser)->test(PendaftaranForm::class, ['competitionId' => $lomba->getKey()])
        ->assertSee('Data peserta')->assertSee('Nadia Putri Anindya')->assertSee('XI-4')->assertSee($this->nadia->nisn)
        ->assertSee($this->nadia->birth_date->locale('id')->translatedFormat('j F Y'))->assertSee('Data salah? Hubungi Kesiswaan.')
        ->assertSee('No HP')->assertSee('Contoh 081234567890.')
        ->assertSet('phone', '081211112222');
});

it('starts with an empty phone when the account has none', function (): void {
    Livewire::actingAs($this->rizkyUser)->test(PendaftaranForm::class, ['competitionId' => F::openCompetition()->getKey()])
        ->assertSet('phone', '');
});

it('requires the phone before registering and saves a changed one', function (): void {
    $lomba = F::openCompetition();
    $form = Livewire::actingAs($this->nadiaUser)->test(PendaftaranForm::class, ['competitionId' => $lomba->getKey()])
        ->set('mentorId', $this->guru->getKey())
        ->set('setuju', true);

    $form->set('phone', '')->call('kirim')->assertHasErrors(['phone' => 'Isi No HP.']);
    $form->set('phone', '0812')->call('kirim')->assertHasErrors(['phone' => 'No HP tidak valid (contoh 0812…).']);
    expect(Registration::query()->exists())->toBeFalse();

    $form->set('phone', '0812 9876 5432')->call('kirim')->assertHasNoErrors();

    expect(Registration::query()->sole()->status)->toBe(RegistrationStatus::MenungguKepsek)
        ->and($this->nadia->fresh()->phone)->toBe('081298765432')
        ->and(Activity::query()->where('event', 'student.phone_updated')->count())->toBe(1);
});

it('keeps the phone untouched and unaudited when it did not change', function (): void {
    Livewire::actingAs($this->nadiaUser)->test(PendaftaranForm::class, ['competitionId' => F::openCompetition()->getKey()])
        ->set('mentorId', $this->guru->getKey())->set('setuju', true)->call('kirim')->assertHasNoErrors();

    expect(Registration::query()->exists())->toBeTrue()
        ->and(Activity::query()->where('event', 'student.phone_updated')->exists())->toBeFalse();
});

it('asks the anggota for the own phone on "Ikut tim"', function (): void {
    $lomba = F::teamCompetition(3);
    $r = app(CreateRegistration::class)->handle($this->nadiaUser, $lomba, RegistrationType::Tim, [$this->rizky->getKey()], $this->guru->getKey());

    $page = Livewire::actingAs($this->rizkyUser)->test(KonfirmasiTim::class, ['registration' => $r])
        ->assertSee('No HP')->assertSet('phone', '')
        // Another student's birth date and phone never reach the anggota's page.
        ->assertDontSee($this->nadia->birth_date->locale('id')->translatedFormat('j F Y'))
        ->assertDontSee('081211112222');

    $page->call('ikut')->assertHasErrors(['phone' => 'Isi No HP.']);
    expect($r->members()->where('role', MemberRole::Anggota)->sole()->confirmed_at)->toBeNull();

    $page->set('phone', '081233334444')->call('ikut')->assertHasNoErrors();

    expect($r->members()->where('role', MemberRole::Anggota)->sole()->confirmed_at)->not->toBeNull()
        ->and($this->rizky->fresh()->phone)->toBe('081233334444');
});
