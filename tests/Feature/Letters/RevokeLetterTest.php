<?php

declare(strict_types=1);

use App\Actions\Letters\IssueLetters;
use App\Actions\Letters\LetterDraft;
use App\Actions\Letters\RevokeLetter;
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
use App\Notifications\Letters\LetterRevised;
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

/*
 * B3 · backlog #11: letter correction (cabut + new version with a NEW number), signature file id +
 * schedule stored per letter, "Dicabut" on the public verification page. M1-A: the new version's complete
 * number is typed in the draft, the correction form explains itself and lists what is wrong. Fictional data only.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    AuthTestHelpers::seedRoles();
    Notification::fake();
    $this->tu = F::staff(RoleName::TataUsaha, ['name' => 'Sri Handayani']);
    $this->mentor = F::mentor();
    $this->signature = F::signature();
});

const B3_SISWA = '400.3.8/313/MRD/X/2026';
const B3_GURU = '400.3.8.6/1681/2026';

function b3LetterDraft(string $number = B3_SISWA, string $issuedOn = '2026-10-01', string $date = '7 November 2026'): LetterDraft
{
    return new LetterDraft(
        number: $number,
        issuedOn: CarbonImmutable::parse($issuedOn),
        basisFrom: 'Panitia NSIF 2026',
        basisNumber: '017/PAN-NSIF/IX/2026',
        basisDate: CarbonImmutable::parse('2026-09-15'),
        scheduleDay: 'Sabtu',
        scheduleDate: $date,
        scheduleTime: '07.00 WIB s.d Selesai',
        place: 'Daring',
    );
}

/** Team with mentor, both letters issued (and sent unless $send = false). */
function b3Issued(object $t, bool $send = true): Registration
{
    $registration = F::registration(RegistrationStatus::Disetujui, ['Raka Pradipta', 'Bima Satria Wicaksono'], $t->mentor);
    app(IssueLetters::class)->handle($t->tu, $registration, LetterType::Siswa, b3LetterDraft(B3_SISWA));
    app(IssueLetters::class)->handle($t->tu, $registration, LetterType::Guru, b3LetterDraft(B3_GURU));

    if ($send) {
        app(SendLetters::class)->handle($t->tu, $registration);
    }

    return $registration->fresh();
}

function b3Letter(LetterType $type = LetterType::Siswa): Letter
{
    return Letter::query()->where('type', $type)->current()->firstOrFail();
}

// Storage of signature + schedule -------------------------------------------------------------------

it('stores the signature file id, the schedule and version 1 on every issued letter', function (): void {
    b3Issued($this, send: false);

    foreach ([LetterType::Siswa, LetterType::Guru] as $type) {
        $letter = b3Letter($type);
        expect($letter->version)->toBe(1)
            ->and($letter->signature_file_id)->toBe($this->signature->getKey())
            ->and($letter->schedule_day)->toBe('Sabtu')
            ->and($letter->schedule_date)->toBe('7 November 2026')
            ->and($letter->schedule_time)->toBe('07.00 WIB s.d Selesai')
            ->and($letter->place)->toBe('Daring')
            ->and($letter->replaces_letter_id)->toBeNull()
            ->and($letter->revoked_at)->toBeNull();
    }
});

// RevokeLetter ---------------------------------------------------------------------------------------

