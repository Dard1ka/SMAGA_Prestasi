<?php

declare(strict_types=1);

use App\Actions\Letters\IssueLetters;
use App\Actions\Letters\LetterDraft;
use App\Actions\Letters\SendLetters;
use App\Actions\Registrations\ApproveRegistration;
use App\Actions\Templates\LetterTemplate;
use App\Actions\Templates\UpdateLetterTemplate;
use App\Domain\Letters\LetterNumber;
use App\Enums\FilePurpose;
use App\Enums\LetterType;
use App\Enums\RegistrationStatus;
use App\Enums\RoleName;
use App\Enums\SettingKey;
use App\Livewire\TataUsaha\ArsipSurat;
use App\Livewire\TataUsaha\SuratTugas;
use App\Models\Classroom;
use App\Models\File;
use App\Models\Letter;
use App\Models\Registration;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use App\Notifications\Letters\LettersIssued;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Auth\AuthTestHelpers;
use Tests\Feature\KepalaSekolah\W2cFixtures as F;

/*
 * Tata Usaha · Surat tugas. M1-A (lead, 2026-10-04): the complete number is typed per letter, each letter is
 * issued on its own with its own data, "Kirim" needs every required letter, failed submits list what is wrong
 * and jump there, the sidebar badge follows the queue. Fictional data only.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    AuthTestHelpers::seedRoles();
    $this->tu = F::staff(RoleName::TataUsaha, ['name' => 'Sri Handayani']);
    $this->mentor = F::mentor();
    F::signature();
});

const W2C_SISWA = '400.3.8/313/MRD/X/2026';
const W2C_GURU = '400.3.8.6/1681/2026';

/** One letter's draft: the number is the only thing most tests vary. */
function w2cDraft(string $number = W2C_SISWA, string $issuedOn = '2026-10-01', string $place = 'Daring'): LetterDraft
{
    return new LetterDraft(
        number: $number,
        issuedOn: CarbonImmutable::parse($issuedOn),
        basisFrom: 'Panitia NSIF 2026',
        basisNumber: '017/PAN-NSIF/IX/2026',
        basisDate: CarbonImmutable::parse('2026-09-15'),
        scheduleDay: 'Sabtu',
        scheduleDate: '7 November 2026',
        scheduleTime: '07.00 WIB s.d Selesai',
        place: $place,
    );
}

function w2cTeam(User $mentor, RegistrationStatus $status = RegistrationStatus::Disetujui): Registration
{
    return F::registration($status, ['Raka Pradipta', 'Bima Satria Wicaksono', 'Yoga Pratama'], $mentor);
}

/** Both letters of a registration (the guru letter only when it has a guru pembimbing). */
function w2cIssueBoth(User $tu, Registration $registration, string $siswa = W2C_SISWA, string $guru = W2C_GURU): void
{
    app(IssueLetters::class)->handle($tu, $registration, LetterType::Siswa, w2cDraft($siswa));

    if ($registration->mentor_id !== null) {
        app(IssueLetters::class)->handle($tu, $registration, LetterType::Guru, w2cDraft($guru));
    }
}

// Access ------------------------------------------------------------------------------------------

