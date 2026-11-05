<?php

declare(strict_types=1);

use App\Actions\Letters\IssueLetters;
use App\Actions\Letters\LetterDraft;
use App\Enums\LetterType;
use App\Enums\RegistrationStatus;
use App\Enums\RoleName;
use App\Livewire\TataUsaha\SuratTugas;
use App\Models\Letter;
use App\Models\Registration;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Auth\AuthTestHelpers;
use Tests\Feature\KepalaSekolah\W2cFixtures as F;

/*
 * SV6, D-188 (the lead, 2026-11-05): "Koreksi" of an issued letter has no warning box, starts with EVERY value the old
 * letter printed (its number and date too: a revoked letter's number may be used again) and tints each field that is
 * still the same in light blue, with one line that says so. Fictional data only.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    AuthTestHelpers::seedRoles();
    Notification::fake();
    $this->tu = F::staff(RoleName::TataUsaha, ['name' => 'Sri Handayani']);
    $this->mentor = F::mentor();
    $this->signature = F::signature();
});

const SV6_SISWA = '400.3.8/319/MRD/XI/2026';
const SV6_GURU = '400.3.8.6/1690/2026';

function sv6Draft(string $number, string $subject = LetterDraft::DEFAULT_SUBJECT): LetterDraft
{
    return new LetterDraft(
        number: $number,
        issuedOn: CarbonImmutable::today()->subDays(3),
        basisFrom: 'Panitia Lomba Sains Nusantara',
        basisNumber: '021/PAN-LSN/X/2026',
        basisDate: CarbonImmutable::today()->subDays(10),
        scheduleDay: 'Sabtu',
        scheduleDate: '21 November 2026',
        scheduleTime: '08.00 WIB s.d Selesai',
        place: 'Aula Universitas Diponegoro',
        basisSubject: $subject,
    );
}

function sv6Issued(object $t): Registration
{
    $registration = F::registration(RegistrationStatus::Disetujui, ['Raka Pradipta', 'Bima Satria Wicaksono'], $t->mentor);
    app(IssueLetters::class)->handle($t->tu, $registration, LetterType::Siswa, sv6Draft(SV6_SISWA));
    app(IssueLetters::class)->handle($t->tu, $registration, LetterType::Guru, sv6Draft(SV6_GURU, 'Undangan Pembimbing'));

    return $registration->fresh();
}

function sv6Letter(LetterType $type): Letter
{
    return Letter::query()->where('type', $type)->current()->firstOrFail();
}

/** Does the input of this form field carry the tint of an unchanged value? */
function sv6Tinted(string $html, string $field): bool
{
    return preg_match('/<input[^>]*id="f-'.preg_quote($field, '/').'"[^>]*class="[^"]*\b'.preg_quote(SuratTugas::SAME_TINT, '/').'\b/', $html) === 1;
}

it('fills every field of the student letter with what the old letter printed, without the warning box', function (): void {
    $registration = sv6Issued($this);
    $old = sv6Letter(LetterType::Siswa);

    Livewire::actingAs($this->tu)->test(SuratTugas::class, ['registration' => $registration])
        ->call('startRevision', $old->getKey())
        ->assertSet('siswa.nomor', SV6_SISWA)
        ->assertSet('siswa.tanggalSurat', CarbonImmutable::today()->subDays(3)->format('Y-m-d'))
        ->assertSet('siswa.dasarDari', 'Panitia Lomba Sains Nusantara')
        ->assertSet('siswa.dasarNomor', '021/PAN-LSN/X/2026')
        ->assertSet('siswa.dasarTanggal', CarbonImmutable::today()->subDays(10)->format('Y-m-d'))
        ->assertSet('siswa.hari', 'Sabtu')
        ->assertSet('siswa.tanggal', '21 November 2026')
        ->assertSet('siswa.pukul', '08.00 WIB s.d Selesai')
        ->assertSet('siswa.tempat', 'Aula Universitas Diponegoro')
        ->assertSet('alasan', '')
        ->assertSee('Koreksi surat siswa')
        ->assertSee('Alasan pencabutan')
        ->assertSee('Nomor lama boleh dipakai lagi.')
        ->assertDontSee('akan dicabut')
        ->assertDontSee('nomor lama tidak boleh dipakai lagi')
        ->assertDontSee('Nomor surat baru');
});

it('fills every field of the guru letter, the invitation and its "Hal" included', function (): void {
    $registration = sv6Issued($this);

    Livewire::actingAs($this->tu)->test(SuratTugas::class, ['registration' => $registration])
        ->call('startRevision', sv6Letter(LetterType::Guru)->getKey())
        ->assertSet('tab', 'guru')
        ->assertSet('guru.nomor', SV6_GURU)
        ->assertSet('guru.tanggalSurat', CarbonImmutable::today()->subDays(3)->format('Y-m-d'))
        ->assertSet('guru.dasarDari', 'Panitia Lomba Sains Nusantara')
        ->assertSet('guru.dasarNomor', '021/PAN-LSN/X/2026')
        ->assertSet('guru.dasarTanggal', CarbonImmutable::today()->subDays(10)->format('Y-m-d'))
        ->assertSet('guru.dasarHal', 'Undangan Pembimbing')
        ->assertSet('guru.hari', 'Sabtu')
        ->assertSet('guru.tanggal', '21 November 2026')
        ->assertSet('guru.pukul', '08.00 WIB s.d Selesai')
        ->assertSet('guru.tempat', 'Aula Universitas Diponegoro')
        ->assertSee('Koreksi surat guru')
        ->assertDontSee('akan dicabut');
});