it('revokes a sent letter and issues a new version with a new number, sent at once', function (): void {
    $registration = b3Issued($this);
    $old = b3Letter();
    $oldFile = $old->file_id;

    $new = app(RevokeLetter::class)->handle($this->tu, $old, 'Tanggal pelaksanaan salah ketik.', b3LetterDraft('400.3.8/320/MRD/X/2026', date: '8 November 2026'));

    $old->refresh();
    expect($old->revoked_at)->not->toBeNull()
        ->and($old->revoked_by)->toBe($this->tu->getKey())
        ->and($old->revoke_reason)->toBe('Tanggal pelaksanaan salah ketik.')
        ->and($old->full_number)->toBe(B3_SISWA)
        ->and($old->file_id)->toBe($oldFile);

    expect($new->full_number)->toBe('400.3.8/320/MRD/X/2026')
        ->and($new->sequence)->toBeNull()
        ->and($new->version)->toBe(2)
        ->and($new->replaces_letter_id)->toBe($old->getKey())
        ->and($new->sent_at)->not->toBeNull()
        ->and($new->verification_code)->not->toBe($old->verification_code)
        ->and($new->schedule_date)->toBe('8 November 2026')
        ->and($new->signature_file_id)->toBe($this->signature->getKey());

    $file = File::query()->findOrFail($new->file_id);
    $bytes = Storage::disk('local')->get($file->path);
    expect($file->purpose)->toBe(FilePurpose::Letter)
        ->and(str_starts_with($bytes, '%PDF-'))->toBeTrue()
        ->and(hash('sha256', $bytes))->toBe($file->sha256)
        ->and($file->original_name)->toEndWith('-versi-2.pdf');

    // Registration and the guru letter are untouched; the relation only returns current letters.
    expect($registration->fresh()->status)->toBe(RegistrationStatus::SuratTerbit)
        ->and($registration->letters()->pluck('full_number')->sort()->values()->all())->toBe([B3_GURU, '400.3.8/320/MRD/X/2026'])
        ->and($registration->letterHistory()->count())->toBe(3)
        ->and($registration->studentLetter()->value('full_number'))->toBe('400.3.8/320/MRD/X/2026');

    Notification::assertSentTo(ApproveRegistration::memberUsers($registration), LetterRevised::class, fn (LetterRevised $n): bool => $n->audience === 'siswa'
        && $n->oldNumber === B3_SISWA && $n->newNumber === '400.3.8/320/MRD/X/2026');
    Notification::assertNotSentTo($this->mentor, LetterRevised::class);

    $audit = Activity::query()->where('event', 'letter.revoked')->firstOrFail();
    expect($audit->causer_id)->toBe($this->tu->getKey())
        ->and($audit->properties['reason'])->toBe('Tanggal pelaksanaan salah ketik.')
        ->and($audit->properties['revoked']['number'])->toBe(B3_SISWA)
        ->and($audit->properties['issued']['number'])->toBe('400.3.8/320/MRD/X/2026')
        ->and($audit->properties['signature_file_id'])->toBe($this->signature->getKey());

    expect(glob($this->pdfSandboxRoot.'/*') ?: [])->toBe([]);
});

it('notifies only the mentor when the guru letter is corrected', function (): void {
    $registration = b3Issued($this);

    $new = app(RevokeLetter::class)->handle($this->tu, b3Letter(LetterType::Guru), 'Pangkat guru salah.', b3LetterDraft('400.3.8.6/1690/2026'));

    expect($new->full_number)->toBe('400.3.8.6/1690/2026');
    Notification::assertSentTo($this->mentor, LetterRevised::class, fn (LetterRevised $n): bool => $n->audience === 'guru');
    Notification::assertNotSentTo(ApproveRegistration::memberUsers($registration), LetterRevised::class);
});

it('never reuses a number, not even the revoked one', function (): void {
    b3Issued($this);
    $other = F::registration(RegistrationStatus::Disetujui, ['Hana Safira'], $this->mentor);
    app(IssueLetters::class)->handle($this->tu, $other, LetterType::Siswa, b3LetterDraft('400.3.8/314/MRD/X/2026'));

    // Its own number, another letter's number, and the same number in another spelling.
    foreach ([B3_SISWA, '400.3.8/314/MRD/X/2026', '400.3.8 / 314 / mrd / x / 2026'] as $taken) {
        try {
            app(RevokeLetter::class)->handle($this->tu, b3Letter(), 'Koreksi nomor surat.', b3LetterDraft($taken));
            $this->fail('A used number was accepted: '.$taken);
        } catch (ValidationException $e) {
            expect($e->errors())->toHaveKey('number');
        }
    }

    // Revoke, then try to give the revoked number to a new registration.
    app(RevokeLetter::class)->handle($this->tu, Letter::query()->where('full_number', B3_SISWA)->firstOrFail(), 'Koreksi data.', b3LetterDraft('400.3.8/330/MRD/X/2026'));
    $third = F::registration(RegistrationStatus::Disetujui, ['Citra Ayu Lestari'], $this->mentor);

    expect(fn () => app(IssueLetters::class)->handle($this->tu, $third, LetterType::Siswa, b3LetterDraft(B3_SISWA)))->toThrow(ValidationException::class);
    expect(Letter::query()->where('full_number', B3_SISWA)->where('type', LetterType::Siswa)->count())->toBe(1);
});

