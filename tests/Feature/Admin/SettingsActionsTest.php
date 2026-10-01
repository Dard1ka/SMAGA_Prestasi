<?php

declare(strict_types=1);

use App\Actions\Settings\RemoveSignatureImage;
use App\Actions\Settings\ReplaceSignatureImage;
use App\Actions\Settings\UpdateLetterFormats;
use App\Actions\Settings\UpdateLimits;
use App\Actions\Settings\UpdatePublicSettings;
use App\Actions\Settings\UpdateSigner;
use App\Enums\FilePurpose;
use App\Enums\RoleName;
use App\Enums\SettingKey;
use App\Livewire\Admin\Pengaturan\BatasArsip;
use App\Livewire\Admin\Pengaturan\Publik;
use App\Livewire\Admin\Pengaturan\Surat;
use App\Livewire\Publik\Data\PublicSettings;
use App\Models\Classroom;
use App\Models\File;
use App\Models\Setting;
use App\Models\Student;
use App\Notifications\Admin\SignatureReplaced;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Admin\AdminTestHelpers as A;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    A::seedRoles();
    $this->admin = A::admin();
});

/** A real PNG with GD (fictional content). */
function sthPngUpload(string $name = 'ttd.png', int $width = 64, int $height = 32): UploadedFile
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, 20, 40, 200));
    ob_start();
    imagepng($image);
    $bytes = (string) ob_get_clean();

    return UploadedFile::fake()->createWithContent($name, $bytes);
}

// Batas & arsip -------------------------------------------------------------------------------------

it('updates the report deadline and recalculates archive dates of students who are not archived', function (): void {
    $class = Classroom::factory()->level(12)->create(['name' => 'XII-2']);
    $current = Student::factory()->create(['classroom_id' => $class->id, 'grade12_start_date' => '2026-07-01', 'archive_at' => '2028-01-01']);
    $alumni = Student::factory()->archived()->create(['classroom_id' => $class->id, 'grade12_start_date' => '2023-07-01', 'archive_at' => '2025-01-01']);

    $result = app(UpdateLimits::class)->handle($this->admin, 60, 24);

    expect(Setting::int(SettingKey::ReportDeadlineDays))->toBe(60)
        ->and(Setting::int(SettingKey::ArchiveMonthsAfterGrade12))->toBe(24)
        ->and($result['recalculated'])->toBe(1)
        ->and($current->fresh()->archive_at->toDateString())->toBe('2028-07-01')
        ->and($alumni->fresh()->archive_at->toDateString())->toBe('2025-01-01');

    $log = Activity::query()->where('event', 'settings.limits_updated')->sole();
    expect($log->properties['report_deadline_days'])->toBe(['from' => 45, 'to' => 60])
        ->and($log->properties['archive_dates_recalculated'])->toBe(1);
});

it('rejects out-of-range limits and non-admins', function (): void {
    expect(fn () => app(UpdateLimits::class)->handle($this->admin, 0, 18))->toThrow(ValidationException::class)
        ->and(fn () => app(UpdateLimits::class)->handle($this->admin, 45, 121))->toThrow(ValidationException::class)
        ->and(fn () => app(UpdateLimits::class)->handle(A::staff(RoleName::Kesiswaan), 45, 18))->toThrow(AuthorizationException::class)
        ->and(Setting::int(SettingKey::ReportDeadlineDays))->toBe(45);
});

it('saves limits from the Batas & arsip tab', function (): void {
    $this->actingAs($this->admin);
    session(A::passwordConfirmed());

    Livewire::test(BatasArsip::class)
        ->assertSet('reportDeadlineDays', 45)
        ->assertSee('Final 24 Sep 2026 → batas lapor 8 Nov 2026.')
        ->set('reportDeadlineDays', 30)
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::int(SettingKey::ReportDeadlineDays))->toBe(30);
});

// Surat & tanda tangan ------------------------------------------------------------------------------

it('replaces the signature with a re-encoded private PNG, keeps the old one and notifies', function (): void {
    Notification::fake();
    $kepsek = A::staff(RoleName::KepalaSekolah);

    $first = app(ReplaceSignatureImage::class)->handle($this->admin, sthPngUpload('ttd-lama.png'));
    $second = app(ReplaceSignatureImage::class)->handle($this->admin, sthPngUpload('ttd-baru.png'));

    expect(Setting::get(SettingKey::SignatureFileId))->toBe($second->id)
        ->and($second->purpose)->toBe(FilePurpose::Signature)
        ->and($second->mime)->toBe('image/png')
        ->and($second->disk)->toBe('local')
        ->and($second->path)->toStartWith('ttd/')
        ->and(File::query()->whereKey($first->id)->exists())->toBeTrue();
    Storage::disk('local')->assertExists([$first->path, $second->path]);

    $log = Activity::query()->where('event', 'signature.replaced')->latest('id')->first();
    expect($log->properties['previous_file_id'])->toBe($first->id)
        ->and($log->properties['sha256'])->toBe($second->sha256);
    Notification::assertSentTo($kepsek, SignatureReplaced::class);
});

