<?php

declare(strict_types=1);

use App\Actions\Letters\IssueLetters;
use App\Actions\Letters\LetterDraft;
use App\Actions\Letters\RevokeLetter;
use App\Actions\Letters\SendLetters;
use App\Actions\Registrations\ApproveRegistration;
use App\Actions\Registrations\ApproveRevision;
use App\Actions\Registrations\ChangeMentor;
use App\Actions\Registrations\ConfirmMembership;
use App\Actions\Registrations\CreateRegistration;
use App\Actions\Registrations\RequestRevision;
use App\Enums\LetterType;
use App\Enums\RegistrationType;
use App\Enums\RoleName;
use App\Livewire\Guru\BinaanDetail;
use App\Models\Letter;
use App\Models\Registration;
use App\Models\StaffProfile;
use App\Models\User;
use App\Policies\LetterPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\KepalaSekolah\W2cFixtures;
use Tests\Feature\Siswa\Lomba\LombaFixtures as F;

/*
| SV8 (2026-11-09): only the guru letter prints the guru pembimbing (name, NIP, pangkat, jabatan), and a letter is never
| edited. After Kesiswaan changes the guru (ChangeMentor, D-198) or Tata Usaha / Kesiswaan approve a revision that
| changes it (ApproveRevision, D-199), the sent guru letter still names the guru before: the new guru may not open it
| (LetterPolicy::namesCurrentMentor()), no screen links it to him, and the Koreksi of Tata Usaha (RevokeLetter) issues
| the version he sees. The student letter and the staff with `letter.view` are unaffected. Fictional people only.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::roles();
    F::withoutStaffTwoFactor();
    Notification::fake();
    $this->withoutVite();
    W2cFixtures::signature();

    $this->hendra = gl8Guru('Hendra Kusuma, S.Pd., M.Si.', 'Pak', '198001012005011001');
    $this->retno = gl8Guru('Retno Wulandari, S.Pd.', 'Bu', '198502022010012002');
    $this->kepsek = F::staff(RoleName::KepalaSekolah, 'Drs. Bambang Hartono, M.Pd.');
    $this->tu = F::staff(RoleName::TataUsaha, 'Sri Handayani');
    $this->kesiswaan = F::staff(RoleName::Kesiswaan, 'Siti Rahmawati, S.Pd.');
    $this->nadia = F::student();
    $this->dimas = F::student('Dimas Arya Nugroho', 'XI-4');
    $this->lomba = F::teamCompetition(4, ['name' => 'Lomba Karya Tulis Ilmiah Nasional 2026']);
});

const GL8_SISWA = '400.3.8/313/MRD/X/2026';
const GL8_GURU = '800.1.11.1/1681/2026';

function gl8Guru(string $name, string $honorific, string $nip): User
{
    $guru = F::staff(RoleName::Guru, $name);
    StaffProfile::factory()->create([
        'user_id' => $guru->getKey(),
        'honorific' => $honorific,
        'nip' => $nip,
        'rank' => 'Penata, III/c',
        'position' => 'Guru',
    ]);

    return $guru->fresh();
}

function gl8Draft(string $number): LetterDraft
{
    return new LetterDraft(
        number: $number,
        issuedOn: CarbonImmutable::parse('2026-11-04'),
        basisFrom: 'Panitia Lomba Contoh 2026',
        basisNumber: '012/PAN-LC/X/2026',
        basisDate: CarbonImmutable::parse('2026-11-02'),
        scheduleDay: 'Sabtu',
        scheduleDate: '21 November 2026',
        scheduleTime: '07.00 WIB s.d Selesai',
        place: 'Daring',
        basisSubject: 'Permohonan Pendamping',
    );
}

/**
 * Nadia + Dimas guided by Pak Hendra, approved (by default without changing the guru), both letters issued and sent by
 * Tata Usaha (surat_terbit).
 */
function gl8Sent(object $t, ?User $approvalMentor = null): Registration
{
    $registration = app(CreateRegistration::class)->handle(F::user($t->nadia), $t->lomba, RegistrationType::Tim, [(string) $t->dimas->getKey()], $t->hendra->id);
    app(ConfirmMembership::class)->handle(F::user($t->dimas), $registration->fresh());
    app(ApproveRegistration::class)->handle($t->kepsek, $registration->fresh(), null, $approvalMentor?->id);
    app(IssueLetters::class)->handle($t->tu, $registration->fresh(), LetterType::Siswa, gl8Draft(GL8_SISWA));
    app(IssueLetters::class)->handle($t->tu, $registration->fresh(), LetterType::Guru, gl8Draft(GL8_GURU));
    app(SendLetters::class)->handle($t->tu, $registration->fresh());

    return $registration->fresh();
}

function gl8Letter(Registration $registration, LetterType $type): Letter
{
    return Letter::query()->where('registration_id', $registration->getKey())->where('type', $type)->whereNull('revoked_at')->sole();
}

