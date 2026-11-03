<?php

declare(strict_types=1);

use App\Actions\Letters\IssueLetters;
use App\Actions\Letters\LetterDraft;
use App\Actions\Letters\RevokeLetter;
use App\Enums\LetterType;
use App\Enums\RegistrationStatus;
use App\Enums\RoleName;
use App\Livewire\TataUsaha\SuratTugas;
use App\Models\Letter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Auth\AuthTestHelpers;
use Tests\Feature\KepalaSekolah\W2cFixtures as F;

/*
 * QA9: TU › Surat tugas in two tabs. Tab A corrected letter X (cabut + new version); "Koreksi" on X or sending the
 * correction that tab B had open answered a full-screen 404 (currentLetter() looked for a current letter only), so
 * "Surat ini sudah dicabut." never showed. Fictional data only.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    AuthTestHelpers::seedRoles();
    Notification::fake();
    $this->tu = F::staff(RoleName::TataUsaha, ['name' => 'Sri Handayani']);
    F::signature();
    $this->registration = F::registration(RegistrationStatus::Disetujui, ['Raka Pradipta', 'Bima Satria Wicaksono'], F::mentor());

    foreach (['siswa' => '400.3.8/313/MRD/X/2026', 'guru' => '400.3.8.6/1681/2026'] as $type => $number) {
        app(IssueLetters::class)->handle($this->tu, $this->registration, LetterType::from($type), qa9SuratDraft($number));
    }

    $this->old = Letter::query()->where('registration_id', $this->registration->getKey())->where('type', LetterType::Siswa)->firstOrFail();
});

function qa9SuratDraft(string $number): LetterDraft
{
    return new LetterDraft(
        number: $number,
        issuedOn: CarbonImmutable::parse('2026-10-01'),
        basisFrom: 'Panitia NSIF 2026',
        basisNumber: '017/PAN-NSIF/IX/2026',
        basisDate: CarbonImmutable::parse('2026-09-15'),
        scheduleDay: 'Sabtu',
        scheduleDate: '7 November 2026',
        scheduleTime: '07.00 WIB s.d Selesai',
        place: 'Daring',
    );
}

/** Tab A corrects the student letter with a new number. */
function qa9SuratCorrect(object $t): void
{
    Livewire::actingAs($t->tu)->test(SuratTugas::class, ['registration' => $t->registration])
        ->call('startRevision', $t->old->getKey())
        ->set('alasan', 'Nomor undangan salah ketik.')
        ->set('siswa.nomor', '400.3.8/321/MRD/X/2026')
        ->call('revise')
        ->assertHasNoErrors();
}

it('tells a second tab that the letter was already corrected when "Koreksi" is clicked (QA9)', function (): void {
    $second = Livewire::actingAs($this->tu)->test(SuratTugas::class, ['registration' => $this->registration]);

    qa9SuratCorrect($this);

    $second->call('startRevision', $this->old->getKey())
        ->assertOk()
        ->assertDispatched('sth-toast', message: 'Surat ini sudah dicabut. Tampilan sudah diperbarui.', tone: 'warning')
        ->assertSet('revisingId', null)
        ->assertSet('siswa.nomor', '400.3.8/321/MRD/X/2026');
});

it('tells a second tab that the letter was already corrected when its own correction is sent (QA9)', function (): void {
    $second = Livewire::actingAs($this->tu)->test(SuratTugas::class, ['registration' => $this->registration])
        ->call('startRevision', $this->old->getKey())
        ->set('alasan', 'Tanggal pelaksanaan salah ketik.')
        ->set('siswa.nomor', '400.3.8/322/MRD/X/2026');

    qa9SuratCorrect($this);

    $second->call('revise')
        ->assertOk()
        ->assertDispatched('sth-toast', message: 'Surat ini sudah dicabut. Tampilan sudah diperbarui.', tone: 'warning')
        ->assertSet('revisingId', null)
        ->assertSet('alasan', '')
        ->assertSet('siswa.nomor', '400.3.8/321/MRD/X/2026');

    // Nothing was written twice: one revoked letter, one new version, and the second number was never used.
    expect(Letter::query()->whereNotNull('revoked_at')->count())->toBe(1)
        ->and(Letter::query()->where('full_number', '400.3.8/322/MRD/X/2026')->exists())->toBeFalse();
});

it('still answers 404 for a letter of another registration (QA9)', function (): void {
    $other = F::registration(RegistrationStatus::Disetujui, ['Hana Safira']);
    $foreign = app(IssueLetters::class)->handle($this->tu, $other, LetterType::Siswa, qa9SuratDraft('400.3.8/920/MRD/X/2026'));
    app(RevokeLetter::class)->handle($this->tu, $foreign, 'Koreksi data.', qa9SuratDraft('400.3.8/921/MRD/X/2026'));

    Livewire::actingAs($this->tu)->test(SuratTugas::class, ['registration' => $this->registration])
        ->call('startRevision', $foreign->getKey())
        ->assertNotFound();
});
