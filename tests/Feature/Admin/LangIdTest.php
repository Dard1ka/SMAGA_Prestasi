<?php

declare(strict_types=1);

use App\Actions\Accounts\ListUserSessions;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;

/**
 * Dot-flattened keys of a validation language file, without the 'custom' and 'attributes' sections.
 *
 * @return list<string>
 */
function langIdValidationKeys(string $path): array
{
    $lines = require $path;
    unset($lines['custom'], $lines['attributes']);

    return array_keys(Arr::dot($lines));
}

it('runs the app in the Indonesian locale', function () {
    expect(app()->getLocale())->toBe('id');
});

it('translates validation messages to Indonesian', function () {
    expect(__('validation.required', ['attribute' => 'nama']))->toBe('Nama wajib diisi.');

    $message = Validator::make([], ['email' => 'required'])->errors()->first('email');

    expect($message)->toContain('wajib diisi');
});

it('uses Indonesian attribute names', function () {
    $message = Validator::make(['password' => 'a'], ['password' => 'min:10'])->errors()->first('password');

    expect($message)->toBe('Kata sandi minimal 10 karakter.');
});

it('translates Fortify messages to Indonesian', function () {
    $english = 'The provided two factor authentication code was invalid.';

    expect(__($english))->not->toBe($english)
        ->and(__($english))->toBe('Kode verifikasi dua langkah tidak valid.')
        ->and(__('auth.failed'))->toBe('NISN atau kata sandi salah.');
});

it('has an Indonesian line for every English validation key', function () {
    $english = base_path('lang/en/validation.php');

    if (! is_file($english)) {
        $english = base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php');
    }

    $missing = array_diff(
        langIdValidationKeys($english),
        langIdValidationKeys(base_path('lang/id/validation.php')),
    );

    expect($missing)->toBe([]);
});

it('ships valid JSON translations', function () {
    $lines = json_decode((string) file_get_contents(base_path('lang/id.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($lines)->toBeArray()->not->toBeEmpty();
});

// QA5 retest (2026-10-07, backlog #67): decision codes such as "(D-08)" are for the team, not for the screen ----

it('shows no decision codes in visible text or seeded data', function () {
    $found = [];

    foreach (File::allFiles(resource_path('views')) as $file) {
        $text = preg_replace(['/\{\{--.*?--\}\}/s', '/<!--.*?-->/s', '/@php.*?@endphp/s'], '', $file->getContents());

        foreach (preg_split('/\R/', (string) $text) as $line) {
            if (preg_match('/\(D-\d+\)/', $line) === 1 && preg_match('/^\s*(\*|\/\/)/', $line) !== 1) {
                $found[] = $file->getRelativePathname().': '.trim($line);
            }
        }
    }

    foreach (File::allFiles(database_path('seeders')) as $file) {
        preg_match_all("/'[^'\n]*\(D-\d+\)[^'\n]*'/", $file->getContents(), $strings);
        foreach ($strings[0] as $string) {
            $found[] = $file->getRelativePathname().': '.$string;
        }
    }

    expect($found)->toBe([]);
});

// QA5 retest (2026-10-07, backlog #67): "browser", not the formal "peramban" (D-89: everyday words) ------------

it('says browser, not peramban, in the mail footer and the session list', function () {
    // D-129: the sentence under a mail's button lives in the published mail component, no longer in lang/id.json
    // (the framework's "If you're having trouble clicking …" line is not printed any more).
    $fallback = view()->file(resource_path('views/vendor/mail/html/fallback.blade.php'), ['url' => 'https://contoh.test/x', 'label' => 'Buka'])->render();

    expect($fallback)->toContain('Tombol "Buka" tidak bisa dibuka? Salin alamat ini ke browser:')->not->toContain('peramban')
        ->and(ListUserSessions::device('Mozilla/5.0 (X11; Linux) Lynx/2.9'))->toStartWith('Browser lain');
});