it('opens the letter queue for Tata Usaha only', function (): void {
    $registration = w2cTeam($this->mentor);

    $this->actingAs($this->tu)->get('/tu')->assertOk()->assertSee('National Science and Invention Fair (NSIF) 2026');
    $this->actingAs($this->tu)->get('/tu/surat/'.$registration->getKey())->assertOk()->assertSee('RAKA PRADIPTA');
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

it('issues one letter at a time, each with its own number and data, as a signed PDF on the private disk', function (): void {
    $registration = w2cTeam($this->mentor);

    $siswa = app(IssueLetters::class)->handle($this->tu, $registration, LetterType::Siswa, w2cDraft(W2C_SISWA, place: 'Daring'));

    expect(Letter::query()->count())->toBe(1)
        ->and($siswa->type)->toBe(LetterType::Siswa)
        ->and($siswa->full_number)->toBe(W2C_SISWA)
        ->and($siswa->sequence)->toBeNull()
        ->and($siswa->year)->toBe(2026)
        ->and($siswa->month)->toBe(10)
        ->and($siswa->basis_from)->toBe('Panitia NSIF 2026')
        ->and($siswa->basis_number)->toBe('017/PAN-NSIF/IX/2026')
        ->and($siswa->basis_subject)->toBeNull()
        ->and($siswa->schedule_day)->toBe('Sabtu')
        ->and($siswa->schedule_date)->toBe('7 November 2026')
        ->and($siswa->place)->toBe('Daring')
        ->and($siswa->generated_by)->toBe($this->tu->getKey())
        ->and($siswa->sent_at)->toBeNull()
        ->and($siswa->verification_code)->toMatch('/^[a-f0-9]{32}$/');

    // The guru letter is a separate step with its own data (here: another place and date).
    $guru = app(IssueLetters::class)->handle($this->tu, $registration, LetterType::Guru, w2cDraft(W2C_GURU, '2026-10-02', 'Aula Universitas Contoh'));

    expect($guru->full_number)->toBe(W2C_GURU)
        ->and($guru->issued_on->toDateString())->toBe('2026-10-02')
        ->and($guru->place)->toBe('Aula Universitas Contoh')
        ->and($guru->basis_subject)->toBe('Undangan')
        ->and($siswa->fresh()->place)->toBe('Daring');

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
    expect($registration->fresh()->status)->toBe(RegistrationStatus::Disetujui)
        ->and(Activity::query()->where('event', 'letters.issued')->count())->toBe(2);

    // No render sandbox is left behind (signature copy + QR are deleted after the render).
    expect(glob($this->pdfSandboxRoot.'/*') ?: [])->toBe([]);
});

it('stores the number as typed, with tidy spaces', function (): void {
    $registration = F::registration(RegistrationStatus::Disetujui, ['Citra Ayu Lestari']);

    $letter = app(IssueLetters::class)->handle($this->tu, $registration, LetterType::Siswa, w2cDraft("  B-12 /  SMAN3 / 2026\t"));

    expect($letter->full_number)->toBe('B-12 / SMAN3 / 2026');
});

it('rejects a number that is not a letter number', function (string $number): void {
    $registration = w2cTeam($this->mentor);

    try {
        app(IssueLetters::class)->handle($this->tu, $registration, LetterType::Siswa, w2cDraft($number));
        $this->fail('Invalid number accepted.');
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toBe(['number']);
    }

    expect(Letter::query()->count())->toBe(0);
})->with([
    'empty' => [''],
    'too short' => ['12'],
    'too long' => [str_repeat('7', 65)],
    'no digit' => ['MRD/X/abc'],
    'forbidden characters' => ['400.3.8/313<script>'],
]);

it('never uses a number twice for the same letter type, whatever the date, case or spacing', function (): void {
    $first = w2cTeam($this->mentor);
    w2cIssueBoth($this->tu, $first);
    $second = F::registration(RegistrationStatus::Disetujui, ['Hana Safira'], $this->mentor);

    $later = CarbonImmutable::today()->addMonths(2)->format('Y-m-d');

    foreach ([W2C_SISWA, '400.3.8/313/mrd/x/2026', ' 400.3.8 / 313 / MRD / X / 2026 '] as $taken) {
        try {
            // Another letter date does not free the number: the complete number is what counts.
            app(IssueLetters::class)->handle($this->tu, $second, LetterType::Siswa, w2cDraft($taken, $later));
            $this->fail('Duplicate number was accepted: '.$taken);
        } catch (ValidationException $e) {
            expect($e->errors())->toHaveKey('number')
                ->and($e->errors()['number'][0])->toBe(IssueLetters::MSG_TAKEN);
        }
    }

    expect(Letter::query()->where('registration_id', $second->getKey())->count())->toBe(0);

    // The same text is free for the OTHER letter type (separate number books).
    $guru = app(IssueLetters::class)->handle($this->tu, $second, LetterType::Guru, w2cDraft(W2C_SISWA));
    expect($guru->full_number)->toBe(W2C_SISWA)
        ->and(IssueLetters::isTaken(LetterType::Guru, W2C_SISWA))->toBeTrue()
        ->and(IssueLetters::isTaken(LetterType::Siswa, '400.3.8/999/MRD/X/2026'))->toBeFalse();
});

it('has the database as the final guard: unique (type, full_number), old sequences may repeat', function (): void {
    $registration = w2cTeam($this->mentor);
    $other = F::registration(RegistrationStatus::Disetujui, ['Hana Safira'], $this->mentor);

    Letter::factory()->create(['registration_id' => $registration->getKey(), 'sequence' => null, 'full_number' => 'B-1/2026']);
    // Two new rows without a sequence are fine (the old unique (type, year, sequence) index is gone).
    Letter::factory()->create(['registration_id' => $other->getKey(), 'sequence' => null, 'full_number' => 'B-2/2026']);
    // The same number as a guru letter is fine too.
    Letter::factory()->guru()->create(['registration_id' => $registration->getKey(), 'sequence' => null, 'full_number' => 'B-1/2026']);

    expect(fn () => Letter::factory()->create(['registration_id' => $other->getKey(), 'version' => 2, 'sequence' => null, 'full_number' => 'B-1/2026']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('refuses registrations that are not approved and the same letter twice', function (): void {
    $waiting = w2cTeam($this->mentor, RegistrationStatus::MenungguKepsek);

    expect(fn () => app(IssueLetters::class)->handle($this->tu, $waiting, LetterType::Siswa, w2cDraft()))->toThrow(ValidationException::class);

    $approved = w2cTeam($this->mentor);
    app(IssueLetters::class)->handle($this->tu, $approved, LetterType::Siswa, w2cDraft());

    try {
        app(IssueLetters::class)->handle($this->tu, $approved, LetterType::Siswa, w2cDraft('400.3.8/400/MRD/X/2026'));
        $this->fail('The student letter was issued twice.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('registration');
    }

    expect(Letter::query()->count())->toBe(1);
});

it('lets only Tata Usaha issue letters', function (): void {
    $registration = w2cTeam($this->mentor);

    foreach ([RoleName::KepalaSekolah, RoleName::Kesiswaan, RoleName::Humas, RoleName::Guru] as $role) {
        expect(fn () => app(IssueLetters::class)->handle(F::staff($role), $registration, LetterType::Siswa, w2cDraft()))->toThrow(AuthorizationException::class);
    }

    expect(Letter::query()->count())->toBe(0);
});

it('needs the signature image from settings, and says so together with the field errors', function (): void {
    Setting::put(SettingKey::SignatureFileId, null);
    $registration = w2cTeam($this->mentor);

    try {
        app(IssueLetters::class)->handle($this->tu, $registration, LetterType::Siswa, w2cDraft());
        $this->fail('Letter without signature was issued.');
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toBe(['signature']);
    }

    try {
        app(IssueLetters::class)->handle($this->tu, $registration, LetterType::Siswa, w2cDraft(''));
        $this->fail('Letter without signature was issued.');
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toEqualCanonicalizing(['number', 'signature']);
    }

    expect(Letter::query()->count())->toBe(0);
});

it('validates every field of the draft at once', function (): void {
    $registration = w2cTeam($this->mentor);
    $draft = new LetterDraft(
        number: '',
        issuedOn: CarbonImmutable::parse('2026-10-01'),
        basisFrom: ' ',
        basisNumber: str_repeat('x', 151),
        basisDate: CarbonImmutable::parse('2026-10-05'),
        scheduleDay: '',
        scheduleDate: '',
        scheduleTime: '',
        place: '',
        basisSubject: '',
    );

    try {
        app(IssueLetters::class)->handle($this->tu, $registration, LetterType::Guru, $draft);
        $this->fail('Invalid draft accepted.');
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toEqualCanonicalizing([
            'number', 'basis_from', 'basis_number', 'basis_date', 'basis_subject', 'schedule_day', 'schedule_date', 'schedule_time', 'place',
        ]);
    }

    // "Hal" belongs to the guru letter only; a far-away letter date is refused.
    expect(IssueLetters::draftErrors($draft, LetterType::Siswa))->not->toHaveKey('basis_subject')
        ->and(IssueLetters::draftErrors(w2cDraft(issuedOn: '2020-01-01'), LetterType::Siswa))->toHaveKey('issued_on');
});

it('has no guru letter for a registration without a guru pembimbing', function (): void {
    $registration = F::registration(RegistrationStatus::Disetujui, ['Citra Ayu Lestari']);

    try {
        app(IssueLetters::class)->handle($this->tu, $registration, LetterType::Guru, w2cDraft(W2C_GURU));
        $this->fail('A guru letter without a guru pembimbing was issued.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('registration');
    }

    app(IssueLetters::class)->handle($this->tu, $registration, LetterType::Siswa, w2cDraft('400.3.8/314/MRD/X/2026'));

    expect(Letter::query()->pluck('type')->all())->toBe([LetterType::Siswa]);
});

// SendLetters -------------------------------------------------------------------------------------

it('sends only when every required letter exists: surat_terbit, sent_at, notifications to members and mentor', function (): void {
    Notification::fake();
    $registration = w2cTeam($this->mentor);

    expect(fn () => app(SendLetters::class)->handle($this->tu, $registration))->toThrow(ValidationException::class);

    app(IssueLetters::class)->handle($this->tu, $registration, LetterType::Siswa, w2cDraft());

    try {
        app(SendLetters::class)->handle($this->tu, $registration);
        $this->fail('Sent without the guru letter.');
    } catch (ValidationException $e) {
        expect($e->errors()['registration'][0])->toContain('surat guru pendamping');
    }

    expect($registration->fresh()->status)->toBe(RegistrationStatus::Disetujui)
        ->and($this->tu->can('send', [Letter::class, $registration->fresh()]))->toBeFalse();
    Notification::assertNothingSent();

    app(IssueLetters::class)->handle($this->tu, $registration, LetterType::Guru, w2cDraft(W2C_GURU));
    expect($this->tu->can('send', [Letter::class, $registration->fresh()]))->toBeTrue();

    app(SendLetters::class)->handle($this->tu, $registration);

    expect($registration->fresh()->status)->toBe(RegistrationStatus::SuratTerbit)
        ->and(Letter::query()->whereNull('sent_at')->count())->toBe(0);

    Notification::assertSentTo(ApproveRegistration::memberUsers($registration), LettersIssued::class, fn (LettersIssued $n) => $n->audience === 'siswa');
    Notification::assertSentTo($this->mentor, LettersIssued::class, fn (LettersIssued $n) => $n->audience === 'guru');
    expect(Activity::query()->where('event', 'letters.sent')->exists())->toBeTrue();

    expect(fn () => app(SendLetters::class)->handle($this->tu, $registration->fresh()))->toThrow(ValidationException::class);
});

it('sends the student letter alone when there is no guru pembimbing', function (): void {
    Notification::fake();
    $registration = F::registration(RegistrationStatus::Disetujui, ['Citra Ayu Lestari']);
    w2cIssueBoth($this->tu, $registration);

    app(SendLetters::class)->handle($this->tu, $registration);

    expect($registration->fresh()->status)->toBe(RegistrationStatus::SuratTerbit);
});

// Letter policy -----------------------------------------------------------------------------------

it('shows letters to staff with letter.view, and to the team and mentor only after sending', function (): void {
    Notification::fake();
    $registration = w2cTeam($this->mentor);

    expect($this->tu->can('create', [Letter::class, $registration]))->toBeTrue()
        ->and($this->tu->can('create', [Letter::class, $registration, LetterType::Guru]))->toBeTrue()
        ->and(F::staff(RoleName::KepalaSekolah)->can('create', [Letter::class, $registration, LetterType::Siswa]))->toBeFalse();

    w2cIssueBoth($this->tu, $registration);

    $siswaLetter = Letter::query()->where('type', LetterType::Siswa)->firstOrFail();
    $guruLetter = Letter::query()->where('type', LetterType::Guru)->firstOrFail();
    $member = ApproveRegistration::memberUsers($registration)->first();
    $stranger = F::student('Hana Safira')->user;

    expect($member->can('view', $siswaLetter))->toBeFalse()
        ->and($this->mentor->can('view', $guruLetter))->toBeFalse()
        ->and(F::staff(RoleName::KepalaSekolah)->can('view', $siswaLetter))->toBeTrue()
        ->and(F::staff(RoleName::Kesiswaan)->can('view', $guruLetter))->toBeTrue()
        ->and(F::staff(RoleName::Humas)->can('view', $siswaLetter))->toBeFalse()
        // Both letters exist: nothing left to issue.
        ->and($this->tu->can('create', [Letter::class, $registration->fresh()]))->toBeFalse()
        ->and($this->tu->can('create', [Letter::class, $registration->fresh(), LetterType::Siswa]))->toBeFalse();

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

it('gives each letter its own form: prefilled from the lomba, number empty, last number and example shown', function (): void {
    $today = CarbonImmutable::today();
    $last = (new LetterNumber)->format(LetterNumber::TEMPLATE_SISWA, 312, $today);
    $example = (new LetterNumber)->format(LetterNumber::TEMPLATE_SISWA, 313, $today);
    $registration = w2cTeam($this->mentor);
    $old = F::registration(RegistrationStatus::Disetujui, ['Hana Safira'], $this->mentor);
    app(IssueLetters::class)->handle($this->tu, $old, LetterType::Siswa, w2cDraft($last, $today->format('Y-m-d')));

    Livewire::actingAs($this->tu)->test(SuratTugas::class, ['registration' => $registration])
        ->assertSet('registrationId', $registration->getKey())
        ->assertSet('tab', 'siswa')
        ->assertSet('siswa.nomor', '')
        ->assertSet('siswa.pukul', LetterDraft::DEFAULT_TIME)
        ->assertSet('siswa.tempat', 'Kota Semarang')
        ->assertSet('siswa.dasarDari', 'Penyelenggara swasta')
        ->assertSet('guru.nomor', '')
        ->assertSet('guru.dasarHal', 'Undangan')
        ->assertSee('Terakhir dipakai: '.$last.'.')
        ->assertSee('Contoh: '.$example.'.')
        ->assertSee('Ketik nomor lengkap')
        ->assertSee('Buat PDF surat siswa')
        ->assertSee('RAKA PRADIPTA')
        ->assertSee('PREVIEW')
        ->assertSee('nomor surat')
        // a typed number is checked at once
        ->set('siswa.nomor', $last)
        ->assertHasErrors(['siswa.nomor'])
        ->set('siswa.nomor', '31?')
        ->assertHasErrors(['siswa.nomor'])
        ->set('siswa.nomor', '  400.3.8/777/MRD/X/2026 ')
        ->assertHasNoErrors(['siswa.nomor'])
        // the preview shows what was typed (the input value itself is not in the HTML)
        ->assertSee('400.3.8/777/MRD/X/2026')
        // the other letter starts from what TU typed (QA6), then keeps its own data
        ->set('siswa.tempat', 'Gedung A')
        ->call('setTab', 'guru')
        ->assertSee('SURAT PERINTAH TUGAS')
        ->assertSee('Terlampir')
        ->assertSee('Buat PDF surat guru pendamping')
        ->assertSet('guru.tempat', 'Gedung A')
        ->set('guru.tempat', 'Gedung B')
        ->assertSet('siswa.tempat', 'Gedung A');
});

it('explains a failed "Buat PDF": every wrong field marked, a summary, and a jump to it', function (): void {
    $registration = w2cTeam($this->mentor);

    Livewire::actingAs($this->tu)->test(SuratTugas::class, ['registration' => $registration])
        ->set('siswa.hari', '')
        ->set('siswa.tanggalSurat', 'bukan-tanggal')
        ->call('generate')
        ->assertHasErrors(['siswa.nomor', 'siswa.hari', 'siswa.tanggalSurat'])
        ->assertSet('failed', true)
        ->assertDispatched('sth-form-invalid', form: 'surat-form-siswa')
        ->assertDispatched('sth-toast', tone: 'error')
        ->assertSee('PDF belum bisa dibuat')
        ->assertSeeInOrder(['Nomor surat:', LetterNumber::MSG_NUMBER_REQUIRED, 'Tanggal surat:', 'Tanggal surat tidak valid.', 'Hari:'])
        ->assertSee('data-sth-error-summary', false)
        // fixing a field takes it off the list
        ->set('siswa.nomor', W2C_SISWA)
        ->assertHasNoErrors(['siswa.nomor'])
        ->assertDontSee(LetterNumber::MSG_NUMBER_REQUIRED);

    expect(Letter::query()->count())->toBe(0);
});

it('says so when the signature is missing instead of greying the button out', function (): void {
    Setting::put(SettingKey::SignatureFileId, null);
    $registration = w2cTeam($this->mentor);

    $component = Livewire::actingAs($this->tu)->test(SuratTugas::class, ['registration' => $registration])
        ->assertSee('Tanda tangan belum di-upload');

    // No button is rendered disabled: the server explains instead.
    expect($component->html())->not->toMatch('/<button[^>]*\sdisabled[\s>]/');

    $component->set('siswa.nomor', W2C_SISWA)
        ->call('generate')
        ->assertHasErrors(['umum'])
        ->assertDispatched('sth-form-invalid', form: 'surat-form-siswa')
        ->assertSee('Minta Admin upload di Pengaturan');

    expect(Letter::query()->count())->toBe(0);
});

it('makes the two PDFs one by one, refuses "Kirim" until both exist, then sends and updates queue and badge', function (): void {
    Notification::fake();
    $registration = w2cTeam($this->mentor);

    $component = Livewire::actingAs($this->tu)->test(SuratTugas::class, ['registration' => $registration])
        ->set('siswa.nomor', W2C_SISWA)
        ->set('siswa.tempat', 'Gedung A')
        ->call('generate')
        ->assertHasNoErrors()
        ->assertSet('failed', false)
        ->assertDispatched('sth-toast', tone: 'success')
        ->assertDispatched('sth-badges', badges: ['tu.surat' => 1])
        ->assertSee('Download')
        ->assertSee('Cetak')
        ->assertSee('Koreksi')
        ->assertSee('Belum bisa dikirim: buat dulu PDF surat guru pendamping.');

    expect(Letter::query()->where('registration_id', $registration->getKey())->pluck('type')->all())->toBe([LetterType::Siswa]);

    // "Kirim" stays clickable and explains what is missing; the guru tab opens.
    $component->call('send')
        ->assertHasErrors(['kirim'])
        ->assertSet('tab', 'guru')
        ->assertDispatched('sth-form-invalid', form: 'surat-kirim')
        ->assertSee('surat guru pendamping belum dibuat');

    expect($registration->fresh()->status)->toBe(RegistrationStatus::Disetujui);

    $component->set('guru.nomor', W2C_GURU)
        ->set('guru.tempat', 'Gedung B')
        ->call('generate')
        ->assertHasNoErrors()
        ->assertSee('PDF siap');

    $letters = Letter::query()->where('registration_id', $registration->getKey())->get()->keyBy(fn (Letter $l) => $l->type->value);
    expect($letters['siswa']->place)->toBe('Gedung A')
        ->and($letters['guru']->place)->toBe('Gedung B')
        ->and($component->viewData('queue'))->toHaveCount(1);

    $component->call('send')
        ->assertHasNoErrors()
        ->assertDispatched('sth-badges', badges: ['tu.surat' => 0])
        ->assertSee('Terkirim ke 3 siswa dan 1 guru');

    expect($registration->fresh()->status)->toBe(RegistrationStatus::SuratTerbit)
        // the queue cards at the top lose the registration in the same request
        ->and($component->viewData('queue'))->toHaveCount(0);
});

it('answers 404 for a guru letter action on a registration without a guru pembimbing', function (): void {
    $registration = F::registration(RegistrationStatus::Disetujui, ['Citra Ayu Lestari']);

    Livewire::actingAs($this->tu)->test(SuratTugas::class, ['registration' => $registration])
        ->assertDontSee('Surat guru pendamping')
        ->call('setTab', 'guru')
        ->assertSet('tab', 'siswa')
        ->set('tab', 'guru')
        ->call('generate')
        ->assertNotFound();
});

it('opens a registration whose letters were issued before M1-A (sequence, "Hari, tanggal" in one column)', function (): void {
    $registration = F::registration(RegistrationStatus::SuratTerbit, ['Hana Safira'], $this->mentor);
    $file = File::factory()->purpose(FilePurpose::Letter)->create();

    foreach ([LetterType::Siswa, LetterType::Guru] as $type) {
        Letter::factory()->create([
            'registration_id' => $registration->getKey(),
            'type' => $type,
            'sequence' => 312,
            'issued_on' => '2026-09-08',
            'schedule_day' => 'Sabtu, 7 November 2026',
            'schedule_time' => '07.00 WIB s.d. selesai',
            'place' => 'Daring',
            'file_id' => $file->getKey(),
            'sent_at' => now(),
        ]);
    }

    Livewire::actingAs($this->tu)->test(SuratTugas::class, ['registration' => $registration])
        ->assertSet('siswa.nomor', '400.3.8/312/MRD/IX/2026')
        ->assertSet('siswa.hari', 'Sabtu')
        ->assertSet('siswa.tanggal', '7 November 2026')
        ->assertSet('guru.nomor', '400.3.8.6/312/2026')
        ->assertSee('Terkirim ke 1 siswa dan 1 guru')
        ->assertSee('Koreksi')
        ->assertDontSee('Buat PDF surat siswa');
});

it('downloads an issued letter through the policy and audits it', function (): void {
    $registration = w2cTeam($this->mentor);
    w2cIssueBoth($this->tu, $registration);
    $letter = Letter::query()->where('type', LetterType::Siswa)->firstOrFail();

    Livewire::actingAs($this->tu)->test(SuratTugas::class, ['registration' => $registration])
        ->call('download', $letter->getKey())
        ->assertFileDownloaded($letter->file()->value('original_name'));

    expect(Activity::query()->where('event', 'letter.downloaded')->exists())->toBeTrue();

    Livewire::actingAs($this->tu)->test(ArsipSurat::class)
        ->assertSee(W2C_SISWA)
        ->assertSee('Cetak')
        ->set('q', '1681')
        ->assertSee(W2C_GURU)
        ->assertDontSee(W2C_SISWA)
        ->call('download', $letter->getKey())
        ->assertFileDownloaded();
});

// Cetak (inline PDF) ------------------------------------------------------------------------------

it('opens a letter inline for printing, audited, never cached', function (): void {
    $registration = w2cTeam($this->mentor);
    w2cIssueBoth($this->tu, $registration);
    $letter = Letter::query()->where('type', LetterType::Guru)->firstOrFail();

    $response = $this->actingAs($this->tu)->get(route('tu.surat.cetak', $letter));

    $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($response->headers->get('Content-Disposition'))->toStartWith('inline;')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')
        ->and($response->headers->get('Content-Security-Policy'))->toBe("frame-ancestors 'none'")
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->streamedContent())->toStartWith('%PDF-');

    $audit = Activity::query()->where('event', 'letter.downloaded')->sole();
    expect($audit->causer_id)->toBe($this->tu->getKey())
        ->and($audit->properties['number'])->toBe(W2C_GURU)
        ->and($audit->properties['inline'])->toBeTrue();
});

it('keeps the print route and the letterhead emblems inside the Tata Usaha area', function (): void {
    $registration = w2cTeam($this->mentor);
    w2cIssueBoth($this->tu, $registration);
    $letter = Letter::query()->where('type', LetterType::Siswa)->firstOrFail();

    foreach ([RoleName::KepalaSekolah, RoleName::Kesiswaan, RoleName::Humas, RoleName::Admin, RoleName::Guru] as $role) {
        $staff = F::staff($role);
        $this->actingAs($staff)->get(route('tu.surat.cetak', $letter))->assertForbidden();
        $this->actingAs($staff)->get(route('tu.surat.kop', ['logo' => 'pemprov']))->assertForbidden();
    }

    $this->actingAs(F::activeStudent('Nadia Putri Anindya')->user)->get(route('tu.surat.cetak', $letter))->assertForbidden();
    expect(Activity::query()->where('event', 'letter.downloaded')->exists())->toBeFalse();

    $this->actingAs($this->tu)->get('/tu/surat/cetak/'.strtolower((string) Str::ulid()))->assertNotFound();
    $this->actingAs($this->tu)->get(route('tu.surat.kop', ['logo' => 'pemprov']))->assertOk()->assertHeader('Content-Type', 'image/png');
    $this->actingAs($this->tu)->get(route('tu.surat.kop', ['logo' => 'sekolah']))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    $this->actingAs($this->tu)->get('/tu/surat/kop/lain')->assertNotFound();

    auth()->logout();
    $this->get(route('tu.surat.cetak', $letter))->assertRedirect(route('login'));
});

// Public verification -----------------------------------------------------------------------------

it('verifies an issued letter publicly without personal data, before and after "Kirim"', function (): void {
    Notification::fake();
    $registration = w2cTeam($this->mentor);
    w2cIssueBoth($this->tu, $registration);
    $letter = Letter::query()->where('type', LetterType::Siswa)->firstOrFail();

    // QA6 (D-113): issued = signed and numbered, so a letter TU printed before "Kirim" is valid too.
    $this->get('/verifikasi-surat/'.$letter->verification_code)->assertOk()->assertSee('Sah')->assertSee(W2C_SISWA);

    app(SendLetters::class)->handle($this->tu, $registration);

    $this->get('/verifikasi-surat/'.$letter->verification_code)
        ->assertOk()
        ->assertSee('Sah')
        ->assertSee(W2C_SISWA)
        ->assertSee('National Science and Invention Fair (NSIF) 2026')
        ->assertDontSee('Raka Pradipta')
        ->assertDontSee('RAKA PRADIPTA')
        ->assertDontSee('Hendra Kusuma')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

    $this->get('/verifikasi-surat/'.str_repeat('0', 32))->assertNotFound();
    $this->get('/verifikasi-surat/tidak-ada')->assertNotFound();
});

// QA retest 2026-10-06 ------------------------------------------------------------------------------

it('shows an issued letter the way its PDF printed it, after the template, the signer and a class changed', function (): void {
    Notification::fake();
    Setting::put(SettingKey::SignerName, 'Drs. Bambang Hartono, M.Pd.');
    $registration = w2cTeam($this->mentor);
    app(IssueLetters::class)->handle($this->tu, $registration, LetterType::Siswa, w2cDraft());

    // Everything the preview used to read live changes after the PDF was made.
    app(UpdateLetterTemplate::class)->handle($this->tu, array_merge(LetterTemplate::defaults(), [
        'kop_5' => 'Kode Pos 50999, Telepon 0000000',
    ]));
    Setting::put(SettingKey::SignerName, 'Plt. Kepala Sekolah Baru, S.Pd.');
    // The yearly import moves the whole team (they were all in XI-4 when the letter was made).
    $xii9 = Classroom::factory()->level(12)->create(['name' => 'XII-9']);
    Student::query()->update(['classroom_id' => $xii9->getKey()]);

    Livewire::actingAs($this->tu)->test(SuratTugas::class, ['registration' => $registration])
        ->call('setTab', 'siswa')
        ->assertSee('Isi PDF yang sudah dibuat')
        ->assertSee('Kode Pos 50132, Telepon 3544287, Faksimile 024-3544291')
        ->assertDontSee('Kode Pos 50999')
        ->assertSee('Drs. Bambang Hartono, M.Pd.')
        ->assertDontSee('Plt. Kepala Sekolah Baru')
        ->assertSee('XI - 4')
        ->assertDontSee('XII - 9');
});

it('does not claim to show the PDF for a letter issued before STH kept what it printed', function (): void {
    $registration = F::registration(RegistrationStatus::Disetujui, ['Hana Safira'], null);
    $file = File::factory()->purpose(FilePurpose::Letter)->create();
    Letter::factory()->create([
        'registration_id' => $registration->getKey(),
        'type' => LetterType::Siswa,
        'full_number' => '400.3.8/301/MRD/IX/2026',
        'issued_on' => '2026-09-08',
        'file_id' => $file->getKey(),
    ]);

    Livewire::actingAs($this->tu)->test(SuratTugas::class, ['registration' => $registration])
        ->assertDontSee('Isi PDF yang sudah dibuat')
        ->assertSee('Menurut data sekarang, bisa beda dari PDF');
});

it('takes the error toast of a failed "Buat PDF" away once the PDF is made or another letter opens', function (): void {
    $registration = w2cTeam($this->mentor);

    $component = Livewire::actingAs($this->tu)->test(SuratTugas::class, ['registration' => $registration])
        ->call('generate')
        ->assertDispatched('sth-toast', tone: 'error', key: 'surat-tugas')
        ->assertNotDispatched('sth-toast-clear');

    // Another letter's tab: the failure belonged to the letter that was open.
    $component->call('setTab', 'guru')->assertDispatched('sth-toast-clear', key: 'surat-tugas');

    // The same letter made after all.
    $component->call('setTab', 'siswa')
        ->call('generate')
        ->assertDispatched('sth-toast', tone: 'error', key: 'surat-tugas')
        ->set('siswa.nomor', W2C_SISWA)
        ->call('generate')
        ->assertHasNoErrors()
        ->assertDispatched('sth-toast', tone: 'success')
        ->assertDispatched('sth-toast-clear', key: 'surat-tugas');
});

// QA6: explore round 3 after PR 14 (2026-10-07) ----------------------------------------------------

it('carries what TU typed about the invitation into the guru letter that is not made yet (QA6, #61 Q1-15)', function (): void {
    $registration = w2cTeam($this->mentor);

    $component = Livewire::actingAs($this->tu)->test(SuratTugas::class, ['registration' => $registration])
        ->set('siswa.nomor', W2C_SISWA)
        ->set('siswa.dasarNomor', '017/PAN-NSIF/IX/2026')
        ->set('siswa.dasarTanggal', '2026-09-15')
        ->set('siswa.tempat', 'Gedung A')
        ->call('setTab', 'guru')
        ->assertSet('guru.dasarNomor', '017/PAN-NSIF/IX/2026')
        ->assertSet('guru.dasarTanggal', '2026-09-15')
        ->assertSet('guru.tempat', 'Gedung A')
        ->assertSet('guru.nomor', '');

    // What TU then types for the guru letter stays its own (D-83: two forms, never one shared draft).
    $component->set('guru.tempat', 'Gedung B')
        ->call('setTab', 'siswa')
        ->call('setTab', 'guru')
        ->assertSet('guru.tempat', 'Gedung B')
        ->assertSet('siswa.tempat', 'Gedung A');
});

it('fills the guru form from the issued student letter when the page is opened again (QA6, #61 Q1-15)', function (): void {
    $registration = w2cTeam($this->mentor);
    app(IssueLetters::class)->handle($this->tu, $registration, LetterType::Siswa, w2cDraft());

    Livewire::actingAs($this->tu)->test(SuratTugas::class, ['registration' => $registration])
        ->assertSet('tab', 'guru')
        ->assertSet('guru.dasarDari', 'Panitia NSIF 2026')
        ->assertSet('guru.dasarNomor', '017/PAN-NSIF/IX/2026')
        ->assertSet('guru.dasarTanggal', '2026-09-15')
        ->assertSet('guru.tanggal', '7 November 2026')
        ->assertSet('guru.nomor', '');
});

it('opens the chosen letter in correction mode from Arsip surat (QA6, #61 Q1-13)', function (): void {
    $registration = w2cTeam($this->mentor);
    w2cIssueBoth($this->tu, $registration);
    $guru = Letter::query()->where('type', LetterType::Guru)->firstOrFail();

    Livewire::actingAs($this->tu)->test(ArsipSurat::class)
        ->assertSeeHtml(route('tu.surat.show', ['registration' => $registration, 'koreksi' => $guru->getKey()]));

    Livewire::withQueryParams(['koreksi' => $guru->getKey()])->actingAs($this->tu)
        ->test(SuratTugas::class, ['registration' => $registration])
        ->assertSet('tab', 'guru')
        ->assertSet('revisingId', $guru->getKey())
        ->assertSet('guru.nomor', '')
        ->call('cancelRevision')
        ->assertSet('koreksi', '')
        ->assertSet('revisingId', null);

    // A letter of another registration, or one that is no longer current, only opens the page.
    Livewire::withQueryParams(['koreksi' => 'bukan-surat'])->actingAs($this->tu)
        ->test(SuratTugas::class, ['registration' => $registration])
        ->assertOk()
        ->assertSet('revisingId', null)
        ->assertSet('koreksi', '');
});

it('asks before "Kirim" once every letter exists (QA6, #65 Q1-10)', function (): void {
    Notification::fake();
    $registration = w2cTeam($this->mentor);
    app(IssueLetters::class)->handle($this->tu, $registration, LetterType::Siswa, w2cDraft());

    // A letter is still missing: the button explains that at once, no question first.
    Livewire::actingAs($this->tu)->test(SuratTugas::class, ['registration' => $registration])
        ->assertDontSeeHtml('data-sth-open="kirim-surat"');

    app(IssueLetters::class)->handle($this->tu, $registration, LetterType::Guru, w2cDraft(W2C_GURU));

    Livewire::actingAs($this->tu)->test(SuratTugas::class, ['registration' => $registration])
        ->assertSeeHtml('data-sth-open="kirim-surat"')
        ->assertSee('Kirim surat sekarang?')
        ->assertSee('3 siswa dan 1 guru pembimbing')
        ->call('send')
        ->assertDispatched('sth-modal-close', id: 'kirim-surat');

    expect($registration->fresh()->status)->toBe(RegistrationStatus::SuratTerbit);
});

it('never refills a field TU changed on the other letter, even back to the lomba value or to empty (QA6 review)', function (): void {
    $registration = w2cTeam($this->mentor);

    Livewire::actingAs($this->tu)->test(SuratTugas::class, ['registration' => $registration])
        ->set('siswa.dasarNomor', '017/PAN-NSIF/IX/2026')
        ->set('siswa.tempat', 'Gedung A')
        ->call('setTab', 'guru')
        ->assertSet('guru.tempat', 'Gedung A')
        ->set('guru.tempat', 'Kota Semarang')   // the lomba value, on purpose
        ->set('guru.dasarNomor', '')            // this invitation has no number for the guru
        ->call('setTab', 'siswa')
        ->assertSet('siswa.tempat', 'Gedung A')
        ->call('setTab', 'guru')
        ->assertSet('guru.tempat', 'Kota Semarang')
        ->assertSet('guru.dasarNomor', '');
});

it('opens a correction only for a current letter of this registration and drops ?koreksi= once it is saved (QA6 review)', function (): void {
    Notification::fake();
    $registration = w2cTeam($this->mentor);
    w2cIssueBoth($this->tu, $registration);
    $other = F::registration(RegistrationStatus::Disetujui, ['Hana Safira']);
    $foreign = app(IssueLetters::class)->handle($this->tu, $other, LetterType::Siswa, w2cDraft('400.3.8/920/MRD/X/2026'));
    $siswa = Letter::query()->where('registration_id', $registration->getKey())->where('type', LetterType::Siswa)->firstOrFail();

    // A letter of another registration only opens the page.
    Livewire::withQueryParams(['koreksi' => $foreign->getKey()])->actingAs($this->tu)
        ->test(SuratTugas::class, ['registration' => $registration])
        ->assertSet('revisingId', null)
        ->assertSet('koreksi', '');

    // The chosen letter: the address keeps it while the correction is open, and loses it once the new version exists.
    Livewire::withQueryParams(['koreksi' => $siswa->getKey()])->actingAs($this->tu)
        ->test(SuratTugas::class, ['registration' => $registration])
        ->assertSet('revisingId', $siswa->getKey())
        ->assertSet('koreksi', $siswa->getKey())
        ->set('alasan', 'Nomor undangan salah ketik.')
        ->set('siswa.nomor', '400.3.8/921/MRD/X/2026')
        ->call('revise')
        ->assertHasNoErrors()
        ->assertSet('revisingId', null)
        ->assertSet('koreksi', '');

    // The old link of the revoked letter only opens the page.
    Livewire::withQueryParams(['koreksi' => $siswa->getKey()])->actingAs($this->tu)
        ->test(SuratTugas::class, ['registration' => $registration])
        ->assertSet('revisingId', null)
        ->assertSet('koreksi', '');
});
