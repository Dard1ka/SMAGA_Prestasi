<?php

declare(strict_types=1);

use App\Actions\Talent\ApplyTalent;
use App\Actions\Talent\CancelTalentApplication;
use App\Enums\FilePurpose;
use App\Enums\RoleName;
use App\Enums\TalentStatus;
use App\Enums\TalentTypeCode;
use App\Enums\UserStatus;
use App\Models\TalentApplication;
use App\Models\TalentBranch;
use App\Models\TalentType;
use Database\Seeders\TalentSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Siswa\Hasil\HasilFixtures as F;

uses(RefreshDatabase::class);

function w2a2TalentType(TalentTypeCode $code): TalentType
{
    return TalentType::query()->where('code', $code)->firstOrFail();
}

function w2a2TalentBranch(TalentTypeCode $code, string $name): TalentBranch
{
    return w2a2TalentType($code)->branches()->where('name', $name)->firstOrFail();
}

beforeEach(function (): void {
    F::boot();
    $this->seed(TalentSeeder::class);

    $this->nadia = F::student(talent: false);
    $this->apply = app(ApplyTalent::class);
});

it('registers a talent choice as terdaftar with an optional bukti on the private disk, audited', function (): void {
    $application = $this->apply->handle($this->nadia->user, w2a2TalentType(TalentTypeCode::Osn), w2a2TalentBranch(TalentTypeCode::Osn, 'Kimia'), F::pdf('sertifikat-osn.pdf'));

    expect($application->status)->toBe(TalentStatus::Terdaftar)
        ->and($application->student_id)->toBe($this->nadia->getKey())
        ->and($application->evidenceFile->purpose)->toBe(FilePurpose::TalentEvidence)
        ->and($application->evidenceFile->owner_user_id)->toBe($this->nadia->user->getKey())
        ->and($application->mentor_id)->toBeNull();
    Storage::disk('local')->assertExists($application->evidenceFile->path);

    expect(Activity::query()->where('event', 'talent.applied')->where('causer_id', $this->nadia->user->getKey())->exists())->toBeTrue();
});

it('allows several jenis and cabang (D-23) but never the same one twice', function (): void {
    $this->apply->handle($this->nadia->user, w2a2TalentType(TalentTypeCode::Osn), w2a2TalentBranch(TalentTypeCode::Osn, 'Kimia'));
    $this->apply->handle($this->nadia->user, w2a2TalentType(TalentTypeCode::Osn), w2a2TalentBranch(TalentTypeCode::Osn, 'Biologi'));
    $this->apply->handle($this->nadia->user, w2a2TalentType(TalentTypeCode::Riset), w2a2TalentBranch(TalentTypeCode::Riset, 'OPSI'));

    expect(fn () => $this->apply->handle($this->nadia->user, w2a2TalentType(TalentTypeCode::Osn), w2a2TalentBranch(TalentTypeCode::Osn, 'Kimia')))
        ->toThrow(ValidationException::class, 'Sudah dipilih. Pilih cabang lain.');

    expect(TalentApplication::query()->where('student_id', $this->nadia->getKey())->count())->toBe(3);
});

it('refuses an old tidak lolos choice as an ordinary duplicate', function (): void {
    $olpain = w2a2TalentType(TalentTypeCode::Olpain);
    $kimia = $this->apply->handle($this->nadia->user, w2a2TalentType(TalentTypeCode::Osn), w2a2TalentBranch(TalentTypeCode::Osn, 'Kimia'));
    $noBranch = $this->apply->handle($this->nadia->user, $olpain, null);

    foreach ([$kimia, $noBranch] as $application) {
        $application->forceFill(['status' => TalentStatus::TidakLolos, 'rejected_from' => TalentStatus::Seleksi, 'student_note' => 'Belum lolos.'])->save();
    }

    // D-142: no stages any more; an old "tidak lolos" row is an ordinary choice the student already has.
    expect(fn () => $this->apply->handle($this->nadia->user, w2a2TalentType(TalentTypeCode::Osn), w2a2TalentBranch(TalentTypeCode::Osn, 'Kimia'), F::pdf()))
        ->toThrow(ValidationException::class, ApplyTalent::DUPLICATE_MESSAGE)
        ->and(fn () => $this->apply->handle($this->nadia->user, $olpain, null))
        ->toThrow(ValidationException::class, ApplyTalent::DUPLICATE_MESSAGE);

    // Nothing changed: same rows, still rejected, no stored file left behind.
    expect(TalentApplication::withTrashed()->where('student_id', $this->nadia->getKey())->count())->toBe(2)
        ->and($kimia->fresh()->status)->toBe(TalentStatus::TidakLolos)
        ->and($kimia->fresh()->student_note)->toBe('Belum lolos.')
        ->and(Storage::disk('local')->allFiles())->toBe([]);

    // Another cabang of the same jenis is still open to the student.
    expect($this->apply->handle($this->nadia->user, w2a2TalentType(TalentTypeCode::Osn), w2a2TalentBranch(TalentTypeCode::Osn, 'Biologi'))->status)->toBe(TalentStatus::Terdaftar);
});