function gl8Koreksi(object $t, Registration $registration): Letter
{
    return app(RevokeLetter::class)->handle($t->tu, gl8Letter($registration, LetterType::Guru), 'Guru pembimbing diganti.', gl8Draft(GL8_GURU));
}

/** What the guru reads on Guru › detail binaan. */
function gl8Binaan(User $guru, Registration $registration): mixed
{
    return Livewire::actingAs($guru)->test(BinaanDetail::class, ['registration' => $registration]);
}

it('keeps both sent letters open to the guru pembimbing while the guru stays', function (): void {
    $registration = gl8Sent($this);
    $guru = gl8Letter($registration, LetterType::Guru);
    $siswa = gl8Letter($registration, LetterType::Siswa);

    expect(LetterPolicy::namesCurrentMentor($guru))->toBeTrue()
        ->and(Gate::forUser($this->hendra)->allows('view', $guru))->toBeTrue()
        ->and(Gate::forUser($this->hendra)->allows('view', $guru->file))->toBeTrue()
        ->and(Gate::forUser($this->hendra)->allows('view', $siswa))->toBeTrue();

    gl8Binaan($this->hendra, $registration)
        ->assertSee('Surat perintah tugas guru')
        ->assertSee(route('files.show', $guru->file_id), false)
        ->assertSee(route('files.show', $siswa->file_id), false)
        ->assertDontSee(BinaanDetail::GURU_LETTER_STALE);
});

it('does not hide a letter issued after the approval replaced the guru', function (): void {
    // The approval's own change (D-132) is logged before any letter exists: the guru letter names Bu Retno.
    $registration = gl8Sent($this, $this->retno);
    $guru = gl8Letter($registration, LetterType::Guru);

    expect((int) $registration->mentor_id)->toBe((int) $this->retno->id)
        ->and(LetterPolicy::namesCurrentMentor($guru))->toBeTrue()
        ->and(Gate::forUser($this->retno)->allows('view', $guru))->toBeTrue();
});

it('withholds the sent guru letter from the new guru after Kesiswaan changed the guru', function (): void {
    $registration = gl8Sent($this);
    $guru = gl8Letter($registration, LetterType::Guru);
    $siswa = gl8Letter($registration, LetterType::Siswa);
    $this->travel(5)->minutes();

    app(ChangeMentor::class)->handle($this->kesiswaan, $registration, $this->retno->id);

    expect(LetterPolicy::namesCurrentMentor($guru->fresh()))->toBeFalse()
        ->and(LetterPolicy::namesCurrentMentor($siswa->fresh()))->toBeTrue()
        // The new guru: the student letter yes, the guru letter (Pak Hendra's NIP, pangkat, jabatan) and its file no.
        ->and(Gate::forUser($this->retno)->allows('view', $siswa->fresh()))->toBeTrue()
        ->and(Gate::forUser($this->retno)->denies('view', $guru->fresh()))->toBeTrue()
        ->and(Gate::forUser($this->retno)->denies('view', $guru->file))->toBeTrue()
        // The guru before is no guru pembimbing of this registration any more: nothing.
        ->and(Gate::forUser($this->hendra)->denies('view', $guru->fresh()))->toBeTrue()
        ->and(Gate::forUser($this->hendra)->denies('view', $siswa->fresh()))->toBeTrue();

    // files.show answers a file that is not yours with 404 (SECURITY §3c).
    $this->actingAs($this->retno)->get(route('files.show', $guru->file_id))->assertNotFound();

    // No link the policy would refuse; one sentence says why the guru letter is missing.
    gl8Binaan($this->retno, $registration->fresh())
        ->assertSee('Surat tugas siswa')
        ->assertSee(route('files.show', $siswa->file_id), false)
        ->assertDontSee(route('files.show', $guru->file_id), false)
        ->assertDontSee(GL8_GURU)
        ->assertSee(BinaanDetail::GURU_LETTER_STALE);
});

it('treats a guru change in the same second as the letter as later', function (): void {
    $this->freezeTime();
    $registration = gl8Sent($this);
    $guru = gl8Letter($registration, LetterType::Guru);

    app(ChangeMentor::class)->handle($this->kesiswaan, $registration, $this->retno->id);

    expect($guru->fresh()->generated_at->toDateTimeString())->toBe(now()->toDateTimeString())
        ->and(LetterPolicy::namesCurrentMentor($guru->fresh()))->toBeFalse()
        ->and(Gate::forUser($this->retno)->denies('view', $guru->fresh()))->toBeTrue();
});

