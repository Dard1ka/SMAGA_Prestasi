<?php

declare(strict_types=1);

use App\Actions\Competitions\SubmitCompetition;
use App\Domain\Files\InvalidUpload;
use App\Domain\Files\StoreUpload;
use App\Domain\Files\UploadLimits;
use App\Enums\FilePurpose;
use App\Enums\RoleName;
use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Auth\AuthTestHelpers as H;

uses(RefreshDatabase::class);

/*
 | Backlog #10 (SECURITY §3d "Kuota & DoS"): 30 stored uploads per user per hour and 200 MB stored per student,
 | enforced inside StoreUpload::store() for every caller, with friendly Indonesian messages.
 */

beforeEach(function (): void {
    H::seedRoles();
    $this->student = H::activeStudent()->user->fresh();
    $this->staff = H::staff(RoleName::Kesiswaan);
});

function b2Pdf(string $name = 'juknis-lomba.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n%%EOF");
}

function b2StoreError(Closure $store): ?string
{
    try {
        $store();
    } catch (InvalidUpload $e) {
        return $e->getMessage();
    }

    return null;
}

it('allows 30 uploads per hour and refuses the 31st with a friendly message', function (): void {
    $store = app(StoreUpload::class);

    for ($i = 0; $i < UploadLimits::MAX_PER_HOUR; $i++) {
        $store->store(b2Pdf(), FilePurpose::Juknis, $this->student);
    }

    $message = b2StoreError(fn () => $store->store(b2Pdf(), FilePurpose::Juknis, $this->student));

    expect($message)->toStartWith('Batas upload tercapai (maks. 30 file per jam). Coba lagi dalam ')
        ->and($message)->toEndWith(' menit.')
        ->and(File::query()->where('owner_user_id', $this->student->getKey())->count())->toBe(30);
});

it('counts the limit per user and resets it after an hour', function (): void {
    $store = app(StoreUpload::class);
    RateLimiter::increment(UploadLimits::key($this->student), UploadLimits::DECAY_SECONDS, UploadLimits::MAX_PER_HOUR);

    expect(b2StoreError(fn () => $store->store(b2Pdf(), FilePurpose::Juknis, $this->student)))->not->toBeNull()
        ->and(b2StoreError(fn () => $store->store(b2Pdf(), FilePurpose::Juknis, $this->staff)))->toBeNull();

    $this->travel(61)->minutes();

    expect(b2StoreError(fn () => $store->store(b2Pdf(), FilePurpose::Juknis, $this->student)))->toBeNull();
});

it('does not count refused files against the hourly limit', function (): void {
    $store = app(StoreUpload::class);
    $fake = UploadedFile::fake()->createWithContent('brosur.pdf', 'not a pdf at all');

    expect(b2StoreError(fn () => $store->store($fake, FilePurpose::Juknis, $this->student)))->not->toBeNull()
        ->and(app(UploadLimits::class)->remainingUploads($this->student))->toBe(UploadLimits::MAX_PER_HOUR);
});

it('refuses a file that would take a student over 200 MB and names the remaining space', function (): void {
    File::factory()->create(['owner_user_id' => $this->student->getKey(), 'size' => UploadLimits::STUDENT_QUOTA_BYTES - 10]);

    $message = b2StoreError(fn () => app(StoreUpload::class)->store(b2Pdf(), FilePurpose::ReportEvidence, $this->student));

    expect($message)->toBe('Ruang berkasmu sudah penuh (maks. 200 MB per siswa). Hubungi Kesiswaan bila perlu ruang tambahan.')
        ->and(File::query()->where('owner_user_id', $this->student->getKey())->count())->toBe(1);

    File::query()->update(['size' => UploadLimits::STUDENT_QUOTA_BYTES - (int) (1.5 * 1024 * 1024)]);
    $big = UploadedFile::fake()->createWithContent('laporan.pdf', "%PDF-1.4\n".str_repeat('%x', 1024 * 1024)."\n%%EOF");

    expect(b2StoreError(fn () => app(StoreUpload::class)->store($big, FilePurpose::ReportEvidence, $this->student)))
        ->toBe('File ini melebihi sisa ruang berkasmu (sisa 1,5 MB dari 200 MB). Perkecil file atau hubungi Kesiswaan.');
});