it('tints every unchanged field in blue with one line, and drops the tint of a changed field', function (): void {
    $registration = sv6Issued($this);
    $fields = ['nomor', 'tanggalSurat', 'dasarDari', 'dasarNomor', 'dasarTanggal', 'hari', 'tanggal', 'pukul', 'tempat'];

    // Before a correction: no tint, no line.
    $component = Livewire::actingAs($this->tu)->test(SuratTugas::class, ['registration' => $registration])
        ->assertDontSee(SuratTugas::SAME_LINE);

    $component->call('startRevision', sv6Letter(LetterType::Siswa)->getKey())
        ->assertSee(SuratTugas::SAME_LINE);

    foreach ($fields as $field) {
        expect(sv6Tinted($component->html(), 'siswa-'.$field))->toBeTrue('not tinted: '.$field);
    }

    $component->set('siswa.tempat', 'Gedung Serbaguna')
        ->set('siswa.nomor', '400.3.8/320/MRD/XI/2026');
    $html = $component->html();

    expect(sv6Tinted($html, 'siswa-tempat'))->toBeFalse()
        ->and(sv6Tinted($html, 'siswa-nomor'))->toBeFalse()
        ->and(sv6Tinted($html, 'siswa-hari'))->toBeTrue()
        ->and(sv6Tinted($html, 'siswa-pukul'))->toBeTrue()
        ->and($html)->toContain(SuratTugas::SAME_LINE);

    // Back to the old value: tinted again.
    $component->set('siswa.tempat', 'Aula Universitas Diponegoro');
    expect(sv6Tinted($component->html(), 'siswa-tempat'))->toBeTrue();

    // Every field changed: no tint and no line.
    $component->set('siswa.tempat', 'Gedung Serbaguna')
        ->set('siswa.tanggalSurat', CarbonImmutable::today()->format('Y-m-d'))
        ->set('siswa.dasarDari', 'Panitia lain')
        ->set('siswa.dasarNomor', '022/PAN-LSN/X/2026')
        ->set('siswa.dasarTanggal', CarbonImmutable::today()->subDays(9)->format('Y-m-d'))
        ->set('siswa.hari', 'Minggu')
        ->set('siswa.tanggal', '22 November 2026')
        ->set('siswa.pukul', '09.00 WIB s.d Selesai')
        ->assertDontSee(SuratTugas::SAME_LINE);

    // Cancelling ends the tint.
    $component->call('cancelRevision')
        ->assertSet('before', [])
        ->assertDontSee(SuratTugas::SAME_LINE);
});

it('re-issues the letter with the SAME number from the screen: old revoked ("Dicabut"), new current ("Sah")', function (): void {
    $registration = sv6Issued($this);
    $old = sv6Letter(LetterType::Siswa);

    Livewire::actingAs($this->tu)->test(SuratTugas::class, ['registration' => $registration])
        ->call('startRevision', $old->getKey())
        ->set('siswa.nomor', SV6_SISWA)
        ->assertHasNoErrors(['siswa.nomor'])
        ->set('siswa.tempat', 'Gedung Serbaguna')
        ->set('alasan', 'Tempat pelaksanaan salah ketik.')
        ->call('revise')
        ->assertHasNoErrors()
        ->assertSet('revisingId', null)
        ->assertSet('before', [])
        ->assertDispatched('sth-toast', tone: 'success')
        ->assertDontSee(SuratTugas::SAME_LINE);

    $new = sv6Letter(LetterType::Siswa);

    expect($old->fresh()->revoked_at)->not->toBeNull()
        ->and($old->fresh()->revoke_reason)->toBe('Tempat pelaksanaan salah ketik.')
        ->and($new->getKey())->not->toBe($old->getKey())
        ->and($new->full_number)->toBe(SV6_SISWA)
        ->and($new->place)->toBe('Gedung Serbaguna')
        ->and($new->version)->toBe(2)
        ->and($new->replaces_letter_id)->toBe($old->getKey());

    $this->get('/verifikasi-surat/'.$old->verification_code)
        ->assertOk()
        ->assertSee('Dicabut')
        ->assertSee(SV6_SISWA);

    $this->get('/verifikasi-surat/'.$new->verification_code)
        ->assertOk()
        ->assertSee('Sah')
        ->assertDontSee('Dicabut');
});

it('still refuses the number of another letter that is not revoked, while typing and on "Cabut dan terbitkan ulang"', function (): void {
    $registration = sv6Issued($this);
    $other = F::registration(RegistrationStatus::Disetujui, ['Hana Safira'], $this->mentor);
    app(IssueLetters::class)->handle($this->tu, $other, LetterType::Siswa, sv6Draft('400.3.8/325/MRD/XI/2026'));

    Livewire::actingAs($this->tu)->test(SuratTugas::class, ['registration' => $registration])
        ->call('startRevision', sv6Letter(LetterType::Siswa)->getKey())
        ->set('siswa.nomor', '400.3.8 / 325 / mrd / XI / 2026')
        ->assertHasErrors(['siswa.nomor'])
        ->assertSee(IssueLetters::MSG_TAKEN)
        ->set('alasan', 'Nomor salah ketik.')
        ->call('revise')
        ->assertHasErrors(['siswa.nomor'])
        ->assertSet('revisingId', sv6Letter(LetterType::Siswa)->getKey());

    expect(Letter::query()->whereNotNull('revoked_at')->count())->toBe(0);
});
