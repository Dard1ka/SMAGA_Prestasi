<?php

declare(strict_types=1);

use Illuminate\Support\Arr;
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
        ->and(__('auth.failed'))->toBe('NIS/NISN atau kata sandi salah.');
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