it('refuses non-PNG, fake PNG and oversized signature files', function (UploadedFile $file): void {

    expect(fn () => app(ReplaceSignatureImage::class)->handle($this->admin, $file))->toThrow(ValidationException::class)
        ->and(Setting::get(SettingKey::SignatureFileId))->toBeNull();
})->with([
    'jpeg' => fn () => UploadedFile::fake()->image('ttd.jpg'),
    'pdf renamed' => fn () => UploadedFile::fake()->createWithContent('ttd.png', "%PDF-1.4\n%fake\n"),
    'too big' => fn () => UploadedFile::fake()->create('ttd.png', 1500, 'image/png'),
]);

it('lets only signature.manage change the signature', function (RoleName $role): void {

    expect(fn () => app(ReplaceSignatureImage::class)->handle(A::staff($role), sthPngUpload()))->toThrow(AuthorizationException::class)
        ->and(fn () => app(RemoveSignatureImage::class)->handle(A::staff($role)))->toThrow(AuthorizationException::class);
})->with([RoleName::TataUsaha, RoleName::KepalaSekolah, RoleName::Kesiswaan]);

it('detaches the signature and audits it', function (): void {
    Notification::fake();
    app(ReplaceSignatureImage::class)->handle($this->admin, sthPngUpload());

    app(RemoveSignatureImage::class)->handle($this->admin);

    expect(Setting::get(SettingKey::SignatureFileId))->toBeNull()
        ->and(Activity::query()->where('event', 'signature.removed')->count())->toBe(1);
});

it('uploads the signature from the Surat tab', function (): void {
    Notification::fake();
    $this->actingAs($this->admin);
    session(A::passwordConfirmed());

    Livewire::test(Surat::class)
        ->set('signature', sthPngUpload())
        ->call('uploadSignature')
        ->assertHasNoErrors()
        ->assertSee('Aktif');

    expect(Setting::get(SettingKey::SignatureFileId))->not->toBeNull();
});

it('updates the signer and letter formats with validation', function (): void {
    app(UpdateSigner::class)->handle($this->admin, 'Drs. Bambang Hartono, M.Pd.', 'Pembina Tk. I, IV/b', '19700101 199501 1 001');
    app(UpdateLetterFormats::class)->handle($this->admin, '400.3.8/{seq}/MRD/{roman_month}/{year}', '400.3.8.6/{seq}/{year}');

    expect(Setting::get(SettingKey::SignerName))->toBe('Drs. Bambang Hartono, M.Pd.')
        ->and(Activity::query()->where('event', 'settings.signer_updated')->count())->toBe(1)
        ->and(Activity::query()->where('event', 'settings.letter_formats_updated')->count())->toBe(0) // unchanged defaults
        ->and(fn () => app(UpdateLetterFormats::class)->handle($this->admin, '400.3.8/MRD/{year}', '400.3.8.6/{seq}/{year}'))
        ->toThrow(ValidationException::class)
        ->and(fn () => app(UpdateSigner::class)->handle($this->admin, 'Drs. Bambang', '', 'NIP-abc'))
        ->toThrow(ValidationException::class);
});

it('previews letter numbers on the Surat tab', function (): void {
    $this->travelTo('2026-09-30 10:00:00');
    $this->actingAs($this->admin);

    Livewire::test(Surat::class)
        ->assertSee('400.3.8/312/MRD/IX/2026')
        ->assertSee('400.3.8.6/1680/2026')
        ->set('formatGuru', 'tanpa-nomor')
        ->assertSee('–');
});

// Halaman publik ------------------------------------------------------------------------------------

it('keeps public indexing and certificate links off by default and lets Admin switch them', function (): void {
    expect(PublicSettings::indexingAllowed())->toBeFalse()
        ->and(PublicSettings::certificatesAllowed())->toBeFalse()
        ->and(PublicSettings::robots())->toBe('noindex, nofollow');

    app(UpdatePublicSettings::class)->handle($this->admin, true, false);

    expect(PublicSettings::indexingAllowed())->toBeTrue()
        ->and(PublicSettings::robots())->toBe('index, follow')
        ->and(PublicSettings::robots(studentPage: true))->toBe('noindex, nofollow')
        ->and(Activity::query()->where('event', 'settings.public_updated')->count())->toBe(1);

    expect(fn () => app(UpdatePublicSettings::class)->handle(A::staff(RoleName::Humas), false, false))->toThrow(AuthorizationException::class);
});

it('saves the public switches from the Halaman publik tab', function (): void {
    $this->actingAs($this->admin);
    session(A::passwordConfirmed());

    Livewire::test(Publik::class)->set('certificates', true)->call('save')->assertHasNoErrors();

    expect(PublicSettings::certificatesAllowed())->toBeTrue();
});