it('validates the reason, the new number and the letter data at once', function (): void {
    b3Issued($this);

    try {
        app(RevokeLetter::class)->handle($this->tu, b3Letter(), 'ok', b3LetterDraft('', date: ''));
        $this->fail('Invalid correction accepted.');
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toEqualCanonicalizing(['reason', 'number', 'schedule_date'])
            ->and($e->errors()['reason'][0])->toBe(RevokeLetter::MSG_REASON);
    }

    expect(Letter::query()->whereNotNull('revoked_at')->count())->toBe(0);
});

it('lets only Tata Usaha revoke, and only the current version', function (): void {
    $registration = b3Issued($this);
    $old = b3Letter();
    $member = ApproveRegistration::memberUsers($registration)->first();

    foreach ([F::staff(RoleName::KepalaSekolah), F::staff(RoleName::Kesiswaan), F::staff(RoleName::Humas), $this->mentor, $member] as $user) {
        expect(fn () => app(RevokeLetter::class)->handle($user, $old, 'Koreksi data.', b3LetterDraft('400.3.8/321/MRD/X/2026')))->toThrow(AuthorizationException::class);
    }

    app(RevokeLetter::class)->handle($this->tu, $old, 'Koreksi data.', b3LetterDraft('400.3.8/321/MRD/X/2026'));

    expect(fn () => app(RevokeLetter::class)->handle($this->tu, $old->fresh(), 'Koreksi lagi.', b3LetterDraft('400.3.8/322/MRD/X/2026')))->toThrow(AuthorizationException::class);
    expect(Letter::query()->where('type', LetterType::Siswa)->count())->toBe(2);
});

it('shows a revoked letter to staff only; members and mentor get the new version', function (): void {
    $registration = b3Issued($this);
    $old = b3Letter();
    $new = app(RevokeLetter::class)->handle($this->tu, $old, 'Koreksi data.', b3LetterDraft('400.3.8/321/MRD/X/2026'));
    $old->refresh();
    $member = ApproveRegistration::memberUsers($registration)->first();

    expect($member->can('view', $old))->toBeFalse()
        ->and($member->can('view', $new))->toBeTrue()
        ->and($this->mentor->can('view', $old))->toBeFalse()
        ->and($this->mentor->can('view', $new))->toBeTrue()
        ->and(F::staff(RoleName::KepalaSekolah)->can('view', $old))->toBeTrue();

    // FilePolicy delegates letter files to LetterPolicy::view.
    $oldFile = File::query()->findOrFail($old->file_id);
    expect($member->can('view', $oldFile))->toBeFalse()
        ->and($member->can('view', File::query()->findOrFail($new->file_id)))->toBeTrue();
});

it('keeps an unsent correction for the normal send step', function (): void {
    $registration = b3Issued($this, send: false);
    $old = b3Letter();

    $new = app(RevokeLetter::class)->handle($this->tu, $old, 'Nomor undangan salah.', b3LetterDraft('400.3.8/315/MRD/X/2026'));

    expect($new->sent_at)->toBeNull();
    Notification::assertNothingSent();

    app(SendLetters::class)->handle($this->tu, $registration);

    expect($old->fresh()->sent_at)->toBeNull() // the revoked version is never sent
        ->and($new->fresh()->sent_at)->not->toBeNull()
        ->and($registration->fresh()->status)->toBe(RegistrationStatus::SuratTerbit);

    Notification::assertSentTo(ApproveRegistration::memberUsers($registration), LettersIssued::class, function (LettersIssued $n): bool {
        return str_contains($n->toArray(new User)['message'], '400.3.8/315/MRD/X/2026');
    });

    // Never sent → never valid publicly.
    $this->get('/verifikasi-surat/'.$old->verification_code)->assertNotFound()->assertSee('Tidak ditemukan');
});

