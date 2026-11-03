<?php

declare(strict_types=1);

use App\Actions\Talent\SaveTalentProfile;
use App\Enums\FilePurpose;
use App\Enums\RoleName;
use App\Models\TalentProfile;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Siswa\Hasil\HasilFixtures as F;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::boot();

    $this->nadia = F::student(talent: false);
    $this->save = app(SaveTalentProfile::class);
});

it('saves the Riwayat talenta with up to 4 unique interests and 2 certificates', function (): void {
    $profile = $this->save->handle($this->nadia->user, [
        'interests' => ['Penelitian', ' Kimia ', 'kimia', ''],
        'skills' => 'Kimia dan metodologi penelitian',
        'organizations' => 'KIR SMAGA, anggota sejak 2025',
        'junior_high_achievements' => 'Juara II LKTI tingkat kota, 2024',
    ], [F::pdf('sertifikat-lkti-2024.pdf')]);

    expect($profile->student_id)->toBe($this->nadia->getKey())
        ->and($profile->interests)->toBe(['Penelitian', 'Kimia'])
        ->and($profile->skills)->toBe('Kimia dan metodologi penelitian')
        ->and($profile->certificates)->toHaveCount(1)
        ->and($profile->certificates->first()->purpose)->toBe(FilePurpose::TalentEvidence)
        ->and(Activity::query()->where('event', 'talent_profile.saved')->exists())->toBeTrue();

    // Second save updates the same row, adds one and removes the first certificate.
    $first = $profile->certificates->first()->getKey();
    $updated = $this->save->handle($this->nadia->user, ['interests' => ['Penelitian']], [F::jpg('sertifikat-kir-2025.jpg')], [$first]);

    expect($updated->getKey())->toBe($profile->getKey())
        ->and(TalentProfile::query()->count())->toBe(1)
        ->and($updated->certificates->pluck('original_name')->all())->toBe(['sertifikat-kir-2025.jpg'])
        ->and($updated->skills)->toBeNull();
});

it('rejects more than 4 interests, too long texts and a third certificate', function (): void {
    expect(fn () => $this->save->handle($this->nadia->user, ['interests' => ['A1', 'B2', 'C3', 'D4', 'E5']]))
        ->toThrow(ValidationException::class)
        ->and(fn () => $this->save->handle($this->nadia->user, ['organizations' => str_repeat('a', 1001)]))
        ->toThrow(ValidationException::class);

    $this->save->handle($this->nadia->user, [], [F::pdf('a.pdf'), F::pdf('b.pdf')]);

    try {
        $this->save->handle($this->nadia->user, [], [F::pdf('c.pdf')]);
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('sertifikat');
    }

    expect(TalentProfile::query()->first()->certificates)->toHaveCount(2)
        ->and(Storage::disk('local')->allFiles('lampiran'))->toHaveCount(2);
});

it('ignores removal ids that are not the student\'s own certificates', function (): void {
    $other = F::student('Dimas Arya Nugroho', talent: false);
    $theirs = $this->save->handle($other->user, [], [F::pdf('milik-dimas.pdf')]);

    $mine = $this->save->handle($this->nadia->user, [], [], [$theirs->certificates->first()->getKey()]);

    expect($mine->certificates)->toHaveCount(0)
        ->and($theirs->fresh()->certificates)->toHaveCount(1);
});

it('is for students only', function (): void {
    expect(fn () => $this->save->handle(F::staff(RoleName::Kesiswaan), ['interests' => ['X']]))
        ->toThrow(AuthorizationException::class);
});
