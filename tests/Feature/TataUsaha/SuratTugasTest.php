<?php

declare(strict_types=1);

use App\Actions\Letters\IssueLetters;
use App\Actions\Letters\LetterDraft;
use App\Actions\Letters\SendLetters;
use App\Actions\Registrations\ApproveRegistration;
use App\Enums\FilePurpose;
use App\Enums\LetterType;
use App\Enums\RegistrationStatus;
use App\Enums\RoleName;
use App\Enums\SettingKey;
use App\Livewire\TataUsaha\ArsipSurat;
use App\Livewire\TataUsaha\SuratTugas;
use App\Models\File;
use App\Models\Letter;
use App\Models\Registration;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\Letters\LettersIssued;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Auth\AuthTestHelpers;
use Tests\Feature\KepalaSekolah\W2cFixtures as F;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    AuthTestHelpers::seedRoles();
    $this->tu = F::staff(RoleName::TataUsaha, ['name' => 'Sri Handayani']);
    $this->mentor = F::mentor();
    F::signature();
});

function w2cDraft(int $siswa = 313, ?int $guru = 1681, string $issuedOn = '2026-10-01'): LetterDraft
{
    return new LetterDraft(
        siswaSequence: $siswa,
        guruSequence: $guru,
        issuedOn: CarbonImmutable::parse($issuedOn),
        basisNumber: '017/PAN-NSIF/IX/2026',
        basisDate: CarbonImmutable::parse('2026-09-15'),
        scheduleDay: 'Sabtu, 7 November 2026',
        scheduleTime: '07.00 WIB s.d. selesai',
        place: 'Daring',
    );
}

function w2cTeam(User $mentor, RegistrationStatus $status = RegistrationStatus::Disetujui): Registration
{
    return F::registration($status, ['Raka Pradipta', 'Bima Satria Wicaksono', 'Yoga Pratama'], $mentor);
}

// Access ------------------------------------------------------------------------------------------

it('opens the letter queue for Tata Usaha only', function (): void {
    $registration = w2cTeam($this->mentor);

    $this->actingAs($this->tu)->get('/tu')->assertOk()->assertSee('National Science and Invention Fair (NSIF) 2026');
    $this->actingAs($this->tu)->get('/tu/surat/'.$registration->getKey())->assertOk()->assertSee('Raka Pradipta');
    $this->actingAs($this->tu)->get('/tu/surat/arsip')->assertOk();

    foreach ([RoleName::KepalaSekolah, RoleName::Humas, RoleName::Kesiswaan, RoleName::Admin, RoleName::Guru] as $role) {
        $staff = F::staff($role);
        $this->actingAs($staff)->get('/tu')->assertForbidden();
        $this->actingAs($staff)->get('/tu/surat/arsip')->assertForbidden();
    }

    $this->actingAs(F::activeStudent('Nadia Putri Anindya')->user)->get('/tu')->assertForbidden();
    auth()->logout();
    $this->get('/tu')->assertRedirect(route('login'));
});

it('answers 404 for a registration that is not approved', function (): void {
    $waiting = w2cTeam($this->mentor, RegistrationStatus::MenungguKepsek);

    $this->actingAs($this->tu)->get('/tu/surat/'.$waiting->getKey())->assertNotFound();
});

// IssueLetters ------------------------------------------------------------------------------------

it('issues one team letter and one guru letter as signed PDFs on the private disk', function (): void {
    $registration = w2cTeam($this->mentor);

    $letters = app(IssueLetters::class)->handle($this->tu, $registration, w2cDraft());

    expect($letters)->toHaveCount(2);

    $siswa = Letter::query()->where('type', LetterType::Siswa)->firstOrFail();
    $guru = Letter::query()->where('type', LetterType::Guru)->firstOrFail();

    expect($siswa->full_number)->toBe('400.3.8/313/MRD/X/2026')
        ->and($guru->full_number)->toBe('400.3.8.6/1681/2026')
        ->and($siswa->year)->toBe(2026)
        ->and($siswa->month)->toBe(10)
        ->and($siswa->basis_number)->toBe('017/PAN-NSIF/IX/2026')
        ->and($siswa->generated_by)->toBe($this->tu->getKey())
        ->and($siswa->sent_at)->toBeNull()
        ->and($siswa->verification_code)->toMatch('/^[a-f0-9]{32}$/');

    foreach ([$siswa, $guru] as $letter) {
        $file = File::query()->findOrFail($letter->file_id);
        $bytes = Storage::disk('local')->get($file->path);

        expect($file->purpose)->toBe(FilePurpose::Letter)
            ->and($file->disk)->toBe('local')
            ->and($file->mime)->toBe('application/pdf')
            ->and(str_starts_with($bytes, '%PDF-'))->toBeTrue()
            ->and(hash('sha256', $bytes))->toBe($file->sha256)
            ->and($file->path)->toStartWith('surat/');
    }

    // Registration waits for "Kirim" (TU reviews the PDFs first).
    expect($registration->fresh()->status)->toBe(RegistrationStatus::Disetujui);
    expect(Activity::query()->where('event', 'letters.issued')->exists())->toBeTrue();

    // No render sandbox is left behind (signature copy + QR are deleted after the render).
    expect(glob($this->pdfSandboxRoot.'/*') ?: [])->toBe([]);
});

