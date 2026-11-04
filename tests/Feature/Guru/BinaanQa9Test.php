<?php

declare(strict_types=1);

use App\Enums\TalentStatus;
use App\Enums\TalentTypeCode;
use App\Livewire\Guru\Binaan;
use App\Models\TalentApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Feature\Guru\GuruFixtures;
use Tests\Feature\Kesiswaan\KesiswaanFixtures;

/*
| QA9 (2026-10-09), Guru › Siswa binaan: the tile "Pembinaan talenta · siswa yang dibina" and the caption "N siswa"
| of the Talenta tab counted talent rows, so one student with two choices counted twice. Both count students now.
| D-142: no stages any more; every student with a choice of this guru pembina is coached, an old "tidak lolos" row too.
| Fictional data only.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    GuruFixtures::boot();
    Notification::fake();

    $this->retno = GuruFixtures::guru('Retno Wulandari, S.Pd.');
});

/** A second talent choice ($branch) of $student, with $mentor as guru pembina. */
function qa9SecondChoice(User $mentor, $student, TalentStatus $status, string $branch): TalentApplication
{
    $application = KesiswaanFixtures::application($student, $status, $branch);
    $application->forceFill(['mentor_id' => $mentor->getKey()])->save();

    return $application->fresh();
}

/** The number on the tile "Pembinaan talenta". */
function qa9TalentTile(Testable $component): string
{
    preg_match('/Pembinaan talenta<\/span>\s*<span[^>]*>([^<]*)<\/span>/', $component->html(), $match);

    return trim($match[1] ?? '');
}

it('shows old rows of every status without a stage and counts every coached student once', function (): void {
    $aurelia = GuruFixtures::student('Aurelia Maharani', 'X-3');
    $hana = GuruFixtures::student('Hana Safira', 'X-8');

    // Aurelia: an old "tidak lolos" row. Hana: two choices with the same guru pembina, one of them jenis Lainnya.
    GuruFixtures::talent($this->retno, $aurelia, TalentStatus::TidakLolos);
    GuruFixtures::talent($this->retno, $hana, TalentStatus::Seleksi);
    $lainnya = new TalentApplication;
    $lainnya->forceFill([
        'student_id' => $hana->getKey(),
        'talent_type_id' => KesiswaanFixtures::talentType(TalentTypeCode::Lainnya)->getKey(),
        'talent_branch_id' => null,
        'other_text' => 'Kaligrafi',
        'mentor_id' => $this->retno->getKey(),
    ])->save();
    // Another guru's student counts nowhere here.
    GuruFixtures::talent(GuruFixtures::guru('Hendra Kusuma, S.Pd., M.Si.'), GuruFixtures::student('Raka Pradipta', 'XII-2'));

    $component = Livewire::actingAs($this->retno)->test(Binaan::class)
        ->assertViewHas('counts', fn (array $c): bool => $c['dibina'] === 2 && $c['talenta'] === 3);

    expect(qa9TalentTile($component))->toBe('2');

    $component->call('setTab', 'talenta')
        ->assertSee('Aurelia Maharani')
        ->assertSee('Hana Safira')
        ->assertSee('Kaligrafi')
        ->assertSee('Pembinaan talenta, 2 siswa')
        ->assertDontSee(['Tahap', 'Tidak lolos', 'Seleksi/asesmen', 'Asesmen', 'Raka Pradipta'])
        // One label for a talent on every screen (FS1 QA): the jenis Lainnya reads "Lainnya (Kaligrafi)".
        ->assertViewHas('talents', fn (array $rows): bool => collect($rows)->pluck('title')->contains('Lainnya (Kaligrafi)')
            && collect($rows)->every(fn (array $row): bool => ! array_key_exists('status', $row) && ! array_key_exists('step', $row)));
});

it('counts the students that match the search in the caption of the Talenta tab (QA9)', function (): void {
    $aurelia = GuruFixtures::student('Aurelia Maharani', 'X-3');
    GuruFixtures::talent($this->retno, $aurelia, TalentStatus::Seleksi);
    qa9SecondChoice($this->retno, $aurelia, TalentStatus::Pemetaan, 'Kimia');
    GuruFixtures::talent($this->retno, GuruFixtures::student('Fajar Ramadhan', 'XII-1'), TalentStatus::Pembinaan);

    $component = Livewire::actingAs($this->retno)->test(Binaan::class)
        ->call('setTab', 'talenta')
        ->set('q', 'Aurelia')
        ->assertSee('Pembinaan talenta, 1 siswa')
        ->assertDontSee('Fajar Ramadhan');

    // Both of Aurelia's open choices and Fajar's make two coached students.
    expect(qa9TalentTile($component))->toBe('2');
});
