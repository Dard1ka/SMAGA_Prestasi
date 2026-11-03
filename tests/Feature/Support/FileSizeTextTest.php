<?php

declare(strict_types=1);

use App\Actions\Letters\IssueLetters;
use App\Actions\Letters\LetterDraft;
use App\Enums\LetterType;
use App\Enums\RegistrationStatus;
use App\Enums\RoleName;
use App\Livewire\Admin\Pengaturan\Surat;
use App\Livewire\TataUsaha\SuratTugas;
use App\Models\File;
use App\Models\Letter;
use App\Support\FileSizeLabel;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File as Filesystem;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Admin\AdminTestHelpers as A;
use Tests\Feature\Auth\AuthTestHelpers;
use Tests\Feature\KepalaSekolah\W2cFixtures as W;

/*
| r3 review (2026-11-01): one file-size rule for every screen, App\Support\FileSizeLabel ("1,6 MB", "350 KB", decimal
| comma, CLAUDE.md §3.9). Humas › Arsip foto printed a 1,6 MB photo as "2 MB" (Number::fileSize with no decimal); the TU
| letter card did the same, and Admin › Pengaturan › Surat worked out its own kilobytes. No view or Livewire class may
| make a size text by itself again. Fictional data only.
*/

uses(RefreshDatabase::class);

/** What makes a size text by hand: Laravel's Number::fileSize, or a division by 1024 (bytes → KB / MB). */
const R3_SIZE_BY_HAND = [
    'Number::fileSize' => '/\bNumber::fileSize\s*\(/',
    'a division by 1024' => '/\/\s*\(?\s*(?:1024|1_024|1048576|1_048_576)\b/',
];

/**
 * The source of a view or a Livewire class without its comments (a comment may name a width such as "at 1024").
 */
function r3SizeScanSource(string $path): string
{
    if (str_ends_with($path, '.blade.php')) {
        return (string) preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents($path));
    }

    return php_strip_whitespace($path);
}

it('makes every size text on a screen with FileSizeLabel, never by hand', function (): void {
    $found = [];

    foreach ([resource_path('views'), app_path('Livewire'), app_path('View')] as $dir) {
        if (! is_dir($dir)) {
            continue;
        }

        foreach (Filesystem::allFiles($dir) as $file) {
            if (! str_ends_with($file->getFilename(), '.php')) {
                continue;
            }

            $source = r3SizeScanSource($file->getPathname());

            foreach (R3_SIZE_BY_HAND as $what => $pattern) {
                if (preg_match($pattern, $source) === 1) {
                    $found[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname()).' ('.$what.')';
                }
            }
        }
    }

    expect($found)->toBe([], 'Use App\Support\FileSizeLabel::format() for: '.implode(', ', $found));
});

it('catches the hand-made size texts it is meant to find', function (): void {
    $source = '{{ \Illuminate\Support\Number::fileSize($size, 0) }} {{ number_format($file->size / 1024, 0) }} {{ $b /(1024 * 1024) }}';

    expect(preg_match(R3_SIZE_BY_HAND['Number::fileSize'], $source))->toBe(1)
        ->and(preg_match_all(R3_SIZE_BY_HAND['a division by 1024'], $source))->toBe(2)
        // A comment that names a screen width is not a size text.
        ->and(preg_match(R3_SIZE_BY_HAND['a division by 1024'], (string) preg_replace('/\{\{--.*?--\}\}/s', '', '{{-- at 1024, 485-500 at 1280 --}}')))->toBe(0);
});

it('prints the signature image size on Admin › Pengaturan › Surat with FileSizeLabel', function (): void {
    A::seedRoles();
    $this->withoutVite();
    $file = W::signature();
    $file->forceFill(['size' => 358_400])->save();

    $this->actingAs(A::admin());

    Livewire::test(Surat::class)
        ->assertSee('PNG · '.FileSizeLabel::format(358_400).' · di-upload', false)
        ->assertSee('PNG · 350 KB · di-upload', false);
});

it('prints the letter size on the TU letter card with one decimal (it printed "2 MB" for 1,6 MB)', function (): void {
    Notification::fake();
    AuthTestHelpers::seedRoles();
    $this->withoutVite();
    $tu = W::staff(RoleName::TataUsaha, ['name' => 'Sri Handayani']);
    W::signature();
    $registration = W::registration(RegistrationStatus::Disetujui, ['Raka Pradipta', 'Bima Satria Wicaksono'], W::mentor());

    app(IssueLetters::class)->handle($tu, $registration, LetterType::Siswa, new LetterDraft(
        number: '400.3.8/313/MRD/X/2026',
        issuedOn: CarbonImmutable::today(),
        basisFrom: 'Panitia NSIF 2026',
        basisNumber: '017/PAN-NSIF/IX/2026',
        basisDate: CarbonImmutable::today()->subDays(7),
        scheduleDay: 'Sabtu',
        scheduleDate: '7 November 2026',
        scheduleTime: '07.00 WIB s.d Selesai',
        place: 'Daring',
    ));

    $letter = Letter::query()->where('registration_id', $registration->getKey())->where('type', LetterType::Siswa)->firstOrFail();
    File::query()->whereKey($letter->file_id)->update(['size' => 1_677_722]);

    // The page opens on the guru letter, the one still to make; the student letter's card is on its own tab.
    Livewire::actingAs($tu)->test(SuratTugas::class, ['registration' => $registration])
        ->call('setTab', LetterType::Siswa->value)
        ->assertSee(LetterType::Siswa->label().' · 1,6 MB · 2 siswa', false)
        ->assertDontSee(' · 2 MB', false);
});
