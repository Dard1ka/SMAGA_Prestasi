<?php

declare(strict_types=1);

use App\Actions\Letters\IssueLetters;
use App\Actions\Letters\LetterDraft;
use App\Actions\Letters\RevokeLetter;
use App\Actions\Letters\SendLetters;
use App\Actions\Templates\LetterTemplate;
use App\Actions\Templates\UpdateLetterTemplate;
use App\Enums\LetterType;
use App\Enums\RegistrationStatus;
use App\Enums\RoleName;
use App\Livewire\Kepsek\SuratSaya;
use App\Models\Letter;
use App\Notifications\Admin\LetterRevokedNotice;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Feature\Auth\AuthTestHelpers;
use Tests\Feature\KepalaSekolah\W2cFixtures as F;
use Tests\Feature\Kepsek\KepsekFixtures;

/*
 * B5 · lead decision 2026-10-01: every letter revocation (B3's RevokeLetter) notifies the Kepala Sekolah with the
 * old/new number and the reason; the Kepsek list shows revoked letters with their replacement. Fictional data only.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    AuthTestHelpers::seedRoles();
    Notification::fake();
    $this->withoutVite();
    $this->kepsek = KepsekFixtures::kepsek();
    $this->tu = F::staff(RoleName::TataUsaha, ['name' => 'Sri Handayani']);
    $this->mentor = F::mentor();
    F::signature();
});

/** M1-A: one draft per letter, with the complete number typed by TU. */
function b5RevokeDraft(string $number = '400.3.8/313/MRD/X/2026', string $date = '7 November 2026'): LetterDraft
{
    return new LetterDraft(
        number: $number,
        issuedOn: CarbonImmutable::parse('2026-10-01'),
        basisFrom: 'Panitia NSIF 2026',
        basisNumber: '017/PAN-NSIF/IX/2026',
        basisDate: CarbonImmutable::parse('2026-09-15'),
        scheduleDay: 'Sabtu',
        scheduleDate: $date,
        scheduleTime: '07.00 WIB s.d Selesai',
        place: 'Daring',
    );
}

function b5IssuedLetter(object $t, bool $send): Letter
{
    $registration = F::registration(RegistrationStatus::Disetujui, ['Raka Pradipta', 'Bima Satria Wicaksono'], $t->mentor);
    app(IssueLetters::class)->handle($t->tu, $registration, LetterType::Siswa, b5RevokeDraft());
    app(IssueLetters::class)->handle($t->tu, $registration, LetterType::Guru, b5RevokeDraft('400.3.8.6/1681/2026'));

    if ($send) {
        app(SendLetters::class)->handle($t->tu, $registration);
    }

    return Letter::query()->where('type', LetterType::Siswa)->whereNull('revoked_at')->firstOrFail();
}

it('notifies the Kepala Sekolah of every revocation with both numbers and the reason', function (bool $sent): void {
    $old = b5IssuedLetter($this, $sent);

    $new = app(RevokeLetter::class)->handle($this->tu, $old, 'Tanggal pelaksanaan salah ketik.', b5RevokeDraft('400.3.8/320/MRD/X/2026', '8 November 2026'));

    Notification::assertSentTo($this->kepsek, LetterRevokedNotice::class, function (LetterRevokedNotice $notice) use ($old, $new): bool {
        $text = implode(' ', $notice->lines());

        return str_contains($text, $old->full_number)
            && str_contains($text, $new->full_number)
            && str_contains($text, 'Tanggal pelaksanaan salah ketik.')
            && str_contains($text, 'Sri Handayani')
            && $notice->via($this->kepsek) === ['mail', 'database'];
    });
    Notification::assertNotSentTo($this->tu, LetterRevokedNotice::class);
})->with(['sent letter' => [true], 'not sent yet' => [false]]);

it('sends nothing to the Kepala Sekolah when the revocation fails', function (): void {
    $old = b5IssuedLetter($this, true);

    expect(fn () => app(RevokeLetter::class)->handle($this->tu, $old, 'x', b5RevokeDraft('400.3.8/320/MRD/X/2026')))->toThrow(ValidationException::class);

    Notification::assertNotSentTo($this->kepsek, LetterRevokedNotice::class);
});

it('shows the revoked letter with its replacement and reason in the Kepala Sekolah list', function (): void {
    $old = b5IssuedLetter($this, true);
    $new = app(RevokeLetter::class)->handle($this->tu, $old, 'Nama anggota salah.', b5RevokeDraft('400.3.8/320/MRD/X/2026'));

    $this->actingAs($this->kepsek);

    Livewire::test(SuratSaya::class)
        ->set('status', 'dicabut')
        ->assertSee($old->full_number)
        ->assertSee('Dicabut')
        ->assertSee('Diganti')
        ->assertSee($new->full_number)
        ->assertSee('Nama anggota salah.')
        ->call('download', $new->id)
        ->assertFileDownloaded();
});

it('renders the replacement PDF with the current letter template', function (): void {
    app(UpdateLetterTemplate::class)->handle($this->tu, array_merge(LetterTemplate::defaults(), ['signer_title' => 'Plt. Kepala Sekolah']));
    $old = b5IssuedLetter($this, false);

    $new = app(RevokeLetter::class)->handle($this->tu, $old, 'Jabatan penandatangan berubah.', b5RevokeDraft('400.3.8/320/MRD/X/2026'));

    expect($new->file_id)->not->toBeNull()
        ->and(LetterTemplate::current()->signerTitle())->toBe('Plt. Kepala Sekolah');
});
