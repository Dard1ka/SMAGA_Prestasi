<?php

declare(strict_types=1);

use App\Enums\TalentStatus;
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
| of the Talenta tab counted talent rows, so one student with two choices counted twice and a rejected choice counted
| as coached. Both count students now; the tile only the students whose choice is still open (not "Tidak lolos").
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

it('counts the students a guru coaches once each and leaves rejected choices out of the tile (QA9)', function (): void {
    $aurelia = GuruFixtures::student('Aurelia Maharani', 'X-3');
    $fajar = GuruFixtures::student('Fajar Ramadhan', 'XII-1');

    // Aurelia: two choices with the same guru pembina (Seleksi and Peserta pembinaan) = one student.
    GuruFixtures::talent($this->retno, $aurelia, TalentStatus::Seleksi);
    qa9SecondChoice($this->retno, $aurelia, TalentStatus::Pembinaan, 'Kimia');
    // Fajar: a rejected choice is listed (with its chip), but he is not coached.
    GuruFixtures::talent($this->retno, $fajar, TalentStatus::TidakLolos);
    // Another guru's student counts nowhere here.
    GuruFixtures::talent(GuruFixtures::guru('Hendra Kusuma, S.Pd., M.Si.'), GuruFixtures::student('Raka Pradipta', 'XII-2'));

    $component = Livewire::actingAs($this->retno)->test(Binaan::class)
        // The tab still counts the rows it lists.
        ->assertViewHas('counts', fn (array $c): bool => $c['talenta'] === 3);

    expect(qa9TalentTile($component))->toBe('1');

    $component->call('setTab', 'talenta')
        ->assertSee('Aurelia Maharani')
        ->assertSee('Fajar Ramadhan')
        ->assertSee('Tidak lolos')
        ->assertSee('Pembinaan talenta, 2 siswa')
        ->assertDontSee('Pembinaan talenta, 3 siswa');
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