it('accepts a student upload that still fits the quota', function (): void {
    File::factory()->create(['owner_user_id' => $this->student->getKey(), 'size' => 150 * 1024 * 1024]);

    $file = app(StoreUpload::class)->store(b2Pdf(), FilePurpose::ReportEvidence, $this->student);

    expect($file->owner_user_id)->toBe($this->student->getKey());
});

it('does not apply the student quota to staff', function (): void {
    File::factory()->create(['owner_user_id' => $this->staff->getKey(), 'size' => UploadLimits::STUDENT_QUOTA_BYTES]);

    expect(b2StoreError(fn () => app(StoreUpload::class)->store(b2Pdf(), FilePurpose::Juknis, $this->staff)))->toBeNull();
});

it('surfaces the limit as a validation error on the form field of an Action', function (): void {
    Notification::fake();
    RateLimiter::increment(UploadLimits::key($this->student), UploadLimits::DECAY_SECONDS, UploadLimits::MAX_PER_HOUR);

    try {
        app(SubmitCompetition::class)->handle($this->student, ['name' => 'Olimpiade Sains Kota 2026'], b2Pdf());
        $this->fail('Expected a validation error.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('brosur')
            ->and($e->errors()['brosur'][0])->toStartWith('Batas upload tercapai');
    }
});

it('stores import spreadsheets privately with the same hourly limit and refuses disguised files', function (): void {
    $store = app(StoreUpload::class);
    $csv = UploadedFile::fake()->createWithContent('data-siswa.csv', "Nama lengkap,NIS\nNadia Putri Anindya,225226201\n");

    $file = $store->storeSpreadsheet($csv, $this->staff);

    expect($file->purpose)->toBe(FilePurpose::Import)
        ->and($file->path)->toStartWith('impor/')
        ->and($file->path)->toEndWith('.csv')
        ->and($file->storage()->exists($file->path))->toBeTrue()
        ->and(app(UploadLimits::class)->remainingUploads($this->staff))->toBe(UploadLimits::MAX_PER_HOUR - 1);

    $pdfAsCsv = UploadedFile::fake()->createWithContent('data-siswa.csv', "%PDF-1.4\n%%EOF");
    expect(b2StoreError(fn () => $store->storeSpreadsheet($pdfAsCsv, $this->staff)))->toBe(StoreUpload::MSG_SPREADSHEET_TYPE);

    $png = UploadedFile::fake()->image('data.png');
    expect(b2StoreError(fn () => $store->storeSpreadsheet($png, $this->staff)))->toBe(StoreUpload::MSG_SPREADSHEET_TYPE);
});

it('never limits uploads without an owner (system files)', function (): void {
    expect(fn () => app(UploadLimits::class)->ensureCanUpload(null))->not->toThrow(InvalidUpload::class)
        ->and(fn () => app(UploadLimits::class)->ensureFitsQuota(null, PHP_INT_MAX))->not->toThrow(InvalidUpload::class);
});

it('treats only students as quota holders', function (): void {
    $guru = H::staff(RoleName::Guru, ['email' => 'guru.b2@example.test']);
    File::factory()->create(['owner_user_id' => $guru->getKey(), 'size' => UploadLimits::STUDENT_QUOTA_BYTES]);

    expect(fn () => app(UploadLimits::class)->ensureFitsQuota($guru, 1024))->not->toThrow(InvalidUpload::class)
        ->and(fn () => app(UploadLimits::class)->ensureFitsQuota(User::query()->find($this->student->getKey()), UploadLimits::STUDENT_QUOTA_BYTES + 1))
        ->toThrow(InvalidUpload::class);
});

// QA8: explore round after PR 17 (2026-10-08) -------------------------------------------------------

it('stores a file under a name that matches its content (QA8)', function (): void {
    // Q1-20: a PNG saved as "brosur.pdf" was accepted as juknis and then downloaded as a PDF nobody can open.
    $png = UploadedFile::fake()->createWithContent('brosur.pdf', UploadedFile::fake()->image('gambar.png', 40, 30)->getContent());

    $file = app(StoreUpload::class)->store($png, FilePurpose::Juknis, $this->staff);

    expect($file->mime)->toBe('image/png')
        ->and($file->original_name)->toBe('brosur.png')
        ->and(app(StoreUpload::class)->store(b2Pdf('juknis.pdf'), FilePurpose::Juknis, $this->staff)->original_name)->toBe('juknis.pdf');
});
