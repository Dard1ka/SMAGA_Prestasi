<?php

declare(strict_types=1);

use App\Enums\Bidang;
use App\Enums\RoleName;
use App\Enums\TalentTypeCode;
use App\Livewire\Guru\Binaan;
use App\Models\TalentApplication;
use App\Models\TalentBranch;
use App\Models\TalentType;
use Database\Seeders\TalentSeeder;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Siswa\Hasil\HasilFixtures as F;

uses(RefreshDatabase::class);

/*
 * FS1-E (D-143): the seventh jenis "Lainnya" (no bidang, no cabang), a "Lainnya" cabang last in every jenis that has
 * cabang, and the student's own text in `talent_applications.other_text`.
 */

beforeEach(function (): void {
    F::boot();
    $this->seed(TalentSeeder::class);
    $this->withoutVite();
    config(['fortify.sth.require_staff_2fa' => false]);

    $this->nadia = F::student();
    $this->osn = TalentType::query()->where('code', TalentTypeCode::Osn)->sole();
    $this->lainnya = TalentType::query()->where('code', TalentTypeCode::Lainnya)->sole();
});

if (! function_exists('talentLainnyaRow')) {
    /** A row as the Actions leave it (`other_text` is not mass-assignable). @param array<string, mixed> $attributes */
    function talentLainnyaRow(TalentType $type, ?TalentBranch $branch, ?string $text, array $attributes = []): TalentApplication
    {
        $row = new TalentApplication;
        $row->forceFill(array_merge([
            'student_id' => test()->nadia->getKey(),
            'talent_type_id' => $type->getKey(),
            'talent_branch_id' => $branch?->getKey(),
            'other_text' => $text,
        ], $attributes))->save();

        return $row->fresh();
    }
}

it('seeds Lainnya as the seventh jenis without bidang and a Lainnya cabang last in every jenis that has cabang', function (): void {
    expect($this->lainnya->bidang)->toBeNull()
        ->and($this->lainnya->sort_order)->toBe(7)
        ->and($this->lainnya->branches()->count())->toBe(0)
        ->and(TalentTypeCode::Lainnya->bidang())->toBeNull()
        ->and(TalentTypeCode::Lainnya->label())->toBe('Lainnya')
        ->and(TalentTypeCode::Lainnya->requiresBranch())->toBeFalse()
        ->and(TalentTypeCode::Olpain->requiresBranch())->toBeFalse()
        ->and(TalentTypeCode::Osn->requiresBranch())->toBeTrue()
        ->and(Bidang::forTalentCode('lainnya'))->toBeNull()
        ->and(Bidang::forTalentCode(TalentTypeCode::Osn))->toBe(Bidang::Sains)
        ->and(Bidang::cases())->toHaveCount(4);

    foreach (['osn', 'riset', 'fls3n', 'o2sn', 'debat'] as $code) {
        $last = TalentType::query()->where('code', $code)->sole()->branches()->get()->last();
        expect($last->name)->toBe(TalentBranch::OTHER)
            ->and($last->isOther())->toBeTrue()
            ->and($last->team_size)->toBeNull();
    }

    expect(TalentType::query()->where('code', 'olpain')->sole()->branches()->count())->toBe(0)
        ->and($this->osn->branches()->where('name', 'Kimia')->sole()->isOther())->toBeFalse();

    $this->seed(TalentSeeder::class); // idempotent

    expect(TalentBranch::query()->where('name', 'Lainnya')->count())->toBe(5)
        ->and(TalentType::query()->count())->toBe(7);
});

it('keeps other_text out of mass assignment and builds the titles', function (): void {
    expect((new TalentApplication)->getFillable())->not->toContain('other_text')
        ->and(fn () => new TalentApplication(['other_text' => 'Robotika']))->toThrow(MassAssignmentException::class);

    $osnOther = $this->osn->branches()->where('name', TalentBranch::OTHER)->sole();
    $kimia = $this->osn->branches()->where('name', 'Kimia')->sole();
    $olpain = TalentType::query()->where('code', TalentTypeCode::Olpain)->sole();

    $robotika = talentLainnyaRow($this->osn, $osnOther, 'Robotika');
    $kaligrafi = talentLainnyaRow($this->lainnya, null, 'Kaligrafi');
    $plain = talentLainnyaRow($this->osn, $kimia, null);
    $olpainRow = talentLainnyaRow($olpain, null, null);

    expect($robotika->title())->toBe('OSN · Lainnya (Robotika)')
        ->and($kaligrafi->title())->toBe('Lainnya (Kaligrafi)')
        ->and($plain->title())->toBe('OSN · Kimia')
        ->and($olpainRow->title())->toBe('OLPAIN')
        ->and($robotika->branchLabel())->toBe('Lainnya (Robotika)')
        ->and($kaligrafi->branchLabel())->toBe('Kaligrafi')
        ->and($plain->branchLabel())->toBe('Kimia')
        ->and($olpainRow->branchLabel())->toBe('Tanpa cabang');
});

it('shows "Lainnya" instead of a bidang on every talent screen', function (): void {
    $guru = F::staff(RoleName::Guru, 'Retno Wulandari');
    talentLainnyaRow($this->lainnya, null, 'Kaligrafi', ['mentor_id' => $guru->getKey()]);

    $this->actingAs($this->nadia->user)->get(route('siswa.talenta'))->assertOk()->assertSee('Lainnya');
    $this->actingAs($this->nadia->user)->get(route('siswa.portofolio'))->assertOk()->assertSee('Lainnya');

    $this->actingAs(F::staff(RoleName::Kesiswaan, 'Dwi Rahayu'))->get(route('kesiswaan.talenta.index'))
        ->assertOk()->assertSee('Lainnya');

    Livewire::actingAs($guru)->test(Binaan::class)->call('setTab', 'talenta')->assertSee('Lainnya');
});