it('keeps letter numbers unique per type and year', function (): void {
    $first = w2cTeam($this->mentor);
    app(IssueLetters::class)->handle($this->tu, $first, w2cDraft(313, 1681));

    $second = F::registration(RegistrationStatus::Disetujui, ['Hana Safira'], $this->mentor);

    try {
        app(IssueLetters::class)->handle($this->tu, $second, w2cDraft(313, 1682));
        $this->fail('Duplicate number was accepted.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('siswa_sequence');
    }

    // Same sequence in another year is fine (numbers restart per year).
    app(IssueLetters::class)->handle($this->tu, $second, w2cDraft(313, 1681, '2027-01-01'));
    expect(Letter::query()->where('registration_id', $second->getKey())->count())->toBe(2);
    expect(Letter::query()->where('registration_id', $second->getKey())->where('type', LetterType::Siswa)->value('full_number'))
        ->toBe('400.3.8/313/MRD/I/2027');
});

it('refuses registrations that are not approved and letters issued twice', function (): void {
    $waiting = w2cTeam($this->mentor, RegistrationStatus::MenungguKepsek);

    expect(fn () => app(IssueLetters::class)->handle($this->tu, $waiting, w2cDraft()))->toThrow(ValidationException::class);

    $approved = w2cTeam($this->mentor);
    app(IssueLetters::class)->handle($this->tu, $approved, w2cDraft());

    expect(fn () => app(IssueLetters::class)->handle($this->tu, $approved, w2cDraft(400, 1700)))->toThrow(ValidationException::class);
    expect(Letter::query()->count())->toBe(2);
});

it('lets only Tata Usaha issue letters', function (): void {
    $registration = w2cTeam($this->mentor);

    foreach ([RoleName::KepalaSekolah, RoleName::Kesiswaan, RoleName::Humas] as $role) {
        expect(fn () => app(IssueLetters::class)->handle(F::staff($role), $registration, w2cDraft()))->toThrow(AuthorizationException::class);
    }

    expect(Letter::query()->count())->toBe(0);
});

it('needs the signature image from settings', function (): void {
    Setting::put(SettingKey::SignatureFileId, null);
    $registration = w2cTeam($this->mentor);

    try {
        app(IssueLetters::class)->handle($this->tu, $registration, w2cDraft());
        $this->fail('Letters without signature were issued.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('signature');
    }

    expect(Letter::query()->count())->toBe(0);
});

it('validates the draft fields', function (): void {
    $registration = w2cTeam($this->mentor);
    $draft = new LetterDraft(null, 0, CarbonImmutable::parse('2026-10-01'), str_repeat('x', 151), CarbonImmutable::parse('2026-10-05'), '', '', '');

    try {
        app(IssueLetters::class)->handle($this->tu, $registration, $draft);
        $this->fail('Invalid draft accepted.');
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toEqualCanonicalizing([
            'siswa_sequence', 'guru_sequence', 'basis_number', 'basis_date', 'schedule_day', 'schedule_time', 'place',
        ]);
    }
});

it('issues only the student letter when there is no guru pembimbing', function (): void {
    $registration = F::registration(RegistrationStatus::Disetujui, ['Citra Ayu Lestari']);

    app(IssueLetters::class)->handle($this->tu, $registration, w2cDraft(314, null));

    expect(Letter::query()->pluck('type')->all())->toBe([LetterType::Siswa]);
});

// SendLetters -------------------------------------------------------------------------------------

it('sends the letters: surat_terbit, sent_at, notifications to members and mentor', function (): void {
    Notification::fake();
    $registration = w2cTeam($this->mentor);

    expect(fn () => app(SendLetters::class)->handle($this->tu, $registration))->toThrow(ValidationException::class);

    app(IssueLetters::class)->handle($this->tu, $registration, w2cDraft());
    app(SendLetters::class)->handle($this->tu, $registration);

    expect($registration->fresh()->status)->toBe(RegistrationStatus::SuratTerbit)
        ->and(Letter::query()->whereNull('sent_at')->count())->toBe(0);

    Notification::assertSentTo(ApproveRegistration::memberUsers($registration), LettersIssued::class, fn (LettersIssued $n) => $n->audience === 'siswa');
    Notification::assertSentTo($this->mentor, LettersIssued::class, fn (LettersIssued $n) => $n->audience === 'guru');
    expect(Activity::query()->where('event', 'letters.sent')->exists())->toBeTrue();

    expect(fn () => app(SendLetters::class)->handle($this->tu, $registration->fresh()))->toThrow(ValidationException::class);
});

// Letter policy -----------------------------------------------------------------------------------

it('shows letters to staff with letter.view, and to the team and mentor only after sending', function (): void {
    Notification::fake();
    $registration = w2cTeam($this->mentor);
    app(IssueLetters::class)->handle($this->tu, $registration, w2cDraft());

    $siswaLetter = Letter::query()->where('type', LetterType::Siswa)->firstOrFail();
    $guruLetter = Letter::query()->where('type', LetterType::Guru)->firstOrFail();
    $member = ApproveRegistration::memberUsers($registration)->first();
    $stranger = F::student('Hana Safira')->user;

    expect($member->can('view', $siswaLetter))->toBeFalse()
        ->and($this->mentor->can('view', $guruLetter))->toBeFalse()
        ->and(F::staff(RoleName::KepalaSekolah)->can('view', $siswaLetter))->toBeTrue()
        ->and(F::staff(RoleName::Kesiswaan)->can('view', $guruLetter))->toBeTrue()
        ->and(F::staff(RoleName::Humas)->can('view', $siswaLetter))->toBeFalse();

    app(SendLetters::class)->handle($this->tu, $registration);
    $siswaLetter->refresh();
    $guruLetter->refresh();

    expect($member->can('view', $siswaLetter))->toBeTrue()
        ->and($member->can('view', $guruLetter))->toBeFalse()
        ->and($this->mentor->can('view', $siswaLetter))->toBeTrue()
        ->and($this->mentor->can('view', $guruLetter))->toBeTrue()
        ->and($stranger->can('view', $siswaLetter))->toBeFalse()
        ->and($this->tu->can('update', $siswaLetter))->toBeFalse()
        ->and($this->tu->can('delete', $siswaLetter))->toBeFalse();
});

// Screen ------------------------------------------------------------------------------------------

it('prefills the form from the lomba, checks numbers live and generates + sends from the screen', function (): void {
    Notification::fake();
    $registration = w2cTeam($this->mentor);
    $old = F::registration(RegistrationStatus::Disetujui, ['Hana Safira'], $this->mentor);
    app(IssueLetters::class)->handle($this->tu, $old, w2cDraft(312, 1680, now()->format('Y-m-d')));

    $component = Livewire::actingAs($this->tu)->test(SuratTugas::class, ['registration' => $registration])
        ->assertSet('registrationId', $registration->getKey())
        ->assertSet('pukul', LetterDraft::DEFAULT_TIME)
        ->assertSet('tempat', 'Kota Semarang')
        ->assertSee('Terakhir dipakai: 312.')
        ->set('nomorSiswa', '312')
        ->assertHasErrors(['nomorSiswa'])
        ->set('nomorSiswa', '31x3')
        ->assertSet('nomorSiswa', '313')
        ->assertHasNoErrors(['nomorSiswa'])
        ->set('nomorGuru', '1681')
        ->assertSee('Raka Pradipta')
        ->assertSee('PRATINJAU')
        ->call('setTab', 'guru')
        ->assertSee('SURAT PERINTAH TUGAS')
        ->call('generate')
        ->assertHasNoErrors()
        ->assertDispatched('sth-toast', tone: 'success')
        ->assertSee('2 PDF siap');

    expect(Letter::query()->where('registration_id', $registration->getKey())->count())->toBe(2);

    $component->call('send')->assertSee('Terkirim ke 3 siswa dan 1 guru');
    expect($registration->fresh()->status)->toBe(RegistrationStatus::SuratTerbit);
});

it('downloads an issued letter through the policy and audits it', function (): void {
    $registration = w2cTeam($this->mentor);
    app(IssueLetters::class)->handle($this->tu, $registration, w2cDraft());
    $letter = Letter::query()->where('type', LetterType::Siswa)->firstOrFail();

    Livewire::actingAs($this->tu)->test(SuratTugas::class, ['registration' => $registration])
        ->call('download', $letter->getKey())
        ->assertFileDownloaded($letter->file()->value('original_name'));

    expect(Activity::query()->where('event', 'letter.downloaded')->exists())->toBeTrue();

    Livewire::actingAs($this->tu)->test(ArsipSurat::class)
        ->assertSee('400.3.8/313/MRD/X/2026')
        ->set('q', '1681')
        ->assertSee('400.3.8.6/1681/2026')
        ->assertDontSee('400.3.8/313/MRD/X/2026')
        ->call('download', $letter->getKey())
        ->assertFileDownloaded();
});

// Public verification -----------------------------------------------------------------------------

it('verifies a sent letter publicly without personal data', function (): void {
    Notification::fake();
    $registration = w2cTeam($this->mentor);
    app(IssueLetters::class)->handle($this->tu, $registration, w2cDraft());
    $letter = Letter::query()->where('type', LetterType::Siswa)->firstOrFail();

    // Not sent yet → not valid.
    $this->get('/verifikasi-surat/'.$letter->verification_code)->assertNotFound()->assertSee('Tidak ditemukan');

    app(SendLetters::class)->handle($this->tu, $registration);

    $this->get('/verifikasi-surat/'.$letter->verification_code)
        ->assertOk()
        ->assertSee('Sah')
        ->assertSee('400.3.8/313/MRD/X/2026')
        ->assertSee('National Science and Invention Fair (NSIF) 2026')
        ->assertDontSee('Raka Pradipta')
        ->assertDontSee('Hendra Kusuma')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

    $this->get('/verifikasi-surat/'.str_repeat('0', 32))->assertNotFound();
    $this->get('/verifikasi-surat/tidak-ada')->assertNotFound();
});