it('handles OLPAIN without a cabang and blocks its duplicate in the Action (NULL branch)', function (): void {
    $olpain = w2a2TalentType(TalentTypeCode::Olpain);

    $this->apply->handle($this->nadia->user, $olpain, null);

    expect(fn () => $this->apply->handle($this->nadia->user, $olpain, null))
        ->toThrow(ValidationException::class);

    $other = TalentBranch::factory()->create(['talent_type_id' => w2a2TalentType(TalentTypeCode::Osn)->getKey()]);
    expect(fn () => $this->apply->handle($this->nadia->user, $olpain, $other))->toThrow(ValidationException::class);

    expect(TalentApplication::query()->where('talent_type_id', $olpain->getKey())->count())->toBe(1);
});

it('requires a cabang of the chosen jenis for every other type', function (): void {
    expect(fn () => $this->apply->handle($this->nadia->user, w2a2TalentType(TalentTypeCode::Fls3n), null))
        ->toThrow(ValidationException::class, 'Pilih satu cabang dulu.')
        ->and(fn () => $this->apply->handle($this->nadia->user, w2a2TalentType(TalentTypeCode::Fls3n), w2a2TalentBranch(TalentTypeCode::O2sn, 'Renang')))
        ->toThrow(ValidationException::class);

    $inactive = w2a2TalentBranch(TalentTypeCode::O2sn, 'Karate');
    $inactive->forceFill(['active' => false])->save();
    expect(fn () => $this->apply->handle($this->nadia->user, w2a2TalentType(TalentTypeCode::O2sn), $inactive))
        ->toThrow(ValidationException::class);
});

it('lets kelas XII join (D-23) and refuses staff and archived/disabled accounts', function (): void {
    $xii = F::student('Raka Pradipta', 'XII-B', talent: false);
    expect($this->apply->handle($xii->user, w2a2TalentType(TalentTypeCode::O2sn), w2a2TalentBranch(TalentTypeCode::O2sn, 'Atletik'))->exists)->toBeTrue();

    expect(fn () => $this->apply->handle(F::staff(RoleName::Kesiswaan), w2a2TalentType(TalentTypeCode::Osn), w2a2TalentBranch(TalentTypeCode::Osn, 'Fisika')))
        ->toThrow(AuthorizationException::class);

    $this->nadia->user->forceFill(['status' => UserStatus::Archived])->save();
    expect(fn () => $this->apply->handle($this->nadia->user->fresh(), w2a2TalentType(TalentTypeCode::Osn), w2a2TalentBranch(TalentTypeCode::Osn, 'Fisika')))
        ->toThrow(AuthorizationException::class);
});

it('refuses an unsafe bukti and stores nothing', function (): void {
    $svg = UploadedFile::fake()->createWithContent('bukti.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

    try {
        $this->apply->handle($this->nadia->user, w2a2TalentType(TalentTypeCode::Osn), w2a2TalentBranch(TalentTypeCode::Osn, 'Kimia'), $svg);
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('bukti');
    }

    expect(TalentApplication::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('cancels own choices in any status, and re-applying restores the same row', function (): void {
    $cancel = app(CancelTalentApplication::class);
    $kimia = $this->apply->handle($this->nadia->user, w2a2TalentType(TalentTypeCode::Osn), w2a2TalentBranch(TalentTypeCode::Osn, 'Kimia'), F::pdf());

    expect(fn () => $cancel->handle(F::student('Dimas Arya Nugroho', talent: false)->user, $kimia))->toThrow(AuthorizationException::class);

    $cancel->handle($this->nadia->user, $kimia);
    expect($kimia->fresh()->trashed())->toBeTrue()
        ->and(Activity::query()->where('event', 'talent.cancelled')->exists())->toBeTrue();

    $again = $this->apply->handle($this->nadia->user, w2a2TalentType(TalentTypeCode::Osn), w2a2TalentBranch(TalentTypeCode::Osn, 'Kimia'));
    expect($again->getKey())->toBe($kimia->getKey())
        ->and($again->trashed())->toBeFalse()
        ->and($again->evidence_file_id)->toBe($kimia->evidence_file_id)
        ->and($again->status)->toBe(TalentStatus::Terdaftar);

    // D-142: an old stage no longer blocks the student; a deleted row cannot be deleted twice.
    $again->forceFill(['status' => TalentStatus::Pemetaan])->save();
    $cancel->handle($this->nadia->user, $again->fresh());
    expect($again->fresh()->trashed())->toBeTrue()
        ->and(fn () => $cancel->handle($this->nadia->user, $again->fresh()))->toThrow(AuthorizationException::class);
});