it('needs the signature image for the new version', function (): void {
    b3Issued($this);
    Setting::put(SettingKey::SignatureFileId, null);

    try {
        app(RevokeLetter::class)->handle($this->tu, b3Letter(), 'Koreksi data.', b3LetterDraft('400.3.8/321/MRD/X/2026'));
        $this->fail('Correction without signature accepted.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('signature');
    }

    expect(b3Letter()->full_number)->toBe(B3_SISWA);
});

// Public verification ---------------------------------------------------------------------------------

it('shows "Dicabut" with the replacement number on the public verification page', function (): void {
    b3Issued($this);
    $old = b3Letter();
    $new = app(RevokeLetter::class)->handle($this->tu, $old, 'Koreksi data.', b3LetterDraft('400.3.8/321/MRD/X/2026'));

    $this->get('/verifikasi-surat/'.$old->verification_code)
        ->assertOk()
        ->assertSee('Dicabut')
        ->assertSee(B3_SISWA)
        ->assertSee('Diganti dengan')
        ->assertSee('400.3.8/321/MRD/X/2026')
        ->assertDontSee('Koreksi data.')
        ->assertDontSee('Raka Pradipta')
        ->assertDontSee('Pastikan nomor dan nama lomba sama')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

    $this->get('/verifikasi-surat/'.$new->verification_code)
        ->assertOk()
        ->assertSee('Sah')
        ->assertSee('400.3.8/321/MRD/X/2026')
        ->assertDontSee('Dicabut');
});

// Screens ---------------------------------------------------------------------------------------------

it('corrects a letter from the TU screen: explained, errors listed with a jump, history kept', function (): void {
    $registration = b3Issued($this);
    $old = b3Letter();

    $component = Livewire::actingAs($this->tu)->test(SuratTugas::class, ['registration' => $registration])
        ->assertSet('siswa.tanggal', '7 November 2026')
        ->assertSet('siswa.nomor', B3_SISWA)
        ->assertSee('Koreksi')
        ->call('startRevision', $old->getKey())
        ->assertSet('revisingId', $old->getKey())
        ->assertSet('tab', 'siswa')
        ->assertSet('siswa.nomor', '')
        ->assertSee('akan dicabut')
        ->assertSee('Nomor surat baru')
        ->assertSee('Alasan pencabutan')
        ->assertSee('minimal 5 karakter')
        ->assertSee('Cabut dan terbitkan ulang')
        // a used number is refused while typing
        ->set('siswa.nomor', B3_SISWA)
        ->assertHasErrors(['siswa.nomor']);

    // The button is never greyed out: an incomplete correction is explained.
    expect($component->html())->not->toMatch('/<button[^>]*\sdisabled[\s>]/');

    $component->set('siswa.nomor', '')
        ->call('revise')
        ->assertHasErrors(['siswa.nomor', 'alasan'])
        ->assertSet('failed', true)
        ->assertDispatched('sth-form-invalid', form: 'surat-form-siswa')
        ->assertSee('Surat belum bisa dicabut')
        ->assertSeeInOrder(['Nomor surat:', 'Alasan pencabutan:', RevokeLetter::MSG_REASON])
        ->set('siswa.nomor', '400.3.8/322/MRD/X/2026')
        ->set('siswa.tanggal', '8 November 2026')
        ->set('alasan', 'Tanggal pelaksanaan salah ketik.')
        ->call('revise')
        ->assertHasNoErrors()
        ->assertSet('revisingId', null)
        ->assertSet('failed', false)
        ->assertDispatched('sth-toast', tone: 'success')
        ->assertDispatched('sth-badges')
        ->assertSee('Riwayat pencabutan')
        ->assertSee('Tanggal pelaksanaan salah ketik.')
        ->assertSee('400.3.8/322/MRD/X/2026');

    expect(b3Letter()->full_number)->toBe('400.3.8/322/MRD/X/2026')
        ->and(b3Letter()->schedule_date)->toBe('8 November 2026')
        ->and(Letter::query()->whereNotNull('revoked_at')->count())->toBe(1);

    // Cancel restores the stored values of that letter.
    $component->call('startRevision', b3Letter(LetterType::Guru)->getKey())
        ->assertSet('tab', 'guru')
        ->set('guru.pukul', 'diubah')
        ->call('cancelRevision')
        ->assertSet('revisingId', null)
        ->assertSet('guru.pukul', '07.00 WIB s.d Selesai')
        ->assertSet('guru.nomor', B3_GURU);

    Livewire::actingAs($this->tu)->test(ArsipSurat::class)
        ->assertSee('Dicabut')
        ->assertSee('diganti 400.3.8/322/')
        ->assertSee('versi 2');
});

it('refuses a correction of a letter from another registration on the screen', function (): void {
    $registration = b3Issued($this);
    $other = F::registration(RegistrationStatus::Disetujui, ['Hana Safira'], $this->mentor);
    app(IssueLetters::class)->handle($this->tu, $other, LetterType::Siswa, b3LetterDraft('400.3.8/314/MRD/X/2026'));
    $foreign = Letter::query()->where('registration_id', $other->getKey())->where('type', LetterType::Siswa)->firstOrFail();

    Livewire::actingAs($this->tu)->test(SuratTugas::class, ['registration' => $registration])
        ->call('startRevision', $foreign->getKey())
        ->assertNotFound();
});