it('withholds the sent guru letter from the new guru after an approved revision changed the guru', function (): void {
    $registration = gl8Sent($this);
    $guru = gl8Letter($registration, LetterType::Guru);
    $this->travel(5)->minutes();
    $revision = app(RequestRevision::class)->handle(F::user($this->nadia), $registration, [], [], $this->retno->id, 'Pak Hendra bertugas ke luar kota.');

    app(ApproveRevision::class)->handle($this->tu, $revision->fresh());

    expect((int) $registration->fresh()->mentor_id)->toBe((int) $this->retno->id)
        ->and(LetterPolicy::namesCurrentMentor($guru->fresh()))->toBeFalse()
        ->and(Gate::forUser($this->retno)->denies('view', $guru->fresh()))->toBeTrue()
        ->and(Gate::forUser($this->retno)->denies('view', $guru->file))->toBeTrue()
        ->and(Gate::forUser($this->hendra)->denies('view', $guru->fresh()))->toBeTrue();

    gl8Binaan($this->retno, $registration->fresh())
        ->assertDontSee(route('files.show', $guru->file_id), false)
        ->assertSee(BinaanDetail::GURU_LETTER_STALE);
});

it('shows the new guru the version Tata Usaha issues with the Koreksi', function (): void {
    $registration = gl8Sent($this);
    $old = gl8Letter($registration, LetterType::Guru);
    $this->travel(5)->minutes();
    app(ChangeMentor::class)->handle($this->kesiswaan, $registration, $this->retno->id);
    $this->travel(5)->minutes();

    $new = gl8Koreksi($this, $registration->fresh());

    expect($new->sent_at)->not->toBeNull()
        ->and(json_encode($new->fresh()->printed))->toContain('198502022010012002')->not->toContain('198001012005011001')
        ->and(LetterPolicy::namesCurrentMentor($new->fresh()))->toBeTrue()
        ->and(Gate::forUser($this->retno)->allows('view', $new->fresh()))->toBeTrue()
        ->and(Gate::forUser($this->retno)->allows('view', $new->file))->toBeTrue()
        // The revoked version stays history for the staff only.
        ->and(Gate::forUser($this->retno)->denies('view', $old->fresh()))->toBeTrue()
        ->and(Gate::forUser($this->hendra)->denies('view', $new->fresh()))->toBeTrue();

    gl8Binaan($this->retno, $registration->fresh())
        ->assertSee('Surat perintah tugas guru')
        ->assertSee(route('files.show', $new->file_id), false)
        ->assertDontSee(route('files.show', $old->file_id), false)
        ->assertDontSee(BinaanDetail::GURU_LETTER_STALE);
});

it('leaves the staff with letter.view and the students\' letter as they were', function (): void {
    $registration = gl8Sent($this);
    $guru = gl8Letter($registration, LetterType::Guru);
    $siswa = gl8Letter($registration, LetterType::Siswa);
    $this->travel(5)->minutes();
    app(ChangeMentor::class)->handle($this->kesiswaan, $registration, $this->retno->id);

    foreach ([$this->tu, $this->kepsek, $this->kesiswaan] as $staff) {
        expect(Gate::forUser($staff)->allows('view', $guru->fresh()))->toBeTrue()
            ->and(Gate::forUser($staff)->allows('view', $guru->file))->toBeTrue()
            ->and(Gate::forUser($staff)->allows('view', $siswa->fresh()))->toBeTrue();
    }

    foreach ([$this->nadia, $this->dimas] as $student) {
        expect(Gate::forUser(F::user($student))->allows('view', $siswa->fresh()))->toBeTrue()
            ->and(Gate::forUser(F::user($student))->denies('view', $guru->fresh()))->toBeTrue();
    }
});

it('lists the guru letter on the student card only while it names the guru the team has', function (): void {
    $registration = gl8Sent($this);
    $siswa = gl8Letter($registration, LetterType::Siswa);

    $this->actingAs(F::user($this->nadia))->get(route('siswa.lomba-saya.show', $registration))
        ->assertOk()
        ->assertSee('Surat perintah tugas untuk guru pendamping sudah terbit')
        ->assertSee('Untuk Pak Hendra Kusuma, S.Pd., M.Si.');

    $this->travel(5)->minutes();
    app(ChangeMentor::class)->handle($this->kesiswaan, $registration, $this->retno->id);

    // "Untuk Bu Retno" would be wrong: the letter still names Pak Hendra. The student letter stays.
    $this->actingAs(F::user($this->nadia))->get(route('siswa.lomba-saya.show', $registration))
        ->assertOk()
        ->assertSee('Surat tugas siswa')
        ->assertSee(route('files.show', $siswa->file_id), false)
        ->assertDontSee('Surat perintah tugas untuk guru pendamping sudah terbit');

    $this->travel(5)->minutes();
    gl8Koreksi($this, $registration->fresh());

    $this->actingAs(F::user($this->nadia))->get(route('siswa.lomba-saya.show', $registration))
        ->assertOk()
        ->assertSee('Surat perintah tugas untuk guru pendamping sudah terbit')
        ->assertSee('Untuk Bu Retno Wulandari, S.Pd.');
});
