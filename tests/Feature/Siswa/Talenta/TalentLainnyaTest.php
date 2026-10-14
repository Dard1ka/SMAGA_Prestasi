<?php

declare(strict_types=1);

use App\Actions\Talent\ApplyTalent;
use App\Actions\Talent\CancelTalentApplication;
use App\Actions\Talent\TalentOtherText;
use App\Enums\Bidang;
use App\Enums\RoleName;
use App\Enums\TalentStatus;
use App\Enums\TalentTypeCode;
use App\Livewire\Guru\Binaan;
use App\Models\TalentApplication;
use App\Models\TalentBranch;
use App\Models\TalentType;
use Database\Seeders\TalentSeeder;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
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

    // FS1 fix wave (E review): assert the row itself, not a word the page always shows (the jenis tile, a filter). The
    // student screens draw no bidang tile for that row (bidang-tile falls back to "sains" for null), the staff screens
    // print "Lainnya" where the bidang line stands.
    $profil = (string) $this->actingAs($this->nadia->user)->get(route('siswa.talenta'))->assertOk()->getContent();
    $chosen = Str::between($profil, 'id="h-pilihan"', 'id="h-tambah"');
    expect($chosen)->toContain('Lainnya (Kaligrafi)')->and($chosen)->not->toContain('cover-');

    $portofolio = (string) $this->actingAs($this->nadia->user)->get(route('siswa.portofolio'))->assertOk()->getContent();
    $talents = Str::between($portofolio, 'id="talenta-judul"', 'Kelola profil talenta');
    expect($talents)->toContain('Lainnya (Kaligrafi)')->and($talents)->not->toContain('cover-');

    $this->actingAs(F::staff(RoleName::Kesiswaan, 'Dwi Rahayu'))->get(route('kesiswaan.talenta.index'))
        ->assertOk()->assertSeeHtml('<span class="text-xs text-muted">Lainnya</span>');

    Livewire::actingAs($guru)->test(Binaan::class)->call('setTab', 'talenta')
        ->assertSeeHtml('<span class="text-xs text-muted">Lainnya</span>');
});

// ApplyTalent with "Sebutkan" (Task 2) ------------------------------------------------------------------------------

it('requires 3 to 60 characters of Sebutkan for a Lainnya cabang and for jenis Lainnya', function (?string $text, string $message): void {
    $apply = app(ApplyTalent::class);
    $osnOther = $this->osn->branches()->where('name', TalentBranch::OTHER)->sole();

    foreach ([[$this->osn, $osnOther], [$this->lainnya, null]] as [$type, $branch]) {
        try {
            $apply->handle($this->nadia->user, $type, $branch, null, $text);
            $this->fail('A Lainnya choice without a valid Sebutkan text was accepted.');
        } catch (ValidationException $e) {
            expect($e->errors())->toBe(['lainnya' => [$message]]);
        }
    }

    expect(TalentApplication::withTrashed()->count())->toBe(0);
})->with([
    'nothing' => [null, 'Tulis talentamu di kolom Sebutkan.'],
    'only spaces' => ["   \n ", 'Tulis talentamu di kolom Sebutkan.'],
    'too short' => ['ab', 'Tulis 3 sampai 60 karakter.'],
    'too long' => [str_repeat('a', 61), 'Tulis 3 sampai 60 karakter.'],
]);

it('stores the text tidied and ignores a text sent with an ordinary cabang', function (): void {
    $apply = app(ApplyTalent::class);
    $kimia = $this->osn->branches()->where('name', 'Kimia')->sole();

    $kaligrafi = $apply->handle($this->nadia->user, $this->lainnya, null, null, "  Kaligrafi \n Arab  ");
    $plain = $apply->handle($this->nadia->user, $this->osn, $kimia, null, 'Robotika');
    $long = $apply->handle($this->nadia->user, $this->lainnya, null, null, ' '.str_repeat('b', 60).' ');

    expect($kaligrafi->other_text)->toBe('Kaligrafi Arab')
        ->and($kaligrafi->status)->toBe(TalentStatus::Terdaftar)
        ->and($kaligrafi->title())->toBe('Lainnya (Kaligrafi Arab)')
        ->and($plain->other_text)->toBeNull()
        ->and($long->other_text)->toBe(str_repeat('b', 60))
        ->and(TalentOtherText::clean("  a \t b  "))->toBe('a b')
        ->and(TalentOtherText::clean('   '))->toBeNull()
        ->and(TalentOtherText::key('  KaLI   grafi '))->toBe('kali grafi');

    $audit = fn (TalentApplication $a): Activity => Activity::query()->where('event', 'talent.applied')
        ->where('subject_id', $a->getKey())->sole();

    expect($audit($kaligrafi)->properties['other_text'])->toBeTrue()
        ->and($audit($plain)->properties['other_text'])->toBeFalse()
        // The student's own text never reaches the sealed log (D-63, QA9).
        ->and(Activity::query()->get()->map(fn (Activity $a) => json_encode($a->properties))->implode(' '))->not->toContain('Kaligrafi');
});

it('refuses a Lainnya text the student already chose, ignoring case and spaces', function (): void {
    $apply = app(ApplyTalent::class);
    $osnOther = $this->osn->branches()->where('name', TalentBranch::OTHER)->sole();

    $apply->handle($this->nadia->user, $this->lainnya, null, null, 'Kaligrafi');

    try {
        $apply->handle($this->nadia->user, $this->lainnya, null, null, '  kaLIgrafi ');
        $this->fail('The same Lainnya text was accepted twice.');
    } catch (ValidationException $e) {
        expect($e->errors())->toBe(['lainnya' => [ApplyTalent::DUPLICATE_OTHER_MESSAGE]])
            ->and(ApplyTalent::DUPLICATE_OTHER_MESSAGE)->toBe('Talenta ini sudah kamu pilih.');
    }

    // Another text on the same jenis, the same text on another cabang, two texts on one cabang: all allowed.
    $apply->handle($this->nadia->user, $this->lainnya, null, null, 'Fotografi alam');
    $apply->handle($this->nadia->user, $this->osn, $osnOther, null, 'Kaligrafi');
    $apply->handle($this->nadia->user, $this->osn, $osnOther, null, 'Robotika');

    expect(fn () => $apply->handle($this->nadia->user, $this->osn, $osnOther, null, 'ROBOTIKA'))
        ->toThrow(ValidationException::class, 'Talenta ini sudah kamu pilih.');

    expect(TalentApplication::query()->where('student_id', $this->nadia->getKey())->count())->toBe(4);
});

it('restores a deleted Lainnya row with the same text instead of adding one', function (): void {
    $apply = app(ApplyTalent::class);

    $first = $apply->handle($this->nadia->user, $this->lainnya, null, null, 'Kaligrafi');
    $other = $apply->handle($this->nadia->user, $this->lainnya, null, null, 'Fotografi');
    app(CancelTalentApplication::class)->handle($this->nadia->user, $first);
    app(CancelTalentApplication::class)->handle($this->nadia->user, $other);

    $again = $apply->handle($this->nadia->user, $this->lainnya, null, null, 'KALIGRAFI');

    expect($again->getKey())->toBe($first->getKey())
        ->and($again->trashed())->toBeFalse()
        ->and($again->other_text)->toBe('KALIGRAFI')
        ->and($other->fresh()->trashed())->toBeTrue()
        ->and(TalentApplication::withTrashed()->where('student_id', $this->nadia->getKey())->count())->toBe(2);
});
